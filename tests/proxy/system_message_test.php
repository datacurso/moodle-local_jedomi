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

namespace local_jedomi\proxy;

use local_jedomi\local\service\message_service;

/**
 * Tests for the rules the system message and tool descriptions give the model about actions that change data.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\system_message
 * @covers     \local_jedomi\local\service\message_service
 */
final class system_message_test extends \advanced_testcase {
    /**
     * MDL-UNIT-046: questions and analysis must never end in an action that changes data, such as sending a message.
     */
    public function test_system_message_limits_actions_to_explicit_requests(): void {
        $this->resetAfterTest();

        $content = system_message::build('admin', [])['content'];

        $this->assertStringContainsString('Only call create_agent, delete_agent, admin_execute', $content);
        $this->assertStringContainsString('when the user explicitly asks for that action', $content);
        $this->assertStringContainsString('Never call send_messages to answer a question or analyse data', $content);
    }

    /**
     * MDL-UNIT-046: the send_messages description limits it to explicit requests to send or notify.
     */
    public function test_send_messages_description_limits_it_to_explicit_requests(): void {
        $this->resetAfterTest();

        $description = message_service::tool_definition()['function']['description'];

        $this->assertStringContainsString('Only when the user explicitly asks to send or notify a message', $description);
    }

    /**
     * MDL-UNIT-046: the document reading instructions are given only while file processing is on.
     */
    public function test_document_read_instructions_follow_the_files_switch(): void {
        $this->resetAfterTest();

        $content = system_message::build('admin', [])['content'];
        $this->assertStringContainsString('document_read(cmid)', $content);
        $this->assertStringNotContainsString('base64', $content);

        set_config('files_enabled', 0, 'local_jedomi');
        $this->assertStringNotContainsString('document_read', system_message::build('admin', [])['content']);
    }

    /**
     * V8: in voice mode the instructions ask for short spoken answers in full sentences, without tables, lists, code or
     * markdown, offering the details on screen; without it they do not.
     */
    public function test_voice_mode_asks_for_spoken_answers(): void {
        $this->resetAfterTest();

        $voice = system_message::build('admin', [], [], true)['content'];
        $text = system_message::build('admin', [])['content'];

        $this->assertStringContainsString('VOICE CONVERSATION:', $voice);
        foreach (['read aloud', 'short', 'complete sentences', 'tables', 'code', 'markdown', 'on screen'] as $rule) {
            $this->assertStringContainsString($rule, $voice, $rule);
        }
        $this->assertStringNotContainsString('VOICE CONVERSATION:', $text);
        $this->assertStringContainsString('ACTIONS THAT CHANGE DATA:', $voice, 'The voice mode keeps every other rule');
    }

    /**
     * MDL-UNIT-047: the agent and preview instructions describe how actions that change data are really handled in those channels.
     * [Pendiente:skip]
     */
    public function test_agent_and_preview_instructions_match_their_channel(): void {
        $this->markTestSkipped('Pending fix: agent and preview instructions say actions wait for a confirmation'
            . ' that does not happen there.');
    }
}
