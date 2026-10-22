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

/**
 * Course settings of the AI tutor: enable it, choose its behaviour, cap its questions and add instructions.
 *
 * The site administration only defines the template; per-activity rows of local_jedomi_stem_cfg are left untouched.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_jedomi\form\tutor_settings_form;
use local_jedomi\tutor\course_settings;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/jedomi:configuretutor', $context);

$url = new moodle_url('/local/jedomi/tutor_settings.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('tutor_settings_title', 'local_jedomi'));
$PAGE->set_heading(format_string($course->fullname));

$sitemode = (string) get_config('local_jedomi', 'stem_tutor_mode');
$template = [
    'mode' => in_array($sitemode, ['socratic', 'guided'], true) ? $sitemode : 'socratic',
    'maxq' => (int) get_config('local_jedomi', 'stem_max_questions') ?: 3,
];
$form = new tutor_settings_form($url, ['template' => $template]);
$form->set_data(['id' => $course->id] + course_settings::load($course->id));

if ($data = $form->get_data()) {
    course_settings::save($course->id, $data, (int) $USER->id);
    redirect($url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tutor_settings_title', 'local_jedomi'));
if ((int) get_config('local_jedomi', 'stem_enabled') !== 1) {
    echo $OUTPUT->notification(get_string('tutor_settings_site_disabled', 'local_jedomi'), 'warning');
}
echo html_writer::tag('p', get_string('tutor_settings_intro', 'local_jedomi'));
$form->display();
echo $OUTPUT->footer();
