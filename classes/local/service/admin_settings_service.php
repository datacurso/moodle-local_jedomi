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

namespace local_jedomi\local\service;

use local_jedomi\telegram\api_client;
use local_jedomi\telegram\manager;

/**
 * Reads and saves the settings shown in the tabs of the Jedomi admin page.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settings_service {
    /** @var string[] Course availability modes. */
    public const COURSE_MODES = ['all', 'exclude', 'include'];

    /** @var string[] Anonymization levels. */
    public const ANONYMIZATION_LEVELS = ['basic', 'strict', 'feedback'];

    /** @var string[] Role keys that have their own web service allowlist. */
    public const WS_ROLES = ['admin', 'manager', 'teacher', 'student'];

    /** @var string[] Web service type filters: every function, read functions and write functions. */
    public const WS_TYPES = ['all', 'Lectura', 'Escritura'];

    /** @var int[] Page sizes offered for the web service table. */
    public const WS_PERPAGE_OPTIONS = [25, 50, 100, 200];

    /**
     * Settings of the General tab.
     *
     * @return array{spt_secret: string, collect_training_data: int} Training data collection defaults to on.
     */
    public static function get_general_settings(): array {
        $collect = get_config('local_jedomi', 'collect_training_data');
        return [
            'spt_secret' => (string) (get_config('local_jedomi', 'spt_secret') ?: ''),
            'collect_training_data' => ($collect === false) ? 1 : (int) $collect,
        ];
    }

    /**
     * Save the settings of the General tab.
     *
     * @param string $sptsecret Shared secret used to sign session tokens.
     * @param bool $collecttrainingdata Whether anonymised conversations are kept to improve the service.
     * @return void
     */
    public static function save_general_settings(string $sptsecret, bool $collecttrainingdata): void {
        set_config('spt_secret', $sptsecret, 'local_jedomi');
        set_config('collect_training_data', (int) $collecttrainingdata, 'local_jedomi');
    }

    /**
     * Settings of the Course availability tab.
     *
     * @return array{course_mode: string, course_ids: int[]}
     */
    public static function get_course_settings(): array {
        return [
            'course_mode' => (string) (get_config('local_jedomi', 'course_mode') ?: 'all'),
            'course_ids' => self::parse_ids((string) (get_config('local_jedomi', 'course_list') ?: '')),
        ];
    }

    /**
     * Save the settings of the Course availability tab.
     *
     * @param string $mode One of {@see self::COURSE_MODES}; anything else is stored as "all".
     * @param array $courseids Course ids; values that are not positive integers are dropped.
     * @return void
     */
    public static function save_course_settings(string $mode, array $courseids): void {
        if (!in_array($mode, self::COURSE_MODES, true)) {
            $mode = 'all';
        }
        set_config('course_mode', $mode, 'local_jedomi');
        $ids = array_values(array_filter(array_map('intval', $courseids)));
        set_config('course_list', implode(',', $ids), 'local_jedomi');
    }

    /**
     * Settings of the Privacy tab.
     *
     * @return array{hide_from_guests: int, anonymization_level: string, hidden_role_ids: int[]}
     */
    public static function get_privacy_settings(): array {
        return [
            'hide_from_guests' => (int) get_config('local_jedomi', 'hide_from_guests'),
            'anonymization_level' => (string) (get_config('local_jedomi', 'anonymization_level') ?: 'basic'),
            'hidden_role_ids' => self::parse_ids((string) (get_config('local_jedomi', 'hidden_roles') ?: '')),
        ];
    }

    /**
     * Save the settings of the Privacy tab.
     *
     * @param bool $hidefromguests Whether the chat is hidden from guests.
     * @param string $level One of {@see self::ANONYMIZATION_LEVELS}; anything else is stored as "basic".
     * @param int[] $visibleroleids Roles that can use the chat; every other role is hidden.
     * @return void
     */
    public static function save_privacy_settings(bool $hidefromguests, string $level, array $visibleroleids): void {
        if (!in_array($level, self::ANONYMIZATION_LEVELS, true)) {
            $level = 'basic';
        }
        set_config('hide_from_guests', (int) $hidefromguests, 'local_jedomi');
        set_config('anonymization_level', $level, 'local_jedomi');
        set_config('hidden_roles', implode(',', self::hidden_role_ids($visibleroleids)), 'local_jedomi');
    }

    /**
     * Every role of the site, in sort order, with its display name.
     *
     * @return array<int, array{id: int, shortname: string, name: string}>
     */
    public static function get_roles(): array {
        global $DB;
        $context = \context_system::instance();
        $roles = [];
        foreach ($DB->get_records('role', null, 'sortorder ASC', 'id, shortname, name, description, archetype') as $role) {
            $name = role_get_name($role, $context);
            $roles[] = [
                'id' => (int) $role->id,
                'shortname' => (string) $role->shortname,
                'name' => $name !== '' ? $name : (string) $role->shortname,
            ];
        }
        return $roles;
    }

    /**
     * Settings and status of the Telegram integration.
     *
     * @return array{enabled: int, bot_token_set: bool, bot_username: string, bot_firstname: string,
     *     bot_verified: int, webhook_error: int, webhook_url: string, rate_limit: int, cooldown: int,
     *     max_turns: int, gateway_timeout: int, hidden_role_ids: int[], last_error: string,
     *     last_error_time: int, is_configured: bool}
     */
    public static function get_telegram_settings(): array {
        $maxturns = get_config('local_jedomi', 'telegram_max_turns');
        $settings = [
            'enabled' => (int) get_config('local_jedomi', 'telegram_enabled'),
            'bot_token_set' => !empty(get_config('local_jedomi', 'telegram_bot_token')),
            'bot_username' => (string) (get_config('local_jedomi', 'telegram_bot_username') ?: ''),
            'bot_firstname' => (string) (get_config('local_jedomi', 'telegram_bot_firstname') ?: ''),
            'bot_verified' => (int) get_config('local_jedomi', 'telegram_bot_verified'),
            'webhook_error' => (int) get_config('local_jedomi', 'telegram_webhook_error'),
            'webhook_url' => manager::get_webhook_url(),
            'rate_limit' => manager::get_rate_limit(),
            'cooldown' => manager::get_cooldown(),
            'max_turns' => ($maxturns === false) ? 10 : (int) $maxturns,
            'gateway_timeout' => (int) (get_config('local_jedomi', 'telegram_gateway_timeout') ?: 120),
            'hidden_role_ids' => self::parse_ids((string) (get_config('local_jedomi', 'telegram_hidden_roles') ?: '')),
            'last_error' => manager::get_last_webhook_error(),
            'last_error_time' => manager::get_last_webhook_error_time(),
        ];
        $settings['is_configured'] = $settings['enabled'] && $settings['bot_token_set'] && $settings['bot_verified'];
        return $settings;
    }

    /**
     * Check a bot token with getMe and, when it is valid, store it and register the webhook.
     *
     * An invalid token is not stored; the bot is marked as not verified.
     *
     * @param string $token Bot token from @BotFather.
     * @param api_client|null $client Client to use; a client for the given token when null.
     * @return bool Whether the token was valid and stored.
     */
    public static function verify_and_save_telegram_token(string $token, ?api_client $client = null): bool {
        $client = $client ?? new api_client($token);
        $botinfo = $client->get_me();
        if (!$botinfo) {
            set_config('telegram_bot_verified', 0, 'local_jedomi');
            return false;
        }

        manager::set_bot_token($token);
        set_config('telegram_bot_username', $botinfo['username'], 'local_jedomi');
        set_config('telegram_bot_firstname', $botinfo['first_name'], 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');

        // Register the webhook again: delete the old one first, then set the new URL.
        $client->delete_webhook();
        if ($client->set_webhook(manager::get_webhook_url())) {
            manager::clear_last_webhook_error();
        } else {
            manager::set_last_webhook_error('setWebhook failed after token save — check the URL is HTTPS and reachable');
        }
        return true;
    }

    /**
     * Save the Telegram limits and the roles that can use the bot.
     *
     * @param int $ratelimit Messages per minute allowed per user.
     * @param int $cooldown Minutes a user waits after reaching the limit.
     * @param int $maxturns Conversation turns kept as context.
     * @param int $gatewaytimeout Seconds to wait for the AI service.
     * @param int[] $visibleroleids Roles that can use the bot; every other role is hidden.
     * @return void
     */
    public static function save_telegram_settings(
        int $ratelimit,
        int $cooldown,
        int $maxturns,
        int $gatewaytimeout,
        array $visibleroleids
    ): void {
        set_config('telegram_rate_limit', $ratelimit, 'local_jedomi');
        set_config('telegram_cooldown', $cooldown, 'local_jedomi');
        set_config('telegram_max_turns', $maxturns, 'local_jedomi');
        set_config('telegram_gateway_timeout', $gatewaytimeout, 'local_jedomi');
        set_config('telegram_hidden_roles', implode(',', self::hidden_role_ids($visibleroleids)), 'local_jedomi');
    }

    /**
     * Role key for the web service allowlist, falling back to "admin" for an unknown key.
     *
     * @param string $role Requested role key.
     * @return string One of {@see self::WS_ROLES}.
     */
    public static function normalise_ws_role(string $role): string {
        return in_array($role, self::WS_ROLES, true) ? $role : 'admin';
    }

    /**
     * Replace the web service allowlist of a role.
     *
     * @param string $role One of {@see self::WS_ROLES}.
     * @param string[] $wsnames Web service function names the role may call.
     * @return void
     */
    public static function save_ws_allowlist(string $role, array $wsnames): void {
        $role = self::normalise_ws_role($role);
        set_config('ws_allowlist_' . $role, json_encode(array_values($wsnames)), 'local_jedomi');
    }

    /**
     * Components that declare web service functions, for the component filter.
     *
     * @return string[]
     */
    public static function get_ws_components(): array {
        global $DB;
        return array_values($DB->get_fieldset_sql('SELECT DISTINCT component FROM {external_functions} ORDER BY component'));
    }

    /**
     * One page of the web service table of a role, with the type counts of the filtered functions.
     *
     * @param string $role Role key; an unknown key is treated as "admin".
     * @param string $search Text the function name must contain; empty for any.
     * @param string $component Component the function must belong to; empty for any.
     * @param string $type One of {@see self::WS_TYPES}; anything else is treated as "all".
     * @param int $page Page number, starting at 1; capped to the last page.
     * @param int $perpage Rows per page, between 10 and 200.
     * @return array{rows: array<int, array{name: string, description: string, component: string, iswrite: bool,
     *     checked: bool}>, total: int, pages: int, page: int, count_all: int, count_read: int, count_write: int}
     */
    public static function get_ws_table(
        string $role,
        string $search,
        string $component,
        string $type,
        int $page,
        int $perpage
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        $role = self::normalise_ws_role($role);
        $type = in_array($type, self::WS_TYPES, true) ? $type : 'all';
        $perpage = max(10, min(200, $perpage));

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = $DB->sql_like('ef.name', ':search', false, false);
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        if ($component !== '') {
            $where[] = 'ef.component = :component';
            $params['component'] = $component;
        }
        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $functions = $DB->get_records_sql(
            "SELECT ef.name, ef.component
               FROM {external_functions} ef
               $wheresql
           ORDER BY ef.component, ef.name",
            $params
        );

        // The access type comes from the function name, so it is filtered here and not in SQL.
        $filtered = [];
        $countread = 0;
        $countwrite = 0;
        foreach ($functions as $function) {
            $access = \local_jedomi_classify_ws_access($function->name);
            if ($access === 'Lectura') {
                $countread++;
            } else if ($access === 'Escritura') {
                $countwrite++;
            }
            if ($type === 'all' || $access === $type) {
                $filtered[] = ['name' => (string) $function->name, 'component' => (string) $function->component,
                    'iswrite' => $access === 'Escritura'];
            }
        }

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perpage));
        $page = min(max(1, $page), $pages);

        $allowlist = \local_jedomi_get_role_allowlist($role);
        $firstvisit = ($role === 'admin' && get_config('local_jedomi', 'ws_allowlist_' . $role) === false);
        $rows = [];
        foreach (array_slice($filtered, ($page - 1) * $perpage, $perpage) as $function) {
            $rows[] = [
                'name' => $function['name'],
                'description' => '',
                'component' => $function['component'],
                'iswrite' => $function['iswrite'],
                'checked' => in_array($function['name'], $allowlist, true) || ($firstvisit && !$function['iswrite']),
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'count_all' => count($functions),
            'count_read' => $countread,
            'count_write' => $countwrite,
        ];
    }

    /**
     * Recent queries run by agents, with the full name of the user who owns each agent.
     *
     * @param int $mindurationms Only queries that took at least this many milliseconds; 0 for all.
     * @param string $sort "duration_ms" or "timecreated", always newest or slowest first.
     * @param int $limit Maximum number of entries.
     * @return array<int, array{agentid: int, userfullname: string, query: string, duration_ms: float,
     *     rowcount: int, timecreated: int}> The user name is empty when the agent no longer exists.
     */
    public static function get_recent_profiler_entries(int $mindurationms, string $sort, int $limit = 20): array {
        global $DB;
        $log = \local_jedomi\agent\query_profiler::get_log([
            'min_duration_ms' => $mindurationms,
            'sort' => $sort === 'duration_ms' ? 'duration_ms' : 'timecreated',
            'dir' => 'DESC',
            'perpage' => $limit,
        ]);
        $names = [];
        $entries = [];
        foreach ($log['entries'] as $entry) {
            $agentid = (int) $entry->agentid;
            if (!array_key_exists($agentid, $names)) {
                $userid = $DB->get_field('local_jedomi_agents', 'userid', ['id' => $agentid]);
                $user = $userid ? \core_user::get_user((int) $userid) : false;
                $names[$agentid] = $user ? fullname($user) : '';
            }
            $entries[] = [
                'agentid' => $agentid,
                'userfullname' => $names[$agentid],
                'query' => (string) $entry->query,
                'duration_ms' => (float) $entry->duration_ms,
                'rowcount' => (int) $entry->rowcount,
                'timecreated' => (int) $entry->timecreated,
            ];
        }
        return $entries;
    }

    /**
     * Parse a comma separated list of ids, dropping empty and non numeric values.
     *
     * @param string $list Comma separated ids.
     * @return int[]
     */
    private static function parse_ids(string $list): array {
        return array_values(array_filter(array_map('intval', explode(',', $list))));
    }

    /**
     * Ids of the roles that are not in the given visible list.
     *
     * @param int[] $visibleroleids Visible role ids.
     * @return int[]
     */
    private static function hidden_role_ids(array $visibleroleids): array {
        global $DB;
        $visible = array_map('intval', $visibleroleids);
        $hidden = [];
        foreach ($DB->get_records('role', null, 'sortorder ASC', 'id') as $role) {
            if (!in_array((int) $role->id, $visible, true)) {
                $hidden[] = (int) $role->id;
            }
        }
        return $hidden;
    }
}
