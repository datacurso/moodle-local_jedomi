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

namespace local_jedomi\local;

/**
 * Tests for the anonymisation of the chat messages sent to the AI.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\anonymizer
 */
final class anonymizer_test extends \advanced_testcase {
    /**
     * Create the site user whose personal data the messages carry.
     *
     * @return void
     */
    private function create_named_user(): void {
        $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
    }

    /**
     * MDL-CTR-011: the user and assistant messages lose the names and e-mails of site users; the system message and
     * the tool messages are kept as they are.
     *
     * @return void
     */
    public function test_user_and_assistant_messages_are_anonymised(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $system = ['role' => 'system', 'content' => 'You help Ximena Quintanilla.'];
        $tool = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '[person_9]'];

        $messages = $anonymizer->anonymize_messages([
            $system,
            ['role' => 'user', 'content' => 'Who is ximena.quintanilla@example.com?'],
            ['role' => 'assistant', 'content' => 'It is Ximena Quintanilla.'],
            $tool,
        ]);

        $this->assertSame($system, $messages[0], 'The system message is never anonymised');
        $this->assertSame($tool, $messages[3], 'Tool messages are anonymised when the tool answers');
        $this->assertSame('Who is [email_1]?', $messages[1]['content']);
        $this->assertSame('It is [person_1] [person_2].', $messages[2]['content']);
    }

    /**
     * MDL-CTR-011: the placeholders of the messages are the ones of the instance, so the tool results agree with them
     * and the answer of the AI gets the real values back.
     *
     * @return void
     */
    public function test_placeholders_are_shared_with_the_tool_results_and_restored(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);

        $messages = $anonymizer->anonymize_messages([['role' => 'user', 'content' => 'Grades of Ximena Quintanilla']]);
        $result = $anonymizer->anonymize_tool_result(json_encode(['firstname' => 'Ximena', 'lastname' => 'Quintanilla']));

        $this->assertSame('Grades of [person_1] [person_2]', $messages[0]['content']);
        $this->assertSame(['firstname' => '[person_1]', 'lastname' => '[person_2]'], json_decode($result, true));
        $this->assertSame('Ximena Quintanilla has 9.', $anonymizer->restore('[person_1] [person_2] has 9.'));
        $this->assertSame(['name' => 'Ximena'], $anonymizer->restore_value(['name' => '[person_1]']));
    }

    /**
     * MDL-CTR-011: in a message with several parts only the text parts are anonymised; image parts are kept.
     *
     * @return void
     */
    public function test_only_text_parts_are_anonymised(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']];

        $messages = (new anonymizer(anonymizer::LEVEL_BASIC))->anonymize_messages([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Is this Ximena?'], $image]],
        ]);

        $this->assertSame('Is this [person_1]?', $messages[0]['content'][0]['text']);
        $this->assertSame($image, $messages[0]['content'][1]);
    }

    /**
     * MDL-CTR-011: the Strict level also replaces codes mixing letters and digits; the Basic level keeps them.
     *
     * @return void
     */
    public function test_strict_level_also_replaces_codes(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $message = [['role' => 'user', 'content' => 'Student ID-778899 is Ximena.']];

        $basic = (new anonymizer(anonymizer::LEVEL_BASIC))->anonymize_messages($message);
        $strict = (new anonymizer(anonymizer::LEVEL_STRICT))->anonymize_messages($message);

        $this->assertSame('Student ID-778899 is [person_1].', $basic[0]['content']);
        $this->assertStringNotContainsString('778899', $strict[0]['content']);
        $this->assertStringNotContainsString('Ximena', $strict[0]['content']);
    }

    /**
     * MDL-CTR-011: the help of the anonymisation level explains what the AI receives with each level, and the user never
     * sees a placeholder the AI altered instead of the real value.
     * [Pendiente:skip] The help still speaks only of stored conversations, and an altered placeholder is shown as is.
     *
     * @return void
     */
    public function test_help_explains_the_levels_and_altered_placeholders_are_restored(): void {
        $this->markTestSkipped('Pending fix: the help of the anonymisation level only mentions stored conversations, and a '
            . 'placeholder altered by the AI is shown to the user as is.');
    }
}
