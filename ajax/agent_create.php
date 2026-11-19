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
        'error' => get_string('error_post_required', 'local_jedomi'),
    ]);
    exit;
}

$action = required_param('action', PARAM_ALPHA);

try {
    switch ($action) {
        case 'create':
            $data = local_jedomi_agent_form_data(false);
            $data->userid = $USER->id;
            $error = agent_manager::validation_error($data);
            if ($error !== null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_error_create', 'local_jedomi', $error),
                ]);
                exit;
            }

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
            $current = agent_manager::require_manageable_agent($id, (int) $USER->id);
            // The fields sent are saved with the same validation as the creation; the form sends all of them.
            $data = local_jedomi_agent_form_data(true);
            $error = agent_manager::validation_error((object) array_merge((array) $current, (array) $data));
            if ($error !== null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_error_update', 'local_jedomi', $error),
                ]);
                exit;
            }

            // Only the owner may add approved writes (RISK-003).
            agent_manager::update_agent($id, $data, (int) $USER->id);

            echo json_encode([
                'ok' => true,
                'id' => $id,
            ]);
            break;

        case 'get':
            $id = required_param('id', PARAM_INT);
            $agent = agent_manager::require_manageable_agent($id, (int) $USER->id);
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
            // The writes approved for the agent and those its owner may approve, for the form.
            $agent->approved_writes = agent_manager::approved_writes($agent);
            $agent->approvable_writes = agent_manager::approvable_writes((int) $agent->userid);
            echo json_encode(['ok' => true, 'agent' => $agent]);
            break;

        case 'details':
            // Every user who manages agents may view the details; the instruction only when they may read it.
            $id = required_param('id', PARAM_INT);
            $agent = agent_manager::get_agent($id);
            if (!$agent) {
                throw new \moodle_exception('agent_not_found', 'local_jedomi');
            }
            echo json_encode(['ok' => true, 'agent' => agent_manager::agent_details_for_actor($agent, (int) $USER->id)]);
            break;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            $agent = agent_manager::require_manageable_agent($id, (int) $USER->id);
            $newenabled = $agent->enabled ? 0 : 1;
            // Approved writes the owner may no longer approve are removed and the user is told (REL-001).
            $dropped = agent_manager::update_agent($id, (object)['enabled' => $newenabled]);
            $response = ['ok' => true, 'enabled' => $newenabled];
            if (!empty($dropped)) {
                $response['notice'] = get_string('agent_approved_writes_dropped', 'local_jedomi', implode(', ', $dropped));
            }
            echo json_encode($response);
            break;

        case 'run':
            $id = required_param('id', PARAM_INT);
            $agent = agent_manager::require_manageable_agent($id, (int) $USER->id);
            // The scheduled task only runs enabled agents: a disabled one is not queued, and the user is told why.
            if (empty($agent->enabled)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_run_disabled', 'local_jedomi'),
                ]);
                exit;
            }
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
            agent_manager::require_manageable_agent($id, (int) $USER->id);
            agent_manager::delete_agent($id);

            echo json_encode([
                'ok' => true,
                'id' => $id,
            ]);
            break;

        default:
            echo json_encode([
                'ok'    => false,
                'error' => get_string('error_unknown_action', 'local_jedomi', $action),
            ]);
            break;
    }
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => \local_jedomi\local\safe_error::for_user($e, 'ajax/agent_create'),
    ]);
}

/**
 * Agent fields sent by the form of the Agents tab.
 *
 * The schedule is the chosen preset or, with "custom", the custom expression. The context id is the id of the Moodle
 * context (course, category or user context). The form always sends role_filter[] (a hidden 0 when no box is checked,
 * which clears the roles), approved_writes[] (a hidden empty value when no box is checked, which clears them) and
 * enabled (a hidden 0 before the Enabled box). Nothing is validated here: see agent_manager::validation_error().
 *
 * @param bool $onlysent Whether to return only the fields present in the request (update) instead of every field with its
 *     default (create).
 * @return stdClass Fields name, prompt, schedule_cron, context_type, context_id, role_filter, approvedwrites and enabled.
 */
function local_jedomi_agent_form_data(bool $onlysent): stdClass {
    global $DB;
    $default = $onlysent ? null : '';
    $data = new stdClass();

    $name = optional_param('name', $default, PARAM_TEXT);
    if ($name !== null) {
        $data->name = trim($name);
    }
    $prompt = optional_param('prompt', $default, PARAM_RAW);
    if ($prompt !== null) {
        $data->prompt = $prompt;
    }
    $schedule = optional_param('schedule_cron', $default, PARAM_RAW);
    if ($schedule !== null) {
        $schedule = trim($schedule);
        $data->schedule_cron = $schedule === 'custom' ? trim(optional_param('custom_cron', '', PARAM_RAW)) : $schedule;
    }
    $contexttype = optional_param('context_type', $onlysent ? null : 'site', PARAM_ALPHA);
    if ($contexttype !== null) {
        $data->context_type = $contexttype;
        $contextid = optional_param('context_id', 0, PARAM_INT);
        $data->context_id = ($contexttype !== 'site' && $contextid > 0) ? $contextid : null;
    }

    $roleids = optional_param_array('role_filter', $onlysent ? null : [], PARAM_INT);
    if ($roleids !== null) {
        $shortnames = [];
        foreach ($roleids as $roleid) {
            $shortname = $roleid > 0 ? $DB->get_field('role', 'shortname', ['id' => $roleid]) : false;
            if ($shortname) {
                $shortnames[] = $shortname;
            }
        }
        $data->role_filter = empty($shortnames) ? null : json_encode(array_values(array_unique($shortnames)));
    }
    $approved = optional_param_array('approved_writes', $onlysent ? null : [], PARAM_ALPHANUMEXT);
    if ($approved !== null) {
        $data->approvedwrites = array_values(array_filter($approved, static fn($name): bool => $name !== ''));
    }
    $enabled = optional_param('enabled', $onlysent ? null : 0, PARAM_INT);
    if ($enabled !== null) {
        $data->enabled = $enabled ? 1 : 0;
    }
    return $data;
}
