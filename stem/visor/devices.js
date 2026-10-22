// Device prejoin screen (as before joining a video call): pick a camera with a preview, check the microphone with a
// level meter and enter. The choice is remembered in localStorage (viewer convenience, inside try/catch).
// The browser speech recognition (Talk) cannot pick a microphone: it uses the system default; here it is only checked.
/**
 * Wires the prejoin screen of the viewer.
 *
 * @param {Object} opts
 * @param {Object} opts.strings Viewer language strings (pj_* keys).
 * @param {boolean} opts.testMode True when a still image replaces the camera.
 * @param {Function} opts.onEnter Called with {cameraId, view} when the student enters.
 * @returns {{stop: Function, isReady: Function}} Controls of the preview.
 */
export function initPrejoin({ strings = {}, testMode = false, onEnter }) {
  const camSel = document.getElementById("pjcam"), micSel = document.getElementById("pjmic"), pv = document.getElementById("pjvideo");
  const note = document.getElementById("pjcamnote"), level = document.getElementById("pjlevel"), status = document.getElementById("pjstatus"), go = document.getElementById("go");
  let stream = null, actx = null, raf = 0, ready = false;
  const store = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { } }, read = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };
  const stopAll = () => { cancelAnimationFrame(raf); if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } if (actx) { actx.close().catch(() => { }); actx = null; } level.style.width = "0"; };

  async function openPreview() {
    stopAll(); status.textContent = ""; note.textContent = "";
    const camId = camSel.value, micId = micSel.value;
    const c = { video: camId ? { deviceId: { exact: camId }, width: { ideal: 1280 }, height: { ideal: 720 } } : { width: { ideal: 1280 } }, audio: micId ? { deviceId: { exact: micId } } : true };
    try { stream = await navigator.mediaDevices.getUserMedia(c); }
    catch (e) { try { stream = await navigator.mediaDevices.getUserMedia({ video: c.video, audio: false }); status.textContent = strings.pj_nomic; } catch (e2) { note.textContent = strings.pj_nocam; return; } }
    pv.srcObject = stream; pv.play().catch(() => { });
    const at = stream.getAudioTracks()[0];
    if (at) { actx = new (window.AudioContext || window.webkitAudioContext)(); const src = actx.createMediaStreamSource(new MediaStream([at])); const an = actx.createAnalyser(); an.fftSize = 512; src.connect(an);
      const buf = new Uint8Array(an.frequencyBinCount); let peak = 0;
      const tick = () => { an.getByteTimeDomainData(buf); let s = 0; for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; s += v * v; } const rms = Math.sqrt(s / buf.length); peak = Math.max(peak * 0.95, rms);
        level.style.width = Math.min(100, Math.round(peak * 400)) + "%"; status.textContent = peak < 0.01 ? (strings.pj_speak) : ""; raf = requestAnimationFrame(tick); }; tick(); }
  }
  async function fill() {
    let devs = [];
    try { await navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(s => s.getTracks().forEach(t => t.stop())); } catch (e) { /* no permission: labels may be empty */ }
    try { devs = await navigator.mediaDevices.enumerateDevices(); } catch (e) { }
    const cams = devs.filter(d => d.kind === "videoinput"), mics = devs.filter(d => d.kind === "audioinput");
    const fillSel = (sel, list, label) => { sel.replaceChildren(...list.map((d, i) => new Option(d.label || String(label).replace("{n}", i + 1), d.deviceId))); };
    fillSel(camSel, cams, strings.pj_cam_n); fillSel(micSel, mics, strings.pj_mic_n);
    // Default camera and microphone: the remembered ones, otherwise the first / system default.
    const savedCam = read("jedomi_visor_cam"), savedMic = read("jedomi_visor_mic");
    const pick = (sel, saved) => { const ids = [...sel.options].map(o => o.value); if (saved && ids.includes(saved)) sel.value = saved; };
    pick(camSel, savedCam); pick(micSel, savedMic);
    if (!cams.length && !testMode) { note.textContent = strings.pj_nodev; }
    if (cams.length) await openPreview();
    ready = true;
  }
  camSel.onchange = () => { store("jedomi_visor_cam", camSel.value); openPreview(); };
  micSel.onchange = () => { store("jedomi_visor_mic", micSel.value); openPreview(); };
  // Chosen view: «ar» (camera and mat) or «web» (3D on screen, no camera); it is remembered.
  const viewRadios = [...document.querySelectorAll('input[name="jv-view"]')];
  const camBlock = document.getElementById("pjcamblock");
  const applyView = () => { const v = (viewRadios.find(r => r.checked) || {}).value || "ar"; if (camBlock) camBlock.hidden = v === "web"; document.querySelector(".pjcam")?.toggleAttribute("hidden", v === "web"); if (v === "web") { cancelAnimationFrame(raf); if (stream) stream.getVideoTracks().forEach(t => t.stop()); } };
  const savedView = read("jedomi_visor_view"); if (savedView) viewRadios.forEach(r => { r.checked = r.value === savedView; });
  viewRadios.forEach(r => r.addEventListener("change", () => { store("jedomi_visor_view", r.value); applyView(); if (r.value === "ar" && r.checked) openPreview(); }));
  go.onclick = () => { const v = (viewRadios.find(r => r.checked) || {}).value || "ar"; const camId = camSel.value || null; store("jedomi_visor_cam", camId || ""); stopAll(); onEnter({ cameraId: camId, view: v }); };
  setTimeout(applyView, 0);
  fill();
  return { stop: stopAll, isReady: () => ready };
}
