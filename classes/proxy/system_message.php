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

/**
 * System message builder for the AI chat proxy.
 *
 * Builds a concise and generic system prompt based on user role and context.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

class system_message {

    /**
     * Build the system message for the AI model.
     *
     * @param  string $role    The user's resolved role.
     * @param  array  $spt     The validated SPT payload.
     * @param  array  $context The current page context.
     * @return array           ['role' => 'system', 'content' => '...']
     */
    public static function build(string $role, array $spt, array $context = []): array {
        global $CFG;

        $sitename  = get_config('core', 'sitename') ?: 'Moodle';
        $release   = $CFG->release ?? 'unknown';
        $courseId  = $spt['course_id'] ?? 0;
        $courseName = $context['course_name'] ?? '';
        $location   = $context['location'] ?? 'unknown';
        $pageUrl    = $context['page_url'] ?? '';
        $pageTitle  = $context['page_title'] ?? '';

        $locLabels = [
            'course'    => get_string('ctx_loc_course', 'local_jedomi') . ($courseName ? " \"{$courseName}\"" : '') . get_string('ctx_loc_course_id', 'local_jedomi', $courseId),
            'activity'  => get_string('ctx_loc_activity', 'local_jedomi') . ' "' . ($context['activity_type'] ?? 'unknown') . get_string('ctx_loc_activity_suffix', 'local_jedomi'),
            'grades'    => get_string('ctx_loc_gradebook', 'local_jedomi'),
            'admin'     => get_string('ctx_loc_admin', 'local_jedomi'),
            'dashboard' => get_string('ctx_loc_dashboard', 'local_jedomi'),
            'messages'  => get_string('ctx_loc_messages', 'local_jedomi'),
            'profile'   => get_string('ctx_loc_profile', 'local_jedomi'),
            'calendar'  => get_string('ctx_loc_calendar', 'local_jedomi'),
            'files'     => get_string('ctx_loc_files', 'local_jedomi'),
        ];

        // Detect database type automatically from Moodle config.
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => get_string('dblabel_mysql', 'local_jedomi'),
            'pgsql'             => get_string('dblabel_pgsql', 'local_jedomi'),
            default             => $dbtype,
        };

        $content = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n";
        $content .= "Role: {$role}.\n";
        $content .= "Goal: help the user complete any valid action using available tools.\n\n";

        $content .= "WORKFLOW:\n";
        $content .= "1. Understand the user goal.\n";
        $content .= "2. Decide the best tool path: WS first, SQL only if WS can't do it.\n";
        $content .= "3. Call tools, then answer with real results.\n\n";

        $content .= "TOOL HIERARCHY — USE WS FIRST:\n";
        $content .= "- Web services (WS) are the PREFERRED path. They are safer, faster, and understand Moodle semantics.\n";
        $content .= "- WS can: list users/courses, get grades, get enrolments, create/update users, get user details, send messages, get site info, get course contents.\n";
        $content .= "- SQL is a FALLBACK for cases WS cannot handle (e.g. custom filters, cross-table aggregations, activity completion stats, custom queries).\n";
        $content .= "- If you are unsure which tool fits, start with ws_search. If ws_search returns nothing useful, fall back to search_tables + SQL.\n\n";

        $content .= "WS FLOW:\n";
        $content .= "- ws_search(query in ENGLISH) → pick best match → ws_describe(exact name) → ws_execute with ALL * fields.\n";
        $content .= "- For batch operations (e.g. enrol in 6 courses, create 3 users): call ws_execute MULTIPLE times with different params. There is NO bulk function.\n";
        $content .= "- Do NOT search again once you have a match.\n\n";

        $content .= "SQL FLOW (fallback only):\n";
        $content .= "- search_tables(keyword in ENGLISH) → describe_table(table) → execute_sql(SELECT with ? placeholders).\n";
        $content .= "- Use execute_sql only for SELECT. Never modify data.\n";
        $content .= "- search_tables searches by intent across table names AND their COMMENT descriptions.\n\n";

        $content .= "GENERAL RULES:\n";
        $content .= "- Never invent data.\n";
        $content .= "- ALWAYS exclude course ID 1 (site home) and guest user from any operation.\n";
        $content .= "- If a tool fails, read the error and retry once with a fix. If it fails again, stop and report.\n";
        $content .= "- If a tool already provides enough data, stop and respond.\n";

        $content .= "RESPONSE RULES:\n";
        $content .= "- Be concise and action-oriented.\n";
        $content .= "- Use the user's language.\n";
        $content .= "- Respect permissions and privacy.\n";
        $content .= "- If something cannot be done, say why and propose the next best step.\n\n";

        $content .= "Current context:\n";
        $content .= "- Moodle version: {$release}\n";
        $content .= "- Site: {$sitename} ({$CFG->wwwroot})\n";
        $content .= "- Database: {$dblabel}\n";
        $content .= "- Location: {$location}\n";
        $content .= "- Page: {$pageTitle} ({$pageUrl})\n";
        $content .= isset($locLabels[$location]) ? "- {$locLabels[$location]}\n" : '';
        $content .= "- User role: {$role}\n";

        return ['role' => 'system', 'content' => $content];
    }
}
