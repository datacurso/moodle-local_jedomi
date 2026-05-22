<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Hook listener — pre-populates the Moodle AI admin form from local_jedomi config.
 *
 * @package   aiprovider_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiprovider_jedomi;

use core_ai\hook\after_ai_provider_form_hook;
use core_ai\hook\after_ai_action_settings_form_hook;

defined('MOODLE_INTERNAL') || die();

class hook_listener {

    /**
     * Adds Jedomi-specific fields to the AI provider instance admin form.
     * Values are pre-populated from local_jedomi config so the admin
     * never needs to configure the same settings in two places.
     */
    public static function set_form_definition_for_aiprovider_jedomi(
        after_ai_provider_form_hook $hook
    ): void {
        if ($hook->plugin !== 'aiprovider_jedomi') {
            return;
        }

        $mform = $hook->mform;

        // ── Gateway URL ───────────────────────────────────────────────────────
        $mform->addElement('text', 'gatewayurl',
            get_string('gatewayurl', 'aiprovider_jedomi'), ['size' => 50]);
        $mform->setType('gatewayurl', PARAM_URL);
        $mform->addHelpButton('gatewayurl', 'gatewayurl', 'aiprovider_jedomi');
        $mform->addRule('gatewayurl', get_string('required'), 'required', null, 'client');

        // Pre-fill from local_jedomi config (so the admin doesn't duplicate config).
        $mform->setDefault('gatewayurl',
            get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765');

        // ── API Key ───────────────────────────────────────────────────────────
        $mform->addElement('passwordunmask', 'apikey',
            get_string('apikey', 'aiprovider_jedomi'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'aiprovider_jedomi');
        $mform->setDefault('apikey',
            get_config('local_jedomi', 'api_key') ?: '');

        // ── Default model ─────────────────────────────────────────────────────
        $mform->addElement('select', 'defaultmodel',
            get_string('defaultmodel', 'aiprovider_jedomi'),
            [
                'jedomi-e4b' => 'jedomi-e4b — Full (8B, university servers)',
                'jedomi-e2b' => 'jedomi-e2b — Lightweight (5B, student PCs)',
            ]);
        $mform->addHelpButton('defaultmodel', 'defaultmodel', 'aiprovider_jedomi');
        $mform->setDefault('defaultmodel',
            get_config('local_jedomi', 'model') ?: 'jedomi-e4b');
    }

    /**
     * Action-level settings are handled via classes/form/action_form.php.
     */
    public static function set_model_form_definition_for_aiprovider_jedomi(
        after_ai_action_settings_form_hook $hook
    ): void {
        if ($hook->plugin !== 'aiprovider_jedomi') {
            return;
        }
        // Intentionally empty — action settings handled by action_form.php.
    }
}
