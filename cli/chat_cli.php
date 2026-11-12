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
 * Jedomi CLI Chat — Full pipeline debug tool.
 *
 * Replicates EXACTLY the web chat pipeline (chatproxy.php → handler.php),
 * including system_message::build(), tool definitions and native function calling.
 * Tools that change data ask the operator to type "yes" before they run, like the web chat's
 * confirmation card.
 *
 * Usage: php cli/chat_cli.php
 * Commands: help, debug on/off, exit
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);
define('AJAX_SCRIPT', false);

// ... ── Security: CLI only ─────────────────────────────────────────────────────
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- CLI guard runs before config.php.
if (isset($_SERVER['REMOTE_ADDR']) || PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. This script can only be run from the command line.');
}

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/proxy/system_message.php');

global $DB, $CFG, $USER;

// ... ── Jedomi disabled in the Datacurso AI provider: no AI call is made ──────
$disabled = \local_jedomi_plugin_disabled_error();
if ($disabled !== null) {
    cli_error($disabled['message']);
}

// ... ── Log in as admin ──────────────────────────────────────────────────────
$admin = get_admin();
if ($admin) {
    $USER = $admin;
    \core\session\manager::set_user($admin);
}

// ... ── AI settings ───────────────────────────────────────────────────────────
// Every call goes through the Datacurso AI provider; the model is the site setting.
$model = \local_jedomi\ai\model_settings::get_model();

// ... ── State ─────────────────────────────────────────────────────────────────
$debug = true;
$messages = [];

// ... ── Header ────────────────────────────────────────────────────────────────
echo "\n" . str_repeat("=", 60) . "\n";
echo "  JEDOMI CLI CHAT — FULL PIPELINE DEBUG\n";
echo "  User: " . ($USER->id ?? 'none') . " | Model: {$model}\n";
echo "  AI route: " . \local_jedomi\ai\client::PATH . " (Datacurso AI provider)\n";
echo str_repeat("=", 60) . "\n";
echo "  Commands: help, debug on/off, exit\n\n";

// ... ── Build system message + tools (EXACTLY like chatproxy) ─────────────────
$role = 'admin';
$spt  = ['user_id' => $USER->id, 'course_id' => 0, 'role' => $role];
$context = ['location' => 'cli', 'course_name' => '', 'page_url' => '', 'page_title' => ''];

$systemmsg = \local_jedomi\proxy\system_message::build($role, $spt, $context);
$messages[] = $systemmsg;

$tools = \local_jedomi_get_tool_definitions($role);
// Convert the tool definitions into an OpenAI tools array.
$openaitools = [];
foreach ($tools as $t) {
    $openaitools[] = [
        'type' => 'function',
        'function' => $t['function'],
    ];
}

if ($debug) {
    echo "  Available tools: " . count($openaitools) . "\n";
    foreach ($openaitools as $t) {
        echo "    - {$t['function']['name']}\n";
    }
    echo "\n";
}

// ... ── Function: call the AI service (no streaming) ─────────────────────────
/**
 * Sends the conversation to the AI service through the Jedomi AI client and returns the assistant message.
 *
 * @param array $messages full conversation history in OpenAI chat format
 * @param array $tools OpenAI-format tool definitions to offer the model (empty for none)
 * @return array|null the assistant message, or null when the call failed (the error is printed)
 */
function call_ai(array $messages, array $tools = []): ?array {
    global $USER;

    try {
        $answer = \local_jedomi\ai\client::instance()->complete($messages, (int) $USER->id, $tools);
    } catch (\Throwable $e) {
        $error = \local_jedomi\ai\error_message::from_exception($e);
        echo "  ⚠️  AI ERROR ({$error['code']}): {$error['message']}\n";
        return null;
    }

    $message = ['role' => 'assistant', 'content' => $answer['content'] !== '' ? $answer['content'] : null];
    if (!empty($answer['tool_calls'])) {
        $message['tool_calls'] = $answer['tool_calls'];
    }
    return $message;
}

// ... ── Function: run a tool (EXACTLY like handler.php) ───────────────────────
/**
 * Executes a tool call through the proxy handler using the admin role.
 *
 * A tool that changes data (the same ones the web chat holds for confirmation) runs only after the
 * operator confirms it; otherwise the model receives an error result and nothing is changed.
 *
 * @param string $name tool name requested by the model
 * @param array $args decoded tool call arguments
 * @return string JSON-encoded tool result
 */
function execute_tool(string $name, array $args): string {
    $severity = \local_jedomi\local\service\pending_action_service::severity($name, $args);
    if ($severity !== null && !confirm_tool($name, $args, $severity)) {
        return json_encode(['error' => 'The operator cancelled this action; it was not run.']);
    }
    return \local_jedomi\proxy\handler::run_tool($name, $args, 'admin');
}

/**
 * Asks the operator to confirm a tool call that changes data.
 *
 * Only an explicit "yes" confirms it; an empty answer, any other answer or a closed input (for example
 * when the script is not run from an interactive terminal) cancels it.
 *
 * @param string $name tool name requested by the model
 * @param array $args decoded tool call arguments
 * @param string $severity severity of the action, as given by pending_action_service::severity()
 * @return bool true when the operator confirmed the action
 */
function confirm_tool(string $name, array $args, string $severity): bool {
    echo "  \e[1;31m⚠️  {$name} changes data (severity: {$severity}).\e[0m\n";
    // Every argument is shown, never a cut: the operator confirms exactly what runs.
    echo "  Arguments:\n" . json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    echo "  Type \"yes\" to run it, anything else to cancel: ";
    $answer = readline();
    $confirmed = $answer !== false && strtolower(trim($answer)) === 'yes';
    echo $confirmed ? "  Confirmed.\n" : "  \e[33mCancelled.\e[0m\n";
    return $confirmed;
}

// ... ── Main Loop ─────────────────────────────────────────────────────────────
while (true) {
    echo "\n\e[1;33m>>> \e[0m";
    $input = readline();
    if ($input === false || $input === 'exit') {
        break;
    }
    $input = trim($input);
    if (empty($input)) {
        continue;
    }

    if ($input === 'debug on') {
        $debug = true;
        echo "  Debug ON\n";
        continue;
    }
    if ($input === 'debug off') {
        $debug = false;
        echo "  Debug OFF\n";
        continue;
    }
    if ($input === 'help') {
        echo "\n";
        echo "  ─── COMMANDS ─────────────────────────────────────\n";
        echo "  help              Show this help\n";
        echo "  debug on/off      Show or hide the raw tool calls\n";
        echo "  exit              Quit\n";
        echo "\n";
        echo "  Anything else is sent as a message to the AI.\n";
        echo "  The AI has access to " . count($openaitools) . " tools:\n";
        foreach ($openaitools as $t) {
            echo "    - {$t['function']['name']}\n";
        }
        echo "\n";
        echo "  TYPICAL FLOW:\n";
        echo "  The AI first searches (ws_search), describes (ws_describe) and\n";
        echo "  then executes (ws_execute). If a web service fails because of\n";
        echo "  missing optional parameters, it retries with default values.\n";
        echo "  ──────────────────────────────────────────────────\n";
        continue;
    }

    $messages[] = ['role' => 'user', 'content' => $input];

    $turn = 0;
    while ($turn < 25) {
        $turn++;
        echo "\n  \e[2m⏳ Turn {$turn}/25 — AI thinking...\e[0m\n";

        $response = call_ai($messages, $openaitools);
        if ($response === null) {
            break;
        }

        $messages[] = $response;

        // ... ── Text response ──────────────────────────────────────────────
        if (!empty($response['content'])) {
            echo "\n  \e[1;32m🤖:\e[0m {$response['content']}\n";
        }

        // ... ── Tool calls ─────────────────────────────────────────────────
        if (!empty($response['tool_calls'])) {
            foreach ($response['tool_calls'] as $tc) {
                $tcid   = $tc['id'];
                $tcname = $tc['function']['name'];
                $tcargs = json_decode($tc['function']['arguments'], true) ?? [];

                if ($debug) {
                    echo "  \e[36m🔧 Tool call: {$tcname}(" .
                         json_encode($tcargs, JSON_UNESCAPED_UNICODE) . ")\e[0m\n";
                }

                // Execute.
                echo "  \e[35m⚡ Executing {$tcname}...\e[0m\n";
                $result = execute_tool($tcname, $tcargs);

                // Parse the result to show a summary.
                $resultparsed = json_decode($result, true);
                $iserror = isset($resultparsed['error']);

                // Show a summary of the result.
                if ($iserror) {
                    echo "  \e[31m❌ Error: " . mb_substr($resultparsed['error'], 0, 200) . "\e[0m\n";
                } else {
                    // Try to show a useful summary.
                    $summary = '';
                    if (isset($resultparsed['result'])) {
                        $inner = json_decode($resultparsed['result'], true);
                        if (is_array($inner)) {
                            $summary = count($inner) . ' result(s)';
                            if (count($inner) > 0 && is_array($inner[0])) {
                                $summary .= ' (' . implode(', ', array_slice(array_keys($inner[0]), 0, 4)) . ')';
                            }
                        } else {
                            $summary = mb_substr($resultparsed['result'], 0, 80);
                        }
                    } else if (isset($resultparsed['success'])) {
                        $summary = 'success: ' . (isset($resultparsed['data']) ? 'data received' : 'true');
                    } else if (isset($resultparsed['rows'])) {
                        $summary = count($resultparsed['rows']) . ' rows';
                    } else {
                        $summary = 'OK (' . mb_substr($result, 0, 80) . ')';
                    }
                    echo "  \e[32m✅ {$summary}\e[0m\n";
                }

                if ($debug && $result) {
                    echo "  \e[2m  Result raw: " . mb_substr($result, 0, 300) . "\e[0m\n";
                }

                // Add the result to the messages.
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tcid,
                    'content' => $result,
                ];
            }
        } else {
            // No tool calls → final answer.
            break;
        }
    }

    if ($turn >= 25) {
        echo "  \e[31m✗ Maximum of 25 turns reached\e[0m\n";
    }
}

echo "\nBye!\n";
