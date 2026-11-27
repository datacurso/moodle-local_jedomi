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
use local_jedomi\messaging\chat_commands;
use local_jedomi\messaging\inbound_message;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Commands of the WhatsApp channel for the router.
 *
 * The chat_processor already handles the link, its codes, AYUDA, BAJA, ALTA and DESVINCULAR before a message reaches
 * the router, so here the link of a chat is only found, never expired nor changed. Only NUEVO is left: it starts a
 * new conversation.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_commands implements chat_commands {
    #[\Override]
    public function is_help(string $text): bool {
        return false;
    }

    #[\Override]
    public function help(string $chatkey): void {
    }

    #[\Override]
    public function find_link(string $chatkey): ?\stdClass {
        global $DB;
        $bsuid = whatsapp_channel::bsuid_of($chatkey);
        if ($bsuid === null) {
            return null;
        }
        return $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['bsuid' => $bsuid, 'confirmed' => 1]) ?: null;
    }

    #[\Override]
    public function expire_or_touch(\stdClass $link): bool {
        // The chat_processor expires and touches the link of every message.
        return false;
    }

    #[\Override]
    public function link_expired(string $chatkey): void {
    }

    #[\Override]
    public function link_first(string $chatkey): void {
    }

    #[\Override]
    public function is_link_command(string $text): bool {
        return false;
    }

    #[\Override]
    public function link_request(string $text): ?string {
        return null;
    }

    #[\Override]
    public function link(inbound_message $message, string $request): void {
    }

    #[\Override]
    public function is_unlink(string $text): bool {
        return false;
    }

    #[\Override]
    public function unlink(string $chatkey, \stdClass $link): void {
    }

    #[\Override]
    public function is_new_conversation(string $text): bool {
        return command::parse($text) === command::NEW;
    }

    #[\Override]
    public function new_conversation(string $chatkey, int $userid): string {
        global $DB;
        \local_jedomi_log('WhatsApp: new conversation', ['user' => \local_jedomi_log_pseudonym('user', $userid)]);
        $store = (new whatsapp_channel())->store();
        $store->clear($chatkey);
        $store->clear_context($chatkey);
        // A new session generation stops any continuation of the previous task.
        $store->set_context('tool_loop_' . $chatkey, ['session_gen' => \core\di::get(\core\clock::class)->time()]);
        $firstname = (string) $DB->get_field('user', 'firstname', ['id' => $userid]);
        return get_string('telegram_new_conversation_greeting', 'local_jedomi', $firstname);
    }
}
