# Design: Fix missing PHPDoc opening in handler.php

## Technical Approach

Insert a single line containing `    /**` between line 830 (`        ]);`) and line 831 (`     * Read a PDF document...`) in `classes/proxy/handler.php`. This restores the opening delimiter of the `document_read_tool()` docblock that was dropped during merge conflict resolution in commit `3633dcf`.

The missing `/**` caused PHP to interpret line 831's `* Read a PDF document...` as a乘法 (multiply) operator on the return value of line 830 (`]);`), producing a `ParseError: syntax error, unexpected token '*'`.

## Architecture Decisions

| Decision | Options | Chosen | Rationale |
|----------|---------|--------|-----------|
| Fix location | Insert before line 831 | Insert between 830 and 831 | No other docblock is missing its opening; the existing `*/` (line 832) and content (line 831) are intact — only the `/**` was lost. |
| Fix mechanism | Direct edit vs. revert-merge vs. patch | Direct line edit | Single-line insertion carries zero behavioral risk. Reverting the merge would lose unrelated changes. A patch file is overkill for one line. |
| Indentation | Tab vs. 4 spaces | 4 spaces `    /**` | Matches every other method-level docblock in the file (see lines 35, 177, 188, etc.) and Moodle coding standards. |

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `classes/proxy/handler.php` | Modify | Insert `    /**` at line 831 (between `        ]);` and `     * Read a PDF document...`) |

Exact diff:

```diff
         ]);
+    /**
      * Read a PDF document from a course module and convert to images.
      */
     private static function document_read_tool(array $args, string $role): string {
```

## Interfaces / Contracts

No interfaces, APIs, or data structures are changed. This is a purely syntactic fix.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Syntax validation | File parses without error | `php -l classes/proxy/handler.php` MUST return `No syntax errors detected` |
| Regression | Existing docblocks intact | `grep -n '/\*\*' classes/proxy/handler.php` MUST show 22 occurrences (consistent with current count) |
| Runtime | Application boots | Load any page that triggers autoload of `classes/proxy/handler.php` — no fatal error |

The fix is syntactic only, so unit/integration tests of behavior are unnecessary.

## Migration / Rollout

No migration required.

Rollback: `git checkout HEAD~1 -- classes/proxy/handler.php` or `git revert <commit-hash>`.

## Open Questions

None. The root cause, fix location, and resolution are fully determined.
