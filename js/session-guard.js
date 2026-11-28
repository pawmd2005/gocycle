/*
 * GoCycle idle-session guard.
 *  - The idle timer only runs while the person does nothing (no mouse, keyboard,
 *    scroll or touch). Any movement restarts it.
 *  - When 30 seconds are left a popup asks: "Stay signed in" or "Log out now".
 *  - When the time reaches zero the person is logged out automatically.
 * Activity is also reported to the server (throttled) so the server-side session
 * stays alive while the person is really using the page. The server still enforces
 * the timeout; this file only keeps the person informed.
 */
(() => {
  "use strict";
  const cfg = document.getElementById("gc-session");
  if (!cfg) return;

  const WARN_AT = 30; // seconds left when the popup appears
  const PING_EVERY = 20000; // ms: at most one "I'm active" report this often
  const timeout = parseInt(cfg.dataset.timeout, 10) || 420; // idle seconds allowed
  const logoutUrl = "logout.php?expired=1";

  let lastActive = Date.now() - (timeout - (parseInt(cfg.dataset.remaining, 10) || timeout)) * 1000;
  let lastPing = Date.now();
  let warning = false; // popup is showing: movement no longer resets the timer
  let ended = false;
  let checking = false;
  let dialog = null;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const fmt = (s) => {
    s = Math.max(0, Math.ceil(s));
    return Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
  };
  const secondsLeft = () => timeout - (Date.now() - lastActive) / 1000;

  /* ---------- server calls ---------- */
  const ping = (method) =>
    fetch(cfg.dataset.ping || "session_ping.php", {
      method,
      credentials: "same-origin",
      cache: "no-store",
      keepalive: method === "POST",
      headers: method === "POST" ? { "X-CSRF-Token": cfg.dataset.csrf || "" } : {},
    });

  // How much idle time does the server think is left? (does not extend the session)
  const serverLeft = async () => {
    try {
      const r = await ping("GET");
      if (r.status === 401) return 0;
      if (!r.ok) return null;
      return (await r.json()).remaining;
    } catch (e) {
      return null; // offline: trust the local clock
    }
  };

  const logout = () => {
    if (ended) return;
    ended = true;
    window.location.href = logoutUrl;
  };

  /* ---------- activity ---------- */
  const onActivity = () => {
    if (warning || ended) return; // once warned, the person must choose a button
    const now = Date.now();
    lastActive = now;
    if (now - lastPing > PING_EVERY) {
      lastPing = now;
      ping("POST").then((r) => { if (r.status === 401) logout(); }).catch(() => {});
    }
  };
  ["mousemove", "mousedown", "keydown", "scroll", "wheel", "touchstart", "touchmove", "pointerdown"].forEach((ev) =>
    window.addEventListener(ev, onActivity, { passive: true, capture: true }),
  );

  /* ---------- popup ---------- */
  const closeDialog = () => {
    if (!dialog) return;
    dialog.backdrop.remove();
    document.body.classList.remove("gc-modal-open");
    dialog = null;
  };

  const toast = (text) => {
    const t = el("div", "gc-session-toast", text);
    t.setAttribute("role", "status");
    document.body.append(t);
    setTimeout(() => t.classList.add("is-out"), 2600);
    setTimeout(() => t.remove(), 3200);
  };

  const stay = async () => {
    const btn = dialog && dialog.stay;
    if (btn) { btn.disabled = true; btn.textContent = "Extending…"; }
    try {
      const r = await ping("POST");
      if (r.ok) {
        lastActive = Date.now();
        lastPing = lastActive;
        warning = false;
        closeDialog();
        toast("Session extended – you're good to go.");
        return;
      }
    } catch (e) { /* fall through */ }
    logout();
  };

  const openWarning = () => {
    closeDialog();
    warning = true;
    const backdrop = el("div", "gc-modal-backdrop gc-session-backdrop");
    const box = el("div", "gc-modal gc-session-modal is-warning");
    box.setAttribute("role", "alertdialog");
    box.setAttribute("aria-modal", "true");
    box.setAttribute("aria-labelledby", "gc-session-title");

    const ring = el("div", "gc-session-ring");
    ring.innerHTML =
      '<svg viewBox="0 0 100 100" aria-hidden="true"><circle class="track" cx="50" cy="50" r="44"/><circle class="bar" cx="50" cy="50" r="44" pathLength="100"/></svg>';
    const count = el("span", "gc-session-count", fmt(WARN_AT));
    ring.append(count);
    const title = el("h2", "gc-modal-title", "Are you still there?");
    title.id = "gc-session-title";
    const message = el(
      "p",
      "gc-modal-message",
      "You have been idle for a while. For your security you will be logged out automatically when the time runs out.",
    );
    const buttons = el("div", "gc-modal-actions");
    const out = el("a", "gc-modal-btn gc-modal-cancel", "Log out");
    out.href = "logout.php";
    const stayBtn = el("button", "gc-modal-btn gc-modal-ok", "Stay signed in");
    stayBtn.type = "button";
    stayBtn.addEventListener("click", stay);
    buttons.append(out, stayBtn);
    box.append(ring, title, message, buttons);
    backdrop.append(box);
    document.body.append(backdrop);
    document.body.classList.add("gc-modal-open");
    dialog = { backdrop, count, ring, stay: stayBtn };
    stayBtn.focus();
    backdrop.addEventListener("keydown", (e) => {
      if (e.key === "Tab") {
        const f = box.querySelectorAll("a,button");
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
  };

  /* ---------- clock ---------- */
  const tick = async () => {
    if (ended) return;
    const left = secondsLeft();

    if (!warning && left <= WARN_AT && !checking) {
      checking = true;
      const real = await serverLeft(); // another tab may have kept the session alive
      checking = false;
      if (real === 0) return logout();
      if (real !== null && real > WARN_AT + 2) {
        lastActive = Date.now() - (timeout - real) * 1000;
        return;
      }
      if (!warning && secondsLeft() <= WARN_AT) openWarning();
      return;
    }

    if (warning && dialog) {
      dialog.count.textContent = fmt(left);
      dialog.ring.style.setProperty("--p", String(Math.max(0, Math.min(1, left / WARN_AT))));
    }
    if (left <= 0) logout(); // time is up: automatic log out
  };

  setInterval(tick, 1000);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) tick(); });
})();
