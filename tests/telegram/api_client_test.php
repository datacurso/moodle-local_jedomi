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

namespace local_jedomi\telegram;

/**
 * Tests for the Telegram Bot API client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\api_client
 */
final class api_client_test extends \advanced_testcase {
    /**
     * The request body separates fields with a plain "&" even though Moodle sets arg_separator.output to "&amp;".
     * MDL-CTR-009: the body of the Telegram calls separates the fields correctly.
     *
     * Otherwise Telegram reads only the first field and rejects sendMessage with "message text is empty".
     */
    public function test_build_body_separates_fields_with_a_plain_ampersand(): void {
        $this->assertSame('&amp;', ini_get('arg_separator.output'));

        $body = api_client::build_body(['chat_id' => '7174568550', 'text' => 'Hi & bye', 'parse_mode' => 'HTML']);

        parse_str($body, $fields);
        $this->assertSame('chat_id=7174568550&text=Hi+%26+bye&parse_mode=HTML', $body);
        $this->assertSame(['chat_id' => '7174568550', 'text' => 'Hi & bye', 'parse_mode' => 'HTML'], $fields);
    }
}
