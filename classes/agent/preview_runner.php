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

use local_jedomi\ai\client;
use local_jedomi\ai\error_message;
use local_jedomi\local\anonymizer;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\task\agent_runner;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Agent preview: runs a prompt through the AI client with tools, synchronously, for testing an agent.
 *
 * Same logic as the scheduled agent runner, plus a preview cache (agentid 0) and a profiler of tool calls.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_runner {
    /** @var int Total timeout of each AI call, in seconds. */
    private const AI_TIMEOUT = 120;

    /**
     * Cache key of the preview of a prompt: the saved result of a preview is private to the user who ran it.
     *
     * @param string $prompt Agent prompt.
     * @param int $userid User the preview runs for.
     * @param string $contexttype Context type of the agent.
     * @param int|null $contextid Context instance of the agent.
     * @return string
     */
    private static function cache_key(string $prompt, int $userid, string $contexttype, ?int $contextid): string {
        $scope = $contexttype === 'site' && empty($contextid) ? '' : ':' . $contexttype . ':' . (int) $contextid;
        return md5($userid . $scope . ':' . trim($prompt));
    }

    /**
     * Run a preview with the same permissions, context, tools and step limit as the scheduled run of the agent.
     *
     * @param string $prompt Agent prompt.
     * @param int $userid User the preview runs for (the future creator of the agent; the AI calls are made for this user).
     * @param string $contexttype Context type of the agent (site, course, category or user).
     * @param int|null $contextid Context instance of the agent.
     * @param int $agentid Agent being edited, recorded with the queries of the preview; 0 for a new agent.
     * @return array Response for the preview page: ok, answer, rounds, profiler, conversation, total_time_ms,
     *               and cached/cached_at/cache_hash/reformulated for cache hits; or ok=false with error and error_code.
     */
    public static function run(
        string $prompt,
        int $userid,
        string $contexttype = 'site',
        ?int $contextid = null,
        int $agentid = 0
    ): array {
        global $DB;

        [, $system, $tools] = agent_runner::run_conditions($userid, $contexttype, $contextid);
        // The tools run with the permissions of the user, as in the scheduled run of the agent; the profiler records
        // their queries as a preview (of the agent being edited, if any), never as a run of the agent.
        tool_executor::get_definitions($agentid, $userid, 'preview');
        $maxrounds = agent_runner::MAX_TOOL_ITERATIONS;

        $messages = [
            $system,
            ['role' => 'user', 'content' => $prompt],
        ];

        // ... ── Check agent cache ─────────────────────────────────────────────────
        // The saved result of a preview is private to the user who ran it.
        $cachehash = self::cache_key($prompt, $userid, $contexttype, $contextid);
        $cached = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => 0,
            'context_hash' => $cachehash,
        ]);
        if ($cached && !empty($cached->result_json)) {
            $fromcache = self::answer_from_cache($cached, $userid);
            if ($fromcache !== null) {
                // The page needs the cache key to offer "Clear cache" for this prompt.
                return $fromcache + ['cache_hash' => $cachehash];
            }
        }

        // ... ── Multi-turn loop (same as agent_runner) ────────────────────────────
        $finalanswer = '';
        $profilerentries = [];
        $plan = [];
        $toolcallcounts = [];
        $loopbroken = false;
        $content = '';
        // As in the scheduled run, personal data returned by the tools reaches the AI anonymised with the level of the
        // Privacy tab; the arguments it proposes and what the preview shows get the real values back.
        $anonymizer = new anonymizer();
        // As in the scheduled run, tool results reach the AI as untrusted data.
        $untrusted = new \local_jedomi\local\untrusted();

        for ($round = 1; $round <= $maxrounds; $round++) {
            try {
                $answer = client::instance()->complete($messages, $userid, $tools, self::AI_TIMEOUT);
            } catch (\Throwable $e) {
                $error = error_message::from_exception($e);
                return ['ok' => false, 'error' => $error['message'], 'error_code' => $error['code']];
            }

            $nativetoolcalls = $answer['tool_calls'];
            $content = $answer['content'];

            // ... ── Parse text-based TOOL_CALL ──────────────────────────────────────
            $texttoolcalls = [];
            if (!empty($tools)) {
                $texttoolcalls = self::parse_text_tool_calls($content);
            }
            $alltoolcalls = array_merge($nativetoolcalls, $texttoolcalls);

            if (empty($alltoolcalls)) {
                $finalanswer = $content;
                break;
            }

            // The assistant message carries every tool call before the tool results:
            // strict OpenAI backends reject tool messages without it.
            $messages[] = [
                'role' => 'assistant',
                'content' => $content !== '' ? $content : null,
                'tool_calls' => $alltoolcalls,
            ];

            foreach ($alltoolcalls as $tc) {
                $funcname = $tc['function']['name'] ?? '?';
                $funcargs = $tc['function']['arguments'] ?? '{}';
                $tcid = $tc['id'];
                $toolduration = 0;
                // The AI only knows the placeholders of the anonymised data; the tools need the real values.
                $realargs = is_string($funcargs) ? json_decode($funcargs, true) : $funcargs;
                $unrestored = null;
                if (is_array($realargs) && !empty($realargs)) {
                    try {
                        $funcargs = json_encode($anonymizer->restore_tool_args($funcname, $realargs), JSON_UNESCAPED_UNICODE);
                    } catch (\local_jedomi\local\unrestored_placeholder_exception $e) {
                        // The query is not run against the text of a placeholder: the AI is told how to write it (RISK-001).
                        $unrestored = $e->tool_result();
                    }
                }

                // ... ── Loop detection ──────────────────────────────────────────────
                $decodedargs = is_string($funcargs) ? json_decode($funcargs, true) : $funcargs;
                $stableargs = is_array($decodedargs)
                    ? hash('sha256', json_encode($decodedargs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE))
                    : md5($funcargs);
                $callkey = $funcname . '|' . $stableargs;
                $toolcallcounts[$callkey] = ($toolcallcounts[$callkey] ?? 0) + 1;

                if ($toolcallcounts[$callkey] > 5) {
                    $toolresult = json_encode([
                        'error' => 'loop_detected',
                        'tool' => $funcname,
                        'detail' => get_string('preview_detail_loop', 'local_jedomi', $toolcallcounts[$callkey]),
                    ]);
                    $loopbroken = true;
                } else if ($unrestored !== null) {
                    $toolresult = $unrestored;
                } else if (pending_action_service::severity($funcname, is_array($decodedargs) ? $decodedargs : []) !== null) {
                    // A preview never runs an action that changes data: those run only after a confirmation.
                    $toolresult = json_encode([
                        'error' => 'confirmation_required',
                        'tool' => $funcname,
                        'detail' => get_string('preview_detail_confirmation', 'local_jedomi'),
                    ]);
                } else {
                    $plan[] = [
                        'tool' => $funcname,
                        'arguments' => $funcargs,
                    ];

                    $toolstart = microtime(true);
                    $toolresult = tool_executor::execute($funcname, $funcargs);
                    $toolduration = (microtime(true) - $toolstart) * 1000;

                    if (strlen($toolresult) > 64000) {
                        $toolresult = mb_substr($toolresult, 0, 64000)
                            . "\n\n[Result truncated at 64000 characters]";
                    }
                }

                // Profile every call (including loop_detected for visibility).
                $resultinfo = json_decode($toolresult, true);
                $profilerentries[] = [
                    'tool' => $funcname,
                    'arguments' => $funcargs,
                    'result_preview' => mb_substr($toolresult, 0, 5000),
                    'duration_ms' => round($resultinfo['duration_ms'] ?? $toolduration, 2),
                    'row_count' => $resultinfo['row_count'] ?? 0,
                    'error' => $resultinfo['error'] ?? null,
                ];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tcid,
                    'content' => $untrusted->wrap('tool ' . $funcname, $anonymizer->anonymize_tool_result($toolresult)),
                ];
            }

            if ($loopbroken) {
                break;
            }
        }

        // ... ── Safety net + cleanup ─────────────────────────────────────────────────
        if (empty($finalanswer)) {
            if ($loopbroken) {
                $finalanswer = get_string('preview_error_fetch_failed', 'local_jedomi');
            } else if (!empty($content)) {
                $finalanswer = $content;
            } else {
                $finalanswer = self::last_tool_result($messages, $untrusted);
            }
        }

        // Strip any TOOL_CALL blocks that leaked into final answer.
        $finalanswer = trim(preg_replace(
            '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
            '',
            $finalanswer
        ));
        // The answer was written from anonymised data: the creator gets the real values back.
        $finalanswer = $anonymizer->restore($finalanswer);

        if ($finalanswer === '') {
            return ['ok' => false, 'error' => get_string('preview_error_no_answer', 'local_jedomi')];
        }

        // ... ── Save to cache ─────────────────────────────────────────────────────────
        // Strip tool payload from messages before caching (not needed for display), and show the real values.
        $cachablemessages = $messages;
        array_walk($cachablemessages, function (&$msg) use ($anonymizer, $untrusted) {
            unset($msg['tool_calls']);
            if (is_string($msg['content'] ?? null)) {
                $msg['content'] = $anonymizer->restore($untrusted->unwrap($msg['content']));
            }
        });
        self::save_cache($cachehash, $userid, $finalanswer, $profilerentries, $cachablemessages, $plan);

        return [
            'ok' => true,
            'answer' => $finalanswer,
            'rounds' => min($round, $maxrounds),
            'profiler' => $profilerentries,
            'conversation' => $cachablemessages,
            'total_time_ms' => round(array_sum(array_column($profilerentries, 'duration_ms')), 2),
        ];
    }

    /**
     * Answer a preview from its cache: replay the cached plan and reformulate, or return the cached text.
     *
     * @param \stdClass $cached Cache record.
     * @param int $userid User the preview runs for.
     * @return array|null Response, or null when the cache cannot be used.
     */
    private static function answer_from_cache(\stdClass $cached, int $userid): ?array {
        // ... ── Try semantic cache plan replay ──────────────────────────────────────
        $plan = !empty($cached->plan_json) ? json_decode($cached->plan_json, true) : null;
        if (is_array($plan) && !empty($plan)) {
            // The AI gets the fresh results and the previous answer anonymised, as in the scheduled run; the profiler
            // and the reformulated answer show the real values.
            $anonymizer = new anonymizer();
            // The fresh results reach the AI as untrusted data, as in a run that is not cached (REL-008).
            $untrusted = new \local_jedomi\local\untrusted();
            $replayprofiler = [];
            $replayresults = [];
            $replayfailed = false;
            foreach ($plan as $step) {
                $stepargs = is_string($step['arguments']) ? json_decode($step['arguments'], true) : $step['arguments'];
                if (pending_action_service::severity((string) $step['tool'], is_array($stepargs) ? $stepargs : []) !== null) {
                    // Never replay an action that changes data.
                    $replayfailed = true;
                    break;
                }
                $toolstart = microtime(true);
                $toolresult = tool_executor::execute($step['tool'], $step['arguments']);
                $toolduration = (microtime(true) - $toolstart) * 1000;
                $resultinfo = json_decode($toolresult, true);
                if (!empty($resultinfo['error'])) {
                    $replayfailed = true;
                    break;
                }
                $replayprofiler[] = [
                    'tool' => $step['tool'],
                    'arguments' => $step['arguments'],
                    'result_preview' => mb_substr($toolresult, 0, 5000),
                    'duration_ms' => round($resultinfo['duration_ms'] ?? $toolduration, 2),
                    'row_count' => $resultinfo['row_count'] ?? 0,
                    'error' => $resultinfo['error'] ?? null,
                ];
                $replayresults[] = [
                    'tool' => $step['tool'],
                    'result' => mb_substr($anonymizer->anonymize_tool_result($toolresult), 0, 5000),
                ];
            }

            if (!$replayfailed && !empty($replayprofiler)) {
                // Build fresh context for reformulation.
                $freshctx = "Updated tool results:\n";
                foreach ($replayresults as $fr) {
                    $freshctx .= "\n" . $untrusted->wrap('tool ' . $fr['tool'], $fr['result']) . "\n";
                }

                $cachedresult = json_decode($cached->result_json, true);
                $cachedanswer = $anonymizer->anonymize_text((string) ($cachedresult['answer'] ?? $cached->result_json));

                $reformmessages = [
                    ['role' => 'system', 'content' => 'You previously answered a question. Below are updated tool results.'
                        . ' Reformulate your answer using the new data. Keep the same style and structure.'
                        . "\n" . \local_jedomi\local\untrusted::rule()],
                    ['role' => 'user', 'content' => "Previous answer:\n{$cachedanswer}\n\n{$freshctx}"],
                ];

                try {
                    $reform = client::instance()->complete($reformmessages, $userid, [], self::AI_TIMEOUT);
                    $reformanswer = $anonymizer->restore((string) $reform['content']);
                } catch (\Throwable $e) {
                    // The cached text below is still a valid answer.
                    $reformanswer = '';
                }
                if (trim($reformanswer) !== '') {
                    return [
                        'ok' => true,
                        'answer' => $reformanswer,
                        'rounds' => 0,
                        'profiler' => $replayprofiler,
                        'total_time_ms' => round(array_sum(array_column($replayprofiler, 'duration_ms')), 2),
                        'cached' => true,
                        'cached_at' => $cached->timecreated,
                        'reformulated' => true,
                    ];
                }
            }
        }

        // ... ── Fallback: plain text cache ──────────────────────────────────────────
        $cachedplain = json_decode($cached->result_json, true);
        if ($cachedplain && is_array($cachedplain)) {
            return [
                'ok' => true,
                'answer' => $cachedplain['answer'] ?? $cached->result_json,
                'rounds' => 0,
                'profiler' => $cachedplain['profiler'] ?? [],
                'conversation' => $cachedplain['conversation'] ?? [],
                'total_time_ms' => 0,
                'cached' => true,
                'cached_at' => $cached->timecreated,
            ];
        }
        return null;
    }

    /**
     * Content of the last tool result that is not an error.
     *
     * @param array $messages Conversation.
     * @param \local_jedomi\local\untrusted $untrusted Delimiters of the run, removed from the result.
     * @return string
     */
    private static function last_tool_result(array $messages, \local_jedomi\local\untrusted $untrusted): string {
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                $content = $untrusted->unwrap((string) $msg['content']);
                $decoded = json_decode($content, true);
                if (!empty($decoded['error'])) {
                    continue;
                }
                return $content;
            }
        }
        return '';
    }

    /**
     * Save the preview result in the preview cache (agentid 0).
     *
     * @param string $cachehash Prompt hash.
     * @param int $userid User the preview ran for, the only one who may clear it.
     * @param string $answer Final answer.
     * @param array $profiler Profiler entries.
     * @param array $conversation Conversation without tool payloads.
     * @param array $plan Tool plan.
     */
    private static function save_cache(
        string $cachehash,
        int $userid,
        string $answer,
        array $profiler,
        array $conversation,
        array $plan
    ): void {
        global $DB;

        $cachedata = json_encode([
            'userid' => $userid,
            'answer' => $answer,
            'profiler' => $profiler,
            'conversation' => $conversation,
        ]);

        // Only save semantic plan if all tools are readonly.
        $allreadonly = true;
        foreach ($plan as $p) {
            if (!agent_manager::is_tool_readonly($p['tool'])) {
                $allreadonly = false;
                break;
            }
        }
        $planjson = (!empty($plan) && $allreadonly) ? json_encode($plan, JSON_UNESCAPED_UNICODE) : null;

        $existingcache = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => 0,
            'context_hash' => $cachehash,
        ], 'id');
        if ($existingcache) {
            $DB->update_record('local_jedomi_agent_cache', (object) [
                'id' => $existingcache->id,
                'result_json' => $cachedata,
                'plan_json' => $planjson,
                'timecreated' => time(),
            ]);
        } else {
            $DB->insert_record('local_jedomi_agent_cache', (object) [
                'agentid' => 0,
                'context_hash' => $cachehash,
                'result_json' => $cachedata,
                'plan_json' => $planjson,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Parse text-based TOOL_CALL blocks from AI response content.
     * Supports TOOL_CALL:/call: format AND <|tool_call|> special token format.
     *
     * @param  string $content The assistant response text (modified by reference).
     * @return array            Parsed tool calls in native format.
     */
    private static function parse_text_tool_calls(string &$content): array {
        $calls = [];

        if (empty(trim($content))) {
            return $calls;
        }

        // ... ── Pattern 1: TOOL_CALL: tool_name\n{json}\nEND_TOOL_CALL ──────────
        $pattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';

        // ... ── Pattern 2: <|tool_call|> or <|python_tag|> special tokens ──────
        $pattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

        // Try pattern 1 first.
        if (preg_match_all($pattern1, $content, $matches, PREG_SET_ORDER)) {
            $content = trim(preg_replace($pattern1, '', $content));
            foreach ($matches as $m) {
                $parsed = self::tool_call_from_match($m[1], $m[2]);
                if ($parsed) {
                    $calls[] = $parsed;
                }
            }
            return $calls;
        }

        // Try the second pattern, the tool_call marker.
        if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
            $content = trim(preg_replace($pattern2, '', $content));
            foreach ($matches as $m) {
                $inner = trim($m[1]);
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                    $toolname = $decoded['name'];
                    $args = $decoded['arguments'] ?? '{}';
                    if (is_array($args)) {
                        $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                    }
                    $parsed = self::tool_call_from_match($toolname, $args);
                } else {
                    $parts = explode("\n", $inner, 2);
                    $parsed = self::tool_call_from_match(trim($parts[0]), $parts[1] ?? '{}');
                }
                if ($parsed) {
                    $calls[] = $parsed;
                }
            }
            return $calls;
        }

        return $calls;
    }

    /**
     * Build a tool call entry from name + JSON string with validation.
     *
     * @param  string $name     The tool name extracted from the match.
     * @param  string $argsjson The tool arguments as a JSON string.
     * @return array|null       Tool call entry, or null when the payload is invalid.
     */
    private static function tool_call_from_match(string $name, string $argsjson): ?array {
        json_decode($argsjson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                $argsjson = $jsonmatch[0];
                json_decode($argsjson);
            }
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return [
            'id' => 'text_' . md5(uniqid('', true)),
            'type' => 'function',
            'function' => [
                'name' => trim($name),
                'arguments' => $argsjson,
            ],
        ];
    }
}
