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

namespace local_jedomi\local\service;

/**
 * Stores and removes the ratings users give to Jedomi answers.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_service {
    /** @var string Table name. */
    public const TABLE = 'local_jedomi_feedback';

    /** @var string Rating: the answer helped. */
    public const RATING_GOOD = 'good';

    /** @var string Rating: the answer needs improvement. */
    public const RATING_BAD = 'bad';

    /** @var int Longest stored answer excerpt, in characters. */
    public const MAX_TEXT_LENGTH = 1000;

    /** @var int Longest stored comment, in characters. */
    public const MAX_COMMENT_LENGTH = 1000;

    /**
     * Store the rating of an answer, or change it.
     *
     * Each answer keeps a single rating per user: rating it again, with the same or the other value, or adding a
     * comment updates that rating instead of adding another one. Ratings without a message id (older clients) are
     * always added. The answer excerpt is anonymised with the level of the Privacy tab (with "Feedback only" it is not
     * stored); the comment keeps the user's words with e-mails and names of site users replaced.
     *
     * @param int $userid User who rates.
     * @param string $rating {@see RATING_GOOD} or {@see RATING_BAD}.
     * @param string $sessionid Browser chat session id.
     * @param string $messagetext Rated answer.
     * @param string $messageid Id the browser gives to the rated answer; empty when unknown.
     * @param string|null $comment Optional comment; null keeps the stored one, '' removes it.
     * @return int Id of the stored rating.
     * @throws \invalid_parameter_exception When the rating is unknown.
     */
    public static function record(
        int $userid,
        string $rating,
        string $sessionid,
        string $messagetext,
        string $messageid = '',
        ?string $comment = null
    ): int {
        global $DB;

        if (!in_array($rating, [self::RATING_GOOD, self::RATING_BAD], true)) {
            throw new \invalid_parameter_exception('Unknown rating');
        }

        // The anonymisation level of the Privacy tab applies to the stored answer: "Feedback only" keeps no text,
        // Basic replaces names and e-mails, Strict replaces identifiers and free text as well.
        $anonymizer = new \local_jedomi\local\anonymizer();
        $text = \core_text::substr(trim($messagetext), 0, self::MAX_TEXT_LENGTH);
        $text = \core_text::substr($anonymizer->anonymize_stored_text($text), 0, self::MAX_TEXT_LENGTH);
        $messageid = \core_text::substr(trim($messageid), 0, 64);
        $now = time();

        $record = (object) [
            'userid' => $userid,
            'rating' => $rating,
            'sessionid' => \core_text::substr($sessionid, 0, 64),
            'messagetext' => $text,
            'timemodified' => $now,
        ];
        if ($comment !== null) {
            // Only an answer that needs improvement carries a comment.
            $comment = $rating === self::RATING_BAD ? \core_text::substr(trim($comment), 0, self::MAX_COMMENT_LENGTH) : '';
            // The comment is the user's own text: it stays readable, with e-mails and names of site users replaced.
            $record->feedbackcomment = $comment === '' ? null : $anonymizer->anonymize_text($comment);
        } else if ($rating === self::RATING_GOOD) {
            $record->feedbackcomment = null;
        }

        $existing = $messageid === '' ? false : $DB->get_record(
            self::TABLE,
            ['userid' => $userid, 'messageid' => $messageid],
            'id',
            IGNORE_MULTIPLE
        );
        if ($existing) {
            $record->id = (int) $existing->id;
            $DB->update_record(self::TABLE, $record);
            return $record->id;
        }

        $record->messageid = $messageid === '' ? null : $messageid;
        $record->timecreated = $now;
        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Ratings of a user, oldest first.
     *
     * @param int $userid User id.
     * @return \stdClass[]
     */
    public static function get_for_user(int $userid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC, id ASC'));
    }

    /**
     * Whether a user has ratings.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function user_has_feedback(int $userid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Delete the ratings of some users.
     *
     * @param int[] $userids User ids.
     */
    public static function delete_for_users(array $userids): void {
        global $DB;
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "userid {$insql}", $params);
    }
}
