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
        'voice_consent' => get_string('stem_visor_voice_consent', 'local_jedomi'), 'voice_off' => get_string('stem_visor_voice_off', 'local_jedomi'),
        'intro_next' => get_string('stem_visor_intro_next', 'local_jedomi'), 'intro_done' => get_string('stem_visor_intro_done', 'local_jedomi'),
        'intro_s1' => get_string('stem_visor_intro_s1', 'local_jedomi'), 'intro_s2' => get_string('stem_visor_intro_s2', 'local_jedomi'), 'intro_s3' => get_string('stem_visor_intro_s3', 'local_jedomi'),
        'intro_s4' => get_string('stem_visor_intro_s4', 'local_jedomi'), 'intro_s5' => get_string('stem_visor_intro_s5', 'local_jedomi')],
];

echo $OUTPUT->header();
?>
<style>
  #stage{position:relative;width:100%;height:calc(100vh - 16px);min-height:480px;background:#000;border-radius:12px;overflow:hidden}
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
  #iscene{width:100%;height:auto;display:block;margin:10px 0;background:#15181f;border-radius:10px}
  #intro-caption{font-size:18px;line-height:1.4;min-height:52px}
  .ifoot{display:flex;justify-content:space-between;align-items:center;margin-top:8px}
  #intro-dots button{display:inline-block;width:12px;height:12px;padding:0;border:0;border-radius:50%;background:#374151;margin-right:8px;cursor:pointer}#intro-dots button.on{background:#f59e0b}#intro-dots button:focus-visible{outline:2px solid #60a5fa;outline-offset:2px}
  .ifoot .primary{background:#f59e0b;color:#111;border:0;border-radius:10px;padding:9px 16px;font-size:15px;font-weight:600;cursor:pointer}
  .replay{display:block;margin-top:10px;color:#9aa3af;font-size:13px}
  #iscene .mat{fill:#f8fafc;opacity:.92}#iscene .aruco rect{fill:#111}
  #iscene .ring{fill:none;stroke:#f59e0b;stroke-width:18}#iscene .ringin{fill:none;stroke:#b45309;stroke-width:2;opacity:.5}
  #iscene .shadow{fill:#000;opacity:.18;transform-origin:320px 200px;transition:transform .4s,opacity .4s}
  #iscene .hand .palm,#iscene .hand rect{fill:#e9c3a0;stroke:#9a6b45;stroke-width:3;stroke-linejoin:round}
  #iscene .hand{transform-box:view-box;opacity:.96}
  #iscene .finger,#iscene .thumb{transition:transform .5s}
  /* posiciones base: mano A entra por abajo; mano B (espejo) sólo en el paso 4 */
  #iscene .ha{transform:translate(327px,540px)}#iscene .hb{display:none}
  /* pellizco: pulgar e índice se cierran sobre el borde; los otros dedos se recogen */
  .pinched .f1{transform:rotate(25deg) scaleY(.7)}.pinched .thumb{transform:rotate(80deg) translate(10px,-20px)}.pinched .f2,.pinched .f3,.pinched .f4{transform:scaleY(.72)}
  /* paso 1: acercarse y coger */
  #jv-intro[data-step="1"] .ha{animation:reach 5.2s ease-in-out infinite}
  #jv-intro[data-step="1"] .ha .f1{animation:f1pinch 5.2s ease-in-out infinite}#jv-intro[data-step="1"] .ha .thumb{animation:thpinch 5.2s ease-in-out infinite}
  #jv-intro[data-step="1"] .ha .f2,#jv-intro[data-step="1"] .ha .f3,#jv-intro[data-step="1"] .ha .f4{animation:curl 5.2s ease-in-out infinite}
  #jv-intro[data-step="1"] .shadow{animation:lift 5.2s ease-in-out infinite}
  @keyframes reach{0%{transform:translate(327px,540px)}35%,100%{transform:translate(327px,391px)}}
  @keyframes f1pinch{0%,35%{transform:none}55%,100%{transform:rotate(25deg) scaleY(.7)}}
  @keyframes thpinch{0%,35%{transform:none}55%,100%{transform:rotate(80deg) translate(10px,-20px)}}
  @keyframes curl{0%,35%{transform:none}55%,100%{transform:scaleY(.72)}}
  @keyframes lift{0%,50%{transform:scale(1);opacity:.18}65%,100%{transform:scale(1.12) translate(0,10px);opacity:.28}}
  #jv-intro[data-step="2"] .ha .f1,#jv-intro[data-step="3"] .ha .f1,#jv-intro[data-step="4"] .hand .f1{transform:rotate(25deg) scaleY(.7)}
  #jv-intro[data-step="2"] .ha .thumb,#jv-intro[data-step="3"] .ha .thumb,#jv-intro[data-step="4"] .hand .thumb{transform:rotate(80deg) translate(10px,-20px)}
  #jv-intro[data-step="2"] .ha .f2,#jv-intro[data-step="2"] .ha .f3,#jv-intro[data-step="2"] .ha .f4,#jv-intro[data-step="3"] .ha .f2,#jv-intro[data-step="3"] .ha .f3,#jv-intro[data-step="3"] .ha .f4,#jv-intro[data-step="4"] .hand .f2,#jv-intro[data-step="4"] .hand .f3,#jv-intro[data-step="4"] .hand .f4{transform:scaleY(.72)}
  /* paso 2: mover (mano y aro juntos) */
  #jv-intro[data-step="2"] .ha{transform:translate(327px,391px)}#jv-intro[data-step="2"] .ha .finger,#jv-intro[data-step="2"] .ha .thumb{transition:none}
  #jv-intro[data-step="2"] .world{animation:slide 5.2s ease-in-out infinite}#jv-intro[data-step="2"] .shadow{transform:scale(1.12) translate(0,10px);opacity:.28}
  @keyframes slide{0%,10%{transform:translateX(0)}45%{transform:translateX(-130px)}80%,100%{transform:translateX(90px)}}
  /* paso 3: girar la mano → la pieza gira igual (en el plano y volteo) */
  #jv-intro[data-step="3"] .ha{transform:translate(327px,391px)}
  #jv-intro[data-step="3"] .world{transform-origin:320px 200px;animation:turn 5.2s ease-in-out infinite}
  #jv-intro[data-step="3"] .ringtilt{animation:tilt 5.2s ease-in-out infinite}#jv-intro[data-step="3"] .shadow{transform:scale(1.12) translate(0,10px);opacity:.28}
  @keyframes turn{0%,10%{transform:rotate(0)}50%{transform:rotate(-40deg)}90%,100%{transform:rotate(25deg)}}
  @keyframes tilt{0%,10%{transform:scaleY(1)}50%{transform:scaleY(.38)}90%,100%{transform:scaleY(.8)}}
  /* paso 4: dos pellizcos → separar agranda, girar las dos manos rota sobre el tapete */
  #jv-intro[data-step="4"] .ha{animation:twoA 5.2s ease-in-out infinite}#jv-intro[data-step="4"] .hb{display:block;animation:twoB 5.2s ease-in-out infinite}
  #jv-intro[data-step="4"] .ringwrap{transform-origin:320px 200px;animation:grow 5.2s ease-in-out infinite}
  #jv-intro[data-step="4"] .world{transform-origin:320px 200px;animation:turn2 5.2s ease-in-out infinite}
  @keyframes turn2{0%,60%{transform:rotate(0)}90%,100%{transform:rotate(-30deg)}}
  @keyframes twoA{0%,15%{transform:translate(257px,319px)}55%,100%{transform:translate(226px,319px)}}
  @keyframes twoB{0%,15%{transform:translate(383px,319px) scaleX(-1)}55%,100%{transform:translate(414px,319px) scaleX(-1)}}
  @keyframes grow{0%,15%{transform:scale(1)}55%,100%{transform:scale(1.45)}}
  /* paso 5: soltar → abrir los dedos y retirar la mano; la pieza se queda apoyada */
  #jv-intro[data-step="5"] .ha{animation:leave 5.2s ease-in-out infinite}
  #jv-intro[data-step="5"] .ha .f1{animation:f1open 5.2s ease-in-out infinite}#jv-intro[data-step="5"] .ha .thumb{animation:thopen 5.2s ease-in-out infinite}
  #jv-intro[data-step="5"] .ha .f2,#jv-intro[data-step="5"] .ha .f3,#jv-intro[data-step="5"] .ha .f4{animation:uncurl 5.2s ease-in-out infinite}
  #jv-intro[data-step="5"] .shadow{animation:drop 5.2s ease-in-out infinite}
  @keyframes leave{0%,45%{transform:translate(327px,391px)}80%,100%{transform:translate(327px,540px)}}
  @keyframes f1open{0%,20%{transform:rotate(25deg) scaleY(.7)}40%,100%{transform:none}}
  @keyframes thopen{0%,20%{transform:rotate(80deg) translate(10px,-20px)}40%,100%{transform:none}}
  @keyframes uncurl{0%,20%{transform:scaleY(.72)}40%,100%{transform:none}}
  @keyframes drop{0%,30%{transform:scale(1.12) translate(0,10px);opacity:.28}50%,100%{transform:scale(1);opacity:.18}}
  @media (prefers-reduced-motion: reduce){#iscene *{animation-duration:.01s!important}}

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
      <button id="zoom" title="<?php echo get_string('stem_visor_zoom_help', 'local_jedomi'); ?>" aria-pressed="true">🔍 <?php echo get_string('stem_visor_zoom', 'local_jedomi'); ?></button>
    </div>
  </div>
  <div class="vpanel" id="status">—</div>
  <div class="vpanel" id="ia"></div>
  <div id="jv-intro" data-step="1" role="dialog" aria-modal="true" aria-labelledby="intro-title">
    <div class="ipanel">
      <div class="ihead"><b id="intro-title"><?php echo get_string('stem_visor_intro_title', 'local_jedomi'); ?></b><button id="intro-skip"><?php echo get_string('stem_visor_intro_skip', 'local_jedomi'); ?> ›</button></div>
      <svg id="iscene" viewBox="0 0 640 400" aria-hidden="true" focusable="false">
        <rect class="mat" x="60" y="40" width="520" height="260" rx="10"/>
        <g class="aruco"><rect x="76" y="56" width="34" height="34"/><rect x="530" y="56" width="34" height="34"/><rect x="76" y="250" width="34" height="34"/><rect x="530" y="250" width="34" height="34"/></g>
        <g class="world">
          <g class="ringwrap"><ellipse class="shadow" cx="320" cy="200" rx="72" ry="72"/><g class="ringtilt" style="transform-origin:320px 200px"><circle class="ring" cx="320" cy="200" r="70"/><circle class="ringin" cx="320" cy="200" r="52"/></g></g>
          <g class="hand ha"><path class="palm" d="M-42 0 Q-48 -40 -36 -72 L36 -72 Q48 -40 42 0 Q30 22 0 24 Q-30 22 -42 0 Z"/>
          <g class="finger f1" style="transform-origin:-27px -70px"><rect x="-37" y="-140" width="20" height="74" rx="10"/></g>
          <g class="finger f2" style="transform-origin:-9px -72px"><rect x="-19" y="-158" width="20" height="90" rx="10"/></g>
          <g class="finger f3" style="transform-origin:9px -72px"><rect x="-1" y="-152" width="20" height="84" rx="10"/></g>
          <g class="finger f4" style="transform-origin:27px -70px"><rect x="17" y="-134" width="20" height="68" rx="10"/></g>
          <g class="thumb" style="transform-origin:-40px -30px"><rect x="-104" y="-46" width="68" height="22" rx="11" transform="rotate(25 -40 -30)"/></g></g>
          <g class="hand hb"><path class="palm" d="M-42 0 Q-48 -40 -36 -72 L36 -72 Q48 -40 42 0 Q30 22 0 24 Q-30 22 -42 0 Z"/>
          <g class="finger f1" style="transform-origin:-27px -70px"><rect x="-37" y="-140" width="20" height="74" rx="10"/></g>
          <g class="finger f2" style="transform-origin:-9px -72px"><rect x="-19" y="-158" width="20" height="90" rx="10"/></g>
          <g class="finger f3" style="transform-origin:9px -72px"><rect x="-1" y="-152" width="20" height="84" rx="10"/></g>
          <g class="finger f4" style="transform-origin:27px -70px"><rect x="17" y="-134" width="20" height="68" rx="10"/></g>
          <g class="thumb" style="transform-origin:-40px -30px"><rect x="-104" y="-46" width="68" height="22" rx="11" transform="rotate(-25 -40 -30)"/></g></g>
        </g>
      </svg>
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
<script type="importmap">
{ "imports": { "three": "<?php echo $base; ?>vendor/three/three.module.js",
               "three/addons/": "<?php echo $base; ?>vendor/three/" } }
</script>
<script>try { sessionStorage.setItem('jedomi_open', '1'); } catch (e) { }
window.JEDOMI_VISOR = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $base; ?>vendor/cv.js"></script>
<script src="<?php echo $base; ?>vendor/aruco.js"></script>
<script src="<?php echo $base; ?>vendor/dictionaries/aruco_4x4_1000.js"></script>
<script src="<?php echo $base; ?>intro.js?v=<?php echo (int) get_config('local_jedomi', 'version'); ?>"></script>
<script type="module" src="<?php echo $base; ?>visor.js?v=<?php echo (int) get_config('local_jedomi', 'version'); ?>"></script>
<?php
echo $OUTPUT->footer();
