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
 * Telegram commands, link and replies of the router.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\local\service\telegram_link_service;
use local_jedomi\messaging\chat_commands;
use local_jedomi\messaging\inbound_message;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * The slash commands of the Telegram bot (/help, /start CODE, /unlink, /new and their aliases), its inline keyboards
 * and the links of local_jedomi_telegram_links with their one-time codes. The Telegram PIN is telegram_pin_policy.
 */
class telegram_commands implements chat_commands {
    #[\Override]
    public function is_help(string $text): bool {
        return in_array(strtolower(trim($text)), webhook_handler::HELP_COMMANDS, true);
    }

    #[\Override]
    public function help(string $chatkey): void {
        api_client::instance()->send_message($chatkey, self::help_text(), self::linked_keyboard());
    }

    #[\Override]
    public function find_link(string $chatkey): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_links', ['chatid' => $chatkey, 'confirmed' => 1]);
        return $record ?: null;
    }

    /**
     * Remove a link unused for more than 30 days (Decision 7), or record that it is used now.
     *
     * @param \stdClass $link Link of the chat.
     * @return bool True when the link expired and was removed with the data of its chat.
     */
    #[\Override]
    public function expire_or_touch(\stdClass $link): bool {
        if (telegram_link_service::is_expired($link)) {
            telegram_link_service::unlink((int) $link->userid);
            return true;
        }
        telegram_link_service::touch($link);
        return false;
    }

    #[\Override]
    public function link_expired(string $chatkey): void {
        api_client::instance()->send_message(
            $chatkey,
            get_string('telegram_msg_link_expired', 'local_jedomi'),
            self::unlinked_keyboard()
        );
    }

    #[\Override]
    public function link_first(string $chatkey): void {
        api_client::instance()->send_message(
            $chatkey,
            get_string('telegram_msg_link_first', 'local_jedomi'),
            self::unlinked_keyboard()
        );
    }

    /**
     * "/start CODE".
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    #[\Override]
    public function is_link_command(string $text): bool {
        return (bool) preg_match('/^\/start\s+\S+/', $text);
    }

    /**
     * "/start" with or without a code as it is, or a bare link code as "/start CODE".
     *
     * @param string $text Trimmed text of the message.
     * @return string|null
     */
    #[\Override]
    public function link_request(string $text): ?string {
        if (strpos($text, '/start') === 0) {
            return $text;
        }
        if (manager::is_link_code_format(trim($text))) {
            return '/start ' . $text;
        }
        return null;
    }

    /**
     * Handle a "/start CODE" link request: the chat is linked to the account that generated the code.
     *
     * @param inbound_message $message Message that sent the request.
     * @param string $request Full command text including the link code.
     * @return void
     */
    #[\Override]
    public function link(inbound_message $message, string $request): void {
        global $DB;

        $chatkey = $message->chatkey;
        $parts = explode(' ', $request, 2);
        $code  = strtoupper(trim($parts[1] ?? ''));
        $client = api_client::instance();

        if (empty($code)) {
            $client->send_message($chatkey, get_string('telegram_msg_provide_code', 'local_jedomi'));
            return;
        }

        if (strlen($code) < 4 || strlen($code) > 8 || !preg_match('/^[A-Z0-9]+$/', $code)) {
            $client->send_message($chatkey, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Resolve code → userid using the APPLICATION-mode cache.
        // (MODE_SESSION cache would not work here — no user session in webhook context.)
        $userid = manager::resolve_link_code($code);
        if ($userid === null) {
            $client->send_message($chatkey, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // Double-validate: the forward mapping must also match.
        if (!manager::validate_link_code($userid, $code)) {
            $client->send_message($chatkey, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        $user = manager::access_guard()->active_user($userid);
        if (!$user) {
            $client->send_message($chatkey, get_string('telegram_msg_expired_code', 'local_jedomi'));
            return;
        }

        // A chat linked to another account moves to this one: the earlier account loses the link and its data.
        foreach ($DB->get_fieldset_select('local_jedomi_telegram_links', 'userid', 'chatid = ?', [$chatkey]) as $previous) {
            if ((int) $previous !== $userid) {
                telegram_link_service::unlink((int) $previous);
            }
        }

        // Upsert the link (delete any existing link for this user first).
        $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
        $DB->insert_record('local_jedomi_telegram_links', (object)[
            'userid'             => $userid,
            'chatid'             => $chatkey,
            'username'           => $message->username,
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
        $client->send_message($chatkey, $welcome);
    }

    /**
     * "/unlink" or "/desvincular", in any letter case.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    #[\Override]
    public function is_unlink(string $text): bool {
        return strcasecmp($text, '/desvincular') === 0 || strcasecmp($text, '/unlink') === 0;
    }

    #[\Override]
    public function unlink(string $chatkey, \stdClass $link): void {
        // The link, the conversation, the task in progress, the limit and the held actions are removed.
        telegram_link_service::unlink((int) $link->userid);
        api_client::instance()->send_message($chatkey, get_string('telegram_msg_unlinked', 'local_jedomi'));
    }

    /**
     * "/new", "/nuevo" or "/clear", in any letter case.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    #[\Override]
    public function is_new_conversation(string $text): bool {
        return strcasecmp($text, '/new') === 0 || strcasecmp($text, '/nuevo') === 0 || strcasecmp($text, '/clear') === 0;
    }

    #[\Override]
    public function new_conversation(string $chatkey, int $userid): string {
        global $DB;
        \local_jedomi_log('[TELEGRAM ENTRY] /new detected, starting a new conversation_engine session user='
            . \local_jedomi_log_pseudonym('user', $userid));
        self::reset_conversation($chatkey);
        // Get user's name so the model greets them personally.
        $firstname = (string) $DB->get_field('user', 'firstname', ['id' => $userid]);
        return get_string('telegram_new_conversation_greeting', 'local_jedomi', $firstname);
    }

    /**
     * Start a new conversation: the history, the cached context and the task in progress are reset, and a new session
     * generation stops any continuation of the previous task.
     *
     * Used by the /new command and the New conversation button.
     *
     * @param string $chatkey Telegram chat id.
     * @return void
     */
    public static function reset_conversation(string $chatkey): void {
        session::clear($chatkey);
        session::clear_context($chatkey);
        session::set_context('tool_loop_' . $chatkey, ['session_gen' => time()]);
    }

    /**
     * Help of the bot: its commands.
     *
     * @return string
     */
    public static function help_text(): string {
        return get_string('telegram_help_heading', 'local_jedomi') . "\n\n"
            . get_string('telegram_help_start', 'local_jedomi') . "\n"
            . get_string('telegram_help_help', 'local_jedomi') . "\n"
            . get_string('telegram_help_new', 'local_jedomi') . "\n"
            . get_string('telegram_help_unlink', 'local_jedomi') . "\n\n"
            . get_string('telegram_help_footer', 'local_jedomi');
    }

    /**
     * Inline keyboard for a linked user (new, help, unlink).
     *
     * @return array Telegram reply keyboard markup.
     */
    public static function linked_keyboard(): array {
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
     * Inline keyboard for an unlinked user (how to link, help).
     *
     * @return array Telegram reply keyboard markup.
     */
    public static function unlinked_keyboard(): array {
        return [
            'inline_keyboard' => [[
                ['text' => get_string('telegram_keyboard_howto_link', 'local_jedomi'),
                    'callback_data' => 'cmd:howtolin'],
                ['text' => get_string('telegram_keyboard_help', 'local_jedomi'),
                    'callback_data' => 'cmd:help'],
            ]],
        ];
    }
}
