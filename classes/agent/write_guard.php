<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\agent;

use local_jedomi\local\service\pending_action_service;

/**
 * Guard of the writes of one scheduled agent run (JEDOMI-SEC-001).
 *
 * Every tool call of the run offered to the role of the creator goes through run() (a tool that is not offered is
 * rejected by tool_executor and never held). Reading tools run. A write (any tool call with a severity, see
 * pending_action_service::severity()) runs without confirmation only when:
 *
 * - it is a web service function approved for the agent by its creator (agent_manager::approved_writes()), and
 * - its targets are inside the fixed scope of the agent (write_scope), and
 * - the run stays within MAX_WRITES_PER_RUN writes and MAX_RECIPIENTS_PER_RUN distinct recipients.
 *
 * A write outside the scope is rejected. Any other write is held as a pending action of the creator, who approves or
 * rejects it in the Jedomi web chat. Going over a cap stops the run (write_cap_exceeded). Each write is logged without
 * its content and notify_creator() sends the creator a summary of the run.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_guard {
    /** @var int Approved writes one run may make. */
    public const MAX_WRITES_PER_RUN = 20;

    /** @var int Distinct recipients (other than the creator) the approved writes of one run may reach. */
    public const MAX_RECIPIENTS_PER_RUN = 50;

    /** @var \stdClass Agent record. */
    private \stdClass $agent;

    /** @var string[] Web service functions approved for the agent. */
    private array $approved;

    /** @var write_scope Scope of the agent. */
    private write_scope $scope;

    /** @var array[] Approved writes run: function, recipients, ok. */
    private array $done = [];

    /** @var array[] Writes held for the creator: tool, name. */
    private array $held = [];

    /** @var array[] Writes rejected outside the scope: tool, function. */
    private array $blocked = [];

    /** @var int[] Distinct recipients of the approved writes run, by id. */
    private array $recipients = [];

    /** @var bool Whether a cap stopped the run. */
    private bool $stopped = false;

    /**
     * Guard of a run of an agent.
     *
     * @param \stdClass $agent Agent record (id, userid, name, context_type, context_id, approvedwrites).
     */
    public function __construct(\stdClass $agent) {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $this->agent = $agent;
        $this->approved = agent_manager::approved_writes($agent);
        $this->scope = new write_scope($agent);
    }

    /**
     * Run, hold or reject a tool call.
     *
     * @param string $tool Tool name as the model called it.
     * @param array $args Tool arguments (with the real values).
     * @param callable $execute Runs the tool and returns its result: function(): string.
     * @return string Tool result for the AI.
     * @throws write_cap_exceeded When this approved write would exceed a cap of the run, or too many writes are held.
     */
    public function run(string $tool, array $args, callable $execute): string {
        [$tool, $args] = pending_action_service::normalise($tool, $args);
        $severity = pending_action_service::severity($tool, $args);
        if ($severity === null) {
            return $execute();
        }

        $function = $tool === 'ws_execute' ? (string) $args['wsname'] : '';
        if ($function === '' || !in_array($function, $this->approved, true)) {
            return $this->hold($tool, $args, 'not approved');
        }
        $params = tool\ws_tool::decode_params($args['params'] ?? null);
        $scope = $params === null ? ['status' => write_scope::UNKNOWN, 'recipients' => []] : $this->scope->check($params);
        if ($scope['status'] === write_scope::OUT) {
            $this->blocked[] = ['function' => $function];
            $this->log("write {$function} rejected (outside the agent scope)");
            return json_encode([
                'error' => 'write_out_of_scope',
                'tool' => $tool,
                'detail' => get_string('agent_detail_out_of_scope', 'local_jedomi'),
            ]);
        }
        if ($scope['status'] === write_scope::UNKNOWN) {
            return $this->hold($tool, $args, 'scope not verified');
        }

        $recipients = $this->recipients;
        foreach ($scope['recipients'] as $userid) {
            $recipients[$userid] = $userid;
        }
        if (count($this->done) + 1 > self::MAX_WRITES_PER_RUN || count($recipients) > self::MAX_RECIPIENTS_PER_RUN) {
            $this->stop("write {$function} over the caps of the run");
        }
        $this->recipients = $recipients;

        $result = $execute();
        $decoded = json_decode($result, true);
        $ok = is_array($decoded) && !isset($decoded['error']);
        $this->done[] = ['function' => $function, 'recipients' => count($scope['recipients']), 'ok' => $ok];
        $this->log("approved write {$function} " . ($ok ? 'ran' : 'failed') . ' (' . count($scope['recipients'])
            . ' recipients)');
        return $result;
    }

    /**
     * Approved writes run, in order.
     *
     * @return array[] Each with function, recipients (count) and ok.
     */
    public function get_done(): array {
        return $this->done;
    }

    /**
     * Writes held for the creator, in order.
     *
     * @return array[] Each with tool and name (the tool, or the web service function).
     */
    public function get_held(): array {
        return $this->held;
    }

    /**
     * Writes rejected outside the scope, in order.
     *
     * @return array[] Each with function.
     */
    public function get_blocked(): array {
        return $this->blocked;
    }

    /**
     * Send the creator a Moodle notification with the writes of the run: run, held for approval and rejected, and
     * whether a cap stopped the run. Nothing is sent when the run had no write. Contents of the writes are never sent.
     *
     * @return bool Whether a notification was sent.
     */
    public function notify_creator(): bool {
        if (empty($this->done) && empty($this->held) && empty($this->blocked) && !$this->stopped) {
            return false;
        }
        $creator = \core_user::get_user((int) $this->agent->userid);
        if (!$creator || !empty($creator->deleted)) {
            return false;
        }
        try {
            [$subject, $text] = \local_jedomi\local\user_language::run((int) $creator->id, fn(): array => $this->summary());
            $message = new \core\message\message();
            $message->component = 'local_jedomi';
            $message->name = 'agentwrites';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $creator;
            $message->subject = $subject;
            $message->fullmessage = $text;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = text_to_html(s($text), false, false, true);
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = (new \moodle_url('/my/'))->out(false);
            $message->contexturlname = get_string('agent_writes_open_chat', 'local_jedomi');
            return (bool) message_send($message);
        } catch (\Throwable $e) {
            $this->log('summary notification failed (' . get_class($e) . ')');
            return false;
        }
    }

    /**
     * Subject and text of the summary, in the current language.
     *
     * @return array Subject and text.
     */
    private function summary(): array {
        $name = trim((string) ($this->agent->name ?? '')) ?: '#' . (int) $this->agent->id;
        $lines = [];
        if ($this->stopped) {
            $lines[] = get_string('agent_writes_stopped', 'local_jedomi');
            $lines[] = '';
        }
        if (!empty($this->done)) {
            $lines[] = get_string('agent_writes_done_heading', 'local_jedomi', count($this->done));
            foreach ($this->done as $write) {
                $lines[] = '- ' . $write['function'] . ' (' . get_string(
                    $write['ok'] ? 'agent_writes_ok' : 'agent_writes_failed',
                    'local_jedomi',
                    $write['recipients']
                ) . ')';
            }
            $lines[] = '';
        }
        if (!empty($this->held)) {
            $lines[] = get_string('agent_writes_held_heading', 'local_jedomi', count($this->held));
            foreach ($this->held as $write) {
                $lines[] = '- ' . $write['name'];
            }
            $lines[] = get_string('agent_writes_held_help', 'local_jedomi');
            $lines[] = '';
        }
        if (!empty($this->blocked)) {
            $lines[] = get_string('agent_writes_blocked_heading', 'local_jedomi', count($this->blocked));
            foreach ($this->blocked as $write) {
                $lines[] = '- ' . $write['function'];
            }
        }
        return [get_string('agent_writes_subject', 'local_jedomi', $name), trim(implode("\n", $lines))];
    }

    /**
     * Hold a write for the creator of the agent.
     *
     * @param string $tool Canonical tool name.
     * @param array $args Canonical arguments.
     * @param string $why Reason for the technical log.
     * @return string Tool result for the AI.
     * @throws write_cap_exceeded When the run already held as many writes as the cap of approved writes.
     */
    private function hold(string $tool, array $args, string $why): string {
        if (count($this->held) >= self::MAX_WRITES_PER_RUN) {
            $this->stop("too many writes held ({$tool})");
        }
        pending_action_service::hold((int) $this->agent->userid, $tool, $args, (int) $this->agent->id);
        // The summary names the tool or function only: the arguments were chosen by the AI and may quote third-party
        // content; the creator sees them in the confirmation card of the web chat.
        $this->held[] = ['tool' => $tool, 'name' => $tool === 'ws_execute' ? (string) $args['wsname'] : $tool];
        $this->log("write {$tool}" . ($tool === 'ws_execute' ? ' ' . $args['wsname'] : '') . " held for the creator ({$why})");
        return json_encode([
            'error' => 'confirmation_required',
            'tool' => $tool,
            'detail' => get_string('agent_detail_held', 'local_jedomi'),
        ]);
    }

    /**
     * Stop the run because a cap was reached.
     *
     * @param string $what What reached the cap, for the technical log.
     * @return void
     * @throws write_cap_exceeded Always.
     */
    private function stop(string $what): void {
        $this->stopped = true;
        $this->log("STOPPED: {$what}");
        throw new write_cap_exceeded(get_string('agent_write_cap_reached', 'local_jedomi', [
            'writes' => self::MAX_WRITES_PER_RUN,
            'recipients' => self::MAX_RECIPIENTS_PER_RUN,
        ]));
    }

    /**
     * Write a line to the technical log; never the arguments of a write.
     *
     * @param string $text Line, without the agent label.
     * @return void
     */
    private function log(string $text): void {
        \local_jedomi_log("[AGENT] Agent #{$this->agent->id} — {$text}");
    }
}
