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

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the get_site_config agent tool: read-only, allowlisted keys, site administrators only.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\site_config_tool
 */
final class site_config_tool_test extends \advanced_testcase {
    /**
     * Run the tool for a user.
     *
     * @param int $userid User the tool runs for.
     * @param mixed $keys Value of the "keys" argument.
     * @return array Decoded result.
     */
    private function read(int $userid, $keys): array {
        return json_decode((new site_config_tool($userid))->execute((object) ['keys' => $keys]), true);
    }

    /**
     * The tool definition names the tool and requires the keys.
     */
    public function test_definition(): void {
        $tool = new site_config_tool();

        $this->assertSame('get_site_config', $tool->get_name());
        $this->assertSame('get_site_config', $tool->get_definition()['function']['name']);
        $this->assertSame(['keys'], $tool->get_definition()['function']['parameters']['required']);
    }

    /**
     * An administrator reads allowlisted keys; other keys are left out and nothing is written.
     */
    public function test_administrator_reads_allowlisted_keys_only(): void {
        $this->resetAfterTest();
        set_config('timezone', 'Europe/Madrid');
        set_config('smtppass', 'secret-smtp-password');
        $adminid = (int) get_admin()->id;

        $result = $this->read($adminid, ['timezone', 'smtppass', 'unknownkey']);

        $this->assertTrue($result['success']);
        $this->assertSame(['timezone' => 'Europe/Madrid'], $result['config']);
        $this->assertStringNotContainsString('secret-smtp-password', json_encode($result));
        $this->assertSame('Europe/Madrid', get_config('core', 'timezone'), 'Reading changes nothing');

        $none = $this->read($adminid, ['smtppass']);
        $this->assertTrue($none['success']);
        $this->assertSame([], $none['config']);
    }

    /**
     * Without a key list the tool asks for one.
     */
    public function test_key_list_is_required(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $this->assertSame('Key list required.', $this->read($adminid, [])['error'] ?? null);
        $this->assertSame('Key list required.', $this->read($adminid, 'timezone')['error'] ?? null);
    }

    /**
     * A manager, a teacher, a student and no user are refused.
     */
    public function test_non_administrators_are_refused(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $manager = $gen->create_user();
        $gen->role_assign('manager', $manager->id);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');

        foreach ([(int) $manager->id, (int) $teacher->id, (int) $student->id, 0] as $userid) {
            $this->assertSame(['error' => 'permission_denied'], $this->read($userid, ['timezone']));
        }
    }
}
