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

namespace local_jedomi\whatsapp;

use local_jedomi\local\secret_store;

/**
 * Settings of the WhatsApp channel (Meta WhatsApp Cloud API).
 *
 * The phone number ID, the WABA ID, the permanent access token, the App Secret and the verify token are kept encrypted
 * with the key of the site (secret_store, as the Telegram bot token), so a dump of the database alone does not reveal
 * them. The Graph API version is kept in clear text. No value is ever logged.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Graph API version used until the administrator sets another one. */
    public const DEFAULT_GRAPH_API_VERSION = 'v25.0';

    /** @var string Component whose settings hold the channel settings. */
    private const COMPONENT = 'local_jedomi';

    /** @var string Setting of the phone number ID (encrypted). */
    private const PHONE_NUMBER_ID = 'whatsapp_phone_number_id';

    /** @var string Setting of the WhatsApp Business Account ID (encrypted). */
    private const WABA_ID = 'whatsapp_waba_id';

    /** @var string Setting of the permanent access token of the system user (encrypted). */
    private const ACCESS_TOKEN = 'whatsapp_access_token';

    /** @var string Setting of the App Secret that signs the webhook (encrypted). */
    private const APP_SECRET = 'whatsapp_app_secret';

    /** @var string Setting of the verify token of the webhook handshake (encrypted). */
    private const VERIFY_TOKEN = 'whatsapp_verify_token';

    /** @var string Setting of the Graph API version (clear text). */
    private const GRAPH_API_VERSION = 'whatsapp_graph_api_version';

    /** @var string Setting that turns the channel on. */
    private const ENABLED = 'whatsapp_enabled';

    /** @var string Setting of the processing mode of the webhook (clear text). */
    private const PROCESSING_MODE = 'whatsapp_processing_mode';

    /** @var string The webhook answers Meta early and processes the message in the same request; the default. */
    public const PROCESSING_INLINE = 'inline';

    /**
     * @var string The webhook only stores the message and the rescue ad hoc task processes it, for sites whose proxies
     * hold the early response back or that run a dedicated ad hoc task worker.
     */
    public const PROCESSING_QUEUE = 'queue';

    /** @var string Pattern of a Graph API version, such as v25.0. */
    private const VERSION_PATTERN = '/^v\d+\.\d+$/D';

    /** @var string Display number of the institution, digits only, for the wa.me link. */
    private const DISPLAY_NUMBER = 'whatsapp_display_number';

    /** @var string Pattern of a display number in E.164 without the +: 8 to 15 digits, not starting with 0. */
    private const DISPLAY_NUMBER_PATTERN = '/^[1-9]\d{7,14}$/D';

    /** @var string Setting with the contact of a person that students are offered when they need one. */
    private const ESCALATION_CONTACT = 'whatsapp_escalation_contact';

    /** @var int Characters of the contact of a person kept for the prompt. */
    public const ESCALATION_CONTACT_LENGTH = 200;

    /**
     * Save the phone number ID; empty clears it.
     *
     * @param string $id Numeric phone number ID.
     * @throws \moodle_exception config_value_invalid_msg when it is not numeric.
     */
    public static function set_phone_number_id(string $id): void {
        self::set_numeric_id(self::PHONE_NUMBER_ID, $id);
    }

    /**
     * Phone number ID, or an empty string when it is not saved.
     *
     * @return string
     */
    public static function get_phone_number_id(): string {
        return secret_store::get(self::PHONE_NUMBER_ID);
    }

    /**
     * Save the WhatsApp Business Account ID; empty clears it.
     *
     * @param string $id Numeric WABA ID.
     * @throws \moodle_exception config_value_invalid_msg when it is not numeric.
     */
    public static function set_waba_id(string $id): void {
        self::set_numeric_id(self::WABA_ID, $id);
    }

    /**
     * WhatsApp Business Account ID, or an empty string when it is not saved.
     *
     * @return string
     */
    public static function get_waba_id(): string {
        return secret_store::get(self::WABA_ID);
    }

    /**
     * Save the permanent access token of the system user; empty clears it.
     *
     * @param string $token Access token.
     */
    public static function set_access_token(string $token): void {
        secret_store::set(self::ACCESS_TOKEN, trim($token));
    }

    /**
     * Access token, or an empty string when it is not saved or cannot be decrypted.
     *
     * @return string
     */
    public static function get_access_token(): string {
        return secret_store::get(self::ACCESS_TOKEN);
    }

    /**
     * Save the App Secret that signs the webhook; empty clears it.
     *
     * @param string $secret App Secret.
     */
    public static function set_app_secret(string $secret): void {
        secret_store::set(self::APP_SECRET, trim($secret));
    }

    /**
     * App Secret, or an empty string when it is not saved or cannot be decrypted.
     *
     * @return string
     */
    public static function get_app_secret(): string {
        return secret_store::get(self::APP_SECRET);
    }

    /**
     * Save the verify token of the webhook handshake; empty clears it.
     *
     * @param string $token Verify token.
     */
    public static function set_verify_token(string $token): void {
        secret_store::set(self::VERIFY_TOKEN, trim($token));
    }

    /**
     * Verify token, or an empty string when it is not saved or cannot be decrypted.
     *
     * @return string
     */
    public static function get_verify_token(): string {
        return secret_store::get(self::VERIFY_TOKEN);
    }

    /**
     * Whether a value is a Graph API version, such as v25.0.
     *
     * @param string $version Version.
     * @return bool
     */
    public static function is_valid_graph_api_version(string $version): bool {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * Save the Graph API version; empty restores the default.
     *
     * @param string $version Version, such as v25.0.
     * @throws \moodle_exception config_value_invalid_msg when it does not have the form vN.N.
     */
    public static function set_graph_api_version(string $version): void {
        $version = trim($version);
        if ($version === '') {
            unset_config(self::GRAPH_API_VERSION, self::COMPONENT);
            return;
        }
        if (!self::is_valid_graph_api_version($version)) {
            throw new \moodle_exception('config_value_invalid_msg', 'local_jedomi', '', self::GRAPH_API_VERSION);
        }
        set_config(self::GRAPH_API_VERSION, $version, self::COMPONENT);
    }

    /**
     * Graph API version of the calls; the default when none, or an invalid one, is saved (it is part of every address).
     *
     * @return string
     */
    public static function get_graph_api_version(): string {
        $version = (string) get_config(self::COMPONENT, self::GRAPH_API_VERSION);
        return self::is_valid_graph_api_version($version) ? $version : self::DEFAULT_GRAPH_API_VERSION;
    }

    /**
     * Turn the channel on or off.
     *
     * @param bool $enabled Whether the channel is on.
     */
    public static function set_enabled(bool $enabled): void {
        set_config(self::ENABLED, $enabled ? 1 : 0, self::COMPONENT);
    }

    /**
     * Whether the channel is on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config(self::COMPONENT, self::ENABLED);
    }

    /**
     * Save the processing mode of the webhook; empty restores the default.
     *
     * @param string $mode PROCESSING_INLINE or PROCESSING_QUEUE.
     * @throws \moodle_exception config_value_invalid_msg for any other value.
     */
    public static function set_processing_mode(string $mode): void {
        $mode = trim($mode);
        if ($mode === '') {
            unset_config(self::PROCESSING_MODE, self::COMPONENT);
            return;
        }
        if (!in_array($mode, [self::PROCESSING_INLINE, self::PROCESSING_QUEUE], true)) {
            throw new \moodle_exception('config_value_invalid_msg', 'local_jedomi', '', self::PROCESSING_MODE);
        }
        set_config(self::PROCESSING_MODE, $mode, self::COMPONENT);
    }

    /**
     * Processing mode of the webhook: PROCESSING_QUEUE only when saved so, PROCESSING_INLINE otherwise.
     *
     * @return string
     */
    public static function get_processing_mode(): string {
        $mode = (string) get_config(self::COMPONENT, self::PROCESSING_MODE);
        return $mode === self::PROCESSING_QUEUE ? self::PROCESSING_QUEUE : self::PROCESSING_INLINE;
    }

    /**
     * Contact of a person (an email address, a phone or an office) that the assistant offers when a student needs a
     * person, as the policy of Meta asks; empty when none is saved. It is one line of at most
     * ESCALATION_CONTACT_LENGTH characters, since it is written into the prompt of the AI.
     *
     * @return string
     */
    public static function get_escalation_contact(): string {
        $contact = (string) get_config(self::COMPONENT, self::ESCALATION_CONTACT);
        $contact = trim(preg_replace('/[\p{C}\s]+/u', ' ', fix_utf8($contact)) ?? '');
        return \core_text::substr($contact, 0, self::ESCALATION_CONTACT_LENGTH);
    }

    /**
     * Save the number students write to, shown on the profile page; empty clears it. Spaces, dashes, brackets and a
     * leading + are dropped, so only the digits of the E.164 number are kept.
     *
     * @param string $number Phone number of the institution.
     * @throws \moodle_exception config_value_invalid_msg when it is not an E.164 number.
     */
    public static function set_display_number(string $number): void {
        $number = trim($number);
        if ($number === '') {
            unset_config(self::DISPLAY_NUMBER, self::COMPONENT);
            return;
        }
        $digits = preg_replace('/^\+|[\s\-().]/', '', $number);
        if (preg_match(self::DISPLAY_NUMBER_PATTERN, (string) $digits) !== 1) {
            throw new \moodle_exception('config_value_invalid_msg', 'local_jedomi', '', self::DISPLAY_NUMBER);
        }
        set_config(self::DISPLAY_NUMBER, $digits, self::COMPONENT);
    }

    /**
     * Display number of the institution as digits only, or an empty string when it is not saved.
     *
     * @return string
     */
    public static function get_display_number(): string {
        $number = (string) get_config(self::COMPONENT, self::DISPLAY_NUMBER);
        return preg_match(self::DISPLAY_NUMBER_PATTERN, $number) === 1 ? $number : '';
    }

    /**
     * Whether every setting the channel needs is saved and readable.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::get_phone_number_id() !== ''
            && self::get_waba_id() !== ''
            && self::get_access_token() !== ''
            && self::get_app_secret() !== ''
            && self::get_verify_token() !== '';
    }

    /**
     * Save a numeric identifier of Meta encrypted; empty clears it.
     *
     * @param string $name Setting name.
     * @param string $id Identifier.
     * @throws \moodle_exception config_value_invalid_msg when it is not numeric.
     */
    private static function set_numeric_id(string $name, string $id): void {
        $id = trim($id);
        if ($id !== '' && preg_match(cloud_api_client::ID_PATTERN, $id) !== 1) {
            throw new \moodle_exception('config_value_invalid_msg', 'local_jedomi', '', $name);
        }
        secret_store::set($name, $id);
    }
}
