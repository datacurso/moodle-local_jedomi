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

namespace local_jedomi\tutor;

/**
 * Reads and writes the course-level AI tutor settings (row cmid = 0 of local_jedomi_stem_cfg).
 *
 * Per-activity rows and any other column of the table are never touched here.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_settings {
    /** @var string[] Tutor modes a course can choose. */
    public const MODES = ['inherit', 'socratic', 'guided', 'assistant'];

    /** @var int Maximum length of the course instructions. */
    public const PROMPT_MAX = 2000;

    /** @var int Highest number of Socratic questions a course can set. */
    public const MAXQ_MAX = 10;

    /**
     * Site-wide tutor template defaults, used when the site has not saved its own value.
     *
     * @return array<string, int|string> Setting name => default value.
     */
    public static function site_defaults(): array {
        return [
            'stem_enabled' => 0,
            'stem_voice_enabled' => 0,
            'stem_tutor_mode' => 'socratic',
            'stem_max_questions' => 3,
            'stem_tutor_prompt' => get_string('stem_tutor_prompt_default', 'local_jedomi'),
        ];
    }

    /**
     * Stores the site defaults that are not set yet (install and upgrade).
     *
     * @return void
     */
    public static function seed_site_defaults(): void {
        foreach (self::site_defaults() as $name => $value) {
            if (get_config('local_jedomi', $name) === false) {
                set_config($name, $value, 'local_jedomi');
            }
        }
    }

    /**
     * Current settings of a course, with defaults when none were saved.
     *
     * @param int $courseid Course id.
     * @return array{enabled: int, tutor_mode: string, max_questions: int, tutor_prompt: string}
     */
    public static function load(int $courseid): array {
        global $DB;
        $row = $DB->get_record('local_jedomi_stem_cfg', ['courseid' => $courseid, 'cmid' => 0]);
        return [
            'enabled' => $row ? (int) $row->enabled : 0,
            'tutor_mode' => $row && in_array($row->tutor_mode, self::MODES, true) ? $row->tutor_mode : 'inherit',
            'max_questions' => $row ? (int) $row->max_questions : 0,
            'tutor_prompt' => $row ? (string) $row->tutor_prompt : '',
        ];
    }

    /**
     * Saves the settings of a course, normalising every value.
     *
     * @param int $courseid Course id.
     * @param \stdClass $data Submitted values: enabled, tutor_mode, max_questions, tutor_prompt.
     * @param int $userid User who saves.
     * @return int Id of the saved row.
     */
    public static function save(int $courseid, \stdClass $data, int $userid): int {
        global $DB;
        $record = (object) [
            'courseid' => $courseid,
            'cmid' => 0,
            'enabled' => empty($data->enabled) ? 0 : 1,
            'tutor_mode' => in_array($data->tutor_mode ?? '', self::MODES, true) ? $data->tutor_mode : 'inherit',
            'max_questions' => max(0, min(self::MAXQ_MAX, (int) ($data->max_questions ?? 0))),
            'tutor_prompt' => \core_text::substr(trim(strip_tags((string) ($data->tutor_prompt ?? ''))), 0, self::PROMPT_MAX),
            'usermodified' => $userid,
            'timemodified' => time(),
        ];
        $id = $DB->get_field('local_jedomi_stem_cfg', 'id', ['courseid' => $courseid, 'cmid' => 0]);
        if ($id) {
            $record->id = (int) $id;
            $DB->update_record('local_jedomi_stem_cfg', $record);
            return (int) $id;
        }
        return (int) $DB->insert_record('local_jedomi_stem_cfg', $record);
    }
}
