<?php
/**
 * Create Agent tool — creates a scheduled AI agent.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class create_agent_tool implements agent_tool {

    public function get_name(): string {
        return 'create_agent';
    }

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

    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
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

        global $USER;
        $data = new \stdClass();
        $data->userid       = (int) ($params->userid ?? $USER->id);
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
