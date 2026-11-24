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

namespace local_jedomi;

/**
 * Tests for the risks declared by the capabilities of Jedomi (JEDOMI-SEC-012).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class capabilities_test extends \advanced_testcase {
    /**
     * Risks each capability must declare.
     *
     * @return array Capability name and expected risk bitmask, keyed by capability.
     */
    public static function capability_risk_provider(): array {
        return [
            // Agents run with the permissions of their creator: they read personal data, change data, send messages
            // and run site administration operations.
            'local/jedomi:manageagents' => ['local/jedomi:manageagents',
                RISK_CONFIG | RISK_DATALOSS | RISK_PERSONAL | RISK_SPAM],
            // The companion API runs database queries, web services and writes site configuration.
            'local/jedomi:companion_api' => ['local/jedomi:companion_api', RISK_CONFIG | RISK_DATALOSS | RISK_PERSONAL],
        ];
    }

    /**
     * JEDOMI-SEC-012: every capability declares the risks of what it allows.
     *
     * @dataProvider capability_risk_provider
     * @param string $capability Capability name.
     * @param int $risks Expected risk bitmask.
     */
    public function test_capabilities_declare_their_risks(string $capability, int $risks): void {
        $info = get_capability_info($capability);

        $this->assertNotEmpty($info, $capability);
        $this->assertSame($risks, (int) $info->riskbitmask, $capability);
    }
}
