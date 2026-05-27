<?php
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

use local_jedomi\schema\table_indexer;
use local_jedomi\schema\ws_indexer;

class webhook_handler {

    /** Lock TTL in seconds — stale locks older than this are auto-released. */
    private const LOCK_TTL = 120;

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

        // Unknown update type — silently ignore
        if (function_exists('\\local_jedomi_log')) {
            \local_jedomi_log('Telegram webhook: unknown update type');
        }
    }

    /**
     * Handle an incoming message (text or photo).
     */
    private static function handle_message(array $message): void {
        global $DB;

        $chat_id = (string) ($message['chat']['id'] ?? '');
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? null;

        if ($chat_id === '') {
            return;
        }

        error_log('[TELEGRAM ENTRY] chat=' . $chat_id . ' text=' . mb_substr($text, 0, 120));

        // ── Deduplicate via MySQL advisory lock ──
        // Telegram retries if 200 OK is slow. Without dedup, multiple PHP-FPM
        // workers process the same message concurrently, spawning 50-iter loops
        // each and overwhelming the Gateway.
        // GET_LOCK('key', 0) returns 1 if lock acquired, 0 if another process
        // holds it. Key is unique per chat_id — concurrent messages from
        // different chats are NOT blocked. Lock auto-releases on script end.
        try {
            $lock_result = (int) $DB->get_field_sql("SELECT GET_LOCK('jedomi_chat_{$chat_id}', 0)");
            if ($lock_result !== 1) {
                if (function_exists('\\local_jedomi_log')) {
                    \local_jedomi_log('DEDUP: another process is handling chat ' . $chat_id . ', skipping');
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
            $client->send_message($chat_id, $help, self::get_linked_keyboard());
            return;
        }

        // Check and record rate limiting atomically
        if (self::check_and_record_rate($chat_id)) {
            $cooldown = manager::get_cooldown();
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => $cooldown]));
            return;
        }

        // Check if user is linked
        $link = self::get_link_by_chat_id($chat_id);
        if (!$link) {
            // Unlinked user — check if it's a /start command with code
            if (strpos($text, '/start') === 0) {
                $username = (string) ($message['chat']['username'] ?? '');
                self::handle_start_command($chat_id, $text, $username);
                return;
            }
            // Check if text looks like a bare link code
            if (self::looks_like_link_code($text)) {
                $username = (string) ($message['chat']['username'] ?? '');
                self::handle_start_command($chat_id, '/start ' . $text, $username);
                return;
            }
            // Otherwise ask them to link first
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_link_first', 'local_jedomi'),
                self::get_unlinked_keyboard());
            return;
        }

        // Check role permission
        if (!self::is_user_role_allowed($link->userid)) {
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_role_blocked', 'local_jedomi'));
            return;
        }

        // ── Impersonate the linked Moodle user ─────────────────────────
        // The Telegram chat is linked to $link->userid. Set $USER so that
        // Moodle's native permission system (validate_context, require_login,
        // require_capability, has_capability) works identically to the web
        // version for the ENTIRE request — proxy_to_gateway, tool loop,
        // and ALL execute_tool cases.
        global $USER;
        $USER = $DB->get_record('user', ['id' => $link->userid, 'deleted' => 0], '*', MUST_EXIST);

        // Handle /desvincular command
        if (strcasecmp($text, '/desvincular') === 0 || strcasecmp($text, '/unlink') === 0) {
            self::handle_unlink_command($chat_id, $link);
            return;
        }

        // Handle /new command to start fresh conversation
        if (strcasecmp($text, '/new') === 0 || strcasecmp($text, '/nuevo') === 0 || strcasecmp($text, '/clear') === 0) {
            error_log('[TELEGRAM ENTRY] /new detected, calling proxy_to_gateway user=' . $link->userid);
            session::clear($chat_id);
            session::clear_context($chat_id);
            // Clear any stale tool_loop state with new session generation.
            session::set_context('tool_loop_' . $chat_id, ['session_gen' => time()]);
            // Get user's name so the model greets them personally.
            $firstname = $DB->get_field('user', 'firstname', ['id' => $link->userid]) ?: 'user';
            self::proxy_to_gateway($link->userid, $chat_id, "Hola, soy {$firstname}", null);
            return;
        }

        // Build and forward to Gateway
        self::proxy_to_gateway($link->userid, $chat_id, $text, $photo);
    }

    /**
     * Handle /start CODE command for account linking.
     */
    private static function handle_start_command(string $chat_id, string $text, string $tg_username): void {
        $parts = explode(' ', $text, 2);
        $code  = strtoupper(trim($parts[1] ?? ''));
        $client = new api_client();

        if (empty($code)) {
            $client->send_message($chat_id, get_string('telegram_msg_provide_code', 'local_jedomi'));
            return;
        }

        if (strlen($code) < 4 || strlen($code) > 8 || !preg_match('/^[A-Z0-9]+$/', $code)) {
            $client->send_message($chat_id, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        global $DB;

        // Resolve code → userid using the APPLICATION-mode cache.
        // (MODE_SESSION cache would not work here — no user session in webhook context.)
        $userid = manager::resolve_link_code($code);
        if ($userid === null) {
            $client->send_message($chat_id, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Double-validate: the forward mapping must also match.
        if (!manager::validate_link_code($userid, $code)) {
            $client->send_message($chat_id, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            $client->send_message($chat_id, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Upsert the link (delete any existing link for this user first).
        $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
        $DB->insert_record('local_jedomi_telegram_links', (object)[
            'userid'             => $userid,
            'chatid'             => $chat_id,
            'username'           => $tg_username,
            'confirmed'          => 1,
            'timecreated'        => time(),
            'lastproactivesent'  => 0,
        ]);

        // Invalidate code so it can't be reused.
        manager::invalidate_link_code($userid, $code);

        $fullname = fullname($user);
        $client->send_message($chat_id, get_string('telegram_msg_linked_success', 'local_jedomi', [
            'fullname' => s($fullname),
            'username' => s($user->username),
        ]));
    }

    /**
     * Handle /desvincular or /unlink command.
     */
    private static function handle_unlink_command(string $chat_id, \stdClass $link): void {
        global $DB;
        $DB->delete_records('local_jedomi_telegram_links', ['id' => $link->id]);
        // Also clean up session and rate limit data
        $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
        $DB->delete_records('local_jedomi_telegram_rate_limit', ['chatid' => $chat_id]);
        $DB->delete_records('local_jedomi_telegram_pending_actions', ['chatid' => $chat_id]);

        $client = new api_client();
        $client->send_message($chat_id, get_string('telegram_msg_unlinked', 'local_jedomi'));
    }

    /**
     * Handle callback_query from inline buttons (confirmations).
     */
    /**
     * Execute a command triggered by inline keyboard callback.
     */
    private static function execute_cmd_callback(string $command, string $chat_id, int $message_id, api_client $client): void {
        switch ($command) {
            case 'new':
                session::clear($chat_id);
                $client->edit_message_text($chat_id, $message_id,
                    get_string('telegram_msg_new_conversation', 'local_jedomi'));
                break;
            case 'help':
                $help = get_string('telegram_help_heading', 'local_jedomi') . "\n\n"
                      . get_string('telegram_help_start', 'local_jedomi') . "\n"
                      . get_string('telegram_help_help', 'local_jedomi') . "\n"
                      . get_string('telegram_help_new', 'local_jedomi') . "\n"
                      . get_string('telegram_help_unlink', 'local_jedomi') . "\n\n"
                      . get_string('telegram_help_footer', 'local_jedomi');
                $client->edit_message_text($chat_id, $message_id, $help);
                break;
            case 'unlink':
                global $DB;
                $link = self::get_link_by_chat_id($chat_id);
                if ($link) {
                    $DB->delete_records('local_jedomi_telegram_links', ['id' => $link->id]);
                    $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
                    $DB->delete_records('local_jedomi_telegram_rate_limit', ['chatid' => $chat_id]);
                    $DB->delete_records('local_jedomi_telegram_pending_actions', ['chatid' => $chat_id]);
                }
                $client->edit_message_text($chat_id, $message_id,
                    get_string('telegram_msg_unlinked', 'local_jedomi'));
                break;
            case 'howtolin':
                $client->edit_message_text($chat_id, $message_id,
                    get_string('telegram_msg_provide_code', 'local_jedomi') . "\n\n" .
                    get_string('telegram_link_instructions', 'local_jedomi'));
                break;
            default:
                // Unknown command — silently ignore.
        }
    }

    /**
     * Build inline keyboard for a linked user (new, help, unlink).
     */
    private static function get_linked_keyboard(): array {
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
     */
    private static function get_unlinked_keyboard(): array {
        return [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_keyboard_howto_link', 'local_jedomi'),
                    'callback_data' => 'cmd:howtolin'],
                ['text' => get_string('telegram_keyboard_help', 'local_jedomi'),
                    'callback_data' => 'cmd:help'],
            ]],
        ];
    }

    private static function handle_callback_query(array $callback): void {
        $callback_id = $callback['id'] ?? '';
        $data = $callback['data'] ?? '';
        $chat_id = (string) ($callback['message']['chat']['id'] ?? '');
        $message_id = (int) ($callback['message']['message_id'] ?? 0);

        if ($callback_id === '' || $data === '') {
            return;
        }

        $client = new api_client();
        $client->answer_callback_query($callback_id);

        // Handle command shortcuts from inline keyboard (/new, /help, /unlink).
        if (strpos($data, 'cmd:') === 0) {
            $command = substr($data, strlen('cmd:'));
            self::execute_cmd_callback($command, $chat_id, $message_id, $client);
            return;
        }

        if (strpos($data, 'confirm:') !== 0) {
            // Unknown action — ignore
            return;
        }

        $action_id = substr($data, strlen('confirm:'));

        global $DB;
        $pending = $DB->get_record('local_jedomi_telegram_pending_actions', ['callbackid' => $action_id]);
        if (!$pending) {
            $client->edit_message_text($chat_id, $message_id, "❌ Action expired. Please request again.");
            return;
        }

        $action = json_decode($pending->actionjson, true);
        if (!is_array($action)) {
            $client->edit_message_text($chat_id, $message_id, "❌ Invalid action data.");
            return;
        }

        // Execute the tool directly (bypass LLM)
        // For now, we support only a limited set of safe tools
        // Full implementation requires mapping action names to PHP functions
        $result = self::execute_pending_action($action, $pending->chatid);

        // Delete pending action
        $DB->delete_records('local_jedomi_telegram_pending_actions', ['id' => $pending->id]);

        // Edit original message with result
        $client->edit_message_text($chat_id, $message_id, $result);
    }

    /**
     * Self-ping: fire a cURL to our own webhook so a new PHP-FPM worker
     * continues the checkpointed tool loop. The webhook responds 200 OK
     * immediately (fastcgi_finish_request), so this returns in <1s.
     */
    private static function self_ping(string $chat_id): void {
        global $CFG;
        $secret = \local_jedomi\telegram\manager::get_webhook_secret();
        $callback = $CFG->wwwroot . '/local/jedomi/telegram_webhook.php';
        $state_self = session::get_context('tool_loop_' . $chat_id);
        $session_gen = $state_self['session_gen'] ?? 0;
        $url = $callback . '?secret=' . urlencode($secret)
             . '&internal_ping=1&chat_id=' . urlencode($chat_id)
             . '&session_gen=' . $session_gen;

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
            error_log('[TELEGRAM SELF-PING] cURL error: ' . $err . ' for chat=' . $chat_id);
        }
    }

    /**
     * Handle an internal self-ping request. Loads the checkpoint state
     * and continues the tool loop from where the previous worker left off.
     */
    public static function handle_internal_ping(string $chat_id): void {
        global $DB;

        $state = session::get_context('tool_loop_' . $chat_id);
        if (empty($state) || empty($state['messages'])) {
            return; // Nothing to continue.
        }

        // Validate session_gen (TOCTOU guard for /new race).
        $expected_gen = (int)($state['session_gen'] ?? 0);
        $incoming_gen = (int)($_GET['session_gen'] ?? 0);
        if ($expected_gen > 0 && $incoming_gen > 0 && $incoming_gen !== $expected_gen) {
            error_log('[GATELOOP] session_gen mismatch chat=' . $chat_id . ' expected=' . $expected_gen
                . ' got=' . $incoming_gen . ' — aborting stale self-ping');
            return;
        }

        // Restore userid from checkpoint (saved in normal flow).
        // Avoids querying local_jedomi_telegram_links on every self-ping.
        $userid = (int)($state['userid'] ?? 0);
        if ($userid <= 0) {
            // Fallback: legacy state (pre-cache) — query link table.
            $link = self::get_link_by_chat_id($chat_id);
            if (!$link) {
                return;
            }
            $userid = (int)$link->userid;
        }

        // Impersonate user so permission checks work.
        global $USER;
        $USER = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

        self::proxy_to_gateway($userid, $chat_id, '', null, true);
    }

    /**
     * Main proxy loop: sends messages to the Gateway AI API, handles tool calls,
     * streams responses, and self-pings when approaching FPM timeout so the
     * process survives across multiple PHP-FPM workers.
     *
     * @param int    $userid        Moodle user ID.
     * @param string $chat_id       Telegram chat ID.
     * @param string $text          Original user message text.
     * @param array|null $photo     Optional photo data.
     * @param bool   $internal_ping True if called from self-ping (continue checkpoint).
     */
    private static function proxy_to_gateway(int $userid, string $chat_id, string $text, ?array $photo, bool $internal_ping = false): void {
        global $CFG, $DB;

        $state_key = 'tool_loop_' . $chat_id;

        // ── Normal flow: build messages & context from scratch ──
        if (!$internal_ping) {
            // Check if another worker is already processing this chat.
            $existing = session::get_context($state_key);
            if (!empty($existing['is_locked'])) {
                $lock_age = time() - ($existing['locked_at'] ?? time());
                if ($lock_age < self::LOCK_TTL) {
                    // Lock is still valid (< 2 min) — block.
                    (new api_client())->send_message($chat_id,
                        '⏳ Sigo procesando tu solicitud anterior, dame un momento...');
                    return;
                }
                // Lock expired (> LOCK_TTL seconds) — auto-release and continue.
                error_log('[GATELOOP] stale_lock chat=' . $chat_id . ' age=' . $lock_age . 's — auto-releasing');
                session::set_context($state_key, []);
                // Fall through to process the new message.
            }

            // ── Send typing indicator so user knows it's processing ──
            (new api_client())->send_chat_action($chat_id, 'typing');

            // Build messages for the Gateway.
            $messages = session::build_messages_for_gateway($chat_id, $text);

            // If photo, download and encode
            $user_content = $text;
            if ($photo !== null && !empty($photo)) {
                $largest = end($photo);
                $client = new api_client();
                $tmp_path = $client->download_file($largest['file_id'] ?? '');
                if ($tmp_path && file_exists($tmp_path)) {
                    $image_data = self::resize_and_base64($tmp_path, 512);
                    if ($image_data) {
                        $user_content = [
                            ['type' => 'text', 'text' => $text],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $image_data]],
                        ];
                    }
                    @unlink($tmp_path);
                }
            }

            if (is_array($user_content)) {
                array_pop($messages);
                $messages[] = ['role' => 'user', 'content' => $user_content];
            }

            // Persist user message.
            session::append($chat_id, 'user', $text);

            // ── User context cache ──
            $user_ctx = session::get_context($chat_id);
            if ($user_ctx === null) {
                $user_ctx = self::get_user_full_context($userid);
                session::set_context($chat_id, $user_ctx);
            }
            $role = $user_ctx['role'];
            $spt = self::generate_spt_for_user($userid, $role, $user_ctx['course_ids_owned'], $user_ctx['course_ids_enrolled']);
            $tools = $spt['tools'] ?? [];

            // Gateway config (from plugin settings, with safe fallbacks).
            $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
            $api_key = get_config('local_jedomi', 'api_key');
            $model = get_config('local_jedomi', 'model');
            $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
            if (empty($gateway_url)) { $gateway_url = 'http://localhost:8765'; }
            if (empty($api_key)) { $api_key = 'jedomi-local-key'; }
            if (empty($model)) { $model = 'jedomi-e4b'; }
            if ($timeout < 5) { $timeout = 120; }
            if ($timeout > 300) { $timeout = 300; }

            // ── Restore checkpoint if a previous attempt was killed ──
            try {
                $checkpoint = session::get_context($state_key);
            } catch (\Exception $e) {
                error_log('[GATELOOP] checkpoint read FAILED for chat ' . $chat_id . ': ' . $e->getMessage());
                $checkpoint = null;
            }
            if (!empty($checkpoint) && !empty($checkpoint['messages'])) {
                $last_user_msg = '';
                for ($i = count($checkpoint['messages']) - 1; $i >= 0; $i--) {
                    if (($checkpoint['messages'][$i]['role'] ?? '') === 'user'
                        && is_string($checkpoint['messages'][$i]['content'] ?? null)) {
                        $last_user_msg = $checkpoint['messages'][$i]['content'];
                        break;
                    }
                }
                if ($last_user_msg === $text) {
                    $messages = $checkpoint['messages'];
                    $cp_json = json_encode($checkpoint, JSON_UNESCAPED_UNICODE);
                    error_log(sprintf('[GATELOOP] checkpoint restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                        $chat_id, $checkpoint['iteration'] ?? 0, strlen($cp_json), count($checkpoint['messages'])));
                } else {
                    session::set_context($state_key, []);
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

        // ── Self-ping flow: restore messages & context from state ──
        } else {
            $state = session::get_context($state_key);
            if (empty($state) || empty($state['messages'])) {
                error_log('[GATELOOP] self-ping empty state for chat ' . $chat_id . ', nothing to continue');
                return; // Nothing to continue.
            }
            $messages = $state['messages'];
            $state_json_check = json_encode($state, JSON_UNESCAPED_UNICODE);
            error_log(sprintf('[GATELOOP] self-ping restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                $chat_id, $state['iteration'] ?? 0, strlen($state_json_check), count($messages)));

            // ── Restore SPT + tools from checkpoint (cached, avoids DB re-queries) ──
            $spt = $state['spt'] ?? null;
            $tools = $state['tools'] ?? [];
            if (empty($spt)) {
                // Fallback: legacy state (pre-cache) — recalc from DB.
                $user_ctx = session::get_context($chat_id);
                if ($user_ctx === null) {
                    $user_ctx = self::get_user_full_context($userid);
                    session::set_context($chat_id, $user_ctx);
                }
                $role = $user_ctx['role'];
                $spt = self::generate_spt_for_user($userid, $role, $user_ctx['course_ids_owned'], $user_ctx['course_ids_enrolled']);
                $tools = $spt['tools'] ?? [];
            }

            $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
            $api_key = get_config('local_jedomi', 'api_key');
            $model = get_config('local_jedomi', 'model');
            $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
            if (empty($gateway_url)) { $gateway_url = 'http://localhost:8765'; }
            if (empty($api_key)) { $api_key = 'jedomi-local-key'; }
            if (empty($model)) { $model = 'jedomi-e4b'; }
            if ($timeout < 5) { $timeout = 120; }
            if ($timeout > 300) { $timeout = 300; }

            // Ensure lock is active.
            $state['is_locked'] = true;
            $state['locked_at'] = time();
        }

        // ── Build system message with course context (same for both flows) ──
        $sitename = get_config('core', 'sitename') ?: 'Moodle';
        $release = $CFG->release ?? 'unknown';
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => 'MySQL/MariaDB',
            'pgsql'             => 'PostgreSQL',
            default             => $dbtype,
        };
        $tool_names = array_map(fn(array $t): string => $t['function']['name'], $tools);
        $courses_by_role = $user_ctx['courses_by_role'];
        $user_lang = $DB->get_field('user', 'lang', ['id' => $userid]) ?: current_language();

        $system_content = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n"
            . "Role: {$role}.\n"
            . "IMPORTANT: Respond in the user's language ({$user_lang}). "
            . "The user writes in {$user_lang}, you answer in {$user_lang}.\n\n"
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
            . "- Be concise, direct, respond in {$user_lang} only.\n\n"
            . "⚡ YOU HAVE MANY ITERATIONS — DO NOT GIVE UP:\n"
            . "- You have up to 50 tool call rounds. Use them.\n"
            . "- Never say 'too many steps' or 'simplify your request'.\n"
            . "- Just keep calling tools until you have the data to answer.\n"
            . "- Complex tasks need more rounds — that is OK.\n\n"
            . "⚡ CRITICAL — NEVER DO THIS:\n"
            . "- Do NOT describe a plan or list steps you are about to take.\n"
            . "- Do NOT say 'I will search for X' or 'Let me verify Y'.\n"
            . "- Do NOT show your reasoning or chain of thought.\n"
            . "- Respond in the user's language ONLY ({$user_lang}).\n"
            . "- JUST CALL THE TOOL. Get the data. Respond with the result.\n"
            . "- If you need info → call the tool directly, no narration.\n"
            . "- Your final text response must be in {$user_lang}.\n\n"
            . "Context: {$release}, {$sitename}, DB: {$dblabel}, User: {$userid}, Role: {$role}, Lang: {$user_lang}\n"
            . "Admin: " . ($role === 'admin' ? 'YES' : 'NO') . "\n"
            . "Tools: " . implode(', ', $tool_names) . "\n"
            . "Courses: " . (empty($courses_by_role) ? 'none' : json_encode($courses_by_role)) . "\n"
            . "Execute what the user asked and respond. Don't keep searching.";

        $messages[0] = ['role' => 'system', 'content' => $system_content];

        // ── Tool loop uses state-based iteration ──
        // Each HTTP worker runs until ~55s, then self-pings so a new worker
        // picks up at the saved iteration. The user sees a single seamless response.
        $LOOP_START = time();
        $MAX_ITERATIONS = 50;
        $hard_guard = 55; // seconds — chain to next worker before FPM kills us

        // Extend PHP execution limit to cover our hard guard + buffer.
        // Without this, php max_execution_time or fpm request_terminate_timeout
        // (often 30s on shared hosts) kills the worker while cURL waits,
        // producing NO error log — just a silent hang.
        $old_limit = @ini_get('max_execution_time');
        @set_time_limit($hard_guard + 20); // 75s total, safe for most hosts
        error_log(sprintf('[GATELOOP] time_limit: old=%s new=%d hard_guard=%d',
            $old_limit ?: 'unlimited', $hard_guard + 20, $hard_guard));

        $toolrepeatcounts = [];
        $progress_sent_first = false;
        $sent_timeout_warning = false;

        for ($iteration = (int)($state['iteration'] ?? 0); $iteration < $MAX_ITERATIONS; $iteration++) {
            $elapsed = time() - $LOOP_START;

            if ($iteration > 0) {
                error_log(sprintf('[GATELOOP] iter_top chat=%s iter=%d elapsed=%ds msg_count=%d',
                    $chat_id, $iteration, $elapsed, count($messages)));
            }

            // ── Hard guard (~55s): save state + self_ping → next worker ──
            // IMPORTANT: save $iteration + 1 (NEXT expected iteration),
            // NOT $iteration (current). The for loop increments AFTER the
            // body, so saving $iteration means the next worker re-does it.
            if ($elapsed >= $hard_guard) {
                error_log(sprintf('[GATELOOP] hard_guard chat=%s iter=%d elapsed=%d self-pinging',
                    $chat_id, $iteration, $elapsed));
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                session::set_context($state_key, $state);
                self::self_ping($chat_id);
                return;
            }

            // ── Medium guard (~40s): self-ping so next worker continues
            // before FPM request_terminate_timeout silently kills this worker.
            // Only on internal iterations (>0).
            if ($elapsed >= 40 && $iteration > 0) {
                error_log(sprintf('[GATELOOP] medium_guard chat=%s iter=%d elapsed=%d self-pinging',
                    $chat_id, $iteration, $elapsed));
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                session::set_context($state_key, $state);
                self::self_ping($chat_id);
                return;
            }

            // ── Soft guard (~30s): send a keepalive so the user knows we're alive ──
            if ($elapsed >= 30 && !$sent_timeout_warning) {
                $sent_timeout_warning = true;
                (new api_client())->send_message($chat_id,
                    "🔄 Sigamos, estoy procesando tu solicitud…");
            }

            // ── Re-send typing indicator each iteration (Telegram auto-expires after ~6s) ──
            // Only on iterations after the first (avoids double-send with the initial one).
            if ($iteration > 0) {
                (new api_client())->send_chat_action($chat_id, 'typing');
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

            // ── Stream from Gateway (same as web version) ──
            $payload['stream'] = true;
            $sse_buffer = '';
            $http_code = 0;
            $curl_errno = 0;
            $curl_error = '';

            // Adaptive Gateway timeout: shrink as we approach the hard guard
            // so we don't get stuck waiting when FPM is about to kill us.
            $curl_timeout = max(5, min($timeout, $hard_guard - (time() - $LOOP_START) - 5));

            $ch = curl_init($gateway_url . '/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT        => $curl_timeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $api_key,
                ],
                CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$sse_buffer) {
                    $sse_buffer .= $data;
                    return strlen($data);
                },
            ]);

            error_log(sprintf('[GATELOOP] calling_gateway chat=%s iter=%d elapsed=%ds curl_timeout=%ds payload_bytes=%d msg_count=%d',
                $chat_id, $iteration, time() - $LOOP_START, $curl_timeout,
                strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)), count($messages)));

            curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            // ── Log Gateway response for debugging ──
            error_log(sprintf('[GATEWAY] iter=%d http=%d curl_errno=%d curl_err=%s sse_bytes=%d',
                $iteration, $http_code, $curl_errno, $curl_error ?: 'none', strlen($sse_buffer)));

            // ── Gateway errors ──
            if ($curl_errno !== 0) {
                if ($curl_errno === CURLE_OPERATION_TIMEDOUT) {
                    error_log('[GATEWAY] Timeout on iter ' . $iteration . ', self-pinging');
                    // Gateway timed out but there may be more time left for
                    // FPM. Self-ping to continue with remaining iterations.
                    $state['iteration'] = $iteration + 1;
                    $state['messages'] = $messages;
                    $state['is_locked'] = true;
                    $state['locked_at'] = time();
                    session::set_context($state_key, $state);
                    self::self_ping($chat_id);
                } else {
                    error_log('[GATEWAY] cURL error on iter ' . $iteration . ': ' . $curl_error);
                    self::release_lock($state_key, $chat_id, get_string('error_telegram_e004', 'local_jedomi'));
                }
                return;
            }
            if ($http_code !== 200) {
                error_log('[GATEWAY] HTTP ' . $http_code . ' on iter ' . $iteration . ', body: ' . substr($sse_buffer, 0, 200));
                self::release_lock($state_key, $chat_id, get_string('error_telegram_e005', 'local_jedomi'));
                return;
            }

            // ── Parse SSE buffer (same parser as web version) ──
            $result = self::parse_sse_buffer($sse_buffer);
            if ($result === null) {
                error_log('[GATEWAY] SSE parse failed on iter ' . $iteration . ', buffer: ' . substr($sse_buffer, 0, 200));
                self::release_lock($state_key, $chat_id, get_string('error_telegram_e006', 'local_jedomi'));
                return;
            }

            if ($result['type'] === 'tool_calls') {
                $message = ['tool_calls' => $result['tool_calls']];
                $content = '';
                $has_tools = true;
            } else {
                $message = ['content' => $result['content'] ?? ''];
                $content = $result['content'] ?? '';
                $has_tools = false;

                // ── Fallback: text-based TOOL_CALL parsing (for models that
                //    don't support native function calling). ────────────────
                $tcPattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';
                $tcPattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

                $toolCalls = [];
                $remainingText = $content;

                // Try Pattern 1: TOOL_CALL: ...
                if (preg_match_all($tcPattern1, $content, $tcMatches, PREG_SET_ORDER)) {
                    $remainingText = trim(preg_replace($tcPattern1, '', $content));
                    foreach ($tcMatches as $m) {
                        $toolName = trim($m[1]);
                        $argsJson = trim($m[2]);
                        json_decode($argsJson);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            if (preg_match('/\{.*\}/s', $argsJson, $jsonMatch)) {
                                $argsJson = $jsonMatch[0];
                            }
                        }
                        json_decode($argsJson);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolCalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolName,
                                    'arguments' => $argsJson,
                                ],
                            ];
                        }
                    }
                }

                // Try Pattern 2 (broader): allow JSON inline after `call:` with optional newline
                // before END_TOOL_CALL. Handles variations where the model omits newlines.
                if (empty($toolCalls)) {
                    $tcPattern1b = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)(?:\n\s*)?END_TOOL_CALL[^\n]*/si';
                    if (preg_match_all($tcPattern1b, $content, $tcMatches2, PREG_SET_ORDER)) {
                        $remainingText = trim(preg_replace($tcPattern1b, '', $content));
                        foreach ($tcMatches2 as $m) {
                            $toolName = trim($m[1]);
                            $argsJson = trim($m[2]);
                            json_decode($argsJson);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                if (preg_match('/\{.*\}/s', $argsJson, $jsonMatch)) {
                                    $argsJson = $jsonMatch[0];
                                }
                            }
                            json_decode($argsJson);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolCalls[] = [
                                    'id' => 'text_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolName,
                                        'arguments' => $argsJson,
                                    ],
                                ];
                            }
                        }
                    }
                }

                // Try Pattern 3: <|tool_call|> (Llama 3 style) — only if patterns 1&2 didn't match.
                if (empty($toolCalls) && preg_match_all($tcPattern2, $content, $tcMatches, PREG_SET_ORDER)) {
                    $remainingText = trim(preg_replace($tcPattern2, '', $content));
                    foreach ($tcMatches as $m) {
                        $inner = trim($m[1]);
                        $decoded = json_decode($inner, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                            $toolName = $decoded['name'];
                            $args = $decoded['arguments'] ?? '{}';
                            if (is_array($args)) { $args = json_encode($args, JSON_UNESCAPED_UNICODE); }
                        } else {
                            $parts = explode("\n", $inner, 2);
                            $toolName = trim($parts[0]);
                            $args = $parts[1] ?? '{}';
                        }
                        json_decode($args);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolCalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolName,
                                    'arguments' => $args,
                                ],
                            ];
                        }
                    }
                }

                // ── Ultra-broad fallback: if content still looks like it has tool calls
                //    but no regex matched, try grabbing everything between 'call:\w+' and
                //    'END_TOOL_CALL' as raw JSON. This catches unusual line breaks/whitespace.
                if (empty($toolCalls) && preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    $tcUltra = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)\s*END_TOOL_CALL\s*/si';
                    if (preg_match_all($tcUltra, $content, $tcMatches3, PREG_SET_ORDER)) {
                        $remainingText = trim(preg_replace($tcUltra, '', $content));
                        foreach ($tcMatches3 as $m) {
                            $toolName = trim($m[1]);
                            // Accept whatever text is between call and END_TOOL_CALL,
                            // attempt to extract a JSON object.
                            $raw = trim($m[2]);
                            if (preg_match('/\{.*\}/s', $raw, $jsonMatch)) {
                                $raw = $jsonMatch[0];
                            }
                            json_decode($raw);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolCalls[] = [
                                    'id' => 'ultra_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolName,
                                        'arguments' => $raw,
                                    ],
                                ];
                            }
                        }
                    }
                }

                if (!empty($toolCalls)) {
                    $message = [
                        'tool_calls' => $toolCalls,
                        'content' => $remainingText ?: '',
                    ];
                    $content = $remainingText ?: '';
                    $has_tools = true;
                } else if (preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    // Log when content looks like it should have tool calls but none parsed.
                    debugging(
                        'Telegram: unparseable tool call in content — ' . mb_substr($content, 0, 500),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // ── Progress messages (icons + lang strings, same as web) ──
            if (!$progress_sent_first) {
                $progress_sent_first = true;
            }
            if ($has_tools && ($iteration % 5 === 0 || $iteration === 1)) {
                $tool_display = [];
                // Map tool names to lang string keys (WS/admin tools)
                // or inline labels (create_* tools — no lang strings exist).
                $tool_lang_keys = [
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
                $tool_inline_labels = [
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
                    'list_agent'      => 'Listar agentes',  // Alias hallucinated singular.
                    'create_agent'    => 'Crear agente',
                    'create_agents'   => 'Crear agente',    // Alias hallucinated plural.
                    'delete_agent'    => 'Eliminar agente',
                    'delete_agents'   => 'Eliminar agente', // Alias hallucinated plural.
                ];
                foreach ($message['tool_calls'] ?? [] as $tc) {
                    $name = $tc['function']['name'] ?? '';
                    if (isset($tool_lang_keys[$name])) {
                        $label = get_string($tool_lang_keys[$name], 'local_jedomi');
                    } elseif (isset($tool_inline_labels[$name])) {
                        $label = $tool_inline_labels[$name];
                    } else {
                        $label = $name;
                    }
                    $tool_display[] = $label;
                }
                $progress_icon = match (true) {
                    $iteration <= 1  => '🔍',
                    $iteration <= 5  => '📖',
                    $iteration <= 10 => '⚡',
                    $iteration <= 20 => '📊',
                    default          => '⚙️',
                };
                (new api_client())->send_message($chat_id,
                    $progress_icon . ' ' . implode(', ', $tool_display) . '…');
            }

            if (!$has_tools) {
                // ── Text response: send to Telegram ──

                // 0. Convert HTML tables to pipe-delimited <pre> blocks.
                //    Telegram does NOT support <table>/<tr>/<td> — strip_tags
                //    would leave all data concatenated (unreadable).
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
                //    the model often uses **bold** or *italic* despite instructions).
                //    Order matters: bold before italic, inline code before formatting.
                // 3a. ``` code blocks → <pre>
                $content = preg_replace('/```(\w*)\s*\n?(.*?)```/s', '<pre>$2</pre>', $content) ?? $content;
                // 3b. ``inline code`` (double) → <pre>
                $content = preg_replace('/``([^`]+)``/', '<pre>$1</pre>', $content) ?? $content;
                // 3c. `inline code` (single) → <code>  (before formatting to avoid ** inside code)
                $content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content) ?? $content;

                // 3c-bis. Protect * inside <code>/<pre> from being consumed by bold/italic steps.
                //         Replace * with &#42; (HTML entity) so "30 * * * *" inside <code> stays intact.
                $content = preg_replace_callback('/<(code|pre)\b[^>]*>.*?<\/\1>/is', function($m) {
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
                //    <strike>/<del>→<s>, <span class="tg-spoiler">→<tg-spoiler>.
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
                $display = strip_tags($display,
                    '<b><i><u><s><tg-spoiler><span><blockquote><a><code><pre><tg-emoji>');

                // 6. Strip attributes per Telegram rules using preg_replace_callback
                //    (handles attributes in ANY order, unlike plain regex).
                // 6a. <span class="tg-spoiler"> → <tg-spoiler>.
                $display = preg_replace_callback('/<span\b[^>]*>/i', function($m) {
                    if (preg_match('/class="tg-spoiler"/i', $m[0])) {
                        return '<tg-spoiler>';
                    }
                    return '';
                }, $display);
                $display = str_replace('</span>', '', $display);
                // 6b. Strip attributes from <b>, <i>, <u>, <s>, <tg-spoiler>, <blockquote>, <pre>.
                $display = preg_replace('/<(b|i|u|s|tg-spoiler|blockquote|pre)\b[^>]*>/i', '<$1>', $display);
                // 6c. <code> → keep class="language-*" if present, else strip all.
                $display = preg_replace_callback('/<code\b[^>]*>/i', function($m) {
                    if (preg_match('/class="language-([^"]*)"/i', $m[0], $cm)) {
                        return '<code class="language-' . $cm[1] . '">';
                    }
                    return '<code>';
                }, $display);
                // 6d. <a> → keep href only (double or single quotes).
                $display = preg_replace_callback('/<a\b[^>]*>/i', function($m) {
                    if (preg_match('/href="([^"]*)"/i', $m[0], $hm)) {
                        return '<a href="' . $hm[1] . '">';
                    }
                    if (preg_match("/href='([^']*)'/i", $m[0], $hm)) {
                        return '<a href="' . $hm[1] . '">';
                    }
                    return '';
                }, $display);
                // 6e. <tg-emoji> → keep emoji-id only.
                $display = preg_replace_callback('/<tg-emoji\b[^>]*>/i', function($m) {
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
                    session::set_context($state_key, []);
                    (new api_client())->send_message($chat_id, $fallback);
                    return;
                }

                // ── Release lock before sending final response ──
                error_log('[GATELOOP] text response for chat ' . $chat_id . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $LOOP_START) . 's, len=' . strlen($display));
                session::set_context($state_key, []);

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
                    (new api_client())->send_document($chat_id, $filepath, $chatcaption);
                    @unlink($filepath);
                } else {
                    (new api_client())->send_message($chat_id, $display);
                }
                return;
            }

            // ── Tool calls → execute and feed results back ──
            // ── Synchronisation point 1: re-read state from DB ─────────────
            // If another worker (self-ping) already processed these tools and
            // saved a newer state, skip execution and reload their results.
            // Only trust the saved iteration if the lock is still active (< 2 min).
            // A stale iteration without an active lock means the state is from
            // a dead/crashed worker — ignore it and continue processing.
            $fresh_state = session::get_context($state_key);
            $is_actively_locked = !empty($fresh_state['is_locked'])
                && (time() - ($fresh_state['locked_at'] ?? 0) < self::LOCK_TTL);
            if ($is_actively_locked && ($fresh_state['iteration'] ?? 0) > $iteration) {
                error_log(sprintf('[GATELOOP] stale_iteration chat=%s iter=%d fresh_iter=%d — reloading',
                    $chat_id, $iteration, $fresh_state['iteration']));
                $iteration = (int)$fresh_state['iteration'] - 1; // For loop increments; restore 1 less.
                $messages = $fresh_state['messages'] ?? $messages;
                continue;
            }

            $messages[] = [
                'role'       => 'assistant',
                'content'    => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'],
            ];

            // Loop detection
            $looping = false;
            foreach ($message['tool_calls'] as $tc) {
                $name = $tc['function']['name'] ?? '';
                $args_raw = $tc['function']['arguments'] ?? '{}';
                $args = is_array($args_raw) ? $args_raw : (json_decode($args_raw, true) ?? []);
                $tc_id = $tc['id'] ?? '';
                ksort($args);
                $callkey = $name . '|' . md5(json_encode($args));
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;
                $repeat_count = $toolrepeatcounts[$callkey];
                if ($repeat_count > 8) {
                    error_log(sprintf('[GATELOOP] loop_detected chat=%s iter=%d tool=%s key=%s count=%d',
                        $chat_id, $iteration, $name, $callkey, $repeat_count));
                    $looping = true;
                    break;
                } elseif ($repeat_count >= 4) {
                    error_log(sprintf('[GATELOOP] loop_warning chat=%s iter=%d tool=%s key=%s count=%d',
                        $chat_id, $iteration, $name, $callkey, $repeat_count));
                }

                try {
                    $tool_result = self::execute_tool($name, $args, $userid, $role, $user_ctx['course_ids_owned']);
                    if (mb_strlen($tool_result) > 64000) {
                        $tool_result = mb_substr($tool_result, 0, 64000) . "\n... (truncated)";
                    }
                } catch (\Exception $e) {
                    $tool_result = 'Error executing ' . $name . ': ' . $e->getMessage();
                }
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc_id,
                    'content'      => $tool_result,
                ];
            }

            // ── Save checkpoint with updated iteration + messages ──
            $state['iteration'] = $iteration + 1;
            $state['messages'] = $messages;
            $state['is_locked'] = true;

            $state_json = json_encode($state, JSON_UNESCAPED_UNICODE);
            error_log(sprintf('[GATELOOP] state_save chat=%s iter=%d state_bytes=%d msg_count=%d',
                $chat_id, $iteration, strlen($state_json), count($messages)));

            try {
                session::set_context($state_key, $state);
            } catch (\Exception $e) {
                error_log('[GATELOOP] STATE SAVE FAILED for chat ' . $chat_id
                    . ' iter=' . $iteration . ' bytes=' . strlen($state_json)
                    . ' err=' . $e->getMessage());
                // Fallback: release lock and inform user, don't crash silently.
                session::set_context($state_key, []);
                (new api_client())->send_message($chat_id,
                    '⚠️ Lo siento, tuve un problema al guardar el progreso. '
                    . 'Por favor intentá de nuevo con /new.');
                return;
            }

            if ($looping) {
                error_log('[GATELOOP] loop_exit for chat ' . $chat_id . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $LOOP_START) . 's');
                session::set_context($state_key, []);
                (new api_client())->send_message($chat_id, get_string('telegram_msg_max_iterations', 'local_jedomi'));
                return;
            }
        }

        // ── Max iterations reached ──
        error_log('[GATELOOP] max iterations reached for chat ' . $chat_id);
        session::set_context($state_key, []);
        (new api_client())->send_message($chat_id, get_string('telegram_msg_max_iterations', 'local_jedomi'));
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
        $text_buf = '';
        $reason_buf = '';
        $tool_calls_acc = [];
        $finish_reason = '';

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
            $finish_reason = $json['choices'][0]['finish_reason'] ?? $finish_reason;

            // Accumulate text content.
            if (isset($delta['content']) && $delta['content'] !== null) {
                $text_buf .= $delta['content'];
            }
            // Gemma reasoning_content (thinking tokens — fallback only).
            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null) {
                $reason_buf .= $delta['reasoning_content'];
            }
            // Accumulate tool_calls streamed across multiple delta chunks.
            if (!empty($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (!isset($tool_calls_acc[$idx])) {
                        $tool_calls_acc[$idx] = [
                            'id'       => $tc['id'] ?? '',
                            'type'     => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (!empty($tc['id'])) {
                        $tool_calls_acc[$idx]['id'] = $tc['id'];
                    }
                    if (!empty($tc['function']['name'])) {
                        $tool_calls_acc[$idx]['function']['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $arg = $tc['function']['arguments'];
                        if (is_array($arg)) {
                            $tool_calls_acc[$idx]['function']['arguments'] = json_encode($arg, JSON_UNESCAPED_UNICODE);
                        } else {
                            $tool_calls_acc[$idx]['function']['arguments'] .= $arg;
                        }
                    }
                }
            }
        }

        // Tool calls detected — ensure every entry has a non-empty id.
        if (!empty($tool_calls_acc) || $finish_reason === 'tool_calls') {
            ksort($tool_calls_acc);
            $tool_calls = array_values($tool_calls_acc);
            foreach ($tool_calls as &$tc) {
                if (empty($tc['id'])) {
                    $tc['id'] = 'call_' . substr(md5($tc['function']['name'] . microtime()), 0, 12);
                }
            }
            unset($tc);

            return ['type' => 'tool_calls', 'tool_calls' => $tool_calls];
        }

        // Text response: prefer content, fall back to reasoning (Gemma thinking tokens).
        $text = !empty($text_buf) ? $text_buf : $reason_buf;
        if (empty($text)) {
            return null;
        }

        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Extract pending action from response, store in DB, send inline buttons.
     */
    private static function handle_pending_action(string $chat_id, string $content): void {
        // Extract JSON between JEDOMI_PENDING_ACTION: and :END_PENDING
        if (!preg_match('/JEDOMI_PENDING_ACTION:(.+?):END_PENDING/s', $content, $matches)) {
            // No valid action found — send raw text
            $client = new api_client();
            $client->send_message($chat_id, $content);
            return;
        }

        $action_json = $matches[1];
        $action = json_decode($action_json, true);
        if (!is_array($action)) {
            $client = new api_client();
            $client->send_message($chat_id, $content);
            return;
        }

        global $DB;
        $callback_id = bin2hex(random_bytes(16));
        $DB->insert_record('local_jedomi_telegram_pending_actions', (object)[
            'callbackid'  => $callback_id,
            'chatid'      => $chat_id,
            'actionjson'  => $action_json,
            'timecreated' => time(),
        ]);

        // Build inline keyboard
        $keyboard = [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_msg_confirm', 'local_jedomi'), 'callback_data' => 'confirm:' . $callback_id],
                ['text' => get_string('telegram_msg_cancel', 'local_jedomi'), 'callback_data' => 'cancel:' . $callback_id],
            ]],
        ];

        // Send clean message without the raw JEDOMI_PENDING_ACTION text
        $clean_text = preg_replace('/JEDOMI_PENDING_ACTION:.+?:END_PENDING/s', '', $content);
        $clean_text = trim($clean_text);

        $client = new api_client();
        $client->send_message($chat_id, $clean_text, $keyboard);
    }

    /**
     * Execute a tool by name with given arguments.
     * Respects role-based access — only safe read operations.
     */
    private static function execute_tool(string $name, array $args, int $userid = 0, string $role = '', array $course_ids_owned = []): string {
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

                // ── File exfiltration check (defense-in-depth, matches web version) ──
                if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
                    return "❌ INTO OUTFILE/DUMPFILE is not allowed.";
                }

                // ── Role gate matching web version (lib.php:472) — admin/manager/teacher only ──
                if (!in_array($role, ['admin', 'manager', 'teacher'], true)) {
                    return "❌ Permission denied.";
                }

                // ── Course-level permission check ─────────────────────────────
                // Extract course IDs from SQL (WHERE courseid = N, course = N).
                $referenced_courses = [];
                if (preg_match_all('/\b(?:courseid|course_id|c\.id)\s*[=!]=?\s*(\d+)/i', $sql, $matches)) {
                    $referenced_courses = array_map('intval', array_unique($matches[1]));
                }

                if (!empty($referenced_courses) && $userid > 0) {
                    // Cache admin check — get_admins() called once per request.
                    static $admin_cache = [];
                    if (!array_key_exists($userid, $admin_cache)) {
                        $is_adm = false;
                        $admins = get_admins();
                        foreach ($admins as $admin) {
                            if ((int) $admin->id === $userid) { $is_adm = true; break; }
                        }
                        $admin_cache[$userid] = $is_adm;
                    }
                    if (!$admin_cache[$userid]) {
                        $sysctx = \context_system::instance();
                        $is_system_manager = has_capability('moodle/course:update', $sysctx, $userid);
                        foreach ($referenced_courses as $cid) {
                            $has_perm = $is_system_manager || in_array($cid, $course_ids_owned, true);
                            if (!$has_perm) {
                                return get_string('error_telegram_e008', 'local_jedomi', $cid);
                            }
                        }
                    }
                }
                // ── End permission check ──────────────────────────────────────

                // Normalize Moodle table placeholders (FROM course → FROM {course}).
                $sql = preg_replace_callback(
                    '/\b(FROM|JOIN)\s+([a-z][a-z0-9_]*)\b/i',
                    static function(array $matches): string {
                        return $matches[1] . ' {' . $matches[2] . '}';
                    },
                    $sql
                );

                $params = $args['params'] ?? [];
                if (!is_array($params)) {
                    $params = [$params];
                }

                // ── Execute query with restrictions matching web version ─────────
                // 1. Statement timeout (5000ms, best-effort on MariaDB, silent on MySQL).
                try {
                    $DB->execute('SET STATEMENT max_statement_time = 5 FOR SELECT 1');
                } catch (\Exception $e) {
                    // Non-MariaDB — query runs without server-side timeout.
                }

                // 2. Use recordset (streaming) + PHP row cap instead of LIMIT.
                //    This avoids double-LIMIT conflicts with model-generated SQL.
                try {
                    $recordset = $DB->get_recordset_sql($sql, $params);
                } catch (\Exception $e) {
                    return "❌ SQL error: " . $e->getMessage();
                }

                // ── Date/time column detection ──
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

                $max_rows = 50;
                $rows = [];
                $count = 0;
                foreach ($recordset as $row) {
                    $count++;
                    if ($count > $max_rows) {
                        $recordset->close();
                        $rows[] = '⚠️ More than ' . $max_rows . ' rows returned. Showing first ' . $max_rows . '.';
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
                        if (in_array($key, $datekeys, true) && (is_int($val) || (is_string($val) && ctype_digit($val))) && (int)$val > 0) {
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
                // ── Role gate: admin only (matches lib.php:498) ──
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

                // ── Gate 1: Role-based allowlist (matching ws_execute::execute) ──
                require_once(__DIR__ . '/../../lib.php');
                $allowlist = \local_jedomi_get_role_allowlist($role);
                // Admin sin allowlist configurada → acceso total.
                // El resto de roles (o admin con allowlist explícita) → debe matchear.
                if ($role !== 'admin' || !empty($allowlist)) {
                    if (!in_array($wsname, $allowlist, true)) {
                        return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
                    }
                }

                // ── Gate 2: Resolve target WS class ──────────────────────────────
                $classname = \local_jedomi_resolve_ws_classname($wsname);
                if (!$classname || !class_exists($classname)) {
                    return json_encode(['error' => 'WS class not found: ' . $wsname]);
                }

                $func_record = $DB->get_record('external_functions',
                    ['name' => $wsname], 'classname, classpath, component, methodname');
                if (!$func_record) {
                    return json_encode(['error' => 'WS not found: ' . $wsname]);
                }
                $methodname = $func_record->methodname;
                if (!method_exists($classname, $methodname)) {
                    return json_encode(['error' => 'Method ' . $classname . '::' . $methodname . '() not found']);
                }

                // ── Gate 3: Call the WS method via reflection ────────────────────
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
                $expected_params = [];
                foreach ($ref->getParameters() as $param) {
                    $pname = $param->getName();
                    $expected_params[] = $pname;
                    if (array_key_exists($pname, $decoded)) {
                        $callargs[] = $decoded[$pname];
                    } elseif ($param->isDefaultValueAvailable()) {
                        $callargs[] = $param->getDefaultValue();
                    } else {
                        $got = implode(', ', array_keys($decoded));
                        return json_encode([
                            'error' => 'ws_execute_param_mismatch',
                            'ws' => $wsname,
                            'expected_params' => $expected_params,
                            'provided_params' => array_keys($decoded),
                            'hint' => "WS expects parameter '{$pname}'. Provided: [{$got}]. "
                                . "Use ws_describe('{$wsname}') to see the exact structure."
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
                    $status_icon = $a->enabled ? '🟢' : '⚪';
                    $next = $a->nextrun ? userdate($a->nextrun) : '?';
                    // Always show agent ID so the model can use delete_agent(id).
                    $name = !empty($a->name) ? s($a->name) : "Agent #{$a->id}";
                    // Truncate long prompts to keep the tool result manageable.
                    $excerpt = s(mb_substr($a->prompt, 0, 60));
                    $lines[] = "{$status_icon} <b>[ID {$a->id}] {$name}</b>"
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
     */
    private static function execute_pending_action(array $action, string $chat_id): string {
        global $DB;

        $toolname = $action['name'] ?? '';
        $params = $action['params'] ?? [];

        // Resolve user role from chat link for permission checks.
        $link = self::get_link_by_chat_id($chat_id);
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
     */
    private static function looks_like_link_code(string $text): bool {
        $text = strtoupper(trim($text));
        return (bool) preg_match('/^[A-Z0-9]{4,8}$/', $text);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Release tool_loop lock and send error message to user.
     *
     * @param string $state_key The tool_loop state key.
     * @param string $chat_id   The Telegram chat ID.
     * @param string $message   The error message to send.
     */
    private static function release_lock(string $state_key, string $chat_id, string $message): void {
        session::set_context($state_key, []);
        (new api_client())->send_message($chat_id, $message);
    }

    private static function get_link_by_chat_id(string $chat_id): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_links', ['chatid' => $chat_id, 'confirmed' => 1]);
        return $record ?: null; // get_record returns false when not found, cast to null.
    }

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
        $hidden_ids = array_filter(array_map('intval', explode(',', $hidden)));
        if (empty($hidden_ids)) {
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
            if (!in_array($rid, $hidden_ids, true)) {
                return true;
            }
        }

        // All roles are hidden — block.
        return false;
    }

    private static function check_and_record_rate(string $chat_id): bool {
        global $DB;
        $now = time();
        $window = 60; // 1 minute
        $limit = manager::get_rate_limit();
        $cooldown = manager::get_cooldown() * 60;

        if ($limit <= 0) {
            return false; // Unlimited
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record('local_jedomi_telegram_rate_limit', ['chatid' => $chat_id]);

            if (!$record) {
                $DB->insert_record('local_jedomi_telegram_rate_limit', (object)[
                    'chatid'      => $chat_id,
                    'windowstart' => $now,
                    'msgcount'    => 1,
                    'blockeduntil'=> 0,
                ]);
                $transaction->allow_commit();
                return false;
            }

            // Check if blocked
            if ($record->blockeduntil > $now) {
                $transaction->allow_commit();
                return true;
            }

            // Reset window if expired
            if ($record->windowstart + $window < $now) {
                $record->windowstart = $now;
                $record->msgcount = 1;
                $record->blockeduntil = 0;
                $DB->update_record('local_jedomi_telegram_rate_limit', $record);
                $transaction->allow_commit();
                return false;
            }

            // Check limit
            if ($record->msgcount >= $limit) {
                $record->blockeduntil = $now + $cooldown;
                $DB->update_record('local_jedomi_telegram_rate_limit', $record);
                $transaction->allow_commit();
                return true;
            }

            // Increment
            $record->msgcount = $record->msgcount + 1;
            $DB->update_record('local_jedomi_telegram_rate_limit', $record);
            $transaction->allow_commit();
            return false;

        } catch (\Exception $e) {
            $transaction->rollback($e);
            return true; // Block on error to be safe
        }
    }

    private static function resize_and_base64(string $path, int $max_dim): ?string {
        if (!function_exists('getimagesize')) {
            // Fallback: return raw file if no GD
            $data = file_get_contents($path);
            return $data ? base64_encode($data) : null;
        }

        $info = getimagesize($path);
        if (!$info) {
            return null;
        }

        $src_w = $info[0];
        $src_h = $info[1];

        // Calculate new dimensions
        if ($src_w > $max_dim || $src_h > $max_dim) {
            $ratio = min($max_dim / $src_w, $max_dim / $src_h);
            $dst_w = (int)($src_w * $ratio);
            $dst_h = (int)($src_h * $ratio);
        } else {
            $dst_w = $src_w;
            $dst_h = $src_h;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $src = imagecreatefromjpeg($path);
                break;
            case IMAGETYPE_PNG:
                $src = imagecreatefrompng($path);
                break;
            default:
                // Unsupported type — return raw
                $data = file_get_contents($path);
                return $data ? base64_encode($data) : null;
        }

        if (!$src) {
            return null;
        }

        $dst = imagecreatetruecolor($dst_w, $dst_h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

        ob_start();
        imagejpeg($dst, null, 85);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $data ? base64_encode($data) : null;
    }

    // ── User role & SPT helpers (self-contained, no dependency on lib.php) ──

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
     * @return array          The signed SPT payload, or empty array if user not found/deleted.
     */
    private static function generate_spt_for_user(int $userid, string $role,
        array $course_ids_owned = [], array $course_ids_enrolled = []): array {
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
        if (empty($course_ids_owned) && empty($course_ids_enrolled) && $role !== 'admin') {
            if ($role === 'teacher' || $role === 'manager') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $ctx = \context_course::instance($c->id);
                    if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                        $course_ids_owned[] = (int)$c->id;
                    }
                }
            } elseif ($role === 'student') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $course_ids_enrolled[] = (int)$c->id;
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
            'course_ids_owned'    => $course_ids_owned,
            'course_ids_enrolled' => $course_ids_enrolled,
            'capabilities'        => $capabilities,
            'tools'               => $tools,
            'moodle_url'          => $CFG->wwwroot,
            'expires'             => time() + 1800,
        ];

        // Same HMAC signing as the existing generate_spt function.
        $static_secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';

        ksort($payload);
        $to_sign = json_encode($payload);
        $signature = hash_hmac('sha256', $to_sign, $static_secret);

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

        $is_admin = false;
        $admins = get_admins();
        foreach ($admins as $admin) {
            if ((int) $admin->id === $userid) {
                $is_admin = true;
                break;
            }
        }

        // Enumerate courses via enrolments AND role_assignments.
        // enrol_get_users_courses only returns active enrolments — an admin
        // or teacher may have role assignments at course context without
        // being "enrolled" via an enrolment plugin.
        $courses = enrol_get_users_courses($userid, true, ['id', 'fullname']);
        $seen_ids = [];
        foreach ($courses as $c) {
            $seen_ids[(int)$c->id] = true;
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
            if (!isset($seen_ids[$cid])) {
                $c_record = $DB->get_record('course', ['id' => $cid], 'id, fullname');
                if ($c_record) {
                    $courses[] = $c_record;
                    $seen_ids[$cid] = true;
                }
            }
        }

        $course_ids_owned  = [];
        $course_ids_enrolled = [];
        $courses_by_role = ['manager' => [], 'teacher' => [], 'student' => []];
        $primary_role = $is_admin ? 'admin' : 'student';

        foreach ($courses as $c) {
            $ctx = \context_course::instance($c->id);

            if ($is_admin) {
                // Admin: use ACTUAL role assignments, not capabilities.
                // has_capability() always returns true for admins — wrong.
                $assigned = get_user_roles($ctx, $userid, false);
                $shortnames = array_map(fn(\stdClass $r): string => $r->shortname, $assigned);
                if (in_array('manager', $shortnames, true)) {
                    $courses_by_role['manager'][] = $c->fullname;
                    $course_ids_owned[] = (int)$c->id;
                } elseif (in_array('editingteacher', $shortnames, true)) {
                    $courses_by_role['teacher'][] = $c->fullname;
                    $course_ids_owned[] = (int)$c->id;
                } elseif (in_array('student', $shortnames, true)) {
                    $courses_by_role['student'][] = $c->fullname;
                    $course_ids_enrolled[] = (int)$c->id;
                }
                // No explicit role → skip (admin has access but no role assignment).
            } else {
                if (has_capability('moodle/course:update', $ctx, $userid)) {
                    $courses_by_role['manager'][] = $c->fullname;
                    $course_ids_owned[] = (int)$c->id;
                    $primary_role = 'manager';
                } elseif (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                    $courses_by_role['teacher'][] = $c->fullname;
                    $course_ids_owned[] = (int)$c->id;
                    if ($primary_role !== 'manager') { $primary_role = 'teacher'; }
                } else {
                    $courses_by_role['student'][] = $c->fullname;
                    $course_ids_enrolled[] = (int)$c->id;
                }
            }
        }

        if (!$is_admin && $primary_role === 'student' && has_capability('moodle/user:update', $sysctx, $userid)) {
            $primary_role = 'manager';
        }

        return [
            'role'              => $primary_role,
            'course_ids_owned'  => $course_ids_owned,
            'course_ids_enrolled' => $course_ids_enrolled,
            'courses_by_role'   => $courses_by_role,
        ];
    }
}
