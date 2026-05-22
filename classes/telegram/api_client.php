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

    public function __construct(?string $token = null) {
        $this->token = $token ?? manager::get_bot_token() ?? '';
        $this->base_url = 'https://api.telegram.org/bot' . $this->token;
    }

    /**
     * Send a text message to a chat.
     *
     * @param int|string $chat_id
     * @param string     $text
     * @param array|null $reply_markup  Inline keyboard or other markup
     * @return bool
     */
    public function send_message(int|string $chat_id, string $text, ?array $reply_markup = null): bool {
        $params = [
            'chat_id' => $chat_id,
            'text'    => $text,
            'parse_mode' => 'HTML',
        ];
        if ($reply_markup !== null) {
            $params['reply_markup'] = json_encode($reply_markup);
        }
        $result = $this->post('sendMessage', $params);
        return $result !== null && ($result['ok'] ?? false);
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
     * Send a POST request to the Telegram Bot API.
     *
     * @param string $method  API method name, e.g. "sendMessage"
     * @param array  $params
     * @return array|null  Decoded JSON response, or null on failure
     */
    private function post(string $method, array $params): ?array {
        if (empty($this->token)) {
            error_log('[local_jedomi] Telegram API client: no token configured');
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
        curl_close($ch);

        if ($http_code !== 200 || $response === false) {
            error_log('[local_jedomi] Telegram API error: method=' . $method . ' http=' . $http_code);
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[local_jedomi] Telegram API JSON decode error: ' . json_last_error_msg());
            return null;
        }

        if (!($decoded['ok'] ?? false)) {
            error_log('[local_jedomi] Telegram API error: ' . ($decoded['description'] ?? 'unknown'));
        }

        return $decoded;
    }
}
