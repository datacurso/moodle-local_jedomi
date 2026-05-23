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
            'course'    => 'Currently viewing a course' . ($courseName ? " \"{$courseName}\"" : '') . " (ID: {$courseId}).",
            'activity'  => 'Currently in an activity of type "' . ($context['activity_type'] ?? 'unknown') . '".',
            'grades'    => 'Currently viewing the gradebook.',
            'admin'     => 'Currently in the Moodle administration panel.',
            'dashboard' => 'Currently on the Moodle dashboard.',
            'messages'  => 'Currently in the messages section.',
            'profile'   => 'Currently viewing a user profile.',
            'calendar'  => 'Currently viewing the calendar.',
            'files'     => 'Currently in the files area.',
        ];

        // Detect database type automatically from Moodle config.
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => 'MySQL/MariaDB',
            'pgsql'             => 'PostgreSQL',
            default             => $dbtype,
        };

        $content = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n";
        $content .= "Role: {$role}.\n";
        $content .= "Goal: help the user complete any valid action using available tools.\n\n";

        $content .= "WORKFLOW:\n";
        $content .= "1. Understand the user goal.\n";
        $content .= "2. Decide if the best path is WS, SQL, or both.\n";
        $content .= "3. Call tools, then answer with real results.\n\n";

        $content .= "TOOL RULES:\n";
        $content .= "- Never invent data.\n";
        $content .= "- For any request about current site data (lists, counts, availability, status), call at least one tool before answering.\n";
        $content .= "- For web services, discover first (ws_search/ws_describe), execute after.\n";
        $content .= "- ONCE ws_search returns good matches: pick the best one, call ws_describe to verify parameters, then call ws_execute. Do NOT search again for the same task.\n";
        $content .= "- For batch operations (same action on multiple items, e.g. enrol in 6 courses): call ws_execute MULTIPLE times with different parameters. There is NO bulk function — execute sequentially, once per item.\n";
        $content .= "- For tables/SQL: discover first (search_tables), describe structure (describe_table), execute after (execute_sql). Same flow as WS.\n";
        $content .= "- search_tables searches by intent across table names AND their COMMENT descriptions — use keywords in ENGLISH.\n";
        $content .= "- ALWAYS exclude course ID 1 (the site home course) from any operation — both SQL queries and WS calls.\n";
        $content .= "- ALWAYS exclude the guest user (username 'guest') from any operation.\n";
        $content .= "- For SQL, use execute_sql only for SELECT statements and parameterized values.\n";
        $content .= "- If a tool fails or permissions are denied, report that explicitly and do not guess results.\n";
        $content .= "- Retry once only when a clear fix exists.\n";
        $content .= "- If a tool already provides enough data, stop and respond.\n\n";

        $content .= "RESPONSE RULES:\n";
        $content .= "- Be concise and action-oriented.\n";
        $content .= "- Use the user's language.\n";
        $content .= "- Respect permissions and privacy.\n";
        $content .= "- If something cannot be done, say why and propose the next best step.\n\n";

        $content .= "Current context:\n";
        $content .= "- Moodle version: {$release}\n";
        $content .= "- Database: {$dblabel}\n";
        $content .= "- Location: {$location}\n";
        $content .= "- Page: {$pageTitle} ({$pageUrl})\n";
        $content .= isset($locLabels[$location]) ? "- {$locLabels[$location]}\n" : '';
        $content .= "- User role: {$role}\n";

        return ['role' => 'system', 'content' => $content];
    }
}
