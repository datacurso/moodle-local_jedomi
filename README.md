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
- **Custom service**: enter the service URL (a trailing `/v1` is optional) and the API key, then press "Verify
  connection". Jedomi calls `GET {url}/v1/models` and, once the endpoint answers with an OpenAI model list, shows the
  model fields (chat, image, audio, voice and voice name) as free text. The settings can only be saved while the
  address and key are the last pair verified. A feature is on only while its model is set.

About the custom service:

- The address must use HTTPS and respects the blocked hosts and allowed ports of the site cURL security settings. Only
  a loopback or private network host may use HTTP, with "Allow HTTP for local models" on. Redirects are never followed.
- The API key never goes back to the browser, the Jedomi log or the events, and is only sent to the saved address:
  changing the address asks for the key again.
- Documents (PDF, Word, PowerPoint and text files) cannot be read with it; images and audio can, when their models are
  set.
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
| Web chat | 100 | 5 s |
| Telegram | 50 | 5 s |
| Companion web service (`jedomi_execute_sql`) | 100 | 5 s |
| Scheduled agents | 1000 | 30 s |

The row limit is applied by the database on every supported database. The time limit is applied by the database on
MySQL, MariaDB and PostgreSQL only: SQL Server and Oracle have no per-query time limit that can be set safely from
Moodle, so there a slow query runs until the database or PHP stops it. The query profiler (Query profiler tab) records
the queries of the agents; its slow query threshold and the days its records are kept are set in the General tab, and
the scheduled task "Purge old query profiler records" removes the old records once a day.

## Documentation

The complete documentation (configuration, roles and permissions, use of the chat, scheduled agents, Telegram and command line tools) is available at [https://docs.datacurso.com/docs/plugins/moodle-local_jedomi](https://docs.datacurso.com/docs/plugins/moodle-local_jedomi).
