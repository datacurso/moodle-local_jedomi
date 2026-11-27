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

namespace local_jedomi\whatsapp;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\local\user_language;
use local_jedomi\messaging\channel;
use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\router;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Conversation of the WhatsApp channel: the default conversation_handler of the chat_processor.
 *
 * A message of a linked student who receives messages is counted, then, when the student accepted the chat category,
 * handled under the lock of the chat of the BSUID (one message per chat at a time), in the language of the student.
 * A student at the monthly limit gets its single notice and the AI is not called (monthly_cap). Replies held while the
 * window was closed are delivered first, and that message only opens the window, as the reply_ready template asks.
 * Otherwise SÍ or NO answers the newest held action of the chat; anything else goes through the router of the core with
 * the WhatsApp channel (message limit per minute, confirmation word, NUEVO and the conversation engine). A voice note,
 * photo or document reaches the router with its media and caption, and the media pipeline of the core reads it (see
 * whatsapp_media_source); a video or a sticker has no media, so the student is asked for text.
 *
 * WhatsApp sends one media per message: several photos sent together arrive as separate messages, each one handled
 * (and counted by the message limit) on its own, one at a time under the chat lock.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_conversation_handler implements conversation_handler {
    #[\Override]
    public function handle(inbound $message, \stdClass $link, \stdClass $user): void {
        $userid = (int) $link->userid;
        usage_meter::add($userid, usage_meter::INBOUND);
        if ($message->media !== null) {
            usage_meter::add($userid, usage_meter::MEDIA);
        }

        $channel = new whatsapp_channel();
        $chatkey = whatsapp_link_service::chatkey((string) $link->bsuid);
        user_language::run($userid, static function () use ($channel, $chatkey, $message, $link, $userid): void {
            if (!consent::accepted($link, consent::CHAT)) {
                $channel->transport()->send_text($chatkey, get_string('whatsapp_msg_chat_not_accepted', 'local_jedomi'));
                return;
            }
            if (!monthly_cap::allows($userid)) {
                monthly_cap::notify($link);
                return;
            }
            $lock = self::acquire_chat_lock($chatkey);
            if ($lock === false) {
                \local_jedomi_log('WhatsApp: another process is handling the chat', [
                    'chat' => \local_jedomi_log_pseudonym('wa', (string) $link->bsuid),
                ]);
                $channel->transport()->send_text($chatkey, $channel->message(channel::MESSAGE_STILL_PROCESSING));
                return;
            }
            try {
                if (!self::deliver_held($channel, $chatkey, $link)) {
                    self::route($channel, $chatkey, $message);
                }
            } finally {
                if ($lock !== null) {
                    $lock->release();
                }
            }
        });
    }

    /**
     * Take the lock that keeps two workers from handling messages of the same chat at once.
     *
     * @param string $chatkey Chat key (wa_ plus the BSUID).
     * @return \core\lock\lock|false|null The lock, false when another process holds it, null when no lock factory is
     *     available (the message is then handled without the lock).
     */
    public static function acquire_chat_lock(string $chatkey) {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_jedomi_whatsapp_chat');
            // The BSUID is personal data: the lock is keyed by its hash.
            return $factory->get_lock('chat_' . sha1($chatkey), 0) ?: false;
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp: chat lock not available (' . get_class($e) . '), proceeding without it');
            return null;
        }
    }

    /**
     * Send the replies held while the window of the student was closed, in order, and remove the ones sent.
     *
     * Delivery stops at the first reply that is not sent (an error of Meta, the monthly limit): that reply and the
     * following ones stay held for the next message. The message that found held replies is not routed, whether they
     * were all sent or not, so the student gets them before any new answer.
     *
     * @param whatsapp_channel $channel Channel.
     * @param string $chatkey Chat key.
     * @param \stdClass $link Link of the student.
     * @return bool Whether there were held replies.
     */
    private static function deliver_held(whatsapp_channel $channel, string $chatkey, \stdClass $link): bool {
        $texts = held_replies::peek($link);
        if ($texts === []) {
            return false;
        }
        $sent = 0;
        foreach ($texts as $text) {
            if (!$channel->transport()->send_text($chatkey, $text)) {
                break;
            }
            $sent++;
        }
        held_replies::acknowledge($link, $texts, $sent);
        if ($sent < count($texts)) {
            \local_jedomi_log('WhatsApp: held replies not all sent, the rest waits for the next message', [
                'chat' => \local_jedomi_log_pseudonym('wa', (string) $link->bsuid),
                'sent' => $sent,
                'held' => count($texts),
            ]);
        }
        return true;
    }

    /**
     * Answer a held action with SÍ or NO, or hand the message to the router.
     *
     * @param whatsapp_channel $channel Channel.
     * @param string $chatkey Chat key.
     * @param inbound $message Message.
     */
    private static function route(whatsapp_channel $channel, string $chatkey, inbound $message): void {
        $confirmations = $channel->confirmation_service();
        $ui = $confirmations->ui();
        $mode = $ui instanceof whatsapp_confirmation_ui ? $ui->text_reply_mode($message->text) : null;
        if ($mode !== null && $message->media === null) {
            $waiting = $confirmations->waiting($chatkey);
            if ($waiting !== null) {
                $channel->transport()->send_text($chatkey, $confirmations->resolve($chatkey, $waiting, $mode));
                return;
            }
        }
        (new router($channel))->route(self::to_message($chatkey, $message));
    }

    /**
     * The message for the core: a text message with its text, a media message with its media and caption.
     *
     * @param string $chatkey Chat key.
     * @param inbound $message Message.
     * @return inbound_message
     */
    private static function to_message(string $chatkey, inbound $message): inbound_message {
        return new inbound_message(
            inbound::CHANNEL,
            $chatkey,
            $message->wamid,
            $message->text,
            $message->replyid,
            $message->media,
            $message->media !== null ? $message->caption : '',
            true,
            '',
            $message->wamid
        );
    }
}
