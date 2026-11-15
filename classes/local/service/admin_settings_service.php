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

use local_jedomi\local\personalization;
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
     * @return array{spt_secret: string}
     */
    public static function get_general_settings(): array {
        return [
            'spt_secret' => (string) (get_config('local_jedomi', 'spt_secret') ?: ''),
            'profiler_slow_ms' => \local_jedomi\agent\query_profiler::slow_threshold_ms(),
            'profiler_retention_days' => \local_jedomi\agent\query_profiler::retention_days(),
        ];
    }

    /**
     * Save the settings of the General tab.
     *
     * @param string $sptsecret Shared secret used to sign session tokens.
     * @param int|null $slowms Slow query threshold in milliseconds; null keeps the current value.
     * @param int|null $retentiondays Days the profiler records are kept; null keeps the current value.
     * @return void
     */
    public static function save_general_settings(string $sptsecret, ?int $slowms = null, ?int $retentiondays = null): void {
        set_config('spt_secret', $sptsecret, 'local_jedomi');
        if ($slowms !== null) {
            set_config('profiler_slow_ms', max(1, $slowms), 'local_jedomi');
        }
        if ($retentiondays !== null) {
            set_config('profiler_retention_days', max(1, $retentiondays), 'local_jedomi');
        }
        \local_jedomi\event\settings_updated::create_for_tab('general')->trigger();
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
        \local_jedomi\event\settings_updated::create_for_tab('course')->trigger();
    }

    /**
     * Settings of the Privacy tab.
     *
     * @return array{anonymization_level: string, hidden_role_ids: int[]}
     */
    public static function get_privacy_settings(): array {
        return [
            'anonymization_level' => (string) (get_config('local_jedomi', 'anonymization_level') ?: 'basic'),
            'hidden_role_ids' => self::parse_ids((string) (get_config('local_jedomi', 'hidden_roles') ?: '')),
        ];
    }

    /**
     * Save the settings of the Privacy tab.
     *
     * @param string $level One of {@see self::ANONYMIZATION_LEVELS}; anything else is stored as "basic".
     * @param int[] $visibleroleids Roles that can use the chat; every other role is hidden.
     * @return void
     */
    public static function save_privacy_settings(string $level, array $visibleroleids): void {
        if (!in_array($level, self::ANONYMIZATION_LEVELS, true)) {
            $level = 'basic';
        }
        set_config('anonymization_level', $level, 'local_jedomi');
        set_config('hidden_roles', implode(',', self::hidden_role_ids($visibleroleids)), 'local_jedomi');
        \local_jedomi\event\settings_updated::create_for_tab('privacy')->trigger();
    }

    /**
     * Roles of the site that can use Jedomi, in sort order, with their display name.
     *
     * The guest role and the role of authenticated users on the site home are left out: guests never use Jedomi and the
     * site home role is never assigned to a user, so a visibility switch for them would have no effect.
     *
     * @return array<int, array{id: int, shortname: string, name: string}>
     */
    public static function get_roles(): array {
        global $CFG, $DB;
        $context = \context_system::instance();
        $excluded = [(int) ($CFG->guestroleid ?? 0), (int) ($CFG->defaultfrontpageroleid ?? 0)];
        $roles = [];
        foreach ($DB->get_records('role', null, 'sortorder ASC', 'id, shortname, name, description, archetype') as $role) {
            if (in_array((int) $role->id, $excluded, true) || in_array($role->archetype, ['guest', 'frontpage'], true)) {
                continue;
            }
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
     *     last_error_time: int, webhook_ok_time: int, needs_registration: bool, is_configured: bool}
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
            'gateway_timeout' => \local_jedomi\telegram\webhook_handler::get_ai_timeout(),
            'hidden_role_ids' => self::parse_ids((string) (get_config('local_jedomi', 'telegram_hidden_roles') ?: '')),
            'last_error' => manager::get_last_webhook_error(),
            'last_error_time' => manager::get_last_webhook_error_time(),
            'webhook_ok_time' => manager::get_webhook_ok_time(),
            'needs_registration' => manager::needs_webhook_registration(),
        ];
        $settings['is_configured'] = $settings['enabled'] && $settings['bot_token_set'] && $settings['bot_verified'];
        return $settings;
    }

    /**
     * Check a bot token with getMe and, when it is valid, store it and register the webhook.
     *
     * An invalid token is not stored and the previous token is removed with its webhook, so no token stays in use
     * while the card shows the bot as disconnected.
     *
     * @param string $token Bot token from @BotFather.
     * @param api_client|null $client Client to use; a client for the given token when null.
     * @param api_client|null $previousclient Client of the stored token, used to remove its webhook; built when null.
     * @return bool Whether the token was valid and stored.
     */
    public static function verify_and_save_telegram_token(
        string $token,
        ?api_client $client = null,
        ?api_client $previousclient = null
    ): bool {
        $client = $client ?? new api_client($token);
        $botinfo = $client->get_me();
        if (!$botinfo) {
            self::remove_telegram_token($previousclient);
            return false;
        }

        manager::set_bot_token($token);
        set_config('telegram_bot_username', $botinfo['username'], 'local_jedomi');
        set_config('telegram_bot_firstname', $botinfo['first_name'], 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');

        // Register the webhook again: delete the old one first, then set the new URL with the secret. Saving the token
        // answers the warning of a registration the update could not make; a failure here shows its own error.
        if (manager::needs_webhook_registration()) {
            // The secret kept by a failed update was in the webhook address of earlier versions: use a new one.
            manager::replace_webhook_secret();
        }
        manager::clear_webhook_registration_need();
        $client->delete_webhook();
        if ($client->set_webhook(manager::get_webhook_url(), manager::get_webhook_secret())) {
            manager::clear_last_webhook_error();
        } else {
            manager::set_last_webhook_error('setWebhook failed after token save — check the URL is HTTPS and reachable');
        }
        return true;
    }

    /**
     * Remove the stored bot token: its webhook is deleted in Telegram, so the bot stops receiving messages, and the
     * bot details, the verified flag and the webhook state are forgotten.
     *
     * @param api_client|null $client Client of the stored token; built from the stored token when null.
     * @return void
     */
    public static function remove_telegram_token(?api_client $client = null): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $token = manager::get_bot_token();
        if ($token !== null) {
            $client = $client ?? new api_client($token);
            if (!$client->delete_webhook()) {
                \local_jedomi_log('[local_jedomi] Telegram webhook could not be deleted while removing the bot token');
            }
        }
        foreach (['telegram_bot_token', 'telegram_bot_username', 'telegram_bot_firstname'] as $name) {
            unset_config($name, 'local_jedomi');
        }
        set_config('telegram_bot_verified', 0, 'local_jedomi');
        manager::forget_webhook_state();
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
        \local_jedomi\event\settings_updated::create_for_tab('telegram')->trigger();
    }

    /**
     * Settings of the Personalization tab, normalised; the welcome message is the default one while none is set.
     *
     * @return array{avatar: string, position: array{corner: string, x: int, y: int, side: string},
     *     assistant_name: string, welcome_message: string, custom_prompt: string}
     */
    public static function get_personalization_settings(): array {
        return [
            'avatar' => personalization::avatar(),
            'position' => personalization::position(),
            'assistant_name' => personalization::assistant_name(),
            'welcome_message' => personalization::welcome_template(),
            'custom_prompt' => personalization::custom_prompt(),
        ];
    }

    /**
     * Save the settings of the Personalization tab, normalised, and log the change (without the values).
     *
     * A welcome message equal to the default one of the current language is stored empty, so every user keeps getting
     * the default message in their own language.
     *
     * @param string $avatar One of {@see personalization::AVATARS}; anything else is stored as the Jedomi icon.
     * @param array $position Corner, x, y and side of the button; see {@see personalization::normalise_position()}.
     * @param string $assistantname Name of the assistant; empty for Jedomi.
     * @param string $welcomemessage Welcome message with placeholders; empty for the default one.
     * @param string $customprompt Site instructions for the AI, capped at {@see personalization::CUSTOM_PROMPT_MAX}.
     * @param int|null $avatardraftid Draft area of the custom avatar field; null leaves the custom avatar unchanged.
     * @return void
     */
    public static function save_personalization_settings(
        string $avatar,
        array $position,
        string $assistantname,
        string $welcomemessage,
        string $customprompt,
        ?int $avatardraftid = null
    ): void {
        set_config('avatar', personalization::clean_avatar($avatar), 'local_jedomi');
        set_config('avatar_position', json_encode(personalization::normalise_position($position)), 'local_jedomi');
        set_config('assistant_name', personalization::clean_name($assistantname), 'local_jedomi');

        $welcome = self::normalise_newlines(trim(clean_param($welcomemessage, PARAM_NOTAGS)));
        $welcome = \core_text::substr($welcome, 0, personalization::WELCOME_MAX);
        if ($welcome === self::normalise_newlines(personalization::default_welcome())) {
            $welcome = '';
        }
        set_config('welcome_message', $welcome, 'local_jedomi');
        set_config('custom_prompt', personalization::clean_custom_prompt($customprompt), 'local_jedomi');

        if ($avatardraftid !== null) {
            file_save_draft_area_files(
                $avatardraftid,
                \context_system::instance()->id,
                'local_jedomi',
                personalization::FILEAREA,
                0,
                self::custom_avatar_options()
            );
        }
        \local_jedomi\event\settings_updated::create_for_tab('personalization')->trigger();
    }

    /**
     * Options of the custom avatar file area: one web image of up to 512 KB.
     *
     * @return array{subdirs: int, maxfiles: int, maxbytes: int, accepted_types: string[]}
     */
    public static function custom_avatar_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => personalization::AVATAR_MAXBYTES,
            'accepted_types' => ['web_image'],
        ];
    }

    /**
     * Text with Unix line ends.
     *
     * @param string $text Text with any line ends.
     * @return string
     */
    private static function normalise_newlines(string $text): string {
        return str_replace(["\r\n", "\r"], "\n", $text);
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
     * Save the web service allowlist of a role.
     *
     * Without $shown the list is replaced. With $shown (the functions the administrator saw, on the
     * visible page or filter) only those change: the functions allowed elsewhere are kept. While the
     * administrator has no saved list, every function counts as allowed.
     *
     * @param string $role One of {@see self::WS_ROLES}.
     * @param string[] $wsnames Web service function names selected (the role may call them).
     * @param string[]|null $shown Function names the selection was made on; null to replace the whole list.
     * @return void
     */
    public static function save_ws_allowlist(string $role, array $wsnames, ?array $shown = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        $role = self::normalise_ws_role($role);
        if ($shown !== null) {
            $current = \local_jedomi_role_has_full_ws_access($role)
                ? $DB->get_fieldset_select('external_functions', 'name', '1 = 1')
                : \local_jedomi_get_role_allowlist($role);
            $wsnames = array_merge(array_diff($current, $shown), $wsnames);
        }
        set_config('ws_allowlist_' . $role, json_encode(array_values(array_unique($wsnames))), 'local_jedomi');
        \local_jedomi\event\settings_updated::create_for_tab('apis')->trigger();
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

        // The type and the description are declared in the db/services.php of each component, so the type is
        // filtered here and not in SQL. Every function is either read or write.
        $declarations = self::ws_declarations(array_unique(array_column($functions, 'component')));
        $filtered = [];
        $countread = 0;
        $countwrite = 0;
        foreach ($functions as $function) {
            $declared = $declarations[$function->name] ?? [];
            $access = self::ws_access((string) $function->name, (string) ($declared['type'] ?? ''));
            if ($access === 'Escritura') {
                $countwrite++;
            } else {
                $countread++;
            }
            if ($type === 'all' || $access === $type) {
                $filtered[] = ['name' => (string) $function->name, 'component' => (string) $function->component,
                    'iswrite' => $access === 'Escritura', 'description' => (string) ($declared['description'] ?? '')];
            }
        }

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perpage));
        $page = min(max(1, $page), $pages);

        $allowlist = \local_jedomi_get_role_allowlist($role);
        // Before the first save the administrator can run every function: the table shows it.
        $fullaccess = \local_jedomi_role_has_full_ws_access($role);
        $rows = [];
        foreach (array_slice($filtered, ($page - 1) * $perpage, $perpage) as $function) {
            $rows[] = [
                'name' => $function['name'],
                'description' => shorten_text($function['description'], 160),
                'component' => $function['component'],
                'iswrite' => $function['iswrite'],
                'checked' => $fullaccess || in_array($function['name'], $allowlist, true),
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
     * Type and description of the web service functions declared by some components.
     *
     * Read from the db/services.php of each component, where Moodle reads them too.
     *
     * @param string[] $components Component names (moodle for core).
     * @return array<string, array{type: string, description: string}> Keyed by function name.
     */
    private static function ws_declarations(array $components): array {
        $declarations = [];
        foreach ($components as $component) {
            $dir = \core_component::get_component_directory((string) $component);
            $file = $dir ? $dir . '/db/services.php' : '';
            if ($file === '' || !is_readable($file)) {
                continue;
            }
            $functions = [];
            include($file);
            foreach ((array) $functions as $name => $definition) {
                $declarations[$name] = [
                    'type' => (string) ($definition['type'] ?? ''),
                    'description' => trim((string) ($definition['description'] ?? '')),
                ];
            }
        }
        return $declarations;
    }

    /**
     * Access type of a web service function: its declared type, or a guess from its name when it declares none.
     *
     * @param string $name Function name.
     * @param string $declared Type declared in db/services.php ("read" or "write"), or empty.
     * @return string "Lectura" or "Escritura", see {@see self::WS_TYPES}.
     */
    public static function ws_access(string $name, string $declared): string {
        if ($declared === 'write') {
            return 'Escritura';
        }
        if ($declared === 'read') {
            return 'Lectura';
        }
        return \local_jedomi_classify_ws_access($name) === 'Escritura' ? 'Escritura' : 'Lectura';
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
        $visible = array_map('intval', $visibleroleids);
        $hidden = [];
        foreach (self::get_roles() as $role) {
            if (!in_array($role['id'], $visible, true)) {
                $hidden[] = $role['id'];
            }
        }
        return $hidden;
    }
}
