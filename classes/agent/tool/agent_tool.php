<?php
/**
 * Agent tool interface — each tool implements this contract.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract for AI-callable tools.
 *
 * Each tool has a name (used by the AI in function_calling),
 * an OpenAI-compatible JSON schema definition, and an execute()
 * method that receives pre-decoded arguments and returns a string
 * result that is fed back to the AI as a "tool" role message.
 */
interface agent_tool {

    /**
     * Unique tool name used in OpenAI function_calling.
     * @return string e.g. "execute_sql"
     */
    public function get_name(): string;

    /**
     * OpenAI-compatible tool definition array.
     * @return array e.g. ['type' => 'function', 'function' => [...]]
     */
    public function get_definition(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  \stdClass $params Decoded arguments from the AI's tool_call.
     * @return string            Result to return to the AI (JSON or text).
     */
    public function execute(\stdClass $params): string;
}
