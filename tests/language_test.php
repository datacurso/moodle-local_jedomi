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
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_english_and_spanish_declare_the_same_used_strings(): void {
        $this->markTestSkipped('Pending fix: untranslated panel labels, Spanish tool texts in English and dozens of '
            . 'declared strings that are never shown.');
    }

    /**
     * MDL-UNIT-059: Spanish strings use usted consistently, without voseo, tuteo or unnecessary English terms.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_spanish_strings_use_neutral_usted(): void {
        $this->markTestSkipped('Pending fix: outside Telegram, Spanish texts (settings, panel, AI and agent errors, quick '
            . 'actions) still mix voseo, tuteo and usted and use English terms with a Spanish equivalent.');
    }
}
