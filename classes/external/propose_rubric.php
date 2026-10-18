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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\stem\rubric_proposal;
use local_jedomi\stem\station_auth;

/**
 * La estación propone niveles de rúbrica + informe HTML para un alumno.
 *
 * Resultado según la configuración del curso:
 *  - auto_release=0 (defecto): se guarda como propuesta ('proposed'); si la tarea usa marking workflow, además se
 *    escribe la nota en estado 'inreview' (oculta al alumno) y el estado pasa a 'inreview'. El profesor aplica/libera
 *    desde course_stem.php.
 *  - auto_release=1: se aplica y se libera ('released') directamente. Activarlo requiere local/jedomi:autorelease.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class propose_rubric extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'          => new external_value(PARAM_INT, 'cmid de la tarea'),
            'userid'        => new external_value(PARAM_INT, 'id del alumno'),
            'sessionid'     => new external_value(PARAM_ALPHANUMEXT, 'id de sesión de la estación'),
            'criteria_json' => new external_value(PARAM_RAW, 'JSON [{criterionid, levelid, remark}] — un elemento por criterio'),
            'report_html'   => new external_value(PARAM_RAW, 'Informe HTML generado por la IA (se sanea en servidor)'),
            'evidence_json' => new external_value(PARAM_RAW, 'JSON [{eventid, caption}] de eventos de esta sesión', VALUE_DEFAULT, '[]'),
            'nonce'         => new external_value(PARAM_ALPHANUMEXT, 'nonce único 32–64 chars'),
            'timestamp'     => new external_value(PARAM_INT, 'epoch de la estación'),
            'signature'     => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex sobre criteria_json+"\\n"+report_html+"\\n"+evidence_json'),
        ]);
    }

    public static function execute(int $cmid, int $userid, string $sessionid, string $criteria_json, string $report_html,
                                   string $evidence_json, string $nonce, int $timestamp, string $signature): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact('cmid', 'userid', 'sessionid', 'criteria_json',
            'report_html', 'evidence_json', 'nonce', 'timestamp', 'signature'));
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/jedomi:ingestassessment', $syscontext);

        $body = $p['criteria_json'] . "\n" . $p['report_html'] . "\n" . $p['evidence_json'];
        station_auth::verify('propose', $p['cmid'], $p['userid'], $p['sessionid'], $p['nonce'], $p['timestamp'], $body, $p['signature']);
        [$cm, $course, $context, $cfg] = station_auth::resolve_target($p['cmid'], $p['userid']);

        $criteria = json_decode($p['criteria_json'], true);
        $evidence = json_decode($p['evidence_json'], true);
        if (!is_array($criteria) || !$criteria || !is_array($evidence)) {
            throw new \moodle_exception('stem_bad_payload', 'local_jedomi');
        }
        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, grade, markingworkflow', MUST_EXIST);
        if ((float) $assign->grade <= 0) {
            throw new \moodle_exception('stem_no_rubric', 'local_jedomi', '', $cm->id);
        }
        $controller = rubric_proposal::rubric_controller($cm, $context);
        $validated = rubric_proposal::validate_criteria($controller, $criteria, (float) $assign->grade);
        $feedback = rubric_proposal::build_feedback_html($validated['summary'], $p['report_html'], $evidence, $cm, $p['userid'], $p['sessionid']);

        // auto_release es una decisión de curso (fila cmid=0), nunca de la estación.
        $coursecfg = \local_jedomi_stem_cfg((int) $course->id, 0);
        $autorelease = $coursecfg && (int) ($coursecfg->auto_release ?? 0) === 1;

        $proposal = (object) [
            'courseid' => (int) $course->id, 'cmid' => (int) $cm->id, 'userid' => $p['userid'], 'sessionid' => $p['sessionid'],
            'criteria_json' => json_encode($validated['fillings'], JSON_UNESCAPED_UNICODE), 'feedback_html' => $feedback,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE), 'expected_grade' => $validated['expected_grade'],
            'status' => 'proposed', 'nonce' => $p['nonce'], 'proposedby' => (int) $USER->id, 'appliedby' => 0,
            'timecreated' => time(), 'timeapplied' => 0,
        ];
        try {
            $proposal->id = $DB->insert_record('local_jedomi_assess_proposals', $proposal);
        } catch (\dml_write_exception $e) {
            throw new \moodle_exception('stem_replay', 'local_jedomi');
        }

        $applied = -1.0;
        if ($autorelease) {
            $applied = rubric_proposal::apply($proposal, $cm, $assign->markingworkflow ? 'released' : '');
            $proposal->status = 'released';
            $proposal->appliedby = (int) $USER->id;
            $proposal->timeapplied = time();
            $DB->update_record('local_jedomi_assess_proposals', $proposal);
            error_log(sprintf('[local_jedomi] propose_rubric AUTO-RELEASE course=%d cmid=%d user=%d proposal=%d grade=%.3f',
                $course->id, $cm->id, $p['userid'], $proposal->id, $applied));
        } else if ((int) $assign->markingworkflow === 1) {
            $applied = rubric_proposal::apply($proposal, $cm, 'inreview');
            $proposal->status = 'inreview';
            $DB->update_record('local_jedomi_assess_proposals', $proposal);
        }
        return [
            'proposalid'     => (int) $proposal->id,
            'status'         => $proposal->status,
            'expected_grade' => (float) $validated['expected_grade'],
            'applied_grade'  => (float) $applied,
            'feedback_html'  => $feedback,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'proposalid'     => new external_value(PARAM_INT, 'id de la propuesta'),
            'status'         => new external_value(PARAM_ALPHA, 'proposed|inreview|released'),
            'expected_grade' => new external_value(PARAM_FLOAT, 'nota calculada de la rúbrica'),
            'applied_grade'  => new external_value(PARAM_FLOAT, 'nota aplicada en mod_assign (-1 si no se aplicó aún)'),
            'feedback_html'  => new external_value(PARAM_RAW, 'informe final saneado'),
        ]);
    }
}
