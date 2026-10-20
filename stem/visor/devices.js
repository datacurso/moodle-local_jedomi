// Pantalla previa de dispositivos (como antes de entrar a una videollamada): elegir cámara con vista previa, comprobar el
// micrófono con un medidor de nivel y entrar. Recuerda la elección en localStorage (comodidad del espectador, con try/catch).
// El reconocimiento de voz del navegador (Conversar) no admite elegir micrófono: usa el predeterminado del sistema; aquí sólo se comprueba.
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
    catch (e) { try { stream = await navigator.mediaDevices.getUserMedia({ video: c.video, audio: false }); status.textContent = strings.pj_nomic || "Sin acceso al micrófono: podrás ver, pero no conversar por voz."; } catch (e2) { note.textContent = strings.pj_nocam || "Sin cámara"; return; } }
    pv.srcObject = stream; pv.play().catch(() => { });
    const at = stream.getAudioTracks()[0];
    if (at) { actx = new (window.AudioContext || window.webkitAudioContext)(); const src = actx.createMediaStreamSource(new MediaStream([at])); const an = actx.createAnalyser(); an.fftSize = 512; src.connect(an);
      const buf = new Uint8Array(an.frequencyBinCount); let peak = 0;
      const tick = () => { an.getByteTimeDomainData(buf); let s = 0; for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; s += v * v; } const rms = Math.sqrt(s / buf.length); peak = Math.max(peak * 0.95, rms);
        level.style.width = Math.min(100, Math.round(peak * 400)) + "%"; status.textContent = peak < 0.01 ? (strings.pj_speak || "Habla para ver el nivel…") : ""; raf = requestAnimationFrame(tick); }; tick(); }
  }
  async function fill() {
    let devs = [];
    try { await navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(s => s.getTracks().forEach(t => t.stop())); } catch (e) { /* sin permiso: las etiquetas pueden venir vacías */ }
    try { devs = await navigator.mediaDevices.enumerateDevices(); } catch (e) { }
    const cams = devs.filter(d => d.kind === "videoinput"), mics = devs.filter(d => d.kind === "audioinput");
    const opt = (d, i, kind) => `<option value="${d.deviceId}">${(d.label || (kind + " " + (i + 1))).replace(/</g, "&lt;")}</option>`;
    camSel.innerHTML = cams.map((d, i) => opt(d, i, "Cámara")).join(""); micSel.innerHTML = mics.map((d, i) => opt(d, i, "Micrófono")).join("");
    // cámara por defecto: la recordada; si no, una OBSBOT (cámara de la estación); si no, la primera. Micrófono: recordado o predeterminado.
    const savedCam = read("jedomi_visor_cam"), savedMic = read("jedomi_visor_mic");
    const pick = (sel, saved, pref) => { const ids = [...sel.options].map(o => o.value); if (saved && ids.includes(saved)) sel.value = saved; else if (pref) { const o = [...sel.options].find(o => pref.test(o.textContent)); if (o) sel.value = o.value; } };
    pick(camSel, savedCam, /obsbot(?!.*virtual)/i); pick(micSel, savedMic, null);
    if (!cams.length && !testMode) { note.textContent = strings.pj_nodev || "Sin acceso a cámara/micrófono"; }
    if (cams.length) await openPreview();
    ready = true;
  }
  camSel.onchange = () => { store("jedomi_visor_cam", camSel.value); openPreview(); };
  micSel.onchange = () => { store("jedomi_visor_mic", micSel.value); openPreview(); };
  go.onclick = () => { const camId = camSel.value || null; store("jedomi_visor_cam", camId || ""); stopAll(); onEnter({ cameraId: camId }); };
  fill();
  return { stop: stopAll, isReady: () => ready };
}
