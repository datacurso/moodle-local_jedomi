<?php
/**
 * Scheduled task: cleanup expired Telegram sessions.
 *
 * Deletes session rows with lastactivity older than 30 minutes.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_telegram_sessions extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('cleanup_telegram_sessions', 'local_jedomi');
    }

    public function execute(): void {
        global $DB;
        $cutoff = time() - (30 * 60); // 30 minutes
        $count = $DB->delete_records_select('local_jedomi_telegram_sessions', 'lastactivity < ?', [$cutoff]);
        mtrace("Deleted {$count} expired Telegram session(s).");

        // Also clean up stale pending actions older than 5 minutes
        $action_cutoff = time() - (5 * 60);
        $action_count = $DB->delete_records_select('local_jedomi_telegram_pending_actions', 'timecreated < ?', [$action_cutoff]);
        mtrace("Deleted {$action_count} expired pending action(s).");
    }
}
