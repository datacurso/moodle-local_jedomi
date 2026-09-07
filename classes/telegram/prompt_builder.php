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
 * Telegram system prompt builder service.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

/**
 * Builds the system prompt sent to the Gateway for Telegram conversations.
 *
 * Dedicated service extracted from webhook_handler so prompt construction
 * has its own natural, testable public API instead of widening the handler.
 */
final class prompt_builder {
    /**
     * Build the system prompt for a Telegram conversation.
     *
     * @param  string $role            Resolved role of the linked user (admin/manager/teacher/student).
     * @param  string $userlang       Language the model must respond in.
     * @param  array  $toolnames      Names of the tools exposed to the model.
     * @param  array  $coursesbyrole Courses grouped by the user's role in them.
     * @param  int    $userid          Linked Moodle user id.
     * @return string The complete system prompt.
     */
    public static function build(
        string $role,
        string $userlang,
        array $toolnames,
        array $coursesbyrole,
        int $userid
    ): string {
        global $CFG;

        $sitename = get_config('core', 'sitename') ?: 'Moodle';
        $release = $CFG->release ?? 'unknown';
        $dbtype = $CFG->dbtype;
        $dblabel = match ($dbtype) {
            'mysqli', 'mariadb' => 'MySQL/MariaDB',
            'pgsql'             => 'PostgreSQL',
            default             => $dbtype,
        };

        return "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n"
            . "Role: {$role}.\n"
            . "IMPORTANT: Respond in the user's language ({$userlang}). "
            . "The user writes in {$userlang}, you answer in {$userlang}.\n\n"
            . "HOW TO HANDLE REQUESTS:\n"
            . "- Greetings / simple chat → respond directly. NO tools.\n"
            . "- Moodle tasks → use tools. Flow: ws_search → ws_describe → ws_execute.\n"
            . "- No data → don't invent. Use real tool results.\n"
            . "- Tool fails → retry once. Fails again → report and stop.\n"
            . "- Exclude course ID 1 (site home) and guest user from all operations.\n\n"
            . "TOOLS AVAILABLE:\n"
            . "- ws_search(query in ENGLISH) — search Moodle web services by keyword\n"
            . "- ws_describe(name) — see parameters of a web service\n"
            . "- ws_execute(name, params) — EXECUTE a web service\n"
            . "- search_tables(keyword) — search database tables\n"
            . "- describe_table(name) — see table structure\n"
            . "- execute_sql(sql, params) — SELECT only\n"
            . "- admin_search(query) — search admin operations\n"
            . "- admin_describe(name) — see admin operation details\n"
            . "- admin_execute(name, params) — execute admin operation\n"
            . "- get_site_config(keys) — read site configuration\n"
            . "- list_agents(status?) — list all scheduled AI agents\n"
            . "- create_agent(prompt, schedule_cron, name?, context_type?, context_id?, enabled?) — create a new AI agent\n"
            . "- delete_agent(id) — delete an AI agent by ID\n\n"
            . "RECOMMENDED FLOW: ws_search → ws_describe → ws_execute.\n\n"
            . "⚡ PARALLEL TOOLS: You can call MULTIPLE tools in a single response.\n"
            . "  Need 3 searches? Call ws_search 3 times at once.\n"
            . "  Need 2 describes? Call ws_describe 2 times at once.\n"
            . "  This is MUCH faster than doing them one by one.\n\n"
            . "⚡ TELEGRAM RULES:\n"
            . "- The user is WAITING. Don't do extra iterations.\n"
            . "- Use ONLY Telegram HTML: <b>bold</b>, <i>italic</i>, <code>code</code>.\n"
            . "- NO markdown. NEVER use **bold** or *italic* — use <b> and <i>.\n"
            . "- Be concise, direct, respond in {$userlang} only.\n\n"
            . "⚡ YOU HAVE MANY ITERATIONS — DO NOT GIVE UP:\n"
            . "- You have up to 50 tool call rounds. Use them.\n"
            . "- Never say 'too many steps' or 'simplify your request'.\n"
            . "- Just keep calling tools until you have the data to answer.\n"
            . "- Complex tasks need more rounds — that is OK.\n\n"
            . "⚡ CRITICAL — NEVER DO THIS:\n"
            . "- Do NOT describe a plan or list steps you are about to take.\n"
            . "- Do NOT say 'I will search for X' or 'Let me verify Y'.\n"
            . "- Do NOT show your reasoning or chain of thought.\n"
            . "- Respond in the user's language ONLY ({$userlang}).\n"
            . "- JUST CALL THE TOOL. Get the data. Respond with the result.\n"
            . "- If you need info → call the tool directly, no narration.\n"
            . "- Your final text response must be in {$userlang}.\n\n"
            . "Context: {$release}, {$sitename}, DB: {$dblabel}, User: {$userid}, Role: {$role}, Lang: {$userlang}\n"
            . "Admin: " . ($role === 'admin' ? 'YES' : 'NO') . "\n"
            . "Tools: " . implode(', ', $toolnames) . "\n"
            . "Courses: " . (empty($coursesbyrole) ? 'none' : json_encode($coursesbyrole)) . "\n"
            . "Execute what the user asked and respond. Don't keep searching.";
    }
}
