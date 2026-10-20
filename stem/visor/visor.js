// Visor STEM AR (prototipo local, base del visor de Jedomi): cámara → ArUco (js-aruco2) → pose del tapete → Three.js (GLB pesados)
// + MediaPipe HandLandmarker (gestos portados de demo_guiada.py v12.3: pellizco en el borde coge/mueve/gira, dos pellizcos giran/escalan).
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { initPrejoin } from "./devices.js";
import { buildParts, applyParts, setHighlight, pickPart, resetPart } from "./parts.js";
import { FilesetResolver, HandLandmarker } from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/vision_bundle.mjs";

// ---------- geometría del tapete (idéntica a stem_vision.py)
const MAT_W = 800, MAT_H = 400, MARKER = 60, MARGIN = 20;
function markerCornersMM(id) {
  const m = MARGIN, s = MARKER;
  const x0 = { 0: m, 3: m, 1: MAT_W - m - s, 2: MAT_W - m - s }[id];
  const y0 = { 0: m, 1: m, 2: MAT_H - m - s, 3: MAT_H - m - s }[id];
  return [[x0, y0], [x0 + s, y0], [x0 + s, y0 + s], [x0, y0 + s]];
}

// ---------- filtro One Euro (como filters.py)
class OneEuro {
  constructor(minCutoff = 1, beta = 0, dCutoff = 1) { this.mc = minCutoff; this.beta = beta; this.dc = dCutoff; this.x = null; this.dx = null; this.t = null; }
  alpha(c, dt) { const tau = 1 / (2 * Math.PI * c); return 1 / (1 + tau / dt); }
  reset() { this.x = this.dx = this.t = null; }
  filter(v, t) {
    if (!this.x || t <= this.t) { this.x = v.slice(); this.dx = v.map(() => 0); this.t = t; return v.slice(); }
    const dt = Math.max(1e-3, t - this.t), ad = this.alpha(this.dc, dt);
    const dx = v.map((x, i) => (x - this.x[i]) / dt), dxh = dx.map((d, i) => ad * d + (1 - ad) * this.dx[i]);
    const speed = Math.hypot(...dxh), a = this.alpha(this.mc + this.beta * speed, dt);
    const xh = v.map((x, i) => a * x + (1 - a) * this.x[i]);
    this.x = xh; this.dx = dxh; this.t = t; return xh.slice();
  }
}

// ---------- álgebra: homografía DLT + descomposición a pose (K conocida)
function solveHomography(src, dst) {           // src: mm del tapete, dst: píxeles
  const A = [];
  for (let i = 0; i < src.length; i++) {
    const [x, y] = src[i], [u, v] = dst[i];
    A.push([-x, -y, -1, 0, 0, 0, u * x, u * y, u]);
    A.push([0, 0, 0, -x, -y, -1, v * x, v * y, v]);
  }
  // h = autovector del menor autovalor de AᵀA (iteración inversa simple sobre 9×9)
  const M = Array.from({ length: 9 }, () => new Array(9).fill(0));
  for (const r of A) for (let i = 0; i < 9; i++) for (let j = 0; j < 9; j++) M[i][j] += r[i] * r[j];
  let h = new Array(9).fill(1 / 3);
  const Minv = invert9(M.map((row, i) => row.map((v, j) => v + (i === j ? 1e-9 : 0))));
  if (!Minv) return null;
  for (let it = 0; it < 30; it++) { const nh = mulMatVec(Minv, h); const n = Math.hypot(...nh); h = nh.map(v => v / n); }
  return [[h[0], h[1], h[2]], [h[3], h[4], h[5]], [h[6], h[7], h[8]]];
}
function mulMatVec(M, v) { return M.map(r => r.reduce((s, x, i) => s + x * v[i], 0)); }
function invert9(M) {                           // Gauss-Jordan
  const n = M.length, A = M.map((r, i) => [...r, ...Array.from({ length: n }, (_, j) => (i === j ? 1 : 0))]);
  for (let c = 0; c < n; c++) {
    let p = c; for (let r = c + 1; r < n; r++) if (Math.abs(A[r][c]) > Math.abs(A[p][c])) p = r;
    if (Math.abs(A[p][c]) < 1e-14) return null;
    [A[c], A[p]] = [A[p], A[c]];
    const d = A[c][c]; for (let j = 0; j < 2 * n; j++) A[c][j] /= d;
    for (let r = 0; r < n; r++) if (r !== c) { const f = A[r][c]; for (let j = 0; j < 2 * n; j++) A[r][j] -= f * A[c][j]; }
  }
  return A.map(r => r.slice(n));
}
function poseFromHomography(H, fx, fy, cx, cy) {   // H = K [r1 r2 t] (coordenadas OpenCV: x→, y↓, z hacia la escena)
  const Kinv = [[1 / fx, 0, -cx / fx], [0, 1 / fy, -cy / fy], [0, 0, 1]];
  const B = Kinv.map(r => [0, 1, 2].map(j => r[0] * H[0][j] + r[1] * H[1][j] + r[2] * H[2][j]));
  let r1 = [B[0][0], B[1][0], B[2][0]], r2 = [B[0][1], B[1][1], B[2][1]], t = [B[0][2], B[1][2], B[2][2]];
  const l = (Math.hypot(...r1) + Math.hypot(...r2)) / 2; r1 = r1.map(v => v / l); r2 = r2.map(v => v / l); t = t.map(v => v / l);
  if (t[2] < 0) { r1 = r1.map(v => -v); r2 = r2.map(v => -v); t = t.map(v => -v); }
  // ortonormalizar r1,r2 (Gram-Schmidt) y r3 = r1 × r2
  const d = r1[0] * r2[0] + r1[1] * r2[1] + r1[2] * r2[2]; r2 = r2.map((v, i) => v - d * r1[i]); const n2 = Math.hypot(...r2); r2 = r2.map(v => v / n2);
  const r3 = [r1[1] * r2[2] - r1[2] * r2[1], r1[2] * r2[0] - r1[0] * r2[2], r1[0] * r2[1] - r1[1] * r2[0]];
  return { R: [r1, r2, r3], t };                  // columnas r1 r2 r3
}

// ---------- integración Moodle (window.JEDOMI_VISOR lo inyecta stem/visor.php); sin ella funciona como prototipo local
const MOODLE = window.JEDOMI_VISOR || null;
const TELEMETRY = new URLSearchParams(location.search).get("telemetry") === "1"; let telemetryT = 0;
const BASE = MOODLE ? MOODLE.base : "";
// ---------- estado
const video = document.getElementById("video"), canvas = document.getElementById("three");
const stepEl = document.getElementById("step"), statusEl = document.getElementById("status"), iaEl = document.getElementById("ia");
let W = 1280, Hh = 720, FX = 0.9;                   // FX·W ≈ focal estimada (demo_guiada)
const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, preserveDrawingBuffer: true });   // la captura de «Explícame» necesita leer el lienzo 3D
renderer.setPixelRatio(Math.min(2, window.devicePixelRatio)); renderer.shadowMap.enabled = true;
const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(50, 16 / 9, 10, 20000);  // mm
scene.add(new THREE.HemisphereLight(0xffffff, 0x334455, 1.1));
const sun = new THREE.DirectionalLight(0xffffff, 1.4); sun.position.set(-300, -400, -900); sun.castShadow = true;
sun.shadow.mapSize.set(2048, 2048); sun.shadow.camera.left = -800; sun.shadow.camera.right = 800; sun.shadow.camera.top = 800; sun.shadow.camera.bottom = -800; sun.shadow.camera.far = 4000;
scene.add(sun);
const mat = new THREE.Group(); scene.add(mat);         // marco del tapete: X→ derecha (mm), Y→ abajo (mm), Z→ hacia la cámara (negativo = arriba del tapete en OpenCV)
const ground = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W, MAT_H), new THREE.ShadowMaterial({ opacity: 0.35 }));
ground.position.set(MAT_W / 2, MAT_H / 2, 0); ground.receiveShadow = true; mat.add(ground);
const frame = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.PlaneGeometry(MAT_W, MAT_H)), new THREE.LineBasicMaterial({ color: 0x22c55e }));
frame.position.set(MAT_W / 2, MAT_H / 2, 0); mat.add(frame);
const objGroup = new THREE.Group(); mat.add(objGroup);  // la pieza: posición (cx, cy, -lift), rotación R, escala para tamaño en mm
const obj = { cx: MAT_W / 2, cy: MAT_H / 2, size: 260, yaw: 0, rot: new THREE.Quaternion(), mesh: null, tris: 0, bbox: null };   // rot: orientación 3D (marco del tapete); yaw se deriva de rot
function yawOf(q) { const v = new THREE.Vector3(1, 0, 0).applyQuaternion(q); return Math.atan2(v.y, v.x); }
function tiltDeg(q) { const v = new THREE.Vector3(0, 0, 1).applyQuaternion(q); return Math.acos(Math.min(1, Math.max(-1, v.z))) * 180 / Math.PI; }
function matQuat() { const R = lastPose.pose.R; return new THREE.Quaternion().setFromRotationMatrix(new THREE.Matrix4().set(R[0][0], R[1][0], R[2][0], 0, R[0][1], R[1][1], R[2][1], 0, R[0][2], R[1][2], R[2][2], 0, 0, 0, 0, 1)); }   // tapete→cámara (R en columnas r1 r2 r3)
let poseOK = false, poseFrozen = false, nmark = 0, lastPose = null, fps = 0, frames = 0, fpsT = performance.now(); let frameNo = 0, lastMarkers = [];
const cornerFilters = new Map();
const detector = new AR.Detector({ dictionaryName: "ARUCO_4X4_1000", maxHammingDistance: 2 });
const work = document.createElement("canvas"), wctx = work.getContext("2d", { willReadFrequently: true });

// ---------- pieza
const loader = new GLTFLoader();
function showCredit(text) {                            // pie de crédito del modelo, discreto, abajo a la derecha (texto plano, sin HTML)
  let el = document.getElementById("credit");
  if (!el) { el = document.createElement("div"); el.id = "credit"; document.getElementById("stage").appendChild(el); }
  el.textContent = text; el.hidden = !text; el.title = text;
}
async function loadModel(spec) {
  objGroup.clear(); obj.mesh = null; obj.tris = 0;
  let root;
  if (spec === "aro") {
    root = new THREE.Mesh(new THREE.TorusGeometry(0.4, 0.1, 24, 48), new THREE.MeshStandardMaterial({ color: 0xffc83c, metalness: 0.2, roughness: 0.5 }));
  } else {
    const gltf = await loader.loadAsync(spec); root = gltf.scene;
    showCredit(gltf.parser?.json?.asset?.copyright || "");   // atribución del modelo (campo estándar glTF asset.copyright), p. ej. CC BY
  }
  root.traverse(o => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; const g = o.geometry; obj.tris += g.index ? g.index.count / 3 : g.attributes.position.count / 3; } });
  // normalizar: centrar y escalar la dimensión mayor a 1 (luego obj.size en mm)
  const box = new THREE.Box3().setFromObject(root); const sz = new THREE.Vector3(); box.getSize(sz); const c = new THREE.Vector3(); box.getCenter(c);
  const k = 1 / Math.max(sz.x, sz.y, sz.z); root.position.sub(c).multiplyScalar(k); root.scale.setScalar(k);
  const wrap = new THREE.Group(); wrap.add(root);
  // el modelo viene con Y arriba; nuestro marco tiene Z hacia la cámara (−Z = arriba del tapete): girar −90° en X para que «arriba» = −Z
  wrap.rotation.x = -Math.PI / 2;
  objGroup.add(wrap); obj.mesh = wrap; obj.root = root; obj.yaw = 0; obj.rot.identity(); objGroup.rotation.set(0, 0, 0);
  obj.bbox = new THREE.Box3().setFromObject(wrap);
  pieces.list = buildParts(root, spec === MOODLE?.modelUrl ? MOODLE?.parts : null);   // piezas descritas por el docente (mod_jedomiar) o catálogo pieces.explode = pieces.target = 0; pieces.sel = pieces.hover = null; refreshPiecesUI();
  log("piezas: " + pieces.list.length + (pieces.list.length ? " (" + pieces.list.slice(0, 6).map(p => p.name).join(", ") + "…)" : ""));
  console.log("modelo", spec, "triángulos", Math.round(obj.tris));
}
function placeObject(lifted) {
  if (!obj.mesh) return;
  const s = obj.size; objGroup.scale.setScalar(s);
  const gap = s * (lifted ? 0.45 : 0.04);
  // orientación completa = giro sobre el tapete (Z) ∘ volteo 3D; la pieza apoya su punto más bajo (max.z tras rotar) a «gap» del plano
  const q = obj.rot; obj.yaw = yawOf(q);
  let low = s * 0.5;
  if (obj.bbox) { low = -Infinity; const b = obj.bbox, v = new THREE.Vector3();
    for (const x of [b.min.x, b.max.x]) for (const y of [b.min.y, b.max.y]) for (const z of [b.min.z, b.max.z]) { v.set(x, y, z).applyQuaternion(q); if (v.z * s > low) low = v.z * s; } }
  objGroup.position.set(obj.cx, obj.cy, -(gap + low));
  objGroup.quaternion.copy(q);
}

// ---------- manos
let hands = null; const H = new Map();              // identidad por posición, como demo_guiada
class Hand {
  constructor(label) { this.label = label; this.pts = null; this.raw = null; this.palm = null; this.pinch = false; this.pinchCm = null; this.pinchPx = null; this.pinchMM = null; this.last = 0; this.f = new OneEuro(2.5, 0.08); this.qf = new OneEuro(0.6, 1.5); this.qRaw = null; this.qHand = null; this.holding = false; this.otherHolding = false; this.openSince = null; }
  update(lm, wlm, t) {
    const raw = lm.map(p => [p.x * W, p.y * Hh]); this.raw = raw;
    const flat = this.f.filter(raw.flat(), t); this.pts = []; for (let i = 0; i < 21; i++) this.pts.push([flat[2 * i], flat[2 * i + 1]]);
    this.palm = [0, 5, 9, 13, 17].reduce((a, i) => [a[0] + raw[i][0] / 5, a[1] + raw[i][1] / 5], [0, 0]);
    this.pinchPx = [(raw[4][0] + raw[8][0]) / 2, (raw[4][1] + raw[8][1]) / 2];
    this.pinchFilt = [(this.pts[4][0] + this.pts[8][0]) / 2, (this.pts[4][1] + this.pts[8][1]) / 2];
    const w4 = wlm[4], w8 = wlm[8]; this.pinchCm = Math.hypot(w4.x - w8.x, w4.y - w8.y, w4.z - w8.z) * 100;
    const idxExt = Math.hypot(raw[8][0] - raw[0][0], raw[8][1] - raw[0][1]) / (Math.hypot(raw[5][0] - raw[0][0], raw[5][1] - raw[0][1]) + 1e-6);
    const openThr = this.holding ? 7.5 : 5.0, easy = this.otherHolding;
    const now = this.pinch ? this.pinchCm < openThr : (this.pinchCm < 3.5 && (easy || idxExt > 1.3));
    if (now) { this.openSince = null; this.pinch = true; } else { this.openSince = this.openSince ?? t; if (t - this.openSince > 0.10 || !this.pinch) this.pinch = false; }
    this.fingerAngle = Math.atan2(raw[8][1] - raw[4][1], raw[8][0] - raw[4][0]);
    // marco de la mano (v13.1, modelo rígido): x = muñeca→nudillo índice, plano con muñeca→nudillo meñique, z = normal de la palma.
    // Su orientación (cuaternión) se filtra con One Euro sobre las 4 componentes con continuidad de signo: la pieza cogida gira igual que la mano.
    // Marco: landmarks de IMAGEN (x→ derecha, y↓ en píxeles) con su profundidad z (misma escala que x: z·W; menor = más cerca de la cámara),
    // es decir, el marco de cámara de OpenCV, el mismo en que está la pose del tapete (QA v13: los worldLandmarks usan otra convención).
    { const P = k => [lm[k].x * W, lm[k].y * Hh, lm[k].z * W]; const w0 = P(0), w5 = P(5), w17 = P(17);
      let x = [w5[0] - w0[0], w5[1] - w0[1], w5[2] - w0[2]]; const lx = Math.hypot(...x) || 1; x = x.map(v => v / lx);
      const yt = [w17[0] - w0[0], w17[1] - w0[1], w17[2] - w0[2]]; let z = cross3(x, yt); const lz = Math.hypot(...z) || 1; z = z.map(v => v / lz); const y = cross3(z, x);
      const q = new THREE.Quaternion().setFromRotationMatrix(new THREE.Matrix4().set(x[0], y[0], z[0], 0, x[1], y[1], z[1], 0, x[2], y[2], z[2], 0, 0, 0, 0, 1));
      const pr = this.qRaw; if (pr && q.x * pr[0] + q.y * pr[1] + q.z * pr[2] + q.w * pr[3] < 0) { q.x = -q.x; q.y = -q.y; q.z = -q.z; q.w = -q.w; }
      this.qRaw = [q.x, q.y, q.z, q.w]; const f = this.qf.filter(this.qRaw, t); this.qHand = new THREE.Quaternion(f[0], f[1], f[2], f[3]).normalize(); }
    this.last = t;
  }
}
// píxel → mm del tapete usando la homografía inversa de la pose actual
let Hinv = null;
function pxToMM(p) { if (!Hinv) return null; const v = mulMatVec(Hinv, [p[0], p[1], 1]); return [v[0] / v[2], v[1] / v[2]]; }
// silueta del objeto en pantalla (caja envolvente proyectada)
function objectHullPx() {
  if (!obj.mesh || !poseOK) return null;
  const b = new THREE.Box3().setFromObject(objGroup); const pts = [];
  for (const x of [b.min.x, b.max.x]) for (const y of [b.min.y, b.max.y]) for (const z of [b.min.z, b.max.z]) {
    const v = new THREE.Vector3(x, y, z).project(camera); pts.push([(v.x + 1) / 2 * W, (1 - v.y) / 2 * Hh]);
  }
  const xs = pts.map(p => p[0]), ys = pts.map(p => p[1]);
  return { x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys) };
}
function overPx(hull, p, margin) { return hull && p[0] > hull.x0 - margin && p[0] < hull.x1 + margin && p[1] > hull.y0 - margin && p[1] < hull.y1 + margin; }

// ---------- gestos (v13 — modelo rígido, «la pieza queda pegada a la mano»)
// Estándar de gestos Jedomi RA v1 (ver python/ESTANDAR_GESTOS_RA.md): 1) pellizco en el borde = coger; mientras está cogida la pieza
// SIGUE A LA MANO: se traslada con el pellizco y gira exactamente como gira la mano (3D, 1:1), como un objeto físico; 2) dos pellizcos =
// escalar y girar sobre el tapete; 3) abrir los dedos = soltar; la pieza conserva posición y orientación. Mismo modelo que la manipulación
// directa de visionOS (pinch+drag, rotación con la mano, dos manos para escala).
let heldBy = null, grabOff = [0, 0], two = null; const objFilter = new OneEuro(1.3, 0.04);
let pinchHold = new Set(), holdPts = [], lastHoldT = 0;
let qHand0 = null, rot0 = null, rotArmed = false; const ROT_DEAD = 6 * Math.PI / 180;   // 6° para empezar a girar (luego sigue 1:1, re-anclado al armar: sin salto)
let gestureName = "libre";
const GESTURE_HINT = { "modo piezas": "🧩 Modo piezas: pasa el ratón para ver el nombre; arrastra o pellizca una pieza para sacarla; clic derecho y arrastrar la gira; el panel de controles la mueve con precisión.", "pieza cogida": "🧩 Pieza cogida: muévela y gira la mano para girarla; abre los dedos para soltarla. «Devolver a su sitio» la recoloca.", "ratón": "🖱 Arrastra para mover · rueda: tamaño · botón derecho o Mayús+arrastrar: girar en 3D.", "libre": "① Pellizca el borde de la pieza con pulgar e índice para cogerla.", "cogida": "② Cogida: muévela con la mano; gira la mano para girarla. Abre los dedos para soltar.", "cogida: girando": "② Girando con tu mano. Abre los dedos para soltar.", "dos pellizcos": "③ Dos pellizcos: separa o junta para agrandar/encoger; gira las manos para girarla sobre el tapete." };
function gestures(active, t) {
  const hull = objectHullPx();
  if (mouse.down) { for (const h of active) { h.holding = false; h.otherHolding = false; } heldBy = null; two = null; holdPts = []; gestureName = "ratón"; return { grasping: new Set(["ratón"]), hull }; }
  if (pieces.on && pieces.list.length > 1) { heldBy = null; two = null; holdPts = []; return partGestures(active, t, hull); }
  const nearHold = h => h.pinchMM && holdPts.some(p => Math.hypot(h.pinchMM[0] - p[0], h.pinchMM[1] - p[1]) < 90);
  // 2.º pellizco: cerca del centro (radio acotado a 230 mm; QA v12.4) y a menos de un diámetro del pellizco que ya sujeta
  const nearObj = h => h.pinchMM && Math.hypot(h.pinchMM[0] - obj.cx, h.pinchMM[1] - obj.cy) < Math.min(obj.size * 1.4, 230)
    && holdPts.some(p => Math.hypot(h.pinchMM[0] - p[0], h.pinchMM[1] - p[1]) < Math.max(obj.size, 160) * 1.1);
  const holding = !!(heldBy || two);
  let pinchers = active.filter(h => h.pinch && h.pinchMM && (nearHold(h) || overPx(hull, h.pinchPx, 60) || (holding && nearObj(h))));
  if (pinchers.length >= 2) { pinchers.sort((a, b) => Math.hypot(a.pinchMM[0] - obj.cx, a.pinchMM[1] - obj.cy) - Math.hypot(b.pinchMM[0] - obj.cx, b.pinchMM[1] - obj.cy)); }
  let grasping = new Set();
  if (pinchers.length >= 2) {
    const a = pinchers[0], b = pinchers[1], p1 = a.pinchMM, p2 = b.pinchMM;
    const dist = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]), ang = Math.atan2(p2[1] - p1[1], p2[0] - p1[0]), mid = [(p1[0] + p2[0]) / 2, (p1[1] + p2[1]) / 2];
    if (!two) { two = { dist, size: obj.size, ang, rot0: obj.rot.clone(), mid, c: [obj.cx, obj.cy], last: ang }; heldBy = null; log("dos pellizcos: escalar/girar"); beep(990); }
    let a2 = two.last + ((ang - two.last + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI; two.last = a2;
    obj.size = Math.min(300, Math.max(90, two.size * Math.min(1.7, Math.max(0.6, dist / two.dist))));
    obj.rot.copy(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 0, 1), a2 - two.ang).multiply(two.rot0));   // giro sobre el tapete (eje Z)
    obj.cx = two.c[0] + (mid[0] - two.mid[0]); obj.cy = two.c[1] + (mid[1] - two.mid[1]);
    pinchHold = new Set([a.label, b.label]); holdPts = [p1, p2]; lastHoldT = t; grasping.add(a.label); grasping.add(b.label); gestureName = "dos pellizcos";
  } else if (pinchers.length === 1) {
    const h = pinchers[0]; two = null;
    if (heldBy !== h.label) {
      if (!(heldBy && nearHold(h))) { grabOff = [h.pinchMM[0] - obj.cx, h.pinchMM[1] - obj.cy]; objFilter.reset(); log(`cogido por el borde con ${h.label}`); beep(880); lockZoom("interacción"); }
      heldBy = h.label; qHand0 = h.qHand ? h.qHand.clone() : null; rot0 = obj.rot.clone(); rotArmed = false;   // (re)anclar la orientación: sin saltos al re-etiquetar
    }
    // traslación: la pieza sigue al pellizco (filtrada)
    const tgt = objFilter.filter([h.pinchMM[0] - grabOff[0], h.pinchMM[1] - grabOff[1]], t);
    obj.cx = Math.min(1.3 * MAT_W, Math.max(-0.3 * MAT_W, tgt[0])); obj.cy = Math.min(1.3 * MAT_H, Math.max(-0.3 * MAT_H, tgt[1]));
    // rotación rígida: la pieza gira como la mano. qRel = rotación de la mano desde el agarre (marco cámara) → al marco del tapete por conjugación
    if (h.qHand && qHand0 && lastPose) {
      const qRel = h.qHand.clone().multiply(qHand0.clone().invert());
      const ang = 2 * Math.acos(Math.min(1, Math.abs(qRel.w)));
      if (!rotArmed) { if (ang > ROT_DEAD) { rotArmed = true; qHand0 = h.qHand.clone(); rot0 = obj.rot.clone(); log("gira con la mano"); } }
      else { const qR = matQuat(); obj.rot.copy(qR.clone().invert().multiply(qRel).multiply(qR).multiply(rot0)); }
    }
    pinchHold = new Set([h.label]); holdPts = [h.pinchMM]; lastHoldT = t; grasping.add(h.label); gestureName = rotArmed ? "cogida: girando" : "cogida";
  } else if (holding && t - lastHoldT < 0.35) {
    // gracia: el pellizco desapareció un instante; mantenemos el agarre sin mover la pieza
  } else {
    if (heldBy || two) { log("soltado"); beep(440); }
    heldBy = null; two = null; pinchHold = new Set(); holdPts = []; qHand0 = null; rotArmed = false; gestureName = "libre";
  }
  for (const h of active) { h.holding = grasping.has(h.label); h.otherHolding = (heldBy && heldBy !== h.label) || false; }
  return { grasping, hull };
}

// ---------- encuadre automático del tapete (zoom digital): cuando se ven los marcadores con margen seguro, la vista se acerca
// hasta que la zona del tapete (más la pieza y algo de aire por encima) llena el área; suave, con histéresis; se congela si se pierde la pose.
const viewEl = document.getElementById("view"), zoomBtn = document.getElementById("zoom");
const zoom = { on: true, k: 1, cx: 0, cy: 0, tk: 1, tcx: 0, tcy: 0, lastT: 0, locked: false, stableSince: 0 };   // locked: encuadre fijo durante la interacción
try { zoom.on = localStorage.getItem("jedomi_visor_zoom") !== "0"; } catch (e) { }
function setZoomBtn() { if (zoomBtn) { zoomBtn.setAttribute("aria-pressed", String(zoom.on)); zoomBtn.style.opacity = zoom.on ? "1" : ".6";
  const lbl = MOODLE?.strings || {}; zoomBtn.textContent = "🔍 " + (!zoom.on ? (lbl.zoom_off || "Encuadre desactivado") : zoom.locked ? (lbl.zoom_refit || "Reencuadrar") : (lbl.zoom_fitting || "Encuadrando…")); } }
// el botón vuelve a encuadrar (se desbloquea, busca el tapete y se fija de nuevo al converger); con Mayús lo desactiva/activa
if (zoomBtn) zoomBtn.onclick = ev => {
  if (ev.shiftKey || !zoom.on) { zoom.on = !zoom.on; try { localStorage.setItem("jedomi_visor_zoom", zoom.on ? "1" : "0"); } catch (e) { } if (!zoom.on) { zoom.k = zoom.tk = 1; zoom.cx = zoom.cy = zoom.tcx = zoom.tcy = 0; viewEl.style.transform = ""; } }
  zoom.locked = false; zoom.stableSince = 0; setZoomBtn(); log("encuadre " + (zoom.on ? "recalculando" : "desactivado"));
};
function lockZoom(why) { if (zoom.on && !zoom.locked) { zoom.locked = true; zoom.k = zoom.tk; zoom.cx = zoom.tcx; zoom.cy = zoom.tcy; applyZoom(); setZoomBtn(); log("encuadre fijado (" + why + ")"); } }
function applyZoom() { viewEl.style.transform = zoom.k > 1.01 ? `translate(${(-zoom.cx * zoom.k).toFixed(1)}px, ${(-zoom.cy * zoom.k).toFixed(1)}px) scale(${zoom.k.toFixed(3)})` : ""; }
setZoomBtn();
function autoFrame(cornersPx, hull, t) {
  if (!zoom.on || !viewEl || zoom.locked) return;              // fijo: no se mueve mientras el alumno interactúa
  if (cornersPx && nmark >= 4 && !poseFrozen) {                 // sólo con los 4 marcadores (márgenes seguros a la vista)
    let x0 = Math.min(...cornersPx.map(c => c[0])), x1 = Math.max(...cornersPx.map(c => c[0])), y0 = Math.min(...cornersPx.map(c => c[1])), y1 = Math.max(...cornersPx.map(c => c[1]));
    const mw = x1 - x0, mh = y1 - y0;
    x0 -= 0.08 * mw; x1 += 0.08 * mw; y0 -= 0.35 * mh; y1 += 0.08 * mh;               // margen + aire por encima (pieza levantada y manos)
    const r = viewEl.getBoundingClientRect(); const sc = Math.min(r.width / W, r.height / Hh); const dw = W * sc, dh = Hh * sc;   // imagen mostrada (contain)
    const k = Math.min(3, Math.max(1, Math.min(r.width / ((x1 - x0) * sc), r.height / ((y1 - y0) * sc))));
    const bcx = (r.width - dw) / 2 + (x0 + x1) / 2 * sc - r.width / 2, bcy = (r.height - dh) / 2 + (y0 + y1) / 2 * sc - r.height / 2;
    const moved = Math.abs(k - zoom.tk) > 0.04 * zoom.tk || Math.hypot(bcx - zoom.tcx, bcy - zoom.tcy) > 0.02 * r.width;
    if (moved) { zoom.tk = k; zoom.tcx = bcx; zoom.tcy = bcy; zoom.stableSince = t; }
    else if (zoom.stableSince && t - zoom.stableSince > 1.2) { lockZoom("tapete estable"); return; }   // convergido: se fija solo
  }
  const a = 0.15; zoom.k += (zoom.tk - zoom.k) * a; zoom.cx += (zoom.tcx - zoom.cx) * a; zoom.cy += (zoom.tcy - zoom.cy) * a;
  if (t - zoom.lastT > 0.1) { zoom.lastT = t; applyZoom(); }
}

// ---------- piezas del modelo: resaltar, nombrar, separar (vista explosionada) y sacar una pieza de su sitio (ratón o pellizco)
const pieces = { list: [], on: false, explode: 0, target: 0, sel: null, hover: null, grab: null }; queueMicrotask(() => { if (window.__ar) window.__ar.pieces = pieces; });
const piecesBtn = document.getElementById("pieces"), explodeBtn = document.getElementById("explode"), infoEl = document.getElementById("partinfo"), labelEl = document.getElementById("partlabel");
const S_ = MOODLE?.strings || {};
function refreshPiecesUI() {
  const has = pieces.list.length > 1;
  if (piecesBtn) { piecesBtn.hidden = !has; piecesBtn.setAttribute("aria-pressed", String(pieces.on)); piecesBtn.classList.toggle("primary", pieces.on); }
  const apart = pieces.target > 0 || pieces.list.some(p => p.offset.lengthSq() > 1e-6 || Math.abs(p.rot.w) < 0.9999);
  if (explodeBtn) { explodeBtn.hidden = !has; explodeBtn.setAttribute("aria-pressed", String(apart)); explodeBtn.textContent = (apart ? "🧲 " + (S_.parts_join || "Unir piezas") : "💥 " + (S_.parts_explode || "Separar piezas")); }
  if (!has || !pieces.on) { showPart(null); if (labelEl) labelEl.hidden = true; }
}
function selectPart(p) { if (pieces.sel && pieces.sel !== p) setHighlight(pieces.sel, null); pieces.sel = p; if (p) setHighlight(p, "selected"); showPart(p); }
function showPart(p) {
  if (!infoEl) return; infoEl.hidden = !p; if (!p) return;
  infoEl.querySelector(".pname").textContent = p.name; infoEl.querySelector(".pdesc").textContent = p.desc || "";
}
if (piecesBtn) piecesBtn.onclick = () => { pieces.on = !pieces.on; if (!pieces.on) { if (pieces.hover) setHighlight(pieces.hover, null); pieces.hover = null; selectPart(null); } refreshPiecesUI(); log("modo piezas " + (pieces.on ? "activado" : "desactivado")); };
if (explodeBtn) explodeBtn.onclick = () => {
  const joining = pieces.target > 0 || pieces.list.some(p => p.offset.lengthSq() > 1e-6 || Math.abs(p.rot.w) < 0.9999);
  if (joining) { pieces.target = 0; pieces.joining = true; } else { pieces.target = 1; if (!pieces.on) pieces.on = true; }
  refreshPiecesUI(); lockZoom("interacción"); log(joining ? "piezas unidas" : "piezas separadas");
};
document.getElementById("part-home")?.addEventListener("click", () => { if (pieces.sel) { resetPart(pieces.sel); refreshPiecesUI(); log("pieza devuelta: " + pieces.sel.name); } });
document.getElementById("part-close")?.addEventListener("click", () => selectPart(null));
document.getElementById("part-explain")?.addEventListener("click", async () => {
  const p = pieces.sel; if (!p) return;
  const ans = await askTutor(`Estoy viendo la pieza «${p.name}» del modelo ${modelLabel()}${p.desc ? " (" + p.desc + ")" : ""}. Te adjunto la captura. Explícame qué función cumple esta pieza y cómo se relaciona con las demás.`);
  if (talk.on && ans) speak(ans);
});
function partsTick() {                                   // animación de la separación + aplicar desplazamientos
  if (!pieces.list.length) return;
  pieces.explode += (pieces.target - pieces.explode) * 0.12; if (Math.abs(pieces.target - pieces.explode) < 0.002) pieces.explode = pieces.target;
  if (pieces.joining) {                                  // «Unir piezas»: cada pieza vuelve a su sitio y a su orientación original
    let done = true; const I = new THREE.Quaternion();
    for (const p of pieces.list) { p.offset.multiplyScalar(0.82); p.rot.slerp(I, 0.18); if (p.offset.lengthSq() > 0.01 || Math.abs(p.rot.w) < 0.99995) done = false; else resetPart(p); }
    if (done && pieces.explode === 0) { pieces.joining = false; refreshPiecesUI(); }
  }
  applyParts(pieces.list, pieces.explode);
}
// desplazamiento en mm del tapete → desplazamiento en coordenadas del modelo (root)
function matDeltaToRoot(a, b) {
  const z = objGroup.position.z, pa = mat.localToWorld(new THREE.Vector3(a[0], a[1], z)), pb = mat.localToWorld(new THREE.Vector3(b[0], b[1], z));
  return obj.root.worldToLocal(pb).sub(obj.root.worldToLocal(pa));
}
// rotación expresada en el marco del tapete → la misma rotación en el marco del modelo (root)
function matRotToRoot(qMat) {
  const qm = mat.getWorldQuaternion(new THREE.Quaternion()), qr = obj.root.getWorldQuaternion(new THREE.Quaternion());
  const qRM = qm.clone().invert().multiply(qr);                       // orientación de root vista desde el tapete
  return qRM.clone().invert().multiply(qMat).multiply(qRM);
}
function rotatePart(p, axis, ang) { p.rot.premultiply(matRotToRoot(new THREE.Quaternion().setFromAxisAngle(axis, ang))); refreshPiecesUI(); }
const AX = { x: new THREE.Vector3(1, 0, 0), y: new THREE.Vector3(0, 1, 0), z: new THREE.Vector3(0, 0, 1) };
function hoverPart(px) {
  if (!pieces.on || !obj.root) return null;
  const p = pickPart(pieces.list, obj.root, camera, px, W, Hh);
  if (p !== pieces.hover) { if (pieces.hover && pieces.hover !== pieces.sel) setHighlight(pieces.hover, null); pieces.hover = p; if (p && p !== pieces.sel) setHighlight(p, "hover"); }
  return p;
}
// con las manos en modo piezas: un pellizco sobre una pieza la coge y la saca de su sitio (sólo esa pieza)
function partGestures(active, t, hull) {
  const g = pieces.grab;
  if (g) {
    const h = active.find(h => h.pinch && h.pinchMM && (h.label === g.label || Math.hypot(h.pinchMM[0] - g.last[0], h.pinchMM[1] - g.last[1]) < 90));
    if (h) { g.part.offset.add(matDeltaToRoot(g.last, h.pinchMM)); g.last = h.pinchMM.slice(); g.label = h.label; g.t = t;
      if (h.qHand && g.q0 && lastPose) { const qRel = h.qHand.clone().multiply(g.q0.clone().invert()); const ang = 2 * Math.acos(Math.min(1, Math.abs(qRel.w)));
        if (!g.armed && ang > ROT_DEAD) { g.armed = true; g.q0 = h.qHand.clone(); g.rot0 = g.part.rot.clone(); }
        else if (g.armed) { const qR = matQuat(); g.part.rot.copy(matRotToRoot(qR.clone().invert().multiply(qRel).multiply(qR)).multiply(g.rot0)); } }
      gestureName = "pieza cogida"; return { grasping: new Set([h.label]), hull }; }
    if (t - g.t < 0.35) return { grasping: new Set([g.label]), hull };
    log("pieza soltada: " + g.part.name); beep(440); pieces.grab = null;
  }
  for (const h of active) {
    if (!h.pinch || !h.pinchMM || !h.pinchPx) continue;
    const p = pickPart(pieces.list, obj.root, camera, h.pinchPx, W, Hh);
    if (p) { pieces.grab = { part: p, label: h.label, last: h.pinchMM.slice(), t, q0: h.qHand ? h.qHand.clone() : null, rot0: p.rot.clone(), armed: false }; selectPart(p); lockZoom("interacción"); log("pieza cogida: " + p.name); beep(880); gestureName = "pieza cogida"; return { grasping: new Set([h.label]), hull }; }
  }
  gestureName = "modo piezas"; return { grasping: new Set(), hull };
}

// ---------- panel de controles en pantalla (alternativa precisa a manos y ratón)
const padEl = document.getElementById("pad"), padTarget = document.getElementById("pad-target");
function padTargetPart() { return pieces.on && pieces.sel ? pieces.sel : null; }
function padRefresh() { if (padTarget) padTarget.textContent = padTargetPart() ? "🧩 " + padTargetPart().name : "▣ " + (S_.pad_whole || "Todo el modelo"); }
const STEP_MM = 10, STEP_ANG = 10 * Math.PI / 180;
function padAction(a) {
  const part = padTargetPart(); lockZoom("interacción");
  const mv = (dx, dy) => { if (part) part.offset.add(matDeltaToRoot([0, 0], [dx, dy])); else { obj.cx = clampX(obj.cx + dx); obj.cy = clampY(obj.cy + dy); } };
  const rt = (axis, ang) => { if (part) rotatePart(part, axis, ang); else obj.rot.premultiply(new THREE.Quaternion().setFromAxisAngle(axis, ang)); };
  switch (a) {
    case "left": mv(-STEP_MM, 0); break; case "right": mv(STEP_MM, 0); break; case "up": mv(0, -STEP_MM); break; case "down": mv(0, STEP_MM); break;
    case "yawl": rt(AX.z, -STEP_ANG); break; case "yawr": rt(AX.z, STEP_ANG); break;
    case "pitchf": rt(AX.x, STEP_ANG); break; case "pitchb": rt(AX.x, -STEP_ANG); break;
    case "rolll": rt(AX.y, -STEP_ANG); break; case "rollr": rt(AX.y, STEP_ANG); break;
    case "bigger": obj.size = Math.min(300, obj.size * 1.06); break; case "smaller": obj.size = Math.max(90, obj.size / 1.06); break;
    case "home": if (part) resetPart(part); else { obj.cx = MAT_W / 2; obj.cy = MAT_H / 2; obj.rot.identity(); } break;
  }
  refreshPiecesUI();
}
if (padEl) padEl.querySelectorAll("button[data-a]").forEach(b => {
  let rep = null; const stop = () => { clearInterval(rep); rep = null; };
  b.addEventListener("pointerdown", ev => { ev.preventDefault(); padAction(b.dataset.a); if (!["home"].includes(b.dataset.a)) rep = setInterval(() => padAction(b.dataset.a), 90); });
  ["pointerup", "pointerleave", "pointercancel"].forEach(e => b.addEventListener(e, stop));
  b.addEventListener("keydown", ev => { if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); padAction(b.dataset.a); } });
});
document.getElementById("padtoggle")?.addEventListener("click", () => { if (padEl) { padEl.hidden = !padEl.hidden; document.getElementById("padtoggle").setAttribute("aria-pressed", String(!padEl.hidden)); } });
setInterval(padRefresh, 250);

// ---------- ratón con «superpoderes» (teclado/ratón como alternativa a las manos): arrastrar mueve, rueda escala,
// botón derecho o Mayús+arrastrar gira en 3D (horizontal: sobre el tapete; vertical: volteo hacia/desde la cámara)
const stageEl = document.getElementById("stage");
const mouse = { down: false, btn: 0, shift: false, last: null, offset: [0, 0], over: false };
function screenToPx(ev) {                                // pantalla → píxeles de la imagen (object-fit: contain)
  const r = canvas.getBoundingClientRect(); const sc = Math.min(r.width / W, r.height / Hh); const dw = W * sc, dh = Hh * sc;
  return [(ev.clientX - (r.left + (r.width - dw) / 2)) / sc, (ev.clientY - (r.top + (r.height - dh) / 2)) / sc];
}
const clampX = v => Math.min(1.3 * MAT_W, Math.max(-0.3 * MAT_W, v)), clampY = v => Math.min(1.3 * MAT_H, Math.max(-0.3 * MAT_H, v));
const uiTarget = ev => ev.target.closest && ev.target.closest("#ctl,#titlebar,#start,#jv-intro,#ia,#status,#step,#partinfo,#credit,#pad,.jedomi-sidebar,#jedomi-trigger");
stageEl.addEventListener("pointerdown", ev => {
  if (uiTarget(ev) || !poseOK || !obj.mesh) return;
  const p = screenToPx(ev), over = overPx(objectHullPx(), p, 40);
  if (pieces.on && ev.button === 0 && !ev.shiftKey) {               // modo piezas: arrastrar una pieza la saca de su sitio; clic la selecciona
    const part = hoverPart(p);
    if (part) { selectPart(part); mouse.part = part; mouse.down = true; mouse.btn = 0; mouse.shift = false; mouse.last = p; mouse.lastMM = pxToMM(p);
      stageEl.setPointerCapture(ev.pointerId); ev.preventDefault(); lockZoom("interacción"); log("ratón: pieza " + part.name); return; }
  }
  if (pieces.on && ev.button === 0 && !ev.shiftKey) return;         // modo piezas: el botón izquierdo sólo actúa sobre piezas
  if (pieces.on && pieces.sel && (ev.button === 2 || ev.shiftKey)) {  // modo piezas: girar la pieza seleccionada
    mouse.down = true; mouse.btn = 2; mouse.shift = true; mouse.last = p; mouse.rotPart = pieces.sel;
    stageEl.setPointerCapture(ev.pointerId); ev.preventDefault(); lockZoom("interacción"); log("ratón: girar pieza " + pieces.sel.name); return; }
  if (!over && !(ev.button === 2 || ev.shiftKey)) return;          // sin pieza debajo sólo se admite el giro (derecho/Mayús)
  mouse.down = true; mouse.btn = ev.button; mouse.shift = ev.shiftKey; mouse.last = p;
  const mm = pxToMM(p); mouse.offset = mm ? [mm[0] - obj.cx, mm[1] - obj.cy] : [0, 0];
  stageEl.setPointerCapture(ev.pointerId); ev.preventDefault(); log("ratón: " + (ev.button === 2 || ev.shiftKey ? "girar" : "mover")); lockZoom("interacción");
});
stageEl.addEventListener("pointermove", ev => {
  if (!mouse.down && pieces.on && !uiTarget(ev) && poseOK && obj.root) {
    const now = performance.now(); if (now - (mouse.pickT || 0) > 70) { mouse.pickT = now; const part = hoverPart(screenToPx(ev));
      if (labelEl) { labelEl.hidden = !part; if (part) { labelEl.textContent = part.name; const r = stageEl.getBoundingClientRect(); labelEl.style.left = (ev.clientX - r.left + 14) + "px"; labelEl.style.top = (ev.clientY - r.top + 14) + "px"; } }
      stageEl.style.cursor = part ? "pointer" : ""; }
    return;
  }
  if (!mouse.down) { if (!uiTarget(ev) && poseOK && obj.mesh) { const over = overPx(objectHullPx(), screenToPx(ev), 40); if (over !== mouse.over) { mouse.over = over; stageEl.style.cursor = over ? "grab" : ""; } } return; }
  const p = screenToPx(ev);
  if (mouse.part) { const mm = pxToMM(p); if (mm && mouse.lastMM) { const d = matDeltaToRoot(mouse.lastMM, mm); mouse.part.offset.add(d); mouse.movedMM = (mouse.movedMM || 0) + Math.hypot(mm[0] - mouse.lastMM[0], mm[1] - mouse.lastMM[1]); mouse.movedRoot = (mouse.movedRoot || 0) + d.length(); mouse.lastMM = mm; } stageEl.style.cursor = "grabbing"; mouse.last = p; return; }
  if (mouse.btn === 0 && !mouse.shift) { const mm = pxToMM(p); if (mm) { obj.cx = clampX(mm[0] - mouse.offset[0]); obj.cy = clampY(mm[1] - mouse.offset[1]); } stageEl.style.cursor = "grabbing"; }
  else if (mouse.rotPart) { const dx = p[0] - mouse.last[0], dy = p[1] - mouse.last[1]; rotatePart(mouse.rotPart, AX.z, dx * 0.008); rotatePart(mouse.rotPart, AX.x, -dy * 0.008); stageEl.style.cursor = "move"; }
  else { const dx = p[0] - mouse.last[0], dy = p[1] - mouse.last[1];
    obj.rot.premultiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 0, 1), dx * 0.008));   // giro sobre el tapete
    obj.rot.premultiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(1, 0, 0), -dy * 0.008));  // volteo hacia/desde la cámara
    stageEl.style.cursor = "move"; }
  mouse.last = p;
});
const mouseUp = ev => { if (!mouse.down) return; mouse.down = false; mouse.rotPart = null; if (mouse.part) { log(`ratón: pieza suelta (${Math.round(mouse.movedMM || 0)} mm en el tapete → ${Math.round(mouse.movedRoot || 0)} u. del modelo)`); mouse.part = null; mouse.movedMM = mouse.movedRoot = 0; } stageEl.style.cursor = mouse.over ? "grab" : ""; log("ratón: suelto"); };
stageEl.addEventListener("pointerup", mouseUp); stageEl.addEventListener("pointercancel", mouseUp);
stageEl.addEventListener("wheel", ev => { if (uiTarget(ev) || !poseOK || !obj.mesh) return; if (!mouse.over && !mouse.down) return;
  obj.size = Math.min(300, Math.max(90, obj.size * (ev.deltaY < 0 ? 1.08 : 0.92))); ev.preventDefault(); }, { passive: false });
stageEl.addEventListener("contextmenu", ev => { if (!uiTarget(ev)) ev.preventDefault(); });   // el botón derecho gira: sin menú contextual sobre el escenario
window.__ar = { obj, mouse, pieces: null, camera, pxToMM: p => pxToMM(p), screenToPx: e => screenToPx(e) };                               // depuración/QA (window.__visor lo usa el bucle)

// ---------- utilidades UI
const logs = []; function log(m) { const s = new Date().toLocaleTimeString() + " " + m; logs.push(s); console.log(s); }
let actx = null; function beep(f) { try { actx = actx || new AudioContext(); const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = f; g.gain.value = 0.08; o.connect(g); g.connect(actx.destination); o.start(); o.stop(actx.currentTime + 0.07); } catch (e) { } }
const overlay = document.createElement("canvas"); overlay.style.cssText = "position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none"; document.getElementById("view").appendChild(overlay); const octx = overlay.getContext("2d");

// ---------- bucle
let source = video;                                   // fuente de imagen: la cámara o, en modo prueba (?img=…), una foto fija
async function start(opts = {}) {
  const imgParam = new URLSearchParams(location.search).get("img");
  if (imgParam) {                                     // modo prueba sin cámara (el panel del navegador de Claude no permite captura)
    const im = new Image(); await new Promise((res, rej) => { im.onload = res; im.onerror = () => rej(new Error("no se pudo cargar " + imgParam)); im.src = imgParam; }); source = im;   // onload, no decode(): decode() se aplaza en pestañas ocultas
    W = im.naturalWidth; Hh = im.naturalHeight; video.style.display = "none";
    const bg = document.createElement("canvas"); bg.width = W; bg.height = Hh; bg.getContext("2d").drawImage(im, 0, 0);
    bg.style.cssText = "position:absolute;inset:0;width:100%;height:100%;object-fit:contain"; document.getElementById("view").prepend(bg);
  } else {
    // la cámara la eligió el alumno en la pantalla previa (devices.js); sin elección, la predeterminada del navegador
    const vc = opts.cameraId ? { deviceId: { exact: opts.cameraId }, width: { ideal: 1920 }, height: { ideal: 1080 } } : { width: { ideal: 1920 }, height: { ideal: 1080 }, facingMode: "environment" };
    const stream = await navigator.mediaDevices.getUserMedia({ video: vc, audio: false });
    log("cámara: " + (stream.getVideoTracks()[0]?.label || "?"));
    video.srcObject = stream; await video.play();
    W = video.videoWidth; Hh = video.videoHeight;
  }
  renderer.setSize(W, Hh, false); overlay.width = W; overlay.height = Hh;
  const fx = FX * W; camera.fov = 2 * Math.atan(Hh / (2 * fx)) * 180 / Math.PI; camera.aspect = W / Hh; camera.updateProjectionMatrix();
  work.width = Math.round(W / 4); work.height = Math.round(Hh / 4);   // ArUco a 1/4: ~4× más rápido (86 → ~20 ms)
  const vision = await FilesetResolver.forVisionTasks("https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm");
  hands = await HandLandmarker.createFromOptions(vision, { baseOptions: { modelAssetPath: BASE + "models/hand_landmarker.task", delegate: "GPU" }, runningMode: "VIDEO", numHands: 2, minHandDetectionConfidence: 0.4, minHandPresenceConfidence: 0.4, minTrackingConfidence: 0.4 });
  await loadModel(MOODLE ? (MOODLE.modelUrl || "aro") : document.getElementById("model").value);   // la pieza que adjuntó el docente; el aro sólo como respaldo
  document.getElementById("start").style.display = "none";
  stepEl.textContent = "① Pellizca el borde de la pieza con pulgar e índice para cogerla.";
  requestAnimationFrame(loop);
}
function loop() {
  const t = performance.now() / 1000; frames++; if (performance.now() - fpsT > 1000) { fps = frames * 1000 / (performance.now() - fpsT); frames = 0; fpsT = performance.now(); }
  // --- tapete (un fotograma sí y otro no: la hoja no se mueve y la pose se mantiene; sube los fps de ~20 a ~30)
  const T0 = performance.now();
  frameNo++;
  let markers = lastMarkers;
  if (frameNo % 2 === 0 || !lastPose) {
    wctx.drawImage(source, 0, 0, work.width, work.height);
    const img = wctx.getImageData(0, 0, work.width, work.height);
    markers = detector.detect(img).filter(m => m.id >= 0 && m.id <= 3); lastMarkers = markers;
  }
  const T1 = performance.now();
  nmark = markers.length; const src = [], dst = [];
  for (const m of markers) { const mm = markerCornersMM(m.id); for (let k = 0; k < 4; k++) { const key = m.id * 4 + k; let f = cornerFilters.get(key); if (!f) { f = new OneEuro(0.8, 0.004); cornerFilters.set(key, f); } const p = f.filter([m.corners[k].x * 4, m.corners[k].y * 4], t); src.push(mm[k]); dst.push(p); } }
  for (const key of [...cornerFilters.keys()]) if (!markers.some(m => Math.floor(key / 4) === m.id)) cornerFilters.delete(key);
  if (nmark >= 3) {
    const Hm = solveHomography(src, dst);
    if (Hm) {
      // mantener la pose previa si las esquinas coinciden (< 6 px): no re-resolver (evita el escalón 4→3 y el ruido)
      let keep = false;
      if (lastPose && lastPose.H) { let err = 0; for (let i = 0; i < src.length; i++) { const v = mulMatVec(lastPose.H, [src[i][0], src[i][1], 1]); err += Math.hypot(v[0] / v[2] - dst[i][0], v[1] / v[2] - dst[i][1]); } keep = err / src.length < 6; }
      if (!keep) {
        const pose = poseFromHomography(Hm, FX * W, FX * W, W / 2, Hh / 2);
        lastPose = { H: Hm, pose };
      }
      poseFrozen = false; poseOK = true;
    }
  } else { poseFrozen = !!lastPose; }
  if (lastPose) {
    const { R, t: tv } = lastPose.pose;
    // OpenCV (x→, y↓, z adelante) → Three (x→, y↑, z hacia el espectador): flip Y y Z
    const M = new THREE.Matrix4().set(R[0][0], R[0][1], R[0][2], tv[0], -R[1][0], -R[1][1], -R[1][2], -tv[1], -R[2][0], -R[2][1], -R[2][2], -tv[2], 0, 0, 0, 1);
    mat.matrixAutoUpdate = false; mat.matrix.copy(M); mat.matrixWorldNeedsUpdate = true;
    Hinv = invert3(lastPose.H);
  }
  // --- manos
  const T2 = performance.now();
  let active = [];
  if (hands) {
    const res = hands.detectForVideo(source, performance.now());
    const dets = res.landmarks.map((lm, i) => ({ lm, wlm: res.worldLandmarks[i], palm: [0, 5, 9, 13, 17].reduce((a, k) => [a[0] + lm[k].x * W / 5, a[1] + lm[k].y * Hh / 5], [0, 0]), side: res.handedness[i]?.[0]?.categoryName || "Hand" }));
    const used = new Set(), seen = new Set();
    const cands = []; for (const [lab, h] of H) for (let di = 0; di < dets.length; di++) if (h.palm) cands.push([Math.hypot(h.palm[0] - dets[di].palm[0], h.palm[1] - dets[di].palm[1]), lab, di]);
    cands.sort((a, b) => a[0] - b[0]); const match = new Map();
    for (const [d, lab, di] of cands) { if (used.has(lab) || match.has(di) || d > 220) continue; match.set(di, lab); used.add(lab); }
    dets.forEach((d, di) => { let lab = match.get(di); if (!lab) { lab = H.has(d.side) || seen.has(d.side) ? `${d.side}${H.size + 1}` : d.side; H.set(lab, new Hand(lab)); } const h = H.get(lab); h.update(d.lm, d.wlm, t); h.pinchMM = pxToMM(h.pinchPx); seen.add(lab); });
    for (const [lab, h] of H) if (!seen.has(lab) && t - h.last > 0.4) H.delete(lab);
    active = [...H.values()].filter(h => h.pinchMM && t - h.last < 0.3);
  }
  const T3 = performance.now();
  const { grasping, hull } = poseOK ? gestures(active, t) : { grasping: new Set(), hull: null };
  // --- dibujo
  partsTick();
  placeObject(grasping.size > 0 || mouse.down || pieces.explode > 0.05);
  renderer.render(scene, camera);
  const T4 = performance.now();
  const cornersPx = [[0, 0], [MAT_W, 0], [MAT_W, MAT_H], [0, MAT_H]].map(([x, y]) => { const v = new THREE.Vector3(x, y, 0); mat.localToWorld(v); v.project(camera); return [Math.round((v.x + 1) / 2 * W), Math.round((1 - v.y) / 2 * Hh)]; });
  autoFrame(cornersPx, hull, t);
  window.__dbg = { ms: { aruco: T1 - T0, hands: T3 - T2, render: T4 - T3 }, cornersPx, markersPx: markers.map(m => ({ id: m.id, c: m.corners.map(c => [Math.round(c.x * 4), Math.round(c.y * 4)]) })), pose: lastPose && lastPose.pose, drawTris: renderer.info.render.triangles, W, Hh };
  octx.clearRect(0, 0, W, Hh);
  for (const h of active) {
    const g = grasping.has(h.label); octx.strokeStyle = g ? "#4ade80" : "#ffffff"; octx.fillStyle = octx.strokeStyle; octx.lineWidth = 3;
    octx.beginPath(); octx.arc(h.palm[0], h.palm[1], 10, 0, 7); octx.stroke();
    octx.beginPath(); octx.arc(h.raw[8][0], h.raw[8][1], 9, 0, 7); octx.stroke();
    if (h.pinch) { octx.strokeStyle = "#facc15"; octx.beginPath(); octx.arc(h.pinchPx[0], h.pinchPx[1], 12, 0, 7); octx.stroke(); }
  }
  if (hull && active.some(h => h.pinch && overPx(hull, h.pinchPx, 70))) { octx.strokeStyle = "rgba(255,255,255,.9)"; octx.lineWidth = 3; octx.strokeRect(hull.x0 - 10, hull.y0 - 10, hull.x1 - hull.x0 + 20, hull.y1 - hull.y0 + 20); }
  window.__visor = { poseOK, poseFrozen, nmark, fps, tris: obj.tris, held: !!(heldBy || two), hands: active.length, obj: { cx: obj.cx, cy: obj.cy, size: obj.size } };
  // telemetría de desarrollo (?telemetry=1): cada 2 s envía el estado a un servidor local para analizar la sesión sin acceso al navegador
  if (TELEMETRY && t - telemetryT > 2) { telemetryT = t; const ms = window.__dbg ? window.__dbg.ms : null;
    fetch("http://127.0.0.1:8766/metrics", { method: "POST", mode: "cors", headers: { "content-type": "application/json" },
      body: JSON.stringify({ t: Math.round(t), fps: Math.round(fps), cam: (video.srcObject && video.srcObject.getVideoTracks()[0] || {}).label || "", nmark, poseFrozen, hands: active.length, pinching: active.filter(h => h.pinch).length, held: !!(heldBy || two), mode: two ? "pinch2" : (heldBy ? "pinch" : ""), size: Math.round(obj.size), tris: Math.round(obj.tris), tilt: Math.round(tiltDeg(obj.rot)), gesture: gestureName, parts: pieces.list.length, piecesOn: pieces.on, explode: +pieces.explode.toFixed(2), sel: pieces.sel ? pieces.sel.name : "", talk: talk.on, asking, sidebar: !!document.getElementById("jedomi-sidebar"), log: logs.slice(-4), ms: ms && { aruco: Math.round(ms.aruco), hands: Math.round(ms.hands), render: Math.round(ms.render * 10) / 10 }, W, Hh, grabs: logs.length }) }).catch(() => { }); }
  if (!talk.on && !asking) stepEl.textContent = GESTURE_HINT[gestureName] || gestureName;
  statusEl.textContent = `tapete ${poseOK ? (poseFrozen ? "congelado (destapa las esquinas)" : nmark + " marcadores") : "no visto"} · manos ${active.length} · ${heldBy || two ? "COGIDO" : "libre"} · ${Math.round(obj.tris / 1000)} k triángulos · ${fps.toFixed(0)} fps`;
  requestAnimationFrame(loop);
}
function cross3(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
function dot3(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
function invert3(Mx) { const m = Mx.flat(); const [a, b, c, d, e, f, g, h, i] = m; const det = a * (e * i - f * h) - b * (d * i - f * g) + c * (d * h - e * g); if (Math.abs(det) < 1e-12) return null; const k = 1 / det; return [[(e * i - f * h) * k, (c * h - b * i) * k, (b * f - c * e) * k], [(f * g - d * i) * k, (a * i - c * g) * k, (c * d - a * f) * k], [(d * h - e * g) * k, (b * g - a * h) * k, (a * e - b * d) * k]]; }

// ---------- controles
const prejoin = initPrejoin({ strings: MOODLE?.strings || {}, testMode: !!new URLSearchParams(location.search).get("img"),
  onEnter: o => start(o).catch(e => { stepEl.textContent = "Error: " + e.message; document.getElementById("pjstatus").textContent = "Error: " + e.message; document.getElementById("start").style.display = "grid"; console.error(e); }) });
document.getElementById("model").onchange = e => loadModel(e.target.value);
document.getElementById("reset").onclick = () => { obj.cx = MAT_W / 2; obj.cy = MAT_H / 2; obj.size = 260; obj.yaw = 0; obj.rot.identity(); for (const p of pieces.list) resetPart(p); pieces.target = 0; pieces.explode = 0; refreshPiecesUI(); };
document.getElementById("quit").onclick = () => { stopTalk(); const s = video.srcObject; if (s) s.getTracks().forEach(tr => tr.stop()); };   // es un enlace: vuelve al curso

// ---------- tutor de Jedomi (barra lateral del curso, ya inyectada en esta página por el plugin)
// El visor no habla con la IA directamente: escribe en el chat de Jedomi y lee su respuesta. Así la conversación queda en la
// sesión del alumno, con el modo del curso (socrático/guiado) resuelto en servidor, y el docente la ve como cualquier otra.
const J = { sidebar: () => document.getElementById("jedomi-sidebar"), trigger: () => document.getElementById("jedomi-trigger"),
  input: () => document.getElementById("jedomi-input"), send: () => document.getElementById("jedomi-send"), file: () => document.getElementById("jedomi-image-input"),
  lastAnswer: () => { const b = document.querySelectorAll("#jedomi-messages .jedomi-message-assistant .jedomi-bubble"); if (!b.length) return "";
    const c = b[b.length - 1].cloneNode(true); c.querySelectorAll(".jedomi-msg-actions, button").forEach(x => x.remove()); return c.innerText.trim(); } };
const modelLabel = () => (MOODLE && document.getElementById("model").value !== "aro" && MOODLE.modelName) || (document.getElementById("model").selectedOptions[0]?.textContent || "la pieza");
const sceneText = () => `(${pieces.sel ? "pieza seleccionada «" + pieces.sel.name + "», " : ""}modelo «${modelLabel()}», ${Math.round(obj.tris / 1000)} mil triángulos, tamaño ${Math.round(obj.size)} mm, giro ${Math.round(obj.yaw * 180 / Math.PI)}°, inclinación ${Math.round(tiltDeg(obj.rot))}°, ${heldBy || two ? "la tengo cogida" : "está apoyada en el tapete"})`;
function openPanel() { const sb = J.sidebar(); if (sb && sb.classList.contains("jedomi-closed")) J.trigger()?.click(); }
async function snapshot() {                             // captura compuesta: fuente de vídeo + lienzo 3D, a media resolución
  renderer.render(scene, camera);
  const snap = document.createElement("canvas"); snap.width = Math.round(W / 2); snap.height = Math.round(Hh / 2); const sctx = snap.getContext("2d");
  sctx.drawImage(source, 0, 0, snap.width, snap.height); sctx.drawImage(canvas, 0, 0, snap.width, snap.height);
  return new Promise(r => snap.toBlob(r, "image/jpeg", 0.8));
}
let asking = false, lastShot = null;
function sceneChanged() {                               // ¿ha cambiado lo que ve el alumno desde la última captura enviada?
  const k = { m: document.getElementById("model").value, s: Math.round(obj.size / 20), y: Math.round(obj.yaw * 4 / Math.PI), x: Math.round(obj.cx / 80), c: Math.round(obj.cy / 80) };
  const ch = !lastShot || Object.keys(k).some(q => k[q] !== lastShot[q]); if (ch) lastShot = k; return ch;
}
async function askTutor(text, withSnapshot = true) {    // devuelve la respuesta del tutor (texto plano) cuando termina el streaming
  if (!MOODLE || !J.trigger() || !J.input() || !J.send()) { iaEl.style.display = "block"; iaEl.textContent = "IA (prototipo local sin Moodle): " + text; return ""; }
  if (asking) return "";
  asking = true; openPanel();
  try {
    if (withSnapshot) {
      try { const blob = await snapshot(); const fi = J.file();
        if (fi && blob) { const dt = new DataTransfer(); dt.items.add(new File([blob], "visor-ar.jpg", { type: "image/jpeg" })); fi.files = dt.files; fi.dispatchEvent(new Event("change", { bubbles: true }));
          await new Promise(r => setTimeout(r, 500)); }                     // la barra lee el fichero de forma asíncrona (FileReader)
      } catch (e) { console.warn("captura no adjuntada", e); }
    }
    const input = J.input(), send = J.send(); input.value = text; input.dispatchEvent(new Event("input", { bubbles: true }));
    const before = J.lastAnswer();
    send.click(); log("enviado al tutor de Jedomi: " + text.slice(0, 60));
    // la barra deshabilita «enviar» mientras responde y lo rehabilita al terminar (finally) → esperamos ese flanco
    await new Promise(r => setTimeout(r, 300));
    await new Promise(resolve => { const t0 = performance.now();
      const tick = () => { if (!send.disabled && performance.now() - t0 > 1500) return resolve(); if (performance.now() - t0 > 90000) return resolve(); setTimeout(tick, 150); }; tick(); });
    const ans = J.lastAnswer(); return ans !== before ? ans : "";
  } finally { asking = false; }
}
document.getElementById("explain").onclick = async () => {
  sceneChanged(); const ans = await askTutor(`Estoy viendo en realidad aumentada el modelo 3D de esta actividad ${sceneText()}. Te adjunto la captura de lo que veo. Ayúdame a entender qué es y cómo funciona.`);
  if (talk.on && ans) speak(ans);
};

// ---------- modo conversación: el tutor habla y escucha, como en la estación física
// Voz → texto: SpeechRecognition del navegador (Chrome/Edge; en Chrome el audio pasa por el servicio de voz de Google).
// Texto → voz: speechSynthesis del navegador. Mientras el tutor habla no se escucha (anti-eco), igual que en voice_worker.py.
const talk = { on: false, rec: null, speaking: false, lang: (document.documentElement.lang || "es").startsWith("es") ? "es-ES" : (document.documentElement.lang || "en-US") };
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function plain(md) { return md.replace(/```[\s\S]*?```/g, " ").replace(/`([^`]*)`/g, "$1").replace(/!\[[^\]]*\]\([^)]*\)/g, "").replace(/\[([^\]]*)\]\([^)]*\)/g, "$1").replace(/[*_#>|]+/g, " ").replace(/\s+/g, " ").trim(); }
function speak(text) {
  if (!("speechSynthesis" in window)) return;
  const u = new SpeechSynthesisUtterance(plain(text).slice(0, 1200)); u.lang = talk.lang; u.rate = 1.0;
  const v = speechSynthesis.getVoices().find(x => x.lang.replace("_", "-").toLowerCase().startsWith(talk.lang.slice(0, 2))); if (v) u.voice = v;
  talk.speaking = true; stepEl.textContent = "🔊 Jedomi: " + plain(text).slice(0, 140) + (text.length > 140 ? "…" : "");
  try { talk.rec?.abort(); } catch (e) { }
  u.onend = u.onerror = () => { setTimeout(() => { talk.speaking = false; if (talk.on) listen(); }, 500); };   // margen anti-auto-escucha (QA): el altavoz tarda en callar
  speechSynthesis.cancel(); speechSynthesis.speak(u);
}
function listen() {
  if (!talk.on || !SR) return;
  try { talk.rec?.abort(); } catch (e) { }
  const r = new SR(); r.lang = talk.lang; r.continuous = true; r.interimResults = false; r.maxAlternatives = 1; talk.rec = r;
  stepEl.textContent = "🎙️ Te escucho. Habla con normalidad; di «terminar» para cerrar la conversación.";
  r.onresult = async ev => {
    const res = ev.results[ev.results.length - 1]; if (!res.isFinal) return;
    const said = res[0].transcript.trim(); if (!said || talk.speaking || asking) return;
    log("alumno dijo: " + said);
    if (/^(terminar|salir|basta|parar)\.?$/i.test(said)) { stopTalk(); speak("Hasta luego."); return; }
    stepEl.textContent = "⌛ Tú: " + said;
    const ans = await askTutor(`${said} ${sceneText()}`, sceneChanged()); if (ans) speak(ans); else if (talk.on) listen();
  };
  r.onerror = e => { if (e.error === "not-allowed" || e.error === "service-not-allowed") { stopTalk(); stepEl.textContent = "El navegador no permite el micrófono aquí (" + e.error + "). Puedes seguir leyendo al tutor en el chat de la izquierda."; } };
  r.onend = () => { if (talk.on && !talk.speaking && !asking) setTimeout(listen, 250); };   // Chrome corta la escucha continua cada ~60 s
  try { r.start(); } catch (e) { }
}
async function startTalk() {
  if (!SR) { stepEl.textContent = "Este navegador no permite reconocimiento de voz (usa Chrome o Edge)."; return; }
  if (!MOODLE?.voiceEnabled) { stepEl.textContent = MOODLE?.strings?.voice_off || "Voz no habilitada."; return; }
  if (!talk.consent) { if (!window.confirm(MOODLE?.strings?.voice_consent || "¿Aceptas usar el micrófono y el reconocimiento de voz del navegador?")) return; talk.consent = true; log("consentimiento de voz aceptado"); }
  sceneChanged(); talk.on = true; const b = document.getElementById("talk"); b.textContent = "⏹ " + ((MOODLE?.strings?.talk_stop) || "Parar voz"); b.classList.add("danger");
  openPanel();
  const ans = await askTutor(`Activo el modo conversación en el visor de realidad aumentada de esta actividad. Te adjunto lo que veo ahora ${sceneText()}. Salúdame en una frase y hazme la primera pregunta o indicación según el modo del curso. Responde en frases cortas: te voy a escuchar en voz alta.`);
  if (ans) speak(ans); else listen();
}
function stopTalk() {
  talk.on = false; try { talk.rec?.abort(); } catch (e) { } talk.rec = null; if ("speechSynthesis" in window) speechSynthesis.cancel(); talk.speaking = false;
  const b = document.getElementById("talk"); if (b) { b.textContent = "🎙 " + ((MOODLE?.strings?.talk) || "Conversar"); b.classList.remove("danger"); }
  if (video.srcObject) stepEl.textContent = "① Pellizca el borde de la pieza con pulgar e índice para cogerla.";
}
const talkBtn = document.getElementById("talk"); if (talkBtn) talkBtn.onclick = () => talk.on ? stopTalk() : startTalk();   // el botón sólo existe si el sitio habilita la voz
if ("speechSynthesis" in window) speechSynthesis.getVoices();           // precarga la lista de voces (en Chrome llega de forma asíncrona)
