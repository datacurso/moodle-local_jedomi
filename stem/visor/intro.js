// Intro animada del visor RA (estándar de gestos Jedomi RA v1): cinco pasos, uno a uno, con manos dibujadas haciendo el gesto.
// Botones: Omitir intro, Siguiente; al terminar se muestra el panel de tarjetas con «Activar cámara». Se recuerda en el navegador
// (localStorage, sólo comodidad del espectador) para no repetirla; «Ver la intro otra vez» la relanza.
(function () {
  const cfg = window.JEDOMI_VISOR || {}, S = cfg.strings || {};
  const intro = document.getElementById("jv-intro"); if (!intro) return;
  const caption = document.getElementById("intro-caption"), dots = document.getElementById("intro-dots"), nextBtn = document.getElementById("intro-next"), skipBtn = document.getElementById("intro-skip"), replay = document.getElementById("intro-replay");
  const steps = [S.intro_s1, S.intro_s2, S.intro_s3, S.intro_s4, S.intro_s5].map((s, k) => s || ("Paso " + (k + 1)));
  const STEP_MS = 5200; let i = 0, timer = null, paused = false;
  const reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;   // WCAG 2.2.2: sin auto-avance; «Siguiente» marca el ritmo
  dots.innerHTML = steps.map((_, k) => `<button type="button" data-k="${k}" aria-label="${k + 1}"></button>`).join("");
  dots.querySelectorAll("button").forEach(b => b.onclick = () => show(+b.dataset.k));
  const panel = intro.querySelector(".ipanel");
  const arm = () => { clearTimeout(timer); if (!reduced && !paused) timer = setTimeout(() => (i + 1 < steps.length ? show(i + 1) : finish()), STEP_MS); };
  ["mouseenter", "focusin"].forEach(ev => panel.addEventListener(ev, () => { paused = true; clearTimeout(timer); }));   // pausa para leer con calma
  ["mouseleave", "focusout"].forEach(ev => panel.addEventListener(ev, () => { paused = false; arm(); }));
  function show(k) {
    i = k; intro.dataset.step = String(k + 1); caption.textContent = steps[k];
    dots.querySelectorAll("button").forEach((d, j) => { d.classList.toggle("on", j <= k); d.setAttribute("aria-current", j === k ? "step" : "false"); });
    nextBtn.textContent = k + 1 < steps.length ? (S.intro_next || "Siguiente") : (S.intro_done || "Entendido");
    arm();
  }
  function finish() { clearTimeout(timer); intro.style.display = "none"; try { localStorage.setItem("jedomi_visor_intro_seen", "1"); } catch (e) { } document.getElementById("go")?.focus(); }
  skipBtn.onclick = finish; nextBtn.onclick = () => (i + 1 < steps.length ? show(i + 1) : finish());
  if (replay) replay.onclick = e => { e.preventDefault(); intro.style.display = "grid"; show(0); };
  let seen = false; try { seen = localStorage.getItem("jedomi_visor_intro_seen") === "1"; } catch (e) { }
  if (seen) intro.style.display = "none"; else show(0);
})();
