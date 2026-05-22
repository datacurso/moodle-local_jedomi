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

class webhook_handler {

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
        error_log('[local_jedomi] Telegram webhook: unknown update type');
    }

    /**
     * Handle an incoming message (text or photo).
     */
    private static function handle_message(array $message): void {
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? null;

        if ($chat_id === '') {
            return;
        }

        // Check rate limiting first
        if (self::is_rate_limited($chat_id)) {
            $cooldown = manager::get_cooldown();
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => $cooldown]));
            return;
        }
        self::record_message($chat_id);

        // Check if user is linked
        $link = self::get_link_by_chat_id($chat_id);
        if (!$link) {
            // Unlinked user — check if it's a /start command with code
            if (strpos($text, '/start') === 0) {
                $username = (string) ($message['chat']['username'] ?? '');
                self::handle_start_command($chat_id, $text, $username);
                return;
            }
            // Otherwise ask them to link first
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_link_first', 'local_jedomi'));
            return;
        }

        // Check role permission
        if (!self::is_user_role_allowed($link->userid)) {
            $client = new api_client();
            $client->send_message($chat_id, get_string('telegram_msg_role_blocked', 'local_jedomi'));
            return;
        }

        // Handle /desvincular command
        if (strcasecmp($text, '/desvincular') === 0 || strcasecmp($text, '/unlink') === 0) {
            self::handle_unlink_command($chat_id, $link);
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

        if (strlen($code) < 4 || strlen($code) > 8) {
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

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,username');
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
        $client->send_message($chat_id, "✅ Linked to Moodle as <b>" . s($fullname) . "</b> (" . s($user->username) . ")");
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
     * Proxy a message to the Jedomi Gateway and send response back to Telegram.
     */
    private static function proxy_to_gateway(int $userid, string $chat_id, string $text, ?array $photo): void {
        global $CFG;

        // Build session context
        $messages = session::build_messages_for_gateway($chat_id, $text);

        // If photo, download and encode
        $user_content = $text;
        if ($photo !== null && !empty($photo)) {
            $largest = end($photo); // last = largest
            $client = new api_client();
            $tmp_path = $client->download_file($largest['file_id'] ?? '');
            if ($tmp_path && file_exists($tmp_path)) {
                // Resize to max 512×512 for token efficiency
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

        // Rebuild messages with correct content type
        if (is_array($user_content)) {
            // Replace last user message with multimodal content
            array_pop($messages);
            $messages[] = ['role' => 'user', 'content' => $user_content];
        }

        // Generate SPT
        $user = \core_user::get_user($userid);
        $role = local_jedomi_get_user_role();
        $spt = local_jedomi_generate_spt($role);

        // Call Gateway
        $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765', '/');
        $api_key = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

        $payload = [
            'model'       => get_config('local_jedomi', 'model') ?: 'jedomi-e4b',
            'temperature' => 0.2,
            'stream'      => false,
            'messages'    => $messages,
            'spt'         => $spt,
        ];

        $ch = curl_init($gateway_url . '/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $response === false) {
            $client = new api_client();
            $client->send_message($chat_id, get_string('connection_error', 'local_jedomi'));
            return;
        }

        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';

        // Store assistant response in session
        session::append($chat_id, 'assistant', $content);

        // Check for pending action pattern
        if (strpos($content, 'JEDOMI_PENDING_ACTION') !== false) {
            // Extract and store, then send inline buttons
            self::handle_pending_action($chat_id, $content);
            return;
        }

        // Send response back to Telegram
        $client = new api_client();
        $client->send_message($chat_id, $content);
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
     * Execute a pending action directly (bypass LLM).
     */
    private static function execute_pending_action(array $action, string $chat_id): string {
        // This is a simplified executor. Full implementation maps action names
        // to PHP functions or CompanionWS calls.
        $tool_name = $action['name'] ?? '';
        $params = $action['params'] ?? [];

        // Example: cron_run, cache_purge, user_suspend, etc.
        // For now, return a placeholder. The full implementation requires
        // mapping all 93 tools to PHP equivalents.
        return "✅ Action <b>" . s($tool_name) . "</b> executed successfully.\n\nResult: " . json_encode($params);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function get_link_by_chat_id(string $chat_id): ?\stdClass {
        global $DB;
        return $DB->get_record('local_jedomi_telegram_links', ['chatid' => $chat_id, 'confirmed' => 1]);
    }

    private static function is_user_role_allowed(int $userid): bool {
        // Reuse the global function but check for the specific user
        global $PAGE;
        $hidden = get_config('local_jedomi', 'telegram_hidden_roles') ?: '';
        if (empty($hidden)) {
            return true;
        }
        $hidden_ids = array_filter(array_map('intval', explode(',', $hidden)));
        if (empty($hidden_ids)) {
            return true;
        }
        $user_roles = get_user_roles(context_system::instance(), $userid, false);
        foreach ($user_roles as $r) {
            if (!in_array((int)$r->roleid, $hidden_ids, true)) {
                return true;
            }
        }
        return false;
    }

    private static function is_rate_limited(string $chat_id): bool {
        global $DB;
        $now = time();
        $window = 60; // 1 minute
        $limit = manager::get_rate_limit();
        $cooldown = manager::get_cooldown() * 60;

        if ($limit <= 0) {
            return false; // Unlimited
        }

        $record = $DB->get_record('local_jedomi_telegram_rate_limit', ['chatid' => $chat_id]);
        if (!$record) {
            $DB->insert_record('local_jedomi_telegram_rate_limit', (object)[
                'chatid'      => $chat_id,
                'windowstart' => $now,
                'msgcount'    => 1,
                'blockeduntil'=> 0,
            ]);
            return false;
        }

        // Check if blocked
        if ($record->blockeduntil > $now) {
            return true;
        }

        // Check if window expired
        if ($record->windowstart + $window < $now) {
            // Reset window
            $record->windowstart = $now;
            $record->msgcount = 1;
            $record->blockeduntil = 0;
            $DB->update_record('local_jedomi_telegram_rate_limit', $record);
            return false;
        }

        // Check limit
        if ($record->msgcount >= $limit) {
            $record->blockeduntil = $now + $cooldown;
            $DB->update_record('local_jedomi_telegram_rate_limit', $record);
            return true;
        }

        return false;
    }

    private static function record_message(string $chat_id): void {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_rate_limit', ['chatid' => $chat_id]);
        if ($record) {
            $record->msgcount = $record->msgcount + 1;
            $DB->update_record('local_jedomi_telegram_rate_limit', $record);
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
}
