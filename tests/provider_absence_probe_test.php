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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Tests for the probe that tells whether a test reached the Datacurso AI provider.
 *
 * The provider classes are written to a request directory and loaded on the fly under a namespace of their own with
 * unique names, so the tests behave the same whether or not aiprovider_datacurso is installed.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\provider_absence_probe
 */
final class provider_absence_probe_test extends \advanced_testcase {
    /**
     * Declare a class in the provider namespace and return its name.
     *
     * @param string $prefix Readable start of the class name.
     * @return string Fully qualified class name.
     */
    private function declare_provider_class(string $prefix): string {
        $name = $prefix . '_' . bin2hex(random_bytes(6));
        $file = make_request_directory() . '/' . $name . '.php';
        file_put_contents($file, "<?php\nnamespace aiprovider_datacurso\\probe_test;\nfinal class {$name} {}\n");
        require($file);
        return 'aiprovider_datacurso\\probe_test\\' . $name;
    }

    /**
     * A provider class loaded by an earlier test in the same process is not blamed on the current test.
     */
    public function test_class_declared_before_start_is_not_reported(): void {
        $this->declare_provider_class('before');

        provider_absence_probe::start();

        $this->assertSame([], provider_absence_probe::touched());
    }

    /**
     * A provider class declared after start() is reported.
     */
    public function test_class_declared_after_start_is_reported(): void {
        $this->declare_provider_class('earlier');
        provider_absence_probe::start();

        $class = $this->declare_provider_class('after');

        $this->assertSame([$class], provider_absence_probe::touched());
    }

    /**
     * A provider class PHP is asked to load after start() is reported, even when it does not exist.
     */
    public function test_class_requested_after_start_is_reported(): void {
        provider_absence_probe::start();

        $exists = class_exists('aiprovider_datacurso\\probe_test\\missing');

        $this->assertFalse($exists);
        $this->assertSame(['aiprovider_datacurso\\probe_test\\missing'], provider_absence_probe::touched());
    }
}
