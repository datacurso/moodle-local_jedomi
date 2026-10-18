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

namespace local_jedomi\stem;

/**
 * Lógica compartida de propuestas de rúbrica: validación contra la definición activa, informe HTML saneado,
 * cálculo de la nota esperada y aplicación mediante mod_assign_external::save_grade.
 *
 * Política (QA r11 #2): por defecto la propuesta queda en estado "proposed" y la aplica un profesor desde
 * course_stem.php. Si la tarea tiene marking workflow, la estación puede dejar la nota "inreview" (no visible para el
 * alumno). Sólo con auto_release=1 en el curso (que requiere local/jedomi:autorelease para activarlo) se libera.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_proposal {

    public const MAX_REMARK = 2000;
    public const MAX_FEEDBACK = 60000;

    /**
     * Devuelve el controlador de rúbrica activo de la tarea o lanza excepción.
     *
     * @return \gradingform_rubric_controller
     */
    public static function rubric_controller(\stdClass $cm, \context_module $context) {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/grade/grading/form/rubric/lib.php');
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        if ($manager->get_active_method() !== 'rubric') {
            throw new \moodle_exception('stem_no_rubric', 'local_jedomi', '', $cm->id);
        }
        $controller = $manager->get_controller('rubric');
        if (!$controller->is_form_available()) {
            throw new \moodle_exception('stem_no_rubric', 'local_jedomi', '', $cm->id);
        }
        return $controller;
    }

    /**
     * Valida los criterios propuestos contra la definición: cada criterio de la rúbrica exactamente una vez,
     * levelid perteneciente a ese criterio, observación ≤ MAX_REMARK sin HTML.
     *
     * @param array $criteria [['criterionid'=>int,'levelid'=>int,'remark'=>string], ...]
     * @return array ['fillings' => [...], 'expected_grade' => float, 'definitionid' => int, 'summary' => [...]]
     */
    public static function validate_criteria(\gradingform_rubric_controller $controller, array $criteria, float $maxgrade): array {
        $definition = $controller->get_definition(true);
        $defcriteria = $definition->rubric_criteria ?? [];
        if (!$defcriteria) {
            throw new \moodle_exception('stem_no_rubric', 'local_jedomi');
        }
        $seen = [];
        $fillings = [];
        $summary = [];
        $sum = 0.0;
        $min = 0.0;
        $max = 0.0;
        foreach ($defcriteria as $cid => $crit) {
            $scores = array_map(fn($l) => (float) $l['score'], $crit['levels']);
            $min += min($scores);
            $max += max($scores);
        }
        foreach ($criteria as $row) {
            $cid = (int) ($row['criterionid'] ?? 0);
            $lid = (int) ($row['levelid'] ?? 0);
            if (!isset($defcriteria[$cid])) {
                throw new \moodle_exception('stem_bad_criterion', 'local_jedomi', '', $cid);
            }
            if (isset($seen[$cid])) {
                throw new \moodle_exception('stem_bad_criterion', 'local_jedomi', '', $cid);
            }
            if (!isset($defcriteria[$cid]['levels'][$lid])) {
                throw new \moodle_exception('stem_bad_level', 'local_jedomi', '', "$cid/$lid");
            }
            $seen[$cid] = true;
            $remark = \core_text::substr(trim(strip_tags((string) ($row['remark'] ?? ''))), 0, self::MAX_REMARK);
            $fillings[] = ['criterionid' => $cid, 'levelid' => $lid, 'remark' => $remark, 'remarkformat' => FORMAT_PLAIN];
            $level = $defcriteria[$cid]['levels'][$lid];
            $sum += (float) $level['score'];
            $summary[] = ['criterionid' => $cid, 'criterion' => strip_tags($defcriteria[$cid]['description']),
                          'levelid' => $lid, 'level' => strip_tags($level['definition']), 'score' => (float) $level['score'], 'remark' => $remark];
        }
        if (count($seen) !== count($defcriteria)) {
            throw new \moodle_exception('stem_missing_criteria', 'local_jedomi', '', count($defcriteria) - count($seen));
        }
        // Misma fórmula que gradingform_rubric_controller::calculate_grade (sin redondeo a entero del gradebook).
        $expected = $max > $min ? round(($sum - $min) / ($max - $min) * $maxgrade, 5) : 0.0;
        return ['fillings' => $fillings, 'expected_grade' => $expected, 'definitionid' => (int) $definition->id, 'summary' => $summary];
    }

    /** Sanea el informe HTML generado por la IA (HTMLPurifier de Moodle) y limita su tamaño. */
    public static function clean_report(string $html): string {
        $html = \core_text::substr($html, 0, self::MAX_FEEDBACK);
        return clean_text($html, FORMAT_HTML);
    }

    /**
     * Construye el informe final: cabecera con tabla de criterios + informe de la IA saneado + evidencias (enlaces
     * pluginfile a imágenes de eventos de ESTA sesión y usuario; nunca imágenes crudas incrustadas).
     */
    public static function build_feedback_html(array $summary, string $aireport, array $evidence, \stdClass $cm, int $userid, string $sessionid): string {
        global $DB;
        $o = \html_writer::tag('h4', get_string('stem_report_title', 'local_jedomi'));
        $rows = '';
        foreach ($summary as $s) {
            $rows .= \html_writer::tag('tr',
                \html_writer::tag('td', s($s['criterion'])) . \html_writer::tag('td', s($s['level'])) .
                \html_writer::tag('td', s(format_float($s['score'], 2))) . \html_writer::tag('td', s($s['remark'])));
        }
        $head = \html_writer::tag('tr', \html_writer::tag('th', get_string('stem_report_criterion', 'local_jedomi')) .
            \html_writer::tag('th', get_string('stem_report_level', 'local_jedomi')) .
            \html_writer::tag('th', get_string('stem_report_score', 'local_jedomi')) .
            \html_writer::tag('th', get_string('stem_report_remark', 'local_jedomi')));
        $o .= \html_writer::tag('table', $head . $rows, ['class' => 'generaltable', 'border' => '1']);
        $o .= \html_writer::tag('h5', get_string('stem_report_ai', 'local_jedomi'));
        $o .= \html_writer::div(self::clean_report($aireport), 'jedomi-stem-report');
        $items = '';
        $context = \context_module::instance($cm->id);
        foreach (array_slice($evidence, 0, 20) as $ev) {
            $eventid = (int) ($ev['eventid'] ?? 0);
            $caption = \core_text::substr(strip_tags((string) ($ev['caption'] ?? '')), 0, 300);
            if ($eventid <= 0) {
                continue;
            }
            $event = $DB->get_record('local_jedomi_assess_events', ['id' => $eventid, 'cmid' => $cm->id, 'userid' => $userid, 'sessionid' => $sessionid]);
            if (!$event) {
                throw new \moodle_exception('stem_bad_evidence', 'local_jedomi', '', $eventid);
            }
            $payload = json_decode($event->payload, true) ?: [];
            $line = s($caption);
            if (!empty($payload['image_file']) && $payload['image_file'] !== 'pending') {
                $url = \moodle_url::make_pluginfile_url($context->id, 'local_jedomi', 'stem_evidence', $eventid, '/', $payload['image_file']);
                $line .= ' ' . \html_writer::link($url, get_string('stem_report_photo', 'local_jedomi') . ' #' . $eventid, ['target' => '_blank']);
            } else if ($event->source === 'speech' && !empty($payload['transcript'])) {
                $line .= ' ' . \html_writer::tag('em', '«' . s(\core_text::substr((string) $payload['transcript'], 0, 400)) . '»');
            }
            $items .= \html_writer::tag('li', $line . ' ' . \html_writer::tag('small', userdate((int) $event->station_time), ['class' => 'text-muted']));
        }
        if ($items !== '') {
            $o .= \html_writer::tag('h5', get_string('stem_report_evidence', 'local_jedomi')) . \html_writer::tag('ul', $items);
        }
        $o .= \html_writer::tag('p', get_string('stem_report_footer', 'local_jedomi', (object) ['session' => s($sessionid)]), ['class' => 'small text-muted']);
        return $o;
    }

    /**
     * Aplica la propuesta a la tarea con mod_assign_external::save_grade y verifica la relectura.
     *
     * @param string $workflowstate '' | 'inreview' | 'released'
     * @return float nota leída tras aplicar
     */
    public static function apply(\stdClass $proposal, \stdClass $cm, string $workflowstate): float {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/externallib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $context = \context_module::instance($cm->id);
        $controller = self::rubric_controller($cm, $context);
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $validated = self::validate_criteria($controller, json_decode($proposal->criteria_json, true) ?: [], (float) $assign->grade);
        $fillings = [];
        foreach ($validated['fillings'] as $f) {
            $fillings[] = ['criterionid' => $f['criterionid'], 'fillings' => [$f]];
        }
        $plugindata = ['assignfeedbackcomments_editor' => ['text' => $proposal->feedback_html, 'format' => FORMAT_HTML]];
        \mod_assign_external::save_grade((int) $assign->id, (int) $proposal->userid, -1.0, -1, false,
            $workflowstate, false, $plugindata, ['rubric' => ['criteria' => $fillings]]);
        // Relectura.
        $assigninstance = new \assign($context, null, null);
        $grade = $assigninstance->get_user_grade((int) $proposal->userid, false);
        $got = $grade ? (float) $grade->grade : -1.0;
        if (abs($got - (float) $validated['expected_grade']) > 0.01) {
            error_log(sprintf('[local_jedomi] propose_rubric grade mismatch proposal=%d expected=%.3f got=%.3f', $proposal->id, $validated['expected_grade'], $got));
            throw new \moodle_exception('stem_grade_mismatch', 'local_jedomi');
        }
        return $got;
    }
}
