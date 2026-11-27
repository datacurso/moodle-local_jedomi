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

use local_jedomi\event\whatsapp_linked;
use local_jedomi\event\whatsapp_opted_in;
use local_jedomi\event\whatsapp_opted_out;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\local\user_language;
use local_jedomi\messaging\access_guard;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Message processor of the WhatsApp channel: link, consent, commands and the hand-off to the conversation.
 *
 * For each message: the link is found by BSUID and, when there is none, by phone (the link then keeps the new BSUID
 * and its chat); a phone the link did not have is filled and never cleared; the inbound time is recorded. A link code
 * (with or without the JEDOMI prefix; only with it when the sender is already linked) links the identity with the
 * consent given on the profile page. An identity that is not linked only gets the linking instructions. BAJA/STOP
 * and ALTA/START stop and resume the messages, DESVINCULAR removes the link with the data of the channel and AYUDA
 * shows the help. A student who stopped the messages gets no other reply. Any other text, NUEVO included, goes to the
 * conversation_handler (whatsapp_conversation_handler unless another one is registered). Link code attempts of an
 * identity that is not linked are limited by the message limit per minute of the channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chat_processor implements message_processor {
    /** @var string Name of the channel in its settings. */
    private const CHANNEL = 'whatsapp';

    /** @var string Table of the links. */
    private const LINKS = whatsapp_link_service::LINKS_TABLE;

    #[\Override]
    public function process(inbound $message): void {
        if ($message->type === inbound::TYPE_SYSTEM) {
            // Identity changes are handled by the inbox_processor; other system messages need no answer.
            return;
        }
        $guard = new access_guard(self::CHANNEL);
        $link = self::find_link($message);
        if ($link !== null && self::is_expired($link)) {
            whatsapp_link_service::unlink((int) $link->userid);
            $link = null;
        }

        $denial = $guard->channel_denial();
        if ($denial !== null) {
            $this->deny($message, $link, $denial);
            return;
        }
        if ($link !== null) {
            $link = self::touch($link, $message);
        }

        // Unlinked senders link with a bare code or JEDOMI <code>; a linked sender relinks with JEDOMI <code> only, so
        // a code-shaped word (as "TAREA5") stays plain text for the conversation, or silence after BAJA.
        $code = link_codes::parse($message->text, $link !== null);
        if ($code !== null) {
            if (self::code_attempts_limited($message)) {
                // Past the limit nothing is tried nor answered, so codes cannot be guessed quickly.
                return;
            }
            $this->link($message, $code, $guard);
            return;
        }
        if ($link === null) {
            $this->reply($message, null, 'whatsapp_msg_link_first');
            return;
        }

        $command = command::parse($message->text);
        $optedout = !empty($link->optedout);
        if ($command === command::OPT_OUT) {
            $this->opt_out($message, $link);
            return;
        }
        if ($command === command::OPT_IN) {
            $this->opt_in($message, $link);
            return;
        }
        if ($command === command::UNLINK) {
            // Any linked student can unlink, whatever the state of the account; the answer confirms the command.
            whatsapp_link_service::unlink((int) $link->userid);
            $this->reply($message, $link, 'whatsapp_msg_unlinked');
            return;
        }
        if ($optedout) {
            // No free replies after BAJA: only the BAJA and ALTA confirmations.
            return;
        }

        $userid = (int) $link->userid;
        $user = $guard->active_user($userid);
        if ($user === null) {
            $this->reply($message, $link, 'whatsapp_msg_account_inactive');
            return;
        }
        if (!$guard->role_allowed($userid)) {
            $this->reply($message, $link, 'whatsapp_msg_role_blocked');
            return;
        }
        if ($command === command::HELP) {
            $this->reply($message, $link, 'whatsapp_help');
            return;
        }
        self::conversation_handler()->handle($message, $link, $user);
    }

    /**
     * Answer while the channel may not serve: the message of the provider when Jedomi is disabled (as Telegram), and
     * the unavailable message to a linked student when the integration is off. A student who stopped the messages
     * gets nothing.
     *
     * @param inbound $message Message.
     * @param \stdClass|null $link Link of the sender.
     * @param array $denial Denial of the access guard.
     */
    private function deny(inbound $message, ?\stdClass $link, array $denial): void {
        if ($link !== null && !empty($link->optedout)) {
            return;
        }
        if ($denial['code'] !== access_guard::INTEGRATION_DISABLED) {
            $this->send($message, $link, (string) $denial['message']);
            return;
        }
        if ($link !== null) {
            $this->reply($message, $link, 'whatsapp_msg_unavailable');
        }
    }

    /**
     * Link the identity of the sender to the account of a code, with the consent recorded with the code.
     *
     * One identity per account and one account per identity: an earlier account of the BSUID and an earlier identity
     * of the account lose their link with the data of their chat. The code is used once.
     *
     * @param inbound $message Message with the code.
     * @param string $code Code.
     * @param access_guard $guard Access guard of the channel.
     */
    private function link(inbound $message, string $code, access_guard $guard): void {
        global $DB;
        if ($message->bsuid === '') {
            // The BSUID is the key of the link; the code stays valid for a message that carries it.
            $this->reply($message, null, 'whatsapp_msg_link_failed');
            return;
        }
        $grant = link_codes::resolve($code);
        $user = $grant !== null ? $guard->active_user($grant['userid']) : null;
        if ($user === null) {
            $this->reply($message, null, 'whatsapp_msg_expired_code');
            return;
        }
        $userid = (int) $user->id;
        /** @var consent $consent */
        $consent = $grant['consent'];
        $now = self::now();

        $transaction = $DB->start_delegated_transaction();
        try {
            $owner = $DB->get_field(self::LINKS, 'userid', ['bsuid' => $message->bsuid]);
            if ($owner !== false && (int) $owner !== $userid) {
                whatsapp_link_service::unlink((int) $owner);
            }
            $existing = $DB->get_record(self::LINKS, ['userid' => $userid]);
            if ($existing && $existing->bsuid !== $message->bsuid) {
                whatsapp_link_service::unlink($userid);
                $existing = false;
            }
            $waid = $message->waid ?? ($existing ? $existing->waid : null);
            $fields = [
                'bsuid' => $message->bsuid,
                'waid' => $waid,
                'phone' => $waid !== null && $waid !== '' ? '+' . $waid : null,
                'confirmed' => 1,
                'consentat' => $consent->acceptedat,
                'consentversion' => $consent->version,
                'consentcategories' => $consent->categories_field(),
                'optedout' => 0,
                'optoutat' => 0,
                'lastinbound' => $now,
                'lastused' => $now,
            ];
            if ($existing) {
                $DB->update_record(self::LINKS, (object) (['id' => $existing->id] + $fields));
            } else {
                $DB->insert_record(self::LINKS, (object) ($fields + ['userid' => $userid, 'lastproactivesent' => 0,
                    'timecreated' => $now]));
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        link_codes::invalidate($userid, $code);
        whatsapp_linked::create_for_user($userid)->trigger();
        $link = $DB->get_record(self::LINKS, ['userid' => $userid], '*', MUST_EXIST);
        $this->reply($message, $link, 'whatsapp_msg_linked', fullname($user));
    }

    /**
     * BAJA/STOP: stop the messages with one confirmation; a second BAJA gets nothing.
     *
     * @param inbound $message Message.
     * @param \stdClass $link Link of the sender.
     */
    private function opt_out(inbound $message, \stdClass $link): void {
        global $DB;
        if (!empty($link->optedout)) {
            return;
        }
        $DB->update_record(self::LINKS, (object) ['id' => $link->id, 'optedout' => 1, 'optoutat' => self::now()]);
        whatsapp_opted_out::create_for_user((int) $link->userid)->trigger();
        $this->reply($message, $link, 'whatsapp_msg_opted_out');
    }

    /**
     * ALTA/START: receive the messages again, with a confirmation.
     *
     * @param inbound $message Message.
     * @param \stdClass $link Link of the sender.
     */
    private function opt_in(inbound $message, \stdClass $link): void {
        global $DB;
        if (!empty($link->optedout)) {
            $DB->update_record(self::LINKS, (object) ['id' => $link->id, 'optedout' => 0]);
            whatsapp_opted_in::create_for_user((int) $link->userid)->trigger();
        }
        $this->reply($message, $link, 'whatsapp_msg_opted_in');
    }

    /**
     * Link of the sender: by BSUID first and, when there is none, by phone when exactly one link has it.
     *
     * @param inbound $message Message.
     * @return \stdClass|null
     */
    private static function find_link(inbound $message): ?\stdClass {
        global $DB;
        if ($message->bsuid !== '') {
            $link = $DB->get_record(self::LINKS, ['bsuid' => $message->bsuid]);
            if ($link) {
                return $link;
            }
        }
        if ($message->waid === null || $message->waid === '') {
            return null;
        }
        $links = $DB->get_records(self::LINKS, ['waid' => $message->waid], 'id', '*', 0, 2);
        return count($links) === 1 ? reset($links) : null;
    }

    /**
     * Whether a link was unused for more than the expiry of the links (since its last use, or since it was created
     * when never used), as the cleanup task decides.
     *
     * @param \stdClass $link Link.
     * @return bool
     */
    private static function is_expired(\stdClass $link): bool {
        $cutoff = self::now() - whatsapp_link_service::EXPIRY;
        return (int) $link->lastused < $cutoff && (int) $link->timecreated < $cutoff;
    }

    /**
     * Record the message on the link: a link found by phone keeps the new BSUID with its chat, a phone the link did
     * not have is filled (never cleared), and the inbound and use times are updated.
     *
     * @param \stdClass $link Link of the sender.
     * @param inbound $message Message.
     * @return \stdClass The link as stored now.
     */
    private static function touch(\stdClass $link, inbound $message): \stdClass {
        global $DB;
        if ($message->bsuid !== '' && $link->bsuid !== $message->bsuid) {
            whatsapp_link_service::change_identity($link->bsuid, $message->bsuid, $message->waid);
        }
        $now = self::now();
        $update = (object) ['id' => $link->id, 'lastinbound' => $now, 'lastused' => $now];
        if ($message->waid !== null && $message->waid !== '' && empty($link->waid)) {
            $update->waid = $message->waid;
            $update->phone = '+' . $message->waid;
        }
        $DB->update_record(self::LINKS, $update);
        return $DB->get_record(self::LINKS, ['id' => $link->id], '*', MUST_EXIST);
    }

    /**
     * Send a string of the plugin, in the language of the linked user (the site language when not linked).
     *
     * @param inbound $message Message answered.
     * @param \stdClass|null $link Link of the sender.
     * @param string $identifier String identifier.
     * @param mixed $a String parameter.
     */
    private function reply(inbound $message, ?\stdClass $link, string $identifier, $a = null): void {
        $userid = $link !== null ? (int) $link->userid : null;
        $text = user_language::run($userid, static fn(): string => get_string($identifier, 'local_jedomi', $a));
        $this->send($message, $link, $text);
    }

    /**
     * Send a text to the sender: to the phone when known, otherwise to the BSUID.
     *
     * @param inbound $message Message answered.
     * @param \stdClass|null $link Link of the sender.
     * @param string $text Text.
     */
    private function send(inbound $message, ?\stdClass $link, string $text): void {
        $waid = $link !== null && !empty($link->waid) ? (string) $link->waid : $message->waid;
        $bsuid = $link !== null ? (string) $link->bsuid : $message->bsuid;
        try {
            $to = recipient_ref::from_link($waid, $bsuid);
        } catch (\invalid_parameter_exception $e) {
            return;
        }
        self::replier()->send($to, $text);
    }

    /**
     * Replier registered in the container, or the Cloud API one.
     *
     * @return replier
     */
    private static function replier(): replier {
        $container = \core\di::get_container();
        return $container->has(replier::class) ? $container->get(replier::class) : new cloud_api_replier();
    }

    /**
     * Whether an identity, linked or not, sent more link codes than the message limit per minute of the channel
     * allows. The limit is kept under the chat key of the BSUID; a sender without BSUID cannot link and is not
     * counted. When the limit cannot be checked, the attempt is refused.
     *
     * @param inbound $message Message with a code.
     * @return bool
     */
    private static function code_attempts_limited(inbound $message): bool {
        if ($message->bsuid === '') {
            return false;
        }
        $limited = (new whatsapp_channel())->rate_limiter()->check_and_record(
            whatsapp_link_service::chatkey($message->bsuid)
        );
        return $limited !== false;
    }

    /**
     * Conversation handler registered in the container, or the WhatsApp conversation.
     *
     * @return conversation_handler
     */
    private static function conversation_handler(): conversation_handler {
        $container = \core\di::get_container();
        return $container->has(conversation_handler::class)
            ? $container->get(conversation_handler::class)
            : new whatsapp_conversation_handler();
    }

    /**
     * Current time of the Moodle clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
