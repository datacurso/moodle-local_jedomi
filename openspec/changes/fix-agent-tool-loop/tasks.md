# Tasks: Fix Agent Tool Loop

Single file: `classes/task/agent_runner.php`. All changes are independent in code but logically ordered.

## Phase 1: Tool definitions source

- [x] 1.1 Import `\local_jedomi_get_tool_definitions()` and `\local_jedomi_get_user_role_for_user()` at line ~413 — replace `tool_executor::get_definitions()` call with role-resolved lang string definitions
- [x] 1.2 Add `$role` variable resolved from `\local_jedomi_get_user_role_for_user((int)($agent->userid ?? 0))` before the definitions call

## Phase 2: Loop behavior

- [x] 2.1 Increase `MAX_TOOL_ITERATIONS` from 10 to 50 (line 29)
- [x] 2.2 Remove post-tool system message at lines 579-582 — delete the `$messages[] = ['role' => 'system', 'content' => '...']` block entirely
- [x] 2.3 Add loop detection: before tool execution (line ~548), compute SHA256 of `$funcName . '|' . stable_args_signature($funcArgs)`, track counts in an array, if >3 return `{"error":"loop_detected"}` and break loop

## Phase 3: System prompt

- [x] 3.1 Rewrite `build_tool_instructions()` (lines 48-87) — remove TOOL_CALL text format example, add flow guidance matching lang string descriptions: "search_tables finds table names → describe_table reveals structure → execute_sql queries data"
- [x] 3.2 Keep the `=== TOOLS ===` listing but descriptions now come from lang strings via the tools parameter

## Verification

- [x] Verify no stray TOOL_CALL format remains in system prompt
- [x] Verify loop detection array is reset per-agent (`$toolcallCounts` initialized inside each agent loop — not shared)
- [x] Verify tool_executor::execute() still works with same tool names (unchanged)
