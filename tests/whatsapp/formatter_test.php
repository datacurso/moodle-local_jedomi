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
 * Tests for the WhatsApp formatter: Markdown and HTML of the answers become WhatsApp formatting, literal format
 * characters do not format, and long texts are split by paragraph.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\formatter
 */
final class formatter_test extends \basic_testcase {
    /**
     * Markdown and HTML become *bold*, _italic_, ~strikethrough~ and ```monospace```, with links as plain addresses.
     *
     * @dataProvider conversion_provider
     * @param string $input Answer of the AI.
     * @param string $expected WhatsApp text.
     */
    public function test_answers_become_whatsapp_formatting(string $input, string $expected): void {
        $this->assertSame($expected, formatter::to_whatsapp($input));
    }

    /**
     * Answers and their WhatsApp text.
     *
     * @return array
     */
    public static function conversion_provider(): array {
        return [
            'markdown bold' => ['**Hola** mundo', '*Hola* mundo'],
            'markdown underscore bold' => ['__Hola__ mundo', '*Hola* mundo'],
            'whatsapp bold kept' => ['*Hola* mundo', '*Hola* mundo'],
            'italic kept' => ['un _poco_ más', 'un _poco_ más'],
            'markdown strikethrough' => ['~~viejo~~ nuevo', '~viejo~ nuevo'],
            'html tags' => ['<b>a</b> <strong>b</strong> <i>c</i> <em>d</em> <s>e</s> <del>f</del>', '*a* *b* _c_ _d_ ~e~ ~f~'],
            'heading' => ["# Mis cursos\nTiene dos.", "*Mis cursos*\nTiene dos."],
            'html heading' => ['<h3>Notas</h3>Todo bien', "*Notas*\nTodo bien"],
            'bullets' => ["- uno\n* dos\n+ tres", "• uno\n• dos\n• tres"],
            'html list' => ['<ul><li>uno</li><li>dos</li></ul>', "• uno\n• dos"],
            'markdown link' => ['Vea [el curso](https://moodle.example/course/view.php?id=2)',
                'Vea el curso (https://moodle.example/course/view.php?id=2)'],
            'markdown link with its address as text' => ['[https://a.example/x](https://a.example/x)', 'https://a.example/x'],
            'html link' => ['<a href="https://a.example/a_b_c">sitio</a>', 'sitio (https://a.example/a_b_c)'],
            'link that is not http' => ['[clic](javascript:alert(1))', 'clic'],
            'bare address untouched' => ['Abra https://a.example/_x_/*y*~z~ ahora', 'Abra https://a.example/_x_/*y*~z~ ahora'],
            'inline code' => [
                "Use \u{60}mod_assign\u{60} aquí",
                'Use ' . formatter::MONOSPACE . 'mod_assign' . formatter::MONOSPACE . ' aquí',
            ],
            'fenced code' => [
                "Código:\n" . formatter::MONOSPACE . "php\necho 1 * 2;\n" . formatter::MONOSPACE,
                "Código:\n" . formatter::MONOSPACE . 'echo 1 * 2;' . formatter::MONOSPACE,
            ],
            'html pre' => ['<pre>a &lt; b</pre>', formatter::MONOSPACE . 'a < b' . formatter::MONOSPACE],
            'line breaks' => ['uno<br>dos<br/>tres', "uno\ndos\ntres"],
            'paragraphs' => ['<p>uno</p><p>dos</p>', "uno\n\ndos"],
            'entities' => ['Tom &amp; Jerry &quot;ok&quot;', 'Tom & Jerry "ok"'],
            'blank lines collapsed' => ["a\n\n\n\nb", "a\n\nb"],
            'unknown tags removed' => ['<span class="x">hola</span>', 'hola'],
            'less-than kept' => ['3 < 5 y 5 > 3', '3 < 5 y 5 > 3'],
            'literal markers do not format' => ['5 * 3 = 15 y ~ok', '5 ∗ 3 = 15 y ∼ok'],
            'markers inside words kept' => ['archivo_final y 2*3', 'archivo_final y 2*3'],
            'private use characters dropped' => ["a\u{E000}b\u{E010}0\u{E011}c", 'ab0c'],
            'private use entities dropped' => ['a&#xE010;0&#57361;b&#xe000;', 'a0b'],
        ];
    }

    /**
     * Text written by a user cannot format the message: the format characters become look-alikes, except inside a
     * word or an address.
     */
    public function test_escape_neutralises_the_format_characters_of_user_content(): void {
        $this->assertSame('∗hola∗ ＿x＿ ∼y∼ ˋzˋ', formatter::escape("*hola* _x_ ~y~ \u{60}z\u{60}"));
        $this->assertSame('snake_case 2*3 a~b', formatter::escape('snake_case 2*3 a~b'));
        $this->assertSame('https://a.example/_x_', formatter::escape('https://a.example/_x_'));
        $this->assertSame('', formatter::escape(''));
    }

    /**
     * A text that fits is one part; an empty text has none.
     */
    public function test_a_short_text_is_one_part(): void {
        $this->assertSame(['Hola'], formatter::chunk('Hola', 1600));
        $this->assertSame([], formatter::chunk("  \n ", 1600));
    }

    /**
     * Paragraphs are packed into parts within the limit, never split while they fit.
     */
    public function test_paragraphs_are_packed_within_the_limit(): void {
        $one = str_repeat('a', 700);
        $two = str_repeat('b', 700);
        $three = str_repeat('c', 700);

        $parts = formatter::chunk($one . "\n\n" . $two . "\n\n" . $three, 1600);

        $this->assertSame([$one . "\n\n" . $two, $three], $parts);
    }

    /**
     * A paragraph longer than the limit is split by line, then by word, then by character; no part is longer than the
     * limit and no text is lost.
     */
    public function test_long_paragraphs_are_split_within_the_limit(): void {
        $words = trim(str_repeat('palabra ', 500));
        $lines = implode("\n", array_fill(0, 40, str_repeat('x', 60)));
        $word = str_repeat('é', 4000);

        foreach ([$words, $lines, $word] as $text) {
            $parts = formatter::chunk($text, 1600);
            $this->assertGreaterThan(1, count($parts));
            foreach ($parts as $part) {
                $this->assertLessThanOrEqual(1600, \core_text::strlen($part));
                $this->assertNotSame('', $part);
            }
            $this->assertSame(
                preg_replace('/\s+/u', '', $text),
                preg_replace('/\s+/u', '', implode('', $parts))
            );
        }
    }
}
