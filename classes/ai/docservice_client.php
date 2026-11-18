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
require_once($CFG->libdir . '/filelib.php');

/**
 * Client of the document service of the custom AI service, which turns a PDF file into page images.
 *
 * Contract (the one of the document-to-image service Jedomi used before 1.5): POST
 * {url}/api/v1/pdf/to-images?dpi=<int>&fmt=JPEG|PNG with the PDF as the multipart field "file"; the answer is JSON
 * {"total_pages": int, "dpi": int, "pages": [{"page": int, "format": "JPEG"|"PNG", "image": "<base64>"}, ...]}.
 * The images are then read by the vision model of the custom service.
 *
 * The address follows the policy of the custom service ({@see custom_endpoint}): HTTPS, plain HTTP only for a local
 * host with "Allow HTTP for local models" (then pinned to the checked address), the cURL security of the site,
 * no redirects, no credentials, query or fragment. Each request has a connection timeout, a total timeout and a cap
 * on the size of the answer enforced while it arrives; at most MAX_PAGES images are kept. The contract has no
 * page selection, so the whole document is converted and the pages asked are chosen by their number before that
 * cap. Errors keep only a status, an
 * error number or a reason: never the address, the body of the answer or the libcurl text.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docservice_client {
    /** @var string Route of the conversion under the address of the service. */
    public const ROUTE = '/api/v1/pdf/to-images';

    /** @var int Resolution of the page images, in dots per inch: readable text at a moderate size. */
    public const DPI = 150;

    /** @var string Format of the page images. */
    public const FORMAT = 'JPEG';

    /** @var int Longest wait to open a connection, in seconds. */
    public const CONNECT_TIMEOUT = 5;

    /** @var int Total request timeout, in seconds. */
    public const TIMEOUT = 120;

    /** @var int Largest PDF sent, in bytes (20 MB). */
    public const MAX_PDF_BYTES = 20971520;

    /** @var int Largest answer accepted, in bytes (32 MB). */
    public const MAX_RESPONSE_BYTES = 33554432;

    /** @var int Largest page image accepted, in bytes (10 MB, as an image attached to the chat). */
    public const MAX_IMAGE_BYTES = 10485760;

    /** @var int Most page images kept from one document. */
    public const MAX_PAGES = 20;

    /** @var int Largest page count of a document accepted from the service; a larger one is cut to it. */
    public const MAX_TOTAL_PAGES = 10000;

    /** @var array<string, string> Content type of each image format of the answer. */
    private const TYPES = ['JPEG' => 'image/jpeg', 'JPG' => 'image/jpeg', 'PNG' => 'image/png'];

    /** @var int[] libcurl errors of an answer stopped for its size: file size exceeded, aborted by the progress check. */
    private const SIZE_ERRORS = [42, 63];

    /** @var \Closure|null Builds the transport in PHPUnit tests: fn(bool $bypass): \curl. */
    private static ?\Closure $testcurlfactory = null;

    /** @var string Address of the service, without trailing slashes. */
    private string $url;

    /** @var bool Whether "Allow HTTP for local models" is on. */
    private bool $allowhttp;

    /**
     * Constructor.
     *
     * @param string $url Address of the service, such as https://docs.example.com.
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     */
    public function __construct(string $url, bool $allowhttp) {
        $this->url = ai_service::normalise_docservice_url($url);
        $this->allowhttp = $allowhttp;
    }

    /**
     * Client of the saved document service.
     *
     * @return docservice_client
     */
    public static function from_settings(): docservice_client {
        return new docservice_client(ai_service::get_docservice_url(), ai_service::allows_custom_http());
    }

    /**
     * Replace the transport. Only for PHPUnit tests.
     *
     * @param \Closure|null $factory fn(bool $bypass): \curl, where $bypass tells whether the site cURL security is
     *        skipped; null to restore Moodle's own.
     * @return void
     */
    public static function set_curl_factory_for_testing(?\Closure $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('docservice_client::set_curl_factory_for_testing() is only for PHPUnit tests');
        }
        self::$testcurlfactory = $factory;
    }

    /**
     * Turn a PDF file into page images.
     *
     * @param string $path Path of the PDF on disk.
     * @param string $filename File name, already clean.
     * @param int[]|null $wanted Page numbers to keep, chosen before the MAX_PAGES cap; null for every page.
     * @param int $timeout Total request timeout, in seconds, at most TIMEOUT: the time left of the deadline of the caller.
     * @return array{total_pages: int, truncated: bool, pages: array<int, array{page: int, type: string, data: string}>}
     *         Pages kept (none when no asked page is in the document), images as base64 with their content type, the
     *         page count of the document (at most MAX_TOTAL_PAGES), and whether asked pages were left out by the cap.
     * @throws \moodle_exception file_error_too_large (the PDF, nothing is sent), docservice_error_url (the address is
     *         refused, nothing is sent), docservice_error_unreachable, docservice_error_http, docservice_error_too_large
     *         or docservice_error_invalid.
     */
    public function to_images(string $path, string $filename, ?array $wanted = null, int $timeout = self::TIMEOUT): array {
        $endpoint = $this->url . self::ROUTE;
        $refused = $this->url === '' ? custom_endpoint::ERROR_INVALID_URL : custom_endpoint::check($endpoint, $this->allowhttp);
        if ($refused !== null) {
            throw new \moodle_exception('docservice_error_url', 'local_jedomi', '', $refused);
        }
        if (!is_readable($path)) {
            throw new \moodle_exception('docservice_error_invalid', 'local_jedomi');
        }
        if ((int) filesize($path) > self::MAX_PDF_BYTES) {
            throw new \moodle_exception('file_error_too_large', 'local_jedomi');
        }

        // The cURL security of the site is skipped only for a request pinned to a checked local address.
        $timeout = max(1, min(self::TIMEOUT, $timeout));
        $options = custom_endpoint::curl_options($endpoint, $this->allowhttp, $timeout);
        $options['CURLOPT_CONNECTTIMEOUT'] = min(self::CONNECT_TIMEOUT, $timeout);
        $options['CURLOPT_MAXFILESIZE'] = self::MAX_RESPONSE_BYTES;
        $options['CURLOPT_NOPROGRESS'] = false;
        $options['CURLOPT_PROGRESSFUNCTION'] = static fn($handle, $downtotal, $downloaded, $uptotal, $uploaded): int =>
            (int) ($downloaded > self::MAX_RESPONSE_BYTES);
        $bypass = isset($options['CURLOPT_RESOLVE']);
        $curl = self::$testcurlfactory !== null
            ? (self::$testcurlfactory)($bypass)
            : new \curl(['ignoresecurity' => $bypass]);
        $query = http_build_query(['dpi' => self::DPI, 'fmt' => self::FORMAT], '', '&');
        $body = $curl->post(
            $endpoint . '?' . $query,
            ['file' => new \CURLFile($path, 'application/pdf', $filename)],
            $options
        );

        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        if (!empty($curl->errno) && in_array((int) $curl->errno, self::SIZE_ERRORS, true)) {
            throw new \moodle_exception('docservice_error_too_large', 'local_jedomi');
        }
        if (!empty($curl->errno) || $status === 0) {
            // The libcurl text may hold the host: only its number is kept.
            throw new \moodle_exception('docservice_error_unreachable', 'local_jedomi', '', (int) $curl->errno);
        }
        if ($status < 200 || $status >= 300) {
            // The body is never kept: it may hold internals of the service.
            throw new \moodle_exception('docservice_error_http', 'local_jedomi', '', $status);
        }
        if (strlen((string) $body) > self::MAX_RESPONSE_BYTES) {
            throw new \moodle_exception('docservice_error_too_large', 'local_jedomi');
        }
        return self::parse((string) $body, $wanted);
    }

    /**
     * Check and keep the pages of an answer.
     *
     * @param string $body Body of the answer.
     * @param int[]|null $wanted Page numbers to keep; null for every page.
     * @return array{total_pages: int, truncated: bool, pages: array<int, array{page: int, type: string, data: string}>}
     * @throws \moodle_exception docservice_error_invalid, or docservice_error_too_large for an image over its cap.
     */
    private static function parse(string $body, ?array $wanted): array {
        $answer = json_decode($body, true);
        if (!is_array($answer) || !is_array($answer['pages'] ?? null) || empty($answer['pages'])) {
            throw new \moodle_exception('docservice_error_invalid', 'local_jedomi');
        }
        $pages = [];
        $left = 0;
        foreach (array_values($answer['pages']) as $index => $page) {
            $number = is_array($page) ? ($page['page'] ?? null) : null;
            $number = is_int($number) && $number > 0 ? $number : $index + 1;
            if ($wanted !== null && !in_array($number, $wanted, true)) {
                continue;
            }
            if (count($pages) >= self::MAX_PAGES) {
                $left++;
                continue;
            }
            $image = is_array($page) ? ($page['image'] ?? null) : null;
            $format = strtoupper((string) (is_array($page) ? ($page['format'] ?? self::FORMAT) : ''));
            if (!is_string($image) || $image === '' || !isset(self::TYPES[$format])) {
                throw new \moodle_exception('docservice_error_invalid', 'local_jedomi');
            }
            if (intdiv(strlen($image), 4) * 3 > self::MAX_IMAGE_BYTES) {
                throw new \moodle_exception('docservice_error_too_large', 'local_jedomi');
            }
            if (base64_decode($image, true) === false) {
                throw new \moodle_exception('docservice_error_invalid', 'local_jedomi');
            }
            $pages[] = [
                'page' => $number,
                'type' => self::TYPES[$format],
                'data' => $image,
            ];
        }
        $count = min(count($answer['pages']), self::MAX_TOTAL_PAGES);
        $total = $answer['total_pages'] ?? null;
        $total = is_int($total) && $total >= $count ? min($total, self::MAX_TOTAL_PAGES) : $count;
        $truncated = $wanted !== null ? $left > 0 : count($pages) < $total;
        return ['total_pages' => $total, 'truncated' => $truncated, 'pages' => $pages];
    }
}
