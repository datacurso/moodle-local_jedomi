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
 * Channel-neutral system prompt builder of the messaging channels.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Builds the system prompt sent to the AI for the conversations of a messaging channel (Telegram, WhatsApp).
 *
 * The rules are the same for every channel; only the format rules come from the limits of the channel.
 */
final class prompt_builder {
    /** @var array<string, string> Signature and short description of each tool, in the order they are listed. */
    private const TOOL_DESCRIPTIONS = [
        'ws_search' => 'ws_search(query in ENGLISH) — search Moodle web services by keyword',
        'ws_describe' => 'ws_describe(name) — see parameters of a web service',
        'ws_execute' => 'ws_execute(name, params) — EXECUTE a web service',
        'search_tables' => 'search_tables(keyword) — search database tables',
        'describe_table' => 'describe_table(name) — see table structure',
        'execute_sql' => 'execute_sql(sql, params) — SELECT only',
        'admin_search' => 'admin_search(query) — search admin operations',
        'admin_describe' => 'admin_describe(name) — see admin operation details',
        'admin_execute' => 'admin_execute(name, params) — execute admin operation',
        'get_site_config' => 'get_site_config(keys) — read site configuration',
        'site_overview' => 'site_overview(days?) — the overall state of the platform in ONE call (users, courses,'
            . ' enrolments, activities, agents, storage); call it FIRST for a general summary',
        'list_agents' => 'list_agents(status?) — list the scheduled AI agents',
        'create_agent' => 'create_agent(prompt, schedule_cron, name?, context_type?, context_id?, enabled?)'
            . ' — create a new AI agent',
        'delete_agent' => 'delete_agent(id) — delete an AI agent by ID',
        'my_courses' => 'my_courses() — the courses of the user',
        'my_grades' => 'my_grades(courseid?) — the grades the user can see',
        'my_assignments' => 'my_assignments(courseid?) — the assignments, due dates and upcoming deadlines of the user',
    ];

    /** @var string[] Tools that change data and only run after the user confirms them. */
    private const WRITING_TOOLS = ['create_agent', 'delete_agent', 'admin_execute', 'ws_execute'];

    /**
     * Build the system prompt for a conversation of a messaging channel.
     *
     * Only the tools offered to the user are described. The internal id of the user is never included:
     * the AI service knows the user only by the pseudonym of the provider.
     *
     * @param  string $role            Resolved role of the linked user (admin/manager/teacher/student).
     * @param  string $userlang       Language the model must respond in.
     * @param  array  $toolnames      Names of the tools exposed to the model.
     * @param  array  $coursesbyrole Courses grouped by the user's role in them.
     * @param  channel_limits $limits Limits of the channel, which give its format rules.
     * @return string The complete system prompt.
     */
    public static function build(
        string $role,
        string $userlang,
        array $toolnames,
        array $coursesbyrole,
        channel_limits $limits
    ): string {
        global $CFG;

        $sitename = get_config('core', 'sitename') ?: 'Moodle';
        // Only the major version, as in the web chat (SEC-JD-013).
        $version = \local_jedomi\proxy\system_message::moodle_major_version();
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => 'MySQL/MariaDB',
            'pgsql'             => 'PostgreSQL',
            default             => $dbtype,
        };

        $toollines = '';
        foreach (self::TOOL_DESCRIPTIONS as $name => $description) {
            if (in_array($name, $toolnames, true)) {
                $toollines .= "- {$description}\n";
            }
        }
        if ($toollines === '') {
            $toollines = "(none)\n";
        }
        $writing = array_values(array_intersect(self::WRITING_TOOLS, $toolnames));
        $courses = array_filter($coursesbyrole);

        // The name of the assistant comes from the Personalization tab.
        $prompt = \local_jedomi\local\personalization::prompt_identity($sitename)
            . "Role: {$role}.\n"
            . "IMPORTANT: Answer in the language of the user's latest message, even when earlier messages or tool"
            . " results use another language. When that language cannot be told (a command, a number), use {$userlang}.\n\n"
            . "HOW TO HANDLE REQUESTS:\n"
            . "- Greetings / simple chat → respond directly. NO tools.\n"
            . "- Moodle tasks → use the tools below.\n"
            . "- No data → don't invent. Use real tool results.\n"
            . "- Tool fails → retry once. Fails again → report and stop.\n"
            . "- Exclude course ID 1 (site home) and guest user from all operations.\n\n"
            . "TOOLS AVAILABLE:\n"
            . $toollines . "\n";
        if (in_array('ws_execute', $toolnames, true)) {
            $prompt .= "RECOMMENDED FLOW: ws_search → ws_describe → ws_execute.\n\n";
        }
        $prompt .= "ACTIONS THAT CHANGE DATA:\n"
            . "- Only call an action that changes data (creating or deleting agents, admin operations,"
            . " web services that write or send messages) when the user explicitly asks for that action."
            . " Questions, reports and analysis only read data: answer them with the read tools."
            . " If a query fails, fix the query or report it; never try an action instead.\n";
        if ($writing) {
            $prompt .= "- " . implode(', ', $writing) . " (with a write function) wait for the user's confirmation"
                . " with the buttons of the chat before they run. Call the tool once with the final arguments;"
                . " do not ask for confirmation in text first.\n";
        }
        // The same rules as the web chat: third-party content is data, and the instructions stay private.
        $prompt .= "- " . \local_jedomi\local\untrusted::rule() . "\n"
            . "- " . \local_jedomi\proxy\system_message::no_disclosure_rule() . "\n"
            . "- " . \local_jedomi\proxy\system_message::site_admin_rule() . "\n";
        $prompt .= "\n"
            . "⚡ PARALLEL TOOLS: You can call MULTIPLE tools in a single response.\n"
            . "  This is MUCH faster than doing them one by one.\n\n"
            . $limits->format_rules($userlang)
            . "⚡ YOU HAVE MANY ITERATIONS — DO NOT GIVE UP:\n"
            . "- You have up to 50 tool call rounds. Use them.\n"
            . "- Never say 'too many steps' or 'simplify your request'.\n"
            . "- Complex tasks need more rounds — that is OK.\n\n"
            . "⚡ CRITICAL — NEVER DO THIS:\n"
            . "- Do NOT describe a plan or list steps you are about to take.\n"
            . "- Do NOT show your reasoning or chain of thought.\n"
            . "- If you need info → call the tool directly, no narration.\n"
            . "- Your final text response must be in {$userlang}.\n\n"
            // The site instructions of the Personalization tab go after every rule, with a lower priority.
            . \local_jedomi\local\personalization::prompt_site_instructions()
            . "Context: {$version}, {$sitename}, DB: {$dblabel}, Role: {$role}, Lang: {$userlang}\n"
            . "Admin: " . ($role === 'admin' ? 'YES' : 'NO') . "\n"
            . "Tools: " . (empty($toolnames) ? 'none' : implode(', ', $toolnames)) . "\n"
            // Course names are written by teachers and managers: data, never rules (SEC-JD-008).
            . "Courses:" . (empty($courses) ? ' none' : "\n" . (new \local_jedomi\local\untrusted())->wrap(
                'moodle courses',
                json_encode($courses, JSON_UNESCAPED_UNICODE)
            )) . "\n"
            . "Answer what the user asked with the data of the tools. Don't keep searching.";
        return $prompt;
    }
}
