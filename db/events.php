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
 * Event observers for proactive Telegram notifications.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback'  => 'local_jedomi\event\observers::submission_graded',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => 'local_jedomi\event\observers::user_loggedin',
        'internal'  => false,
    ],
    // The Telegram link is revoked when the account is suspended or deleted or its password changes (Decision 7).
    [
        'eventname' => '\core\event\user_updated',
        'callback'  => 'local_jedomi\event\observers::user_updated',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => 'local_jedomi\event\observers::user_deleted',
    ],
    [
        'eventname' => '\core\event\user_password_updated',
        'callback'  => 'local_jedomi\event\observers::user_password_updated',
    ],
];
