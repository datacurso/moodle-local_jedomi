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

namespace local_jedomi\form;

/**
 * Tests for the form of the Privacy tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\privacy_settings_form
 */
final class privacy_settings_form_test extends \advanced_testcase {
    /**
     * MDL-UNIT-010: the form has one visibility box per role and returns which roles were left visible.
     *
     * @return void
     */
    public function test_role_visibility_is_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $roles = [['id' => 3, 'shortname' => 'editingteacher', 'name' => 'Teacher'],
            ['id' => 5, 'shortname' => 'student', 'name' => 'Student']];
        privacy_settings_form::mock_submit([
            'anonymization_level' => 'strict',
            'role_visible_3' => 1,
            'role_visible_5' => 0,
        ]);

        $form = new privacy_settings_form(null, ['roles' => $roles]);
        $data = $form->get_data();

        $this->assertObjectNotHasProperty('hide_from_guests', $data);
        $this->assertSame('strict', $data->anonymization_level);
        $this->assertSame([3], privacy_settings_form::visible_role_ids($data, $roles));
    }

    /**
     * MDL-UNIT-010: an unknown anonymization level is rejected.
     *
     * @return void
     */
    public function test_unknown_level_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        privacy_settings_form::mock_submit(['anonymization_level' => 'everything']);

        $this->assertNull((new privacy_settings_form(null, ['roles' => []]))->get_data());
    }
}
