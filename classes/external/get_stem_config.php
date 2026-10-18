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

/**
 * Entrega a la estación física la configuración del Modo Evaluación Física STEM (pestaña "Estación STEM").
 *
 * Única fuente de verdad para los scripts Python. Requiere moodle/site:config (token de administrador de la
 * estación). Incluye el secreto HMAC de la estación: por eso NO se expone por descarga anónima ni al navegador.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_stem_config extends external_api {

    /** Claves de configuración (sin el prefijo stem_) y su tipo PARAM para la salida. */
    public const KEYS = [
        'enabled' => PARAM_INT, 'osc_host' => PARAM_HOST, 'osc_port' => PARAM_INT,
        'osc_reply_port' => PARAM_INT, 'preset_face' => PARAM_INT, 'preset_table' => PARAM_INT, 'camera_index' => PARAM_INT,
        'camera_width' => PARAM_INT, 'camera_height' => PARAM_INT, 'mic_match' => PARAM_TEXT, 'mat_width_mm' => PARAM_INT,
        'mat_height_mm' => PARAM_INT, 'marker_mm' => PARAM_INT, 'margin_mm' => PARAM_INT, 'aruco_dict' => PARAM_ALPHANUMEXT,
        'whisper_model' => PARAM_ALPHANUMEXT, 'whisper_prompt' => PARAM_TEXT, 'vision_model' => PARAM_TEXT,
        'tutor_mode' => PARAM_ALPHAEXT, 'max_questions' => PARAM_INT, 'tutor_prompt' => PARAM_TEXT, 'station_secret' => PARAM_ALPHANUMEXT,
    ];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'cmid de la tarea a evaluar (0 = sólo plantilla)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $cmid = 0): array {
        global $CFG, $DB;
        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $out = [];
        foreach (self::KEYS as $key => $type) {
            $val = get_config('local_jedomi', 'stem_' . $key);
            $out[$key] = $type === PARAM_INT ? (int) $val : (string) $val;
        }
        // Datos derivados de la tarea indicada: assign, curso, rúbrica activa y configuración del curso/actividad.
        $out['assign_cmid'] = $cmid; $out['assign_id'] = 0; $out['course_id'] = 0; $out['rubric_definition_id'] = 0;
        $out['course_enabled'] = 0; $out['course_tutor_mode'] = ''; $out['course_max_questions'] = 0; $out['course_tutor_prompt'] = '';
        if ($cmid > 0) {
            $cm = $DB->get_record_sql(
                "SELECT cm.id, cm.instance, cm.course FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = 'assign' WHERE cm.id = :id",
                ['id' => $cmid]);
            if (!$cm) {
                throw new \moodle_exception('invalidcoursemodule');
            }
            $out['assign_id'] = (int) $cm->instance;
            $out['course_id'] = (int) $cm->course;
            $def = $DB->get_record_sql(
                "SELECT gd.id FROM {grading_areas} ga JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = ga.activemethod
                  WHERE ga.contextid = :ctx AND ga.component = 'mod_assign' AND ga.areaname = 'submissions' AND ga.activemethod = 'rubric'",
                ['ctx' => \context_module::instance($cm->id)->id]);
            $out['rubric_definition_id'] = $def ? (int) $def->id : 0;
            require_once($CFG->dirroot . '/local/jedomi/lib.php');
            $cfg = \local_jedomi_stem_cfg((int) $cm->course, $cmid);
            if ($cfg) {
                $out['course_enabled'] = (int) $cfg->enabled;
                $out['course_tutor_mode'] = (string) $cfg->tutor_mode;
                $out['course_max_questions'] = (int) $cfg->max_questions;
                $out['course_tutor_prompt'] = (string) ($cfg->tutor_prompt ?? '');
            }
        }
        $out['wwwroot'] = $CFG->wwwroot;
        $out['generated_at'] = time();
        return $out;
    }

    public static function execute_returns(): external_single_structure {
        $fields = [];
        foreach (self::KEYS as $key => $type) {
            $fields[$key] = new external_value($type, 'stem_' . $key);
        }
        $fields['assign_cmid'] = new external_value(PARAM_INT, 'cmid solicitado');
        $fields['assign_id'] = new external_value(PARAM_INT, 'id de mdl_assign de la tarea (0 si ninguna)');
        $fields['course_enabled'] = new external_value(PARAM_INT, 'tutor STEM activado en el curso/actividad');
        $fields['course_tutor_mode'] = new external_value(PARAM_ALPHAEXT, 'modo del curso/actividad (inherit|socratic|guided|assistant) o vacío');
        $fields['course_max_questions'] = new external_value(PARAM_INT, 'tope de preguntas del curso (0 = plantilla)');
        $fields['course_tutor_prompt'] = new external_value(PARAM_TEXT, 'instrucciones del profesor (vacío = plantilla)');
        $fields['course_id'] = new external_value(PARAM_INT, 'curso de la tarea seleccionada');
        $fields['rubric_definition_id'] = new external_value(PARAM_INT, 'definición de rúbrica activa (0 si no hay)');
        $fields['wwwroot'] = new external_value(PARAM_URL, 'URL del sitio');
        $fields['generated_at'] = new external_value(PARAM_INT, 'timestamp');
        return new external_single_structure($fields);
    }
}
