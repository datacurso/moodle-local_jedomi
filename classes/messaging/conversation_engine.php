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
 * Channel-neutral conversation engine: the AI and tools loop of a chat.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

use local_jedomi\local\anonymizer;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Runs the conversation of a chat with the AI through the pieces of its channel: history and state in the store of
 * the channel, tools gated by the confirmation service and the tool runner, answers through the transport.
 *
 * A worker runs the loop until the hard guard (or the medium guard after its first round), saves the state, still
 * locked, and hands the rest to the next worker through the continuation of the channel. All times come from the
 * Moodle clock.
 */
class conversation_engine {
    /** Lock TTL in seconds — stale locks older than this are auto-released. */
    public const LOCK_TTL = 120;

    /** Seconds a worker runs the tool loop before it hands the rest to the next worker. */
    public const HARD_GUARD = 55;

    /** Seconds after which a worker hands the loop over at the top of a round other than its first. */
    public const MEDIUM_GUARD = 40;

    /** Seconds after which the user is told once that the assistant is still working. */
    public const KEEPALIVE_AFTER = 30;

    /** Longest AI call that fits in one worker (the hard guard less a margin), the top of the timeout settings. */
    public const MAX_AI_TIMEOUT = self::HARD_GUARD - 5;

    /** Seconds a question may take over all its workers; then it stops with a message. */
    public const QUESTION_TIME_LIMIT = 600;

    /** Rounds of the loop of a question, over all its workers. */
    public const MAX_ITERATIONS = 50;

    /**
     * Constructor.
     *
     * @param channel $channel Channel of the chats this engine runs.
     */
    public function __construct(
        /** @var channel Channel of the chats. */
        private readonly channel $channel,
    ) {
    }

    /**
     * Continue the saved tool loop of a chat in a new worker, in the language already chosen by the caller.
     *
     * The loop goes on only while the channel serves, the leg belongs to the current session of the chat, and the chat
     * is still linked to the same active user with an allowed role.
     *
     * @param string $chatkey Chat key of the channel, already validated by the channel.
     * @param int $incominggen Session generation the leg was scheduled with; 0 when unknown.
     * @return void
     */
    public function resume(string $chatkey, int $incominggen): void {
        $denial = $this->channel->access_guard()->channel_denial();
        if ($denial !== null && $denial['code'] === access_guard::INTEGRATION_DISABLED) {
            return;
        }
        if ($denial !== null) {
            // Disabled in the Datacurso AI provider: do not continue the tool loop with the AI.
            $this->channel->transport()->send_text($chatkey, $denial['message']);
            return;
        }

        $state = $this->channel->store()->get_context('tool_loop_' . $chatkey);
        if (empty($state) || empty($state['messages'])) {
            return; // Nothing to continue.
        }

        // Validate session_gen (TOCTOU guard for /new race).
        $expectedgen = (int)($state['session_gen'] ?? 0);
        if ($expectedgen > 0 && $incominggen > 0 && $incominggen !== $expectedgen) {
            \local_jedomi_log('[GATELOOP] session_gen mismatch chat=' . self::chatref($chatkey) . ' expected=' . $expectedgen
                . ' got=' . $incominggen . ' — aborting stale self-ping');
            return;
        }

        // The task continues only while the chat is still linked to the same active user with an allowed role.
        $linkeduserid = $this->channel->linked_userid($chatkey);
        $user = $linkeduserid !== null ? $this->channel->access_guard()->active_user($linkeduserid) : null;
        $stateuserid = (int) ($state['userid'] ?? 0);
        if (
            !$user || ($stateuserid > 0 && $stateuserid !== (int) $user->id)
            || !$this->channel->access_guard()->role_allowed((int) $user->id)
        ) {
            \local_jedomi_log('[GATELOOP] chat ' . self::chatref($chatkey)
                . ' no longer linked to an active user, task stopped');
            $this->channel->store()->set_context('tool_loop_' . $chatkey, []);
            return;
        }
        $userid = (int) $user->id;

        // Impersonate user so permission checks work.
        global $USER;
        $USER = $user;

        $this->run($userid, $chatkey, '', true);
    }

    /**
     * Main loop: sends messages to the AI service through the AI client, handles tool calls, answers through the
     * transport, and hands the loop to the next worker when approaching the FPM timeout, so a question survives across
     * several PHP-FPM workers.
     *
     * @param int $userid Moodle user ID.
     * @param string $chatkey Chat key of the channel.
     * @param string $text User message text (photos and audio are already text).
     * @param bool $resume True when a new worker continues the saved loop.
     * @param array $readmap Placeholders the caption of a file got when it was read (placeholder => real value).
     * @return void
     */
    public function run(int $userid, string $chatkey, string $text, bool $resume = false, array $readmap = []): void {
        global $DB;

        $statekey = 'tool_loop_' . $chatkey;

        // ... ── Normal flow: build messages & context from scratch ──
        if (!$resume) {
            // Check if another worker is already processing this chat.
            $existing = $this->channel->store()->get_context($statekey);
            if (!empty($existing['is_locked'])) {
                $lockage = self::now() - ($existing['locked_at'] ?? self::now());
                if ($lockage < self::LOCK_TTL) {
                    // Lock is still valid (< 2 min) — block.
                    $this->channel->transport()->send_text(
                        $chatkey,
                        $this->channel->message(channel::MESSAGE_STILL_PROCESSING)
                    );
                    return;
                }
                // Lock expired (> LOCK_TTL seconds) — auto-release and continue.
                \local_jedomi_log('[GATELOOP] stale_lock chat=' . self::chatref($chatkey) . ' age=' . $lockage
                    . 's — auto-releasing');
                $this->channel->store()->set_context($statekey, []);
                // Fall through to process the new message.
            }

            // ... ── Send typing indicator so user knows it's processing ──
            $this->channel->transport()->send_activity($chatkey);

            // Build messages for the Gateway.
            $messages = $this->messages_for_ai($chatkey, $text);

            // Persist user message.
            $this->channel->store()->append($chatkey, 'user', $text);

            // ... ── Current role and courses: computed on every message, never cached ──
            $userctx = $this->channel->tool_runner()->user_context($userid);
            $role = $userctx['role'];
            $spt = $this->channel->tool_runner()->signed_permissions(
                $userid,
                $role,
                $userctx['course_ids_owned'],
                $userctx['course_ids_enrolled']
            );
            $tools = $spt['tools'] ?? [];

            $timeout = $this->channel->ai_timeout();

            // ... ── Restore checkpoint if a previous attempt was killed ──
            $anonymizermap = [];
            try {
                $checkpoint = $this->channel->store()->get_context($statekey);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] checkpoint read FAILED for chat ' . self::chatref($chatkey) . ': '
                    . \local_jedomi\local\safe_error::describe($e));
                $checkpoint = null;
            }
            if (!empty($checkpoint) && !empty($checkpoint['messages'])) {
                $lastusermsg = '';
                for ($i = count($checkpoint['messages']) - 1; $i >= 0; $i--) {
                    if (
                        ($checkpoint['messages'][$i]['role'] ?? '') === 'user'
                        && is_string($checkpoint['messages'][$i]['content'] ?? null)
                    ) {
                        $lastusermsg = $checkpoint['messages'][$i]['content'];
                        break;
                    }
                }
                if ($lastusermsg === $text) {
                    $messages = $checkpoint['messages'];
                    $anonymizermap = (array) ($checkpoint['anonymizer'] ?? []);
                    $cpjson = json_encode($checkpoint, JSON_UNESCAPED_UNICODE);
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] checkpoint restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                        self::chatref($chatkey),
                        $checkpoint['iteration'] ?? 0,
                        strlen($cpjson),
                        count($checkpoint['messages'])
                    ));
                } else {
                    $this->channel->store()->set_context($statekey, []);
                }
            }

            // The placeholders the caption of a file got when it was read keep their values for the whole turn.
            $anonymizermap = $readmap + $anonymizermap;

            // Init state (iteration counter, lock, cached SPT for self-pings).
            $state = [
                'anonymizer'     => $anonymizermap,
                'started_at'     => self::now(),
                'is_locked'      => true,
                'locked_at'      => self::now(),
                'iteration'      => 0,
                'max_iterations' => self::MAX_ITERATIONS,
                'messages'       => $messages,
                'userid'         => $userid,
                'spt'            => $spt,
                'tools'          => $tools,
            ];

            // ... ── Self-ping flow: restore messages & context from state ──
        } else {
            $state = $this->channel->store()->get_context($statekey);
            if (empty($state) || empty($state['messages'])) {
                \local_jedomi_log('[GATELOOP] self-ping empty state for chat ' . self::chatref($chatkey) . ', nothing to continue');
                return; // Nothing to continue.
            }
            $messages = $state['messages'];
            $statejsoncheck = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] self-ping restored chat=%s iter=%d state_bytes=%d msg_count=%d',
                self::chatref($chatkey),
                $state['iteration'] ?? 0,
                strlen($statejsoncheck),
                count($messages)
            ));

            // ... ── The role, courses and tools are those the user has now, not those of the checkpoint ──
            $userctx = $this->channel->tool_runner()->user_context($userid);
            $role = $userctx['role'];
            $spt = $this->channel->tool_runner()->signed_permissions(
                $userid,
                $role,
                $userctx['course_ids_owned'],
                $userctx['course_ids_enrolled']
            );
            $tools = $spt['tools'] ?? [];

            $timeout = $this->channel->ai_timeout();

            // A state saved before the time limit existed starts counting now.
            $state['started_at'] = (int) ($state['started_at'] ?? self::now());

            // Ensure lock is active.
            $state['is_locked'] = true;
            $state['locked_at'] = self::now();
        }

        // ... ── Build system message with course context (same for both flows) ──
        $toolnames = array_map(fn(array $t): string => $t['function']['name'], $tools);
        $coursesbyrole = $userctx['courses_by_role'];
        $userlang = $DB->get_field('user', 'lang', ['id' => $userid]) ?: current_language();

        $systemcontent = \local_jedomi\messaging\prompt_builder::build(
            $role,
            $userlang,
            $toolnames,
            $coursesbyrole,
            $this->channel->limits()
        );

        $messages[0] = ['role' => 'system', 'content' => $systemcontent];

        // Personal data returned by the tools reaches the AI anonymised with the level of the Privacy tab; the
        // placeholders are kept in the state of the conversation, so a later worker (self-ping) can still turn the
        // arguments the AI proposes and its final answer back into real data.
        $anonymizer = anonymizer::from_map((array) ($state['anonymizer'] ?? []));
        // Tool results reach the AI as untrusted data, as in the web chat and the agents (JEDOMI-SEC-005). Blocks saved
        // by an earlier worker keep their own marker: the rule of the instructions covers any marker.
        $untrusted = new \local_jedomi\local\untrusted();

        // ... ── Tool loop uses state-based iteration ──
        // Each HTTP worker runs until ~55s, then self-pings so a new worker
        // picks up at the saved iteration. The user sees a single seamless response.
        $loopstart = self::now();
        $maxiterations = self::MAX_ITERATIONS;
        $hardguard = self::HARD_GUARD; // Seconds — chain to next worker before FPM kills us.

        // Extend PHP execution limit to cover our hard guard + buffer.
        // Without this, php max_execution_time or fpm request_terminate_timeout
        // (often 30s on shared hosts) kills the worker while cURL waits,
        // producing NO error log — just a silent hang.
        $oldlimit = @ini_get('max_execution_time');
        @set_time_limit($hardguard + 20); // 75s total, safe for most hosts
        \local_jedomi_log(sprintf(
            '[GATELOOP] time_limit: old=%s new=%d hard_guard=%d',
            $oldlimit ?: 'unlimited',
            $hardguard + 20,
            $hardguard
        ));

        $toolrepeatcounts = [];
        // The same call failing with the same error stops the question; the counts follow the state across workers.
        $toolerrors = new \local_jedomi\local\repeated_tool_error((array) ($state['tool_errors'] ?? []));
        $senttimeoutwarning = false;
        // After a "typing" status fails, no other one is sent for the question in this worker.
        $typingok = true;

        for ($iteration = (int)($state['iteration'] ?? 0); $iteration < $maxiterations; $iteration++) {
            $elapsed = self::now() - $loopstart;

            if ($iteration > 0) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] iter_top chat=%s iter=%d elapsed=%ds msg_count=%d',
                    self::chatref($chatkey),
                    $iteration,
                    $elapsed,
                    count($messages)
                ));
            }

            // ... ── Time limit of the question, over all its workers: a clear message instead of a chat that never ends ──
            $questionelapsed = self::now() - (int) ($state['started_at'] ?? self::now());
            if ($questionelapsed >= self::QUESTION_TIME_LIMIT) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] question_time_limit chat=%s iter=%d elapsed=%ds',
                    self::chatref($chatkey),
                    $iteration,
                    $questionelapsed
                ));
                $this->release_lock(
                    $statekey,
                    $chatkey,
                    get_string('error_question_time_limit', 'local_jedomi', (int) ceil(self::QUESTION_TIME_LIMIT / 60))
                );
                return;
            }

            // ... ── Hard guard (~55s): save state + self_ping → next worker ──
            // IMPORTANT: save $iteration + 1 (NEXT expected iteration),
            // NOT $iteration (current). The for loop increments AFTER the
            // body, so saving $iteration means the next worker re-does it.
            if ($elapsed >= $hardguard) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] hard_guard chat=%s iter=%d elapsed=%d self-pinging',
                    self::chatref($chatkey),
                    $iteration,
                    $elapsed
                ));
                if (!$this->channel->serves($chatkey, $userid)) {
                    $this->stop_without_access($statekey, $chatkey);
                    return;
                }
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = self::now();
                $state['anonymizer'] = $anonymizer->export_map();
                $this->channel->store()->set_context($statekey, $state);
                $this->hand_over($chatkey);
                return;
            }

            // ... ── Medium guard (~40s): self-ping so next worker continues
            // before FPM request_terminate_timeout silently kills this worker.
            // Only on internal iterations (>0).
            if ($elapsed >= self::MEDIUM_GUARD && $iteration > 0) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] medium_guard chat=%s iter=%d elapsed=%d self-pinging',
                    self::chatref($chatkey),
                    $iteration,
                    $elapsed
                ));
                if (!$this->channel->serves($chatkey, $userid)) {
                    $this->stop_without_access($statekey, $chatkey);
                    return;
                }
                $state['iteration'] = $iteration + 1;
                $state['messages'] = $messages;
                $state['is_locked'] = true;
                $state['locked_at'] = self::now();
                $state['anonymizer'] = $anonymizer->export_map();
                $this->channel->store()->set_context($statekey, $state);
                $this->hand_over($chatkey);
                return;
            }

            // ... ── Soft guard (~30s): send a keepalive so the user knows we're alive ──
            if ($elapsed >= self::KEEPALIVE_AFTER && !$senttimeoutwarning) {
                $senttimeoutwarning = true;
                $this->channel->transport()->send_text($chatkey, $this->channel->message(channel::MESSAGE_KEEPALIVE));
            }

            // ... ── Re-send typing indicator each iteration (Telegram auto-expires after ~6s) ──
            // Only on iterations after the first (avoids double-send with the initial one).
            if ($iteration > 0 && $typingok) {
                $typingok = $this->send_typing($chatkey);
            }

            // Adaptive AI timeout: shrink as we approach the hard guard
            // so we don't get stuck waiting when FPM is about to kill us,
            // and never past the time limit of the question.
            $aitimeout = max(5, min(
                $timeout,
                $hardguard - (self::now() - $loopstart) - 5,
                self::QUESTION_TIME_LIMIT - $questionelapsed
            ));

            \local_jedomi_log(sprintf(
                '[GATELOOP] calling_ai chat=%s iter=%d elapsed=%ds timeout=%ds msg_count=%d',
                self::chatref($chatkey),
                $iteration,
                self::now() - $loopstart,
                $aitimeout,
                count($messages)
            ));

            // While the AI call waits, the chat shows "typing" again (it expires after about 5 seconds).
            \local_jedomi\ai\heartbeat::start($this->channel->activity_interval(), function () use ($chatkey, &$typingok): void {
                if ($typingok) {
                    $typingok = $this->send_typing($chatkey);
                }
            });
            try {
                // The question and the history keep the real values in the state; the AI gets them anonymised with
                // the placeholders of the conversation, as the tool results.
                $answer = \local_jedomi\ai\client::instance()->complete(
                    $anonymizer->anonymize_messages($messages),
                    $userid,
                    $tools,
                    $aitimeout,
                    0.2
                );
            } catch (\Throwable $e) {
                $error = \local_jedomi\ai\error_message::from_exception($e, $userlang);
                // Every failure, including a timeout, ends the turn: the request is never resent,
                // because the AI service may already have processed (and billed) it.
                \local_jedomi_log('[AI] error on iter ' . $iteration . ': ' . $error['code']);
                $this->release_lock($statekey, $chatkey, $error['message']);
                return;
            } finally {
                \local_jedomi\ai\heartbeat::stop();
            }

            // The access may have changed while the AI answered: nothing more runs and nothing is sent.
            if (!$this->channel->serves($chatkey, $userid)) {
                $this->stop_without_access($statekey, $chatkey);
                return;
            }

            // An answer without text is asked again once, briefly; a second one ends the question with a clear message.
            if (\local_jedomi\ai\empty_answer::is_empty($answer)) {
                \local_jedomi_log('[AI] empty answer on iter ' . $iteration . ' finish=' . ($answer['finish_reason'] ?? ''));
                if (!empty($state['empty_retried'])) {
                    $this->release_lock($statekey, $chatkey, get_string('error_empty_answer', 'local_jedomi'));
                    return;
                }
                $state['empty_retried'] = true;
                $messages[] = \local_jedomi\ai\empty_answer::retry_message((string) ($answer['finish_reason'] ?? ''));
                continue;
            }

            $result = self::parse_answer($answer);
            if ($result === null) {
                \local_jedomi_log('[AI] empty answer on iter ' . $iteration);
                $this->release_lock($statekey, $chatkey, $this->channel->message(channel::MESSAGE_NO_ANSWER));
                return;
            }

            if ($result['type'] === 'tool_calls') {
                $message = ['tool_calls' => $result['tool_calls']];
                $content = '';
                $hastools = true;
            } else {
                $message = ['content' => $result['content'] ?? ''];
                $content = $result['content'] ?? '';
                $hastools = false;

                // ... ── Fallback: text-based TOOL_CALL parsing (for models that
                // don't support native function calling). ────────────────
                $tcpattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';
                $tcpattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

                $toolcalls = [];
                $remainingtext = $content;

                // Try Pattern 1: TOOL_CALL: ...
                if (preg_match_all($tcpattern1, $content, $tcmatches, PREG_SET_ORDER)) {
                    $remainingtext = trim(preg_replace($tcpattern1, '', $content));
                    foreach ($tcmatches as $m) {
                        $toolname = trim($m[1]);
                        $argsjson = trim($m[2]);
                        json_decode($argsjson);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                                $argsjson = $jsonmatch[0];
                            }
                        }
                        json_decode($argsjson);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolcalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolname,
                                    'arguments' => $argsjson,
                                ],
                            ];
                        }
                    }
                }

                // Try Pattern 2 (broader): allow JSON inline after `call:` with optional newline
                // before END_TOOL_CALL. Handles variations where the model omits newlines.
                if (empty($toolcalls)) {
                    $tcpattern1b = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)(?:\n\s*)?END_TOOL_CALL[^\n]*/si';
                    if (preg_match_all($tcpattern1b, $content, $tcmatches2, PREG_SET_ORDER)) {
                        $remainingtext = trim(preg_replace($tcpattern1b, '', $content));
                        foreach ($tcmatches2 as $m) {
                            $toolname = trim($m[1]);
                            $argsjson = trim($m[2]);
                            json_decode($argsjson);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                                    $argsjson = $jsonmatch[0];
                                }
                            }
                            json_decode($argsjson);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolcalls[] = [
                                    'id' => 'text_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolname,
                                        'arguments' => $argsjson,
                                    ],
                                ];
                            }
                        }
                    }
                }

                // Try Pattern 3: <|tool_call|> (Llama 3 style) — only if patterns 1&2 didn't match.
                if (empty($toolcalls) && preg_match_all($tcpattern2, $content, $tcmatches, PREG_SET_ORDER)) {
                    $remainingtext = trim(preg_replace($tcpattern2, '', $content));
                    foreach ($tcmatches as $m) {
                        $inner = trim($m[1]);
                        $decoded = json_decode($inner, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                            $toolname = $decoded['name'];
                            $args = $decoded['arguments'] ?? '{}';
                            if (is_array($args)) {
                                $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                            }
                        } else {
                            $parts = explode("\n", $inner, 2);
                            $toolname = trim($parts[0]);
                            $args = $parts[1] ?? '{}';
                        }
                        json_decode($args);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $toolcalls[] = [
                                'id' => 'text_' . md5(uniqid('', true)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolname,
                                    'arguments' => $args,
                                ],
                            ];
                        }
                    }
                }

                // ... ── Ultra-broad fallback: if content still looks like it has tool calls
                // but no regex matched, try grabbing everything between 'call:\w+' and
                // 'END_TOOL_CALL' as raw JSON. This catches unusual line breaks/whitespace.
                if (empty($toolcalls) && preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    $tcultra = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)\s*END_TOOL_CALL\s*/si';
                    if (preg_match_all($tcultra, $content, $tcmatches3, PREG_SET_ORDER)) {
                        $remainingtext = trim(preg_replace($tcultra, '', $content));
                        foreach ($tcmatches3 as $m) {
                            $toolname = trim($m[1]);
                            // Accept whatever text is between call and END_TOOL_CALL,
                            // attempt to extract a JSON object.
                            $raw = trim($m[2]);
                            if (preg_match('/\{.*\}/s', $raw, $jsonmatch)) {
                                $raw = $jsonmatch[0];
                            }
                            json_decode($raw);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $toolcalls[] = [
                                    'id' => 'ultra_' . md5(uniqid('', true)),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $toolname,
                                        'arguments' => $raw,
                                    ],
                                ];
                            }
                        }
                    }
                }

                if (!empty($toolcalls)) {
                    $message = [
                        'tool_calls' => $toolcalls,
                        'content' => $remainingtext ?: '',
                    ];
                    $content = $remainingtext ?: '';
                    $hastools = true;
                } else if (preg_match('/\b(?:TOOL_CALL|call):\s*\w+/i', $content)) {
                    // Log when content looks like it should have tool calls but none parsed.
                    debugging(
                        $this->channel->label() . ': unparseable tool call in content — ' . mb_substr($content, 0, 500),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // ... ── Progress messages (icons + lang strings, same as web) ──
            if ($hastools && ($iteration % 5 === 0 || $iteration === 1)) {
                $tooldisplay = [];
                // Each tool has a progress label in the language of the user; a tool without one shows its name.
                $toollangkeys = [
                    'execute_sql'     => 'tool_sql_read',
                    'ws_execute'      => 'tool_api_call',
                    'search_tables'   => 'tool_schema_lookup',
                    'describe_table'  => 'tool_schema_lookup',
                    'ws_search'       => 'tool_webservice_lookup',
                    'ws_describe'     => 'tool_webservice_lookup',
                    'get_site_config' => 'tool_get_site_config',
                    'site_overview'   => 'tool_site_overview',
                    'admin_search'    => 'confirm_executing',
                    'admin_describe'  => 'confirm_executing',
                    'admin_execute'   => 'confirm_executing',
                    'list_agent'      => 'tool_progress_list_agents', // Alias hallucinated singular.
                    'create_agents'   => 'tool_progress_create_agent', // Alias hallucinated plural.
                    'delete_agents'   => 'tool_progress_delete_agent', // Alias hallucinated plural.
                ];
                foreach ($message['tool_calls'] ?? [] as $tc) {
                    $name = (string) ($tc['function']['name'] ?? '');
                    $key = $toollangkeys[$name] ?? 'tool_progress_' . $name;
                    $label = get_string_manager()->string_exists($key, 'local_jedomi') ? get_string($key, 'local_jedomi') : $name;
                    $tooldisplay[] = $label;
                }
                $progressicon = match (true) {
                    $iteration <= 1  => '🔍',
                    $iteration <= 5  => '📖',
                    $iteration <= 10 => '⚡',
                    $iteration <= 20 => '📊',
                    default          => '⚙️',
                };
                $this->channel->transport()->send_text(
                    $chatkey,
                    $progressicon . ' ' . implode(', ', $tooldisplay) . '…'
                );
            }

            if (!$hastools) {
                // ... ── Text response: send to Telegram ──

                // The answer was written from anonymised data: the user gets the real values back, without the
                // untrusted data markers it may have echoed (REL-003).
                $content = $anonymizer->restore(\local_jedomi\local\untrusted::strip_markers((string) $content));

                // Markdown and HTML of the answer become the format of the channel (Telegram: its HTML subset).
                $display = $this->channel->transport()->format_answer($content);

                // If formatting left nothing to show in the channel, send the original as plain text.
                if ($this->channel->transport()->is_blank($display)) {
                    $fallback = (is_string($content) && $content !== '')
                        ? $content : $this->channel->message(channel::MESSAGE_DONE);
                    $this->channel->store()->set_context($statekey, []);
                    $this->channel->store()->append($chatkey, 'assistant', $fallback);
                    \local_jedomi\event\chat_answered::create_for_channel($this->channel->name(), null, $userid)->trigger();
                    $this->channel->transport()->send_text($chatkey, $fallback);
                    return;
                }

                // ... ── Release lock before sending final response ──
                \local_jedomi_log('[GATELOOP] text response for chat ' . self::chatref($chatkey) . ' at iter ' . $iteration
                    . ', elapsed=' . (self::now() - $loopstart) . 's, len=' . strlen($display));
                $this->channel->store()->set_context($statekey, []);

                // The answer is kept in the history, so the next turns have the whole conversation.
                $this->channel->store()->append($chatkey, 'assistant', (string) $content);
                \local_jedomi\event\chat_answered::create_for_channel($this->channel->name(), null, $userid)->trigger();

                // A long or tabular answer is sent as a text file instead of several messages.
                $this->channel->transport()->send_answer($chatkey, $display);
                return;
            }

            // ... ── Tool calls → execute and feed results back ──
            // ── Synchronisation point 1: re-read state from DB ─────────────
            // If another worker (self-ping) already processed these tools and
            // saved a newer state, skip execution and reload their results.
            // Only trust the saved iteration if the lock is still active (< 2 min).
            // A stale iteration without an active lock means the state is from
            // a dead/crashed worker — ignore it and continue processing.
            $freshstate = $this->channel->store()->get_context($statekey);
            $isactivelylocked = !empty($freshstate['is_locked'])
                && (self::now() - ($freshstate['locked_at'] ?? 0) < self::LOCK_TTL);
            if ($isactivelylocked && ($freshstate['iteration'] ?? 0) > $iteration) {
                \local_jedomi_log(sprintf(
                    '[GATELOOP] stale_iteration chat=%s iter=%d fresh_iter=%d — reloading',
                    self::chatref($chatkey),
                    $iteration,
                    $freshstate['iteration']
                ));
                $iteration = (int)$freshstate['iteration'] - 1; // For loop increments; restore 1 less.
                $messages = $freshstate['messages'] ?? $messages;
                $anonymizer = anonymizer::from_map((array) ($freshstate['anonymizer'] ?? []));
                $toolerrors = new \local_jedomi\local\repeated_tool_error((array) ($freshstate['tool_errors'] ?? []));
                continue;
            }

            $messages[] = [
                'role'       => 'assistant',
                'content'    => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'],
            ];

            // Loop detection.
            $looping = false;
            foreach ($message['tool_calls'] as $tc) {
                $name = $tc['function']['name'] ?? '';
                $argsraw = $tc['function']['arguments'] ?? '{}';
                $args = is_array($argsraw) ? $argsraw : (json_decode($argsraw, true) ?? []);
                $tcid = $tc['id'] ?? '';
                $callkey = $name . '|' . \local_jedomi\local\repeated_tool_error::args_signature($args);
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;
                $repeatcount = $toolrepeatcounts[$callkey];
                if ($repeatcount > 8) {
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] loop_detected chat=%s iter=%d tool=%s key=%s count=%d',
                        self::chatref($chatkey),
                        $iteration,
                        $name,
                        $callkey,
                        $repeatcount
                    ));
                    $looping = true;
                    break;
                } else if ($repeatcount >= 4) {
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] loop_warning chat=%s iter=%d tool=%s key=%s count=%d',
                        self::chatref($chatkey),
                        $iteration,
                        $name,
                        $callkey,
                        $repeatcount
                    ));
                }

                // Each tool runs only while the user still has access.
                if (!$this->channel->serves($chatkey, $userid)) {
                    $this->stop_without_access($statekey, $chatkey);
                    return;
                }

                // The AI only knows the placeholders of the anonymised data; the tools need the real values.
                $unrestored = null;
                try {
                    $args = $anonymizer->restore_tool_args($name, $args);
                } catch (\local_jedomi\local\unrestored_placeholder_exception $e) {
                    // The query is not run against the text of a placeholder: the AI is told how to write it (RISK-001).
                    $unrestored = $e->tool_result();
                }

                try {
                    if ($unrestored !== null) {
                        $toolresult = $unrestored;
                    } else if (!in_array(tool_runner::canonical_tool_name($name), $toolnames, true)) {
                        // Only the tools offered to the AI for the role of the user can run.
                        $toolresult = json_encode(['error' => 'permission_denied']);
                    } else {
                        $toolresult = $this->channel->confirmation_service()->hold($chatkey, $userid, $name, $args)
                            ?? $this->channel->tool_runner()->execute(
                                $name,
                                $args,
                                $userid,
                                $role,
                                $userctx['course_ids_owned'],
                                $anonymizer
                            );
                    }
                    if (mb_strlen($toolresult) > 64000) {
                        $toolresult = mb_substr($toolresult, 0, 64000) . "\n... (truncated)";
                    }
                } catch (\Exception $e) {
                    $toolresult = 'Error executing ' . $name . ': ' . \local_jedomi\local\safe_error::for_model($e);
                }
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcid,
                    'content'      => $untrusted->wrap('tool ' . $name, $anonymizer->anonymize_tool_result($toolresult)),
                ];

                if ($toolerrors->record($callkey, $toolresult)) {
                    \local_jedomi_log(sprintf(
                        '[GATELOOP] tool_error_repeated chat=%s iter=%d tool=%s',
                        self::chatref($chatkey),
                        $iteration,
                        $name
                    ));
                    $this->release_lock($statekey, $chatkey, get_string('error_tool_repeated', 'local_jedomi', $name));
                    return;
                }
                $state['tool_errors'] = $toolerrors->export();
            }

            // ... ── Save checkpoint with updated iteration + messages ──
            $state['iteration'] = $iteration + 1;
            $state['messages'] = $messages;
            $state['is_locked'] = true;

            $statejson = json_encode($state, JSON_UNESCAPED_UNICODE);
            \local_jedomi_log(sprintf(
                '[GATELOOP] state_save chat=%s iter=%d state_bytes=%d msg_count=%d',
                self::chatref($chatkey),
                $iteration,
                strlen($statejson),
                count($messages)
            ));

            // The state of a task whose access changed (or that unlinking removed) is not written again.
            if (!$this->channel->serves($chatkey, $userid)) {
                $this->stop_without_access($statekey, $chatkey);
                return;
            }

            try {
                $state['anonymizer'] = $anonymizer->export_map();
                $this->channel->store()->set_context($statekey, $state);
            } catch (\Exception $e) {
                \local_jedomi_log('[GATELOOP] STATE SAVE FAILED for chat ' . self::chatref($chatkey)
                    . ' iter=' . $iteration . ' bytes=' . strlen($statejson)
                    . ' err=' . \local_jedomi\local\safe_error::describe($e));
                // Fallback: release lock and inform user, don't crash silently.
                $this->channel->store()->set_context($statekey, []);
                $this->channel->transport()->send_text($chatkey, $this->channel->message(channel::MESSAGE_STATE_SAVE_FAILED));
                return;
            }

            if ($looping) {
                \local_jedomi_log('[GATELOOP] loop_exit for chat ' . self::chatref($chatkey) . ' at iter ' . $iteration
                    . ', elapsed=' . (self::now() - $loopstart) . 's');
                $this->channel->store()->set_context($statekey, []);
                $this->channel->transport()->send_text($chatkey, $this->channel->message(channel::MESSAGE_MAX_ITERATIONS));
                return;
            }
        }

        // ... ── Max iterations reached ──
        \local_jedomi_log('[GATELOOP] max iterations reached for chat ' . self::chatref($chatkey));
        $this->channel->store()->set_context($statekey, []);
        $this->channel->transport()->send_text($chatkey, $this->channel->message(channel::MESSAGE_MAX_ITERATIONS));
    }

    /**
     * Messages for the AI: a system message (always replaced by the prompt of the channel before any AI call), the
     * history of the chat and the current message.
     *
     * @param string $chatkey Chat key of the channel.
     * @param string $text Current message.
     * @return array
     */
    private function messages_for_ai(string $chatkey, string $text): array {
        $messages = [['role' => 'system', 'content' => '']];
        foreach ($this->channel->store()->get($chatkey) as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $text];
        return $messages;
    }

    /**
     * Hand the saved loop of a chat to the next worker, with the session generation of the loop.
     *
     * @param string $chatkey Chat key of the channel.
     * @return void
     */
    private function hand_over(string $chatkey): void {
        $state = $this->channel->store()->get_context('tool_loop_' . $chatkey);
        $this->channel->continuation()->schedule($chatkey, (int) ($state['session_gen'] ?? 0));
    }

    /**
     * Stop a request in progress whose access changed. Nothing is sent, and the task state is released only when it
     * still exists, so the state that unlinking removed is not written again.
     *
     * @param string $statekey Key of the task state.
     * @param string $chatkey Chat key of the channel.
     * @return void
     */
    private function stop_without_access(string $statekey, string $chatkey): void {
        \local_jedomi_log('[GATELOOP] access changed for chat ' . self::chatref($chatkey) . ', request stopped');
        if ($this->channel->store()->exists($statekey)) {
            $this->channel->store()->set_context($statekey, []);
        }
    }

    /**
     * Release the tool loop lock and send a message to the user.
     *
     * @param string $statekey The tool loop state key.
     * @param string $chatkey Chat key of the channel.
     * @param string $message The message to send.
     * @return void
     */
    private function release_lock(string $statekey, string $chatkey, string $message): void {
        $this->channel->store()->set_context($statekey, []);
        $this->channel->transport()->send_text($chatkey, $message);
    }

    /**
     * Send the activity status of a chat with a short timeout; a failure is only logged.
     *
     * @param string $chatkey Chat key of the channel.
     * @return bool Whether the channel accepted it.
     */
    private function send_typing(string $chatkey): bool {
        try {
            if ($this->channel->transport()->send_activity($chatkey, $this->channel->activity_timeout())) {
                return true;
            }
        } catch (\Throwable $e) {
            \local_jedomi_log('[GATELOOP] typing_failed chat=' . self::chatref($chatkey) . ' error=' . get_class($e));
            return false;
        }
        \local_jedomi_log('[GATELOOP] typing_failed chat=' . self::chatref($chatkey));
        return false;
    }

    /**
     * Turn an AI client answer into the shape the tool loop uses.
     *
     * @param  array $answer Answer from \local_jedomi\ai\client::complete().
     * @return array|null    ['type'=>'text', 'content'=>'...']
     *                       ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                       null when the answer has neither text nor tool calls.
     */
    private static function parse_answer(array $answer): ?array {
        if (!empty($answer['tool_calls'])) {
            return ['type' => 'tool_calls', 'tool_calls' => $answer['tool_calls']];
        }

        // Text response: prefer content, fall back to reasoning (thinking tokens).
        $text = ($answer['content'] ?? '') !== '' ? $answer['content'] : ($answer['reasoning'] ?? '');
        if ($text === '') {
            return null;
        }
        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Current time of the Moodle clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }

    /**
     * Pseudonym of a chat for the technical log, which never keeps the chat key.
     *
     * @param string $chatkey Chat key of the channel.
     * @return string
     */
    private static function chatref(string $chatkey): string {
        return \local_jedomi_log_pseudonym('chat', $chatkey);
    }
}
