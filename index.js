/*
 * The dashboard.
 *
 * Everything comes from api.php. Nothing here decides what may be seen: the
 * server sends masked names to a signed-out caller and never sends the real
 * ones, so there is nothing in this file to bypass.
 */

const API = "./api.php";

/**
 * The colours a chart draws its series in, in order.
 *
 * Up here rather than inside drawLineChart because the legend has to agree with
 * it. It used to be a local in the drawing code and the legend was three
 * hand-written spans with no colour on them at all, so every swatch came out
 * the inherited grey and matched nothing on the chart.
 */
const SERIES_COLORS = ["#73b7ff", "#ffb86b", "#75d58a", "#d5a6ff", "#ff7f9f"];
const seriesColor = i => SERIES_COLORS[i % SERIES_COLORS.length];

/** How often the page refreshes itself, awake and in live mode. */
const IDLE_POLL_MS = 30000;
const LIVE_POLL_MS = 10000;

const state = {
  signedIn: false,
  csrf: null,
  range: 86400,
  live: false,
  timer: null,
  historyPathId: null,
  deletable: [],
  busy: false,
};

const $ = id => document.getElementById(id);

/* ----------------------------------------------------------------- format -- */

function humanBytes(value, digits) {
  if (value == null || !Number.isFinite(Number(value))) return "—";
  let n = Number(value);
  const sign = n < 0 ? "-" : "";
  n = Math.abs(n);
  const units = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"];
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  const places = digits ?? (i === 0 ? 0 : n >= 100 ? 0 : n >= 10 ? 1 : 2);
  return `${sign}${n.toFixed(places)} ${units[i]}`;
}

function humanCount(value) {
  if (value == null || !Number.isFinite(Number(value))) return "—";
  return new Intl.NumberFormat().format(Number(value));
}

function humanDuration(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) return "—";
  if (seconds < 90) return `${Math.round(seconds)}s`;
  if (seconds < 5400) return `${Math.round(seconds / 60)} min`;
  if (seconds < 172800) return `${(seconds / 3600).toFixed(1)} h`;
  return `${(seconds / 86400).toFixed(1)} days`;
}

function humanTime(epoch) {
  if (!epoch) return "—";
  return new Date(epoch * 1000).toLocaleString();
}

function ago(epoch) {
  if (!epoch) return "";
  return `${humanDuration(Date.now() / 1000 - epoch)} ago`;
}

/* -------------------------------------------------------------------- api -- */

async function api(action, params = {}) {
  const query = new URLSearchParams({ action, ...params });
  const response = await fetch(`${API}?${query}`, {
    cache: "no-store",
    credentials: "same-origin",
  });
  const body = await response.json().catch(() => ({ error: "Bad response" }));
  if (!response.ok) throw new Error(body.error || `HTTP ${response.status}`);
  return body;
}

async function post(action, fields = {}) {
  const body = new URLSearchParams({ action, csrf: state.csrf ?? "", ...fields });
  const response = await fetch(API, {
    method: "POST",
    body,
    credentials: "same-origin",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
  });
  const parsed = await response.json().catch(() => ({ error: "Bad response" }));
  if (!response.ok) throw new Error(parsed.error || `HTTP ${response.status}`);
  return parsed;
}

function notice(message, ok = false) {
  const el = $("notice");
  el.textContent = message ?? "";
  el.className = ok ? "ok" : "";
}

/* ------------------------------------------------------------------ cards -- */

function setCard(id, value, sub, level = "") {
  const card = $(id);
  card.querySelector(".value").textContent = value;
  card.querySelector(".sub").textContent = sub ?? "";
  card.className = `card ${level}`.trim();
}

function renderCards(overview, vitalRows) {
  const v = overview.vitals;
  if (!v) {
    setCard("cardDisk", "—", "nothing collected yet");
    return;
  }

  const freePercent = v.disk_total_bytes
    ? (v.disk_free_bytes / v.disk_total_bytes) * 100
    : null;

  setCard(
    "cardDisk",
    humanBytes(v.disk_free_bytes),
    freePercent === null ? "" : `${freePercent.toFixed(1)}% free of ${humanBytes(v.disk_total_bytes)}`,
    freePercent === null ? "" : freePercent < 5 ? "crit" : freePercent < 12 ? "warn" : ""
  );

  /*
   * How long until it is full, from the slope of what has actually happened.
   *
   * Worked out in the browser from the same rows the chart is drawn from, so
   * the number and the line always agree - and so changing the range changes
   * the horizon, which is the honest behaviour: an hour of samples cannot tell
   * you anything about next week.
   */
  const trend = projectFull(vitalRows);
  if (trend === null) {
    setCard("cardTrend", "steady", "free space is not falling");
  } else {
    setCard(
      "cardTrend",
      humanDuration(trend.seconds),
      `losing ${humanBytes(trend.perHour)}/h`,
      trend.seconds < 86400 ? "crit" : trend.seconds < 604800 ? "warn" : ""
    );
  }

  const inodePercent = v.inodes_total ? (v.inodes_free / v.inodes_total) * 100 : null;
  setCard(
    "cardInodes",
    v.inodes_free == null ? "n/a" : humanCount(v.inodes_free),
    inodePercent === null ? "not reported" : `${inodePercent.toFixed(1)}% free`,
    inodePercent === null ? "" : inodePercent < 5 ? "crit" : inodePercent < 12 ? "warn" : ""
  );

  setCard(
    "cardCpu",
    v.cpu_percent == null ? "n/a" : `${Number(v.cpu_percent).toFixed(0)}%`,
    v.cpu_percent == null ? "no /proc/stat here" : `since the previous reading`,
    v.cpu_percent > 90 ? "crit" : v.cpu_percent > 70 ? "warn" : ""
  );

  setCard(
    "cardMem",
    v.mem_used_percent == null ? "n/a" : `${Number(v.mem_used_percent).toFixed(0)}%`,
    v.mem_total_bytes ? `${humanBytes(v.mem_available_bytes)} available` : "no /proc/meminfo here",
    v.mem_used_percent > 92 ? "crit" : v.mem_used_percent > 80 ? "warn" : ""
  );

  setCard(
    "cardLoad",
    v.load1 == null ? "n/a" : Number(v.load1).toFixed(2),
    v.load1 == null ? "" : `${Number(v.load5).toFixed(2)} / ${Number(v.load15).toFixed(2)} over 5 and 15 min`
  );
}

/** Seconds until free space reaches zero at the recent rate, or null. */
function projectFull(rows) {
  const points = rows
    .filter(r => r.disk_free_bytes != null)
    .map(r => [Number(r.taken_at), Number(r.disk_free_bytes)]);
  if (points.length < 4) return null;

  const n = points.length;
  const x0 = points[0][0];
  let sx = 0, sy = 0, sxy = 0, sxx = 0;
  for (const [x, y] of points) {
    const t = x - x0;
    sx += t; sy += y; sxy += t * y; sxx += t * t;
  }
  const denominator = n * sxx - sx * sx;
  if (Math.abs(denominator) < 1e-9) return null;

  const slope = (n * sxy - sx * sy) / denominator;
  if (slope >= 0) return null;

  const free = points[n - 1][1];
  return { seconds: free / -slope, perHour: -slope * 3600 };
}

/* ----------------------------------------------------------------- charts -- */

/**
 * Writes the legend for a chart, from the series it was actually given.
 *
 * Kept next to the canvas and rebuilt on every draw, so a series that is added,
 * removed or reordered cannot leave the key behind saying something else.
 */
function drawLegend(canvas, series) {
  let el = canvas.nextElementSibling;
  if (!el || !el.classList.contains("legend")) {
    el = document.createElement("div");
    el.className = "legend";
    canvas.after(el);
  }

  el.replaceChildren(...series.map((s, i) => {
    const span = document.createElement("span");
    span.textContent = s.label;
    span.style.setProperty("--dot", seriesColor(i));
    return span;
  }));
}

/**
 * A line chart of one or more series against time.
 *
 * `rows` carry their own x in `xKey` (a unix timestamp) rather than being
 * evenly spaced by index. That matters here: readings are a minute apart until
 * somebody turns on live updates and then they are ten seconds apart, and
 * spacing them evenly would draw the busy period as though it took hours.
 */
function drawLineChart(canvas, rows, series, options = {}) {
  drawLegend(canvas, series);

  const dpr = window.devicePixelRatio || 1;
  const cssW = Math.max(300, canvas.clientWidth);
  const cssH = canvas.clientHeight || 260;
  canvas.width = Math.round(cssW * dpr);
  canvas.height = Math.round(cssH * dpr);

  const ctx = canvas.getContext("2d");
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, cssW, cssH);
  ctx.font = "12px system-ui";
  ctx.fillStyle = "#9a9a9a";
  ctx.strokeStyle = "#333";
  ctx.lineWidth = 1;

  const xKey = options.xKey || "taken_at";
  const format = options.format || (v => String(Math.round(v)));
  const pad = { left: 72, right: 14, top: 14, bottom: 34 };
  const w = cssW - pad.left - pad.right;
  const h = cssH - pad.top - pad.bottom;

  const usable = rows.filter(r => series.some(s => Number.isFinite(Number(r[s.key]))));
  if (!usable.length) {
    ctx.fillText(options.empty || "Nothing collected for this range yet", pad.left, pad.top + 20);
    return;
  }

  const values = [];
  for (const s of series) {
    for (const r of usable) {
      const v = Number(r[s.key]);
      if (Number.isFinite(v)) values.push(v);
    }
  }

  let min = Math.min(...values);
  let max = Math.max(...values);

  /*
   * The axis is not pinned to zero.
   *
   * It used to be, and on a disk that is 65% full and losing a gigabyte a day
   * that draws a flat line across the top of the chart - the one chart whose
   * whole purpose is to show the slope. Instead the range is padded a little
   * either side of the data, which keeps the shape readable, and `baseline`
   * lets a caller that genuinely wants zero (percentages) ask for it.
   */
  if (options.zeroBased) min = Math.min(0, min);
  if (options.max != null) max = Math.max(max, options.max);
  if (max === min) { max = min + 1; }
  const padding = (max - min) * 0.08;
  min -= padding;
  max += padding;
  if (options.zeroBased) min = Math.max(0, min);

  const t0 = Number(usable[0][xKey]);
  const t1 = Number(usable[usable.length - 1][xKey]);
  const span = t1 - t0 || 1;

  const x = t => pad.left + ((Number(t) - t0) / span) * w;
  const y = v => pad.top + h - ((v - min) / (max - min)) * h;

  for (let i = 0; i <= 4; i++) {
    const yy = pad.top + (i / 4) * h;
    ctx.beginPath();
    ctx.moveTo(pad.left, yy);
    ctx.lineTo(pad.left + w, yy);
    ctx.stroke();
    ctx.fillText(format(max - (i / 4) * (max - min)), 4, yy + 4);
  }

  const marks = [t0, t0 + span / 2, t1];
  marks.forEach((t, i) => {
    const label = new Date(t * 1000).toLocaleString([], {
      month: "short", day: "numeric", hour: "2-digit", minute: "2-digit",
    });
    const at = x(t);
    ctx.textAlign = i === 0 ? "left" : i === marks.length - 1 ? "right" : "center";
    ctx.fillText(label, Math.min(Math.max(pad.left, at), pad.left + w), cssH - 10);
  });
  ctx.textAlign = "left";

  series.forEach((s, si) => {
    ctx.strokeStyle = seriesColor(si);
    ctx.fillStyle = seriesColor(si);
    ctx.lineWidth = 2;
    ctx.beginPath();

    let started = false;
    for (const r of usable) {
      const v = Number(r[s.key]);
      if (!Number.isFinite(v)) { started = false; continue; }
      const xx = x(r[xKey]);
      const yy = y(v);
      if (started) ctx.lineTo(xx, yy); else ctx.moveTo(xx, yy);
      started = true;
    }
    ctx.stroke();

    if (usable.length <= 80) {
      for (const r of usable) {
        const v = Number(r[s.key]);
        if (!Number.isFinite(v)) continue;
        ctx.beginPath();
        ctx.arc(x(r[xKey]), y(v), 2.2, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  });
}

/* ------------------------------------------------------------------ table -- */

function renderTable(target, rows, columns, onRowClick) {
  const el = $(target);
  if (!rows || !rows.length) {
    el.innerHTML = '<p class="muted">Nothing to show.</p>';
    return;
  }

  const table = document.createElement("table");
  const head = table.createTHead().insertRow();
  for (const col of columns) {
    const th = document.createElement("th");
    th.textContent = col.label;
    if (col.className) th.className = col.className;
    head.appendChild(th);
  }

  const body = table.createTBody();
  for (const row of rows) {
    const tr = body.insertRow();
    if (onRowClick) {
      tr.className = "clickable";
      tr.addEventListener("click", event => {
        if (event.target.closest("button")) return;
        onRowClick(row);
      });
    }
    for (const col of columns) {
      const td = tr.insertCell();
      if (col.className) td.className = col.className;
      const content = col.render ? col.render(row) : row[col.key] ?? "";
      if (content instanceof Node) td.appendChild(content);
      else td.textContent = content;
    }
  }

  el.replaceChildren(table);
}

/* ------------------------------------------------------------------ views -- */

async function refreshAll() {
  if (state.busy) return;
  state.busy = true;
  try {
    const to = Math.floor(Date.now() / 1000);
    const from = to - state.range;

    const [overview, vitals, scans] = await Promise.all([
      api("overview"),
      api("vitals", { from, to, limit: 900 }),
      api("scans", { from, to }),
    ]);

    state.signedIn = overview.signed_in;
    state.csrf = overview.csrf;
    state.deletable = overview.deletable || [];
    applySignedInChrome();

    renderCards(overview, vitals.rows);
    renderVitalCharts(vitals.rows);
    renderCategoryChart(scans.rows);
    $("lastSeen").textContent = overview.vitals
      ? `updated ${ago(overview.vitals.taken_at)}`
      : "";

    await Promise.all([refreshOffenders(), refreshAlerts(), refreshActionLog()]);
    if (state.historyPathId) await showHistory(state.historyPathId, true);
    notice("");
  } catch (error) {
    notice(String(error.message || error));
  } finally {
    state.busy = false;
  }
}

function renderVitalCharts(rows) {
  drawLineChart($("diskChart"), rows,
    [{ key: "disk_free_bytes", label: "Free" }],
    { format: v => humanBytes(v, 1) });

  drawLineChart($("cpuChart"), rows,
    [{ key: "cpu_percent", label: "CPU %" }, { key: "mem_used_percent", label: "Memory %" }],
    { format: v => `${v.toFixed(0)}%`, zeroBased: true, max: 100 });

  drawLineChart($("loadChart"), rows,
    [{ key: "load1", label: "1 min" }, { key: "load5", label: "5 min" }, { key: "load15", label: "15 min" }],
    { format: v => v.toFixed(2), zeroBased: true });

  drawLineChart($("inodeChart"), rows,
    [{ key: "inodes_free", label: "Free" }],
    { format: v => humanCount(Math.round(v)) });
}

function renderCategoryChart(rows) {
  drawLineChart($("categoryChart"), rows,
    [
      { key: "trash_bytes", label: "Trash" },
      { key: "logs_bytes", label: "Logs" },
      { key: "temp_bytes", label: "Temp" },
    ],
    {
      xKey: "started_at",
      format: v => humanBytes(v, 1),
      zeroBased: true,
      empty: "No scan has finished in this range yet",
    });
}

async function refreshOffenders() {
  const params = {
    limit: 30,
    sort: $("offSort").value,
  };
  if ($("offCategory").value) params.category = $("offCategory").value;
  if ($("offKind").value) params.kind = $("offKind").value;

  const data = await api("offenders", params);
  $("maskNote").hidden = state.signedIn || !data.rows.length;

  renderTable("offenders", data.rows, [
    { label: "Path", className: "name", render: row => row.display },
    { label: "Kind", render: row => (row.kind === "dir" ? "directory" : "file") },
    { label: "Category", render: row => row.category ?? "—" },
    { label: "Files", className: "size", render: row => (row.kind === "dir" ? humanCount(row.files) : "") },
    { label: "Size", className: "size", render: row => humanBytes(row.bytes) },
    {
      label: "Change",
      className: "size",
      render: row => {
        const span = document.createElement("span");
        const growth = Number(row.growth || 0);
        span.textContent = growth === 0 ? "—" : humanBytes(growth);
        span.className = growth > 0 ? "grew" : growth < 0 ? "shrank" : "";
        return span;
      },
    },
    { label: "", className: "size", render: row => deleteButton(row) },
  ], row => showHistory(row.path_id));
}

/**
 * The delete button for one row, or nothing.
 *
 * Only rendered when the server has said this session may delete and the row is
 * in a category the config allows. The server checks all of it again anyway -
 * this is about not offering something that will be refused.
 */
function deleteButton(row) {
  if (!state.signedIn || row.kind !== "file" || !state.deletable.includes(row.category)) {
    return "";
  }

  const button = document.createElement("button");
  button.className = "danger";
  button.textContent = "Delete";
  button.addEventListener("click", async () => {
    if (!confirm(`Delete this file permanently?\n\n${row.display}\n${humanBytes(row.bytes)}`)) return;
    button.disabled = true;
    try {
      const result = await post("delete-file", { path: row.path });
      notice(`Deleted ${humanBytes(result.bytes_freed)}.`, true);
      await refreshAll();
    } catch (error) {
      notice(String(error.message || error));
      button.disabled = false;
    }
  });
  return button;
}

function renderEmptyButtons() {
  const holder = $("emptyButtons");
  if (!state.signedIn) {
    holder.replaceChildren();
    return;
  }

  holder.replaceChildren(...state.deletable.map(category => {
    const button = document.createElement("button");
    button.className = "danger";
    button.textContent = `Empty ${category}`;
    button.addEventListener("click", async () => {
      if (!confirm(`Delete every file counted as ${category}?\n\nThis cannot be undone.`)) return;
      button.disabled = true;
      try {
        const result = await post("empty", { category });
        notice(
          `Emptied ${category}: ${humanCount(result.files_removed)} files, ${humanBytes(result.bytes_freed)} freed`
          + (result.failed ? `, ${result.failed} could not be removed.` : "."),
          true
        );
        await refreshAll();
      } catch (error) {
        notice(String(error.message || error));
      } finally {
        button.disabled = false;
      }
    });
    return button;
  }));
}

async function showHistory(pathId, quiet = false) {
  if (!pathId) return;
  state.historyPathId = pathId;

  const to = Math.floor(Date.now() / 1000);
  const data = await api("history", { path_id: pathId, from: to - state.range, to });

  $("historyPanel").hidden = false;
  $("historyTitle").textContent = data.path.display;
  drawLineChart($("historyChart"), data.rows,
    [{ key: "bytes", label: "Size" }, { key: "files", label: "Files" }],
    { xKey: "started_at", format: v => humanBytes(v, 1), zeroBased: true,
      empty: "This path has only been seen in one scan so far" });

  if (!quiet) $("historyPanel").scrollIntoView({ behavior: "smooth", block: "nearest" });
}

async function refreshAlerts() {
  const data = await api("alerts", { limit: 40 });

  const recent = data.rows.filter(r => Date.now() / 1000 - r.raised_at < 86400);
  const banner = $("alertBanner");
  if (recent.length) {
    banner.hidden = false;
    banner.className = recent.some(r => r.severity === "critical") ? "crit" : "";
    const list = recent.slice(0, 4).map(r => `<li>${escapeHtml(r.subject)}</li>`).join("");
    banner.innerHTML =
      `<strong>${recent.length} alert${recent.length === 1 ? "" : "s"} in the last day</strong><ul>${list}</ul>`;
  } else {
    banner.hidden = true;
  }

  renderTable("alerts", data.rows, [
    { label: "When", render: row => humanTime(row.raised_at) },
    { label: "Severity", render: row => row.severity },
    { label: "What", className: "name", render: row => row.subject },
    { label: "Emailed", render: row => (row.emailed_at ? "yes" : "no") },
  ], row => (row.path_id ? showHistory(row.path_id) : undefined));
}

async function refreshActionLog() {
  const panel = $("actionPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  const data = await api("actions");
  renderTable("actionLog", data.rows, [
    { label: "When", render: row => humanTime(row.at) },
    { label: "Action", render: row => row.action },
    { label: "Target", className: "name", render: row => row.target ?? "" },
    { label: "Outcome", render: row => row.outcome + (row.detail ? ` — ${row.detail}` : "") },
    { label: "Freed", className: "size", render: row => humanBytes(row.bytes_freed) },
  ]);
}

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = String(text ?? "");
  return div.innerHTML;
}

/* ------------------------------------------------------------- sign in/out -- */

function applySignedInChrome() {
  $("signInOut").textContent = state.signedIn ? "Sign out" : "Sign in";
  renderEmptyButtons();
}

async function doSignIn(password) {
  const body = new URLSearchParams({ action: "signin", password });
  const response = await fetch(API, {
    method: "POST",
    body,
    credentials: "same-origin",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
  });
  const parsed = await response.json().catch(() => ({}));
  if (!response.ok || !parsed.signed_in) {
    throw new Error(parsed.error || "Incorrect password.");
  }
  state.signedIn = true;
  state.csrf = parsed.csrf;
}

/* ----------------------------------------------------------------- polling -- */

function schedule() {
  if (state.timer) clearInterval(state.timer);
  state.timer = setInterval(tick, state.live ? LIVE_POLL_MS : IDLE_POLL_MS);
}

async function tick() {
  // A hidden tab is a tab nobody is reading. Polling it wastes the server's
  // time and, in live mode, writes a reading a minute nobody asked for.
  if (document.hidden) return;

  if (state.live) {
    /*
     * Live mode takes a *new* reading rather than re-reading the last one.
     *
     * The collector runs once a minute at best, so without this the ten-second
     * mode would show the same row six times - and the moment you want
     * ten-second resolution is the moment something is going wrong, which is
     * the worst possible time to be looking at minute-old numbers.
     */
    try {
      await api("sample");
    } catch {
      // A failed sample is not worth interrupting the page for; the refresh
      // below will surface anything that actually matters.
    }
  }

  await refreshAll();
}

/* -------------------------------------------------------------------- wire -- */

$("range").addEventListener("change", () => {
  state.range = Number($("range").value);
  refreshAll();
});

$("live").addEventListener("click", () => {
  state.live = !state.live;
  $("live").setAttribute("aria-pressed", String(state.live));
  schedule();
  if (state.live) tick();
});

for (const id of ["offCategory", "offKind", "offSort"]) {
  $(id).addEventListener("change", () => refreshOffenders().catch(e => notice(String(e.message || e))));
}

$("historyClose").addEventListener("click", () => {
  state.historyPathId = null;
  $("historyPanel").hidden = true;
});

$("signInOut").addEventListener("click", async () => {
  if (state.signedIn) {
    await post("signout");
    state.signedIn = false;
    state.csrf = null;
    state.historyPathId = null;
    $("historyPanel").hidden = true;
    await refreshAll();
    return;
  }
  $("signInError").hidden = true;
  $("password").value = "";
  $("signInDialog").showModal();
  $("password").focus();
});

$("signInCancel").addEventListener("click", () => $("signInDialog").close());

$("signInForm").addEventListener("submit", async event => {
  event.preventDefault();
  const error = $("signInError");
  error.hidden = true;
  try {
    await doSignIn($("password").value);
    $("signInDialog").close();
    await refreshAll();
  } catch (failure) {
    error.textContent = String(failure.message || failure);
    error.hidden = false;
  }
});

// Redrawing on resize is a canvas thing, not a data thing: the old code
// re-fetched every JSON file on every resize event.
let resizeTimer = null;
window.addEventListener("resize", () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => refreshAll(), 200);
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) refreshAll();
});

refreshAll();
schedule();
