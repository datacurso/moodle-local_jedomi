<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');

/**
 * Tests for the client of the document service of the custom AI service (PDF to page images).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\docservice_client
 */
final class docservice_client_test extends \advanced_testcase {
    /** @var fake_curl Transport double. */
    private fake_curl $curl;

    /** @var bool[] Whether each created transport bypassed the site cURL security. */
    private array $bypassed = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // A public address: no blocked hosts, so nothing is resolved in the tests.
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->curl = new fake_curl();
        docservice_client::set_curl_factory_for_testing(function (bool $bypass): \curl {
            $this->bypassed[] = $bypass;
            return $this->curl;
        });
    }

    #[\Override]
    protected function tearDown(): void {
        docservice_client::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * A PDF on disk.
     *
     * @return string Path.
     */
    private function pdf(): string {
        $path = make_request_directory() . '/doc.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        return $path;
    }

    /**
     * Answer of the document service with some pages.
     *
     * @param int $count Number of pages.
     * @param string $format Format of the images.
     * @return string
     */
    private static function answer(int $count, string $format = 'JPEG'): string {
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $pages[] = ['page' => $i, 'format' => $format, 'image' => base64_encode('IMG' . $i)];
        }
        return json_encode(['total_pages' => $count, 'dpi' => 150, 'pages' => $pages]);
    }

    /**
     * Expect a failure with an error code, and check that it carries neither the body of the answer nor the address.
     *
     * @param callable $call Call.
     * @param string $code Expected error code.
     * @param string|int|null $a Expected value of the error, or null to skip that check.
     * @return void
     */
    private function assert_failure(callable $call, string $code, $a = null): void {
        try {
            $call();
            $this->fail('The call must fail with ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame('local_jedomi', $e->module);
            $this->assertSame($code, $e->errorcode);
            if ($a !== null) {
                $this->assertSame($a, $e->a);
            }
            foreach (['secret-body', 'docs.example.com', 'internal.example'] as $leak) {
                $this->assertStringNotContainsString($leak, $e->getMessage() . ' ' . json_encode($e->a));
            }
        }
    }

    /**
     * The PDF is posted as multipart to {url}/api/v1/pdf/to-images with the dpi and the format, and the pages come back
     * as base64 images with their content type.
     *
     * @return void
     */
    public function test_pdf_is_converted_to_page_images(): void {
        $this->curl->enqueue(self::answer(2));

        $result = (new docservice_client('https://docs.example.com/', false))->to_images($this->pdf(), 'doc.pdf');

        $this->assertSame(2, $result['total_pages']);
        $this->assertFalse($result['truncated']);
        $this->assertSame([
            ['page' => 1, 'type' => 'image/jpeg', 'data' => base64_encode('IMG1')],
            ['page' => 2, 'type' => 'image/jpeg', 'data' => base64_encode('IMG2')],
        ], $result['pages']);
        $call = $this->curl->calls[0];
        $this->assertSame('https://docs.example.com/api/v1/pdf/to-images?dpi=150&fmt=JPEG', $call['url']);
        $this->assertInstanceOf(\CURLFile::class, $call['params']['file']);
        $this->assertSame('application/pdf', $call['params']['file']->getMimeType());
        $this->assertSame('doc.pdf', $call['params']['file']->getPostFilename());
        $this->assertSame([false], $this->bypassed, 'A public host keeps the site cURL security');
    }

    /**
     * Every request has the security options: TLS checks, no redirects, a connection timeout, a total timeout and a cap
     * on the size of the answer, enforced while it is received by the write callback (RES-005: not by the progress
     * callback, which belongs to the signals of life).
     *
     * @return void
     */
    public function test_requests_have_timeouts_and_a_size_cap(): void {
        $this->curl->enqueue(self::answer(1));

        (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf');

        $options = $this->curl->calls[0]['options'];
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(docservice_client::CONNECT_TIMEOUT, $options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(docservice_client::TIMEOUT, $options['CURLOPT_TIMEOUT']);
        $this->assertSame(docservice_client::MAX_RESPONSE_BYTES, $options['CURLOPT_MAXFILESIZE']);
        $this->assertIsCallable($options['CURLOPT_WRITEFUNCTION']);
        $this->assertArrayNotHasKey('CURLOPT_PROGRESSFUNCTION', $options, 'No progress callback without signals of life');
    }

    /**
     * While a question sends signals of life, the request beats through the progress callback of the heartbeat and the
     * size cap still holds.
     *
     * @return void
     */
    public function test_size_cap_and_signals_of_life_coexist(): void {
        $beats = 0;
        heartbeat::start(1, function () use (&$beats): void {
            $beats++;
        });
        try {
            $this->curl->enqueue(str_repeat('x', docservice_client::MAX_RESPONSE_BYTES + 1));
            $this->assert_failure(
                fn() => (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf'),
                'docservice_error_too_large'
            );
            $options = $this->curl->calls[0]['options'];
            $this->assertSame(heartbeat::curl_options()['CURLOPT_NOPROGRESS'], $options['CURLOPT_NOPROGRESS']);
            $this->assertSame(0, $options['CURLOPT_PROGRESSFUNCTION'](null, 0, docservice_client::MAX_RESPONSE_BYTES + 1, 0, 0));
            $this->assertIsCallable($options['CURLOPT_WRITEFUNCTION']);
        } finally {
            heartbeat::stop();
        }
    }

    /**
     * RES-003: a dropped connection or a busy service (502, 503, 504) is tried once more after a short pause, while time
     * is left; other failures are not.
     *
     * @return void
     */
    public function test_transient_failures_are_retried_once(): void {
        $client = new docservice_client('https://docs.example.com', false);

        $this->curl->enqueue('', 503);
        $this->curl->enqueue(self::answer(1));
        $this->assertCount(1, $client->to_images($this->pdf(), 'doc.pdf')['pages']);
        $this->assertCount(2, $this->curl->calls);

        $this->curl->calls = [];
        $this->curl->enqueue('', 0, 'Connection refused', 7);
        $this->curl->enqueue('', 0, 'Connection refused', 7);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_unreachable', 7);
        $this->assertCount(2, $this->curl->calls, 'Only one retry');

        $this->curl->calls = [];
        $this->curl->enqueue('', 502);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf', null, 3), 'docservice_error_http', 502);
        $this->assertCount(1, $this->curl->calls, 'No retry without time left');
    }

    /**
     * The address follows the policy of the custom service: HTTPS, HTTP only for a local host with "Allow HTTP for local
     * models", the blocked hosts of the site, and no query or credentials. A refused address gets nothing.
     *
     * @dataProvider refused_provider
     * @param string $url Address of the document service.
     * @param bool $allowhttp Allow HTTP for local models.
     * @param string $reason Expected reason.
     * @return void
     */
    public function test_refused_addresses_get_nothing(string $url, bool $allowhttp, string $reason): void {
        set_config('curlsecurityblockedhosts', "10.0.0.0/8\n169.254.169.254");
        $this->assert_failure(
            fn() => (new docservice_client($url, $allowhttp))->to_images($this->pdf(), 'doc.pdf'),
            'docservice_error_url',
            $reason
        );
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * Refused addresses.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'empty' => ['', false, custom_endpoint::ERROR_INVALID_URL],
            'not a url' => ['docs', false, custom_endpoint::ERROR_INVALID_URL],
            'query' => ['https://docs.example.com/?token=secret', false, custom_endpoint::ERROR_INVALID_URL],
            'credentials' => ['https://user:pass@docs.example.com', false, custom_endpoint::ERROR_INVALID_URL],
            'public http' => ['http://203.0.113.10', true, custom_endpoint::ERROR_HTTP_NOT_ALLOWED],
            'local http without the option' => ['http://127.0.0.1:8000', false, custom_endpoint::ERROR_HTTP_NOT_ALLOWED],
            'blocked host' => ['https://10.0.0.5', false, custom_endpoint::ERROR_BLOCKED_HOST],
            'metadata host' => ['https://169.254.169.254', true, custom_endpoint::ERROR_BLOCKED_HOST],
        ];
    }

    /**
     * A local document service over HTTP is allowed with "Allow HTTP for local models", pinned to its checked address.
     *
     * @return void
     */
    public function test_local_service_over_http_is_pinned(): void {
        $this->curl->enqueue(self::answer(1, 'PNG'));

        $result = (new docservice_client('http://127.0.0.1:8000', true))->to_images($this->pdf(), 'doc.pdf');

        $this->assertSame('image/png', $result['pages'][0]['type']);
        $this->assertSame([true], $this->bypassed);
        $this->assertSame(['127.0.0.1:8000:127.0.0.1'], $this->curl->calls[0]['options']['CURLOPT_RESOLVE']);
        $this->assertSame('http://127.0.0.1:8000/api/v1/pdf/to-images?dpi=150&fmt=JPEG', $this->curl->calls[0]['url']);
    }

    /**
     * Errors keep only a status or an error number: never the body of the answer, the libcurl text or the address.
     *
     * @return void
     */
    public function test_errors_never_leak_the_answer_or_the_address(): void {
        $client = new docservice_client('https://docs.example.com', false);

        $this->curl->enqueue('{"detail":"secret-body at internal.example"}', 500);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_http', 500);

        $this->curl->enqueue('', 302);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_http', 302);

        $this->curl->enqueue('', 0, 'Could not resolve host: docs.example.com', 6);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_unreachable', 6);

        $this->curl->enqueue('', 0, 'Operation timed out', 28);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_unreachable', 28);
    }

    /**
     * An answer over the size cap is refused, whether libcurl stopped it (file size exceeded) or the write callback did;
     * a request aborted by the progress callback (the user left) is no size problem.
     *
     * @return void
     */
    public function test_answer_over_the_size_cap_is_refused(): void {
        $client = new docservice_client('https://docs.example.com', false);

        $this->curl->enqueue('', 0, 'Maximum file size exceeded', 63);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_too_large');

        $this->curl->enqueue(str_repeat('x', docservice_client::MAX_RESPONSE_BYTES + 1));
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_too_large');

        $this->curl->enqueue('', 0, 'Callback aborted', 42);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_unreachable', 42);

        $this->curl->enqueue(str_repeat('x', docservice_client::MAX_RESPONSE_BYTES), 200);
        $this->assert_failure(fn() => $client->to_images($this->pdf(), 'doc.pdf'), 'docservice_error_invalid');
    }

    /**
     * READ-010: the cap of a page image is the one of an image read by the custom service.
     *
     * @return void
     */
    public function test_image_cap_is_shared(): void {
        $this->assertSame(\local_jedomi\local\service\vision_service::MAX_CUSTOM_IMAGE_BYTES, docservice_client::MAX_IMAGE_BYTES);
    }

    /**
     * A PDF over the upload cap is refused before anything is sent.
     *
     * @return void
     */
    public function test_large_pdf_is_not_sent(): void {
        $path = make_request_directory() . '/big.pdf';
        $handle = fopen($path, 'w');
        ftruncate($handle, docservice_client::MAX_PDF_BYTES + 1);
        fclose($handle);

        $this->assert_failure(
            fn() => (new docservice_client('https://docs.example.com', false))->to_images($path, 'big.pdf'),
            'file_error_too_large'
        );
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * Only the first MAX_PAGES images are kept; the result says it was cut and keeps the total of the document.
     *
     * @return void
     */
    public function test_pages_are_capped(): void {
        $this->curl->enqueue(self::answer(docservice_client::MAX_PAGES + 3));

        $result = (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf');

        $this->assertCount(docservice_client::MAX_PAGES, $result['pages']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(docservice_client::MAX_PAGES + 3, $result['total_pages']);
        $this->assertSame(docservice_client::MAX_PAGES, end($result['pages'])['page']);
    }

    /**
     * An answer that is not the expected list of images is refused.
     *
     * @dataProvider invalid_answer_provider
     * @param string $body Body of the answer.
     * @return void
     */
    public function test_invalid_answers_are_refused(string $body): void {
        $this->curl->enqueue($body);

        $this->assert_failure(
            fn() => (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf'),
            'docservice_error_invalid'
        );
    }

    /**
     * Invalid answers.
     *
     * @return array
     */
    public static function invalid_answer_provider(): array {
        $image = base64_encode('IMG');
        return [
            'not json' => ['secret-body'],
            'no pages' => [json_encode(['total_pages' => 0, 'pages' => []])],
            'pages not a list' => [json_encode(['pages' => 'x'])],
            'page without image' => [json_encode(['pages' => [['page' => 1]]])],
            'bad base64' => [json_encode(['pages' => [['page' => 1, 'image' => '***not base64***']]])],
            'unknown format' => [json_encode(['pages' => [['page' => 1, 'format' => 'GIF', 'image' => $image]]])],
        ];
    }

    /**
     * The client of the saved settings uses the saved address and "Allow HTTP for local models".
     *
     * @return void
     */
    public function test_from_settings(): void {
        set_config('custom_docservice_url', ' https://docs.example.com/ ', 'local_jedomi');
        $this->curl->enqueue(self::answer(1));

        docservice_client::from_settings()->to_images($this->pdf(), 'doc.pdf');

        $this->assertStringStartsWith('https://docs.example.com/api/v1/pdf/to-images', $this->curl->calls[0]['url']);
    }

    /**
     * The test transport can only be replaced in PHPUnit (here it always can), and an image too large is refused.
     *
     * @return void
     */
    public function test_large_image_in_the_answer_is_refused(): void {
        $big = base64_encode(str_repeat('x', docservice_client::MAX_IMAGE_BYTES + 1));
        $this->curl->enqueue(json_encode(['total_pages' => 1, 'pages' => [['page' => 1, 'format' => 'JPEG', 'image' => $big]]]));

        $this->assert_failure(
            fn() => (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf'),
            'docservice_error_too_large'
        );
    }

    /**
     * U2: the caller can give a shorter total timeout, the time left of its own deadline.
     *
     * @return void
     */
    public function test_a_shorter_timeout_can_be_given(): void {
        $this->curl->enqueue(self::answer(1));

        (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf', null, 3);

        $this->assertSame(3, $this->curl->calls[0]['options']['CURLOPT_TIMEOUT']);
        $this->assertSame(3, $this->curl->calls[0]['options']['CURLOPT_CONNECTTIMEOUT']);
    }

    /**
     * U5: the pages asked are chosen before the page cap, by their page number.
     *
     * @return void
     */
    public function test_wanted_pages_are_chosen_before_the_cap(): void {
        $client = new docservice_client('https://docs.example.com', false);

        $this->curl->enqueue(self::answer(30));
        $result = $client->to_images($this->pdf(), 'doc.pdf', [25, 26, 27, 28, 29, 30]);
        $this->assertSame([25, 26, 27, 28, 29, 30], array_column($result['pages'], 'page'));
        $this->assertFalse($result['truncated']);
        $this->assertSame(30, $result['total_pages']);

        $this->curl->enqueue(self::answer(30));
        $result = $client->to_images($this->pdf(), 'doc.pdf', range(1, 30));
        $this->assertCount(docservice_client::MAX_PAGES, $result['pages']);
        $this->assertTrue($result['truncated']);

        $this->curl->enqueue(self::answer(3));
        $this->assertSame([], $client->to_images($this->pdf(), 'doc.pdf', [40])['pages'], 'Pages not in the document');
    }

    /**
     * U6: the page count told by the service is capped.
     *
     * @return void
     */
    public function test_total_pages_is_capped(): void {
        $this->curl->enqueue(json_encode(['total_pages' => PHP_INT_MAX, 'pages' => [
            ['page' => 1, 'format' => 'JPEG', 'image' => base64_encode('IMG')],
        ]]));

        $result = (new docservice_client('https://docs.example.com', false))->to_images($this->pdf(), 'doc.pdf');

        $this->assertSame(docservice_client::MAX_TOTAL_PAGES, $result['total_pages']);
    }
}
