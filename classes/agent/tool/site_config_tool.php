<?php
/**
 * get_site_config tool — reads allowed Moodle site configuration values.
 *
 * Only pre-defined non-sensitive keys can be read. This matches the
 * same allowlist used by the Telegram handler and the webservice
 * external function to prevent leaking sensitive data.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

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

    public function __construct(int $userid = 0) {
        $this->userid = $userid;
    }

    public function get_name(): string {
        return 'get_site_config';
    }

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
                            'description' => 'List of config keys. Allowed: sitename, siteurl, wwwroot, lang, country, timezone, maxbytes, passwordpolicy, minpasswordlength, passwordminlength, passwordmaxlength, enrol_plugins_enabled, auth_plugins_enabled, smtphosts.',
                        ],
                    ],
                    'required' => ['keys'],
                ],
            ],
        ];
    }

    public function execute(\stdClass $params): string {
        // ── Admin-only gate (matches Telegram webhook_handler behaviour) ──
        // Fail-closed: userid=0 also denies (no user context).
        if (!is_siteadmin($this->userid)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $keys = $params->keys ?? [];
        if (!is_array($keys) || empty($keys)) {
            return json_encode(['error' => 'Key list required.']);
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
                'message' => 'No matching keys found (only allowlisted keys are readable).',
            ]);
        }

        return json_encode([
            'success' => true,
            'config' => $output,
        ], JSON_UNESCAPED_UNICODE);
    }
}
