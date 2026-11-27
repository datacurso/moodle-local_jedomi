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

namespace local_jedomi\task;

use local_jedomi\whatsapp\inbox_processor;

/**
 * Ad hoc task that rescues one row of the WhatsApp inbox.
 *
 * The webhook queues it for every stored message before answering Meta. It only acts when the row is still pending, or
 * processing for longer than LOCK_TTL (the webhook process died, or the site runs in queue mode); a done or failed row
 * is left alone. When the row is busy or must be retried, it queues itself again after LOCK_TTL; the attempts of the
 * row bound the retries.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_whatsapp_inbox extends \core\task\adhoc_task {
    /**
     * Queue the rescue of a row.
     *
     * @param int $inboxid Row id.
     * @param int $delay Seconds from now before the task runs.
     * @param bool $unique Skip it when a rescue of the same row is already queued.
     */
    public static function queue(int $inboxid, int $delay, bool $unique = false): void {
        $task = new self();
        $task->set_custom_data(['inboxid' => $inboxid]);
        $task->set_next_run_time(\core\di::get(\core\clock::class)->time() + $delay);
        \core\task\manager::queue_adhoc_task($task, $unique);
    }

    /**
     * Process the row when it still needs it.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $inboxid = (int) ($data->inboxid ?? 0);
        if ($inboxid <= 0) {
            return;
        }
        $result = (new inbox_processor())->process($inboxid);
        if (in_array($result, [inbox_processor::RESULT_BUSY, inbox_processor::RESULT_RETRY], true)) {
            self::queue($inboxid, inbox_processor::LOCK_TTL);
        }
    }
}
