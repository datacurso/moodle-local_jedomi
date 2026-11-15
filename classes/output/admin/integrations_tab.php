<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\output\admin;

/**
 * Integrations tab of the Jedomi settings: the Telegram bot card.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class integrations_tab extends form_tab {
    /** @var array Telegram settings from admin_settings_service::get_telegram_settings(). */
    protected array $telegram;

    /**
     * Store the Telegram settings and the rendered form.
     *
     * @param array $telegram Telegram settings from admin_settings_service::get_telegram_settings().
     * @param string $formhtml HTML of the Telegram settings form.
     */
    public function __construct(array $telegram, string $formhtml) {
        parent::__construct($formhtml);
        $this->telegram = $telegram;
    }

    /**
     * Export the bot state, the webhook status and the setup guide for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $tg = $this->telegram;
        $tokenset = (bool) $tg['bot_token_set'];
        $verified = (bool) $tg['bot_verified'];
        $lasterror = (string) $tg['last_error'];
        $errortime = (int) $tg['last_error_time'];
        // A registration that succeeded and was not followed by an error means the webhook works.
        $webhookok = $tokenset && $lasterror === '' && (int) ($tg['webhook_ok_time'] ?? 0) > 0;

        $adminsteps = [];
        foreach ([1, 2, 3, 4] as $step) {
            $adminsteps[] = [
                'number' => (string) $step,
                'titlekey' => 'telegram_step' . $step . '_title',
                'title' => get_string('telegram_step' . $step . '_title', 'local_jedomi'),
                'desc' => get_string('telegram_step' . $step . '_desc', 'local_jedomi'),
                // Step 3 is the webhook registration, so it shows whether the webhook is registered.
                'showwebhookstate' => $step === 3,
            ];
        }
        $usersteps = [];
        foreach ([1, 2, 3, 4] as $step) {
            $usersteps[] = [
                'number' => 'U' . $step,
                'titlekey' => 'telegram_stepU' . $step . '_title',
                'title' => get_string('telegram_stepU' . $step . '_title', 'local_jedomi'),
                // The profile section is named after the plugin, as the profile page shows it.
                'desc' => get_string('telegram_stepU' . $step . '_desc', 'local_jedomi', get_string('pluginname', 'local_jedomi')),
            ];
        }

        return parent::export_for_template($output) + [
            'enabled' => (bool) $tg['enabled'],
            'tokenset' => $tokenset,
            'verified' => $verified,
            'botusername' => (string) $tg['bot_username'],
            'botfirstname' => (string) $tg['bot_firstname'],
            'showbadge' => $verified && $tg['bot_username'] !== '',
            'webhookurl' => (string) $tg['webhook_url'],
            'haslasterror' => $lasterror !== '',
            'lasterror' => $lasterror,
            'lasterrortime' => $errortime,
            'haslasterrortime' => $errortime > 0,
            'webhookok' => $webhookok,
            'webhooknotchecked' => $lasterror === '' && !$webhookok,
            'isconfigured' => (bool) $tg['is_configured'],
            'showerroralert' => $tokenset && !empty($tg['webhook_error']),
            // The update could not register the webhook with its new secret: the bot is silent until the token is saved.
            'needsregistration' => !empty($tg['needs_registration']),
            'adminsteps' => $adminsteps,
            'usersteps' => $usersteps,
        ];
    }

    /**
     * Template of the tab.
     *
     * @param \renderer_base $renderer Renderer used to render the tab.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/admin/integrations_tab';
    }
}
