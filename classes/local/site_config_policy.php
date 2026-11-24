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

namespace local_jedomi\local;

/**
 * The site settings Jedomi may write, with the type of value each one takes (JEDOMI-SEC-011).
 *
 * This is the single source of truth for every channel that writes site settings: only the companion web service
 * local_jedomi_set_site_config writes them today. Any setting not listed here is refused.
 *
 * The list holds only settings of presentation and defaults: the names of the site, its default location, language and
 * first day of the week, and the defaults of new courses. None of them decides who can log in, which privileges anyone
 * has, where data or requests are sent, or how the site is secured. Left out on purpose:
 *  - every setting of an AI provider of Moodle (any aiprovider_* component, whatever the setting): their endpoints would
 *    send the API key and every AI request to another server;
 *  - authentication, roles, security, network, mail, paths and anything holding a secret;
 *  - the settings of local_jedomi itself, so the AI cannot loosen its own limits (course scope, anonymisation);
 *  - the course default maximum upload size and other settings that weigh on disk or performance.
 *
 * Names are compared in lower case: the config table is case-insensitive on MySQL and MariaDB.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_config_policy {
    /** @var string Plain text that may not be empty. */
    private const TYPE_NAME = 'name';
    /** @var string Plain text, possibly empty. */
    private const TYPE_TEXT = 'text';
    /** @var string 0 or 1. */
    private const TYPE_BOOL = 'bool';
    /** @var string Timezone known to Moodle, or 99 for the timezone of the server. */
    private const TYPE_TIMEZONE = 'timezone';
    /** @var string Country code known to Moodle, or empty. */
    private const TYPE_COUNTRY = 'country';
    /** @var string Installed language pack. */
    private const TYPE_LANG = 'lang';
    /** @var string Installed language pack, or empty (no forced language). */
    private const TYPE_LANG_OPTIONAL = 'langoptional';
    /** @var string Installed course format. */
    private const TYPE_FORMAT = 'format';

    /** @var int Longest plain text value accepted. */
    private const MAX_TEXT = 255;

    /**
     * Allowed settings by component ('core' for the main settings). A type is one of the TYPE_ constants, or an integer
     * range [min, max].
     */
    private const ALLOWED = [
        'core' => [
            'fullname' => self::TYPE_NAME,
            'shortname' => self::TYPE_NAME,
            'timezone' => self::TYPE_TIMEZONE,
            'country' => self::TYPE_COUNTRY,
            'defaultcity' => self::TYPE_TEXT,
            'lang' => self::TYPE_LANG,
            'calendar_startwday' => [0, 6],
        ],
        'moodlecourse' => [
            'format' => self::TYPE_FORMAT,
            'numsections' => [0, 52],
            'hiddensections' => [0, 1],
            'coursedisplay' => [0, 1],
            'newsitems' => [0, 10],
            'showgrades' => self::TYPE_BOOL,
            'showreports' => self::TYPE_BOOL,
            'showactivitydates' => self::TYPE_BOOL,
            'visible' => self::TYPE_BOOL,
            'lang' => self::TYPE_LANG_OPTIONAL,
            'enablecompletion' => self::TYPE_BOOL,
            'showcompletionconditions' => self::TYPE_BOOL,
            'groupmode' => [0, 2],
            'groupmodeforce' => self::TYPE_BOOL,
        ],
    ];

    /**
     * Whether a setting may be written.
     *
     * @param string $plugin Component, 'core' or empty for the main settings.
     * @param string $name Setting name, in any case.
     * @return bool
     */
    public static function is_allowed(string $plugin, string $name): bool {
        return self::type_of($plugin, $name) !== null;
    }

    /**
     * Whether a value fits the type of an allowed setting. False for a setting that is not allowed.
     *
     * @param string $plugin Component, 'core' or empty for the main settings.
     * @param string $name Setting name, in any case.
     * @param string $value Value to write.
     * @return bool
     */
    public static function is_valid_value(string $plugin, string $name, string $value): bool {
        $type = self::type_of($plugin, $name);
        if ($type === null) {
            return false;
        }
        if (is_array($type)) {
            return preg_match('/^\d{1,4}$/', $value) === 1 && (int) $value >= $type[0] && (int) $value <= $type[1];
        }
        switch ($type) {
            case self::TYPE_NAME:
                return trim($value) !== '' && self::is_plain_text($value);
            case self::TYPE_TEXT:
                return self::is_plain_text($value);
            case self::TYPE_BOOL:
                return $value === '0' || $value === '1';
            case self::TYPE_TIMEZONE:
                return array_key_exists($value, \core_date::get_list_of_timezones(null, true));
            case self::TYPE_COUNTRY:
                return $value === '' || array_key_exists($value, get_string_manager()->get_list_of_countries(true));
            case self::TYPE_LANG_OPTIONAL:
                if ($value === '') {
                    return true;
                }
                return array_key_exists($value, get_string_manager()->get_list_of_translations());
            case self::TYPE_LANG:
                return array_key_exists($value, get_string_manager()->get_list_of_translations());
            case self::TYPE_FORMAT:
                return array_key_exists($value, \core_component::get_plugin_list('format'));
        }
        return false;
    }

    /**
     * Name the setting is stored under: the name in the allowlist, whatever the case it was sent in.
     *
     * @param string $name Setting name, in any case.
     * @return string
     */
    public static function canonical_name(string $name): string {
        return \core_text::strtolower($name);
    }

    /**
     * Allowed settings as "component/name" (main settings without component), separated by commas, for messages.
     *
     * @return string
     */
    public static function allowed_keys_list(): string {
        $keys = [];
        foreach (self::ALLOWED as $plugin => $settings) {
            foreach (array_keys($settings) as $name) {
                $keys[] = $plugin === 'core' ? $name : "{$plugin}/{$name}";
            }
        }
        return implode(', ', $keys);
    }

    /**
     * Type of an allowed setting, or null when it is not allowed. No component of an AI provider is ever allowed.
     *
     * @param string $plugin Component, 'core' or empty for the main settings.
     * @param string $name Setting name, in any case.
     * @return string|int[]|null
     */
    private static function type_of(string $plugin, string $name) {
        $plugin = \core_text::strtolower($plugin) ?: 'core';
        if (strpos($plugin, 'aiprovider_') === 0) {
            return null;
        }
        return self::ALLOWED[$plugin][self::canonical_name($name)] ?? null;
    }

    /**
     * Whether a value is plain text of reasonable length: no markup, no control characters.
     *
     * @param string $value Value.
     * @return bool
     */
    private static function is_plain_text(string $value): bool {
        return \core_text::strlen($value) <= self::MAX_TEXT
            && clean_param($value, PARAM_NOTAGS) === $value
            && preg_match('/[\x00-\x1F\x7F]/', $value) === 0;
    }
}
