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

/**
 * Base of the read-only tools that give a user their own data (courses, grades, assignments).
 *
 * The user is never an argument: it is the session user in the web chat, the linked user in Telegram and the agent
 * creator in the agents. Any argument the definition does not declare, such as a user id, is rejected.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class own_data_tool implements agent_tool {
    use acts_for_user;

    /** @var string[] Roles the own data tools are offered to. */
    private const OFFERED_ROLES = ['student'];

    /**
     * Whether the own data tools are offered to a role.
     *
     * @param string $role Jedomi role.
     * @return bool
     */
    public static function is_offered(string $role): bool {
        return in_array($role, self::OFFERED_ROLES, true);
    }

    /**
     * Definitions of every own data tool, in the order they are offered.
     *
     * @return array
     */
    public static function definitions(): array {
        return [
            (new my_courses_tool())->get_definition(),
            (new my_grades_tool())->get_definition(),
            (new my_assignments_tool())->get_definition(),
        ];
    }

    /**
     * Names of every own data tool.
     *
     * @return string[]
     */
    public static function names(): array {
        return ['my_courses', 'my_grades', 'my_assignments'];
    }

    /**
     * Run an own data tool by name for a user.
     *
     * @param string $name Tool name.
     * @param array $args Arguments from the AI.
     * @param int|null $userid User the data is read for; null for the session user.
     * @return string|null JSON result, or null when the name is not an own data tool.
     */
    public static function run(string $name, array $args, ?int $userid = null): ?string {
        $tool = match ($name) {
            'my_courses' => new my_courses_tool($userid),
            'my_grades' => new my_grades_tool($userid),
            'my_assignments' => new my_assignments_tool($userid),
            default => null,
        };
        if ($tool === null) {
            return null;
        }
        return $tool->execute((object) $args);
    }

    /**
     * Description language string identifier of the tool.
     *
     * @return string
     */
    abstract protected function description_key(): string;

    /**
     * Whether the tool accepts an optional course filter.
     *
     * @return bool
     */
    protected function accepts_course(): bool {
        return true;
    }

    /**
     * Read the data of the user.
     *
     * @param int $userid User the data is read for.
     * @param int $courseid Only this course; 0 for all.
     * @return array
     */
    abstract protected function read(int $userid, int $courseid): array;

    #[\Override]
    public function get_definition(): array {
        $properties = [];
        if ($this->accepts_course()) {
            $properties['courseid'] = [
                'type' => 'integer',
                'description' => get_string('tool_own_data_courseid', 'local_jedomi'),
            ];
        }
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->get_name(),
                'description' => get_string($this->description_key(), 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                ],
            ],
        ];
    }

    #[\Override]
    public function execute(\stdClass $params): string {
        $allowed = $this->accepts_course() ? ['courseid'] : [];
        $unknown = array_diff(array_keys((array) $params), $allowed);
        if (!empty($unknown)) {
            // The data is always the data of the user the tool runs for: a user id or any other argument is refused.
            return json_encode(['error' => get_string('error_own_data_arguments', 'local_jedomi', implode(', ', $unknown))]);
        }
        $courseid = (int) ($params->courseid ?? 0);
        try {
            return json_encode($this->read($this->actor_id(), $courseid), JSON_UNESCAPED_UNICODE);
        } catch (\moodle_exception $e) {
            $error = $e->errorcode === 'nopermissions' ? 'permission_denied' : \local_jedomi\local\safe_error::for_model($e);
            return json_encode(['error' => $error]);
        }
    }
}
