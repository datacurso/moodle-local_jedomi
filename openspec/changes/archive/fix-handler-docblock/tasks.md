# Tasks: Fix missing PHPDoc opening in handler.php

## Phase 1: Fix

- [ ] 1.1 Insert `    /**` between line 830 (`        ]);`) and line 831 (`     * Read a PDF document...`) in `classes/proxy/handler.php`

## Phase 2: Verification

- [ ] 2.1 Run `php -l classes/proxy/handler.php` — must return "No syntax errors detected" (exit code 0)
- [ ] 2.2 Visual inspect the full `document_read_tool()` docblock (lines 831–832) to confirm `/** ... */` is complete
- [ ] 2.3 Run `grep -n '/\*\*' classes/proxy/handler.php` — confirm 22 occurrences (existing count preserved)

## Phase 3: Commit

- [ ] 3.1 Commit the fix with a conventional commit message
