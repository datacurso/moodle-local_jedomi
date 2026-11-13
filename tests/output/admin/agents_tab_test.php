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
     *
     * @return void
     */
    public function test_agents_tab_shows_readable_names(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $manager = \local_jedomi\agent\agent_manager::class;

        // Schedules: the presets by their name, the usual patterns described, anything else as a custom expression.
        $this->assertSame(get_string('agent_every_15min', 'local_jedomi'), $manager::describe_schedule('*/15 * * * *'));
        $this->assertSame(get_string('agent_cron_daily_at', 'local_jedomi', '08:00'), $manager::describe_schedule('0 8 * * *'));
        $this->assertSame('Every day at 08:00', $manager::describe_schedule('0 8 * * *'));
        $this->assertSame('Every hour at minute 15', $manager::describe_schedule('15 * * * *'));
        $this->assertSame(
            get_string('agent_cron_weekly_at', 'local_jedomi', ['day' => get_string('monday', 'calendar'), 'time' => '07:30']),
            $manager::describe_schedule('30 7 * * 1')
        );
        $this->assertSame(
            get_string('agent_cron_monthly_at', 'local_jedomi', ['day' => 1, 'time' => '06:00']),
            $manager::describe_schedule('0 6 1 * *')
        );
        $custom = get_string('agent_cron_custom', 'local_jedomi', '0 8-17/2 * * 1-5');
        $this->assertSame($custom, $manager::describe_schedule('0 8-17/2 * * 1-5'));
        $spanish = get_string_manager()->get_string('agent_cron_daily_at', 'local_jedomi', '08:00', 'es');
        $this->assertSame('Cada día a las 08:00', $spanish);

        // Context: the type and the name, in the language of the user.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra I']);
        $coursecontext = \context_course::instance($course->id);
        $this->assertSame(get_string('agent_context_type_site', 'local_jedomi'), $manager::describe_context('site', null));
        $this->assertSame('Course: Algebra I', $manager::describe_context('course', (int) $coursecontext->id));
        $this->assertStringContainsString('#999999', $manager::describe_context('course', 999999));

        // Rows and charts carry readable names: localised role names and status names.
        $creator = $this->getDataGenerator()->create_user();
        $manager::create_agent((object) ['userid' => $creator->id, 'prompt' => 'Course report', 'schedule_cron' => '0 8 * * *',
            'enabled' => 1, 'context_type' => 'course', 'context_id' => $coursecontext->id,
            'role_filter' => json_encode(['editingteacher'])]);
        $stats = $manager::get_stats([]);
        $this->assertSame('Every day at 08:00', $stats->rows[0]->schedule_label);
        $this->assertSame('Course: Algebra I', $stats->rows[0]->context_label);
        $teacher = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $teachername = role_get_name($teacher, \context_system::instance(), ROLENAME_ALIAS);
        $this->assertSame($teachername, $stats->charts->role_labels->editingteacher);
        $this->assertNotSame('editingteacher', $stats->charts->role_labels->editingteacher);
        $this->assertSame(get_string('agent_enabled', 'local_jedomi'), $stats->charts->status_labels->enabled);
        $this->assertSame(get_string('agent_disabled', 'local_jedomi'), $stats->charts->status_labels->disabled);

        // The table and the details show the readable names, the charts use the labels.
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');
        $row = ['id' => 1, 'userid' => 2, 'creator' => 'A', 'name' => 'N', 'schedule' => '0 8 * * *',
            'schedulelabel' => 'Every day at 08:00', 'contextlabel' => 'Course: Algebra I', 'enabled' => true,
            'lastrun' => 0, 'failcount' => 0, 'canmanage' => true];
        $table = $renderer->render_from_template('local_jedomi/admin/agent_table', ['hasrows' => true, 'rows' => [$row]]);
        $this->assertStringContainsString('Every day at 08:00', $table);
        $details = $renderer->render_from_template('local_jedomi/admin/agent_details', $row);
        $this->assertStringContainsString('Course: Algebra I', $details);
        $source = file_get_contents(__DIR__ . '/../../../amd/src/admin/agents.js');
        $this->assertStringContainsString('charts.role_labels', $source);
        $this->assertStringContainsString('charts.status_labels', $source);
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
     * MDL-INT-022: the executions chart counts every run of the last 30 days from the agent_run events of the standard
     * log, only for the agents that match the filters; without the standard log it falls back to the last run.
     */
    public function test_filters_apply_to_the_table_and_the_charts(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $creator = $this->getDataGenerator()->create_user();
        $make = fn(string $prompt, int $enabled): int => \local_jedomi\agent\agent_manager::create_agent((object) [
            'userid' => $creator->id, 'prompt' => $prompt, 'schedule_cron' => '0 8 * * *', 'enabled' => $enabled]);
        $on = $make('Runs three times', 1);
        $off = $make('Runs once', 0);
        \local_jedomi\agent\agent_manager::mark_run($on, true, 'ok');
        \local_jedomi\agent\agent_manager::mark_run($on, false, 'failed');
        \local_jedomi\agent\agent_manager::mark_run($on, true, 'ok');
        \local_jedomi\agent\agent_manager::mark_run($off, true, 'ok');

        $today = static function (\stdClass $stats): int {
            $last = end($stats->charts->executions_30d);
            return $last->count;
        };
        $all = \local_jedomi\agent\agent_manager::get_stats([]);
        $this->assertCount(30, $all->charts->executions_30d);
        $this->assertSame(4, $today($all), 'Every run counts, successful or failed, not only the last one');
        $this->assertSame(3, $today(\local_jedomi\agent\agent_manager::get_stats(['status' => 'enabled'])));
        $this->assertSame(1, $today(\local_jedomi\agent\agent_manager::get_stats(['status' => 'disabled'])));

        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->assertSame(2, $today(\local_jedomi\agent\agent_manager::get_stats([])), 'Without the log, the last run');
    }

    /**
     * MDL-INT-022: the Role and Status filters, sent as the tab sends them, filter the table and the charts, and each row
     * carries the name of its creator.
     *
     * @return void
     */
    public function test_role_and_status_filters_apply_to_the_table_and_the_charts(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Pérez']);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $make = function (string $prompt, int $enabled, ?array $roles) use ($creator): int {
            return \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => $creator->id, 'prompt' => $prompt,
                'schedule_cron' => '0 8 * * *', 'enabled' => $enabled, 'role_filter' => $roles ? json_encode($roles) : null]);
        };
        $make('Teachers on', 1, ['editingteacher']);
        $make('Teachers off', 0, ['editingteacher']);
        $make('Students on', 1, ['student']);

        $stats = \local_jedomi\agent\agent_manager::get_stats(['role' => $teacherrole, 'status' => 'enabled']);
        $this->assertSame(['Teachers on'], array_column($stats->rows, 'prompt'));
        $this->assertSame(['editingteacher' => 1], $stats->charts->by_role);
        $this->assertSame(1, $stats->charts->by_status->enabled);
        $this->assertSame(0, $stats->charts->by_status->disabled);
        $this->assertSame(fullname($creator), $stats->rows[0]->user_fullname);

        $disabled = \local_jedomi\agent\agent_manager::get_stats(['status' => '0']);
        $this->assertSame(['Teachers off'], array_column($disabled->rows, 'prompt'));
        $this->assertSame(1, $disabled->charts->by_status->disabled);
        $this->assertSame(0, $disabled->charts->by_status->enabled);
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

    /**
     * MDL-INT-094: a manager with the permission to manage agents finds a link to the Agents tab in the administration
     * menu; a user without the permission does not, and an administrator keeps the single settings page.
     *
     * @return void
     */
    public function test_managers_reach_the_agents_tab_from_the_menu(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $student = $this->getDataGenerator()->create_user();

        $this->setUser($manager);
        // With its path, from the page up to the root: the page, its category and the root.
        $page = admin_get_root(true, false)->locate('local_jedomi_agents', true);
        $this->assertInstanceOf(\admin_externalpage::class, $page, 'Managers get a link to the Agents tab');
        $this->assertTrue($page->check_access(), 'The link is shown to managers who manage agents');
        $this->assertStringContainsString('/local/jedomi/admin_settings.php?tab=agents', (string) $page->url);
        $this->assertSame('ai', $page->path[1] ?? '', 'The link is in the AI category of the administration menu');

        $this->setUser($student);
        $page = admin_get_root(true, false)->locate('local_jedomi_agents');
        $this->assertFalse($page && $page->check_access(), 'Users without the permission do not see the link');

        $this->setAdminUser();
        $root = admin_get_root(true, false);
        $this->assertNull($root->locate('local_jedomi_agents'), 'Administrators keep the settings page only');
        $this->assertNotNull($root->locate('local_jedomi'));
    }
}
