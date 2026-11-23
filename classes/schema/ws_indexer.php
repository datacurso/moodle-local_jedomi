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
 * Web Service index — compact descriptions for RAG-style search.
 *
 * Uses external_api::external_function_info() (same source as wsdoc) to extract
 * description, parameter structure, and return structure for each WS function.
 * Results are stored per-function in schema_cache (24 h TTL) with key prefix "ws_".
 *
 * Search scores functions by keyword relevance across name, description, and
 * parameter names so the AI can find the right WS to call without receiving a
 * massive paginated list.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\schema;

defined('MOODLE_INTERNAL') || die();

// Ensure Moodle external API infrastructure is loaded (same as wsdoc plugin).
require_once($CFG->dirroot . '/webservice/lib.php');

use core_external\external_api;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Indexes Moodle web service functions into compact, cached summaries
 * and provides keyword search and per-function description lookups.
 */
class ws_indexer {
    /**
     * Get compact info for one web service function.
     * Cached 24 h in schema_cache under key "ws_{wsname}".
     *
     * Returns array with keys: name, description, type, component, params, returns.
     * On failure returns ['error' => '...'].
     *
     * @param  string $wsname Function name (e.g. core_user_create_users).
     * @return array
     */
    public static function describe(string $wsname): array {
        global $DB;

        if (empty($wsname)) {
            return ['error' => 'wsname is required'];
        }

        $cache = \cache::make('local_jedomi', 'schema_cache');
        $cachekey = 'ws_' . $wsname;
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        $record = $DB->get_record(
            'external_functions',
            ['name' => $wsname]
        );
        if (!$record) {
            return ['error' => get_string('ws_function_not_found', 'local_jedomi', $wsname)];
        }

        try {
            $info = external_api::external_function_info($record);
        } catch (\Throwable $e) {
            return ['error' => get_string('ws_cannot_load_info', 'local_jedomi', \local_jedomi\local\safe_error::for_model($e))];
        }

        $result = self::build_compact_info($record, $info);
        $cache->set($cachekey, $result);
        return $result;
    }

    /**
     * Build compact metadata array from a full external_function_info object.
     *
     * @param  \stdClass $record Raw DB record from external_functions.
     * @param  \stdClass $info   Enriched info from external_api::external_function_info().
     * @return array
     */
    private static function build_compact_info(\stdClass $record, \stdClass $info): array {
        return [
            'name'        => $record->name,
            'description' => $info->description ?? '',
            'type'        => $info->type ?? 'unknown',
            'component'   => $record->component,
            'params'      => isset($info->parameters_desc)
                ? self::compact_params($info->parameters_desc)
                : '(none)',
            'returns'     => isset($info->returns_desc)
                ? self::compact_desc($info->returns_desc, 0)
                : 'void',
        ];
    }

    /**
     * Build (or refresh) the full WS index by caching compact info for every function.
     * Stores a meta key so we know the index is warm.
     */
    private static function build_index(): void {
        global $DB;

        @set_time_limit(0);

        $records = $DB->get_records('external_functions');
        if (empty($records)) {
            return;
        }

        $cache = \cache::make('local_jedomi', 'schema_cache');

        foreach ($records as $record) {
            $cachekey = 'ws_' . $record->name;
            try {
                $info = external_api::external_function_info($record);
                $cache->set($cachekey, self::build_compact_info($record, $info));
            } catch (\Throwable $e) {
                // Skip functions that fail to load; don't let one bad class break the index.
                continue;
            }
        }

        $cache->set('_ws_index_built_v2', time());
    }

    /**
     * Ensure the full index has been built within the last 24 hours.
     */
    private static function ensure_index(): void {
        $cache = \cache::make('local_jedomi', 'schema_cache');
        $built = $cache->get('_ws_index_built_v2');
        if ($built === false || (time() - $built) > DAYSECS) {
            self::build_index();
        }
    }

    /**
     * Search WS functions by keyword query, scored by relevance.
     *
     * The index is warmed automatically on first use (all function descriptions
     * are cached in schema_cache). Search scores across name, description,
     * parameters and return types so the AI can find functions even when the
     * query does not match the function name exactly.
     *
     * @param  string $query   Natural language or keyword query (e.g. "create user").
     * @param  array  $wsnames Allowlist of function names to search within.
     *                         Pass empty array to search over ALL functions (admin).
     * @param  int    $limit   Maximum results to return.
     * @return array           Top-N function info arrays, sorted by score descending.
     */
    public static function search(string $query, array $wsnames = [], int $limit = 8): array {
        if (empty($query)) {
            return [];
        }

        self::ensure_index();

        // Admin path: no allowlist => search over all functions.
        if (empty($wsnames)) {
            global $DB;
            $wsnames = $DB->get_fieldset_sql('SELECT name FROM {external_functions}');
        }

        if (empty($wsnames)) {
            return [];
        }

        $tokens = array_values(array_filter(
            array_map('strtolower', preg_split('/[\s_\-]+/', $query)),
            fn($t) => strlen($t) >= 2
        ));

        $scored = [];
        foreach ($wsnames as $wsname) {
            $info = self::describe($wsname);
            if (isset($info['error'])) {
                continue;
            }

            $score = 0;
            $namelower = strtolower($wsname);
            $desclower = strtolower($info['description'] ?? '');
            $paramslower = strtolower($info['params'] ?? '');
            $returnslower = strtolower($info['returns'] ?? '');

            foreach ($tokens as $token) {
                // Name segment match — highest weight.
                if (strpos($namelower, $token) !== false) {
                    $score += 10;
                    // Exact underscore-delimited segment bonus.
                    if (preg_match('/(^|_)' . preg_quote($token, '/') . '(_|$)/', $namelower)) {
                        $score += 6;
                    }
                }
                // Description word match.
                if (strpos($desclower, $token) !== false) {
                    $score += 4;
                    if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $desclower)) {
                        $score += 2;
                    }
                }
                // Parameter name / type match.
                if (strpos($paramslower, $token) !== false) {
                    $score += 2;
                }
                // Return type match.
                if (strpos($returnslower, $token) !== false) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'info' => $info];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        $top = array_slice($scored, 0, $limit);
        return array_map(fn($item) => $item['info'], $top);
    }

    /**
     * Compact top-level parameter structure: {param1*:type, param2?:type, ...}
     *
     * @param  external_single_structure $params
     * @return string
     */
    private static function compact_params(external_single_structure $params): string {
        $parts = [];
        foreach ($params->keys as $keyname => $keydesc) {
            $req = ($keydesc->required === VALUE_REQUIRED) ? '*' : '?';
            $parts[] = $keyname . $req . ':' . self::compact_desc($keydesc, 1);
        }
        return '{' . implode(', ', $parts) . '}';
    }

    /**
     * Recursively render an external_description as compact text.
     * Depth limit of 3 to avoid massive nested structures.
     * Depth 3 is enough to show array-of-object fields (e.g. users[] fields).
     *
     * Format examples:
     *   str, int, bool, float
     *   [{field1*:str, field2?:int}]   (array of objects)
     *   {field1*:str, ...}             (object, truncated at 15 fields)
     *
     * @param  external_description $desc
     * @param  int                  $depth Current nesting depth.
     * @return string
     */
    private static function compact_desc(external_description $desc, int $depth = 0): string {
        if ($desc instanceof external_multiple_structure) {
            return '[' . self::compact_desc($desc->content, $depth) . ']';
        }
        if ($desc instanceof external_single_structure) {
            if ($depth >= 3) {
                return '{...}';
            }
            $parts = [];
            $count = 0;
            $total = count($desc->keys);
            foreach ($desc->keys as $k => $v) {
                $req = ($v->required === VALUE_REQUIRED) ? '*' : '?';
                $parts[] = $k . $req . ':' . self::compact_desc($v, $depth + 1);
                $count++;
                if ($count >= 15 && $total > 15) {
                    $parts[] = '+' . ($total - 15) . ' more';
                    break;
                }
            }
            return '{' . implode(', ', $parts) . '}';
        }
        if ($desc instanceof external_value) {
            return self::value_type($desc);
        }
        return 'mixed';
    }

    /**
     * Map a PARAM_* constant to a short type name.
     *
     * @param  external_value $v
     * @return string
     */
    private static function value_type(external_value $v): string {
        switch ($v->type) {
            case PARAM_BOOL:
                return 'bool';
            case PARAM_INT:
                return 'int';
            case PARAM_FLOAT:
                return 'float';
            default:
                return 'str';
        }
    }
}
