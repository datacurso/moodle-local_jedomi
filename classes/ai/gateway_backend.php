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
 * Backend for the Jedomi gateway (self-hosted OpenAI-compatible server).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * Talks to {gateway_url}/v1/chat/completions with a bearer API key.
 */
final class gateway_backend extends openai_compatible_backend {
    /** @var string Gateway URL used when none is configured. */
    public const DEFAULT_URL = 'http://localhost:8765';

    /** @var string API key used when none is configured. */
    public const DEFAULT_API_KEY = 'jedomi-local-key';

    /** @var string Base URL without trailing slash. */
    private string $url;

    /** @var string Bearer token. */
    private string $apikey;

    /**
     * Constructor.
     *
     * @param string|null $url Gateway base URL; defaults to the gateway_url setting, then DEFAULT_URL.
     * @param string|null $apikey Bearer token; defaults to the api_key setting, then DEFAULT_API_KEY.
     * @param transport|null $transport HTTP transport.
     */
    public function __construct(?string $url = null, ?string $apikey = null, ?transport $transport = null) {
        parent::__construct($transport);
        $this->url = rtrim($url ?? ((string) get_config('local_jedomi', 'gateway_url') ?: self::DEFAULT_URL), '/');
        $this->apikey = $apikey ?? ((string) get_config('local_jedomi', 'api_key') ?: self::DEFAULT_API_KEY);
    }

    /**
     * Backend key.
     *
     * @return string
     */
    public function get_key(): string {
        return client::BACKEND_GATEWAY;
    }

    /**
     * Chat completions endpoint.
     *
     * @return string
     */
    protected function get_endpoint_url(): string {
        return $this->url . '/v1/chat/completions';
    }

    /**
     * JSON content type plus bearer authentication.
     *
     * @return string[]
     */
    protected function get_headers(): array {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ];
    }

    /**
     * Merge the caller's extra fields (e.g. the Telegram "spt" block) into the payload.
     *
     * @param array $payload
     * @param array $options
     * @return array
     */
    protected function extend_payload(array $payload, array $options): array {
        if (!empty($options['extra']) && is_array($options['extra'])) {
            $payload = array_merge($payload, $options['extra']);
        }
        return $payload;
    }

    /**
     * The gateway has no user-facing error vocabulary.
     *
     * @param int $code
     * @param string $body
     * @return array|null Always null.
     */
    protected function map_http_error(int $code, string $body): ?array {
        return null;
    }
}
