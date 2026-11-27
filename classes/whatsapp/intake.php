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

namespace local_jedomi\whatsapp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\safe_error;
use local_jedomi\local\secret_store;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\http_responder;
use local_jedomi\task\process_whatsapp_inbox;

/**
 * Intake of the WhatsApp webhook (whatsapp_webhook.php).
 *
 * GET: verification handshake of Meta. POST: the signature of the raw body is checked; every message of
 * entry[].changes[].value.messages[] is stored in the inbox, deduplicated by its unique wamid and encrypted, with a
 * rescue ad hoc task; statuses go to the status sink; Meta gets an early empty 200 and, in inline mode, the new
 * messages are processed in the same request. An internal error after a row is saved is never returned to Meta: the
 * rescue task retries it. With internal=1 the request is the self-ping of a long question (see internal_ping).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class intake {
    /** @var int Seconds after which the rescue task looks at a stored message. */
    public const RESCUE_DELAY = 90;

    /** @var string Pattern of a challenge echoed back in the handshake. */
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9._-]{1,256}$/D';

    /** @var string Table of the inbox. */
    private const TABLE = whatsapp_link_service::INBOX_TABLE;

    /**
     * Handle one request to the webhook.
     *
     * @param string $method HTTP method.
     * @param array $query Query parameters; PHP names hub.mode, hub.verify_token and hub.challenge with underscores.
     * @param string $rawbody Raw body, exactly as received (php://input).
     * @param string|null $signature X-Hub-Signature-256 header; null when missing.
     * @param string|null $internalsignature X-Jedomi-Signature header of an internal ping; null when missing.
     */
    public function handle(
        string $method,
        array $query,
        string $rawbody,
        ?string $signature,
        ?string $internalsignature = null
    ): void {
        if (self::param($query, 'internal') === '1') {
            // The self-ping of a long question, authenticated by the internal HMAC only (never by Meta's signature).
            (new internal_ping())->handle($method, $rawbody, $internalsignature);
            return;
        }
        if ($method === 'GET') {
            $this->verify($query);
            return;
        }
        if ($method !== 'POST') {
            $this->responder()->send(405, ['Allow: GET, POST', 'Content-Type: text/plain; charset=utf-8'], '');
            return;
        }
        $this->receive($rawbody, $signature);
    }

    /**
     * Answer the verification handshake: the challenge as plain text when the mode is subscribe and the verify token
     * matches the saved one; 403 otherwise.
     *
     * @param array $query Query parameters.
     */
    private function verify(array $query): void {
        $mode = self::param($query, 'hub_mode');
        $token = self::param($query, 'hub_verify_token');
        $challenge = self::param($query, 'hub_challenge');
        $saved = manager::get_verify_token();

        $valid = $mode === 'subscribe'
            && $saved !== ''
            && hash_equals($saved, $token)
            && preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
        if (!$valid) {
            \local_jedomi_log('WhatsApp webhook: verification refused');
            $this->responder()->send(403, ['Content-Type: text/plain; charset=utf-8'], '');
            return;
        }
        $this->responder()->send(
            200,
            ['Content-Type: text/plain; charset=utf-8', 'X-Content-Type-Options: nosniff'],
            $challenge
        );
    }

    /**
     * Handle a notification of Meta.
     *
     * @param string $rawbody Raw body.
     * @param string|null $signature X-Hub-Signature-256 header.
     */
    private function receive(string $rawbody, ?string $signature): void {
        if (!meta_signature::verify($rawbody, $signature, manager::get_app_secret())) {
            \local_jedomi_log('WhatsApp webhook: invalid signature');
            $this->responder()->send(403, ['Content-Type: application/json'], '');
            return;
        }
        if (!manager::is_enabled()) {
            $this->acknowledge();
            return;
        }

        $values = self::values(json_decode($rawbody, true));
        $saved = [];
        $complete = true;
        foreach ($values as $value) {
            $complete = $this->store_messages($value, $saved) && $complete;
        }
        foreach ($values as $value) {
            $this->record_statuses($value);
        }

        if ($complete) {
            $this->acknowledge();
        } else {
            // A message could not be stored: an error status makes Meta deliver the notification again, and the
            // messages already stored are deduplicated then.
            $this->responder()->send_and_close(500, http_responder::closing_headers('application/json', ''), '', true);
        }

        if (!$saved || manager::get_processing_mode() === manager::PROCESSING_QUEUE) {
            return;
        }
        $processor = new inbox_processor();
        foreach ($saved as $inboxid) {
            try {
                $processor->process($inboxid);
            } catch (\Throwable $e) {
                // The rescue task retries the row.
                \local_jedomi_log('WhatsApp webhook: inline processing failed', [
                    'row' => $inboxid,
                    'error' => safe_error::describe($e),
                ]);
            }
        }
    }

    /**
     * Store the new messages of a change value, each with its rescue task.
     *
     * @param array $value entry[].changes[].value.
     * @param int[] $saved Ids of the new rows to process; the new ones are appended.
     * @return bool Whether every message was stored (or was already in the inbox).
     */
    private function store_messages(array $value, array &$saved): bool {
        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
        $complete = true;
        foreach ($messages as $message) {
            if (!is_array($message) || !inbound_parser::is_wamid($message['id'] ?? null)) {
                continue;
            }
            try {
                $inboxid = $this->store_message($message, $contacts);
            } catch (\Throwable $e) {
                \local_jedomi_log('WhatsApp webhook: message not stored', ['error' => safe_error::describe($e)]);
                $complete = false;
                continue;
            }
            if ($inboxid !== null) {
                $saved[] = $inboxid;
            }
        }
        return $complete;
    }

    /**
     * Hand the statuses of a change value to the status sink; a failure is logged and never returned to Meta.
     *
     * @param array $value entry[].changes[].value.
     */
    private function record_statuses(array $value): void {
        try {
            foreach (status_parser::parse($value) as $status) {
                self::status_sink()->record($status);
            }
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp webhook: status not recorded', ['error' => safe_error::describe($e)]);
        }
    }

    /**
     * Store one message with its rescue task in one transaction, so a row is never left without its rescue. A message
     * without BSUID nor phone is stored as failed and without payload, so a retry of Meta is still deduplicated but
     * nothing is processed. When the wamid is already in the inbox, no row is added; a row that is still recoverable
     * gets its rescue queued again, in case the request that stored it died before queueing it.
     *
     * @param array $message Raw message.
     * @param array $contacts Raw value.contacts[].
     * @return int|null Id of the row to process; null for a settled duplicate or a message that cannot be processed.
     */
    private function store_message(array $message, array $contacts): ?int {
        global $DB;
        $wamid = $message['id'];
        $existing = $DB->get_record(self::TABLE, ['wamid' => $wamid], 'id, status, timeprocessed');
        if ($existing) {
            return $this->rescue_duplicate($existing);
        }
        $bsuid = inbound_parser::sender_bsuid($message, $contacts);
        $processable = $bsuid !== '' || inbound_parser::sender_waid($message, $contacts) !== null;
        $contact = inbound_parser::contact_for($message, $contacts);
        $now = \core\di::get(\core\clock::class)->time();
        $row = (object) [
            'wamid' => $wamid,
            'bsuid' => $bsuid,
            'payload' => $processable
                ? secret_store::encrypt(json_encode(['message' => $message, 'contact' => $contact ?: null]))
                : '',
            'status' => $processable ? inbox_processor::STATUS_PENDING : inbox_processor::STATUS_FAILED,
            'attempts' => 0,
            'timecreated' => $now,
            'timeprocessed' => $processable ? 0 : $now,
        ];
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $id = (int) $DB->insert_record(self::TABLE, $row);
                if ($processable) {
                    $this->queue_rescue($id);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } catch (\dml_write_exception $e) {
            // Another request stored the same wamid, with its rescue, in the meantime.
            if ($DB->record_exists(self::TABLE, ['wamid' => $wamid])) {
                return null;
            }
            throw $e;
        }
        if (!$processable) {
            \local_jedomi_log('WhatsApp webhook: message without sender identity stored as failed');
            return null;
        }
        return $id;
    }

    /**
     * Queue again the rescue of a row already in the inbox when it is still pending, or processing for longer than
     * LOCK_TTL; a rescue already queued for it is kept.
     *
     * @param \stdClass $row Existing row (id, status, timeprocessed).
     * @return int|null Id of the row to process; null when it is done, failed or claimed right now.
     */
    private function rescue_duplicate(\stdClass $row): ?int {
        $now = \core\di::get(\core\clock::class)->time();
        $recoverable = $row->status === inbox_processor::STATUS_PENDING
            || ($row->status === inbox_processor::STATUS_PROCESSING
                && (int) $row->timeprocessed < $now - inbox_processor::LOCK_TTL);
        if (!$recoverable) {
            return null;
        }
        $this->queue_rescue((int) $row->id);
        return (int) $row->id;
    }

    /**
     * Queue the rescue task of a row, unless one is already queued for it.
     *
     * @param int $inboxid Row id.
     */
    protected function queue_rescue(int $inboxid): void {
        process_whatsapp_inbox::queue($inboxid, self::RESCUE_DELAY, true);
    }

    /**
     * Values of the changes of the messages field of a decoded notification.
     *
     * @param mixed $payload Decoded body.
     * @return array[]
     */
    private static function values(mixed $payload): array {
        if (
            !is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account'
            || !is_array($payload['entry'] ?? null)
        ) {
            return [];
        }
        $values = [];
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                continue;
            }
            foreach ($entry['changes'] as $change) {
                if (is_array($change) && ($change['field'] ?? null) === 'messages' && is_array($change['value'] ?? null)) {
                    $values[] = $change['value'];
                }
            }
        }
        return $values;
    }

    /**
     * Send the early empty 200 and close the connection.
     */
    private function acknowledge(): void {
        $this->responder()->send_and_close(200, http_responder::closing_headers('application/json', ''), '', true);
    }

    /**
     * A string query parameter; empty when missing or not a string.
     *
     * @param array $query Query parameters.
     * @param string $name Name.
     * @return string
     */
    private static function param(array $query, string $name): string {
        return is_string($query[$name] ?? null) ? $query[$name] : '';
    }

    /**
     * The responder of the container.
     *
     * @return http_responder
     */
    private function responder(): http_responder {
        return \core\di::get(http_responder::class);
    }

    /**
     * The status sink registered in the container, or the default one that logs failed statuses.
     *
     * @return status_sink
     */
    private static function status_sink(): status_sink {
        $container = \core\di::get_container();
        return $container->has(status_sink::class) ? $container->get(status_sink::class) : new log_status_sink();
    }
}
