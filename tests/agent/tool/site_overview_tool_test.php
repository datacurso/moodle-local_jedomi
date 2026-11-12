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

use local_jedomi\agent\agent_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the site_overview tool: the overall state of the platform in one call, for admins and managers only.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\site_overview_tool
 * @covers     \local_jedomi\proxy\handler
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class site_overview_tool_test extends \advanced_testcase {
    /**
     * The overview has exactly the documented sections and fields, and no personal data.
     */
    public function test_overview_has_the_documented_shape(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('lastcronstart', time() - 3 * MINSECS, 'tool_task');

        $overview = json_decode(site_overview_tool::run([], 'admin'), true);

        $sections = ['window_days', 'site', 'users', 'courses', 'enrolments', 'activities', 'agents', 'storage'];
        $this->assertSame($sections, array_keys($overview));
        $this->assertSame(30, $overview['window_days']);
        $site = ['name', 'host', 'moodle_release', 'moodle_version', 'default_language', 'timezone', 'last_cron_run',
            'last_cron_minutes_ago'];
        $this->assertSame($site, array_keys($overview['site']));
        $users = ['total', 'active', 'suspended', 'active_in_window', 'never_logged_in'];
        $this->assertSame($users, array_keys($overview['users']));
        $this->assertSame(['total', 'visible', 'hidden', 'categories', 'without_teacher'], array_keys($overview['courses']));
        $this->assertSame(['active'], array_keys($overview['enrolments']));
        $this->assertSame(['total', 'top_types'], array_keys($overview['activities']));
        $this->assertSame(['total', 'enabled', 'disabled'], array_keys($overview['agents']));
        $this->assertSame(['bytes', 'human'], array_keys($overview['storage']));

        $this->assertSame(parse_url($CFG->wwwroot, PHP_URL_HOST), $overview['site']['host']);
        $this->assertSame($CFG->release, $overview['site']['moodle_release']);
        $this->assertSame($CFG->lang, $overview['site']['default_language']);
        $this->assertGreaterThanOrEqual(3, $overview['site']['last_cron_minutes_ago']);
        $this->assertLessThanOrEqual(4, $overview['site']['last_cron_minutes_ago']);
        $this->assertStringNotContainsString('@', json_encode($overview), 'No email address is returned');
    }

    /**
     * Each figure counts the generated data: users, courses, enrolments, activities, agents and distinct file contents.
     */
    public function test_overview_counts_the_generated_data(): void {
        $this->resetAfterTest();
        $before = site_overview_tool::build(30);
        $gen = $this->getDataGenerator();

        // Users: two never logged in (one of them suspended), one active this week, one active 40 days ago, one deleted.
        $neverseen = $gen->create_user(['lastaccess' => 0]);
        $gen->create_user(['lastaccess' => 0, 'suspended' => 1]);
        $recent = $gen->create_user(['lastaccess' => time() - DAYSECS]);
        $gen->create_user(['lastaccess' => time() - 40 * DAYSECS]);
        delete_user($gen->create_user());

        // Courses: one category, two visible and one hidden; only the first one has a teacher.
        $category = $gen->create_category();
        $withteacher = $gen->create_course(['category' => $category->id]);
        $noteacher = $gen->create_course(['category' => $category->id]);
        $gen->create_course(['category' => $category->id, 'visible' => 0]);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $withteacher->id, 'editingteacher');
        $gen->enrol_user($recent->id, $withteacher->id, 'student');
        $gen->enrol_user($neverseen->id, $noteacher->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        // Activities: three forums and a page.
        foreach ([$withteacher, $withteacher, $noteacher] as $course) {
            $gen->create_module('forum', ['course' => $course->id]);
        }
        $gen->create_module('page', ['course' => $noteacher->id]);

        // Agents: one enabled, one disabled.
        $admin = get_admin();
        agent_manager::create_agent((object) ['userid' => $admin->id, 'prompt' => 'Daily report',
            'schedule_cron' => '0 8 * * *']);
        agent_manager::create_agent((object) ['userid' => $admin->id, 'prompt' => 'Paused report',
            'schedule_cron' => '0 9 * * *', 'enabled' => 0]);

        // Files: the same content twice is stored once; another content adds its own size.
        $fs = get_file_storage();
        $context = \context_system::instance();
        $record = ['contextid' => $context->id, 'component' => 'local_jedomi', 'filearea' => 'test', 'filepath' => '/'];
        $fs->create_file_from_string($record + ['itemid' => 1, 'filename' => 'a.txt'], 'site overview content');
        $fs->create_file_from_string($record + ['itemid' => 2, 'filename' => 'b.txt'], 'site overview content');
        $fs->create_file_from_string($record + ['itemid' => 3, 'filename' => 'c.txt'], 'another');

        $after = site_overview_tool::build(30);

        // Users: five created and kept (the teacher too), one deleted; the teacher has never logged in.
        $this->assertSame($before['users']['total'] + 5, $after['users']['total']);
        $this->assertSame($before['users']['active'] + 4, $after['users']['active']);
        $this->assertSame($before['users']['suspended'] + 1, $after['users']['suspended']);
        $this->assertSame($before['users']['active_in_window'] + 1, $after['users']['active_in_window']);
        $this->assertSame($before['users']['never_logged_in'] + 3, $after['users']['never_logged_in']);
        $longer = site_overview_tool::build(60)['users']['active_in_window'];
        $this->assertSame($before['users']['active_in_window'] + 2, $longer, 'A longer window counts the user active 40 days ago');

        $this->assertSame($before['courses']['total'] + 3, $after['courses']['total']);
        $this->assertSame($before['courses']['visible'] + 2, $after['courses']['visible']);
        $this->assertSame($before['courses']['hidden'] + 1, $after['courses']['hidden']);
        $this->assertSame($before['courses']['categories'] + 1, $after['courses']['categories']);
        $this->assertSame($before['courses']['without_teacher'] + 2, $after['courses']['without_teacher']);

        // The suspended enrolment is not active.
        $this->assertSame($before['enrolments']['active'] + 2, $after['enrolments']['active']);

        $this->assertSame($before['activities']['total'] + 4, $after['activities']['total']);
        $types = array_column($after['activities']['top_types'], 'count', 'type');
        $forumsbefore = array_column($before['activities']['top_types'], 'count', 'type')['forum'] ?? 0;
        $this->assertSame($forumsbefore + 3, $types['forum'] ?? null);
        $this->assertLessThanOrEqual(5, count($types));
        $counts = array_values($types);
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'The most used types come first');

        $agents = ['total' => $before['agents']['total'] + 2, 'enabled' => $before['agents']['enabled'] + 1,
            'disabled' => $before['agents']['disabled'] + 1];
        $this->assertSame($agents, $after['agents']);

        $added = strlen('site overview content') + strlen('another');
        $this->assertSame($before['storage']['bytes'] + $added, $after['storage']['bytes']);
        $this->assertSame(display_size($after['storage']['bytes']), $after['storage']['human']);
    }

    /**
     * Data provider: activity windows given by the model and the window used.
     *
     * @return array
     */
    public static function window_days_provider(): array {
        return [
            'missing' => [null, 30],
            'not a number' => ['week', 30],
            'numeric string' => ['7', 7],
            'zero' => [0, 1],
            'negative' => [-5, 1],
            'too long' => [1000, 365],
            'limit' => [365, 365],
        ];
    }

    /**
     * The activity window is kept between 1 and 365 days, with 30 by default.
     *
     * @dataProvider window_days_provider
     * @param mixed $days Value given by the model.
     * @param int $expected Window used.
     */
    public function test_window_days_is_kept_within_limits($days, int $expected): void {
        $this->assertSame($expected, site_overview_tool::window_days($days));
    }

    /**
     * Only admins and managers are offered the tool and can run it, in the web chat and in Telegram.
     */
    public function test_only_admins_and_managers_get_the_tool(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');

        foreach (['admin' => true, 'manager' => true, 'teacher' => false, 'student' => false, '' => false] as $role => $offered) {
            $names = array_column(array_column(\local_jedomi_get_tool_definitions($role), 'function'), 'name');
            $this->assertSame($offered, in_array('site_overview', $names, true), "Offered to '{$role}'");
            $this->assertSame($offered, site_overview_tool::is_offered($role));
        }

        foreach (['teacher', 'student'] as $role) {
            $denied = json_decode(\local_jedomi\proxy\handler::run_tool('site_overview', [], $role), true);
            $this->assertSame('permission_denied', $denied['error'] ?? null, "The web chat denies '{$role}'");
        }
    }

    /**
     * The web chat dispatcher runs the tool for an admin and a manager and passes the activity window.
     */
    public function test_web_chat_dispatch_runs_the_tool(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (['admin', 'manager'] as $role) {
            $result = json_decode(\local_jedomi\proxy\handler::run_tool('site_overview', ['days' => 7], $role), true);
            $this->assertSame(7, $result['window_days'] ?? null, $role);
            $this->assertArrayHasKey('storage', $result);
        }
    }

    /**
     * Telegram runs the tool for a manager and denies it to a teacher, whatever role the caller claims.
     */
    public function test_telegram_dispatch_follows_the_role_of_the_user(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $manager = $gen->create_user();
        $gen->role_assign('manager', $manager->id);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $gen->create_course()->id, 'editingteacher');

        $telegram = \local_jedomi\telegram\webhook_handler::class;
        $result = json_decode($telegram::execute_tool('site_overview', [], (int) $manager->id), true);
        // The role the caller claims is ignored: it is derived from the user.
        $denied = json_decode($telegram::execute_tool('site_overview', [], (int) $teacher->id, 'admin'), true);

        $this->assertSame(30, $result['window_days'] ?? null);
        $this->assertSame('permission_denied', $denied['error'] ?? null);
    }
}
