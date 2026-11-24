# Changes

## Unreleased

- Add `\local_jedomi\public_api::chat_available(int $userid, \context $context)`: other plugins ask whether the Jedomi chat is offered to a user in a context (active user who is not a guest, Jedomi enabled in the Datacurso AI provider, the course available to Jedomi and a role not hidden from it) before they show something that opens it
- Add the AMD module `local_jedomi/api` for other plugins: `isAvailable()` tells whether the panel is on the page, and `openWithSuggestion(prompt)` (or `{prompt, source}`) opens the panel with the text written in the message field, as plain text the user can change and send. It is never sent on its own; a suggestion is cut to 4000 characters and waits up to 15 seconds for a panel that is still loading
- Add the hook `\local_jedomi\hook\tool_registry`: other plugins give Jedomi their own tools from their `db/hooks.php`. The web chat dispatches it with the user, the signed course (or the system) and the channel, offers a tool only when its plugin's `is_available($userid, $context)` answers true, runs it as the session user, logs it as a Jedomi tool and anonymises its result like any other. A tool without `is_available()`, with a malformed name, with the name of a Jedomi tool or of an earlier plugin tool, or whose definition names another tool is ignored; a failing tool answers a controlled error, and a result over 100 KB is replaced by a `tool_result_too_large` error. A tool declares with `is_read_only()` whether it only reads: a tool that changes something, or does not declare it, waits for the user's confirmation card (severity high) and runs only when confirmed, as the user and only if its plugin still offers it in the context it was held in. Telegram and the scheduled agents do not offer plugin tools yet
- Fixed: the instructions of the web chat, Telegram and the scheduled agents carry the date of today (`- Today: 2026-11-18 (Wednesday), time zone America/Bogota`), taken from the server clock in the time zone of the user and never from the request, with a rule to read relative dates ("last month", "since ...") against it. Before, the AI did not know the date and compared periods of earlier years when asked about "last month"
- Added: plugin tools whose data is still being prepared are asked again. A read-only plugin tool that answers a JSON object with `"meta": {"pending": true}` at its top level (the contract of the `tool_registry` hook; the course tools of `local_datacurso_heatmap` answer so) is run again with the same arguments after 10 seconds, at most 3 runs within 25 seconds and 30 seconds of waiting in all for one turn (`\local_jedomi\local\plugin_tool_retry`), and the AI gets the last answer. A tool that changes data, an answer with `error`, a failing tool or any other shape (a text, `"pending": "true"`, `pending` outside `meta`) is never run again. If the data is still being prepared after the last run, the instructions tell the AI to say that it will be ready in about a minute, never that there is no data. Each new run is logged (`PLUGIN_TOOL_PENDING_RETRY`)
- Security (JEDOMI-PR33-SEC-001): an error of a plugin tool reaches the AI through `safe_error::for_model()`, like the Jedomi tools, so a failing database query of another plugin no longer sends its SQL and parameters to the AI when developer debugging is shown

## 1.0.0 (2026-11-23)

First public release of Jedomi, an AI assistant for Moodle 4.5 that works through the Datacurso AI provider
(`aiprovider_datacurso` 1.9.0 or later) or an OpenAI-compatible service of your own.

### Web chat

- Floating chat panel on every page, aware of the page context (course, activity, page type) and of the role of the
  user (administrator, manager, teacher or student), with role-specific tools and quick actions
- Streaming answers with Markdown, a stop button, editing of the last question, copy, and ratings with an optional
  comment
- Attach up to five files per message: images, PDF, Word, PowerPoint, text and CSV files, and audio files
- Voice messages and a hands-free voice conversation (silence detection, spoken answers, interruptions)
- Actions that change data wait for the user's confirmation on a card that shows every argument; critical actions need
  a confirmation word
- Answers in the language of the user's latest message; English and Spanish included
- Accessible panel: keyboard shortcuts (Alt+Shift+J, Alt+Shift+N), focus handling, screen reader announcements, WCAG AA
  contrast, reduced motion and right-to-left support

### Tools

- Database queries with a SQL guard: administrators and managers read every non-sensitive table; teachers read only
  the data of their own courses that Moodle would show them; students read only their own courses, grades and
  assignments
- Moodle web service calls limited by an allowlist per role and by the capabilities each function declares
- Site overview, activity PDF reading, internal messages and administration operations for administrators
- Writing a small allowlist of site settings (site names, timezone, country, language, course defaults)

### Scheduled agents

- Agents that run an instruction on a schedule in a course, category, user or site context
- Approved writes run only inside the scope of the agent and within per-run caps; any other write waits for the
  creator's approval
- Retries with backoff, safe re-runs, failure notices and automatic disabling after repeated failures
- Agents tab with a Report builder report, filters and charts, and a preview with a query profiler

### Telegram

- Telegram bot linked to the Moodle account, with the same roles, tools and confirmations as the web chat
- Photos, documents and voice messages; grade and scheduled task notifications
- Optional PIN for administrators and managers, link expiry and revocation, per-chat rate limits

### Administration

- Settings in tabs: General, Course availability, Privacy, APIs, Integrations, Agents, Query profiler and
  Personalization (avatar, button position, assistant name, welcome message, site instructions)
- Choice of AI service: Datacurso service (credits) or a custom OpenAI-compatible service (OpenAI, DeepInfra, Ollama,
  vLLM...) with connection check, model suggestions, context window and optional document service
- Course inclusion or exclusion, hidden roles, per-user message limit and request timeouts
- Companion web services (`local_jedomi_companion`) signed with HMAC for external clients
- Command-line chat and end-to-end check scripts

### Security and privacy

- Personal data anonymised before it reaches the AI at three levels (Basic, Strict, Feedback only), and restored only
  in answers and tool arguments that stay on the site, never in links to other sites
- Untrusted content (tool results, files, Moodle names) is marked as data, never instructions, in every channel
- Secrets encrypted with the site key; signed chat permissions; replay protection for companion requests
- Every external service that receives user data declared in the Privacy API; export and deletion of the plugin data
- Pseudonymised technical log with size-based retention; Moodle events and config log entries for every change
- Exception details never reach the browser, Telegram or the log
