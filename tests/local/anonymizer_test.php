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
     * MDL-CTR-011: the text of an attached file that already holds placeholder-shaped tokens (an export anonymised
     * before) reaches the AI with distinct tokens, so the answer gives back the original tokens and never turns them
     * into the real data of another placeholder; the real data of the file is still anonymised and restored.
     *
     * @return void
     */
    public function test_placeholders_already_in_an_attachment_are_not_restored_into_real_data(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        // The sidebar sends the extracted text of the file inside the user message.
        $attachment = "Summarise it\n\nAttached file export.txt — 1 page\n\n"
            . "Row 1: [person_1] wrote to [email_1].\nRow 2: ximena.quintanilla@example.com is Ximena.";

        $messages = $anonymizer->anonymize_messages([
            ['role' => 'user', 'content' => 'Hello Quintanilla'],
            ['role' => 'user', 'content' => $attachment],
        ]);

        $sent = $messages[1]['content'];
        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $sent);
        $this->assertStringNotContainsString('Row 1: [person_1]', $sent, 'The token of the file is not a placeholder');
        $this->assertStringNotContainsString('[email_1].', $sent);
        preg_match('/Row 1: (\[literal_\d+\]) wrote to (\[literal_\d+\])/', $sent, $tokens);
        $this->assertCount(3, $tokens, $sent);

        // The AI quotes both rows: each token comes back as it was written.
        preg_match('/Row 2: (\[email_\d+\]) is (\[person_\d+\])/', $sent, $real);
        $answer = "Row 1: {$tokens[1]} wrote to {$tokens[2]}. Row 2: {$real[1]} is {$real[2]}.";
        $this->assertSame(
            'Row 1: [person_1] wrote to [email_1]. Row 2: ximena.quintanilla@example.com is Ximena.',
            $anonymizer->restore($answer)
        );
    }

    /**
     * MDL-CTR-011: data anonymised twice (the rows of a query and then the whole tool result) keeps its placeholders,
     * and a conversation continued with its placeholders keeps the ones of the AI, so the user always gets the real
     * values back.
     *
     * @return void
     */
    public function test_data_anonymised_twice_is_still_restored(): void {
        $this->resetAfterTest();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);

        $row = $anonymizer->anonymize_row(['firstname' => 'Ximena', 'email' => 'ximena.quintanilla@example.com']);
        $twice = $anonymizer->anonymize_tool_result(json_encode($row));
        $this->assertSame(json_encode($row), $twice);
        $this->assertStringContainsString('ximena.quintanilla@example.com', $anonymizer->restore($twice));

        $continued = anonymizer::from_map($anonymizer->export_map(), anonymizer::LEVEL_BASIC);
        $assistant = $continued->anonymize_messages([['role' => 'assistant', 'content' => "I found {$row['firstname']}."]]);
        $this->assertSame('I found Ximena.', $continued->restore($assistant[0]['content']));
    }

    /**
     * MDL-CTR-011: the help of the anonymisation level explains what the AI receives with each level, and the user never
     * sees a placeholder the AI altered instead of the real value.
     *
     * @return void
     */
    public function test_help_explains_the_levels_and_altered_placeholders_are_restored(): void {
        $this->resetAfterTest();
        $manager = get_string_manager();
        $english = $manager->get_string('anonymization_level_help', 'local_jedomi', null, 'en');
        $spanish = $manager->get_string('anonymization_level_help', 'local_jedomi', null, 'es');
        $this->assertStringNotContainsString('stored conversations', $english);
        $this->assertStringNotContainsString('conversaciones guardadas', $spanish);
        foreach (['Telegram', 'agent preview', 'Basic', 'Strict', 'Feedback only', 'real values'] as $part) {
            $this->assertStringContainsString($part, $english);
        }
        foreach (['Telegram', 'vista previa', 'Básico', 'Estricto', 'Solo comentarios', 'datos reales'] as $part) {
            $this->assertStringContainsString($part, $spanish);
        }

        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $sent = $anonymizer->anonymize_messages([['role' => 'user', 'content' => 'Write to ana.diaz@example.com']]);
        $this->assertStringContainsString('[email_1]', $sent[0]['content']);
        foreach (['[email_1]', '[Email 1]', '[EMAIL_1]', '[email-1]', '[ email_1 ]', '\\[email\\_1\\]'] as $altered) {
            $this->assertSame(
                'Sent to ana.diaz@example.com.',
                $anonymizer->restore("Sent to {$altered}."),
                "The altered placeholder {$altered} gets the real value back"
            );
        }
        $this->assertSame('Unknown [email_7].', $anonymizer->restore('Unknown [email_7].'), 'No value is invented');

        // A loose token that came from the user text is not taken for a placeholder: it comes back as written.
        $other = new anonymizer(anonymizer::LEVEL_BASIC);
        $sent = $other->anonymize_messages([['role' => 'user', 'content' => 'Mail ana.diaz@example.com about [Email 1]']]);
        $this->assertStringNotContainsString('[Email 1]', $sent[0]['content']);
        $this->assertSame('Mail ana.diaz@example.com about [Email 1]', $other->restore($sent[0]['content']));
    }
}
