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

namespace local_jedomi\agent\tool;

/**
 * Tests for the describe_table tool of the agents.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\describe_table_tool
 */
final class describe_table_tool_test extends \advanced_testcase {
    /**
     * Sensitive tables, as the agent may name them.
     *
     * @return array
     */
    public static function sensitive_table_provider(): array {
        global $CFG;
        return [
            'agents' => ['local_jedomi_agents'],
            'private keys' => ['user_private_key'],
            'config in braces' => ['{config}'],
            'config with prefix' => [($CFG->prefix ?? 'mdl_') . 'config'],
        ];
    }

    /**
     * A sensitive table is refused like in the web chat, without its columns.
     *
     * @dataProvider sensitive_table_provider
     * @param string $table Table name given by the agent.
     */
    public function test_sensitive_table_is_refused(string $table): void {
        $answer = (new describe_table_tool())->execute((object) ['tablename' => $table]);

        $this->assertSame(['error' => 'permission_denied'], json_decode($answer, true));
    }

    /**
     * An ordinary table is described.
     */
    public function test_course_table_is_described(): void {
        $answer = json_decode((new describe_table_tool())->execute((object) ['tablename' => 'course']), true);

        $this->assertArrayNotHasKey('error', $answer);
        $this->assertStringContainsString('fullname', json_encode($answer));
    }

    /**
     * An empty table name and an unknown table give errors in the language of the user, not fixed English text.
     */
    public function test_errors_use_language_strings(): void {
        $tool = new describe_table_tool();
        $empty = json_decode($tool->execute((object) ['tablename' => '  ']), true);
        $missing = json_decode($tool->execute((object) ['tablename' => 'nonexistent_table_xyz']), true);

        $this->assertSame(get_string('telegram_table_name_required', 'local_jedomi'), $empty['error']);
        $this->assertSame(get_string('table_not_found', 'local_jedomi', 'nonexistent_table_xyz'), $missing['error']);
    }
}
