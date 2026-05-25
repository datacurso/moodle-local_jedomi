<?php
/**
 * Scheduled tasks definitions for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => \local_jedomi\task\cleanup_telegram_sessions::class,
        'blocking'  => 0,
        'minute'    => '*/30',   // every 30 minutes
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => \local_jedomi\task\agent_runner::class,
        'blocking'  => 0,
        'minute'    => '*',      // every minute
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
