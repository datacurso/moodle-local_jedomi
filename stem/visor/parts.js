// Piezas de un modelo GLB: agrupa los nodos del CAD en piezas con nombre comprensible, permite resaltarlas, elegirlas por rayo,
// separarlas (vista explosionada) y sacarlas de su sitio. Genérico: cualquier GLB con varias mallas; el catálogo da nombres y
// descripciones a las familias conocidas (Framework Laptop 13) y el resto se nombra limpiando el nombre del nodo.
import * as THREE from "three";

const ES = (document.documentElement.lang || "es").startsWith("es");
// Orden: de lo más específico a lo más general (el primer patrón que encaja gana). Patrones sobre el nombre del nodo sin puntos
// (GLTFLoader quita «.» de los nombres).
const CATALOG = [
  [/KEYBOARD/i, "keyboard", ["Teclado", "Keyboard"], ["Teclado completo de perfil bajo. En el Framework se cambia como un módulo junto con el reposamanos, sin soldaduras.", "Low-profile keyboard. On the Framework it is replaced as one module together with the palm rest, no soldering."]],
  [/C_COVER/i, "ccover", ["Reposamanos (C-cover)", "Palm rest (C-cover)"], ["Carcasa superior de la base: sostiene el teclado y el touchpad y cubre la placa base.", "Top shell of the base: holds the keyboard and touchpad and covers the mainboard."]],
  [/D_COVER/i, "dcover", ["Tapa inferior (D-cover)", "Bottom cover (D-cover)"], ["Carcasa inferior de aluminio de la base; protege la placa base, la batería y el ventilador.", "Aluminium bottom shell of the base; protects the mainboard, battery and fan."]],
  [/COVER_LOGO/i, "logo", ["Logotipo de la tapa", "Lid logo"], ["Logotipo en la tapa de la pantalla (marca de Framework Computer).", "Logo on the display lid (Framework Computer trademark)."]],
  [/COVER_A/i, "acover", ["Tapa de la pantalla (A-cover)", "Display lid (A-cover)"], ["Tapa trasera de aluminio que protege el panel de la pantalla.", "Aluminium back lid that protects the display panel."]],
  [/CAM_LENS/i, "camlens", ["Lente de la cámara", "Webcam lens"], ["Ventana de la cámara web, en el bisel superior.", "Webcam window in the top bezel."]],
  [/CAM_(SWITCH|BRK)/i, "camswitch", ["Interruptor de privacidad de la cámara", "Webcam privacy switch"], ["Corta físicamente la cámara: nadie puede activarla por software mientras está apagada.", "Physically disconnects the webcam: no software can turn it on while it is off."]],
  [/MIC_(SWITCH|BRK)/i, "micswitch", ["Interruptor de privacidad del micrófono", "Microphone privacy switch"], ["Corta físicamente los micrófonos.", "Physically disconnects the microphones."]],
  [/ALS_LENS/i, "als", ["Sensor de luz ambiental", "Ambient light sensor"], ["Mide la luz del entorno para ajustar el brillo de la pantalla.", "Measures ambient light to adjust screen brightness."]],
  [/HINGE_CAP/i, "hingecap", ["Embellecedor de la bisagra", "Hinge cap"], ["Pieza que cubre la bisagra de la pantalla.", "Trim that covers the display hinge."]],
  [/HINGE_PLATE/i, "hinge", ["Placas de la bisagra", "Hinge plates"], ["Unen la pantalla con la base y fijan el ángulo de apertura.", "Join the display to the base and hold the opening angle."]],
  [/LED/i, "led", ["Indicadores LED", "LED indicators"], ["Luces de estado (carga, cámara activa…).", "Status lights (charging, camera on…)."]],
  [/BEZEL/i, "bezel", ["Bisel de la pantalla", "Display bezel"], ["Marco magnético intercambiable alrededor de la pantalla; se quita a mano para llegar al panel.", "Magnetic, swappable frame around the display; it comes off by hand to reach the panel."]],
  [/USBA_CARD/i, "usba", ["Tarjeta de expansión USB-A", "USB-A expansion card"], ["Módulo que se inserta en una ranura lateral y da un puerto USB-A. El alumno elige qué puertos quiere.", "Module that slides into a side bay and provides a USB-A port. You choose which ports you want."]],
  [/USBC_CARD/i, "usbc", ["Tarjeta de expansión USB-C", "USB-C expansion card"], ["Módulo de expansión con puerto USB-C (datos, vídeo y carga).", "Expansion module with a USB-C port (data, video and charging)."]],
  [/HDMI_CARD/i, "hdmi", ["Tarjeta de expansión HDMI", "HDMI expansion card"], ["Módulo de expansión con salida de vídeo HDMI.", "Expansion module with an HDMI video output."]],
  [/SD_CARD/i, "sd", ["Tarjeta de expansión SD", "SD expansion card"], ["Módulo de expansión con lector de tarjetas SD.", "Expansion module with an SD card reader."]],
  [/FRAME/i, "frame", ["Bastidor interno", "Internal frame"], ["Estructura que da rigidez y sobre la que se montan los componentes.", "Structure that gives rigidity and holds the components."]],
];
function classify(name) {
  for (const [re, key, n, d] of CATALOG) if (re.test(name)) return { key, name: n[ES ? 0 : 1], desc: d[ES ? 0 : 1] };
  const clean = name.replace(/[_\s]*\d+$/g, "").replace(/_/g, " ").trim() || (ES ? "Pieza" : "Part");
  return { key: "n:" + clean.toLowerCase(), name: clean, desc: "" };
}

// Construye las piezas tras cargar el modelo (root ya colocado en la escena y con matrices actualizadas).
// meta (opcional): piezas definidas por el docente en la actividad [{key, name, description, nodes:[nombres crudos del GLB]}];
// tienen prioridad sobre el catálogo. GLTFLoader sanea los nombres de nodo (quita [ ] . : /), así que se comparan saneados.
export function buildParts(root, meta = null) {
  root.updateMatrixWorld(true);
  const byNode = new Map();
  for (const p of (Array.isArray(meta) ? meta : [])) for (const n of (p.nodes || [])) byNode.set(THREE.PropertyBinding.sanitizeNodeName(String(n)), p);
  const groups = new Map(), meshes = [];
  root.traverse(o => { if (o.isMesh) meshes.push(o); });
  if (meshes.length < 2) return [];
  for (const m of meshes) {
    // se desplaza la propia malla; el nombre es el de la malla o, si GLTFLoader la creó como primitiva sin nombre propio, el de su grupo
    const node = m;
    const generic = !m.name || /^(mesh|primitive|object)?_?\d*$/i.test(m.name);
    const nm = (generic && m.parent ? m.parent.name : m.name) || "";
    const t = byNode.get(nm) || byNode.get(m.name) || (m.parent && byNode.get(m.parent.name));
    const c = t ? { key: "t:" + (t.key || t.name), name: t.name || nm, desc: t.description || "" } : classify(nm);
    if (!groups.has(c.key)) groups.set(c.key, { ...c, nodes: new Set(), meshes: [] });
    const g = groups.get(c.key); g.nodes.add(node); g.meshes.push(m);
  }
  // centro del modelo y de cada pieza en coordenadas de root
  const inv = root.matrixWorld.clone().invert(), box = new THREE.Box3(), mbox = new THREE.Box3();
  const parts = [];
  for (const g of groups.values()) {
    box.makeEmpty(); for (const m of g.meshes) box.expandByObject(m);
    const cW = box.getCenter(new THREE.Vector3()), cL = cW.clone().applyMatrix4(inv);
    mbox.union(box);
    const nodes = [...g.nodes].map(n => {
      // M: root→padre del nodo. L0: matriz original del nodo expresada en coordenadas de root (para girar/mover la pieza en root)
      const m4 = n.parent.matrixWorld.clone().invert().multiply(root.matrixWorld); n.updateMatrix();
      const L0 = m4.clone().invert().multiply(n.matrix);
      return { n, m4, L0, p0: n.position.clone(), q0: n.quaternion.clone(), s0: n.scale.clone() };
    });
    for (const m of g.meshes) { if (Array.isArray(m.material)) m.material = m.material.map(x => x.clone()); else m.material = m.material.clone(); }
    parts.push({ key: g.key, name: g.name, desc: g.desc, meshes: g.meshes, nodes, centerL: cL, dir: new THREE.Vector3(), offset: new THREE.Vector3(), rot: new THREE.Quaternion() });
  }
  const cModel = mbox.getCenter(new THREE.Vector3()).applyMatrix4(inv);
  const sizeL = new THREE.Box3().setFromObject(root).getSize(new THREE.Vector3()).length() / (root.matrixWorld.getMaxScaleOnAxis() || 1);
  for (const p of parts) {
    const d = p.centerL.clone().sub(cModel);
    if (d.length() < 1e-6 * sizeL) d.set(0, 1, 0).multiplyScalar(0.05 * sizeL);   // pieza centrada (bastidor): sube un poco
    d.y *= 1.6;                                                                    // separar más en vertical: las capas del portátil se ven mejor
    p.dir.copy(d);
  }
  parts.sort((a, b) => b.meshes.length - a.meshes.length);
  return parts;
}

// aplica separación (0…1,2) + desplazamiento y giro propios de cada pieza (giro alrededor de su centro), en coordenadas de root
const _T = new THREE.Matrix4(), _A = new THREE.Matrix4(), _B = new THREE.Matrix4(), _R = new THREE.Matrix4(), _v = new THREE.Vector3();
export function applyParts(parts, explode) {
  for (const p of parts) {
    _v.copy(p.dir).multiplyScalar(explode).add(p.offset);
    _T.makeTranslation(_v.x, _v.y, _v.z);
    _A.makeTranslation(p.centerL.x, p.centerL.y, p.centerL.z); _B.makeTranslation(-p.centerL.x, -p.centerL.y, -p.centerL.z); _R.makeRotationFromQuaternion(p.rot);
    const W = _T.clone().multiply(_A).multiply(_R).multiply(_B);          // transformación de la pieza en root
    for (const k of p.nodes) { const m = k.m4.clone().multiply(W).multiply(k.L0); m.decompose(k.n.position, k.n.quaternion, k.n.scale); }
  }
}
export function resetPart(p) { p.offset.set(0, 0, 0); p.rot.identity(); }
// centro actual de la pieza en root (tras separación y desplazamiento)
export function partCenter(p, explode) { return p.centerL.clone().addScaledVector(p.dir, explode).add(p.offset); }

export function setHighlight(part, kind) {        // kind: "hover" | "selected" | null
  if (!part) return;
  const col = kind === "selected" ? 0xf59e0b : kind === "hover" ? 0x38bdf8 : 0x000000, intensity = kind === "selected" ? 0.45 : kind === "hover" ? 0.35 : 0;
  for (const m of part.meshes) for (const mat of (Array.isArray(m.material) ? m.material : [m.material])) {
    if (!mat.emissive) continue; if (mat.userData.e0 === undefined) { mat.userData.e0 = mat.emissive.getHex(); mat.userData.ei0 = mat.emissiveIntensity ?? 1; }
    if (kind) { mat.emissive.setHex(col); mat.emissiveIntensity = intensity; } else { mat.emissive.setHex(mat.userData.e0); mat.emissiveIntensity = mat.userData.ei0; }
  }
}

const ray = new THREE.Raycaster();
export function pickPart(parts, root, camera, px, W, H) {   // px: píxel de la imagen
  if (!parts.length) return null;
  ray.setFromCamera(new THREE.Vector2(px[0] / W * 2 - 1, -(px[1] / H * 2 - 1)), camera);
  const hit = ray.intersectObject(root, true)[0]; if (!hit) return null;
  return parts.find(p => p.meshes.includes(hit.object)) || null;
}
