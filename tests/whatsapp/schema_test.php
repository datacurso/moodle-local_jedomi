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

namespace local_jedomi\whatsapp;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\conversation_store;
use local_jedomi\messaging\rate_limiter;

/**
 * Tests for the WhatsApp tables: install, upgrade step and use by the channel-neutral core.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class schema_test extends \advanced_testcase {
    /** @var string[] WhatsApp tables of the plugin. */
    private const TABLES = [
        'local_jedomi_whatsapp_links',
        'local_jedomi_whatsapp_sessions',
        'local_jedomi_whatsapp_pending',
        'local_jedomi_whatsapp_rate',
        'local_jedomi_whatsapp_inbox',
        'local_jedomi_whatsapp_usage',
    ];

    /** @var int Version whose upgrade step creates the WhatsApp tables. */
    private const UPGRADE_VERSION = 2026112600;

    /**
     * Longest BSUID Meta documents: two country letters, a dot and 128 alphanumeric characters (131 characters).
     *
     * @return string
     */
    private static function longest_bsuid(): string {
        return 'US.' . str_repeat('a1B2', 32);
    }

    /**
     * Columns of a table as reported by the database.
     *
     * @param string $table Table name.
     * @return \database_column_info[]
     */
    private static function columns(string $table): array {
        global $DB;
        $DB->reset_caches();
        return $DB->get_columns($table, false);
    }

    /**
     * Shape of a table as the database reports it: per column the type, length, NOT NULL and default, and the indexes
     * (unique keys included) by their columns and uniqueness, since the database names them on its own.
     *
     * @param string $table Table name.
     * @return array
     */
    private static function shape(string $table): array {
        global $DB;
        $columns = array_map(static fn(\database_column_info $column): array => [
            'type' => $column->meta_type,
            'length' => (int) $column->max_length,
            'notnull' => (bool) $column->not_null,
            'hasdefault' => (bool) $column->has_default,
            'default' => $column->has_default ? (string) $column->default_value : null,
        ], self::columns($table));
        $indexes = array_values(array_map(static fn(array $index): string =>
            ($index['unique'] ? 'unique' : 'index') . ':' . implode(',', $index['columns']), $DB->get_indexes($table)));
        sort($indexes);
        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /**
     * The six WhatsApp tables exist after install.
     */
    public function test_the_whatsapp_tables_are_installed(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::TABLES as $table) {
            $this->assertTrue($dbman->table_exists($table), "Missing table {$table}");
        }
    }

    /**
     * The link keeps the BSUID as the key of the student (char 160, required), the phone id as optional and the consent
     * and opt-out fields.
     */
    public function test_the_link_table_has_the_bsuid_key_and_an_optional_wa_id(): void {
        $columns = self::columns('local_jedomi_whatsapp_links');
        $fields = ['userid', 'bsuid', 'waid', 'phone', 'confirmed', 'consentat', 'consentversion', 'consentcategories',
            'optedout', 'optoutat', 'lastinbound', 'lastused', 'lastproactivesent', 'timecreated'];
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $columns, "Missing field {$field}");
        }
        $this->assertSame(160, (int) $columns['bsuid']->max_length);
        $this->assertTrue((bool) $columns['bsuid']->not_null, 'The BSUID is required');
        $this->assertSame(20, (int) $columns['waid']->max_length);
        $this->assertFalse((bool) $columns['waid']->not_null, 'The phone id is optional');
        $this->assertFalse((bool) $columns['phone']->not_null, 'The phone is optional');
    }

    /**
     * Sessions, held actions and message limits key the chat by 'wa_' plus the BSUID, in a column of 170 characters.
     */
    public function test_the_chat_key_columns_fit_the_prefixed_bsuid(): void {
        foreach (['local_jedomi_whatsapp_sessions', 'local_jedomi_whatsapp_pending', 'local_jedomi_whatsapp_rate'] as $table) {
            $columns = self::columns($table);
            $this->assertSame(170, (int) $columns['chatid']->max_length, "chatid of {$table}");
        }
    }

    /**
     * The inbox keeps the Meta message id (char 128), the BSUID (char 160), the payload, the status and the attempts.
     */
    public function test_the_inbox_table_has_the_message_id_and_the_status(): void {
        $columns = self::columns('local_jedomi_whatsapp_inbox');
        foreach (['wamid', 'bsuid', 'payload', 'status', 'attempts', 'timecreated', 'timeprocessed'] as $field) {
            $this->assertArrayHasKey($field, $columns, "Missing field {$field}");
        }
        $this->assertSame(128, (int) $columns['wamid']->max_length);
        $this->assertSame(160, (int) $columns['bsuid']->max_length);
    }

    /**
     * A link keyed by the longest BSUID and without a phone id is stored; a second link with the same BSUID or the
     * same user is rejected by the unique keys.
     */
    public function test_a_link_with_the_longest_bsuid_and_no_wa_id_is_stored_once(): void {
        global $DB;
        $this->resetAfterTest();
        $bsuid = self::longest_bsuid();
        $this->assertSame(131, strlen($bsuid));
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $id = $DB->insert_record('local_jedomi_whatsapp_links', (object) ['userid' => $user->id, 'bsuid' => $bsuid,
            'timecreated' => time()]);

        $link = $DB->get_record('local_jedomi_whatsapp_links', ['id' => $id]);
        $this->assertSame($bsuid, $link->bsuid);
        $this->assertNull($link->waid);
        $this->assertSame(0, (int) $link->optedout);
        $this->assertSame(0, (int) $link->confirmed);

        $duplicates = 0;
        foreach ([['userid' => $other->id, 'bsuid' => $bsuid], ['userid' => $user->id, 'bsuid' => 'CO.2']] as $record) {
            try {
                $DB->insert_record('local_jedomi_whatsapp_links', (object) ($record + ['timecreated' => time()]));
            } catch (\dml_write_exception $e) {
                $duplicates++;
            }
        }
        $this->assertSame(2, $duplicates, 'One identity per account and one account per identity');
    }

    /**
     * The inbox rejects a second row for the same Meta message id (deduplication of retries).
     */
    public function test_the_inbox_rejects_a_repeated_message_id(): void {
        global $DB;
        $this->resetAfterTest();
        $wamid = 'wamid.' . str_repeat('X', 122);
        $row = ['wamid' => $wamid, 'bsuid' => self::longest_bsuid(), 'payload' => 'secret', 'status' => 'pending',
            'timecreated' => time()];
        $DB->insert_record('local_jedomi_whatsapp_inbox', (object) $row);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_jedomi_whatsapp_inbox', (object) $row);
    }

    /**
     * The usage counters are unique per user and month.
     */
    public function test_the_usage_is_unique_per_user_and_period(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $user->id, 'period' => 202611]);
        $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $user->id, 'period' => 202612]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $user->id, 'period' => 202611]);
    }

    /**
     * The conversation store and the rate limiter of the neutral core work on the WhatsApp tables with the chat key of
     * the longest BSUID, including the tool loop state of the conversation.
     */
    public function test_the_neutral_core_uses_the_whatsapp_tables(): void {
        global $DB;
        $this->resetAfterTest();
        $chatkey = whatsapp_link_service::chatkey(self::longest_bsuid());
        $this->assertSame('wa_' . self::longest_bsuid(), $chatkey);

        $store = new conversation_store('local_jedomi_whatsapp_sessions', 'whatsapp_max_turns');
        $store->append($chatkey, 'user', 'My grades please');
        $store->set_context('tool_loop_' . $chatkey, ['session_gen' => 1]);
        $this->assertSame([['role' => 'user', 'content' => 'My grades please']], $store->get($chatkey));
        $this->assertSame(['session_gen' => 1], $store->get_context('tool_loop_' . $chatkey));

        set_config('whatsapp_rate_limit', 1, 'local_jedomi');
        $limiter = new rate_limiter('local_jedomi_whatsapp_rate', 'whatsapp_rate_limit', 'whatsapp_cooldown', '[WHATSAPP RATE]');
        $this->assertFalse($limiter->check_and_record($chatkey));
        $this->assertTrue($limiter->check_and_record($chatkey));
        $this->assertTrue($DB->record_exists('local_jedomi_whatsapp_rate', ['chatid' => $chatkey]));

        $DB->insert_record('local_jedomi_whatsapp_pending', (object) ['callbackid' => random_string(16), 'chatid' => $chatkey,
            'actionjson' => '{"tool":"my_grades"}', 'courseid' => 0, 'timecreated' => time()]);
        $this->assertSame(1, $DB->count_records('local_jedomi_whatsapp_pending', ['chatid' => $chatkey]));
    }

    /**
     * The upgrade step from the previous version creates the six tables with the fields (type, length, NOT NULL and
     * DEFAULT), keys and indexes (with their uniqueness) of install.xml.
     */
    public function test_the_upgrade_step_creates_the_tables_as_install_xml(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/jedomi/db/upgrade.php');
        $dbman = $DB->get_manager();

        $xmldb = new \xmldb_file($CFG->dirroot . '/local/jedomi/db/install.xml');
        $this->assertTrue($xmldb->loadXMLStructure());
        $expected = [];
        foreach ($xmldb->getStructure()->getTables() as $table) {
            if (in_array($table->getName(), self::TABLES, true)) {
                $expected[$table->getName()] = $table;
            }
        }
        $this->assertSame(self::TABLES, array_keys($expected), 'install.xml defines the six tables in order');

        // The reference is the shape of the tables created from install.xml here, not the tables already in the test
        // database: the structure is not rolled back between tests, so it could come from an earlier run.
        foreach ($expected as $name => $table) {
            $dbman->drop_table(new \xmldb_table($name));
            $dbman->create_table($table);
        }
        $installed = array_combine(self::TABLES, array_map([self::class, 'shape'], self::TABLES));
        foreach (self::TABLES as $name) {
            $dbman->drop_table(new \xmldb_table($name));
        }
        set_config('version', self::UPGRADE_VERSION - 1, 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(self::UPGRADE_VERSION - 1));

        $this->assertSame((string) self::UPGRADE_VERSION, get_config('local_jedomi', 'version'));
        foreach ($expected as $name => $table) {
            $this->assertTrue($dbman->table_exists($name), "Upgrade did not create {$name}");
            $columns = self::columns($name);
            $this->assertSame(
                array_map(static fn(\xmldb_field $field): string => $field->getName(), $table->getFields()),
                array_keys($columns),
                "Fields of {$name}"
            );
            $types = [XMLDB_TYPE_INTEGER => ['I', 'R'], XMLDB_TYPE_CHAR => ['C'], XMLDB_TYPE_TEXT => ['X']];
            foreach ($table->getFields() as $field) {
                $column = $columns[$field->getName()];
                $label = "{$name}.{$field->getName()}";
                $this->assertContains($column->meta_type, $types[$field->getType()], "Type of {$label}");
                $this->assertSame((bool) $field->getNotNull(), (bool) $column->not_null, "NOT NULL of {$label}");
                if ($field->getType() === XMLDB_TYPE_CHAR) {
                    $this->assertSame((int) $field->getLength(), (int) $column->max_length, "Length of {$label}");
                }
                // Without a DEFAULT in install.xml the database may add an implicit one (empty text for a required char),
                // so only an explicit one is checked here; the shape comparison below covers both cases.
                if ($field->getDefault() !== null) {
                    $this->assertTrue((bool) $column->has_default, "DEFAULT of {$label}");
                    $this->assertSame((string) $field->getDefault(), (string) $column->default_value, "DEFAULT of {$label}");
                }
            }
            // Type, length of every column (integers included), NOT NULL, DEFAULT and the uniqueness of every index
            // match the tables created from install.xml.
            $this->assertSame($installed[$name], self::shape($name), "Shape of {$name}");
            $dbtable = new \xmldb_table($name);
            foreach ($table->getKeys() as $key) {
                if ($key->getType() === XMLDB_KEY_UNIQUE) {
                    $index = new \xmldb_index($key->getName(), XMLDB_INDEX_UNIQUE, $key->getFields());
                    $this->assertTrue($dbman->index_exists($dbtable, $index), "Unique key {$key->getName()} of {$name}");
                }
            }
            foreach ($table->getIndexes() as $index) {
                $this->assertTrue($dbman->index_exists($dbtable, $index), "Index {$index->getName()} of {$name}");
            }
        }
    }
}
