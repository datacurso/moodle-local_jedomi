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

namespace local_jedomi\local;

use local_jedomi\agent\tool\agent_tool;
use local_jedomi\hook\tool_registry;

/**
 * Tools other plugins give Jedomi through the tool registry hook ({@see tool_registry}).
 *
 * A registered tool is offered only when it is well formed, does not take the name of a Jedomi
 * tool or of an earlier plugin tool, and its plugin says it is available to the user in the
 * context. Being offered never grants anything: the tool runs as the current user and checks its
 * permissions again on its own.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_tools {
    /** @var string Pattern of a plugin tool name. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    /** @var string[] Names of Jedomi's own tools and of their aliases, in every channel: never a plugin tool. */
    public const RESERVED_NAMES = [
        'admin_describe', 'admin_execute', 'admin_search', 'call_webservice', 'create_agent', 'create_agents',
        'delete_agent', 'delete_agents', 'describe_table', 'document_read', 'execute_sql', 'get_site_config',
        'list_agent', 'list_agents', 'my_assignments', 'my_courses', 'my_grades', 'search_tables', 'send_messages',
        'site_overview', 'ws_describe', 'ws_execute', 'ws_search',
    ];

    /**
     * Tools the plugins offer to a user in a context, keyed by name, in the order they were added.
     *
     * @param int $userid User the tools are offered to.
     * @param \context $context Context the assistant is used in: the course of the page, or the system.
     * @param string $channel Channel of the conversation: web, telegram or agent.
     * @return agent_tool[]
     */
    public static function available(int $userid, \context $context, string $channel): array {
        $hook = new tool_registry($userid, $context, $channel);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $tools = [];
        foreach ($hook->get_tools() as $tool) {
            $name = self::checked_name($tool, $tools);
            if ($name !== null && self::is_offered($tool, $userid, $context)) {
                $tools[$name] = $tool;
            }
        }
        return $tools;
    }

    /**
     * Definitions of some tools in the OpenAI function-calling format, in order.
     *
     * @param agent_tool[] $tools Tools from {@see self::available()}.
     * @return array[]
     */
    public static function definitions(array $tools): array {
        return array_values(array_map(static fn(agent_tool $tool): array => $tool->get_definition(), $tools));
    }

    /**
     * Run a tool as the current user with the arguments of the AI.
     *
     * A failure never stops the conversation: the AI gets {"error": "tool_failed", "tool": name}, with the message
     * of a Moodle exception (a refused permission, a wrong argument) as detail. Other errors give no detail, so no
     * path or internal message reaches the AI service.
     *
     * @param agent_tool $tool The tool.
     * @param array $args Arguments decoded from the tool call.
     * @return string The result of the tool.
     */
    public static function execute(agent_tool $tool, array $args): string {
        $error = ['error' => 'tool_failed', 'tool' => $tool->get_name()];
        try {
            $params = json_decode(json_encode((object) $args));
            return $tool->execute($params instanceof \stdClass ? $params : new \stdClass());
        } catch (\moodle_exception $e) {
            return json_encode($error + ['detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            debugging('Jedomi plugin tool ' . $tool->get_name() . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return json_encode($error);
        }
    }

    /**
     * Name of a registered tool when it can be offered: well formed, its definition names it, and it is not a Jedomi
     * tool nor taken by an earlier plugin tool.
     *
     * @param agent_tool $tool The tool.
     * @param agent_tool[] $taken Tools already accepted.
     * @return string|null The name, or null when the tool is ignored.
     */
    private static function checked_name(agent_tool $tool, array $taken): ?string {
        $name = $tool->get_name();
        $problem = null;
        if (!preg_match(self::NAME_PATTERN, $name)) {
            $problem = 'has an invalid name';
        } else if (in_array($name, self::RESERVED_NAMES, true)) {
            $problem = 'takes the name of a Jedomi tool';
        } else if (isset($taken[$name])) {
            $problem = 'takes the name of another plugin tool';
        } else if (!method_exists($tool, 'is_available')) {
            $problem = 'has no is_available() method';
        } else if (($tool->get_definition()['function']['name'] ?? null) !== $name) {
            $problem = 'has a definition with another name';
        }
        if ($problem === null) {
            return $name;
        }
        $message = 'Jedomi ignored the plugin tool ' . s($name) . ' (' . get_class($tool) . '): it ' . $problem . '.';
        debugging($message, DEBUG_DEVELOPER);
        return null;
    }

    /**
     * Whether the plugin of a tool offers it to the user in the context; a failing check hides it.
     *
     * @param agent_tool $tool The tool.
     * @param int $userid User the tools are offered to.
     * @param \context $context Context the assistant is used in.
     * @return bool
     */
    private static function is_offered(agent_tool $tool, int $userid, \context $context): bool {
        try {
            return (bool) $tool->is_available($userid, $context);
        } catch (\Throwable $e) {
            debugging('Jedomi hid the plugin tool ' . s($tool->get_name()) . ': its availability check failed: '
                . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
