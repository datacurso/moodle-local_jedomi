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
 * External function: search courses for the course-availability chip selector.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Searches courses by shortname or fullname (admin-only, max 20 results).
 */
class search_courses extends external_api {
    /**
     * Declare input parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search term'),
        ]);
    }

    /**
     * Execute the search.
     *
     * @param string $query
     * @return array
     */
    public static function execute(string $query): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['query' => $query]
        );
        $query = trim($params['query']);

        // Require site:config capability (admin only).
        $context = \core\context\system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        if ($query === '') {
            return ['courses' => []];
        }

        $like1 = $DB->sql_like('c.shortname', ':q1', false);
        $like2 = $DB->sql_like('c.fullname', ':q2', false);

        $sql = "SELECT c.id, c.shortname, c.fullname, cc.name AS categoryname
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE ({$like1} OR {$like2})
                   AND c.id != :siteid
              ORDER BY c.shortname ASC";

        $term    = '%' . $DB->sql_like_escape($query) . '%';
        $records = $DB->get_records_sql($sql, [
            'q1'     => $term,
            'q2'     => $term,
            'siteid' => SITEID,
        ], 0, 20);

        $courses = [];
        foreach ($records as $r) {
            $courses[] = [
                'id'           => (int) $r->id,
                'shortname'    => $r->shortname,
                'fullname'     => $r->fullname,
                'categoryname' => $r->categoryname,
            ];
        }

        return ['courses' => $courses];
    }

    /**
     * Declare return structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT, 'Course ID'),
                    'shortname'    => new external_value(PARAM_TEXT, 'Short name'),
                    'fullname'     => new external_value(PARAM_TEXT, 'Full name'),
                    'categoryname' => new external_value(PARAM_TEXT, 'Category name'),
                ])
            ),
        ]);
    }
}
