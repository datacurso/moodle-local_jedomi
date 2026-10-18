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

/**
 * Configuración del Tutor STEM a nivel de curso y de actividad (profesor editor / gestor).
 *
 * La administración del sitio sólo define la PLANTILLA (defaults de estación, prompt base, cámaras recomendadas,
 * tapete). Aquí el profesor decide: activar el tutor en el curso, modo (heredar/socrático/guiado/desactivado),
 * tope de preguntas, instrucciones propias, y qué tareas con rúbrica se evalúan en la estación física.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$course   = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/jedomi:configuretutor', $context);

$PAGE->set_url(new moodle_url('/local/jedomi/course_stem.php', ['id' => $course->id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('course_stem_title', 'local_jedomi'));
$PAGE->set_heading(format_string($course->fullname));

$modes = ['inherit', 'socratic', 'guided', 'assistant'];

// Tareas con rúbrica activa del curso.
$assigns = $DB->get_records_sql("
    SELECT cm.id AS cmid, a.name, ctx.id AS contextid,
           (SELECT ga.activemethod FROM {grading_areas} ga WHERE ga.contextid = ctx.id AND ga.component = 'mod_assign' AND ga.areaname = 'submissions') AS method
      FROM {course_modules} cm
      JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      JOIN {assign} a ON a.id = cm.instance
      JOIN {context} ctx ON ctx.contextlevel = :ctxmod AND ctx.instanceid = cm.id
     WHERE cm.course = :courseid AND cm.deletioninprogress = 0
  ORDER BY a.name", ['courseid' => $course->id, 'ctxmod' => CONTEXT_MODULE]);

if (optional_param('save', 0, PARAM_INT)) {
    require_sesskey();
    $now = time();
    $upsert = function (int $cmid, array $data) use ($DB, $course, $now, $USER) {
        $row = $DB->get_record('local_jedomi_stem_cfg', ['courseid' => $course->id, 'cmid' => $cmid]);
        $data = (object) array_merge(['courseid' => $course->id, 'cmid' => $cmid, 'usermodified' => $USER->id, 'timemodified' => $now], $data);
        if ($row) {
            $data->id = $row->id;
            $DB->update_record('local_jedomi_stem_cfg', $data);
        } else {
            $DB->insert_record('local_jedomi_stem_cfg', $data);
        }
    };
    $mode = optional_param('tutor_mode', 'inherit', PARAM_ALPHAEXT);
    $upsert(0, [
        'enabled'       => optional_param('enabled', 0, PARAM_INT) ? 1 : 0,
        'tutor_mode'    => in_array($mode, $modes, true) ? $mode : 'inherit',
        'max_questions' => max(0, min(10, optional_param('max_questions', 0, PARAM_INT))),
        'tutor_prompt'  => core_text::substr(trim(strip_tags(optional_param('tutor_prompt', '', PARAM_TEXT))), 0, 2000),
    ]);
    $selected = optional_param_array('stem_assign', [], PARAM_INT);
    foreach ($assigns as $a) {
        $on = in_array((int) $a->cmid, array_map('intval', $selected), true) ? 1 : 0;
        $exists = $DB->record_exists('local_jedomi_stem_cfg', ['courseid' => $course->id, 'cmid' => (int) $a->cmid]);
        if ($on || $exists) {
            $upsert((int) $a->cmid, ['enabled' => $on, 'tutor_mode' => 'inherit', 'max_questions' => 0, 'tutor_prompt' => null]);
        }
    }
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$cfg = $DB->get_record('local_jedomi_stem_cfg', ['courseid' => $course->id, 'cmid' => 0]) ?: (object) ['enabled' => 0, 'tutor_mode' => 'inherit', 'max_questions' => 0, 'tutor_prompt' => ''];
$activecms = $DB->get_fieldset_select('local_jedomi_stem_cfg', 'cmid', 'courseid = :c AND cmid > 0 AND enabled = 1', ['c' => $course->id]);
$siteenabled = (int) get_config('local_jedomi', 'stem_enabled') === 1;
$template = ['mode' => get_config('local_jedomi', 'stem_tutor_mode') ?: 'socratic', 'maxq' => (int) get_config('local_jedomi', 'stem_max_questions') ?: 3];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('course_stem_title', 'local_jedomi'));
if (!$siteenabled) {
    echo $OUTPUT->notification(get_string('course_stem_site_disabled', 'local_jedomi'), 'warning');
}
echo html_writer::tag('p', get_string('course_stem_intro', 'local_jedomi'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);

echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('course_stem_tutor_heading', 'local_jedomi'));
echo html_writer::start_div('form-check form-switch mb-3');
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'class' => 'form-check-input', 'id' => 'stem-enabled', 'name' => 'enabled', 'value' => 1] + ($cfg->enabled ? ['checked' => 'checked'] : []));
echo html_writer::tag('label', get_string('course_stem_enabled', 'local_jedomi'), ['class' => 'form-check-label', 'for' => 'stem-enabled']);
echo html_writer::tag('div', get_string('course_stem_enabled_help', 'local_jedomi'), ['class' => 'form-text']);
echo html_writer::end_div();
echo html_writer::tag('label', get_string('course_stem_mode', 'local_jedomi'), ['for' => 'stem-mode', 'class' => 'form-label']);
$opts = [];
foreach ($modes as $m) {
    $label = $m === 'inherit' ? get_string('course_stem_mode_inherit', 'local_jedomi', get_string('stem_tutor_mode_' . ($template['mode'] === 'assessment_only' ? 'assessment_only' : $template['mode']), 'local_jedomi'))
                              : ($m === 'assistant' ? get_string('course_stem_mode_assistant', 'local_jedomi') : get_string('stem_tutor_mode_' . $m, 'local_jedomi'));
    $opts[$m] = $label;
}
echo html_writer::select($opts, 'tutor_mode', $cfg->tutor_mode, false, ['id' => 'stem-mode', 'class' => 'form-control mb-3', 'style' => 'max-width:480px']);
echo html_writer::tag('label', get_string('course_stem_maxq', 'local_jedomi', $template['maxq']), ['for' => 'stem-maxq', 'class' => 'form-label']);
echo html_writer::empty_tag('input', ['type' => 'number', 'min' => 0, 'max' => 10, 'id' => 'stem-maxq', 'name' => 'max_questions', 'value' => (int) $cfg->max_questions, 'class' => 'form-control mb-3', 'style' => 'max-width:160px']);
echo html_writer::tag('label', get_string('course_stem_prompt', 'local_jedomi'), ['for' => 'stem-prompt', 'class' => 'form-label']);
echo html_writer::tag('textarea', s((string) $cfg->tutor_prompt), ['id' => 'stem-prompt', 'name' => 'tutor_prompt', 'rows' => 5, 'maxlength' => 2000, 'class' => 'form-control']);
echo html_writer::tag('div', get_string('course_stem_prompt_help', 'local_jedomi'), ['class' => 'form-text']);
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('course_stem_assign_heading', 'local_jedomi'));
echo html_writer::tag('p', get_string('course_stem_assign_help', 'local_jedomi'), ['class' => 'form-text']);
if (!$assigns) {
    echo $OUTPUT->notification(get_string('course_stem_no_assign', 'local_jedomi'), 'info');
}
foreach ($assigns as $a) {
    $hasrubric = ($a->method === 'rubric');
    $id = 'stem-assign-' . $a->cmid;
    echo html_writer::start_div('form-check');
    $attrs = ['type' => 'checkbox', 'class' => 'form-check-input', 'id' => $id, 'name' => 'stem_assign[]', 'value' => (int) $a->cmid];
    if (in_array((int) $a->cmid, array_map('intval', $activecms), true)) { $attrs['checked'] = 'checked'; }
    if (!$hasrubric) { $attrs['disabled'] = 'disabled'; }
    echo html_writer::empty_tag('input', $attrs);
    $badge = $hasrubric ? html_writer::tag('span', get_string('course_stem_badge_rubric', 'local_jedomi'), ['class' => 'badge badge-success bg-success ms-2'])
                        : html_writer::tag('span', get_string('course_stem_badge_norubric', 'local_jedomi'), ['class' => 'badge badge-secondary bg-secondary ms-2']);
    echo html_writer::tag('label', format_string($a->name) . ' ' . html_writer::tag('small', "(cmid {$a->cmid})", ['class' => 'text-muted']) . $badge, ['class' => 'form-check-label', 'for' => $id]);
    echo html_writer::end_div();
}
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::start_div('card mb-3'); echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('stem_mat_heading', 'local_jedomi'));
echo html_writer::tag('p', get_string('course_stem_mat_help', 'local_jedomi'), ['class' => 'form-text']);
foreach (['tapete_A4_1hoja.pdf' => 'A4 · 1 hoja', 'tapete_2xA4.pdf' => 'A4 · 2 hojas (400×200 mm)', 'tapete_Carta_1hoja.pdf' => 'Carta · 1 hoja', 'tapete_2xCarta.pdf' => 'Carta · 2 hojas (400×200 mm)'] as $file => $label) {
    echo html_writer::link(new moodle_url('/local/jedomi/stem/tapete.php', ['file' => $file]), '<i class="fa fa-file-pdf-o"></i> ' . $label, ['class' => 'btn btn-outline-secondary btn-sm me-2 mb-2']);
}
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::tag('button', '<i class="fa fa-save"></i> ' . get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
