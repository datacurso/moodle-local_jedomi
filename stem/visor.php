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
 * AR viewer of a Jedomi AR activity (mod_jedomiar): loads the activity model and the parts described by the teacher.
 *
 * The AI is reached through the course Jedomi chat, which starts expanded next to the stage on this page; the
 * «Talk» button opens the voice conversation mode.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/jedomi/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('jedomiar', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);
require_login($course, false, $cm);
require_capability('mod/jedomiar:view', $context);

$payload = \mod_jedomiar\local\service\model_service::get_viewer_payload($cm);
$modelurl = (string) ($payload['modelurl'] ?? '');
$modelname = (string) ($payload['modelname'] ?? '');
$parts = array_values((array) ($payload['parts'] ?? []));
$objectcontext = (string) ($payload['objectcontext'] ?? '');
$model = $modelurl !== '' ? (object) ['name' => $modelname] : null;
$enabled = (int) get_config('local_jedomi', 'stem_enabled') === 1;

$PAGE->set_url(new moodle_url('/local/jedomi/stem/visor.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('stem_visor_title', 'local_jedomi') . ': ' . format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));
// The viewer draws its own activity header (title bar next to the chat).
$PAGE->activityheader->disable();
$courseurl = (new moodle_url('/mod/jedomiar/view.php', ['id' => $cm->id]))->out(false);

$base = (new moodle_url('/local/jedomi/stem/visor/'))->out(false);
// Strings used by the viewer JavaScript (JS key => language key without the stem_visor_ prefix).
$jsstrings = ['talk', 'talk_stop', 'pj_nocam', 'pj_speak', 'pj_nodev', 'pj_nomic', 'zoom_refit', 'zoom_fitting',
    'zoom_off', 'parts_explode', 'parts_join', 'pad_whole', 'voice_consent', 'voice_off', 'intro_next', 'intro_done',
    'intro_s1', 'intro_s2', 'intro_s3', 'intro_s4', 'intro_s5', 'ptt_hint', 'ptt_listening', 'ptt_handsfree', 'ptt_hold_mode',
    'view_ar', 'view_web', 'ask_object', 'ask_part', 'ask_part_desc', 'ask_selected', 'ask_selected_desc',
    'ask_voice_start', 'cam_failed', 'error', 'hint_free', 'hint_held', 'hint_held_rotating', 'hint_mouse',
    'hint_part_held', 'hint_parts', 'hint_two', 'hint_web_free', 'hint_web_mouse', 'hint_web_parts', 'ia_offline',
    'img_failed', 'mic_blocked', 'mic_blocked_chat', 'model_generic', 'no_speech_api', 'scene', 'scene_held',
    'scene_resting', 'scene_selected', 'status_mat_covered', 'status_mat_missing', 'status_mat_ok', 'voice_bye',
    'voice_handsfree', 'voice_stop_words', 'voice_unclear', 'you_said', 'speaking', 'status_web', 'pj_cam_n',
    'pj_mic_n', 'hint_web_free_touch', 'hint_web_parts_touch'];
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
// Version each script by its modification time so browsers never keep a stale copy after an update.
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
