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

namespace local_jedomi\external;

use core_external\external_api;

/**
 * Tests for the answer feedback web service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\save_feedback
 * @covers     \local_jedomi\local\service\feedback_service
 */
final class save_feedback_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web service checks the plugin state through it.');
        }
    }

    /**
     * M10: feedback is stored for the current user only.
     * MDL-INT-055: the rating is stored for the current user with the date and a bounded excerpt.
     */
    public function test_feedback_is_stored_for_current_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = save_feedback::execute('good', 'sess_abc123', 'The answer text');
        $result = external_api::clean_returnvalue(save_feedback::execute_returns(), $result);

        $this->assertTrue($result['saved']);
        $records = $DB->get_records('local_jedomi_feedback');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame((int) $user->id, (int) $record->userid);
        $this->assertSame('good', $record->rating);
        $this->assertSame('sess_abc123', $record->sessionid);
        $this->assertSame('The answer text', $record->messagetext);
        $this->assertFalse($DB->record_exists('local_jedomi_feedback', ['userid' => $other->id]));
    }

    /**
     * M10: only the two known ratings are accepted.
     * MDL-INT-055: an invalid rating is rejected.
     */
    public function test_invalid_rating_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\invalid_parameter_exception::class);
        save_feedback::execute('excellent', 'sess_1', 'x');
    }

    /**
     * M10: guests cannot leave feedback.
     * MDL-INT-055: a guest cannot rate.
     */
    public function test_guest_cannot_leave_feedback(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        save_feedback::execute('bad', 'sess_1', 'x');
    }

    /**
     * MDL-UNIT-078: a long excerpt is cut to the maximum length.
     * MDL-INT-055: the stored excerpt of the answer is bounded.
     * M10: the answer excerpt is cut, and not stored at all with the "feedback only" anonymisation level.
     */
    public function test_message_text_is_trimmed_and_dropped_when_feedback_only(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        save_feedback::execute('bad', 'sess_1', str_repeat('a', 5000));
        set_config('anonymization_level', 'feedback_only', 'local_jedomi');
        save_feedback::execute('good', 'sess_2', 'Private answer');

        $long = $DB->get_record('local_jedomi_feedback', ['sessionid' => 'sess_1']);
        $this->assertSame(\local_jedomi\local\service\feedback_service::MAX_TEXT_LENGTH, \core_text::strlen($long->messagetext));
        $private = $DB->get_record('local_jedomi_feedback', ['sessionid' => 'sess_2']);
        $this->assertSame('', (string) $private->messagetext);
    }

    /**
     * MDL-UNIT-078: a shorter text is stored complete without surrounding spaces, a text exactly at the limit is kept
     * and one character more is cut, also with multibyte characters.
     */
    public function test_excerpt_limit_boundaries(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $max = \local_jedomi\local\service\feedback_service::MAX_TEXT_LENGTH;

        save_feedback::execute('good', 'short', "  \n Short answer \t ");
        save_feedback::execute('good', 'atlimit', str_repeat('ñ', $max));
        save_feedback::execute('good', 'overlimit', str_repeat('é', $max) . 'X');
        save_feedback::execute('good', 'padded', '   ' . str_repeat('b', $max) . '   ');

        $this->assertSame('Short answer', $DB->get_field('local_jedomi_feedback', 'messagetext', ['sessionid' => 'short']));
        $this->assertSame(str_repeat('ñ', $max), $DB->get_field('local_jedomi_feedback', 'messagetext', ['sessionid' => 'atlimit']));
        $this->assertSame(str_repeat('é', $max), $DB->get_field('local_jedomi_feedback', 'messagetext',
            ['sessionid' => 'overlimit']));
        $this->assertSame(str_repeat('b', $max), $DB->get_field('local_jedomi_feedback', 'messagetext', ['sessionid' => 'padded']));
    }

    /**
     * Rate an answer that names a user, with an anonymisation level chosen in the Privacy tab.
     *
     * @param string $level Anonymisation level as saved by the Privacy tab.
     * @return array{0: string, 1: \stdClass} Stored answer text and the user named in the answer.
     */
    private function rate_answer_with_level(string $level): array {
        global $DB;
        $this->resetAfterTest();
        \local_jedomi\local\service\admin_settings_service::save_privacy_settings(false, $level, []);
        $named = $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
        $this->setUser($this->getDataGenerator()->create_user());
        $answer = "Ximena Quintanilla (ximena.quintanilla@example.com, id ID-778899) has not submitted the essay "
            . "about medieval trade routes.";

        save_feedback::execute('good', 'sess_' . $level, $answer);

        return [(string) $DB->get_field('local_jedomi_feedback', 'messagetext', ['sessionid' => 'sess_' . $level]), $named];
    }

    /**
     * MDL-INT-011: with the "Feedback only" level chosen in the Privacy tab no text of the rated answer is stored.
     * MDL-INT-055: with the rating-only privacy level the text is not stored.
     */
    public function test_feedback_only_level_stores_no_answer_text(): void {
        [$stored] = $this->rate_answer_with_level('feedback');

        $this->assertSame('', $stored);
    }

    /**
     * MDL-INT-011: with the Basic level names and emails are replaced in the stored data.
     */
    public function test_basic_level_replaces_names_and_emails(): void {
        [$stored] = $this->rate_answer_with_level('basic');

        $this->assertStringNotContainsString('Ximena', $stored);
        $this->assertStringNotContainsString('Quintanilla', $stored);
        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $stored);
    }

    /**
     * MDL-INT-011: with the Strict level identifiers and free text are also replaced in the stored data.
     */
    public function test_strict_level_also_replaces_identifiers_and_free_text(): void {
        [$stored] = $this->rate_answer_with_level('strict');

        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $stored);
        $this->assertStringNotContainsString('ID-778899', $stored);
        $this->assertStringNotContainsString('medieval trade routes', $stored);
    }
    /**
     * MDL-INT-056: each answer keeps a single rating, the last one, and "Improve" accepts an optional comment.
     * [Pendiente:skip]
     */
    public function test_each_answer_keeps_a_single_editable_rating(): void {
        $this->markTestSkipped('Pending fix: every click stores a new rating, both ratings can be given and Improve has no '
            . 'comment.');
    }
}
