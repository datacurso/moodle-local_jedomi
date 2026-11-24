<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi;

/**
 * The file endpoints of the sidebar (extract_file.php, read_file.php) use the message limit of the web chat
 * (JEDOMI-SEC-006, RISK-001): every request counts as one message, before any AI call.
 *
 * The endpoints end the request with die(), so each request runs in its own process through
 * tests/fixtures/page_runner.php.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upload_rate_limit_test extends \advanced_testcase {
    /**
     * Post to an endpoint as a user and decode its JSON answer.
     *
     * @param string $username User.
     * @param string $script Endpoint, relative to the plugin.
     * @param array $params Parameters.
     * @return array
     */
    private function post_as(string $username, string $script, array $params): array {
        global $CFG;
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($username) . ' '
            . escapeshellarg($script) . ' ' . escapeshellarg(json_encode($params)) . ' 2>&1';
        $output = (string) shell_exec($command);
        // The runner has already started the page, so the endpoint warns that AJAX_SCRIPT is defined again: the answer
        // is the last line.
        $lines = preg_split('/\R/', trim($output));
        $answer = json_decode((string) end($lines), true);
        $this->assertIsArray($answer, 'Unexpected endpoint output: ' . $output);
        return $answer;
    }

    /**
     * With a limit of one message per minute, the second request of a user to a file endpoint is refused with the
     * rate_limited error of the chat before the file is looked at; the first one counted even though it had no file.
     *
     * @dataProvider endpoint_provider
     * @param string $script Endpoint.
     */
    public function test_second_upload_within_a_minute_is_refused(string $script): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the endpoints ask it whether Jedomi is enabled.');
        }
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        set_config('chat_rate_limit', 1, 'local_jedomi');
        $username = 'uploader' . random_string(8);
        $user = $this->getDataGenerator()->create_user(['username' => strtolower($username)]);
        $params = ['generate_spt' => 'student', 'courseid' => 0];

        $first = $this->post_as($user->username, $script, $params);
        $second = $this->post_as($user->username, $script, $params);

        $this->assertNotSame('rate_limited', $first['error']['code'] ?? '', 'The first request is within the limit');
        $this->assertSame('rate_limited', $second['error']['code'] ?? null);
        $this->assertGreaterThan(0, $second['error']['seconds']);
        $this->assertSame(
            get_string('chat_error_rate_limited', 'local_jedomi', $second['error']['seconds']),
            $second['error']['message']
        );
    }

    /**
     * File endpoints of the sidebar.
     *
     * @return array
     */
    public static function endpoint_provider(): array {
        return [
            'documents and audio' => ['extract_file.php'],
            'images' => ['read_file.php'],
        ];
    }
}
