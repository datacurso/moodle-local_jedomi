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

namespace local_jedomi\tests;

/**
 * Reads the Spanish strings of Jedomi straight from its own language file.
 *
 * The string manager only loads the lang/es file of a plugin when the core Spanish language pack is installed, which
 * is not the case in the PHPUnit dataroot nor in CI. Reading the file of the plugin keeps the checks of the Spanish
 * texts running everywhere.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait spanish_strings {
    /**
     * Returns every string declared in lang/es/local_jedomi.php.
     *
     * @return array Spanish texts keyed by string identifier.
     */
    protected static function plugin_spanish_strings(): array {
        $string = [];
        include(__DIR__ . '/../../lang/es/local_jedomi.php');
        return $string;
    }

    /**
     * Returns one Spanish string of Jedomi with its placeholders replaced, as get_string() would.
     *
     * @param string $identifier String identifier.
     * @param mixed $a Value of {$a}, or an array or object for {$a->name}.
     * @return string The Spanish text.
     */
    protected static function plugin_spanish_string(string $identifier, $a = null): string {
        $strings = self::plugin_spanish_strings();
        if (!array_key_exists($identifier, $strings)) {
            throw new \coding_exception("The Spanish string {$identifier} of local_jedomi does not exist");
        }
        $text = (string) $strings[$identifier];
        if ($a === null) {
            return $text;
        }
        if (is_array($a) || is_object($a)) {
            foreach ((array) $a as $name => $value) {
                if (is_scalar($value)) {
                    $text = str_replace('{$a->' . $name . '}', (string) $value, $text);
                }
            }
            return $text;
        }
        return str_replace('{$a}', (string) $a, $text);
    }
}
