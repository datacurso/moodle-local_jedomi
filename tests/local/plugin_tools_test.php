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

namespace local_jedomi\local;

use local_jedomi\fixtures\fake_plugin_tool;
use local_jedomi\fixtures\fake_tool_plugin;
use local_jedomi\hook\tool_registry;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/plugin_tools/fake_tool_plugin.php');

/**
 * Tests for the tools other plugins register in Jedomi (tool registry hook).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\plugin_tools
 * @covers     \local_jedomi\hook\tool_registry
 */
final class plugin_tools_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        fake_tool_plugin::reset();
        // Only the test plugin answers the hook, whatever is installed on the site.
        \core\di::set(\core\hook\manager::class, \core\hook\manager::phpunit_get_instance([
            'fake_toolplugin' => __DIR__ . '/../fixtures/plugin_tools/hooks.php',
        ]));
    }

    #[\Override]
    protected function tearDown(): void {
        fake_tool_plugin::reset();
        parent::tearDown();
    }

    /**
     * Names of a list of tools.
     *
     * @param array $tools Tools keyed by name.
     * @return string[]
     */
    private function names(array $tools): array {
        return array_keys($tools);
    }

    /**
     * Without any plugin tool the list is empty, so the native tool lists stay as they are.
     */
    public function test_no_registered_tool_gives_an_empty_list(): void {
        $this->assertSame([], plugin_tools::available(2, \context_system::instance(), 'web'));
    }

    /**
     * The hook carries the user, the context and the channel of the chat.
     */
    public function test_hook_carries_user_context_and_channel(): void {
        $context = \context_system::instance();
        plugin_tools::available(7, $context, 'web');

        $hook = fake_tool_plugin::$lasthook;
        $this->assertInstanceOf(tool_registry::class, $hook);
        $this->assertSame(7, $hook->get_userid());
        $this->assertSame($context, $hook->get_context());
        $this->assertSame('web', $hook->get_channel());
    }

    /**
     * A tool the plugin offers to the user joins the list; one it does not offer is left out.
     */
    public function test_only_tools_available_to_the_user_are_offered(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        fake_tool_plugin::$tools = [
            new fake_plugin_tool('demo_offered'),
            new fake_plugin_tool('demo_hidden', false),
            new fake_plugin_tool('demo_for_user', fn(int $userid): bool => $userid === (int) $user->id),
        ];

        $tools = plugin_tools::available((int) $user->id, \context_system::instance(), 'web');

        $this->assertSame(['demo_offered', 'demo_for_user'], $this->names($tools));
        $this->assertSame(['demo_offered'], $this->names(plugin_tools::available(2, \context_system::instance(), 'web')));
    }

    /**
     * A plugin can never take the name of a Jedomi tool, so it cannot replace or shadow it.
     */
    public function test_native_tool_names_are_refused(): void {
        fake_tool_plugin::$tools = [
            new fake_plugin_tool('execute_sql'),
            new fake_plugin_tool('ws_execute'),
            new fake_plugin_tool('send_messages'),
            new fake_plugin_tool('create_agents'),
        ];

        $this->assertSame([], plugin_tools::available(2, \context_system::instance(), 'web'));
        $this->assertDebuggingCalledCount(4);
    }

    /**
     * Every tool Jedomi itself offers to the highest role is a reserved name.
     */
    public function test_every_native_tool_is_reserved(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');
        $names = array_column(array_column(\local_jedomi_get_tool_definitions('admin'), 'function'), 'name');
        $names[] = \local_jedomi\local\service\message_service::tool_definition()['function']['name'];

        foreach ($names as $name) {
            $this->assertContains($name, plugin_tools::RESERVED_NAMES, "$name must be reserved");
        }
    }

    /**
     * Malformed names, a second tool with the same name and a definition naming another tool are refused.
     */
    public function test_invalid_duplicate_and_mismatched_tools_are_refused(): void {
        fake_tool_plugin::$tools = [
            new fake_plugin_tool('Bad Name'),
            new fake_plugin_tool(''),
            new fake_plugin_tool(str_repeat('a', 65)),
            new fake_plugin_tool('demo_once'),
            new fake_plugin_tool('demo_once'),
            new fake_plugin_tool('demo_alias', true, 'execute_sql'),
        ];

        $tools = plugin_tools::available(2, \context_system::instance(), 'web');

        $this->assertSame(['demo_once'], $this->names($tools));
        $this->assertDebuggingCalledCount(5);
    }

    /**
     * A tool that does not say to whom it is available is never offered.
     */
    public function test_tool_without_availability_check_is_ignored(): void {
        fake_tool_plugin::$tools = [new class implements \local_jedomi\agent\tool\agent_tool {
            #[\Override]
            public function get_name(): string {
                return 'demo_everyone';
            }
            #[\Override]
            public function get_definition(): array {
                return ['type' => 'function', 'function' => ['name' => 'demo_everyone', 'parameters' => []]];
            }
            #[\Override]
            public function execute(\stdClass $params): string {
                return '{}';
            }
        }];

        $this->assertSame([], plugin_tools::available(2, \context_system::instance(), 'web'));
        $this->assertDebuggingCalled();
    }

    /**
     * A tool whose availability check fails is not offered, and the chat keeps working.
     */
    public function test_failing_availability_check_hides_the_tool(): void {
        fake_tool_plugin::$tools = [
            new fake_plugin_tool('demo_broken', function (): bool {
                throw new \coding_exception('boom');
            }),
            new fake_plugin_tool('demo_fine'),
        ];

        $this->assertSame(['demo_fine'], $this->names(plugin_tools::available(2, \context_system::instance(), 'web')));
        $this->assertDebuggingCalledCount(1);
    }

    /**
     * The definitions sent to the AI are the tools' own, in order.
     */
    public function test_definitions_are_the_tool_definitions(): void {
        fake_tool_plugin::$tools = [new fake_plugin_tool('demo_one'), new fake_plugin_tool('demo_two')];

        $definitions = plugin_tools::definitions(plugin_tools::available(2, \context_system::instance(), 'web'));

        $this->assertSame(['demo_one', 'demo_two'], array_column(array_column($definitions, 'function'), 'name'));
        $this->assertSame('function', $definitions[0]['type']);
    }

    /**
     * A tool runs as the session user with the arguments of the AI as an object.
     */
    public function test_execute_runs_as_the_current_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = json_decode(plugin_tools::execute(new fake_plugin_tool('demo_echo'), ['q' => 'hello']), true);

        $this->assertSame((int) $user->id, $result['ran_as']);
        $this->assertSame(['q' => 'hello'], $result['params']);
    }

    /**
     * A tool that fails answers a controlled error with the message of a Moodle exception, never a stack trace.
     */
    public function test_failing_tool_answers_a_controlled_error(): void {
        $moodle = new fake_plugin_tool('demo_refused', true, null, new \moodle_exception('nopermissions', 'error', '', 'x'));
        $other = new fake_plugin_tool('demo_crash', true, null, new \RuntimeException('secret detail /var/www'));

        $refused = json_decode(plugin_tools::execute($moodle, []), true);
        $crash = json_decode(plugin_tools::execute($other, []), true);
        $this->assertDebuggingCalled();

        $this->assertSame('tool_failed', $refused['error']);
        $this->assertSame('demo_refused', $refused['tool']);
        $this->assertStringContainsString('x', $refused['detail']);
        $this->assertSame('tool_failed', $crash['error']);
        $this->assertArrayNotHasKey('detail', $crash);
    }

    /**
     * A result larger than the limit never reaches the AI service: the AI gets a controlled error instead.
     */
    public function test_oversized_result_is_replaced_by_an_error(): void {
        $tool = new fake_plugin_tool('demo_big');
        $big = str_repeat('x', plugin_tools::MAX_RESULT_BYTES + 1);

        $result = json_decode(plugin_tools::execute($tool, ['q' => $big]), true);

        $this->assertSame('tool_result_too_large', $result['error']);
        $this->assertSame('demo_big', $result['tool']);
        $this->assertDebuggingCalled();
    }

    /**
     * A tool only reads when it says so; a tool that does not say it, says no or fails to answer changes something.
     */
    public function test_only_a_tool_that_declares_it_is_read_only(): void {
        $undeclared = new class implements \local_jedomi\agent\tool\agent_tool {
            #[\Override]
            public function get_name(): string {
                return 'demo_undeclared';
            }
            #[\Override]
            public function get_definition(): array {
                return ['type' => 'function', 'function' => ['name' => 'demo_undeclared', 'parameters' => []]];
            }
            #[\Override]
            public function execute(\stdClass $params): string {
                return '{}';
            }
        };
        $broken = new class implements \local_jedomi\agent\tool\agent_tool {
            #[\Override]
            public function get_name(): string {
                return 'demo_broken';
            }
            #[\Override]
            public function get_definition(): array {
                return ['type' => 'function', 'function' => ['name' => 'demo_broken', 'parameters' => []]];
            }
            #[\Override]
            public function execute(\stdClass $params): string {
                return '{}';
            }
            /**
             * Fails to answer.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                throw new \coding_exception('boom');
            }
        };

        $this->assertTrue(plugin_tools::is_read_only(new fake_plugin_tool('demo_reader')));
        $this->assertFalse(plugin_tools::is_read_only(new fake_plugin_tool('demo_writer', true, null, null, false)));
        $this->assertFalse(plugin_tools::is_read_only($undeclared));
        $this->assertFalse(plugin_tools::is_read_only($broken));
        $this->assertDebuggingCalled();
    }
}
