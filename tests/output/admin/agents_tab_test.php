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

namespace local_jedomi\output\admin;

/**
 * Tests for the Agents tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\admin\agents_tab
 */
final class agents_tab_test extends \advanced_testcase {
    /**
     * The tab exports the roles, schedules and context types for the filters and the agent form.
     *
     * @return void
     */
    public function test_export_for_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $roles = [['id' => 5, 'shortname' => 'student', 'name' => 'Student']];

        $data = (new agents_tab($roles, 7))->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertSame([['id' => 5, 'name' => 'Student']], $data['roles']);
        $this->assertSame(7, $data['editagentid']);
        $this->assertContains('custom', array_column($data['schedules'], 'value'));
        $this->assertSame(['site', 'course', 'category', 'user'], array_column($data['contexttypes'], 'value'));
    }

    /**
     * The rendered tab has the regions the agents module fills in.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = $PAGE->get_renderer('local_jedomi')->render(new agents_tab([], 0));

        foreach (['table-body', 'pagination', 'preview-body', 'purge-message'] as $region) {
            $this->assertStringContainsString('data-region="local_jedomi/admin/agents/' . $region . '"', $html);
        }
        $this->assertStringContainsString('data-form="local_jedomi/admin/agents/create"', $html);
    }
}
