document.addEventListener("DOMContentLoaded", function () {
  if (document.body.classList.contains("registration-complete")) {
    try {
      sessionStorage.removeItem("gocycle-registration-draft");
    } catch (ignore) {}
  }

  document
    .querySelectorAll("[data-password-toggle]")
    .forEach(function (button) {
      button.addEventListener("click", function () {
        var target = document.getElementById(
          button.getAttribute("data-password-toggle"),
        );
        if (!target) return;
        var visible = target.type === "text";
        target.type = visible ? "password" : "text";
        if (!button.querySelector("svg"))
          button.textContent = visible ? "Show" : "Hide";
        button.setAttribute("aria-pressed", visible ? "false" : "true");
        button.setAttribute(
          "aria-label",
          visible ? "Show password" : "Hide password",
        );
      });
    });

  document
    .querySelectorAll("[data-captcha-refresh]")
    .forEach(function (button) {
      button.addEventListener("click", function () {
        var image = button.parentElement.querySelector(".captcha-image");
        if (image) image.src = "captcha_image.php?refresh=1&t=" + Date.now();
      });
    });

  var form = document.querySelector("form[data-persist-key]");
  var draftKey = form ? form.getAttribute("data-persist-key") : "";
  var saved = {};
  function skipDraftField(field) {
    return (
      !field.name ||
      ["hidden", "submit", "button", "file"].indexOf(field.type) !==
        -1 ||
      /^captcha$/i.test(field.name)
    );
  }
  if (form && draftKey) {
    try {
      saved = JSON.parse(sessionStorage.getItem(draftKey) || "{}");
    } catch (ignore) {
      saved = {};
    }
    Object.keys(saved).forEach(function (key) {
      if (/^captcha$/i.test(key)) delete saved[key];
    });
    try {
      sessionStorage.setItem(draftKey, JSON.stringify(saved));
    } catch (ignore) {}
    form.querySelectorAll("input,select,textarea").forEach(function (field) {
      if (skipDraftField(field)) return;
      if (Object.prototype.hasOwnProperty.call(saved, field.name)) {
        if (field.type === "checkbox" || field.type === "radio")
          field.checked = !!saved[field.name];
        else if (!field.value) field.value = saved[field.name];
      }
      field.addEventListener("input", saveDraft);
      field.addEventListener("change", saveDraft);
    });
  }
  function saveDraft() {
    if (!form || !draftKey) return;
    var data = {};
    form.querySelectorAll("input,select,textarea").forEach(function (field) {
      if (skipDraftField(field)) return;
      data[field.name] =
        field.type === "checkbox" || field.type === "radio"
          ? field.checked
          : field.value;
    });
    data._step = current;
    try {
      sessionStorage.setItem(draftKey, JSON.stringify(data));
    } catch (ignore) {}
  }

  var birth = document.getElementById("birthdate"),
    age = document.getElementById("age");
  function updateAge() {
    if (!birth || !age) return;
    var d = new Date(birth.value + "T00:00:00"),
      now = new Date();
    if (!birth.value || isNaN(d.getTime()) || d > now) {
      age.value = "";
      return;
    }
    var n = now.getFullYear() - d.getFullYear();
    if (
      now.getMonth() < d.getMonth() ||
      (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())
    )
      n--;
    age.value = n;
  }
  if (birth) {
    birth.addEventListener("input", updateAge);
    updateAge();
  }

  var pass = document.getElementById("password"),
    meter = document.getElementById("password-strength");
  function strength() {
    if (!pass || !meter) return;
    var p = pass.value,
      score = 0;
    if (p.length >= 10) score++;
    if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++;
    if (/[0-9]/.test(p) && /[^A-Za-z0-9]/.test(p)) score++;
    var label = score >= 3 ? "Strong" : score === 2 ? "Medium" : "Weak";
    meter.innerHTML =
      'Password strength: <b class="strength-' +
      label.toLowerCase() +
      '">' +
      label +
      "</b>";
  }
  if (pass) {
    pass.addEventListener("input", strength);
    strength();
  }

  var username = document.getElementById("username"),
    status = document.getElementById("username-status"),
    timer;
  if (username && status) {
    username.addEventListener("input", function () {
      clearTimeout(timer);
      status.textContent = "Checking username…";
      timer = setTimeout(function () {
        fetch(
          "username_check.php?username=" + encodeURIComponent(username.value),
          {
            credentials: "same-origin",
          },
        )
          .then(function (r) {
            return r.json();
          })
          .then(function (x) {
            status.textContent = x.message;
            status.className =
              "field-status " + (x.available ? "available" : "unavailable");
          })
          .catch(function () {
            status.textContent = "Username availability could not be checked.";
            status.className = "field-status unavailable";
          });
      }, 250);
    });
  }

  var steps = Array.prototype.slice.call(
    document.querySelectorAll("[data-register-step]"),
  );
  var progress = Array.prototype.slice.call(
    document.querySelectorAll("[data-progress]"),
  );
  var current = 0;
  if (saved && Number.isInteger(Number(saved._step)))
    current = Math.max(0, Math.min(Number(saved._step), steps.length - 1));

  function input(id) {
    return document.getElementById(id);
  }
  function value(id) {
    var x = input(id);
    return x ? x.value.trim() : "";
  }
  function errorFor(field, message) {
    if (!field) return;
    field.classList.add("has-error");
    field.setAttribute("aria-invalid", "true");
    var holder =
      field.closest(".field") ||
      field.closest("fieldset") ||
      field.parentElement;
    if (!holder) holder = field;
    var old = holder.querySelector('.field-error[data-for="' + field.id + '"]');
    if (!old) {
      old = document.createElement("small");
      old.className = "field-error";
      old.dataset.for = field.id;
      holder.appendChild(old);
    }
    old.textContent = message;
  }
  function clearErrors(scope) {
    (scope || document).querySelectorAll(".field-error").forEach(function (x) {
      x.remove();
    });
    (scope || document).querySelectorAll(".has-error").forEach(function (x) {
      x.classList.remove("has-error");
      x.removeAttribute("aria-invalid");
    });
  }
  function requireName(id, label, optional) {
    var f = input(id),
      v = value(id);
    if (optional && !v) return true;
    if (!v) {
      errorFor(f, label + " is required.");
      return false;
    }
    if (
      v.length < 2 ||
      v.length > 50 ||
      !/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/.test(v) ||
      /(.)\1\1/i.test(v)
    ) {
      errorFor(
        f,
        label +
          " must use Title Case letters only, with single spaces and no three repeated letters.",
      );
      return false;
    }
    return true;
  }
  function requireWords(id, label, min, max) {
    var f = input(id),
      v = value(id);
    if (!v) {
      errorFor(f, label + " is required.");
      return false;
    }
    if (
      v.length < min ||
      v.length > max ||
      !/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/.test(v) ||
      /(.)\1\1/i.test(v)
    ) {
      errorFor(f, label + " must be Title Case letters and spaces only.");
      return false;
    }
    return true;
  }
  function validateStep(n) {
    if (!steps.length) return true;
    var scope = steps[n];
    clearErrors(scope);
    var ok = true,
      f,
      v;
    if (n === 0) {
      f = input("id_number");
      v = value("id_number");
      if (!/^\d{4}-\d{4}$/.test(v)) {
        errorFor(f, "Use the format xxxx-xxxx.");
        ok = false;
      }
      if (!requireName("first_name", "First Name", false)) ok = false;
      if (!requireName("middle_name", "Middle Name", true)) ok = false;
      if (!requireName("family_name", "Family Name", false)) ok = false;
      f = input("birthdate");
      v = value("birthdate");
      if (!v || !age.value || Number(age.value) < 18) {
        errorFor(
          f,
          "You must be at least 18 years old and use a valid birthdate.",
        );
        ok = false;
      }
      f = input("name_extension");
      if (f && f.value === "Other") {
        var ext = input("extension_other");
        if (!/^(I|II|III|IV|IX|X)$/.test(value("extension_other"))) {
          errorFor(ext, "Use I, II, III, IV, IX, or X.");
          ok = false;
        }
      }
    }
    if (n === 1) {
      f = input("purok");
      v = value("purok");
      if (!/^(?=.{3,50}$)[A-Za-z0-9]+(?: [A-Za-z0-9]+)*$/.test(v)) {
        errorFor(f, "Use 3–50 letters, digits, and single spaces.");
        ok = false;
      }
      ["barangay", "city", "province", "country"].forEach(function (id) {
        if (
          !requireWords(
            id,
            id === "city"
              ? "Municipality / City"
              : id.charAt(0).toUpperCase() + id.slice(1),
            2,
            50,
          )
        )
          ok = false;
      });
      f = input("zip_code");
      if (!/^\d{4}$/.test(value("zip_code"))) {
        errorFor(f, "ZIP Code must be exactly 4 digits.");
        ok = false;
      }
    }
    if (n === 2) {
      f = input("username");
      v = value("username");
      var minU = Number(form.dataset.minUser || 6),
        maxU = Number(form.dataset.maxUser || 20);
      if (
        !new RegExp(
          "^[A-Za-z][A-Za-z0-9]{" + (minU - 1) + "," + (maxU - 1) + "}$",
        ).test(v)
      ) {
        errorFor(
          f,
          "Use " +
            minU +
            "–" +
            maxU +
            " letters and digits, starting with a letter.",
        );
        ok = false;
      }
      f = input("email");
      if (!/^\S+@\S+\.\S+$/.test(value("email"))) {
        errorFor(f, "Enter a valid email address.");
        ok = false;
      }
      f = input("password");
      v = f ? f.value : "";
      var minP = Number(form.dataset.minPass || 8);
      if (
        v.length < minP ||
        v.length > 64 ||
        !/[A-Z]/.test(v) ||
        !/[a-z]/.test(v) ||
        !/[0-9]/.test(v)
      ) {
        errorFor(
          f,
          "Use " +
            minP +
            "–64 characters with uppercase, lowercase, and a number.",
        );
        ok = false;
      }
      f = input("confirm");
      if (f.value !== v) {
        errorFor(f, "Passwords do not match.");
        ok = false;
      }
    }
    if (n === 3) {
      var picked = [];
      [1, 2, 3].forEach(function (i) {
        f = input("question_" + i);
        if (!f.value) {
          errorFor(f, "Please choose question " + i + ".");
          ok = false;
        } else if (picked.indexOf(f.value) !== -1) {
          errorFor(f, "Choose three different questions.");
          ok = false;
        } else picked.push(f.value);
        f = input("answer_" + i);
        if (!/^(?=.{2,50}$)[A-Za-z]+(?: [A-Za-z]+)*$/.test(value("answer_" + i))) {
          errorFor(f, "Use 2–50 letters and single spaces.");
          ok = false;
        }
      });
      f = input("captcha");
      if (!/^[A-Za-z0-9]{6}$/.test(value("captcha"))) {
        errorFor(f, "Enter the six-character code shown.");
        ok = false;
      }
      f = document.querySelector('input[name="consent"]');
      if (!f || !f.checked) {
        errorFor(f, "You must agree to the Privacy Notice.");
        ok = false;
      }
    }
    return ok;
  }
  // Authentication questions: a question picked in one list is greyed out in the others.
  (function () {
    var selects = document.querySelectorAll("[data-question-select]");
    if (!selects.length) return;
    function sync() {
      var used = [];
      selects.forEach(function (s) { if (s.value) used.push(s.value); });
      selects.forEach(function (s) {
        Array.prototype.forEach.call(s.options, function (o) {
          o.disabled = !!o.value && o.value !== s.value && used.indexOf(o.value) !== -1;
        });
      });
    }
    selects.forEach(function (s) { s.addEventListener("change", sync); });
    sync();
  })();
  function showStep(n) {
    if (!steps.length) return;
    current = Math.max(0, Math.min(n, steps.length - 1));
    steps.forEach(function (x, i) {
      x.classList.toggle("active", i === current);
    });
    progress.forEach(function (x, i) {
      x.classList.toggle("active", i === current);
      x.classList.toggle("done", i < current);
    });
    saveDraft();
    var first = steps[current].querySelector("input,select");
    if (first) first.focus();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  if (form && steps.length)
    form
      .querySelectorAll(
        "[data-register-step] input,[data-register-step] select",
      )
      .forEach(function (field) {
        field.addEventListener("input", function () {
          if (field.classList.contains("has-error")) validateStep(current);
        });
        field.addEventListener("change", function () {
          if (field.classList.contains("has-error")) validateStep(current);
        });
      });
  document.querySelectorAll("[data-wizard-next]").forEach(function (button) {
    button.addEventListener("click", function () {
      if (validateStep(current)) showStep(current + 1);
    });
  });
  document.querySelectorAll("[data-wizard-back]").forEach(function (button) {
    button.addEventListener("click", function () {
      showStep(current - 1);
    });
  });
  if (form && steps.length)
    form.addEventListener("submit", function (event) {
      for (var i = 0; i < steps.length; i++) {
        if (!validateStep(i)) {
          event.preventDefault();
          showStep(i);
          var notice = document.querySelector('[data-form-message]');
          if (notice) {
            notice.textContent = 'Please correct the highlighted fields in Step ' + (i + 1) + ' before registering.';
            notice.classList.add('is-error');
          }
          return;
        }
      }
    });
  if (steps.length) {
    if (form && form.getAttribute('data-server-error')) {
      var note = document.querySelector('[data-form-message]');
      if (note) note.textContent += ' Your password fields were cleared for safety - please re-enter them in Step 3.';
      var msgText = note ? note.textContent : '';
      current = /^ID Number must/.test(msgText) ? 0 : 2;
    }
    showStep(current);
  }

  var extSel = document.getElementById("name_extension"),
    extOther = document.getElementById("extension_other");
  function syncExt() {
    if (extSel && extOther)
      extOther.style.display = extSel.value === "Other" ? "" : "none";
  }
  if (extSel) {
    extSel.addEventListener("change", syncExt);
    syncExt();
    setTimeout(syncExt, 0);
  }
});

/* GoCycle auth scene: pedalling rider (crank + two-bone legs). Decorative only. */
(function () {
  var bikes = document.querySelectorAll(".gc-ride-scene .rs-bike");
  if (!bikes.length) return;
  var HIP = [50, 21], BB = [58, 52], R = 8, L = 21, NS = "http://www.w3.org/2000/svg";
  function leg(path, angle) {
    var px = BB[0] + R * Math.cos(angle), py = BB[1] + R * Math.sin(angle);
    var dx = px - HIP[0], dy = py - HIP[1], d = Math.min(Math.sqrt(dx * dx + dy * dy), 2 * L - 0.5);
    var h = Math.sqrt(Math.max(L * L - (d / 2) * (d / 2), 0));
    var mx = HIP[0] + dx / 2, my = HIP[1] + dy / 2, len = Math.sqrt(dx * dx + dy * dy) || 1;
    var kx = mx + (dy / len) * h, ky = my - (dx / len) * h; /* knee bends forward */
    path.setAttribute("d", "M" + HIP[0] + " " + HIP[1] + " L" + kx.toFixed(1) + " " + ky.toFixed(1) + " L" + px.toFixed(1) + " " + py.toFixed(1));
  }
  var rigs = [];
  bikes.forEach(function (b, i) {
    rigs.push({
      crank: b.querySelector(".rs-crank"),
      f: b.querySelector(".rs-leg-f"),
      b: b.querySelector(".rs-leg-b"),
      speed: i === 0 ? 2.1 : 1.5
    });
  });
  function pose(t) {
    rigs.forEach(function (r) {
      var a = t * r.speed * Math.PI * 2 / 1.4;
      r.crank.setAttribute("transform", "rotate(" + (a * 180 / Math.PI).toFixed(1) + ")");
      leg(r.f, a);
      leg(r.b, a + Math.PI);
    });
  }
  pose(0);
  if (window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var start = performance.now();
  (function tick(now) {
    if (!document.hidden) pose((now - start) / 1000);
    requestAnimationFrame(tick);
  })(start);
})();
/* OTP resend button: visible countdown (60 s cooldown, or the 30-minute wait after 3 resends).
   The server enforces the real limits; this only shows them. */
(function () {
  document.querySelectorAll("[data-resend-wait]").forEach(function (form) {
    var wait = parseInt(form.getAttribute("data-resend-wait"), 10) || 0;
    var blocked = form.getAttribute("data-resend-mode") === "blocked";
    var btn = form.querySelector(".resend-button");
    var note = form.querySelector(".resend-note");
    if (!btn || wait <= 0) return;
    var end = Date.now() + wait * 1000;
    function mmss(t) {
      return Math.floor(t / 60) + ":" + ("0" + (t % 60)).slice(-2);
    }
    function tick() {
      var left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
      if (left <= 0) {
        btn.disabled = false;
        if (note) note.textContent = "";
        return;
      }
      btn.disabled = true;
      if (note) {
        if (blocked) {
          var m = Math.ceil(left / 60);
          note.textContent = "Too many code requests. Try again in " + m + " minute" + (m === 1 ? "" : "s") + ".";
        } else {
          note.textContent = "You can request a new code in " + mmss(left) + ".";
        }
      }
      setTimeout(tick, 1000);
    }
    tick();
  });
})();


/* Live lockout countdown on the log-in page: ticks every second and keeps the
   Log-in button and the "Register here" link disabled until the lock ends. */
(function () {
  var msg = document.querySelector("[data-lock-left]");
  if (!msg) return;
  var left = parseInt(msg.getAttribute("data-lock-left"), 10);
  if (isNaN(left) || left <= 0) return;
  var end = Date.now() + left * 1000;
  var form = msg.closest("form");
  var submit = form ? form.querySelector(".gc-submit") : null;
  var reg = document.querySelector(".gc-switch a");
  function lock(on) {
    if (submit) submit.disabled = on;
    if (reg) {
      if (on) {
        reg.setAttribute("aria-disabled", "true");
        reg.style.pointerEvents = "none";
        reg.style.opacity = "0.5";
      } else {
        reg.removeAttribute("aria-disabled");
        reg.style.pointerEvents = "";
        reg.style.opacity = "";
      }
    }
  }
  function tick() {
    var t = Math.max(0, Math.ceil((end - Date.now()) / 1000));
    if (t <= 0) {
      msg.textContent = "You can try logging in again now.";
      lock(false);
      return;
    }
    msg.textContent = "Account temporarily locked. Try again in " + Math.floor(t / 60) + "m " + ("0" + (t % 60)).slice(-2) + "s.";
    lock(true);
    setTimeout(tick, 1000);
  }
  tick();
})();
