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
 * Tests for the Telegram profile page (telegram_profile.php).
 *
 * The page ends the request on redirects and errors, so each request runs in its own process
 * through tests/fixtures/page_runner.php.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class telegram_profile_page_test extends \advanced_testcase {
    /**
     * Post a request to the Telegram profile page as a user.
     *
     * @param string $username User who sends the request.
     * @param array $params Request parameters.
     * @return string Page output.
     */
    private function request_page(string $username, array $params): string {
        global $CFG;
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($username)
            . ' telegram_profile.php ' . escapeshellarg(json_encode($params)) . ' 2>&1';
        return (string) shell_exec($command);
    }

    /**
     * Configure an available Telegram bot.
     */
    private function configure_bot(): void {
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        set_config('telegram_bot_username', 'jedomi_test_bot', 'local_jedomi');
        set_config('telegram_bot_firstname', 'Jedomi', 'local_jedomi');
    }

    /**
     * MDL-INT-059: each user can generate the link code of their own account.
     */
    public function test_user_can_generate_own_link_code(): void {
        $this->resetAfterTest();
        $this->configure_bot();
        $user = $this->getDataGenerator()->create_user(['username' => 'jedomistudent']);

        $output = $this->request_page('jedomistudent', ['userid' => $user->id, 'action' => 'generate']);

        $this->assertStringContainsString('local_jedomi-tg-code', $output, $output);
    }

    /**
     * MDL-INT-059: a manager cannot generate the link code of another account, such as an admin.
     * [Pendiente:fail]
     */
    public function test_manager_cannot_generate_link_code_of_another_user(): void {
        $this->resetAfterTest();
        $this->configure_bot();
        $manager = $this->getDataGenerator()->create_user(['username' => 'jedomimanager']);
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);

        $output = $this->request_page('jedomimanager', ['userid' => get_admin()->id, 'action' => 'generate']);

        $this->assertFalse(str_contains($output, 'local_jedomi-tg-code'),
            'A manager generated the Telegram link code of the admin account.');
    }
}
