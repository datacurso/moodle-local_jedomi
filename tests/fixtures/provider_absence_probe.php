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

/**
 * Probe that tells whether a test reached the Datacurso AI provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Records every class of aiprovider_datacurso that PHP is asked to load or declares while a test runs.
 *
 * A test that must also run where the provider is not installed (the CI does not install it) calls start() in setUp()
 * and asserts touched() is empty in assertPostConditions(). The autoloader goes first and only records: the class is
 * still loaded by Moodle when the provider is installed, so the test behaves the same; touched() then names it.
 * start() also takes a snapshot of the provider classes, interfaces and traits already declared, so a class loaded by
 * an earlier test in the same PHP process is not blamed on the current one; only a class declared after start() is.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_absence_probe {
    /** @var string Namespace prefix of the provider classes. */
    private const PREFIX = 'aiprovider_datacurso\\';

    /** @var string[] Provider classes PHP was asked to load since start(). */
    private static array $requested = [];

    /** @var string[] Provider classes, interfaces and traits already declared when start() ran, as keys. */
    private static array $declaredatstart = [];

    /** @var bool Whether the recording autoloader is registered. */
    private static bool $registered = false;

    /**
     * Start recording, forgetting what earlier tests requested or declared.
     *
     * @return void
     */
    public static function start(): void {
        self::$requested = [];
        self::$declaredatstart = array_fill_keys(self::declared(), true);
        if (!self::$registered) {
            spl_autoload_register(static function (string $class): void {
                if (stripos(ltrim($class, '\\'), self::PREFIX) === 0) {
                    self::$requested[] = ltrim($class, '\\');
                }
            }, true, true);
            self::$registered = true;
        }
    }

    /**
     * Provider classes requested since start() or declared since start().
     *
     * @return string[]
     */
    public static function touched(): array {
        $new = array_filter(self::declared(), static fn(string $class): bool => !isset(self::$declaredatstart[$class]));
        return array_values(array_unique(array_merge(self::$requested, $new)));
    }

    /**
     * Provider classes, interfaces and traits declared in this PHP process.
     *
     * @return string[]
     */
    private static function declared(): array {
        $declared = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
        return array_values(array_filter($declared, static fn(string $class): bool => stripos($class, self::PREFIX) === 0));
    }
}
