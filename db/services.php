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
 * Web Service function and service definitions for local_jedomi CompanionWS.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'jedomi_execute_sql' => [
        'classname'    => 'local_jedomi\external\execute_sql',
        'methodname'   => 'execute',
        'description'  => 'Execute a read-only SELECT query on the Moodle database.',
        'type'         => 'read',
        'capabilities' => 'local/jedomi:companion_api',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_run_task' => [
        'classname'    => 'local_jedomi\external\run_task',
        'methodname'   => 'execute',
        'description'  => 'Trigger a Moodle scheduled task by class name.',
        'type'         => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_purge_caches' => [
        'classname'    => 'local_jedomi\external\purge_caches',
        'methodname'   => 'execute',
        'description'  => 'Purge all Moodle caches.',
        'type'         => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_extract_schema' => [
        'classname'    => 'local_jedomi\external\extract_schema',
        'methodname'   => 'execute',
        'description'  => 'Extract the complete Moodle database schema as JSON.',
        'type'         => 'read',
        'capabilities' => 'local/jedomi:companion_api',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_validate_password' => [
        'classname'    => 'local_jedomi\external\validate_password',
        'methodname'   => 'execute',
        'description'  => 'Validate a password against the Moodle site password policy.',
        'type'         => 'read',
        'capabilities' => 'local/jedomi:companion_api',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_get_site_config' => [
        'classname'    => 'local_jedomi\external\get_site_config',
        'methodname'   => 'execute',
        'description'  => 'Return a pre-approved subset of Moodle site configuration values.',
        'type'         => 'read',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_read_file' => [
        'classname'    => 'local_jedomi\external\read_file',
        'methodname'   => 'execute',
        'description'  => 'Read a Moodle source file for diagnostic purposes. Blocked paths and whitelist enforced.',
        'type'         => 'read',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'jedomi_set_site_config' => [
        'classname'    => 'local_jedomi\external\set_site_config',
        'methodname'   => 'execute',
        'description'  => 'Write a Moodle site config value. Blocked keys cannot be overridden.',
        'type'         => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
    'local_jedomi_search_courses' => [
        'classname'    => 'local_jedomi\external\search_courses',
        'methodname'   => 'execute',
        'description'  => 'Search courses by name for the Jedomi course-availability chip selector.',
        'type'         => 'read',
        'capabilities' => 'moodle/site:config',
        'ajax'         => true,
    ],
    'jedomi_ws_execute' => [
        'classname'    => 'local_jedomi\external\ws_execute',
        'methodname'   => 'execute',
        'description'  => 'Execute a Moodle web service function on behalf of the user.',
        'type'         => 'write',
        'capabilities' => 'local/jedomi:companion_api',
        'ajax'         => false,
        'services'     => ['jedomi_companion'],
    ],
];

$services = [
    'jedomi_companion' => [
        'name'          => 'Jedomi Companion API',
        'functions'     => [
            'jedomi_execute_sql',
            'jedomi_run_task',
            'jedomi_purge_caches',
            'jedomi_extract_schema',
            'jedomi_validate_password',
            'jedomi_get_site_config',
            'jedomi_set_site_config',
            'jedomi_read_file',
            'jedomi_ws_execute',
        ],
        'enabled'       => 1,
        'shortname'     => 'jedomi_companion',
        'downloadfiles' => 0,
        'uploadfiles'   => 0,
        'restrictedusers' => 1,
    ],
];
