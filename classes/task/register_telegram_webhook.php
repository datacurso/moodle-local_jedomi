<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi\task;

use core\task\adhoc_task;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\manager;

/**
 * Adhoc task that retries the registration of the Telegram webhook with a new secret (RES-001).
 *
 * Queued when the update could not register the webhook. Each attempt calls setWebhook with a fresh secret, stored only
 * once Telegram accepts it. A failed attempt fails the task, so cron retries it with its backoff (1, 2, 4... minutes);
 * after ATTEMPTS attempts (about 17 hours) it stops. The site administrators are notified on the first and on the last
 * failed attempt; the Integrations tab warns until the webhook is registered.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_telegram_webhook extends adhoc_task {
    /** @var int Attempts of the task: with the cron backoff the last one runs about 17 hours after the first. */
    public const ATTEMPTS = 11;

    /** @var int Seconds each attempt may wait for Telegram. */
    public const TIMEOUT = 10;

    /**
     * Queue the task once, with its limited attempts.
     *
     * @return void
     */
    public static function queue(): void {
        $task = new self();
        $task->set_attempts_available(self::ATTEMPTS);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    #[\Override]
    public function get_name(): string {
        return get_string('task_register_telegram_webhook', 'local_jedomi');
    }

    /**
     * Register the webhook with a new secret; fail, so the task is retried, while Telegram refuses it.
     *
     * @return void
     * @throws \moodle_exception When the attempt failed and attempts remain.
     */
    public function execute(): void {
        if (!manager::needs_webhook_registration()) {
            mtrace('[local_jedomi] Telegram webhook registration is no longer needed.');
            return;
        }
        if (manager::is_bot_token_unreadable()) {
            // The token exists but cannot be decrypted: retrying cannot help and the registration stays pending until
            // the administrator enters the token again, which registers the webhook.
            manager::set_last_webhook_error('The bot token could not be decrypted (the encryption key of the site changed); '
                . 'enter the token again');
            \local_jedomi_log('[local_jedomi] Telegram webhook not registered: the bot token could not be decrypted');
            mtrace('[local_jedomi] Telegram webhook not registered: the bot token could not be decrypted.');
            return;
        }
        if (manager::get_bot_token() === null) {
            manager::clear_webhook_registration_need();
            mtrace('[local_jedomi] Telegram webhook not registered: no bot token is configured.');
            return;
        }

        $client = api_client::instance();
        $client->set_timeout(self::TIMEOUT);
        if (manager::register_webhook_with_new_secret($client)) {
            mtrace('[local_jedomi] Telegram webhook registered with a new secret.');
            return;
        }

        $attempts = $this->get_attempts_available();
        $final = $attempts <= 1;
        if ($final || $attempts >= self::ATTEMPTS) {
            self::notify_admins($final);
        }
        if ($final) {
            mtrace('[local_jedomi] Telegram webhook could not be registered; retries stopped.');
            return;
        }
        throw new \moodle_exception('telegram_webhook_retry_failed', 'local_jedomi');
    }

    /**
     * Send every site administrator a notification that the bot does not receive messages.
     *
     * @param bool $final Whether the retries stopped.
     * @return void
     */
    public static function notify_admins(bool $final): void {
        $url = (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'integrations']))->out(false);
        foreach (get_admins() as $admin) {
            try {
                [$subject, $text, $open] = \local_jedomi\local\user_language::run((int) $admin->id, static fn(): array => [
                    get_string('telegram_webhook_failed_subject', 'local_jedomi'),
                    get_string($final ? 'telegram_webhook_failed_final_body' : 'telegram_webhook_failed_body', 'local_jedomi'),
                    get_string('telegram_webhook_failed_open', 'local_jedomi'),
                ]);
                $message = new \core\message\message();
                $message->component = 'local_jedomi';
                $message->name = 'telegramwebhook';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $admin;
                $message->subject = $subject;
                $message->fullmessage = $text;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = text_to_html(s($text), false, false, true);
                $message->smallmessage = $subject;
                $message->notification = 1;
                $message->contexturl = $url;
                $message->contexturlname = $open;
                message_send($message);
            } catch (\Throwable $e) {
                mtrace('[local_jedomi] Telegram webhook notification failed (' . get_class($e) . ')');
            }
        }
    }
}
