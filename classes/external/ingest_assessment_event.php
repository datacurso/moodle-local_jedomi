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
use local_jedomi\stem\station_auth;

/**
 * Ingiere un evento de observación de la estación física.
 *
 * Fuentes: vision (p. ej. mat_detected, topdown_capture con image_base64), speech (transcript), dialog (question,
 * answer), spatial (pointer_on_mat), system (session_start, session_end, preset_face, preset_table). Las imágenes
 * se guardan con la File API (component local_jedomi, filearea stem_evidence, itemid = id del evento) y en el payload
 * queda sólo la referencia.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingest_assessment_event extends external_api {

    public const SOURCES = ['vision', 'speech', 'dialog', 'spatial', 'system'];
    public const MAX_PAYLOAD = 65536;       // bytes de JSON (sin contar imagen)
    public const MAX_IMAGE = 400 * 1024;    // bytes decodificados

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'cmid de la tarea evaluada'),
            'userid'       => new external_value(PARAM_INT, 'id del alumno observado'),
            'sessionid'    => new external_value(PARAM_ALPHANUMEXT, 'id de sesión de la estación (8–64 chars)'),
            'source'       => new external_value(PARAM_ALPHA, 'vision|speech|dialog|spatial|system'),
            'event_type'   => new external_value(PARAM_ALPHANUMEXT, 'tipo de evento (p. ej. topdown_capture)'),
            'payload_json' => new external_value(PARAM_RAW, 'JSON del evento; opcional image_base64 (JPEG/PNG ≤ 400 KB)'),
            'nonce'        => new external_value(PARAM_ALPHANUMEXT, 'nonce único 32–64 chars'),
            'timestamp'    => new external_value(PARAM_INT, 'epoch de la estación'),
            'signature'    => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex (station_auth::sign)'),
        ]);
    }

    public static function execute(int $cmid, int $userid, string $sessionid, string $source, string $event_type,
                                   string $payload_json, string $nonce, int $timestamp, string $signature): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact('cmid', 'userid', 'sessionid', 'source',
            'event_type', 'payload_json', 'nonce', 'timestamp', 'signature'));
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:ingestassessment', $context);

        station_auth::verify('ingest', $p['cmid'], $p['userid'], $p['sessionid'], $p['nonce'], $p['timestamp'], $p['payload_json'], $p['signature']);
        [$cm, $course, $modcontext] = station_auth::resolve_target($p['cmid'], $p['userid']);

        if (!in_array($p['source'], self::SOURCES, true)) {
            throw new \moodle_exception('stem_bad_source', 'local_jedomi', '', $p['source']);
        }
        $payload = json_decode($p['payload_json'], true);
        if (!is_array($payload)) {
            throw new \moodle_exception('stem_bad_payload', 'local_jedomi');
        }
        $image = null;
        if (isset($payload['image_base64'])) {
            $image = base64_decode((string) $payload['image_base64'], true);
            if ($image === false || strlen($image) > self::MAX_IMAGE) {
                throw new \moodle_exception('stem_bad_image', 'local_jedomi');
            }
            $isjpeg = substr($image, 0, 3) === "\xFF\xD8\xFF";
            $ispng  = substr($image, 0, 8) === "\x89PNG\r\n\x1a\n";
            if (!$isjpeg && !$ispng) {
                throw new \moodle_exception('stem_bad_image', 'local_jedomi');
            }
            unset($payload['image_base64']);
            $payload['image_file'] = 'pending';
        }
        $clean = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($clean) > self::MAX_PAYLOAD) {
            throw new \moodle_exception('stem_bad_payload', 'local_jedomi');
        }

        $event = (object) [
            'courseid' => (int) $course->id, 'cmid' => (int) $cm->id, 'userid' => $p['userid'], 'sessionid' => $p['sessionid'],
            'source' => $p['source'], 'event_type' => \core_text::substr($p['event_type'], 0, 40), 'payload' => $clean,
            'nonce' => $p['nonce'], 'station_time' => $p['timestamp'], 'ingestedby' => (int) $USER->id, 'timecreated' => time(),
        ];
        try {
            $event->id = $DB->insert_record('local_jedomi_assess_events', $event);
        } catch (\dml_write_exception $e) {
            // Índice único sobre nonce: reenvío/replay.
            throw new \moodle_exception('stem_replay', 'local_jedomi');
        }

        $fileurl = '';
        if ($image !== null) {
            $fs = get_file_storage();
            $filename = 'evidencia-' . $event->id . ($isjpeg ? '.jpg' : '.png');
            $record = ['contextid' => $modcontext->id, 'component' => 'local_jedomi', 'filearea' => 'stem_evidence',
                       'itemid' => $event->id, 'filepath' => '/', 'filename' => $filename, 'userid' => $USER->id];
            $stored = $fs->create_file_from_string($record, $image);
            $payload['image_file'] = $filename;
            $payload['image_sha1'] = $stored->get_contenthash();
            $payload['image_bytes'] = $stored->get_filesize();
            $DB->set_field('local_jedomi_assess_events', 'payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['id' => $event->id]);
            $fileurl = \moodle_url::make_pluginfile_url($modcontext->id, 'local_jedomi', 'stem_evidence', $event->id, '/', $filename)->out(false);
        }
        return ['eventid' => (int) $event->id, 'image_url' => $fileurl, 'servertime' => time()];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'eventid'    => new external_value(PARAM_INT, 'id del evento almacenado'),
            'image_url'  => new external_value(PARAM_RAW, 'URL pluginfile de la imagen guardada (vacío si no había)'),
            'servertime' => new external_value(PARAM_INT, 'epoch del servidor'),
        ]);
    }
}
