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
 * Site overview tool — the overall state of the platform in one call.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Read-only tool that returns site-wide aggregates (site, users, courses, enrolments, activities, agents and storage).
 *
 * One call answers a general "state of the platform" question, so the model does not need several rounds (and AI
 * requests) of configuration reads, web service searches and guessed queries. Every figure is an aggregate computed
 * by the database (COUNT/SUM); no names, emails or other personal data are returned.
 */
final class site_overview_tool {
    /** @var string Name of the tool in the AI tool calls. */
    public const NAME = 'site_overview';

    /** @var string[] Jedomi roles that read site-wide data and get this tool. */
    private const ALLOWED_ROLES = ['admin', 'manager'];

    /** @var int Default activity window, in days. */
    public const DEFAULT_DAYS = 30;

    /** @var int Longest activity window, in days. */
    public const MAX_DAYS = 365;

    /** @var int Number of module types listed by count. */
    private const TOP_MODULES = 5;

    /**
     * Whether the tool can be offered to a role.
     *
     * @param string $role Jedomi role: admin, manager, teacher or student.
     * @return bool True for the roles that read site-wide data.
     */
    public static function is_offered(string $role): bool {
        return in_array($role, self::ALLOWED_ROLES, true);
    }

    /**
     * OpenAI-compatible function definition of the tool.
     *
     * @return array Tool definition with an optional 'days' argument.
     */
    public static function definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => get_string('tool_site_overview_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => [
                            'type' => 'integer',
                            'description' => get_string('tool_site_overview_days', 'local_jedomi'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Run the tool for a role.
     *
     * @param array $args Tool arguments; 'days' (1-365, default 30) is the activity window.
     * @param string $role Jedomi role of the user.
     * @return string JSON-encoded overview, or a permission_denied error for the other roles.
     */
    public static function run(array $args, string $role): string {
        if (!self::is_offered($role)) {
            return json_encode(['error' => 'permission_denied']);
        }
        try {
            return json_encode(self::build(self::window_days($args['days'] ?? null)), JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => get_string('summary_error_prefix', 'local_jedomi') . \local_jedomi\local\safe_error::for_model($e),
            ]);
        }
    }

    /**
     * Activity window in days from the argument of the model, kept within 1 and MAX_DAYS.
     *
     * @param mixed $days Value given by the model; anything that is not a number gives the default.
     * @return int Days of the window.
     */
    public static function window_days($days): int {
        if (!is_numeric($days)) {
            return self::DEFAULT_DAYS;
        }
        return max(1, min(self::MAX_DAYS, (int) $days));
    }

    /**
     * Compute the overview.
     *
     * @param int $days Activity window, in days.
     * @return array Overview with the keys window_days, site, users, courses, enrolments, activities, agents and storage.
     */
    public static function build(int $days): array {
        return [
            'window_days' => $days,
            'site' => self::site(),
            'users' => self::users(time() - $days * DAYSECS),
            'courses' => self::courses(),
            'enrolments' => self::enrolments(),
            'activities' => self::activities(),
            'agents' => self::agents(),
            'storage' => self::storage(),
        ];
    }

    /**
     * Site name, host, Moodle release, default language, time zone and the last cron run.
     *
     * The last cron run comes from the start time core cron saves in the configuration (no log is read).
     *
     * @return array Site details.
     */
    private static function site(): array {
        global $CFG;
        $lastcron = (int) get_config('tool_task', 'lastcronstart');
        return [
            'name' => format_string(get_site()->fullname, true, ['context' => \context_system::instance()]),
            'host' => (string) parse_url($CFG->wwwroot, PHP_URL_HOST),
            'moodle_release' => (string) ($CFG->release ?? ''),
            'moodle_version' => (string) ($CFG->version ?? ''),
            'default_language' => (string) ($CFG->lang ?? ''),
            'timezone' => \core_date::get_server_timezone(),
            'last_cron_run' => $lastcron > 0 ? date('c', $lastcron) : null,
            'last_cron_minutes_ago' => $lastcron > 0 ? (int) floor(max(0, time() - $lastcron) / MINSECS) : null,
        ];
    }

    /**
     * Counts of the users that are not deleted, without the guest user.
     *
     * @param int $since Start of the activity window (timestamp).
     * @return array total, active, suspended, active_in_window and never_logged_in.
     */
    private static function users(int $since): array {
        global $CFG, $DB;
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS total,
                    COALESCE(SUM(CASE WHEN suspended = 0 THEN 1 ELSE 0 END), 0) AS active,
                    COALESCE(SUM(CASE WHEN suspended = 1 THEN 1 ELSE 0 END), 0) AS suspended,
                    COALESCE(SUM(CASE WHEN lastaccess >= :since THEN 1 ELSE 0 END), 0) AS recent,
                    COALESCE(SUM(CASE WHEN lastaccess = 0 THEN 1 ELSE 0 END), 0) AS never
               FROM {user}
              WHERE deleted = 0 AND id <> :guestid",
            ['since' => $since, 'guestid' => (int) ($CFG->siteguest ?? 0)]
        );
        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'suspended' => (int) $row->suspended,
            'active_in_window' => (int) $row->recent,
            'never_logged_in' => (int) $row->never,
        ];
    }

    /**
     * Counts of the courses (without the site course) and categories.
     *
     * A course without a teacher has nobody with a role of the teacher or editing teacher archetype in its context.
     *
     * @return array total, visible, hidden, categories and without_teacher.
     */
    private static function courses(): array {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS total, COALESCE(SUM(CASE WHEN visible = 1 THEN 1 ELSE 0 END), 0) AS visible
               FROM {course}
              WHERE id <> :siteid",
            ['siteid' => SITEID]
        );
        [$insql, $params] = $DB->get_in_or_equal(['editingteacher', 'teacher'], SQL_PARAMS_NAMED, 'arch');
        $withoutteacher = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {course} c
              WHERE c.id <> :siteid
                AND NOT EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
                      JOIN {role} r ON r.id = ra.roleid
                     WHERE ctx.instanceid = c.id AND r.archetype {$insql})",
            array_merge($params, ['siteid' => SITEID, 'courselevel' => CONTEXT_COURSE])
        );
        return [
            'total' => (int) $row->total,
            'visible' => (int) $row->visible,
            'hidden' => (int) $row->total - (int) $row->visible,
            'categories' => $DB->count_records('course_categories'),
            'without_teacher' => $withoutteacher,
        ];
    }

    /**
     * Active user enrolments: the enrolment and its method are on, it has started and not ended, the user exists.
     *
     * @return array active.
     */
    private static function enrolments(): array {
        global $DB;
        $now = time();
        $active = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE ue.status = :uestatus AND e.status = :estatus AND u.deleted = 0 AND e.courseid <> :siteid
                AND ue.timestart <= :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)",
            ['uestatus' => ENROL_USER_ACTIVE, 'estatus' => ENROL_INSTANCE_ENABLED, 'siteid' => SITEID,
                'now1' => $now, 'now2' => $now]
        );
        return ['active' => $active];
    }

    /**
     * Course modules that are not being deleted, and the most used module types.
     *
     * @return array total and top_types (type and count, most used first).
     */
    private static function activities(): array {
        global $DB;
        $bytype = $DB->get_records_sql_menu(
            "SELECT m.name, COUNT(1) AS total
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.deletioninprogress = 0
           GROUP BY m.name"
        );
        $bytype = array_map('intval', $bytype);
        arsort($bytype);
        $top = [];
        foreach (array_slice($bytype, 0, self::TOP_MODULES, true) as $type => $count) {
            $top[] = ['type' => (string) $type, 'count' => $count];
        }
        return ['total' => array_sum($bytype), 'top_types' => $top];
    }

    /**
     * Scheduled Jedomi agents by status. Only counts: no instruction or owner is returned.
     *
     * @return array total, enabled and disabled.
     */
    private static function agents(): array {
        global $DB;
        $bystatus = $DB->get_records_sql_menu(
            "SELECT enabled, COUNT(1) AS total
               FROM {local_jedomi_agents}
           GROUP BY enabled"
        );
        $enabled = (int) ($bystatus[1] ?? 0);
        $disabled = (int) ($bystatus[0] ?? 0);
        return ['total' => $enabled + $disabled, 'enabled' => $enabled, 'disabled' => $disabled];
    }

    /**
     * Size of the distinct file contents stored (each content counted once), without directory entries.
     *
     * @return array bytes and human (readable size).
     */
    private static function storage(): array {
        global $DB;
        $bytes = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(f.filesize), 0)
               FROM (SELECT DISTINCT contenthash, filesize
                       FROM {files}
                      WHERE filename <> :dot) f",
            ['dot' => '.']
        );
        return ['bytes' => $bytes, 'human' => display_size($bytes)];
    }
}
