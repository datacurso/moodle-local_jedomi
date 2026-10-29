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

namespace local_jedomi\local\service;

/**
 * Moodle internal messages sent from the web chat (send_messages tool).
 *
 * The tool is offered only in the web chat, to users with moodle/site:sendmessage, and it
 * always waits for the user's confirmation ({@see pending_action_service}).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_service {
    /** @var string Tool name. */
    public const TOOL = 'send_messages';

    /**
     * Whether a user may send messages, and so is offered the tool.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function can_send(int $userid): bool {
        return has_capability('moodle/site:sendmessage', \context_system::instance(), $userid);
    }

    /**
     * Tool definition in OpenAI function-calling format.
     *
     * @return array
     */
    public static function tool_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::TOOL,
                'description' => get_string('tool_send_messages_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'user_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => get_string('tool_send_messages_user_ids', 'local_jedomi'),
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => get_string('tool_send_messages_message', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['user_ids', 'message'],
                ],
            ],
        ];
    }

    /**
     * Distinct positive user ids from the tool argument.
     *
     * @param mixed $userids Value the model sent as user_ids.
     * @return int[]
     */
    public static function recipients($userids): array {
        if (!is_array($userids)) {
            return [];
        }
        $ids = [];
        foreach ($userids as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Send a Moodle internal message to each recipient the sender may contact.
     *
     * A recipient that does not exist, is deleted, is the sender, or cannot be contacted by the
     * sender (messaging preferences, blocked, no capability) counts as failed.
     *
     * @param int $senderid User who sends.
     * @param mixed $userids Recipients as sent by the model.
     * @param string $message Plain text message.
     * @return array{sent: int, failed: int}
     */
    public static function send(int $senderid, $userids, string $message): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/message/lib.php');

        $recipients = self::recipients($userids);
        $message = trim($message);
        $sender = $DB->get_record('user', ['id' => $senderid, 'deleted' => 0]);
        if ($message === '' || !$sender || !self::can_send($senderid)) {
            return ['sent' => 0, 'failed' => count($recipients)];
        }

        $sent = 0;
        foreach ($recipients as $recipientid) {
            $recipient = $DB->get_record('user', ['id' => $recipientid, 'deleted' => 0]);
            if (!$recipient || $recipientid === $senderid
                    || !\core_message\api::can_send_message($recipientid, $senderid)) {
                continue;
            }
            try {
                if (message_post_message($sender, $recipient, $message, FORMAT_PLAIN)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                debugging('Jedomi send_messages: message to user ' . $recipientid . ' not sent', DEBUG_DEVELOPER);
            }
        }
        return ['sent' => $sent, 'failed' => count($recipients) - $sent];
    }
}
