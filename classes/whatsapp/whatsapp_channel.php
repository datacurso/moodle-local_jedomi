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

use local_jedomi\local\service\telegram_pin_service;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\access_guard;
use local_jedomi\messaging\channel;
use local_jedomi\messaging\channel_limits;
use local_jedomi\messaging\chat_commands;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\continuation;
use local_jedomi\messaging\conversation_engine;
use local_jedomi\messaging\conversation_store;
use local_jedomi\messaging\media_source;
use local_jedomi\messaging\no_pin_policy;
use local_jedomi\messaging\rate_limiter;
use local_jedomi\messaging\tool_runner;
use local_jedomi\messaging\transport;

/**
 * The WhatsApp channel for the channel-neutral core, over the Cloud API.
 *
 * The chat key is wa_ plus the BSUID of the student. Conversations, message limits and held actions are kept in the
 * WhatsApp tables; answers go through whatsapp_transport (phone or BSUID, 24-hour window, parts); held actions are
 * confirmed by text; media is not read yet; long questions continue with a signed self-ping. WhatsApp cannot ask for
 * the PIN, so a write of a user who needs one is refused with a note to use the web chat.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_channel implements channel {
    /** @var string Name of the channel. */
    public const NAME = 'whatsapp';

    /** @var int Seconds between typing indicators while the AI works; WhatsApp hides it after about 25 seconds. */
    public const TYPING_INTERVAL = 20;

    /** @var int Seconds a typing indicator request may take. */
    public const TYPING_TIMEOUT = 2;

    /** @var string Prefix of a chat key. */
    private const CHATKEY_PREFIX = 'wa_';

    /** @var array<string, string> String of each message of the core. */
    private const MESSAGES = [
        channel::MESSAGE_STILL_PROCESSING => 'whatsapp_msg_still_processing',
        channel::MESSAGE_KEEPALIVE => 'whatsapp_msg_keepalive',
        channel::MESSAGE_DONE => 'whatsapp_msg_done',
        channel::MESSAGE_STATE_SAVE_FAILED => 'whatsapp_msg_state_save_failed',
        channel::MESSAGE_MAX_ITERATIONS => 'whatsapp_msg_max_iterations',
        channel::MESSAGE_NO_ANSWER => 'whatsapp_msg_no_answer',
        channel::MESSAGE_QUESTION_TIME_LIMIT => 'error_question_time_limit',
        channel::MESSAGE_EMPTY_ANSWER => 'error_empty_answer',
        channel::MESSAGE_TOOL_REPEATED => 'error_tool_repeated',
        channel::MESSAGE_RATE_CHECK_FAILED => 'whatsapp_msg_rate_check_failed',
        channel::MESSAGE_RATE_LIMITED => 'whatsapp_msg_rate_limited',
        channel::MESSAGE_PRIVATE_ONLY => 'whatsapp_msg_text_only',
        channel::MESSAGE_ACCOUNT_INACTIVE => 'whatsapp_msg_account_inactive',
        channel::MESSAGE_ROLE_BLOCKED => 'whatsapp_msg_role_blocked',
        channel::MESSAGE_AUDIO_DISABLED => 'whatsapp_msg_text_only',
        channel::MESSAGE_HEARD => 'whatsapp_msg_heard',
        channel::MESSAGE_FILES_DISABLED => 'whatsapp_msg_text_only',
        channel::MESSAGE_FILE_UNSUPPORTED => 'whatsapp_msg_text_only',
        channel::MESSAGE_TEXT_ONLY => 'whatsapp_msg_text_only',
    ];

    /**
     * BSUID of a chat key.
     *
     * @param string $chatkey Chat key.
     * @return string|null Null when it is not a WhatsApp chat key.
     */
    public static function bsuid_of(string $chatkey): ?string {
        if (!str_starts_with($chatkey, self::CHATKEY_PREFIX) || strlen($chatkey) === strlen(self::CHATKEY_PREFIX)) {
            return null;
        }
        return substr($chatkey, strlen(self::CHATKEY_PREFIX));
    }

    #[\Override]
    public function name(): string {
        return self::NAME;
    }

    #[\Override]
    public function label(): string {
        return 'WhatsApp';
    }

    #[\Override]
    public function store(): conversation_store {
        return new conversation_store(whatsapp_link_service::SESSIONS_TABLE, 'whatsapp_max_turns');
    }

    #[\Override]
    public function access_guard(): access_guard {
        return new access_guard(self::NAME);
    }

    #[\Override]
    public function rate_limiter(): rate_limiter {
        return new rate_limiter(
            whatsapp_link_service::RATE_TABLE,
            'whatsapp_rate_limit',
            'whatsapp_cooldown',
            '[WHATSAPP RATE]'
        );
    }

    #[\Override]
    public function commands(): chat_commands {
        return new whatsapp_commands();
    }

    #[\Override]
    public function tool_runner(): tool_runner {
        return new tool_runner(self::NAME, $this->label(), whatsapp_limits::SUPPORTED_TOOLS);
    }

    #[\Override]
    public function confirmation_service(): confirmation_service {
        return new confirmation_service(
            whatsapp_link_service::PENDING_TABLE,
            $this->label(),
            new whatsapp_confirmation_ui($this->transport()),
            $this->access_guard(),
            $this->tool_runner(),
            fn(string $chatkey): ?int => $this->linked_userid($chatkey),
            // Who needs a PIN is the rule of the user in every channel; WhatsApp cannot ask for it (version 1).
            new no_pin_policy(static fn(int $userid): bool => telegram_pin_service::is_required($userid))
        );
    }

    #[\Override]
    public function transport(): transport {
        return new whatsapp_transport(new whatsapp_limits());
    }

    #[\Override]
    public function media_source(): media_source {
        return new whatsapp_media_source();
    }

    #[\Override]
    public function continuation(): continuation {
        return \core\di::get(whatsapp_continuation::class);
    }

    #[\Override]
    public function limits(): channel_limits {
        return new whatsapp_limits();
    }

    #[\Override]
    public function ai_timeout(): int {
        $timeout = (int) get_config('local_jedomi', 'whatsapp_gateway_timeout');
        if ($timeout < 5) {
            return conversation_engine::MAX_AI_TIMEOUT;
        }
        return min($timeout, conversation_engine::MAX_AI_TIMEOUT);
    }

    #[\Override]
    public function activity_interval(): int {
        return self::TYPING_INTERVAL;
    }

    #[\Override]
    public function activity_timeout(): int {
        return self::TYPING_TIMEOUT;
    }

    #[\Override]
    public function message(string $key, mixed $a = null): string {
        if (!isset(self::MESSAGES[$key])) {
            throw new \coding_exception('Unknown message of the WhatsApp channel: ' . $key);
        }
        return get_string(self::MESSAGES[$key], 'local_jedomi', $a);
    }

    #[\Override]
    public function linked_userid(string $chatkey): ?int {
        global $DB;
        $bsuid = self::bsuid_of($chatkey);
        if ($bsuid === null) {
            return null;
        }
        $link = $DB->get_record(
            whatsapp_link_service::LINKS_TABLE,
            ['bsuid' => $bsuid, 'confirmed' => 1],
            'id, userid, optedout'
        );
        return $link && empty($link->optedout) ? (int) $link->userid : null;
    }

    #[\Override]
    public function serves(string $chatkey, int $userid): bool {
        $guard = $this->access_guard();
        return $this->linked_userid($chatkey) === $userid
            && $guard->channel_denial() === null
            && $guard->served_user($userid) !== null;
    }
}
