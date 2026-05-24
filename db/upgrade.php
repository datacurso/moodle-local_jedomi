<?php
/**
 * Upgrade file for local_jedomi — adds Telegram integration tables.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_jedomi_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051401) {

        // ── Table: local_jedomi_telegram_links ──────────────────────────────
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

        // ── Table: local_jedomi_telegram_sessions ───────────────────────────
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

        // ── Table: local_jedomi_telegram_pending_actions ────────────────────
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

        // ── Table: local_jedomi_telegram_rate_limit ─────────────────────────
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
        // Add usercontext TEXT column to sessions table
        $table = new xmldb_table('local_jedomi_telegram_sessions');
        $field = new xmldb_field('usercontext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'messages');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026052402, 'local', 'jedomi');
    }

    return true;
}
