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

$base = (new moodle_url('/local/jedomi/stem/visor/'))->out(false);
$config = [
    'cmid' => $cmid, 'courseid' => (int) $course->id, 'modelUrl' => $modelurl, 'modelName' => $model ? $model->get_filename() : '',
    'base' => $base, 'sesskey' => sesskey(), 'enabled' => $enabled, 'userid' => (int) $USER->id,
    'voiceEnabled' => (int) get_config('local_jedomi', 'stem_voice_enabled') === 1,
    'strings' => ['nomodel' => get_string('stem_visor_nomodel', 'local_jedomi'), 'intro' => get_string('stem_visor_intro', 'local_jedomi'),
        'talk' => get_string('stem_visor_talk', 'local_jedomi'), 'talk_stop' => get_string('stem_visor_talk_stop', 'local_jedomi'),
        'voice_consent' => get_string('stem_visor_voice_consent', 'local_jedomi'), 'voice_off' => get_string('stem_visor_voice_off', 'local_jedomi'),
        'intro_next' => get_string('stem_visor_intro_next', 'local_jedomi'), 'intro_done' => get_string('stem_visor_intro_done', 'local_jedomi'),
        'intro_s1' => get_string('stem_visor_intro_s1', 'local_jedomi'), 'intro_s2' => get_string('stem_visor_intro_s2', 'local_jedomi'), 'intro_s3' => get_string('stem_visor_intro_s3', 'local_jedomi'),
        'intro_s4' => get_string('stem_visor_intro_s4', 'local_jedomi'), 'intro_s5' => get_string('stem_visor_intro_s5', 'local_jedomi')],
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
  #start .tut{max-width:720px}
  .tuthead{margin:12px 0 8px;font-weight:600;color:#fbbf24}
  .cards{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .card{display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.06);border-radius:10px;padding:8px 10px;font-size:14px;line-height:1.35}
  .card svg{width:64px;height:64px;flex:none;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
  .card .ring{stroke:#f59e0b}.card .fin{stroke:#e5e7eb}.card .dot{fill:#22c55e;stroke:none}.card .arr{stroke:#60a5fa}
  .tutnote{margin:10px 0 12px;font-size:14px;color:#9aa3af}
  @media (max-width:900px){.cards{grid-template-columns:1fr}}
  /* ── intro animada ── */
  #intro{position:absolute;inset:0;z-index:5;display:grid;place-items:center;background:rgba(0,0,0,.86)}
  .ipanel{width:min(760px,96%);background:#0f1115;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 18px;color:#f3f4f6}
  .ihead{display:flex;justify-content:space-between;align-items:center;font-size:17px}
  .ihead button{background:transparent;color:#9aa3af;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:14px}
  #iscene{width:100%;height:auto;display:block;margin:10px 0;background:#15181f;border-radius:10px}
  #intro-caption{font-size:18px;line-height:1.4;min-height:52px}
  .ifoot{display:flex;justify-content:space-between;align-items:center;margin-top:8px}
  #intro-dots span{display:inline-block;width:10px;height:10px;border-radius:50%;background:#374151;margin-right:6px}#intro-dots span.on{background:#f59e0b}
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
  #intro[data-step="1"] .ha{animation:reach 5.2s ease-in-out infinite}
  #intro[data-step="1"] .ha .f1{animation:f1pinch 5.2s ease-in-out infinite}#intro[data-step="1"] .ha .thumb{animation:thpinch 5.2s ease-in-out infinite}
  #intro[data-step="1"] .ha .f2,#intro[data-step="1"] .ha .f3,#intro[data-step="1"] .ha .f4{animation:curl 5.2s ease-in-out infinite}
  #intro[data-step="1"] .shadow{animation:lift 5.2s ease-in-out infinite}
  @keyframes reach{0%{transform:translate(327px,540px)}35%,100%{transform:translate(327px,391px)}}
  @keyframes f1pinch{0%,35%{transform:none}55%,100%{transform:rotate(25deg) scaleY(.7)}}
  @keyframes thpinch{0%,35%{transform:none}55%,100%{transform:rotate(80deg) translate(10px,-20px)}}
  @keyframes curl{0%,35%{transform:none}55%,100%{transform:scaleY(.72)}}
  @keyframes lift{0%,50%{transform:scale(1);opacity:.18}65%,100%{transform:scale(1.12) translate(0,10px);opacity:.28}}
  #intro[data-step="2"] .ha .f1,#intro[data-step="3"] .ha .f1,#intro[data-step="4"] .hand .f1{transform:rotate(25deg) scaleY(.7)}
  #intro[data-step="2"] .ha .thumb,#intro[data-step="3"] .ha .thumb,#intro[data-step="4"] .hand .thumb{transform:rotate(80deg) translate(10px,-20px)}
  #intro[data-step="2"] .ha .f2,#intro[data-step="2"] .ha .f3,#intro[data-step="2"] .ha .f4,#intro[data-step="3"] .ha .f2,#intro[data-step="3"] .ha .f3,#intro[data-step="3"] .ha .f4,#intro[data-step="4"] .hand .f2,#intro[data-step="4"] .hand .f3,#intro[data-step="4"] .hand .f4{transform:scaleY(.72)}
  /* paso 2: mover (mano y aro juntos) */
  #intro[data-step="2"] .ha{transform:translate(327px,391px)}#intro[data-step="2"] .ha .finger,#intro[data-step="2"] .ha .thumb{transition:none}
  #intro[data-step="2"] .world{animation:slide 5.2s ease-in-out infinite}#intro[data-step="2"] .shadow{transform:scale(1.12) translate(0,10px);opacity:.28}
  @keyframes slide{0%,10%{transform:translateX(0)}45%{transform:translateX(-130px)}80%,100%{transform:translateX(90px)}}
  /* paso 3: girar la mano → la pieza gira igual (en el plano y volteo) */
  #intro[data-step="3"] .ha{transform:translate(327px,391px)}
  #intro[data-step="3"] .world{transform-origin:320px 200px;animation:turn 5.2s ease-in-out infinite}
  #intro[data-step="3"] .ringtilt{animation:tilt 5.2s ease-in-out infinite}#intro[data-step="3"] .shadow{transform:scale(1.12) translate(0,10px);opacity:.28}
  @keyframes turn{0%,10%{transform:rotate(0)}50%{transform:rotate(-40deg)}90%,100%{transform:rotate(25deg)}}
  @keyframes tilt{0%,10%{transform:scaleY(1)}50%{transform:scaleY(.38)}90%,100%{transform:scaleY(.8)}}
  /* paso 4: dos pellizcos → separar agranda, girar las dos manos rota sobre el tapete */
  #intro[data-step="4"] .ha{animation:twoA 5.2s ease-in-out infinite}#intro[data-step="4"] .hb{display:block;animation:twoB 5.2s ease-in-out infinite}
  #intro[data-step="4"] .ringwrap{transform-origin:320px 200px;animation:grow 5.2s ease-in-out infinite}
  #intro[data-step="4"] .world{transform-origin:320px 200px;animation:turn2 5.2s ease-in-out infinite}
  @keyframes turn2{0%,60%{transform:rotate(0)}90%,100%{transform:rotate(-30deg)}}
  @keyframes twoA{0%,15%{transform:translate(257px,319px)}55%,100%{transform:translate(226px,319px)}}
  @keyframes twoB{0%,15%{transform:translate(383px,319px) scaleX(-1)}55%,100%{transform:translate(414px,319px) scaleX(-1)}}
  @keyframes grow{0%,15%{transform:scale(1)}55%,100%{transform:scale(1.45)}}
  /* paso 5: soltar → abrir los dedos y retirar la mano; la pieza se queda apoyada */
  #intro[data-step="5"] .ha{animation:leave 5.2s ease-in-out infinite}
  #intro[data-step="5"] .ha .f1{animation:f1open 5.2s ease-in-out infinite}#intro[data-step="5"] .ha .thumb{animation:thopen 5.2s ease-in-out infinite}
  #intro[data-step="5"] .ha .f2,#intro[data-step="5"] .ha .f3,#intro[data-step="5"] .ha .f4{animation:uncurl 5.2s ease-in-out infinite}
  #intro[data-step="5"] .shadow{animation:drop 5.2s ease-in-out infinite}
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
  <video id="video" autoplay playsinline muted></video>
  <canvas id="three"></canvas>
  <div id="hud">
    <div class="vpanel" id="step">…</div>
    <div id="ctl">
      <select id="model"><option value="aro" selected>1) Aro de prueba</option><?php if ($model) { ?><option value="<?php echo s($modelurl); ?>">2) Pieza de la actividad: <?php echo s($model->get_filename()); ?></option><?php } ?></select>
      <?php if ($config['voiceEnabled']) { ?><button id="talk" class="primary"><?php echo get_string('stem_visor_talk', 'local_jedomi'); ?></button><?php } ?>
      <button id="explain"><?php echo get_string('stem_visor_explain', 'local_jedomi'); ?></button>
      <button id="reset"><?php echo get_string('stem_visor_reset', 'local_jedomi'); ?></button>
      <button id="quit" class="danger">X</button>
    </div>
  </div>
  <div class="vpanel" id="status">—</div>
  <div class="vpanel" id="ia"></div>
  <div id="intro" data-step="1">
    <div class="ipanel">
      <div class="ihead"><b><?php echo get_string('stem_visor_intro_title', 'local_jedomi'); ?></b><button id="intro-skip"><?php echo get_string('stem_visor_intro_skip', 'local_jedomi'); ?> ›</button></div>
      <svg id="iscene" viewBox="0 0 640 400">
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
      <div id="intro-caption">…</div>
      <div class="ifoot"><div id="intro-dots"></div><button id="intro-next" class="primary"><?php echo get_string('stem_visor_intro_next', 'local_jedomi'); ?></button></div>
    </div>
  </div>
  <div id="start"><div class="vpanel tut">
    <b><?php echo get_string('stem_visor_title', 'local_jedomi'); ?></b> · <?php echo format_string($cm->name); ?>
    <div class="tuthead"><?php echo get_string('stem_visor_tut_title', 'local_jedomi'); ?></div>
    <div class="cards">
      <div class="card"><svg viewBox="0 0 64 64"><circle cx="32" cy="36" r="14" class="ring"/><path d="M14 10 Q20 22 26 30" class="fin"/><path d="M40 8 Q36 20 32 28" class="fin"/><circle cx="29" cy="30" r="3" class="dot"/></svg>
        <div><b>①</b> <?php echo get_string('stem_visor_tut_grab', 'local_jedomi'); ?></div></div>
      <div class="card"><svg viewBox="0 0 64 64"><ellipse cx="32" cy="36" rx="14" ry="6" class="ring"/><path d="M12 24 A22 22 0 0 1 52 24" class="arr"/><path d="M48 18 L52 24 L46 27" class="arr"/><path d="M26 18 Q32 10 38 18" class="fin"/></svg>
        <div><b>②</b> <?php echo get_string('stem_visor_tut_rotate', 'local_jedomi'); ?></div></div>
      <div class="card"><svg viewBox="0 0 64 64"><circle cx="32" cy="34" r="12" class="ring"/><circle cx="14" cy="34" r="3" class="dot"/><circle cx="50" cy="34" r="3" class="dot"/><path d="M4 34 L10 34 M54 34 L60 34" class="arr"/><path d="M6 31 L4 34 L6 37 M58 31 L60 34 L58 37" class="arr"/></svg>
        <div><b>③</b> <?php echo get_string('stem_visor_tut_two', 'local_jedomi'); ?></div></div>
      <div class="card"><svg viewBox="0 0 64 64"><circle cx="32" cy="42" r="10" class="ring"/><path d="M18 26 L10 12 M28 22 L26 6 M38 22 L42 6 M46 26 L56 14" class="fin"/></svg>
        <div><b>④</b> <?php echo get_string('stem_visor_tut_release', 'local_jedomi'); ?></div></div>
    </div>
    <div class="tutnote"><?php echo $model ? get_string('stem_visor_tut_mat', 'local_jedomi') : get_string('stem_visor_nomodel', 'local_jedomi'); ?></div>
    <button id="go" class="primary"><?php echo get_string('stem_visor_start', 'local_jedomi'); ?></button>
    <a href="#" id="intro-replay" class="replay"><?php echo get_string('stem_visor_intro_replay', 'local_jedomi'); ?></a>
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
