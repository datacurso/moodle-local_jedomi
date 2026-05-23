<?php
/**
 * Table index — semantic search over install.xml definitions.
 *
 * Scans all plugin db/install.xml files to build a searchable index
 * of table names and their COMMENT descriptions (both table and field level).
 * Search scores by keyword relevance so the AI can find the right table
 * without guessing exact table names.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\schema;

defined('MOODLE_INTERNAL') || die();

class table_indexer {

    /**
     * Build the full table index from all install.xml files.
     *
     * @return array Table info indexed by table name: {name, comment, fields[], field_comments[]}
     */
    public static function build_index(): array {
        $index = [];

        $xmlfiles = self::find_install_xml_files();
        foreach ($xmlfiles as $xmlpath) {
            $tables = self::parse_install_xml($xmlpath);
            foreach ($tables as $tablename => $info) {
                if (!isset($index[$tablename])) {
                    $index[$tablename] = $info;
                }
            }
        }

        return $index;
    }

    /**
     * Find all db/install.xml files across core + all installed plugins.
     *
     * @return array Absolute paths to install.xml files.
     */
    private static function find_install_xml_files(): array {
        global $CFG;
        $files = [];

        // Core tables.
        $corefile = $CFG->libdir . '/db/install.xml';
        if (file_exists($corefile)) {
            $files[] = $corefile;
        }

        // All plugin types.
        $types = \core_component::get_plugin_types();
        foreach ($types as $type => $typedir) {
            $plugins = \core_component::get_plugin_list($type);
            foreach ($plugins as $name => $plugindir) {
                $xmlfile = $plugindir . '/db/install.xml';
                if (file_exists($xmlfile)) {
                    $files[] = $xmlfile;
                }
            }
        }

        return $files;
    }

    /**
     * Parse a single install.xml file and extract table metadata.
     *
     * @param  string $xmlpath Absolute path to install.xml.
     * @return array           Table info: {tablename => {name, comment, fields, field_comments}}.
     */
    private static function parse_install_xml(string $xmlpath): array {
        $tables = [];

        $xml = simplexml_load_file($xmlpath);
        if (!$xml) {
            return $tables;
        }

        foreach ($xml->TABLES->TABLE ?? [] as $table) {
            $tablename = (string)$table['NAME'];
            if (empty($tablename)) {
                continue;
            }

            $tabledata = [
                'name'    => $tablename,
                'comment' => (string)($table['COMMENT'] ?? ''),
            ];

            $fields = [];
            $fieldComments = [];

            foreach ($table->FIELDS->FIELD ?? [] as $field) {
                $fieldname = (string)$field['NAME'];
                if (empty($fieldname)) {
                    continue;
                }
                $fields[] = $fieldname;

                $fcomment = (string)($field['COMMENT'] ?? '');
                if ($fcomment !== '') {
                    $fieldComments[$fieldname] = $fcomment;
                }
            }

            $tabledata['fields'] = $fields;
            $tabledata['field_comments'] = $fieldComments;
            $tables[$tablename] = $tabledata;
        }

        return $tables;
    }

    /**
     * Search tables by keyword intent across names, comments, and field definitions.
     *
     * Results include both the install.xml metadata AND current database structure
     * (via table_descriptor::describe) so the AI gets the full picture.
     *
     * @param  string $query Natural language or keyword query.
     * @param  int    $limit Maximum results.
     * @return array         Top-N table info arrays, sorted by relevance score.
     */
    public static function search(string $query, int $limit = 8): array {
        global $DB;

        if (empty(trim($query))) {
            return [];
        }

        $cache = \cache::make('local_jedomi', 'schema_cache');
        $cachekey = 'tbl_idx_' . md5($query . '|' . $limit);
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $index = self::build_index();
        if (empty($index)) {
            return [];
        }

        $tokens = array_values(array_filter(
            array_map('strtolower', preg_split('/[\s_\-]+/', $query)),
            fn($t) => strlen($t) >= 2
        ));

        if (empty($tokens)) {
            return [];
        }

        $scored = [];
        foreach ($index as $tablename => $info) {
            $score = 0;
            $namelower = strtolower($tablename);
            $commentlower = strtolower($info['comment'] ?? '');
            $fieldstext = strtolower(implode(' ', $info['fields'] ?? []));
            $fieldcommentsText = strtolower(implode(' ', $info['field_comments'] ?? []));

            foreach ($tokens as $token) {
                // Table name match — highest weight.
                if (strpos($namelower, $token) !== false) {
                    $score += 10;
                    if (preg_match('/(^|_)' . preg_quote($token, '/') . '(_|$)/', $namelower)) {
                        $score += 6;
                    }
                }
                // Table comment match.
                if (strpos($commentlower, $token) !== false) {
                    $score += 4;
                    if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $commentlower)) {
                        $score += 2;
                    }
                }
                // Field name match.
                if (strpos($fieldstext, $token) !== false) {
                    $score += 2;
                }
                // Field comment match.
                if (strpos($fieldcommentsText, $token) !== false) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'info'  => $info,
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        $top = array_slice($scored, 0, $limit);

        $result = array_map(fn($item) => $item['info'], $top);

        $cache->set($cachekey, $result);

        return $result;
    }
}
