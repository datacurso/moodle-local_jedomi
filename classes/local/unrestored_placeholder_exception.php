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

namespace local_jedomi\local;

/**
 * A query of the SQL tool holds a placeholder of the anonymiser that cannot be put back (RISK-001).
 *
 * Run with the text of the token in place of the real value, the query would give a false result, so it is not run: every
 * channel that runs tools sends {@see self::tool_result()} to the AI as the result of the call, so it can write the query
 * again with the exact placeholders.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unrestored_placeholder_exception extends \Exception {
    /** @var string[] Placeholders, as this anonymiser writes them, that could not be put back. */
    private array $placeholders;

    /**
     * Create the exception.
     *
     * @param string[] $placeholders Placeholders that could not be put back, as this anonymiser writes them.
     */
    public function __construct(array $placeholders) {
        $this->placeholders = array_values(array_unique($placeholders));
        parent::__construct('The query was not run: the placeholders ' . implode(', ', $this->placeholders)
            . ' could not be replaced by their real values, so the query would have compared the data with the text of'
            . ' the placeholder. Write each placeholder exactly as received, in the form [kind_N] (lowercase, with an'
            . ' underscore and no spaces), alone or inside plain single quotes, without backslashes before it or its'
            . ' brackets, and with spaces or operators between a placeholder and any other value or string.');
    }

    /**
     * Placeholders that could not be put back.
     *
     * @return string[]
     */
    public function get_placeholders(): array {
        return $this->placeholders;
    }

    /**
     * Result of the tool call to send to the AI instead of running the query.
     *
     * @return string JSON with error "unrestored_placeholder" and the detail.
     */
    public function tool_result(): string {
        return (string) json_encode(['error' => 'unrestored_placeholder', 'detail' => $this->getMessage()]);
    }
}
