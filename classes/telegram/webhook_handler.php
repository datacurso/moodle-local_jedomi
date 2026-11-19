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
 * Routes: message (text, photo, document, voice or audio), callback_query (inline buttons), /start command.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\ai\model_settings;
use local_jedomi\local\anonymizer;
use local_jedomi\local\service\audio_service;
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\local\service\telegram_link_service;
use local_jedomi\local\service\vision_service;
use local_jedomi\schema\table_indexer;
use local_jedomi\schema\ws_indexer;

/**
 * Main Telegram webhook orchestrator: routes messages, photos, commands and callback queries.
 */
class webhook_handler {
    /** Lock TTL in seconds — stale locks older than this are auto-released. */
    private const LOCK_TTL = 120;

    /** Seconds a worker runs the tool loop before it hands the rest to the next worker. */
    private const HARD_GUARD = 55;

    /** Longest AI call that fits in one worker (the hard guard less a margin), the top of the timeout setting. */
    public const MAX_AI_TIMEOUT = self::HARD_GUARD - 5;

    /** Tools the Telegram channel can run; any other tool is never offered to the AI here. */
    private const SUPPORTED_TOOLS = [
        'describe_table', 'search_tables', 'execute_sql', 'get_site_config', 'site_overview', 'ws_search', 'ws_describe',
        'ws_execute', 'admin_search', 'admin_describe', 'admin_execute', 'create_agent', 'list_agents',
        'delete_agent', 'my_courses', 'my_grades', 'my_assignments',
    ];

    /** Prefix of the callback data of the confirm button of a held action. */
    private const CALLBACK_CONFIRM = 'confirm:';

    /** Prefix of the callback data of the cancel button of a held action. */
    private const CALLBACK_CANCEL = 'cancel:';

    /**
     * Pseudonym of a chat for the technical log, which never keeps the Telegram chat id.
     *
     * @param string $chatid Telegram chat id.
     * @return string
     */
    private static function chatref(string $chatid): string {
        return \local_jedomi_log_pseudonym('chat', $chatid);
    }

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
     * Body and headers of the early 200 OK sent before the update is processed.
     *
     * Content-Length must match the body: with a larger value the proxy keeps waiting for the
     * missing bytes, Telegram gets an error and sends the same update again.
     *
     * @return array{body: string, headers: string[]}
     */
    public static function acknowledgement(): array {
        $body = json_encode(['ok' => true]);
        return [
            'body' => $body,
            'headers' => [
                'Content-Type: application/json',
                'Connection: close',
                'Content-Length: ' . strlen($body),
            ],
        ];
    }

    /**
     * Send the early 200 OK and close the connection so processing can continue after it.
     */
    public static function acknowledge(): void {
        $ack = self::acknowledgement();
        http_response_code(200);
        foreach ($ack['headers'] as $header) {
            header($header);
        }
        echo $ack['body'];
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Main entry point — dispatch based on Telegram update type.
     *
     * @param array $update Decoded JSON from Telegram webhook
     */
    public static function handle(array $update): void {
        // Every answer goes out in the language of the linked user; the site language when the chat is not linked yet.
        \local_jedomi\local\user_language::run(self::update_user_id($update), static function () use ($update): void {
            self::dispatch($update);
        });
    }

    /**
     * Moodle user linked to the chat of an update, or null when the chat is not linked.
     *
     * @param array $update Decoded JSON from Telegram webhook
     * @return int|null
     */
    private static function update_user_id(array $update): ?int {
        $rawchatid = $update['callback_query']['message']['chat']['id'] ?? ($update['message']['chat']['id'] ?? '');
        $chatid = self::normalise_chat_id((string) $rawchatid);
        if ($chatid === null) {
            return null;
        }
        $link = self::get_link_by_chat_id($chatid);
        return $link ? (int) $link->userid : null;
    }

    /**
     * Dispatch an update based on its type.
     *
     * @param array $update Decoded JSON from Telegram webhook
     */
    private static function dispatch(array $update): void {
        $disabled = \local_jedomi_plugin_disabled_error();
        if ($disabled !== null) {
            self::reply_plugin_disabled($update, $disabled['message']);
            return;
        }

        if (!manager::is_enabled()) {
            // The administrator turned the integration off: the bot processes nothing, a linked chat is told so.
            \local_jedomi_log('Telegram webhook: integration disabled, update not processed');
            self::reply_integration_disabled($update);
            return;
        }

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
     * Answer an update with the "not enabled" message while Jedomi is disabled in the Datacurso AI provider.
     *
     * Nothing else runs: no AI call, no link code, no inline action.
     *
     * @param array $update Decoded JSON from Telegram webhook
     * @param string $message The provider's "not enabled" message
     */
    private static function reply_plugin_disabled(array $update, string $message): void {
        $client = api_client::instance();
        if (isset($update['callback_query'])) {
            $callbackid = (string) ($update['callback_query']['id'] ?? '');
            if ($callbackid !== '') {
                $client->answer_callback_query($callbackid);
            }
            $rawchatid = $update['callback_query']['message']['chat']['id'] ?? '';
        } else {
            $rawchatid = $update['message']['chat']['id'] ?? '';
        }
        $chatid = self::normalise_chat_id((string) $rawchatid);
        if ($chatid !== null) {
            $client->send_message($chatid, $message);
        }
    }

    /**
     * Tell a linked chat that the bot is not available while the Telegram integration is disabled.
     *
     * Nothing else runs; a chat that is not linked gets no answer.
     *
     * @param array $update Decoded JSON from Telegram webhook
     */
    private static function reply_integration_disabled(array $update): void {
        $client = api_client::instance();
        if (isset($update['callback_query'])) {
            $callbackid = (string) ($update['callback_query']['id'] ?? '');
            if ($callbackid !== '') {
                $client->answer_callback_query($callbackid);
            }
            $rawchatid = $update['callback_query']['message']['chat']['id'] ?? '';
        } else {
            $rawchatid = $update['message']['chat']['id'] ?? '';
        }
        $chatid = self::normalise_chat_id((string) $rawchatid);
        if ($chatid !== null && self::get_link_by_chat_id($chatid) !== null) {
            $client->send_message($chatid, get_string('telegram_msg_unavailable', 'local_jedomi'));
        }
    }

    /**
     * Handle an incoming message (text, photo, document, voice or audio).
     *
     * @param array $message Decoded message object from the Telegram update
     */
    private static function handle_message(array $message): void {
        $chatid = self::normalise_chat_id((string) ($message['chat']['id'] ?? ''));
        $text = trim($message['text'] ?? '');

        if ($chatid === null) {
            // Attacker-controllable value: never log it raw, never let it reach the DB.
            \local_jedomi_log('[TELEGRAM ENTRY] rejected non-numeric chat id (len='
                . mb_strlen((string) ($message['chat']['id'] ?? '')) . ')');
            return;
        }

        \local_jedomi_log('[TELEGRAM ENTRY] chat=' . self::chatref($chatid) . ' textlen=' . mb_strlen($text));

        // One message per chat at a time: Telegram retries a slow update, and two workers on the same chat would run
        // the same tool loop twice. The Moodle lock API works on every supported database (the advisory lock used
        // before worked only on MySQL); the lock is released when the message is done, also on an exception.
        $lock = self::acquire_chat_lock($chatid);
        if ($lock === false) {
            \local_jedomi_log('DEDUP: another process is handling chat ' . self::chatref($chatid) . ', skipping');
            // Another process already holds the lock for this chat: the message is not lost without notice.
            api_client::instance()->send_message($chatid, get_string('telegram_msg_still_processing', 'local_jedomi'));
            return;
        }
        try {
            self::process_message($message, $chatid, $text);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Take the per-chat lock that keeps two workers from handling messages of the same chat at once.
     *
     * @param string $chatid Normalised Telegram chat id.
     * @return \core\lock\lock|false|null The lock, false when another process holds it, null when no lock
     *     factory is available (the message is then handled without the lock).
     */
    public static function acquire_chat_lock(string $chatid) {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_jedomi_telegram_chat');
            return $factory->get_lock('chat_' . $chatid, 0) ?: false;
        } catch (\Throwable $e) {
            \local_jedomi_log('DEDUP: lock not available (' . get_class($e) . '), proceeding without dedup');
            return null;
        }
    }

    /**
     * Handle a message once the per-chat lock is held.
     *
     * @param array $message Decoded message object from the Telegram update.
     * @param string $chatid Normalised Telegram chat id.
     * @param string $text Trimmed text of the message.
     */
    private static function process_message(array $message, string $chatid, string $text): void {
        global $DB;

        // Handle /help command (always responds, even if rate-limited or unlinked).
        // Aliases: /ayuda (Spanish).
        if (in_array(strtolower(trim($text)), ['/help', '/ayuda'], true)) {
            $client = api_client::instance();
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
        $limited = self::check_and_record_rate($chatid);
        if ($limited === null) {
            // The limit could not be checked: the message is not processed, and the user knows it must be sent again.
            api_client::instance()->send_message($chatid, get_string('telegram_msg_rate_check_failed', 'local_jedomi'));
            return;
        }
        if ($limited) {
            $cooldown = manager::get_cooldown();
            $client = api_client::instance();
            $client->send_message($chatid, get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => $cooldown]));
            return;
        }

        if (!self::is_private_chat($chatid, (string) ($message['chat']['type'] ?? ''))) {
            // In a group every member would act with the account that linked it: groups are not served.
            api_client::instance()->send_message($chatid, get_string('telegram_msg_private_only', 'local_jedomi'));
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
            $client = api_client::instance();
            $client->send_message(
                $chatid,
                get_string('telegram_msg_link_first', 'local_jedomi'),
                self::get_unlinked_keyboard()
            );
            return;
        }

        // A linked chat can move to another account with a new link code.
        if (preg_match('/^\/start\s+\S+/', $text)) {
            self::handle_start_command($chatid, $text, (string) ($message['chat']['username'] ?? ''));
            return;
        }

        // Handle /desvincular command: any linked user can unlink, whatever the state of the account.
        if (strcasecmp($text, '/desvincular') === 0 || strcasecmp($text, '/unlink') === 0) {
            self::handle_unlink_command($chatid, $link);
            return;
        }

        // The account must still be active and able to log in; it is checked on every message.
        $user = self::get_active_user((int) $link->userid);
        if ($user === null) {
            api_client::instance()->send_message($chatid, get_string('telegram_msg_account_inactive', 'local_jedomi'));
            return;
        }

        // Check role permission.
        if (!self::is_user_role_allowed($link->userid)) {
            $client = api_client::instance();
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
        $USER = $user;

        // The confirmation word confirms the newest critical action of the chat that waits for it.
        if (pending_action_service::is_confirm_keyword($text)) {
            $critical = self::waiting_critical_action($chatid);
            if ($critical !== null) {
                api_client::instance()->send_message($chatid, self::resolve_held_action($chatid, $critical, true, true));
                return;
            }
        }

        // Handle /new command to start fresh conversation.
        if (strcasecmp($text, '/new') === 0 || strcasecmp($text, '/nuevo') === 0 || strcasecmp($text, '/clear') === 0) {
            \local_jedomi_log('[TELEGRAM ENTRY] /new detected, calling proxy_to_gateway user='
                . \local_jedomi_log_pseudonym('user', $link->userid));
            self::reset_conversation($chatid);
            // Get user's name so the model greets them personally.
            $firstname = (string) $DB->get_field('user', 'firstname', ['id' => $link->userid]);
            self::proxy_to_gateway(
                $link->userid,
                $chatid,
                get_string('telegram_new_conversation_greeting', 'local_jedomi', $firstname)
            );
            return;
        }

        // Photos, documents and audio become text first; nothing without text reaches the AI.
        $text = self::message_text($message, $chatid, (int) $link->userid, $text);
        if ($text === null) {
            return;
        }

        // Build and forward to Gateway.
        self::proxy_to_gateway($link->userid, $chatid, $text);
    }

    /**
     * Text of a message for the chat model: its own text, the transcription of a voice or audio
     * message, a photo or PDF read by the vision model, or the text of a Word or PowerPoint document
     * (read for free), with its caption as the question.
     *
     * Answers the user and returns null when the message must not reach the AI: the feature is off,
     * the file cannot be read, or the message has no text (sticker, location...).
     *
     * @param array $message Telegram message.
     * @param string $chatid Telegram chat id.
     * @param int $userid Linked Moodle user.
     * @param string $text Text of the message.
     * @return string|null
     */
    private static function message_text(array $message, string $chatid, int $userid, string $text): ?string {
        $client = api_client::instance();
        $caption = trim((string) ($message['caption'] ?? ''));
        $audio = $message['voice'] ?? $message['audio'] ?? null;
        $file = !empty($message['photo']) && is_array($message['photo']) ? end($message['photo']) : ($message['document'] ?? null);

        if (is_array($audio)) {
            if (!model_settings::is_audio_enabled()) {
                $client->send_message($chatid, get_string('telegram_msg_audio_disabled', 'local_jedomi'));
                return null;
            }
            $client->send_chat_action($chatid, 'typing');
            $filename = isset($message['voice']) ? 'voice.ogg' : self::audio_filename($audio);
            try {
                $transcription = self::with_download(
                    $client,
                    (string) ($audio['file_id'] ?? ''),
                    static fn(string $path): array => audio_service::transcribe_upload($userid, $path, $filename)
                );
            } catch (\Throwable $e) {
                $client->send_message($chatid, audio_service::error_for($e)['message']);
                return null;
            }
            // The user sees what was understood before the answer.
            $client->send_message($chatid, get_string('telegram_msg_heard', 'local_jedomi', $transcription['text']));
            return $transcription['text'];
        }

        if (is_array($file)) {
            if (!model_settings::is_files_enabled()) {
                $client->send_message($chatid, get_string('telegram_msg_files_disabled', 'local_jedomi'));
                return null;
            }
            $filename = isset($message['document'])
                ? clean_param((string) ($file['file_name'] ?? 'document'), PARAM_FILE)
                : 'photo.jpg';
            if (isset($message['document']) && file_extraction_service::is_office_document($filename)) {
                // Word and PowerPoint: their text is read without a model, so it is not billed.
                $client->send_chat_action($chatid, 'typing');
                try {
                    $extracted = self::with_download(
                        $client,
                        (string) ($file['file_id'] ?? ''),
                        static fn(string $path): array => file_extraction_service::extract_upload($userid, $path, $filename)
                    );
                } catch (\Throwable $e) {
                    $client->send_message($chatid, file_extraction_service::error_for($e)['message']);
                    return null;
                }
                return file_extraction_service::compose_message($caption, $filename, $extracted['text']);
            }
            if (!vision_service::is_supported($filename)) {
                $client->send_message($chatid, get_string('telegram_msg_file_unsupported', 'local_jedomi'));
                return null;
            }
            $client->send_chat_action($chatid, 'typing');
            try {
                $read = self::with_download(
                    $client,
                    (string) ($file['file_id'] ?? ''),
                    static fn(string $path): array => vision_service::read_upload($userid, $path, $filename, $caption)
                );
            } catch (\Throwable $e) {
                $client->send_message($chatid, vision_service::error_for($e)['message']);
                return null;
            }
            return vision_service::compose_message($caption, $filename, $read['text']);
        }

        if ($text === '') {
            $client->send_message($chatid, get_string('telegram_msg_text_only', 'local_jedomi'));
            return null;
        }
        return $text;
    }

    /**
     * Download a Telegram file, pass its local path to a callback and remove it afterwards.
     *
     * @param api_client $client Telegram client.
     * @param string $fileid Telegram file id.
     * @param callable $callback Receives the local path and returns the result.
     * @return array Result of the callback.
     * @throws \moodle_exception emptyresponse when the file cannot be downloaded.
     */
    private static function with_download(api_client $client, string $fileid, callable $callback): array {
        $path = $fileid !== '' ? $client->download_file($fileid) : null;
        if ($path === null || !file_exists($path)) {
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        }
        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * File name of a Telegram audio message, with an extension the transcription service knows.
     *
     * @param array $audio Telegram audio object.
     * @return string
     */
    private static function audio_filename(array $audio): string {
        $filename = clean_param((string) ($audio['file_name'] ?? ''), PARAM_FILE);
        if ($filename !== '' && audio_service::is_supported($filename)) {
            return $filename;
        }
        $extension = match (strtolower((string) ($audio['mime_type'] ?? ''))) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a' => 'm4a',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/opus' => 'ogg',
            default => '',
        };
        return $extension !== '' ? 'audio.' . $extension : ($filename !== '' ? $filename : 'audio');
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
        $client = api_client::instance();

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

        $user = self::get_active_user($userid);
        if (!$user) {
            $client->send_message($chatid, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // A chat linked to another account moves to this one: the earlier account loses the link and its data.
        foreach ($DB->get_fieldset_select('local_jedomi_telegram_links', 'userid', 'chatid = ?', [$chatid]) as $previous) {
            if ((int) $previous !== $userid) {
                telegram_link_service::unlink((int) $previous);
            }
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
        \local_jedomi\event\telegram_linked::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'relateduserid' => $userid,
        ])->trigger();

        // The chat was not linked when the update arrived: the welcome goes out in the language of the user just linked.
        $fullname = fullname($user);
        $welcome = \local_jedomi\local\user_language::run($userid, static function () use ($fullname, $user): string {
            return get_string('telegram_msg_linked_success', 'local_jedomi', [
                'fullname' => s($fullname),
                'username' => s($user->username),
            ]);
        });
        $client->send_message($chatid, $welcome);
    }

    /**
     * Handle /desvincular or /unlink command.
     *
     * @param string $chatid Telegram chat id
     * @param \stdClass $link Telegram link record for the user
     */
    private static function handle_unlink_command(string $chatid, \stdClass $link): void {
        // The link, the conversation, the task in progress, the limit and the held actions are removed.
        telegram_link_service::unlink((int) $link->userid);

        $client = api_client::instance();
        $client->send_message($chatid, get_string('telegram_msg_unlinked', 'local_jedomi'));
    }

    /**
     * Start a new conversation: the history, the cached context and the task in progress are reset, and a new session
     * generation stops any continuation of the previous task.
     *
     * Used by the /new command and the New conversation button.
     *
     * @param string $chatid Telegram chat id
     */
    private static function reset_conversation(string $chatid): void {
        session::clear($chatid);
        session::clear_context($chatid);
        session::set_context('tool_loop_' . $chatid, ['session_gen' => time()]);
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
                // The same reset as the /new command.
                self::reset_conversation($chatid);
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
                $link = self::get_link_by_chat_id($chatid);
                if ($link) {
                    telegram_link_service::unlink((int) $link->userid);
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
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @return array Telegram reply keyboard markup.
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
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @return array Telegram reply keyboard markup.
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

        $client = api_client::instance();
        $client->answer_callback_query($callbackid);

        // Handle command shortcuts from inline keyboard (/new, /help, /unlink).
        if (strpos($data, 'cmd:') === 0) {
            $command = substr($data, strlen('cmd:'));
            self::execute_cmd_callback($command, $chatid, $messageid, $client);
            return;
        }

        $confirm = strpos($data, self::CALLBACK_CONFIRM) === 0;
        if (!$confirm && strpos($data, self::CALLBACK_CANCEL) !== 0) {
            // Unknown action — ignore.
            return;
        }
        $actionid = substr($data, strlen($confirm ? self::CALLBACK_CONFIRM : self::CALLBACK_CANCEL));
        $client->edit_message_text($chatid, $messageid, self::resolve_held_action($chatid, $actionid, $confirm));
    }

    /**
     * Hold a tool call that changes data until the user confirms it with the buttons of the chat.
     *
     * @param string $chatid Telegram chat id.
     * @param int $userid Linked Moodle user.
     * @param string $name Tool name.
     * @param array $args Tool arguments.
     * @return string|null Result for the AI when the call is held, or null when the tool runs without confirmation.
     */
    private static function hold_action(string $chatid, int $userid, string $name, array $args): ?string {
        global $DB;

        $severity = pending_action_service::severity($name, $args);
        if ($severity === null) {
            return null;
        }
        $callbackid = random_string(16);
        $DB->insert_record('local_jedomi_telegram_pending_actions', (object) [
            'callbackid' => $callbackid,
            'chatid' => $chatid,
            'actionjson' => json_encode(['name' => $name, 'params' => $args, 'userid' => $userid], JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ]);

        api_client::instance()->send_message(
            $chatid,
            self::confirmation_text($userid, $name, $args, $severity),
            ['inline_keyboard' => [self::confirmation_buttons($callbackid, $severity)]]
        );
        $note = $severity === pending_action_service::SEVERITY_CRITICAL
            ? 'The action waits for the user to type the confirmation word in the chat. Do not call it again.'
            : 'The action waits for the user to confirm it with the buttons of the chat. Do not call it again.';
        return json_encode(['status' => 'awaiting_confirmation', 'note' => $note]);
    }

    /**
     * Confirmation message of a held action, with the same readable description and data as the web card; a critical
     * action asks for the confirmation word, as the web chat does.
     *
     * @param int $userid Linked Moodle user.
     * @param string $name Tool name.
     * @param array $args Tool arguments.
     * @param string $severity One of the pending_action_service::SEVERITY_* constants.
     * @return string HTML text for Telegram.
     */
    private static function confirmation_text(int $userid, string $name, array $args, string $severity): string {
        $card = pending_action_service::card($userid, $name, $args, $severity);
        $text = '<b>' . s($card['description']) . '</b>';
        if ($name === 'send_messages') {
            $text = '<b>' . s(str_replace('{count}', (string) $card['count'], get_string('confirm_send_bulk', 'local_jedomi')))
                . '</b>';
            if ($card['preview'] !== '') {
                $text .= "\n<i>" . s($card['preview']) . '</i>';
            }
        }
        if ($card['details'] !== '') {
            $text .= "\n" . s($card['details']);
        }
        $message = get_string('telegram_confirm_action', 'local_jedomi', $text);
        if ($severity === pending_action_service::SEVERITY_CRITICAL) {
            $message .= "\n\n" . get_string('telegram_confirm_keyword', 'local_jedomi', s(self::confirm_keyword()));
        }
        return $message;
    }

    /**
     * Buttons of a held action: a critical action is confirmed only with the confirmation word, so it has no
     * confirm button.
     *
     * @param string $callbackid Callback id of the held action.
     * @param string $severity One of the pending_action_service::SEVERITY_* constants.
     * @return array[] One row of inline keyboard buttons.
     */
    private static function confirmation_buttons(string $callbackid, string $severity): array {
        $cancel = ['text' => get_string('confirm_btn_no', 'local_jedomi'), 'callback_data' => self::CALLBACK_CANCEL . $callbackid];
        if ($severity === pending_action_service::SEVERITY_CRITICAL) {
            return [$cancel];
        }
        return [
            ['text' => get_string('confirm_btn_yes', 'local_jedomi'), 'callback_data' => self::CALLBACK_CONFIRM . $callbackid],
            $cancel,
        ];
    }

    /**
     * Confirmation word of critical actions: the confirm button label in capitals, as in the web chat.
     *
     * @return string
     */
    private static function confirm_keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }

    /**
     * Callback id of the newest critical action of a chat that is still waiting for the confirmation word.
     *
     * @param string $chatid Telegram chat id.
     * @return string|null
     */
    private static function waiting_critical_action(string $chatid): ?string {
        global $DB;
        $records = $DB->get_records_select(
            'local_jedomi_telegram_pending_actions',
            'chatid = :chatid AND timecreated >= :cutoff',
            ['chatid' => $chatid, 'cutoff' => time() - pending_action_service::EXPIRY],
            'timecreated DESC, id DESC'
        );
        foreach ($records as $record) {
            $action = json_decode((string) $record->actionjson, true);
            if (
                is_array($action) && pending_action_service::severity(
                    (string) ($action['name'] ?? ''),
                    (array) ($action['params'] ?? [])
                ) === pending_action_service::SEVERITY_CRITICAL
            ) {
                return (string) $record->callbackid;
            }
        }
        return null;
    }

    /**
     * Confirm or cancel a held action of a chat. The action runs once, only for the user who still has the chat linked
     * and is active, and never after it expires.
     *
     * A critical action runs only with the confirmation word typed in the chat; a confirm button for it (from an
     * older message) is rejected and the action is kept.
     *
     * @param string $chatid Telegram chat id.
     * @param string $actionid Callback id of the held action.
     * @param bool $confirm True to run it, false to cancel it.
     * @param bool $keyword Whether the confirmation word was typed.
     * @return string Message for the user.
     */
    private static function resolve_held_action(string $chatid, string $actionid, bool $confirm, bool $keyword = false): string {
        global $DB, $USER;

        $pending = $DB->get_record('local_jedomi_telegram_pending_actions', ['callbackid' => $actionid, 'chatid' => $chatid]);
        if (!$pending) {
            return get_string('pending_action_not_found', 'local_jedomi');
        }
        if ((int) $pending->timecreated < time() - pending_action_service::EXPIRY) {
            $DB->delete_records('local_jedomi_telegram_pending_actions', ['id' => $pending->id]);
            return get_string('pending_action_expired', 'local_jedomi');
        }
        $action = json_decode($pending->actionjson, true);
        $critical = is_array($action) && pending_action_service::severity(
            (string) ($action['name'] ?? ''),
            (array) ($action['params'] ?? [])
        ) === pending_action_service::SEVERITY_CRITICAL;
        if ($confirm && $critical && !$keyword) {
            return get_string('telegram_confirm_keyword', 'local_jedomi', s(self::confirm_keyword()));
        }
        // Removed before it runs, so it can run only once.
        $DB->delete_records('local_jedomi_telegram_pending_actions', ['id' => $pending->id]);
        $link = self::get_link_by_chat_id($chatid);
        if (!$confirm) {
            if ($link) {
                pending_action_service::action_event(false, (string) ($action['name'] ?? ''), 'telegram', (int) $link->userid);
            }
            return get_string('confirm_cancelled', 'local_jedomi');
        }

        $user = $link ? self::get_active_user((int) $link->userid) : null;
        if (
            !is_array($action) || !$user || (int) ($action['userid'] ?? 0) !== (int) $user->id
                || !self::is_user_role_allowed((int) $user->id)
        ) {
            return get_string('pending_action_denied', 'local_jedomi');
        }

        $USER = $user;
        pending_action_service::action_event(true, (string) ($action['name'] ?? ''), 'telegram', (int) $user->id);
        try {
            $result = self::execute_tool((string) ($action['name'] ?? ''), (array) ($action['params'] ?? []), (int) $user->id);
        } catch (\Throwable $e) {
            return get_string('pending_action_failed', 'local_jedomi');
        }
        $decoded = json_decode($result, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            $denied = is_string($decoded['error']) && str_starts_with($decoded['error'], 'permission_denied');
            return get_string($denied ? 'pending_action_denied' : 'pending_action_failed', 'local_jedomi');
        }
        return get_string('confirm_done', 'local_jedomi');
    }

    /**
     * Self-ping: fire a cURL to our own webhook so a new PHP-FPM worker
     * continues the checkpointed tool loop. The webhook responds 200 OK
     * immediately (fastcgi_finish_request), so this returns in <1s.
     *
     * @param string $chatid Telegram chat id whose tool loop continues
     */
    private static function self_ping(string $chatid): void {
        $request = self::self_ping_request($chatid);
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, $request['options']);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            \local_jedomi_log('[TELEGRAM SELF-PING] cURL error: ' . $err . ' for chat=' . self::chatref($chatid));
        }
    }

    /**
     * Address and cURL options of the self-ping to our own webhook.
     *
     * The secret goes in the same header Telegram uses, never in the address, and TLS is verified (JEDOMI-SEC-004).
     *
     * @param string $chatid Telegram chat id whose tool loop continues.
     * @return array ['url' => string, 'options' => array of cURL options].
     */
    public static function self_ping_request(string $chatid): array {
        global $CFG;
        $secret = \local_jedomi\telegram\manager::get_webhook_secret();
        $stateself = session::get_context('tool_loop_' . $chatid);
        $sessiongen = (int) ($stateself['session_gen'] ?? 0);
        $url = $CFG->wwwroot . '/local/jedomi/telegram_webhook.php?internal_ping=1&chat_id=' . urlencode($chatid)
            . '&session_gen=' . $sessiongen;
        $options = [
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret],
        ];
        // The certificate authorities Moodle uses for its own requests, when the site has them.
        require_once($CFG->libdir . '/filelib.php');
        $cacert = \curl::get_cacert();
        if (!empty($cacert)) {
            $options[CURLOPT_CAINFO] = $cacert;
        }
        return ['url' => $url, 'options' => $options];
    }

    /**
     * Handle an internal self-ping request. Loads the checkpoint state
     * and continues the tool loop from where the previous worker left off.
     *
     * @param string $chatid Telegram chat id whose checkpoint state is loaded
     */
    public static function handle_internal_ping(string $chatid): void {
        $normalised = self::normalise_chat_id($chatid);
        $link = $normalised === null ? null : self::get_link_by_chat_id($normalised);
        \local_jedomi\local\user_language::run($link ? (int) $link->userid : null, static function () use ($chatid): void {
            self::continue_tool_loop($chatid);
        });
    }

    /**
     * Continue the tool loop of a chat from its checkpoint, in the language already chosen by the caller.
     *
     * @param string $chatid Telegram chat id whose checkpoint state is loaded
     */
    private static function continue_tool_loop(string $chatid): void {
        global $DB;

        $chatid = self::normalise_chat_id($chatid);
        if ($chatid === null) {
            // The chat id arrives as a raw HTTP parameter: reject before any DB access.
            \local_jedomi_log('[GATELOOP] rejected non-numeric chat id in internal ping');
            return;
        }

        $disabled = \local_jedomi_plugin_disabled_error();
        if ($disabled !== null) {
            // Disabled in the Datacurso AI provider: do not continue the tool loop with the AI.
            api_client::instance()->send_message($chatid, $disabled['message']);
            return;
        }
        if (!manager::is_enabled()) {
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
            \local_jedomi_log('[GATELOOP] session_gen mismatch chat=' . self::chatref($chatid) . ' expected=' . $expectedgen
                . ' got=' . $incominggen . ' — aborting stale self-ping');
            return;
        }

        // The task continues only while the chat is still linked to the same active user with an allowed role.
        $link = self::get_link_by_chat_id($chatid);
        $user = $link ? self::get_active_user((int) $link->userid) : null;
        $stateuserid = (int) ($state['userid'] ?? 0);
        if (!$user || ($stateuserid > 0 && $stateuserid !== (int) $user->id) || !self::is_user_role_allowed((int) $user->id)) {
            \local_jedomi_log('[GATELOOP] chat ' . self::chatref($chatid) . ' no longer linked to an active user, task stopped');
            session::set_context('tool_loop_' . $chatid, []);
            return;
        }
        $userid = (int) $user->id;

        // Impersonate user so permission checks work.
        global $USER;
        $USER = $user;

        self::proxy_to_gateway($userid, $chatid, '', true);
    }

    /**
     * Main proxy loop: sends messages to the AI service through the AI client, handles tool calls,
     * streams responses, and self-pings when approaching FPM timeout so the
     * process survives across multiple PHP-FPM workers.
     *
     * @param int    $userid        Moodle user ID.
     * @param string $chatid       Telegram chat ID.
     * @param string $text          User message text (photos and audio are already text).
     * @param bool   $internalping True if called from self-ping (continue checkpoint).
     */
    private static function proxy_to_gateway(
        int $userid,
        string $chatid,
        string $text,
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
                    api_client::instance()->send_message(
                        $chatid,
                        get_string('telegram_msg_still_processing', 'local_jedomi')
                    );
                    return;
                }
                // Lock expired (> LOCK_TTL seconds) — auto-release and continue.
                \local_jedomi_log('[GATELOOP] stale_lock chat=' . self::chatref($chatid) . ' age=' . $lockage
                    . 's — auto-releasing');
                session::set_context($statekey, []);
                // Fall through to process the new message.
            }

            // ... ── Send typing indicator so user knows it's processing ──
            api_client::instance()->send_chat_action($chatid, 'typing');

            // Build messages for the Gateway.
            $messages = session::build_messages_for_gateway($chatid, $text);

            // Persist user message.
            session::append($chatid, 'user', $text);

            // ... ── Current role and courses: computed on every message, never cached ──
            $userctx = self::get_user_full_context($userid);
            $role = $userctx['role'];
            $spt = self::generate_spt_for_user($userid, $role, $userctx['course_ids_owned'], $userctx['course_ids_enrolled']);
            $tools = $spt['tools'] ?? [];

            $timeout = self::get_ai_timeout();

            // ... ── Restore checkpoint if a previous attempt was killed ──
            $anonymizermap = [];
            try {
                $checkpoint = session::get_context($statekey);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] checkpoint read FAILED for chat ' . self::chatref($chatid) . ': ' . $e->getMessage());
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
                    $anonymizermap = (array) ($checkpoint['anonymizer'] ?? []);
                    $cpjson = json_encode($checkpoint, JSON_UNESCAPED_UNICODE);
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] checkpoint restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                        self::chatref($chatid),
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
                'anonymizer'     => $anonymizermap,
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
                \local_jedomi_log('[GATELOOP] self-ping empty state for chat ' . self::chatref($chatid) . ', nothing to continue');
                return; // Nothing to continue.
            }
            $messages = $state['messages'];
            $statejsoncheck = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] self-ping restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                self::chatref($chatid),
                $state['iteration'] ?? 0,
                strlen($statejsoncheck),
                count($messages)
            ));

            // ... ── The role, courses and tools are those the user has now, not those of the checkpoint ──
            $userctx = self::get_user_full_context($userid);
            $role = $userctx['role'];
            $spt = self::generate_spt_for_user($userid, $role, $userctx['course_ids_owned'], $userctx['course_ids_enrolled']);
            $tools = $spt['tools'] ?? [];

            $timeout = self::get_ai_timeout();

            // Ensure lock is active.
            $state['is_locked'] = true;
            $state['locked_at'] = time();
        }

        // ... ── Build system message with course context (same for both flows) ──
        $toolnames = array_map(fn(array $t): string => $t['function']['name'], $tools);
        $coursesbyrole = $userctx['courses_by_role'];
        $userlang = $DB->get_field('user', 'lang', ['id' => $userid]) ?: current_language();

        $systemcontent = prompt_builder::build($role, $userlang, $toolnames, $coursesbyrole);

        $messages[0] = ['role' => 'system', 'content' => $systemcontent];

        // Personal data returned by the tools reaches the AI anonymised with the level of the Privacy tab; the
        // placeholders are kept in the state of the conversation, so a later worker (self-ping) can still turn the
        // arguments the AI proposes and its final answer back into real data.
        $anonymizer = anonymizer::from_map((array) ($state['anonymizer'] ?? []));
        // Tool results reach the AI as untrusted data, as in the web chat and the agents (JEDOMI-SEC-005). Blocks saved
        // by an earlier worker keep their own marker: the rule of the instructions covers any marker.
        $untrusted = new \local_jedomi\local\untrusted();

        // ... ── Tool loop uses state-based iteration ──
        // Each HTTP worker runs until ~55s, then self-pings so a new worker
        // picks up at the saved iteration. The user sees a single seamless response.
        $loopstart = time();
        $maxiterations = 50;
        $hardguard = self::HARD_GUARD; // Seconds — chain to next worker before FPM kills us.

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
                    self::chatref($chatid),
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
                    self::chatref($chatid),
                    $iteration,
                    $elapsed
                ));
                if (!self::access_still_valid($chatid, $userid)) {
                    self::stop_without_access($statekey, $chatid);
                    return;
                }
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                $state['anonymizer'] = $anonymizer->export_map();
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
                    self::chatref($chatid),
                    $iteration,
                    $elapsed
                ));
                if (!self::access_still_valid($chatid, $userid)) {
                    self::stop_without_access($statekey, $chatid);
                    return;
                }
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = time();
                $state['anonymizer'] = $anonymizer->export_map();
                session::set_context($statekey, $state);
                self::self_ping($chatid);
                return;
            }

            // ... ── Soft guard (~30s): send a keepalive so the user knows we're alive ──
            if ($elapsed >= 30 && !$senttimeoutwarning) {
                $senttimeoutwarning = true;
                api_client::instance()->send_message($chatid, get_string('telegram_msg_keepalive', 'local_jedomi'));
            }

            // ... ── Re-send typing indicator each iteration (Telegram auto-expires after ~6s) ──
            // Only on iterations after the first (avoids double-send with the initial one).
            if ($iteration > 0) {
                api_client::instance()->send_chat_action($chatid, 'typing');
            }

            // Adaptive AI timeout: shrink as we approach the hard guard
            // so we don't get stuck waiting when FPM is about to kill us.
            $aitimeout = max(5, min($timeout, $hardguard - (time() - $loopstart) - 5));

            \local_jedomi_log(sprintf(
                '[GATELOOP] calling_ai chat=%s iter=%d elapsed=%ds timeout=%ds msg_count=%d',
                self::chatref($chatid),
                $iteration,
                time() - $loopstart,
                $aitimeout,
                count($messages)
            ));

            try {
                // The question and the history keep the real values in the state; the AI gets them anonymised with
                // the placeholders of the conversation, as the tool results.
                $answer = \local_jedomi\ai\client::instance()->complete(
                    $anonymizer->anonymize_messages($messages),
                    $userid,
                    $tools,
                    $aitimeout,
                    0.2
                );
            } catch (\Throwable $e) {
                $error = \local_jedomi\ai\error_message::from_exception($e, $userlang);
                // Every failure, including a timeout, ends the turn: the request is never resent,
                // because the AI service may already have processed (and billed) it.
                \local_jedomi_log('[AI] error on iter ' . $iteration . ': ' . $error['code']);
                self::release_lock($statekey, $chatid, $error['message']);
                return;
            }

            // The access may have changed while the AI answered: nothing more runs and nothing is sent.
            if (!self::access_still_valid($chatid, $userid)) {
                self::stop_without_access($statekey, $chatid);
                return;
            }

            $result = self::parse_answer($answer);
            if ($result === null) {
                \local_jedomi_log('[AI] empty answer on iter ' . $iteration);
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
                // Each tool has a progress label in the language of the user; a tool without one shows its name.
                $toollangkeys = [
                    'execute_sql'     => 'tool_sql_read',
                    'ws_execute'      => 'tool_api_call',
                    'search_tables'   => 'tool_schema_lookup',
                    'describe_table'  => 'tool_schema_lookup',
                    'ws_search'       => 'tool_webservice_lookup',
                    'ws_describe'     => 'tool_webservice_lookup',
                    'get_site_config' => 'tool_get_site_config',
                    'site_overview'   => 'tool_site_overview',
                    'admin_search'    => 'confirm_executing',
                    'admin_describe'  => 'confirm_executing',
                    'admin_execute'   => 'confirm_executing',
                    'list_agent'      => 'tool_progress_list_agents', // Alias hallucinated singular.
                    'create_agents'   => 'tool_progress_create_agent', // Alias hallucinated plural.
                    'delete_agents'   => 'tool_progress_delete_agent', // Alias hallucinated plural.
                ];
                foreach ($message['tool_calls'] ?? [] as $tc) {
                    $name = (string) ($tc['function']['name'] ?? '');
                    $key = $toollangkeys[$name] ?? 'tool_progress_' . $name;
                    $label = get_string_manager()->string_exists($key, 'local_jedomi') ? get_string($key, 'local_jedomi') : $name;
                    $tooldisplay[] = $label;
                }
                $progressicon = match (true) {
                    $iteration <= 1  => '🔍',
                    $iteration <= 5  => '📖',
                    $iteration <= 10 => '⚡',
                    $iteration <= 20 => '📊',
                    default          => '⚙️',
                };
                api_client::instance()->send_message(
                    $chatid,
                    $progressicon . ' ' . implode(', ', $tooldisplay) . '…'
                );
            }

            if (!$hastools) {
                // ... ── Text response: send to Telegram ──

                // The answer was written from anonymised data: the user gets the real values back, without the
                // untrusted data markers it may have echoed (REL-003).
                $content = $anonymizer->restore(\local_jedomi\local\untrusted::strip_markers((string) $content));

                // Markdown and HTML of the answer become the Telegram HTML subset, escaped and balanced.
                $display = formatter::to_html($content);

                // If sanitisation emptied the text, send original as plain text.
                if (trim(strip_tags($display)) === '') {
                    $fallback = (is_string($content) && $content !== '')
                        ? $content : get_string('telegram_msg_done', 'local_jedomi');
                    session::set_context($statekey, []);
                    session::append($chatid, 'assistant', $fallback);
                    \local_jedomi\event\chat_answered::create_for_channel('telegram', null, $userid)->trigger();
                    api_client::instance()->send_message($chatid, $fallback);
                    return;
                }

                // ... ── Release lock before sending final response ──
                \local_jedomi_log('[GATELOOP] text response for chat ' . self::chatref($chatid) . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $loopstart) . 's, len=' . strlen($display));
                session::set_context($statekey, []);

                // The answer is kept in the history, so the next turns have the whole conversation.
                session::append($chatid, 'assistant', (string) $content);
                \local_jedomi\event\chat_answered::create_for_channel('telegram', null, $userid)->trigger();

                // If the response is large (> 3800 chars) or contains tabular data
                // (<pre> block), send as a .txt file instead of chunking multiple messages.
                if (mb_strlen($display) > 3800 || preg_match('/<pre>/i', $display)) {
                    $tmpdir = $CFG->dataroot . '/jedomi/telegram';
                    check_dir_exists($tmpdir, true, true);
                    $filename = 'chat_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
                    $filepath = $tmpdir . '/' . $filename;

                    // Extract first meaningful line for the caption.
                    $plaintext = html_entity_decode(strip_tags($display), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
                    api_client::instance()->send_document($chatid, $filepath, $chatcaption);
                    @unlink($filepath);
                } else {
                    api_client::instance()->send_message($chatid, $display);
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
                    self::chatref($chatid),
                    $iteration,
                    $freshstate['iteration']
                ));
                $iteration = (int)$freshstate['iteration'] - 1; // For loop increments; restore 1 less.
                $messages = $freshstate['messages'] ?? $messages;
                $anonymizer = anonymizer::from_map((array) ($freshstate['anonymizer'] ?? []));
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
                        self::chatref($chatid),
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
                        self::chatref($chatid),
                        $iteration,
                        $name,
                        $callkey,
                        $repeatcount
                    ));
                }

                // Each tool runs only while the user still has access.
                if (!self::access_still_valid($chatid, $userid)) {
                    self::stop_without_access($statekey, $chatid);
                    return;
                }

                // The AI only knows the placeholders of the anonymised data; the tools need the real values.
                $unrestored = null;
                try {
                    $args = $anonymizer->restore_tool_args($name, $args);
                } catch (\local_jedomi\local\unrestored_placeholder_exception $e) {
                    // The query is not run against the text of a placeholder: the AI is told how to write it (RISK-001).
                    $unrestored = $e->tool_result();
                }

                try {
                    if ($unrestored !== null) {
                        $toolresult = $unrestored;
                    } else if (!in_array(self::canonical_tool_name($name), $toolnames, true)) {
                        // Only the tools offered to the AI for the role of the user can run.
                        $toolresult = json_encode(['error' => 'permission_denied']);
                    } else {
                        $toolresult = self::hold_action($chatid, $userid, $name, $args)
                            ?? self::execute_tool($name, $args, $userid, $role, $userctx['course_ids_owned'], $anonymizer);
                    }
                    if (mb_strlen($toolresult) > 64000) {
                        $toolresult = mb_substr($toolresult, 0, 64000) . "\n... (truncated)";
                    }
                } catch (\Exception $e) {
                    $toolresult = 'Error executing ' . $name . ': ' . $e->getMessage();
                }
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcid,
                    'content'      => $untrusted->wrap('tool ' . $name, $anonymizer->anonymize_tool_result($toolresult)),
                ];
            }

            // ... ── Save checkpoint with updated iteration + messages ──
            $state['iteration'] = $iteration + 1;
            $state['messages'] = $messages;
            $state['is_locked'] = true;

            $statejson = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] state_save chat=%s iter=%d state_bytes=%d msg_count=%d',
                self::chatref($chatid),
                $iteration,
                strlen($statejson),
                count($messages)
            ));

            // The state of a task whose access changed (or that unlinking removed) is not written again.
            if (!self::access_still_valid($chatid, $userid)) {
                self::stop_without_access($statekey, $chatid);
                return;
            }

            try {
                $state['anonymizer'] = $anonymizer->export_map();
                session::set_context($statekey, $state);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] STATE SAVE FAILED for chat ' . self::chatref($chatid)
                    . ' iter=' . $iteration . ' bytes=' . strlen($statejson)
                    . ' err=' . $e->getMessage());
                // Fallback: release lock and inform user, don't crash silently.
                session::set_context($statekey, []);
                api_client::instance()->send_message($chatid, get_string('telegram_msg_state_save_failed', 'local_jedomi'));
                return;
            }

            if ($looping) {
                \local_jedomi_log('[GATELOOP] loop_exit for chat ' . self::chatref($chatid) . ' at iter ' . $iteration
                    . ', elapsed=' . (time() - $loopstart) . 's');
                session::set_context($statekey, []);
                api_client::instance()->send_message($chatid, get_string('telegram_msg_max_iterations', 'local_jedomi'));
                return;
            }
        }

        // ... ── Max iterations reached ──
        \local_jedomi_log('[GATELOOP] max iterations reached for chat ' . self::chatref($chatid));
        session::set_context($statekey, []);
        api_client::instance()->send_message($chatid, get_string('telegram_msg_max_iterations', 'local_jedomi'));
    }


    /**
     * Whether a request in progress may go on: Jedomi and the Telegram integration are enabled and the chat is still
     * linked to the same user, whose account is active and whose role is allowed.
     *
     * @param string $chatid Telegram chat id.
     * @param int $userid User the request runs for.
     * @return bool
     */
    private static function access_still_valid(string $chatid, int $userid): bool {
        $link = self::get_link_by_chat_id($chatid);
        return $link !== null && (int) $link->userid === $userid && \local_jedomi_telegram_recipient($userid) !== null;
    }

    /**
     * Stop a request in progress whose access changed. Nothing is sent, and the task state is released only when it
     * still exists, so the state that unlinking removed is not written again.
     *
     * @param string $statekey Key of the task state.
     * @param string $chatid Telegram chat id.
     */
    private static function stop_without_access(string $statekey, string $chatid): void {
        global $DB;
        \local_jedomi_log('[GATELOOP] access changed for chat ' . self::chatref($chatid) . ', request stopped');
        if ($DB->record_exists('local_jedomi_telegram_sessions', ['chatid' => $statekey])) {
            session::set_context($statekey, []);
        }
    }

    /**
     * Turn an AI client answer into the shape the tool loop uses.
     *
     * @param  array $answer Answer from \local_jedomi\ai\client::complete().
     * @return array|null    ['type'=>'text', 'content'=>'...']
     *                       ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                       null when the answer has neither text nor tool calls.
     */
    private static function parse_answer(array $answer): ?array {
        if (!empty($answer['tool_calls'])) {
            return ['type' => 'tool_calls', 'tool_calls' => $answer['tool_calls']];
        }

        // Text response: prefer content, fall back to reasoning (thinking tokens).
        $text = ($answer['content'] ?? '') !== '' ? $answer['content'] : ($answer['reasoning'] ?? '');
        if ($text === '') {
            return null;
        }
        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Total timeout of each AI call, from the Telegram timeout setting (5 s to MAX_AI_TIMEOUT, the default).
     *
     * A worker hands the loop to the next one after HARD_GUARD seconds, so a longer value could never apply; values
     * saved by older versions are capped here.
     *
     * @return int
     */
    public static function get_ai_timeout(): int {
        $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
        if ($timeout < 5) {
            return self::MAX_AI_TIMEOUT;
        }
        return min($timeout, self::MAX_AI_TIMEOUT);
    }

    /**
     * Execute a tool by name with given arguments.
     * Respects role-based access — only safe read operations.
     *
     * SECURITY: the caller-supplied $role is NEVER trusted for authorization.
     * It is immediately overwritten with the role re-derived from $userid
     * (the only value taken from the authenticated chat link), so a spoofed
     * role string cannot bypass role-gated tools. $userid <= 0 always
     * derives to '' (denies every role-gated tool).
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @param string $name Tool name to execute
     * @param array $args Tool arguments
     * @param int $userid Moodle user id executing the tool
     * @param string $role Ignored for authorization; kept for signature compatibility.
     * @param array $courseidsowned Course ids the user manages
     * @param anonymizer|null $anonymizer Anonymiser of the conversation; the rows of a database query are anonymised
     *                                    by column name with it before they are formatted.
     * @return string Tool execution result formatted for Telegram
     */
    public static function execute_tool(
        string $name,
        array $args,
        int $userid = 0,
        string $role = '',
        array $courseidsowned = [],
        ?anonymizer $anonymizer = null
    ): string {
        global $DB, $CFG;

        // Re-derive the authoritative role from $userid — never trust the
        // caller-supplied $role string for authorization decisions.
        $role = self::derive_authoritative_role($userid);

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

                // ... ── Role gate shared with the web chat — admin/manager/teacher only ──
                if (!\local_jedomi\local\query_runner::role_can_query($role)) {
                    return "❌ Permission denied.";
                }

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

                // ... ── Guard, timeout, row cap and sensitive columns shared with the web chat ──
                $maxrows = \local_jedomi\local\query_runner::row_limit('telegram');
                try {
                    $result = \local_jedomi\local\query_runner::run($sql, $params, $userid, $maxrows, 'telegram');
                } catch (\moodle_exception $e) {
                    if (in_array($e->errorcode, ['execute_sql_error', 'query_timeout'], true)) {
                        return "❌ SQL error: " . $e->getMessage() . (empty($e->debuginfo) ? '' : ' | ' . $e->debuginfo);
                    }
                    return '❌ ' . $e->getMessage();
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

                $rows = [];
                foreach ($result['rows'] as $row) {
                    // Detect timestamp-like columns from the first row's keys.
                    if (empty($datekeys)) {
                        foreach ((array)$row as $key => $val) {
                            if (in_array($key, $datecolumns, true)) {
                                $datekeys[] = $key;
                            }
                        }
                    }

                    $fields = [];
                    if ($anonymizer !== null) {
                        $row = $anonymizer->anonymize_row($row);
                    }
                    foreach ($row as $key => $val) {
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
                if ($result['truncated']) {
                    $rows[] = '⚠️ More than ' . $maxrows . ' rows returned. Showing first ' . $maxrows . '.';
                }

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

            case 'site_overview':
                // Admin and manager only, with the role derived above; aggregates only, no personal data.
                return \local_jedomi\agent\tool\site_overview_tool::run($args, $role);

            case 'ws_search':
                $query = $args['query'] ?? '';
                $limit = min((int)($args['limit'] ?? 10), 20);
                if (empty($query)) {
                    return "Search query required.";
                }
                // Only the functions of the list of the role, the same list that gates the execution.
                $allowed = \local_jedomi\local\ws_runner::searchable_functions($role);
                if ($allowed === []) {
                    return get_string('note_ws_none_configured', 'local_jedomi');
                }
                $entries = ws_indexer::search($query, $allowed ?? [], $limit);
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
                if (!\local_jedomi\local\ws_runner::role_allows($role, (string) $wsname)) {
                    return json_encode(['error' => 'permission_denied: function not in allowlist']);
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

                // ... ── Gate 1: role list shared with the web chat and the companion web service ──
                if (!\local_jedomi\local\ws_runner::role_allows($role, $wsname)) {
                    return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
                }

                // ... ── Gate 2: Resolve target WS method ──────────────────────────────
                try {
                    $method = \local_jedomi\local\ws_runner::resolve($wsname);
                } catch (\moodle_exception $e) {
                    return json_encode(['error' => 'WS not found: ' . $wsname]);
                }
                $decoded = json_decode($params, true);
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $missing = \local_jedomi\local\ws_runner::missing_parameter($method, $decoded);
                if ($missing !== null) {
                    $got = implode(', ', array_keys($decoded));
                    return json_encode([
                        'error' => 'ws_execute_param_mismatch',
                        'ws' => $wsname,
                        'expected_params' => array_map(
                            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                            $method->getParameters()
                        ),
                        'provided_params' => array_keys($decoded),
                        'hint' => "WS expects parameter '{$missing}'. Provided: [{$got}]. "
                            . "Use ws_describe('{$wsname}') to see the exact structure.",
                    ]);
                }

                // ... ── Gate 3: call the WS method (native capabilities inside) ──────
                // The call runs in a delegated transaction, committed only when it succeeds, because the webhook does
                // not go through external_api::call_external_function() (its validate_context() needs a web page).
                try {
                    $result = \local_jedomi\local\ws_runner::call($method, $decoded, true);
                } catch (\Throwable $e) {
                    return json_encode(['error' => 'Error in ' . $wsname . ': ' . $e->getMessage()]);
                }
                $json = json_encode($result);
                if (mb_strlen($json) > 2048) {
                    $json = mb_substr($json, 0, 2048) . "\n... (truncated)";
                }
                return $json;

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
                $result = \local_jedomi\schema\cli_registry::execute($name, $params, $userid);
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
                $data = new \stdClass();
                $data->userid = $userid;
                $data->name = trim($args['name'] ?? '');
                $data->prompt = $prompt;
                $data->schedule_cron = $schedule;
                $data->context_type = $args['context_type'] ?? 'site';
                $data->context_id = isset($args['context_id']) ? (int) $args['context_id'] : null;
                $data->enabled = isset($args['enabled']) ? (int) $args['enabled'] : 1;
                // The schedule and the context (a Moodle context id of the chosen type) are validated as in the form.
                $invalid = \local_jedomi\agent\agent_manager::validation_error($data);
                if ($invalid !== null) {
                    return '❌ ' . s($invalid);
                }
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
                    // The instruction is given only to the users who may read it.
                    $a = \local_jedomi\agent\agent_manager::agent_for_actor($a, (int) $userid);
                    $statusicon = $a->enabled ? '🟢' : '⚪';
                    $next = $a->nextrun ? userdate($a->nextrun) : '?';
                    // Always show agent ID so the model can use delete_agent(id).
                    $name = !empty($a->name) ? s($a->name) : "Agent #{$a->id}";
                    // Truncate long prompts to keep the tool result manageable; hidden ones are never given.
                    $excerpt = $a->prompthidden ? get_string('agent_prompt_hidden', 'local_jedomi')
                        : s(mb_substr($a->prompt, 0, 60));
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
                if (!\local_jedomi\agent\agent_manager::can_manage_agent($agent, (int) $userid)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $label = !empty($agent->name) ? $agent->name : "Agent #{$id}";
                \local_jedomi\agent\agent_manager::delete_agent($id);
                return "🗑️ <b>{$label} deleted</b> — " . s(mb_substr($agent->prompt, 0, 60));

            case 'my_courses':
            case 'my_grades':
            case 'my_assignments':
                // Always the data of the linked user, whatever the arguments say; no linked user reads nothing.
                return \local_jedomi\agent\tool\own_data_tool::run($name, $args, $userid);

            default:
                return "❌ Tool '" . s($name) . "' is not supported via Telegram. Try the web interface.";
        }
    }

    /**
     * Execute a pending action directly (bypass LLM).
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @param array $action Pending action payload (action name and params)
     * @param string $chatid Telegram chat id
     * @return string Result message formatted for Telegram
     */
    public static function execute_pending_action(array $action, string $chatid): string {
        global $DB;

        $toolname = $action['name'] ?? '';
        $params = $action['params'] ?? [];

        // Resolve user role from chat link for permission checks.
        $link = self::get_link_by_chat_id($chatid);
        $role = $link ? \local_jedomi_get_user_role_for_user((int) $link->userid) : '';

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
                    'anonymization_level',
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
     * Check if text has the exact format of a link code: six characters of the code alphabet with at least one digit
     * (see manager::generate_link_code()). A greeting or another short word is never taken as a code.
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @param string $text Incoming message text
     * @return bool True if the text matches the link code format
     */
    public static function looks_like_link_code(string $text): bool {
        return manager::is_link_code_format(trim($text));
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
        api_client::instance()->send_message($chatid, $message);
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
     * The same rule as the profile link and the notifications: see local_jedomi_telegram_role_allowed_for().
     *
     * @param  int $userid Moodle user id to check.
     * @return bool True if the user is allowed to interact with the bot.
     */
    public static function is_user_role_allowed(int $userid): bool {
        return \local_jedomi_telegram_role_allowed_for($userid);
    }

    /**
     * Record one message against the per-chat rate limit window and report if the chat is blocked.
     *
     * Uses a delegated transaction. When the limit cannot be checked or recorded (a database error) the
     * failure is logged and null is returned, so the message is not processed and the user is told.
     *
     * @param  string $chatid Telegram chat id.
     * @return bool|null True if the chat is currently rate-limited (message must be rejected), null on failure.
     */
    private static function check_and_record_rate(string $chatid): ?bool {
        global $DB;
        $now = time();
        $window = 60; // 1 minute
        $limit = manager::get_rate_limit();
        $cooldown = manager::get_cooldown() * 60;

        if ($limit <= 0) {
            return false; // Unlimited.
        }

        try {
            $transaction = $DB->start_delegated_transaction();
        } catch (\Throwable $e) {
            \local_jedomi_log('[TELEGRAM RATE] limit check failed for chat ' . self::chatref($chatid) . ': ' . $e->getMessage());
            return null;
        }
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
        } catch (\Throwable $e) {
            \local_jedomi_log('[TELEGRAM RATE] limit check failed for chat ' . self::chatref($chatid) . ': ' . $e->getMessage());
            try {
                // The rollback throws the error again; it is already handled here.
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                unset($rethrown);
            }
            return null;
        }
    }

    // ... ── User role & SPT helpers (self-contained, no dependency on lib.php) ──

    /**
     * Re-derive the authoritative Jedomi role for a userid, bypassing any caller-supplied role string.
     *
     * It is computed on every call, as in the web chat, so a role removed from the user stops applying at once.
     *
     * @param  int $userid Moodle user id. <= 0 always denies (returns '').
     * @return string The authoritative role ('admin'|'manager'|'teacher'|'student'|'').
     */
    private static function derive_authoritative_role(int $userid): string {
        if ($userid <= 0) {
            return '';
        }
        return \local_jedomi_get_user_role_for_user($userid);
    }

    /**
     * Whether a Telegram chat is a private chat with one person. Groups, supergroups and channels have negative
     * ids, and their messages carry their type.
     *
     * @param string $chatid Normalised chat id.
     * @param string $type Chat type from the update, empty when absent.
     * @return bool
     */
    private static function is_private_chat(string $chatid, string $type): bool {
        if ($type !== '' && $type !== 'private') {
            return false;
        }
        return strpos($chatid, '-') !== 0;
    }

    /**
     * The linked user, when the account still exists, is not suspended and can log in.
     *
     * @param int $userid Linked Moodle user.
     * @return \stdClass|null The user record, or null when the account can no longer be served.
     */
    private static function get_active_user(int $userid): ?\stdClass {
        return \local_jedomi_telegram_active_user($userid);
    }

    /**
     * Canonical name of a tool, mapping the plural or singular forms models sometimes use.
     *
     * @param string $name Tool name as the model called it.
     * @return string
     */
    private static function canonical_tool_name(string $name): string {
        return match ($name) {
            'create_agents' => 'create_agent',
            'delete_agents' => 'delete_agent',
            'list_agent' => 'list_agents',
            default => $name,
        };
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
        // Excluded courses are outside Jedomi: they are never signed as courses of the user.
        $courseidsowned = \local_jedomi\course_access::filter_course_ids($courseidsowned);
        $courseidsenrolled = \local_jedomi\course_access::filter_course_ids($courseidsenrolled);

        // Only the tools of the role that Telegram can really run are offered.
        $tools = array_values(array_filter(
            \local_jedomi_get_tool_definitions($role, $userid, 'telegram'),
            static fn(array $tool): bool => in_array($tool['function']['name'] ?? '', self::SUPPORTED_TOOLS, true)
        ));

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
        $staticsecret = \local_jedomi_get_spt_secret();

        ksort($payload);
        $signature = hash_hmac('sha256', \local_jedomi_spt_signing_string($payload), $staticsecret);

        $payload['signature'] = $signature;
        return $payload;
    }

    /**
     * Current role of the user and their courses grouped by the role held in each one.
     *
     * The role is computed as in the web chat: only site administrators and system user managers are
     * administrators and managers, so a role or permission in a course never makes the user a manager.
     * The courses are those with an active enrolment or a role assigned in the course, without the site
     * home and the courses excluded from Jedomi, and each one is grouped by the highest role the user holds in it.
     *
     * @param  int   $userid
     * @return array  'role', 'course_ids_owned', 'course_ids_enrolled', 'courses_by_role'
     */
    private static function get_user_full_context(int $userid): array {
        global $DB;

        $courses = [];
        foreach (enrol_get_users_courses($userid, true, ['id', 'fullname']) as $course) {
            $courses[(int) $course->id] = $course;
        }
        // Courses where the user has a role without an enrolment.
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]
        );
        foreach ($rows as $row) {
            $courses[(int) $row->id] = $courses[(int) $row->id] ?? $row;
        }
        unset($courses[SITEID]);
        // Excluded courses are outside Jedomi: never listed, signed or named to the AI.
        $courses = array_filter(
            $courses,
            static fn(int $courseid): bool => \local_jedomi\course_access::is_course_in_jedomi($courseid),
            ARRAY_FILTER_USE_KEY
        );

        $rank = ['student' => 1, 'teacher' => 2, 'manager' => 3];
        $courseidsowned = [];
        $courseidsenrolled = [];
        $coursesbyrole = [];
        foreach ($courses as $courseid => $course) {
            $context = \context_course::instance($courseid);
            $group = null;
            foreach (get_user_roles($context, $userid, false) as $assignment) {
                $archetype = $DB->get_field('role', 'archetype', ['id' => $assignment->roleid]) ?: $assignment->shortname;
                $candidate = match ($archetype) {
                    'manager' => 'manager',
                    'editingteacher', 'teacher' => 'teacher',
                    'student' => 'student',
                    default => null,
                };
                if ($candidate !== null && ($group === null || $rank[$candidate] > $rank[$group])) {
                    $group = $candidate;
                }
            }
            if ($group === null) {
                // No role of a known kind in this course: it gives no access to report.
                continue;
            }
            $coursesbyrole[$group][] = $course->fullname;
            if (has_capability('moodle/course:manageactivities', $context, $userid)) {
                $courseidsowned[] = $courseid;
            } else {
                $courseidsenrolled[] = $courseid;
            }
        }

        return [
            'role'              => \local_jedomi_get_user_role_for_user($userid),
            'course_ids_owned'  => $courseidsowned,
            'course_ids_enrolled' => $courseidsenrolled,
            'courses_by_role'   => $coursesbyrole,
        ];
    }
}
