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
 * Capability definitions for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Agents run with the permissions of their creator: they read personal data, change data, send messages and run
    // site administration operations.
    'local/jedomi:manageagents' => [
        'riskbitmask'  => RISK_CONFIG | RISK_DATALOSS | RISK_PERSONAL | RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Allows calling the companion API functions (database queries, schema, password check and web services), both
    // from the web chat, which runs them in-process with the user's permissions, and from the companion service.
    // It is meant for managers and for the local_jedomi_companion service account, to which an administrator grants it.
    // Other roles do not have it unless an administrator grants it. There is no CAP_PROHIBIT on any archetype: the
    // authenticated user role is held by every logged-in user, so a prohibit there would remove it from managers too.
    // It runs database queries, web services and writes site configuration.
    'local/jedomi:companion_api' => [
        'riskbitmask'  => RISK_CONFIG | RISK_DATALOSS | RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
