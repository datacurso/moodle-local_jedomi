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
 * Test double of the database that records the read queries.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/array_recordset.php');

/**
 * Database wrapper that reports a chosen database type, records the read query and the session statements, and
 * answers the read query with given rows or a given failure, so the query is never run on the real database.
 *
 * Every other call is delegated to the real database.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_database {
    /** @var \moodle_database Real database. */
    private \moodle_database $real;

    /** @var string Reported database family. */
    private string $family;

    /** @var string Reported database vendor. */
    private string $vendor;

    /** @var \stdClass[] Rows answered to the read query. */
    public array $rows = [];

    /** @var \Throwable|null Failure thrown by the read query. */
    public ?\Throwable $failure = null;

    /** @var array[] Read queries: sql, params, limitfrom and limitnum. */
    public array $queries = [];

    /** @var string[] Session statements and reads, in order. */
    public array $statements = [];

    /**
     * Wrap the real database.
     *
     * @param \moodle_database $real Real database.
     * @param string $family Reported database family (mysql, postgres, mssql or oracle).
     * @param string $vendor Reported database vendor (mysql, mariadb, postgres...).
     */
    public function __construct(\moodle_database $real, string $family, string $vendor) {
        $this->real = $real;
        $this->family = $family;
        $this->vendor = $vendor;
    }

    /**
     * Reported database family.
     *
     * @return string
     */
    public function get_dbfamily() {
        return $this->family;
    }

    /**
     * Reported database vendor.
     *
     * @return string
     */
    public function get_dbvendor() {
        return $this->vendor;
    }

    /**
     * Reported server information.
     *
     * @return array
     */
    public function get_server_info() {
        return ['description' => $this->vendor, 'version' => '0'];
    }

    /**
     * Record the read query and answer it with the given rows or failure.
     *
     * @param string $sql Query.
     * @param array|null $params Parameters.
     * @param int $limitfrom First row.
     * @param int $limitnum Maximum rows.
     * @return array_recordset
     */
    public function get_recordset_sql($sql, ?array $params = null, $limitfrom = 0, $limitnum = 0) {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'limitfrom' => $limitfrom, 'limitnum' => $limitnum];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $rows = $this->rows;
        if ($limitnum > 0) {
            $rows = array_slice($rows, (int) $limitfrom, (int) $limitnum);
        }
        return new array_recordset($rows);
    }

    /**
     * Record a session statement (SET) without running it, delegate any other statement.
     *
     * @param string $sql Statement.
     * @param array|null $params Parameters.
     * @return bool
     */
    public function execute($sql, ?array $params = null) {
        if (!self::is_session_sql($sql)) {
            return $this->real->execute($sql, $params);
        }
        $this->statements[] = $sql;
        return true;
    }

    /**
     * Record a read of a session setting without running it (answering "0"), delegate any other read.
     *
     * @param string $sql Query.
     * @param array|null $params Parameters.
     * @param int $strictness Strictness.
     * @return mixed
     */
    public function get_field_sql($sql, ?array $params = null, $strictness = IGNORE_MISSING) {
        if (!self::is_session_sql($sql . ' ' . implode(' ', (array) $params))) {
            return $this->real->get_field_sql($sql, $params, $strictness);
        }
        $this->statements[] = $params ? $sql . ' [' . implode(', ', $params) . ']' : $sql;
        return '0';
    }

    /**
     * Tell whether a statement reads or changes a session setting.
     *
     * @param string $sql Statement.
     * @return bool
     */
    private static function is_session_sql(string $sql): bool {
        return (bool) preg_match('/^\s*SET\b|statement_timeout|max_execution_time|max_statement_time/i', $sql);
    }

    /**
     * Delegate to the real database.
     *
     * @param string $method Method name.
     * @param array $args Arguments.
     * @return mixed
     */
    public function __call(string $method, array $args) {
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
