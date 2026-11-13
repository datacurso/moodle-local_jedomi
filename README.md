# Jedomi AI Sidebar

**Jedomi AI Sidebar** (`local_jedomi`) adds an AI assistant to Moodle as a side panel available on every page. Administrators, managers, teachers and students ask questions in natural language and Jedomi answers with real data from the site, according to the role and permissions of each user. It can also run actions that change data after the user confirms them, read attached files, images and audio, answer from Telegram, and run scheduled AI agents that send their results to Telegram.

All AI processing goes through the **Datacurso AI Provider**, which supplies the license, the credits and the usage limits.

This plugin is part of the **Datacurso AI Plugin Suite**.

## Pre-requisites

1. Moodle 4.5 or later.
2. The Moodle AI provider **Datacurso AI Provider** (`aiprovider_datacurso`) 2026111100 (release 1.8.0) or later, installed **before** Jedomi. Download it for free from [https://moodle.org/plugins/aiprovider_datacurso/versions](https://moodle.org/plugins/aiprovider_datacurso/versions).
3. A valid license key configured in the Datacurso AI Provider settings, as documented at [https://docs.datacurso.com/docs/plugins/moodle-aiprovider_datacurso/getting-license-keys](https://docs.datacurso.com/docs/plugins/moodle-aiprovider_datacurso/getting-license-keys).
4. Moodle cron running, for the scheduled agents and the cleanup tasks.
5. Optional: a Telegram bot created with @BotFather and a public HTTPS address for the site, only if you want to use Jedomi from Telegram.

### IMPORTANT
This plugin will not install or work unless the **Datacurso AI Provider** plugin is installed and licensed.

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
