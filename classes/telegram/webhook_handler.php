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
 * Telegram webhook handler — facade of the Telegram channel.
 *
 * Updates are parsed into inbound messages (update_parser); typed messages go to the channel-neutral router, inline
 * buttons are handled here.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\anonymizer;
use local_jedomi\local\service\telegram_link_service;
use local_jedomi\messaging\access_guard;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\conversation_engine;
use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\router;

/**
 * Entry point of the Telegram webhook: acknowledges and deduplicates updates, answers while Jedomi or the integration
 * is disabled, takes the lock of the chat and hands typed messages to the router; handles inline buttons. Its public
 * methods are kept for the webhook, the tasks and the tests, and delegate to the Telegram channel.
 *
 * Sends: plain texts go through the transport of the channel (manager::transport()->send_text()); messages with a
 * keyboard, button answers and message edits stay on api_client, which the transport does not cover.
 *
 * The slash commands, with the help ones ('/help' and its Spanish alias '/ayuda'), are in telegram_commands.
 */
class webhook_handler {
    /**
     * Alias of conversation_engine::MAX_AI_TIMEOUT, kept for tests; production code uses the engine constant.
     */
    public const MAX_AI_TIMEOUT = conversation_engine::MAX_AI_TIMEOUT;

    /**
     * Alias of conversation_engine::QUESTION_TIME_LIMIT, kept for tests; production code uses the engine constant.
     */
    public const QUESTION_TIME_LIMIT = conversation_engine::QUESTION_TIME_LIMIT;

    /** Alias of telegram_channel::TYPING_INTERVAL, kept for tests; production code uses the channel constant. */
    public const TYPING_INTERVAL = telegram_channel::TYPING_INTERVAL;

    /** Alias of telegram_channel::TYPING_TIMEOUT, kept for tests; production code uses the channel constant. */
    public const TYPING_TIMEOUT = telegram_channel::TYPING_TIMEOUT;

    /**
     * A held action is confirmed with the confirmation word typed in the chat. Only read by a reflection test
     * (webhook_conversation_test), which checks the keyword mode; kept for it.
     */
    private const RESOLVE_KEYWORD = confirmation_service::MODE_KEYWORD;

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
        return update_parser::chat_key($chatid);
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
        \core\di::get(\local_jedomi\messaging\http_responder::class)->send_and_close(200, $ack['headers'], $ack['body']);
    }

    /**
     * Main entry point — dispatch based on Telegram update type.
     *
     * @param array $update Decoded JSON from Telegram webhook
     */
    public static function handle(array $update): void {
        if (manager::mark_update_seen($update)) {
            // Telegram delivered the update again: it was acknowledged and is not processed twice (SEC-JD-049).
            \local_jedomi_log('Telegram webhook: repeated update ignored');
            return;
        }
        try {
            // Every answer goes out in the language of the linked user; the site language when the chat is not linked
            // yet.
            \local_jedomi\local\user_language::run(self::update_user_id($update), static function () use ($update): void {
                self::dispatch($update);
            });
        } catch (\Throwable $e) {
            // The update failed: it is forgotten so the retry of Telegram is processed (REL-006).
            manager::forget_update($update);
            throw $e;
        }
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
        $denial = manager::access_guard()->channel_denial();
        if ($denial !== null && $denial['code'] === access_guard::INTEGRATION_DISABLED) {
            // The administrator turned the integration off: the bot processes nothing, a linked chat is told so.
            \local_jedomi_log('Telegram webhook: integration disabled, update not processed');
            self::reply_integration_disabled($update);
            return;
        }
        if ($denial !== null) {
            self::reply_plugin_disabled($update, $denial['message']);
            return;
        }

        // An unknown update, a message with an invalid chat id or an incomplete button press is not processed.
        $message = update_parser::parse($update);
        if ($message === null) {
            return;
        }
        if ($message->is_action()) {
            self::handle_callback_query($message);
            return;
        }
        self::handle_message($message);
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
            manager::transport()->send_text($chatid, $message);
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
            manager::transport()->send_text($chatid, get_string('telegram_msg_unavailable', 'local_jedomi'));
        }
    }

    /**
     * Handle an incoming message (text, photo, document, voice or audio).
     *
     * @param inbound_message $message Typed message of the update, with a validated chat id.
     */
    private static function handle_message(inbound_message $message): void {
        $chatid = $message->chatkey;

        \local_jedomi_log('[TELEGRAM ENTRY] chat=' . self::chatref($chatid) . ' textlen=' . mb_strlen($message->text));

        // One message per chat at a time: Telegram retries a slow update, and two workers on the same chat would run
        // the same tool loop twice. The Moodle lock API works on every supported database (the advisory lock used
        // before worked only on MySQL); the lock is released when the message is done, also on an exception.
        $lock = self::acquire_chat_lock($chatid);
        if ($lock === false) {
            \local_jedomi_log('DEDUP: another process is handling chat ' . self::chatref($chatid) . ', skipping');
            // Another process already holds the lock for this chat: the message is not lost without notice.
            manager::transport()->send_text($chatid, get_string('telegram_msg_still_processing', 'local_jedomi'));
            return;
        }
        try {
            (new router(manager::channel()))->route($message);
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
                telegram_commands::reset_conversation($chatid);
                $client->edit_message_text(
                    $chatid,
                    $messageid,
                    get_string('telegram_msg_new_conversation', 'local_jedomi')
                );
                break;
            case 'help':
                $client->edit_message_text($chatid, $messageid, telegram_commands::help_text());
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
        return telegram_commands::linked_keyboard();
    }

    /**
     * Build inline keyboard for an unlinked user (howto link, help).
     *
     * Internal: exposed as a testable entry point; not a stable plugin API.
     *
     * @return array Telegram reply keyboard markup.
     */
    public static function get_unlinked_keyboard(): array {
        return telegram_commands::unlinked_keyboard();
    }

    /**
     * Handle an inline-keyboard callback query: acknowledge it and dispatch cmd:/confirm: actions.
     *
     * Buttons stay here: their commands edit the message that carries them, which is particular to Telegram.
     *
     * @param inbound_message $callback Pressed button, with its id, its payload and the message that carries it.
     */
    private static function handle_callback_query(inbound_message $callback): void {
        $data = (string) $callback->actionpayload;
        $chatid = $callback->chatkey;
        $messageid = (int) $callback->messageid;

        $client = api_client::instance();
        $client->answer_callback_query($callback->externalid);

        $link = self::get_link_by_chat_id($chatid);
        if ($link && self::expire_or_touch($link)) {
            // The button of an expired link does nothing.
            $client->edit_message_text($chatid, $messageid, get_string('telegram_msg_link_expired', 'local_jedomi'));
            return;
        }

        // Handle command shortcuts from inline keyboard (/new, /help, /unlink).
        if (strpos($data, 'cmd:') === 0) {
            $command = substr($data, strlen('cmd:'));
            self::execute_cmd_callback($command, $chatid, $messageid, $client);
            return;
        }

        $reply = manager::confirmation_service()->ui()->parse_reply($data);
        if ($reply === null) {
            // Unknown action — ignore.
            return;
        }
        $client->edit_message_text($chatid, $messageid, self::resolve_held_action($chatid, $reply->actionid, $reply->mode));
    }

    /**
     * Remove a link unused for more than 30 days (Decision 7), or record that it is used now.
     *
     * @param \stdClass $link Link of the chat.
     * @return bool True when the link expired and was removed with the data of its chat.
     */
    private static function expire_or_touch(\stdClass $link): bool {
        return manager::commands()->expire_or_touch($link);
    }

    /**
     * Hold a tool call that changes data until the user confirms it (see confirmation_service::hold()).
     *
     * @param string $chatid Telegram chat id.
     * @param int $userid Linked Moodle user.
     * @param string $name Tool name.
     * @param array $args Tool arguments.
     * @return string|null Result for the AI when the call is held or refused, or null for a tool that changes nothing.
     */
    private static function hold_action(string $chatid, int $userid, string $name, array $args): ?string {
        return manager::confirmation_service()->hold($chatid, $userid, $name, $args);
    }

    /**
     * Confirm or cancel a held action of a chat (see confirmation_service::resolve()).
     *
     * @param string $chatid Telegram chat id.
     * @param string $actionid Callback id of the held action.
     * @param string $mode How the user answered: one of the confirmation_service::MODE_* constants.
     * @return string Message for the user.
     */
    private static function resolve_held_action(string $chatid, string $actionid, string $mode): string {
        return manager::confirmation_service()->resolve($chatid, $actionid, $mode);
    }

    /**
     * Address, headers and \curl options of the self-ping to our own webhook.
     *
     * Internal: exposed as a testable entry point; not a stable plugin API. It delegates to the Telegram
     * continuation (see telegram_continuation::request()).
     *
     * @param string $chatid Telegram chat id whose tool loop continues.
     * @return array ['url' => string, 'headers' => string[], 'options' => array of \curl options keyed by name].
     */
    public static function self_ping_request(string $chatid): array {
        return (new telegram_continuation())->request($chatid, self::session_gen($chatid));
    }

    /**
     * Session generation of the saved tool loop of a chat; 0 when none was recorded.
     *
     * @param string $chatid Telegram chat id.
     * @return int
     */
    private static function session_gen(string $chatid): int {
        $state = session::get_context('tool_loop_' . $chatid);
        return (int) ($state['session_gen'] ?? 0);
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
     * The chat id arrives as a raw HTTP parameter: it is validated here, before any DB access, and the rest runs in the
     * conversation engine (see conversation_engine::resume()).
     *
     * @param string $chatid Telegram chat id whose checkpoint state is loaded
     */
    private static function continue_tool_loop(string $chatid): void {
        $chatid = self::normalise_chat_id($chatid);
        if ($chatid === null) {
            \local_jedomi_log('[GATELOOP] rejected non-numeric chat id in internal ping');
            return;
        }
        self::engine()->resume($chatid, optional_param('session_gen', 0, PARAM_INT));
    }

    /**
     * Conversation engine of the Telegram channel.
     *
     * @return conversation_engine
     */
    private static function engine(): conversation_engine {
        return new conversation_engine(manager::channel());
    }

    /**
     * Total timeout of each AI call, from the Telegram timeout setting (5 s to MAX_AI_TIMEOUT, the default).
     *
     * A worker hands the loop to the next one after conversation_engine::HARD_GUARD seconds, so a longer value could
     * never apply; values saved by older versions are capped (see telegram_channel::ai_timeout()).
     *
     * @return int
     */
    public static function get_ai_timeout(): int {
        return manager::channel()->ai_timeout();
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
     * Internal: exposed as a testable entry point; not a stable plugin API. It delegates to the Telegram tool
     * runner (see manager::tool_runner()).
     *
     * @param string $name Tool name to execute
     * @param array $args Tool arguments
     * @param int $userid Moodle user id executing the tool
     * @param string $role Ignored for authorization; kept for signature compatibility.
     * @param array $courseidsowned Course ids the user manages
     * @param anonymizer|null $anonymizer Anonymiser of the conversation; the rows of a database query are anonymised
     *                                    by column name with it before they are formatted.
     * @param int $courseid Course stored with a confirmed held action: the role is computed in it; 0 outside a course.
     * @return string Tool execution result formatted for Telegram
     */
    public static function execute_tool(
        string $name,
        array $args,
        int $userid = 0,
        string $role = '',
        array $courseidsowned = [],
        ?anonymizer $anonymizer = null,
        int $courseid = 0
    ): string {
        return manager::tool_runner()->execute($name, $args, $userid, $role, $courseidsowned, $anonymizer, $courseid);
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
     * Fetch the confirmed Telegram link record for a chat id (see telegram_commands::find_link()).
     *
     * @param  string $chatid Telegram chat id.
     * @return \stdClass|null The confirmed link record, or null when none exists.
     */
    private static function get_link_by_chat_id(string $chatid): ?\stdClass {
        return manager::commands()->find_link($chatid);
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
        return manager::access_guard()->role_allowed($userid);
    }

    /**
     * Current role of the user and their courses grouped by the role held in each one.
     *
     * @see \local_jedomi\messaging\tool_runner::user_context()
     * @param  int   $userid
     * @return array  'role', 'course_ids_owned', 'course_ids_enrolled', 'courses_by_role'
     */
    private static function get_user_full_context(int $userid): array {
        return manager::tool_runner()->user_context($userid);
    }
}
