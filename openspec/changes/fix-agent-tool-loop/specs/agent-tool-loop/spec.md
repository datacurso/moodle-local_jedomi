# Agent Tool Loop Specification

## Purpose

Define requirements for the `agent_runner` MISS flow — the multi-turn tool-calling loop that produces data-grounded plans for scheduled AI agents.

## Requirements

### Requirement: Tool definitions MUST use language string descriptions

The system MUST use `\local_jedomi_get_tool_definitions($role)` as the source of tool definitions when building the MISS gateway payload. The `$role` MUST be resolved from the agent creator user. Definitions MUST include flow guidance in their descriptions (e.g., "Use this BEFORE execute_sql to find the correct table name").

#### Scenario: Tool descriptions include flow guidance

- GIVEN the agent runner is in MISS flow
- WHEN building the gateway payload
- THEN tool descriptions MUST be sourced from language strings
- AND the descriptions MUST include guidance like "search_tables before describe_table or execute_sql"

#### Scenario: Role resolved from agent creator

- GIVEN an agent owned by an admin user
- WHEN building tool definitions
- THEN `\local_jedomi_get_user_role_for_user()` MUST resolve the role to "admin"

### Requirement: System MUST NOT add messages after tool results

After injecting a tool result message (`role='tool'`), the system MUST NOT append any system, user, or assistant message. The model sees only the tool result and decides whether to call another tool or produce the final answer.

#### Scenario: No post-tool system message

- GIVEN a MISS loop iteration with tool results
- WHEN the tool result is injected as `role='tool'`
- THEN no additional message of any role is appended before the next gateway call

### Requirement: System MUST detect and break tool call loops

The system MUST track repeated tool calls by computing a SHA256 hash of `toolName|stableJsonArgs`. If the same hash appears more than 3 times, the system MUST return an error result for that tool call and break the loop, falling back to the last successful tool result.

#### Scenario: Loop detection triggers on repeated call

- GIVEN the model calls `search_tables("enrolled users")` 4 times
- WHEN the 4th call is detected
- THEN the system returns `{"error": "loop_detected"}` for that call
- AND the loop stops, using the last tool result

#### Scenario: Legitimate repeated tool with different args is not blocked

- GIVEN the model calls `execute_sql({"sql": "SELECT ..."})` with 3 different queries
- WHEN each has different args
- THEN no loop detection triggers

### Requirement: MAX_TOOL_ITERATIONS MUST be 50

The safety limit for the tool-calling loop MUST be 50 iterations.

#### Scenario: Loop exhausts without final answer

- GIVEN the model calls tools for 50 iterations without producing a final answer
- THEN the system falls back to the last tool result content
