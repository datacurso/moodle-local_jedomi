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

use aiprovider_datacurso\local\plugin_settings;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\local\service\pending_action_service;

/**
 * Confirms or cancels an action the web chat is holding for the current user (local_jedomi_resolve_action).
 *
 * Called from the sidebar through core/ajax, which checks the session key. Only the owner can
 * resolve an action, once, within its expiry; it runs with the role the user has now.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolve_action extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Pending action id'),
            'confirm' => new external_value(PARAM_BOOL, 'True to run the action, false to cancel it'),
            'keyword' => new external_value(
                PARAM_TEXT,
                'Confirmation keyword typed by the user (critical actions)',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Confirm or cancel the action.
     *
     * @param int $id Pending action id.
     * @param bool $confirm True to run it, false to cancel it.
     * @param string $keyword Confirmation keyword typed by the user, required to run a critical action.
     * @return array
     */
    public static function execute(int $id, bool $confirm, string $keyword = ''): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['id' => $id, 'confirm' => $confirm, 'keyword' => $keyword]
        );

        plugin_settings::require_enabled('local_jedomi');

        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        if (isguestuser() || !isloggedin()) {
            throw new \require_login_exception('Guests cannot resolve actions');
        }

        return pending_action_service::resolve(
            (int) $params['id'],
            (int) $USER->id,
            (bool) $params['confirm'],
            (string) $params['keyword']
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'executed, cancelled or failed'),
            'message' => new external_value(PARAM_TEXT, 'Short result for the user'),
            'sent' => new external_value(PARAM_INT, 'Messages sent (send_messages only)'),
            'failed' => new external_value(PARAM_INT, 'Messages not sent (send_messages only)'),
        ]);
    }
}
