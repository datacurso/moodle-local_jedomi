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
 * Create Agent tool — creates a scheduled AI agent.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that creates a new scheduled AI agent record with a prompt
 * and cron schedule, restricted to admin and manager roles.
 */
class create_agent_tool implements agent_tool {
    use acts_for_user;

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'create_agent'.
     */
    public function get_name(): string {
        return 'create_agent';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition requiring prompt and schedule_cron, with optional name, owner, context, and enabled flag.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_agent',
                'description' => get_string('tool_create_agent_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => [
                            'type' => 'string',
                            'description' => get_string('tool_create_agent_prompt', 'local_jedomi'),
                        ],
                        'schedule_cron' => [
                            'type' => 'string',
                            'description' => get_string('tool_create_agent_schedule', 'local_jedomi'),
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Optional human-readable name for the agent.',
                        ],
                        'userid' => [
                            'type' => 'integer',
                            'description' => 'Owner user ID (defaults to the current user).',
                        ],
                        'context_type' => [
                            'type' => 'string',
                            'description' => get_string('tool_create_agent_context_type', 'local_jedomi'),
                        ],
                        'context_id' => [
                            'type' => 'integer',
                            'description' => get_string('tool_create_agent_context_id', 'local_jedomi'),
                        ],
                        'enabled' => [
                            'type' => 'integer',
                            'description' => get_string('tool_create_agent_enabled', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['prompt', 'schedule_cron'],
                ],
            ],
        ];
    }

    /**
     * Validate the prompt and 5-field cron expression, then create the agent via agent_manager.
     *
     * @param \stdClass $params Decoded tool arguments; requires 'prompt' and 'schedule_cron',
     *                          accepts name, userid, context_type, context_id, enabled.
     * @return string JSON-encoded creation result with the new agent id, or an error message.
     */
    public function execute(\stdClass $params): string {
        $role = $this->actor_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $prompt  = trim($params->prompt ?? '');
        $schedule = trim($params->schedule_cron ?? '');
        if (empty($prompt) || empty($schedule)) {
            return json_encode(['error' => 'Both prompt and schedule_cron are required.']);
        }

        $parts = preg_split('/\s+/', $schedule);
        if (count($parts) !== 5) {
            return json_encode(['error' => 'Invalid cron expression. Use 5 fields.']);
        }

        $data = new \stdClass();
        $data->userid       = (int) ($params->userid ?? $this->actor_id());
        if (!\local_jedomi\agent\agent_manager::can_create_for($data->userid, $this->actor_id())) {
            return json_encode(['error' => 'permission_denied']);
        }
        $data->name         = trim($params->name ?? '');
        $data->prompt       = $prompt;
        $data->schedule_cron = $schedule;
        $data->context_type = $params->context_type ?? 'site';
        $data->context_id   = isset($params->context_id) ? (int) $params->context_id : null;
        $data->enabled      = isset($params->enabled) ? (int) $params->enabled : 1;

        $id    = \local_jedomi\agent\agent_manager::create_agent($data);
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        $label = $agent->name ?? "Agent #{$id}";

        return json_encode([
            'success'  => true,
            'agent_id' => $id,
            'name'     => $agent->name ?? '',
            'message'  => "✅ {$label} created successfully.",
        ]);
    }
}
