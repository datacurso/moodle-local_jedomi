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

namespace local_jedomi\agent\tool;

/**
 * Tests for the document_read tool when the document service is not configured.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\document_read_tool
 * @covers     \local_jedomi\proxy\handler
 */
final class document_read_tool_test extends \advanced_testcase {
    /**
     * Without a document service URL the agent tool answers that the service is not configured.
     *
     * There is no fallback to a local address.
     *
     * @return void
     */
    public function test_agent_tool_requires_a_configured_service(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('docservice_url', 'local_jedomi');

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => 5]), true);

        $this->assertSame('document service not configured', $result['error']);
    }

    /**
     * Without a document service URL the chat tool answers that the service is not configured.
     *
     * @return void
     */
    public function test_chat_tool_requires_a_configured_service(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('docservice_url', 'local_jedomi');

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => 5], 'admin'), true);

        $this->assertSame('document service not configured', $result['error']);
    }
}
