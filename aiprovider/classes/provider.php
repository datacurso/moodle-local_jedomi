<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Jedomi AI Provider — reads config from local_jedomi when not overridden.
 *
 * @package   aiprovider_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiprovider_jedomi;

use core_ai\form\action_settings_form;

defined('MOODLE_INTERNAL') || die();

class provider extends \core_ai\provider {

    #[\Override]
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];
    }

    #[\Override]
    public static function get_action_settings(
        string $action,
        array $customdata = [],
    ): action_settings_form|bool {
        $actionname = substr($action, (strrpos($action, '\\') + 1));
        $customdata['actionname']   = $actionname;
        $customdata['action']       = $action;
        $customdata['providername'] = 'aiprovider_jedomi';

        if (in_array($actionname, ['generate_text', 'summarise_text', 'explain_text'])) {
            return new form\action_form(customdata: $customdata);
        }
        return false;
    }

    #[\Override]
    public static function get_action_setting_defaults(string $action): array {
        $actionname = substr($action, (strrpos($action, '\\') + 1));
        $customdata = [
            'actionname'   => $actionname,
            'action'       => $action,
            'providername' => 'aiprovider_jedomi',
        ];
        if (in_array($actionname, ['generate_text', 'summarise_text', 'explain_text'])) {
            $mform = new form\action_form(customdata: $customdata);
            return $mform->get_defaults();
        }
        return [];
    }

    #[\Override]
    public function add_authentication_headers(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\RequestInterface {
        // Provider-specific key first, fall back to local_jedomi config.
        $apikey = $this->config['apikey']
            ?? get_config('local_jedomi', 'api_key')
            ?? '';
        if (!empty($apikey)) {
            return $request->withAddedHeader('Authorization', 'Bearer ' . $apikey);
        }
        return $request;
    }

    #[\Override]
    public function is_provider_configured(): bool {
        // Configured if either the provider-specific URL OR local_jedomi URL is set.
        return !empty($this->config['gatewayurl'])
            || !empty(get_config('local_jedomi', 'gateway_url'));
    }
}
