# Delta for handler.php PHPDoc Syntax

## ADDED Requirements

### Requirement: Complete PHPDoc Block for document_read_tool()

The file `classes/proxy/handler.php` MUST contain valid PHP syntax without parse errors. The `document_read_tool()` method SHALL have a complete PHPDoc block with an opening `/**` line, continuation lines prefixed with ` *`, and a closing `*/` line. A missing `/**` opening line SHALL be treated as a syntax error.

#### Scenario: document_read_tool() docblock is complete after fix

- GIVEN the file `classes/proxy/handler.php` with the missing `/**` line restored between `]);` and ` * Read a PDF document...`
- WHEN the PHP parser processes the file
- THEN it SHALL NOT emit a ParseError for unexpected token `*`
- AND the entire PHPDoc block from `/**` to `*/` SHALL be syntactically valid

#### Scenario: php -l validation

- GIVEN the fixed `classes/proxy/handler.php`
- WHEN `php -l classes/proxy/handler.php` is executed
- THEN the exit code SHALL be 0
- AND the output SHALL contain "No syntax errors detected"

#### Scenario: No regression on other docblocks

- GIVEN the fix applied only at line 831
- WHEN the PHP parser processes the rest of the file
- THEN all other existing PHPDoc blocks SHALL remain syntactically valid

## Non-Requirements

- This spec does NOT cover the logic, correctness, or behavior of `document_read_tool()` or any other method
- This spec does NOT cover PHPDoc content quality, parameter descriptions, `@return` types, or documentation standards
- This spec does NOT cover any file outside `classes/proxy/handler.php`
- This spec does NOT address PHP linting automation or CI enforcement
