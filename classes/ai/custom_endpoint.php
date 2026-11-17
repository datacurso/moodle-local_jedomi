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
 * Address policy of the custom (OpenAI-compatible) AI service.
 *
 * The address is an http or https URL without credentials, query or fragment. HTTPS is required; plain HTTP only
 * for a local host (loopback or private network) and only with "Allow HTTP for local models" on. The blocked hosts
 * and allowed ports of the site (curlsecurityblockedhosts, curlsecurityallowedport) apply to every other address;
 * only the local host allowed by that checkbox skips them, and its requests are then pinned to the address checked
 * here, so a DNS change between the check and the request cannot send them elsewhere. Redirects are never followed.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_endpoint {
    /** @var string Error: not an http or https URL, or it has credentials, a query or a fragment. */
    public const ERROR_INVALID_URL = 'invalid_url';

    /** @var string Error: plain HTTP to a host that is not an allowed local host. */
    public const ERROR_HTTP_NOT_ALLOWED = 'http_not_allowed';

    /** @var string Error: the host or port is blocked by the cURL security settings of the site. */
    public const ERROR_BLOCKED_HOST = 'blocked_host';

    /** @var int Longest accepted address, in characters. */
    private const MAX_URL_LENGTH = 1024;

    /** @var int Longest wait to open a connection, in seconds. */
    private const CONNECT_TIMEOUT = 5;

    /** @var string[] Networks of a local model: loopback and private networks (never link-local or 0.0.0.0). */
    private const LOCAL_NETWORKS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1/128',
        'fc00::/7',
    ];

    /**
     * Root of the API that every route is added to, with the convention of the base_url of the OpenAI SDK.
     *
     * Surrounding spaces and trailing slashes are removed. An address without a path (such as http://localhost:11434
     * or https://api.openai.com) gets /v1; an address with a path (such as https://api.openai.com/v1 or
     * https://api.deepinfra.com/v1/openai) is kept as the provider documents it. An address with a query, a fragment
     * or no host is left unchanged, so {@see check()} still refuses it. Normalising twice changes nothing, and two
     * addresses of the same API root normalise to the same text.
     *
     * @param string $url Address typed by the administrator.
     * @return string
     */
    public static function normalise(string $url): string {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (
            $url === '' || !is_array($parts) || !isset($parts['host'])
                || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])
        ) {
            return $url;
        }
        return $url . '/v1';
    }

    /**
     * Full address of a route of the OpenAI API, such as /chat/completions.
     *
     * @param string $base Base address, normalised first.
     * @param string $route Route under the root of the API, starting with a slash.
     * @return string
     */
    public static function url(string $base, string $route): string {
        return self::normalise($base) . $route;
    }

    /**
     * Why an address cannot be used, or null when it can.
     *
     * @param string $url Address (base or full).
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     * @return string|null One of the ERROR_* constants, or null.
     */
    public static function check(string $url, bool $allowhttp): ?string {
        $parts = self::parse($url);
        if ($parts === null) {
            return self::ERROR_INVALID_URL;
        }
        $local = $allowhttp && self::is_local_host($parts['host']);
        if ($parts['scheme'] === 'http' && !$local) {
            return self::ERROR_HTTP_NOT_ALLOWED;
        }
        if (!$local && (new \core\files\curl_security_helper())->url_is_blocked($url)) {
            return self::ERROR_BLOCKED_HOST;
        }
        return null;
    }

    /**
     * Whether a host is a local model host: localhost, or an address (or a name whose addresses all are) of the
     * loopback or a private network.
     *
     * @param string $host Host, with or without the brackets of an IPv6 address.
     * @return bool
     */
    public static function is_local_host(string $host): bool {
        $addresses = self::resolve(trim($host, '[]'));
        return !empty($addresses) && self::all_local($addresses);
    }

    /**
     * Whether every address is on the loopback or a private network.
     *
     * @param string[] $addresses IP addresses.
     * @return bool
     */
    private static function all_local(array $addresses): bool {
        foreach ($addresses as $address) {
            if (!\core\ip_utils::is_ip_in_subnet_list($address, implode("\n", self::LOCAL_NETWORKS))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether requests to an address skip the cURL security of the site: only a local host allowed by the checkbox.
     *
     * @param string $url Address.
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     * @return bool
     */
    public static function bypasses_curl_security(string $url, bool $allowhttp): bool {
        return self::local_pin($url, $allowhttp) !== null;
    }

    /**
     * Options of Moodle's \curl wrapper for a request: TLS checks, no redirects, timeouts, and the address of a
     * local host pinned to the one checked.
     *
     * A request skips the cURL security of the site only when these options pin it (CURLOPT_RESOLVE): the host is
     * resolved once, every address must be local, and the request goes to that address.
     *
     * @param string $url Full address of the request.
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     * @param int $timeout Seconds allowed for the whole request.
     * @return array
     */
    public static function curl_options(string $url, bool $allowhttp, int $timeout): array {
        $timeout = max(1, $timeout);
        $options = [
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_CONNECTTIMEOUT' => min(self::CONNECT_TIMEOUT, $timeout),
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_RETURNTRANSFER' => true,
        ];
        $pin = self::local_pin($url, $allowhttp);
        if ($pin !== null) {
            $options['CURLOPT_RESOLVE'] = [$pin];
        }
        return $options;
    }

    /**
     * Pinned address (host:port:address) of a local host allowed by the checkbox, resolved once.
     *
     * @param string $url Address.
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     * @return string|null Null when the address is not an allowed local host.
     */
    private static function local_pin(string $url, bool $allowhttp): ?string {
        $parts = self::parse($url);
        if ($parts === null || !$allowhttp) {
            return null;
        }
        $host = trim($parts['host'], '[]');
        $addresses = self::resolve($host);
        if (empty($addresses) || !self::all_local($addresses)) {
            return null;
        }
        return $host . ':' . $parts['port'] . ':' . $addresses[0];
    }

    /**
     * Scheme, host and port of a valid address, or null.
     *
     * @param string $url Address.
     * @return array|null
     */
    private static function parse(string $url): ?array {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/\s/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            !in_array($scheme, ['http', 'https'], true) || $host === ''
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return null;
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    /**
     * Addresses of a host: itself when it is an IP address, otherwise its IPv4 addresses.
     *
     * @param string $host Host without brackets.
     * @return string[] Empty when it cannot be resolved.
     */
    private static function resolve(string $host): array {
        if ($host === '') {
            return [];
        }
        if (\core\ip_utils::is_ip_address($host)) {
            return [$host];
        }
        if (!\core\ip_utils::is_domain_name($host)) {
            return [];
        }
        $addresses = gethostbynamel($host);
        return is_array($addresses) ? array_values($addresses) : [];
    }
}
