<?php
/**
 * Tool executor — receives AI tool_calls, dispatches to the right handler,
 * and returns results.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

defined('MOODLE_INTERNAL') || die();

/**
 * Static facade for tool dispatch.
 *
 * Maintains a registry of available tools. When the AI returns tool_calls,
 * the executor finds the matching tool, runs it, and collects results.
 */
class tool_executor {

    /** @var tool\agent_tool[] Cached tool instances. */
    private static array $instances = [];

    /**
     * Build all tool definitions for the gateway payload.
     *
     * @param  int $agentid Agent ID (for SQL profiler logging).
     * @param  int $userid  Agent creator userid (for WS permission checks).
     * @return array Array of tool definitions compatible with OpenAI's "tools" field.
     */
    public static function get_definitions(int $agentid = 0, int $userid = 0): array {
        $tools = self::get_tools($agentid, $userid);
        $defs = [];
        foreach ($tools as $tool) {
            $defs[] = $tool->get_definition();
        }
        return $defs;
    }

    /**
     * Execute a single tool call.
     *
     * @param  string $name      Tool name (e.g. "execute_sql").
     * @param  string $arguments JSON-encoded arguments from the AI.
     * @return string            Result to return as a "tool" role message.
     */
    public static function execute(string $name, string $arguments): string {
        $tools = self::get_tools();
        $tool = $tools[$name] ?? null;

        if (!$tool) {
            return json_encode(['error' => "Unknown tool: {$name}"]);
        }

        $decoded = json_decode($arguments);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(['error' => 'Invalid arguments JSON: ' . json_last_error_msg()]);
        }

        return $tool->execute($decoded);
    }

    /**
     * Get all registered tool instances, keyed by name.
     *
     * @param  int $agentid
     * @param  int $userid
     * @return tool\agent_tool[]
     */
    private static function get_tools(int $agentid = 0, int $userid = 0): array {
        if (empty(self::$instances) || $agentid > 0) {
            $wstool = new tool\ws_tool($userid);
            self::$instances = [
                'execute_sql' => new tool\sql_tool($agentid, $userid),
                'call_webservice' => $wstool,
                'ws_execute' => $wstool,
                'describe_table' => new tool\describe_table_tool(),
                'search_tables' => new tool\search_tables_tool(),
                'get_site_config' => new tool\site_config_tool($userid),
                'ws_search' => new tool\ws_search_tool(),
                'ws_describe' => new tool\ws_describe_tool(),
            ];
        }
        return self::$instances;
    }
}
