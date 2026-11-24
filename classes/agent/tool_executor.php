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

/**
 * Tool executor — receives AI tool_calls, dispatches to the right handler,
 * and returns results.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

/**
 * Tools of one run, prepared for one user.
 *
 * An executor is built for the user the tools run for (the agent creator) and keeps, as its own state, the tool
 * instances and the names of the tools offered to the Jedomi role of that user (READ-003): two runs never share them,
 * and a tool never runs as another user or as user 0 because a previous run prepared it.
 */
class tool_executor {
    /** @var tool\agent_tool[] Tool instances of this run, keyed by name. */
    private array $instances;

    /** @var string[] Names of the tools offered to the role of the user the tools were prepared for. */
    private array $offered = [];

    /**
     * Prepare the tools of a user.
     *
     * The tools are built for the user (the agent creator) and only the tools offered to the Jedomi role of that user
     * (the same list the agent sends to the AI) can be executed afterwards.
     *
     * @param int $agentid Agent ID (for SQL profiler logging).
     * @param int $userid Agent creator userid (for WS permission checks).
     * @param string $channel agent for a scheduled run, preview for a preview (for SQL profiler logging).
     */
    public function __construct(int $agentid = 0, int $userid = 0, string $channel = 'agent') {
        require_once(__DIR__ . '/../../lib.php');
        $this->instances = self::build_tools($agentid, $userid, $channel);
        $role = \local_jedomi_get_user_role_for_user($userid);
        foreach (\local_jedomi_get_tool_definitions($role, $userid, 'agent') as $definition) {
            $this->offered[] = (string) ($definition['function']['name'] ?? '');
        }
    }

    /**
     * Tool definitions for the gateway payload.
     *
     * @return array Array of tool definitions compatible with OpenAI's "tools" field.
     */
    public function get_definitions(): array {
        $defs = [];
        foreach ($this->instances as $name => $tool) {
            if ($name === 'document_read' && !\local_jedomi\ai\model_settings::is_documents_enabled()) {
                // Not offered while documents cannot be read (file processing off, or the custom service); execute()
                // refuses it too.
                continue;
            }
            $defs[] = $tool->get_definition();
        }
        return $defs;
    }

    /**
     * Whether a tool (by any of its names) is offered to the role of the user the tools were prepared for.
     *
     * @param string $name Tool name as the model called it.
     * @return bool
     */
    public function is_offered(string $name): bool {
        [$resolved] = \local_jedomi\local\service\pending_action_service::normalise($name, []);
        return in_array($resolved, $this->offered, true);
    }

    /**
     * Execute a single tool call with the tools of this executor.
     *
     * Only a tool offered to the role of the prepared user runs (JEDOMI-SEC-008); alternative names (plural forms,
     * call_webservice) are resolved first.
     *
     * @param  string $name      Tool name (e.g. "execute_sql").
     * @param  string $arguments JSON-encoded arguments from the AI.
     * @return string            Result to return as a "tool" role message.
     */
    public function execute(string $name, string $arguments): string {
        [$resolved] = \local_jedomi\local\service\pending_action_service::normalise($name, []);
        $tool = $this->is_offered($name) ? ($this->instances[$resolved] ?? null) : null;

        if (!$tool) {
            return json_encode(['error' => get_string('agent_tool_unknown_tool', 'local_jedomi', $name)]);
        }

        $decoded = json_decode($arguments);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(['error' => get_string('agent_tool_invalid_arguments', 'local_jedomi', json_last_error_msg())]);
        }
        if ($decoded === [] || $decoded === null) {
            // No arguments ("[]" from an empty PHP array, or "null").
            $decoded = new \stdClass();
        }
        if (!$decoded instanceof \stdClass) {
            return json_encode(['error' => get_string('agent_tool_arguments_not_object', 'local_jedomi')]);
        }

        return $tool->execute($decoded);
    }

    /**
     * Build the tool instances of a run, keyed by name.
     *
     * @param  int $agentid
     * @param  int $userid
     * @param  string $channel agent or preview.
     * @return tool\agent_tool[]
     */
    private static function build_tools(int $agentid, int $userid, string $channel): array {
        // Tools are built for every run, so a run never keeps the permissions of the user of a previous run.
        $wstool = new tool\ws_tool($userid);
        return [
            // Data tools.
            'execute_sql' => new tool\sql_tool($agentid, $userid, $channel),
            'describe_table' => new tool\describe_table_tool(),
            'search_tables' => new tool\search_tables_tool(),
            'get_site_config' => new tool\site_config_tool($userid),

            // Web service tools.
            'call_webservice' => $wstool,
            'ws_execute' => $wstool,
            'ws_search' => new tool\ws_search_tool($userid),
            'ws_describe' => new tool\ws_describe_tool($userid),

            // Agent management tools.
            'create_agent' => new tool\create_agent_tool($userid),
            'list_agents' => new tool\list_agents_tool($userid),
            'delete_agent' => new tool\delete_agent_tool($userid),

            // Admin tools.
            'admin_search' => new tool\admin_search_tool($userid),
            'admin_describe' => new tool\admin_describe_tool($userid),
            'admin_execute' => new tool\admin_execute_tool($userid),

            // Document reading.
            'document_read' => new tool\document_read_tool($userid),

            // Own data of the user the tools run for (never another user).
            'my_courses' => new tool\my_courses_tool($userid),
            'my_grades' => new tool\my_grades_tool($userid),
            'my_assignments' => new tool\my_assignments_tool($userid),
        ];
    }
}
