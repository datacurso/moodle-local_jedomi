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
 * Discards the debugging messages raised by other plugins installed on the site.
 *
 * Some tests load code of every plugin (the whole administration tree, every web service description). A debugging
 * message of another plugin is not a defect of Jedomi and would fail the test only on sites that have that plugin.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait other_plugins_debugging {
    /**
     * Discard the debugging messages raised in the code of other plugins; any other message fails the test.
     *
     * @return void
     */
    protected function discard_debugging_of_other_plugins(): void {
        $own = [];
        foreach ($this->getDebuggingMessages() as $debug) {
            if (!self::raised_by_other_plugin((string) $debug->from)) {
                $own[] = $debug->message;
            }
        }
        $this->resetDebugging();
        $this->assertSame([], $own, 'Only other plugins raise debugging messages');
    }

    /**
     * Tell whether a backtrace passes through the code of a plugin other than Jedomi.
     *
     * @param string $backtrace Formatted backtrace of the debugging message.
     * @return bool
     */
    private static function raised_by_other_plugin(string $backtrace): bool {
        global $CFG;
        foreach (array_keys(\core_component::get_plugin_types()) as $type) {
            foreach (\core_component::get_plugin_list($type) as $name => $directory) {
                if ($type . '_' . $name === 'local_jedomi') {
                    continue;
                }
                $relative = substr($directory, strlen($CFG->dirroot));
                if (strpos($backtrace, ' of ' . $relative . '/') !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
