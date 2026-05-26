<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_jedomi';
$plugin->version   = 2026052503; // 03: semantic cache — tool plan replay with fresh data + LLM reformulation
// 20: agent panel improvements: tool metrics, preview args+cache, profiler tab
// 19: fix telegram stuck loop: lock release, TTL, lastactivity, guard 20→40, TOCTOU
// 18: <|tool_call|> format support, profiler data in preview, final cleanup all tokens
// 17: agent_preview endpoint + UI, context_hash includes prompt, TOOL_CALL parser fixes, max_tokens config
// 16: auto-name from prompt, max_tokens config setting, 1000-row SQL limit, 64k truncation, use name not ID in messages
// 15: agent name (DB column + form), text-based TOOL_CALL protocol, WS permission-based auth
// 14: agent_tool interface, sql_tool, ws_tool, query_profiler, multi-turn agent_runner
// 13: fix agent cache: store extracted content instead of raw gateway JSON
// 12: force table creation for deployments where 11 step was missed
$plugin->requires = 2024100700; // Moodle 4.5.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.2.2';
