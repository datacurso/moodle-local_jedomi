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
 * End-to-end check of the Jedomi AI route: Jedomi, Datacurso provider, plugins-ai, LiteLLM and the model.
 *
 * Holds one short real conversation with one tool call, as the site administrator, with the license,
 * model and region configured in the site. It consumes credits like any other message.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(['help' => false], ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo "End-to-end check of the Jedomi AI route (Jedomi > Datacurso provider > plugins-ai > LiteLLM > model).

Holds one short conversation that needs the search_tables tool, as the site administrator, with the
license, model and region configured in the site. Prints PASS or FAIL for each step and, on failure,
the error code and the layer that returned it. Consumes credits like any other Jedomi message.
Never prints the license or the answer bodies.

Options:
-h, --help    Print out this help

Example:
\$ php local/jedomi/cli/e2e_check.php

Exit code: 0 when every step passes, 1 otherwise.
";
    exit(0);
}

$admin = get_admin();
\core\session\manager::set_user($admin);

$ok = (new \local_jedomi\local\e2e_check())->run((int) $admin->id);
exit($ok ? 0 : 1);
