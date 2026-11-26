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
 * Telegram transport: messages, "typing" and the final answer through the Bot API client.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\transport;

/**
 * Sends to a Telegram chat with the client of the moment (api_client::instance(), replaceable in tests). The text is
 * Telegram HTML; the client splits long messages.
 */
class telegram_transport implements transport {
    /** Characters of a formatted answer sent as a message; a longer one is sent as a text file. */
    private const ANSWER_MESSAGE_LENGTH = 3800;

    /** Characters of the first line of an answer kept in the caption of its text file. */
    private const CAPTION_LENGTH = 200;

    #[\Override]
    public function send_text(string $chatkey, string $text): bool {
        return api_client::instance()->send_message($chatkey, $text);
    }

    #[\Override]
    public function send_activity(string $chatkey, ?int $timeout = null): bool {
        return api_client::instance()->send_chat_action($chatkey, 'typing', $timeout);
    }

    #[\Override]
    public function format_answer(string $markdown): string {
        // Markdown and HTML of the answer become the Telegram HTML subset, escaped and balanced.
        return formatter::to_html($markdown);
    }

    #[\Override]
    public function send_answer(string $chatkey, string $formatted): bool {
        // If the response is large or contains tabular data (<pre> block), send as a .txt file instead of chunking
        // multiple messages.
        if (mb_strlen($formatted) > self::ANSWER_MESSAGE_LENGTH || preg_match('/<pre>/i', $formatted)) {
            return $this->send_as_file($chatkey, $formatted);
        }
        return api_client::instance()->send_message($chatkey, $formatted);
    }

    /**
     * Send a formatted answer as a plain text file whose caption is its first line.
     *
     * @param string $chatkey Telegram chat id.
     * @param string $formatted Answer in Telegram HTML.
     * @return bool Whether Telegram accepted the file.
     */
    private function send_as_file(string $chatkey, string $formatted): bool {
        global $CFG;

        $tmpdir = $CFG->dataroot . '/jedomi/telegram';
        check_dir_exists($tmpdir, true, true);
        $filename = 'chat_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
        $filepath = $tmpdir . '/' . $filename;

        // Extract first meaningful line for the caption.
        $plaintext = html_entity_decode(strip_tags($formatted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $chatoneliner = '';
        foreach (explode("\n", $plaintext) as $cline) {
            $cline = trim($cline);
            if (!empty($cline)) {
                $chatoneliner = $cline;
                break;
            }
        }
        if (mb_strlen($chatoneliner) > self::CAPTION_LENGTH) {
            $chatoneliner = mb_substr($chatoneliner, 0, self::CAPTION_LENGTH - 3) . '...';
        }

        file_put_contents($filepath, $plaintext);
        $sent = api_client::instance()->send_document($chatkey, $filepath, "📄 {$chatoneliner}");
        @unlink($filepath);
        return $sent;
    }
}
