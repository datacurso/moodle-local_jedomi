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
$PAGE->activityheader->disable();   // la cabecera de la actividad la pinta el propio visor (barra de título a la derecha del chat)
$courseurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);

$base = (new moodle_url('/local/jedomi/stem/visor/'))->out(false);
$config = [
    'cmid' => $cmid, 'courseid' => (int) $course->id, 'modelUrl' => $modelurl, 'modelName' => $model ? $model->get_filename() : '',
    'base' => $base, 'sesskey' => sesskey(), 'enabled' => $enabled, 'userid' => (int) $USER->id,
    'voiceEnabled' => (int) get_config('local_jedomi', 'stem_voice_enabled') === 1, 'courseUrl' => $courseurl,
    'dev' => optional_param('dev', 0, PARAM_INT) === 1 && has_capability('local/jedomi:configuretutor', context_course::instance($course->id)),
    'strings' => ['nomodel' => get_string('stem_visor_nomodel', 'local_jedomi'), 'intro' => get_string('stem_visor_intro', 'local_jedomi'),
        'talk' => get_string('stem_visor_talk', 'local_jedomi'), 'talk_stop' => get_string('stem_visor_talk_stop', 'local_jedomi'),
        'pj_nocam' => get_string('stem_visor_pj_nocam', 'local_jedomi'), 'pj_speak' => get_string('stem_visor_pj_speak', 'local_jedomi'), 'pj_nodev' => get_string('stem_visor_pj_nodev', 'local_jedomi'), 'pj_nomic' => get_string('stem_visor_pj_nomic', 'local_jedomi'),
        'zoom_refit' => get_string('stem_visor_zoom_refit', 'local_jedomi'), 'parts_explode' => get_string('stem_visor_parts_explode', 'local_jedomi'), 'parts_join' => get_string('stem_visor_parts_join', 'local_jedomi'), 'zoom_fitting' => get_string('stem_visor_zoom_fitting', 'local_jedomi'), 'zoom_off' => get_string('stem_visor_zoom_off', 'local_jedomi'),
        'voice_consent' => get_string('stem_visor_voice_consent', 'local_jedomi'), 'voice_off' => get_string('stem_visor_voice_off', 'local_jedomi'),
        'intro_next' => get_string('stem_visor_intro_next', 'local_jedomi'), 'intro_done' => get_string('stem_visor_intro_done', 'local_jedomi'),
        'intro_s1' => get_string('stem_visor_intro_s1', 'local_jedomi'), 'intro_s2' => get_string('stem_visor_intro_s2', 'local_jedomi'), 'intro_s3' => get_string('stem_visor_intro_s3', 'local_jedomi'),
        'intro_s4' => get_string('stem_visor_intro_s4', 'local_jedomi'), 'intro_s5' => get_string('stem_visor_intro_s5', 'local_jedomi')],
];

echo $OUTPUT->header();
?>
<style>
  #stage{position:relative;width:100%;height:calc(100vh - 16px);min-height:480px;background:#000;border-radius:12px;overflow:hidden}
  #partlabel{position:absolute;z-index:6;pointer-events:none;background:rgba(15,17,21,.9);color:#f3f4f6;font-size:13px;padding:3px 8px;border-radius:6px;border:1px solid rgba(56,189,248,.6)}
  #partinfo{position:absolute;left:16px;bottom:56px;max-width:min(420px,60%);z-index:5;font-size:14px;line-height:1.4}
  #partinfo .pname{font-weight:600;font-size:16px;color:#fbbf24;margin-bottom:4px}#partinfo .pdesc{color:#e5e7eb}
  #partinfo .pbtns{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
  #partinfo button{font-size:14px;padding:6px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:#1f2937;color:#f3f4f6;cursor:pointer}#partinfo .primary{background:#f59e0b;color:#111;border-color:transparent;font-weight:600}
  #credit{position:absolute;right:12px;bottom:8px;max-width:46%;font-size:11px;line-height:1.3;color:#cbd5e1;background:rgba(15,17,21,.6);padding:3px 8px;border-radius:6px;z-index:3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #view{position:absolute;inset:0;transform-origin:50% 50%;will-change:transform;transition:transform .35s ease-out}
  #view video,#view canvas{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}
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
  #titlebar{position:absolute;left:0;right:0;top:0;height:44px;display:flex;align-items:center;gap:12px;padding:0 16px;background:rgba(15,17,21,.85);color:#f3f4f6;z-index:4;border-bottom:1px solid rgba(255,255,255,.1)}
  #titlebar .tname{font-weight:600;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #titlebar .tcourse{color:#9aa3af;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #titlebar .tquit{margin-left:auto;color:#fca5a5;text-decoration:none;font-size:14px;border:1px solid rgba(252,165,165,.4);border-radius:8px;padding:4px 10px}
  #hud{top:56px}
  #start .prejoin{max-width:860px;width:min(860px,96%)}
  .pj{display:grid;grid-template-columns:1.3fr 1fr;gap:16px;margin-top:12px}
  .pjcam{position:relative;background:#111;border-radius:10px;overflow:hidden;min-height:240px}
  .pjcam video{width:100%;height:100%;object-fit:cover;display:block;aspect-ratio:16/9}
  #pjcamnote{position:absolute;inset:0;display:grid;place-items:center;color:#9aa3af;font-size:14px;text-align:center;padding:12px;pointer-events:none}
  .pjctl{display:flex;flex-direction:column;gap:10px}
  .pjctl label{display:flex;flex-direction:column;gap:4px;font-size:14px;color:#cbd5e1}
  .pjctl select{font-size:15px;padding:8px;border-radius:8px;background:#1f2937;color:#f3f4f6;border:1px solid rgba(255,255,255,.2)}
  .meter{height:10px;background:#1f2937;border-radius:5px;overflow:hidden}#pjlevel{height:100%;width:0;background:linear-gradient(90deg,#22c55e,#f59e0b);transition:width .08s}
  #pjmicnote{color:#9aa3af;font-size:12px}#pjstatus{font-size:13px;color:#fbbf24;min-height:18px}
  @media (max-width:900px){.pj{grid-template-columns:1fr}}
  /* ── intro animada ── */
  #jv-intro{position:absolute;inset:0;z-index:5;display:grid;place-items:center;background:rgba(0,0,0,.86)}
  .ipanel{width:min(760px,96%);background:#0f1115;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 18px;color:#f3f4f6}
  .ihead{display:flex;justify-content:space-between;align-items:center;font-size:17px}
  .ihead button{background:transparent;color:#9aa3af;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:14px}
  #jv-hands3d{display:block;width:100%;aspect-ratio:640/400;margin:10px 0;border-radius:10px;background:#14171d}
  #intro-caption{font-size:18px;line-height:1.4;min-height:52px}
  .ifoot{display:flex;justify-content:space-between;align-items:center;margin-top:8px}
  #intro-dots button{display:inline-block;width:12px;height:12px;padding:0;border:0;border-radius:50%;background:#374151;margin-right:8px;cursor:pointer}#intro-dots button.on{background:#f59e0b}#intro-dots button:focus-visible{outline:2px solid #60a5fa;outline-offset:2px}
  .ifoot .primary{background:#f59e0b;color:#111;border:0;border-radius:10px;padding:9px 16px;font-size:15px;font-weight:600;cursor:pointer}
  .replay{display:block;margin-top:10px;color:#9aa3af;font-size:13px}

  body:has(#jedomi-sidebar.jedomi-open) #stage{margin-left:var(--j-width,400px);width:auto}
  body:has(#jedomi-sidebar.jedomi-open.jedomi-expand-half) #stage{margin-left:50vw}
  body:has(#jedomi-sidebar.jedomi-open) #jedomi-trigger{display:none}
</style>
<div id="stage">
  <div id="titlebar"><span class="tname"><?php echo format_string($cm->name); ?></span><span class="tcourse"><?php echo format_string($course->fullname); ?></span>
    <a id="quit" class="tquit" href="<?php echo s($courseurl); ?>" title="<?php echo get_string('stem_visor_quit_help', 'local_jedomi'); ?>">✕ <?php echo get_string('stem_visor_quit', 'local_jedomi'); ?></a></div>
  <div id="view"><video id="video" autoplay playsinline muted></video><canvas id="three"></canvas></div>
  <div id="hud">
    <div class="vpanel" id="step">…</div>
    <div id="ctl">
      <?php // el selector sólo existe para quien puede configurar el tutor (capacidad comprobada en servidor); el alumno recibe únicamente la pieza del docente ?>
      <select id="model" <?php echo $config['dev'] ? '' : 'hidden'; ?>><?php if ($model) { ?><option value="<?php echo s($modelurl); ?>" selected><?php echo s($model->get_filename()); ?></option><?php } ?><?php if ($config['dev'] || !$model) { ?><option value="aro" <?php echo $model ? '' : 'selected'; ?>>Aro de prueba (desarrollo)</option><?php } ?></select>
      <?php if ($config['voiceEnabled']) { ?><button id="talk" class="primary" title="<?php echo get_string('stem_visor_talk_help', 'local_jedomi'); ?>">🎙 <?php echo get_string('stem_visor_talk', 'local_jedomi'); ?></button><?php } ?>
      <button id="explain" title="<?php echo get_string('stem_visor_explain_help', 'local_jedomi'); ?>">💬 <?php echo get_string('stem_visor_explain', 'local_jedomi'); ?></button>
      <button id="reset" title="<?php echo get_string('stem_visor_reset_help', 'local_jedomi'); ?>">⟲ <?php echo get_string('stem_visor_reset', 'local_jedomi'); ?></button>
      <button id="pieces" hidden aria-pressed="false" title="<?php echo get_string('stem_visor_parts_help', 'local_jedomi'); ?>">🧩 <?php echo get_string('stem_visor_parts', 'local_jedomi'); ?></button>
      <button id="explode" hidden aria-pressed="false" title="<?php echo get_string('stem_visor_parts_explode_help', 'local_jedomi'); ?>">💥 <?php echo get_string('stem_visor_parts_explode', 'local_jedomi'); ?></button>
      <button id="zoom" title="<?php echo get_string('stem_visor_zoom_help', 'local_jedomi'); ?>" aria-pressed="true">🔍 <?php echo get_string('stem_visor_zoom', 'local_jedomi'); ?></button>
    </div>
  </div>
  <div id="partlabel" hidden></div>
  <div class="vpanel" id="partinfo" hidden aria-live="polite"><div class="pname"></div><div class="pdesc"></div>
    <div class="pbtns"><button id="part-explain" class="primary">💬 <?php echo get_string('stem_visor_parts_explain', 'local_jedomi'); ?></button><button id="part-home">↩ <?php echo get_string('stem_visor_parts_home', 'local_jedomi'); ?></button><button id="part-close" aria-label="<?php echo get_string('closebuttontitle'); ?>">✕</button></div></div>
  <div class="vpanel" id="status">—</div>
  <div class="vpanel" id="ia"></div>
  <div id="jv-intro" data-step="1" role="dialog" aria-modal="true" aria-labelledby="intro-title">
    <div class="ipanel">
      <div class="ihead"><b id="intro-title"><?php echo get_string('stem_visor_intro_title', 'local_jedomi'); ?></b><button id="intro-skip"><?php echo get_string('stem_visor_intro_skip', 'local_jedomi'); ?> ›</button></div>
      <canvas id="jv-hands3d" aria-hidden="true"></canvas>
      <div id="intro-caption" aria-live="polite">…</div>
      <div class="ifoot"><div id="intro-dots"></div><button id="intro-next" class="primary"><?php echo get_string('stem_visor_intro_next', 'local_jedomi'); ?></button></div>
    </div>
  </div>
  <div id="start"><div class="vpanel prejoin">
    <b><?php echo get_string('stem_visor_pj_title', 'local_jedomi'); ?></b>
    <div class="pj">
      <div class="pjcam"><video id="pjvideo" autoplay playsinline muted></video><div id="pjcamnote"></div></div>
      <div class="pjctl">
        <label><?php echo get_string('stem_visor_pj_camera', 'local_jedomi'); ?><select id="pjcam"></select></label>
        <label><?php echo get_string('stem_visor_pj_mic', 'local_jedomi'); ?><select id="pjmic"></select></label>
        <div class="meter" aria-hidden="true"><div id="pjlevel"></div></div>
        <small id="pjmicnote"><?php echo get_string('stem_visor_pj_micnote', 'local_jedomi'); ?></small>
        <div id="pjstatus" aria-live="polite"></div>
        <button id="go" class="primary"><?php echo get_string('stem_visor_pj_enter', 'local_jedomi'); ?></button>
        <a href="#" id="intro-replay" class="replay"><?php echo get_string('stem_visor_intro_replay', 'local_jedomi'); ?></a>
      </div>
    </div>
  </div></div>
</div>
<?php
// versión por fecha de modificación de cada guion: evita que el navegador sirva copias viejas tras una actualización
$vdir = __DIR__ . '/visor/'; $ver = fn(string $f) => (int) @filemtime($vdir . $f);
$imports = ['three' => $base . 'vendor/three/three.module.js', 'three/addons/' => $base . 'vendor/three/'];
foreach (['visor.js', 'intro.js', 'devices.js', 'parts.js', 'hands3d.js'] as $f) { $imports[$base . $f] = $base . $f . '?v=' . $ver($f); }
?>
<script type="importmap"><?php echo json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
<script>try { sessionStorage.setItem('jedomi_open', '1'); } catch (e) { }
window.JEDOMI_VISOR = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $base; ?>vendor/cv.js"></script>
<script src="<?php echo $base; ?>vendor/aruco.js"></script>
<script src="<?php echo $base; ?>vendor/dictionaries/aruco_4x4_1000.js"></script>
<script type="module" src="<?php echo $base; ?>intro.js?v=<?php echo $ver('intro.js'); ?>"></script>
<script type="module" src="<?php echo $base; ?>visor.js?v=<?php echo $ver('visor.js'); ?>"></script>
<?php
echo $OUTPUT->footer();
