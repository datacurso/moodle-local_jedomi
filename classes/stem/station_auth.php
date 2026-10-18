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
 * Autenticación de la estación física: HMAC-SHA256 con el secreto de estación (pestaña plantilla) + nonce antirréplica.
 *
 * Frontera de confianza (QA r11 #1): SÓLO la estación (que posee el secreto) puede llamar a los servicios de
 * evaluación. El navegador del alumno nunca llega aquí: cualquier evidencia generada en el cliente debe entrar por
 * chatproxy con la sesión del alumno y marcada como no verificada; nunca afirma corrección ni nivel de rúbrica.
 *
 * Mensaje firmado: "{fn}:{cmid}:{userid}:{sessionid}:{nonce}:{timestamp}:{sha256(body)}". El hash del cuerpo se
 * RECALCULA en el servidor (QA r7: nunca aceptar un hash enviado por el cliente). Ventana temporal de 120 s. El nonce
 * debe ser único (índice UNIQUE en la tabla destino: una reinserción falla y se reporta como replay).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class station_auth {

    /** Ventana máxima entre el reloj de la estación y el del servidor. */
    public const WINDOW_SECONDS = 120;

    /**
     * Verifica firma, ventana temporal y formato de nonce/sesión.
     *
     * @throws \moodle_exception station_unauthorized
     */
    public static function verify(string $fn, int $cmid, int $userid, string $sessionid, string $nonce, int $timestamp,
                                  string $body, string $signature): void {
        $secret = (string) get_config('local_jedomi', 'stem_station_secret');
        if ($secret === '' || strlen($secret) < 32) {
            self::fail($fn, 'no_station_secret');
        }
        if (abs(time() - $timestamp) > self::WINDOW_SECONDS) {
            self::fail($fn, 'timestamp_out_of_window');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $nonce) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sessionid)) {
            self::fail($fn, 'bad_nonce_or_session');
        }
        $expected = self::sign($secret, $fn, $cmid, $userid, $sessionid, $nonce, $timestamp, $body);
        if (!hash_equals($expected, strtolower(trim($signature)))) {
            self::fail($fn, 'invalid_signature');
        }
    }

    /** Firma canónica (misma función que usa la estación en Python: stem_station.sign_event). */
    public static function sign(string $secret, string $fn, int $cmid, int $userid, string $sessionid, string $nonce,
                                int $timestamp, string $body): string {
        $message = implode(':', [$fn, $cmid, $userid, $sessionid, $nonce, $timestamp, hash('sha256', $body)]);
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * Comprueba que la tarea existe, es un assign, el tutor STEM está activado para ella y el usuario es un
     * estudiante matriculado (no profesor/gestor).
     *
     * @return array [$cm, $course, $context, $cfg]
     * @throws \moodle_exception
     */
    public static function resolve_target(int $cmid, int $userid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        $course  = get_course($cm->course);
        $context = \context_module::instance($cm->id);
        $cfg = \local_jedomi_stem_cfg((int) $course->id, (int) $cm->id);
        if ((int) get_config('local_jedomi', 'stem_enabled') !== 1 || !$cfg || (int) $cfg->enabled !== 1) {
            throw new \moodle_exception('stem_not_enabled_for_activity', 'local_jedomi', '', $cmid);
        }
        $coursectx = \context_course::instance($course->id);
        $student = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0], 'id, username');
        if (!$student || !is_enrolled($coursectx, $userid, '', true)) {
            throw new \moodle_exception('stem_student_not_enrolled', 'local_jedomi', '', $userid);
        }
        if (has_capability('mod/assign:grade', $context, $userid) ||
                has_capability('moodle/course:manageactivities', $coursectx, $userid)) {
            throw new \moodle_exception('stem_student_not_enrolled', 'local_jedomi', '', $userid);
        }
        return [$cm, $course, $context, $cfg];
    }

    /** Registra el fallo (sin revelar el motivo al cliente) y lanza la excepción genérica. */
    private static function fail(string $fn, string $reason): void {
        error_log(sprintf('[local_jedomi] station auth failed fn=%s reason=%s ip=%s', $fn, $reason, getremoteaddr()));
        throw new \moodle_exception('station_unauthorized', 'local_jedomi');
    }
}
