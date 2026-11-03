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

namespace local_jedomi;

/**
 * Connection settings of the AI gateway (URL, API key, model), in one place.
 *
 * There are no built-in fallbacks for the URL or the key: without both, nothing calls the gateway and each caller
 * reports that the gateway is not configured, instead of sending a placeholder key to a guessed address.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway_config {
    /** @var string Model used when the site sets none. */
    public const DEFAULT_MODEL = 'jedomi-e4b';

    /**
     * The gateway settings, or null when the URL or the API key is missing.
     *
     * @return \stdClass|null {url (without trailing slash), key, model}
     */
    public static function get(): ?\stdClass {
        $url = rtrim(trim((string) get_config('local_jedomi', 'gateway_url')), '/');
        $key = trim((string) get_config('local_jedomi', 'api_key'));
        if ($url === '' || $key === '') {
            return null;
        }
        $model = trim((string) get_config('local_jedomi', 'model'));
        return (object) ['url' => $url, 'key' => $key, 'model' => $model !== '' ? $model : self::DEFAULT_MODEL];
    }

    /**
     * The gateway settings, for callers that cannot go on without them.
     *
     * @return \stdClass {url, key, model}
     * @throws \moodle_exception When the URL or the API key is missing.
     */
    public static function require(): \stdClass {
        $gateway = self::get();
        if (!$gateway) {
            throw new \moodle_exception('gateway_notconfigured', 'local_jedomi');
        }
        return $gateway;
    }
}
