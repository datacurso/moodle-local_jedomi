# Jedomi AI Sidebar

**Jedomi AI Sidebar** (`local_jedomi`) adds an AI assistant to Moodle as a side panel available on every page. Administrators, managers, teachers and students ask questions in natural language and Jedomi answers with real data from the site, according to the role and permissions of each user. It can also run actions that change data after the user confirms them, read attached files, images and audio, answer from Telegram, and run scheduled AI agents that send their results to Telegram.

The AI requests go to one of two AI services, chosen by the administrator:

- **Datacurso service** (the default): through the **Datacurso AI Provider**, which supplies the license, the credits and
  the usage limits.
- **Custom service**: straight to an OpenAI-compatible endpoint of your choice (OpenAI, DeepInfra, Ollama, LM Studio,
  vLLM...). No Datacurso license or credits are needed for now.

This plugin is part of the **Datacurso AI Plugin Suite**.

## Pre-requisites

1. Moodle 4.5 (`MOODLE_405_STABLE`). This is the version the plugin requires and is tested with; later versions are
   not supported yet.
2. The Moodle AI provider **Datacurso AI Provider** (`aiprovider_datacurso`) 2026111100 (release 1.8.0) or later,
   installed **before** Jedomi, with whichever AI service you use: its settings page holds the Jedomi settings and its
   "Enabled" switch turns Jedomi on or off. Download it for free from
   [https://moodle.org/plugins/aiprovider_datacurso/versions](https://moodle.org/plugins/aiprovider_datacurso/versions).
3. Only with the Datacurso service: a valid license key configured in the Datacurso AI Provider settings, as documented
   at [https://docs.datacurso.com/docs/plugins/moodle-aiprovider_datacurso/getting-license-keys](https://docs.datacurso.com/docs/plugins/moodle-aiprovider_datacurso/getting-license-keys).
   With the custom service no license is needed for now.
4. Only with the custom service: the address and API key of an OpenAI-compatible endpoint, and the models to use.
5. Moodle cron running, for the scheduled agents and the cleanup tasks.
6. Optional: a Telegram bot created with @BotFather and a public HTTPS address for the site, only if you want to use
   Jedomi from Telegram.

### IMPORTANT
This plugin will not install unless the **Datacurso AI Provider** plugin is installed. With the Datacurso service it
also needs a valid license of the provider to work.

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to `Site administration > Plugins > Install plugins`.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can be also installed by putting the contents of this directory to

`{your/moodle/dirroot}/local/jedomi`

Afterwards, log in to your Moodle site as an admin and go to `Site administration > Notifications` to complete the installation.

Alternatively, you can run

```bash
php admin/cli/upgrade.php
```

to complete the installation from the command line.

## AI service

Choose the service on the Jedomi page of the Datacurso AI Provider settings, with the "AI service" select (under
"Enabled"):

- **Datacurso service**: the chat, file and image, audio and voice models offered by Datacurso. Each request uses
  Datacurso credits and is recorded in the consumption history of the provider.
- **Custom service**: enter the base URL of the OpenAI-compatible API exactly as the provider documents it (for
  example `https://api.openai.com/v1`, `https://api.deepinfra.com/v1/openai`, `https://api.groq.com/openai/v1` or
  `http://localhost:11434` for Ollama) and the API key, then press "Verify connection". A URL without a path gets
  `/v1`; a URL with a path is used as given, and each route (`/models`, `/chat/completions`, `/audio/transcriptions`,
  `/audio/speech`) is added to it. Jedomi calls `GET {url}/models` and, once the endpoint answers with an OpenAI
  model list, shows the fields of the service. The verification lists the ids of the models of the service (up to
  100, sorted, only ids the fields accept) and offers them as suggestions in the model fields, which stay free text; an
  id that is not listed can still be typed. The settings can only be saved while the address and key are the last pair
  verified.

Once verified, the custom service mirrors the Datacurso fields:

| Field | Default | Notes |
| --- | --- | --- |
| Chat model | (required) | Must support tool calling. |
| Image processing + image model | off | The model is shown, and required, only while the switch is on. |
| Documents + document service URL | off | Needs image processing; see below. |
| Audio processing + audio model | off | The model is shown, and required, only while the switch is on. |
| Voice conversation + voice model and voice | off | Needs audio processing. |
| Max tokens | 16384, or half the known context window when smaller | Kept free for the answer; must be smaller than the context window. |
| Context window (tokens) | detected | See below. |

The custom service has its own switches (`custom_files_enabled`, `custom_documents_enabled`, `custom_audio_enabled`,
`custom_voice_enabled`): the Datacurso switches belong to the Datacurso service, so switching services never turns a
feature of the other one on or off. A feature is on only while its switch is on and its model is set.

**Documents with the custom service.** With "Documents" on, PDF files (attached in the web chat, sent in Telegram, or
read by the `document_read` tool from course activities) are sent to the document service, which turns them into page
images (`POST {url}/api/v1/pdf/to-images?dpi=150&fmt=JPEG`, multipart field `file`; answer
`{"total_pages": n, "pages": [{"page": 1, "format": "JPEG", "image": "<base64>"}]}`), and each page (at most 20) is read
by the image model. The document service URL follows the same rules as the service URL (HTTPS, HTTP only for a local
host with "Allow HTTP for local models", the cURL security of the site, no redirects); its requests have a 5 s
connection timeout and a 120 s total timeout, and the PDF (20 MB), the answer (32 MB) and each image (10 MB) have a
size cap. The pages asked (`document_read` with `pages`) are chosen before the 20-page cap. The whole read of a PDF has one
deadline, the AI request timeout: the pages left when it runs out are skipped, and a page the image model fails on is
skipped while the others are kept. On a site behind a proxy (Cloudflare closes requests after 100 seconds), ask for
fewer pages of long documents. Text files (`.txt`, `.csv`, `.md`, up to 2 MB, cut at 200,000 characters) are read on
the site, also only with "Documents" on and the document service URL. Word and
PowerPoint files are refused with a message asking for a PDF. With "Documents" off or no document service URL,
documents are refused as before.

**Context window.** "Verify connection" reads the context length of each model when the service tells it
(`max_model_len` of vLLM, `context_length` or `context_window`) and shows it for the chosen chat model as the
placeholder of the field ("16384 (auto)"); a value typed there overrides it. Before each AI request of the web chat,
Jedomi estimates the tokens (about four characters per token), keeps the max tokens (at most half the window) free
for the answer, and drops the
oldest messages that do not fit. The system message and the latest question (with the tool calls of the current
answer) always stay, an assistant tool call is dropped together with its results, and the kept history starts with a
user message. The technical log records each trimming (`CONTEXT_TRIMMED`, counts only). Empty and nothing detected
means no trimming.

**AI request timeout.** "AI request timeout (seconds)", after the fields of either service, sets how long the web chat
and the CLI wait for each AI answer, for both services: 10 to 600 seconds, 180 by default. Telegram and the scheduled
agents keep their own timeouts. A proxy in front of the site (Cloudflare closes requests after 100 seconds), the
`max_execution_time` of PHP or the timeout of the web server can end a request earlier.

About the custom service:

- The address must use HTTPS and respects the blocked hosts and allowed ports of the site cURL security settings. Only
  a loopback or private network host may use HTTP, with "Allow HTTP for local models" on. Redirects are never followed.
- The API key never goes back to the browser, the Jedomi log or the events, and is only sent to the saved address:
  changing the address asks for the key again.
- PDF and text files can be read with "Documents" on (see above); Word and PowerPoint files cannot.
- Its requests use no Datacurso credits and are not recorded in the consumption history of the provider.
- When a request fails, the user sees the same message as with the Datacurso service, and the technical log keeps the
  HTTP status, cURL error number or refusal reason, never the key or the answer.

## Personalization

The Personalization tab of the Jedomi settings (site configuration permission) sets:

- the avatar of the button and the panel: the Jedomi icon, one of ten preset avatars, or a custom image of up to
  512 KB;
- the position of the button (corner and distance, with a live preview) and the side the panel opens from. On screens
  up to 767 px wide the button is always bottom left (bottom right in right-to-left languages);
- the assistant name, the welcome message (with the placeholders `{assistantname}`, `{firstname}`, `{username}` and
  `{coursename}`) and site instructions for the AI, which can never override the security, permission, privacy,
  language or confirmation rules.

## Scheduled agents and their writes

Scheduled agents run on their own, so the actions they take are limited:

- **Approved writes**: an agent runs a web service function that changes data without confirmation only when that
  function is in its "Writes run without confirmation" list. The list offers the functions of the web service list
  saved for the role of the agent owner; without a saved list, an agent runs no web service function at all.
- **Scope**: an approved write runs only when its targets stay inside the course, category, user or site of the agent,
  checked on the server. A target outside the scope is rejected.
- **Held writes**: any other write, and a write whose targets cannot be verified, waits as a pending action of the agent
  creator for 7 days, to approve or reject in the Jedomi web chat.
- **Caps**: each run makes at most 20 approved writes to at most 50 distinct recipients; going over stops the run.
- **Notification**: after each run with writes, the creator gets a Moodle notification (popup and email by default)
  listing the writes run, waiting for approval and rejected.

After upgrading to 1.6.1, existing agents have no approved writes, so all their writes wait for approval until their
owners approve the functions each agent needs in its form.

## Telegram webhook

Telegram sends the webhook secret in the `X-Telegram-Bot-Api-Secret-Token` header; the secret is not part of the
webhook address, and a secret in the query string is refused. Upgrading to 1.6.1 rotates the secret and registers the
webhook again when a bot token is configured. If Telegram cannot be reached at that moment, the Integrations tab shows
a warning until the bot token is saved again.

## Anonymisation

Personal data is replaced by placeholders such as `[person_1]` or `[id_1]` before it reaches the AI service (messages,
attached texts, page context, history and tool results) in the web chat, Telegram, the scheduled agents and the agent
preview. The answer shown to the user and the arguments of the tools the AI calls get the real values back, so the user
never sees a placeholder. The level is set in the Privacy tab:

- **Basic** (default): names of site users, email addresses and identifier columns of tool results (user name, ID
  number, phones, address, IP address, messaging ids and web page).
- **Strict**: also free text (descriptions, messages, long texts) and codes mixing letters and digits inside texts.
- **Feedback only**: the AI receives the same as with Basic, and ratings are saved without the conversation text.

## Technical log and privacy

The plugin writes an operational and security trace to `{dataroot}/local/jedomi/jedomi.log`. It never keeps user ids,
Telegram chat ids or conversation texts: users and chats appear as pseudonyms (a keyed hash with a random key of the
site), and the source IP address is kept only for requests rejected for security reasons. The file rotates at 5 MB and
keeps the five previous files (about 30 MB in total), so the oldest entries are removed as new ones are written; an
administrator can delete the files at any time. The log is declared in the privacy registry of the site.

## Database queries and their limits

The SQL tool only runs read-only `SELECT` queries. Each channel has its own limits, and the AI is told the limits of the
channel it works in:

| Channel | Rows | Time per query |
| --- | --- | --- |
| Web chat | 100 (setting, 10 to 1000) | 5 s |
| Telegram | 50 | 5 s |
| Companion web service (`jedomi_execute_sql`) | 100 | 5 s |
| Scheduled agents | 1000 | 30 s |

The row limit is applied by the database on every supported database. The time limit is applied by the database on
MySQL, MariaDB and PostgreSQL only: SQL Server and Oracle have no per-query time limit that can be set safely from
Moodle, so there a slow query runs until the database or PHP stops it. The query profiler (Query profiler tab) records
the queries of the agents; its slow query threshold and the days its records are kept are set in the General tab, and
the scheduled task "Purge old query profiler records" removes the old records once a day. The row limit of the web chat
is set in the General tab too ("Maximum rows per query (web chat)", `sql_row_limit_web`); the other channels keep their
fixed limits.

## Web chat usage limits

These limits are applied in Moodle, so they hold with the Datacurso AI service and with the custom AI service alike:

- **Messages per user per minute (web chat)** (`chat_rate_limit`, General tab, 0 to 120, default 10; 0 turns it off).
  Every request of the chat panel that leads to an AI call counts one: each question (`chatproxy.php`) and each
  attached file, image or voice message read or transcribed (`extract_file.php`, `read_file.php`). A question with two
  attached files counts three. Requests refused because the user cannot use Jedomi there do not count. A user over the
  limit is told, in the chat, how many seconds to wait, and no AI request is made; the refusal is logged as
  `RATE_LIMITED` (pseudonymised user, no content). The window slides: each request counts for one minute. Site
  administrators are never limited. Telegram keeps its own limit, and the scheduled agents and the companion web service
  are not affected.
  - The counts are kept in the application cache `local_jedomi/chat_rate` under a per-user lock. On a site with
    several web servers the limit holds across them only when the application cache is a shared store (Redis or
    Memcached); with a file store on each server it applies per server.
  - If the lock cannot be taken (two requests of the same user at the same moment), the request is refused with
    "Please try again in a moment" (`rate_limit_busy`, logged as `RATE_LIMIT_BUSY`). If the cache store itself fails,
    requests go through without a limit and `RATE_LIMIT_UNAVAILABLE` is logged, so a cache fault never stops the chat.
- **Request size**: `chatproxy.php` refuses a request body above 8 MB before decoding it. The text of attached files
  travels inside the conversation history, so the cap leaves room for two messages with five files at the 200,000
  character cap each.
- **Attachment size**: files attached in the chat (`extract_file.php`, `read_file.php`) and files read for Telegram are
  refused above 20 MB before they are read or sent; the panel refuses them before uploading. The custom AI service keeps
  its smaller caps for images (10 MB) and text files (2 MB). PHP's `upload_max_filesize` and `post_max_size` must allow
  the size you want to accept.

## Documentation

The complete documentation (configuration, roles and permissions, use of the chat, scheduled agents, Telegram and command line tools) is available at [https://docs.datacurso.com/docs/plugins/moodle-local_jedomi](https://docs.datacurso.com/docs/plugins/moodle-local_jedomi).
