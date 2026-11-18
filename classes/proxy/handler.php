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

/**
 * AI Chat Proxy — tool-calling loop and SSE streaming handler.
 *
 * Contains the main tool loop (run), the AI client call,
 * tool execution dispatch, all individual tool functions, and SSE
 * helper methods. No in-memory cache, no query history tracking.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\service\message_service;
use local_jedomi\local\service\pending_action_service;

/**
 * AI chat proxy handler: runs the tool-calling loop, dispatches tool executions and streams SSE.
 */
class handler {
    /** @var string[] Locations the panel sends for its page (see local_jedomi_page_location()). */
    private const PAGE_LOCATIONS = ['course', 'activity', 'grades', 'admin', 'dashboard', 'messages', 'profile', 'calendar',
        'files', 'unknown'];

    /** @var int Messages of the browser history kept for a request: the panel sends at most this many. */
    public const MAX_BROWSER_MESSAGES = 30;

    /** @var string[] Roles the browser may send: tool calls, tool results and system rules come only from the server. */
    private const BROWSER_ROLES = ['user', 'assistant'];

    /**
     * Refuse the database and web service tools when Jedomi is disabled in the Datacurso AI provider (when installed:
     * without it the chat cannot reach the AI at all).
     */
    private static function require_enabled(): void {
        if (class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            \aiprovider_datacurso\local\plugin_settings::require_enabled('local_jedomi');
        }
    }

    /**
     * Run the tool loop for a web chat request, ignoring any model the browser sends.
     *
     * @param array  $input  Decoded browser payload (messages, context, voice_mode; any "model" is ignored).
     *                       voice_mode asks for answers fit to be read aloud, only while the voice conversation is on.
     * @param string $role   User role (already validated).
     * @param array  $spt    SPT payload (already validated).
     * @param int    $userid Moodle user the AI calls are made for.
     */
    public static function run_from_request(array $input, string $role, array $spt, int $userid): void {
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];
        // Only user questions and assistant texts come from the browser (JEDOMI-SEC-002); the channel and the output
        // of the system message are fixed by the server for the web chat, never taken from the browser.
        $messages = self::browser_messages(is_array($input['messages'] ?? null) ? $input['messages'] : []);
        $context['channel'] = 'chat';
        $context['output'] = 'web';

        // Disabled in the Datacurso AI provider: answer its message and make no AI call.
        $disabled = \local_jedomi_plugin_disabled_error();
        if ($disabled !== null) {
            self::send_error($disabled);
            return;
        }

        // The course is the one signed in the permissions. The panel sends the course of its page as the server gave it
        // (context.course_id); a different one is refused, and a request without it is taken as sent outside a course,
        // so only permissions signed for the site accept it. The signed course is then checked again on every request
        // with the same rules as the panel: the user must still be able to enter it, it must be available and the
        // user must have a visible role. Nothing is sent to the AI otherwise.
        $courseid = (int) ($spt['course_id'] ?? 0);
        $pagecourseid = is_numeric($context['course_id'] ?? null) ? (int) $context['course_id'] : 0;
        $denied = \local_jedomi_signed_course_error($courseid, $pagecourseid);
        if ($denied !== null) {
            self::send_error($denied);
            return;
        }

        // The role is computed from the session user: the presented one can never be above it.
        $role = \local_jedomi_effective_chat_role($role, $userid, $courseid);

        // The activity of the page is used only when it belongs to the signed course and the user can see it; its type
        // and name are read from it, never from the browser.
        $context = self::with_checked_activity($context, $courseid);
        // The name of the course is read from the signed course the user can enter; the title of the page and any other
        // free text of the browser never reach the system message (JEDOMI-SEC-002).
        $context = self::with_server_course_name($context, $courseid, $userid);
        unset($context['page_title']);

        // Images are read by the vision model before they are sent (read_file.php), so the chat model
        // only gets text. A new image part means the browser skipped that step.
        $last = end($messages);
        if (is_array($last) && self::has_image_parts($last) && !\local_jedomi\ai\model_settings::is_files_enabled()) {
            self::send_error(['code' => 'files_disabled', 'message' => get_string('files_error_disabled', 'local_jedomi')]);
            return;
        }
        $messages = array_map([self::class, 'without_image_parts'], $messages);

        // One anonymiser for the whole request: the messages, the page context and the tool results share its
        // placeholders, so the arguments the AI proposes and its answer get the real values back.
        $anonymizer = new \local_jedomi\local\anonymizer();
        $context = self::anonymize_context($context, $anonymizer);

        $voicemode = !empty($input['voice_mode']) && \local_jedomi\ai\model_settings::is_voice_enabled();
        $system = system_message::build($role, $spt, $context, $voicemode);
        // The signed course is also the context the tools of other plugins are offered in.
        $requestcontext = $courseid > SITEID
            ? (\context_course::instance($courseid, IGNORE_MISSING) ?: \context_system::instance())
            : \context_system::instance();
        if (self::run(array_merge([$system], $messages), $role, $userid, $anonymizer, $requestcontext)) {
            // Audit trail without the conversation: who was answered, where and through which channel.
            \local_jedomi\event\chat_answered::create_for_channel('web', $requestcontext, $userid)->trigger();
        }
    }

    /**
     * Chat history sent by the browser, rebuilt with the messages it may send (JEDOMI-SEC-002).
     *
     * The panel sends only the questions of the user and the texts of the answers. Everything else is dropped rather
     * than refused, so a forged message never reaches the AI and a valid conversation still gets its answer: system
     * messages (the only one is the server's), tool results, assistant messages with tool calls and messages with an
     * unknown role or shape. Kept messages carry only their role and content: other keys (tool_calls, tool_call_id,
     * name, id) are dropped. An assistant message must be text; a user message may also be a list of parts, whose
     * images are handled later. Only the latest MAX_BROWSER_MESSAGES are kept.
     *
     * @param array $messages Messages decoded from the browser payload.
     * @return array List of ['role' => 'user'|'assistant', 'content' => string|array].
     */
    public static function browser_messages(array $messages): array {
        $kept = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, self::BROWSER_ROLES, true)) {
                continue;
            }
            $role = $message['role'];
            $content = $message['content'] ?? null;
            if ($role === 'assistant' && (!empty($message['tool_calls']) || !is_string($content))) {
                continue;
            }
            if (!is_string($content) && !($role === 'user' && is_array($content))) {
                continue;
            }
            $kept[] = ['role' => $role, 'content' => $content];
        }
        return array_slice($kept, -self::MAX_BROWSER_MESSAGES);
    }

    /**
     * Page context sent by the browser with its free texts anonymised, before they are written in the system message.
     *
     * The names of the course and the activity, read on the server, can name a person, and so can the file name in the
     * path of the page. The address keeps only its path: the query string and the fragment (ids, searches, names) are
     * never sent. The instructions of the system message are never anonymised: only these texts of the browser are.
     * The location and the activity type are names, not free text: a location the panel does not use becomes
     * "unknown", an activity type that is not a plugin name is dropped, and the activity id is a number.
     *
     * @param array $context Page context sent by the browser.
     * @param \local_jedomi\local\anonymizer $anonymizer Anonymiser of the request.
     * @return array Context with the same keys.
     */
    private static function anonymize_context(array $context, \local_jedomi\local\anonymizer $anonymizer): array {
        if (isset($context['page_url']) && is_string($context['page_url'])) {
            $context['page_url'] = system_message::page_path($context['page_url']);
        }
        if (array_key_exists('location', $context) && !in_array($context['location'], self::PAGE_LOCATIONS, true)) {
            $context['location'] = 'unknown';
        }
        if (isset($context['activity_type'])) {
            $type = $context['activity_type'];
            $context['activity_type'] = is_string($type) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) ? $type : '';
        }
        if (isset($context['activity_id'])) {
            $context['activity_id'] = is_numeric($context['activity_id']) ? (int) $context['activity_id'] : 0;
        }
        foreach (['course_name', 'page_url', 'activity_name'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $context[$key] = $anonymizer->anonymize_text($context[$key]);
            }
        }
        return $context;
    }

    /**
     * Page context with its activity checked against the signed course: an activity of another course, missing or
     * hidden from the user is dropped, and the type and name of a valid one come from the activity itself.
     *
     * @param array $context Page context sent by the browser.
     * @param int $courseid Course signed in the permissions (0 or the site course outside a course).
     * @return array Context with activity_id, activity_type and activity_name checked.
     */
    private static function with_checked_activity(array $context, int $courseid): array {
        $cmid = is_numeric($context['activity_id'] ?? null) ? (int) $context['activity_id'] : 0;
        $cm = \local_jedomi_page_activity($courseid, $cmid);
        $context['activity_id'] = $cm ? (int) $cm->id : 0;
        $context['activity_type'] = $cm ? $cm->modname : '';
        $context['activity_name'] = $cm ? $cm->get_formatted_name() : '';
        if (!$cm && ($context['location'] ?? '') === 'activity') {
            $context['location'] = $courseid > SITEID ? 'course' : 'unknown';
        }
        return $context;
    }

    /**
     * Page context with the name of its course read on the server (JEDOMI-SEC-002): the name the browser sends is
     * replaced by the full name of the signed course when the user can enter it, and dropped otherwise.
     *
     * @param array $context Page context sent by the browser.
     * @param int $courseid Course signed in the permissions (0 or the site course outside a course).
     * @param int $userid Session user.
     * @return array Context with course_name read from Moodle, or '' for none.
     */
    private static function with_server_course_name(array $context, int $courseid, int $userid): array {
        global $DB;
        $context['course_name'] = '';
        if ($courseid <= SITEID) {
            return $context;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course && can_access_course($course, $userid)) {
            $coursecontext = \context_course::instance($courseid);
            $context['course_name'] = format_string($course->fullname, true, ['context' => $coursecontext]);
        }
        return $context;
    }

    /**
     * Send an error event to the browser: {"error": {"code", "message"}} with a message ready to show.
     *
     * @param array $error Error code and message.
     */
    private static function send_error(array $error): void {
        echo "data: " . json_encode(['error' => $error], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Whether a message has image parts.
     *
     * @param array $message Chat message.
     * @return bool
     */
    private static function has_image_parts(array $message): bool {
        if (!is_array($message['content'] ?? null)) {
            return false;
        }
        foreach ($message['content'] as $part) {
            if (is_array($part) && ($part['type'] ?? '') !== 'text') {
                return true;
            }
        }
        return false;
    }

    /**
     * The message with only its text: image parts, from conversations started before images were
     * read by the vision model, are dropped.
     *
     * @param mixed $message Chat message.
     * @return mixed
     */
    private static function without_image_parts($message) {
        if (!is_array($message) || !is_array($message['content'] ?? null)) {
            return $message;
        }
        $texts = [];
        foreach ($message['content'] as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') {
                $texts[] = (string) ($part['text'] ?? '');
            }
        }
        $message['content'] = implode("\n\n", $texts);
        return $message;
    }

    /**
     * Run the tool-calling loop using native OpenAI function calling.
     *
     * Passes tool definitions to the model via the API. The model returns structured
     * tool_calls (no text parsing). Tool results are re-injected as role=tool messages
     * (OpenAI standard). The final text is sent to the browser as SSE chunks.
     *
     * The text of the user and assistant messages (the question and the history the browser sends back, with the
     * real values) is anonymised with the same anonymiser as the tool results; the system message is not.
     *
     * @param array  $messages Conversation messages (system message first).
     * @param string $role     User role (already validated).
     * @param int    $userid   Moodle user the AI calls are made for.
     * @param \local_jedomi\local\anonymizer|null $anonymizer Anonymiser of the request; null for a new one.
     * @param \context|null $toolcontext Context the tools of other plugins are offered in (the signed course or the
     *                                   system); null offers none.
     * @return bool True when an answer of the AI was sent to the user, false on an error or a stopped answer.
     */
    public static function run(
        array $messages,
        string $role,
        int $userid,
        ?\local_jedomi\local\anonymizer $anonymizer = null,
        ?\context $toolcontext = null
    ): bool {
        require_once(__DIR__ . '/../../lib.php');
        \local_jedomi_log('RUN_START', ['role' => $role, 'user_message_count' => count($messages)]);

        $tools = spt_validator::get_tool_definitions($role);
        if (message_service::can_send($userid)) {
            // Offered only in the web chat, where the user confirms each send.
            $tools[] = message_service::tool_definition();
        }
        // Tools of other plugins (tool registry hook), offered by each plugin to this user in this context. Only these
        // can run under their names: a plugin tool that was not offered is an unknown tool.
        $plugintools = $toolcontext !== null ? \local_jedomi\local\plugin_tools::available($userid, $toolcontext, 'web') : [];
        $tools = array_merge($tools, \local_jedomi\local\plugin_tools::definitions($plugintools));
        $toolrepeatcounts = [];
        // Personal data of the messages and of the tool results reaches the AI anonymised with the level of the
        // Privacy tab; the answer shown to the user gets the real values back.
        $anonymizer = $anonymizer ?? new \local_jedomi\local\anonymizer();
        $messages = $anonymizer->anonymize_messages($messages);
        // Tool results reach the AI as untrusted data, as in the agents (JEDOMI-SEC-005): the rule of the system
        // message tells the AI never to follow what is written inside them. They are anonymised first, so the
        // placeholders inside the blocks are restored in the answer as before.
        $untrusted = new \local_jedomi\local\untrusted();

        for ($i = 0; $i < 50; $i++) {
            echo ": thinking\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            // The user stopped the answer (the browser closed the request): no further AI round is asked for, so
            // nothing more is charged. A tool already run or an action already held stays as it is.
            if (connection_aborted()) {
                \local_jedomi_log('CLIENT_ABORTED', ['iteration' => $i]);
                return false;
            }

            $response = self::call_ai($messages, $tools, $userid);

            if ($response['type'] === 'error') {
                echo "data: " . json_encode(['error' => $response['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                return false;
            }

            if ($response['type'] === 'text') {
                \local_jedomi_log('AI_RESPONSE_TEXT', ['iteration' => $i, 'length' => mb_strlen($response['content'])]);
                // No tool calls — stream the final response to the client, without markers it echoed (REL-003).
                $answer = \local_jedomi\local\untrusted::strip_markers($response['content']);
                self::stream_sse_content($anonymizer->restore($answer));
                return true;
            }

            // Type === 'tool_calls': execute each one and collect results.
            $toolcalls = $response['tool_calls'];
            \local_jedomi_log('AI_TOOL_CALLS', [
                'iteration' => $i,
                'tool_calls_count' => count($toolcalls),
                'tool_names' => array_map(fn($tc) => $tc['function']['name'] ?? '?', $toolcalls),
            ]);

            // Add the assistant message with tool_calls to conversation history.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $toolcalls,
            ];

            foreach (array_values($toolcalls) as $position => $tc) {
                $name = $tc['function']['name'];
                $tcid = $tc['id'];
                $argsraw = $tc['function']['arguments'];
                $args = is_array($argsraw) ? $argsraw : (json_decode($argsraw, true) ?? []);
                // The AI only knows the placeholders of the anonymised data; the tools need the real values.
                $args = $anonymizer->restore_value($args);

                // JEDOMI-SEC-008: a tool that was not offered to the role is neither held nor run.
                $offered = self::is_offered_tool($name, $tools);

                // A tool that changes data does not run now: it waits for the user's confirmation
                // and the turn ends, so the model cannot act on a result that does not exist yet.
                // A plugin tool that does not declare it only reads waits for the confirmation too.
                if (!$offered) {
                    $pending = null;
                } else if (isset($plugintools[$name]) && !\local_jedomi\local\plugin_tools::is_read_only($plugintools[$name])) {
                    $pending = pending_action_service::hold_plugin_tool($userid, $name, $args, $toolcontext);
                } else {
                    $pending = pending_action_service::hold($userid, $name, $args);
                }
                if ($pending !== null) {
                    \local_jedomi_log('TOOL_HELD', ['tool' => $name, 'severity' => $pending['severity']]);
                    echo "data: " . json_encode(['_jedomi_pending_action' => $pending], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    // The turn ends here: the next calls of this answer are not run, and the user and the model
                    // (through the saved answer) are told so, so they can be asked for again.
                    $notrun = count($toolcalls) - $position - 1;
                    $text = get_string('pending_action_needed', 'local_jedomi');
                    if ($notrun > 0) {
                        $text .= ' ' . get_string('pending_action_others_not_run', 'local_jedomi', $notrun);
                        \local_jedomi_log('TOOLS_NOT_RUN', ['count' => $notrun]);
                    }
                    self::stream_sse_content($text);
                    return true;
                }

                $callkey = $name . '|' . self::stable_tool_args_signature($args);
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;

                // Notify the client that a tool is being executed.
                echo "data: " . json_encode([
                    '_jedomi_tool_executing' => ['tool' => $name, 'args' => $args],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                if ($toolrepeatcounts[$callkey] > 5) {
                    $result = json_encode([
                        'error' => 'loop_detected',
                        'tool' => $name,
                        'detail' => get_string('error_tool_loop_detected', 'local_jedomi'),
                    ]);
                    \local_jedomi_log('LOOP_DETECTED', ['tool' => $name, 'count' => $toolrepeatcounts[$callkey]]);
                } else if (!$offered) {
                    \local_jedomi_log('TOOL_NOT_OFFERED', ['tool' => $name]);
                    $result = json_encode(['error' => get_string('error_unknown_tool', 'local_jedomi', $name)]);
                } else {
                    try {
                        $result = isset($plugintools[$name])
                            ? self::execute_plugin_tool($plugintools[$name], $args, $role)
                            : self::execute_tool_by_name($name, $args, $role);
                    } catch (\Throwable $e) {
                        $result = json_encode([
                            'error' => 'tool_failed',
                            'tool'  => $name,
                            'detail' => get_class($e) . ': ' . $e->getMessage(),
                        ]);
                    }
                }

                // Send result summary to client.
                echo "data: " . json_encode([
                    '_jedomi_tool_result' => [
                        'tool'    => $name,
                        'summary' => self::summarize_tool_result($name, $result),
                    ],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Inject tool result as role=tool message (OpenAI standard format), as untrusted data.
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcid,
                    'content'      => $untrusted->wrap('tool ' . $name, $anonymizer->anonymize_tool_result($result)),
                ];

                \local_jedomi_log('TOOL_RESULT', ['tool' => $name, 'summary' => self::summarize_tool_result($name, $result)]);
            }
        }

        \local_jedomi_log('RUN_FALLBACK_ITERATIONS_EXCEEDED', ['iteration_count' => 10]);
        self::stream_sse_content(get_string('error_fallback_iterations', 'local_jedomi'));
        return false;
    }

    /**
     * Whether a tool, by any of its names (plural forms are resolved first), is one of the tools offered in this turn.
     *
     * @param string $name Tool name as the model called it.
     * @param array $tools Tool definitions offered to the model.
     * @return bool
     */
    private static function is_offered_tool(string $name, array $tools): bool {
        [$resolved] = pending_action_service::normalise($name, []);
        foreach ($tools as $tool) {
            if (($tool['function']['name'] ?? null) === $resolved) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a deterministic signature for tool arguments.
     *
     * @param  array $args Tool arguments.
     * @return string
     */
    private static function stable_tool_args_signature(array $args): string {
        $normalized = self::sort_recursive($args);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    }

    /**
     * Recursively sort associative arrays for stable hashing.
     *
     * @param  mixed $value
     * @return mixed
     */
    private static function sort_recursive($value) {
        if (!is_array($value)) {
            return $value;
        }

        $islist = array_keys($value) === range(0, count($value) - 1);
        if ($islist) {
            return array_map(static fn($v) => self::sort_recursive($v), $value);
        }

        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::sort_recursive($v);
        }
        return $value;
    }

    /**
     * Call the AI service through the Jedomi AI client.
     *
     * @param  array $messages Conversation messages.
     * @param  array $tools    OpenAI-format tool definitions.
     * @param  int   $userid   Moodle user the call is made for.
     * @return array           ['type'=>'text', 'content'=>'...']
     *                         ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                         ['type'=>'error', 'error'=>['code'=>'...', 'message'=>'...']]
     */
    private static function call_ai(array $messages, array $tools, int $userid): array {
        // With the custom service, the oldest history is dropped so the request fits in the context window of its model.
        $messages = \local_jedomi\ai\context_window::fit_for_custom_service($messages, $tools);
        try {
            $answer = \local_jedomi\ai\client::instance()->complete($messages, $userid, $tools);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'error' => \local_jedomi\ai\error_message::from_exception($e)];
        }

        if (!empty($answer['tool_calls'])) {
            return ['type' => 'tool_calls', 'tool_calls' => $answer['tool_calls']];
        }

        // Text response: prefer content, fall back to reasoning (thinking tokens).
        $text = $answer['content'] !== '' ? $answer['content'] : ($answer['reasoning'] ?? '');
        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Public entry point for CLI debug — delegates to the private dispatcher.
     *
     * @param  string $name Tool name.
     * @param  array  $args Tool arguments.
     * @param  string $role User role for permission checks.
     * @return string       JSON-encoded result.
     */
    public static function run_tool(string $name, array $args, string $role): string {
        return self::execute_tool_by_name($name, $args, $role);
    }

    /**
     * Run a tool of another plugin as the session user, logged like the tools of Jedomi.
     *
     * @param \local_jedomi\agent\tool\agent_tool $tool Tool offered by its plugin for this request.
     * @param array $args Tool arguments.
     * @param string $role User role, for the log.
     * @return string Result of the tool.
     */
    private static function execute_plugin_tool(\local_jedomi\agent\tool\agent_tool $tool, array $args, string $role): string {
        \local_jedomi_log('TOOL_CALL', ['tool' => $tool->get_name(), 'arg_names' => array_keys($args), 'role' => $role,
            'source' => 'plugin']);
        // A read-only tool whose data is still being prepared runs again for a short while (see plugin_tool_retry).
        return \local_jedomi\local\plugin_tool_retry::run($tool, $args);
    }

    /**
     * Execute a tool by name, dispatching to the correct handler.
     *
     * @param  string $name Tool name.
     * @param  array  $args Tool arguments.
     * @param  string $role User role for permission checks.
     * @return string       JSON-encoded result.
     */
    private static function execute_tool_by_name(string $name, array $args, string $role): string {
        // Log every tool invocation with full args.
        require_once(__DIR__ . '/../../lib.php');
        // Only the argument names: their values can hold personal data (queries, messages, user data).
        \local_jedomi_log('TOOL_CALL', ['tool' => $name, 'arg_names' => array_keys($args), 'role' => $role]);

        switch ($name) {
            case 'describe_table':
                return self::describe_table_tool($args);
            case 'search_tables':
                return self::search_tables_tool($args);
            case 'execute_sql':
                return self::execute_sql_tool($args, $role);
            case 'get_site_config':
                return self::get_site_config_tool($args, $role);
            case \local_jedomi\agent\tool\site_overview_tool::NAME:
                return \local_jedomi\agent\tool\site_overview_tool::run($args, $role);
            case 'ws_search':
                return self::ws_search_tool($args, $role);
            case 'ws_describe':
                return self::ws_describe_tool($args, $role);
            case 'ws_execute':
                return self::ws_execute_tool($args, $role);
            case 'admin_search':
                return self::admin_search_tool($args, $role);
            case 'admin_describe':
                return self::admin_describe_tool($args, $role);
            case 'admin_execute':
                return self::admin_execute_tool($args, $role);

            case 'create_agents':   // Model sometimes hallucinates plural form.
            case 'create_agent':
                return self::create_agent_tool($args, $role);
            case 'list_agent':     // Model sometimes hallucinates singular form.
            case 'list_agents':
                return self::list_agents_tool($args, $role);
            case 'delete_agents':   // Model sometimes hallucinates plural form.
            case 'delete_agent':
                return self::delete_agent_tool($args, $role);

            case 'my_courses':
            case 'my_grades':
            case 'my_assignments':
                // Always the data of the session user, whatever the arguments say.
                return \local_jedomi\agent\tool\own_data_tool::run($name, $args);
            case 'document_read':
                return self::document_read_tool($args, $role);
            case 'send_messages':
                return self::send_messages_tool($args);
            default:
                return json_encode(['error' => get_string('error_unknown_tool', 'local_jedomi', $name)]);
        }
    }

    /**
     * Execute SQL tool.
     *
     * @param  array  $args Tool arguments (sql, params).
     * @param  string $role User role.
     * @return string       JSON-encoded result.
     */
    private static function execute_sql_tool(array $args, string $role): string {
        global $USER;
        // Same gate as Telegram: the chat role, then the shared guard limits teachers to the courses they manage.
        if (!\local_jedomi\local\query_runner::role_can_query($role)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $sql    = $args['sql'] ?? $args['query'] ?? '';
        $sql    = self::normalize_moodle_table_placeholders($sql);
        $params = $args['params'] ?? [];
        if (!is_array($params)) {
            $params = [$params];
        }

        \local_jedomi_log('EXECUTE_SQL', ['sql_hash' => hash('sha256', $sql), 'param_count' => count($params)]);

        try {
            self::require_enabled();
            $result = \local_jedomi\local\query_runner::run(
                $sql,
                $params,
                (int) $USER->id,
                \local_jedomi\local\query_runner::row_limit('web'),
                'web'
            );
            return json_encode([
                'rows' => array_map(
                    static fn(array $row): array => ['json_row' => json_encode($row, JSON_UNESCAPED_UNICODE)],
                    $result['rows']
                ),
                'row_count' => count($result['rows']),
                'truncated' => $result['truncated'],
                'execution_ms' => $result['execution_ms'],
            ]);
        } catch (\Exception $e) {
            $realerror = $e->getMessage();
            // The query runner wraps the database error in execute_sql_error with the database message as debug info;
            // the model needs that message (an unknown column, a syntax error) to fix its query instead of guessing.
            $withdetail = $e instanceof \dml_exception
                || ($e instanceof \moodle_exception && $e->errorcode === 'execute_sql_error');
            if ($withdetail && !empty($e->debuginfo)) {
                $realerror .= ' | DBG: ' . $e->debuginfo;
            }
            $prev = $e->getPrevious();
            if ($prev) {
                $realerror .= ' | PREV: ' . $prev->getMessage();
                if ($prev instanceof \dml_exception && !empty($prev->debuginfo)) {
                    $realerror .= ' | PREV_DBG: ' . $prev->debuginfo;
                }
            }
            if (strpos($sql, '{') === false) {
                $realerror .= get_string('hint_sql_braces', 'local_jedomi');
            }
            // The error text can quote the query, so only its class and code are logged.
            \local_jedomi_log('EXECUTE_SQL_ERROR', ['sql_hash' => hash('sha256', $sql), 'class' => get_class($e),
                'errorcode' => $e instanceof \moodle_exception ? $e->errorcode : '']);
            return json_encode(['error' => $realerror]);
        }
    }

    /**
     * Normalize SQL by wrapping plain table names in FROM/JOIN with Moodle braces.
     *
     * Example: FROM course -> FROM {course}
     *
     * @param string $sql
     * @return string
     */
    private static function normalize_moodle_table_placeholders(string $sql): string {
        return (string)preg_replace_callback(
            '/\b(FROM|JOIN)\s+([a-z][a-z0-9_]*)\b/i',
            static function (array $matches): string {
                $keyword = $matches[1];
                $table = $matches[2];
                return $keyword . ' {' . $table . '}';
            },
            $sql
        );
    }

    /**
     * Describe table tool (batch).
     *
     * @param  array  $args Tool arguments (tables).
     * @return string       JSON-encoded result.
     */
    /**
     * Describe table tool (single).
     *
     * @param  array  $args Tool arguments (tablename).
     * @return string       JSON-encoded result.
     */
    private static function describe_table_tool(array $args): string {
        try {
            $tablename = $args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? '';
            $result = \local_jedomi\schema\table_descriptor::describe($tablename);
            return json_encode($result);
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Search tables tool — semantic search over install.xml definitions.
     *
     * Unlike the old table_descriptor::search_tables (plain string match),
     * this searches across table names, table COMMENTS, field names,
     * and field COMMENTS from install.xml files — same approach as ws_search.
     *
     * @param  array  $args Tool arguments (query, limit).
     * @return string       JSON-encoded result.
     */
    private static function search_tables_tool(array $args): string {
        try {
            $query  = trim($args['query'] ?? $args['keyword'] ?? '');
            $limit  = max(1, min(15, (int) ($args['limit'] ?? 8)));

            if (empty($query)) {
                return json_encode(['error' => 'query is required']);
            }

            $results = \local_jedomi\schema\table_indexer::search($query, $limit);

            return json_encode([
                'tables' => $results,
                'count'  => count($results),
                'note'   => count($results) === 0
                    ? get_string('note_tables_not_found', 'local_jedomi')
                    : get_string('note_tables_found', 'local_jedomi'),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => get_string('error_search_tables_failed', 'local_jedomi', $e->getMessage())]);
        }
    }

    /**
     * Get site config tool.
     *
     * @param  array  $args Tool arguments (keys).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function get_site_config_tool(array $args, string $role): string {
        if ($role !== 'admin') {
            return json_encode(['error' => 'permission_denied']);
        }
        try {
            $cfgkeys = $args['keys'] ?? [];
            if (empty($cfgkeys) && isset($args['key'])) {
                $cfgkeys = is_array($args['key']) ? $args['key'] : [$args['key']];
            }
            $result = \local_jedomi\external\get_site_config::execute(
                $cfgkeys,
                '', // Hmac_signature.
                0, // Hmac_timestamp.
                '', // Payload_hash.
                true    // Skip_hmac.
            );
            return json_encode($result);
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * WS Search tool — semantic keyword search over available web service functions.
     *
     * @param  array  $args Tool arguments (query, limit).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_search_tool(array $args, string $role): string {
        try {
            $query = trim($args['query'] ?? $args['keyword'] ?? $args['filter'] ?? '');
            $limit = max(1, min(15, (int) ($args['limit'] ?? 8)));

            if (empty($query)) {
                return json_encode(['error' => 'query is required']);
            }

            $allowed = \local_jedomi\local\ws_runner::searchable_functions($role);
            if ($allowed === []) {
                return json_encode(['results' => [], 'note' => get_string('note_ws_none_configured', 'local_jedomi')]);
            }

            $wsnames = $allowed ?? [];

            $results = \local_jedomi\schema\ws_indexer::search($query, $wsnames, $limit);

            \local_jedomi_log('WS_SEARCH_RESULTS', [
                'query' => $query,
                'count' => count($results),
                'names' => array_map(fn($r) => $r['name'] ?? '?', $results),
                'descriptions' => array_map(fn($r) => mb_substr($r['description'] ?? '', 0, 120), $results),
            ]);

            return json_encode([
                'results' => $results,
                'count'   => count($results),
                'note'    => count($results) === 0
                    ? get_string('note_ws_not_found', 'local_jedomi')
                    : get_string('note_ws_found', 'local_jedomi'),
            ]);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => get_string('error_ws_search_failed', 'local_jedomi', get_class($e) . ': ' . $e->getMessage()),
            ]);
        }
    }

    /**
     * WS Describe tool — returns full compact documentation for a specific WS function.
     *
     * @param  array  $args Tool arguments (wsname).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_describe_tool(array $args, string $role): string {
        try {
            $wsname = trim($args['wsname'] ?? $args['name'] ?? $args['ws_name'] ?? '');

            if (empty($wsname)) {
                return json_encode(['error' => 'wsname is required']);
            }

            // The same list that gates the execution: an empty list allows no description at all.
            if (!\local_jedomi\local\ws_runner::role_allows($role, $wsname)) {
                return json_encode(['error' => 'permission_denied: function not in allowlist']);
            }

            return json_encode(\local_jedomi\schema\ws_indexer::describe($wsname));
        } catch (\Throwable $e) {
            return json_encode([
                'error' => get_string('error_ws_describe_failed', 'local_jedomi', get_class($e) . ': ' . $e->getMessage()),
            ]);
        }
    }

    /**
     * WS Execute tool — executes a web service on behalf of the user.
     *
     * @param  array  $args Tool arguments (wsname, params).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_execute_tool(array $args, string $role): string {
        try {
            $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
            $raw = $args['params'] ?? '{}';
            $params = is_array($raw) ? json_encode($raw) : $raw;

            \local_jedomi_log('WS_EXECUTE', ['wsname' => $wsname]);

            // Same gate as Telegram: the web service list of the chat role, then the real capabilities of the user.
            self::require_enabled();
            if (!\local_jedomi\local\ws_runner::role_allows($role, (string) $wsname)) {
                throw new \moodle_exception('ws_not_in_allowlist', 'local_jedomi');
            }
            $decoded = json_decode((string) $params, true);
            $result = \local_jedomi\local\ws_runner::call(
                \local_jedomi\local\ws_runner::resolve((string) $wsname),
                is_array($decoded) ? $decoded : []
            );
            return json_encode(['result' => json_encode($result)]);
        } catch (\moodle_exception $e) {
            $detail = $e->getMessage();
            if (method_exists($e, 'getDebugInfo') && $e->getDebugInfo()) {
                $detail .= get_string('ws_debug_prefix', 'local_jedomi') . $e->getDebugInfo();
            }
            \local_jedomi_log('WS_EXECUTE_ERROR', ['wsname' => $wsname ?? '']);
            return json_encode([
                'error'    => get_string('error_ws_execute_failed', 'local_jedomi', $detail),
                'wsname'   => $wsname ?? '',
                'params'   => $params ?? '{}',
                'errclass' => get_class($e),
            ]);
        } catch (\Throwable $e) {
            \local_jedomi_log('WS_EXECUTE_ERROR', [
                'wsname' => $wsname ?? '',
                'params' => $params ?? '{}',
                'error' => $e->getMessage(),
            ]);
            return json_encode([
                'error'    => get_string('error_ws_execute_failed', 'local_jedomi', $e->getMessage()),
                'wsname'   => $wsname ?? '',
                'params'   => $params ?? '{}',
                'errclass' => get_class($e),
            ]);
        }
    }

    /**
     * Search admin CLI operations by keyword.
     *
     * @param  array  $args Tool arguments; expects "query" and optional "limit" keys.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded search results, or a permission_denied error.
     */
    private static function admin_search_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $query = trim($args['query'] ?? '');
        $limit = min((int) ($args['limit'] ?? 8), 20);

        $results = \local_jedomi\schema\cli_registry::search($query, $limit);
        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    /**
     * Describe one admin CLI operation from the registry.
     *
     * @param  array  $args Tool arguments; expects a "name" key with the operation name.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded registry entry, or a permission_denied error.
     */
    private static function admin_describe_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name  = trim($args['name'] ?? '');
        $entry = \local_jedomi\schema\cli_registry::describe($name);
        return json_encode($entry);
    }

    /**
     * Execute one admin CLI operation from the registry.
     *
     * @param  array  $args Tool arguments; expects "name" and optional "params" keys.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded execution result, or a permission_denied error.
     */
    private static function admin_execute_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        global $USER;

        $name   = trim($args['name'] ?? '');
        $params = $args['params'] ?? [];

        $result = \local_jedomi\schema\cli_registry::execute($name, $params, (int) ($USER->id ?? 0));
        return json_encode($result);
    }

    /**
     * Create a scheduled AI agent.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function create_agent_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $prompt = trim($args['prompt'] ?? '');
        $schedule = trim($args['schedule_cron'] ?? '');
        if (empty($prompt) || empty($schedule)) {
            return json_encode(['error' => 'Both prompt and schedule_cron are required.']);
        }
        global $USER;
        $data = new \stdClass();
        $data->userid = (int) ($args['userid'] ?? $USER->id);
        // The agent runs with the permissions of its owner: nobody creates one on behalf of another user without permission.
        if (!\local_jedomi\agent\agent_manager::can_create_for($data->userid, (int) $USER->id)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $data->name = trim($args['name'] ?? '');
        $data->prompt = $prompt;
        $data->schedule_cron = $schedule;
        $data->context_type = $args['context_type'] ?? 'site';
        $data->context_id = isset($args['context_id']) ? (int) $args['context_id'] : null;
        $data->enabled = isset($args['enabled']) ? (int) $args['enabled'] : 1;
        // The schedule and the context (a Moodle context id of the chosen type) are validated as in the form.
        $invalid = \local_jedomi\agent\agent_manager::validation_error($data);
        if ($invalid !== null) {
            return json_encode(['error' => $invalid]);
        }
        $id = \local_jedomi\agent\agent_manager::create_agent($data);
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        $label = (string) ($agent->name ?? '') !== '' ? $agent->name : get_string('agent_unnamed', 'local_jedomi');
        return json_encode([
            'success' => true,
            'agent_id' => $id,
            'name' => $agent->name ?? '',
            'message' => get_string('agent_created_message', 'local_jedomi', $label),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * List scheduled AI agents.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function list_agents_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $filters = [];
        if (!empty($args['status'])) {
            $filters['status'] = $args['status'];
        }
        global $USER;
        $actorid = (int) $USER->id;
        $result = \local_jedomi\agent\agent_manager::get_agents($filters);
        return json_encode([
            'success' => true,
            'total' => $result['total'],
            'agents' => array_map(function ($a) use ($actorid) {
                // The instruction is given only to the users who may read it.
                $a = \local_jedomi\agent\agent_manager::agent_for_actor($a, $actorid);
                return [
                    'id' => (int) $a->id,
                    'prompt' => $a->prompt,
                    'prompt_hidden' => $a->prompthidden,
                    'schedule_cron' => $a->schedule_cron,
                    'context_type' => $a->context_type,
                    'context_id' => $a->context_id ? (int) $a->context_id : null,
                    'enabled' => (bool) $a->enabled,
                    'lastrun' => $a->lastrun ? (int) $a->lastrun : null,
                    'nextrun' => (int) $a->nextrun,
                ];
            }, $result['items']),
        ]);
    }

    /**
     * Delete a scheduled AI agent.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function delete_agent_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return json_encode(['error' => 'Valid agent ID required.']);
        }
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        if (!$agent) {
            return json_encode(['error' => "Agent #{$id} not found."]);
        }
        global $USER;
        if (!\local_jedomi\agent\agent_manager::can_manage_agent($agent, (int) $USER->id)) {
            return json_encode(['error' => 'permission_denied']);
        }
        \local_jedomi\agent\agent_manager::delete_agent($id);
        return json_encode([
            'success' => true,
            'message' => get_string('agent_deleted_message', 'local_jedomi', $id),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send Moodle internal messages as the current user.
     *
     * @param  array $args Tool arguments; expects "user_ids" and "message".
     * @return string JSON with the sent and failed counts, or a permission_denied error.
     */
    private static function send_messages_tool(array $args): string {
        global $USER;
        if (!message_service::can_send((int) $USER->id)) {
            return json_encode(['error' => 'permission_denied']);
        }
        return json_encode(message_service::send((int) $USER->id, $args['user_ids'] ?? [], (string) ($args['message'] ?? '')));
    }

    /**
     * Read the PDF of a course module as text.
     *
     * @param  array  $args Tool arguments; expects "cmid" and an optional "pages".
     * @param  string $role User role forwarded by the tool dispatcher.
     * @return string JSON-encoded text of the document, or an error message.
     */
    private static function document_read_tool(array $args, string $role): string {
        return \local_jedomi\agent\tool\document_read_tool::read(
            (int) ($args['cmid'] ?? 0),
            $role,
            (string) ($args['pages'] ?? '')
        );
    }

    /**
     * Send an SSE event line to the client.
     *
     * @param string $event Event name (e.g. _jedomi_tool_executing).
     * @param mixed  $data  Data payload (will be JSON-encoded).
     */
    private static function sse_send(string $event, $data): void {
        $payload = json_encode(
            array_merge(
                ['event' => $event],
                is_array($data) ? $data : ['data' => $data]
            ),
            JSON_UNESCAPED_UNICODE
        );
        echo "data: {$payload}\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Stream text content to the client as OpenAI-format SSE tokens.
     *
     * @param string $content The text to stream.
     */
    private static function stream_sse_content(string $content): void {
        if (empty($content)) {
            echo "data: [DONE]\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            return;
        }

        $chunks = mb_str_split($content, 15);
        foreach ($chunks as $chunk) {
            echo "data: " . json_encode([
                'choices' => [['delta' => ['content' => $chunk]]],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            usleep(2000);
        }
        echo "data: [DONE]\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Summarize a tool result for the _jedomi_tool_result SSE event.
     *
     * @param  string $name   Tool name.
     * @param  string $result JSON-encoded result.
     * @return string         Human-readable summary.
     */
    private static function summarize_tool_result(string $name, string $result): string {
        $decoded = json_decode($result, true);
        if (!$decoded) {
            return get_string('summary_invalid_json', 'local_jedomi');
        }
        if (isset($decoded['error'])) {
            return get_string('summary_error_prefix', 'local_jedomi') . $decoded['error'];
        }
        return match ($name) {
            'execute_sql' => ($decoded['row_count'] ?? count($decoded['rows'] ?? []))
                . get_string('summary_rows_returned', 'local_jedomi'),
            'describe_table' => get_string('summary_table_structure', 'local_jedomi'),
            'search_tables' => ($decoded['count'] ?? count($decoded['tables'] ?? $decoded) ?? 0)
                . get_string('summary_matching_tables', 'local_jedomi'),
            'get_site_config', 'site_overview' => get_string('summary_config_data', 'local_jedomi'),
            'ws_search'   => count($decoded['results'] ?? []) . get_string('summary_functions_found', 'local_jedomi'),
            'ws_describe' => get_string('summary_function_details', 'local_jedomi'),
            'ws_execute' => get_string('summary_ws_executed', 'local_jedomi'),
            default => get_string('summary_done', 'local_jedomi'),
        };
    }
}
