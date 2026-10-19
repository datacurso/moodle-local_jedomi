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
 * (pellizco en el borde; dos pellizcos). La IA se consulta a través del chat de Jedomi del propio curso.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/jedomi/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);
require_login($course, false, $cm);
require_capability('mod/assign:view', $context);

$model = \local_jedomi\stem\model_validator::get_model($context);
$modelurl = $model ? moodle_url::make_pluginfile_url($context->id, 'local_jedomi', 'stem_model', 0, '/', $model->get_filename())->out(false) : '';
$cfg = local_jedomi_stem_cfg((int) $course->id, (int) $cm->id);
$enabled = (int) get_config('local_jedomi', 'stem_enabled') === 1 && $cfg && (int) $cfg->enabled === 1;

$PAGE->set_url(new moodle_url('/local/jedomi/stem/visor.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('stem_visor_title', 'local_jedomi') . ': ' . format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));

$base = (new moodle_url('/local/jedomi/stem/visor/'))->out(false);
$config = [
    'cmid' => $cmid, 'courseid' => (int) $course->id, 'modelUrl' => $modelurl, 'modelName' => $model ? $model->get_filename() : '',
    'base' => $base, 'sesskey' => sesskey(), 'enabled' => $enabled, 'userid' => (int) $USER->id,
    'strings' => ['nomodel' => get_string('stem_visor_nomodel', 'local_jedomi'), 'intro' => get_string('stem_visor_intro', 'local_jedomi')],
];

echo $OUTPUT->header();
?>
<style>
  #stage{position:relative;width:100%;height:calc(100vh - 60px);min-height:480px;background:#000;border-radius:12px;overflow:hidden}
  #stage video,#stage canvas{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}
  #three{pointer-events:none}
  #hud{position:absolute;left:16px;top:16px;right:16px;display:flex;gap:12px;align-items:flex-start;pointer-events:none}
  .vpanel{background:rgba(15,17,21,.78);color:#f3f4f6;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:12px 16px}
  #step{font-size:20px;line-height:1.3;max-width:60%}
  #status{position:absolute;left:16px;bottom:16px;font-size:14px;color:#9aa3af}
  #ctl{margin-left:auto;display:flex;gap:8px;pointer-events:auto;flex-wrap:wrap;justify-content:flex-end}
  #ctl button,#ctl select{font-size:15px;padding:8px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:#1f2937;color:#f3f4f6;cursor:pointer}
  #ctl .primary{background:#f59e0b;color:#111;border-color:transparent;font-weight:600}
  #ctl .danger{background:#b91c1c;border-color:transparent}
  #ia{position:absolute;right:16px;bottom:16px;max-width:40%;font-size:15px;display:none}
  #start{position:absolute;inset:0;display:grid;place-items:center;background:rgba(0,0,0,.72)}
  #start .vpanel{max-width:560px;font-size:16px;line-height:1.5}
</style>
<div id="stage">
  <video id="video" autoplay playsinline muted></video>
  <canvas id="three"></canvas>
  <div id="hud">
    <div class="vpanel" id="step">…</div>
    <div id="ctl">
      <select id="model"><option value="<?php echo s($modelurl ?: 'aro'); ?>" selected><?php echo $model ? s($model->get_filename()) : 'Aro de prueba'; ?></option><option value="aro">Aro de prueba</option></select>
      <button id="explain" class="primary"><?php echo get_string('stem_visor_explain', 'local_jedomi'); ?></button>
      <button id="reset"><?php echo get_string('stem_visor_reset', 'local_jedomi'); ?></button>
      <button id="quit" class="danger">X</button>
    </div>
  </div>
  <div class="vpanel" id="status">—</div>
  <div class="vpanel" id="ia"></div>
  <div id="start"><div class="vpanel">
    <b><?php echo get_string('stem_visor_title', 'local_jedomi'); ?></b> · <?php echo format_string($cm->name); ?><br><br>
    <?php echo $model ? get_string('stem_visor_intro', 'local_jedomi') : get_string('stem_visor_nomodel', 'local_jedomi'); ?><br><br>
    <button id="go" class="primary"><?php echo get_string('stem_visor_start', 'local_jedomi'); ?></button>
  </div></div>
</div>
<script type="importmap">
{ "imports": { "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
               "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/" } }
</script>
<script>window.JEDOMI_VISOR = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $base; ?>vendor/cv.js"></script>
<script src="<?php echo $base; ?>vendor/aruco.js"></script>
<script src="<?php echo $base; ?>vendor/dictionaries/aruco_4x4_1000.js"></script>
<script type="module" src="<?php echo $base; ?>visor.js"></script>
<?php
echo $OUTPUT->footer();
