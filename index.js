const DATA_ROOT = "./data/";

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
let indexData = null;
let allRows = [];
let latestSnapshot = null;

const $ = id => document.getElementById(id);

function humanBytes(value) {
  if (value == null || !Number.isFinite(Number(value))) return "—";
  let n = Number(value);
  const units = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"];
  let i = 0;
  while (Math.abs(n) >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n.toFixed(i === 0 ? 0 : n >= 10 ? 1 : 2)} ${units[i]}`;
}

function humanCount(value) {
  if (value == null || !Number.isFinite(Number(value))) return "—";
  return new Intl.NumberFormat().format(Number(value));
}

async function loadJson(path) {
  const response = await fetch(DATA_ROOT + path, {cache: "no-store"});
  if (!response.ok) throw new Error(`${response.status} loading ${path}`);
  return response.json();
}

function option(select, value, label = value) {
  const o = document.createElement("option");
  o.value = value;
  o.textContent = label;
  select.appendChild(o);
}

function resetSelect(select, firstLabel = "All") {
  select.innerHTML = "";
  option(select, "", firstLabel);
}

function populateSelectors() {
  const years = [...new Set(indexData.years || [])].sort();
  for (const y of years) option($("year"), y, y);
  populateMonths();
  populateDays();
}

function populateMonths() {
  const y = $("year").value;
  const current = $("month").value;
  resetSelect($("month"));
  const months = (indexData.months || [])
    .filter(v => !y || v.startsWith(y + "/"))
    .map(v => v.split("/")[1]);
  for (const m of [...new Set(months)].sort()) option($("month"), m, m);
  if ([...$("month").options].some(o => o.value === current)) $("month").value = current;
}

function populateDays() {
  const y = $("year").value;
  const m = $("month").value;
  const current = $("day").value;
  resetSelect($("day"));
  const days = (indexData.days || [])
    .filter(v => {
      const [yy, mm] = v.split("/");
      return (!y || yy === y) && (!m || mm === m);
    })
    .map(v => v.split("/")[2]);
  for (const d of [...new Set(days)].sort()) option($("day"), d, d);
  if ([...$("day").options].some(o => o.value === current)) $("day").value = current;
}

function selectedPaths() {
  const y = $("year").value;
  const m = $("month").value;
  const d = $("day").value;

  if (y && m && d) return [`${y}/${m}/${d}.json`];
  if (y && m) return [`${y}/${m}.json`];
  if (y) return [`${y}.json`];

  return (indexData.years || []).map(year => `${year}.json`);
}

async function loadSelectedPeriod() {
  const paths = selectedPaths();
  const docs = await Promise.all(paths.map(loadJson));
  let rows = docs.flatMap(doc => doc.snapshots || []);
  rows.sort((a, b) => (a.epoch || 0) - (b.epoch || 0));
  allRows = rows;
  await renderFiltered();
}

function rangeEpoch(id, endOfRange = false) {
  const value = $(id).value;
  if (!value) return endOfRange ? Infinity : -Infinity;
  const ms = new Date(value).getTime();
  return Number.isFinite(ms) ? Math.floor(ms / 1000) : (endOfRange ? Infinity : -Infinity);
}

async function renderFiltered() {
  const from = rangeEpoch("from");
  const to = rangeEpoch("to", true);
  const rows = allRows.filter(r => (r.epoch || 0) >= from && (r.epoch || 0) <= to);

  $("countValue").textContent = humanCount(rows.length);

  if (!rows.length) {
    updateCards(null);
    drawLineChart($("categoryChart"), [], []);
    drawLineChart($("diskChart"), [], []);
    drawLineChart($("inodeChart"), [], []);
    return;
  }

  const latestRow = rows[rows.length - 1];
  updateCards(latestRow);

  drawLineChart(
    $("categoryChart"),
    rows,
    [
      { key: "trash_bytes", label: "Trash", format: humanBytes },
      { key: "logs_bytes", label: "Logs", format: humanBytes },
      { key: "temp_bytes", label: "Temp", format: humanBytes },
    ]
  );

  drawLineChart(
    $("diskChart"),
    rows,
    [{ key: "filesystem_available_bytes", label: "Available", format: humanBytes }]
  );

  drawLineChart(
    $("inodeChart"),
    rows,
    [{ key: "inodes_available", label: "Available", format: humanCount }]
  );

  if (latestRow.path) {
    latestSnapshot = await loadJson(latestRow.path);
    renderTables(latestSnapshot);
  }
}

function updateCards(row) {
  $("trashValue").textContent = row ? humanBytes(row.trash_bytes) : "—";
  $("logsValue").textContent = row ? humanBytes(row.logs_bytes) : "—";
  $("tempValue").textContent = row ? humanBytes(row.temp_bytes) : "—";
  $("diskValue").textContent = row ? humanBytes(row.filesystem_available_bytes) : "—";

  if (row && row.inodes_available != null) {
    const pct = row.inodes_used_percent == null ? "" : ` (${row.inodes_used_percent}% used)`;
    $("inodeValue").textContent = humanCount(row.inodes_available) + pct;
  } else {
    $("inodeValue").textContent = "—";
  }
}

function renderTable(target, rows, columns) {
  const el = $(target);
  if (!rows || !rows.length) {
    el.innerHTML = '<p class="muted">No matching data.</p>';
    return;
  }

  const table = document.createElement("table");
  const thead = document.createElement("thead");
  const trh = document.createElement("tr");

  for (const col of columns) {
    const th = document.createElement("th");
    th.textContent = col.label;
    trh.appendChild(th);
  }
  thead.appendChild(trh);
  table.appendChild(thead);

  const tbody = document.createElement("tbody");
  for (const row of rows) {
    const tr = document.createElement("tr");
    for (const col of columns) {
      const td = document.createElement("td");
      if (col.className) td.className = col.className;
      td.textContent = col.render ? col.render(row[col.key], row) : (row[col.key] ?? "");
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);

  el.replaceChildren(table);
}

function renderTables(snapshot) {
  renderTable("topFiles", snapshot.top_files || [], [
    {key: "path", label: "Path"},
    {key: "bytes", label: "Size", className: "size", render: humanBytes},
  ]);

  const columns = [
    {key: "path", label: "Directory"},
    {key: "bytes", label: "Size", className: "size", render: humanBytes},
  ];

  renderTable("trashDirs", snapshot.categories?.trash?.top_directories || [], columns);
  renderTable("logDirs", snapshot.categories?.logs?.top_directories || [], columns);
  renderTable("tempDirs", snapshot.categories?.temp?.top_directories || [], columns);
}

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

function drawLineChart(canvas, rows, series) {
  drawLegend(canvas, series);

  const dpr = window.devicePixelRatio || 1;
  const cssW = Math.max(300, canvas.clientWidth);
  const cssH = 280;
  canvas.width = Math.round(cssW * dpr);
  canvas.height = Math.round(cssH * dpr);

  const ctx = canvas.getContext("2d");
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, cssW, cssH);

  const pad = {left: 70, right: 16, top: 20, bottom: 42};
  const w = cssW - pad.left - pad.right;
  const h = cssH - pad.top - pad.bottom;

  ctx.font = "12px system-ui";
  ctx.fillStyle = "#aaa";
  ctx.strokeStyle = "#333";
  ctx.lineWidth = 1;

  if (!rows.length || !series.length) {
    ctx.fillText("No data for this range", pad.left, pad.top + 20);
    return;
  }

  const values = [];
  for (const s of series) {
    for (const r of rows) {
      const v = Number(r[s.key]);
      if (Number.isFinite(v)) values.push(v);
    }
  }

  let min = Math.min(...values);
  let max = Math.max(...values);
  min = Math.min(0, min);
  if (max === min) max = min + 1;

  const x = i => pad.left + (rows.length === 1 ? w / 2 : (i / (rows.length - 1)) * w);
  const y = v => pad.top + h - ((v - min) / (max - min)) * h;

  // Horizontal grid and labels.
  for (let i = 0; i <= 4; i++) {
    const yy = pad.top + (i / 4) * h;
    const val = max - (i / 4) * (max - min);
    ctx.beginPath();
    ctx.moveTo(pad.left, yy);
    ctx.lineTo(pad.left + w, yy);
    ctx.stroke();
    const formatter = series[0].format || (v => String(v));
    ctx.fillText(formatter(val), 4, yy + 4);
  }

  // X axis labels.
  const labelIndexes = [...new Set([0, Math.floor((rows.length - 1) / 2), rows.length - 1])];
  for (const i of labelIndexes) {
    const ts = rows[i]?.timestamp;
    const text = ts ? new Date(ts).toLocaleString() : "";
    const xx = x(i);
    ctx.fillText(text, Math.min(Math.max(pad.left, xx - 45), cssW - 120), cssH - 12);
  }

  series.forEach((s, si) => {
    ctx.strokeStyle = seriesColor(si);
    ctx.fillStyle = seriesColor(si);
    ctx.lineWidth = 2;
    ctx.beginPath();

    let started = false;
    rows.forEach((r, i) => {
      const v = Number(r[s.key]);
      if (!Number.isFinite(v)) return;
      const xx = x(i);
      const yy = y(v);
      if (!started) {
        ctx.moveTo(xx, yy);
        started = true;
      } else {
        ctx.lineTo(xx, yy);
      }
    });
    ctx.stroke();

    if (rows.length <= 60) {
      rows.forEach((r, i) => {
        const v = Number(r[s.key]);
        if (!Number.isFinite(v)) return;
        ctx.beginPath();
        ctx.arc(x(i), y(v), 2.5, 0, Math.PI * 2);
        ctx.fill();
      });
    }
  });
}

async function init() {
  try {
    indexData = await loadJson("index.json");
    populateSelectors();
    await loadSelectedPeriod();
  } catch (err) {
    $("error").innerHTML =
      `<div class="error"><strong>Unable to load monitor data.</strong><br>${String(err)}</div>`;
  }
}

$("year").addEventListener("change", async () => {
  populateMonths();
  populateDays();
  await loadSelectedPeriod();
});
$("month").addEventListener("change", async () => {
  populateDays();
  await loadSelectedPeriod();
});
$("day").addEventListener("change", loadSelectedPeriod);
$("apply").addEventListener("click", renderFiltered);
window.addEventListener("resize", () => {
  if (allRows.length) renderFiltered();
});

init();
