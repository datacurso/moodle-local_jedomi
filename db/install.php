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
 * Post-install hook for local_jedomi.
 * Automatically deploys the bundled aiprovider_jedomi to the correct
 * Moodle directory so admins only need to install one plugin.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Runs after local_jedomi is installed.
 */
function xmldb_local_jedomi_install(): bool {
    global $DB, $CFG;

    // Change usercontext to MEDIUMTEXT for MySQL/MariaDB so that large
    // tool loop states (tool calls + results) don't get silently truncated.
    $dbtype = $CFG->dbtype ?? 'mysqli';
    if (in_array($dbtype, ['mysqli', 'mariadb'], true)) {
        $DB->execute("ALTER TABLE {local_jedomi_telegram_sessions} MODIFY COLUMN usercontext MEDIUMTEXT");
    }

    return true;
}
