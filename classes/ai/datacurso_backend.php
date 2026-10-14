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
 * Backend for the Datacurso AI services (via aiprovider_datacurso).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * Talks to the dedicated /jedomi/chat/completions route authenticated with the license key.
 *
 * aiprovider_datacurso is an optional dependency: every reference to its
 * classes is guarded so this file can be autoloaded when the provider is
 * absent, and client only instantiates it when is_available() is true.
 *
 * Not final so tests can override create_services_api() without touching the network.
 */
class datacurso_backend extends openai_compatible_backend {
    /** @var string Service id registered in the provider's catalog and rate limiter. */
    public const SERVICE_ID = 'local_jedomi';

    /** @var string Endpoint path relative to the provider base URL. */
    public const ENDPOINT_PATH = '/jedomi/chat/completions';

    /** @var int Minimum aiprovider_datacurso version that knows about local_jedomi. */
    public const MIN_PROVIDER_VERSION = 2026101400;

    /** @var string Provider HTTP client class (its constructor performs a network call). */
    private const API_CLASS = '\aiprovider_datacurso\httpclient\ai_services_api';

    /** @var string Provider base class exposing the site UUID. */
    private const API_BASE_CLASS = '\aiprovider_datacurso\httpclient\datacurso_api_base';

    /** @var string Provider rate limiter class. */
    private const RATELIMITER_CLASS = '\aiprovider_datacurso\local\ratelimiter';

    /** @var string|null Base URL; resolved lazily when null. */
    private ?string $baseurl;

    /** @var string|null License key; read from the provider config when null. */
    private ?string $licensekey;

    /** @var int|null User id sent as "userid"; current user when null. */
    private ?int $userid;

    /**
     * Constructor.
     *
     * @param string|null $baseurl Provider base URL; null resolves it lazily through ai_services_api.
     * @param string|null $licensekey License key; null reads aiprovider_datacurso/licensekey.
     * @param transport|null $transport HTTP transport.
     * @param int|null $userid User id for budget/rate-limit accounting; null uses $USER.
     */
    public function __construct(
        ?string $baseurl = null,
        ?string $licensekey = null,
        ?transport $transport = null,
        ?int $userid = null
    ) {
        parent::__construct($transport);
        $this->baseurl = $baseurl;
        $this->licensekey = $licensekey;
        $this->userid = $userid;
    }

    /**
     * Backend key.
     *
     * @return string
     */
    public function get_key(): string {
        return client::BACKEND_DATACURSO;
    }

    /**
     * Cheap availability check: provider installed and a license key configured.
     *
     * Never instantiates provider classes, so it is safe to call from lib.php hooks.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (!class_exists(self::API_CLASS)) {
            return false;
        }
        return trim((string) get_config('aiprovider_datacurso', 'licensekey')) !== '';
    }

    /**
     * Installed provider version (from the plugin manager), or null when not installed.
     *
     * @return int|null
     */
    public static function get_provider_version(): ?int {
        $info = \core_plugin_manager::instance()->get_plugin_info('aiprovider_datacurso');
        if ($info === null || empty($info->versiondb)) {
            return null;
        }
        return (int) $info->versiondb;
    }

    /**
     * Whether the installed provider knows about the local_jedomi service.
     *
     * @return bool
     */
    public static function provider_version_ok(): bool {
        $version = self::get_provider_version();
        return $version !== null && $version >= self::MIN_PROVIDER_VERSION;
    }

    /**
     * Instantiate the provider HTTP client (its constructor performs a network call).
     *
     * @return object An ai_services_api instance.
     */
    protected function create_services_api(): object {
        $apiclass = self::API_CLASS;
        return new $apiclass();
    }

    /**
     * Resolve (once) and return the provider base URL without trailing slash.
     *
     * The result is only cached on success, so a transient provider failure is retried next time.
     *
     * @return string
     * @throws \RuntimeException When the provider cannot resolve the base URL.
     */
    private function get_base_url(): string {
        if ($this->baseurl === null) {
            try {
                $this->baseurl = (string) $this->create_services_api()->get_base_url();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Datacurso base URL resolution failed: ' . $e->getMessage(), 0, $e);
            }
        }
        return rtrim($this->baseurl, '/');
    }

    /**
     * Chat completions endpoint.
     *
     * @return string
     */
    protected function get_endpoint_url(): string {
        return $this->get_base_url() . self::ENDPOINT_PATH;
    }

    /**
     * JSON content type, license key and the provider's rate-limit headers.
     *
     * @return string[]
     */
    protected function get_headers(): array {
        $licensekey = $this->licensekey ?? (string) get_config('aiprovider_datacurso', 'licensekey');
        $headers = [
            'Content-Type: application/json',
            'License-Key: ' . $licensekey,
        ];
        if (class_exists(self::RATELIMITER_CLASS)) {
            $ratelimiterclass = self::RATELIMITER_CLASS;
            foreach ((new $ratelimiterclass())->get_rate_limit_headers(self::SERVICE_ID) as $header) {
                $headers[] = $header;
            }
        }
        return $headers;
    }

    /**
     * Add the Datacurso accounting fields, force usage reporting and pick the model.
     *
     * The gateway model name is never forwarded: the Python service applies its
     * own default unless datacurso_model is configured. Caller extras are ignored.
     *
     * @param array $payload
     * @param array $options
     * @return array
     */
    protected function extend_payload(array $payload, array $options): array {
        global $USER;

        unset($payload['model']);
        $model = trim((string) get_config('local_jedomi', 'datacurso_model'));
        if ($model !== '') {
            $payload = ['model' => $model] + $payload;
        }

        // The token-manager types userid/site_id as strings and answers 422 to integers,
        // which the Python middleware surfaces as 403 license_not_allowed.
        $payload['userid'] = (string) ($this->userid ?? (int) ($USER->id ?? 0));
        $payload['site_id'] = self::get_site_uuid();
        $payload['stream_options'] = ['include_usage' => true];

        return $payload;
    }

    /**
     * Persistent site UUID from the provider (empty when unavailable).
     *
     * @return string
     */
    private static function get_site_uuid(): string {
        if (!class_exists(self::API_BASE_CLASS)) {
            return '';
        }
        return (string) call_user_func([self::API_BASE_CLASS, 'get_site_uuid']);
    }

    /**
     * Translate budget, license and rate-limit rejections into localised notices.
     *
     * @param int $code HTTP status code.
     * @param string $body Response body.
     * @return array|null
     */
    protected function map_http_error(int $code, string $body): ?array {
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $detail = $decoded['detail'] ?? '';
        if (is_array($detail)) {
            $detail = (string) ($detail['detail'] ?? '');
        }
        $detail = (string) $detail;

        if ($code === 401) {
            return self::notice('invalidlicensekey');
        }
        if ($code === 402 || ($code === 403 && $detail === 'tokens_not_sufficient')) {
            return self::notice('notenoughtokens');
        }
        if ($code === 403 && $detail === 'rate_limit_exceeded') {
            $resetat = (int) ($decoded['reset_at'] ?? 0);
            $retryat = $resetat > 0 ? userdate($resetat, get_string('strftimedatetime', 'langconfig')) : '';
            \local_jedomi_log('AI_API_RATE_LIMITED', ['reset_at' => $resetat]);
            return self::notice('error_ratelimit_exceeded', $retryat);
        }
        if ($code === 403 && $detail === 'license_not_allowed') {
            return self::notice('license_not_allowed');
        }
        return null;
    }

    /**
     * Build a notice from an aiprovider_datacurso language string (links flattened to text).
     *
     * @param string $identifier String identifier.
     * @param mixed $a Optional placeholder value.
     * @return array
     */
    private static function notice(string $identifier, $a = null): array {
        $message = html_to_text(get_string($identifier, 'aiprovider_datacurso', $a), 0, false);
        return ['type' => self::TYPE_NOTICE, 'message' => $message];
    }
}
