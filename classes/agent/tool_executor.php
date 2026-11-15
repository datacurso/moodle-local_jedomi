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
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

/**
 * Static facade for tool dispatch.
 *
 * Maintains a registry of available tools. When the AI returns tool_calls,
 * the executor finds the matching tool, runs it, and collects results.
 */
class tool_executor {
    /** @var tool\agent_tool[] Cached tool instances. */
    private static array $instances = [];

    /** @var string[]|null Names of the tools offered to the role of the user the tools were prepared for; null when the
     *      tools were not prepared. */
    private static ?array $offered = null;

    /**
     * Build all tool definitions for the gateway payload, and prepare the tools of a user.
     *
     * The tools are built for the user (the agent creator) and only the tools offered to the Jedomi role of that user
     * (the same list the agent sends to the AI) can be executed afterwards.
     *
     * @param  int $agentid Agent ID (for SQL profiler logging).
     * @param  int $userid  Agent creator userid (for WS permission checks).
     * @param  string $channel agent for a scheduled run, preview for a preview (for SQL profiler logging).
     * @return array Array of tool definitions compatible with OpenAI's "tools" field.
     */
    public static function get_definitions(int $agentid = 0, int $userid = 0, string $channel = 'agent'): array {
        require_once(__DIR__ . '/../../lib.php');
        $tools = self::get_tools($agentid, $userid, $channel);
        $role = \local_jedomi_get_user_role_for_user($userid);
        self::$offered = [];
        foreach (\local_jedomi_get_tool_definitions($role, $userid, 'agent') as $definition) {
            self::$offered[] = (string) ($definition['function']['name'] ?? '');
        }
        $defs = [];
        foreach ($tools as $name => $tool) {
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
    public static function is_offered(string $name): bool {
        [$resolved] = \local_jedomi\local\service\pending_action_service::normalise($name, []);
        return self::$offered !== null && in_array($resolved, self::$offered, true);
    }

    /**
     * Forget the prepared tools, so nothing runs until get_definitions() prepares them for a user again.
     *
     * @return void
     */
    public static function reset(): void {
        self::$instances = [];
        self::$offered = null;
    }

    /**
     * Execute a single tool call with the tools prepared by get_definitions().
     *
     * Only a tool offered to the role of the prepared user runs (JEDOMI-SEC-008); alternative names (plural forms,
     * call_webservice) are resolved first. Without prepared tools nothing runs, so a tool never runs as user 0.
     *
     * @param  string $name      Tool name (e.g. "execute_sql").
     * @param  string $arguments JSON-encoded arguments from the AI.
     * @return string            Result to return as a "tool" role message.
     */
    public static function execute(string $name, string $arguments): string {
        require_once(__DIR__ . '/../../lib.php');

        if (self::$offered === null || empty(self::$instances)) {
            return json_encode(['error' => 'tools_not_prepared']);
        }

        [$resolved] = \local_jedomi\local\service\pending_action_service::normalise($name, []);
        $tool = self::is_offered($name) ? (self::$instances[$resolved] ?? null) : null;

        if (!$tool) {
            return json_encode(['error' => "Unknown tool: {$name}"]);
        }

        $decoded = json_decode($arguments);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(['error' => 'Invalid arguments JSON: ' . json_last_error_msg()]);
        }
        if ($decoded === [] || $decoded === null) {
            // No arguments ("[]" from an empty PHP array, or "null").
            $decoded = new \stdClass();
        }
        if (!$decoded instanceof \stdClass) {
            return json_encode(['error' => 'Invalid arguments JSON: an object is expected']);
        }

        return $tool->execute($decoded);
    }

    /**
     * Get all registered tool instances, keyed by name.
     *
     * @param  int $agentid
     * @param  int $userid
     * @param  string $channel agent or preview.
     * @return tool\agent_tool[]
     */
    private static function get_tools(int $agentid = 0, int $userid = 0, string $channel = 'agent'): array {
        // Tools are rebuilt for every preparation, so a run never keeps the permissions of the user of a previous run.
        $wstool = new tool\ws_tool($userid);
        self::$instances = [
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
        return self::$instances;
    }
}
