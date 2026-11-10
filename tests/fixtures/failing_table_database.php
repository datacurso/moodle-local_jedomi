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
 * Test double of the database that fails on one table.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Database wrapper that throws a write error for every operation on one table and delegates the rest.
 *
 * It simulates a database failure at a true boundary of the code under test.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_table_database {
    /** @var \moodle_database Real database. */
    private \moodle_database $real;

    /** @var string Table whose operations fail. */
    private string $table;

    /**
     * Wrap the real database.
     *
     * @param \moodle_database $real Real database.
     * @param string $table Table whose operations fail.
     */
    public function __construct(\moodle_database $real, string $table) {
        $this->real = $real;
        $this->table = $table;
    }

    /**
     * Fail for the table, delegate otherwise.
     *
     * @param string $method Method name.
     * @param array $args Arguments.
     * @return mixed
     * @throws \dml_write_exception For any operation on the failing table.
     */
    public function __call(string $method, array $args) {
        if (($args[0] ?? null) === $this->table) {
            throw new \dml_write_exception('Simulated database failure on ' . $this->table);
        }
        return $this->real->$method(...$args);
    }

    /**
     * Read a property of the real database.
     *
     * @param string $name Property name.
     * @return mixed
     */
    public function __get(string $name) {
        return $this->real->$name;
    }
}
