let running = false;
let controller = null;

document
  .getElementById("startBtn")
  .addEventListener("click", async function () {
    if (running) {
      return;
    }

    running = true;

    const output = document.getElementById("output");

    const status = document.getElementById("statusStat");

    const attempts = document.getElementById("attemptStat");

    const failedStat = document.getElementById("failedStat");

    output.textContent = "root@localhost:~$ ./auth-test\n\n";

    attempts.textContent = "0";

    failedStat.textContent = "0";

    status.textContent = "RUNNING";

    status.style.color = "#00bfff";

    output.textContent += "[*] Initializing test...\n";

    output.textContent +=
      "[*] Target      : " + document.getElementById("target").value + "\n";

    output.textContent +=
      "[*] Identifier  : " + document.getElementById("loginType").value + "\n";

    output.textContent +=
      "[*] Login Value : " + document.getElementById("loginValue").value + "\n";

    output.textContent += "[*] Environment : LOCALHOST\n";

    output.textContent += "---------------------------------------------\n";

    try {
      controller = new AbortController();

      const response = await fetch("lab_test.php?start=1&_=" + Date.now(), {
        method: "GET",
        cache: "no-store",
        signal: controller.signal,
      });

      if (!response.ok) {
        throw new Error("HTTP " + response.status);
      }

      const reader = response.body.getReader();

      const decoder = new TextDecoder("utf-8");

      while (true) {
        const result = await reader.read();

        if (result.done) {
          break;
        }

        const chunk = decoder.decode(result.value, {
          stream: true,
        });

        if (!chunk) {
          continue;
        }

        const lines = chunk.split("\n");

        lines.forEach(function (lineText, index) {
          if (index === lines.length - 1 && lineText === "") {
            return;
          }

          const line = document.createElement("div");

          line.textContent = lineText;

          if (lineText.indexOf(">>> SUCCESS <<<") !== -1) {
            line.className = "success-line";
          } else if (lineText.indexOf("FAILED") !== -1) {
            line.className = "failed-line";
          } else if (lineText.match(/^\[\d+\]/)) {
            line.className = "attempt-line";
          }

          output.appendChild(line);
        });

        const attemptMatches = chunk.match(/\[(\d+)\]/g);

        if (attemptMatches && attemptMatches.length) {
          const last = attemptMatches[attemptMatches.length - 1];

          const number = last.replace(/\D/g, "");

          attempts.textContent = parseInt(number, 10);
        }

        const failedMatches = chunk.match(/FAILED/g);

        if (failedMatches) {
          failedStat.textContent =
            parseInt(failedStat.textContent, 10) + failedMatches.length;
        }

        if (chunk.indexOf("SUCCESS") !== -1) {
          status.textContent = "SUCCESS";

          status.style.color = "#00ff88";
        }

        output.scrollTop = output.scrollHeight;
      }
    } catch (error) {
      if (error.name === "AbortError") {
        output.textContent += "\n[!] PROCESS STOPPED BY USER.\n";

        status.textContent = "STOPPED";

        status.style.color = "#ff3355";
      } else {
        output.textContent += "\n[ERROR] " + error.message + "\n";

        status.textContent = "ERROR";

        status.style.color = "#ff3355";
      }
    }

    running = false;

    controller = null;

    if (status.textContent === "RUNNING") {
      status.textContent = "DONE";

      status.style.color = "#00ff88";
    }
  });

document.getElementById("stopBtn").addEventListener("click", function () {
  if (controller && running) {
    controller.abort();
  }

  running = false;
});

(function () {
  const characters =
    "01ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz<>[]{}#$%&@";

  function randomCode(length) {
    let result = "";

    for (let i = 0; i < length; i++) {
      result += characters.charAt(
        Math.floor(Math.random() * characters.length),
      );

      if (i % 3 === 2) {
        result += "\n";
      }
    }

    return result;
  }

  function createColumns(containerId, count) {
    const container = document.getElementById(containerId);

    if (!container) {
      return;
    }

    for (let i = 0; i < count; i++) {
      const column = document.createElement("div");

      column.className = "falling-column";

      column.textContent = randomCode(95 + Math.floor(Math.random() * 55));

      column.style.left = 5 + Math.random() * 88 + "%";

      column.style.animationDuration = 7 + Math.random() * 10 + "s";

      column.style.animationDelay = -Math.random() * 12 + "s";

      column.style.opacity = (0.25 + Math.random() * 0.65).toFixed(2);

      column.style.fontSize = 9 + Math.random() * 5 + "px";

      container.appendChild(column);
    }
  }

  createColumns("fallingLeft", 7);

  createColumns("fallingRight", 7);
})();
