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

namespace local_jedomi;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\whatsapp\link_codes;
use local_jedomi\whatsapp\manager;

/**
 * Tests for the WhatsApp profile page (whatsapp_profile.php).
 *
 * The page ends the request on redirects and errors, so each request runs in its own process
 * through tests/fixtures/page_runner.php.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class whatsapp_profile_page_test extends \advanced_testcase {
    /** @var string Number of the institution. */
    private const NUMBER = '573001234567';

    /** @var string Submit marker of the consent form. */
    private const FORM = '_qf__local_jedomi_form_whatsapp_consent_form';

    /**
     * Post a request to the WhatsApp profile page as a user.
     *
     * @param string $username User who sends the request.
     * @param array $params Request parameters.
     * @return string Page output.
     */
    private function request_page(string $username, array $params): string {
        global $CFG;
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($username)
            . ' whatsapp_profile.php ' . escapeshellarg(json_encode($params)) . ' 2>&1';
        return (string) shell_exec($command);
    }

    /**
     * Skip the test when aiprovider_datacurso is not installed: the page checks that Jedomi is enabled in it.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; this test needs it.');
        }
    }

    /**
     * Configure an available WhatsApp channel.
     */
    private function configure_channel(): void {
        manager::set_enabled(true);
        manager::set_phone_number_id('123456789');
        manager::set_waba_id('987654321');
        manager::set_access_token('test-token');
        manager::set_app_secret('test-secret');
        manager::set_verify_token('test-verify');
        manager::set_display_number(self::NUMBER);
    }

    /**
     * Without accepting the consent no code is generated.
     */
    public function test_no_code_without_consent(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->configure_channel();
        $user = $this->getDataGenerator()->create_user(['username' => 'wastudent']);

        $output = $this->request_page('wastudent', ['userid' => $user->id, self::FORM => 1, 'consent' => 0,
            'chat' => 1, 'grades' => 1]);

        $this->assertStringContainsString('name="consent"', $output, $output);
        $this->assertStringNotContainsString('https://wa.me/', $output);
        $this->assertStringContainsString(get_string('whatsapp_consent_required', 'local_jedomi'), $output);
    }

    /**
     * Accepting the consent shows a code with the wa.me link of the institution; the code carries the consent.
     */
    public function test_consent_generates_a_code_with_the_wa_me_link(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->configure_channel();
        $user = $this->getDataGenerator()->create_user(['username' => 'wastudent']);

        $output = $this->request_page('wastudent', ['userid' => $user->id, self::FORM => 1, 'consent' => 1,
            'chat' => 1, 'grades' => 0]);

        $this->assertMatchesRegularExpression(
            '~https://wa\.me/' . self::NUMBER . '\?text=JEDOMI%20([A-Z2-9]{6})~',
            $output,
            $output
        );
        preg_match('~text=JEDOMI%20([A-Z2-9]{6})~', $output, $matches);
        $grant = link_codes::resolve($matches[1]);
        $this->assertNotNull($grant, 'The code shown is the code stored');
        $this->assertSame((int) $user->id, $grant['userid']);
        $this->assertSame('chat', $grant['consent']->categories_field());
    }

    /**
     * A user cannot get the code of another account.
     */
    public function test_user_cannot_generate_the_code_of_another_account(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->configure_channel();
        $this->getDataGenerator()->create_user(['username' => 'wastudent']);

        $output = $this->request_page('wastudent', ['userid' => get_admin()->id, self::FORM => 1, 'consent' => 1,
            'chat' => 1, 'grades' => 1]);

        $this->assertStringNotContainsString('https://wa.me/', $output);
    }

    /**
     * A user whose role cannot use the channel gets no consent form and no code.
     */
    public function test_blocked_role_gets_no_code(): void {
        global $DB;
        $this->require_provider();
        $this->resetAfterTest();
        $this->configure_channel();
        $user = $this->getDataGenerator()->create_user(['username' => 'wastudent']);
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        set_config('whatsapp_hidden_roles', (string) $DB->get_field('role', 'id', ['shortname' => 'student']), 'local_jedomi');

        $output = $this->request_page('wastudent', ['userid' => $user->id, self::FORM => 1, 'consent' => 1,
            'chat' => 1, 'grades' => 1]);

        $this->assertStringContainsString(get_string('whatsapp_msg_role_blocked', 'local_jedomi'), $output, $output);
        $this->assertStringNotContainsString('name="consent"', $output);
        $this->assertStringNotContainsString('https://wa.me/', $output);
    }

    /**
     * A linked user sees the link and the opt-out state, and the unlink button removes the link.
     */
    public function test_linked_user_sees_the_state_and_can_unlink(): void {
        global $DB;
        $this->require_provider();
        $this->resetAfterTest();
        $this->configure_channel();
        $user = $this->getDataGenerator()->create_user(['username' => 'wastudent']);
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $user->id,
            'bsuid' => 'US.13491208655302741918', 'waid' => '16505551234', 'confirmed' => 1, 'optedout' => 1,
            'timecreated' => time()]);

        $page = $this->request_page('wastudent', ['userid' => $user->id]);
        $this->assertStringContainsString(get_string('whatsapp_optedout_notice', 'local_jedomi'), $page, $page);
        $this->assertStringContainsString(get_string('whatsapp_linked_phone', 'local_jedomi', '1234'), $page);
        $this->assertStringNotContainsString('US.13491208655302741918', $page);

        $this->request_page('wastudent', ['userid' => $user->id, 'action' => 'unlink']);
        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
    }
}
