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

    /**
     * Store a rating.
     *
     * The answer excerpt is not stored when the anonymisation level is "feedback only".
     *
     * @param int $userid User who rates.
     * @param string $rating {@see RATING_GOOD} or {@see RATING_BAD}.
     * @param string $sessionid Browser chat session id.
     * @param string $messagetext Rated answer.
     * @return int New record id.
     * @throws \invalid_parameter_exception When the rating is unknown.
     */
    public static function record(int $userid, string $rating, string $sessionid, string $messagetext): int {
        global $DB;

        if (!in_array($rating, [self::RATING_GOOD, self::RATING_BAD], true)) {
            throw new \invalid_parameter_exception('Unknown rating');
        }

        $storetext = get_config('local_jedomi', 'anonymization_level') !== 'feedback_only';
        return (int) $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'rating' => $rating,
            'sessionid' => \core_text::substr($sessionid, 0, 64),
            'messagetext' => $storetext ? \core_text::substr(trim($messagetext), 0, self::MAX_TEXT_LENGTH) : '',
            'timecreated' => time(),
        ]);
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
