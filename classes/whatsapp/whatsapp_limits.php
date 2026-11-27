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

use local_jedomi\messaging\channel_limits;

/**
 * Limits of the WhatsApp channel: the length of a message part and the parts of an answer, the reply buttons, the
 * data of a held action, the tools of the channel and the rules of the prompt, with the two rules of the policy of
 * Meta (answer only about the courses of the student and Moodle, and offer a person when one is needed).
 *
 * The real limit of the text body of the Cloud API is not confirmed, so the length of a part is kept here.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_limits implements channel_limits {
    /** @var int Characters of a message part. */
    public const MESSAGE_LENGTH = 1600;

    /** @var int Messages of one answer; a longer answer is cut to one part less and a link to the web chat. */
    public const MAX_PARTS = 4;

    /** @var int Characters of the data of a held action shown in its message. */
    public const DETAILS_LENGTH = 1200;

    /**
     * @var string[] Tools the WhatsApp channel can run: in version 1 only the read tools of the students over their
     *      own data. Every message and tool result leaves the site to Meta, so no admin or teacher tool (SQL, site
     *      configuration, web services, admin settings, agents) is offered to any role, and none of these tools is
     *      ever held for confirmation (pending_action_service::severity() is null for them). The admin and teacher
     *      tools on WhatsApp are deferred to a later design.
     */
    public const SUPPORTED_TOOLS = ['my_courses', 'my_grades', 'my_assignments'];

    /**
     * Characters of a message part.
     *
     * @return int
     */
    public function message_length(): int {
        return self::MESSAGE_LENGTH;
    }

    /**
     * Messages of one answer at most.
     *
     * @return int
     */
    public function max_parts(): int {
        return self::MAX_PARTS;
    }

    /**
     * Reply buttons of an interactive message.
     *
     * @return int
     */
    public function max_buttons(): int {
        return cloud_api_client::MAX_BUTTONS;
    }

    /**
     * No tool progress lines: each one would reach the student as a separate WhatsApp message.
     *
     * @return bool
     */
    #[\Override]
    public function progress_messages(): bool {
        return false;
    }

    #[\Override]
    public function format_rules(string $userlang): string {
        $contact = manager::get_escalation_contact();
        $person = $contact !== ''
            ? "tell them they can contact a person at: {$contact}"
            : 'tell them to contact their teacher or the institution';
        $length = self::MESSAGE_LENGTH;
        return "⚡ WHATSAPP RULES:\n"
            . "- The user is WAITING on WhatsApp. Don't do extra iterations.\n"
            . "- Use ONLY WhatsApp formatting: *bold*, _italic_, ~strikethrough~ and " . formatter::MONOSPACE . 'monospace'
            . formatter::MONOSPACE . ".\n"
            . "- NO Markdown headings, tables or HTML. Write links as plain addresses.\n"
            . "- Keep the answer short, if possible under {$length} characters.\n"
            . "- Be concise, direct, respond in {$userlang} only.\n"
            . "- Answer only about the user's courses on this site and about using Moodle; politely decline any other "
            . "topic.\n"
            . "- When the user needs a person (a complaint, a personal or urgent problem, or something you cannot solve), "
            . "{$person}.\n\n";
    }
}
