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

namespace local_jedomi\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\local\service\feedback_service;

/**
 * Stores the current user's rating of a Jedomi answer (local_jedomi_save_feedback).
 *
 * Called from the sidebar through core/ajax, which checks the session key.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_feedback extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'rating' => new external_value(PARAM_ALPHA, 'Rating: good or bad'),
            'sessionid' => new external_value(PARAM_ALPHANUMEXT, 'Browser chat session id', VALUE_DEFAULT, ''),
            'messagetext' => new external_value(PARAM_RAW, 'Rated answer', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Store the rating for the current user.
     *
     * @param string $rating Rating: good or bad.
     * @param string $sessionid Browser chat session id.
     * @param string $messagetext Rated answer.
     * @return array
     */
    public static function execute(string $rating, string $sessionid = '', string $messagetext = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'rating' => $rating,
            'sessionid' => $sessionid,
            'messagetext' => $messagetext,
        ]);

        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        if (isguestuser() || !isloggedin()) {
            throw new \require_login_exception('Guests cannot rate answers');
        }

        feedback_service::record((int) $USER->id, $params['rating'], $params['sessionid'], $params['messagetext']);
        return ['saved' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether the rating was stored'),
        ]);
    }
}
