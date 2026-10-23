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
 * Upgrade steps for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps of local_jedomi.
 *
 * @param int $oldversion Version installed before this upgrade.
 * @return bool Always true.
 */
function xmldb_local_jedomi_upgrade(int $oldversion): bool {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051401) {
        // Table: local_jedomi_telegram_links.
        $table = new xmldb_table('local_jedomi_telegram_links');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('username', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('confirmed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastproactivesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_UNIQUE, ['userid']);
        $table->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: local_jedomi_telegram_sessions.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('messages', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('lastactivity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
        $table->add_index('lastactivity_idx', XMLDB_INDEX_NOTUNIQUE, ['lastactivity']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: local_jedomi_telegram_pending_actions.
        $table = new xmldb_table('local_jedomi_telegram_pending_actions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('callbackid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('actionjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('callbackid', XMLDB_KEY_UNIQUE, ['callbackid']);
        $table->add_index('chatid_idx', XMLDB_INDEX_NOTUNIQUE, ['chatid']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: local_jedomi_telegram_rate_limit.
        $table = new xmldb_table('local_jedomi_telegram_rate_limit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('msgcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051401, 'local', 'jedomi');
    }

    if ($oldversion < 2026051409) {
        // Purge schema_cache — new cache definition for table_descriptor.
        $cache = \cache::make('local_jedomi', 'schema_cache');
        $cache->purge();

        upgrade_plugin_savepoint(true, 2026051409, 'local', 'jedomi');
    }

    if ($oldversion < 2026052402) {
        // Add usercontext TEXT column to sessions table.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('usercontext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'messages');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026052402, 'local', 'jedomi');
    }

    if ($oldversion < 2026052405) {
        // Change usercontext column from TEXT to MEDIUMTEXT (MySQL/MariaDB) to
        // prevent silent truncation when tool loop state exceeds 64KB.
        // PostgreSQL already uses unlimited TEXT, so this is MySQL/MariaDB only.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('usercontext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'messages');
        if ($dbman->field_exists($table, $field)) {
            $dbtype = $CFG->dbtype ?? 'mysqli';
            if (in_array($dbtype, ['mysqli', 'mariadb'], true)) {
                $DB->execute("ALTER TABLE {local_jedomi_telegram_sessions} MODIFY COLUMN usercontext MEDIUMTEXT");
            }
            // PostgreSQL TEXT is already unlimited — no change needed.
        }
        upgrade_plugin_savepoint(true, 2026052405, 'local', 'jedomi');
    }

    if ($oldversion < 2026052408) {
        // Add iteration column for optimistic concurrency in tool loop state.
        // Multiple HTTP workers (original + self-pings) compete for the same
        // state key. The iteration column lets set_context() reject stale writes
        // where another worker already saved a newer state.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('iteration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usercontext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026052408, 'local', 'jedomi');
    }

    if ($oldversion < 2026052409) {
        // Table: local_jedomi_agents.
        $table = new xmldb_table('local_jedomi_agents');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('schedule_cron', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('context_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
        $table->add_field('context_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('role_filter', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('lastrun', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('nextrun', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failcount', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('enabled_nextrun_idx', XMLDB_INDEX_NOTUNIQUE, ['enabled', 'nextrun']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Table: local_jedomi_agent_cache.
        $table = new xmldb_table('local_jedomi_agent_cache');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('context_hash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('result_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('agentid_context_hash_idx', XMLDB_INDEX_UNIQUE, ['agentid', 'context_hash']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026052409, 'local', 'jedomi');
    }

    if ($oldversion < 2026052412) {
        // Ensure agent tables exist. The 2026052409 step might have been
        // skipped if the DB version was bumped directly, so we create them
        // here as a safe fallback (table_exists() guards against duplicates).
        $table = new xmldb_table('local_jedomi_agents');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('schedule_cron', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('context_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'site');
            $table->add_field('context_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('role_filter', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('lastrun', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('nextrun', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('failcount', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('enabled_nextrun_idx', XMLDB_INDEX_NOTUNIQUE, ['enabled', 'nextrun']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_jedomi_agent_cache');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('context_hash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('result_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('agentid_context_hash_idx', XMLDB_INDEX_UNIQUE, ['agentid', 'context_hash']);
            $dbman->create_table($table);
        }

        // Force capability refresh — registers manageagents + assigns archetypes.
        upgrade_plugin_savepoint(true, 2026052412, 'local', 'jedomi');
    }

    if ($oldversion < 2026052413) {
        // Clear corrupted agent cache entries. Previously the agent_runner was
        // caching the full raw HTTP response (entire gateway JSON) instead of
        // the extracted assistant content. This left nested JSON in the cache.
        // Purging the cache forces fresh AI calls on the next cron cycle.
        $DB->delete_records('local_jedomi_agent_cache', []);
        upgrade_plugin_savepoint(true, 2026052413, 'local', 'jedomi');
    }

    if ($oldversion < 2026052414) {
        // Table: local_jedomi_query_log.
        $table = new xmldb_table('local_jedomi_query_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('query', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('duration_ms', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rowcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('duration_idx', XMLDB_INDEX_NOTUNIQUE, ['duration_ms']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026052414, 'local', 'jedomi');
    }

    if ($oldversion < 2026052415) {
        // Add "name" column to local_jedomi_agents.
        $table = new xmldb_table('local_jedomi_agents');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Remove the WS allowlist setting (obsolete — permission-based now).
        unset_config('agent_ws_allowlist', 'local_jedomi');

        upgrade_plugin_savepoint(true, 2026052415, 'local', 'jedomi');
    }

    if ($oldversion < 2026052501) {
        // Migrate result_json column from TEXT to MEDIUMTEXT (MySQL/MariaDB)
        // so that full conversation data fits without truncation.
        // PostgreSQL TEXT is already unlimited — no change needed.
        $table = new xmldb_table('local_jedomi_agent_cache');
        $field = new xmldb_field('result_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'context_hash');
        if ($dbman->field_exists($table, $field)) {
            $dbtype = $CFG->dbtype ?? 'mysqli';
            if (in_array($dbtype, ['mysqli', 'mariadb'], true)) {
                $DB->execute("ALTER TABLE {local_jedomi_agent_cache} MODIFY COLUMN result_json MEDIUMTEXT NOT NULL");
            }
        }
        upgrade_plugin_savepoint(true, 2026052501, 'local', 'jedomi');
    }

    if ($oldversion < 2026052502) {
        // Purge agent cache — previous versions may have cached raw TOOL_CALL
        // text content instead of the final answer. Clearing cache ensures fresh
        // AI calls produce clean results.
        $DB->delete_records('local_jedomi_agent_cache', []);
        upgrade_plugin_savepoint(true, 2026052502, 'local', 'jedomi');
    }

    if ($oldversion < 2026052503) {
        // Add plan_json column — stores the execution plan (tool chain) for
        // semantic cache replay on HIT.
        $table = new xmldb_table('local_jedomi_agent_cache');
        $field = new xmldb_field('plan_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'result_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026052503, 'local', 'jedomi');
    }

    if ($oldversion < 2026101801) {
        // Tutor settings per course and activity: the assignment selection and the mode stop being global;
        // the administration tab becomes a template.
        $table = new xmldb_table('local_jedomi_stem_cfg');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tutor_mode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'inherit');
        $table->add_field('max_questions', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tutor_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('courseid_cmid_uq', XMLDB_INDEX_UNIQUE, ['courseid', 'cmid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // Move the old global assignment selection (if any) to a row per activity.
        $oldcmid = (int) get_config('local_jedomi', 'stem_assign_cmid');
        if ($oldcmid > 0) {
            $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $oldcmid]);
            if ($courseid && !$DB->record_exists('local_jedomi_stem_cfg', ['courseid' => $courseid, 'cmid' => $oldcmid])) {
                $DB->insert_record('local_jedomi_stem_cfg', (object) ['courseid' => $courseid, 'cmid' => $oldcmid,
                    'enabled' => (int) get_config('local_jedomi', 'stem_enabled'), 'tutor_mode' => 'inherit', 'max_questions' => 0,
                    'tutor_prompt' => null, 'usermodified' => 0, 'timemodified' => time()]);
            }
        }
        unset_config('stem_assign_cmid', 'local_jedomi');
        upgrade_plugin_savepoint(true, 2026101801, 'local', 'jedomi');
    }

    if ($oldversion < 2026101802) {
        // Station services: observation events, rubric proposals and the auto_release option per course.
        $table = new xmldb_table('local_jedomi_stem_cfg');
        $field = new xmldb_field('auto_release', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'tutor_prompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_jedomi_assess_events');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('event_type', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('nonce', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('station_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ingestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('nonce_uq', XMLDB_INDEX_UNIQUE, ['nonce']);
        $table->add_index('session_idx', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
        $table->add_index('cmid_userid_idx', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_jedomi_assess_proposals');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('criteria_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('feedback_html', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('evidence_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('expected_grade', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'proposed');
        $table->add_field('nonce', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('proposedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('appliedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeapplied', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('nonce_uq', XMLDB_INDEX_UNIQUE, ['nonce']);
        $table->add_index('cmid_userid_status_idx', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'userid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026101802, 'local', 'jedomi');
    }

    if ($oldversion < 2026102200) {
        // The AR viewer inside assignments is retired: its 3D models (file area stem_model) are no longer served.
        $fs = get_file_storage();
        $contextids = $DB->get_fieldset_sql(
            "SELECT DISTINCT contextid FROM {files} WHERE component = :component AND filearea = :filearea",
            ['component' => 'local_jedomi', 'filearea' => 'stem_model']
        );
        foreach ($contextids as $contextid) {
            $fs->delete_area_files((int) $contextid, 'local_jedomi', 'stem_model');
        }
        upgrade_plugin_savepoint(true, 2026102200, 'local', 'jedomi');
    }

    if ($oldversion < 2026102201) {
        // The physical station is retired: its tables, its evidence files, its auto_release option and its settings.
        foreach (['local_jedomi_assess_events', 'local_jedomi_assess_proposals'] as $name) {
            $table = new xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
        $table = new xmldb_table('local_jedomi_stem_cfg');
        $field = new xmldb_field('auto_release');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $fs = get_file_storage();
        $contextids = $DB->get_fieldset_sql(
            "SELECT DISTINCT contextid FROM {files} WHERE component = :component AND filearea = :filearea",
            ['component' => 'local_jedomi', 'filearea' => 'stem_evidence']
        );
        foreach ($contextids as $contextid) {
            $fs->delete_area_files((int) $contextid, 'local_jedomi', 'stem_evidence');
        }
        $retired = ['stem_osc_host', 'stem_osc_port', 'stem_osc_reply_port', 'stem_preset_face', 'stem_preset_table',
            'stem_camera_index', 'stem_camera_width', 'stem_camera_height', 'stem_mic_match', 'stem_mat_width_mm',
            'stem_mat_height_mm', 'stem_marker_mm', 'stem_margin_mm', 'stem_aruco_dict', 'stem_whisper_model',
            'stem_whisper_prompt', 'stem_vision_model', 'stem_station_secret'];
        foreach ($retired as $name) {
            unset_config($name, 'local_jedomi');
        }
        if (get_config('local_jedomi', 'stem_tutor_mode') === 'assessment_only') {
            set_config('stem_tutor_mode', 'socratic', 'local_jedomi');
        }
        upgrade_plugin_savepoint(true, 2026102201, 'local', 'jedomi');
    }

    if ($oldversion < 2026102202) {
        // Repair sites installed from an install.xml that lacked these structures: their upgrade steps have older
        // version numbers and never ran there.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('iteration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usercontext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_jedomi_agents');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('local_jedomi_query_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('query', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('duration_ms', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rowcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('duration_idx', XMLDB_INDEX_NOTUNIQUE, ['duration_ms']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        // The tutor template defaults used to be written by the admin page on every visit; store them once here.
        \local_jedomi\tutor\course_settings::seed_site_defaults();
        upgrade_plugin_savepoint(true, 2026102202, 'local', 'jedomi');
    }

    if ($oldversion < 2026102300) {
        // The AR viewer moved to mod_jedomiar, which carried the voice setting over in its own upgrade.
        unset_config('stem_voice_enabled', 'local_jedomi');

        upgrade_plugin_savepoint(true, 2026102300, 'local', 'jedomi');
    }

    return true;
}
