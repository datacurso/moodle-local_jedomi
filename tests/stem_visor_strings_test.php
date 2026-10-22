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

namespace local_jedomi;

/**
 * Contract between the AR viewer script, the page that exports its strings and the language packs.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class stem_visor_strings_test extends \advanced_testcase {
    /**
     * Keys listed in $jsstrings of stem/visor.php (without the stem_visor_ prefix).
     *
     * @return string[]
     */
    private function exported_keys(): array {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/jedomi/stem/visor.php');
        $this->assertSame(1, preg_match('/\$jsstrings = \[(.*?)\];/s', $src, $m), 'visor.php must declare $jsstrings');
        preg_match_all("/'([a-z0-9_]+)'/", $m[1], $keys);
        return $keys[1];
    }

    /**
     * Keys the viewer reads through T("key") in visor.js and as strings.<key> in devices.js.
     *
     * @return string[]
     */
    private function used_keys(): array {
        global $CFG;
        $src = file_get_contents($CFG->dirroot . '/local/jedomi/stem/visor/visor.js');
        $used = [];
        preg_match_all('/\bT\(([^()]*(?:\([^()]*\)[^()]*)*)\)/', $src, $calls);
        foreach ($calls[1] as $args) {
            // Drop comparison operands such as view.mode === "web": they are values, not keys.
            $args = preg_replace('/===?\s*"[^"]*"/', '', $args);
            preg_match_all('/"([a-z][a-z0-9_]*)"/', $args, $lits);
            $used = array_merge($used, $lits[1]);
        }
        preg_match_all('/"(hint_[a-z_]+)"/', $src, $hints);
        $used = array_merge($used, $hints[1]);
        // The prejoin module reads its strings as strings.<key>.
        $prejoin = file_get_contents($CFG->dirroot . '/local/jedomi/stem/visor/devices.js');
        preg_match_all('/\bstrings\.([a-z][a-z0-9_]*)/', $prejoin, $direct);
        return array_values(array_unique(array_merge($used, $direct[1])));
    }

    /**
     * Strings of one language file of this plugin.
     *
     * @param string $lang Language code.
     * @return array<string, string>
     */
    private function lang_file(string $lang): array {
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/local/jedomi/lang/' . $lang . '/local_jedomi.php');
        return $string;
    }

    /**
     * Every key the viewer uses is exported by the page.
     */
    public function test_viewer_keys_are_exported(): void {
        $this->resetAfterTest(true);
        $missing = array_diff($this->used_keys(), $this->exported_keys());
        $this->assertSame([], array_values($missing), 'Keys used by visor.js but missing from $jsstrings');
        $this->assertContains('you_said', $this->used_keys());
        $this->assertContains('hint_free', $this->used_keys());
        $this->assertContains('pj_cam_n', $this->used_keys());
    }

    /**
     * Every exported key exists in English and in Spanish, and placeholders match.
     */
    public function test_exported_keys_exist_in_both_languages(): void {
        $this->resetAfterTest(true);
        $en = $this->lang_file('en');
        $es = $this->lang_file('es');
        foreach ($this->exported_keys() as $key) {
            $id = 'stem_visor_' . $key;
            $this->assertArrayHasKey($id, $en, "Missing English string $id");
            $this->assertArrayHasKey($id, $es, "Missing Spanish string $id");
            preg_match_all('/\{[a-z]+\}/', $en[$id], $enph);
            preg_match_all('/\{[a-z]+\}/', $es[$id], $esph);
            sort($enph[0]);
            sort($esph[0]);
            $this->assertSame($enph[0], $esph[0], "Placeholders differ between languages in $id");
        }
    }

    /**
     * The page really sends the strings to the viewer through get_string.
     */
    public function test_get_string_returns_the_exported_text(): void {
        $this->resetAfterTest(true);
        $this->assertSame('⌛ You: {said}', get_string('stem_visor_you_said', 'local_jedomi'));
        $this->assertSame('stop|exit|enough|bye', get_string('stem_visor_voice_stop_words', 'local_jedomi'));
    }
}
