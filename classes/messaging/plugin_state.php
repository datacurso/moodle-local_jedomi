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
 * Whether Jedomi is enabled, as seen by the messaging channels.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Plugin state seam over local_jedomi_plugin_disabled_error() for the messaging channels (Telegram, WhatsApp).
 *
 * Channel code gets it from the container, \core\di::get(plugin_state::class), so a test can register another state
 * with \core\di::set() and run the channel without the Datacurso AI provider. The container is reset between tests.
 */
class plugin_state {
    /**
     * Error to answer when Jedomi is disabled in the Datacurso AI provider.
     *
     * @return array|null ['code' => 'plugindisabled', 'message' => ...], or null when Jedomi is enabled.
     */
    public function disabled_error(): ?array {
        return \local_jedomi_plugin_disabled_error();
    }

    /**
     * Whether Jedomi is enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return $this->disabled_error() === null;
    }
}
