/*
 * GoCycle workspace motion: counts numbers up on the KPI / metric cards.
 * Also animates numbers that the page fills in later (admin security metrics).
 * Purely cosmetic – the real value is always the final text.
 */
(() => {
  "use strict";
  if (window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  const SEL = ".ws-kpi strong, .monitor-metric strong";
  let obs = null;

  const run = (el, target) => {
    if (el._target === target) return;
    el._target = target;
    cancelAnimationFrame(el._raf);
    if (target === 0) { write(el, "0"); return; }
    const dur = Math.min(1100, 400 + target * 12);
    const t0 = performance.now();
    const step = (now) => {
      const k = Math.min(1, (now - t0) / dur);
      const eased = 1 - Math.pow(1 - k, 3);
      write(el, String(Math.round(target * eased)));
      if (k < 1) el._raf = requestAnimationFrame(step);
    };
    el._raf = requestAnimationFrame(step);
  };

  const write = (el, text) => {
    if (el.textContent !== text) el.textContent = text;
    if (obs) obs.takeRecords(); // ignore our own writes
  };

  const init = (el) => {
    const text = el.textContent.trim();
    if (/^\d+$/.test(text)) run(el, parseInt(text, 10));
  };

  const start = () => {
    const els = document.querySelectorAll(SEL);
    els.forEach(init);
    obs = new MutationObserver((records) => {
      const seen = new Set();
      records.forEach((r) => {
        const el = r.target.nodeType === 1 ? r.target : r.target.parentElement;
        const host = el && el.closest ? el.closest(SEL) : null;
        if (host && !seen.has(host)) { seen.add(host); init(host); }
      });
    });
    els.forEach((el) => obs.observe(el, { childList: true, characterData: true, subtree: true }));
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
