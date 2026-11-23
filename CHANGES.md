# Changes

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
