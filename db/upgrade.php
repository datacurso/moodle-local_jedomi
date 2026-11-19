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
 * Upgrade file for local_jedomi — adds Telegram integration tables.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executes the plugin upgrade steps, creating the Telegram integration tables and indexes.
 *
 * @param int $oldversion the version being upgraded from
 * @return bool true on success
 */
function xmldb_local_jedomi_upgrade(int $oldversion): bool {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051401) {
        // ... ── Table: local_jedomi_telegram_links ──────────────────────────────
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

        // ... ── Table: local_jedomi_telegram_sessions ───────────────────────────
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

        // ... ── Table: local_jedomi_telegram_pending_actions ────────────────────
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

        // ... ── Table: local_jedomi_telegram_rate_limit ─────────────────────────
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
        // ... ── Table: local_jedomi_agents ─────────────────────────────────────────
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

        // ... ── Table: local_jedomi_agent_cache ────────────────────────────────────
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
        // ... ── Table: local_jedomi_query_log ─────────────────────────────────────
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
        // ... ── Add "name" column to local_jedomi_agents ─────────────────────────
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

    if ($oldversion < 2026102700) {
        // AI calls now go through the Datacurso AI provider: drop the legacy gateway URL and key,
        // and replace a model that the provider route cannot use with the default one.
        \local_jedomi\ai\model_settings::migrate_legacy_settings();

        // Answer ratings, previously sent to the legacy gateway, are now stored in Moodle.
        $table = new xmldb_table('local_jedomi_feedback');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rating', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessionid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('messagetext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026102700, 'local', 'jedomi');
    }

    if ($oldversion < 2026102801) {
        // The model list changed after the model evaluation: a saved model that is no longer
        // listed (for example Mistral Small 3.2) is replaced with the default one.
        \local_jedomi\ai\model_settings::reset_unlisted_model();
        upgrade_plugin_savepoint(true, 2026102801, 'local', 'jedomi');
    }

    if ($oldversion < 2026102804) {
        // Actions of the web chat that change data wait in this table until the user confirms them.
        $table = new xmldb_table('local_jedomi_pending_actions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tool', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('argsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('severity', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102804, 'local', 'jedomi');
    }

    if ($oldversion < 2026102902) {
        // The "Enabled" switch moved to the Datacurso AI provider, which copied its value before this step.
        unset_config('enabled', 'local_jedomi');
        upgrade_plugin_savepoint(true, 2026102902, 'local', 'jedomi');
    }

    if ($oldversion < 2026110300) {
        // The 2026052414 and 2026052415 steps were added after sites had already passed those
        // versions, so they never ran there. Create the profiler table and the agent name column
        // when they are missing.
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

        $table = new xmldb_table('local_jedomi_agents');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026110300, 'local', 'jedomi');
    }

    if ($oldversion < 2026110301) {
        // Files and images keep working (now through the vision model), audio stays off until an
        // administrator turns it on, and the vision model replaces the document-to-image service.
        \local_jedomi\ai\model_settings::apply_media_defaults();
        upgrade_plugin_savepoint(true, 2026110301, 'local', 'jedomi');
    }

    if ($oldversion < 2026110401) {
        // Sites without an own permission token secret (or with the former built-in key) get a random one.
        $sptsecret = (string) get_config('local_jedomi', 'spt_secret');
        if ($sptsecret === '' || $sptsecret === 'jedomi-spt-secret') {
            set_config('spt_secret', bin2hex(random_bytes(32)), 'local_jedomi');
        }
        upgrade_plugin_savepoint(true, 2026110401, 'local', 'jedomi');
    }

    if ($oldversion < 2026111000) {
        // Older defaults prohibited the companion API for the authenticated user role, which every logged-in user
        // holds, so managers could not run database queries or web services from the chat. Remove that prohibit.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_remove_companion_api_prohibits();
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'jedomi');
    }

    if ($oldversion < 2026111100) {
        // The voice conversation is new and has its own cost: it stays off until an administrator turns it on,
        // with the default speech model and voice already chosen.
        \local_jedomi\ai\model_settings::apply_voice_defaults();
        upgrade_plugin_savepoint(true, 2026111100, 'local', 'jedomi');
    }

    if ($oldversion < 2026111202) {
        // The Agents tab shows the answer of the last run of an agent and the reason of its last failure.
        $table = new xmldb_table('local_jedomi_agents');
        $fields = [
            new xmldb_field('lastresult', XMLDB_TYPE_TEXT, null, null, null, null, null, 'failcount'),
            new xmldb_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lastresult'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026111202, 'local', 'jedomi');
    }

    if ($oldversion < 2026111203) {
        // Each answer keeps a single rating per user, which can be changed and can carry a comment.
        $table = new xmldb_table('local_jedomi_feedback');
        $fields = [
            new xmldb_field('messageid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'timecreated'),
            new xmldb_field('feedbackcomment', XMLDB_TYPE_TEXT, null, null, null, null, null, 'messageid'),
            new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'feedbackcomment'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('usermessage_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'messageid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // Older ratings have no message id: repeated clicks on the same answer are kept once, the latest one.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_deduplicate_feedback();
        upgrade_plugin_savepoint(true, 2026111203, 'local', 'jedomi');
    }

    if ($oldversion < 2026111204) {
        // Sites installed from an install.xml without the iteration column skipped the step that adds it.
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('iteration', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'usercontext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // The training data and "Hide from guest users" options had no effect and were removed.
        unset_config('collect_training_data', 'local_jedomi');
        unset_config('hide_from_guests', 'local_jedomi');
        upgrade_plugin_savepoint(true, 2026111204, 'local', 'jedomi');
    }

    if ($oldversion < 2026111206) {
        // The query profiler records the queries of every channel (web chat, Telegram, companion and agent previews).
        $table = new xmldb_table('local_jedomi_query_log');
        $fields = [
            new xmldb_field('channel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'agent', 'agentid'),
            new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'channel'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('channel_idx', XMLDB_INDEX_NOTUNIQUE, ['channel']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // Queries of a preview were stored with agent 0 before the channel existed.
        $DB->set_field('local_jedomi_query_log', 'channel', 'preview', ['agentid' => 0]);
        upgrade_plugin_savepoint(true, 2026111206, 'local', 'jedomi');
    }

    if ($oldversion < 2026111301) {
        // Existing sites keep the Datacurso AI service; the custom service is chosen on the provider settings page.
        if (get_config('local_jedomi', 'ai_service') === false) {
            set_config('ai_service', 'datacurso', 'local_jedomi');
        }
        upgrade_plugin_savepoint(true, 2026111301, 'local', 'jedomi');
    }

    if ($oldversion < 2026111302) {
        // The "none" service is retired: Jedomi is switched off with "Enabled" of the provider.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_ai_service();
        upgrade_plugin_savepoint(true, 2026111302, 'local', 'jedomi');
    }

    if ($oldversion < 2026111303) {
        // The Telegram webhook secret leaves the webhook address (JEDOMI-SEC-004): it is rotated and the webhook is
        // registered again with it as secret_token. A failure leaves a warning in the Integrations tab, prints a line
        // and queues a task that retries the registration (RES-001).
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_telegram_webhook();
        upgrade_plugin_savepoint(true, 2026111303, 'local', 'jedomi');
    }

    if ($oldversion < 2026111304) {
        // Agents run only the writes approved for them (JEDOMI-SEC-001): existing agents start with none, and the
        // actions they hold name the agent.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_agent_write_approvals();
        upgrade_plugin_savepoint(true, 2026111304, 'local', 'jedomi');
    }

    if ($oldversion < 2026111305) {
        // New message provider "telegramwebhook" for failed Telegram webhook registrations (RES-001); no data changes.
        upgrade_plugin_savepoint(true, 2026111305, 'local', 'jedomi');
    }

    if ($oldversion < 2026111700) {
        // A custom service URL with a path is used as given from 1.6.2: the saved URL, kept without its /v1 until now,
        // gets back the /v1 every route added, so it keeps calling the same address and stays verified.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_custom_service_url();
        upgrade_plugin_savepoint(true, 2026111700, 'local', 'jedomi');
    }

    if ($oldversion < 2026111800) {
        // The custom service gets its own feature switches (on for each feature whose model was saved, documents off),
        // and the custom max tokens, the AI request timeout and the row limit of the web chat get their defaults.
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_custom_feature_switches();
        upgrade_plugin_savepoint(true, 2026111800, 'local', 'jedomi');
    }

    if ($oldversion < 2026111900) {
        // The writes a scheduled run already attempted are kept across its retries, so a retry never repeats them
        // (RES-003).
        $table = new xmldb_table('local_jedomi_agents');
        $field = new xmldb_field('runwrites', XMLDB_TYPE_TEXT, null, null, null, null, null, 'approvedwrites');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111900, 'local', 'jedomi');
    }

    if ($oldversion < 2026111902) {
        // Telegram link security (Decision 7): the last use of the link, after which it expires in 30 days, and the PIN
        // of administrators and managers with its failures, lock and confirmation window.
        $table = new xmldb_table('local_jedomi_telegram_links');
        $fields = [
            new xmldb_field('lastused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastproactivesent'),
            new xmldb_field('pinhash', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'lastused'),
            new xmldb_field('pinfailures', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'pinhash'),
            new xmldb_field('pinlockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pinfailures'),
            new xmldb_field('pinverifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pinlockeduntil'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // The existing links count as used now, so they get 30 days before they expire instead of being expired and
        // removed at once (RES-001).
        $DB->set_field('local_jedomi_telegram_links', 'lastused', time(), ['lastused' => 0]);
        upgrade_plugin_savepoint(true, 2026111902, 'local', 'jedomi');
    }

    if ($oldversion < 2026111903) {
        // The summary of each agent run is no longer sent by email by default (REL-005).
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        local_jedomi_upgrade_agentwrites_email_default();
        upgrade_plugin_savepoint(true, 2026111903, 'local', 'jedomi');
    }

    return true;
}
