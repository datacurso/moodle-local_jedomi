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

namespace local_jedomi\hook;

use local_jedomi\agent\tool\agent_tool;

/**
 * Hook other plugins use to give Jedomi their own tools.
 *
 * Jedomi dispatches it when it builds the tools of a conversation, with the user, the context the
 * assistant is used in (the course of the page, or the system) and the channel. A plugin answers
 * it from its db/hooks.php and adds objects that follow the Jedomi tool contract
 * ({@see agent_tool}) plus a public method
 * `is_available(int $userid, \context $context): bool`: Jedomi offers the tool only when it
 * answers true, so the plugin decides with its own capabilities and settings who gets it.
 *
 * The contract of a registered tool:
 * - it has is_available(); a tool without it is ignored;
 * - its name matches /^[a-z][a-z0-9_]{2,63}$/, is the name of its definition and is not a name
 *   of Jedomi's own tools; otherwise it is ignored;
 * - it declares with a public method `is_read_only(): bool` whether it only reads. Jedomi runs a
 *   reading tool at once; a tool that changes something, or does not declare it, waits for the
 *   user's confirmation like Jedomi's own actions and runs only when the user confirms it;
 * - it runs as the current user ($USER) and checks every permission again on its own: being
 *   offered is never a permission;
 * - its result goes through Jedomi's anonymiser before it reaches the AI service;
 * - a reading tool whose data is not ready yet answers a JSON object with
 *   "meta": {"pending": true, ...} at its top level (the boolean true, nothing else) and no
 *   "error" key. Jedomi then waits {@see \local_jedomi\local\plugin_tool_retry::WAIT_SECONDS}
 *   seconds and runs it again with the same arguments, at most
 *   {@see \local_jedomi\local\plugin_tool_retry::MAX_ATTEMPTS} runs within
 *   {@see \local_jedomi\local\plugin_tool_retry::MAX_TOTAL_SECONDS} seconds, and gives the AI
 *   the last answer, which the AI reports as data that will be ready in about a minute. A tool
 *   that changes something, an error or any other shape is never run again.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to give the Jedomi assistant their own read-only tools.')]
#[\core\attribute\tags('ai', 'local_jedomi')]
final class tool_registry {
    /** @var agent_tool[] Tools added by the plugins, in order. */
    private array $tools = [];

    /**
     * Create the hook.
     *
     * @param int $userid User the tools are offered to.
     * @param \context $context Context the assistant is used in: the course of the page, or the system.
     * @param string $channel Channel of the conversation: web, telegram or agent.
     */
    public function __construct(
        /** @var int User the tools are offered to. */
        private readonly int $userid,
        /** @var \context Context the assistant is used in. */
        private readonly \context $context,
        /** @var string Channel of the conversation. */
        private readonly string $channel
    ) {
    }

    /**
     * User the tools are offered to.
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Context the assistant is used in: the course of the page, or the system outside a course.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->context;
    }

    /**
     * Channel of the conversation: web, telegram or agent.
     *
     * @return string
     */
    public function get_channel(): string {
        return $this->channel;
    }

    /**
     * Add a tool. Jedomi checks it before offering it (see the class description).
     *
     * @param agent_tool $tool The tool.
     * @return void
     */
    public function add_tool(agent_tool $tool): void {
        $this->tools[] = $tool;
    }

    /**
     * Tools added by the plugins, in order, before Jedomi checks them.
     *
     * @return agent_tool[]
     */
    public function get_tools(): array {
        return $this->tools;
    }
}
