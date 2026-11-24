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
 * AJAX endpoint — POST agent preview: test a prompt against the AI service + tools.
 *
 * Takes a raw prompt, runs it through the AI client with tool support,
 * and returns the AI's final response (with real data from tools).
 *
 * Same logic as agent_runner but synchronous & for testing; see \local_jedomi\agent\preview_runner.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

header('Content-Type: application/json');

$disabled = local_jedomi_plugin_disabled_error();
if ($disabled !== null) {
    echo json_encode(['ok' => false, 'error' => $disabled['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => get_string('error_post_required', 'local_jedomi')]);
    exit;
}

$prompt = required_param('prompt', PARAM_RAW);
if (trim($prompt) === '') {
    echo json_encode(['ok' => false, 'error' => get_string('required')]);
    exit;
}

// The preview runs in the context chosen for the agent, as its scheduled run will.
$contexttype = optional_param('context_type', 'site', PARAM_ALPHA);
if (!in_array($contexttype, ['site', 'course', 'category', 'user'], true)) {
    $contexttype = 'site';
}
$contextid = optional_param('context_id', 0, PARAM_INT);
// The agent being edited, if any: the query profiler shows the queries as a preview of that agent.
$agentid = optional_param('agent_id', 0, PARAM_INT);
if ($agentid > 0 && !$DB->record_exists('local_jedomi_agents', ['id' => $agentid])) {
    $agentid = 0;
}

echo json_encode(
    \local_jedomi\agent\preview_runner::run(
        $prompt,
        (int) $USER->id,
        $contexttype,
        $contextid > 0 ? $contextid : null,
        max(0, $agentid)
    ),
    JSON_UNESCAPED_UNICODE
);
