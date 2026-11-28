(() => {
  "use strict";

  const page = document.querySelector(".admin-monitor-page");
  if (!page) return;
  const endpoint = page.dataset.endpoint;
  const form = document.getElementById("event-filters");
  const status = document.getElementById("monitor-refresh-status");
  const metricElements = document.querySelectorAll("[data-metric]");
  let busy = false;
  let lastInteraction = Date.now();
  let lastReportedInteraction = 0;

  ["pointerdown", "keydown", "input", "touchstart"].forEach((eventName) => {
    document.addEventListener(
      eventName,
      () => {
        lastInteraction = Date.now();
      },
      { passive: true },
    );
  });

  const makeCell = (row, value, className = "") => {
    const cell = document.createElement("td");
    if (className) cell.className = className;
    cell.textContent =
      value === null || value === undefined || value === ""
        ? "—"
        : String(value);
    row.appendChild(cell);
    return cell;
  };

  const replaceRows = (body, rows, emptyText, render) => {
    body.replaceChildren();
    if (!rows.length) {
      const row = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = body.closest("table").querySelectorAll("thead th").length;
      cell.textContent = emptyText;
      row.appendChild(cell);
      body.appendChild(row);
      return;
    }
    rows.forEach((item) => {
      const row = document.createElement("tr");
      render(row, item);
      body.appendChild(row);
    });
  };

  const drawChart = (chart) => {
    const canvas = document.getElementById("hourly-failures-chart");
    const summary = document.getElementById("hourly-chart-summary");
    if (!canvas || !canvas.getContext) return;
    const context = canvas.getContext("2d");
    const bounds = canvas.getBoundingClientRect();
    const width = Math.max(320, Math.floor(bounds.width || canvas.width));
    const height = Math.max(200, Math.floor(bounds.height || 220));
    const ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);

    const values = Array.isArray(chart.values) ? chart.values : [];
    const labels = Array.isArray(chart.labels) ? chart.labels : [];
    const maxValue = Math.max(4, Math.ceil(Math.max(0, ...values) / 4) * 4);
    const left = 38;
    const right = 10;
    const top = 15;
    const bottom = 38;
    const chartWidth = width - left - right;
    const chartHeight = height - top - bottom;
    const slot = chartWidth / Math.max(1, values.length);

    context.font = "11px Arial, sans-serif";
    context.textAlign = "right";
    context.textBaseline = "middle";
    for (let tick = 0; tick <= 4; tick += 1) {
      const y = top + (chartHeight * tick) / 4;
      const value = Math.round((maxValue * (4 - tick)) / 4);
      context.strokeStyle = "#e8eee9";
      context.lineWidth = 1;
      context.beginPath();
      context.moveTo(left, y);
      context.lineTo(width - right, y);
      context.stroke();
      context.fillStyle = "#7c8980";
      context.fillText(String(value), left - 8, y);
    }

    values.forEach((value, index) => {
      const barHeight = chartHeight * (Number(value) / maxValue);
      const x = left + index * slot + slot * 0.19;
      const barWidth = Math.max(2, slot * 0.62);
      context.fillStyle = "#258060";
      context.fillRect(x, top + chartHeight - barHeight, barWidth, barHeight);
      if (index % 4 === 0 || index === values.length - 1) {
        context.fillStyle = "#718077";
        context.textAlign = "center";
        context.textBaseline = "top";
        context.fillText(
          labels[index] || "",
          x + barWidth / 2,
          height - bottom + 9,
        );
      }
    });
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
    if (summary)
      summary.textContent = `${total} failed log-in event${total === 1 ? "" : "s"} shown across the last 24 hours.`;
  };

  const drawData = (data) => {
    const summary = data.summary || {};
    metricElements.forEach((element) => {
      const key = element.dataset.metric;
      element.textContent = Number.isFinite(Number(summary[key]))
        ? String(summary[key])
        : "0";
    });
    drawChart(data.hourlyFailures || { labels: [], values: [] });

    replaceRows(
      document.getElementById("top-ips-body"),
      data.topIps || [],
      "No failed log-ins recorded in the last 24 hours.",
      (row, ip) => {
        makeCell(row, ip.ip);
        makeCell(row, ip.failures);
        const statusCell = makeCell(
          row,
          ip.blocked ? "Blocked" : "Not blocked",
          ip.blocked ? "monitor-blocked" : "monitor-clear",
        );
        statusCell.setAttribute(
          "aria-label",
          ip.blocked
            ? "IP address is currently blocked"
            : "IP address is not currently blocked",
        );
      },
    );

    replaceRows(
      document.getElementById("security-flags-body"),
      data.flags || [],
      "No monitoring flags recorded yet. Run the monitoring script to scan recent events.",
      (row, flag) => {
        makeCell(row, flag.created_at);
        makeCell(
          row,
          flag.severity,
          flag.severity === "HIGH"
            ? "monitor-severity-high"
            : "monitor-severity-medium",
        );
        makeCell(row, flag.pattern_name);
        makeCell(row, flag.target);
        makeCell(row, flag.details);
      },
    );

    replaceRows(
      document.getElementById("recent-events-body"),
      data.events || [],
      "No events match these filters.",
      (row, event) => {
        makeCell(row, event.created_at);
        makeCell(row, event.event_type);
        makeCell(row, event.username);
        makeCell(row, event.ip_address);
        makeCell(row, event.user_agent);
        makeCell(row, event.details);
      },
    );
    const generated = data.generatedAt
      ? new Date(data.generatedAt)
      : new Date();
    status.textContent = `Updated ${generated.toLocaleTimeString()} · refreshes every 60 seconds`;
  };

  const refresh = async () => {
    if (busy) return;
    busy = true;
    const query = new URLSearchParams(new FormData(form));
    const suffix = query.toString();
    const headers = { Accept: "application/json" };
    if (lastInteraction > lastReportedInteraction) {
      headers["X-GC-User-Activity"] = "1";
      lastReportedInteraction = lastInteraction;
    }
    try {
      const response = await fetch(`${endpoint}${suffix ? `?${suffix}` : ""}`, {
        method: "GET",
        credentials: "same-origin",
        headers,
        cache: "no-store",
      });
      const data = await response.json();
      if (response.status === 401) {
        window.location.href = "login.php?expired=1";
        return;
      }
      if (!response.ok)
        throw new Error(data.error || "Could not load security data.");
      drawData(data);
    } catch (error) {
      status.textContent =
        error.message || "Security data is temporarily unavailable.";
    } finally {
      busy = false;
    }
  };

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    refresh();
  });
  form.addEventListener("reset", () => window.setTimeout(refresh, 0));
  window.addEventListener("resize", () => refresh());
  refresh();
  window.setInterval(refresh, 60000);
})();
