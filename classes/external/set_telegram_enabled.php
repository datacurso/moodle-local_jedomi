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

/**
 * Enables or disables the Telegram integration (local_jedomi_set_telegram_enabled).
 *
 * Called from the "Integrations" tab of the Jedomi settings page through core/ajax
 * when the "Enable Telegram integration" switch is toggled, so the state is stored
 * without submitting the whole form. The form submit keeps saving the same setting.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_telegram_enabled extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enabled' => new external_value(PARAM_BOOL, 'Whether the Telegram integration is enabled'),
        ]);
    }

    /**
     * Store the switch state and return the stored one.
     *
     * @param bool $enabled Whether the Telegram integration is enabled.
     * @return array
     */
    public static function execute(bool $enabled): array {
        $params = self::validate_parameters(self::execute_parameters(), ['enabled' => $enabled]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        set_config('telegram_enabled', $params['enabled'] ? 1 : 0, 'local_jedomi');

        return ['enabled' => (bool) get_config('local_jedomi', 'telegram_enabled')];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Stored state of the Telegram integration'),
        ]);
    }
}
