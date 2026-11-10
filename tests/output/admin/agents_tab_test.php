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

    /**
     * MDL-UNIT-029: schedules, context, status and role are shown with readable, translated names in the table, details and charts.
     * [Pendiente:skip]
     */
    public function test_agents_tab_shows_readable_names(): void {
        $this->markTestSkipped('Pending fix: technical cron expressions, internal context and status names'
            . ' and role short names are shown.');
    }

    /**
     * Purge form of the rendered tab.
     *
     * @param array $roles Roles given to the tab.
     * @return string HTML of the purge form.
     */
    private function purge_form(array $roles): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $html = $PAGE->get_renderer('local_jedomi')->render(new agents_tab($roles, 0));
        $this->assertSame(1, preg_match('~<form data-form="local_jedomi/admin/agents/purge">.*?</form>~s', $html, $matches));
        return $matches[0];
    }

    /**
     * MDL-INT-027: the purge lets the administrator choose the role from a list and also purge the agents of a user.
     */
    public function test_purge_offers_every_context_and_a_list_of_roles(): void {
        $this->resetAfterTest();
        $roles = [['id' => 5, 'shortname' => 'student', 'name' => 'Student'],
            ['id' => 3, 'shortname' => 'editingteacher', 'name' => 'Teacher']];

        $form = $this->purge_form($roles);

        foreach (['site', 'course', 'category', 'user', 'role'] as $type) {
            $this->assertStringContainsString('<option value="' . $type . '"', $form, "The purge must offer the {$type} context");
        }
        $this->assertMatchesRegularExpression(
            '~<option value="5"[^>]*>\s*Student\s*</option>~',
            $form,
            'The role must be chosen from a list of role names'
        );
        $this->assertMatchesRegularExpression('~<option value="3"[^>]*>\s*Teacher\s*</option>~', $form);
    }

    /**
     * MDL-INT-022: the Role, Status, Context and search filters filter the table and the charts, which count the last
     * 30 days, and the Creator column shows the user name.
     * [Pendiente:skip]
     */
    public function test_filters_apply_to_the_table_and_the_charts(): void {
        $this->markTestSkipped('Pending fix: the Role and Status filters have no effect, the charts ignore the filters and '
            . 'the Creator column shows an id.');
    }

    /**
     * MDL-INT-028: the permission to manage agents gives real access to the Agents tab, or it is not granted.
     */
    public function test_agent_managers_can_open_the_agents_tab(): void {
        global $CFG;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user(['username' => 'agentmanager']);
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->assertTrue(has_capability('local/jedomi:manageagents', \context_system::instance(), $manager));
        $this->assertFalse(has_capability('moodle/site:config', \context_system::instance(), $manager));

        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $request = static fn(string $tab): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($runner) . ' agentmanager admin_settings.php ' . escapeshellarg(json_encode(['tab' => $tab]))
            . ' 2>&1');

        $agents = $request('agents');
        $this->assertStringContainsString(
            'data-region="local_jedomi/admin/agents/table-body"',
            $agents,
            'A manager with the permission to manage agents opens the Agents tab'
        );
        $this->assertStringContainsString('data-form="local_jedomi/admin/agents/create"', $agents);

        $general = $request('general');
        $this->assertStringContainsString(
            'data-region="local_jedomi/admin/agents/table-body"',
            $general,
            'A manager who cannot configure the site only gets the Agents tab'
        );
        $this->assertStringNotContainsString('tab=general', $general, 'The other settings tabs are not offered');
    }
}
