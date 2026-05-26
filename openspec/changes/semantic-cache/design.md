# Design: Semantic Cache

## Technical Approach

Two-phase cache keyed by `(agentid, context_hash)`:

- **MISS**: record ordered tool chain → write `plan_json` + `answer` to cache
- **HIT**: load `plan_json` → re-execute all tools (fresh data) → 1 Gateway call reformulates → return fresh answer

## Architecture Decisions

### Decision: New `plan_json` column over repurposing `result_json`

| Option | Tradeoff |
|--------|----------|
| Store plan as JSON inside `result_json` | Breaks preview cache format (already has structured JSON), requires migration for all entries |
| **New `plan_json` MEDIUMTEXT column** | Clean separation, `result_json` keeps backward compatibility with preview, nullable = no plan |

**Chosen**: `plan_json` column. Nullable MEDIUMTEXT. Upgrade step adds it + purges stale cache.

### Decision: LLM reformulation on HIT instead of template interpolation

| Option | Tradeoff |
|--------|----------|
| Template interpolation (parse answer → extract placeholders → replace) | Complex, fragile, model output is unpredictable |
| **1 Gateway call with fresh data + previous answer as context** | Simpler, preserves narrative quality, costs 1 API round |

**Chosen**: 1 reformulation round. The payload sends fresh tool results + cached answer as "previous answer" context.

### Decision: Re-execute ALL tools in plan, not just last one

| Option | Tradeoff |
|--------|----------|
| Only re-execute last tool | Some agents need multiple tools for context (search_tables → describe → execute_sql) |
| **Re-execute all tools in order** | Consistent with original execution, handles multi-step agents correctly |

**Chosen**: Re-execute all. Tool results stored in array for reformulation context.

## Data Flow

```
MISS:
  Gateway loop (rounds 1-N)
    → foreach tool_call: record $plan[] = {tool, arguments}
    → execute tool, feed result back
  → final answer
  → agent_manager::set_cached_plan(agentid, hash, plan, answer)

HIT:
  agent_manager::get_cached_plan(agentid, hash)
    → plan = [tool+args, tool+args, ...]
    → answer (original text for context)
  → foreach item in plan:
      tool_executor::execute(item.tool, item.arguments) → fresh result
      if any fail → mark_run(false), continue (error)
  → Build messages:
      system: "Reformula con datos actualizados"
      user: fresh results JSON + previous answer
  → 1 Gateway call → reformulated answer
  → Send Telegram with new answer
```

## Plan JSON Structure

```json
[
  {"tool": "execute_sql", "arguments": "{\"sql\": \"SELECT ...\"}"},
  {"tool": "execute_sql", "arguments": "{\"sql\": \"SELECT ...\"}"}
]
```

## Destructive Tool Detection

Before caching plan, scan ALL tool names:

| Tool | Classification |
|------|---------------|
| `execute_sql` | Read-only (SELECT gate in tool) |
| `search_tables`, `describe_table` | Read-only |
| `ws_search`, `ws_describe` | Read-only |
| `get_site_config` | Read-only |
| `ws_execute`, `call_webservice` | Check via `local_jedomi_classify_ws_access()` |
| `admin_execute`, `admin_search`, `admin_describe` | Read-only (schema/admin queries) |
| `create_*`, `enrol_*`, `upload_*`, `set_*` | Read-only (Moodle mutations, not DB writes — but re-executing creates duplicates) |

If ANY tool in plan is write → `plan_json` = null (force MISS on next run). Cache only `result_json` as fallback (current behavior).

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `classes/task/agent_runner.php` | Modify | Record `$plan[]` during MISS loop; on HIT, if plan exists, replay + reformulate |
| `classes/agent/agent_manager.php` | Modify | Add `set_cached_plan()`, `get_cached_plan()`, `is_tool_destructive()` |
| `db/install.xml` | Modify | Add `plan_json` MEDIUMTEXT column to `local_jedomi_agent_cache` |
| `db/upgrade.php` | Modify | Step to add `plan_json` + purge stale cache |
| `ajax/agent_preview.php` | Modify | Record plan in preview cache too |
| `version.php` | Modify | Bump |

## Agent Manager Interface

```php
// Store plan + answer
public static function set_cached_plan(
    int $agentid,
    string $context_hash,
    array $plan,       // [['tool' => 'x', 'arguments' => '{}'], ...]
    string $answer     // Original answer text for reformulation context
): void;

// Retrieve plan + answer. Returns null if no plan exists.
public static function get_cached_plan(
    int $agentid,
    string $context_hash
): ?array;  // ['plan' => [...], 'answer' => '...']

// Check if a tool name is read-only (can be replayed safely).
public static function is_tool_readonly(string $toolname): bool;
```

## Reformulation Prompt

```
System: "You previously answered this question. Here are the updated tool results. Reformulate your answer with the new data. Keep the same style and structure."

User: "Previous answer: {cached_answer}\n\nUpdated tool results:\nResult 1 ({tool}): {fresh_result}\nResult 2 ({tool}): {fresh_result}"
```

## Migration

- `db/upgrade.php`: Add `plan_json MEDIUMTEXT` null. Purge all cache rows (existing text answers are stale).
- `version.php`: Bump.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | `agent_manager::set_cached_plan()` / `get_cached_plan()` | Store plan, retrieve it, verify structure |
| Unit | `agent_manager::is_tool_readonly()` | Test known read-only tools, known write tools, edge cases |
| Integration | MIS recording + HIT replay | Mock Gateway, verify plan recorded correctly, replay produces fresh data |

## Open Questions

None.
