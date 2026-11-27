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
 * Router of the inbound messages of the messaging channels.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

use local_jedomi\local\service\pending_action_service;

/**
 * Takes a typed message of a chat through its steps, in this order, whatever the channel: help, message limit, private
 * chat, link (expired link, link codes), unlink, access (active account, allowed role), typed PIN or confirmation word,
 * new conversation, media and the conversation with the AI. Each step that answers ends the message.
 *
 * The words, buttons and records of the help, the link and a new conversation come from the commands of the channel
 * (see chat_commands); the PIN from the pin_policy of its confirmation service; the texts from its messages. A channel
 * whose PIN is not available never reads a text as a PIN, and the confirmation word of a user who needs a PIN only gets
 * the PIN prompt of the channel: such a write is never run from here, whatever the channel.
 */
final class router {
    /**
     * Constructor.
     *
     * @param channel $channel Channel of the message.
     */
    public function __construct(
        /** @var channel Channel of the message. */
        private readonly channel $channel,
    ) {
    }

    /**
     * Route a typed message of a chat, once the channel holds the lock of the chat.
     *
     * @param inbound_message $message Message, already validated by the channel.
     * @return void
     */
    public function route(inbound_message $message): void {
        global $USER;

        $chatkey = $message->chatkey;
        $text = $message->text;
        $commands = $this->channel->commands();
        $transport = $this->channel->transport();

        // The help always answers, even when the chat is rate limited or not linked.
        if ($commands->is_help($text)) {
            $commands->help($chatkey);
            return;
        }

        // Check and record the message limit atomically.
        $limiter = $this->channel->rate_limiter();
        $limited = $limiter->check_and_record($chatkey);
        if ($limited === null) {
            // The limit could not be checked: the message is not processed, and the user knows it must be sent again.
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_RATE_CHECK_FAILED));
            return;
        }
        if ($limited) {
            $minutes = $limiter->cooldown_minutes();
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_RATE_LIMITED, ['minutes' => $minutes]));
            return;
        }

        if (!$message->isprivate) {
            // In a group every member would act with the account that linked it: groups are not served.
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_PRIVATE_ONLY));
            return;
        }

        $link = $commands->find_link($chatkey);
        if ($link && $commands->expire_or_touch($link)) {
            $link = null;
            // A new link code is handled in the same message (REL-003); anything else is told to link again.
            if (!$commands->is_link_command($text)) {
                $commands->link_expired($chatkey);
                return;
            }
        }
        if (!$link) {
            // A chat that is not linked can only link itself, with a link command or a bare link code.
            $request = $commands->link_request($text);
            if ($request !== null) {
                $commands->link($message, $request);
                return;
            }
            $commands->link_first($chatkey);
            return;
        }

        // A linked chat can move to another account with a new link code.
        if ($commands->is_link_command($text)) {
            $commands->link($message, $text);
            return;
        }

        // Any linked user can unlink, whatever the state of the account.
        if ($commands->is_unlink($text)) {
            $commands->unlink($chatkey, $link);
            return;
        }

        // The account must still be active and able to log in; it is checked on every message.
        $userid = (int) $link->userid;
        $guard = $this->channel->access_guard();
        $user = $guard->active_user($userid);
        if ($user === null) {
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_ACCOUNT_INACTIVE));
            return;
        }
        if (!$guard->role_allowed($userid)) {
            $transport->send_text($chatkey, $this->channel->message(channel::MESSAGE_ROLE_BLOCKED));
            return;
        }

        // The chat acts as the linked Moodle user for the whole request, so the Moodle permission checks (validate_context,
        // require_login, require_capability, has_capability) of the AI loop and of every tool work as on the web.
        $USER = $user;

        // A user who needs a PIN confirms with it and the confirmation word does not confirm (Decision 7), except in the
        // 15 minutes after a right PIN, when actions are confirmed as without the PIN (JEDOMI-SEC-016). A number is a
        // PIN only while an action waits in the chat; then it never reaches the AI. Otherwise it is a normal message
        // (RES-003).
        $confirmations = $this->channel->confirmation_service();
        $pins = $confirmations->pins();
        $pinrequired = $pins->is_required($userid);
        $waiting = $pinrequired ? $confirmations->waiting($chatkey) : null;
        if ($waiting !== null && $pins->available() && $pins->is_pin($text)) {
            // The PIN is still checked when its message cannot be removed: the user is not blocked by it.
            $pins->hide($message);
            $transport->send_text($chatkey, $confirmations->confirm_with_pin($chatkey, $userid, $text, $waiting));
            return;
        }
        if ($pinrequired && !$pins->in_window($userid)) {
            if ($waiting !== null && pending_action_service::is_confirm_keyword($text)) {
                $transport->send_text($chatkey, $confirmations->ui()->pin_prompt());
                return;
            }
        } else if (pending_action_service::is_confirm_keyword($text)) {
            // The confirmation word confirms the newest critical action of the chat that waits for it.
            $critical = $confirmations->waiting_critical($chatkey);
            if ($critical !== null) {
                $transport->send_text(
                    $chatkey,
                    $confirmations->resolve($chatkey, $critical, confirmation_service::MODE_KEYWORD)
                );
                return;
            }
        }

        if ($commands->is_new_conversation($text)) {
            $greeting = $commands->new_conversation($chatkey, $userid);
            (new conversation_engine($this->channel))->run($userid, $chatkey, $greeting);
            return;
        }

        // Photos, documents and audio become text first; nothing without text reaches the AI.
        $anonymizermap = [];
        $text = (new media_pipeline($this->channel))->text($message, $userid, $anonymizermap);
        if ($text === null) {
            return;
        }

        (new conversation_engine($this->channel))->run($userid, $chatkey, $text, false, $anonymizermap);
    }
}
