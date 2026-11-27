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

/**
 * Commands of the WhatsApp channel.
 *
 * A command is one word, with or without a leading slash, in any letter case, with Spanish and English aliases:
 * AYUDA/HELP, NUEVO/NEW, DESVINCULAR/UNLINK, BAJA/STOP and ALTA/START.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class command {
    /** @var string Show the help. */
    public const HELP = 'help';

    /** @var string Start a new conversation. */
    public const NEW = 'new';

    /** @var string Remove the link and the data of the channel. */
    public const UNLINK = 'unlink';

    /** @var string Stop the messages (opt-out). */
    public const OPT_OUT = 'optout';

    /** @var string Receive the messages again (opt-in). */
    public const OPT_IN = 'optin';

    /** @var array<string, string> Command of each alias, in upper case. */
    private const ALIASES = [
        'AYUDA' => self::HELP,
        'HELP' => self::HELP,
        'NUEVO' => self::NEW,
        'NEW' => self::NEW,
        'DESVINCULAR' => self::UNLINK,
        'UNLINK' => self::UNLINK,
        'BAJA' => self::OPT_OUT,
        'STOP' => self::OPT_OUT,
        'ALTA' => self::OPT_IN,
        'START' => self::OPT_IN,
    ];

    /**
     * The command of a text, or null when the text is not a command.
     *
     * @param string $text Text of the message.
     * @return string|null One of the command constants.
     */
    public static function parse(string $text): ?string {
        $word = strtoupper(trim($text));
        if (str_starts_with($word, '/')) {
            $word = substr($word, 1);
        }
        return self::ALIASES[$word] ?? null;
    }
}
