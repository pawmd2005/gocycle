/*
 * GoCycle workspace UI helpers (no inline scripts, no libraries).
 *  1. Confirmation popup for forms   <form data-confirm="..." data-confirm-title="..." data-confirm-ok="..." data-confirm-tone="danger|ok">
 *  2. Confirmation popup for links   <a href="logout.php" data-confirm-link data-confirm-title="..." ...>
 *  3. Toast popups for status notices   <div data-toast class="booking-notice success">...</div>
 * Without JavaScript the forms and links still work; the server never depends on this file.
 */
(() => {
  "use strict";

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  /* ---------- Confirmation popup ---------- */
  let openDialog = null;

  const ask = (options) =>
    new Promise((resolve) => {
      if (openDialog) openDialog.close(false);
      const previous = document.activeElement;
      const tone = options.tone === "danger" ? "danger" : "ok";

      const backdrop = el("div", "gc-modal-backdrop");
      const dialog = el("div", "gc-modal gc-modal-" + tone);
      dialog.setAttribute("role", "alertdialog");
      dialog.setAttribute("aria-modal", "true");
      const titleId = "gc-modal-title-" + Date.now();
      dialog.setAttribute("aria-labelledby", titleId);

      const icon = el(
        "div",
        "gc-modal-icon",
        tone === "danger" ? "!" : "\u2713",
      );
      icon.setAttribute("aria-hidden", "true");
      const title = el(
        "h2",
        "gc-modal-title",
        options.title || "Are you sure?",
      );
      title.id = titleId;
      const message = el("p", "gc-modal-message", options.message || "");
      const actions = el("div", "gc-modal-actions");
      const cancel = el(
        "button",
        "gc-modal-btn gc-modal-cancel",
        options.cancelText || "Keep it",
      );
      cancel.type = "button";
      const ok = el(
        "button",
        "gc-modal-btn gc-modal-ok",
        options.okText || "Yes, continue",
      );
      ok.type = "button";
      actions.append(cancel, ok);
      dialog.append(icon, title, message, actions);
      backdrop.appendChild(dialog);
      document.body.appendChild(backdrop);
      document.body.classList.add("gc-modal-open");

      const close = (result) => {
        document.removeEventListener("keydown", onKey, true);
        backdrop.remove();
        document.body.classList.remove("gc-modal-open");
        openDialog = null;
        if (previous && previous.focus) previous.focus();
        resolve(result);
      };
      const onKey = (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          close(false);
          return;
        }
        if (event.key === "Tab") {
          // keep keyboard focus inside the popup
          const first = cancel,
            last = ok;
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
      };
      document.addEventListener("keydown", onKey, true);
      cancel.addEventListener("click", () => close(false));
      ok.addEventListener("click", () => close(true));
      backdrop.addEventListener("mousedown", (event) => {
        if (event.target === backdrop) close(false);
      });
      openDialog = { close };
      (tone === "danger" ? cancel : ok).focus();
    });

  const optionsFrom = (node) => ({
    title: node.dataset.confirmTitle,
    message: node.dataset.confirm || node.dataset.confirmMessage,
    okText: node.dataset.confirmOk,
    cancelText: node.dataset.confirmCancel,
    tone: node.dataset.confirmTone,
  });

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (
      !(form instanceof HTMLFormElement) ||
      form.dataset.confirm === undefined ||
      form.dataset.confirmed === "1"
    )
      return;
    event.preventDefault();
    const submitter = event.submitter;
    ask(optionsFrom(form)).then((yes) => {
      if (!yes) return;
      form.dataset.confirmed = "1";
      if (form.requestSubmit && submitter) form.requestSubmit(submitter);
      else form.submit();
    });
  });

  document.addEventListener("click", (event) => {
    const link =
      event.target instanceof Element
        ? event.target.closest("a[data-confirm-link]")
        : null;
    if (!link) return;
    event.preventDefault();
    ask(optionsFrom(link)).then((yes) => {
      if (yes) window.location.href = link.href;
    });
  });

  /* ---------- Toast popups for status notices ---------- */
  const mountToasts = () => {
    const notices = document.querySelectorAll("[data-toast]");
    if (!notices.length) return;
    const stack = el("div", "gc-toast-stack");
    stack.setAttribute("aria-live", "polite");
    document.body.appendChild(stack);
    notices.forEach((notice) => {
      const isError =
        notice.classList.contains("error") ||
        notice.classList.contains("notice-error");
      const toast = el(
        "div",
        "gc-toast " + (isError ? "gc-toast-error" : "gc-toast-ok"),
      );
      toast.setAttribute("role", isError ? "alert" : "status");
      toast.append(
        el("span", "gc-toast-icon", isError ? "!" : "\u2713"),
        el("span", "gc-toast-text", notice.textContent.trim()),
      );
      const closeButton = el("button", "gc-toast-close", "\u00d7");
      closeButton.type = "button";
      closeButton.setAttribute("aria-label", "Dismiss");
      const dismiss = () => {
        toast.classList.add("is-leaving");
        setTimeout(() => toast.remove(), 220);
      };
      closeButton.addEventListener("click", dismiss);
      toast.appendChild(closeButton);
      stack.appendChild(toast);
      setTimeout(dismiss, isError ? 9000 : 6000);
      notice.remove();
    });
  };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", mountToasts);
  else mountToasts();
})();

/* Dashboard profile password controls: one stable eye button per field. */
document.querySelectorAll("[data-workspace-password-toggle]").forEach(function (button) {
  button.addEventListener("click", function () {
    var input = document.getElementById(button.getAttribute("data-workspace-password-toggle"));
    if (!input) return;
    var showing = input.type === "text";
    input.type = showing ? "password" : "text";
    button.setAttribute("aria-pressed", showing ? "false" : "true");
    button.setAttribute("aria-label", showing ? "Show password" : "Hide password");
  });
});


/* Dashboard password strength feedback mirrors the registration meter. */
(function () {
  var password = document.getElementById("account-new-password");
  var confirm = document.getElementById("account-confirm-password");
  var meter = document.getElementById("account-password-strength");
  if (!password || !meter) return;
  var form = password.closest("form");
  var minLength = Number(password.getAttribute("data-min-password") || 10);
  function evaluatePassword() {
    var value = password.value;
    var score = 0;
    if (!value) {
      meter.innerHTML = "Password strength: <b>Not checked</b>";
      password.setCustomValidity("");
      if (confirm) confirm.setCustomValidity("");
      return;
    }
    if (value.length >= 10) score++;
    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
    if (/[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value)) score++;
    var label = score >= 3 ? "Strong" : score === 2 ? "Medium" : "Weak";
    meter.innerHTML = 'Password strength: <b class="account-strength-' + label.toLowerCase() + '">' + label + "</b>";
    var issue = value.length < minLength
      ? "Use at least " + minLength + " characters."
      : value.length > 64
        ? "Use no more than 64 characters."
        : !/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/[0-9]/.test(value)
          ? "Include uppercase, lowercase, and a number."
          : "";
    password.setCustomValidity(issue);
    if (confirm) confirm.setCustomValidity(confirm.value !== value ? "Passwords do not match." : "");
  }
  password.addEventListener("input", evaluatePassword);
  if (confirm) confirm.addEventListener("input", evaluatePassword);
  if (form) form.addEventListener("submit", function (event) {
    if (!password.value) return;
    evaluatePassword();
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
    }
  });
  evaluatePassword();
})();
