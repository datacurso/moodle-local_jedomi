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
 * AJAX endpoint — POST create, update, or delete an agent.
 *
 * Actions:
 *   - create: inserts a new agent record
 *   - update: modifies an existing agent
 *   - delete: removes an agent and its cache
 *
 * Requires admin + sesskey.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

use local_jedomi\agent\agent_manager;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'ok'    => false,
        'error' => 'POST required',
    ]);
    exit;
}

$action = required_param('action', PARAM_ALPHA);

try {
    switch ($action) {
        case 'create':
            $data = new \stdClass();

            $prompt = required_param('prompt', PARAM_RAW);
            if (trim($prompt) === '') {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('required'),
                ]);
                exit;
            }
            $data->prompt = $prompt;
            $data->name = optional_param('name', '', PARAM_RAW);
            $data->userid = $USER->id;

            // Schedule: support both preset select and custom cron.
            $schedule = required_param('schedule_cron', PARAM_RAW);
            if ($schedule === 'custom') {
                $schedule = required_param('custom_cron', PARAM_RAW);
            }
            // Validate basic cron expression format (5 fields).
            $parts = preg_split('/\s+/', trim($schedule));
            if (count($parts) !== 5) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_error_create', 'local_jedomi', 'Invalid cron expression'),
                ]);
                exit;
            }
            $data->schedule_cron = $schedule;

            $context_type = required_param('context_type', PARAM_ALPHA);
            $valid_contexts = ['site', 'course', 'category', 'user'];
            if (!in_array($context_type, $valid_contexts, true)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_error_create', 'local_jedomi', 'Invalid context type'),
                ]);
                exit;
            }
            $data->context_type = $context_type;
            $data->context_id = optional_param('context_id', null, PARAM_INT);

            // Role filter: array of role IDs.
            $role_ids = optional_param_array('role_filter', [], PARAM_INT);
            if (!empty($role_ids)) {
                // Resolve role IDs to shortnames.
                $shortnames = [];
                foreach ($role_ids as $rid) {
                    $sn = $DB->get_field('role', 'shortname', ['id' => $rid]);
                    if ($sn) {
                        $shortnames[] = $sn;
                    }
                }
                $data->role_filter = json_encode($shortnames);
            } else {
                $data->role_filter = null;
            }

            $data->enabled = optional_param('enabled', 0, PARAM_INT);

            $id = agent_manager::create_agent($data);

            // Reload to get the resolved record.
            $created = agent_manager::get_agent($id);
            $name = $created->name ?? '';

            echo json_encode([
                'ok' => true,
                'id' => $id,
                'name' => $name,
            ]);
            break;

        case 'update':
            $id = required_param('id', PARAM_INT);
            $data = new \stdClass();

            $prompt = optional_param('prompt', '', PARAM_RAW);
            if ($prompt !== '') {
                $data->prompt = $prompt;
            }

            $schedule = optional_param('schedule_cron', '', PARAM_RAW);
            if ($schedule !== '') {
                if ($schedule === 'custom') {
                    $schedule = optional_param('custom_cron', '', PARAM_RAW);
                }
                $parts = preg_split('/\s+/', trim($schedule));
                if (count($parts) === 5) {
                    $data->schedule_cron = $schedule;
                }
            }

            $context_type = optional_param('context_type', '', PARAM_ALPHA);
            if ($context_type !== '') {
                $data->context_type = $context_type;
            }
            $ctx_id = optional_param('context_id', null, PARAM_INT);
            if ($ctx_id !== null) {
                $data->context_id = $ctx_id;
            }

            $role_ids = optional_param_array('role_filter', [], PARAM_INT);
            if (!empty($role_ids)) {
                $shortnames = [];
                foreach ($role_ids as $rid) {
                    $sn = $DB->get_field('role', 'shortname', ['id' => $rid]);
                    if ($sn) {
                        $shortnames[] = $sn;
                    }
                }
                $data->role_filter = json_encode($shortnames);
            }

            $enabled = optional_param('enabled', null, PARAM_INT);
            if ($enabled !== null) {
                $data->enabled = $enabled;
            }

            agent_manager::update_agent($id, $data);

            echo json_encode([
                'ok' => true,
                'id' => $id,
            ]);
            break;

        case 'get':
            $id = required_param('id', PARAM_INT);
            $agent = $DB->get_record('local_jedomi_agents', ['id' => $id]);
            if (!$agent) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_not_found', 'local_jedomi'),
                ]);
                exit;
            }
            // Resolve role_filter shortnames back to role IDs for the form.
            $agent->role_ids = [];
            if (!empty($agent->role_filter)) {
                $shortnames = json_decode($agent->role_filter, true);
                if (is_array($shortnames)) {
                    foreach ($shortnames as $sn) {
                        $rid = $DB->get_field('role', 'id', ['shortname' => $sn]);
                        if ($rid) {
                            $agent->role_ids[] = (int) $rid;
                        }
                    }
                }
            }
            echo json_encode(['ok' => true, 'agent' => $agent]);
            break;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            $agent = $DB->get_record('local_jedomi_agents', ['id' => $id]);
            if (!$agent) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_not_found', 'local_jedomi'),
                ]);
                exit;
            }
            $newenabled = $agent->enabled ? 0 : 1;
            agent_manager::update_agent($id, (object)['enabled' => $newenabled]);
            echo json_encode(['ok' => true, 'enabled' => $newenabled]);
            break;

        case 'run':
            $id = required_param('id', PARAM_INT);
            if (!agent_manager::queue_for_run($id)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_not_found', 'local_jedomi'),
                ]);
                exit;
            }
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = required_param('id', PARAM_INT);
            agent_manager::delete_agent($id);

            echo json_encode([
                'ok' => true,
                'id' => $id,
            ]);
            break;

        default:
            echo json_encode([
                'ok'    => false,
                'error' => 'Unknown action: ' . $action,
            ]);
            break;
    }
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
