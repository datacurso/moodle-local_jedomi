# Semantic Cache Specification

## Purpose

Almacenar el plan de ejecución de tools (secuencia ordenada de tool name + arguments) durante el cache MISS, y re-ejecutarlo en cache HIT para obtener datos frescos. La respuesta final se reformula con 1 round de LLM usando los datos actualizados.

## Requirements

### Requirement: Tool Plan Recording on Cache MISS

During a cache MISS, the system MUST record the ordered sequence of tool calls (name + arguments) that the model executed. The plan MUST only include read-only tools.

#### Scenario: Record single tool call

- GIVEN a cache MISS for an agent
- WHEN the model calls 1 tool and then provides a final answer
- THEN the plan MUST contain that single tool call with its arguments

#### Scenario: Record multiple tool calls

- GIVEN a cache MISS for an agent
- WHEN the model calls multiple tools (e.g. search_tables → execute_sql) before providing a final answer
- THEN the plan MUST contain ALL tool calls in execution order with their arguments

#### Scenario: Exclude destructive tools from plan

- GIVEN a cache MISS for an agent
- WHEN the model calls ANY write tool (classified as "Escritura" by `local_jedomi_classify_ws_access()`)
- THEN the system MUST NOT cache a plan (fall back to current text cache or force MISS next time)

### Requirement: Tool Plan Replay on Cache HIT

When a cache HIT contains a valid plan, the system MUST re-execute ALL tools in the plan with the stored arguments, then make exactly 1 LLM call to reformulate the final answer with fresh data.

#### Scenario: Replay with fresh data

- GIVEN a cache HIT with a valid plan from a previous MISS
- WHEN the system re-executes all tools in the plan
- THEN the tools MUST run with their original arguments
- AND the fresh tool results MUST be passed to the LLM for reformulation
- AND the final answer MUST contain current data (not the original cached answer)

#### Scenario: Tool execution fails during replay

- GIVEN a cache HIT with a valid plan
- WHEN a tool execution fails during replay (e.g. SQL error, permission denied)
- THEN the system MUST return the error message as part of the reformulation context
- AND the agent MUST be marked as failed

### Requirement: LLM Reformulation Phase

After re-executing all tools on HIT, the system MUST make exactly 1 LLM call with the fresh tool results plus the original cached answer as context. The model SHALL reformulate the answer with current data.

#### Scenario: Reformulation with fresh data

- GIVEN fresh tool results from replay
- WHEN the LLM receives the fresh results + original answer as context
- THEN the model MUST produce a new answer reflecting the current data

### Requirement: Preview Panel Compatibility

The preview panel (agent_preview.php) MUST also store and use the semantic cache plan, following the same recording/replay logic as the agent runner.

#### Scenario: Preview records plan

- GIVEN a preview request (cache MISS)
- WHEN tools are executed during preview
- THEN the plan MUST be stored in the cache alongside the answer

#### Scenario: Preview replays plan

- GIVEN a preview request (cache HIT) with a valid plan
- WHEN the plan is replayed
- THEN tools MUST re-execute and the answer MUST be reformulated with fresh data

### Requirement: Cache Upgrade & Cleanup

The upgrade step MUST add the `plan_json` MEDIUMTEXT column to `local_jedomi_agent_cache` and purge all existing cache entries (which contain stale plain text).

#### Scenario: Upgrade migration

- GIVEN an existing installation with cache entries
- WHEN the plugin upgrades to the semantic cache version
- THEN the `plan_json` column MUST be added
- AND all existing cache rows MUST be deleted
