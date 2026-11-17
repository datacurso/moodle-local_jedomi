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

namespace local_jedomi\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\ai\ai_service;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\custom_endpoint;
use local_jedomi\aiprovider\settings_extension;

/**
 * Verifies a custom OpenAI-compatible AI service (local_jedomi_verify_custom_service).
 *
 * Called by the "Verify connection" button of the Jedomi page in the Datacurso AI provider. It calls
 * GET {root}/models, where {root} is the root of the API ({@see custom_endpoint::normalise()}), with the key as a
 * Bearer token and accepts an OpenAI model list ({"object": "list", "data": [{"id": ...}]}). On success the address
 * and key are remembered as verified (a keyed fingerprint, never the values), which saving the custom service
 * requires. The ids of the listed models come back as suggestions for the model fields: only ids the form accepts
 * (settings_extension::CUSTOM_ID_PATTERN), without repetitions, sorted and at most MAX_MODELS, with their total.
 *
 * Only administrators who can change the site configuration may call it, with the session key (AJAX). The address
 * follows {@see custom_endpoint}; the request has a short timeout and never follows redirects, and each
 * administrator has a few verifications per minute, so it cannot be used to scan the internal network. The key is
 * never returned, and an empty key uses the saved key only for the saved address.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_custom_service extends external_api {
    /** @var int Verifications an administrator can make in one minute. */
    public const RATE_LIMIT_PER_MINUTE = 10;

    /** @var int Most model ids returned as suggestions. */
    public const MAX_MODELS = 100;

    /** @var int Total request timeout, in seconds. */
    private const TIMEOUT = 10;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'url' => new external_value(PARAM_RAW_TRIMMED, 'Address of the custom AI service'),
            'apikey' => new external_value(PARAM_RAW_TRIMMED, 'API key; empty to use the saved key for the saved address'),
            'allowhttp' => new external_value(PARAM_BOOL, 'Whether HTTP is allowed for a local model'),
        ]);
    }

    /**
     * Verify the service and return the result.
     *
     * @param string $url Address.
     * @param string $apikey API key; empty for the saved key.
     * @param bool $allowhttp Whether HTTP is allowed for a local model.
     * @return array
     */
    public static function execute(string $url, string $apikey, bool $allowhttp): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'url' => $url,
            'apikey' => $apikey,
            'allowhttp' => $allowhttp,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        if (!self::consume_rate_limit((int) $USER->id)) {
            return self::result('rate_limited');
        }

        $url = custom_endpoint::normalise($params['url']);
        $refused = custom_endpoint::check($url, $params['allowhttp']);
        if ($refused !== null) {
            return self::result($refused);
        }
        $key = ai_service::effective_key($url, $params['apikey']);
        if ($key === null) {
            return self::result('key_required');
        }

        try {
            $models = (new custom_api($url, $key, $params['allowhttp']))->list_models(self::TIMEOUT);
        } catch (\moodle_exception $e) {
            return self::failure($e);
        }
        ai_service::remember_verified($url, $key);
        $ids = array_values(array_unique(array_filter(
            $models,
            static fn(string $id): bool => (bool) preg_match(settings_extension::CUSTOM_ID_PATTERN, $id)
        )));
        sort($ids, SORT_STRING);
        return ['models' => array_slice($ids, 0, self::MAX_MODELS), 'modeltotal' => count($ids)]
            + self::result('ok', count($models));
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid' => new external_value(PARAM_BOOL, 'Whether the service answered as an OpenAI-compatible API'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'ok, or why the service was not accepted'),
            'message' => new external_value(PARAM_TEXT, 'Message for the administrator'),
            'modelcount' => new external_value(PARAM_INT, 'Number of models the service lists'),
            'models' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Id of a listed model that the model fields accept'),
                'Ids of the listed models the model fields accept, sorted, at most ' . self::MAX_MODELS
            ),
            'modeltotal' => new external_value(PARAM_INT, 'Number of listed models the model fields accept'),
        ]);
    }

    /**
     * Result for a failed request.
     *
     * @param \moodle_exception $e Failure of the client.
     * @return array
     */
    private static function failure(\moodle_exception $e): array {
        if ($e->module === 'local_jedomi' && $e->errorcode === 'custom_error_url') {
            return self::result((string) $e->a);
        }
        if ($e->module === 'local_jedomi' && $e->errorcode === 'custom_error_http') {
            $status = (int) $e->a;
            return in_array($status, [401, 403], true) ? self::result('unauthorized') : self::result('http_error', $status);
        }
        if ($e->module === 'local_jedomi' && $e->errorcode === 'ai_error_invalidanswer') {
            return self::result('not_openai');
        }
        return self::result('no_response');
    }

    /**
     * Result of a verification.
     *
     * @param string $reason ok, or why the service was not accepted; its message is the string custom_verify_<reason>.
     * @param int $number Number of models when valid, HTTP status for http_error.
     * @return array
     */
    private static function result(string $reason, int $number = 0): array {
        return [
            'valid' => $reason === 'ok',
            'reason' => $reason,
            'message' => get_string('custom_verify_' . $reason, 'local_jedomi', $number),
            'modelcount' => $reason === 'ok' ? $number : 0,
            'models' => [],
            'modeltotal' => 0,
        ];
    }

    /**
     * Count a verification of an administrator in the current minute.
     *
     * @param int $userid Administrator.
     * @return bool False when the limit of the minute is reached; the verification is then not counted.
     */
    private static function consume_rate_limit(int $userid): bool {
        $cache = \cache::make('local_jedomi', 'verify_rate');
        $key = $userid . '_' . intdiv(time(), 60);
        $count = (int) $cache->get($key);
        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        $cache->set($key, $count + 1);
        return true;
    }
}
