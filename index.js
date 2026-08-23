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
  viewable: [],
  categories: [],
  log: { pathId: null, page: 0, needle: "" },
  schedule: null,
  scheduleDirty: false,
  units: "bytes",
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
  const body = await response.json().catch(() => ({
    error: `The server returned ${response.status} with something that is not JSON.`,
  }));
  if (!response.ok) throw new Error(withReference(body, response.status));
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
  const parsed = await response.json().catch(() => ({
    error: `The server returned ${response.status} with something that is not JSON.`,
  }));
  if (!response.ok) throw new Error(withReference(parsed, response.status));
  return parsed;
}

/**
 * The message to show for a failed request.
 *
 * The reference is the point of it. The server will not put the real error in a
 * response - it names paths and sometimes SQL - so the reference is what ties
 * what you saw on screen to the entry in the problem log.
 */
function withReference(body, status) {
  const parts = [body.error || `HTTP ${status}`];
  if (body.reference) parts.push(`(reference ${body.reference})`);
  return parts.join(" ");
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

  /*
   * Load, said in a way that means something.
   *
   * The raw figure is "processes wanting to run", which is only interpretable
   * against the number of cores: 4.00 is half busy on eight and twice
   * oversubscribed on two. So the card shows the ratio and the card's subtitle
   * says what it is measured against.
   */
  const cores = v.cpu_cores ? Number(v.cpu_cores) : null;
  const perCore = v.load1 != null && cores ? Number(v.load1) / cores : null;
  setCard(
    "cardLoad",
    v.load1 == null ? "n/a" : Number(v.load1).toFixed(2),
    v.load1 == null
      ? ""
      : (cores ? `${(perCore * 100).toFixed(0)}% of ${cores} cores · ` : "")
        + `${Number(v.load5).toFixed(2)} / ${Number(v.load15).toFixed(2)} at 5 and 15 min`,
    perCore == null ? "" : perCore > 1.5 ? "crit" : perCore > 1 ? "warn" : ""
  );

  const rx = v.net_rx_rate == null ? null : Number(v.net_rx_rate);
  const tx = v.net_tx_rate == null ? null : Number(v.net_tx_rate);
  setCard(
    "cardNet",
    rx == null ? "n/a" : `${humanBytes(rx, 1)}/s`,
    rx == null ? "no /proc/net/dev here" : `in · ${humanBytes(tx, 1)}/s out`
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
 * Tick values on round numbers covering a range.
 *
 * The old chart divided the range into four and printed whatever fell out,
 * which on a nearly-full disk produced five ticks all reading "71.8 GiB" while
 * the line visibly moved between them. Two things were wrong: the steps were
 * arbitrary fractions of an arbitrary range, and the labels were rounded to
 * fewer digits than it took to tell them apart.
 *
 * Steps of 1, 2 or 5 times a power of ten fix the first - they are the numbers
 * people can read differences off without doing arithmetic. `tickPrecision`
 * below fixes the second.
 */
function niceTicks(min, max, target = 5, floorAtZero = false) {
  if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) {
    return { ticks: [min, max], step: Math.max(1e-9, max - min), min, max };
  }

  const rough = (max - min) / Math.max(1, target - 1);
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const normalised = rough / magnitude;
  const step = (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) * magnitude;

  let first = Math.floor(min / step) * step;
  const last = Math.ceil(max / step) * step;
  // A load average of -1, or -500 MiB free, is not a thing. Rounding the bottom
  // of the range down to a round number is right until it crosses zero.
  if (floorAtZero && first < 0) first = 0;

  const ticks = [];
  // Counted rather than accumulated, because adding a float to itself thirty
  // times lands on 0.30000000000000004 and prints as its own tick label.
  for (let i = 0; first + i * step <= last + step * 1e-9; i += 1) {
    ticks.push(first + i * step);
  }

  return { ticks, step, min: first, max: last };
}

/**
 * How many decimals a label needs for neighbouring ticks to differ.
 *
 * The reason "82,701,000 / 82,700,999 / 82,700,999" happened: two ticks a
 * thousandth of a percent apart, printed to the same width, come out identical
 * and the axis looks broken. Given the step, this works out what it takes to
 * show the difference - and if that is more digits than anybody wants to read,
 * the caller should be showing a delta instead.
 */
function tickPrecision(step, scale = 1) {
  const shown = step / scale;
  if (!Number.isFinite(shown) || shown <= 0) return 0;
  return Math.max(0, Math.min(4, Math.ceil(-Math.log10(shown)) + 1));
}

/**
 * Bytes, with the unit chosen by the size of the numbers and the number of
 * decimals chosen by the distance between them.
 *
 * Those are two different questions and picking the unit from the step gets the
 * first one wrong: on a disk with 72 GiB free changing by 50 MiB an hour, a
 * step-sized unit labels the axis "73671 MiB", which is the right precision
 * expressed in a unit nobody thinks in. Magnitude picks GiB; step then says it
 * needs two decimals, and the label is "71.94 GiB".
 */
function bytesAxis(step, magnitude = step) {
  const units = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"];
  let scale = 1;
  let i = 0;
  while (Math.abs(magnitude) / scale >= 1024 && i < units.length - 1) { scale *= 1024; i++; }
  const digits = tickPrecision(step, scale);
  return {
    scale,
    unit: units[i],
    format: v => `${(v / scale).toFixed(digits)} ${units[i]}`,
  };
}

/**
 * Plain counts, shortened only when shortening does not cost readability.
 *
 * Inodes run to eight digits and change by tens, so "82.70100M" is a worse
 * label than "82,701,000" - the abbreviation is supposed to save you reading
 * digits and there it adds two. Past two decimals it gives up and groups.
 */
function countAxis(step, magnitude = step) {
  const units = [["", 1], ["K", 1e3], ["M", 1e6], ["B", 1e9]];
  let at = 0;
  while (at < units.length - 1 && Math.abs(magnitude) / units[at + 1][1] >= 1) at++;
  const [suffix, scale] = units[at];
  const digits = tickPrecision(step, scale);

  if (digits > 2) {
    return {
      scale: 1,
      unit: "",
      format: v => new Intl.NumberFormat().format(Math.round(v)),
    };
  }

  return {
    scale,
    unit: suffix,
    format: v => `${(v / scale).toFixed(digits)}${suffix}`,
  };
}

function percentAxis(step) {
  const digits = Math.min(1, tickPrecision(step));
  return { scale: 1, unit: "%", format: v => `${v.toFixed(digits)}%` };
}

function rateAxis(step, magnitude = step) {
  const inner = bytesAxis(step, magnitude);
  return { ...inner, format: v => `${inner.format(v)}/s` };
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
  const pad = { left: 78, right: 16, top: 14, bottom: 34 };
  const h = cssH - pad.top - pad.bottom;

  const usable = rows.filter(r => series.some(s => Number.isFinite(Number(r[s.key]))));
  if (!usable.length) {
    ctx.fillText(options.empty || "Nothing collected for this range yet", pad.left, pad.top + 20);
    return;
  }

  /*
   * One scale per axis.
   *
   * A series says which side it belongs to. That is what lets disk space and
   * inodes share a chart: they are both "how much room is left" but one is
   * bytes and the other is a count, and forcing them onto one scale makes
   * whichever is smaller a flat line along the bottom.
   */
  const sides = ["left", "right"];
  const scales = {};
  for (const side of sides) {
    const mine = series.filter(s => (s.axis || "left") === side);
    if (!mine.length) continue;

    const values = [];
    for (const s of mine) {
      for (const r of usable) {
        const v = Number(r[s.key]);
        if (Number.isFinite(v)) values.push(v);
      }
    }
    if (!values.length) continue;

    let lo = Math.min(...values);
    let hi = Math.max(...values);

    /*
     * Not pinned to zero unless asked.
     *
     * On a disk that is 92% full and losing a gigabyte a day, a zero-based axis
     * draws a flat line across the top of the one chart whose whole purpose is
     * to show the slope.
     */
    if (options.zeroBased) { lo = Math.min(0, lo); hi = Math.max(hi, options.max ?? hi); }
    if (hi === lo) hi = lo + Math.max(1, Math.abs(lo) * 0.01);

    const nice = niceTicks(lo, hi, 5, Boolean(options.zeroBased));
    const axisFor = (side === "right" ? options.rightAxis : options.axis) || bytesAxis;
    // The axis is told how big the numbers are as well as how far apart the
    // ticks are: the first picks the unit, the second picks the decimals.
    scales[side] = { ...nice, ...axisFor(nice.step, Math.max(Math.abs(lo), Math.abs(hi))) };
  }

  if (!scales.left && !scales.right) {
    ctx.fillText(options.empty || "Nothing collected for this range yet", pad.left, pad.top + 20);
    return;
  }

  if (scales.right) pad.right = 76;
  const plotW = cssW - pad.left - pad.right;

  const t0 = Number(usable[0][xKey]);
  const t1 = Number(usable[usable.length - 1][xKey]);
  const single = t1 <= t0;
  const span = single ? 1 : t1 - t0;

  // A single reading has no time axis to speak of. Drawn at the left edge it
  // looks like a bug and its three identical date labels look like another, so
  // it goes in the middle with one label under it.
  const x = t => single
    ? pad.left + plotW / 2
    : pad.left + ((Number(t) - t0) / span) * plotW;
  const yOn = (side, v) => {
    const scale = scales[side] || scales.left;
    return pad.top + h - ((v - scale.min) / (scale.max - scale.min)) * h;
  };

  // Gridlines follow the left axis; the right one only gets labels, or two
  // sets of lines cross each other and the chart turns into a net.
  const gridding = scales.left || scales.right;
  const gridSide = scales.left ? "left" : "right";
  for (const value of gridding.ticks) {
    const yy = yOn(gridSide, value);
    if (yy < pad.top - 1 || yy > pad.top + h + 1) continue;
    ctx.beginPath();
    ctx.moveTo(pad.left, yy);
    ctx.lineTo(pad.left + plotW, yy);
    ctx.stroke();
  }

  ctx.textAlign = "right";
  if (scales.left) {
    for (const value of scales.left.ticks) {
      const yy = yOn("left", value);
      if (yy < pad.top - 1 || yy > pad.top + h + 1) continue;
      ctx.fillText(scales.left.format(value), pad.left - 8, yy + 4);
    }
  }
  ctx.textAlign = "left";
  if (scales.right) {
    for (const value of scales.right.ticks) {
      const yy = yOn("right", value);
      if (yy < pad.top - 1 || yy > pad.top + h + 1) continue;
      ctx.fillText(scales.right.format(value), pad.left + plotW + 8, yy + 4);
    }
  }

  /*
   * Time labels sized to the span, not to a fixed format.
   *
   * Three full date-times do not fit across a panel four hundred pixels wide -
   * they ran into each other and read as one long string. Within a day the date
   * is the same on every tick anyway, so it is the part to drop.
   */
  const sameDay = span < 86400;
  const stamp = t => new Date(t * 1000).toLocaleString([], sameDay
    ? { hour: "2-digit", minute: "2-digit" }
    : { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });

  const marks = single ? [t0] : [t0, t0 + span / 2, t1];
  marks.forEach((t, i) => {
    const label = stamp(t);
    const at = x(t);
    ctx.textAlign = marks.length === 1
      ? "center"
      : i === 0 ? "left" : i === marks.length - 1 ? "right" : "center";
    ctx.fillText(label, Math.min(Math.max(pad.left, at), pad.left + plotW), cssH - 10);
  });
  ctx.textAlign = "left";

  /*
   * The lines stay inside the plot.
   *
   * Without this a value below the axis floor - a load average the axis has
   * clamped at zero, a spike past the top between two redraws - is drawn over
   * the date labels and the legend, which looks like a rendering fault rather
   * than data out of range.
   */
  ctx.save();
  ctx.beginPath();
  ctx.rect(pad.left, pad.top - 2, plotW, h + 4);
  ctx.clip();

  series.forEach((s, si) => {
    const side = (s.axis || "left") in scales ? (s.axis || "left") : Object.keys(scales)[0];
    ctx.strokeStyle = seriesColor(si);
    ctx.fillStyle = seriesColor(si);
    ctx.lineWidth = 2;
    // A dashed line for the right-hand axis, so which series belongs to which
    // set of numbers survives being printed, or being looked at quickly.
    ctx.setLineDash(side === "right" ? [5, 4] : []);
    ctx.beginPath();

    let started = false;
    for (const r of usable) {
      const v = Number(r[s.key]);
      if (!Number.isFinite(v)) { started = false; continue; }
      const xx = x(r[xKey]);
      const yy = yOn(side, v);
      if (started) ctx.lineTo(xx, yy); else ctx.moveTo(xx, yy);
      started = true;
    }
    ctx.stroke();
    ctx.setLineDash([]);

    if (usable.length <= 80) {
      for (const r of usable) {
        const v = Number(r[s.key]);
        if (!Number.isFinite(v)) continue;
        ctx.beginPath();
        ctx.arc(x(r[xKey]), yOn(side, v), 2.2, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  });

  ctx.restore();
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

/** Keeps the category filter in step with the rules the server is using. */
function syncCategoryOptions() {
  const select = $("offCategory");
  const wanted = ["", ...state.categories];
  const have = [...select.options].map(o => o.value);
  if (have.length === wanted.length && have.every((v, i) => v === wanted[i])) {
    return;
  }

  const chosen = select.value;
  select.replaceChildren(...wanted.map(value => {
    const option = document.createElement("option");
    option.value = value;
    option.textContent = value === ""
      ? "All"
      : value.charAt(0).toUpperCase() + value.slice(1);
    return option;
  }));
  if (wanted.includes(chosen)) select.value = chosen;
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
    state.viewable = overview.viewable || [];
    applySignedInChrome();

    state.categories = overview.categories ?? [];
    syncCategoryOptions();

    renderCards(overview, vitals.rows);
    renderVitalCharts(vitals.rows);
    renderCategoryChart(scans.rows, scans.categories ?? state.categories);
    $("lastSeen").textContent = overview.vitals
      ? `updated ${ago(overview.vitals.taken_at)}`
      : "";

    await Promise.all([
      refreshOffenders(), refreshAlerts(), refreshActionLog(),
      refreshSchedule(), refreshHealth(), refreshProblems(),
    ]);
    if (state.historyPathId) await showHistory(state.historyPathId, true);
    if (state.log.pathId) await showLog(state.log.pathId, true);
    notice("");
  } catch (error) {
    notice(String(error.message || error));
  } finally {
    state.busy = false;
  }
}

/**
 * Disk and inodes on one chart, because they are the same question.
 *
 * "How much room is left" has two answers on a Unix filesystem and either can
 * run out on its own - a disk with plenty of gigabytes and no inodes refuses to
 * create a file, which looks exactly like a disk that is full.
 *
 * In percent they share one axis and can be read against each other directly.
 * In absolute terms they cannot: bytes and a count of inodes have no common
 * scale, so inodes move to the right-hand axis and get a dashed line.
 */
function renderRoomChart(rows) {
  const percent = state.units === "percent";

  const withPercents = rows.map(r => ({
    ...r,
    disk_free_percent: r.disk_total_bytes
      ? (Number(r.disk_free_bytes) / Number(r.disk_total_bytes)) * 100
      : null,
    inodes_free_percent: r.inodes_total
      ? (Number(r.inodes_free) / Number(r.inodes_total)) * 100
      : null,
  }));

  drawLineChart($("roomChart"), withPercents,
    percent
      ? [
          { key: "disk_free_percent", label: "Disk free %" },
          { key: "inodes_free_percent", label: "Inodes free %" },
        ]
      : [
          { key: "disk_free_bytes", label: "Disk free" },
          { key: "inodes_free", label: "Inodes free (right)", axis: "right" },
        ],
    percent
      ? { axis: percentAxis, zeroBased: true, max: 100 }
      : { axis: bytesAxis, rightAxis: countAxis }
  );
}

function renderVitalCharts(rows) {
  renderRoomChart(rows);

  drawLineChart($("cpuChart"), rows,
    [{ key: "cpu_percent", label: "CPU %" }, { key: "mem_used_percent", label: "Memory %" }],
    { axis: percentAxis, zeroBased: true, max: 100 });

  /*
   * Load average is not a percentage, which is the single most common thing to
   * get wrong about it, so the chart says what it is in the panel subtitle and
   * draws a line at one-per-core - the level above which work is queueing.
   */
  drawLineChart($("loadChart"), rows,
    [
      { key: "load1", label: "1 min" },
      { key: "load5", label: "5 min" },
      { key: "load15", label: "15 min" },
    ],
    { axis: step => ({ scale: 1, unit: "", format: v => v.toFixed(tickPrecision(step)) }),
      zeroBased: true });

  drawLineChart($("netChart"), rows,
    [{ key: "net_rx_rate", label: "In" }, { key: "net_tx_rate", label: "Out" }],
    { axis: rateAxis, zeroBased: true,
      empty: "No network counters here — /proc/net/dev is not readable" });
}

function renderCategoryChart(rows, categories) {
  // Built from the categories the server actually has rules for, so adding one
  // to the config puts it on the chart rather than collecting it invisibly.
  const series = (categories ?? []).map(name => ({
    key: `${name}_bytes`,
    label: name.charAt(0).toUpperCase() + name.slice(1),
  }));

  drawLineChart($("categoryChart"), rows, series, {
    xKey: "started_at",
    axis: bytesAxis,
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
    { label: "", className: "size", render: row => rowButtons(row) },
  ], row => showHistory(row.path_id));
}

/** The buttons for one row: view a log, delete a file, or neither. */
function rowButtons(row) {
  const holder = document.createElement("span");
  holder.style.display = "inline-flex";
  holder.style.gap = "6px";

  if (state.signedIn && row.kind === "file" && state.viewable.includes(row.category)) {
    const view = document.createElement("button");
    view.textContent = "View";
    view.addEventListener("click", () => showLog(row.path_id));
    holder.appendChild(view);
  }

  const remove = deleteButton(row);
  if (remove !== "") holder.appendChild(remove);

  return holder.childElementCount ? holder : "";
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
  // Size on the left in bytes, file count on the right: a directory holding
  // ten thousand small files and one holding a single huge one are different
  // problems, and on one axis the count is a flat line along the bottom.
  drawLineChart($("historyChart"), data.rows,
    [
      { key: "bytes", label: "Size" },
      { key: "files", label: "Files (right)", axis: "right" },
    ],
    { xKey: "started_at", axis: bytesAxis, rightAxis: countAxis, zeroBased: true,
      empty: "This path has only been seen in one scan so far" });

  if (!quiet) $("historyPanel").scrollIntoView({ behavior: "smooth", block: "nearest" });
}

/**
 * Shows the end of a log file.
 *
 * `page` counts backwards from the end, so 0 is the newest lines and paging
 * "older" walks back through the file. With a search, it walks back through
 * matches instead - which is the only way to page through the interesting lines
 * of a file too big to read.
 */
async function showLog(pathId, quiet = false) {
  if (!pathId) return;
  if (pathId !== state.log.pathId) {
    state.log = { pathId, page: 0, needle: $("logSearch").value.trim() };
  }

  const panel = $("logPanel");
  const body = $("logBody");

  let data;
  try {
    data = await api("tail", {
      path_id: pathId,
      lines: $("logLines").value,
      page: state.log.page,
      q: state.log.needle,
    });
  } catch (error) {
    panel.hidden = false;
    $("logTitle").textContent = "Log";
    $("logStatus").textContent = String(error.message || error);
    body.replaceChildren();
    return;
  }

  panel.hidden = false;
  $("logTitle").textContent = data.path;

  const shown = data.lines.length;
  const parts = [];
  if (state.log.needle) {
    parts.push(`${data.matched} match${data.matched === 1 ? "" : "es"} found`);
  }
  parts.push(`${shown} line${shown === 1 ? "" : "s"}`);
  if (state.log.page > 0) parts.push(`page ${state.log.page + 1} back from the end`);
  parts.push(`file is ${humanBytes(data.size)}`);
  parts.push(`read ${humanBytes(data.scanned)} of it`);
  if (data.truncated) {
    parts.push("stopped searching before the start of the file — narrow the search or look at the file directly");
  } else if (data.reached_start) {
    parts.push("this is the beginning of the file");
  }
  $("logStatus").textContent = parts.join(" · ");

  /*
   * Built as nodes rather than a string of HTML.
   *
   * A log file is the least trustworthy text on the machine - it is full of
   * whatever was sent to the server, which is exactly where somebody would put
   * a script tag hoping an admin tool would render it.
   */
  body.replaceChildren(...data.lines.map(line => {
    const row = document.createElement("div");
    const number = document.createElement("span");
    number.className = "num";
    number.textContent = String(line.n);
    row.appendChild(number);
    row.append(...highlight(line.text, state.log.needle));
    return row;
  }));

  if (!shown) {
    body.textContent = state.log.needle ? "No matching lines here." : "Nothing to show.";
  }

  $("logNewer").disabled = state.log.page === 0;
  $("logOlder").disabled = data.reached_start && shown === 0;

  if (!quiet) panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
}

/** Splits a line around the search term, so the match can be marked. */
function highlight(text, needle) {
  if (!needle) return [document.createTextNode(text)];

  const out = [];
  const haystack = text.toLowerCase();
  const found = needle.toLowerCase();
  let at = 0;

  for (;;) {
    const next = haystack.indexOf(found, at);
    if (next === -1) break;
    if (next > at) out.push(document.createTextNode(text.slice(at, next)));
    const mark = document.createElement("mark");
    mark.textContent = text.slice(next, next + needle.length);
    out.push(mark);
    at = next + needle.length;
  }

  out.push(document.createTextNode(text.slice(at)));
  return out;
}

/* ----------------------------------------------------------------- health -- */

/** Plain-language names for the checks, and what a false one means. */
const HEALTH_LABELS = {
  data_dir_writable: ["Data directory writable", "The database cannot be created or updated."],
  database_writable: ["Database writable", "Readings cannot be recorded."],
  scan_root_readable: ["Watched directory readable", "Scans will find nothing."],
  php_new_enough: ["PHP 8.1 or newer", "This code will not run correctly."],
  sqlite: ["SQLite driver", "Nothing can be stored at all."],
  mail_available: ["mail() available", "Alert emails cannot be sent from here."],
  shell_available: ["shell_exec available", "Inodes and the crontab editor are unavailable."],
  proc_readable: ["/proc readable", "CPU, memory and network stay empty."],
};

async function refreshHealth() {
  let data;
  try {
    data = await api("health");
  } catch (error) {
    $("healthBody").textContent = String(error.message || error);
    return;
  }

  const checks = data.checks;
  const rows = Object.entries(HEALTH_LABELS)
    .filter(([key]) => key in checks)
    .map(([key, [label, consequence]]) => ({
      label,
      ok: Boolean(checks[key]),
      consequence,
      /*
       * A host with no /proc and no mail() is limited, not broken.
       *
       * Painting those red would put two permanent failures on every shared
       * host, which is how people learn to ignore the whole panel.
       */
      soft: ["mail_available", "shell_available", "proc_readable"].includes(key),
    }));

  const table = document.createElement("table");
  const body = table.createTBody();
  for (const row of rows) {
    const tr = body.insertRow();
    const mark = tr.insertCell();
    mark.textContent = row.ok ? "\u2713" : row.soft ? "\u2014" : "\u2715";
    mark.className = row.ok ? "shrank" : row.soft ? "muted" : "grew";
    mark.style.width = "2ch";
    tr.insertCell().textContent = row.label;
    const note = tr.insertCell();
    note.className = "muted small";
    note.textContent = row.ok ? "" : row.consequence;
  }

  const facts = document.createElement("p");
  facts.className = "muted small";
  const bits = [
    `PHP ${checks.php_version}`,
    `schema v${checks.schema_version}`,
    `${checks.problem_log_entries} problem${checks.problem_log_entries === 1 ? "" : "s"} logged`,
  ];
  if (checks.php_error_log) bits.push(`PHP logs to ${checks.php_error_log}`);
  if (checks.problem_log) bits.push(`this log: ${checks.problem_log}`);
  facts.textContent = bits.join(" \u00b7 ");

  $("healthBody").replaceChildren(table, facts);
}

async function refreshProblems() {
  const panel = $("problemPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  let data;
  try {
    data = await api("problems");
  } catch (error) {
    $("problemBody").textContent = String(error.message || error);
    return;
  }

  $("problemPath").textContent = data.path;

  if (!data.rows.length) {
    $("problemBody").replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Nothing has gone wrong. Good.",
    }));
    return;
  }

  renderTable("problemBody", data.rows, [
    { label: "When", render: row => (row.at ? new Date(row.at).toLocaleString() : "") },
    { label: "Level", render: row => row.level },
    { label: "Where", render: row => row.where },
    { label: "What happened", className: "name", render: row => row.message },
  ]);
}

/* --------------------------------------------------------------- schedule -- */

async function refreshSchedule() {
  const panel = $("schedulePanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  /*
   * Never rebuild the form out from under somebody filling it in.
   *
   * refreshSchedule runs on the same thirty-second poll as everything else, and
   * it used to replace the whole panel each time - so choices made just before
   * a tick were silently reset to whatever is installed, and the next press of
   * Save wrote those instead of what had been picked.
   */
  if (state.scheduleDirty || panel.contains(document.activeElement)) {
    return;
  }

  let data;
  try {
    data = await api("schedule");
  } catch (error) {
    $("scheduleBody").textContent = String(error.message || error);
    return;
  }

  state.schedule = data;
  drawSchedule(data);
}

function drawSchedule(data) {
  const holder = document.createElement("div");
  holder.className = "sched";

  if (!data.available) {
    holder.textContent = data.reason || "The crontab cannot be read here.";
    $("scheduleBody").replaceChildren(holder);
    return;
  }

  const current = document.createElement("div");
  if (data.lines.length) {
    const pre = document.createElement("pre");
    pre.textContent = data.lines.join("\n");
    current.append(Object.assign(document.createElement("p"), {
      className: "muted small",
      textContent: `Installed now${data.other_lines > 0
        ? `, alongside ${data.other_lines} other line${data.other_lines === 1 ? "" : "s"} this dashboard does not touch`
        : ""}:`,
    }), pre);
  } else {
    current.append(Object.assign(document.createElement("p"), {
      className: "muted small",
      textContent: "Nothing is scheduled. The dashboard will only show whatever you collect by hand.",
    }));
  }
  holder.appendChild(current);

  const form = document.createElement("div");
  form.className = "row";
  form.append(
    picker("schedVitals", "Vitals", data.presets.vitals, guessPick(data.lines, "--vitals", data.presets.vitals)),
    picker("schedScan", "Scan", data.presets.scan, guessPick(data.lines, "--scan", data.presets.scan)),
    numberField("schedMinute", "At minute", 0, 59, 7),
    numberField("schedHour", "At hour", 0, 23, 2),
    picker("schedPhp", "PHP binary",
      Object.fromEntries(data.binaries.map(b => [b, { label: b }])), data.binaries[0]),
  );
  holder.appendChild(form);

  const note = document.createElement("p");
  note.className = "muted small";
  note.id = "schedNote";
  holder.appendChild(note);

  /*
   * What will actually be written, shown before it is.
   *
   * The reason this exists: "At hour" is only used by the one preset that runs
   * once a day, so choosing Hourly and setting the hour silently dropped it,
   * and the first anybody knew was reading the crontab afterwards. A preview
   * makes every one of those cases obvious without having to know which field
   * feeds which schedule.
   *
   * The server builds the real lines and refuses anything not on its list; this
   * is a rendering of the same rules, not a second source of truth.
   */
  const previewLabel = document.createElement("p");
  previewLabel.className = "muted small";
  previewLabel.textContent = "This will be written:";
  const preview = document.createElement("pre");
  preview.id = "schedPreview";
  holder.append(previewLabel, preview);

  const buttons = document.createElement("div");
  buttons.className = "row";

  const save = document.createElement("button");
  save.id = "schedSave";
  save.textContent = data.lines.length ? "Update the schedule" : "Add to the crontab";
  save.addEventListener("click", () => saveSchedule(false));
  buttons.appendChild(save);

  if (data.lines.length) {
    const remove = document.createElement("button");
    remove.className = "danger";
    remove.textContent = "Remove from the crontab";
    remove.addEventListener("click", () => saveSchedule(true));
    buttons.appendChild(remove);
  }

  holder.appendChild(buttons);
  $("scheduleBody").replaceChildren(holder);

  for (const id of ["schedVitals", "schedScan", "schedMinute", "schedHour", "schedPhp"]) {
    $(id).addEventListener("input", () => {
      state.scheduleDirty = true;
      updateSchedulePreview();
    });
  }

  updateSchedulePreview();
}

/** Substitutes the chosen minute and hour the way the server will. */
function cronExpression(expr, minute, hour) {
  return expr.replace(/%M/g, String(minute)).replace(/%H/g, String(hour));
}

function updateSchedulePreview() {
  const data = state.schedule;
  if (!data || !$("schedPreview")) return;

  const minute = Math.max(0, Math.min(59, Number($("schedMinute").value) || 0));
  const hour = Math.max(0, Math.min(23, Number($("schedHour").value) || 0));
  const php = $("schedPhp").value;
  const script = data.script || "cron.php";

  const jobs = [
    ["vitals", $("schedVitals").value, "--vitals --alerts --quiet"],
    ["scan", $("schedScan").value, "--scan --alerts --prune --quiet"],
  ];

  const lines = [];
  let usesMinute = false;
  let usesHour = false;

  for (const [job, pick, flags] of jobs) {
    const preset = data.presets[job]?.[pick];
    if (!preset || !preset.expr) continue;
    if (preset.expr.includes("%M")) usesMinute = true;
    if (preset.expr.includes("%H")) usesHour = true;
    lines.push(`${cronExpression(preset.expr, minute, hour)} '${php}' '${script}' ${flags}`);
  }

  $("schedPreview").textContent = lines.length
    ? lines.join("\n")
    : "Nothing — saving will remove this dashboard's lines from the crontab.";

  /*
   * A field that does nothing is greyed out and says why.
   *
   * Leaving both enabled all the time is what made "hourly at minute 7 at hour
   * 2" look like it had been accepted when only the 7 was ever used.
   */
  setFieldUsable("schedMinute", usesMinute);
  setFieldUsable("schedHour", usesHour);

  const unused = [];
  if (!usesMinute) unused.push("minute");
  if (!usesHour) unused.push("hour");
  $("schedNote").textContent = unused.length
    ? `The ${unused.join(" and ")} ${unused.length === 1 ? "field is" : "fields are"} `
      + "not used by the schedules you have chosen. "
      + "Only \u201cOnce a day\u201d uses the hour; the fixed intervals use neither."
    : "";
}

function setFieldUsable(id, usable) {
  const field = $(id);
  field.disabled = !usable;
  field.style.opacity = usable ? "1" : ".4";
  field.parentElement.style.opacity = usable ? "1" : ".55";
}

/** Which preset the installed line looks like, so the form opens on it. */
function guessPick(lines, flag, presets) {
  const line = lines.find(l => l.includes(flag));
  if (!line) return "off";
  const expr = line.split(/\s+/).slice(0, 5).join(" ");
  const match = Object.entries(presets).find(([, p]) =>
    p.expr && p.expr.replace(/%M|%H/g, "\\d+").split(" ").every(
      (part, i) => new RegExp(`^${part.replace(/\*/g, "\\*")}$`).test(expr.split(" ")[i] ?? "")
    ));
  return match ? match[0] : "off";
}

function picker(id, label, options, chosen) {
  const wrap = document.createElement("label");
  wrap.className = "inline";
  wrap.append(label);
  const select = document.createElement("select");
  select.id = id;
  for (const [value, option] of Object.entries(options)) {
    const el = document.createElement("option");
    el.value = value;
    el.textContent = option.label;
    if (value === chosen) el.selected = true;
    select.appendChild(el);
  }
  wrap.appendChild(select);
  return wrap;
}

function numberField(id, label, min, max, value) {
  const wrap = document.createElement("label");
  wrap.className = "inline";
  wrap.append(label);
  const input = document.createElement("input");
  Object.assign(input, { id, type: "number", min, max, value });
  input.style.width = "5.5rem";
  wrap.appendChild(input);
  return wrap;
}

async function saveSchedule(remove) {
  const preview = remove ? "" : ($("schedPreview")?.textContent ?? "");
  const what = remove
    ? "Remove this dashboard's lines from your crontab?"
    : `Write this into your crontab?\n\n${preview}`;
  if (!confirm(`${what}\n\nNothing else in it is changed.`)) return;

  try {
    const result = await post("schedule-save", {
      vitals: remove ? "off" : $("schedVitals").value,
      scan: remove ? "off" : $("schedScan").value,
      minute: $("schedMinute").value,
      hour: $("schedHour").value,
      php: $("schedPhp").value,
    });
    notice(result.lines.length
      ? `Crontab updated with ${result.lines.length} line${result.lines.length === 1 ? "" : "s"}.`
      : "Removed from the crontab.", true);
    // The edits are committed, so the panel may follow the installed state again.
    state.scheduleDirty = false;
    await refreshSchedule();
  } catch (error) {
    notice(String(error.message || error));
  }
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

$("units").addEventListener("change", () => {
  state.units = $("units").value;
  refreshAll();
});

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

$("logClose").addEventListener("click", () => {
  state.log = { pathId: null, page: 0, needle: "" };
  $("logPanel").hidden = true;
});

$("logOlder").addEventListener("click", () => {
  state.log.page += 1;
  showLog(state.log.pathId, true);
});

$("logNewer").addEventListener("click", () => {
  state.log.page = Math.max(0, state.log.page - 1);
  showLog(state.log.pathId, true);
});

$("logLines").addEventListener("change", () => {
  state.log.page = 0;
  if (state.log.pathId) showLog(state.log.pathId, true);
});

// Searching restarts at the newest match rather than keeping the page number,
// which would otherwise land somewhere arbitrary in a different set of results.
let searchTimer = null;
$("logSearch").addEventListener("input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    state.log.needle = $("logSearch").value.trim();
    state.log.page = 0;
    if (state.log.pathId) showLog(state.log.pathId, true);
  }, 350);
});

$("healthRefresh").addEventListener("click", () => {
  refreshHealth().catch(e => notice(String(e.message || e)));
  refreshProblems().catch(() => {});
});

$("testEmail").addEventListener("click", async () => {
  const button = $("testEmail");
  button.disabled = true;
  try {
    const result = await post("test-email");
    notice(result.sent
      ? `Test email sent to ${result.to}. If it does not arrive, check the spam folder and the host's mail log.`
      : (result.error || "Could not send."), result.sent);
  } catch (error) {
    notice(String(error.message || error));
  } finally {
    button.disabled = false;
  }
});

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
