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
 * A test plugin that registers tools in Jedomi through the tool registry hook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\fixtures;

use local_jedomi\agent\tool\agent_tool;
use local_jedomi\hook\tool_registry;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fake_plugin_tool.php');

/**
 * Hook callback of the test plugin: registers the tools a test prepared.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_tool_plugin {
    /** @var agent_tool[] Tools the next dispatch registers. */
    public static array $tools = [];

    /** @var tool_registry|null Last hook received. */
    public static ?tool_registry $lasthook = null;

    /**
     * Callback of the tool registry hook.
     *
     * @param tool_registry $hook The hook.
     * @return void
     */
    public static function register(tool_registry $hook): void {
        self::$lasthook = $hook;
        foreach (self::$tools as $tool) {
            $hook->add_tool($tool);
        }
    }

    /**
     * Forget the tools and the last hook.
     *
     * @return void
     */
    public static function reset(): void {
        self::$tools = [];
        self::$lasthook = null;
        fake_plugin_tool::$runs = [];
    }
}
