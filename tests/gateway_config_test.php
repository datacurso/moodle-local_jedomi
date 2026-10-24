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

namespace local_jedomi;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The gateway settings have no built-in URL or key: without both nothing calls the gateway (fail closed).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gateway_config::class)]
final class gateway_config_test extends \advanced_testcase {
    /**
     * With URL and key the settings come trimmed, with the default model when none is set.
     */
    public function test_configured(): void {
        $this->resetAfterTest(true);
        set_config('gateway_url', ' https://gateway.example/ ', 'local_jedomi');
        set_config('api_key', 'test-key', 'local_jedomi');
        set_config('model', '', 'local_jedomi');

        $gateway = gateway_config::require();

        $this->assertSame('https://gateway.example', $gateway->url);
        $this->assertSame('test-key', $gateway->key);
        $this->assertSame(gateway_config::DEFAULT_MODEL, $gateway->model);
        set_config('model', 'other-model', 'local_jedomi');
        $this->assertSame('other-model', gateway_config::get()->model);
    }

    /**
     * Without the key, or without the URL, there are no settings and require() fails with a clear error.
     */
    public function test_not_configured(): void {
        $this->resetAfterTest(true);
        set_config('gateway_url', 'https://gateway.example', 'local_jedomi');
        set_config('api_key', '', 'local_jedomi');
        $this->assertNull(gateway_config::get());
        set_config('api_key', 'test-key', 'local_jedomi');
        unset_config('gateway_url', 'local_jedomi');
        $this->assertNull(gateway_config::get());

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('gateway_notconfigured', 'local_jedomi'));
        gateway_config::require();
    }

    /**
     * The chat proxy refuses to call the AI without the settings, instead of sending a placeholder key.
     */
    public function test_proxy_fails_closed(): void {
        $this->resetAfterTest(true);
        unset_config('gateway_url', 'local_jedomi');
        unset_config('api_key', 'local_jedomi');
        $call = new \ReflectionMethod(\local_jedomi\proxy\handler::class, 'call_ai_api_buffer');

        $result = $call->invoke(null, 'any-model', [['role' => 'user', 'content' => 'Hi']], [], []);

        $this->assertSame(['type' => 'error', 'error' => 'ai_api_error'], $result);
    }
}
