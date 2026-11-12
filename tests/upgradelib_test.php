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

/**
 * Tests for the upgrade helper that removes the companion API prohibit left by older versions.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_remove_companion_api_prohibits
 */
final class upgradelib_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        $this->resetAfterTest();
    }

    /**
     * Permission of a role for the companion capability at system context, or null when it has none.
     *
     * @param int $roleid Role id.
     * @return int|null
     */
    private function companion_permission(int $roleid): ?int {
        global $DB;
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $roleid,
            'capability' => 'local/jedomi:companion_api',
            'contextid' => \context_system::instance()->id,
        ]);
        return $permission === false ? null : (int) $permission;
    }

    /**
     * MDL-INT-075 / MDL-INT-086: the update removes the prohibits of the companion permission of previous versions and keeps the changes of the administrator.
     * The prohibit left on the authenticated user role is removed, so a manager holds the capability again, while an
     * allow set by an administrator on another role is kept.
     */
    public function test_prohibit_is_removed_and_administrator_values_are_kept(): void {
        global $DB;
        $systemcontext = \context_system::instance();
        $userroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);

        // State left by the older defaults, plus values set by an administrator.
        assign_capability('local/jedomi:companion_api', CAP_PROHIBIT, $userroleid, $systemcontext->id, true);
        assign_capability('local/jedomi:companion_api', CAP_ALLOW, $studentroleid, $systemcontext->id, true);
        assign_capability('local/jedomi:companion_api', CAP_PREVENT, $teacherroleid, $systemcontext->id, true);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerroleid, $manager->id, $systemcontext);
        $this->assertFalse(has_capability('local/jedomi:companion_api', $systemcontext, $manager));

        $this->assertSame(1, local_jedomi_remove_companion_api_prohibits());

        $this->assertNull($this->companion_permission($userroleid));
        $this->assertSame(CAP_ALLOW, $this->companion_permission($studentroleid));
        $this->assertSame(CAP_PREVENT, $this->companion_permission($teacherroleid));
        $this->assertSame(CAP_ALLOW, $this->companion_permission($managerroleid));
        $this->assertTrue(has_capability('local/jedomi:companion_api', $systemcontext, $manager));

        // Running it again changes nothing.
        $this->assertSame(0, local_jedomi_remove_companion_api_prohibits());
    }

    /**
     * MDL-INT-086: a prohibit set by the administrator on another role is kept.
     * A prohibit on a role whose archetype is not one of the old defaults (for example a manager role) is kept.
     */
    public function test_prohibit_on_other_archetypes_is_kept(): void {
        global $DB;
        $systemcontext = \context_system::instance();
        $customroleid = create_role('Custom manager', 'custommanager', '', 'manager');
        assign_capability('local/jedomi:companion_api', CAP_PROHIBIT, $customroleid, $systemcontext->id, true);

        local_jedomi_remove_companion_api_prohibits();

        $this->assertSame(CAP_PROHIBIT, $this->companion_permission($customroleid));
    }
}
