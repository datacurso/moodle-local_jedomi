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

/**
 * Factory and configuration accessors for the AI backends.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Single entry point used by every call site to obtain a backend.
 */
final class client {
    /** @var string Self-hosted OpenAI-compatible gateway (default). */
    public const BACKEND_GATEWAY = 'gateway';

    /** @var string Datacurso AI services through aiprovider_datacurso. */
    public const BACKEND_DATACURSO = 'datacurso';

    /** @var string Model sent to the gateway when none is configured. */
    public const DEFAULT_MODEL = 'jedomi-e4b';

    /** @var int max_tokens used when the setting is missing or invalid. */
    public const DEFAULT_MAX_TOKENS = 16384;

    /** @var int Lower bound for a configured max_tokens. */
    public const MIN_MAX_TOKENS = 256;

    /**
     * Pure resolution of the configured backend key.
     *
     * Unknown or empty values and an unavailable Datacurso backend resolve to the gateway.
     *
     * @param string $configured Raw ai_backend setting.
     * @param bool $available Whether the Datacurso backend can be used right now.
     * @return string One of the BACKEND_* constants.
     */
    public static function resolve_backend_key(string $configured, bool $available): string {
        $configured = strtolower(trim($configured));
        if ($configured === self::BACKEND_DATACURSO && $available) {
            return self::BACKEND_DATACURSO;
        }
        return self::BACKEND_GATEWAY;
    }

    /**
     * Effective backend key for this request, logging a fallback once.
     *
     * @return string
     */
    public static function backend_key(): string {
        $configured = (string) get_config('local_jedomi', 'ai_backend');
        $key = self::resolve_backend_key($configured, datacurso_backend::is_available());

        $normalised = strtolower(trim($configured));
        if ($normalised !== '' && $normalised !== $key) {
            self::log_fallback_once($normalised, $key);
        }
        return $key;
    }

    /**
     * Instantiate the effective backend.
     *
     * @param transport|null $transport Optional transport override (tests, CLI).
     * @return backend
     */
    public static function create(?transport $transport = null): backend {
        if (self::backend_key() === self::BACKEND_DATACURSO) {
            return new datacurso_backend(null, null, $transport);
        }
        return new gateway_backend(null, null, $transport);
    }

    /**
     * Configured model name for the gateway.
     *
     * @return string
     */
    public static function get_model(): string {
        $model = trim((string) get_config('local_jedomi', 'model'));
        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Configured max_tokens, defaulted and clamped to MIN_MAX_TOKENS.
     *
     * @return int
     */
    public static function get_max_tokens(): int {
        $value = (int) get_config('local_jedomi', 'max_tokens');
        if ($value <= 0) {
            return self::DEFAULT_MAX_TOKENS;
        }
        return max(self::MIN_MAX_TOKENS, $value);
    }

    /**
     * Options for a standard completion round.
     *
     * @return array{max_tokens:int,timeout:int}
     */
    public static function default_options(): array {
        return [
            'max_tokens' => self::get_max_tokens(),
            'timeout' => openai_compatible_backend::DEFAULT_TIMEOUT,
        ];
    }

    /**
     * Log AI_BACKEND_FALLBACK at most once per request.
     *
     * @param string $configured Normalised configured key.
     * @param string $resolved Effective key.
     */
    private static function log_fallback_once(string $configured, string $resolved): void {
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'local_jedomi', 'ai_backend_fallback');
        if ($cache->get('logged')) {
            return;
        }
        $cache->set('logged', true);
        \local_jedomi_log('AI_BACKEND_FALLBACK', [
            'configured' => $configured,
            'resolved' => $resolved,
            'provider_installed' => class_exists('\aiprovider_datacurso\httpclient\ai_services_api'),
        ]);
    }
}
