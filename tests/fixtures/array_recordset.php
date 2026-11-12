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
 * Test double of a recordset.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Recordset over rows held in memory.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class array_recordset extends \moodle_recordset {
    /** @var \stdClass[] Rows. */
    private array $rows;

    /** @var int Position of the current row. */
    private int $position = 0;

    /** @var bool Whether close() was called. */
    public bool $closed = false;

    /**
     * Hold the rows.
     *
     * @param \stdClass[] $rows Rows.
     */
    public function __construct(array $rows) {
        $this->rows = array_values($rows);
    }

    /**
     * Current row.
     *
     * @return \stdClass
     */
    public function current(): \stdClass {
        return $this->rows[$this->position];
    }

    /**
     * Key of the current row.
     *
     * @return int
     */
    public function key(): int {
        return $this->position;
    }

    /**
     * Move to the next row.
     */
    public function next(): void {
        $this->position++;
    }

    /**
     * Tell whether there is a current row.
     *
     * @return bool
     */
    public function valid(): bool {
        return $this->position < count($this->rows);
    }

    /**
     * Close the recordset.
     */
    public function close() {
        $this->closed = true;
    }
}
