// Visor STEM AR (prototipo local, base del visor de Jedomi): cámara → ArUco (js-aruco2) → pose del tapete → Three.js (GLB pesados)
// + MediaPipe HandLandmarker (gestos portados de demo_guiada.py v12.3: pellizco en el borde coge/mueve/gira, dos pellizcos giran/escalan).
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
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
const BASE = MOODLE ? MOODLE.base : "";
// ---------- estado
const video = document.getElementById("video"), canvas = document.getElementById("three");
const stepEl = document.getElementById("step"), statusEl = document.getElementById("status"), iaEl = document.getElementById("ia");
let W = 1280, Hh = 720, FX = 0.9;                   // FX·W ≈ focal estimada (demo_guiada)
const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
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
const obj = { cx: MAT_W / 2, cy: MAT_H / 2, size: 180, yaw: 0, mesh: null, tris: 0, bbox: null };
let poseOK = false, poseFrozen = false, nmark = 0, lastPose = null, fps = 0, frames = 0, fpsT = performance.now();
const cornerFilters = new Map();
const detector = new AR.Detector({ dictionaryName: "ARUCO_4X4_1000", maxHammingDistance: 2 });
const work = document.createElement("canvas"), wctx = work.getContext("2d", { willReadFrequently: true });

// ---------- pieza
const loader = new GLTFLoader();
async function loadModel(spec) {
  objGroup.clear(); obj.mesh = null; obj.tris = 0;
  let root;
  if (spec === "aro") {
    root = new THREE.Mesh(new THREE.TorusGeometry(0.4, 0.1, 24, 48), new THREE.MeshStandardMaterial({ color: 0xffc83c, metalness: 0.2, roughness: 0.5 }));
  } else {
    const gltf = await loader.loadAsync(spec); root = gltf.scene;
  }
  root.traverse(o => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; const g = o.geometry; obj.tris += g.index ? g.index.count / 3 : g.attributes.position.count / 3; } });
  // normalizar: centrar y escalar la dimensión mayor a 1 (luego obj.size en mm)
  const box = new THREE.Box3().setFromObject(root); const sz = new THREE.Vector3(); box.getSize(sz); const c = new THREE.Vector3(); box.getCenter(c);
  const k = 1 / Math.max(sz.x, sz.y, sz.z); root.position.sub(c).multiplyScalar(k); root.scale.setScalar(k);
  const wrap = new THREE.Group(); wrap.add(root);
  // el modelo viene con Y arriba; nuestro marco tiene Z hacia la cámara (−Z = arriba del tapete): girar −90° en X para que «arriba» = −Z
  wrap.rotation.x = -Math.PI / 2;
  objGroup.add(wrap); obj.mesh = wrap; obj.yaw = 0; objGroup.rotation.set(0, 0, 0);
  obj.bbox = new THREE.Box3().setFromObject(wrap);
  console.log("modelo", spec, "triángulos", Math.round(obj.tris));
}
function placeObject(lifted) {
  if (!obj.mesh) return;
  const s = obj.size; objGroup.scale.setScalar(s);
  const bottom = obj.bbox ? -obj.bbox.min.z * s : s * 0.5;   // en nuestro marco «arriba» es −Z: la parte más alta del modelo está en min.z (negativo)
  const gap = s * (lifted ? 0.45 : 0.04);
  // la pieza apoya su parte baja (max.z, hacia el tapete) a «gap» del plano
  const low = obj.bbox ? obj.bbox.max.z * s : s * 0.5;
  objGroup.position.set(obj.cx, obj.cy, -(gap + low));
  objGroup.rotation.z = obj.yaw;
}

// ---------- manos
let hands = null; const H = new Map();              // identidad por posición, como demo_guiada
class Hand {
  constructor(label) { this.label = label; this.pts = null; this.raw = null; this.palm = null; this.pinch = false; this.pinchCm = null; this.pinchPx = null; this.pinchMM = null; this.last = 0; this.f = new OneEuro(2.5, 0.08); this.holding = false; this.otherHolding = false; this.openSince = null; }
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

// ---------- gestos (v12.3)
let heldBy = null, grabOff = [0, 0], grabYaw = 0, grabAngle = null, two = null; const objFilter = new OneEuro(1.3, 0.04);
let pinchHold = new Set();
function gestures(active, t) {
  const hull = objectHullPx();
  const near = h => { const d = h.pinchMM && Math.hypot(h.pinchMM[0] - obj.cx, h.pinchMM[1] - obj.cy); return d != null && d < obj.size * 0.6 * 2.2; };
  let pinchers = active.filter(h => h.pinch && ((pinchHold.has(h.label) && near(h)) || overPx(hull, h.pinchPx, 40)));
  if (pinchers.length >= 2) { pinchers.sort((a, b) => Math.hypot(a.pinchMM[0] - obj.cx, a.pinchMM[1] - obj.cy) - Math.hypot(b.pinchMM[0] - obj.cx, b.pinchMM[1] - obj.cy)); }
  let grasping = new Set();
  if (pinchers.length >= 2) {
    const a = pinchers[0], b = pinchers[1], p1 = a.pinchMM, p2 = b.pinchMM;
    const dist = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]), ang = Math.atan2(p2[1] - p1[1], p2[0] - p1[0]), mid = [(p1[0] + p2[0]) / 2, (p1[1] + p2[1]) / 2];
    if (!two) { two = { dist, size: obj.size, ang, yaw: obj.yaw, mid, c: [obj.cx, obj.cy], last: ang }; heldBy = null; log("dos pellizcos: girar/escalar"); }
    let a2 = two.last + ((ang - two.last + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI; two.last = a2;
    obj.size = Math.min(300, Math.max(90, two.size * Math.min(1.7, Math.max(0.6, dist / two.dist))));
    obj.yaw = two.yaw + (a2 - two.ang);
    obj.cx = two.c[0] + (mid[0] - two.mid[0]); obj.cy = two.c[1] + (mid[1] - two.mid[1]);
    pinchHold = new Set([a.label, b.label]); grasping.add(a.label); grasping.add(b.label);
  } else if (pinchers.length === 1) {
    const h = pinchers[0]; two = null;
    if (heldBy !== h.label) { heldBy = h.label; grabOff = [h.pinchMM[0] - obj.cx, h.pinchMM[1] - obj.cy]; grabYaw = obj.yaw; grabAngle = h.fingerAngle; objFilter.reset(); log(`cogido por el borde con ${h.label}`); beep(880); }
    const tgt = objFilter.filter([h.pinchMM[0] - grabOff[0], h.pinchMM[1] - grabOff[1]], t);
    obj.cx = Math.min(1.3 * MAT_W, Math.max(-0.3 * MAT_W, tgt[0])); obj.cy = Math.min(1.3 * MAT_H, Math.max(-0.3 * MAT_H, tgt[1]));
    const d = ((h.fingerAngle - grabAngle + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI;
    if (Math.abs(d) > 3 * Math.PI / 180) obj.yaw = grabYaw + d;
    pinchHold = new Set([h.label]); grasping.add(h.label);
  } else {
    if (heldBy || two) { log("soltado"); beep(440); }
    heldBy = null; two = null; pinchHold = new Set();
  }
  for (const h of active) { h.holding = grasping.has(h.label); h.otherHolding = (heldBy && heldBy !== h.label) || false; }
  return { grasping, hull };
}

// ---------- utilidades UI
const logs = []; function log(m) { const s = new Date().toLocaleTimeString() + " " + m; logs.push(s); console.log(s); }
let actx = null; function beep(f) { try { actx = actx || new AudioContext(); const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = f; g.gain.value = 0.08; o.connect(g); g.connect(actx.destination); o.start(); o.stop(actx.currentTime + 0.07); } catch (e) { } }
const overlay = document.createElement("canvas"); overlay.style.cssText = "position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none"; document.getElementById("stage").insertBefore(overlay, document.getElementById("hud")); const octx = overlay.getContext("2d");

// ---------- bucle
let source = video;                                   // fuente de imagen: la cámara o, en modo prueba (?img=…), una foto fija
async function start() {
  const imgParam = new URLSearchParams(location.search).get("img");
  if (imgParam) {                                     // modo prueba sin cámara (el panel del navegador de Claude no permite captura)
    const im = new Image(); im.src = imgParam; await im.decode(); source = im;
    W = im.naturalWidth; Hh = im.naturalHeight; video.style.display = "none";
    const bg = document.createElement("canvas"); bg.width = W; bg.height = Hh; bg.getContext("2d").drawImage(im, 0, 0);
    bg.style.cssText = "position:absolute;inset:0;width:100%;height:100%;object-fit:contain"; document.getElementById("stage").prepend(bg);
  } else {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1920 }, height: { ideal: 1080 }, facingMode: "environment" }, audio: false });
    video.srcObject = stream; await video.play();
    W = video.videoWidth; Hh = video.videoHeight;
  }
  renderer.setSize(W, Hh, false); overlay.width = W; overlay.height = Hh;
  const fx = FX * W; camera.fov = 2 * Math.atan(Hh / (2 * fx)) * 180 / Math.PI; camera.aspect = W / Hh; camera.updateProjectionMatrix();
  work.width = Math.round(W / 4); work.height = Math.round(Hh / 4);   // ArUco a 1/4: ~4× más rápido (86 → ~20 ms)
  const vision = await FilesetResolver.forVisionTasks("https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm");
  hands = await HandLandmarker.createFromOptions(vision, { baseOptions: { modelAssetPath: BASE + "models/hand_landmarker.task", delegate: "GPU" }, runningMode: "VIDEO", numHands: 2, minHandDetectionConfidence: 0.4, minHandPresenceConfidence: 0.4, minTrackingConfidence: 0.4 });
  await loadModel(document.getElementById("model").value);
  document.getElementById("start").style.display = "none";
  stepEl.textContent = "Coge la pieza por el borde con un pellizco. Dos pellizcos: gira como una rueda y escala.";
  requestAnimationFrame(loop);
}
function loop() {
  const t = performance.now() / 1000; frames++; if (performance.now() - fpsT > 1000) { fps = frames * 1000 / (performance.now() - fpsT); frames = 0; fpsT = performance.now(); }
  // --- tapete
  const T0 = performance.now();
  wctx.drawImage(source, 0, 0, work.width, work.height);
  const img = wctx.getImageData(0, 0, work.width, work.height);
  const markers = detector.detect(img).filter(m => m.id >= 0 && m.id <= 3);
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
  placeObject(grasping.size > 0);
  renderer.render(scene, camera);
  const T4 = performance.now();
  const cornersPx = [[0, 0], [MAT_W, 0], [MAT_W, MAT_H], [0, MAT_H]].map(([x, y]) => { const v = new THREE.Vector3(x, y, 0); mat.localToWorld(v); v.project(camera); return [Math.round((v.x + 1) / 2 * W), Math.round((1 - v.y) / 2 * Hh)]; });
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
  statusEl.textContent = `tapete ${poseOK ? (poseFrozen ? "congelado (destapa las esquinas)" : nmark + " marcadores") : "no visto"} · manos ${active.length} · ${heldBy || two ? "COGIDO" : "libre"} · ${Math.round(obj.tris / 1000)} k triángulos · ${fps.toFixed(0)} fps`;
  requestAnimationFrame(loop);
}
function invert3(Mx) { const m = Mx.flat(); const [a, b, c, d, e, f, g, h, i] = m; const det = a * (e * i - f * h) - b * (d * i - f * g) + c * (d * h - e * g); if (Math.abs(det) < 1e-12) return null; const k = 1 / det; return [[(e * i - f * h) * k, (c * h - b * i) * k, (b * f - c * e) * k], [(f * g - d * i) * k, (a * i - c * g) * k, (c * d - a * f) * k], [(d * h - e * g) * k, (b * g - a * h) * k, (a * e - b * d) * k]]; }

// ---------- controles
document.getElementById("go").onclick = () => start().catch(e => { stepEl.textContent = "Error: " + e.message; console.error(e); });
document.getElementById("model").onchange = e => loadModel(e.target.value);
document.getElementById("reset").onclick = () => { obj.cx = MAT_W / 2; obj.cy = MAT_H / 2; obj.size = 180; obj.yaw = 0; };
document.getElementById("quit").onclick = () => { const s = video.srcObject; if (s) s.getTracks().forEach(tr => tr.stop()); stepEl.textContent = "Sesión cerrada."; document.getElementById("start").style.display = "grid"; };
document.getElementById("explain").onclick = async () => {
  // Dentro de Moodle: el visor habla con el tutor de Jedomi del curso (barra lateral ya cargada en la página): adjunta una
  // captura de lo que ve el alumno y envía el contexto. El tutor responde según la configuración del curso (socrático/guiado).
  const trigger = document.getElementById("jedomi-trigger"), input = document.getElementById("jedomi-input"), send = document.getElementById("jedomi-send");
  const name = (MOODLE && MOODLE.modelName) || document.getElementById("model").selectedOptions[0]?.textContent || "la pieza";
  const ctx = `Estoy viendo en realidad aumentada el modelo 3D «${name}» de esta actividad (${Math.round(obj.tris / 1000)} mil triángulos, tamaño ${Math.round(obj.size)} mm, giro ${Math.round(obj.yaw * 180 / Math.PI)}°). Te adjunto la captura de lo que veo. Ayúdame a entender qué es y cómo funciona.`;
  if (!MOODLE || !trigger || !input || !send) {
    iaEl.style.display = "block"; iaEl.textContent = "IA (prototipo local sin Moodle): " + ctx; return;
  }
  try {
    // captura compuesta: fuente de vídeo + 3D
    const snap = document.createElement("canvas"); snap.width = Math.round(W / 2); snap.height = Math.round(Hh / 2); const sctx = snap.getContext("2d");
    sctx.drawImage(source, 0, 0, snap.width, snap.height); sctx.drawImage(canvas, 0, 0, snap.width, snap.height);
    const blob = await new Promise(r => snap.toBlob(r, "image/jpeg", 0.8));
    const fileInput = document.getElementById("jedomi-image-input");
    if (fileInput && blob) {
      const dt = new DataTransfer(); dt.items.add(new File([blob], "visor-ar.jpg", { type: "image/jpeg" })); fileInput.files = dt.files;
      fileInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
  } catch (e) { console.warn("captura no adjuntada", e); }
  if (document.getElementById("jedomi-sidebar")?.classList.contains("jedomi-closed")) trigger.click();
  setTimeout(() => { input.value = ctx; input.dispatchEvent(new Event("input", { bubbles: true })); send.click(); log("Explícame enviado al tutor de Jedomi"); }, 400);
};
