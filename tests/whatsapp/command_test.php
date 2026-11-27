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
 * Tests for the WhatsApp commands: no slash needed, any letter case, Spanish and English aliases.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\command
 */
final class command_test extends \basic_testcase {
    /**
     * Texts of each command.
     *
     * @return array
     */
    public static function commands(): array {
        return [
            'AYUDA' => ['AYUDA', command::HELP],
            'help with a slash' => ['/help', command::HELP],
            'ayuda in lower case' => ['ayuda', command::HELP],
            'NUEVO' => ['Nuevo', command::NEW],
            'new with a slash' => ['/NEW', command::NEW],
            'DESVINCULAR' => ['DESVINCULAR', command::UNLINK],
            'unlink' => ['unlink', command::UNLINK],
            'BAJA' => ['baja', command::OPT_OUT],
            'STOP' => ['STOP', command::OPT_OUT],
            'stop with a slash and spaces' => ['  /stop  ', command::OPT_OUT],
            'ALTA' => ['Alta', command::OPT_IN],
            'START' => ['start', command::OPT_IN],
        ];
    }

    /**
     * Each alias gives its command.
     *
     * @dataProvider commands
     * @param string $text Text of the message.
     * @param string $expected Command.
     */
    public function test_parse_recognises_the_command(string $text, string $expected): void {
        $this->assertSame($expected, command::parse($text));
    }

    /**
     * Other texts are not commands.
     *
     * @return array
     */
    public static function other_texts(): array {
        return [
            'empty' => [''],
            'sentence' => ['baja la nota'],
            'unknown word' => ['HOLA'],
            'two slashes' => ['//stop'],
            'start with a code' => ['START ABC234'],
        ];
    }

    /**
     * A text that is not a command gives null.
     *
     * @dataProvider other_texts
     * @param string $text Text of the message.
     */
    public function test_parse_ignores_other_texts(string $text): void {
        $this->assertNull(command::parse($text));
    }
}
