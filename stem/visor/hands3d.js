// hands3d.js — Manos 3D articuladas y animadas para la intro del visor RA (local_jedomi).
//
// Modelo: perfil "generic-hand" de @webxr-input-profiles/assets (MIT, (c) 2019 Amazon),
// ficheros models/hands/left.glb y right.glb (25 articulaciones con nombres WebXR).
// En el GLB las 25 articulaciones son HERMANAS planas bajo "Armature" (convención WebXR:
// cada joint recibe su pose absoluta del tracking). Aquí se reparentan en cadenas
// (wrist → metacarpal → proximal → intermediate → distal → tip) para poder flexionarlas
// con rotaciones locales por hueso.
//
// API:
//   import { createHandsIntro } from './hands3d.js';
//   const intro = createHandsIntro(canvas, { base, background, step, autoplay });
//   intro.setStep(k)   // 1..5, reinicia el bucle de ese paso
//   intro.play(); intro.pause(); intro.destroy();
//   intro.ready        // Promise<void> resuelta cuando los GLB están cargados
//   intro.getStep(); intro.getFps(); intro.getBoneNames(); intro.seek(u); intro.getTime()
//
// Requiere importmap con "three" y "three/addons/" (igual que visor.js).

import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";

const DEG = Math.PI / 180;
/** Ángulos de las poses (grados). Exportado para poder ajustarlo en QA. */
export const POSES = {
  // Dedos: [MCP, PIP, DIP] mano abierta relajada
  OPEN: { index: [10, 12, 6], middle: [12, 14, 6], ring: [14, 16, 8], pinky: [16, 18, 8] },
  // Dedos: [MCP, PIP, DIP] en el pellizco (índice en gancho, los demás recogidos)
  PINCH: { index: [45, 85, 40], middle: [82, 98, 52], ring: [88, 102, 56], pinky: [92, 102, 58] },
  SPREAD: { index: -7, middle: -1, ring: 6, pinky: 13 },        // abducción mano abierta (hacia el meñique +)
  PINCH_SPREAD: { index: -9, middle: 0, ring: 0, pinky: 0 },   // el índice se acerca al pulgar
  THUMB_PINCH: [30, 10],                                        // flexión MCP e IP del pulgar en el pellizco
  THUMB_GAP: 0.03,                                              // distancia yema-yema objetivo (m)
  THUMB_GAP_DIR: [-1, -0.7, 0.3],                                // dirección índice→pulgar (x se espeja en la izquierda)
};
const FINGERS = ["index", "middle", "ring", "pinky"];
const CHAINS = {
  thumb: ["thumb-metacarpal", "thumb-phalanx-proximal", "thumb-phalanx-distal", "thumb-tip"],
};
for (const f of FINGERS) {
  CHAINS[f] = [
    `${f}-finger-metacarpal`, `${f}-finger-phalanx-proximal`, `${f}-finger-phalanx-intermediate`,
    `${f}-finger-phalanx-distal`, `${f}-finger-tip`,
  ];
}
export const BONE_NAMES = ["wrist", ...Object.values(CHAINS).flat()]; // 25 nombres WebXR

// ---------- Geometría de la escena (metros) ----------
const MAT_W = 0.44, MAT_H = 0.32;          // tapete
const RING_R = 0.07, RING_TUBE = 0.012;    // aro
const RING_Y = RING_TUBE;                  // altura del centro del tubo apoyado
const STEP_DURATION = 5.2;                 // s por bucle

// ---------- Utilidades de interpolación ----------
const easeInOut = (u) => (u < 0.5 ? 4 * u * u * u : 1 - Math.pow(-2 * u + 2, 3) / 2);
const easeOut = (u) => 1 - Math.pow(1 - u, 3);
const easeIn = (u) => u * u * u;
const EASES = { inout: easeInOut, out: easeOut, in: easeIn, linear: (u) => u };
const clamp01 = (x) => Math.min(1, Math.max(0, x));
const lerp = (a, b, u) => a + (b - a) * u;

/** Interpola una lista de keyframes {t, ...} en t∈[0,1]. Devuelve [k0, k1, u]. */
function segment(keys, t) {
  let i = 0;
  while (i < keys.length - 1 && keys[i + 1].t <= t) i++;
  const k0 = keys[i], k1 = keys[Math.min(i + 1, keys.length - 1)];
  const span = k1.t - k0.t;
  const raw = span > 0 ? clamp01((t - k0.t) / span) : 1;
  const u = (EASES[k1.e] || easeInOut)(raw);
  return [k0, k1, u];
}

const _q1 = new THREE.Quaternion(), _q2 = new THREE.Quaternion(), _e = new THREE.Euler();
const _v1 = new THREE.Vector3(), _v2 = new THREE.Vector3(), _v3 = new THREE.Vector3();

/** Orientación de mano desde [pitch, yaw, roll] en grados (orden YXZ: guiñada, cabeceo, balanceo). */
function quatFromPYR(out, pyr) {
  _e.set(pyr[0] * DEG, pyr[1] * DEG, pyr[2] * DEG, "YXZ");
  return out.setFromEuler(_e);
}

// ---------- Mano articulada ----------
class Hand {
  constructor(gltf, side, material) {
    this.side = side;
    this.sign = side === "right" ? 1 : -1; // +1 derecha, -1 izquierda (espejo en X)
    this.root = new THREE.Group();          // pose de la mano en la escena
    this.root.name = `hand-${side}`;
    this.armature = gltf.scene;
    this.root.add(this.armature);

    // Orientación base: dedos hacia -Z (lejos del espectador), palma hacia -Y (abajo).
    // Espacio del GLB: dedos -Y, anchura Z, palma -X (derecha) / +X (izquierda).
    const basis = new THREE.Matrix4();
    if (side === "right") {
      basis.makeBasis(new THREE.Vector3(0, 1, 0), new THREE.Vector3(0, 0, 1), new THREE.Vector3(1, 0, 0));
    } else {
      basis.makeBasis(new THREE.Vector3(0, -1, 0), new THREE.Vector3(0, 0, 1), new THREE.Vector3(-1, 0, 0));
    }
    this.armature.quaternion.setFromRotationMatrix(basis);

    // Huesos por nombre.
    this.bones = {};
    this.found = [];
    this.armature.traverse((o) => { if (BONE_NAMES.includes(o.name)) { this.bones[o.name] = o; this.found.push(o.name); } });
    this.missing = BONE_NAMES.filter((n) => !this.bones[n]);
    if (this.missing.length) console.warn(`[hands3d] ${side}: huesos no encontrados`, this.missing);

    // Malla: material de piel, sombras.
    this.armature.traverse((o) => {
      if (o.isMesh) {
        o.material = material;
        o.castShadow = true;
        o.receiveShadow = false;
        o.frustumCulled = false; // la malla skinned se mueve con los huesos
        this.mesh = o;
      }
    });

    // Reparentar las articulaciones en cadenas conservando su transformación mundial.
    this.armature.updateMatrixWorld(true);
    const wrist = this.bones.wrist;
    for (const chain of Object.values(CHAINS)) {
      let parent = wrist;
      for (const name of chain) {
        const b = this.bones[name];
        if (!b || !parent) { parent = b || parent; continue; }
        parent.attach(b);
        parent = b;
      }
    }
    this.armature.updateMatrixWorld(true);

    // Antebrazo (cilindro) para que la mano no flote cortada en la muñeca.
    const wristPos = wrist.position.clone(); // en espacio del armature (hijo directo)
    const fore = new THREE.Mesh(new THREE.CylinderGeometry(0.027, 0.034, 0.22, 24, 1, false), material);
    fore.position.copy(wristPos).add(new THREE.Vector3(0, 0.11 + 0.005, 0));
    fore.scale.set(0.78, 1, 1.12);
    fore.castShadow = true;
    this.armature.add(fore);

    // Ejes de flexión por articulación, calculados a partir de la pose de reposo.
    // Para cada hueso con hijo: d = dirección al hijo (local), p = normal de la palma (local).
    // curl: eje d×p (flexión hacia la palma); spread: eje p (abducción en el plano de la palma).
    this.joints = {};
    const palmWorld = new THREE.Vector3(0, -1, 0); // root está en identidad en este momento
    for (const [finger, chain] of Object.entries(CHAINS)) {
      for (let i = 0; i < chain.length; i++) {
        const b = this.bones[chain[i]];
        if (!b) continue;
        const child = this.bones[chain[i + 1]];
        const rest = b.quaternion.clone();
        const wq = b.getWorldQuaternion(new THREE.Quaternion()).invert();
        const p = palmWorld.clone().applyQuaternion(wq).normalize();
        let d;
        if (child) d = child.position.clone().normalize();
        else d = new THREE.Vector3(0, 0, 1); // tip: sin hijo
        const curlAxis = new THREE.Vector3().crossVectors(d, p).normalize();
        this.joints[chain[i]] = { bone: b, rest, curlAxis, spreadAxis: p, dir: d, finger, idx: i };
      }
    }

    this.pose = { grip: 0, open: 0 };
    this.thumbSolved = { c: 0, s: 0, w: 0 }; // se calibra en solveThumb()
    this.pinchLocal = new THREE.Vector3();   // punto de pellizco en el espacio del root
    this.tipGap = 0;
  }

  /** Rota un hueso: reposo × giro alrededor de ejes locales (curl y spread en grados). */
  _rot(name, curlDeg, spreadDeg = 0, twistDeg = 0) {
    const j = this.joints[name];
    if (!j) return;
    j.bone.quaternion.copy(j.rest);
    if (curlDeg) j.bone.quaternion.multiply(_q1.setFromAxisAngle(j.curlAxis, curlDeg * DEG));
    if (spreadDeg) j.bone.quaternion.multiply(_q2.setFromAxisAngle(j.spreadAxis, spreadDeg * DEG));
    if (twistDeg) j.bone.quaternion.multiply(_q2.setFromAxisAngle(j.dir, twistDeg * DEG));
  }

  /**
   * Pose de los 25 huesos. grip∈[0,1]: 0 = mano abierta relajada, 1 = pellizco índice-pulgar
   * con los otros tres dedos recogidos. open∈[0,1]: dedos muy abiertos (soltar).
   */
  setPose(grip, open = 0) {
    this.pose.grip = grip; this.pose.open = open;
    const g = grip, o = open * (1 - grip);
    const sp = this.sign; // abducción "hacia el meñique" positiva
    // Dedos: [MCP, PIP, DIP] abiertos → cerrados
    const { OPEN, PINCH, PINCH_SPREAD, SPREAD, THUMB_PINCH } = POSES;
    for (const f of FINGERS) {
      const ch = CHAINS[f];
      const spread = lerp(SPREAD[f] * (1 + 1.4 * o), PINCH_SPREAD[f], g);
      this._rot(ch[0], 0, sp * spread * 0.35);
      for (let i = 0; i < 3; i++) {
        const a = lerp(OPEN[f][i], PINCH[f][i], g) - 14 * o;
        this._rot(ch[1 + i], a, i === 0 ? sp * spread * 0.65 : 0);
      }
      this._rot(ch[4], 0);
    }
    // Pulgar: metacarpiano (CMC) con oposición calibrada, luego MCP e IP.
    const ts = this.thumbSolved;
    this._rot("thumb-metacarpal", lerp(0, ts.c, g) - 10 * o, lerp(0, ts.s, g) - sp * 12 * o, lerp(0, ts.w, g));
    this._rot("thumb-phalanx-proximal", lerp(6, THUMB_PINCH[0], g) - 6 * o);
    this._rot("thumb-phalanx-distal", lerp(6, THUMB_PINCH[1], g) - 6 * o);
    this._rot("thumb-tip", 0);
  }

  /** Posición mundial de un hueso (requiere matrices actualizadas). */
  worldPos(name, out) {
    return this.bones[name].getWorldPosition(out);
  }

  /**
   * Calibra el pulgar: busca los giros del metacarpiano (curl c, oposición s) que ponen la
   * yema del pulgar a `gap` metros de la del índice, en la dirección de la palma, en pose de pellizco.
   * Se hace una vez tras cargar, con el root en identidad.
   */
  solveThumb(gap = POSES.THUMB_GAP) {
    this.root.position.set(0, 0, 0); this.root.quaternion.identity();
    const target = new THREE.Vector3(), tip = new THREE.Vector3(), idx = new THREE.Vector3();
    const gd = POSES.THUMB_GAP_DIR;
    const gapDir = new THREE.Vector3(this.sign * gd[0], gd[1], gd[2]).normalize(); // bajo el índice, hacia el lado del pulgar
    this.setPose(1, 0);
    this.root.updateMatrixWorld(true);
    this.worldPos("index-finger-tip", idx);
    target.copy(idx).addScaledVector(gapDir, gap);
    const meta = this.bones["thumb-metacarpal"];
    let best = { err: Infinity, c: 0, s: 0, w: 0 };
    const evalAt = (c, s, w) => {
      this._rot("thumb-metacarpal", c, s, w);
      meta.updateMatrixWorld(true);
      const err = this.worldPos("thumb-tip", tip).distanceTo(target);
      if (err < best.err) best = { err, c, s, w };
    };
    // Búsqueda gruesa (3 giros del metacarpiano: flexión, abducción, torsión) y refinado.
    for (let c = -30; c <= 120; c += 10) for (let s = -90; s <= 90; s += 10) for (let w = -60; w <= 60; w += 10) evalAt(c, s, w);
    const b0 = { ...best };
    for (let c = b0.c - 9; c <= b0.c + 9; c += 3) for (let s = b0.s - 9; s <= b0.s + 9; s += 3) for (let w = b0.w - 9; w <= b0.w + 9; w += 3) evalAt(c, s, w);
    this.thumbSolved = { c: best.c, s: best.s, w: best.w };
    this.setPose(1, 0);
    this.root.updateMatrixWorld(true);
    this.worldPos("index-finger-tip", idx);
    this.worldPos("thumb-tip", tip);
    this.pinchLocal.copy(idx).add(tip).multiplyScalar(0.5); // root en identidad ⇒ mundo = local
    this.tipGap = idx.distanceTo(tip);
    this.solveInfo = { ...best, tipGap: this.tipGap, pinchLocal: this.pinchLocal.toArray() };
    return this.solveInfo;
  }

  /** Coloca la mano de modo que su punto de pellizco quede en P (mundo) con orientación q. */
  place(P, q) {
    this.root.quaternion.copy(q);
    _v1.copy(this.pinchLocal).applyQuaternion(q);
    this.root.position.copy(P).sub(_v1);
    this.root.updateMatrixWorld(true);
  }
}

// ---------- Escena ----------
function buildScene(background) {
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(background);

  // Tapete claro con 4 marcadores negros en las esquinas.
  const mat = new THREE.Mesh(
    new THREE.PlaneGeometry(MAT_W, MAT_H),
    new THREE.MeshStandardMaterial({ color: 0xf3efe6, roughness: 0.95, metalness: 0 }),
  );
  mat.rotation.x = -Math.PI / 2;
  mat.receiveShadow = true;
  scene.add(mat);
  const mk = 0.034, inset = 0.03;
  const mkGeo = new THREE.PlaneGeometry(mk, mk);
  const mkMat = new THREE.MeshStandardMaterial({ color: 0x111111, roughness: 1 });
  const innerGeo = new THREE.PlaneGeometry(mk * 0.5, mk * 0.5);
  const innerMat = new THREE.MeshStandardMaterial({ color: 0xf3efe6, roughness: 1 });
  for (const sx of [-1, 1]) for (const sz of [-1, 1]) {
    const m = new THREE.Mesh(mkGeo, mkMat);
    m.rotation.x = -Math.PI / 2;
    m.position.set(sx * (MAT_W / 2 - inset), 0.0006, sz * (MAT_H / 2 - inset));
    m.receiveShadow = true;
    scene.add(m);
    // Patrón interior sencillo (recuerda a un ArUco)
    const inner = new THREE.Mesh(innerGeo, innerMat);
    inner.rotation.x = -Math.PI / 2;
    inner.position.set(m.position.x + sx * mk * 0.12, 0.0012, m.position.z - sz * mk * 0.12);
    scene.add(inner);
  }

  // Aro naranja.
  const ring = new THREE.Mesh(
    new THREE.TorusGeometry(RING_R, RING_TUBE, 28, 96),
    new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.38, metalness: 0.25 }),
  );
  ring.castShadow = true;
  ring.receiveShadow = true;
  ring.matrixAutoUpdate = false;
  scene.add(ring);

  // Iluminación suave.
  scene.add(new THREE.HemisphereLight(0xffffff, 0xb8b0a4, 0.75));
  const key = new THREE.DirectionalLight(0xffffff, 1.9);
  key.position.set(0.35, 0.9, 0.45);
  key.castShadow = true;
  key.shadow.mapSize.set(1024, 1024);
  key.shadow.camera.left = -0.4; key.shadow.camera.right = 0.4;
  key.shadow.camera.top = 0.4; key.shadow.camera.bottom = -0.4;
  key.shadow.camera.near = 0.2; key.shadow.camera.far = 2.5;
  key.shadow.bias = -0.0005;
  key.shadow.radius = 4;
  scene.add(key);
  const fill = new THREE.DirectionalLight(0xdfe8ff, 0.6);
  fill.position.set(-0.6, 0.5, -0.3);
  scene.add(fill);

  // Cámara cenital ligeramente inclinada.
  const camera = new THREE.PerspectiveCamera(36, 640 / 400, 0.05, 5);
  camera.position.set(0, 0.54, 0.45);
  camera.lookAt(0, 0.02, -0.02);

  return { scene, ring, camera };
}

// ---------- Coreografía de los 5 pasos ----------
// Keyframe de mano: { t, p:[x,y,z] punto de pellizco (mundo), r:[pitch,yaw,roll] grados, g: grip, o: open, e: easing }
const ROLL = 38;                      // balanceo base: palma hacia abajo-fuera, pulgar bajo el índice
const PITCH = -10;                    // cabeceo base: el antebrazo baja hacia el tapete desde el espectador
const YAW = 34;                       // guiñada base: los dedos apuntan hacia el centro del aro
/** Orientación [pitch, yaw, roll] de una mano (sign=+1 derecha, -1 izquierda) con desvíos. */
const R = (sign, dp = 0, dy = 0, dr = 0) => [PITCH + dp, sign * YAW + dy, sign * ROLL + dr];
const EDGE = (sign, y, s = 1) => [sign * RING_R * s, y, 0];
const FAR = (sign) => [sign * 0.30, 0.12, 0.42];

function rightGrabSequence({ liftY = 0.05, mid = [] }) {
  // Entrada, pellizco, elevación, [tramo intermedio], bajada, soltar, salida.
  const s = 1;
  return [
    { t: 0.00, p: FAR(s), r: R(1), g: 0, o: 0 },
    { t: 0.22, p: EDGE(s, 0.07), r: R(1), g: 0, o: 0 },
    { t: 0.32, p: EDGE(s, RING_Y), r: R(1), g: 0, o: 0, e: "out" },
    { t: 0.40, p: EDGE(s, RING_Y), r: R(1), g: 1, o: 0 },
    { t: 0.50, p: EDGE(s, liftY), r: R(1), g: 1, o: 0 },
    ...mid,
    { t: 0.84, p: EDGE(s, RING_Y), r: R(1), g: 1, o: 0 },
    { t: 0.90, p: EDGE(s, RING_Y), r: R(1), g: 0, o: 0 },
    { t: 1.00, p: FAR(s), r: R(1), g: 0, o: 0 },
  ];
}

const STEPS = {
  // 1) Coger: entra desde abajo, pellizca el borde, el aro se eleva un poco.
  1: {
    right: rightGrabSequence({ liftY: 0.045, mid: [{ t: 0.72, p: EDGE(1, 0.045), r: R(1), g: 1, o: 0 }] }),
    ring: (t, H) => (t >= 0.36 && t < 0.90 ? follow(H.right) : rest()),
  },
  // 2) Mover: con el pellizco, la mano se desplaza y el aro va pegado a ella.
  2: {
    right: rightGrabSequence({
      liftY: 0.05,
      mid: [
        { t: 0.62, p: [RING_R - 0.10, 0.05, 0.07], r: R(1), g: 1, o: 0 },
        { t: 0.74, p: [RING_R + 0.07, 0.05, -0.06], r: R(1), g: 1, o: 0 },
      ],
    }),
    ring: (t, H) => (t >= 0.36 && t < 0.90 ? follow(H.right) : rest()),
  },
  // 3) Girar la muñeca: pronación/supinación y giro; el aro gira igual (rígido).
  3: {
    right: rightGrabSequence({
      liftY: 0.07,
      mid: [
        { t: 0.58, p: EDGE(1, 0.07), r: R(1, -10, 18, 40), g: 1, o: 0 },
        { t: 0.67, p: EDGE(1, 0.07), r: R(1, 12, -22, -36), g: 1, o: 0 },
        { t: 0.76, p: EDGE(1, 0.07), r: R(1), g: 1, o: 0 },
      ],
    }),
    ring: (t, H) => (t >= 0.36 && t < 0.90 ? follow(H.right) : rest()),
  },
  // 4) Dos manos: ambas pellizcan los bordes, se separan (el aro crece) y giran juntas.
  4: (() => {
    const Y = 0.035;
    const S = 1.42, TH = 55; // escala máxima y giro máximo (grados)
    // Trayectoria paramétrica: s(t) escala, th(t) giro sobre el tapete.
    const scaleKeys = [{ t: 0, v: 1 }, { t: 0.40, v: 1 }, { t: 0.56, v: S }, { t: 0.78, v: S }, { t: 0.90, v: 1 }, { t: 1, v: 1 }];
    const turnKeys = [{ t: 0, v: 0 }, { t: 0.56, v: 0 }, { t: 0.78, v: TH }, { t: 0.90, v: 0 }, { t: 1, v: 0 }];
    const yKeys = [{ t: 0, v: RING_Y }, { t: 0.40, v: RING_Y }, { t: 0.47, v: Y }, { t: 0.84, v: Y }, { t: 0.90, v: RING_Y }, { t: 1, v: RING_Y }];
    const val = (keys, t) => { const [k0, k1, u] = segment(keys, t); return lerp(k0.v ?? k1.v, k1.v ?? k0.v, u); };
    const handKeys = (sign) => [
      { t: 0.00, p: FAR(sign), r: R(sign), g: 0, o: 0 },
      { t: 0.22, p: EDGE(sign, 0.07), r: R(sign), g: 0, o: 0 },
      { t: 0.32, p: EDGE(sign, RING_Y), r: R(sign), g: 0, o: 0, e: "out" },
      { t: 0.40, p: EDGE(sign, RING_Y), r: R(sign), g: 1, o: 0 },
      { t: 0.90, p: EDGE(sign, RING_Y), r: R(sign), g: 1, o: 0 }, // tramo controlado por s/θ
      { t: 0.95, p: EDGE(sign, RING_Y), r: R(sign), g: 0, o: 0 },
      { t: 1.00, p: FAR(sign), r: R(sign), g: 0, o: 0 },
    ];
    const st = { scaleKeys, turnKeys, yKeys, val };
    return {
      right: handKeys(1),
      left: handKeys(-1),
      // Sobrescribe posición/orientación de las manos durante el tramo pellizcado.
      override: (t, H, pose) => {
        if (t < 0.40 || t >= 0.90) return;
        const s = st.val(scaleKeys, t), th = st.val(turnKeys, t) * DEG, y = st.val(yKeys, t);
        for (const sign of [1, -1]) {
          const h = sign === 1 ? H.right : H.left;
          const x = sign * RING_R * s;
          pose[h.side].p.set(x * Math.cos(th), y, -x * Math.sin(th));
          quatFromPYR(_q1, R(sign, 0, th / DEG, 0));
          pose[h.side].q.copy(_q1);
        }
      },
      ring: (t) => {
        if (t < 0.40 || t >= 0.90) return rest();
        const s = st.val(scaleKeys, t), th = st.val(turnKeys, t) * DEG, y = st.val(yKeys, t);
        return explicit([0, y, 0], th, s);
      },
    };
  })(),
  // 5) Soltar: la mano abre los dedos, se retira y el aro queda apoyado.
  5: {
    right: [
      { t: 0.00, p: FAR(1), r: R(1), g: 0, o: 0 },
      { t: 0.18, p: EDGE(1, 0.07), r: R(1), g: 0, o: 0 },
      { t: 0.26, p: EDGE(1, RING_Y), r: R(1), g: 0, o: 0, e: "out" },
      { t: 0.33, p: EDGE(1, RING_Y), r: R(1), g: 1, o: 0 },
      { t: 0.45, p: EDGE(1, 0.09), r: R(1), g: 1, o: 0 },
      { t: 0.55, p: EDGE(1, 0.09), r: R(1), g: 1, o: 0 },
      { t: 0.62, p: EDGE(1, 0.09), r: R(1, 0, 0, -ROLL * 0.4), g: 0, o: 1, e: "out" }, // abre los dedos
      { t: 0.74, p: [RING_R + 0.02, 0.16, 0.06], r: R(1, 0, 0, -ROLL * 0.4), g: 0, o: 1 },
      { t: 0.86, p: [0.26, 0.18, 0.30], r: R(1), g: 0, o: 0.3 },
      { t: 1.00, p: FAR(1), r: R(1), g: 0, o: 0 },
    ],
    ring: (t, H) => {
      if (t < 0.30) return rest();
      if (t < 0.57) return follow(H.right);
      // Caída libre desde y=0.09 hasta apoyarse, con un pequeño rebote.
      const u = clamp01((t - 0.57) / 0.11);
      let y;
      if (u < 0.7) y = lerp(0.09, RING_Y, easeIn(u / 0.7));
      else { const b = (u - 0.7) / 0.3; y = RING_Y + 0.012 * Math.sin(Math.PI * b) * (1 - b); }
      return explicit([0, y, 0], 0, 1);
    },
  },
};

// Modos del aro
const _ringM = new THREE.Matrix4();
function rest() { return _ringM.compose(_v1.set(0, RING_Y, 0), _q1.setFromEuler(_e.set(-Math.PI / 2, 0, 0, "XYZ")), _v2.set(1, 1, 1)); }
function explicit(pos, yaw, s) {
  _q1.setFromEuler(_e.set(0, yaw, 0, "XYZ")).multiply(_q2.setFromEuler(_e.set(-Math.PI / 2, 0, 0, "XYZ")));
  return _ringM.compose(_v1.fromArray(pos), _q1, _v2.set(s, s, s));
}
function follow(hand) { return _ringM.multiplyMatrices(hand.root.matrixWorld, hand.ringLocal); }

// ---------- API pública ----------
export function createHandsIntro(canvas, opts = {}) {
  const base = opts.base ?? new URL("models/hands/", import.meta.url).href;
  const background = opts.background ?? 0x14171d;
  let step = opts.step ?? 1;
  let playing = opts.autoplay ?? true;

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, powerPreference: "high-performance" });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;

  const { scene, ring, camera } = buildScene(background);
  const skin = new THREE.MeshStandardMaterial({ color: 0xe6b394, roughness: 0.68, metalness: 0 });

  const H = { right: null, left: null };
  const pose = { right: { p: new THREE.Vector3(), q: new THREE.Quaternion(), g: 0, o: 0 }, left: { p: new THREE.Vector3(), q: new THREE.Quaternion(), g: 0, o: 0 } };

  // Tamaño responsive (lógico 640×400 si el canvas no tiene medidas CSS).
  function resize() {
    const w = canvas.clientWidth || 640, h = canvas.clientHeight || 400;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  resize();
  let ro = null;
  if (typeof ResizeObserver !== "undefined") { ro = new ResizeObserver(resize); ro.observe(canvas); }

  // Carga de los GLB.
  const loader = new GLTFLoader().setPath(base);
  const loadHand = (side) => new Promise((res, rej) => loader.load(`${side}.glb`, res, undefined, rej));
  const ready = Promise.all([loadHand("right"), loadHand("left")]).then(([gr, gl]) => {
    H.right = new Hand(gr, "right", skin);
    H.left = new Hand(gl, "left", skin);
    for (const h of [H.right, H.left]) {
      h.solveThumb(0.03);
      // Matriz del aro en el espacio del root cuando la mano está en la pose canónica de agarre.
      h.place(_v3.fromArray(EDGE(h.sign, RING_Y)), quatFromPYR(_q1, R(h.sign)));
      h.ringLocal = new THREE.Matrix4().copy(h.root.matrixWorld).invert().multiply(rest().clone());
      h.root.visible = false;
      scene.add(h.root);
    }
  });
  ready.catch((e) => console.error("[hands3d] error cargando manos", e));

  // Bucle de animación.
  let raf = 0, t0 = performance.now(), elapsed = 0, lastNow = t0;
  let frames = 0, fpsAccum = 0, fps = 0;
  function evalHand(h, keys, t) {
    const out = pose[h.side];
    if (!keys) { h.root.visible = false; return; }
    h.root.visible = true;
    const [k0, k1, u] = segment(keys, t);
    out.p.set(lerp(k0.p[0], k1.p[0], u), lerp(k0.p[1], k1.p[1], u), lerp(k0.p[2], k1.p[2], u));
    quatFromPYR(_q1, k0.r); quatFromPYR(_q2, k1.r);
    out.q.copy(_q1).slerp(_q2, u);
    out.g = lerp(k0.g, k1.g, u);
    out.o = lerp(k0.o ?? 0, k1.o ?? 0, u);
  }
  function frame(now) {
    raf = requestAnimationFrame(frame);
    const dt = Math.min(0.1, (now - lastNow) / 1000);
    lastNow = now;
    if (playing) elapsed += dt;
    // fps
    frames++; fpsAccum += dt;
    if (fpsAccum >= 1) { fps = frames / fpsAccum; frames = 0; fpsAccum = 0; }

    if (H.right && H.left) {
      const S = STEPS[step] || STEPS[1];
      const t = (elapsed % STEP_DURATION) / STEP_DURATION;
      evalHand(H.right, S.right, t);
      evalHand(H.left, S.left, t);
      if (S.override) S.override(t, H, pose);
      for (const h of [H.right, H.left]) {
        if (!h.root.visible) continue;
        const ps = pose[h.side];
        h.setPose(ps.g, ps.o);
        h.place(ps.p, ps.q);
      }
      ring.matrix.copy(S.ring(t, H));
      ring.matrixWorldNeedsUpdate = true;
    }
    renderer.render(scene, camera);
  }
  raf = requestAnimationFrame(frame);

  return {
    ready,
    setStep(k) { step = Math.max(1, Math.min(5, k | 0)); elapsed = 0; },
    /** Salta a la fracción u∈[0,1) del bucle del paso actual (útil para QA/capturas). */
    seek(u) { elapsed = clamp01(u) * STEP_DURATION; },
    getTime() { return (elapsed % STEP_DURATION) / STEP_DURATION; },
    getStep() { return step; },
    play() { playing = true; },
    pause() { playing = false; },
    getFps() { return fps; },
    getBoneNames() { return H.right ? { right: H.right.found, left: H.left.found, missing: { right: H.right.missing, left: H.left.missing } } : null; },
    getDebug() { return { H, pose, ring, scene, camera, renderer, thumb: H.right && { right: H.right.solveInfo, left: H.left.solveInfo } }; },
    destroy() {
      cancelAnimationFrame(raf);
      if (ro) ro.disconnect();
      scene.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
      skin.dispose();
      scene.traverse(o => { const ms = o.material ? (Array.isArray(o.material) ? o.material : [o.material]) : []; for (const m of ms) { for (const k in m) { const v = m[k]; if (v && v.isTexture) v.dispose(); } m.dispose(); } }); renderer.dispose(); renderer.forceContextLoss();
    },
  };
}
