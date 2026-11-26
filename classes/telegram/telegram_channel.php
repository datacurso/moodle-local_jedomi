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
 * Telegram channel: the pieces of the Telegram bot for the conversation engine.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\access_guard;
use local_jedomi\messaging\channel;
use local_jedomi\messaging\channel_limits;
use local_jedomi\messaging\chat_commands;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\continuation;
use local_jedomi\messaging\conversation_engine;
use local_jedomi\messaging\conversation_store;
use local_jedomi\messaging\media_source;
use local_jedomi\messaging\rate_limiter;
use local_jedomi\messaging\tool_runner;
use local_jedomi\messaging\transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * The Telegram bot as a channel: the session table, the telegram_* settings and strings, the slash commands and inline
 * keyboards, the Bot API transport and file downloads, the self-ping and the confirmed links of
 * local_jedomi_telegram_links. Each piece comes from its factory in manager.
 */
class telegram_channel implements channel {
    /** @var string[] Telegram string of each message of the engine; the errors that end a question are the shared ones. */
    private const MESSAGES = [
        channel::MESSAGE_STILL_PROCESSING => 'telegram_msg_still_processing',
        channel::MESSAGE_KEEPALIVE => 'telegram_msg_keepalive',
        channel::MESSAGE_DONE => 'telegram_msg_done',
        channel::MESSAGE_STATE_SAVE_FAILED => 'telegram_msg_state_save_failed',
        channel::MESSAGE_MAX_ITERATIONS => 'telegram_msg_max_iterations',
        channel::MESSAGE_NO_ANSWER => 'error_telegram_e006',
        channel::MESSAGE_QUESTION_TIME_LIMIT => 'error_question_time_limit',
        channel::MESSAGE_EMPTY_ANSWER => 'error_empty_answer',
        channel::MESSAGE_TOOL_REPEATED => 'error_tool_repeated',
        channel::MESSAGE_RATE_CHECK_FAILED => 'telegram_msg_rate_check_failed',
        channel::MESSAGE_RATE_LIMITED => 'telegram_msg_rate_limited',
        channel::MESSAGE_PRIVATE_ONLY => 'telegram_msg_private_only',
        channel::MESSAGE_ACCOUNT_INACTIVE => 'telegram_msg_account_inactive',
        channel::MESSAGE_ROLE_BLOCKED => 'telegram_msg_role_blocked',
        channel::MESSAGE_AUDIO_DISABLED => 'telegram_msg_audio_disabled',
        channel::MESSAGE_HEARD => 'telegram_msg_heard',
        channel::MESSAGE_FILES_DISABLED => 'telegram_msg_files_disabled',
        channel::MESSAGE_FILE_UNSUPPORTED => 'telegram_msg_file_unsupported',
        channel::MESSAGE_TEXT_ONLY => 'telegram_msg_text_only',
    ];

    #[\Override]
    public function name(): string {
        return 'telegram';
    }

    #[\Override]
    public function label(): string {
        return 'Telegram';
    }

    #[\Override]
    public function store(): conversation_store {
        return session::store();
    }

    #[\Override]
    public function access_guard(): access_guard {
        return manager::access_guard();
    }

    #[\Override]
    public function rate_limiter(): rate_limiter {
        return manager::rate_limiter();
    }

    #[\Override]
    public function commands(): chat_commands {
        return manager::commands();
    }

    #[\Override]
    public function tool_runner(): tool_runner {
        return manager::tool_runner();
    }

    #[\Override]
    public function confirmation_service(): confirmation_service {
        return manager::confirmation_service();
    }

    #[\Override]
    public function transport(): transport {
        return manager::transport();
    }

    #[\Override]
    public function media_source(): media_source {
        return manager::media_source();
    }

    #[\Override]
    public function continuation(): continuation {
        return manager::continuation();
    }

    #[\Override]
    public function limits(): channel_limits {
        return manager::channel_limits();
    }

    /**
     * Total timeout of each AI call, from the Telegram timeout setting (5 s to the longest call a worker fits, the
     * default). Values saved by older versions above that are capped here.
     *
     * @return int
     */
    #[\Override]
    public function ai_timeout(): int {
        $timeout = (int) get_config('local_jedomi', 'telegram_gateway_timeout');
        if ($timeout < 5) {
            return conversation_engine::MAX_AI_TIMEOUT;
        }
        return min($timeout, conversation_engine::MAX_AI_TIMEOUT);
    }

    /**
     * Telegram shows each "typing" status for about 5 seconds.
     *
     * @return int
     */
    #[\Override]
    public function activity_interval(): int {
        return webhook_handler::TYPING_INTERVAL;
    }

    #[\Override]
    public function activity_timeout(): int {
        return webhook_handler::TYPING_TIMEOUT;
    }

    #[\Override]
    public function message(string $key, mixed $a = null): string {
        if (!isset(self::MESSAGES[$key])) {
            throw new \coding_exception('Unknown message of the Telegram channel: ' . $key);
        }
        return get_string(self::MESSAGES[$key], 'local_jedomi', $a);
    }

    #[\Override]
    public function linked_userid(string $chatkey): ?int {
        global $DB;
        $userid = $DB->get_field('local_jedomi_telegram_links', 'userid', ['chatid' => $chatkey, 'confirmed' => 1]);
        return $userid === false ? null : (int) $userid;
    }

    #[\Override]
    public function serves(string $chatkey, int $userid): bool {
        return $this->linked_userid($chatkey) === $userid && \local_jedomi_telegram_recipient($userid) !== null;
    }
}
