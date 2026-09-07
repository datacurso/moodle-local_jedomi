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
 * Telegram webhook handler — main orchestrator.
 *
 * Routes: message (text/photo), callback_query (inline buttons), /start command.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\schema\table_indexer;
use local_jedomi\schema\ws_indexer;

/**
 * Main Telegram webhook orchestrator: routes messages, photos, commands and callback queries.
 */
class webhook_handler {
    /** Lock TTL in seconds — stale locks older than this are auto-released. */
    private const LOCK_TTL = 120;

    /**
     * Validate and normalise a Telegram chat id.
     *
     * Telegram chat ids are integers (negative for groups/channels). The value
     * arrives from an external HTTP payload, so it must be validated before it
     * reaches any SQL, cache key or log line.
     *
     * @param  string $chatid Raw value from the webhook payload.
     * @return string|null The normalised id, or null when invalid.
     */
    private static function normalise_chat_id(string $chatid): ?string {
        if (!preg_match('/^-?\d{1,20}$/', $chatid)) {
            return null;
        }
        return $chatid;
    }

    /**
     * Main entry point — dispatch based on Telegram update type.
     *
     * @param array $update Decoded JSON from Telegram webhook
     */
    public static function handle(array $update): void {
        if (isset($update['callback_query'])) {
            self::handle_callback_query($update['callback_query']);
            return;
        }

        if (isset($update['message'])) {
            self::handle_message($update['message']);
            return;
        }

        // Unknown update type — silently ignore.
        if (function_exists('\\local_jedomi_log')) {
            \local_jedomi_log('Telegram webhook: unknown update type');
        }
    }

    /**
     * Handle an incoming message (text or photo).
     *
     * @param array $message Decoded message object from the Telegram update
     */
    private static function handle_message(array $message): void {
        global $DB;

        $chatid = self::normalise_chat_id((string) ($message['chat']['id'] ?? ''));
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? null;

        if ($chatid === null) {
            // Attacker-controllable value: never log it raw, never let it reach the DB.
            \local_jedomi_log('[TELEGRAM ENTRY] rejected non-numeric chat id (len='
                . mb_strlen((string) ($message['chat']['id'] ?? '')) . ')');
            return;
        }

        \local_jedomi_log('[TELEGRAM ENTRY] chat=' . $chatid . ' textlen=' . mb_strlen($text));

        // ... ── Deduplicate via MySQL advisory lock ──
        // Telegram retries if 200 OK is slow. Without dedup, multiple PHP-FPM
        // workers process the same message concurrently, spawning 50-iter loops
        // each and overwhelming the Gateway.
        // GET_LOCK('key', 0) returns 1 if lock acquired, 0 if another process
        // holds it. Key is unique per chat_id — concurrent messages from
        // different chats are NOT blocked. Lock auto-releases on script end.
        try {
            $lockresult = (int) $DB->get_field_sql('SELECT GET_LOCK(?, 0)', ['jedomi_chat_' . $chatid]);
            if ($lockresult !== 1) {
                if (function_exists('\\local_jedomi_log')) {
                    \local_jedomi_log('DEDUP: another process is handling chat ' . $chatid . ', skipping');
                }
                return; // Another process already holds the lock for this chat.
            }
        } catch (\Exception $e) {
            // GET_LOCK not supported (non-MySQL). Proceed without dedup.
            if (function_exists('\\local_jedomi_log')) {
                \local_jedomi_log('DEDUP: GET_LOCK not available, proceeding without dedup');
            }
        }

        // Handle /help command (always responds, even if rate-limited or unlinked).
        // Aliases: /ayuda (Spanish).
        if (in_array(strtolower(trim($text)), ['/help', '/ayuda'], true)) {
            $client = new api_client();
            $help = get_string('telegram_help_heading', 'local_jedomi') . "\n\n"
                  . get_string('telegram_help_start', 'local_jedomi') . "\n"
                  . get_string('telegram_help_help', 'local_jedomi') . "\n"
                  . get_string('telegram_help_new', 'local_jedomi') . "\n"
                  . get_string('telegram_help_unlink', 'local_jedomi') . "\n\n"
                  . get_string('telegram_help_footer', 'local_jedomi');
            $client->send_message($chatid, $help, self::get_linked_keyboard());
            return;
        }

        // Check and record rate limiting atomically.
        if (self::check_and_record_rate($chatid)) {
            $cooldown = manager::get_cooldown();
            $client = new api_client();
            $client->send_message($chatid, get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => $cooldown]));
            return;
        }

        // Check if user is linked.
        $link = self::get_link_by_chat_id($chatid);
        if (!$link) {
            // Unlinked user — check if it's a /start command with code.
            if (strpos($text, '/start') === 0) {
                $username = (string) ($message['chat']['username'] ?? '');
                self::handle_start_command($chatid, $text, $username);
                return;
            }
            // Check if text looks like a bare link code.
            if (self::looks_like_link_code($text)) {
                $username = (string) ($message['chat']['username'] ?? '');
                self::handle_start_command($chatid, '/start ' . $text, $username);
                return;
            }
            // Otherwise ask them to link first.
            $client = new api_client();
            $client->send_message(
                $chatid,
                get_string('telegram_msg_link_first', 'local_jedomi'),
                self::get_unlinked_keyboard()
            );
            return;
        }

        // Check role permission.
        if (!self::is_user_role_allowed($link->userid)) {
            $client = new api_client();
            $client->send_message($chatid, get_string('telegram_msg_role_blocked', 'local_jedomi'));
            return;
        }

        // ... ── Impersonate the linked Moodle user ─────────────────────────
        // The Telegram chat is linked to $link->userid. Set $USER so that
        // Moodle's native permission system (validate_context, require_login,
        // require_capability, has_capability) works identically to the web
        // version for the ENTIRE request — proxy_to_gateway, tool loop,
        // and ALL execute_tool cases.
        global $USER;
        $USER = $DB->get_record('user', ['id' => $link->userid, 'deleted' => 0], '*', MUST_EXIST);

        // Handle /desvincular command.
        if (strcasecmp($text, '/desvincular') === 0 || strcasecmp($text, '/unlink') === 0) {
            self::handle_unlink_command($chatid, $link);
            return;
        }

        // Handle /new command to start fresh conversation.
        if (strcasecmp($text, '/new') === 0 || strcasecmp($text, '/nuevo') === 0 || strcasecmp($text, '/clear') === 0) {
            \local_jedomi_log('[TELEGRAM ENTRY] /new detected, calling proxy_to_gateway user=' . $link->userid);
            session::clear($chatid);
            session::clear_context($chatid);
            // Clear any stale tool_loop state with new session generation.
            session::set_context('tool_loop_' . $chatid, ['session_gen' => time()]);
            // Get user's name so the model greets them personally.
            $firstname = $DB->get_field('user', 'firstname', ['id' => $link->userid]) ?: 'user';
            self::proxy_to_gateway($link->userid, $chatid, "Hola, soy {$firstname}", null);
            return;
        }

        // Build and forward to Gateway.
        self::proxy_to_gateway($link->userid, $chatid, $text, $photo);
    }

    /**
     * Handle /start CODE command for account linking.
     *
     * @param string $chatid Telegram chat id
     * @param string $text Full command text including the linking code
     * @param string $tgusername Telegram username of the sender
     */
    private static function handle_start_command(string $chatid, string $text, string $tgusername): void {
        $parts = explode(' ', $text, 2);
        $code  = strtoupper(trim($parts[1] ?? ''));
        $client = new api_client();

        if (empty($code)) {
            $client->send_message($chatid, get_string('telegram_msg_provide_code', 'local_jedomi'));
            return;
        }

        if (strlen($code) < 4 || strlen($code) > 8 || !preg_match('/^[A-Z0-9]+$/', $code)) {
            $client->send_message($chatid, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        global $DB;

        // Resolve code → userid using the APPLICATION-mode cache.
        // (MODE_SESSION cache would not work here — no user session in webhook context.)
        $userid = manager::resolve_link_code($code);
        if ($userid === null) {
            $client->send_message($chatid, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Double-validate: the forward mapping must also match.
        if (!manager::validate_link_code($userid, $code)) {
            $client->send_message($chatid, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            $client->send_message($chatid, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Upsert the link (delete any existing link for this user first).
        $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
        $DB->insert_record('local_jedomi_telegram_links', (object)[
            'userid'             => $userid,
            'chatid'             => $chatid,
            'username'           => $tgusername,
            'confirmed'          => 1,
            'timecreated'        => time(),
            'lastproactivesent'  => 0,
        ]);

        // Invalidate code so it can't be reused.
        manager::invalidate_link_code($userid, $code);

        $fullname = fullname($user);
        $client->send_message($chatid, get_string('telegram_msg_linked_success', 'local_jedomi', [
            'fullname' => s($fullname),
            'username' => s($user->username),
        ]));
    }

    /**
     * Handle /desvincular or /unlink command.
     *
     * @param string $chatid Telegram chat id
     * @param \stdClass $link Telegram link record for the user
     */
    private static function handle_unlink_command(string $chatid, \stdClass $link): void {
        global $DB;
        $DB->delete_records('local_jedomi_telegram_links', ['id' => $link->id]);
        // Also clean up session and rate limit data.
        $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
        $DB->delete_records('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]);
        $DB->delete_records('local_jedomi_telegram_pending_actions', ['chatid' => $chatid]);

        $client = new api_client();
        $client->send_message($chatid, get_string('telegram_msg_unlinked', 'local_jedomi'));
    }

    /**
     * Execute a command triggered by inline keyboard callback.
     *
     * @param string $command Callback command name
     * @param string $chatid Telegram chat id
     * @param int $messageid Telegram message id to edit
     * @param api_client $client Telegram API client instance
     */
    private static function execute_cmd_callback(string $command, string $chatid, int $messageid, api_client $client): void {
        switch ($command) {
            case 'new':
                session::clear($chatid);
                $client->edit_message_text(
                    $chatid,
                    $messageid,
                    get_string('telegram_msg_new_conversation', 'local_jedomi')
                );
                break;
            case 'help':
                $help = get_string('telegram_help_heading', 'local_jedomi') . "\n\n"
                      . get_string('telegram_help_start', 'local_jedomi') . "\n"
                      . get_string('telegram_help_help', 'local_jedomi') . "\n"
                      . get_string('telegram_help_new', 'local_jedomi') . "\n"
                      . get_string('telegram_help_unlink', 'local_jedomi') . "\n\n"
                      . get_string('telegram_help_footer', 'local_jedomi');
                $client->edit_message_text($chatid, $messageid, $help);
                break;
            case 'unlink':
                global $DB;
                $link = self::get_link_by_chat_id($chatid);
                if ($link) {
                    $DB->delete_records('local_jedomi_telegram_links', ['id' => $link->id]);
                    $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
                    $DB->delete_records('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]);
                    $DB->delete_records('local_jedomi_telegram_pending_actions', ['chatid' => $chatid]);
                }
                $client->edit_message_text(
                    $chatid,
                    $messageid,
                    get_string('telegram_msg_unlinked', 'local_jedomi')
                );
                break;
            case 'howtolin':
                $client->edit_message_text(
                    $chatid,
                    $messageid,
                    get_string('telegram_msg_provide_code', 'local_jedomi') . "\n\n" .
                    get_string('telegram_link_instructions', 'local_jedomi')
                );
                break;
            default:
                // Unknown command — silently ignore.
        }
    }

    /**
     * Build inline keyboard for a linked user (new, help, unlink).
     * @internal Exposed as a testable entry point; not a stable plugin API.
     */
    public static function get_linked_keyboard(): array {
        return [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_keyboard_new', 'local_jedomi'),
                    'callback_data' => 'cmd:new'],
                ['text' => get_string('telegram_keyboard_help', 'local_jedomi'),
                    'callback_data' => 'cmd:help'],
                ['text' => get_string('telegram_keyboard_unlink', 'local_jedomi'),
                    'callback_data' => 'cmd:unlink'],
            ]],
        ];
    }

    /**
     * Build inline keyboard for an unlinked user (howto link, help).
     * @internal Exposed as a testable entry point; not a stable plugin API.
     */
    public static function get_unlinked_keyboard(): array {
        return [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_keyboard_howto_link', 'local_jedomi'),
                    'callback_data' => 'cmd:howtolin'],
                ['text' => get_string('telegram_keyboard_help', 'local_jedomi'),
                    'callback_data' => 'cmd:help'],
            ]],
        ];
    }

    /**
     * Handle an inline-keyboard callback query: acknowledge it and dispatch cmd:/confirm: actions.
     *
     * @param array $callback The callback_query object from the Telegram update.
     */
    private static function handle_callback_query(array $callback): void {
        $callbackid = $callback['id'] ?? '';
        $data = $callback['data'] ?? '';
        $chatid = self::normalise_chat_id((string) ($callback['message']['chat']['id'] ?? ''));
        $messageid = (int) ($callback['message']['message_id'] ?? 0);

        if ($callbackid === '' || $data === '' || $chatid === null) {
            return;
        }

        $client = new api_client();
        $client->answer_callback_query($callbackid);

        // Handle command shortcuts from inline keyboard (/new, /help, /unlink).
        if (strpos($data, 'cmd:') === 0) {
            $command = substr($data, strlen('cmd:'));
            self::execute_cmd_callback($command, $chatid, $messageid, $client);
            return;
        }

        if (strpos($data, 'confirm:') !== 0) {
            // Unknown action — ignore.
            return;
        }

        $actionid = substr($data, strlen('confirm:'));

        global $DB;
        $pending = $DB->get_record('local_jedomi_telegram_pending_actions', ['callbackid' => $actionid]);
        if (!$pending) {
            $client->edit_message_text($chatid, $messageid, "❌ Action expired. Please request again.");
            return;
        }

        $action = json_decode($pending->actionjson, true);
        if (!is_array($action)) {
            $client->edit_message_text($chatid, $messageid, "❌ Invalid action data.");
            return;
        }

        // Execute the tool directly (bypass LLM)
        // For now, we support only a limited set of safe tools
        // Full implementation requires mapping action names to PHP functions.
        $result = self::execute_pending_action($action, $pending->chatid);

        // Delete pending action.
        $DB->delete_records('local_jedomi_telegram_pending_actions', ['id' => $pending->id]);

        // Edit original message with result.
        $client->edit_message_text($chatid, $messageid, $result);
    }

    /**
     * Self-ping: fire a cURL to our own webhook so a new PHP-FPM worker
     * continues the checkpointed tool loop. The webhook responds 200 OK
     * immediately (fastcgi_finish_request), so this returns in <1s.
     *
     * @param string $chatid Telegram chat id whose tool loop continues
     */
    private static function self_ping(string $chatid): void {
        global $CFG;
        $secret = \local_jedomi\telegram\manager::get_webhook_secret();
        $callback = $CFG->wwwroot . '/local/jedomi/telegram_webhook.php';
        $stateself = session::get_context('tool_loop_' . $chatid);
        $sessiongen = $stateself['session_gen'] ?? 0;
        $url = $callback . '?secret=' . urlencode($secret)
             . '&internal_ping=1&chat_id=' . urlencode($chatid)
             . '&session_gen=' . $sessiongen;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            \local_jedomi_log('[TELEGRAM SELF-PING] cURL error: ' . $err . ' for chat=' . $chatid);
        }
    }

    /**
     * Handle an internal self-ping request. Loads the checkpoint state
     * and continues the tool loop from where the previous worker left off.
     *
     * @param string $chatid Telegram chat id whose checkpoint state is loaded
     */
    public static function handle_internal_ping(string $chatid): void {
        global $DB;

        $chatid = self::normalise_chat_id($chatid);
        if ($chatid === null) {
            // The chat id arrives as a raw HTTP parameter: reject before any DB access.
            \local_jedomi_log('[GATELOOP] rejected non-numeric chat id in internal ping');
            return;
        }

        $state = session::get_context('tool_loop_' . $chatid);
        if (empty($state) || empty($state['messages'])) {
            return; // Nothing to continue.
        }

        // Validate session_gen (TOCTOU guard for /new race).
        $expectedgen = (int)($state['session_gen'] ?? 0);
        $incominggen = (int)($_GET['session_gen'] ?? 0);
        if ($expectedgen > 0 && $incominggen > 0 && $incominggen !== $expectedgen) {
            \local_jedomi_log('[GATELOOP] session_gen mismatch chat=' . $chatid . ' expected=' . $expectedgen
                . ' got=' . $incominggen . ' — aborting stale self-ping');
            return;
        }

        // Restore userid from checkpoint (saved in normal flow).
        // Avoids querying local_jedomi_telegram_links on every self-ping.
        $userid = (int)($state['userid'] ?? 0);
        if ($userid <= 0) {
            // Fallback: legacy state (pre-cache) — query link table.
            $link = self::get_link_by_chat_id($chatid);
            if (!$link) {
                return;
            }
            $userid = (int)$link->userid;
        }

        // Impersonate user so permission checks work.
        global $USER;
        $USER = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

        self::proxy_to_gateway($userid, $chatid, '', null, true);
    }

    /**
     * Main proxy loop: sends messages to the Gateway AI API, handles tool calls,
     * streams responses, and self-pings when approaching FPM timeout so the
     * process survives across multiple PHP-FPM workers.
     *
     * @param int    $userid        Moodle user ID.
     * @param string $chatid       Telegram chat ID.
     * @param string $text          Original user message text.
     * @param array|null $photo     Optional photo data.
     * @param bool   $internalping True if called from self-ping (continue checkpoint).
     */
    private static function proxy_to_gateway(
        int $userid,
        string $chatid,
        string $text,
        ?array $photo,
        bool $internalping = false
    ): void {
        global $CFG, $DB;

        $statekey = 'tool_loop_' . $chatid;

        // ... ── Normal flow: build messages & context from scratch ──
        if (!$internalping) {
            // Check if another worker is already processing this chat.
            $existing = session::get_context($statekey);
            if (!empty($existing['is_locked'])) {
                $lockage = time() - ($existing['locked_at'] ?? time());
                if ($lockage < self::LOCK_TTL) {
                    // Lock is still valid (< 2 min) — block.
                    (new api_client())->send_message(
                        $chatid,
                        '⏳ Sigo procesando tu solicitud anterior, dame un momento...'
                    );
                    return;
                }
                // Lock expired (> LOCK_TTL seconds) — auto-release and continue.
                \local_jedomi_log('[GATELOOP] stale_lock chat=' . $chatid . ' age=' . $lockage . 's — auto-releasing');
                session::set_context($statekey, []);
                // Fall through to process the new message.
            }

            // ... ── Send typing indicator so user knows it's processing ──
            (new api_client())->send_chat_action($chatid, 'typing');

            // Build messages for the Gateway.
            $messages = session::build_messages_for_gateway($chatid, $text);

            // If photo, download and encode.
            $usercontent = $text;
            if ($photo !== null && !empty($photo)) {
                $largest = end($photo);
                $client = new api_client();
                $tmppath = $client->download_file($largest['file_id'] ?? '');
                if ($tmppath && file_exists($tmppath)) {
                    $imagedata = self::resize_and_base64($tmppath, 512);
                    if ($imagedata) {
                        $usercontent = [
                            ['type' => 'text', 'text' => $text],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imagedata]],
                        ];
                    }
                    @unlink($tmppath);
                }
            }

            if (is_array($usercontent)) {
                array_pop($messages);
                $messages[] = ['role' => 'user', 'content' => $usercontent];
            }

            // Persist user message.
            session::append($chatid, 'user', $text);

            // ... ── User context cache ──
            $userctx = session::get_context($chatid);
            if ($userctx === null) {
                $userctx = self::get_user_full_context($userid);
                session::set_context($chatid, $userctx);
            }
            $role = $userctx['role'];
            $spt = self::generate_spt_for_user($userid, $role, $userctx['course_ids_owned'], $userctx['course_ids_enrolled']);
            $tools = $spt['tools'] ?? [];

            // Gateway config (from plugin settings, with safe fallbacks).
            $gatewayurl = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
            $apikey = get_config('local_jedomi', 'api_key');
            $model = get_config('local_jedomi', 'model');
            $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
            if (empty($gatewayurl)) {
                $gatewayurl = 'http://localhost:8765';
            }
            if (empty($apikey)) {
                $apikey = 'jedomi-local-key';
            }
            if (empty($model)) {
                $model = 'jedomi-e4b';
            }
            if ($timeout < 5) {
                $timeout = 120;
            }
            if ($timeout > 300) {
                $timeout = 300;
            }

            // ... ── Restore checkpoint if a previous attempt was killed ──
            try {
                $checkpoint = session::get_context($statekey);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] checkpoint read FAILED for chat ' . $chatid . ': ' . $e->getMessage());
                $checkpoint = null;
            }
            if (!empty($checkpoint) && !empty($checkpoint['messages'])) {
                $lastusermsg = '';
                for ($i = count($checkpoint['messages']) - 1; $i >= 0; $i--) {
                    if (
                        ($checkpoint['messages'][$i]['role'] ?? '') === 'user'
                        && is_string($checkpoint['messages'][$i]['content'] ?? null)
                    ) {
                        $lastusermsg = $checkpoint['messages'][$i]['content'];
                        break;
                    }
                }
                if ($lastusermsg === $text) {
                    $messages = $checkpoint['messages'];
                    $cpjson = json_encode($checkpoint, JSON_UNESCAPED_UNICODE);
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] checkpoint restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                        $chatid,
                        $checkpoint['iteration'] ?? 0,
                        strlen($cpjson),
                        count($checkpoint['messages'])
                    ));
                } else {
                    session::set_context($statekey, []);
                }
            }

            // Init state (iteration counter, lock, cached SPT for self-pings).
            $state = [
                'is_locked'      => true,
                'locked_at'      => time(),
                'iteration'      => 0,
                'max_iterations' => 50,
                'messages'       => $messages,
                'userid'         => $userid,
                'spt'            => $spt,
                'tools'          => $tools,
            ];

            // ... ── Self-ping flow: restore messages & context from state ──
        } else {
            $state = session::get_context($statekey);
            if (empty($state) || empty($state['messages'])) {
                \local_jedomi_log('[GATELOOP] self-ping empty state for chat ' . $chatid . ', nothing to continue');
                return; // Nothing to continue.
            }
            $messages = $state['messages'];
            $statejsoncheck = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] self-ping restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                $chatid,
                $state['iteration'] ?? 0,
                strlen($statejsoncheck),
                count($messages)
            ));

            // ... ── Restore SPT + tools from checkpoint (cached, avoids DB re-queries) ──
            $spt = $state['spt'] ?? null;
            $tools = $state['tools'] ?? [];

            // The system prompt below always needs $role and $userctx,
            // whether or not the SPT came cached in the checkpoint.
            $userctx = session::get_context($chatid);
            if ($userctx === null) {
                $userctx = self::get_user_full_context($userid);
                session::set_context($chatid, $userctx);
            }
            $role = $userctx['role'];

            if (empty($spt)) {
                // Fallback: legacy state (pre-cache) — recalc the SPT from DB.
                $spt = self::generate_spt_for_user($userid, $role, $userctx['course_ids_owned'], $userctx['course_ids_enrolled']);
                $tools = $spt['tools'] ?? [];
            }

            $gatewayurl = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
            $apikey = get_config('local_jedomi', 'api_key');
            $model = get_config('local_jedomi', 'model');
            $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
            if (empty($gatewayurl)) {
                $gatewayurl = 'http://localhost:8765';
            }
            if (empty($apikey)) {
                $apikey = 'jedomi-local-key';
            }
            if (empty($model)) {
                $model = 'jedomi-e4b';
            }
            if ($timeout < 5) {
                $timeout = 120;
            }
            if ($timeout > 300) {
                $timeout = 300;
            }

            // Ensure lock is active.
            $state['is_locked'] = true;
            $state['locked_at'] = time();
        }

        // ... ── Build system message with course context (same for both flows) ──
        $toolnames = array_map(fn(array $t): string => $t['function']['name'], $tools);
        $coursesbyrole = $userctx['courses_by_role'];
        $userlang = $DB->get_field('user', 'lang', ['id' => $userid]) ?: current_language();

        $systemcontent = self::build_telegram_system_prompt($role, $userlang, $toolnames, $coursesbyrole, $userid);

        $messages[0] = ['role' => 'system', 'content' => $systemcontent];

        // ... ── Tool loop uses state-based iteration ──
        // Each HTTP worker runs until ~55s, then self-pings so a new worker
        // picks up at the saved iteration. The user sees a single seamless response.
        $loopstart = time();
        $maxiterations = 50;
        $hardguard = 55; // Seconds — chain to next worker before FPM kills us.

        // Extend PHP execution limit to cover our hard guard + buffer.
        // Without this, php max_execution_time or fpm request_terminate_timeout
        // (often 30s on shared hosts) kills the worker while cURL waits,
        // producing NO error log — just a silent hang.
        $oldlimit = @ini_get('max_execution_time');
        @set_time_limit($hardguard + 20); // 75s total, safe for most hosts
        \local_jedomi_log(sprintf(
            '[GATELOOP] time_limit: old=%s new=%d hard_guard=%d',
            $oldlimit ?: 'unlimited',
            $hardguard + 20,
            $hardguard
        ));

        $toolrepeatcounts = [];
        $progresssentfirst = false;
        $senttimeoutwarning = false;

        for ($iteration = (int)($state['iteration'] ?? 0); $iteration < $maxiterations; $iteration++) {
            $elapsed = time() - $loopstart;

            if ($iteration > 0) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] iter_top chat=%s iter=%d elapsed=%ds msg_count=%d',
                    $chatid,
                    $iteration,
                    $elapsed,
                    count($messages)
                ));
            }

            // ... ── Hard guard (~55s): save state + self_ping → next worker ──
            // IMPORTANT: save $iteration + 1 (NEXT expected iteration),
            // NOT $iteration (current). The for loop increments AFTER the
            // body, so saving $iteration means the next worker re-does it.
            if ($elapsed >= $hardguard) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] hard_guard chat=%s iter=%d elapsed=%d self-pinging',
                    $chatid,
                    $iteration,
                    $elapsed
                ));
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                session::set_context($statekey, $state);
                self::self_ping($chatid);
                return;
            }

            // ... ── Medium guard (~40s): self-ping so next worker continues
            // before FPM request_terminate_timeout silently kills this worker.
            // Only on internal iterations (>0).
            if ($elapsed >= 40 && $iteration > 0) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] medium_guard chat=%s iter=%d elapsed=%d self-pinging',
                    $chatid,
                    $iteration,
                    $elapsed
                ));
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                session::set_context($statekey, $state);
                self::self_ping($chatid);
                return;
            }

            // ... ── Soft guard (~30s): send a keepalive so the user knows we're alive ──
            if ($elapsed >= 30 && !$senttimeoutwarning) {
                $senttimeoutwarning = true;
                (new api_client())->send_message(
                    $chatid,
                    "🔄 Sigamos, estoy procesando tu solicitud…"
                );
            }

            // ... ── Re-send typing indicator each iteration (Telegram auto-expires after ~6s) ──
            // Only on iterations after the first (avoids double-send with the initial one).
            if ($iteration > 0) {
                (new api_client())->send_chat_action($chatid, 'typing');
            }

            $payload = [
                'model'       => $model,
                'temperature' => 0.2,
                'max_tokens'  => 16384,
                'stream'      => false,
                'messages'    => $messages,
                'spt'         => $spt,
            ];
            if (!empty($tools)) {
                $payload['tools']       = $tools;
                $payload['tool_choice'] = 'auto';
            }

            // ... ── Stream from Gateway (same as web version) ──
            $payload['stream'] = true;
            $ssebuffer = '';
            $httpcode = 0;
            $curlerrno = 0;
            $curlerror = '';

            // Adaptive Gateway timeout: shrink as we approach the hard guard
            // so we don't get stuck waiting when FPM is about to kill us.
            $curltimeout = max(5, min($timeout, $hardguard - (time() - $loopstart) - 5));

            $ch = curl_init($gatewayurl . '/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT        => $curltimeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apikey,
                ],
                CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$ssebuffer) {
                    $ssebuffer .= $data;
                    return strlen($data);
                },
            ]);

            \local_jedomi_log(sprintf(
                '[GATELOOP] calling_gateway chat=%s iter=%d elapsed=%ds curl_timeout=%ds payload_bytes=%d msg_count=%d',
                $chatid,
                $iteration,
                time() - $loopstart,
                $curltimeout,
                strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)),
                count($messages)
            ));

            curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerrno = curl_errno($ch);
            $curlerror = curl_error($ch);
            curl_close($ch);

            // ... ── Log Gateway response for debugging ──
            \local_jedomi_log(sprintf(
                '[GATEWAY] iter=%d http=%d curl_errno=%d curl_err=%s sse_bytes=%d',
                $iteration,
                $httpcode,
                $curlerrno,
                $curlerror ?: 'none',
                strlen($ssebuffer)
            ));

            // ... ── Gateway errors ──
            if ($curlerrno !== 0) {
                if ($curlerrno === CURLE_OPERATION_TIMEDOUT) {
                    \local_jedomi_log('[GATEWAY] Timeout on iter ' . $iteration . ', self-pinging');
                    // Gateway timed out but there may be more time left for
                    // FPM. Self-ping to continue with remaining iterations.
                    $state['iteration'] = $iteration + 1;
                    $state['messages'] = $messages;
                    $state['is_locked'] = true;
                    $state['locked_at'] = time();
                    session::set_context($statekey, $state);
                    self::self_ping($chatid);
                } else {
                    \local_jedomi_log('[GATEWAY] cURL error on iter ' . $iteration . ': ' . $curlerror);
                    self::release_lock($statekey, $chatid, get_string('error_telegram_e004', 'local_jedomi'));
                }
                return;
            }
            if ($httpcode !== 200) {
                \local_jedomi_log('[GATEWAY] HTTP ' . $httpcode . ' on iter ' . $iteration
                    . ', body: ' . substr($ssebuffer, 0, 200));
                self::release_lock($statekey, $chatid, get_string('error_telegram_e005', 'local_jedomi'));
                return;
            }

            // ... ── Parse SSE buffer (same parser as web version) ──
            $result = self::parse_sse_buffer($ssebuffer);
            if ($result === null) {
                \local_jedomi_log('[GATEWAY] SSE parse failed on iter ' . $iteration . ', buffer: ' . substr($ssebuffer, 0, 200));
                self::release_lock($statekey, $chatid, get_string('error_telegram_e006', 'local_jedomi'));
                return;
            }

            if ($result['type'] === 'tool_calls') {
                $message = ['tool_calls' => $result['tool_calls']];
                $content = '';
                $hastools = true;
            } else {
                $message = ['content' => $result['content'] ?? ''];
                $content = $result['content'] ?? '';
                $hastools = false;

                // ... ── Fallback: text-based TOOL_CALL parsing (for models that
                // don't support native function calling). ────────────────
                $tcpattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';
                $tcpattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

                $toolcalls = [];
                $remainingtext = $content;

                // Try Pattern 1: TOOL_CALL: ...
                if (preg_match_all($tcpattern1, $content, $tcmatches, PREG_SET_ORDER)) {
                    $remainingtext = trim(preg_replace($tcpattern1, '', $content));
                    foreach ($tcmatches as $m) {
                        $toolname = trim($m[1]);
                        $argsjson = trim($m[2]);
                        json_decode($argsjson);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                                $argsjson = $jsonmatch[0];
                            }
                        }
                        json_decode($argsjson);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolcalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolname,
                                    'arguments' => $argsjson,
                                ],
                            ];
                        }
                    }
                }

                // Try Pattern 2 (broader): allow JSON inline after `call:` with optional newline
                // before END_TOOL_CALL. Handles variations where the model omits newlines.
                if (empty($toolcalls)) {
                    $tcpattern1b = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)(?:\n\s*)?END_TOOL_CALL[^\n]*/si';
                    if (preg_match_all($tcpattern1b, $content, $tcmatches2, PREG_SET_ORDER)) {
                        $remainingtext = trim(preg_replace($tcpattern1b, '', $content));
                        foreach ($tcmatches2 as $m) {
                            $toolname = trim($m[1]);
                            $argsjson = trim($m[2]);
                            json_decode($argsjson);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                                    $argsjson = $jsonmatch[0];
                                }
                            }
                            json_decode($argsjson);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolcalls[] = [
                                    'id' => 'text_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolname,
                                        'arguments' => $argsjson,
                                    ],
                                ];
                            }
                        }
                    }
                }

                // Try Pattern 3: <|tool_call|> (Llama 3 style) — only if patterns 1&2 didn't match.
                if (empty($toolcalls) && preg_match_all($tcpattern2, $content, $tcmatches, PREG_SET_ORDER)) {
                    $remainingtext = trim(preg_replace($tcpattern2, '', $content));
                    foreach ($tcmatches as $m) {
                        $inner = trim($m[1]);
                        $decoded = json_decode($inner, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                            $toolname = $decoded['name'];
                            $args = $decoded['arguments'] ?? '{}';
                            if (is_array($args)) {
                                $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                            }
                        } else {
                            $parts = explode("\n", $inner, 2);
                            $toolname = trim($parts[0]);
                            $args = $parts[1] ?? '{}';
                        }
                        json_decode($args);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolcalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolname,
                                    'arguments' => $args,
                                ],
                            ];
                        }
                    }
                }

                // ... ── Ultra-broad fallback: if content still looks like it has tool calls
                // but no regex matched, try grabbing everything between 'call:\w+' and
                // 'END_TOOL_CALL' as raw JSON. This catches unusual line breaks/whitespace.
                if (empty($toolcalls) && preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    $tcultra = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)\s*END_TOOL_CALL\s*/si';
                    if (preg_match_all($tcultra, $content, $tcmatches3, PREG_SET_ORDER)) {
                        $remainingtext = trim(preg_replace($tcultra, '', $content));
                        foreach ($tcmatches3 as $m) {
                            $toolname = trim($m[1]);
                            // Accept whatever text is between call and END_TOOL_CALL,
                            // attempt to extract a JSON object.
                            $raw = trim($m[2]);
                            if (preg_match('/\{.*\}/s', $raw, $jsonmatch)) {
                                $raw = $jsonmatch[0];
                            }
                            json_decode($raw);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolcalls[] = [
                                    'id' => 'ultra_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolname,
                                        'arguments' => $raw,
                                    ],
                                ];
                            }
                        }
                    }
                }

                if (!empty($toolcalls)) {
                    $message = [
                        'tool_calls' => $toolcalls,
                        'content' => $remainingtext ?: '',
                    ];
                    $content = $remainingtext ?: '';
                    $hastools = true;
                } else if (preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    // Log when content looks like it should have tool calls but none parsed.
                    debugging(
                        'Telegram: unparseable tool call in content — ' . mb_substr($content, 0, 500),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // ... ── Progress messages (icons + lang strings, same as web) ──
            if (!$progresssentfirst) {
                $progresssentfirst = true;
            }
            if ($hastools && ($iteration % 5 === 0 || $iteration === 1)) {
                $tooldisplay = [];
                // Map tool names to lang string keys (WS/admin tools)
                // or inline labels (create_* tools — no lang strings exist).
                $toollangkeys = [
                    'execute_sql'     => 'tool_sql_read',
                    'ws_execute'      => 'tool_api_call',
                    'search_tables'   => 'tool_schema_lookup',
                    'describe_table'  => 'tool_schema_lookup',
                    'ws_search'       => 'tool_webservice_lookup',
                    'ws_describe'     => 'tool_webservice_lookup',
                    'get_site_config' => 'tool_get_site_config',
                    'admin_search'    => 'confirm_executing',
                    'admin_describe'  => 'confirm_executing',
                    'admin_execute'   => 'confirm_executing',
                ];
                $toolinlinelabels = [
                    'create_categories'  => 'Crear categorías',
                    'create_courses'     => 'Crear cursos',
                    'create_users'       => 'Crear usuarios',
                    'enrol_users'        => 'Matricular usuarios',
                    'create_interactive' => 'Crear contenido interactivo',
                    'set_course_sections' => 'Estructurar secciones',
                    'upload_content_files' => 'Subir archivos',
                    'create_quiz'        => 'Crear cuestionarios',
                    'create_page'        => 'Crear páginas',
                    'create_label'       => 'Crear etiquetas',
                    'create_lesson'      => 'Crear lecciones',
                    'create_forum'       => 'Crear foros',
                    'create_assign'      => 'Crear tareas',
                    'create_book'        => 'Crear libros',
                    'create_group'       => 'Crear grupos',
                    'create_grouping'    => 'Crear agrupamientos',
                    'create_groupings_groups' => 'Agrupar grupos',
                    'list_agents'     => 'Listar agentes',
                    'list_agent'      => 'Listar agentes', // Alias hallucinated singular.
                    'create_agent'    => 'Crear agente',
                    'create_agents'   => 'Crear agente', // Alias hallucinated plural.
                    'delete_agent'    => 'Eliminar agente',
                    'delete_agents'   => 'Eliminar agente', // Alias hallucinated plural.
                ];
                foreach ($message['tool_calls'] ?? [] as $tc) {
                    $name = $tc['function']['name'] ?? '';
                    if (isset($toollangkeys[$name])) {
                        $label = get_string($toollangkeys[$name], 'local_jedomi');
                    } else if (isset($toolinlinelabels[$name])) {
                        $label = $toolinlinelabels[$name];
                    } else {
                        $label = $name;
                    }
                    $tooldisplay[] = $label;
                }
                $progressicon = match (true) {
                    $iteration <= 1  => '🔍',
                    $iteration <= 5  => '📖',
                    $iteration <= 10 => '⚡',
                    $iteration <= 20 => '📊',
                    default          => '⚙️',
                };
                (new api_client())->send_message(
                    $chatid,
                    $progressicon . ' ' . implode(', ', $tooldisplay) . '…'
                );
            }

            if (!$hastools) {
                // ... ── Text response: send to Telegram ──

                // 0. Convert HTML tables to pipe-delimited <pre> blocks.
                // Telegram does NOT support <table>/<tr>/<td> — strip_tags
                // would leave all data concatenated (unreadable).
                $content = \local_jedomi_sanitize_html_tables($content);

                // 1. Replace \[...\] and \(...\) with dollar signs for Moodle-style inline math.
                // IMPORTANT: Use hex escapes \x5d (]) and \x29 ()) for the closing
                // chars because \] and \) can trigger "unmatched closing parenthesis"
                // in some PCRE2 versions when outside a capture group.
                // Null coalescing: if preg_replace fails, keep original $content.
                $content = preg_replace('/\\\\\[(.*?)\\\\\x5d/s', '$$$$1$$', $content) ?? $content;
                $content = preg_replace('/\\\\\((.*?)\\\\\x29/s', '$$$$1$$', $content) ?? $content;

                // 2. Convert markdown links [text](url) to HTML <a href>.
                $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $content);

                // 3. Convert markdown formatting to Telegram HTML (defense-in-depth,
                // the model often uses **bold** or *italic* despite instructions).
                // Order matters: bold before italic, inline code before formatting.
                // 3a. ``` code blocks → <pre>
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
                $content = preg_replace('/```(\w*)\s*\n?(.*?)```/s', '<pre>$2</pre>', $content) ?? $content;
                // 3b. ``inline code`` (double) → <pre>
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
                $content = preg_replace('/``([^`]+)``/', '<pre>$1</pre>', $content) ?? $content;
                // 3c. `inline code` (single) → <code>  (before formatting to avoid ** inside code)
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
                $content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content) ?? $content;

                // 3c-bis. Protect * inside <code>/<pre> from being consumed by bold/italic steps.
                // Replace * with &#42; (HTML entity) so "30 * * * *" inside <code> stays intact.
                $content = preg_replace_callback('/<(code|pre)\b[^>]*>.*?<\/\1>/is', function ($m) {
                    return str_replace('*', '&#42;', $m[0]);
                }, $content) ?? $content;

                // 3d. ~~strikethrough~~ → <s>
                $content = preg_replace('/~~([^~]+)~~/', '<s>$1</s>', $content) ?? $content;
                // 3e. __underline__ → <u>  (before **bold** to avoid conflict)
                $content = preg_replace('/__([^_]+)__/', '<u>$1</u>', $content) ?? $content;
                // 3f. **bold** → <b> (double asterisk first, so single *italic* doesn't consume it)
                $content = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $content) ?? $content;
                // 3g. *italic* → <i> (single asterisk, only if not already wrapped)
                $content = preg_replace('/\*([^*]+)\*/', '<i>$1</i>', $content) ?? $content;

                // 4. Normalize Telegram variants: <strong>→<b>, <em>→<i>, <ins>→<u>,
                // <strike>/<del>→<s>, <span class="tg-spoiler">→<tg-spoiler>.
                $replacements = [
                    '/<strong\b[^>]*>/i' => '<b>',
                    '/<\/strong>/i'      => '</b>',
                    '/<em\b[^>]*>/i'     => '<i>',
                    '/<\/em>/i'          => '</i>',
                    '/<ins\b[^>]*>/i'    => '<u>',
                    '/<\/ins>/i'         => '</u>',
                    '/<strike\b[^>]*>/i' => '<s>',
                    '/<\/strike>/i'      => '</s>',
                    '/<del\b[^>]*>/i'    => '<s>',
                    '/<\/del>/i'         => '</s>',
                ];
                $display = preg_replace(array_keys($replacements), array_values($replacements), $content);

                // 5. Strip DISALLOWED tags via strip_tags.
                $display = strip_tags(
                    $display,
                    '<b><i><u><s><tg-spoiler><span><blockquote><a><code><pre><tg-emoji>'
                );

                // 6. Strip attributes per Telegram rules using preg_replace_callback
                // (handles attributes in ANY order, unlike plain regex).
                // 6a. <span class="tg-spoiler"> → <tg-spoiler>.
                $display = preg_replace_callback('/<span\b[^>]*>/i', function ($m) {
                    if (preg_match('/class="tg-spoiler"/i', $m[0])) {
                        return '<tg-spoiler>';
                    }
                    return '';
                }, $display);
                $display = str_replace('</span>', '', $display);
                // 6b. Strip attributes from <b>, <i>, <u>, <s>, <tg-spoiler>, <blockquote>, <pre>.
                $display = preg_replace('/<(b|i|u|s|tg-spoiler|blockquote|pre)\b[^>]*>/i', '<$1>', $display);
                // 6c. <code> → keep class="language-*" if present, else strip all.
                $display = preg_replace_callback('/<code\b[^>]*>/i', function ($m) {
                    if (preg_match('/class="language-([^"]*)"/i', $m[0], $cm)) {
                        return '<code class="language-' . $cm[1] . '">';
                    }
                    return '<code>';
                }, $display);
                // 6d. <a> → keep href only (double or single quotes).
                $display = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
                    if (preg_match('/href="([^"]*)"/i', $m[0], $hm)) {
                        return '<a href="' . $hm[1] . '">';
                    }
                    if (preg_match("/href='([^']*)'/i", $m[0], $hm)) {
                        return '<a href="' . $hm[1] . '">';
                    }
                    return '';
                }, $display);
                // 6e. <tg-emoji> → keep emoji-id only.
                $display = preg_replace_callback('/<tg-emoji\b[^>]*>/i', function ($m) {
                    if (preg_match('/emoji-id="([^"]*)"/i', $m[0], $em)) {
                        return '<tg-emoji emoji-id="' . $em[1] . '">';
                    }
                    return '';
                }, $display);

                // 7. Escape bare & that aren't already entities.
                $display = preg_replace('/&(?!(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);)/', '&amp;', $display);

                // If sanitisation emptied the text, send original as plain text.
                if (trim(strip_tags($display)) === '') {
                    $fallback = (is_string($content) && $content !== '') ? $content : '✅ Listo.';
                    session::set_context($statekey, []);
                    (new api_client())->send_message($chatid, $fallback);
                    return;
                }

                // ... ── Release lock before sending final response ──
                \local_jedomi_log('[GATELOOP] text response for chat ' . $chatid . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $loopstart) . 's, len=' . strlen($display));
                session::set_context($statekey, []);

                // If the response is large (> 3800 chars) or contains tabular data
                // (<pre> block), send as a .txt file instead of chunking multiple messages.
                if (mb_strlen($display) > 3800 || preg_match('/<pre>/i', $display)) {
                    $tmpdir = $CFG->dataroot . '/jedomi/telegram';
                    check_dir_exists($tmpdir, true, true);
                    $filename = 'chat_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
                    $filepath = $tmpdir . '/' . $filename;

                    // Extract first meaningful line for the caption.
                    $plaintext = strip_tags($display);
                    $chatlines = explode("\n", $plaintext);
                    $chatoneliner = '';
                    foreach ($chatlines as $cline) {
                        $cline = trim($cline);
                        if (!empty($cline)) {
                            $chatoneliner = $cline;
                            break;
                        }
                    }
                    if (mb_strlen($chatoneliner) > 200) {
                        $chatoneliner = mb_substr($chatoneliner, 0, 197) . '...';
                    }
                    $chatcaption = "📄 {$chatoneliner}";

                    file_put_contents($filepath, $plaintext);
                    (new api_client())->send_document($chatid, $filepath, $chatcaption);
                    @unlink($filepath);
                } else {
                    (new api_client())->send_message($chatid, $display);
                }
                return;
            }

            // ... ── Tool calls → execute and feed results back ──
            // ── Synchronisation point 1: re-read state from DB ─────────────
            // If another worker (self-ping) already processed these tools and
            // saved a newer state, skip execution and reload their results.
            // Only trust the saved iteration if the lock is still active (< 2 min).
            // A stale iteration without an active lock means the state is from
            // a dead/crashed worker — ignore it and continue processing.
            $freshstate = session::get_context($statekey);
            $isactivelylocked = !empty($freshstate['is_locked'])
                && (time() - ($freshstate['locked_at'] ?? 0) < self::LOCK_TTL);
            if ($isactivelylocked && ($freshstate['iteration'] ?? 0) > $iteration) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] stale_iteration chat=%s iter=%d fresh_iter=%d — reloading',
                    $chatid,
                    $iteration,
                    $freshstate['iteration']
                ));
                $iteration = (int)$freshstate['iteration'] - 1; // For loop increments; restore 1 less.
                $messages = $freshstate['messages'] ?? $messages;
                continue;
            }

            $messages[] = [
                'role'       => 'assistant',
                'content'    => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'],
            ];

            // Loop detection.
            $looping = false;
            foreach ($message['tool_calls'] as $tc) {
                $name = $tc['function']['name'] ?? '';
                $argsraw = $tc['function']['arguments'] ?? '{}';
                $args = is_array($argsraw) ? $argsraw : (json_decode($argsraw, true) ?? []);
                $tcid = $tc['id'] ?? '';
                ksort($args);
                $callkey = $name . '|' . md5(json_encode($args));
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;
                $repeatcount = $toolrepeatcounts[$callkey];
                if ($repeatcount > 8) {
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] loop_detected chat=%s iter=%d tool=%s key=%s count=%d',
                        $chatid,
                        $iteration,
                        $name,
                        $callkey,
                        $repeatcount
                    ));
                    $looping = true;
                    break;
                } else if ($repeatcount >= 4) {
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] loop_warning chat=%s iter=%d tool=%s key=%s count=%d',
                        $chatid,
                        $iteration,
                        $name,
                        $callkey,
                        $repeatcount
                    ));
                }

                try {
                    $toolresult = self::execute_tool($name, $args, $userid, $role, $userctx['course_ids_owned']);
                    if (mb_strlen($toolresult) > 64000) {
                        $toolresult = mb_substr($toolresult, 0, 64000) . "\n... (truncated)";
                    }
                } catch (\Exception $e) {
                    $toolresult = 'Error executing ' . $name . ': ' . $e->getMessage();
                }
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcid,
                    'content'      => $toolresult,
                ];
            }

            // ... ── Save checkpoint with updated iteration + messages ──
            $state['iteration'] = $iteration + 1;
            $state['messages'] = $messages;
            $state['is_locked'] = true;

            $statejson = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] state_save chat=%s iter=%d state_bytes=%d msg_count=%d',
                $chatid,
                $iteration,
                strlen($statejson),
                count($messages)
            ));

            try {
                session::set_context($statekey, $state);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] STATE SAVE FAILED for chat ' . $chatid
                    . ' iter=' . $iteration . ' bytes=' . strlen($statejson)
                    . ' err=' . $e->getMessage());
                // Fallback: release lock and inform user, don't crash silently.
                session::set_context($statekey, []);
                (new api_client())->send_message(
                    $chatid,
                    '⚠️ Lo siento, tuve un problema al guardar el progreso. '
                    . 'Por favor intentá de nuevo con /new.'
                );
                return;
            }

            if ($looping) {
                \local_jedomi_log('[GATELOOP] loop_exit for chat ' . $chatid . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $loopstart) . 's');
                session::set_context($statekey, []);
                (new api_client())->send_message($chatid, get_string('telegram_msg_max_iterations', 'local_jedomi'));
                return;
            }
        }

        // ... ── Max iterations reached ──
        \local_jedomi_log('[GATELOOP] max iterations reached for chat ' . $chatid);
        session::set_context($statekey, []);
        (new api_client())->send_message($chatid, get_string('telegram_msg_max_iterations', 'local_jedomi'));
    }

    /**
     * Build the system prompt sent to the Gateway for Telegram conversations.
     *
     * Extracted from proxy_to_gateway() so tests can verify the built prompt
     * through the same code path the runtime uses.
     *
     * @internal Exposed as a testable entry point; not a stable plugin API.
     *
     * @param  string $role            Resolved role of the linked user (admin/manager/teacher/student).
     * @param  string $userlang       Language the model must respond in.
     * @param  array  $toolnames      Names of the tools exposed to the model.
     * @param  array  $coursesbyrole Courses grouped by the user's role in them.
     * @param  int    $userid          Linked Moodle user id.
     * @return string The complete system prompt.
     */
    public static function build_telegram_system_prompt(
        string $role,
        string $userlang,
        array $toolnames,
        array $coursesbyrole,
        int $userid
    ): string {
        global $CFG;

        $sitename = get_config('core', 'sitename') ?: 'Moodle';
        $release = $CFG->release ?? 'unknown';
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => 'MySQL/MariaDB',
            'pgsql'             => 'PostgreSQL',
            default             => $dbtype,
        };

        return "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n"
            . "Role: {$role}.\n"
            . "IMPORTANT: Respond in the user's language ({$userlang}). "
            . "The user writes in {$userlang}, you answer in {$userlang}.\n\n"
            . "HOW TO HANDLE REQUESTS:\n"
            . "- Greetings / simple chat → respond directly. NO tools.\n"
            . "- Moodle tasks → use tools. Flow: ws_search → ws_describe → ws_execute.\n"
            . "- No data → don't invent. Use real tool results.\n"
            . "- Tool fails → retry once. Fails again → report and stop.\n"
            . "- Exclude course ID 1 (site home) and guest user from all operations.\n\n"
            . "TOOLS AVAILABLE:\n"
            . "- ws_search(query in ENGLISH) — search Moodle web services by keyword\n"
            . "- ws_describe(name) — see parameters of a web service\n"
            . "- ws_execute(name, params) — EXECUTE a web service\n"
            . "- search_tables(keyword) — search database tables\n"
            . "- describe_table(name) — see table structure\n"
            . "- execute_sql(sql, params) — SELECT only\n"
            . "- admin_search(query) — search admin operations\n"
            . "- admin_describe(name) — see admin operation details\n"
            . "- admin_execute(name, params) — execute admin operation\n"
            . "- get_site_config(keys) — read site configuration\n"
            . "- list_agents(status?) — list all scheduled AI agents\n"
            . "- create_agent(prompt, schedule_cron, name?, context_type?, context_id?, enabled?) — create a new AI agent\n"
            . "- delete_agent(id) — delete an AI agent by ID\n\n"
            . "RECOMMENDED FLOW: ws_search → ws_describe → ws_execute.\n\n"
            . "⚡ PARALLEL TOOLS: You can call MULTIPLE tools in a single response.\n"
            . "  Need 3 searches? Call ws_search 3 times at once.\n"
            . "  Need 2 describes? Call ws_describe 2 times at once.\n"
            . "  This is MUCH faster than doing them one by one.\n\n"
            . "⚡ TELEGRAM RULES:\n"
            . "- The user is WAITING. Don't do extra iterations.\n"
            . "- Use ONLY Telegram HTML: <b>bold</b>, <i>italic</i>, <code>code</code>.\n"
            . "- NO markdown. NEVER use **bold** or *italic* — use <b> and <i>.\n"
            . "- Be concise, direct, respond in {$userlang} only.\n\n"
            . "⚡ YOU HAVE MANY ITERATIONS — DO NOT GIVE UP:\n"
            . "- You have up to 50 tool call rounds. Use them.\n"
            . "- Never say 'too many steps' or 'simplify your request'.\n"
            . "- Just keep calling tools until you have the data to answer.\n"
            . "- Complex tasks need more rounds — that is OK.\n\n"
            . "⚡ CRITICAL — NEVER DO THIS:\n"
            . "- Do NOT describe a plan or list steps you are about to take.\n"
            . "- Do NOT say 'I will search for X' or 'Let me verify Y'.\n"
            . "- Do NOT show your reasoning or chain of thought.\n"
            . "- Respond in the user's language ONLY ({$userlang}).\n"
            . "- JUST CALL THE TOOL. Get the data. Respond with the result.\n"
            . "- If you need info → call the tool directly, no narration.\n"
            . "- Your final text response must be in {$userlang}.\n\n"
            . "Context: {$release}, {$sitename}, DB: {$dblabel}, User: {$userid}, Role: {$role}, Lang: {$userlang}\n"
            . "Admin: " . ($role === 'admin' ? 'YES' : 'NO') . "\n"
            . "Tools: " . implode(', ', $toolnames) . "\n"
            . "Courses: " . (empty($coursesbyrole) ? 'none' : json_encode($coursesbyrole)) . "\n"
            . "Execute what the user asked and respond. Don't keep searching.";
    }

    /**
     * Parse SSE streamed buffer from the Gateway AI API.
     *
     * Same parser as the web version (handler.php::call_ai_api_buffer).
     * Extracts text content, finish_reason, and tool_calls from SSE chunks.
     *
     * @param  string $buffer Raw SSE buffer from the Gateway.
     * @return array|null     ['type'=>'text', 'content'=>'...']
     *                        ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                        null on parse failure.
     */
    private static function parse_sse_buffer(string $buffer): ?array {
        $textbuf = '';
        $reasonbuf = '';
        $toolcallsacc = [];
        $finishreason = '';

        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $raw = trim(substr($line, 6));
            if ($raw === '[DONE]') {
                break;
            }
            $json = json_decode($raw, true);
            if (!$json) {
                continue;
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            $finishreason = $json['choices'][0]['finish_reason'] ?? $finishreason;

            // Accumulate text content.
            if (isset($delta['content']) && $delta['content'] !== null) {
                $textbuf .= $delta['content'];
            }
            // Gemma reasoning_content (thinking tokens — fallback only).
            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null) {
                $reasonbuf .= $delta['reasoning_content'];
            }
            // Accumulate tool_calls streamed across multiple delta chunks.
            if (!empty($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (!isset($toolcallsacc[$idx])) {
                        $toolcallsacc[$idx] = [
                            'id'       => $tc['id'] ?? '',
                            'type'     => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (!empty($tc['id'])) {
                        $toolcallsacc[$idx]['id'] = $tc['id'];
                    }
                    if (!empty($tc['function']['name'])) {
                        $toolcallsacc[$idx]['function']['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $arg = $tc['function']['arguments'];
                        if (is_array($arg)) {
                            $toolcallsacc[$idx]['function']['arguments'] = json_encode($arg, JSON_UNESCAPED_UNICODE);
                        } else {
                            $toolcallsacc[$idx]['function']['arguments'] .= $arg;
                        }
                    }
                }
            }
        }

        // Tool calls detected — ensure every entry has a non-empty id.
        if (!empty($toolcallsacc) || $finishreason === 'tool_calls') {
            ksort($toolcallsacc);
            $toolcalls = array_values($toolcallsacc);
            foreach ($toolcalls as &$tc) {
                if (empty($tc['id'])) {
                    $tc['id'] = 'call_' . substr(md5($tc['function']['name'] . microtime()), 0, 12);
                }
            }
            unset($tc);

            return ['type' => 'tool_calls', 'tool_calls' => $toolcalls];
        }

        // Text response: prefer content, fall back to reasoning (Gemma thinking tokens).
        $text = !empty($textbuf) ? $textbuf : $reasonbuf;
        if (empty($text)) {
            return null;
        }

        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Extract pending action from response, store in DB, send inline buttons.
     *
     * @param string $chatid Telegram chat id
     * @param string $content AI response text possibly containing a pending action block
     */
    private static function handle_pending_action(string $chatid, string $content): void {
        // Extract JSON between JEDOMI_PENDING_ACTION: and :END_PENDING.
        if (!preg_match('/JEDOMI_PENDING_ACTION:(.+?):END_PENDING/s', $content, $matches)) {
            // No valid action found — send raw text.
            $client = new api_client();
            $client->send_message($chatid, $content);
            return;
        }

        $actionjson = $matches[1];
        $action = json_decode($actionjson, true);
        if (!is_array($action)) {
            $client = new api_client();
            $client->send_message($chatid, $content);
            return;
        }

        global $DB;
        $callbackid = bin2hex(random_bytes(16));
        $DB->insert_record('local_jedomi_telegram_pending_actions', (object)[
            'callbackid'  => $callbackid,
            'chatid'      => $chatid,
            'actionjson'  => $actionjson,
            'timecreated' => time(),
        ]);

        // Build inline keyboard.
        $keyboard = [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_msg_confirm', 'local_jedomi'), 'callback_data' => 'confirm:' . $callbackid],
                ['text' => get_string('telegram_msg_cancel', 'local_jedomi'), 'callback_data' => 'cancel:' . $callbackid],
            ]],
        ];

        // Send clean message without the raw JEDOMI_PENDING_ACTION text.
        $cleantext = preg_replace('/JEDOMI_PENDING_ACTION:.+?:END_PENDING/s', '', $content);
        $cleantext = trim($cleantext);

        $client = new api_client();
        $client->send_message($chatid, $cleantext, $keyboard);
    }

    /**
     * Execute a tool by name with given arguments.
     * Respects role-based access — only safe read operations.
     *
     * @param string $name Tool name to execute
     * @param array $args Tool arguments
     * @param int $userid Moodle user id executing the tool
     * @param string $role Jedomi role shortname (admin|manager|teacher|student)
     * @param array $courseidsowned Course ids the user manages
     * @return string Tool execution result formatted for Telegram
     * @internal Exposed as a testable entry point; not a stable plugin API.
     */
    public static function execute_tool(
        string $name,
        array $args,
        int $userid = 0,
        string $role = '',
        array $courseidsowned = []
    ): string {
        global $DB, $CFG;

        switch ($name) {
            case 'describe_table':
                $tablename = $args['tablename'] ?? '';
                if (empty($tablename)) {
                    return "Table name required.";
                }
                $columns = $DB->get_columns($tablename);
                if (!$columns) {
                    return "Table not found: " . s($tablename);
                }
                $result = [];
                foreach ($columns as $col) {
                    $result[] = "• {$col->name} ({$col->type})" . ($col->meta_type ? " [{$col->meta_type}]" : '');
                }
                return "📋 <b>" . s($tablename) . "</b> (" . count($result) . " columns):\n" . implode("\n", $result);

            case 'search_tables':
                $query = $args['query'] ?? '';
                $limit = min((int)($args['limit'] ?? 10), 20);
                if (empty($query)) {
                    return "Search query required.";
                }
                $entries = table_indexer::search($query, $limit);
                $result = [];
                foreach ($entries as $entry) {
                    $line = "• <b>" . s($entry['name']) . "</b>";
                    if (!empty($entry['comment'])) {
                        $line .= " — " . s($entry['comment']);
                    }
                    $result[] = $line;
                }
                if (empty($result)) {
                    return "No tables found matching: " . s($query);
                }
                return "📚 <b>Tables</b> (" . count($result) . "):\n" . implode("\n", $result);

            case 'execute_sql':
                // SQL is ONLY SELECT — safe read-only.
                $sql = $args['sql'] ?? $args['query'] ?? '';
                if (empty($sql)) {
                    return "SQL query required.";
                }
                $sqlupper = strtoupper(trim($sql));
                if (!preg_match('/^SELECT\s/i', $sqlupper)) {
                    return "❌ Only SELECT queries are allowed.";
                }

                // ... ── File exfiltration check (defense-in-depth, matches web version) ──
                if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
                    return "❌ INTO OUTFILE/DUMPFILE is not allowed.";
                }

                // ... ── Role gate matching web version (lib.php:472) — admin/manager/teacher only ──
                if (!in_array($role, ['admin', 'manager', 'teacher'], true)) {
                    return "❌ Permission denied.";
                }

                // ... ── Course-level permission check ─────────────────────────────
                // Extract course IDs from SQL (WHERE courseid = N, course = N).
                $referencedcourses = [];
                if (preg_match_all('/\b(?:courseid|course_id|c\.id)\s*[=!]=?\s*(\d+)/i', $sql, $matches)) {
                    $referencedcourses = array_map('intval', array_unique($matches[1]));
                }

                if (!empty($referencedcourses) && $userid > 0) {
                    // Cache admin check — get_admins() called once per request.
                    static $admincache = [];
                    if (!array_key_exists($userid, $admincache)) {
                        $isadm = false;
                        $admins = get_admins();
                        foreach ($admins as $admin) {
                            if ((int) $admin->id === $userid) {
                                $isadm = true;
                                break;
                            }
                        }
                        $admincache[$userid] = $isadm;
                    }
                    if (!$admincache[$userid]) {
                        $sysctx = \context_system::instance();
                        $issystemmanager = has_capability('moodle/course:update', $sysctx, $userid);
                        foreach ($referencedcourses as $cid) {
                            $hasperm = $issystemmanager || in_array($cid, $courseidsowned, true);
                            if (!$hasperm) {
                                return get_string('error_telegram_e008', 'local_jedomi', $cid);
                            }
                        }
                    }
                }
                // ... ── End permission check ──────────────────────────────────────

                // Normalize Moodle table placeholders (FROM course → FROM {course}).
                $sql = preg_replace_callback(
                    '/\b(FROM|JOIN)\s+([a-z][a-z0-9_]*)\b/i',
                    static function (array $matches): string {
                        return $matches[1] . ' {' . $matches[2] . '}';
                    },
                    $sql
                );

                $params = $args['params'] ?? [];
                if (!is_array($params)) {
                    $params = [$params];
                }

                // ... ── Execute query with restrictions matching web version ─────────
                // 1. Statement timeout (5000ms, best-effort on MariaDB, silent on MySQL).
                try {
                    $DB->execute('SET STATEMENT max_statement_time = 5 FOR SELECT 1');
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- comment-only catch.
                } catch (\Exception $e) {
                    // Non-MariaDB — query runs without server-side timeout.
                }

                // 2. Use recordset (streaming) + PHP row cap instead of LIMIT.
                // This avoids double-LIMIT conflicts with model-generated SQL.
                try {
                    $recordset = $DB->get_recordset_sql($sql, $params);
                } catch (\Exception $e) {
                    return "❌ SQL error: " . $e->getMessage();
                }

                // ... ── Date/time column detection ──
                $datecolumns = [
                    'timecreated', 'timemodified', 'time', 'timeend', 'timestart',
                    'lastaccess', 'lastlogin', 'firstaccess', 'timeaccessed',
                    'timecompleted', 'timedue', 'timesent', 'timeopen', 'timeclose',
                    'deadline', 'date', 'created', 'modified', 'updated', 'deleted',
                    'startdate', 'enddate',
                ];
                $datekeys = [];
                $dateformat = get_string('strftimedatetime', 'core_langconfig');

                // Fetch user for timezone-aware date formatting.
                $user = null;
                if ($userid > 0) {
                    $user = \core_user::get_user($userid);
                }

                $maxrows = 50;
                $rows = [];
                $count = 0;
                foreach ($recordset as $row) {
                    $count++;
                    if ($count > $maxrows) {
                        $recordset->close();
                        $rows[] = '⚠️ More than ' . $maxrows . ' rows returned. Showing first ' . $maxrows . '.';
                        break;
                    }

                    // Detect timestamp-like columns from the first row's keys.
                    if (empty($datekeys)) {
                        foreach ((array)$row as $key => $val) {
                            if (in_array($key, $datecolumns, true)) {
                                $datekeys[] = $key;
                            }
                        }
                    }

                    $fields = [];
                    foreach ((array)$row as $key => $val) {
                        // Format timestamp columns as human-readable dates.
                        $istimestamp = in_array($key, $datekeys, true)
                            && (is_int($val) || (is_string($val) && ctype_digit($val)))
                            && (int)$val > 0;
                        if ($istimestamp) {
                            $val = userdate($val, $dateformat, $user);
                        } else {
                            $val = is_string($val) ? shorten_text($val, 60) : $val;
                        }
                        $fields[] = s($key) . ': ' . s($val);
                    }
                    $rows[] = "• " . implode(', ', $fields);
                }
                $recordset->close();

                if (empty($rows)) {
                    return "Query returned no results.";
                }
                return "📊 <b>Results</b> (" . count($rows) . " row(s)):\n" . implode("\n", $rows);

            case 'get_site_config':
                // ... ── Role gate: admin only (matches lib.php:498) ──
                if ($role !== 'admin') {
                    return json_encode(['error' => 'permission_denied']);
                }
                $keys = $args['keys'] ?? [];
                if (empty($keys) || !is_array($keys)) {
                    return "❌ Key list required.";
                }
                // Allowlist matching web version (external/get_site_config.php:37-51).
                $allowlist = [
                    'sitename', 'siteurl', 'wwwroot', 'lang', 'country', 'timezone',
                    'maxbytes', 'passwordpolicy', 'minpasswordlength',
                    'passwordminlength', 'passwordmaxlength',
                    'enrol_plugins_enabled', 'auth_plugins_enabled', 'smtphosts',
                ];
                $allowset = array_flip($allowlist);
                $output = [];
                foreach ($keys as $k) {
                    // Silently skip non-allowed keys (matching web version behaviour).
                    if (!isset($allowset[$k])) {
                        continue;
                    }
                    $v = get_config('core', $k);
                    // Fallback: model often asks for "siteurl" but Moodle stores it as "wwwroot".
                    if ($v === false && $k === 'siteurl') {
                        $v = get_config('core', 'wwwroot');
                    }
                    if ($v === false) {
                        $output[] = "• <b>" . s($k) . "</b> = (not found)";
                    } else {
                        $output[] = "• <b>" . s($k) . "</b> = " . s($v);
                    }
                }
                if (empty($output)) {
                    return "⚙️ <b>Site Config</b>: No matching keys found.";
                }
                return "⚙️ <b>Site Config</b>:\n" . implode("\n", $output);

            case 'ws_search':
                $query = $args['query'] ?? '';
                $limit = min((int)($args['limit'] ?? 10), 20);
                if (empty($query)) {
                    return "Search query required.";
                }
                $entries = ws_indexer::search($query, [], $limit);
                $results = [];
                foreach ($entries as $entry) {
                    $line = "• <b>" . s($entry['name']) . "</b> (" . s($entry['component']) . ")"
                        . " — " . s($entry['description']);
                    // Append param signature like the web version does.
                    if (!empty($entry['params']) && $entry['params'] !== '(none)') {
                        $line .= "\n   Params: " . s($entry['params']);
                    }
                    if (!empty($entry['returns']) && $entry['returns'] !== 'void') {
                        $line .= "\n   Returns: " . s($entry['returns']);
                    }
                    $results[] = $line;
                }
                if (empty($results)) {
                    return "No web services found matching: " . s($query);
                }
                return "🔌 <b>Web Services</b> (" . count($results) . "):\n" . implode("\n", $results);

            case 'ws_describe':
                $wsname = $args['wsname'] ?? '';
                if (empty($wsname)) {
                    return "Web service name required.";
                }
                $info = ws_indexer::describe($wsname);
                if (isset($info['error'])) {
                    return "Web service not found: " . s($wsname);
                }

                $result = "🔌 <b>" . s($info['name']) . "</b>\n";
                if (!empty($info['description'])) {
                    $result .= s($info['description']) . "\n";
                }
                $result .= "Component: " . s($info['component']) . " | Type: " . s($info['type']) . "\n";
                if (!empty($info['params']) && $info['params'] !== '(none)') {
                    $result .= "Parameters: " . s($info['params']) . "\n";
                }
                if (!empty($info['returns']) && $info['returns'] !== 'void') {
                    $result .= "Returns: " . s($info['returns']);
                }
                return $result;

            case 'ws_execute':
                $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
                $raw    = $args['params'] ?? '{}';
                $params = is_array($raw) ? json_encode($raw) : $raw;

                if (empty($wsname)) {
                    return json_encode(['error' => 'Web service name required.']);
                }

                // ... ── Gate 1: Role-based allowlist (matching ws_execute::execute) ──
                require_once(__DIR__ . '/../../lib.php');
                $allowlist = \local_jedomi_get_role_allowlist($role);
                // Admin sin allowlist configurada → acceso total.
                // El resto de roles (o admin con allowlist explícita) → debe matchear.
                if ($role !== 'admin' || !empty($allowlist)) {
                    if (!in_array($wsname, $allowlist, true)) {
                        return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
                    }
                }

                // ... ── Gate 2: Resolve target WS class ──────────────────────────────
                $classname = \local_jedomi_resolve_ws_classname($wsname);
                if (!$classname || !class_exists($classname)) {
                    return json_encode(['error' => 'WS class not found: ' . $wsname]);
                }

                $funcrecord = $DB->get_record(
                    'external_functions',
                    ['name' => $wsname],
                    'classname, classpath, component, methodname'
                );
                if (!$funcrecord) {
                    return json_encode(['error' => 'WS not found: ' . $wsname]);
                }
                $methodname = $funcrecord->methodname;
                if (!method_exists($classname, $methodname)) {
                    return json_encode(['error' => 'Method ' . $classname . '::' . $methodname . '() not found']);
                }

                // ... ── Gate 3: Call the WS method via reflection ────────────────────
                // IMPORTANT: Wrap in a delegated transaction to fix the orphaned
                // transaction bug. Many external_* methods call
                // start_delegated_transaction() internally. When called via
                // ReflectionMethod (not through external_api::call_external_function),
                // the inner transaction is never committed. By wrapping the call
                // in our own delegated transaction, the inner call sees an outer
                // transaction and delegates to it — our transaction does the
                // actual commit. This fixes the "active database transaction
                // detected during request shutdown" error.
                // Do NOT use external_api::call_external_function() — it calls
                // validate_context() which requires a web page context that
                // doesn't exist in the webhook handler.
                $decoded = json_decode($params, true);
                if (!is_array($decoded)) {
                    $decoded = [];
                }

                // Build callargs by matching Gateway params to method signature.
                $ref = new \ReflectionMethod($classname, $methodname);
                $callargs = [];
                $expectedparams = [];
                foreach ($ref->getParameters() as $param) {
                    $pname = $param->getName();
                    $expectedparams[] = $pname;
                    if (array_key_exists($pname, $decoded)) {
                        $callargs[] = $decoded[$pname];
                    } else if ($param->isDefaultValueAvailable()) {
                        $callargs[] = $param->getDefaultValue();
                    } else {
                        $got = implode(', ', array_keys($decoded));
                        return json_encode([
                            'error' => 'ws_execute_param_mismatch',
                            'ws' => $wsname,
                            'expected_params' => $expectedparams,
                            'provided_params' => array_keys($decoded),
                            'hint' => "WS expects parameter '{$pname}'. Provided: [{$got}]. "
                                . "Use ws_describe('{$wsname}') to see the exact structure.",
                        ]);
                    }
                }

                // Wrap in delegated transaction so inner start_delegated_transaction()
                // calls (from external_* methods) delegate to us and actually commit.
                $transaction = $DB->start_delegated_transaction();
                try {
                    $result = $ref->invokeArgs(null, $callargs);
                    $transaction->allow_commit();

                    $json = json_encode($result);
                    if (mb_strlen($json) > 2048) {
                        $json = mb_substr($json, 0, 2048) . "\n... (truncated)";
                    }
                    return $json;
                } catch (\moodle_exception $e) {
                    $transaction->rollback($e);
                    return json_encode(['error' => 'Error en ' . $wsname . ': ' . $e->getMessage()]);
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                    return json_encode(['error' => 'Error ejecutando ' . $wsname . ': ' . $e->getMessage()]);
                }

            case 'admin_search':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $query   = trim($args['query'] ?? '');
                $limit   = min((int)($args['limit'] ?? 8), 20);
                $results = \local_jedomi\schema\cli_registry::search($query, $limit);
                return json_encode(['results' => $results, 'count' => count($results)]);

            case 'admin_describe':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $name  = trim($args['name'] ?? '');
                $entry = \local_jedomi\schema\cli_registry::describe($name);
                return json_encode($entry);

            case 'admin_execute':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $name   = trim($args['name'] ?? '');
                $params = $args['params'] ?? [];
                $result = \local_jedomi\schema\cli_registry::execute($name, $params);
                return json_encode($result);

            case 'create_agents':   // Model sometimes hallucinates plural form.
            case 'create_agent':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $prompt = trim($args['prompt'] ?? '');
                $schedule = trim($args['schedule_cron'] ?? '');
                if (empty($prompt) || empty($schedule)) {
                    return "❌ Both prompt and schedule_cron are required.";
                }
                // Validate cron expression has 5 fields.
                $parts = preg_split('/\s+/', $schedule);
                if (count($parts) !== 5) {
                    return "❌ Invalid cron expression. Use 5 fields like: \"*/30 * * * *\"";
                }
                $data = new \stdClass();
                $data->userid = $userid;
                $data->name = trim($args['name'] ?? '');
                $data->prompt = $prompt;
                $data->schedule_cron = $schedule;
                $data->context_type = $args['context_type'] ?? 'site';
                $data->context_id = isset($args['context_id']) ? (int) $args['context_id'] : null;
                $data->enabled = isset($args['enabled']) ? (int) $args['enabled'] : 1;
                $id = \local_jedomi\agent\agent_manager::create_agent($data);
                $agent = \local_jedomi\agent\agent_manager::get_agent($id);
                $nextrun = $agent ? userdate($agent->nextrun) : '?';
                $label = (!empty($agent->name)) ? $agent->name : "Agent #{$id}";
                return "✅ <b>{$label}</b> created\n"
                    . "📝 " . s(mb_substr($prompt, 0, 100)) . (mb_strlen($prompt) > 100 ? '…' : '') . "\n"
                    . "⏱ {$schedule}\n"
                    . "📍 {$data->context_type}" . ($data->context_id ? " #{$data->context_id}" : '') . "\n"
                    . "📅 Next run: {$nextrun}";

            case 'list_agent':     // Model sometimes hallucinates singular form.
            case 'list_agents':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $filters = [];
                if (!empty($args['status'])) {
                    $filters['status'] = $args['status'];
                }
                $result = \local_jedomi\agent\agent_manager::get_agents($filters);
                $agents = $result['items'] ?? [];
                $total = $result['total'] ?? 0;
                if (empty($agents)) {
                    return "📭 No agents found" . (!empty($filters['status']) ? " ({$filters['status']})" : '') . ".";
                }
                $lines = [];
                $lines[] = "🤖 <b>Agents</b> ({$total}):";
                foreach ($agents as $a) {
                    $statusicon = $a->enabled ? '🟢' : '⚪';
                    $next = $a->nextrun ? userdate($a->nextrun) : '?';
                    // Always show agent ID so the model can use delete_agent(id).
                    $name = !empty($a->name) ? s($a->name) : "Agent #{$a->id}";
                    // Truncate long prompts to keep the tool result manageable.
                    $excerpt = s(mb_substr($a->prompt, 0, 60));
                    $lines[] = "{$statusicon} <b>[ID {$a->id}] {$name}</b>"
                        . " — {$excerpt}… —— {$a->schedule_cron} — next: {$next}";
                }
                return implode("\n", $lines);

            case 'delete_agents':   // Model sometimes hallucinates plural form.
            case 'delete_agent':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return "❌ Valid agent ID required.";
                }
                $agent = \local_jedomi\agent\agent_manager::get_agent($id);
                if (!$agent) {
                    return "❌ Agent #{$id} not found.";
                }
                $label = !empty($agent->name) ? $agent->name : "Agent #{$id}";
                \local_jedomi\agent\agent_manager::delete_agent($id);
                return "🗑️ <b>{$label} deleted</b> — " . s(mb_substr($agent->prompt, 0, 60));

            default:
                return "❌ Tool '" . s($name) . "' is not supported via Telegram. Try the web interface.";
        }
    }

    /**
     * Execute a pending action directly (bypass LLM).
     *
     * @param array $action Pending action payload (action name and params)
     * @param string $chatid Telegram chat id
     * @return string Result message formatted for Telegram
     * @internal Exposed as a testable entry point; not a stable plugin API.
     */
    public static function execute_pending_action(array $action, string $chatid): string {
        global $DB;

        $toolname = $action['name'] ?? '';
        $params = $action['params'] ?? [];

        // Resolve user role from chat link for permission checks.
        $link = self::get_link_by_chat_id($chatid);
        $role = $link ? self::get_user_role_for_user((int)$link->userid) : '';

        // Map action names to actual execution.
        switch ($toolname) {
            case 'cache_purge':
            case 'purge_caches':
                if ($role !== 'admin') {
                    return "❌ Permission denied. Admin only.";
                }
                purge_all_caches();
                return "✅ <b>Cache purged</b> successfully.";

            case 'run_task':
            case 'cron_run':
                if ($role !== 'admin') {
                    return "❌ Permission denied. Admin only.";
                }
                $taskname = $params['task'] ?? $params['task_name'] ?? '';
                if (empty($taskname)) {
                    return "❌ Task name required.";
                }
                // Block tasks that send communications or could cause side effects
                // when run out-of-band via Telegram.
                $blockedtasks = [
                    '\\core\\task\\send_failed_login_notifications_task',
                    '\\core\\task\\send_new_user_passwords_task',
                    '\\core\\task\\send_login_notifications_task',
                    '\\core\\task\\send_user_password_reset_notifications_task',
                    '\\core\\task\\send_sms_to_user_task',
                    '\\core\\task\\message_cleanup_task',
                ];
                foreach ($blockedtasks as $blocked) {
                    if (strcasecmp($taskname, $blocked) === 0) {
                        return "❌ Task <b>" . s($taskname) . "</b> is blocked for Telegram execution.";
                    }
                }
                $task = \core\task\manager::get_scheduled_task($taskname);
                if (!$task) {
                    return "❌ Task not found: " . s($taskname);
                }
                $task->execute();
                return "✅ Task <b>" . s($taskname) . "</b> executed.";

            case 'get_config':
                if ($role !== 'admin') {
                    return "❌ Permission denied. Admin only.";
                }
                $key = $params['key'] ?? '';
                // Allowlist matching get_site_config pattern: only non-sensitive keys.
                $safekeys = [
                    'enabled', 'model', 'version',
                    'hide_from_guests', 'anonymization_level',
                    'course_mode', 'course_list',
                    'telegram_enabled', 'telegram_bot_username', 'telegram_bot_firstname',
                    'telegram_bot_verified', 'telegram_rate_limit', 'telegram_cooldown',
                    'telegram_max_turns', 'telegram_gateway_timeout',
                    'telegram_hidden_roles',
                ];
                if (!in_array($key, $safekeys, true)) {
                    return "❌ Config key not in allowlist: " . s($key);
                }
                $value = get_config('local_jedomi', $key);
                if ($value === false) {
                    return "❌ Config key not found: " . s($key);
                }
                return "⚙️ <b>" . s($key) . "</b> = " . s($value);

            default:
                // Try to execute via tool execution system.
                try {
                    $result = self::execute_tool($toolname, $params, 0, '', []);
                    return $result;
                } catch (\Exception $e) {
                    return "❌ Action <b>" . s($toolname) . "</b> not implemented.\n\n" . s($e->getMessage());
                }
        }
    }

    /**
     * Check if text looks like a bare link code (4-8 uppercase alphanumeric chars).
     * This prevents treating random user messages as codes.
     *
     * @param string $text Incoming message text
     * @return bool True if the text matches the link code format
     * @internal Exposed as a testable entry point; not a stable plugin API.
     */
    public static function looks_like_link_code(string $text): bool {
        $text = strtoupper(trim($text));
        return (bool) preg_match('/^[A-Z0-9]{4,8}$/', $text);
    }

    // ... ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Release tool_loop lock and send error message to user.
     *
     * @param string $statekey The tool_loop state key.
     * @param string $chatid   The Telegram chat ID.
     * @param string $message   The error message to send.
     */
    private static function release_lock(string $statekey, string $chatid, string $message): void {
        session::set_context($statekey, []);
        (new api_client())->send_message($chatid, $message);
    }

    /**
     * Fetch the confirmed Telegram link record for a chat id.
     *
     * @param  string $chatid Telegram chat id.
     * @return \stdClass|null The confirmed link record, or null when none exists.
     */
    private static function get_link_by_chat_id(string $chatid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_links', ['chatid' => $chatid, 'confirmed' => 1]);
        return $record ?: null; // Get_record returns false when not found, cast to null.
    }

    /**
     * Decide whether the user may use the Telegram bot based on the hidden-roles setting.
     *
     * Admins are always allowed; otherwise the user is blocked only when every role
     * they hold (system and course contexts) is in the configured hidden list.
     *
     * @param  int $userid Moodle user id to check.
     * @return bool True if the user is allowed to interact with the bot.
     */
    private static function is_user_role_allowed(int $userid): bool {
        // Site admins always have access (matching web version).
        $admins = get_admins();
        foreach ($admins as $admin) {
            if ((int) $admin->id === $userid) {
                return true;
            }
        }

        $hidden = get_config('local_jedomi', 'telegram_hidden_roles') ?: '';
        if (empty($hidden)) {
            return true;
        }
        $hiddenids = array_filter(array_map('intval', explode(',', $hidden)));
        if (empty($hiddenids)) {
            return true;
        }

        // Collect all role IDs across all contexts.
        $allroleids = [];

        // Check system context.
        $systemroles = get_user_roles(\context_system::instance(), $userid, false);
        foreach ($systemroles as $r) {
            $allroleids[] = (int)$r->roleid;
        }

        // Check course contexts.
        $courses = enrol_get_all_users_courses($userid);
        foreach ($courses as $course) {
            $ctx = \context_course::instance($course->id);
            $courseroles = get_user_roles($ctx, $userid, false);
            foreach ($courseroles as $r) {
                $allroleids[] = (int)$r->roleid;
            }
        }

        // No roles found anywhere — allow (don't block users without roles).
        if (empty($allroleids)) {
            return true;
        }

        // If ANY role is NOT in the hidden list, the user is allowed.
        foreach ($allroleids as $rid) {
            if (!in_array($rid, $hiddenids, true)) {
                return true;
            }
        }

        // All roles are hidden — block.
        return false;
    }

    /**
     * Record one message against the per-chat rate limit window and report if the chat is blocked.
     *
     * Uses a delegated transaction; on any error the chat is treated as blocked for safety.
     *
     * @param  string $chatid Telegram chat id.
     * @return bool True if the chat is currently rate-limited (message must be rejected).
     */
    private static function check_and_record_rate(string $chatid): bool {
        global $DB;
        $now = time();
        $window = 60; // 1 minute
        $limit = manager::get_rate_limit();
        $cooldown = manager::get_cooldown() * 60;

        if ($limit <= 0) {
            return false; // Unlimited.
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]);

            if (!$record) {
                $DB->insert_record('local_jedomi_telegram_rate_limit', (object)[
                    'chatid'      => $chatid,
                    'windowstart' => $now,
                    'msgcount'    => 1,
                    'blockeduntil' => 0,
                ]);
                $transaction->allow_commit();
                return false;
            }

            // Check if blocked.
            if ($record->blockeduntil > $now) {
                $transaction->allow_commit();
                return true;
            }

            // Reset window if expired.
            if ($record->windowstart + $window < $now) {
                $record->windowstart = $now;
                $record->msgcount = 1;
                $record->blockeduntil = 0;
                $DB->update_record('local_jedomi_telegram_rate_limit', $record);
                $transaction->allow_commit();
                return false;
            }

            // Check limit.
            if ($record->msgcount >= $limit) {
                $record->blockeduntil = $now + $cooldown;
                $DB->update_record('local_jedomi_telegram_rate_limit', $record);
                $transaction->allow_commit();
                return true;
            }

            // Increment.
            $record->msgcount = $record->msgcount + 1;
            $DB->update_record('local_jedomi_telegram_rate_limit', $record);
            $transaction->allow_commit();
            return false;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            return true; // Block on error to be safe.
        }
    }

    /**
     * Resize an image to fit within a maximum dimension and return it base64-encoded.
     *
     * Falls back to encoding the raw file when GD is unavailable or the type is unsupported.
     *
     * @param  string $path    Path to the local image file.
     * @param  int    $maxdim Maximum width/height in pixels for the resized image.
     * @return string|null Base64-encoded image data, or null on failure.
     */
    private static function resize_and_base64(string $path, int $maxdim): ?string {
        if (!function_exists('getimagesize')) {
            // Fallback: return raw file if no GD.
            $data = file_get_contents($path);
            return $data ? base64_encode($data) : null;
        }

        $info = getimagesize($path);
        if (!$info) {
            return null;
        }

        $srcw = $info[0];
        $srch = $info[1];

        // Calculate new dimensions.
        if ($srcw > $maxdim || $srch > $maxdim) {
            $ratio = min($maxdim / $srcw, $maxdim / $srch);
            $dstw = (int)($srcw * $ratio);
            $dsth = (int)($srch * $ratio);
        } else {
            $dstw = $srcw;
            $dsth = $srch;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $src = imagecreatefromjpeg($path);
                break;
            case IMAGETYPE_PNG:
                $src = imagecreatefrompng($path);
                break;
            default:
                // Unsupported type — return raw.
                $data = file_get_contents($path);
                return $data ? base64_encode($data) : null;
        }

        if (!$src) {
            return null;
        }

        $dst = imagecreatetruecolor($dstw, $dsth);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstw, $dsth, $srcw, $srch);

        ob_start();
        imagejpeg($dst, null, 85);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $data ? base64_encode($data) : null;
    }

    // ... ── User role & SPT helpers (self-contained, no dependency on lib.php) ──

    /**
     * Determine a user's Jedomi role WITHOUT depending on $COURSE or $PAGE globals.
     *
     * Used by the Telegram webhook where there is no page context or current course.
     * Checks admin status via get_admins(), manager via system-level capability,
     * teacher via manageactivities in any enrolled course, and falls back to student.
     *
     * @param  int    $userid The user ID to check.
     * @return string         'admin', 'manager', 'teacher', or 'student'.
     */
    private static function get_user_role_for_user(int $userid): string {

        // 1. Check if user is site admin via get_admins().
        $admins = get_admins();
        foreach ($admins as $admin) {
            if ((int) $admin->id === $userid) {
                return 'admin';
            }
        }

        $sysctx = \context_system::instance();

        // 2. Manager: has moodle/user:update at system level.
        if (has_capability('moodle/user:update', $sysctx, $userid)) {
            return 'manager';
        }

        // 3. Teacher: has moodle/course:manageactivities in ANY of their enrolled courses.
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $c) {
            $ctx = \context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                return 'teacher';
            }
        }

        return 'student';
    }

    /**
     * Generate a signed permission token (SPT) for a specific user
     * (not the global $USER).
     *
     * @param  int    $userid The user ID to generate the SPT for.
     * @param  string $role   The Jedomi role shortname (admin|manager|teacher|student).
     * @param  array  $courseidsowned The course ids the user manages.
     * @param  array  $courseidsenrolled The course ids the user is enrolled in.
     * @return array          The signed SPT payload, or empty array if user not found/deleted.
     */
    private static function generate_spt_for_user(
        int $userid,
        string $role,
        array $courseidsowned = [],
        array $courseidsenrolled = []
    ): array {
        global $CFG, $DB;

        $sysctx = \context_system::instance();
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, firstname, lastname');
        if (!$user) {
            return [];
        }

        // Capabilities for this specific user (not global $USER).
        $capabilities = [
            'can_view_all_grades'   => has_capability('moodle/grade:viewall', $sysctx, $userid),
            'can_manage_users'      => has_capability('moodle/user:update', $sysctx, $userid),
            'can_send_messages'     => has_capability('moodle/site:sendmessage', $sysctx, $userid),
            'can_manage_courses'    => has_capability('moodle/course:update', $sysctx, $userid),
            'can_view_reports'      => has_capability('moodle/course:viewhiddenactivities', $sysctx, $userid),
            'can_enrol_users'       => has_capability('enrol/manual:enrol', $sysctx, $userid),
            'can_access_all_groups' => has_capability('moodle/site:accessallgroups', $sysctx, $userid),
        ];

        // Course lists — use pre-computed if provided, otherwise compute.
        // This avoids redundant calls when called from proxy_to_gateway.
        if (empty($courseidsowned) && empty($courseidsenrolled) && $role !== 'admin') {
            if ($role === 'teacher' || $role === 'manager') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $ctx = \context_course::instance($c->id);
                    if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                        $courseidsowned[] = (int)$c->id;
                    }
                }
            } else if ($role === 'student') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $courseidsenrolled[] = (int)$c->id;
                }
            }
        }
        // Admin: no course list (full access).

        $tools = \local_jedomi_get_tool_definitions($role);

        $payload = [
            'userid'              => $userid,
            'username'            => $user->username,
            'role'                => $role,
            'course_id'           => 0,
            'course_ids_owned'    => $courseidsowned,
            'course_ids_enrolled' => $courseidsenrolled,
            'capabilities'        => $capabilities,
            'tools'               => $tools,
            'moodle_url'          => $CFG->wwwroot,
            'expires'             => time() + 1800,
        ];

        // Same HMAC signing as the existing generate_spt function.
        $staticsecret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';

        ksort($payload);
        $tosign = json_encode($payload);
        $signature = hash_hmac('sha256', $tosign, $staticsecret);

        $payload['signature'] = $signature;
        return $payload;
    }

    /**
     * Get ALL user context (role, course IDs, course names by role) in ONE pass.
     *
     * Calls enrol_get_users_courses() exactly once. Used by proxy_to_gateway
     * to avoid redundant queries between get_user_role_for_user,
     * generate_spt_for_user, and context injection.
     *
     * @param  int   $userid
     * @return array  'role', 'course_ids_owned', 'course_ids_enrolled', 'courses_by_role'
     */
    private static function get_user_full_context(int $userid): array {
        $sysctx = \context_system::instance();

        $isadmin = false;
        $admins = get_admins();
        foreach ($admins as $admin) {
            if ((int) $admin->id === $userid) {
                $isadmin = true;
                break;
            }
        }

        // Enumerate courses via enrolments AND role_assignments.
        // enrol_get_users_courses only returns active enrolments — an admin
        // or teacher may have role assignments at course context without
        // being "enrolled" via an enrolment plugin.
        $courses = enrol_get_users_courses($userid, true, ['id', 'fullname']);
        $seenids = [];
        foreach ($courses as $c) {
            $seenids[(int)$c->id] = true;
        }

        // Also fetch courses via role_assignments (catches roles without enrolment).
        global $DB;
        $ras = $DB->get_records_sql(
            "SELECT DISTINCT ra.contextid, ctx.instanceid AS courseid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = ?
              WHERE ra.userid = ?",
            [CONTEXT_COURSE, $userid]
        );
        foreach ($ras as $ra) {
            $cid = (int)$ra->courseid;
            if (!isset($seenids[$cid])) {
                $crecord = $DB->get_record('course', ['id' => $cid], 'id, fullname');
                if ($crecord) {
                    $courses[] = $crecord;
                    $seenids[$cid] = true;
                }
            }
        }

        $courseidsowned  = [];
        $courseidsenrolled = [];
        $coursesbyrole = ['manager' => [], 'teacher' => [], 'student' => []];
        $primaryrole = $isadmin ? 'admin' : 'student';

        foreach ($courses as $c) {
            $ctx = \context_course::instance($c->id);

            if ($isadmin) {
                // Admin: use ACTUAL role assignments, not capabilities.
                // has_capability() always returns true for admins — wrong.
                $assigned = get_user_roles($ctx, $userid, false);
                $shortnames = array_map(fn(\stdClass $r): string => $r->shortname, $assigned);
                if (in_array('manager', $shortnames, true)) {
                    $coursesbyrole['manager'][] = $c->fullname;
                    $courseidsowned[] = (int)$c->id;
                } else if (in_array('editingteacher', $shortnames, true)) {
                    $coursesbyrole['teacher'][] = $c->fullname;
                    $courseidsowned[] = (int)$c->id;
                } else if (in_array('student', $shortnames, true)) {
                    $coursesbyrole['student'][] = $c->fullname;
                    $courseidsenrolled[] = (int)$c->id;
                }
                // No explicit role → skip (admin has access but no role assignment).
            } else {
                if (has_capability('moodle/course:update', $ctx, $userid)) {
                    $coursesbyrole['manager'][] = $c->fullname;
                    $courseidsowned[] = (int)$c->id;
                    $primaryrole = 'manager';
                } else if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                    $coursesbyrole['teacher'][] = $c->fullname;
                    $courseidsowned[] = (int)$c->id;
                    if ($primaryrole !== 'manager') {
                        $primaryrole = 'teacher';
                    }
                } else {
                    $coursesbyrole['student'][] = $c->fullname;
                    $courseidsenrolled[] = (int)$c->id;
                }
            }
        }

        if (!$isadmin && $primaryrole === 'student' && has_capability('moodle/user:update', $sysctx, $userid)) {
            $primaryrole = 'manager';
        }

        return [
            'role'              => $primaryrole,
            'course_ids_owned'  => $courseidsowned,
            'course_ids_enrolled' => $courseidsenrolled,
            'courses_by_role'   => $coursesbyrole,
        ];
    }
}
