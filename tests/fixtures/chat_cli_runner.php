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
 * Test runner that starts the command console of the assistant (cli/chat_cli.php) in its own process, with the AI
 * service replaced by a scripted answer.
 *
 * The console reads the questions of the user from the standard input, so it cannot run inside a PHPUnit test. This
 * runner loads the PHPUnit site, queues the tool calls the AI proposes on its first turn followed by a final answer,
 * and runs the console, which then reads the questions from the standard input of this process.
 *
 * Usage: php chat_cli_runner.php <JSON tool calls of the first turn>
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing,moodle.Files.MoodleInternal.MoodleInternalGlobalState
// Standalone CLI runner: it bootstraps the PHPUnit site itself, so MOODLE_INTERNAL is not defined yet.

define('PHPUNIT_UTIL', true);
require(__DIR__ . '/../../../../vendor/autoload.php');
require(__DIR__ . '/../../../../lib/phpunit/bootstrap.php');
require_once(__DIR__ . '/fake_ai_client.php');
// A real command line run loads the command line library; the PHPUnit site does not.
require_once($CFG->libdir . '/clilib.php');

$fake = new \local_jedomi\ai\fake_ai_client();
$fake->enqueue_tool_calls((array) json_decode($argv[1] ?? '[]', true));
for ($i = 0; $i < 3; $i++) {
    $fake->enqueue_text('Done.');
}
\local_jedomi\ai\client::set_instance_for_testing($fake);

// The console defines the script constants again; the runner already did it when it loaded the site.
set_error_handler(static fn(int $errno, string $message): bool => str_contains($message, 'already defined'), E_WARNING);
require(__DIR__ . '/../../cli/chat_cli.php');
