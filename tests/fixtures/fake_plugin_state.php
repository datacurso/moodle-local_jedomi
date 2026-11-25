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
 * Test double for the Jedomi plugin state.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Plugin state fixed by the test, so channel code runs without the Datacurso AI provider.
 *
 * Register it with \core\di::set(plugin_state::class, fake_plugin_state::enabled()); the container is reset after
 * every test.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_plugin_state extends plugin_state {
    /** @var array|null Error returned by {@see disabled_error()}, or null when the plugin is enabled. */
    private ?array $error;

    /**
     * Fix the state to return.
     *
     * @param array|null $error ['code' => ..., 'message' => ...] when disabled, or null when enabled.
     */
    public function __construct(?array $error = null) {
        $this->error = $error;
    }

    /**
     * A state that reports Jedomi as enabled.
     *
     * @return self
     */
    public static function enabled(): self {
        return new self(null);
    }

    /**
     * A state that reports Jedomi as disabled with the given message.
     *
     * @param string $message Message of the disabled error.
     * @return self
     */
    public static function disabled(string $message): self {
        return new self(['code' => 'plugindisabled', 'message' => $message]);
    }

    #[\Override]
    public function disabled_error(): ?array {
        return $this->error;
    }
}
