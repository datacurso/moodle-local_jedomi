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

namespace local_jedomi\ai;

/**
 * Translates AI client failures into a stable code and a user message.
 *
 * The same code and message are shown in the web chat, agent preview, Telegram, agents and CLI.
 * Internal details (exception text, hosts, bodies) never reach the message.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class error_message {
    /** @var string Code: the organisation has no AI credit left. */
    public const CODE_NO_CREDIT = 'no_credit';

    /** @var string Code: the per-user rate limit was reached. */
    public const CODE_RATE_LIMITED = 'rate_limited';

    /** @var string Code: the license is missing, invalid or not allowed. */
    public const CODE_LICENSE = 'license';

    /** @var string Code: the AI service cannot be reached or failed. */
    public const CODE_UNAVAILABLE = 'unavailable';

    /** @var string Code: the AI gateway reached its usage budget (HTTP 402 from plugins-ai, jedomi_quota_exceeded). */
    public const CODE_QUOTA_EXCEEDED = 'quota_exceeded';

    /** @var string Code: the AI request took longer than the allowed time. */
    public const CODE_TIMEOUT = 'timeout';

    /** @var string Code: the Datacurso AI provider is disabled in Site administration > AI > AI providers. */
    public const CODE_PROVIDER_DISABLED = 'provider_disabled';

    /** @var string Code: the model of the custom AI service cannot call tools. */
    public const CODE_TOOLS_UNSUPPORTED = 'tools_unsupported';

    /** @var string Code: any other failure. */
    public const CODE_GENERIC = 'generic';

    /**
     * Code and message for a failure.
     *
     * @param \Throwable $e Failure raised by the AI client.
     * @param string|null $lang Language for the message; null for the current language.
     * @return array{code: string, message: string}
     */
    public static function from_exception(\Throwable $e, ?string $lang = null): array {
        [$code, $key, $a, $component] = self::classify($e);
        $message = get_string_manager()->get_string($key, $component, $a, $lang ?? current_language());
        return ['code' => $code, 'message' => $message];
    }

    /**
     * Map a failure to its code, language string key, string parameter and string component.
     *
     * @param \Throwable $e Failure raised by the AI client.
     * @return array{0: string, 1: string, 2: mixed, 3: string}
     */
    private static function classify(\Throwable $e): array {
        if ($e instanceof \moodle_exception && $e->module === 'aiprovider_datacurso' && $e->errorcode === 'providerdisabled') {
            // The provider's own message says where an administrator enables it.
            return [self::CODE_PROVIDER_DISABLED, 'providerdisabled', null, 'aiprovider_datacurso'];
        }
        return array_merge(self::classify_jedomi($e), ['local_jedomi']);
    }

    /**
     * Map a failure shown with a Jedomi string to its code, language string key and string parameter.
     *
     * @param \Throwable $e Failure raised by the AI client.
     * @return array{0: string, 1: string, 2: mixed}
     */
    private static function classify_jedomi(\Throwable $e): array {
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi') {
            return self::classify_custom_service($e);
        }
        if (!$e instanceof \moodle_exception || $e->module !== 'aiprovider_datacurso') {
            return [self::CODE_GENERIC, 'ai_error_generic', null];
        }

        switch ($e->errorcode) {
            case 'notenoughtokens':
                return [self::CODE_NO_CREDIT, 'ai_error_nocredit', null];
            case 'error_ratelimit_exceeded':
                $retryat = is_scalar($e->a) ? trim((string) $e->a) : '';
                return $retryat !== ''
                    ? [self::CODE_RATE_LIMITED, 'ai_error_ratelimit', $retryat]
                    : [self::CODE_RATE_LIMITED, 'ai_error_ratelimit_noretry', null];
            case 'license_not_allowed':
            case 'invalidlicensekey':
                return [self::CODE_LICENSE, 'ai_error_license', null];
            case 'curlerror':
                if (self::is_timeout($e)) {
                    return [self::CODE_TIMEOUT, 'ai_error_timeout', null];
                }
                return [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null];
            case 'emptyresponse':
                return [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null];
            case 'httperror':
                if ((int) $e->a === 402) {
                    return [self::CODE_QUOTA_EXCEEDED, 'ai_error_quota', null];
                }
                return (int) $e->a >= 500
                    ? [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null]
                    : [self::CODE_GENERIC, 'ai_error_generic', null];
            default:
                return [self::CODE_GENERIC, 'ai_error_generic', null];
        }
    }

    /**
     * Map a failure of the custom AI service to its code, string key and parameter.
     *
     * The users get the same messages as with the Datacurso service; the status or cURL error is never shown.
     *
     * @param \moodle_exception $e Failure with the local_jedomi component.
     * @return array
     */
    private static function classify_custom_service(\moodle_exception $e): array {
        switch ($e->errorcode) {
            case 'ai_error_tools_unsupported':
                return [self::CODE_TOOLS_UNSUPPORTED, 'ai_error_tools_unsupported', null];
            case 'custom_error_unreachable':
                return self::is_timeout($e)
                    ? [self::CODE_TIMEOUT, 'ai_error_timeout', null]
                    : [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null];
            case 'custom_error_url':
                return [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null];
            case 'custom_error_http':
                $status = (int) $e->a;
                if ($status === 429) {
                    return [self::CODE_RATE_LIMITED, 'ai_error_ratelimit_noretry', null];
                }
                return ($status >= 500 || in_array($status, [401, 403, 404], true) || ($status >= 300 && $status < 400))
                    ? [self::CODE_UNAVAILABLE, 'ai_error_unavailable', null]
                    : [self::CODE_GENERIC, 'ai_error_generic', null];
            default:
                return [self::CODE_GENERIC, 'ai_error_generic', null];
        }
    }

    /**
     * Detail of a failure of the custom AI service for the technical log of the administrator.
     *
     * The HTTP status (custom_error_http), the cURL error number (custom_error_unreachable) or the reason the address
     * was refused (custom_error_url) is kept, so the failures can be told apart in the log. These parameters never
     * contain the key, the answer, the address or the text of libcurl; the user message is unchanged
     * ({@see self::classify_custom_service()}). Any other failure, such as a Datacurso provider error whose parameter
     * may be a free text, adds nothing.
     *
     * @param \Throwable $e Failure raised by the AI client.
     * @return array ['detail' => string] for a custom service failure, else empty.
     */
    public static function log_detail(\Throwable $e): array {
        $codes = ['custom_error_http', 'custom_error_unreachable', 'custom_error_url'];
        if (
            !$e instanceof \moodle_exception || $e->module !== 'local_jedomi'
            || !in_array($e->errorcode, $codes, true) || !is_scalar($e->a)
        ) {
            return [];
        }
        return ['detail' => (string) $e->a];
    }

    /**
     * Whether a failure is a request timeout (cURL error 28).
     *
     * A timed-out request must not be resent: the AI service may have processed and billed it.
     *
     * @param \Throwable $e Failure raised by the AI client.
     * @return bool
     */
    public static function is_timeout(\Throwable $e): bool {
        if (!$e instanceof \moodle_exception || (int) $e->a !== CURLE_OPERATION_TIMEDOUT) {
            return false;
        }
        return ($e->module === 'aiprovider_datacurso' && $e->errorcode === 'curlerror')
            || ($e->module === 'local_jedomi' && $e->errorcode === 'custom_error_unreachable');
    }
}
