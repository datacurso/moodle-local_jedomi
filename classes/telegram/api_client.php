<?php
/**
 * Telegram Bot API client — thin wrapper around Telegram HTTP API.
 *
 * Handles: sendMessage, editMessageText, answerCallbackQuery, file downloads.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

class api_client {

    private string $token;
    private string $base_url;
    private int $last_http_code = 0;

    public function __construct(?string $token = null) {
        $this->token = $token ?? manager::get_bot_token() ?? '';
        $this->base_url = 'https://api.telegram.org/bot' . $this->token;
    }

    /**
     * Split long text into chunks ≤ 4000 chars for Telegram's limit.
     * Tries to split on \n\n boundaries first, falls back to last space before 4000.
     *
     * @param string $text The text to split.
     * @param bool $truncate If true, return single chunk truncated to 4000 + "… (truncated)".
     * @return array Array of text chunks.
     */
    /**
     * Close any open HTML tags in a text fragment so Telegram's parser
     * doesn't reject it. Works by tracking which tags from an allowlist
     * are open and appending closing tags at the end.
     *
     * Non-element tags (self-closing, comments, etc.) are ignored.
     *
     * @param string $text
     * @return string
     */
    private function close_open_html_tags(string $text): string {
        // Only close tags that Telegram supports with parse_mode=HTML.
        $allowed = ['b', 'i', 'u', 's', 'a', 'code', 'pre', 'em', 'strong'];
        $open = [];

        // Remove all attributes inside tags for matching purposes.
        $stripped = preg_replace('/<(\w+)\s[^>]*>/', '<$1>', $text);

        // Find all <tag> and </tag> occurrences.
        preg_match_all('/<\/?(\w+)[^>]*>/', $stripped, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $tag = strtolower($m[1]);
            if (!in_array($tag, $allowed, true)) {
                continue;
            }
            if ($m[0][1] === '/') { // Closing tag </tag>
                $idx = array_search($tag, $open, true);
                if ($idx !== false) {
                    array_splice($open, $idx, 1);
                }
            } else if (substr($m[0], -2) !== '/>') { // Opening tag (not self-closing)
                $open[] = $tag;
            }
        }

        // Append closing tags in reverse order.
        foreach (array_reverse($open) as $tag) {
            $text .= '</' . $tag . '>';
        }

        return $text;
    }

    /**
     * Split long text into chunks ≤ 4000 chars for Telegram's limit.
     * Tries to split on \n\n boundaries first, falls back to last space before 4000.
     * Each chunk (except the last) has open HTML tags closed to prevent 400 errors.
     *
     * @param string $text The text to split.
     * @param bool $truncate If true, return single chunk truncated to 4000 + "… (truncated)".
     * @return array Array of text chunks.
     */
    private function chunk_text(string $text, bool $truncate = false): array {
        $max = 4000;
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        if ($truncate) {
            return [mb_substr($text, 0, $max - 15) . '… (truncated)'];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $max) {
            $chunk = mb_substr($remaining, 0, $max);

            // Try to split at \n\n
            $last_double_nl = mb_strrpos($chunk, "\n\n");
            if ($last_double_nl !== false && $last_double_nl > $max * 0.5) {
                $split_at = $last_double_nl + 2;
            } else {
                // Fallback: last space
                $last_space = mb_strrpos($chunk, ' ');
                $split_at = ($last_space !== false && $last_space > 0) ? $last_space + 1 : $max;
            }

            $fragment = mb_substr($remaining, 0, $split_at);
            // Close any HTML tags that were opened but not closed in this fragment.
            $chunks[] = $this->close_open_html_tags($fragment);
            $remaining = trim(mb_substr($remaining, $split_at));
        }

        if (mb_strlen($remaining) > 0) {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /**
     * Returns the HTTP status code from the last API call.
     *
     * @return int
     */
    public function get_last_http_code(): int {
        return $this->last_http_code;
    }

    /**
     * Send a text message to a chat.
     * Splits long messages into multiple chunks if they exceed Telegram's 4000-char limit.
     *
     * @param int|string $chat_id
     * @param string     $text
     * @param array|null $reply_markup  Inline keyboard or other markup
     * @param bool       $truncate      If true, truncate to 4000 chars instead of chunking
     * @return bool
     */
    public function send_message(int|string $chat_id, string $text, ?array $reply_markup = null, bool $truncate = false): bool {
        $chunks = $this->chunk_text($text, $truncate);
        $all_success = true;

        foreach ($chunks as $chunk) {
            $params = [
                'chat_id' => $chat_id,
                'text' => $chunk,
                'parse_mode' => 'HTML',
            ];
            if ($reply_markup !== null) {
                $params['reply_markup'] = json_encode($reply_markup);
            }

            $response = $this->post('sendMessage', $params);

            // 400 with HTML parse_mode → retry without parse_mode (plain text).
            if ($response === null && $this->last_http_code === 400) {
                unset($params['parse_mode']);
                $response = $this->post('sendMessage', $params);
            }

            if ($response === null || !($response['ok'] ?? false)) {
                $all_success = false;
                \local_jedomi_log('E002: Telegram API error: method=sendMessage http=' . $this->last_http_code);
            }
        }

        return $all_success;
    }

    /**
     * Edit a message text (used for confirmation results).
     *
     * @param int|string $chat_id
     * @param int        $message_id
     * @param string     $text
     * @param array|null $reply_markup
     * @return bool
     */
    public function edit_message_text(int|string $chat_id, int $message_id, string $text, ?array $reply_markup = null): bool {
        $params = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($reply_markup !== null) {
            $params['reply_markup'] = json_encode($reply_markup);
        }
        $result = $this->post('editMessageText', $params);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Answer a callback query (removes the "loading" state from inline buttons).
     *
     * @param string $callback_id
     * @param string $text  Optional short text shown to the user
     * @return bool
     */
    public function answer_callback_query(string $callback_id, string $text = ''): bool {
        $params = [
            'callback_query_id' => $callback_id,
        ];
        if ($text !== '') {
            $params['text'] = $text;
        }
        $result = $this->post('answerCallbackQuery', $params);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Download a file from Telegram servers to a local temp path.
     *
     * @param string $file_id  Telegram file_id
     * @return string|null  Local temp file path, or null on failure
     */
    public function download_file(string $file_id): ?string {
        // Step 1: Get file path
        $file_info = $this->post('getFile', ['file_id' => $file_id]);
        if (empty($file_info['result']['file_path'])) {
            return null;
        }
        $file_path = $file_info['result']['file_path'];

        // Step 2: Download from Telegram CDN
        $download_url = 'https://api.telegram.org/file/bot' . $this->token . '/' . $file_path;
        $tmp_path = make_temp_directory('local_jedomi') . '/' . basename($file_path);

        $ch = curl_init($download_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $data === false) {
            return null;
        }

        file_put_contents($tmp_path, $data);
        return $tmp_path;
    }

    /**
     * Call getMe — returns bot info (id, username, first_name) or null on failure.
     *
     * @return array|null  ['id', 'username', 'first_name'] or null
     */
    public function get_me(): ?array {
        $result = $this->post('getMe', []);
        if ($result && ($result['ok'] ?? false)) {
            $bot = $result['result'] ?? [];
            return [
                'id'         => $bot['id']         ?? 0,
                'username'   => $bot['username']   ?? '',
                'first_name' => $bot['first_name'] ?? '',
            ];
        }
        return null;
    }

    /**
     * Call getWebhookInfo — returns current webhook status.
     *
     * @return array|null  ['url', 'has_custom_certificate', 'pending_update_count', 'last_error_message']
     */
    public function get_webhook_info(): ?array {
        $result = $this->post('getWebhookInfo', []);
        if ($result && ($result['ok'] ?? false)) {
            return $result['result'] ?? [];
        }
        return null;
    }

    /**
     * Set the webhook URL for this bot.
     *
     * @param string $url  Full HTTPS URL including the secret query param
     * @return bool
     */
    public function set_webhook(string $url): bool {
        $result = $this->post('setWebhook', ['url' => $url]);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Delete the currently registered webhook (drops all pending updates).
     *
     * @return bool
     */
    public function delete_webhook(): bool {
        $result = $this->post('deleteWebhook', ['drop_pending_updates' => true]);
        return $result !== null && ($result['ok'] ?? false);
    }

    /**
     * Send a POST request to the Telegram Bot API.
     *
     * @param string $method  API method name, e.g. "sendMessage"
     * @param array  $params
     * @return array|null  Decoded JSON response, or null on failure
     */
    private function post(string $method, array $params): ?array {
        if (empty($this->token)) {
            \local_jedomi_log('E001: no token configured');
            return null;
        }

        $url = $this->base_url . '/' . $method;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);
        $this->last_http_code = $http_code;

        if ($http_code !== 200 || $response === false) {
            $snippet = $response !== false ? mb_substr($response, 0, 500) : 'no_response';
            error_log('[TELEGRAM API ERR] HTTP ' . $http_code . ' method=' . $method . ' body=' . $snippet . ' curl_err=' . ($curl_err ?: 'none'));
            \local_jedomi_log('E002: Telegram API HTTP error: method=' . $method . ' http=' . $http_code . ' body=' . $snippet . ' curl=' . ($curl_err ?: 'none'));
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[TELEGRAM API ERR] JSON decode: ' . json_last_error_msg() . ' method=' . $method);
            \local_jedomi_log('E003: Telegram JSON error: ' . json_last_error_msg());
            return null;
        }

        if (!($decoded['ok'] ?? false)) {
            $desc = $decoded['description'] ?? 'unknown';
            \local_jedomi_log('Telegram API error: ' . $desc);
            error_log('[TELEGRAM API ERR] method=' . $method . ' desc=' . $desc);
        }

        return $decoded;
    }

    /**
     * Send a chat action (typing indicator) to a chat.
     *
     * Telegram shows "Jedomi está escribiendo..." for ~6 seconds.
     * Call at start of processing and re-send during long loops.
     *
     * @param string $chat_id Telegram chat ID.
     * @param string $action  'typing', 'upload_photo', 'record_video', etc.
     * @return bool True if Telegram accepted the action.
     */
    public function send_chat_action(string $chat_id, string $action = 'typing'): bool {
        $result = $this->post('sendChatAction', [
            'chat_id' => $chat_id,
            'action'  => $action,
        ]);
        return $result !== null && !empty($result['ok']);
    }

    /**
     * Send a document file to a chat (supports TXT, CSV, PDF, etc.).
     *
     * If the file path does not exist, falls back to send_message with a truncated
     * version of the content so the user never loses the data.
     *
     * @param  int|string $chat_id   Telegram chat ID.
     * @param  string     $filepath  Absolute path to the file on disk.
     * @param  string     $caption   Optional caption (0-1024 chars, HTML supported).
     * @return bool                  True if Telegram accepted the file.
     */
    public function send_document(int|string $chat_id, string $filepath, string $caption = ''): bool {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            \local_jedomi_log('E004: send_document file not found: ' . $filepath);
            return false;
        }

        $params = [
            'chat_id'   => $chat_id,
            'document'  => new \CURLFile($filepath),
        ];
        if (!empty($caption)) {
            $params['caption'] = mb_substr($caption, 0, 1024);
            $params['parse_mode'] = 'HTML';
        }

        $response = $this->post_multipart('sendDocument', $params);

        // 400 with HTML parse_mode → retry caption as plain text.
        if ($response === null && $this->last_http_code === 400 && !empty($caption)) {
            unset($params['parse_mode']);
            $response = $this->post_multipart('sendDocument', $params);
        }

        return $response !== null && !empty($response['ok']);
    }

    /**
     * Send a POST request with multipart/form-data (for file uploads).
     *
     * @param  string     $method  API method name, e.g. "sendDocument".
     * @param  array      $params  Params (CURLFile objects for file fields).
     * @return array|null          Decoded JSON response, or null on failure.
     */
    private function post_multipart(string $method, array $params): ?array {
        if (empty($this->token)) {
            \local_jedomi_log('E001: no token configured');
            return null;
        }

        $url = $this->base_url . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,  // Array → libcurl uses multipart/form-data.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);
        $this->last_http_code = $http_code;

        if ($http_code !== 200 || $response === false) {
            $snippet = $response !== false ? mb_substr($response, 0, 500) : 'no_response';
            error_log('[TELEGRAM API ERR] HTTP ' . $http_code . ' method=' . $method . ' body=' . $snippet . ' curl_err=' . ($curl_err ?: 'none'));
            \local_jedomi_log('E002: Telegram API HTTP error: method=' . $method . ' http=' . $http_code . ' body=' . $snippet . ' curl=' . ($curl_err ?: 'none'));
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[TELEGRAM API ERR] JSON decode: ' . json_last_error_msg() . ' method=' . $method);
            \local_jedomi_log('E003: Telegram JSON error: ' . json_last_error_msg());
            return null;
        }

        if (!($decoded['ok'] ?? false)) {
            $desc = $decoded['description'] ?? 'unknown';
            \local_jedomi_log('Telegram API error: ' . $desc);
            error_log('[TELEGRAM API ERR] method=' . $method . ' desc=' . $desc);
        }

        return $decoded;
    }
}
