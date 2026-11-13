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
 * Tool of the test plugin that registers tools in Jedomi.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\fixtures;

use local_jedomi\agent\tool\agent_tool;

/**
 * Tool of the test plugin: answers who ran it and with which arguments.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_plugin_tool implements agent_tool {
    /**
     * Create the tool.
     *
     * @param string $name Tool name.
     * @param bool|\Closure $available Whether it is offered, or fn(int $userid, \context $context): bool.
     * @param string|null $definitionname Name written in the definition, when it must differ from $name.
     * @param \Throwable|null $failure Exception execute() throws, if any.
     */
    public function __construct(
        /** @var string Tool name. */
        private string $name,
        /** @var bool|\Closure Availability. */
        private $available = true,
        /** @var string|null Name in the definition. */
        private ?string $definitionname = null,
        /** @var \Throwable|null Exception of execute(). */
        private ?\Throwable $failure = null
    ) {
    }

    #[\Override]
    public function get_name(): string {
        return $this->name;
    }

    #[\Override]
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->definitionname ?? $this->name,
                'description' => 'Test tool',
                'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => []],
            ],
        ];
    }

    /**
     * Whether the tool is offered to a user in a context.
     *
     * @param int $userid User id.
     * @param \context $context Context of the chat.
     * @return bool
     */
    public function is_available(int $userid, \context $context): bool {
        if ($this->available instanceof \Closure) {
            return ($this->available)($userid, $context);
        }
        return (bool) $this->available;
    }

    #[\Override]
    public function execute(\stdClass $params): string {
        global $USER;
        if ($this->failure) {
            throw $this->failure;
        }
        return json_encode(['ran_as' => (int) $USER->id, 'params' => $params]);
    }
}
