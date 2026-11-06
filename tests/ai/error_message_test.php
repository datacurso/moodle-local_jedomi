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

/**
 * Tests for the translation of provider errors into user messages.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\error_message
 */
final class error_message_test extends \advanced_testcase {
    /**
     * Make Spanish an installed language so the plugin's lang/es strings are resolved.
     *
     * The test dataroot has no language packs; a minimal langconfig is enough for the string
     * manager to accept "es" and read the plugin's own Spanish file.
     */
    private function install_spanish_language(): void {
        global $CFG;
        $dir = $CFG->dataroot . '/lang/es';
        check_dir_exists($dir);
        file_put_contents(
            $dir . '/langconfig.php',
            "<?php\n\$string['thislanguage'] = 'Español';\n\$string['parentlanguage'] = '';\n"
        );
        get_string_manager()->reset_caches();
    }

    /**
     * Provider errors and the message each one maps to.
     *
     * @return array
     */
    public static function provider_error_provider(): array {
        return [
            'no credit' => ['notenoughtokens', null, 'no_credit', 'ai_error_nocredit', null],
            'rate limit with retry time' => [
                'error_ratelimit_exceeded', '27/10/26, 15:00', 'rate_limited', 'ai_error_ratelimit', '27/10/26, 15:00',
            ],
            'rate limit without retry time' => ['error_ratelimit_exceeded', '', 'rate_limited', 'ai_error_ratelimit_noretry', null],
            'license not allowed' => ['license_not_allowed', null, 'license', 'ai_error_license', null],
            'license missing or invalid' => ['invalidlicensekey', null, 'license', 'ai_error_license', null],
            'service unavailable' => ['httperror', 503, 'unavailable', 'ai_error_unavailable', null],
            'network error' => ['curlerror', 7, 'unavailable', 'ai_error_unavailable', null],
            'request timeout' => ['curlerror', CURLE_OPERATION_TIMEDOUT, 'timeout', 'ai_error_timeout', null],
            'bad request' => ['httperror', 400, 'generic', 'ai_error_generic', null],
            'unknown forbidden' => ['forbidden', null, 'generic', 'ai_error_generic', null],
        ];
    }

    /**
     * M2: each provider exception becomes its own message, in English and in Spanish.
     *
     * @dataProvider provider_error_provider
     * @param string $errorcode Provider error code.
     * @param mixed $a Provider exception parameter.
     * @param string $expectedcode Expected stable error code.
     * @param string $expectedkey Expected language string key.
     * @param mixed $expecteda Expected language string parameter.
     */
    public function test_provider_exception_translates_to_its_message_in_english_and_spanish(
        string $errorcode,
        $a,
        string $expectedcode,
        string $expectedkey,
        $expecteda
    ): void {
        $this->resetAfterTest();
        $this->install_spanish_language();
        $e = new \moodle_exception($errorcode, 'aiprovider_datacurso', '', $a);
        $sm = get_string_manager();

        $en = error_message::from_exception($e, 'en');
        $es = error_message::from_exception($e, 'es');

        $this->assertSame($expectedcode, $en['code']);
        $this->assertSame($expectedcode, $es['code']);
        $this->assertSame($sm->get_string($expectedkey, 'local_jedomi', $expecteda, 'en'), $en['message']);
        $this->assertSame($sm->get_string($expectedkey, 'local_jedomi', $expecteda, 'es'), $es['message']);
        $this->assertNotSame($en['message'], $es['message']);
        if ($expecteda !== null) {
            $this->assertStringContainsString((string) $expecteda, $en['message']);
        }
    }

    /**
     * I6: a disabled provider gives the provider's own message, in the requested language.
     */
    public function test_provider_disabled_gives_the_provider_message_in_english_and_spanish(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; its language strings are not available.');
        }
        $this->resetAfterTest();
        $this->install_spanish_language();
        $e = new \moodle_exception('providerdisabled', 'aiprovider_datacurso');
        $sm = get_string_manager();

        $en = error_message::from_exception($e, 'en');
        $es = error_message::from_exception($e, 'es');

        $this->assertSame(error_message::CODE_PROVIDER_DISABLED, $en['code']);
        $this->assertSame('provider_disabled', $es['code']);
        $this->assertSame($sm->get_string('providerdisabled', 'aiprovider_datacurso', null, 'en'), $en['message']);
        $this->assertSame($sm->get_string('providerdisabled', 'aiprovider_datacurso', null, 'es'), $es['message']);
        $this->assertNotSame($en['message'], $es['message']);
    }

    /**
     * M2: any other failure (including non-provider exceptions) gives the generic message, never internal details.
     */
    public function test_unknown_exception_gives_generic_message_without_details(): void {
        $result = error_message::from_exception(new \RuntimeException('secret internal detail'), 'en');

        $this->assertSame('generic', $result['code']);
        $this->assertSame(get_string_manager()->get_string('ai_error_generic', 'local_jedomi', null, 'en'), $result['message']);
        $this->assertStringNotContainsString('secret internal detail', $result['message']);
    }
}
