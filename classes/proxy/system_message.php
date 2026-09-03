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

/**
 * Builds the role- and context-aware system prompt for the AI chat proxy.
 */
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
        $courseid  = $spt['course_id'] ?? 0;
        $coursename = $context['course_name'] ?? '';
        $location   = $context['location'] ?? 'unknown';
        $pageurl    = $context['page_url'] ?? '';
        $pagetitle  = $context['page_title'] ?? '';
        $output     = $context['output'] ?? 'web';  // Either web or telegram.

        $loclabels = [
            'course'    => get_string('ctx_loc_course', 'local_jedomi')
                . ($coursename ? " \"{$coursename}\"" : '')
                . get_string('ctx_loc_course_id', 'local_jedomi', $courseid),
            'activity'  => get_string('ctx_loc_activity', 'local_jedomi')
                . ' "' . ($context['activity_type'] ?? 'unknown')
                . get_string('ctx_loc_activity_suffix', 'local_jedomi'),
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
        $content .= "2. Try WS first. Only fall back to SQL if WS data is insufficient.\n";
        $content .= "3. Call tools, then answer with real results.\n\n";

        $content .= "DECISION RULE — WS FIRST, SQL SECOND:\n";
        $content .= "- Step 1: ws_search(query in ENGLISH). If a relevant function exists → ws_describe → ws_execute.\n";
        $content .= "- Step 2: Evaluate the WS result. If the data is COMPLETE and answers the question → stop and respond.\n";
        $content .= "- Step 3: If the WS data is INSUFFICIENT (missing fields, wrong scope, incomplete)"
            . " → complement with SQL: search_tables → describe_table → execute_sql.\n";
        $content .= "- Do NOT search SQL before evaluating the WS result.\n";
        $content .= "- Do NOT search WS and SQL in parallel — always sequential.\n\n";

        $content .= "WS PATH:\n";
        $content .= "- ws_search(query in ENGLISH) → pick best match → ws_describe(exact name) → ws_execute with ALL * fields.\n";
        $content .= "- Batch operations: call ws_execute MULTIPLE times. No bulk function.\n";
        $content .= "- Once you have a match, stop exploring.\n";
        $content .= "- RETRY STRATEGY: If ws_execute returns \"Missing required key\""
            . " or \"Detectado valor de parámetro no válido\" for a field NOT marked with *,"
            . " retry adding that field with a sensible default"
            . " (\"\" for text, 0 for int, false for bool, [] for arrays).\n\n";

        $content .= "SQL PATH (fallback / complement):\n";
        $content .= "- search_tables(keyword in ENGLISH) → describe_table(table) → execute_sql(SELECT with ? placeholders).\n";
        $content .= "- execute_sql is SELECT only. Never modify data.\n";
        $content .= "- search_tables searches intent across table names AND COMMENT descriptions.\n\n";

        $content .= "ADMIN PATH (create/change things in Moodle):\n";
        $content .= "- admin_search(query in ENGLISH) → discover what admin operations exist.\n";
        $content .= "- admin_describe(name) → see required parameters for an operation.\n";
        $content .= "- admin_execute(name, params) → execute the operation.\n";
        $content .= "- COMBINE with WS: use ws_search/ws_execute FIRST to gather context"
            . " (course info, sections, users), THEN call admin_execute to do the action.\n";
        $content .= "- Example: to create a 'page' in section 1 of course X"
            . " → ws_execute(core_course_get_courses_by_field) to verify course"
            . " → admin_execute(create_mod, {courseid: X, resource_type: 'page', section: 1,"
            . " parameters: {name: '...', content: '...', ...}}).\n\n";

        $content .= "DOCUMENT READ PATH:\n";
        $content .= "- When the user asks to read, analyze, summarize, or explain a document/file"
            . " from any course activity → use document_read(cmid).\n";
        $content .= "- The cmid is available in the context below as \"Activity cmid\" when on an activity page.\n";
        $content .= "- If you don't know the cmid, first discover it: ws_search for functions like"
            . " core_course_get_courses_by_field or core_course_get_contents,"
            . " then ws_execute to find the activity cmid.\n";
        $content .= "- document_read returns each page as a base64 image — you SEE the original"
            . " layout, tables, images, and formatting. Analyze them to answer.\n\n";

        $content .= "GENERAL RULES:\n";
        $content .= "- Never invent data.\n";
        $content .= "- ALWAYS exclude course ID 1 (site home) and guest user from any operation.\n";
        $content .= "- If a tool fails, retry once (adjust params if applicable,"
            . " see WS PATH for WS-specific retry). If it fails again, stop and report.\n";
        $content .= "- If you have enough data, stop and respond.\n";

        $content .= "RESPONSE RULES:\n";
        $content .= "- Be concise and action-oriented.\n";
        $content .= "- Use the user's language.\n";
        $content .= "- Respect permissions and privacy.\n";
        $content .= "- If something cannot be done, say why and propose the next best step.\n\n";

        if ($output === 'telegram') {
            $content .= "TELEGRAM FORMAT:\n";
            $content .= "- Use ONLY Telegram HTML tags: <b>bold</b>, <i>italic</i>, <code>code</code>, <pre>pre</pre>.\n";
            $content .= "- NO attributes on tags. NEVER use style, class, color, or any attribute.\n";
            $content .= "  Just <b>text</b>, NOT <b style=\"...\">text</b>.\n";
            $content .= "- NO markdown. NEVER use **bold** or *italic* — use <b> and <i>.\n";
            $content .= "- NO HTML lists (<ul>, <ol>, <li>). Use plain text with dashes or numbers.\n";
            $content .= "- NO HTML tables. Use plain text lines.\n";
            $content .= "- Be concise — Telegram has a 4000 character limit.\n\n";
        }

        $content .= "Current context:\n";
        $content .= "- Moodle version: {$release}\n";
        $content .= "- Site: {$sitename} ({$CFG->wwwroot})\n";
        $content .= "- Database: {$dblabel}\n";
        $content .= "- Location: {$location}\n";
        $content .= "- Page: {$pagetitle} ({$pageurl})\n";
        $content .= isset($loclabels[$location]) ? "- {$loclabels[$location]}\n" : '';

        // Add activity cmid when available.
        $cmid = $context['activity_id'] ?? 0;
        if ($cmid > 0) {
            $content .= "- Activity cmid: {$cmid}\n";
        }

        $content .= "- User role: {$role}\n";

        return ['role' => 'system', 'content' => $content];
    }
}
