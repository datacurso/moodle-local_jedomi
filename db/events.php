<?php
/**
 * Event observers for proactive Telegram notifications.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
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
];
