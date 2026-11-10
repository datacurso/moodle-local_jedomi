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

/**
 * Test runner that posts a request to a page or endpoint of the plugin in its own process.
 *
 * Pages and endpoints end the request with exit() or redirect(), so they cannot be included
 * from a PHPUnit test. This runner loads the PHPUnit site, logs in as the given user and posts
 * the given parameters to the given script, printing its answer.
 *
 * Usage: php page_runner.php <username> <script relative to the plugin> <JSON parameters>
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState
// Standalone CLI runner: it bootstraps the PHPUnit site itself, so MOODLE_INTERNAL is not defined yet.

define('PHPUNIT_UTIL', true);
require(__DIR__ . '/../../../../vendor/autoload.php');
require(__DIR__ . '/../../../../lib/phpunit/bootstrap.php');

global $CFG;
global $DB;
[, $username, $script, $params] = array_pad($argv, 4, '');
\core\session\manager::set_user($DB->get_record('user', ['username' => $username], '*', MUST_EXIST));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = (array) json_decode($params ?: '{}', true);
$_POST['sesskey'] = sesskey();
$_GET = $_POST;

require($CFG->dirroot . '/local/jedomi/' . clean_param($script, PARAM_PATH));
