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

namespace local_jedomi\local\service;

/**
 * Tests for the messages the user gets when a document, an image or an audio cannot be read.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\file_extraction_service
 * @covers     \local_jedomi\local\service\vision_service
 * @covers     \local_jedomi\local\service\audio_service
 * @covers     \local_jedomi\local\service\provider_upload_service
 */
final class read_error_messages_test extends \advanced_testcase {
    /**
     * Make Spanish an installed language so the plugin's lang/es strings are resolved.
     *
     * @return void
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
     * Message of a failure in a language.
     *
     * @param string $service Service class name, without namespace.
     * @param \Throwable $e Failure.
     * @param string $lang Language.
     * @return array{code: string, message: string}
     */
    private function error_in(string $service, \Throwable $e, string $lang): array {
        $class = '\\local_jedomi\\local\\service\\' . $service;
        force_current_language($lang);
        try {
            return $class::error_for($e);
        } finally {
            force_current_language('');
        }
    }

    /**
     * Assert the message of a failure in English and Spanish.
     *
     * @param string $service Service class name, without namespace.
     * @param \Throwable $e Failure.
     * @param string $code Expected code.
     * @param string $key Expected language string.
     * @return void
     */
    private function assert_message_in_both_languages(string $service, \Throwable $e, string $code, string $key): void {
        $sm = get_string_manager();
        $en = $this->error_in($service, $e, 'en');
        $es = $this->error_in($service, $e, 'es');

        $this->assertSame($code, $en['code']);
        $this->assertSame($code, $es['code']);
        $this->assertSame($sm->get_string($key, 'local_jedomi', null, 'en'), $en['message']);
        $this->assertSame($sm->get_string($key, 'local_jedomi', null, 'es'), $es['message']);
        $this->assertNotSame($en['message'], $es['message']);
    }

    /**
     * Rejections of the AI service and the message of each content type.
     *
     * @return array
     */
    public static function service_rejection_provider(): array {
        return [
            'document too large' => ['file_extraction_service', 413, 'file_too_large', 'file_error_too_large'],
            'document unsupported' => ['file_extraction_service', 415, 'file_unsupported', 'file_error_unsupported'],
            'document unreadable' => ['file_extraction_service', 422, 'file_no_text', 'file_error_no_text'],
            'image too large' => ['vision_service', 413, 'file_too_large', 'file_error_too_large'],
            'image unsupported' => ['vision_service', 415, 'file_unsupported', 'image_error_unsupported'],
            'image unreadable' => ['vision_service', 422, 'file_no_text', 'image_error_no_text'],
            'audio too long' => ['audio_service', 413, 'audio_too_long', 'audio_error_too_long'],
            'audio unsupported' => ['audio_service', 415, 'audio_unsupported', 'audio_error_unsupported'],
            'audio unreadable' => ['audio_service', 422, 'audio_unreadable', 'audio_error_unreadable'],
        ];
    }

    /**
     * MDL-UNIT-097: a rejection of the AI service becomes the message of the content being read, in English and Spanish.
     *
     * @dataProvider service_rejection_provider
     * @param string $service Service that was reading.
     * @param int $status HTTP status of the rejection.
     * @param string $code Expected code.
     * @param string $key Expected language string.
     * @return void
     */
    public function test_service_rejection_gives_the_message_of_the_content_type(string $service, int $status, string $code,
            string $key): void {
        $this->resetAfterTest();
        $this->install_spanish_language();

        $this->assert_message_in_both_languages($service, new \moodle_exception('httperror', 'aiprovider_datacurso', '', $status),
            $code, $key);
    }

    /**
     * MDL-UNIT-097: unsupported content and unreadable content give a different message for documents, images and audio.
     *
     * @return void
     */
    public function test_unsupported_and_unreadable_messages_differ_by_content_type(): void {
        foreach ([415, 422] as $status) {
            $e = new \moodle_exception('httperror', 'aiprovider_datacurso', '', $status);
            $messages = [
                file_extraction_service::error_for($e)['message'],
                vision_service::error_for($e)['message'],
                audio_service::error_for($e)['message'],
            ];
            $this->assertCount(3, array_unique($messages), "Status {$status}");
        }
    }

    /**
     * Local rejections and their message.
     *
     * @return array
     */
    public static function local_rejection_provider(): array {
        return [
            'document type' => ['file_extraction_service', 'file_error_unsupported', 'file_unsupported', 'file_error_unsupported'],
            'files disabled for documents' => ['file_extraction_service', 'files_error_disabled', 'files_disabled',
                'files_error_disabled'],
            'image type' => ['vision_service', 'image_error_unsupported', 'file_unsupported', 'image_error_unsupported'],
            'files disabled for images' => ['vision_service', 'files_error_disabled', 'files_disabled', 'files_error_disabled'],
            'audio disabled' => ['audio_service', 'audio_error_disabled', 'audio_disabled', 'audio_error_disabled'],
            'audio type' => ['audio_service', 'audio_error_unsupported', 'audio_unsupported', 'audio_error_unsupported'],
            'audio not understood' => ['audio_service', 'audio_error_unreadable', 'audio_unreadable', 'audio_error_unreadable'],
        ];
    }

    /**
     * MDL-UNIT-097: local rejections give their own message, in English and Spanish.
     *
     * @dataProvider local_rejection_provider
     * @param string $service Service that was reading.
     * @param string $errorcode Local error.
     * @param string $code Expected code.
     * @param string $key Expected language string.
     * @return void
     */
    public function test_local_rejection_gives_its_message(string $service, string $errorcode, string $code, string $key): void {
        $this->resetAfterTest();
        $this->install_spanish_language();

        $this->assert_message_in_both_languages($service, new \moodle_exception($errorcode, 'local_jedomi'), $code, $key);
    }

    /**
     * General failures and the common message they must use.
     *
     * @return array
     */
    public static function general_failure_provider(): array {
        return [
            'no credit' => ['notenoughtokens', null, 'no_credit', 'ai_error_nocredit'],
            'usage limit' => ['error_ratelimit_exceeded', '', 'rate_limited', 'ai_error_ratelimit_noretry'],
            'other service error' => ['httperror', 400, 'generic', 'ai_error_generic'],
            'unknown error' => ['forbidden', null, 'generic', 'ai_error_generic'],
        ];
    }

    /**
     * MDL-UNIT-097: any other failure uses the common messages of the AI service for every content type,
     * in English and Spanish and without internal details.
     *
     * @dataProvider general_failure_provider
     * @param string $errorcode Provider error.
     * @param mixed $a Provider error parameter.
     * @param string $code Expected code.
     * @param string $key Expected language string.
     * @return void
     */
    public function test_general_failures_use_the_common_message($errorcode, $a, string $code, string $key): void {
        $this->resetAfterTest();
        $this->install_spanish_language();
        $e = new \moodle_exception($errorcode, 'aiprovider_datacurso', '', $a, 'secret internal detail');

        foreach (['file_extraction_service', 'vision_service', 'audio_service'] as $service) {
            $this->assert_message_in_both_languages($service, $e, $code, $key);
            $this->assertStringNotContainsString('secret internal detail', $this->error_in($service, $e, 'en')['message']);
        }
        $plain = new \RuntimeException('SQLSTATE[42S02] internal');
        $this->assertStringNotContainsString('SQLSTATE', audio_service::error_for($plain)['message']);
    }
}
