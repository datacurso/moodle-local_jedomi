# Review rules for this Moodle plugin

These rules are read by Gentleman Guardian Angel (`gga`) on every commit and by AI coding agents working in this repository.
Organisation skills with the full guidance: `moodle-plugin-coding`, `moodle-phpunit`, `moodle-javascript`, `moodle-mustache`,
`moodle-rendering`, `moodle-webservices`, `code-quality-standards`, `plugin-ci-preflight` (datacurso/moodle-ai).

## Block the commit when

- A page or endpoint misses `require_login()` (or `require_course_login()`), the matching `require_capability()`, or
  `require_sesskey()` / `confirm_sesskey()` before any write.
- User input is read without `required_param()` / `optional_param()` and a strict `PARAM_*` type, or `$_GET`, `$_POST`,
  `$_REQUEST`, `$_FILES` are used directly where a Moodle API exists.
- SQL is built by concatenating user input instead of using placeholders with `$DB`.
- Output echoes user or teacher data without `s()`, `format_string()`, `format_text()` or Mustache double braces;
  triple braces are only allowed for values encoded on the server with `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP |
  JSON_HEX_APOS | JSON_HEX_QUOT)`.
- Files are stored or served outside the File API (`get_file_storage()`, `pluginfile`), or a `pluginfile` callback does
  not check login and capability for its file area.
- Secrets (API keys, tokens, passwords) are hard-coded or sent to the browser.
- A user-facing string is hard-coded instead of a language string in `lang/en` (and `lang/es` when present).
- A schema change is missing its `db/upgrade.php` step and `upgrade_*_savepoint()`, or `version.php` was not bumped.
- PHPUnit tests mock the database or the File API. Tests extend `advanced_testcase` and call `$this->resetAfterTest()`.

## Flag (do not block) when

- A PHP file outside `db/` grows beyond 400 lines, a loop is nested in another loop, or `if` nesting exceeds two levels.
- A public function or class lacks a docblock with `@param` and `@return`.
- Code, identifiers or comments are not in English, or comments mention AI tools.
- Behaviour changes without a test that proves it.

## Ignore

Vendored and generated code: `vendor/`, `**/vendor/**`, `amd/build/`, `*.min.js`, `*.map`, binary models (`*.glb`, `*.task`).
