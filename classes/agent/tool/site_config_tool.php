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
 * get_site_config tool — reads allowed Moodle site configuration values.
 *
 * Only pre-defined non-sensitive keys can be read. This matches the
 * same allowlist used by the Telegram handler and the webservice
 * external function to prevent leaking sensitive data.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that reads allowlisted, non-sensitive Moodle site
 * configuration values for site administrators.
 */
class site_config_tool implements agent_tool {
    /** @var int Agent creator userid (for admin check). */
    private int $userid;

    /** @var string[] Allowlisted config keys that can be read. */
    private const ALLOWLIST = [
        'sitename', 'siteurl', 'wwwroot', 'lang', 'country', 'timezone',
        'maxbytes', 'passwordpolicy', 'minpasswordlength',
        'passwordminlength', 'passwordmaxlength',
        'enrol_plugins_enabled', 'auth_plugins_enabled', 'smtphosts',
    ];

    /**
     * Constructor.
     *
     * @param int $userid Agent creator's user ID, used for the site admin check (0 denies access).
     */
    public function __construct(int $userid = 0) {
        $this->userid = $userid;
    }

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'get_site_config'.
     */
    public function get_name(): string {
        return 'get_site_config';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with a required array of allowlisted config keys.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_site_config',
                'description' => 'Get Moodle site configuration values. Only allowed keys can be read.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keys' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of config keys. Allowed: sitename, siteurl,'
                                . ' wwwroot, lang, country, timezone, maxbytes, passwordpolicy,'
                                . ' minpasswordlength, passwordminlength, passwordmaxlength,'
                                . ' enrol_plugins_enabled, auth_plugins_enabled, smtphosts.',
                        ],
                    ],
                    'required' => ['keys'],
                ],
            ],
        ];
    }

    /**
     * Read the requested config keys, silently skipping any not in the allowlist.
     *
     * Only site admins may call this; a userid of 0 fails closed. 'siteurl' falls back
     * to the 'wwwroot' setting when unset.
     *
     * @param \stdClass $params Decoded tool arguments; expects a 'keys' array of config key names.
     * @return string JSON-encoded key/value map of readable settings, or a permission_denied error.
     */
    public function execute(\stdClass $params): string {
        // ... ── Admin-only gate (matches Telegram webhook_handler behaviour) ──
        // Fail-closed: userid=0 also denies (no user context).
        if (!is_siteadmin($this->userid)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $keys = $params->keys ?? [];
        if (!is_array($keys) || empty($keys)) {
            return json_encode(['error' => get_string('agent_tool_key_list_required', 'local_jedomi')]);
        }

        $allowset = array_flip(self::ALLOWLIST);
        $output = [];

        foreach ($keys as $k) {
            $k = trim((string) $k);
            // Silently skip non-allowed keys.
            if (!isset($allowset[$k])) {
                continue;
            }

            $v = get_config('core', $k);
            // Fallback: model often asks for "siteurl" but Moodle stores it as "wwwroot".
            if ($v === false && $k === 'siteurl') {
                $v = get_config('core', 'wwwroot');
            }

            $output[$k] = ($v === false) ? null : $v;
        }

        if (empty($output)) {
            return json_encode([
                'success' => true,
                'config' => [],
                'message' => get_string('agent_tool_no_config_keys', 'local_jedomi'),
            ]);
        }

        return json_encode([
            'success' => true,
            'config' => $output,
        ], JSON_UNESCAPED_UNICODE);
    }
}
