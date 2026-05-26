# Tasks: Semantic Cache

## Phase 1: Foundation (DB + Cache API)

- [ ] 1.1 `db/install.xml` — Add `plan_json` MEDIUMTEXT null to `local_jedomi_agent_cache`
- [ ] 1.2 `db/upgrade.php` — Add step: `plan_json` MEDIUMTEXT + purge all cache rows
- [ ] 1.3 `version.php` — Bump version
- [ ] 1.4 `classes/agent/agent_manager.php` — Add `set_cached_plan(agentid, hash, plan[], answer): void`
- [ ] 1.5 `classes/agent/agent_manager.php` — Add `get_cached_plan(agentid, hash): ?array`
- [ ] 1.6 `classes/agent/agent_manager.php` — Add `is_tool_readonly(toolname): bool` with tool classification

## Phase 2: Core Implementation (agent_runner)

- [ ] 2.1 `classes/task/agent_runner.php` — In MISS loop, record `$plan[]` array with each tool call (name + arguments)
- [ ] 2.2 `classes/task/agent_runner.php` — After MISS completes, call `agent_manager::set_cached_plan()` with plan + answer
- [ ] 2.3 `classes/task/agent_runner.php` — On HIT with plan: re-execute all tools via `tool_executor::execute()`, collect fresh results
- [ ] 2.4 `classes/task/agent_runner.php` — On HIT replay: build reformulation messages (fresh results + cached answer), make 1 Gateway call
- [ ] 2.5 `classes/task/agent_runner.php` — If replay fails (tool error, Gateway error), mark_run(false), fall back to MISS

## Phase 3: Preview Panel

- [ ] 3.1 `ajax/agent_preview.php` — Record plan in preview cache alongside conversation
- [ ] 3.2 `ajax/agent_preview.php` — On preview HIT with plan: re-execute tools, return fresh data

## Phase 4: Testing

- [ ] 4.1 Unit test: `agent_manager::set_cached_plan()` / `get_cached_plan()` round-trip
- [ ] 4.2 Unit test: `agent_manager::is_tool_readonly()` with known read-only + write tools
- [ ] 4.3 Integration test: MISS recording produces correct plan structure
- [ ] 4.4 Integration test: HIT replay re-executes tools and reformulates
- [ ] 4.5 Integration test: Write tool in chain → plan not cached (fallback to text cache)
- [ ] 4.6 Integration test: Tool failure during replay → agent marked as failed
