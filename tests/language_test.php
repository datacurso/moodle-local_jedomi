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

namespace local_jedomi;

/**
 * Tests for the English and Spanish language strings of Jedomi.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class language_test extends \basic_testcase {
    /**
     * MDL-UNIT-058: both languages declare the same strings, no visible text is fixed in code or templates and no
     * declared string is unused.
     *
     * A string counts as used when its key appears in the code, the templates, the JavaScript or the tests of the plugin,
     * or when it belongs to a family whose key is built at run time (listed below with the code that builds it).
     *
     * @return void
     */
    public function test_english_and_spanish_declare_the_same_used_strings(): void {
        $root = dirname(__DIR__);
        // The files are read directly: the string manager fills a missing Spanish string with the English one.
        $read = static function (string $lang) use ($root): array {
            $string = [];
            include($root . "/lang/{$lang}/local_jedomi.php");
            return $string;
        };
        $en = $read('en');
        $es = $read('es');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($es))), 'Every string is translated');
        $this->assertSame([], array_values(array_diff(array_keys($es), array_keys($en))), 'No Spanish string is left over');

        $source = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root));
            $skipped = preg_match('#^/(lang|amd/build|\.git|node_modules)/#', $path);
            if ($skipped || !preg_match('/\.(php|js|mustache|feature)$/', $path)) {
                continue;
            }
            $source .= file_get_contents($file->getPathname()) . "\n";
        }

        // Families whose key is built at run time: help buttons (addHelpButton($field, $field)), anonymisation levels
        // (privacy_settings_form), API roles (apis_tab), course modes (course_settings_form), creator reasons
        // (agent_runner), profiler origins (profiler_tab), spoken placeholders (speech_service), Telegram guide steps
        // (integrations_tab) and tool progress labels (webhook_handler, by the name of the tool the AI calls).
        $dynamic = [
            '/^privacy:metadata/', '/^jedomi:/', '/^cachedef_/', '/^messageprovider:/', '/^pluginname$/',
            '/_help$/',
            '/^anon_[a-z_]+_desc$/',
            '/^api_role_/',
            '/^course_mode_/',
            '/^agent_disabled_reason_creator_/',
            '/^query_profiler_origin_/',
            '/^speech_placeholder_/',
            '/^telegram_step(U?\d)_(title|desc)$/',
            '/^tool_progress_/',
        ];
        $unused = [];
        foreach (array_keys($en) as $key) {
            foreach ($dynamic as $pattern) {
                if (preg_match($pattern, $key)) {
                    continue 2;
                }
            }
            if (!preg_match('/(?<![A-Za-z0-9_:])' . preg_quote($key, '/') . '(?![A-Za-z0-9_])/', $source)) {
                $unused[] = $key;
            }
        }
        $this->assertSame([], $unused, 'Every declared string is used');
    }

    /**
     * MDL-UNIT-059: Spanish strings use usted consistently, without voseo, tuteo or unnecessary English terms. Quoted
     * examples, code, placeholders, commands and technical identifiers are not checked.
     *
     * @return void
     */
    public function test_spanish_strings_use_neutral_usted(): void {
        $manager = get_string_manager();
        $es = $manager->load_component_strings('local_jedomi', 'es', true);
        $en = $manager->load_component_strings('local_jedomi', 'en', true);
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($es))), 'Every string is translated');

        $tuteo = '/\b(tú|vos|tenés|podés|querés|sabés|hacé|escribí|elegí|usá|intentá|tu|tus|te|ti|contigo|puedes|tienes|'
            . 'quieres|debes|necesitas|haz)\b/iu';
        $english = '/\b(admin|manager|student|teacher|dashboard|e-?mails?|default|click|login|logout|feedback|upload|'
            . 'download|webservice)\b/iu';
        $technical = ['/"[^"]*"/u', '/«[^»]*»/u', '/<code>.*?<\/code>/u', '/\{\$a[^}]*\}/u', '/@\w+/u', '/\/\w+/u',
            '/\b\w+_\w+\b/u'];
        foreach ($es as $key => $value) {
            $text = preg_replace($technical, ' ', (string) $value);
            $this->assertDoesNotMatchRegularExpression($tuteo, $text, "es/{$key} uses tuteo or voseo");
            $this->assertDoesNotMatchRegularExpression($english, $text, "es/{$key} keeps an English term");
        }
    }
}
