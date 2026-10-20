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
 * Visor de realidad aumentada del modelo 3D de una actividad (alumno o profesor, dentro del curso).
 * Carga el GLB adjunto por el docente (filearea stem_model) y lo manipula con los gestos de la estación
 * (pellizco en el borde; dos pellizcos). La IA se consulta a través del chat de Jedomi del propio curso, que en esta
 * página arranca desplegado junto al escenario; el botón «Conversar» abre el modo conversacional (voz en ambos sentidos).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/jedomi/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
if (!in_array($cm->modname, ['assign', 'jedomiar'], true)) {
    throw new moodle_exception('invalidcoursemodule');
}
$course = get_course($cm->course);
$context = context_module::instance($cm->id);
require_login($course, false, $cm);
require_capability('mod/' . $cm->modname . ':view', $context);

$parts = [];
$objectcontext = '';
if ($cm->modname === 'jedomiar') {
    // Actividad RA propia: modelo y piezas descritas por el docente (mod_jedomiar).
    $payload = null;
    foreach (['\\mod_jedomiar\\local\\service\\model_service', '\\mod_jedomiar\\local\\model_service'] as $cls) {
        if (class_exists($cls) && method_exists($cls, 'get_viewer_payload')) {
            $payload = $cls::get_viewer_payload($cm);
            break;
        }
    }
    if (is_array($payload)) {
        $modelurl = (string) ($payload['modelurl'] ?? '');
        $modelname = (string) ($payload['modelname'] ?? '');
        $parts = array_values((array) ($payload['parts'] ?? []));
        $objectcontext = (string) ($payload['objectcontext'] ?? '');
    } else {
        $f = \mod_jedomiar\local\service\model_service::get_model($context);
        $u = \mod_jedomiar\local\service\model_service::get_model_url($context);
        $modelurl = $u ? $u->out(false) : '';
        $modelname = $f ? $f->get_filename() : '';
    }
    $model = $modelurl !== '' ? (object) ['name' => $modelname] : null;
    $enabled = (int) get_config('local_jedomi', 'stem_enabled') === 1;
} else {
    $file = \local_jedomi\stem\model_validator::get_model($context);
    $fileurl = $file
        ? moodle_url::make_pluginfile_url($context->id, 'local_jedomi', 'stem_model', 0, '/', $file->get_filename())
        : null;
    $modelurl = $fileurl ? $fileurl->out(false) : '';
    $model = $file ? (object) ['name' => $file->get_filename()] : null;
    $cfg = local_jedomi_stem_cfg((int) $course->id, (int) $cm->id);
    $enabled = (int) get_config('local_jedomi', 'stem_enabled') === 1 && $cfg && (int) $cfg->enabled === 1;
}

$PAGE->set_url(new moodle_url('/local/jedomi/stem/visor.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('stem_visor_title', 'local_jedomi') . ': ' . format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));
// La cabecera de la actividad la pinta el propio visor (barra de título a la derecha del chat).
$PAGE->activityheader->disable();
$courseurl = ($cm->modname === 'jedomiar' ? new moodle_url('/mod/jedomiar/view.php', ['id' => $cm->id])
    : new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);

$base = (new moodle_url('/local/jedomi/stem/visor/'))->out(false);
// Cadenas que usa el JavaScript del visor (clave JS => clave de idioma sin el prefijo stem_visor_).
$jsstrings = ['nomodel', 'intro', 'talk', 'talk_stop', 'pj_nocam', 'pj_speak', 'pj_nodev', 'pj_nomic', 'zoom_refit', 'zoom_fitting',
    'zoom_off', 'parts_explode', 'parts_join', 'pad_whole', 'voice_consent', 'voice_off', 'intro_next', 'intro_done',
    'intro_s1', 'intro_s2', 'intro_s3', 'intro_s4', 'intro_s5'];
$strings = [];
foreach ($jsstrings as $k) {
    $strings[$k] = get_string('stem_visor_' . $k, 'local_jedomi');
}
$config = [
    'cmid' => $cmid,
    'courseid' => (int) $course->id,
    'modelUrl' => $modelurl,
    'modelName' => $model ? $model->name : '',
    'modname' => $cm->modname,
    'parts' => array_map(fn($p) => [
        'key' => (string) ($p['key'] ?? ''),
        'name' => (string) ($p['name'] ?? ''),
        'description' => (string) ($p['description'] ?? ''),
        'nodes' => array_values(array_map('strval', (array) ($p['nodes'] ?? []))),
    ], $parts),
    'base' => $base,
    'sesskey' => sesskey(),
    'enabled' => $enabled,
    'userid' => (int) $USER->id,
    'voiceEnabled' => (int) get_config('local_jedomi', 'stem_voice_enabled') === 1,
    'courseUrl' => $courseurl,
    'dev' => optional_param('dev', 0, PARAM_INT) === 1
        && has_capability('local/jedomi:configuretutor', context_course::instance($course->id)),
    'strings' => $strings,
];

$vdir = __DIR__ . '/visor/';
$ver = fn(string $f): int => (int) @filemtime($vdir . $f);
// Versión por fecha de modificación de cada guion: evita que el navegador sirva copias viejas tras una actualización.
$imports = ['three' => $base . 'vendor/three/three.module.js', 'three/addons/' => $base . 'vendor/three/'];
foreach (['visor.js', 'intro.js', 'devices.js', 'parts.js', 'hands3d.js'] as $f) {
    $imports[$base . $f] = $base . $f . '?v=' . $ver($f);
}
$jsonflags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$templatedata = [
    'activityname' => format_string($cm->name),
    'coursename' => format_string($course->fullname),
    'quiturl' => $courseurl,
    'dev' => (bool) $config['dev'],
    'hasmodel' => (bool) $model,
    'modelurl' => $modelurl,
    'modelname' => $model ? $model->name : '',
    'showaro' => $config['dev'] || !$model,
    'voiceenabled' => (bool) $config['voiceEnabled'],
    'base' => $base,
    'importmap' => json_encode(['imports' => $imports], $jsonflags),
    'config' => json_encode($config, $jsonflags),
    'verintro' => $ver('intro.js'),
    'vervisor' => $ver('visor.js'),
];

$PAGE->requires->css(new moodle_url('/local/jedomi/stem/visor/visor.css', ['v' => $ver('visor.css')]));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_jedomi/stem_visor', $templatedata);
echo $OUTPUT->footer();
