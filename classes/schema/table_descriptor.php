<?php
/**
 * DB Schema introspection via INFORMATION_SCHEMA.
 *
 * Provides column metadata, foreign keys, and index information
 * for any Moodle database table. Results are cached 24h in schema_cache.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\schema;

defined('MOODLE_INTERNAL') || die();

class table_descriptor {

    /**
     * Describe a single table: columns, foreign keys, indices.
     * Cached 24h in schema_cache.
     *
     * @param  string $tablename The name of the table to describe.
     * @return array Columns, foreign_keys, and indices metadata.
     */
    public static function describe(string $tablename): array {
        global $DB, $CFG;

        if (empty($tablename)) {
            return ['error' => 'Table not found: ' . $tablename];
        }

        $cache = \cache::make('local_jedomi', 'schema_cache');
        $key = 'desc_' . $tablename;

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        // --- Columns via Moodle's cross-DB API (works on MySQL, MariaDB, PostgreSQL) ---
        // Strip Moodle braces ({course} → course) — get_columns() adds prefix internally.
        $lookup = $tablename;
        if (preg_match('/^\{(\w+)\}$/', $lookup, $m)) {
            $lookup = $m[1];
        }

        $columns = [];
        $dbcolumns = $DB->get_columns($lookup);
        if (empty($dbcolumns)) {
            return ['error' => 'Table not found: ' . $tablename];
        }
        foreach ($dbcolumns as $name => $col) {
            $columns[$name] = [
                'type'     => $col->meta_type,
                'dbtype'   => $col->type,
                'max_length' => $col->max_length,
                'nullable' => !$col->not_null,
                'default'  => $col->default_value,
                'key'      => $col->primary_key ? 'PRI' : ($col->unique ? 'UNI' : null),
                'auto'     => $col->auto_increment,
            ];
        }

        // --- Foreign keys & indices via INFORMATION_SCHEMA (MySQL/MariaDB only) ---
        // Fallback gracefully on PostgreSQL — FK/index info is non-critical.
        $foreign_keys = [];
        $indices      = [];

        if (in_array($CFG->dbtype, ['mysqli', 'mariadb', 'auroramysql'])) {
            $dbname = $CFG->dbname;
            // INFORMATION_SCHEMA uses the full physical table name WITH prefix.
            $prefixed = $CFG->prefix . $lookup;

            try {
                // Foreign keys.
                $fks_rs = $DB->get_recordset_sql(
                    "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
                       FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                       JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                            ON tc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                           AND tc.TABLE_SCHEMA      = kcu.TABLE_SCHEMA
                           AND tc.TABLE_NAME        = kcu.TABLE_NAME
                      WHERE kcu.TABLE_SCHEMA           = ?
                        AND kcu.TABLE_NAME             = ?
                        AND tc.CONSTRAINT_TYPE         = 'FOREIGN KEY'
                        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
                    [$dbname, $prefixed]
                );
                foreach ($fks_rs as $fk) {
                    $foreign_keys[] = [
                        'column'            => $fk->column_name,
                        'references_table'  => $fk->referenced_table_name,
                        'references_column' => $fk->referenced_column_name,
                    ];
                }
                $fks_rs->close();

                // Indices.
                $idx_rs = $DB->get_recordset_sql(
                    "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
                       FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME   = ?
                      ORDER BY INDEX_NAME, SEQ_IN_INDEX",
                    [$dbname, $prefixed]
                );
                foreach ($idx_rs as $idx) {
                    $name = $idx->index_name;
                    if (!isset($indices[$name])) {
                        $indices[$name] = [
                            'columns' => [],
                            'unique'  => !((bool) $idx->non_unique),
                        ];
                    }
                    $indices[$name]['columns'][] = $idx->column_name;
                }
                $idx_rs->close();
            } catch (\Exception $e) {
                // INFORMATION_SCHEMA not available — FK/index info is optional.
            }
        }

        $result = [
            'tablename'    => $tablename,
            'columns'      => $columns,
            'foreign_keys' => $foreign_keys,
            'indices'      => $indices,
        ];

        $cache->set($key, $result);

        return $result;
    }

    /**
     * Search tables by name pattern (LIKE %pattern%).
     * Cached 24h in schema_cache.
     *
     * @param  string $pattern Table name pattern to search for.
     * @return array Matching table names with total count.
     */
    public static function search_tables(string $pattern): array {
        global $DB, $CFG;

        if (empty($pattern)) {
            return ['tables' => [], 'total' => 0];
        }

        $cache = \cache::make('local_jedomi', 'schema_cache');
        $key = 'search_' . $pattern;

        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        // Use Moodle's cross-DB API: get all tables and filter by pattern.
        $prefix = $CFG->prefix;
        $alltables = $DB->get_tables();
        $like = $DB->sql_like_escape($pattern);

        $tables = [];
        foreach ($alltables as $fullname => $unused) {
            // Strip prefix for display.
            $displayname = (strpos($fullname, $prefix) === 0)
                ? substr($fullname, strlen($prefix))
                : $fullname;

            // Match against the display name (without prefix).
            if (stripos($displayname, $pattern) !== false) {
                $tables[] = $displayname;
            }
        }
        sort($tables);

        $result = [
            'tables' => $tables,
            'total'  => count($tables),
        ];

        $cache->set($key, $result);

        return $result;
    }

    /**
     * Purge the entire schema_cache.
     *
     * @return bool True on success, false if cache store unavailable.
     */
    public static function clear_cache(): bool {
        try {
            $cache = \cache::make('local_jedomi', 'schema_cache');
            $cache->purge();
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }
}
