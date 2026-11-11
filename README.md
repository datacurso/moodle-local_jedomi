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

## Documentation

The complete documentation (configuration, roles and permissions, use of the chat, scheduled agents, Telegram and command line tools) is available at [https://docs.datacurso.com/docs/plugins/moodle-local_jedomi](https://docs.datacurso.com/docs/plugins/moodle-local_jedomi).
