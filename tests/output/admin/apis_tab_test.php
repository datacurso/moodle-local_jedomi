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
 * Tests for the APIs tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\admin\apis_tab
 */
final class apis_tab_test extends \advanced_testcase {
    /**
     * Build a tab with two rows.
     *
     * @return apis_tab
     */
    private function tab(): apis_tab {
        $table = [
            'rows' => [
                ['name' => 'core_course_get_courses', 'description' => '', 'component' => 'moodle', 'iswrite' => false,
                    'checked' => true],
                ['name' => 'core_course_delete_courses', 'description' => '', 'component' => 'moodle', 'iswrite' => true,
                    'checked' => false],
            ],
            'total' => 60, 'pages' => 3, 'page' => 2, 'count_all' => 70, 'count_read' => 40, 'count_write' => 25,
        ];
        return new apis_tab('teacher', $table, ['moodle', 'mod_forum'], 'course', 'moodle', 'Lectura', 25);
    }

    /**
     * The tab exports the role and type pills, the filters, the rows and the pagination.
     *
     * @return void
     */
    public function test_export_for_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $data = $this->tab()->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertSame('teacher', $data['role']);
        $this->assertSame(['admin', 'manager', 'teacher', 'student'], array_column($data['roles'], 'key'));
        $this->assertSame([false, false, true, false], array_column($data['roles'], 'active'));
        $this->assertSame([70, 40, 25], array_column($data['types'], 'count'));
        $this->assertSame([false, true, false], array_column($data['types'], 'active'));
        $this->assertSame([true, false], array_column($data['components'], 'selected'));
        $this->assertTrue($data['hasrows']);
        $this->assertSame([1, 2, 3], array_column($data['pagination']['pagelinks'], 'page'));
        $this->assertSame([false, true, false], array_column($data['pagination']['pagelinks'], 'active'));
    }

    /**
     * The rendered tab posts the allowlist of the role and marks write functions.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = $PAGE->get_renderer('local_jedomi')->render($this->tab());

        $this->assertStringContainsString('name="ws_teacher[]"', $html);
        $this->assertStringContainsString('value="core_course_get_courses" checked', $html);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('data-region="local_jedomi/admin/apis/rows"', $html);
        $this->assertStringContainsString('local_jedomi-ws-write', $html);
    }

    /**
     * MDL-UNIT-053: each function shows its description and declared type, every function appears in its type filter,
     * and the role row has its own label with translated role buttons.
     *
     * @return void
     */
    public function test_functions_are_described_and_classified_with_translated_labels(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $functions = [];
        require($CFG->dirroot . '/lib/db/services.php');

        $table = \local_jedomi\local\service\admin_settings_service::get_ws_table('admin', 'core_course_', '', 'all', 1, 200);
        $this->assertSame($table['count_all'], $table['count_read'] + $table['count_write'], 'Every function has a type');
        foreach ($table['rows'] as $row) {
            $declared = $functions[$row['name']] ?? [];
            if (!empty($declared['description'])) {
                $this->assertNotSame('', $row['description'], "{$row['name']} shows its description");
            }
            if (isset($declared['type'])) {
                $this->assertSame($declared['type'] === 'write', $row['iswrite'], "{$row['name']} has its declared type");
            }
        }

        $html = $PAGE->get_renderer('local_jedomi')->render($this->tab());
        $this->assertStringContainsString(get_string('ws_role_filter', 'local_jedomi'), $html);
        $this->assertSame(1, substr_count($html, get_string('filter_type', 'local_jedomi')), 'Only the type row is labelled Type');

        // The test dataroot has no language packs: a minimal langconfig lets the string manager read lang/es.
        check_dir_exists($CFG->dataroot . '/lang/es');
        file_put_contents(
            $CFG->dataroot . '/lang/es/langconfig.php',
            "<?php\n\$string['thislanguage'] = 'Español';\n\$string['parentlanguage'] = '';\n"
        );
        $strings = get_string_manager();
        $strings->reset_caches();
        foreach (['admin', 'manager', 'teacher', 'student'] as $role) {
            $this->assertNotSame(
                $strings->get_string('api_role_' . $role, 'local_jedomi', null, 'en'),
                $strings->get_string('api_role_' . $role, 'local_jedomi', null, 'es'),
                "The {$role} button is translated"
            );
        }
    }
}
