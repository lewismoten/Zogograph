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
/** How long a confirmation survives the refresh that its own action triggered. */
const NOTICE_KEEP_SECONDS = 10;

const SERIES_COLORS = ["#73b7ff", "#ffb86b", "#75d58a", "#d5a6ff", "#ff7f9f"];
const seriesColor = i => SERIES_COLORS[i % SERIES_COLORS.length];

/** How often the page refreshes itself, awake and in live mode. */
const IDLE_POLL_MS = 30000;
const LIVE_POLL_MS = 10000;
/** While a scan is running, so its finishing is noticed promptly. */
const SCAN_POLL_MS = 5000;

const state = {
  signedIn: false,
  csrf: null,
  range: 86400,
  capabilities: null,
  view: "overview",
  rendered: new Set(),
  frozenAt: null,
  overview: null,
  live: false,
  timer: null,
  historyPathId: null,
  deletable: [],
  viewable: [],
  scanRunning: false,
  categories: [],
  log: { pathId: null, page: 0, needle: "" },
  schedule: null,
  scheduleDirty: false,
  units: "bytes",
  uptime: null,
  totals: {},
  emptied: {},
  noticeAt: 0,
  noticeOk: false,
  restarts: [],
  hostMetric: "pss",
  hostCharted: [],
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
  /*
   * Accepts a plain object or a URLSearchParams.
   *
   * Spreading URLSearchParams into an object literal yields nothing - it has no
   * own enumerable properties - so a caller passing one would silently send a
   * bare action and get an answer to a different question. Repeated keys, which
   * `names[]` needs, are only expressible the second way.
   */
  const query = params instanceof URLSearchParams
    ? params
    : new URLSearchParams(params);
  query.set("action", action);
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
  // Remembered so a refresh can tell a confirmation it should leave alone from
  // a stale error it should tidy away.
  state.noticeAt = message ? Math.floor(Date.now() / 1000) : 0;
  state.noticeOk = Boolean(message) && ok;
}

/**
 * Clears the notice after a refresh, but not one that just said something.
 *
 * Every action that changes anything finishes by refreshing, and the refresh
 * used to end by blanking the notice - so "Deleted 8.58 MiB." was wiped by the
 * reload it had itself started, and deleting a file gave no confirmation at
 * all. The message it replaced was the whole feedback for the action.
 *
 * Errors are still cleared: a failure that has since been fixed should not stay
 * on screen. A success is kept briefly and then goes on its own.
 */
function clearStaleNotice() {
  const age = Math.floor(Date.now() / 1000) - (state.noticeAt || 0);
  if (state.noticeOk && age < NOTICE_KEEP_SECONDS) {
    return;
  }
  notice("");
}

/* ------------------------------------------------------------------ cards -- */

function setCard(id, value, sub, level = "") {
  const card = $(id);
  // The gauges took several of these over, and a caller that still computes a
  // figure for one is not a fault - it is just no longer drawn here.
  if (!card) return;
  card.querySelector(".value").textContent = value;
  card.querySelector(".sub").textContent = sub ?? "";
  card.className = `card ${level}`.trim();
}

/*
 * The dials at the top of the landing page.
 *
 * Everything here is already in the cards below, and that is the point: a card
 * is a number you have to know the thresholds for, and an arc is a position you
 * can read from across the room. Somebody opening this at eight in the morning
 * wants "is anything red", not eight figures to compare against remembered
 * limits.
 *
 * Each gauge names its band as well as colouring it. Red and green are the same
 * shape to a colour-blind reader, and this is the one screen where the whole
 * message is carried by the colour.
 */
function bandOf(value, warn, crit) {
  if (value == null || !Number.isFinite(value)) return null;
  return value >= crit ? "crit" : value >= warn ? "warn" : "ok";
}

const BAND_WORD = { ok: "healthy", warn: "watch", crit: "urgent" };
const BAND_COLOR = { ok: "#56c98a", warn: "#f0a35e", crit: "#f2616b" };

function renderGauges(overview) {
  const v = overview.vitals;
  const holder = $("gauges");

  if (!v) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Nothing collected yet.",
    }));
    return;
  }

  const host = overview.limits?.rows || [];
  const hostOf = name => host.find(r => r.name === name)?.host ?? null;

  const usedPercent = v.disk_total_bytes
    ? (v.disk_used_bytes / v.disk_total_bytes) * 100
    : null;
  const inodePercent = v.inodes_total
    ? (v.inodes_used / v.inodes_total) * 100
    : null;
  const cores = v.cpu_cores ? Number(v.cpu_cores) : null;
  const perCore = v.load1 != null && cores ? (Number(v.load1) / cores) * 100 : null;
  const hostCpu = hostOf("CPU in use");

  const dials = [
    {
      label: "Disk used", value: usedPercent, warn: 88, crit: 95,
      sub: v.disk_total_bytes
        ? `${humanBytes(v.disk_free_bytes)} free of ${humanBytes(v.disk_total_bytes)}`
        : "not reported",
    },
    {
      label: "Inodes used", value: inodePercent, warn: 88, crit: 95,
      sub: v.inodes_total ? `${humanCount(v.inodes_free)} free` : "not reported",
    },
    {
      label: "CPU", value: v.cpu_percent == null ? null : Number(v.cpu_percent),
      warn: 70, crit: 90,
      // The account's allowance, with the machine's own figure under it - see
      // the limits panel for why they are not the same measurement.
      sub: v.cpu_percent == null
        ? "no /proc/stat here"
        : hostCpu == null
          ? "of this account's allowance"
          : `of the allowance · host ${Number(hostCpu).toFixed(0)}%`,
    },
    {
      label: "Memory", value: v.mem_used_percent == null ? null : Number(v.mem_used_percent),
      warn: 80, crit: 92,
      sub: v.mem_total_bytes
        ? `${humanBytes(v.mem_available_bytes)} available of ${humanBytes(v.mem_total_bytes)}`
        : "not reported",
    },
    {
      label: "Load", value: perCore, warn: 100, crit: 150, max: 200,
      sub: v.load1 == null
        ? "not reported"
        : cores
          ? `${Number(v.load1).toFixed(2)} over ${count(cores, "core")}`
          : `${Number(v.load1).toFixed(2)}, cores unknown`,
    },
  ];

  holder.replaceChildren(...dials.map(buildGauge));
}

function buildGauge(dial) {
  const box = document.createElement("div");
  box.className = "gauge";

  const canvas = document.createElement("canvas");
  box.appendChild(canvas);

  const label = document.createElement("div");
  label.className = "gauge-label";
  label.textContent = dial.label;
  box.appendChild(label);

  const band = bandOf(dial.value, dial.warn, dial.crit);
  const state = document.createElement("div");
  state.className = "gauge-band " + (band ? `band-${band}` : "muted");
  state.textContent = band ? BAND_WORD[band] : "not measured";
  box.appendChild(state);

  const sub = document.createElement("div");
  sub.className = "gauge-sub";
  sub.textContent = dial.sub;
  box.appendChild(sub);

  // Drawn after the node is in the document, or clientWidth is zero and the
  // remembered redraw is the only thing that ever paints it.
  requestAnimationFrame(() => drawGauge(canvas, dial, band));
  rememberDraw(canvas, () => drawGauge(canvas, dial, bandOf(dial.value, dial.warn, dial.crit)));

  return box;
}

/*
 * A three-quarter arc, because a full circle has no beginning.
 *
 * The gap at the bottom is what tells you which end is empty; a ring with no
 * gap is read as a pie and the eye looks for the second slice.
 */
function drawGauge(canvas, dial, band) {
  const width = canvas.clientWidth;
  if (!width) return;

  const dpr = window.devicePixelRatio || 1;
  const height = Math.round(width * 0.62);
  canvas.width = Math.round(width * dpr);
  canvas.height = Math.round(height * dpr);
  canvas.style.height = height + "px";

  const ctx = canvas.getContext("2d");
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, width, height);

  const max = dial.max || 100;
  const cx = width / 2;
  const cy = height * 0.78;
  const radius = Math.min(width / 2, cy) - 12;
  const thickness = Math.max(8, radius * 0.22);

  const start = Math.PI * 0.75;
  const sweep = Math.PI * 1.5;

  ctx.lineCap = "round";
  ctx.lineWidth = thickness;

  ctx.strokeStyle = "#232d3a";
  ctx.beginPath();
  ctx.arc(cx, cy, radius, start, start + sweep);
  ctx.stroke();

  if (dial.value != null && Number.isFinite(dial.value)) {
    const share = Math.max(0, Math.min(1, dial.value / max));
    if (share > 0) {
      ctx.strokeStyle = BAND_COLOR[band] || "#4c9aff";
      ctx.beginPath();
      ctx.arc(cx, cy, radius, start, start + sweep * share);
      ctx.stroke();
    }
  }

  ctx.fillStyle = dial.value == null ? "#66717f" : "#e6edf3";
  ctx.textAlign = "center";
  ctx.textBaseline = "alphabetic";
  ctx.font = `600 ${Math.round(radius * 0.52)}px system-ui, sans-serif`;
  ctx.fillText(
    dial.value == null ? "—" : `${Math.round(dial.value)}%`,
    cx, cy + radius * 0.12
  );
}

/*
 * The account's readings against the machine's.
 *
 * Every row shows both numbers and then says what the pair means, because the
 * pair is the finding: two cores out of thirty-two is not a fact about this
 * account's CPU use, it is the reason its CPU use looks the way it does.
 */
function renderLimits(limits) {
  const body = $("limitsBody");
  const when = $("limitsWhen");

  if (!limits || !limits.available) {
    when.textContent = "";
    body.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: limits?.reason || "No comparison available.",
    }));
    return;
  }

  when.textContent = limits.host_at
    ? `host reading ${humanTime(limits.host_at)}`
    : "";

  const format = (value, unit) => {
    if (value == null) return "not measured";
    if (unit === "bytes") return humanBytes(value);
    if (unit === "percent") return `${Number(value).toFixed(0)}%`;
    return String(Number(value) % 1 === 0 ? value : Number(value).toFixed(2));
  };

  body.replaceChildren(...limits.rows.map(row => {
    const div = document.createElement("div");
    div.className = "limit-row";

    const name = document.createElement("div");
    name.className = "limit-name";
    name.textContent = row.name;
    div.appendChild(name);

    for (const [side, value] of [["This account sees", row.account], ["The machine has", row.host]]) {
      const pair = document.createElement("div");
      pair.className = "limit-pair";

      const heading = document.createElement("div");
      heading.className = "muted";
      heading.textContent = side;
      pair.appendChild(heading);

      const figure = document.createElement("div");
      figure.textContent = format(value, row.unit);
      if (row.verdict === "capped" && side === "This account sees") {
        figure.className = "band-warn";
      }
      pair.appendChild(figure);

      div.appendChild(pair);
    }

    const note = document.createElement("div");
    note.className = "limit-note";
    note.textContent = row.note;
    div.appendChild(note);

    return div;
  }));
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

  setTrendCard(overview.trend?.disk);

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

/**
 * The projection card, and the distinction it exists to draw.
 *
 * "Losing space" and "ran out of space once" look identical on a total and are
 * completely different problems. The server separates them - see seriesTrend in
 * lib/alerts.php - and this shows which one it decided, because a projection
 * you cannot see the reasoning behind is a number people learn to ignore.
 */
function setTrendCard(trend) {
  if (!trend) {
    setCard("cardTrend", "—", "not enough history yet");
    return;
  }

  if (trend.spike) {
    setCard(
      "cardTrend",
      "one-off",
      `${humanBytes(-trend.overall * (trend.span_seconds || 1))} went at once, not a trend`
    );
    return;
  }

  if (!trend.sustained || trend.seconds_left == null) {
    setCard("cardTrend", "steady", "free space is not falling");
    return;
  }

  setCard(
    "cardTrend",
    projectedDate(trend.seconds_left),
    `losing ${humanBytes(-trend.rate * 3600)}/h`
      + `, measured over ${humanDuration(trend.window_seconds || 86400)}`,
    trend.seconds_left < 86400 ? "crit" : trend.seconds_left < 604800 ? "warn" : ""
  );
}

/**
 * When the disk is projected to fill, as a date.
 *
 * A duration is the harder thing to act on: "1095.1 days" has to be converted
 * to something a calendar understands before it means anything, and at that
 * range the decimal is spurious precision on an estimate that is really "some
 * time in 2029". A date is the same information already converted.
 *
 * The year appears only past a month. Inside that, the day and month are
 * unambiguous and the year is noise; beyond it, leaving the year off would make
 * next August and August three years from now look identical.
 */
function projectedDate(secondsLeft) {
  const when = new Date((Math.floor(Date.now() / 1000) + secondsLeft) * 1000);

  return when.toLocaleDateString([], secondsLeft > 30 * 86400
    ? { year: "numeric", month: "numeric", day: "numeric" }
    : { month: "numeric", day: "numeric" });
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
/*
 * Charts remember how to redraw themselves.
 *
 * A canvas inside a hidden view has a clientWidth of zero, so its backing
 * store gets sized for a 300px clamp and CSS then stretches it to the panel's
 * real width - four times over, which is why the category chart came out
 * blurry with wide letters. It happened because the data for that chart is
 * fetched by one view while the canvas lives in another.
 *
 * Rather than police which view fetches what, every draw is remembered and
 * replayed when the canvas actually has a layout: on becoming visible, and on
 * resize. The draw while hidden is skipped rather than done wrongly.
 */
function rememberDraw(canvas, redraw) {
  canvas.__redraw = redraw;
}

function redrawCharts(root = document) {
  const dpr = window.devicePixelRatio || 1;

  for (const canvas of root.querySelectorAll("canvas")) {
    if (canvas.clientWidth === 0) continue;

    if (canvas.__redraw) {
      canvas.__redraw();
      continue;
    }

    /*
     * A canvas nothing ever drew into.
     *
     * It keeps the HTML default backing store of 300x150 while CSS stretches
     * it to the panel's width, and the browser scales that empty bitmap to
     * fit - the same blurry, letters-too-wide look, arrived at from the other
     * direction. Sizing it to its own box makes an undrawn chart blank rather
     * than smeared.
     */
    canvas.width = Math.round(canvas.clientWidth * dpr);
    canvas.height = Math.round((canvas.clientHeight || 260) * dpr);
  }
}

function drawLineChart(canvas, rows, series, options = {}) {
  rememberDraw(canvas, () => drawLineChart(canvas, rows, series, options));

  /*
   * Nothing is drawn into a canvas with no width.
   *
   * Drawing anyway produces a backing store sized for a box that is not the
   * one it will be shown in, and the result is stretched the moment it becomes
   * visible. The remembered redraw above puts it right.
   */
  if (canvas.clientWidth === 0) {
    return;
  }

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
     * Scaled to the bulk of the readings, not to the worst one.
     *
     * This is the difference between a useful chart of changes and a flat line
     * with one spike on it. A twenty-gigabyte upload is four hundred times a
     * steady loss of fifty megabytes, so on a full-range axis the thing you are
     * looking for is a pixel high. Trimming the extremes puts the ordinary
     * readings back on a readable scale; the outliers are still drawn, clipped
     * at the edge, and counted underneath so nothing is hidden.
     */
    if (options.trim) {
      const sorted = [...values].sort((a, b) => a - b);
      const cut = Math.floor(sorted.length * options.trim);
      if (cut > 0 && sorted.length - cut > cut) {
        const trimmedLo = sorted[cut];
        const trimmedHi = sorted[sorted.length - 1 - cut];
        // Only worth doing when the extremes really are extreme; otherwise it
        // just crops a chart that was fine.
        if ((hi - lo) > (trimmedHi - trimmedLo) * 4) {
          lo = trimmedLo;
          hi = trimmedHi;
        }
      }
    }

    /*
     * Not pinned to zero unless asked.
     *
     * On a disk that is 92% full and losing a gigabyte a day, a zero-based axis
     * draws a flat line across the top of the one chart whose whole purpose is
     * to show the slope.
     */
    if (options.zeroBased) { lo = Math.min(0, lo); hi = Math.max(hi, options.max ?? hi); }

    /*
     * A chart of changes always shows zero.
     *
     * Zero is the reference the whole reading depends on - above it is space
     * coming back, below it is space going - and an axis that happens to
     * exclude it turns "steadily losing 85 MiB" into a flat line in the middle
     * of nowhere. It also stops a perfectly constant series collapsing to a
     * zero-height range whose tick labels are all the same number.
     */
    if (options.zeroLine) {
      lo = Math.min(0, lo);
      hi = Math.max(0, hi);
    }

    if (hi === lo) hi = lo + Math.max(1, Math.abs(lo) * 0.01);

    const axisFor = (side === "right" ? options.rightAxis : options.axis) || bytesAxis;
    const magnitude = Math.max(Math.abs(lo), Math.abs(hi));

    /*
     * Ticks picked in the unit they will be printed in.
     *
     * niceTicks rounds to 1, 2 or 5 times a power of ten, which is only nice if
     * the label is written in tens. Bytes are not: five billion is a perfectly
     * round decimal step and prints as "4.7 GiB", and a column of 0.0 / 4.7 /
     * 9.3 / 14.0 GiB is the kind of axis you have to decode rather than read.
     *
     * So the axis is asked what unit these numbers will be shown in, the range
     * is divided by it, and the ticks are chosen among those - 0, 5, 10, 15 GiB
     * rather than the same positions rendered awkwardly. Two passes because the
     * unit depends on a step and the step depends on the unit; the first guess
     * is only used to ask the question, and when the unit turns out to be 1 -
     * percentages, load averages - the guess was already the answer.
     */
    const guess = niceTicks(lo, hi, 5, Boolean(options.zeroBased));
    const unit = axisFor(guess.step, magnitude).scale || 1;
    const nice = unit === 1
      ? guess
      : niceTicks(lo / unit, hi / unit, 5, Boolean(options.zeroBased));

    const step = nice.step * unit;
    // The axis is told how big the numbers are as well as how far apart the
    // ticks are: the first picks the unit, the second picks the decimals.
    scales[side] = {
      ticks: nice.ticks.map(tick => tick * unit),
      step,
      min: nice.min * unit,
      max: nice.max * unit,
      ...axisFor(step, magnitude),
    };
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

  /*
   * Stretches with no readings, shaded before anything is drawn over them.
   *
   * Without this a gap is invisible: the line simply joins the reading before
   * to the reading after, which draws a confident straight edge across hours
   * that were never measured. Red for a restart, grey for a stretch the
   * collector missed while the machine kept running - the same distinction the
   * analysis makes, because they call for different responses.
   */
  for (const band of options.outages || []) {
    const x0 = x(Math.max(band.from, t0));
    const x1 = x(Math.min(band.to, t1));
    if (!(x1 > x0)) continue;

    ctx.save();
    ctx.fillStyle = band.kind === "restart"
      ? "rgba(220, 60, 60, 0.22)"
      : "rgba(150, 150, 150, 0.14)";
    ctx.fillRect(x0, pad.top, Math.max(1.5, x1 - x0), h);
    ctx.restore();
  }

  /*
   * Known restarts, as a line rather than a band.
   *
   * A reboot need not leave a measurable hole: if the collector resumes within
   * a minute, the gap is under the threshold and there is nothing to shade -
   * yet the machine did restart, and that is exactly the moment somebody
   * scanning a chart wants marked. A band of near-zero width would be
   * invisible, so these are drawn as a line at the instant itself.
   *
   * They come from `last` on the host, which knows about restarts the readings
   * cannot show - including any that happened before boot times were being
   * recorded at all.
   */
  /*
   * Restart marks default to whatever the page knows about, deliberately.
   *
   * Passing them per call meant five charts had them and four did not, which is
   * not a decision anybody made - it is the list nobody finished. A chart that
   * plots time against anything should say when the machine restarted, so the
   * default is "yes" and a caller that genuinely does not want them can pass an
   * empty array. This couples a drawing function to page state, which is a real
   * cost; the alternative is every future chart starting out wrong.
   */
  const restartsAt = options.marks ?? state.restarts ?? [];

  for (const at of restartsAt) {
    if (at < t0 || at > t1) continue;
    const mx = x(at);

    ctx.save();
    ctx.strokeStyle = "rgba(230, 90, 90, 0.85)";
    ctx.lineWidth = 1.5;
    ctx.setLineDash([4, 3]);
    ctx.beginPath();
    ctx.moveTo(mx, pad.top);
    ctx.lineTo(mx, pad.top + h);
    ctx.stroke();
    ctx.restore();

    // A solid cap at the top, so a dashed line crossing a busy chart still
    // reads as a marker rather than as another series.
    ctx.save();
    ctx.fillStyle = "rgba(230, 90, 90, 0.95)";
    ctx.fillRect(mx - 3, pad.top, 6, 3);
    ctx.restore();
  }

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

  /*
   * A brighter line at zero when the values can be negative.
   *
   * On a chart of changes the sign is the whole message, and telling above from
   * below by reading the axis labels is work the chart should be doing.
   */
  if (options.zeroLine && gridding.min < 0 && gridding.max > 0) {
    const zero = yOn(gridSide, 0);
    ctx.save();
    ctx.strokeStyle = "#6a6a6a";
    ctx.beginPath();
    ctx.moveTo(pad.left, zero);
    ctx.lineTo(pad.left + plotW, zero);
    ctx.stroke();
    ctx.restore();
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

/* -------------------------------------------------------------------- pie -- */

/*
 * A palette wide enough for every slice.
 *
 * The line-chart palette holds five, which is plenty for five lines and wrong
 * for a pie: at six slices the sixth is handed the first colour again, and two
 * identical wedges make the labels look shuffled. Ten distinct hues, because
 * the pie draws at most ten named slices before folding the rest away.
 */
const PIE_COLORS = [
  "#73b7ff", "#ffb86b", "#75d58a", "#d5a6ff", "#ff7f9f",
  "#4fd6d2", "#f5d76e", "#a0a8ff", "#ff9d6b", "#8fd67a",
];

/** The most named slices before the rest are folded into one. */
const PIE_MAX_SLICES = 10;

/** Text on a coloured wedge, dark or light by how bright the wedge is. */
function inkOn(hex) {
  const n = parseInt(hex.slice(1), 16);
  // Rec. 601 luma: green carries most of perceived brightness, blue least.
  const luma = (((n >> 16) & 255) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000;
  return luma > 140 ? "#111" : "#f0f0f0";
}

/**
 * A pie of memory by service, with the long tail as one slice.
 *
 * **Drawn from PSS, and it has to be.** A pie asserts that its slices make up a
 * whole, and resident memory cannot: a page shared by ten processes is counted
 * ten times, so the parts add up to more than the machine has and every
 * percentage is inflated by an amount that varies per slice. Proportional set
 * size divides shared pages among their holders, which is exactly the property
 * a pie needs.
 *
 * Slices large enough to hold text carry their own share and size, so the list
 * beside the circle only has to name things. The ones too narrow to write in
 * keep their figures in the list instead - nothing is only ever readable in one
 * place.
 */
function drawPieChart(canvas, slices, options = {}) {
  /*
   * Remembered like every other chart.
   *
   * Without this, redrawCharts treats the canvas as one nothing ever drew into
   * and resizes it to its box - which clears it. The pie was painting
   * correctly and then being wiped by the tidy-up pass a moment later.
   */
  rememberDraw(canvas, () => drawPieChart(canvas, slices, options));
  if (canvas.clientWidth === 0) return;

  const dpr = window.devicePixelRatio || 1;
  const cssW = Math.max(240, canvas.clientWidth);
  const cssH = canvas.clientHeight || 300;
  canvas.width = Math.round(cssW * dpr);
  canvas.height = Math.round(cssH * dpr);

  const ctx = canvas.getContext("2d");
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, cssW, cssH);
  ctx.font = "12px system-ui";
  ctx.textAlign = "left";
  ctx.textBaseline = "alphabetic";

  const total = slices.reduce((sum, s) => sum + Math.max(0, s.value), 0);
  if (!slices.length || total <= 0) {
    ctx.fillStyle = "#9a9a9a";
    ctx.fillText(options.empty || "Nothing to show yet", 12, 24);
    drawLegend(canvas, []);
    return;
  }

  const shareOf = slice => Math.max(0, slice.value) / total;
  const percentText = share => `${share >= 0.1 ? (share * 100).toFixed(0) : (share * 100).toFixed(1)}%`;

  /*
   * The circle and its list are centred as one block.
   *
   * Sizing the circle against half the canvas and starting at the left edge
   * leaves the whole right side empty on a wide panel, which reads as a chart
   * that failed to fill its space.
   */
  const radius = Math.max(40, Math.min(cssW / 3, cssH) / 2 - 10);
  const labelGap = 20;

  /*
   * A second measurement of the same whole, drawn inside the first.
   *
   * Used where two partitions overlap and cannot honestly share one set of
   * wedges - file types and watched categories count the same log file twice.
   * As two concentric rings against the same total, each ring is internally
   * correct and the eye can still compare a category against the disk it sits
   * on, which is the actual question.
   */
  const inner = (options.inner || []).filter(slice => slice.value > 0);
  const hole = inner.length ? radius * 0.56 : 0;

  /*
   * A slice writes inside itself only if the text actually fits.
   *
   * A share threshold alone is a guess about pixels: at 8% two neighbouring
   * wedges are each narrower than the words placed in them, and the labels
   * collide across the boundary. The wedge's width at the text's radius is a
   * chord - 2r·sin(θ/2) - so it can simply be measured against the widest of
   * the two lines, and the answer holds at any canvas size.
   */
  /*
   * Text goes in the middle of the band it labels.
   *
   * With a hole punched for the inner ring, 0.62r is inside the hole - so every
   * outer label drifted off its own wedge and sat over the ring below it. The
   * band runs from `hole` to `radius`, and its midpoint is where the words
   * belong. Set after `hole` is known, which is why this is not a constant.
   */
  const textRadius = hole > 0 ? (hole + radius) / 2 : radius * 0.62;

  // "both" writes the share and the size, "percent" only the share, "none"
  // leaves both to the list. Degrading through the middle case labels a good
  // many more wedges than an all-or-nothing test at the same width.
  const inside = slices.map(slice => {
    const share = shareOf(slice);
    if (share < 0.04) {
      return "none";
    }

    const sweep = share * Math.PI * 2;
    // The chord at the text's own radius, which is where the words will sit.
    const available = 2 * textRadius * Math.sin(Math.min(sweep, Math.PI) / 2);

    // A ring is only so thick; two lines of text will not fit in a thin band
    // however wide the wedge is.
    const band = hole > 0 ? radius - hole : radius;

    ctx.font = "bold 13px system-ui";
    const percentWidth = ctx.measureText(percentText(share)).width;
    ctx.font = "11px system-ui";
    const sizeWidth = ctx.measureText(humanBytes(slice.value)).width;
    ctx.font = "12px system-ui";

    if (band >= 30 && available >= Math.max(percentWidth, sizeWidth) + 8) return "both";
    if (available >= percentWidth + 8) return "percent";
    return "none";
  });

  // What the list has to say for each slice, given what the wedge already does.
  const listText = slices.map((slice, i) => {
    if (inside[i] === "both") return slice.label;
    if (inside[i] === "percent") return `${slice.label} — ${humanBytes(slice.value)}`;
    return `${slice.label} — ${humanBytes(slice.value)} (${percentText(shareOf(slice))})`;
  });

  const widest = listText.reduce(
    (wide, text) => Math.max(wide, ctx.measureText(text).width + 16),
    0
  );

  const left = Math.max(0, (cssW - (radius * 2 + labelGap + widest)) / 2);
  const cx = left + radius;
  const cy = cssH / 2;

  let angle = -Math.PI / 2;
  slices.forEach((slice, i) => {
    const share = shareOf(slice);
    const sweep = share * Math.PI * 2;
    const color = slice.color || PIE_COLORS[i % PIE_COLORS.length];

    ctx.beginPath();
    if (hole > 0) {
      // A ring rather than a wedge, leaving room for the second measurement
      // in the middle. Same angles, so the two rings stay comparable.
      ctx.arc(cx, cy, radius, angle, angle + sweep);
      ctx.arc(cx, cy, hole, angle + sweep, angle, true);
    } else {
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, radius, angle, angle + sweep);
    }
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.fill();

    /*
     * A hairline between slices, in the panel's own background.
     *
     * Two adjacent colours of similar lightness read as one wedge without it,
     * which is how a pie quietly under-reports the number of things in it.
     */
    ctx.strokeStyle = "#161616";
    ctx.lineWidth = 1.5;
    ctx.stroke();

    if (inside[i] !== "none") {
      const mid = angle + sweep / 2;
      const tx = cx + Math.cos(mid) * textRadius;
      const ty = cy + Math.sin(mid) * textRadius;
      const both = inside[i] === "both";

      ctx.save();
      ctx.textAlign = "center";
      ctx.fillStyle = inkOn(color);
      ctx.font = "bold 13px system-ui";
      ctx.fillText(percentText(share), tx, both ? ty - 1 : ty + 4);
      if (both) {
        ctx.font = "11px system-ui";
        ctx.fillText(humanBytes(slice.value), tx, ty + 13);
      }
      ctx.restore();
    }

    angle += sweep;
  });

  // The list runs down the right in slice order, so the eye can follow it round.
  const labelX = cx + radius + labelGap;
  const lineHeight = 18;
  const startY = cy - ((slices.length + inner.length) * lineHeight) / 2;

  slices.forEach((slice, i) => {
    const y = startY + i * lineHeight;
    if (y < 12 || y > cssH - 4) return;

    const color = slice.color || PIE_COLORS[i % PIE_COLORS.length];
    ctx.fillStyle = color;
    ctx.fillRect(labelX, y - 8, 10, 10);

    ctx.fillStyle = "#ddd";
    ctx.fillText(listText[i], labelX + 16, y + 1);
  });

  if (inner.length) {
    /*
     * The inner ring shares the outer ring's denominator.
     *
     * Rescaling it to its own sum would make three categories covering a tenth
     * of the disk fill the whole circle - which is precisely the exaggeration
     * this panel exists to avoid. What is left over is drawn in the panel's own
     * background so it reads as absence rather than as another category.
     */
    let innerAngle = -Math.PI / 2;
    const named = inner.reduce((sum, slice) => sum + slice.value, 0);
    const parts = [...inner, {
      label: "everything else", value: Math.max(0, total - named), quiet: true,
    }];

    for (const slice of parts) {
      const sweep = (slice.value / total) * Math.PI * 2;
      if (sweep <= 0) continue;

      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.arc(cx, cy, hole - 3, innerAngle, innerAngle + sweep);
      ctx.closePath();
      /*
       * The remainder is drawn, not left out.
       *
       * In the panel background it read as a hole in the page rather than as a
       * measured quantity, and a hole invites the eye to look through it. A
       * muted fill of its own says "this is the part none of these cover",
       * which is what it is.
       */
      ctx.fillStyle = slice.quiet ? "#2b3542" : (slice.color || "#8899aa");
      ctx.fill();

      ctx.strokeStyle = "#161616";
      ctx.lineWidth = 1.5;
      ctx.stroke();

      innerAngle += sweep;
    }

    let y = startY + slices.length * lineHeight + 6;
    for (const slice of parts) {
      if (y > cssH - 4) break;
      ctx.fillStyle = slice.quiet ? "#2b3542" : (slice.color || "#8899aa");
      ctx.fillRect(labelX, y - 8, 10, 10);
      ctx.fillStyle = "#ddd";
      ctx.fillText(
        `${slice.label} — ${humanBytes(slice.value)} `
          + `(${percentText(slice.value / total)})`,
        labelX + 16, y + 1
      );
      y += lineHeight;
    }
  }

  // The canvas carries its own labels, so the shared legend would repeat them.
  drawLegend(canvas, []);
}

/**
 * Slices for the memory pie: the biggest few, then one slice for all the rest.
 *
 * Capped by count as well as by share. A machine running thirty things each
 * taking two percent would otherwise produce thirty wedges, none of which can
 * be told apart or written in, which is a picture of nothing.
 */
function memorySlices(services, measuredTotal, options = {}) {
  const threshold = options.threshold ?? 0.01;
  const most = options.most ?? PIE_MAX_SLICES;

  const rows = services
    .map(s => ({ label: s.name, value: Number(s.pss_bytes) || 0 }))
    .filter(s => s.value > 0)
    .sort((a, b) => b.value - a.value);

  /*
   * The denominator is the measured total, not the sum of these rows.
   *
   * Only the biggest services are recorded, and PSS itself is measured for the
   * biggest processes, so summing the visible rows would quietly rescale every
   * percentage to make the visible part look like the whole machine.
   */
  const total = measuredTotal > 0 ? measuredTotal : rows.reduce((sum, r) => sum + r.value, 0);
  if (total <= 0) return [];

  const big = rows.filter(r => r.value / total >= threshold).slice(0, most);
  const rest = total - big.reduce((sum, r) => sum + r.value, 0);

  const slices = big.slice();
  if (rest > 0) {
    const hidden = rows.length - big.length;
    slices.push({
      label: hidden > 0 ? `Everything else (${count(hidden, "service")})` : "Everything else",
      value: rest,
      // Deliberately grey: it is the part with no answer in it, and colouring
      // it like a service invites reading it as one.
      color: "#4a4a4a",
    });
  }

  return slices;
}

/* ------------------------------------------------------------------ table -- */

/** How many rows of a table are drawn before "show more" appears. */
const TABLE_PAGE = 25;

/*
 * Turns whatever a column renders into something a spreadsheet can hold.
 *
 * The render functions return DOM nodes as often as strings - a struck-through
 * size, a link, a tooltip - and an export that wrote "[object HTMLSpanElement]"
 * would be worse than no export. textContent is what the reader actually saw.
 */
function cellText(column, row) {
  const value = column.render ? column.render(row) : row[column.key] ?? "";
  if (value instanceof Node) return value.textContent ?? "";
  return String(value ?? "");
}

function downloadFile(name, text, type) {
  const blob = new Blob([text], { type });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  // Revoked on the next turn of the loop: revoking immediately races the
  // download in some browsers and silently produces an empty file.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/*
 * Exports what is on screen, not what the server holds.
 *
 * Deliberately: the table has already had filters, a range and a sort applied,
 * and an export that quietly returned something else would be a different
 * answer wearing the same name.
 */
function exportRows(target, rows, columns, format) {
  const headers = columns.map(c => c.label).filter(Boolean);
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  const name = `hostscope-${target}-${stamp}.${format}`;

  if (format === "json") {
    const out = rows.map(row => {
      const item = {};
      for (const column of columns) {
        if (!column.label) continue;
        item[column.label] = cellText(column, row);
      }
      return item;
    });
    downloadFile(name, JSON.stringify(out, null, 2), "application/json");
    return;
  }

  const quote = value => `"${String(value).replace(/"/g, '""')}"`;
  const lines = [headers.map(quote).join(",")];
  for (const row of rows) {
    lines.push(columns.filter(c => c.label).map(c => quote(cellText(c, row))).join(","));
  }
  // A BOM, so Excel opens UTF-8 as UTF-8 rather than as mojibake.
  downloadFile(name, "\uFEFF" + lines.join("\r\n"), "text/csv;charset=utf-8");
}

/*
 * How far each table has been unfolded, by the id it renders into.
 *
 * Outside renderTable because renderTable is called afresh every poll: state
 * kept inside it lives exactly as long as the table it is meant to outlive.
 */
const TABLE_SHOWN = new Map();

/*
 * What each table last drew, so an unchanged poll can leave it alone.
 *
 * Rebuilding a table that has not changed destroys the reader's text selection,
 * scroll position inside it and any focus in it - every few seconds, for as
 * long as they sit on the page. Since the rows are plain data from the server,
 * "has it changed" is a comparison rather than a guess.
 */
const TABLE_DREW = new Map();

function renderTable(target, rows, columns, onRowClick, rowClass) {
  const el = $(target);
  if (!rows || !rows.length) {
    if (TABLE_DREW.get(target) !== "empty") {
      TABLE_DREW.set(target, "empty");
      el.innerHTML = '<p class="muted">Nothing to show.</p>';
    }
    return;
  }

  /*
   * The signature covers the data and the shape, not the rendered nodes.
   *
   * The column list is part of it because the same target is reused with
   * different columns - the offender table changes its columns with the view -
   * and identical rows under different headings are a different table.
   */
  /*
   * The data and the shape, and deliberately not how far it is unfolded.
   *
   * Folding the row count in seemed right and was wrong: the count is set by
   * draw() *after* the signature is taken, so the first render stored 0 and
   * every render after it computed 25 - a guaranteed mismatch that made the
   * comparison useless without ever failing visibly. Unfolding further sets
   * TABLE_DREW to null explicitly, which is the honest way to say "this one
   * really has changed".
   */
  const signature = JSON.stringify([rows, columns.map(c => c.label)]);
  if (TABLE_DREW.get(target) === signature && el.querySelector("table")) {
    return;
  }
  TABLE_DREW.set(target, signature);

  const tools = document.createElement("div");
  tools.className = "table-tools";

  const counter = document.createElement("span");
  tools.appendChild(counter);

  const spacer = document.createElement("span");
  spacer.className = "spacer";
  tools.appendChild(spacer);

  for (const format of ["csv", "json"]) {
    const button = document.createElement("button");
    button.className = "link";
    button.textContent = format.toUpperCase();
    button.title = `Download these ${rows.length} rows as ${format.toUpperCase()}`;
    button.addEventListener("click", () => exportRows(target, rows, columns, format));
    tools.appendChild(button);
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
  let shown = 0;

  const addRow = row => {
    const tr = body.insertRow();
    // A row can say something about itself - read or unread, healthy or not -
    // without that being a column of its own.
    if (rowClass) tr.className = rowClass(row);
    if (onRowClick) {
      tr.classList.add("clickable");
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
  };

  const more = document.createElement("button");
  more.className = "table-more";

  const draw = () => {
    const next = rows.slice(shown, shown + TABLE_PAGE);
    next.forEach(addRow);
    shown += next.length;
    // Remembered against the table's id, so the next poll can put it back.
    TABLE_SHOWN.set(target, shown);

    counter.textContent = shown >= rows.length
      ? count(rows.length, "row")
      : `${shown} of ${count(rows.length, "row")}`;

    more.hidden = shown >= rows.length;
    more.textContent = `Show ${Math.min(TABLE_PAGE, rows.length - shown)} more`;
  };

  /*
   * However far it was unfolded before, again.
   *
   * The poll rebuilds every table from scratch, which threw away how many rows
   * were on screen - so "Show 25 more" expanded and then collapsed a second
   * later when the next refresh landed. Restoring the count rather than the
   * rows keeps it correct when the data underneath has changed.
   */
  const wanted = Math.min(TABLE_SHOWN.get(target) || 0, rows.length);
  do { draw(); } while (shown < wanted && shown < rows.length);

  more.addEventListener("click", () => {
    draw();
    /*
     * Only a click invalidates the signature.
     *
     * Doing it inside draw() meant every render - including the first - wiped
     * the record of what had just been drawn, so the "nothing changed" test
     * could never once succeed and every poll rebuilt every table.
     */
    TABLE_DREW.set(target, null);
  });

  el.replaceChildren(tools, table, more);
}

/**
 * The Scan now button, and what it says about a scan in flight.
 *
 * A scan takes minutes, so the button has to show that something is happening -
 * otherwise the only feedback is a table that changes some time later, and
 * people press it again.
 */
function syncScanButton(overview) {
  const button = $("scanNow");
  if (!state.signedIn) {
    button.hidden = true;
    return;
  }
  button.hidden = false;

  const running = overview.scan_running;
  button.disabled = Boolean(running);
  button.textContent = running
    ? `Scanning\u2026 (${humanDuration(Date.now() / 1000 - running.started_at)})`
    : "Scan now";
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

/*
 * The parts every view needs, whichever one is on screen.
 *
 * Session state drives the sign-in button, the alert count drives the
 * navigation badges, and both are visible from everywhere - so they refresh
 * with the poll regardless of which view asked for it. Everything else belongs
 * to a view and is fetched only when that view is showing.
 */
async function refreshShared() {
  const overview = await api("overview");

  state.signedIn = overview.signed_in;
  state.csrf = overview.csrf;
  state.deletable = overview.deletable || [];
  state.deletableScope = overview.deletable_scope || "account";
  state.totals = overview.totals || {};
  state.emptied = overview.emptied || {};
  state.viewable = overview.viewable || [];
  state.categories = overview.categories ?? [];

  const wasScanning = state.scanRunning;
  state.scanRunning = Boolean(overview.scan_running);

  applySignedInChrome();
  syncCategoryOptions();
  syncScanButton(overview);

  state.overview = overview;
  state.lastReadingAt = overview.vitals ? Number(overview.vitals.taken_at) : null;
  renderLastSeen();

  if (wasScanning && !state.scanRunning) {
    notice("Scan finished.", true);
    // A finished scan changes what Storage shows, wherever you are standing.
    state.rendered.delete("storage");
    if (state.view === "storage") await VIEWS.storage.refresh();
  }

  await refreshBadges();
}

/*
 * The counts behind the navigation badges.
 *
 * Deliberately small queries. The whole point of one view at a time is not
 * paying for the other seven, and a badge that cost a full panel refresh would
 * put all of it back.
 */
async function refreshBadges() {
  try {
    const alerts = await api("alerts", { limit: 200 });
    alertState.rows = alerts.rows;

    /*
     * Unread, not "raised in the last day".
     *
     * A badge counting the last day counts the same alert every time you look
     * at it, so the only way to clear it is to wait. That teaches people to
     * ignore the number, which is the one thing a badge must not do.
     */
    const unread = alerts.rows.filter(r => !r.acknowledged_at);
    const critical = unread.filter(r => r.severity === "critical").length;

    setBadge("alerts", unread.length, critical ? "crit" : "warn");
    renderAlertBanner(unread);
  } catch {
    setBadge("alerts", 0);
  }

  if (!state.signedIn) {
    setBadge("system", 0);
    return;
  }

  try {
    const problems = await api("problems", { limit: 1 });
    setBadge("system", Number(problems.total || 0), "warn");
  } catch {
    setBadge("system", 0);
  }
}

/*
 * The overview: cards and the vitals charts.
 *
 * Uptime is fetched alongside rather than after, because the charts need its
 * restart marks at the moment they draw. Fetched later, the marks were simply
 * absent on load and appeared at some subsequent refresh, which looks exactly
 * like the feature not working.
 */
async function refreshVitals() {
  const to = state.frozenAt || Math.floor(Date.now() / 1000);
  const from = to - state.range;

  const [vitals, uptime] = await Promise.all([
    api("vitals", { from, to, limit: 900 }),
    api("uptime", { from, to }),
  ]);

  const overview = state.overview || await api("overview");
  overview.trend = vitals.trend;
  state.limits = overview.limits;
  renderGauges(overview);
  renderLimits(overview.limits);
  renderCards(overview, vitals.rows);

  state.uptime = uptime;
  // The analysis from the vitals payload covers exactly the rows returned, so
  // the shading and the line can never describe different windows.
  state.uptime.analysis = vitals.uptime;
  state.restarts = restartMarks(uptime, from, to);

  renderUptime();
  showCompactionNote(vitals);
  renderVitalCharts(vitals.rows, vitals.uptime);
}

/** The category chart, which belongs to Storage rather than to the overview. */
async function refreshScans() {
  const to = state.frozenAt || Math.floor(Date.now() / 1000);
  const from = to - state.range;

  const scans = await api("scans", { from, to });
  state.scans = scans;

  syncScanButton(scans);
  renderScanFacts(scans);
  renderCategoryChart(scans.rows, scans.categories ?? state.categories);
  renderFileTypes(scans);
  renderTypeGrowth(scans);
}

/** Kept so that anything still calling it refreshes the view on screen. */
async function refreshAll() {
  await refreshCurrent();
}

/*
 * What the last scan cost and what it found.
 *
 * The button on its own says nothing about whether pressing it is worth it. A
 * scan that takes four minutes and last ran twenty minutes ago is one you
 * should probably not start again, and only these figures can say so.
 */
function renderScanFacts(scans) {
  const last = scans.last_scan;
  const holder = $("scanFacts");

  if (!last) {
    holder.replaceChildren();
    $("scanNote").textContent = scans.scan_running
      ? "The first scan is running now."
      : "No completed scan yet.";
    return;
  }

  const cards = [
    ["Last scan", humanTime(last.finished_at || last.started_at),
      last.duration_ms ? `took ${humanDuration(last.duration_ms / 1000)}` : ""],
    ["Seen", humanBytes(last.bytes_seen),
      `${humanCount(last.files_seen)} files in ${humanCount(last.dirs_seen)} directories`],
    ["Scans in this range", String(scans.rows.length),
      scans.rows.length > 1 ? "each point on the charts below" : "not enough to draw a trend"],
  ];

  const frag = document.createDocumentFragment();
  for (const [label, value, sub] of cards) {
    const card = document.createElement("div");
    card.className = "card";
    for (const [cls, text] of [["label", label], ["value", value], ["sub muted", sub]]) {
      const div = document.createElement("div");
      div.className = cls;
      div.textContent = text;
      card.appendChild(div);
    }
    frag.appendChild(card);
  }
  holder.replaceChildren(frag);

  $("scanNote").textContent = last.error
    ? `The last scan reported: ${last.error}`
    : "";
}

/*
 * A colour per type, fixed rather than positional.
 *
 * The order of the slices follows the sizes, so it changes between scans. If
 * the colours followed the order too, "the green one" would be images this week
 * and video the next, and every card would change colour when one of them grew.
 */
const TYPE_COLORS = {
  images: "#4c9aff", video: "#f2616b", audio: "#c07ce0", archives: "#f0a35e",
  web: "#56c98a", documents: "#5ecbd6", code: "#e0c05c", databases: "#8f8ce0",
  logs: "#e08a8a", executables: "#b5895e", other: "#6f7d8c",
};

/*
 * The watched categories, in colours no type uses.
 *
 * Deliberately outside the type palette: on a chart carrying both, a category
 * that happened to borrow the colour of a type would read as the same series.
 */
const CATEGORY_COLORS = {
  trash: "#b06fb0", logs: "#d98f4a", temp: "#4a9fb0", errors: "#d05a5a",
};

/** The fixed colour for a type, wherever it is drawn. */
const typeColor = type => TYPE_COLORS[type] || "#6f7d8c";

function renderFileTypes(scans) {
  const types = scans.file_types ?? [];
  const cards = $("typeCards");

  if (!types.length) {
    /*
     * Nothing measured is not the same as nothing there.
     *
     * The tally starts at the first scan after this was installed, so an
     * account whose newest scan predates it has no rows - and drawing an empty
     * pie would say the disk is empty.
     */
    cards.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: (scans.rows ?? []).length
        ? "The last scan ran before this was measured. The next one will fill it in."
        : "No completed scan in this range.",
    }));
    drawPieChart($("typePie"), [], { empty: "Not measured yet" });
    renderOtherExtensions([]);
    $("typeWhen").textContent = "";
    $("pieNote").textContent = "";
    return;
  }

  const total = types.reduce((sum, t) => sum + Number(t.bytes || 0), 0);
  const files = types.reduce((sum, t) => sum + Number(t.files || 0), 0);

  const frag = document.createDocumentFragment();
  for (const type of types) {
    const bytes = Number(type.bytes || 0);
    const card = document.createElement("div");
    card.className = "card";

    const label = document.createElement("div");
    label.className = "label";
    label.style.setProperty("--dot", typeColor(type.type));
    label.textContent = type.type;
    card.appendChild(label);

    const value = document.createElement("div");
    value.className = "value";
    value.textContent = humanBytes(bytes);
    card.appendChild(value);

    const sub = document.createElement("div");
    sub.className = "sub muted";
    sub.textContent = `${total ? ((bytes / total) * 100).toFixed(1) : "0"}% \u00b7 `
      + count(Number(type.files || 0), "file");
    card.appendChild(sub);

    frag.appendChild(card);
  }
  cards.replaceChildren(frag);

  renderTypePie(scans, total);
  renderOtherExtensions(scans.other_extensions ?? []);

  const scan = (scans.rows ?? []).find(row => row.id === scans.file_types_scan_id);
  $("typeWhen").textContent = scan
    ? `${humanBytes(total)} in ${count(files, "file")}, measured ${humanTime(scan.finished_at || scan.started_at)}`
    : `${humanBytes(total)} in ${count(files, "file")}`;
}

/*
 * The pie, optionally with the watched categories beside the types.
 *
 * These are two different partitions of the same disk and they overlap: a
 * `.log` inside a log directory is counted once under `logs` the type and again
 * under `logs` the category. Drawn as one pie that would be nonsense - the
 * slices would sum past the total. Drawn as two rings on one canvas they answer
 * the question actually being asked, which is "has one of the watched things
 * got out of hand compared with everything else".
 *
 * So the categories go in as slices measured against the same whole, and the
 * note says plainly that they are a second view of the same bytes rather than
 * extra ones.
 */
function renderTypePie(scans, total) {
  const withCategories = $("pieShowCategories").checked;
  const types = scans.file_types ?? [];

  const slices = types.map(t => ({
    label: t.type,
    value: Number(t.bytes || 0),
    color: typeColor(t.type),
  }));

  const latest = (scans.rows ?? []).find(r => r.id === scans.file_types_scan_id)
    || (scans.rows ?? [])[scans.rows.length - 1];

  const categories = [];
  if (withCategories && latest) {
    for (const name of scans.categories ?? []) {
      const bytes = Number(latest[`${name}_bytes`] || 0);
      if (bytes > 0) categories.push({ name, bytes });
    }
  }

  if (categories.length) {
    drawPieChart($("typePie"), slices, {
      empty: "Not measured yet",
      inner: categories.map(c => ({
        label: `${c.name} (watched)`,
        value: c.bytes,
        color: CATEGORY_COLORS[c.name] || "#8899aa",
      })),
    });

    $("pieNote").textContent =
      "Outer ring: every file by type, each counted once, so it is the whole "
      + "account. Inner ring: the categories the alert rules watch, against that "
      + "same total \u2014 the dark remainder is everything they do not cover. The "
      + "two rings measure some of the same bytes twice, since a log file is in "
      + "both, so read each ring against the whole rather than against the other.";
    return;
  }

  drawPieChart($("typePie"), slices, { empty: "Not measured yet" });
  $("pieNote").textContent =
    "Every file counted once, so the slices are the whole account.";
}

/*
 * What fell into `other`, as a table rather than more slices.
 *
 * Forty extensions is forty wedges nobody can tell apart, and the useful form
 * is sortable and readable: name, size, count, and what share of the account it
 * is. The point is to be able to say "so *that* is what .bkup is doing here".
 */
function renderOtherExtensions(rows) {
  if (!rows.length) {
    $("otherExtensions").innerHTML =
      '<p class="muted">Nothing landed in <code>other</code>, or the last scan '
      + 'ran before this was measured.</p>';
    return;
  }

  const total = rows.reduce((sum, r) => sum + Number(r.bytes || 0), 0);

  renderTable("otherExtensions", rows, [
    {
      label: "Extension",
      className: "name",
      render: row => row.extension === "(none)" ? "(no extension)" : `.${row.extension}`,
    },
    { label: "Size", className: "size", render: row => humanBytes(row.bytes) },
    { label: "Files", className: "size", render: row => humanCount(row.files) },
    {
      label: "Of other",
      className: "size",
      render: row => total ? `${((Number(row.bytes) / total) * 100).toFixed(1)}%` : "\u2014",
    },
  ]);
}

/*
 * Each type over time.
 *
 * Total size answers "what is growing"; change per scan answers "what grew
 * *this hour*", which a total hides completely - a type sitting at 40 GiB and
 * one climbing through 40 GiB draw the same flat-looking line at that scale.
 */
function renderTypeGrowth(scans) {
  const history = scans.file_type_history ?? [];
  const canvas = $("typeGrowthChart");

  if (!history.length) {
    drawLineChart(canvas, [], [], { empty: "No completed scans in this range yet" });
    return;
  }

  const mode = $("typeGrowthMode").value;

  // Long rows into one row per scan, which is what the chart draws from.
  const byScan = new Map();
  const seen = new Set();
  for (const row of history) {
    const at = Number(row.started_at);
    if (!byScan.has(at)) byScan.set(at, { started_at: at });
    byScan.get(at)[row.type] = Number(row.bytes);
    seen.add(row.type);
  }

  let rows = [...byScan.values()].sort((a, b) => a.started_at - b.started_at);
  const types = [...seen].sort();

  if (mode === "change") {
    const changes = [];
    for (let i = 1; i < rows.length; i++) {
      const row = { started_at: rows[i].started_at };
      for (const type of types) {
        const before = rows[i - 1][type];
        const after = rows[i][type];
        // A type that did not exist in one of the two scans has no change to
        // report - not a change of its whole size from zero.
        row[type] = before == null || after == null ? null : after - before;
      }
      changes.push(row);
    }
    rows = changes;
  }

  drawLineChart(
    canvas,
    rows,
    types.map(type => ({ key: type, label: type, color: typeColor(type) })),
    {
      xKey: "started_at",
      axis: bytesAxis,
      zeroBased: mode !== "change",
      empty: mode === "change"
        ? "Two completed scans are needed before there is a change to draw"
        : "No completed scans in this range yet",
    }
  );
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
/**
 * Disk and inodes over time, in whichever of three readings is wanted.
 *
 * Absolute answers "how much is left", percent answers "how close to the edge",
 * and change answers "what is actually happening" - which the other two hide.
 * Fifty megabytes an hour is invisible on an axis running to a terabyte and
 * obvious the moment it is drawn as a difference, and only the difference shows
 * the sign: whether a directory is filling or a cleanup is working.
 */
function renderRoomChart(rows) {
  const mode = state.units;

  /*
   * How long a bar of "change" covers.
   *
   * Per reading is what you want over an hour and useless over a month: at a
   * reading a minute, thirty days is forty thousand points drawn into eight
   * hundred pixels, and what reaches the screen is noise. Totalling the change
   * over a longer bucket keeps the same information at a scale that fits.
   *
   * Summed, not averaged. The total bytes lost in an hour is a real quantity
   * you can reason about; the mean of the steps inside it is a smaller number
   * that means nothing on its own. And an average would not help with spikes
   * anyway - the mean is precisely the statistic one outlier moves furthest.
   * Spikes are handled by trimming the axis and by the median-based trend.
   */
  const bucket = state.units === "delta" ? deltaBucket(state.range) : 0;

  const prepared = rows.map((row, i) => {
    const previous = rows[i - 1];
    const seconds = previous ? Number(row.taken_at) - Number(previous.taken_at) : 0;

    return {
      ...row,
      disk_free_percent: row.disk_total_bytes
        ? (Number(row.disk_free_bytes) / Number(row.disk_total_bytes)) * 100
        : null,
      inodes_free_percent: row.inodes_total
        ? (Number(row.inodes_free) / Number(row.inodes_total)) * 100
        : null,
      // The first reading has nothing to differ from, so it is a gap rather
      // than a zero - a drawn zero would read as "nothing changed".
      disk_delta: delta(previous?.disk_free_bytes, row.disk_free_bytes, seconds),
      inodes_delta: delta(previous?.inodes_free, row.inodes_free, seconds),
    };
  });

  const shapes = {
    bytes: {
      series: [
        { key: "disk_free_bytes", label: "Disk free" },
        { key: "inodes_free", label: "Inodes free (right)", axis: "right" },
      ],
      options: { axis: bytesAxis, rightAxis: countAxis },
      note: "Disk space and inodes. Either can run out on its own \u2014 a disk with "
        + "free gigabytes and no inodes still refuses to create a file.",
    },
    percent: {
      series: [
        { key: "disk_free_percent", label: "Disk free %" },
        { key: "inodes_free_percent", label: "Inodes free %" },
      ],
      options: { axis: percentAxis, zeroBased: true, max: 100 },
      note: "How close to the edge each one is. Both on one scale, so they can be "
        + "read against each other.",
    },
    delta: {
      series: [
        { key: "disk_delta", label: "Disk change" },
        { key: "inodes_delta", label: "Inode change (right)", axis: "right" },
      ],
      options: { axis: bytesAxis, rightAxis: countAxis, zeroLine: true, trim: 0.02 },
      note: "What changed between one reading and the next. Below the line is space "
        + "being consumed, above it is space coming back \u2014 small, steady losses are "
        + "what a leak looks like, and one deep spike is a file being written.",
    },
  };

  const bucketed = bucket > 0 ? bucketDeltas(prepared, bucket) : prepared;
  const shape = shapes[mode] ?? shapes.bytes;
  let note = shape.note;

  if (mode === "delta" && bucket > 0) {
    const per = bucket === 86400 ? "day"
      : bucket === 3600 ? "hour"
      : `${Math.round(bucket / 3600)} hours`;

    shape.series = shape.series.map(entry => ({
      ...entry,
      label: entry.label.replace("change", `change per ${per}`),
    }));
    note += ` Totalled per ${per} over this range, because a reading a minute is`
      + " more points than there are pixels to draw them in.";
  }

  if (mode === "delta") {
    const biggest = bucketed.reduce((worst, row) => {
      const v = Number(row.disk_delta);
      return Number.isFinite(v) && Math.abs(v) > Math.abs(worst) ? v : worst;
    }, 0);
    if (biggest) {
      note += ` Largest single change: ${humanBytes(biggest)}.`;
    }
  }

  $("roomNote").textContent = note;
  drawLineChart($("roomChart"), mode === "delta" ? bucketed : prepared,
    shape.series, { ...shape.options, outages: outageBands(state.uptime) });
}

/** The change between two readings, or null when there is nothing to compare. */
function delta(before, after, seconds) {
  if (before == null || after == null || seconds <= 0) return null;
  return Number(after) - Number(before);
}

/** How long each bar of change should cover, for the range being shown. */
function deltaBucket(rangeSeconds) {
  if (rangeSeconds <= 21600) return 0;        // up to 6 hours: every reading
  if (rangeSeconds <= 172800) return 3600;    // up to 2 days: per hour
  if (rangeSeconds <= 2678400) return 21600;  // up to a month: per 6 hours
  return 86400;                               // longer: per day
}

/**
 * Adds the changes up within each bucket.
 *
 * Summed rather than averaged: "this hour cost 1.2 GiB" is a quantity, and the
 * mean of the steps that made it up is a smaller number nobody asked about.
 * Buckets are aligned to the clock rather than to the first reading, so the
 * boundaries land on the hour and two ranges of the same data agree.
 */
function bucketDeltas(rows, seconds) {
  const buckets = new Map();

  for (const row of rows) {
    const at = Math.floor(Number(row.taken_at) / seconds) * seconds;
    const into = buckets.get(at) ?? { taken_at: at, disk_delta: null, inodes_delta: null };

    for (const key of ["disk_delta", "inodes_delta"]) {
      const value = row[key];
      if (value == null) continue;
      into[key] = (into[key] ?? 0) + Number(value);
    }

    buckets.set(at, into);
  }

  return [...buckets.values()].sort((a, b) => a.taken_at - b.taken_at);
}

/**
 * CPU and memory, where only one of the two has an absolute form.
 *
 * A percentage is the only way to say "CPU", so switching the page to Absolute
 * cannot do anything to that line. Memory has both, and the byte figure is the
 * one worth having: "83%" is a number you have to already know the machine to
 * act on, and "13.3 GiB of 16 GiB" is not. So in Absolute the memory series
 * becomes bytes consumed and moves to the right-hand axis, for the same reason
 * inodes sit opposite disk space on the chart above - two quantities in
 * different units forced onto one scale leave whichever is smaller as a flat
 * line along the bottom.
 *
 * Consumed rather than available, because that is the direction the question
 * runs: memory is *used up* by something, and a chart that climbs when things
 * get worse needs no explaining. It is derived here rather than stored, since
 * the collector already keeps total and available and a third column would be
 * a way for the three to disagree.
 */
function renderCpuChart(rows) {
  const absolute = state.units === "bytes";

  const prepared = absolute
    ? rows.map(row => ({
        ...row,
        mem_used_bytes: row.mem_total_bytes == null || row.mem_available_bytes == null
          ? null
          : Number(row.mem_total_bytes) - Number(row.mem_available_bytes),
      }))
    : rows;

  const series = [{ key: "cpu_percent", label: "CPU %" }];
  series.push(absolute
    ? { key: "mem_used_bytes", label: "Memory used (right)", axis: "right" }
    : { key: "mem_used_percent", label: "Memory %" });

  drawLineChart($("cpuChart"), prepared, series, {
    outages: outageBands(state.uptime),
    axis: percentAxis,
    rightAxis: absolute ? bytesAxis : undefined,
    zeroBased: true,
    // Caps the percentage axis. Harmless on the byte axis beside it, which is
    // already orders of magnitude past a hundred.
    max: 100,
  });

  // The last reading that actually had a memory figure, so the note can say how
  // much of the machine that is. A host with no /proc/meminfo has none at all.
  const latest = [...prepared].reverse().find(row => row.mem_total_bytes != null);

  /*
   * "Share of the machine" was the wrong words, and they mattered.
   *
   * Inside a cage this is the share of what the account is *allowed*, not of
   * the server, and a chart at 95% next to an idle machine has been the single
   * most misleading thing on this page. The limits panel carries the
   * comparison; the note only has to stop claiming the wrong denominator.
   */
  const cap = state.limits?.available ? " See what the host allows, above." : "";
  $("cpuNote").textContent = absolute && latest
    ? `Share of what this account may use, since the previous reading. Memory is `
      + `bytes consumed on the right, out of ${humanBytes(latest.mem_total_bytes)} `
      + `available to this account.${cap}`
    : `Share of what this account may use, since the previous reading.${cap}`;
}

/**
 * Disk I/O, with the account's own traffic shown against the device's.
 *
 * Two lines that answer two different questions, and the gap between them is
 * the useful part. Inside CageFS the device figures are the *host's* disk -
 * every account on the machine together - so a busy line there says the disk is
 * working, never that this account is the reason. The own-traffic lines are
 * summed from the processes this user can see, which inside a cage is exactly
 * its own, and that is the number worth acting on.
 *
 * Utilisation is drawn on the right in percent, because bytes per second and a
 * percentage share no scale, and because a disk can move very little data and
 * still be saturated if the requests are small and scattered.
 */
function renderIoChart(rows) {
  const has = key => rows.some(r => r[key] != null);

  const series = [];
  if (has("own_read_rate")) series.push({ key: "own_read_rate", label: "This account, read" });
  if (has("own_write_rate")) series.push({ key: "own_write_rate", label: "This account, written" });
  if (has("io_read_rate")) series.push({ key: "io_read_rate", label: "Device read" });
  if (has("io_write_rate")) series.push({ key: "io_write_rate", label: "Device written" });
  if (has("io_util_percent")) {
    series.push({ key: "io_util_percent", label: "Device busy % (right)", axis: "right" });
  }

  drawLineChart($("ioChart"), rows, series, {
    outages: outageBands(state.uptime),
    axis: rateAxis,
    rightAxis: percentAxis,
    zeroBased: true,
    empty: series.length
      ? "Nothing collected for this range yet"
      : "No disk I/O readings — neither /proc/diskstats nor /proc/<pid>/io could be read",
  });

  const latest = [...rows].reverse().find(r => r.io_util_percent != null || r.own_read_rate != null);
  const parts = ["Bytes actually reaching a disk, not bytes served from cache."];

  /*
   * Which halves are here and which are not, said plainly.
   *
   * On a caged account the device lines are simply absent, and a chart with two
   * lines where the text describes four leaves the reader wondering whether
   * something is broken. Nothing is: /proc/diskstats is filtered out, and the
   * host collector has it.
   */
  if (!has("io_read_rate")) {
    parts.push("The device lines are missing because /proc/diskstats is not readable "
      + "from this account — see the host panel, which reads it as root.");
  }
  if (!has("own_read_rate")) {
    parts.push("This account's own traffic is missing too: /proc/<pid>/io could not be "
      + "read for any process here.");
  }
  // Only worth saying when those lines are actually on the chart. Explaining
  // what the device lines cover, immediately after saying there are none, reads
  // as a contradiction.
  if (has("io_read_rate") && has("own_read_rate")) {
    parts.push("The device lines cover every account on the machine; only the "
      + "\u201cthis account\u201d lines are yours.");
  }

  if (latest && latest.io_util_percent != null) {
    parts.push(`The device was busy ${Number(latest.io_util_percent).toFixed(0)}% of the `
      + `last interval.`);
  }
  if (latest && latest.io_pressure_full != null) {
    /*
     * Pressure, where the kernel offers it, because utilisation alone is a poor
     * proxy for harm: a disk can sit at 100% pushing background writeback and
     * bother nobody. This measures the opposite thing - time spent *waiting*.
     */
    parts.push(`Tasks were stalled waiting on the disk `
      + `${Number(latest.io_pressure_some).toFixed(1)}% of the time, and nothing at all `
      + `could run for ${Number(latest.io_pressure_full).toFixed(1)}%.`);
  }
  $("ioNote").textContent = parts.join(" ");
}

function renderVitalCharts(rows, uptime) {
  renderRoomChart(rows);
  renderCpuChart(rows);

  renderIoChart(rows);

  /*
   * Load average is not a percentage, which is the single most common thing to
   * get wrong about it, so the chart says what it is in the panel subtitle and
   * draws a line at one-per-core - the level above which work is queueing.
   */
  const bands = outageBands({ analysis: uptime });

  drawLineChart($("loadChart"), rows,
    [
      { key: "load1", label: "1 min" },
      { key: "load5", label: "5 min" },
      { key: "load15", label: "15 min" },
    ],
    { axis: step => ({ scale: 1, unit: "", format: v => v.toFixed(tickPrecision(step)) }),
      zeroBased: true, outages: bands });

  drawLineChart($("netChart"), rows,
    [{ key: "net_rx_rate", label: "In" }, { key: "net_tx_rate", label: "Out" }],
    { axis: rateAxis, zeroBased: true, outages: bands,
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
  const view = $("offSort").value;
  const params = { limit: 30, view };
  if ($("offCategory").value) params.category = $("offCategory").value;
  if ($("offKind").value) params.kind = $("offKind").value;

  const data = await api("offenders", params);
  state.offenders = data;
  $("maskNote").hidden = state.signedIn || !data.rows.length;

  /*
   * What was actually measured, said out loud.
   *
   * Scans are periodic and a window rarely lines up with one, so "the last
   * hour" can easily be six hours of growth. The server works out the real
   * span; printing it is the difference between a number and a misleading one.
   */
  $("offNote").textContent = data.note ?? "";

  const columns = [
    { label: "Path", className: "name", render: row => row.display },
    { label: "Kind", render: row => (row.kind === "dir" ? "directory" : "file") },
    { label: "Category", render: row => row.category ?? "—" },
    { label: "Files", className: "size", render: row => (row.kind === "dir" ? humanCount(row.files) : "") },
    {
      /*
       * Size, and a warning when the size is shared.
       *
       * A file with two directory entries occupies its bytes once. Deleting
       * this path frees nothing while the other link survives, so the number
       * beside it is not the number you would get back - and that is worth
       * knowing before pressing Delete rather than after.
       */
      label: view === "deleted" ? "Size when last seen" : "Size",
      className: "size",
      render: row => {
        const links = Number(row.links ?? 1);
        if (links <= 1) {
          if (!row.deleted_at && !row.gone) return humanBytes(row.bytes);
          // Struck through: the size was real when the scan ran and is not now.
          const was = document.createElement("span");
          was.className = "struck-through";
          was.textContent = humanBytes(row.bytes);
          return was;
        }

        const box = document.createElement("span");
        box.appendChild(document.createTextNode(humanBytes(row.bytes)));
        const note = document.createElement("span");
        note.className = "muted small";
        note.textContent = ` · ${links} links`;
        note.title = `This file has ${links} names. Removing one frees nothing `
          + `until the last one goes.`;
        box.appendChild(note);
        return box;
      },
    },
  ];

  if (view === "deleted") {
    /*
     * What happened to it, which the scan list alone cannot say.
     *
     * A scan records only the largest paths, so a file missing from the latest
     * one may have been deleted or may just have been pushed off the list by
     * something else growing. The server settles it by asking the filesystem;
     * this reports which answer came back rather than flattening both into
     * "deleted".
     */
    columns.push({
      label: "What happened",
      render: row => {
        const span = document.createElement("span");
        if (!row.gone) {
          span.className = "muted";
          span.textContent = "still on disk — no longer among the largest";
          span.title = "The file has not gone anywhere. Something else grew past it, so it "
            + "dropped out of the paths this scan records.";
        } else if (row.deleted_at) {
          span.className = "shrank";
          span.textContent = `deleted from here ${humanTime(row.deleted_at)}`;
          span.title = "Deleted through this dashboard, from the action log.";
        } else {
          span.className = "shrank";
          span.textContent = "gone";
          span.title = "Not on disk any more, and not deleted through this dashboard — so "
            + "something else removed it: log rotation, a cron job, or a person.";
        }
        return span;
      },
    });
  } else if (view === "new") {
    columns.push({
      label: "First seen",
      render: row => {
        const span = document.createElement("span");
        if (row.never_seen_before) {
          span.className = "grew";
          span.textContent = "new";
          span.title = "This path has never been recorded before.";
        } else {
          span.className = "muted";
          span.textContent = "back in the list";
          span.title = "Recorded before, absent from the previous scan, and back now — it "
            + "grew past the size that gets a path recorded rather than being created.";
        }
        return span;
      },
    });
  } else {
    columns.push({
      label: "Change",
      className: "size",
      render: row => growthCell(Number(row.growth || 0)),
    });
  }

  /*
   * The last line, for the rows that have one.
   *
   * "Is it still throwing the same error" is answered by one line and does not
   * need the viewer opened - which for a two-gigabyte log is the difference
   * between a glance and a decision.
   */
  columns.push({ label: "Last line", className: "cmd", render: row => lastLineCell(row) });

  // A file that is gone has nothing to press. The deleted view is a report.
  if (view !== "deleted") {
    columns.push({ label: "", className: "size", render: row => rowButtons(row) });

    /*
     * A picker, for clearing several things in one go.
     *
     * Put first so the checkboxes line up down the left where the eye expects
     * them, and only where deleting is possible at all - a column of boxes that
     * arm a button nobody may press is furniture.
     */
    if (state.signedIn) {
      columns.unshift({ label: "", render: row => offenderPicker(row) });
    }
  }

  renderTable("offenders", data.rows, columns, row => showHistory(row.path_id));
  syncOffenderTools(data.rows);
  fetchLastLines(data.rows);
}

/*
 * A change in size, with which way it went shown as well as said.
 *
 * The arrow is the direction and the colour is the judgement, and on a disk
 * monitor those point opposite ways: a file that gained four gigabytes went
 * *up*, and that is the bad one. Colouring growth green because green means up
 * elsewhere would be reading a share price, not a disk.
 */
function growthCell(growth) {
  const span = document.createElement("span");

  if (!growth) {
    span.className = "muted";
    span.textContent = "\u2014";
    span.title = "No measurable change since the previous scan.";
    return span;
  }

  const up = growth > 0;
  span.className = up ? "grew" : "shrank";
  span.textContent = `${up ? "\u25b2" : "\u25bc"} ${humanBytes(Math.abs(growth))}`;
  span.title = up
    ? `Grew by ${humanBytes(growth)} since the previous scan \u2014 this is taking space.`
    : `Shrank by ${humanBytes(-growth)} since the previous scan \u2014 this gave space back.`;
  return span;
}

/*
 * The most recent line of every readable log in the table.
 *
 * One request for all of them rather than one each, and only for the rows the
 * server has already said are readable - asking about the other forty would be
 * forty refusals to no purpose. The result is cached by path so paging through
 * a table does not re-read the same files every time the poll comes round.
 */
const lastLines = new Map();

async function fetchLastLines(rows) {
  const wanted = rows
    .filter(r => r.viewable && r.path_id && !lastLines.has(r.path_id))
    .map(r => r.path_id);

  if (!wanted.length) {
    paintLastLines();
    return;
  }

  try {
    const data = await api("last-lines", { path_ids: wanted.join(",") });
    for (const row of data.rows) {
      lastLines.set(row.path_id, row);
    }
  } catch {
    // Not worth a notice: the table is complete without this, and the column
    // simply stays empty rather than the page reporting a failure nobody asked
    // for.
    for (const id of wanted) lastLines.set(id, { line: null, error: null });
  }

  paintLastLines();
}

/*
 * Written into the cells after the fact, rather than by re-rendering.
 *
 * Re-rendering the table when the lines arrive would undo the reader's
 * selection and scroll - the thing this session has been fixing everywhere
 * else. The cells are placeholders that get filled.
 */
function paintLastLines() {
  for (const cell of document.querySelectorAll("[data-lastline]")) {
    const row = lastLines.get(Number(cell.dataset.lastline));
    if (!row) continue;

    if (row.error) {
      cell.className = "lastline muted";
      cell.textContent = row.error;
      cell.title = row.error;
      continue;
    }
    if (!row.line) {
      cell.className = "lastline muted";
      cell.textContent = "empty";
      continue;
    }

    cell.className = "lastline";
    cell.textContent = row.line;
    cell.title = `Line ${row.line_number}: ${row.line}`;
  }
}

function lastLineCell(row) {
  if (!row.viewable || !row.path_id) return "";

  const span = document.createElement("span");
  span.className = "lastline muted";
  span.dataset.lastline = String(row.path_id);

  const known = lastLines.get(row.path_id);
  span.textContent = known ? (known.line ?? known.error ?? "empty") : "\u2026";
  return span;
}

/*
 * Which rows are ticked, by path.
 *
 * By path rather than by position, because the table re-sorts and re-filters
 * under the selection - ticking the third row and then changing the sort order
 * should not silently arm a different file.
 */
const offenderPicked = new Set();

function offenderPicker(row) {
  if (!mayDelete(row)) return "";

  const box = document.createElement("input");
  box.type = "checkbox";
  box.checked = offenderPicked.has(row.path);
  box.setAttribute("aria-label", `Select ${row.display}`);
  box.addEventListener("click", event => event.stopPropagation());
  box.addEventListener("change", () => {
    box.checked ? offenderPicked.add(row.path) : offenderPicked.delete(row.path);
    syncOffenderTools();
  });
  return box;
}

function syncOffenderTools(rows) {
  if (rows) {
    // Anything no longer in the table cannot stay armed behind the button.
    const live = new Set(rows.map(r => r.path));
    for (const path of [...offenderPicked]) {
      if (!live.has(path)) offenderPicked.delete(path);
    }
  }

  const button = $("offenderDelete");
  if (!button) return;

  const picked = offenderPicked.size;
  button.hidden = !state.signedIn;
  button.disabled = !picked;
  button.textContent = picked ? `Delete ${count(picked, "selected item")}` : "Delete selected";
}

/*
 * Removes everything ticked, one request at a time.
 *
 * Sequential rather than parallel: each one is a deletion, the failures matter
 * individually, and firing twenty concurrent writes at a shared host to save a
 * second is a poor trade. Directories go through the recursive action and files
 * through the single-file one, decided per row rather than per batch.
 */
async function deletePicked() {
  const rows = (state.offenders?.rows || []).filter(r => offenderPicked.has(r.path));
  if (!rows.length) return;

  const bytes = rows.reduce((sum, r) => sum + Number(r.bytes || 0), 0);
  const dirs = rows.filter(r => r.kind === "dir").length;

  const question = `Delete ${count(rows.length, "item")} permanently?\n\n`
    + rows.slice(0, 12).map(r => `${r.kind === "dir" ? "[dir] " : ""}${r.display}`).join("\n")
    + (rows.length > 12 ? `\n\u2026and ${rows.length - 12} more` : "")
    + `\n\nThat is ${humanBytes(bytes)}`
    + (dirs ? `, and ${count(dirs, "directory", "directories")} will be emptied recursively` : "")
    + ". This cannot be undone.";

  if (!confirm(question)) return;

  const button = $("offenderDelete");
  button.disabled = true;

  let freed = 0;
  let done = 0;
  const failed = [];

  for (const row of rows) {
    try {
      const result = row.kind === "dir"
        ? await post("delete-dir", { path: row.path })
        : await post("delete-file", { path: row.path });
      freed += Number(result.bytes ?? result.bytes_freed ?? 0);
      done++;
      offenderPicked.delete(row.path);
    } catch (error) {
      failed.push(`${row.display}: ${error.message || error}`);
    }
  }

  notice(
    `Removed ${count(done, "item")}, freeing ${humanBytes(freed)}.`
      + (failed.length ? ` ${count(failed.length, "item")} could not be removed: ${failed[0]}` : ""),
    !failed.length
  );

  await refreshAll();
}

/** The buttons for one row: view a log, delete a file, or neither. */
function rowButtons(row) {
  /*
   * Nothing to offer on a path that is gone.
   *
   * Handled here rather than in each button so there is one marker rather than
   * two, and so a control added later cannot forget: View had the same problem
   * as Delete - it stayed live, and pressing it answered "no such file" from a
   * row the table was still showing.
   *
   * The row itself stays, because the table is what the last scan saw and that
   * remains true. Clicking it still opens the size history, which is a record
   * and does not need the file to exist.
   */
  if (row.kind === "file" && row.deleted_at) {
    const gone = document.createElement("span");
    gone.className = "muted small";
    gone.textContent = "deleted";
    gone.title = `Deleted ${humanTime(row.deleted_at)}. Still listed because the last `
      + `scan found it; the next scan will drop it.`;
    return gone;
  }

  const holder = document.createElement("span");
  holder.style.display = "inline-flex";
  holder.style.gap = "6px";

  /*
   * The server decides whether a row is readable, not the category.
   *
   * A rotated log is still in the logs category and still cannot be shown - it
   * is a gzip stream, and the viewer would print the last few kilobytes of it
   * as text. Only the server can tell, so it says.
   */
  if (row.viewable) {
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
/*
 * Whether the interface should offer to delete this row.
 *
 * The server decides for real - resolveDeletable is the authority and this
 * cannot grant anything it refuses. This only decides whether to draw a button
 * that would work, because a Delete that always answers "not allowed" is worse
 * than no Delete at all.
 */
function mayDelete(row) {
  if (!state.signedIn || row.deleted_at) return false;
  if (state.deletableScope === "account") return true;
  return state.deletable.includes(row.category);
}

function deleteButton(row) {
  if (!mayDelete(row)) {
    return "";
  }

  // The deleted case is handled by rowButtons, which owns the whole cell.
  if (row.deleted_at) {
    return "";
  }

  const button = document.createElement("button");
  button.className = "danger";
  button.textContent = "Delete";
  button.addEventListener("click", async () => {
    const directory = row.kind === "dir";
    const question = directory
      ? `Delete this directory and everything inside it?\n\n${row.display}\n`
        + `${humanBytes(row.bytes)} in ${count(row.files || 0, "file")}\n\nThis cannot be undone.`
      : `Delete this file permanently?\n\n${row.display}\n${humanBytes(row.bytes)}`;
    if (!confirm(question)) return;
    button.disabled = true;
    try {
      const result = directory
        ? await post("delete-dir", { path: row.path })
        : await post("delete-file", { path: row.path });

      if (directory) {
        notice(
          `Removed ${humanBytes(result.bytes)} in ${count(result.files, "file")}`
            + (result.skipped?.length
              ? `. ${count(result.skipped.length, "path")} could not be removed.`
              : "."),
          !result.skipped?.length
        );
        await refreshAll();
        return;
      }
      notice(
        result.bytes_freed > 0
          ? `Deleted ${humanBytes(result.bytes_freed)}.`
          : `Deleted, but nothing was freed: ${count(result.links, "other name")} still `
            + `point at those ${humanBytes(result.bytes)}.`,
        true
      );
      await refreshAll();
    } catch (error) {
      notice(String(error.message || error));
      button.disabled = false;
    }
  });
  return button;
}

/**
 * One card per emptyable category: how much is in it, then the button.
 *
 * A bare "Empty trash" asks for a decision without supplying the fact the
 * decision turns on. Putting the size above the button means the question being
 * answered is "is 1.2 GiB across 4,312 files worth reclaiming", which is a
 * question, rather than "do you want to delete an unknown quantity of things",
 * which is a dare.
 */
function renderEmptyButtons() {
  const holder = $("emptyButtons");
  if (!state.signedIn || !state.deletable.length) {
    holder.replaceChildren();
    return;
  }

  holder.replaceChildren(...state.deletable.map(category => {
    const totals = (state.totals || {})[category] || { bytes: 0, files: 0 };
    const emptiedAt = (state.emptied || {})[category];

    const card = document.createElement("div");
    card.className = "card empty-card";

    const label = document.createElement("div");
    label.className = "label";
    label.textContent = category.charAt(0).toUpperCase() + category.slice(1);
    card.appendChild(label);

    const value = document.createElement("div");
    value.className = "value";
    value.textContent = humanBytes(totals.bytes);
    // Struck through once emptied: the figure was true when the scan ran.
    if (emptiedAt) value.classList.add("struck-through");
    card.appendChild(value);

    const sub = document.createElement("div");
    sub.className = "sub muted";
    sub.textContent = totals.files ? count(totals.files, "file") : "nothing counted here";
    if (emptiedAt) sub.classList.add("struck-through");
    card.appendChild(sub);

    if (emptiedAt) {
      /*
       * Emptied already, so no button - the same reasoning as a deleted row,
       * and the same shape: the figures struck through because they were true
       * when the scan ran, and one line saying what happened. The card stays
       * because the category still exists and will fill up again.
       */
      const done = document.createElement("div");
      done.className = "muted small";
      done.textContent = `emptied ${humanTime(emptiedAt)}`;
      done.title = "The next scan will show what is in it now.";
      card.appendChild(done);
      return card;
    }

    const button = document.createElement("button");
    button.className = "danger small";
    button.textContent = "Empty";
    button.disabled = !totals.bytes && !totals.files;

    button.addEventListener("click", async () => {
      if (!confirm(
        `Delete every file counted as ${category}?\n\n`
        + `${humanBytes(totals.bytes)} across ${count(totals.files, "file")}, `
        + `as of the last scan.\n\nThis cannot be undone.`
      )) return;

      button.disabled = true;
      try {
        const result = await post("empty", { category });
        notice(
          `Emptied ${category}: ${humanCount(result.files_removed)} files, `
          + `${humanBytes(result.bytes_freed)} freed`
          + (result.still_linked
            ? `. ${count(result.still_linked, "file")} had another name, so freed nothing`
            : "")
          + (result.failed ? `, ${result.failed} could not be removed.` : "."),
          true
        );
        await refreshAll();
      } catch (error) {
        notice(String(error.message || error));
        button.disabled = false;
      }
    });

    card.appendChild(button);
    return card;
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
/** Opens a log named by a raw path - the treemap and the host log list. */
function showLogPath(path, quiet = false) {
  if (!path) return Promise.resolve();
  if (path !== state.log.path) {
    state.log = { pathId: null, path, page: 0, needle: $("logSearch").value.trim() };
  }
  return showLog(null, quiet);
}

async function showLog(pathId, quiet = false) {
  if (pathId) {
    if (pathId !== state.log.pathId) {
      state.log = { pathId, path: null, page: 0, needle: $("logSearch").value.trim() };
    }
  } else if (!state.log.path) {
    return;
  }

  const panel = $("logPanel");
  const body = $("logBody");

  let data;
  try {
    data = await api("tail", {
      ...(state.log.pathId ? { path_id: state.log.pathId } : { path: state.log.path }),
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
    row.append(...highlightMatches(line.text, state.log.needle));
    return row;
  }));

  if (!shown) {
    body.textContent = state.log.needle ? "No matching lines here." : "Nothing to show.";
  }

  $("logNewer").disabled = state.log.page === 0;
  $("logOlder").disabled = data.reached_start && shown === 0;

  if (!quiet) panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
}

/*
 * Splits a line around the search term, so the match can be marked.
 *
 * Named for what it highlights. There was a second `highlight` further down
 * that outlines a treemap rectangle, and a function declaration does not care
 * which of the two you meant - the later one simply won for the whole file, so
 * opening a log called the canvas version and died on `ctx.strokeRect`.
 */
function highlightMatches(text, needle) {
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

/* --------------------------------------------------------------- symlinks -- */

/** What each finding means, in the order that matters. */
const SYMLINK_KINDS = {
  loop: ["Loop", "grew"],
  ancestor: ["Endless directory", "grew"],
  outside: ["Leaves the tree", ""],
  broken: ["Broken", "muted"],
};

/**
 * Checks the tree for symlinks that will send something into a loop.
 *
 * On a button rather than on the poll: it is a whole extra walk of the tree,
 * and the answer only changes when somebody makes a link.
 */
async function checkSymlinks() {
  const button = $("symlinkCheck");
  const holder = $("symlinkBody");

  button.disabled = true;
  button.textContent = "Checking\u2026";
  holder.replaceChildren();

  try {
    const data = await api("symlinks", { budget: 15 });

    const summary = document.createElement("p");
    summary.className = "muted small";
    summary.textContent =
      `${humanCount(data.links)} link${data.links === 1 ? "" : "s"} across `
      + `${humanCount(data.dirs)} directories in ${data.seconds}s. `
      + (data.complete
        ? "The whole tree was checked."
        : "Stopped before the end of the tree \u2014 there may be more.");
    holder.appendChild(summary);

    if (!data.problems.length) {
      holder.appendChild(Object.assign(document.createElement("p"), {
        className: "shrank",
        textContent: data.complete
          ? "No symlink loops, dangling links or escapes found."
          : "Nothing wrong in the part that was checked.",
      }));
      return;
    }

    const table = document.createElement("table");
    const head = table.createTHead().insertRow();
    for (const label of ["", "Link", "Points at", "Why it matters"]) {
      const th = document.createElement("th");
      th.textContent = label;
      head.appendChild(th);
    }

    const body = table.createTBody();
    for (const problem of data.problems) {
      const tr = body.insertRow();
      const [label, tone] = SYMLINK_KINDS[problem.kind] ?? [problem.kind, ""];

      const kind = tr.insertCell();
      kind.textContent = label;
      kind.className = tone;

      tr.insertCell().className = "name";
      tr.cells[1].textContent = problem.path;

      tr.insertCell().className = "name";
      tr.cells[2].textContent = problem.target || "—";

      const note = tr.insertCell();
      note.className = "muted small";
      note.textContent = problem.note;
    }

    holder.appendChild(table);
  } catch (error) {
    holder.textContent = String(error.message || error);
  } finally {
    button.disabled = false;
    button.textContent = "Check for symlink loops";
  }
}

/* -------------------------------------------------------------- processes -- */

async function refreshProcesses() {
  const panel = $("processPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  let data;
  try {
    data = await api("processes", {
      by: $("procBy").value,
      limit: 15,
      group: $("procGroup").checked ? 1 : 0,
    });
  } catch (error) {
    $("processBody").textContent = String(error.message || error);
    return;
  }

  if (!data.available) {
    $("processBody").textContent = data.reason || "Cannot read the process list here.";
  } else {
    const grouped = data.grouped;
    const columns = [
      { label: "Process", render: row => row.name },
      { label: "How many", className: "size", render: row => (row.procs > 1 ? row.procs : "") },
      { label: "User", render: row => row.user },
      { label: "CPU", className: "size", render: row => `${row.cpu_percent.toFixed(1)}%` },
      { label: "Memory", className: "size", render: row => humanBytes(row.rss_bytes) },
      { label: "Of RAM", className: "size", render: row => `${row.mem_percent.toFixed(1)}%` },
      { label: "Running for", className: "size", render: row => processAge(row) },
      // The heaviest of the group, which is the one worth going to look at.
      { label: grouped ? "Biggest PID" : "PID", className: "size", render: row => row.pid },
      { label: "Command", className: "cmd", render: row => row.command },
    ];

    if (data.can_stop) {
      columns.push({
        label: "Stop",
        className: "stop-cell",
        render: row => stopButtons(row, grouped),
      });
    }

    renderTable("processBody", data.rows, columns);
    $("procStopNote").textContent = data.can_stop ? "" : (data.stop_reason || "");
  }

}

/**
 * The two buttons, which are two genuinely different acts.
 *
 * Ask sends SIGTERM: the process is told to go and gets to close its files
 * first. Force sends SIGKILL, which it cannot catch, cannot decline and cannot
 * tidy up before - anything it was part-way through writing stays part-way
 * written. They are not two strengths of the same button, so they do not look
 * like one; Force is styled as damage and confirmed in its own words.
 */
function stopButtons(row, grouped) {
  const holder = document.createElement("span");
  holder.className = "stop-buttons";

  const many = grouped && row.procs > 1;

  for (const force of [false, true]) {
    const button = document.createElement("button");
    button.className = force ? "danger small" : "small";
    button.textContent = force ? "Force" : "Ask";

    /*
     * Root's processes, and the account's own plumbing, are dead buttons
     * carrying the reason. Drawing a live one that always refuses is how a
     * control stops being believed.
     */
    if (row.eligible === false) {
      button.disabled = true;
      button.title = row.reason || "This one cannot be stopped from here.";
      holder.appendChild(button);
      continue;
    }

    button.title = force
      ? `SIGKILL — ${many ? "all " + row.procs + " are" : "it is"} killed outright, `
        + "with no chance to finish writing anything"
      : `SIGTERM — ${many ? "each is" : "it is"} asked to exit and can tidy up first`;

    button.addEventListener("click", async () => {
      if (!confirmStop(row, grouped, force)) return;

      button.disabled = true;
      try {
        const result = many
          ? await post("stop-group", { name: row.name, force: force ? "1" : "0" })
          : await post("stop-process", {
              pid: row.pid,
              // What was on screen, so the server can refuse if the number has
              // since been handed to something else. Grouped rows carry the
              // age under one name and ungrouped rows under another; sending
              // the wrong one sends nothing, and silently turns the guard off.
              name: row.name,
              elapsed: row.oldest_seconds ?? row.elapsed_seconds ?? "",
              force: force ? "1" : "0",
            });
        notice(describeStop(result, row, many), true);
      } catch (error) {
        notice(String(error.message || error));
      } finally {
        button.disabled = false;
        refreshProcesses().catch(() => {});
      }
    });

    holder.appendChild(button);
  }

  return holder;
}

/**
 * The confirmation, which says what will actually happen.
 *
 * "Are you sure?" teaches people to click yes. The count, the name and the
 * difference between the two signals are the whole content of the decision, so
 * they are the whole content of the box.
 */
function confirmStop(row, grouped, force) {
  const many = grouped && row.procs > 1;
  const what = many
    ? `all ${count(row.procs, "process", "processes")} named “${row.name}”`
    : `“${row.name}” (pid ${row.pid})`;

  return confirm(
    force
      ? `Force ${what} to stop?\n\nSIGKILL cannot be caught or ignored. `
        + `Anything part-way through being written stays part-way written.`
      : `Ask ${what} to stop?\n\nSIGTERM lets it close its files and exit cleanly. `
        + `It can also ignore this.`
  );
}

/** What came back, in the terms somebody watching a process cares about. */
function describeStop(result, row, many) {
  if (!many) {
    return result.note || (result.stopped ? "Stopped." : "Signalled.");
  }

  const parts = [`${result.stopped} of ${row.procs} stopped`];
  if (result.remaining) parts.push(`${result.remaining} still running`);
  if (result.refused) parts.push(`${result.refused} refused`);
  if (result.failed) parts.push(`${result.failed} failed`);
  return parts.join(", ") + ".";
}

/**
 * How long a group has been up, and whether it is churning.
 *
 * One age for a single process. For a group, the oldest with the newest beside
 * it when they differ sharply - a pool up for forty days whose newest member is
 * nine seconds old is restarting as fast as it dies, which is a different fault
 * from one that has simply been running a long time, and the pair of numbers is
 * the only thing that says so.
 */
function processAge(row) {
  // Grouped rows carry the span of the group; a single process carries its own
  // age under a different name, and the ungrouped table is made of those.
  const oldest = row.oldest_seconds ?? row.elapsed_seconds;
  if (oldest == null) return "—";

  const age = humanDuration(oldest);
  const newest = row.newest_seconds;

  if (row.procs > 1 && newest != null && newest * 8 < oldest && oldest > 600) {
    return `${age} · newest ${humanDuration(newest)}`;
  }
  return age;
}

/**
 * "1 process", "3 processes", "2 files".
 *
 * The plural is a parameter rather than a rule. An earlier version appended
 * "es", which is right for exactly the word it was written for and gives
 * "2 filees" for the next one.
 */
function count(n, singular, plural = `${singular}s`) {
  return `${n} ${n === 1 ? singular : plural}`;
}

/**
 * Shows what a command-line pattern would hit, before it hits anything.
 *
 * The preview is the feature. `pkill -9 -f` fails in exactly one way - the
 * pattern was broader than the person writing it believed - and it fails
 * silently, after the fact, on processes that are already gone. Reading the
 * list first turns that into a decision instead of a discovery.
 */
async function previewPattern() {
  const pattern = $("patternText").value.trim();
  const holder = $("patternBody");

  if (!pattern) {
    holder.replaceChildren();
    return;
  }

  let data;
  try {
    data = await post("match-processes", { pattern });
  } catch (error) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "error small",
      textContent: String(error.message || error),
    }));
    return;
  }

  if (!data.available) {
    holder.textContent = data.reason || "Cannot read the process list here.";
    return;
  }

  const eligible = data.matched.filter(row => row.eligible);
  const blocked = data.matched.filter(row => !row.eligible);

  holder.replaceChildren();

  const summary = document.createElement("p");
  summary.className = data.matched.length ? "small" : "muted small";
  summary.textContent = data.matched.length
    ? `Matches ${data.matched.length} of the ${data.scanned} running processes`
      + (blocked.length
        ? `, ${blocked.length === data.matched.length ? "all" : blocked.length} of which `
          + `${blocked.length === 1 ? "is" : "are"} protected.`
        : ".")
    : `Nothing among the ${data.scanned} running processes matches that.`;
  holder.appendChild(summary);

  if (!data.matched.length) return;

  const table = document.createElement("table");
  const head = table.createTHead().insertRow();
  for (const label of ["PID", "User", "Running for", "CPU", "Command"]) {
    const th = document.createElement("th");
    th.textContent = label;
    if (label !== "Command" && label !== "User") th.className = "size";
    head.appendChild(th);
  }

  const body = table.createTBody();
  for (const row of data.matched) {
    const tr = body.insertRow();
    // Protected matches are shown struck through rather than hidden: a pattern
    // that reaches sshd is a pattern about to be run again with one more
    // character on the end, and that is worth seeing now.
    if (!row.eligible) tr.className = "struck";
    tr.insertCell().textContent = row.pid;
    tr.insertCell().textContent = row.user;
    Object.assign(tr.insertCell(), {
      className: "size",
      textContent: row.elapsed_seconds == null ? "—" : humanDuration(row.elapsed_seconds),
    });
    Object.assign(tr.insertCell(), {
      className: "size",
      textContent: `${row.cpu_percent.toFixed(1)}%`,
    });
    Object.assign(tr.insertCell(), {
      className: "cmd",
      textContent: row.command,
      title: row.eligible ? "" : row.reason,
    });
  }
  holder.appendChild(table);

  if (!eligible.length) {
    holder.appendChild(Object.assign(document.createElement("p"), {
      className: "muted small",
      textContent: "Every match is protected, so there is nothing here to stop.",
    }));
    return;
  }

  const actions = document.createElement("div");
  actions.className = "pattern-actions";

  for (const force of [false, true]) {
    const button = document.createElement("button");
    button.className = force ? "danger" : "";
    button.textContent = `${force ? "Force" : "Ask"} ${count(eligible.length, "process", "processes")} to stop`;

    button.addEventListener("click", async () => {
      const verb = force ? "Force" : "Ask";
      if (!confirm(
        `${verb} ${count(eligible.length, "process", "processes")} to stop?\n\n`
        + `Pattern: ${pattern}\n\n`
        + (force
          ? "SIGKILL cannot be caught or ignored. Anything part-way through "
            + "being written stays part-way written."
          : "SIGTERM lets each one close its files and exit cleanly.")
      )) return;

      button.disabled = true;
      try {
        const result = await post("stop-matching", { pattern, force: force ? "1" : "0" });
        const parts = [`${result.stopped} stopped`];
        if (result.remaining) parts.push(`${result.remaining} still running`);
        if (result.refused) parts.push(`${result.refused} protected`);
        if (result.failed) parts.push(`${result.failed} failed`);
        notice(parts.join(", ") + ".", true);
      } catch (error) {
        notice(String(error.message || error));
      } finally {
        button.disabled = false;
        // Re-run the match so the table reflects what is actually left.
        previewPattern().catch(() => {});
        refreshProcesses().catch(() => {});
      }
    });

    actions.appendChild(button);
  }

  holder.appendChild(actions);
}

/**
 * The moments the machine was struggling, and what was running then.
 *
 * Grouped by instant rather than listed flat, because the useful unit is "at
 * 04:12 the CPU was pinned and these were running" - a flat table of rows makes
 * the reader reassemble that themselves.
 */
async function refreshBusy() {
  const panel = $("busyPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  const data = await api("busy");
  renderBusyMoments(data.moments ?? []);
}

/*
 * What each recorded moment looked like, folded shut.
 *
 * Twenty spikes each with a table of processes is several screens of nothing
 * before you reach the one you came for, so the heading carries enough to
 * choose by - when, how hard, and what was at the top of the list - and the
 * detail waits until it is asked for. `details` rather than a hand-rolled
 * toggle: it opens with the keyboard, it prints open, and find-in-page reaches
 * inside it.
 */
/*
 * Which moments the reader has opened, by the time they happened at.
 *
 * The poll rebuilds this list from scratch, which threw away every open
 * disclosure - so reading one meant racing the next refresh. Keyed on taken_at
 * rather than on position, because a new spike arriving at the top would
 * otherwise shift the open one down and open its neighbour instead.
 */
const busyOpen = new Set();

function renderBusyMoments(moments) {
  const holder = $("processHistory");

  if (!moments.length) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Nothing recorded — the machine has not been busy enough to bother.",
    }));
    return;
  }

  holder.replaceChildren(...moments.map(moment => {
    const section = document.createElement("details");
    section.className = "busy-moment";
    section.open = busyOpen.has(moment.taken_at);
    section.addEventListener("toggle", () => {
      section.open ? busyOpen.add(moment.taken_at) : busyOpen.delete(moment.taken_at);
    });

    const summary = document.createElement("summary");

    const when = document.createElement("span");
    when.className = "busy-when";
    when.textContent = humanTime(moment.taken_at);
    summary.appendChild(when);

    const first = moment.rows[0] || {};
    const trigger = Number(first.trigger_percent ?? 0);

    const level = document.createElement("span");
    level.className = "busy-level";
    // "high cpu 72%" - the reading is the whole reason the moment was kept, so
    // it belongs in the line you decide from rather than inside the fold.
    level.textContent = trigger
      ? `high ${moment.reason} ${trigger.toFixed(0)}%`
      : `high ${moment.reason}`;
    summary.appendChild(level);

    const top = document.createElement("span");
    top.className = "busy-top muted";
    top.textContent = first.name
      ? `${first.name}${moment.rows.length > 1 ? ` and ${count(moment.rows.length - 1, "other")}` : ""}`
      : "nothing measurable";
    summary.appendChild(top);

    for (const alert of moment.alerts || []) {
      const link = document.createElement("button");
      link.className = "link busy-alert";
      link.textContent = `${severityIcon(alert.severity)} ${alert.subject}`;
      link.title = "An alert fired within two minutes of this — open the alerts page";
      link.addEventListener("click", event => {
        // Inside a summary, so the click would otherwise fold the thing open.
        event.preventDefault();
        event.stopPropagation();
        showView("alerts");
      });
      summary.appendChild(link);
    }

    section.appendChild(summary);

    /*
     * What set it off, and whether anything visible was doing it.
     *
     * Deliberately not expressed as a fraction of the trigger. `ps` reports a
     * lifetime average per core, so a busy multi-core machine sums well past
     * 100 and the arithmetic would claim the processes account for more than
     * the whole reading. The useful distinction is coarser and survives the
     * mismatch: either something in the list was working, or nothing was and
     * the cause had already exited.
     */
    if (moment.reason === "cpu" && first.trigger_percent != null) {
      const busiest = Number(first.cpu_percent ?? 0);
      const note = document.createElement("p");
      note.className = "muted small";
      note.textContent = busiest >= 2
        ? `The reading was ${trigger.toFixed(0)}%. These were the busiest processes `
          + `at that moment — ps reports a lifetime average per core, so they will `
          + `not add up to it.`
        : `The reading was ${trigger.toFixed(0)}%, but nothing in the list was using `
          + `measurable CPU: whatever caused it had already finished when the process `
          + `list was taken. A burst rather than steady load.`;
      section.appendChild(note);
    }

    const table = document.createElement("table");
    const head = table.createTHead().insertRow();
    for (const [label, cls] of [["What", ""], ["CPU", "size"], ["Memory", "size"], ["Running for", "size"]]) {
      const th = document.createElement("th");
      th.textContent = label;
      if (cls) th.className = cls;
      head.appendChild(th);
    }

    const body = table.createTBody();
    for (const row of moment.rows) {
      const tr = body.insertRow();
      tr.insertCell().textContent = row.name
        + (row.procs > 1 ? ` (${row.procs} processes)` : "");
      const cpu = tr.insertCell();
      cpu.className = "size";
      cpu.textContent = `${Number(row.cpu_percent).toFixed(1)}%`;
      const rss = tr.insertCell();
      rss.className = "size";
      rss.textContent = humanBytes(row.rss_bytes);
      const age = tr.insertCell();
      age.className = "size muted";
      age.textContent = processAge(row);
    }
    section.appendChild(table);

    return section;
  }));
}

/* ------------------------------------------------------------------- host -- */

/**
 * What root can see and this account cannot.
 *
 * Kept in its own panel rather than folded into the others because it comes
 * from a different database, written by a different user, on a different
 * schedule - and because it is either entirely present or entirely absent.
 * Merging it would leave every other panel having to explain which half of its
 * numbers were missing.
 */
async function refreshHost() {
  /*
   * One request, three pages.
   *
   * Memory, the machine's disks and whether its daemons are alive all come from
   * the same host reading, and splitting them into separate views should not
   * mean fetching that reading three times. Each page has its own body element
   * for the "no host readings" message and its own content wrapper, so whichever
   * one is open shows the state of the collector rather than an empty panel.
   */
  const bodies = ["hostBody", "hostIoBody", "hostDaemonBody", "diskProcsBody", "rootFilesBody"];
  const contents = ["hostMemory", "hostIoContent", "hostDaemonContent"];
  const showAll = hidden => contents.forEach(id => { const el = $(id); if (el) el.hidden = hidden; });
  const sayAll = build => bodies.forEach(id => { const el = $(id); if (el) build(el); });

  for (const id of ["hostBody", "hostIoBody", "hostDaemonBody", "diskProcsBody", "rootFilesBody"]) {
    const el = $(id);
    if (el && !el.childElementCount) el.replaceChildren(spinner("Reading the host collector\u2026"));
  }

  const panel = $("hostPanel");
  if (!state.signedIn) {
    for (const id of ["hostPanel", "hostIoPanel", "hostDaemonPanel", "diskProcsPanel", "rootFilesPanel"]) {
      const el = $(id);
      if (el) el.hidden = true;
    }
    return;
  }
  for (const id of ["hostPanel", "hostIoPanel", "hostDaemonPanel", "diskProcsPanel", "rootFilesPanel"]) {
    const el = $(id);
    if (el) el.hidden = false;
  }

  let data;
  try {
    const to = Math.floor(Date.now() / 1000);
    // The selected metric goes with the request, so a background refresh comes
    // back with the series the user is looking at rather than the default.
    data = await api("host", { from: to - state.range, to, metric: state.hostMetric || "pss" });
  } catch (error) {
    sayAll(el => { el.textContent = String(error.message || error); });
    showAll(true);
    return;
  }

  if (!data.available) {
    /*
     * The instruction, not just the diagnosis.
     *
     * This is the one panel that cannot set itself up: it needs a crontab entry
     * for a user the dashboard is not running as. "No readings yet" leaves
     * somebody to go and find out how, so the line to paste goes here, with
     * this install's real paths already in it.
     */
    const holder = $("hostBody");
    // The same explanation on the other two pages, which are just as blank
    // without the collector and just as much in need of the instruction.
    for (const id of ["hostIoBody", "hostDaemonBody"]) {
      const el = $(id);
      if (el) {
        el.replaceChildren(Object.assign(document.createElement("p"), {
          className: "muted",
          textContent: data.reason || "No host readings.",
        }));
      }
    }
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: data.reason || "No host readings.",
    }));

    if (data.cron) {
      holder.appendChild(Object.assign(document.createElement("p"), {
        className: "muted small",
        textContent: "As root — ssh in or use WHM's Terminal — add this to root's "
          + "crontab with crontab -e:",
      }));
      const pre = document.createElement("pre");
      pre.className = "paste";
      pre.textContent = data.cron;
      holder.appendChild(pre);
      holder.appendChild(Object.assign(document.createElement("p"), {
        className: "muted small",
        textContent: "Then run it once by hand to see what it finds: "
          + data.command,
      }));
    }

    showAll(true);
    return;
  }

  sayAll(el => { el.textContent = ""; });
  showAll(false);

  const s = data.sample;
  const swapUsed = num(s.swap_total) - num(s.swap_free);

  $("hostCards").replaceChildren();
  const swap = data.swap ?? { state: "unknown" };
  const swapPercent = num(s.swap_total) > 0
    ? Math.round((swapUsed / num(s.swap_total)) * 100)
    : null;

  setHostCard("Memory in use", humanBytes(s.used_bytes),
    `of ${humanBytes(s.mem_total)}`);
  /*
   * MemAvailable, not free. The kernel's own estimate of what a new process
   * could actually get, which counts reclaimable cache as available - `free` is
   * the number that makes a healthy machine look full.
   */
  setHostCard("Available", humanBytes(s.mem_available),
    `the kernel's estimate of what is left`);
  setHostCard("Cache and buffers", humanBytes(s.cache_bytes),
    "reclaimable — not a problem");
  setHostCard("Kernel slab", humanBytes(s.slab),
    `${humanBytes(s.sunreclaim)} of it unreclaimable`);
  /*
   * Named for what it is, because "shared" is overloaded here.
   *
   * This is Shmem from /proc/meminfo: tmpfs pages and System V segments, memory
   * the kernel holds on behalf of no particular process. The PSS column in the
   * tables below is a completely unrelated quantity - one page of one process's
   * mapping, divided by however many processes map it. Labelling both "shared"
   * invites the reader to add them together or compare them, and neither is
   * meaningful.
   */
  setHostCard("Kernel shared memory / tmpfs", humanBytes(s.shmem),
    s.devshm_used == null ? "" : `${humanBytes(s.devshm_used)} in /dev/shm`);
  /*
   * Swap is shown large but coloured only under real pressure.
   *
   * A number in warning colours that is usually nothing teaches people to
   * ignore the colour, and this one is usually nothing.
   */
  setHostCard("Swap",
    `${humanBytes(swapUsed)} / ${humanBytes(s.swap_total)}`,
    swapPercent == null ? "" : `${swapPercent}% allocated`,
    swap.state === "pressure" ? "warn" : "",
    "pair");
  /*
   * Named for what it is rather than for what is missing.
   *
   * "Unaccounted" reads as a leak or a broken sum, and it is neither. A few
   * hundred megabytes here is ordinary: vmalloc regions, DMA and driver
   * buffers, hugepages, per-cpu areas the summary lines do not break out, and
   * plain accounting differences between counters sampled at slightly
   * different moments. Linux does not reconcile exactly from a handful of
   * /proc figures and never claimed to. What is worth watching is the number
   * *growing* over days, not the number existing.
   */
  setHostCard("Other kernel/system memory", humanBytes(s.unaccounted),
    "not represented by the categories above");

  $("hostSwapNote").textContent = swapVerdict(swap, swapUsed);

  /*
   * The sentence that stops a wild goose chase.
   *
   * Somebody reading `ps --sort=-rss` adds the column up, gets five gigabytes
   * on a machine using three, and goes looking for the missing two — which were
   * never there. Resident size counts a shared page once per process holding
   * it, so the total is an overcount by construction.
   *
   * Only said when it is true, though. On a quiet machine the sum can genuinely
   * come in under what is in use, and a hardcoded "that is more than the
   * machine has" would then be simply wrong in a panel whose whole job is to be
   * trusted about memory.
   */
  const parts = [
    `${s.proc_count} processes across ${data.users.length} users, `
      + `${s.thread_count} threads.`,
  ];

  const rssSum = num(s.rss_sum_bytes);
  const inUse = num(s.used_bytes);
  parts.push(
    rssSum > inUse
      ? `Their resident sizes add up to ${humanBytes(rssSum)}, more than the `
        + `${humanBytes(inUse)} actually in use — a page shared by ten processes is `
        + `counted ten times, so that total is an overcount and the difference is `
        + `not missing memory.`
      : `Their resident sizes add up to ${humanBytes(rssSum)}. Resident size counts `
        + `shared pages once per process, so it is an upper bound rather than a total.`
  );

  if (num(s.pss_procs) > 0) {
    parts.push(
      `Proportional set size — private memory in full plus a share of whatever is `
      + `shared — comes to ${humanBytes(s.pss_sum_bytes)} across the ${s.pss_procs} biggest`
      + (num(s.pss_procs) < num(s.proc_count) ? ` of them.` : `.`)
    );
  }

  $("hostMemNote").textContent = parts.join(" ");

  drawLineChart($("hostChart"), data.rows, [
    { key: "used_bytes", label: "In use" },
    { key: "cache_bytes", label: "Cache" },
    { key: "slab", label: "Slab" },
    { key: "unaccounted", label: "Other kernel/system" },
  ], { axis: bytesAxis, zeroBased: true, empty: "Only one host reading so far" });

  renderTable("hostUsers", data.users, [
    { label: "User", render: r => r.user },
    { label: "Processes", className: "size", render: r => r.procs },
    { label: "Threads", className: "size", render: r => r.threads },
    { label: "Resident", className: "size", render: r => humanBytes(r.rss_bytes) },
    { label: "Proportional (PSS)", className: "size", render: r => hostPss(r.pss_bytes) },
    { label: "Swapped", className: "size", render: r => humanBytes(r.swap_bytes) },
  ]);

  renderServices(
    data.services ?? [],
    data.service_series ?? [],
    data.service_charted ?? [],
    data.service_metrics ?? {},
    data.service_metric ?? "pss"
  );
  renderMemoryPie(data.services ?? [], s);

  renderTable("hostProcesses", data.processes, [
    { label: "PID", className: "size", render: r => r.pid },
    { label: "User", render: r => r.user },
    { label: "Process", render: r => r.name },
    { label: "Resident", className: "size", render: r => humanBytes(r.rss_bytes) },
    { label: "Proportional (PSS)", className: "size", render: r => hostPss(r.pss_bytes) },
    { label: "Swapped", className: "size", render: r => humanBytes(r.swap_bytes) },
    { label: "Threads", className: "size", render: r => r.threads },
    { label: "Running for", className: "size",
      render: r => (r.elapsed_seconds == null ? "—" : humanDuration(r.elapsed_seconds)) },
    { label: "Command", className: "cmd", render: r => r.command },
  ]);

  /*
   * What the collector could see, which is a different machine from the one the
   * dashboard runs on. The account is inside a cage with a filtered /proc; this
   * line reports the host's view, which is the only one that answers "does this
   * machine have PSI".
   */
  const seen = [];
  if (s.kernel) seen.push(`Kernel ${s.kernel}`);
  if (s.diskstats_available != null) {
    seen.push(`device counters ${Number(s.diskstats_available) ? "readable" : "unreadable"}`);
  }
  if (s.psi_available != null) {
    seen.push(`pressure stall info ${Number(s.psi_available) ? "available" : "unavailable"}`);
  }
  $("hostSeesNote").textContent = seen.length
    ? `As root, the collector sees: ${seen.join(", ")}.`
      // The reason, from the collector rather than from here: this page runs as
      // the account and cannot read /boot/config to find out.
      + (s.psi_reason ? ` ${s.psi_reason}` : "")
    : "";

  renderHostDisks(data.disks ?? [], s, Number(data.sample_count ?? 0));
  refreshHostIoChart();
  renderHostIoProcs(data.io_processes ?? null);
  renderHostDaemons(data.daemons ?? null);

  renderTable("hostSlab", data.slab, [
    { label: "Cache", render: r => r.name },
    { label: "Holding", className: "size", render: r => humanBytes(r.cache_bytes) },
    { label: "Objects", className: "size", render: r => Number(r.objs).toLocaleString() },
    { label: "Each", className: "size", render: r => humanBytes(r.obj_bytes) },
  ]);

  renderHostFiles(data.files ?? [], data.file_totals ?? null);

  $("hostLveWrap").hidden = !data.lve.length;
  if (data.lve.length) {
    renderTable("hostLve", data.lve, [
      { label: "Account", render: r => r.user },
      { label: "CPU faults", className: "size", render: r => r.faults_cpu },
      { label: "Memory faults", className: "size", render: r => r.faults_mem },
      { label: "Process faults", className: "size", render: r => r.faults_procs },
    ]);
  }
}

/**
 * What the swap figure actually means, which is usually "nothing".
 *
 * Linux never pulls pages back out of swap merely because RAM became free
 * again, so a machine that was squeezed last Tuesday still reads as heavily
 * swapped today. Presented bare, that number sends people hunting for a
 * shortage that ended a week ago. Only the combination of swap climbing *and*
 * available memory falling is worth acting on, and only that case gets said in
 * those terms.
 */
function swapVerdict(swap, swapUsed) {
  const perHour = rate => `${humanBytes(Math.abs(rate) * 3600)}/h`;

  switch (swap.state) {
    case "clear":
      return "Nothing is swapped.";

    case "pressure":
      return `Swap is climbing (${perHour(swap.swap_rate)}) while available memory `
        + `falls (${perHour(swap.available_rate)}). Those two together are real `
        + `pressure rather than leftover cold pages — the machine is paying for `
        + `memory with disk. This is the one worth acting on.`;

    case "filling":
      return `Swap is growing (${perHour(swap.swap_rate)}), but available memory is `
        + `holding steady. That is the kernel moving cold pages out of the way, not `
        + `the machine running short.`;

    case "cold":
      return swapUsed > 0
        ? "Old or cold pages left over from an earlier squeeze. Linux does not pull "
          + "pages back out of swap just because RAM frees up, so this figure stays "
          + "where it is until something touches them — a high-water mark, not a "
          + "measure of now. No current shortage: watch for swap rising while "
          + "available memory falls, which is a different thing entirely."
        : "Nothing is swapped.";

    default:
      return "Not enough readings yet to say whether this is left over or still "
        + "growing — that needs about half an hour of history.";
  }
}

/**
 * Watched files, with several names for one file shown as one file.
 *
 * A hard link is not a copy. Apache publishes the same log flat and again under
 * a per-account directory, and both names report 199 MiB because both describe
 * the same 199 MiB. Listed as two rows and added up, that is 398 MiB of disk
 * that was never allocated — and the number is wrong in the direction that
 * makes somebody go looking for space that is already free.
 *
 * So rows are grouped by (device, inode): one row per physical file, with the
 * other names for it underneath. The size is stated once, because it exists
 * once.
 */
function renderHostFiles(rows, totals) {
  const holder = $("hostFiles");

  if (!rows.length) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Nothing over the size threshold.",
    }));
    return;
  }

  // Grouped in order, so the biggest physical file stays first.
  const byInode = new Map();
  for (const row of rows) {
    const key = row.inode ? `${row.device}:${row.inode}` : `path:${row.path}`;
    if (!byInode.has(key)) byInode.set(key, []);
    byInode.get(key).push(row);
  }

  const groups = [...byInode.values()];

  renderTable("hostFiles", groups, [
    {
      label: "Path",
      className: "name",
      render: group => {
        const box = document.createElement("div");
        box.appendChild(Object.assign(document.createElement("div"), {
          textContent: group[0].path,
        }));
        // The other names for the same file, marked as such rather than
        // listed as if they were more files.
        for (const other of group.slice(1)) {
          box.appendChild(Object.assign(document.createElement("div"), {
            className: "muted small",
            textContent: `also at ${other.path}`,
          }));
        }
        return box;
      },
    },
    { label: "Size", className: "size", render: group => humanBytes(group[0].bytes) },
    {
      label: "Names",
      className: "size",
      render: group => {
        const links = Number(group[0].links ?? 1);
        if (group.length === 1 && links <= 1) return "1";
        /*
         * Two counts, because they answer different questions. `group.length`
         * is how many of the names are inside the watch list; `links` is how
         * many exist anywhere on the filesystem. When links is higher, deleting
         * every path shown here still would not free the space.
         */
        return group.length === links
          ? `${group.length} paths, 1 file`
          : `${group.length} here of ${links}, 1 file`;
      },
    },
    { label: "Owner", render: group => group[0].owner ?? "—" },
    { label: "Last written", className: "size", render: group => humanTime(group[0].mtime) },
  ]);

  if (totals) {
    const note = document.createElement("p");
    note.className = "muted small";
    note.textContent = totals.linked > 0
      ? `${humanBytes(totals.bytes)} across ${count(totals.files, "file")}, `
        + `reached by ${totals.paths} paths. The ${totals.linked} extra `
        + `${totals.linked === 1 ? "path is a hard link" : "paths are hard links"} `
        + `to a file already counted, so ${totals.linked === 1 ? "it costs" : "they cost"} `
        + `no additional disk.`
      : `${humanBytes(totals.bytes)} across ${count(totals.files, "file")}.`;
    holder.appendChild(note);
  }
}

/** Per-device throughput, utilisation and queue depth. */
/*
 * Groups a pile of paths by a shared parent.
 *
 * Seventy paths under /usr/share/cagefs-skeleton are one fact, not seventy,
 * and a list that says so is readable where a list that does not is a wall.
 *
 * Grouped on the first three segments, which is a rule of thumb rather than a
 * theory: it puts /usr/share/cagefs-skeleton/* together and leaves
 * /var/log/apache2/domlogs on its own, which is the right answer for both. A
 * cleverer longest-common-prefix would group everything under /usr the moment
 * two unrelated /usr paths appeared.
 */
function groupPaths(paths) {
  const groups = new Map();

  for (const path of paths) {
    const parts = String(path).split("/").filter(Boolean);
    const key = parts.length > 3 ? "/" + parts.slice(0, 3).join("/") : String(path);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(String(path));
  }

  return [...groups.entries()]
    .map(([prefix, members]) => ({ prefix, members }))
    .sort((a, b) => b.members.length - a.members.length || a.prefix.localeCompare(b.prefix));
}

/** Shows a long list of paths in a dialog that scrolls. */
function showPathList(title, paths, note) {
  $("listDialogTitle").textContent = title;
  $("listDialogNote").textContent = note || "";

  const body = $("listDialogBody");
  // The dialog is shared, so whoever opens it sets its own class rather than
  // inheriting whatever the last caller left behind.
  body.className = "scroll-list";
  const frag = document.createDocumentFragment();

  for (const group of groupPaths(paths)) {
    const box = document.createElement("div");
    box.className = "group";

    const head = document.createElement("div");
    head.className = "group-head";
    /*
     * A group of one shows the whole path, not the prefix it was keyed on.
     *
     * The key is the first three segments, so /var/log/apache2/domlogs keys on
     * /var/log/apache2 - and printing that as the heading would display a
     * directory that is not the one mounted. A truncated path presented as a
     * real one is worse than a long list.
     */
    head.textContent = group.members.length > 1
      ? `${group.prefix}/… — ${count(group.members.length, "path")}`
      : group.members[0];
    box.appendChild(head);

    if (group.members.length > 1) {
      const ul = document.createElement("ul");
      for (const path of group.members) {
        const li = document.createElement("li");
        // The shared part is already in the heading; repeating it on every
        // line is what made the original unreadable.
        li.textContent = path.startsWith(group.prefix + "/")
          ? path.slice(group.prefix.length + 1)
          : path;
        ul.appendChild(li);
      }
      box.appendChild(ul);
    }

    frag.appendChild(box);
  }

  body.replaceChildren(frag);
  $("listDialog").showModal();
}

/*
 * The root-side answer to "df says 72 GB and I can only find 4".
 *
 * Laid out as the three questions in the order worth asking them, because the
 * cheapest one ends the investigation surprisingly often: which filesystem,
 * then which directories, then the space that has no directory entry at all.
 */
async function refreshHostDisk() {
  const holder = $("hostDisk");
  if (!holder) return;

  // Whatever goes wrong, it goes wrong on all four pages - each of them is a
  // view of this one reading, and three of them silently showing nothing would
  // be worse than three of them saying why.
  const everywhere = text => {
    for (const id of ["hostDisk", "diskDeleted", "diskDirs"]) {
      const el = $(id);
      if (el) el.replaceChildren(note(text));
    }
    const walk = $("hostWalk");
    if (walk) walk.replaceChildren();
  };

  for (const id of ["hostDisk", "diskDeleted", "diskDirs"]) {
    const el = $(id);
    // Only when there is nothing there yet: replacing a drawn page with a
    // spinner every poll would be worse than the blank it replaces.
    if (el && !el.childElementCount) el.replaceChildren(spinner("Reading the last walk\u2026"));
  }

  let data;
  try {
    data = await api("host-disk");
  } catch (error) {
    everywhere(String(error.message || error));
    return;
  }

  if (!data.available) {
    everywhere(data.reason || "No disk walk recorded.");
    renderWalkButton(data);
    return;
  }

  /*
   * One reading, several pages.
   *
   * The walk produces four separate findings - the gap, the filesystems, the
   * space held by deleted files, the biggest directories - and each was a
   * heading in a page long enough that the last of them was never reached. They
   * are pages now, filled from the one request, so moving between them costs
   * nothing and each one opens on the thing it is named after.
   */
  const into = (id, ...nodes) => {
    const el = $(id);
    if (!el) return;
    el.replaceChildren(...nodes.filter(Boolean));
  };

  const body = document.createDocumentFragment();

  /*
   * The missing figure first, and stated as a quantity.
   *
   * "Some space is unaccounted for" is a feeling. "58.2 GiB is unaccounted
   * for" is a fact somebody can chase, and it is the whole reason this panel
   * exists.
   */
  const gap = data.unaccounted;
  if (gap) {
    const facts = [
      [`${gap.mount} says used`, humanBytes(gap.used_bytes)],
      ["Found by walking", humanBytes(gap.found_bytes) + (gap.complete ? "" : " (walk stopped early)")],
    ];
    if (gap.deleted_bytes > 0) {
      facts.push(["Deleted but still open", humanBytes(gap.deleted_bytes)]);
    }
    facts.push(["Still unaccounted for", humanBytes(gap.missing_bytes)]);
    body.appendChild(section("The gap", factGrid(facts)));

    if (!gap.complete) {
      body.appendChild(note("The walk ran out of time, so \u201cfound\u201d is a lower bound and "
        + "the gap is an upper one. Raise host.walk_seconds and run it again before "
        + "chasing the difference."));
    }
  }

  if (data.mounts.length) {
    const wrap = document.createElement("div");
    wrap.className = "scroll-x";

    const table = document.createElement("table");
    const head = table.createTHead().insertRow();
    ["Mounted at", "Device", "Type", "Used", "Free", "Full"].forEach((label, i) => {
      const th = document.createElement("th");
      th.textContent = label;
      if (i === 0) th.className = "name";
      if (i >= 3) th.className = "size";
      head.appendChild(th);
    });

    const tbody = table.createTBody();
    for (const m of data.mounts) {
      const tr = tbody.insertRow();

      const first = tr.insertCell();
      first.className = "name";
      first.appendChild(document.createTextNode(m.mount));

      /*
       * The other mount points behind a button, not spilled into the cell.
       *
       * CageFS bind-mounts the same filesystem into seventy-odd places under
       * /usr/share/cagefs-skeleton, and printing them inline produced a single
       * unreadable line that pushed every other column off the screen. They
       * are worth keeping - a bind mount is why one device appears once rather
       * than seventy times - but they are reference material, not something to
       * read every time the page loads.
       */
      const others = String(m.also_at || "").split(/\s+/).filter(Boolean);
      if (others.length) {
        const button = document.createElement("button");
        button.className = "link more-link";
        button.textContent = `+${count(others.length, "mount point")}`;
        button.title = "The same filesystem is mounted in these places too. Bind mounts, "
          + "so the space is counted once.";
        button.addEventListener("click", () => showPathList(
          `${m.mount} is also mounted at`,
          others,
          "One filesystem, mounted in several places. The disk holds these bytes once — "
          + "which is why this table shows one row rather than one per mount point."
        ));
        first.appendChild(button);
      }

      [m.device, m.type || "—", humanBytes(m.used_bytes), humanBytes(m.free_bytes),
       `${Number(m.used_percent).toFixed(0)}%`].forEach((value, i) => {
        const td = tr.insertCell();
        if (i >= 2) td.className = "size";
        td.textContent = value;
      });
    }

    wrap.appendChild(table);
    body.appendChild(section("Filesystems", wrap));
  }

  const deleted = document.createDocumentFragment();
  const dirs = document.createDocumentFragment();

  if (data.deleted.length) {
    /*
     * Before the directories, deliberately.
     *
     * This is the one a directory walk can never find, so if there is anything
     * here it is very likely the answer - and somebody scrolling a list of
     * directories will not think to look further down for it.
     */
    deleted.appendChild(section(
      `Deleted, still holding ${humanBytes(data.deleted_bytes)}`,
      (() => {
        const wrap = document.createDocumentFragment();
        wrap.appendChild(note("These files have been unlinked but a process still has them "
          + "open, so the blocks stay allocated. df counts them; du, ls, find and the "
          + "treemap cannot see them. Restarting the process listed frees the space."));
        wrap.appendChild(simpleTable(
          ["Path", "Held by", "PID", "Size"],
          data.deleted.map(d => [
            d.path, d.process + (d.holders > 1 ? ` (\u00d7${d.holders})` : ""),
            String(d.pid), humanBytes(d.bytes),
          ]),
          ["name", null, "size", "size"]
        ));
        return wrap;
      })()
    ));
  } else if (data.deleted_readable) {
    deleted.appendChild(note("None \u2014 no process is holding space in a deleted file."));
  } else {
    deleted.appendChild(note("This needs /proc/<pid>/fd, which only root can read. "
      + "Run the host collector to fill it in."));
  }

  if (data.dirs.length) {
    dirs.appendChild(simpleTable(
      ["Directory", "Size", "Files"],
      data.dirs.map(d => [d.path, humanBytes(d.bytes), humanCount(d.files)]),
      ["name", "size", "size"]
    ));
  } else {
    dirs.appendChild(note("Nothing recorded. Ask for a walk from the Storage page."));
  }

  const when = [`Walked ${humanTime(data.taken_at)} (${ago(data.taken_at)})`];
  if (data.dirs_scanned) when.push(`${humanCount(data.dirs_scanned)} directories in ${data.seconds}s.`);
  if (data.note) when.push(data.note);

  body.appendChild(note(when.join(" \u00b7 ")));

  // The same "when was this measured" line under each page built from it, so
  // no page can quietly show last week's walk as though it were current.
  into("hostDisk", body);
  into("diskDeleted", deleted, note(when.join(" \u00b7 ")));
  into("diskDirs", dirs, note(when.join(" \u00b7 ")));
  renderWalkButton(data);
}

/*
 * The walk request, which lives on the Storage page now.
 *
 * It is the control that produces everything on these four pages, so it belongs
 * where somebody starts rather than at the bottom of one of the four things it
 * feeds.
 */
function renderWalkButton(data) {
  const holder = $("hostWalk");
  if (holder) holder.replaceChildren(walkButton(data));
}

/*
 * Asks root to walk the disk on its next run.
 *
 * The dashboard cannot run anything as root, and the honest interface for that
 * is a button that says what it actually does: it leaves a request, and the
 * collector picks it up within a few minutes. A spinner implying work is
 * happening now would be a lie about who is doing it.
 */
function walkButton(data) {
  const box = document.createElement("p");

  if (data.pending) {
    box.className = "muted small";
    box.textContent = `A walk was requested ${ago(data.requested_at)} and is waiting for the `
      + "next host collection. It runs on the collector's schedule — every five minutes with "
      + "the suggested crontab line.";
    return box;
  }

  const button = document.createElement("button");
  button.textContent = data.taken_at ? "Walk the disk again" : "Walk the disk";
  button.addEventListener("click", async () => {
    button.disabled = true;
    try {
      await post("request-host-walk");
      notice("Requested. The host collector will walk the disk on its next run.");
      await refreshHostDisk();
    } catch (error) {
      notice(String(error.message || error));
      button.disabled = false;
    }
  });

  box.appendChild(button);
  return box;
}

/*
 * Whether each watched service is alive.
 *
 * Four states, and keeping them four is the point. "Stopped" and "not
 * installed" both mean the process is absent and they mean opposite things
 * about whether anybody should care: the watch list covers ten daemons and a
 * given machine runs four, so collapsing them would paint six permanent red
 * rows and the panel would stop being read within a week.
 */
function renderHostDaemons(daemons) {
  const holder = $("hostDaemons");
  if (!holder) return;

  if (!daemons || !daemons.available || !daemons.rows?.length) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: daemons?.reason || "No service states recorded yet.",
    }));
    return;
  }

  const now = Date.now() / 1000;

  const STATES = {
    running: { text: "running", className: "state-up" },
    stopped: { text: "STOPPED", className: "state-down" },
    absent: { text: "not installed", className: "state-absent" },
    unknown: { text: "cannot tell", className: "state-absent" },
    off: { text: "not watched", className: "state-absent" },
    pending: { text: "waiting for a reading", className: "state-absent" },
  };

  renderTable("hostDaemons", daemons.rows, [
    { label: "Service", className: "name", render: row => row.label },
    {
      label: "State",
      render: row => {
        const span = document.createElement("span");

        /*
         * Stopped since before we were watching is not the same as stopped.
         *
         * A service that has been down for every reading on record is one
         * somebody turned off on purpose; one that was up this morning is a
         * fault. The alert already draws exactly this line and stays quiet
         * about the first kind — so if the table shouts about it in red anyway,
         * the panel acquires a permanent alarm and the two disagree about what
         * matters. The colour follows the alert.
         */
        if (row.state === "stopped" && !row.since_known) {
          span.className = "state-absent";
          span.textContent = "stopped";
          span.title = "Stopped for every reading on record, so this was almost certainly "
            + "disabled deliberately rather than having failed. It does not raise alerts.";
          return span;
        }

        const shown = STATES[row.state] ?? STATES.unknown;
        span.className = shown.className;
        span.textContent = shown.text;
        if (row.state === "off") {
          span.title = "Switched off here, so the collector is not recording it. Nothing is "
            + "known about whether it is running.";
        } else if (row.state === "pending") {
          span.title = "Switched on, but the collector has not run since. It reports on its "
            + "own schedule.";
        } else if (row.state === "absent") {
          span.title = "Neither a systemd unit nor a running process by any of the names "
            + "this service is known by. Almost certainly not installed on this machine.";
        } else if (row.state === "unknown") {
          span.title = "systemd could not be asked and no matching process is running. "
            + "Stopped and never-installed look identical from a process list.";
        } else if (row.state === "stopped") {
          span.title = "This was running earlier and is not now.";
        }
        return span;
      },
    },
    {
      label: "For",
      className: "size",
      render: row => {
        // A duration for something that was never installed is not a fact
        // about the machine, it is a fact about when this monitor started.
        if (["absent", "unknown", "off", "pending"].includes(row.state)) return "—";

        /*
         * systemd's own start time in preference to ours.
         *
         * They differ whenever the collector was not watching — after its own
         * outage, or on the first run — and systemd's does not depend on us
         * having been there to see it.
         */
        const at = row.started_at ?? row.since;
        if (!at) return "—";
        const span = document.createElement("span");
        span.textContent = humanDuration(now - at);
        span.title = row.started_at
          ? "Since systemd started it."
          : (row.since_known
              ? "Since this state was first seen here."
              : "At least this long — it has been this way for every reading on record.");
        if (!row.started_at && !row.since_known) span.className = "muted";
        return span;
      },
    },
    {
      label: "Restarts",
      className: "size",
      render: row => (row.restarts == null ? "—" : String(row.restarts)),
    },
    {
      label: "Processes",
      className: "size",
      render: row => {
        // The unit count is what matters for PHP-FPM, where cPanel runs one
        // service per installed PHP version and a missing one is the finding.
        if (row.units_total > 1) return `${row.units_active}/${row.units_total} units`;
        return row.procs ? String(row.procs) : "—";
      },
    },
    {
      label: "Last seen down",
      render: row => {
        if (!row.last_down_at) {
          const span = document.createElement("span");
          span.className = "muted";
          span.textContent = row.state === "absent" || row.state === "off" || row.state === "pending"
            ? "—"
            : `not in ${count(daemons.history_days, "day")}`;
          return span;
        }
        return humanTime(row.last_down_at);
      },
    },
    {
      label: "",
      render: row => {
        const button = document.createElement("button");
        button.className = row.watched ? "link" : "link muted";
        button.textContent = row.watched ? "Stop watching" : "Watch";
        button.title = row.watched
          ? "Stop recording this service. Its history is kept, but nothing more is added "
            + "and it will not raise alerts."
          : "Start recording this service again from the collector's next run.";

        button.addEventListener("click", async () => {
          button.disabled = true;
          try {
            await post("daemon-watch", { name: row.name, watched: row.watched ? "0" : "1" });
            /*
             * Said plainly, because nothing visible happens for a few minutes.
             *
             * The choice is written here and acted on by the collector, which
             * runs on its own schedule. Without this the button looks broken:
             * you press it, the row does not change, and you press it again.
             */
            notice(row.watched
              ? `${row.label} will stop being recorded at the next host reading.`
              : `${row.label} will be recorded again at the next host reading.`);
            await refreshHost();
          } catch (error) {
            notice(String(error.message || error));
            button.disabled = false;
          }
        });

        return button;
      },
    },
  ]);
}

/*
 * Total host throughput over the selected range.
 *
 * The table below says what the disks are doing now, which cannot distinguish a
 * runaway from the nightly backup - both look identical in a snapshot taken
 * while they run, and neither is visible in one taken at any other moment.
 *
 * Its own request rather than a field on the host payload: this one answers to
 * the range selector, and folding it in would mean re-reading a week of device
 * counters on every poll to refresh a table that only ever shows the latest
 * reading.
 */
async function refreshHostIoChart() {
  const to = Math.floor(Date.now() / 1000);
  const data = await api("host-io", { from: to - state.range, to });
  const rows = data.rows ?? [];

  drawLineChart($("hostIoChart"), rows,
    [
      { key: "read_rate", label: "Read" },
      { key: "write_rate", label: "Written" },
      { key: "util_percent", label: "Busiest device % (right)", axis: "right" },
    ],
    {
      xKey: "taken_at",
      // Restart marks come from state.restarts inside drawLineChart, which is
      // the only place that knows the window they were computed for.
      outages: outageBands(state.uptime),
      axis: rateAxis,
      rightAxis: percentAxis,
      zeroBased: true,
      empty: data.available === false
        ? (data.reason || "The host collector is not installed.")
        : (Number(data.sample_count ?? 0) < 2
            ? "Needs two host readings before a rate can be worked out."
            : "No device counters recorded for this range."),
    });

  const note = $("hostIoChartNote");
  if (!note) return;

  if (!rows.length) {
    note.textContent = "";
    return;
  }

  /*
   * The peak, because the average of a range containing one backup is a small
   * number that describes no moment that actually happened.
   */
  const peak = rows.reduce((worst, row) => {
    const total = (row.read_rate ?? 0) + (row.write_rate ?? 0);
    return total > worst.total ? { total, row } : worst;
  }, { total: -1, row: null });

  const gaps = rows.filter(r => r.read_rate == null).length;

  const parts = [];
  if (peak.row) {
    parts.push(`Busiest moment: ${humanBytes(peak.total)}/s at ${humanTime(peak.row.taken_at)}`
      + (peak.row.util_percent != null ? ` — the busiest device was ${peak.row.util_percent}% saturated.` : "."));
  }
  if (gaps) {
    // Said out loud: a gap here is a reboot, and a chart that silently drew
    // through one would invent traffic across an interval that did not happen.
    parts.push(`${count(gaps, "reading")} could not be turned into a rate, `
      + "because the counters restarted — that is a reboot.");
  }
  note.textContent = parts.join(" ");
}

/*
 * The processes doing the I/O, as against the devices carrying it.
 *
 * Two kinds of rate arrive mixed together and the difference matters enough to
 * label every row. A process seen in two consecutive readings has a real
 * instantaneous rate; one seen only once has its total spread over its own
 * lifetime, which is exactly right for a backup that started a minute ago and
 * close to meaningless for a daemon up for nine days. Presenting them as the
 * same number would be the more comfortable choice and the wrong one.
 */
function renderHostIoProcs(io) {
  const holder = $("hostIoProcs");
  if (!holder) return;

  if (!io || !io.available || !io.rows?.length) {
    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: io?.reason
        || "No per-process disk I/O recorded yet. It needs the host collector, running as root.",
    }));
    return;
  }

  const rate = value => (value == null ? "—" : `${humanBytes(value)}/s`);

  renderTable("hostIoProcs", io.rows, [
    {
      label: "Process",
      className: "name",
      render: row => {
        const box = document.createElement("span");
        box.appendChild(document.createTextNode(row.name));
        const note = document.createElement("span");
        note.className = "muted small";
        note.textContent = ` · pid ${row.pid} · ${row.user}`;
        box.appendChild(note);
        box.title = row.command;
        return box;
      },
    },
    { label: "Reading", className: "size", render: row => rate(row.read_rate) },
    { label: "Writing", className: "size", render: row => rate(row.write_rate) },
    {
      label: "Since it started",
      className: "size",
      render: row => `${humanBytes(row.read_bytes + row.write_bytes)}`,
    },
    {
      label: "Rate is",
      render: row => {
        const span = document.createElement("span");
        if (row.basis === "current") {
          span.textContent = `now (over ${io.seconds}s)`;
          span.title = "Measured between the last two host readings.";
        } else if (row.basis === "average") {
          span.textContent = "lifetime average";
          span.className = "muted";
          span.title = "This process has only been seen once, so its total I/O is spread "
            + "over how long it has been running. Accurate for a job that just started, "
            + "much less so for a long-running daemon.";
        } else {
          span.textContent = "—";
          span.className = "muted";
        }
        return span;
      },
    },
  ]);
}

function renderHostDisks(rows, sample, sampleCount) {
  const holder = $("hostDisks");
  if (!holder) return;

  if (!rows.length) {
    /*
     * Why it is empty, not just that it is.
     *
     * Three different reasons land here and need three different responses:
     * the collector has not run twice yet, it ran but could not read the
     * device counters, or it is an older build that does not collect them.
     * "Needs two readings" was printed for all three, which is wrong twice.
     */
    let why;
    /*
     * "Not recorded" is tested before "recorded as zero", because Number(null)
     * is 0 and the looser test swallows the other case entirely — reporting a
     * stale deployment as a kernel that will not answer, which sends the reader
     * to the wrong machine.
     */
    if (sample && sample.diskstats_available == null) {
      why = "The reading was written by an older build of the collector that did not "
        + "record disk counters. Upload host-cron.php and lib/, then let it run twice.";
    } else if (sample && Number(sample.diskstats_available) === 0) {
      why = "The host collector ran but could not read /proc/diskstats, even as root.";
    } else if (sampleCount < 2) {
      why = "Needs two host readings before a rate can be worked out — "
        + `there ${sampleCount === 1 ? "is 1" : `are ${sampleCount}`} so far.`;
    } else {
      why = "No devices were recorded in the latest reading.";
    }

    holder.replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: why,
    }));
    return;
  }

  const rate = value => (value == null ? "—" : `${humanBytes(value)}/s`);

  renderTable("hostDisks", rows, [
    {
      label: "Device",
      render: row => {
        if (!row.stacked) return row.name;
        const box = document.createElement("span");
        box.appendChild(document.createTextNode(row.name));
        const note = document.createElement("span");
        note.className = "muted small";
        note.textContent = " · stacked";
        note.title = "A volume mapped onto another device. Its traffic is already "
          + "counted there, so it is not added into any total.";
        box.appendChild(note);
        return box;
      },
    },
    { label: "Read", className: "size", render: row => rate(row.read_rate) },
    { label: "Written", className: "size", render: row => rate(row.write_rate) },
    {
      label: "Busy",
      className: "size",
      render: row => {
        if (row.util_percent == null) return "—";
        const span = document.createElement("span");
        span.textContent = `${Number(row.util_percent).toFixed(0)}%`;
        // Saturated is worth a colour; merely busy is not.
        if (row.util_percent >= 90) span.className = "grew";
        return span;
      },
    },
    {
      label: "Queue",
      className: "size",
      render: row => (row.queue_depth == null ? "—" : Number(row.queue_depth).toFixed(2)),
    },
    { label: "In flight", className: "size", render: row => row.in_flight },
  ]);
}

/**
 * Each service now, a day ago and a week ago.
 *
 * The table that answers the question a single number cannot. "Forgejo is using
 * 1.8 GiB" might be a leak or might have been true since it was installed;
 * "1.2 GiB last week, 1.5 GiB yesterday, 1.8 GiB now" carries its own diagnosis.
 */
function renderServices(rows, series, charted, metrics, metric) {
  // Remembered so a redraw - a poll, a range change - does not snap the chart
  // back to memory while somebody is reading the write rates.
  state.hostCharted = charted;
  syncMetricOptions(metrics);

  if (!rows.length) {
    $("hostServices").replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "No service history yet — this needs a few readings to build up.",
    }));
    $("hostServiceChart").hidden = true;
    return;
  }
  $("hostServiceChart").hidden = false;

  const change = value => {
    // No reading to compare against is not the same as no change. A blank
    // says "unknown"; a zero would claim the service had been measured and
    // found stable, which on a fresh install is a lie about every row.
    if (value == null) return Object.assign(document.createElement("span"), {
      className: "muted", textContent: "—",
    });

    const span = document.createElement("span");
    span.textContent = (value > 0 ? "+" : value < 0 ? "−" : "") + humanBytes(Math.abs(value));
    span.className = value > 0 ? "grew" : value < 0 ? "shrank" : "muted";
    return span;
  };

  renderTable("hostServices", rows, [
    { label: "Service", render: r => r.name },
    { label: "Processes", className: "size", render: r => r.procs },
    { label: "Now", className: "size", render: r => humanBytes(r.pss_bytes) },
    {
      label: "24h ago",
      className: "size",
      render: r => (r.day_ago == null ? "—" : humanBytes(r.day_ago)),
    },
    { label: "Change 24h", className: "size", render: r => change(r.change_day) },
    {
      label: "7d ago",
      className: "size",
      render: r => (r.week_ago == null ? "—" : humanBytes(r.week_ago)),
    },
    { label: "Change 7d", className: "size", render: r => change(r.change_week) },
    { label: "Swapped", className: "size", render: r => humanBytes(r.swap_bytes) },
    {
      label: "Reading",
      className: "size",
      render: r => (r.read_rate == null ? "—" : `${humanBytes(r.read_rate)}/s`),
    },
    {
      label: "Writing",
      className: "size",
      render: r => (r.write_rate == null ? "—" : `${humanBytes(r.write_rate)}/s`),
    },
  ]);

  /*
   * The axis follows the data, not the selector.
   *
   * Those are normally the same thing - the request carries the selection - but
   * if they ever diverge, drawing a byte series against a per-second scale is
   * worse than drawing it against the right one under a stale heading. So the
   * metric the server says it returned is what picks the axis.
   */
  drawServiceChart(series, charted, metric);
}

/** The metric picker, built from whatever the server says it can chart. */
function syncMetricOptions(metrics) {
  const select = $("hostMetric");
  const names = Object.keys(metrics);
  if (!names.length || select.dataset.built === names.join(",")) return;

  select.replaceChildren(...names.map(key => {
    const option = document.createElement("option");
    option.value = key;
    option.textContent = metrics[key];
    return option;
  }));
  select.dataset.built = names.join(",");
  select.value = state.hostMetric || "pss";
}

/**
 * The service chart, on whichever metric is chosen.
 *
 * The axis follows the metric rather than being fixed: bytes for memory, bytes
 * per second for the counters, and a plain count for processes and threads. A
 * process count rendered on a byte axis reads as "4 B", which is worse than no
 * label at all.
 */
function drawServiceChart(series, charted, metric) {
  const axis = metric === "procs" || metric === "threads"
    ? countAxis
    : (metric === "read" || metric === "write" ? rateAxis : bytesAxis);

  drawLineChart(
    $("hostServiceChart"),
    series,
    charted.map(name => ({ key: name, label: name })),
    {
      axis,
      zeroBased: true,
      empty: "Not enough service history to draw yet",
    }
  );

  $("hostMetricNote").textContent = (metric === "read" || metric === "write")
    // Worth saying, because the stored column is cumulative and somebody
    // reading the database directly will see a number that only ever climbs.
    ? "Counters differentiated into a rate; a gap means the service restarted."
    : "";
}

/** Redraws the chart on a different metric without refetching the whole panel. */
async function refreshServiceChart() {
  const charted = state.hostCharted || [];
  if (!charted.length) return;

  const to = Math.floor(Date.now() / 1000);
  const params = new URLSearchParams({
    metric: state.hostMetric || "pss",
    from: String(to - state.range),
    to: String(to),
  });
  for (const name of charted) params.append("names[]", name);

  const data = await api("host-series", params);
  drawServiceChart(data.rows ?? [], charted, data.metric || "pss");
}

/**
 * The memory pie, over the services from the latest reading.
 *
 * The total comes from the sample rather than from adding the slices up, so the
 * "everything else" wedge covers services too small to have been recorded at
 * all - not merely the ones below the threshold that were.
 */
function renderMemoryPie(services, sample) {
  const measured = Number(sample?.pss_sum_bytes) || 0;
  const slices = memorySlices(services, measured);

  drawPieChart($("hostPie"), slices, {
    empty: "No measured process memory in the latest reading",
  });

  $("hostPieNote").textContent =
    "Share of measured process memory. Drawn from PSS because a pie claims its "
    + "slices make a whole, and resident memory cannot — a page shared by ten "
    + "processes would be counted ten times and every slice inflated."
    + (measured
      ? ` Total ${humanBytes(measured)} across the ${sample.pss_procs} biggest processes.`
        + " The ten largest services get a slice; everything under 1%, and everything"
        + " past the tenth, is folded into one."
      : "");
}

/**
 * PSS is only measured for the biggest processes, so absence is not zero.
 *
 * Proportional set size: a process's private pages counted in full, plus its
 * share of each page it maps alongside others. It is the effective physical
 * memory attributable to that process, and the only per-process figure that
 * can be added up without inventing memory.
 */
function hostPss(value) {
  return value == null || Number(value) === 0 ? "—" : humanBytes(value);
}

function setHostCard(label, value, sub, level = "", valueClass = "") {
  const card = document.createElement("div");
  card.className = `card ${level}`.trim();
  const l = document.createElement("div");
  l.className = "label";
  l.textContent = label;
  const v = document.createElement("div");
  v.className = `value ${valueClass}`.trim();
  v.textContent = value;
  const s = document.createElement("div");
  s.className = "sub muted";
  s.textContent = sub;
  card.append(l, v, s);
  $("hostCards").appendChild(card);
}

function num(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/* ----------------------------------------------------------------- uptime -- */

/**
 * Instants the machine is known to have come up, for marking on the charts.
 *
 * Sourced from the host's boot history where there is one, because `last` knows
 * about restarts the readings cannot show - a reboot too brief to leave a gap,
 * or one that happened before boot times were recorded. Falls back to the boot
 * time of the current session, which at least marks the most recent one.
 */
/*
 * Says when the points have stopped being single readings.
 *
 * Old history is compacted, and a compacted point carries the worst value in
 * its bucket rather than a measurement of one minute. That is the right thing
 * to keep - a spike is most of the reason to hold a year of history - but it
 * changes what the chart is claiming, and a chart whose points quietly change
 * meaning partway along the axis is a trap rather than a record.
 */
function showCompactionNote(vitals) {
  const note = $("compactionNote");
  if (!note) return;

  const perPoint = Number(vitals?.samples_per_point ?? 1);
  const bucket = Number(vitals?.bucket_seconds ?? 0);
  const parts = [];

  /*
   * Two different kinds of grouping, and they are worth separating.
   *
   * One is on disk and permanent - old readings merged by lib/retention.php.
   * The other is only for this chart, because a year has more readings than
   * the canvas has pixels. Both show the worst value rather than an average,
   * and neither is something to leave the reader to infer.
   */
  if (bucket > 0) {
    parts.push(`Each point covers ${humanDuration(bucket)} and shows the worst value in `
      + `that period, not an average.`);
  }
  if (Number.isFinite(perPoint) && perPoint > 1) {
    parts.push(`The oldest readings here are also compacted on disk, up to `
      + `${count(perPoint, "reading")} to a row. Readings from around an alert are never `
      + `compacted.`);
  }

  if (parts.length === 0) {
    note.hidden = true;
    return;
  }

  note.hidden = false;
  note.textContent = parts.join(" ");
}

function restartMarks(uptime, from, to) {
  const marks = new Set();

  for (const boot of uptime?.history?.boots || []) {
    const at = Number(boot.booted_at);
    if (at >= from && at <= to) marks.add(at);
  }

  // Without a host collector there is still one boot time worth marking.
  const own = Number(uptime?.boot_at);
  if (Number.isFinite(own) && own >= from && own <= to) marks.add(own);

  return [...marks].sort((a, b) => a - b);
}

/** Both kinds of hole, flattened into bands the charts can shade. */
function outageBands(uptime) {
  const analysis = uptime?.analysis;
  if (!analysis) return [];

  return [
    ...(analysis.outages || []).map(o => ({ from: o.from, to: o.to, kind: "restart" })),
    ...(analysis.gaps || []).map(g => ({ from: g.from, to: g.to, kind: "gap" })),
  ];
}

/**
 * How long it has been up, and what the window missed.
 *
 * Two percentages, kept apart on purpose. Uptime counts only time the machine
 * is known to have been off; coverage counts holes that cannot be accounted
 * for. Merging them would let a cron job that stopped running look exactly like
 * a server that keeps falling over.
 */
/*
 * Fetches its own readings when it is the page being looked at.
 *
 * This used to render from whatever the dashboard had already loaded, which
 * worked only if you arrived here from there - opening Uptime directly, or
 * reloading on it, drew nothing at all. The vitals call comes with it because
 * the shading and the line have to describe the same window, and the analysis
 * that decides which holes are outages arrives with the vitals rather than with
 * the uptime record.
 */
async function refreshUptime() {
  const to = state.frozenAt || Math.floor(Date.now() / 1000);
  const from = to - state.range;

  const [uptime, vitals] = await Promise.all([
    api("uptime", { from, to }),
    api("vitals", { from, to, limit: 900 }),
  ]);

  state.uptime = uptime;
  state.uptime.analysis = vitals.uptime;
  state.restarts = restartMarks(uptime, from, to);

  renderUptime();
}

function renderUptime() {
  const data = state.uptime;
  if (!data) {
    return;
  }

  const a = data.analysis || {};

  if (data.up_for == null) {
    setCard("cardUptime", "—", "no boot time recorded yet");
  } else {
    setCard(
      "cardUptime",
      humanDuration(data.up_for),
      `since ${humanTime(data.boot_at)}`,
      // Freshly booted is worth noticing: it means something restarted, and
      // whatever was wrong before may be about to be wrong again.
      data.up_for < 3600 ? "warn" : ""
    );
  }

  const panel = $("uptimePanel");
  panel.hidden = false;

  const parts = [];
  if (a.uptime_percent != null) {
    parts.push(`Up ${trimPercent(a.uptime_percent)}% of the measured window`);
  }
  if (a.coverage_percent != null && a.coverage_percent < 100) {
    parts.push(`${trimPercent(a.coverage_percent)}% of it was actually recorded`);
  }
  if (a.uptime_percent === 100 && a.unmonitored > 0 && !a.boots) {
    // Worth stating outright: the holes below are gaps in the record, and the
    // machine is not known to have been off at any point.
    parts.push("no downtime detected");
  }
  if (a.boots) {
    parts.push(count(a.boots, "restart") + " in this window");
  }
  if (a.downtime) {
    parts.push(`at most ${humanDuration(a.downtime)} down`);
  }
  if (a.unmonitored) {
    parts.push(`${humanDuration(a.unmonitored)} not recorded`);
  }

  $("uptimeSummary").textContent = parts.length
    ? parts.join(" · ") + "."
    : "No readings in this window.";

  const bands = [
    ...(a.outages || []).map(o => ({
      kind: "Restarted",
      from: o.from,
      to: o.to,
      seconds: o.seconds_at_most,
      note: "Boot time moved, so the machine did restart. It came back at "
        + `${humanTime(o.booted_at)}; it went down at some point after the last `
        + "reading, so this is an upper bound rather than a measurement.",
    })),
    /*
     * All three are called a reading gap, not an outage.
     *
     * "Unexplained" in a panel headed "Uptime and outages" reads as though the
     * machine might have been off, and none of these say that - the uptime
     * figure beside them is untouched by any of it. What varies is how much can
     * be said about why the readings stop, so that goes in the explanation
     * rather than in the label.
     */
    ...(a.gaps || []).map(g => ({
      kind: "Reading gap",
      from: g.from,
      to: g.to,
      seconds: g.seconds,
      note: g.kind === "not_recorded"
        ? "Boot time was unchanged either side, so the machine ran straight "
          + "through this — it was up, and the collector was not running."
        : (g.kind === "before_tracking"
          ? "These readings predate boot-time recording, so whether the machine "
            + "restarted here cannot be told. Not an outage — just a stretch that "
            + "cannot be classified. Gaps from now on will say which."
          : "No boot time either side to compare, so whether the machine stayed "
            + "up through this cannot be told from these readings."),
    })),
  ].sort((x, y) => y.from - x.from);

  if (!bands.length) {
    $("uptimeBody").replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Every reading in this window is present and the machine never "
        + "restarted. Nothing to explain.",
    }));
  } else {
    renderTable("uptimeBody", bands, [
      {
        label: "What",
        render: row => {
          const span = document.createElement("span");
          span.textContent = row.kind;
          // Only a restart is coloured. A gap in the readings is not a fault of
          // the machine's, and colouring it the same invites reading it as one.
          if (row.kind === "Restarted") span.className = "grew";
          else span.className = "muted";
          return span;
        },
      },
      { label: "From", render: row => humanTime(row.from) },
      { label: "Until", render: row => humanTime(row.to) },
      {
        label: "For",
        className: "size",
        render: row => (row.kind === "Restarted" ? "≤ " : "") + humanDuration(row.seconds),
      },
      { label: "What it means", className: "name", render: row => row.note },
    ]);
  }

  renderBootHistory(data.history);
}

/** Trailing zeros off a percentage: "99.9" beats "99.9000". */
function trimPercent(value) {
  const n = Number(value);
  return n >= 99.95 && n < 100 ? n.toFixed(2) : (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2));
}

/** Why the machine restarted, where root could find out. */
function renderBootHistory(history) {
  const wrap = $("bootHistoryWrap");
  if (!history) {
    wrap.hidden = true;
    return;
  }
  wrap.hidden = false;

  if (!history.available) {
    $("bootHistory").replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: history.reason || "No boot history.",
    }));
    return;
  }

  renderTable("bootHistory", history.boots, [
    { label: "Came up", render: row => humanTime(row.booted_at) },
    {
      label: "Kernel",
      render: row => row.kernel || "—",
    },
    {
      label: "Previous session ended",
      render: row => {
        const span = document.createElement("span");
        span.textContent = row.ended === "shutdown"
          ? "cleanly"
          : (row.ended === "unclean" ? "without a shutdown" : "not known");
        // Only the unclean case is coloured: a clean shutdown is not news, and
        // an unknown one is an absence of evidence rather than a fault.
        if (row.ended === "unclean") span.className = "grew";
        return span;
      },
    },
    {
      label: "Still running",
      className: "size",
      render: row => (Number(row.still_running) ? "yes" : ""),
    },
    { label: "What that means", className: "name", render: row => row.reason },
  ]);
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
  no_inline_styles: [
    "Dashboard has no inline styles",
    "index.html carries a style=\"\" attribute, which the content security policy "
      + "blocks — the rule silently does not apply. Move it into index.css.",
  ],
  diskstats_readable: ["Disk counters readable", "Disk I/O throughput and utilisation stay empty."],
  io_pressure_available: ["I/O pressure available", "Stall percentages stay empty."],
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
       * Some checks can say why, not merely that. "Needs kernel 4.20+ with
       * CONFIG_PSI" leaves the reader to go and find out which of those they
       * are missing, which is the work this panel exists to save.
       */
      reason: checks[`${key.replace(/_available$|_readable$/, "")}_reason`] || "",
      /*
       * A host with no /proc and no mail() is limited, not broken.
       *
       * Painting those red would put two permanent failures on every shared
       * host, which is how people learn to ignore the whole panel.
       */
      soft: [
        "mail_available", "shell_available", "proc_readable",
        "diskstats_readable", "io_pressure_available",
      ].includes(key),
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
    note.textContent = row.ok ? "" : (row.reason || row.consequence);
  }

  const facts = document.createElement("p");
  facts.className = "muted small";
  const bits = [
    `PHP ${checks.php_version}`,
    ...(checks.kernel ? [`kernel ${checks.kernel}`] : []),
    `schema v${checks.schema_version}`,
    `${checks.problem_log_entries} problem${checks.problem_log_entries === 1 ? "" : "s"} logged`,
  ];
  if (checks.php_error_log) bits.push(`PHP logs to ${checks.php_error_log}`);
  if (checks.problem_log) bits.push(`this log: ${checks.problem_log}`);
  facts.textContent = bits.join(" \u00b7 ");

  $("healthBody").replaceChildren(table, facts);
  // The check reports paths, so it is offered only to somebody who may see them.
  $("symlinkCheck").hidden = !state.signedIn;
  if (!state.signedIn) $("symlinkBody").replaceChildren();
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

  $("problemPath").textContent = data.lines
    ? `${count(data.lines, "entry", "entries")} in ${data.path}`
    : data.path;
  $("problemClear").hidden = !data.lines;

  if (!data.rows.length) {
    $("problemBody").replaceChildren(Object.assign(document.createElement("p"), {
      className: "muted",
      textContent: "Nothing has gone wrong. Good.",
    }));
    return;
  }

  renderTable("problemBody", data.rows, [
    {
      label: "Last seen",
      render: row => (row.last ? new Date(row.last).toLocaleString() : ""),
    },
    {
      // One row per distinct fault, so a number here is repetition rather than
      // separate problems - which is the difference between "89 things are
      // broken" and "three things are broken, one of them noisily".
      label: "Times",
      className: "size",
      render: row => (row.count > 1 ? row.count : ""),
    },
    { label: "Level", render: row => row.level },
    {
      label: "Where",
      render: row => {
        const span = document.createElement("span");
        span.textContent = row.where;
        // The full list on hover, so collapsing several endpoints into a count
        // never costs the reader information.
        if (row.endpoint_list && row.endpoint_list !== row.where) {
          span.title = row.endpoint_list;
          span.className = "muted";
        }
        return span;
      },
    },
    { label: "What happened", className: "name", render: row => row.message },
    {
      label: "Reference",
      className: "size",
      render: row => (row.reference ? row.reference : ""),
    },
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
  const times = document.createElement("p");
  times.className = "muted small";
  times.id = "schedTimes";
  holder.append(note, times);

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

function updateSchedulePreview() {
  const data = state.schedule;
  if (!data || !$("schedPreview")) return;

  const jobs = [
    ["vitals", $("schedVitals").value, "--vitals --alerts --quiet"],
    ["scan", $("schedScan").value, "--scan --alerts --prune --quiet"],
  ];

  /*
   * What each field is for, and how far it may go.
   *
   * Both fields are shared between the two jobs, so the bound is whichever
   * chosen preset is fussiest. A six-hourly scan caps the hour at 5 because
   * cron reads `2/6` as "from 02:00, then every six hours" - a phase, not a
   * time - and a phase of 20 would silently become a once-a-day job.
   */
  const limit = { minute: { used: false, max: 59 }, hour: { used: false, max: 23 } };
  for (const [job, pick] of jobs) {
    const preset = data.presets[job]?.[pick];
    if (!preset || preset.minute_step == null) continue;
    if (preset.minute_step > 1) limit.minute.used = true;
    if ((preset.hour_step ?? 1) > 1) limit.hour.used = true;
  }

  const minute = applyBound("schedMinute", limit.minute);
  const hour = applyBound("schedHour", limit.hour);
  const php = $("schedPhp").value;
  const script = data.script || "cron.php";

  const lines = [];
  for (const [job, pick, flags] of jobs) {
    const expr = expressionFor(data.presets[job]?.[pick], minute, hour);
    if (!expr) continue;
    lines.push(`${expr} '${php}' '${script}' ${flags}`);
  }

  $("schedPreview").textContent = lines.length
    ? lines.join("\n")
    : "Nothing — saving will remove this dashboard's lines from the crontab.";

  $("schedNote").textContent = scheduleNote(limit);
  $("schedTimes").textContent = lines.length ? describeTimes(jobs, data, minute, hour) : "";
}

/**
 * Points a field at its current bounds, clamping what is in it.
 *
 * A field that does nothing is greyed out; one that is restricted keeps its
 * range on the input itself so the browser's own stepper respects it. Leaving
 * both wide open all the time is what made "hourly at minute 7 at hour 2" look
 * as though it had been accepted when only the 7 was ever used.
 */
function applyBound(id, bound) {
  const field = $(id);
  field.disabled = !bound.used;
  field.max = String(bound.max);
  field.style.opacity = bound.used ? "1" : ".4";
  field.parentElement.style.opacity = bound.used ? "1" : ".55";

  const value = Math.max(0, Math.min(bound.max, Number(field.value) || 0));
  if (String(value) !== field.value) field.value = String(value);
  return value;
}

function scheduleNote(limit) {
  const unused = [];
  if (!limit.minute.used) unused.push("minute");
  if (!limit.hour.used) unused.push("hour");

  if (!unused.length) {
    // Any value is accepted: a repeating schedule always includes the time it
    // was asked to start from, whatever the step.
    return "The minute and hour are when it first runs; a repeating schedule "
      + "then carries on from there at its own interval.";
  }

  return `The ${unused.join(" and ")} ${unused.length === 1 ? "field is" : "fields are"} `
    + "not used by the schedules you have chosen.";
}

/* ------------------------------------------------------- when cron fires -- */

/**
 * One cron field, built from a start and a step. Mirrors cronField() in PHP.
 *
 * A list, not `4/6`: the start/step form is a Vixie extension that cronie
 * accepts and cPanel's cron editor rejects, so a schedule written that way
 * installs over ssh and is refused through the interface most people on shared
 * hosting actually use.
 *
 * The server builds the real line and this is a preview of the same rules, not
 * a second source of truth.
 */
function cronField(start, step, cycle) {
  if (step <= 1) return "*";
  if (step >= cycle) return String(((start % cycle) + cycle) % cycle);

  const values = new Set();
  for (let k = 0; k * step < cycle; k++) {
    values.add((((start + k * step) % cycle) + cycle) % cycle);
  }
  return [...values].sort((a, b) => a - b).join(",");
}

/** The expression a preset produces, or null when it is switched off. */
function expressionFor(preset, minute, hour) {
  if (!preset || preset.minute_step == null) return null;
  return `${cronField(minute, preset.minute_step, 60)} `
    + `${cronField(hour, preset.hour_step ?? 1, 24)} * * *`;
}

/**
 * The values a cron field stands for.
 *
 * Handles every form this project has ever written: `*`, a single number, a
 * comma list, and the `N/S` and `*` + step forms earlier versions produced.
 */
function cronFieldValues(field, max) {
  if (field === "*") {
    return Array.from({ length: max + 1 }, (_, i) => i);
  }

  if (field.includes(",")) {
    return field.split(",").map(Number).filter(Number.isFinite).sort((a, b) => a - b);
  }

  const step = field.match(/^(\*|\d+)\/(\d+)$/);
  if (step) {
    const from = step[1] === "*" ? 0 : Number(step[1]);
    const by = Number(step[2]);
    const out = [];
    for (let v = from; v <= max; v += by) out.push(v);
    return out;
  }

  const single = Number(field);
  return Number.isFinite(single) ? [single] : [];
}

/** The start and step a field describes, for recognising an installed line. */
function cronFieldShape(field, cycle) {
  const values = cronFieldValues(field, cycle - 1);
  if (!values.length) return null;
  if (values.length === cycle) return { start: 0, step: 1 };
  if (values.length === 1) return { start: values[0], step: cycle };
  return { start: values[0], step: values[1] - values[0] };
}

/**
 * The instant a wall-clock time in some other zone corresponds to.
 *
 * This is the awkward direction: a Date is an instant, and turning "02:07 in
 * America/New_York" into one means knowing that zone's offset *at that moment*,
 * which depends on the answer. Guessing UTC and correcting by the offset the
 * guess lands on converges immediately for every case except the hour that
 * daylight saving removes, and the second pass handles the rest.
 */
function zonedToInstant(year, month, day, hour, minute, timeZone) {
  let guess = Date.UTC(year, month, day, hour, minute);

  for (let pass = 0; pass < 2; pass++) {
    const offset = zoneOffsetAt(guess, timeZone);
    const corrected = Date.UTC(year, month, day, hour, minute) - offset;
    if (corrected === guess) break;
    guess = corrected;
  }

  return new Date(guess);
}

/** How far ahead of UTC a zone is at a given instant, in milliseconds. */
function zoneOffsetAt(instant, timeZone) {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone,
    hour12: false,
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit",
  }).formatToParts(new Date(instant));

  const at = {};
  for (const part of parts) at[part.type] = part.value;
  // "24" for midnight is legal in this format and breaks Date.UTC.
  const hour = at.hour === "24" ? 0 : Number(at.hour);

  const asUtc = Date.UTC(
    Number(at.year), Number(at.month) - 1, Number(at.day),
    hour, Number(at.minute), Number(at.second)
  );

  return asUtc - instant;
}

/**
 * The next few times a cron expression fires, and how many a day it fires.
 *
 * Worked out in the zone cron reads, then handed back as instants so the
 * browser can print them wherever the person actually is. That is the whole
 * point: a server on UTC and a reader on a half-hour offset in the middle of a
 * daylight-saving change have no way to agree otherwise.
 */
function nextCronRuns(expr, timeZone, count = 4) {
  const [minuteField, hourField] = expr.split(/\s+/);
  const minutes = cronFieldValues(minuteField, 59);
  const hours = cronFieldValues(hourField, 23);
  const perDay = minutes.length * hours.length;
  if (!perDay) return { runs: [], perDay: 0 };

  const now = Date.now();
  const runs = [];

  // Today and tomorrow is always enough: the sparsest schedule here is once a
  // day, so two days guarantee more candidates than anyone asks for.
  for (let dayOffset = 0; dayOffset <= 1 && runs.length < count; dayOffset++) {
    const marker = new Date(now + dayOffset * 86400000);
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone, year: "numeric", month: "2-digit", day: "2-digit",
    }).formatToParts(marker);
    const at = {};
    for (const part of parts) at[part.type] = part.value;

    for (const hour of hours) {
      for (const minute of minutes) {
        const when = zonedToInstant(
          Number(at.year), Number(at.month) - 1, Number(at.day), hour, minute, timeZone
        );
        if (when.getTime() > now) runs.push(when);
        if (runs.length >= count * 4) break;
      }
    }
  }

  runs.sort((a, b) => a - b);
  return { runs: runs.slice(0, count), perDay };
}

/**
 * The times the chosen schedule actually fires, in the reader's own clock.
 *
 * Converted rather than printed as written, because the number in the crontab
 * is in the server's zone and means nothing to somebody in another one - or in
 * the same one six months later, on the other side of a daylight-saving change.
 * The browser knows where it is, so it does the last step.
 */
function describeTimes(jobs, data, minute, hour) {
  const zone = data.timezone?.name;
  const here = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const said = [];

  for (const [job, pick] of jobs) {
    const preset = data.presets[job]?.[pick];
    if (!preset || preset.minute_step == null) continue;

    const expr = expressionFor(preset, minute, hour);
    if (!expr) continue;

    if (!zone) {
      said.push(`${job}: ${expr} in the server's timezone, which could not be determined`);
      continue;
    }

    /*
     * Never list more times than the schedule actually has.
     *
     * Asking for four from a three-a-day job returns tomorrow's first as well,
     * so it printed "9:15 PM, 5:15 AM, 1:15 PM, 9:15 PM" - which reads as a
     * mistake rather than as the next four occurrences.
     */
    const { runs, perDay } = nextCronRuns(expr, zone, 4);
    const clock = runs
      .slice(0, Math.min(4, perDay))
      .map(when => when.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }));

    /*
     * A schedule that fires more often than hourly is described, not listed.
     *
     * "Every minute, next at 3:04, 3:05, 3:06, 3:07" is a worse sentence than
     * "every minute", and the list crowds out the schedules where the actual
     * times are the thing you need to check.
     */
    if (perDay > 24) {
      said.push(`${job}: ${preset.label.toLowerCase()}, next at ${clock[0] ?? "—"}`);
      continue;
    }

    const more = perDay - clock.length;
    said.push(
      `${job}: ${clock.join(", ")}`
      + (more > 0 ? ` and ${more} more` : "")
      + ` \u2014 ${perDay}\u00d7 a day`
    );
  }

  if (!said.length) return "";

  const where = zone && here && zone !== here
    ? ` Times shown in yours (${here}); the crontab is read in the server's (${zone}).`
    : zone
      ? ` Your timezone and the server's are both ${zone}.`
      : "";

  return said.join(" \u00b7 ") + where;
}

/**
 * Which preset an installed line came from, so the form opens on it.
 *
 * Compared by shape - the start and step each field describes - rather than by
 * matching text, so a line written by any earlier version is still recognised.
 * Getting this wrong is not cosmetic: a schedule shown as "Off" invites
 * somebody to replace a working crontab with nothing.
 */
function guessPick(lines, flag, presets) {
  const line = lines.find(l => l.includes(flag));
  if (!line) return "off";

  const [minuteField, hourField] = line.split(/\s+/);
  const minute = cronFieldShape(minuteField, 60);
  const hour = cronFieldShape(hourField, 24);
  if (!minute || !hour) return "off";

  const match = Object.entries(presets).find(([, p]) =>
    p.minute_step != null
    && p.minute_step === minute.step
    && (p.hour_step ?? 1) === hour.step);

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

/*
 * What each severity looks like at a glance.
 *
 * The word is still there - the emoji is a second channel, not a replacement.
 * Scanning forty rows for the word "critical" is work; scanning them for a red
 * dot is not.
 */
const SEVERITY_ICON = { critical: "\u{1F534}", warning: "\u{1F7E0}", info: "\u{1F535}" };

/*
 * Declared before the object that reads it on the way up.
 *
 * `const` is in the temporal dead zone until its own line runs, so reading this
 * from alertState's initialiser threw - and the try/catch around localStorage,
 * there for private browsing, quietly turned that into "nothing was dismissed".
 * The dismissal was being written correctly and discarded on every load.
 */
const DISMISS_KEY = "hostscope.alertsDismissedThrough";
const severityIcon = severity => SEVERITY_ICON[severity] || "\u26AA";

const alertState = {
  rows: [],
  selected: new Set(),
  unreadOnly: false,
  /*
   * The newest alert the banner has been waved away for.
   *
   * Dismissing has to stick across the poll or the banner reappears two
   * seconds later, and it has to *stop* sticking when something new fires -
   * otherwise one dismissal silences every future alert. Keying on the newest
   * id gets both: the banner stays down until an alert arrives that the reader
   * has not already seen.
   *
   * Kept in localStorage, because a reload is exactly when somebody expects a
   * thing they dismissed to stay dismissed. It is a per-browser preference, not
   * a fact about the alert - marking one read is the server-side act, and that
   * is a different button.
   */
  dismissedThrough: readDismissed(),
};

function readDismissed() {
  try {
    return Number(localStorage.getItem(DISMISS_KEY)) || 0;
  } catch {
    // Private browsing, or storage turned off. The banner simply comes back,
    // which is the safe direction to fail in.
    return 0;
  }
}

function writeDismissed(id) {
  alertState.dismissedThrough = id;
  try {
    localStorage.setItem(DISMISS_KEY, String(id));
  } catch {
    /* Nothing to do: the in-memory value still holds for this page. */
  }
}

/*
 * The banner, which appears above every view rather than only on Alerts.
 *
 * Split out from refreshAlerts so the shared poll can raise it wherever you
 * are standing. A monitor whose warnings are only visible on the page about
 * warnings has arranged for them to be missed.
 */
function renderAlertBanner(unread) {
  const banner = $("alertBanner");
  if (!banner) return;

  /*
   * The dashboard only.
   *
   * A banner on all eighteen pages is a banner you scroll past, and it pushes
   * whatever you actually came for below the fold every time. The nav badge is
   * what carries the warning everywhere else - it is always visible, it counts,
   * and it does not take a third of the screen to do it.
   */
  const newest = unread.reduce((max, row) => Math.max(max, row.id), 0);
  if (!unread.length || state.view !== "overview" || newest <= alertState.dismissedThrough) {
    banner.hidden = true;
    return;
  }

  banner.hidden = false;
  banner.className = unread.some(r => r.severity === "critical") ? "crit" : "";

  const frag = document.createDocumentFragment();
  const head = document.createElement("strong");
  head.textContent = `${count(unread.length, "unread alert")}`;
  frag.appendChild(head);

  const list = document.createElement("ul");
  for (const row of unread.slice(0, 4)) {
    const li = document.createElement("li");
    li.textContent = `${severityIcon(row.severity)} ${row.subject}`;
    list.appendChild(li);
  }
  frag.appendChild(list);

  const go = document.createElement("button");
  go.className = "link";
  go.textContent = "See all";
  go.addEventListener("click", () => showView("alerts"));
  frag.appendChild(go);

  const close = document.createElement("button");
  close.className = "dismiss";
  close.title = "Hide this until something new fires";
  close.setAttribute("aria-label", "Dismiss");
  close.textContent = "\u00d7";
  close.addEventListener("click", () => {
    writeDismissed(newest);
    banner.hidden = true;
  });
  frag.appendChild(close);

  banner.replaceChildren(frag);
}

/*
 * Marking read, or removing.
 *
 * Optimistic only as far as the redraw: the server is asked first, and the
 * table is rebuilt from what comes back. Flipping the row locally and hoping
 * would show an alert as handled that is still sitting in the database.
 */
async function alertAction(what, ids) {
  try {
    await post("alert-action", { what, ids: ids.join(",") });
    alertState.selected.clear();
    await refreshAlerts();
    await refreshBadges();
  } catch (error) {
    notice(String(error.message || error));
  }
}

function syncAlertTools() {
  const chosen = alertState.selected.size;
  $("alertAckSome").disabled = !chosen;
  $("alertDeleteSome").disabled = !chosen;
  $("alertAckSome").textContent = chosen ? `Mark ${chosen} read` : "Mark read";
  $("alertDeleteSome").textContent = chosen ? `Delete ${chosen}` : "Delete";
}

async function refreshAlerts() {
  const to = state.frozenAt || Math.floor(Date.now() / 1000);

  /*
   * Restarts alongside the alerts, because half of them are about the restart.
   *
   * A cluster of warnings just after the machine came back is a machine coming
   * back, not a machine in trouble - and the same cluster with no restart under
   * it is something else entirely. Without the boot marks the chart cannot tell
   * those two apart, and neither can the person reading it.
   */
  const [data, uptime] = await Promise.all([
    api("alerts", { limit: 200 }),
    api("uptime", { from: to - Math.max(state.range, 7 * 86400), to }).catch(() => null),
  ]);

  alertState.rows = data.rows;
  alertState.uptime = uptime;

  // Selections that no longer exist - deleted, or filtered away - must not
  // keep the buttons lit for rows nobody can see.
  const live = new Set(data.rows.map(r => r.id));
  for (const id of [...alertState.selected]) {
    if (!live.has(id)) alertState.selected.delete(id);
  }

  const rows = alertState.unreadOnly
    ? data.rows.filter(r => !r.acknowledged_at)
    : data.rows;

  drawAlertTimeline(data.rows);

  renderTable("alerts", rows, [
    { label: "", render: alertPicker },
    { label: "When", render: row => humanTime(row.raised_at) },
    { label: "Severity", render: alertSeverity },
    { label: "What", className: "name", render: alertWhat },
    { label: "Emailed", render: row => (row.emailed_at ? "yes" : "no") },
    { label: "", render: alertRowActions },
  ], null, row => (row.acknowledged_at ? "read" : "unread"));

  syncAlertTools();
}

function alertPicker(row) {
  const box = document.createElement("input");
  box.type = "checkbox";
  box.checked = alertState.selected.has(row.id);
  box.setAttribute("aria-label", `Select alert ${row.id}`);
  box.addEventListener("change", () => {
    box.checked ? alertState.selected.add(row.id) : alertState.selected.delete(row.id);
    syncAlertTools();
  });
  return box;
}

function alertSeverity(row) {
  const span = document.createElement("span");
  const icon = document.createElement("span");
  icon.className = "sev";
  icon.textContent = severityIcon(row.severity);
  span.appendChild(icon);
  span.appendChild(document.createTextNode(row.severity));
  return span;
}

function alertRowActions(row) {
  const cell = document.createDocumentFragment();

  if (!row.acknowledged_at) {
    const ack = document.createElement("button");
    ack.className = "link";
    ack.textContent = "Mark read";
    ack.addEventListener("click", () => alertAction("ack", [row.id]));
    cell.appendChild(ack);
  }

  const remove = document.createElement("button");
  remove.className = "link danger";
  remove.textContent = "Delete";
  remove.addEventListener("click", () => alertAction("delete", [row.id]));
  cell.appendChild(remove);

  return cell;
}

/*
 * When alerts fired, stacked by severity.
 *
 * The table answers "what went wrong"; this answers "was it once or has it
 * been going on all week", which the table cannot show without being read end
 * to end. Bucketed by hour or by day depending on how far back the alerts
 * actually go, so a quiet fortnight is not drawn as 336 empty columns.
 */
/*
 * Every moment the machine is known to have come up, from either record.
 *
 * Two sources that overlap and neither of which is complete: `last` on the host
 * knows about reboots from before this tool existed, and the readings know
 * about ones on a machine where `last` cannot be read. Merged and deduplicated
 * to the minute, because the same restart recorded twice would draw two lines
 * a few seconds apart and read as two reboots.
 */
function bootTimes(uptime) {
  if (!uptime) return [];

  const seen = new Set();
  const out = [];

  const add = at => {
    const when = Number(at);
    if (!when) return;
    const minute = Math.floor(when / 60);
    if (seen.has(minute)) return;
    seen.add(minute);
    out.push(when);
  };

  for (const boot of uptime.boots || []) add(boot.booted_at ?? boot.boot_at);
  for (const outage of uptime.analysis?.outages || []) add(outage.booted_at);
  add(uptime.boot_at);

  return out.sort((a, b) => a - b);
}

function drawAlertTimeline(rows) {
  const canvas = $("alertTimeline");
  rememberDraw(canvas, () => drawAlertTimeline(alertState.rows));

  /*
   * The canvas's own width, not its parent's.
   *
   * The parent's content box is wider than the canvas whenever the panel has
   * padding or the canvas has a margin, and a bitmap laid out for the wrong
   * box gets rescaled by the browser to fit the right one. That rescale is
   * exactly the blur this chart is meant to avoid.
   */
  const width = canvas.clientWidth;
  if (width === 0) return;

  const ratio = window.devicePixelRatio || 1;
  const height = 170;
  canvas.width = Math.max(1, Math.floor(width * ratio));
  canvas.height = Math.max(1, Math.floor(height * ratio));
  canvas.style.height = height + "px";

  const ctx = canvas.getContext("2d");
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.clearRect(0, 0, width, height);

  if (!rows.length) {
    ctx.fillStyle = "#8b98a8";
    ctx.font = "13px system-ui, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText("Nothing has fired yet.", width / 2, height / 2);
    $("alertLegend").replaceChildren();
    $("alertRange").textContent = "";
    return;
  }

  const now = Math.floor(Date.now() / 1000);
  const oldest = Math.min(...rows.map(r => r.raised_at));
  const span = Math.max(3600, now - oldest);
  const bucket = span > 3 * 86400 ? 86400 : 3600;

  /*
   * Day buckets start at local midnight, not at a multiple of 86400.
   *
   * Rounding the epoch is rounding in UTC, which puts the first bar at 8pm the
   * previous evening for anyone west of Greenwich - and labels the chart with a
   * start time hours before the alert it is meant to begin at.
   */
  const edge = new Date(oldest * 1000);
  edge.setHours(bucket === 86400 ? 0 : edge.getHours(), 0, 0, 0);
  const from = Math.floor(edge.getTime() / 1000);
  const slots = Math.max(1, Math.ceil((now - from) / bucket) + 1);

  const levels = ["critical", "warning", "info"];
  const colour = { critical: "#f2616b", warning: "#f0a35e", info: "#4c9aff" };

  const buckets = Array.from({ length: slots }, () => ({ critical: 0, warning: 0, info: 0 }));
  for (const row of rows) {
    const i = Math.min(slots - 1, Math.max(0, Math.floor((row.raised_at - from) / bucket)));
    const level = levels.includes(row.severity) ? row.severity : "info";
    buckets[i][level]++;
  }

  const pad = { left: 32, right: 8, top: 12, bottom: 22 };
  const plotW = Math.max(1, width - pad.left - pad.right);
  const plotH = Math.max(1, height - pad.top - pad.bottom);

  const tallest = Math.max(1, ...buckets.map(b => b.critical + b.warning + b.info));
  const scale = plotH / tallest;
  const step = plotW / slots;
  const barW = Math.max(2, Math.min(26, step - 1));

  ctx.strokeStyle = "#232d3a";
  ctx.beginPath();
  ctx.moveTo(pad.left, pad.top + plotH + .5);
  ctx.lineTo(pad.left + plotW, pad.top + plotH + .5);
  ctx.stroke();

  // Whole alerts only: there is no such thing as half a warning.
  ctx.fillStyle = "#8b98a8";
  ctx.font = "10px system-ui, sans-serif";
  ctx.textAlign = "right";
  for (const value of tallest > 1 ? [0, tallest] : [0, 1]) {
    ctx.fillText(String(value), pad.left - 6, pad.top + plotH - value * scale + 3);
  }

  buckets.forEach((counts, i) => {
    let y = pad.top + plotH;
    for (const level of levels) {
      if (!counts[level]) continue;
      const h = counts[level] * scale;
      y -= h;
      ctx.fillStyle = colour[level];
      ctx.fillRect(pad.left + i * step, y, barW, h);
    }
  });

  /*
   * Each restart as a dashed line, drawn over the bars.
   *
   * Over rather than under: the question is "did this fire around a reboot",
   * and a line hidden behind a bar answers it for every case except the one
   * that matters.
   */
  const boots = bootTimes(alertState.uptime).filter(at => at >= from && at <= now);
  if (boots.length) {
    ctx.save();
    ctx.setLineDash([4, 3]);
    ctx.strokeStyle = "#5ecbd6";
    ctx.lineWidth = 1.5;
    for (const at of boots) {
      const x = pad.left + ((at - from) / Math.max(1, now - from)) * plotW;
      ctx.beginPath();
      ctx.moveTo(x, pad.top);
      ctx.lineTo(x, pad.top + plotH);
      ctx.stroke();
    }
    ctx.restore();
  }

  ctx.fillStyle = "#8b98a8";
  ctx.textAlign = "left";
  ctx.fillText(humanTime(from), pad.left, height - 6);
  ctx.textAlign = "right";
  ctx.fillText(humanTime(now), pad.left + plotW, height - 6);

  const legend = document.createDocumentFragment();
  for (const level of levels) {
    const total = buckets.reduce((sum, b) => sum + b[level], 0);
    if (!total) continue;
    const span = document.createElement("span");
    span.style.setProperty("--dot", colour[level]);
    span.textContent = `${level} (${total})`;
    legend.appendChild(span);
  }
  if (boots.length) {
    const span = document.createElement("span");
    span.style.setProperty("--dot", "#5ecbd6");
    span.textContent = `${count(boots.length, "restart")} (dashed)`;
    legend.appendChild(span);
  }
  $("alertLegend").replaceChildren(legend);
  $("alertRange").textContent =
    `${count(rows.length, "alert")}, one bar per ${bucket === 86400 ? "day" : "hour"}`;
}

/*
 * The alert, and why it is one.
 *
 * The subject alone is enough for a threshold - "disk is at 91%" argues for
 * itself. It is not enough for an anomaly, where the whole claim rests on a
 * comparison the reader cannot make: "english has been running for 1.3 hours"
 * is either alarming or completely routine depending on how long that job
 * normally takes, and only the detail line knows. Showing the subject on its
 * own would leave every one of these looking like an assertion.
 *
 * Built as nodes rather than markup because the detail is server-side text
 * that quotes paths and service names, and renderTable sets .textContent on a
 * string - which would escape the whole thing, newlines included, into one
 * run-on line.
 */
function alertWhat(row) {
  const cell = document.createDocumentFragment();

  const subject = document.createElement("div");
  subject.className = "alert-subject";
  subject.textContent = row.subject;
  cell.appendChild(subject);

  if (row.detail) {
    const why = document.createElement("div");
    why.className = "alert-why";
    why.textContent = row.detail;
    cell.appendChild(why);
  }

  /*
   * The evidence, for whoever is allowed to see it.
   *
   * Hidden when signed out rather than masked, which is not how the rest of
   * this dashboard behaves - the record holds other accounts' command lines,
   * and the endpoint refuses them outright. Offering a button that always
   * returns "sign in" would be a worse answer than not offering it.
   */
  if (row.incident_id && state.signedIn) {
    const button = document.createElement("button");
    button.className = "link why";
    button.textContent = "Why did this fire?";
    button.addEventListener("click", () => showIncident(row.incident_id));
    cell.appendChild(button);
  }

  return cell;
}

/*
 * The state of the machine at the moment a rule fired.
 *
 * Laid out worst-first rather than in the order the sections were gathered:
 * whoever opens this is looking for the cause, and the cause is nearly always
 * a process or a file rather than the load average that triggered the mail.
 */
async function showIncident(id) {
  const data = await api("incident", { id });
  const evidence = data.evidence;

  $("incidentPanel").hidden = false;
  $("incidentTitle").textContent =
    data.alerts.length === 1 ? data.alerts[0].subject : `${data.alerts.length} alerts fired together`;
  $("incidentWhen").textContent =
    `Captured ${humanTime(data.captured_at)} — the machine as it stood at that moment.`;

  const body = document.createDocumentFragment();

  if (!evidence) {
    body.appendChild(note("This record could not be read back."));
    $("incidentBody").replaceChildren(body);
    return;
  }

  if (data.alerts.length > 1) {
    body.appendChild(section("What fired", listOf(data.alerts.map(a => a.subject))));
  }

  const vitals = evidence.vitals;
  if (vitals) {
    body.appendChild(section("At that moment", factGrid([
      ["Load", vitals.load["1"] === null ? "not reported"
        : `${vitals.load["1"].toFixed(2)}${vitals.load.cores ? ` on ${count(vitals.load.cores, "core")}` : ""}`],
      ["Load per core", vitals.load.per_core === null ? "—" : vitals.load.per_core.toFixed(2)],
      ["CPU", vitals.cpu_percent === null ? "not reported" : `${vitals.cpu_percent.toFixed(1)}%`],
      ["Memory available", humanBytes(vitals.memory.available_bytes)],
      ["Memory used", vitals.memory.used_percent === null ? "—" : `${vitals.memory.used_percent.toFixed(1)}%`],
      ["Swap used", `${humanBytes(vitals.memory.swap_used_bytes)} of ${humanBytes(vitals.memory.swap_total_bytes)}`],
      ["Reading own I/O", vitals.io.own_read_rate === null ? "not reported"
        : `${humanBytes(vitals.io.own_read_rate)}/s`],
      ["Writing own I/O", vitals.io.own_write_rate === null ? "not reported"
        : `${humanBytes(vitals.io.own_write_rate)}/s`],
    ])));
  }

  const procs = evidence.processes;
  if (procs && procs.available) {
    body.appendChild(section(`Top processes, of ${procs.count} running`, processTables(procs)));
  } else if (procs) {
    body.appendChild(section("Top processes", note(procs.missing?.processes || "Not available.")));
  }

  if (evidence.tree && evidence.tree.length) {
    body.appendChild(section("How they were started", processTree(evidence.tree)));
  }

  const host = evidence.host;
  if (host && host.available && host.services?.length) {
    body.appendChild(section("Memory by service (PSS, from the host collector)",
      simpleTable(
        ["Service", "Proportional (PSS)", "Resident", "Processes", "Oldest"],
        host.services.map(s => [
          s.name,
          humanBytes(s.pss_bytes),
          humanBytes(s.rss_bytes),
          String(s.procs),
          s.oldest_seconds === null ? "—" : humanDuration(s.oldest_seconds),
        ]),
        [null, "size", "size", "size", "size"]
      )));
  }

  const disk = evidence.disk;
  if (disk) {
    const growth = Object.entries(disk.growth_bytes || {}).map(([label, bytes]) =>
      [`Grew over ${label}`, bytes === null ? "not enough history" : humanBytes(bytes)]);
    body.appendChild(section("Disk", factGrid([
      ["Used", `${humanBytes(disk.used_bytes)} of ${humanBytes(disk.total_bytes)} (${disk.used_percent.toFixed(1)}%)`],
      ["Free", humanBytes(disk.free_bytes)],
      ...growth,
    ])));

    if (disk.categories?.length) {
      body.appendChild(section("By category, since the previous scan", simpleTable(
        ["Category", "Size", "Change"],
        disk.categories.map(c => [
          c.category,
          humanBytes(c.bytes),
          c.grew_bytes === null ? "—" : humanBytes(c.grew_bytes),
        ]),
        [null, "size", "size"]
      )));
    }
  }

  const files = evidence.files;
  if (files && files.rows?.length) {
    body.appendChild(section("Files that grew most, since the previous scan", simpleTable(
      ["Path", "Size", "Change"],
      files.rows.map(f => [f.path, humanBytes(f.bytes), humanBytes(f.grew_bytes)]),
      ["name", "size", "size"]
    )));
  }

  const missing = Object.entries(evidence.missing || {});
  if (missing.length) {
    /*
     * What could not be read, said out loud.
     *
     * A section quietly absent reads as "there was none of that", which for
     * disk I/O inside CageFS would be flatly untrue.
     */
    body.appendChild(section("What could not be seen",
      listOf(missing.map(([, why]) => why))));
  }

  $("incidentBody").replaceChildren(body);
  $("incidentPanel").scrollIntoView({ behavior: "smooth", block: "nearest" });
}

/** A titled block within the incident record. */
function section(title, contents) {
  const wrap = document.createElement("div");
  wrap.className = "evidence";

  const heading = document.createElement("h3");
  heading.textContent = title;
  wrap.appendChild(heading);
  wrap.appendChild(contents);

  return wrap;
}

/*
 * Something on screen while a request is in flight.
 *
 * A panel that is blank for four seconds and then full is indistinguishable
 * from a panel that is broken, and the pages that take longest - the backup
 * analysis, a disk walk being read back - are exactly the ones where somebody
 * concludes it does not work and goes away.
 */
function spinner(text = "Loading\u2026") {
  const wrap = document.createElement("p");
  wrap.className = "loading";

  const ring = document.createElement("span");
  ring.className = "loading-ring";
  ring.setAttribute("aria-hidden", "true");
  wrap.appendChild(ring);

  wrap.appendChild(document.createTextNode(text));
  wrap.setAttribute("role", "status");
  return wrap;
}

function note(text) {
  const p = document.createElement("p");
  p.className = "muted";
  p.textContent = text;
  return p;
}

function listOf(items) {
  const ul = document.createElement("ul");
  ul.className = "evidence-list";
  for (const item of items) {
    const li = document.createElement("li");
    li.textContent = item;
    ul.appendChild(li);
  }
  return ul;
}

/** Label/value pairs, which is most of what an incident is. */
function factGrid(pairs) {
  const grid = document.createElement("div");
  grid.className = "facts";
  for (const [label, value] of pairs) {
    const l = document.createElement("div");
    l.className = "facts-label";
    l.textContent = label;
    const v = document.createElement("div");
    v.className = "facts-value";
    v.textContent = value;
    grid.append(l, v);
  }
  return grid;
}

function simpleTable(headings, rows, classes = []) {
  const wrap = document.createElement("div");
  wrap.className = "scroll-x";

  const table = document.createElement("table");
  const head = table.createTHead().insertRow();
  headings.forEach((heading, i) => {
    const th = document.createElement("th");
    th.textContent = heading;
    if (classes[i]) th.className = classes[i];
    head.appendChild(th);
  });

  const body = table.createTBody();
  for (const row of rows) {
    const tr = body.insertRow();
    row.forEach((cell, i) => {
      const td = tr.insertCell();
      if (classes[i]) td.className = classes[i];
      td.textContent = cell;
    });
  }

  wrap.appendChild(table);
  return wrap;
}

/*
 * Three rankings, because they answer three different questions.
 *
 * A stuck job appears in none of the usual ones: it is using no CPU and little
 * memory, which is what being stuck means. It only stands out by still being
 * there, so age gets a column of its own.
 */
function processTables(procs) {
  const wrap = document.createElement("div");
  wrap.className = "evidence-columns";

  const views = [
    ["Using the most CPU", procs.by_cpu, p => `${p.cpu_percent.toFixed(1)}%`],
    ["Holding the most memory", procs.by_memory, p => humanBytes(p.rss_bytes)],
    ["Running the longest", procs.by_age, p => humanDuration(p.elapsed_seconds)],
  ];

  for (const [title, rows, value] of views) {
    if (!rows || !rows.length) continue;
    const column = document.createElement("div");
    const heading = document.createElement("h4");
    heading.textContent = title;
    column.appendChild(heading);
    column.appendChild(simpleTable(
      ["Process", "", "User"],
      rows.slice(0, 8).map(p => [p.name, value(p), p.user]),
      [null, "size", null]
    ));
    wrap.appendChild(column);
  }

  return wrap;
}

/*
 * The tree, indented.
 *
 * The ancestry is the point. Eleven identical processes are a curiosity until
 * you can see they all hang off one cron entry, and then they are a diagnosis -
 * which is exactly how the webalizer jobs would have been read.
 */
function processTree(nodes) {
  const wrap = document.createElement("div");
  wrap.className = "scroll-x";

  const pre = document.createElement("div");
  pre.className = "tree";

  for (const node of nodes) {
    const line = document.createElement("div");
    line.className = node.notable ? "tree-line notable" : "tree-line";
    line.style.paddingLeft = `${node.depth * 18}px`;

    const name = document.createElement("span");
    name.className = "tree-name";
    name.textContent = node.name;

    const meta = document.createElement("span");
    meta.className = "tree-meta";
    const bits = [`pid ${node.pid}`, `${node.cpu_percent.toFixed(1)}% cpu`, humanBytes(node.rss_bytes)];
    if (node.elapsed_seconds !== null) bits.push(`up ${humanDuration(node.elapsed_seconds)}`);
    meta.textContent = bits.join(" · ");

    line.append(name, meta);
    line.title = node.command;
    pre.appendChild(line);
  }

  wrap.appendChild(pre);
  return wrap;
}

const actionState = { kind: "", who: "", result: "" };

/*
 * What a raw action name means in words.
 *
 * The database stores identifiers because code compares them; a person reading
 * a list of things that happened to their disk should not have to translate
 * `empty` into "emptied a directory" in their head.
 */
const ACTION_WORDS = {
  delete_file: "deleted a file",
  empty: "emptied a directory",
  scan_now: "ran a scan",
  schedule_save: "changed the schedule",
  test_email: "sent a test email",
  clear_problems: "cleared the problem log",
  ack_alerts: "marked alerts read",
  delete_alerts: "deleted alerts",
  daemon_watch: "changed which services are watched",
  request_host_walk: "asked the host to walk the disk",
  check_capabilities: "checked which programs are available",
};

async function refreshActionLog() {
  const panel = $("actionPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  const data = await api("actions");
  fillFilter($("actionKind"), data.kinds, actionState.kind,
    value => ACTION_WORDS[value] || value);
  fillFilter($("actionWho"), data.who, actionState.who);

  // Said out loud rather than left for the reader to work out from a column
  // heading, because "From 203.0.113.9" looks like an answer to "who".
  $("actionWhoNote").textContent = data.identity === "address"
    ? "There is one password, so there is one identity. The address a change "
      + "came from is the only thing that tells two uses of it apart \u2014 it is a "
      + "place, not a person."
    : "";

  const rows = data.rows.filter(row =>
    (!actionState.kind || row.action === actionState.kind) &&
    (!actionState.who || row.remote_addr === actionState.who) &&
    (!actionState.result || row.outcome === actionState.result));

  renderActionSummary(rows);

  renderTable("actionLog", rows, [
    { label: "When", render: row => humanTime(row.at) },
    { label: "What", render: row => ACTION_WORDS[row.action] || row.action },
    { label: "Target", className: "name", render: row => row.target ?? "" },
    { label: "Outcome", render: row => row.outcome + (row.detail ? ` \u2014 ${row.detail}` : "") },
    { label: "Freed", className: "size", render: row => humanBytes(row.bytes_freed) },
    // Not "who": one shared password means the address is the only thing that
    // tells two uses of it apart, and it is a place rather than a person.
    { label: "From", render: row => row.remote_addr || "\u2014" },
  ], null, row => (row.outcome === "ok" ? "" : "row-bad"));
}

/*
 * Keeps a filter's options in step with the data without losing the choice.
 *
 * Rebuilding the list on every poll is what makes a dropdown forget what it was
 * set to mid-click, so the current value is put back afterwards - and kept even
 * when it has dropped out of the data, or filtering to something rare would
 * silently reset itself the moment that thing stopped happening.
 */
function fillFilter(select, values, current, label = v => v) {
  const wanted = [...new Set(values)].sort();
  if (current && !wanted.includes(current)) wanted.push(current);

  const already = [...select.options].slice(1).map(o => o.value);
  if (already.join("\u0000") !== wanted.join("\u0000")) {
    while (select.options.length > 1) select.remove(1);
    for (const value of wanted) {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = label(value);
      select.appendChild(option);
    }
  }
  select.value = current;
}

function renderActionSummary(rows) {
  const freed = rows.reduce((sum, r) => sum + Number(r.bytes_freed || 0), 0);
  const files = rows.reduce((sum, r) => sum + Number(r.files_removed || 0), 0);
  const refused = rows.filter(r => r.outcome !== "ok").length;

  const cards = [
    ["Things done", String(rows.length), rows.length ? `since ${humanTime(rows[rows.length - 1].at)}` : ""],
    ["Space freed", humanBytes(freed), count(files, "file") + " removed"],
    ["Refused or failed", String(refused), refused ? "these changed nothing" : "everything worked"],
  ];

  const frag = document.createDocumentFragment();
  for (const [label, value, sub] of cards) {
    const card = document.createElement("div");
    card.className = "card";
    for (const [cls, text] of [["label", label], ["value", value], ["sub muted", sub]]) {
      const div = document.createElement("div");
      div.className = cls;
      div.textContent = text;
      card.appendChild(div);
    }
    frag.appendChild(card);
  }
  $("actionSummary").replaceChildren(frag);
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

/*
 * Keeps "updated 12s ago" honest between refreshes.
 *
 * Its own one-second timer rather than a faster poll: the cost is a string
 * assignment, and tying it to the refresh interval is what made it wrong in
 * the first place.
 */
function renderLastSeen() {
  const el = $("lastSeen");
  if (!el) return;

  if (!state.lastReadingAt) {
    el.textContent = "";
    return;
  }

  el.textContent = `updated ${ago(state.lastReadingAt)}`;
}

setInterval(renderLastSeen, 1000);


/* ------------------------------------------------------------------ treemap -- */

/*
 * Where the space is, drawn as area.
 *
 * Nothing about this is stored: each view is one request for the immediate
 * children of one directory, measured when asked. See lib/treemap.php for why
 * that is the right trade rather than a nightly job that records the tree.
 */
const treeState = { path: "", source: "account", level: null, boxes: [], hover: null,
                    colourBy: "type", selected: null, typeFilter: "" };

async function refreshTreemap(path = treeState.path) {
  if (!state.signedIn) {
    treeState.level = null;
    treeState.boxes = [];
    drawTreemap();
    $("treeNote").textContent = "Sign in to explore the disk.";
    $("treeCrumbs").replaceChildren();
    return;
  }

  $("treeNote").textContent = "Measuring\u2026";
  if (!treeState.level) {
    // The first walk of a large directory is the slow one, and an empty canvas
    // with no explanation is where people conclude it is broken.
    $("treeNote").replaceChildren(spinner("Measuring this directory\u2026"));
  }

  try {
    const level = await api("treemap", { path, source: treeState.source });
    treeState.level = level;
    treeState.path = level.path;
    fillFilter($("treeTypeFilter"), [...Object.keys(TYPE_EXTENSIONS), "other"],
      treeState.typeFilter);
    drawTreemap();
    renderCrumbs();
    renderTreeNote();
    renderTreeLegend();
  } catch (error) {
    treeState.level = null;
    treeState.boxes = [];
    drawTreemap();
    $("treeNote").textContent = String(error.message || error);
  }
}

function renderTreeNote() {
  const level = treeState.level;
  if (!level) return;

  const parts = [
    `${humanBytes(level.bytes)} in ${count(level.files, "file")}`
    + (level.children.length ? ` across ${count(level.children.length, "entry", "entries")}` : "")
    + ".",
  ];

  if (level.reason) parts.push(level.reason);

  /*
   * An incomplete measurement is said out loud, every time.
   *
   * A directory whose walk ran out of time reports a lower bound, and a lower
   * bound that looks like a measurement is how somebody dismisses the 12 GB
   * they were looking for as 400 MB.
   */
  if (!level.complete && !level.reason) {
    parts.push("Some directories were still being measured when the time budget ran out — "
      + "those are marked and their sizes are at least what is shown, not exactly.");
  }
  if (level.pooled) {
    parts.push(`${count(level.pooled, "small entry", "small entries")} are pooled into one `
      + "rectangle; its area is still their true total.");
  }
  if (level.seconds) parts.push(`Measured in ${level.seconds}s.`);

  if (treeState.typeFilter) {
    const shown = treeState.boxes.reduce((sum, b) => sum + Number(b.bytes || 0), 0);
    parts.push(`Showing only ${treeState.typeFilter}: ${humanBytes(shown)} of the `
      + `${humanBytes(level.bytes)} here. Directories are sized by how much `
      + `${treeState.typeFilter} is inside them, not by their whole contents.`);
  }

  $("treeNote").textContent = parts.join(" ");
}

function renderCrumbs() {
  const holder = $("treeCrumbs");
  const level = treeState.level;
  if (!level) { holder.replaceChildren(); return; }

  const root = level.root || "/";
  const rest = level.path.startsWith(root) ? level.path.slice(root.length) : level.path;
  const names = rest.split("/").filter(Boolean);

  const frag = document.createDocumentFragment();
  const add = (label, target, current) => {
    if (current) {
      const span = document.createElement("span");
      span.textContent = label;
      frag.appendChild(span);
      return;
    }
    const button = document.createElement("button");
    button.className = "link";
    button.textContent = label;
    button.addEventListener("click", () => refreshTreemap(target));
    frag.appendChild(button);
  };

  add(treeState.source === "host" ? "/" : "watched directory", root, names.length === 0);

  let walk = root;
  names.forEach((name, i) => {
    walk = walk.replace(/\/$/, "") + "/" + name;
    const sep = document.createElement("span");
    sep.className = "sep";
    sep.textContent = "/";
    frag.appendChild(sep);
    add(name, walk, i === names.length - 1);
  });

  holder.replaceChildren(frag);
  $("treeUp").disabled = !level.parent;
}

/*
 * Squarified layout.
 *
 * Slicing the rectangle straight down the list is far simpler and produces
 * slivers - a 4000-pixel by 2-pixel shape is impossible to hover, impossible
 * to label, and gives no sense of its area. Squarifying keeps each rectangle
 * near square by laying out a run of items at a time and stopping the run when
 * adding another would make the worst aspect ratio worse.
 */
function squarify(items, x, y, width, height) {
  const out = [];
  const total = items.reduce((sum, item) => sum + Math.max(0, item.bytes), 0);
  if (total <= 0 || width <= 0 || height <= 0) return out;

  const scale = (width * height) / total;
  const queue = items.map(item => ({ item, area: Math.max(0, item.bytes) * scale }));

  let cx = x, cy = y, cw = width, ch = height;

  const worst = (row, side) => {
    if (!row.length) return Infinity;
    const sum = row.reduce((s, r) => s + r.area, 0);
    const max = Math.max(...row.map(r => r.area));
    const min = Math.min(...row.map(r => r.area));
    const side2 = side * side;
    const sum2 = sum * sum;
    return Math.max((side2 * max) / sum2, sum2 / (side2 * min));
  };

  while (queue.length) {
    const side = Math.min(cw, ch);
    const row = [];

    while (queue.length) {
      const next = [...row, queue[0]];
      if (row.length && worst(next, side) > worst(row, side)) break;
      row.push(queue.shift());
      if (side <= 0) break;
    }

    const sum = row.reduce((s, r) => s + r.area, 0);
    const thickness = sum / side || 0;

    let offset = 0;
    for (const entry of row) {
      const length = entry.area / thickness || 0;
      if (cw >= ch) {
        out.push({ ...entry.item, x: cx, y: cy + offset, w: thickness, h: length });
      } else {
        out.push({ ...entry.item, x: cx + offset, y: cy, w: length, h: thickness });
      }
      offset += length;
    }

    if (cw >= ch) { cx += thickness; cw -= thickness; } else { cy += thickness; ch -= thickness; }
    if (cw < 0.5 || ch < 0.5) break;
  }

  return out;
}

/** Colour by kind, and by age within files, so old and cold stands out. */
/*
 * What a rectangle's colour means, which is a choice rather than a fact.
 *
 * By age answers "what is active" and by type answers "what is it" - two
 * genuinely different hunts. A directory of week-old video and a directory of
 * week-old logs are the same rectangle under one scheme and obviously different
 * under the other.
 *
 * The type palette is the same one the Storage page uses, and it is fixed per
 * type rather than assigned by rank. A colour that means "video" here and
 * "archives" after the next scan is worse than no colour at all.
 */
function treeColour(node) {
  if (node.kind === "link") return "#4a4a4a";
  if (node.kind === "pool") return "#3a3a3a";

  if (treeState.colourBy === "type") {
    // A directory has no type of its own. Colouring it by its heaviest child
    // would be inventing a fact; it gets the neutral blue it always had.
    if (node.kind === "dir") return "#2f6f8f";
    return typeColor(fileTypeOfNode(node) || "other");
  }

  if (node.kind === "dir") return "#2f6f8f";

  const days = node.modified_at ? (Date.now() / 1000 - node.modified_at) / 86400 : null;
  if (days === null) return "#6b7f3a";
  if (days < 1) return "#c9603f";      // touched today
  if (days < 7) return "#b08040";
  if (days < 90) return "#6b7f3a";
  return "#41603f";                     // cold
}

/*
 * The same classification the scanner does, in the browser.
 *
 * Repeated rather than sent down with every rectangle: the treemap already
 * carries a name per node and this is a lookup, where shipping a type field for
 * ten thousand nodes is bytes on the wire for something the client can work out.
 * It has to stay in step with fileTypeGroups() in lib/collect.php - if the two
 * disagree, the map is coloured by one rule and the pie by another.
 */
const TYPE_EXTENSIONS = {
  images: "jpg jpeg png gif webp bmp tif tiff svg ico heic avif psd raw cr2 nef xcf",
  video: "mp4 m4v mov avi mkv webm wmv flv mpg mpeg ts 3gp",
  audio: "mp3 wav flac aac ogg oga m4a wma aiff opus",
  archives: "zip gz br tgz bz2 xz 7z rar tar zst lz lzma jar war iso dmg pkg deb rpm cab backup",
  web: "html htm css js mjs cjs map woff woff2 ttf eot otf json xml scss less vue ts tsx jsx xsd xsl xslt htaccess htpasswd mustache twig hbs ejs pug",
  documents: "pdf doc docx xls xlsx ppt pptx odt ods odp rtf txt md csv tsv epub mobi license manual readme changelog nfo",
  code: "php py rb pl sh bash c h cpp hpp go rs java cs swift kt lua sql yml yaml toml ini conf patch diff",
  databases: "db sqlite sqlite3 mdb frm ibd myd myi dump bak idx pack mmdb rdb aof",
  logs: "log out err",
  executables: "exe dll so dylib class bin com msi app o a",
};

const EXTENSION_TYPE = (() => {
  const index = new Map();
  for (const [type, list] of Object.entries(TYPE_EXTENSIONS)) {
    for (const extension of list.split(" ")) index.set(extension, type);
  }
  return index;
})();

/*
 * The type of a node, from the server's word where there is one.
 *
 * The scanner decides types by extension *and*, for a file with no extension,
 * by whether it is executable - which is a fact the browser cannot see. So a
 * treemap node carrying an `executable` flag is trusted, and the name is only
 * consulted when there is nothing better.
 */
function fileTypeOfNode(node) {
  if (node.kind === "dir" || node.kind === "pool" || node.kind === "link") return null;
  const name = node.name || "";
  const dot = name.lastIndexOf(".");
  if (dot <= 0) return node.executable ? "executables" : "other";
  return fileTypeOfName(name);
}

function fileTypeOfName(name) {
  // A leading dot is not a separator - `.htaccess` is named entirely by what
  // follows it, which is what PHP's pathinfo reports as the extension too.
  const dot = name.lastIndexOf(".");
  if (dot < 0) return "other";
  const extension = name.slice(dot + 1).toLowerCase();
  if (!extension || extension.length > 12 || !/^[a-z0-9]+$/.test(extension)) return "other";
  return EXTENSION_TYPE.get(extension) || "other";
}

/* What the current colouring means, said in words under the map. */
function renderTreeLegend() {
  const legend = $("treeLegend");
  const entries = treeState.colourBy === "type"
    ? [
      ...Object.keys(TYPE_EXTENSIONS).map(type => [type, typeColor(type)]),
      ["other", typeColor("other")],
      ["directory", "#2f6f8f"],
    ]
    : [
      ["touched today", "#c9603f"],
      ["this week", "#b08040"],
      ["this quarter", "#6b7f3a"],
      ["older", "#41603f"],
      ["directory", "#2f6f8f"],
    ];

  const frag = document.createDocumentFragment();
  for (const [label, colour] of [...entries, ["symlink", "#4a4a4a"], ["pooled", "#3a3a3a"]]) {
    const span = document.createElement("span");
    span.style.setProperty("--dot", colour);
    span.textContent = label;
    frag.appendChild(span);
  }
  legend.replaceChildren(frag);
}

function drawTreemap() {
  const canvas = $("treemap");
  if (!canvas) return;

  rememberDraw(canvas, drawTreemap);
  if (canvas.clientWidth === 0) return;

  const ratio = window.devicePixelRatio || 1;
  const width = canvas.clientWidth;
  const height = 460;

  canvas.width = Math.max(1, Math.floor(width * ratio));
  canvas.height = Math.max(1, Math.floor(height * ratio));
  canvas.style.height = height + "px";

  const ctx = canvas.getContext("2d");
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.clearRect(0, 0, width, height);

  const level = treeState.level;
  if (!level || !level.children.length) {
    ctx.fillStyle = "#9a9a9a";
    ctx.font = "13px system-ui, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(
      level ? "Nothing to show in this directory" : "Nothing measured yet",
      width / 2, height / 2);
    treeState.boxes = [];
    return;
  }

  /*
   * Filtering shrinks directories, it does not hide them.
   *
   * "Show me only audio" has to answer "and which directories hold it", so a
   * directory keeps its rectangle sized by the audio *inside* it rather than
   * vanishing because it is not itself an audio file. That number comes from
   * the server, which walked the tree; the browser only knows the totals.
   */
  const children = filteredChildren(level.children);

  if (!children.length) {
    ctx.fillStyle = "#9a9a9a";
    ctx.font = "13px system-ui, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(
      `Nothing of that type in this directory`,
      width / 2, height / 2);
    treeState.boxes = [];
    return;
  }

  treeState.boxes = squarify(children, 0, 0, width, height);

  ctx.textAlign = "left";
  ctx.textBaseline = "top";

  for (const box of treeState.boxes) {
    if (box.w < 1 || box.h < 1) continue;

    ctx.fillStyle = treeColour(box);
    ctx.fillRect(box.x, box.y, box.w, box.h);

    ctx.strokeStyle = "rgba(0,0,0,.55)";
    ctx.lineWidth = 1;
    ctx.strokeRect(box.x + .5, box.y + .5, box.w - 1, box.h - 1);

    /*
     * A measurement that ran out of time is hatched.
     *
     * The number under it is a lower bound, and a lower bound drawn exactly
     * like a measurement is how the thing you are hunting gets dismissed.
     */
    if (box.complete === false) {
      ctx.save();
      ctx.beginPath();
      ctx.rect(box.x, box.y, box.w, box.h);
      ctx.clip();
      ctx.strokeStyle = "rgba(255,255,255,.22)";
      for (let i = -box.h; i < box.w; i += 8) {
        ctx.beginPath();
        ctx.moveTo(box.x + i, box.y);
        ctx.lineTo(box.x + i + box.h, box.y + box.h);
        ctx.stroke();
      }
      ctx.restore();
    }

    // Labels only where one genuinely fits; a clipped name is worse than none.
    if (box.w > 54 && box.h > 26) {
      ctx.save();
      ctx.beginPath();
      ctx.rect(box.x + 3, box.y + 2, box.w - 6, box.h - 4);
      ctx.clip();
      ctx.fillStyle = "rgba(255,255,255,.95)";
      ctx.font = "600 11px system-ui, sans-serif";
      ctx.fillText(box.name, box.x + 5, box.y + 4);
      ctx.fillStyle = "rgba(255,255,255,.72)";
      ctx.font = "10px system-ui, sans-serif";
      ctx.fillText(humanBytes(box.bytes), box.x + 5, box.y + 18);

      /*
       * A globe on the directory a website is served from.
       *
       * The one question somebody staring at a home directory usually has is
       * "which of these forty is the live site", and it is answerable without
       * opening any of them. The domain goes beside it only if it fits - a name
       * clipped to "exam" is worse than no name, and the details panel has it
       * in full either way.
       */
      if (box.site?.is_site) {
        ctx.font = "12px system-ui, sans-serif";
        const globe = "\u{1F310}";
        ctx.fillStyle = "rgba(255,255,255,.95)";
        ctx.fillText(globe, box.x + 5, box.y + 32);

        const domain = (box.site.domains || [])[0];
        if (domain) {
          const at = box.x + 5 + ctx.measureText(globe).width + 4;
          ctx.font = "10px system-ui, sans-serif";
          if (at + ctx.measureText(domain).width + 5 <= box.x + box.w) {
            ctx.fillStyle = box.site.confident
              ? "rgba(255,255,255,.85)"
              : "rgba(255,255,255,.55)";
            ctx.fillText(domain, at, box.y + 34);
          }
        }
      }

      ctx.restore();
    }
  }

  if (treeState.hover) outlineBox(ctx, treeState.hover);
}

function outlineBox(ctx, box) {
  ctx.strokeStyle = "#ffffff";
  ctx.lineWidth = 2;
  ctx.strokeRect(box.x + 1, box.y + 1, box.w - 2, box.h - 2);
}

/*
 * The children that match the chosen type, resized to just that part of them.
 *
 * A file either is the type or it is not. A directory carries a per-type
 * breakdown from the walk, so it keeps a rectangle proportional to how much of
 * that type it contains - which is the whole point: the directory holding the
 * audio is the answer, not the individual mp3s.
 */
function filteredChildren(children) {
  const wanted = treeState.typeFilter;
  if (!wanted) return children;

  const out = [];
  for (const node of children) {
    if (node.kind === "dir") {
      const bytes = Number(node.type_bytes?.[wanted] || 0);
      if (bytes > 0) out.push({ ...node, bytes });
      continue;
    }
    if (node.kind === "pool") continue;
    if (fileTypeOfNode(node) === wanted) out.push(node);
  }
  return out;
}

function boxAt(x, y) {
  // Reversed: later rectangles are drawn on top, so they win a tie.
  for (let i = treeState.boxes.length - 1; i >= 0; i--) {
    const box = treeState.boxes[i];
    if (x >= box.x && x <= box.x + box.w && y >= box.y && y <= box.y + box.h) return box;
  }
  return null;
}

/*
 * The tooltip is built from data already in hand.
 *
 * Every fact here arrived with the level, from the same stat that measured the
 * entry. Fetching on hover would mean the tooltip appears after the pointer has
 * moved on, which is the difference between a tooltip and an annoyance.
 */
function showTip(box, px, py) {
  const tip = $("treeTip");
  tip.hidden = false;

  const rows = [];
  const add = (label, value) => { if (value !== null && value !== undefined && value !== "") rows.push([label, value]); };

  add("Kind", box.kind === "dir" ? "directory" : box.kind === "link" ? "symlink" : box.kind === "pool" ? "pooled entries" : "file");
  if (box.kind === "dir" || box.kind === "pool") add("Contains", count(box.files, "file"));
  add("Modified", box.modified_at ? `${humanTime(box.modified_at)} (${ago(box.modified_at)})` : null);
  add("Inode changed", box.changed_at ? humanTime(box.changed_at) : null);
  add("Last read", box.accessed_at ? humanTime(box.accessed_at) : null);
  add("Owner", box.owner);
  add("Mode", box.mode);
  /*
   * Files only, and the distinction is not pedantic.
   *
   * A directory's link count is 2 plus the number of subdirectories it holds -
   * nothing to do with hard links to the same data. Printing "22 names,
   * deleting one frees nothing" against a directory is confidently wrong about
   * something somebody might act on.
   */
  if (box.kind === "file" && Number(box.links) > 1) {
    add("Links", `${box.links} names — deleting one frees nothing`);
  }
  if (box.complete === false) {
    add("Measured", "incomplete — at least this size");
  }

  const frag = document.createDocumentFragment();
  const name = document.createElement("div");
  name.className = "tip-name";
  name.textContent = box.name;
  frag.appendChild(name);

  const size = document.createElement("div");
  size.className = "tip-size";
  size.textContent = (box.complete === false ? "at least " : "") + humanBytes(box.bytes);
  frag.appendChild(size);

  const dl = document.createElement("dl");
  for (const [label, value] of rows) {
    const dt = document.createElement("dt");
    dt.textContent = label;
    const dd = document.createElement("dd");
    dd.textContent = String(value);
    dl.append(dt, dd);
  }
  frag.appendChild(dl);
  tip.replaceChildren(frag);

  // Kept inside the panel: a tooltip half off the right edge is unreadable.
  const holder = tip.parentElement;
  const maxX = holder.clientWidth - tip.offsetWidth - 8;
  const maxY = holder.clientHeight - tip.offsetHeight - 8;
  tip.style.left = Math.max(4, Math.min(px + 14, maxX)) + "px";
  tip.style.top = Math.max(4, Math.min(py + 14, maxY)) + "px";
}

$("treemap").addEventListener("mousemove", event => {
  const rect = event.currentTarget.getBoundingClientRect();
  const x = event.clientX - rect.left;
  const y = event.clientY - rect.top;
  const box = boxAt(x, y);

  if (box !== treeState.hover) {
    treeState.hover = box;
    drawTreemap();
  }

  if (box) showTip(box, x, y);
  else $("treeTip").hidden = true;
});

$("treemap").addEventListener("mouseleave", () => {
  treeState.hover = null;
  $("treeTip").hidden = true;
  drawTreemap();
});

$("treemap").addEventListener("click", event => {
  const rect = event.currentTarget.getBoundingClientRect();
  const box = boxAt(event.clientX - rect.left, event.clientY - rect.top);
  if (!box) return;

  /*
   * A click opens the details; going in is a second, deliberate act.
   *
   * Descending on a plain click meant a directory could only ever be passed
   * through - there was no way to ask about the thing you had just landed on,
   * which is where the size you are chasing usually is.
   */
  if (box.kind === "pool") return;

  /*
   * The host map is a picture of what root recorded, not of files this account
   * can reach. Asking the inspector about one would only ever come back
   * refused, so the map says why rather than sending a request that cannot work.
   */
  if (treeState.source === "host") {
    notice("These are the host's own files, recorded by root. This account cannot "
      + "examine them directly — double-click to go in.");
    return;
  }

  inspect(box.path);
});

$("treemap").addEventListener("dblclick", event => {
  const rect = event.currentTarget.getBoundingClientRect();
  const box = boxAt(event.clientX - rect.left, event.clientY - rect.top);
  // A symlink is never followed, here or anywhere else in this tool.
  if (box && box.kind === "dir") refreshTreemap(box.path);
});

$("treeColourBy").addEventListener("change", () => {
  treeState.colourBy = $("treeColourBy").value;
  drawTreemap();
  renderTreeLegend();
});

$("treeTypeFilter").addEventListener("change", () => {
  treeState.typeFilter = $("treeTypeFilter").value;
  drawTreemap();
  renderTreeNote();
});

$("inspectClose").addEventListener("click", () => {
  $("inspectPanel").hidden = true;
  treeState.selected = null;
});

$("treeUp").addEventListener("click", () => {
  if (treeState.level?.parent) refreshTreemap(treeState.level.parent);
});

$("treeSource").addEventListener("change", () => {
  treeState.source = $("treeSource").value;
  treeState.path = "";
  refreshTreemap("");
});



/*
 * Everything about one path, from a click on the map.
 *
 * Laid out in the order somebody actually asks: what is this, how big, is it
 * still growing, can I see inside it, and can I get rid of it. The delete
 * button comes last on purpose - it should be the thing you reach after
 * reading, not the thing your eye lands on first.
 */
async function inspect(path) {
  const panel = $("inspectPanel");
  panel.hidden = false;
  treeState.selected = path;

  $("inspectTitle").textContent = "Details";
  $("inspectPath").textContent = path;
  $("inspectBody").replaceChildren(note("Looking\u2026"));

  let data;
  try {
    data = await api("inspect", { path });
  } catch (error) {
    $("inspectBody").replaceChildren(note(String(error.message || error)));
    return;
  }

  state.inspected = data;
  renderInspect(data);

  /*
   * The page does not move.
   *
   * Scrolling to the panel takes the map out from under the pointer mid-hunt,
   * which reads as the page having jumped somewhere on its own. The panel is
   * directly below the map and the map shrinks nothing, so it is already
   * findable; if it happens to be off-screen the reader scrolls, which is the
   * one action they were not expecting to have done for them.
   */
}

function renderInspect(data) {
  const body = $("inspectBody");
  const parts = [];

  $("inspectTitle").textContent = data.name;
  $("inspectPath").textContent = data.path;

  const f = data.facts;
  const facts = [
    ["Kind", data.kind === "dir" ? "directory" : data.kind],
    ["Size", data.kind === "dir"
      ? `${humanBytes(data.contents.bytes)}${data.contents.complete ? "" : " or more"}`
      : humanBytes(f.size_bytes)],
  ];

  /*
   * Apparent size and allocated size, when they differ.
   *
   * They usually do, in both directions: a small file still occupies a whole
   * block, and a sparse one reports a size the disk has never held. Showing
   * only the first is why a directory of tiny files never adds up to what df
   * says.
   */
  if (data.kind === "file" && data.allocated_bytes != null
      && Math.abs(data.allocated_bytes - f.size_bytes) > 4096) {
    facts.push(["On disk", `${humanBytes(data.allocated_bytes)} allocated `
      + (data.allocated_bytes > f.size_bytes ? "(block padding)" : "(sparse file)")]);
  }

  if (data.type) facts.push(["Type", `${data.type}${data.extension ? ` (.${data.extension})` : ""}`]);
  if (data.category) facts.push(["Watched as", data.category]);

  facts.push(
    ["Last modified", f.modified_at ? `${humanTime(f.modified_at)} (${humanDuration(Date.now() / 1000 - f.modified_at)} ago)` : "unknown"],
    ["Last read", f.accessed_at ? humanTime(f.accessed_at) : "unknown"],
    // Named for what it is: Unix does not record a creation time, and every
    // file manager that labels ctime "created" is repeating the same mistake.
    ["Inode last changed", f.changed_at ? humanTime(f.changed_at) : "unknown"],
    ["Owner", f.owner ?? "unknown"],
    ["Permissions", f.mode ? `${f.mode} \u2014 ${modeInWords(f.mode, data.kind === "dir")}` : "unknown"],
  );

  /*
   * Only for files. A directory's link count is not what it looks like.
   *
   * Every subdirectory holds a `..` pointing back at its parent, so a directory
   * with three children has an nlink of five - and reporting that as "the same
   * bytes are reachable by five paths" is nonsense dressed up as a warning.
   */
  if (data.kind === "file" && f.links > 1) {
    facts.push(["Hard links", `${f.links} \u2014 the same bytes are reachable by ${f.links} paths, `
      + "and removing this one frees nothing until the last goes"]);
  } else if (data.kind === "dir" && f.links > 2) {
    facts.push(["Subdirectories", `${f.links - 2}, counted from the link count`]);
  }
  if (data.inode) facts.push(["Inode", String(data.inode)]);

  if (data.kind === "link") {
    facts.push(["Points at", data.link_target ?? "unreadable"]);
    facts.push(["Target exists", data.link_resolves ? "yes" : "no \u2014 this link is broken"]);
  }

  parts.push(section("What it is", factGrid(facts)));

  if (data.kind === "dir") parts.push(...inspectDirectory(data));
  if (data.image?.is_image) parts.push(inspectImage(data));

  parts.push(inspectHistory(data));
  parts.push(inspectActions(data));

  body.replaceChildren(...parts);
}

/*
 * A mode like 0750, said out loud.
 *
 * Four octal digits are a fact nobody should have to decode in their head, and
 * the meaning genuinely differs between a file and a directory: x on a file
 * means "may be run", x on a directory means "may be entered", and r on a
 * directory without x lists the names and opens none of them.
 */
function modeInWords(mode, isDir) {
  const digits = String(mode).padStart(4, "0").slice(-3);
  const who = ["owner", "group", "everyone else"];

  const said = [...digits].map((d, i) => {
    const bits = Number(d);
    const can = [];
    if (bits & 4) can.push("read");
    if (bits & 2) can.push("write");
    if (bits & 1) can.push(isDir ? "enter" : "run");
    return `${who[i]}: ${can.length ? can.join(" and ") : "nothing"}`;
  });

  return said.join(", ");
}

function inspectDirectory(data) {
  const c = data.contents;
  const out = [];

  const facts = [
    ["Directly inside", `${count(c.entries.files, "file")}, `
      + `${count(c.entries.dirs, "directory", "directories")}, `
      + `${count(c.entries.links, "symlink")}`],
    ["Files below here", `${humanCount(c.files)}${c.complete ? "" : " so far"}`],
  ];
  if (!c.complete) {
    facts.push(["Measurement", "The walk ran out of time. The size above is a lower "
      + "bound \u2014 at least that much, not exactly that much."]);
  }
  if (data.site?.is_site) {
    facts.push([
      "Serves",
      data.site.domains.length
        ? `\u{1F310} ${data.site.domains.join(", ")}`
        : "\u{1F310} a website, domain unknown",
    ]);
    // Whether this is read from cPanel's own record or inferred from the name,
    // because those are very different levels of certainty.
    facts.push(["How that is known", data.site.why]);
  }
  out.push(section("What is in it", factGrid(facts)));

  /*
   * Two questions, two pies, side by side.
   *
   * "Which file is the big one" and "what kind of thing is this directory" are
   * both answered by area, and side by side you can see when they disagree -
   * one enormous archive next to a directory that is nine-tenths images means
   * something different from either chart alone.
   */
  const pair = document.createElement("div");
  pair.className = "pie-pair";

  if (c.biggest.length) {
    pair.appendChild(inspectPie(
      "The biggest files directly inside",
      c.biggest.map(b => ({ label: b.name, value: Number(b.bytes) })),
      c.entries.files > c.biggest.length
        ? `The ${c.biggest.length} biggest of ${count(c.entries.files, "file")} directly `
          + "inside. Subdirectories are not counted here."
        : "Every file directly inside. Subdirectories are not counted here."
    ));
  }

  if (c.types.length) {
    pair.appendChild(inspectPie(
      "By type, everything below",
      c.types.map(t => ({
        label: t.type, value: Number(t.bytes), color: typeColor(t.type),
      })),
      "The whole subtree, not just this level."
    ));
  }

  if (pair.children.length) out.push(section("What it is made of", pair));

  return out;
}

/** One titled pie in the details panel, drawn once it has a width. */
function inspectPie(title, slices, note_) {
  const box = document.createElement("div");
  box.className = "pie-half";

  const heading = document.createElement("h4");
  heading.textContent = title;
  box.appendChild(heading);

  const canvas = document.createElement("canvas");
  canvas.className = "pie small";
  box.appendChild(canvas);
  box.appendChild(note(note_));

  requestAnimationFrame(() => drawPieChart(canvas, slices, { empty: "Nothing to show" }));
  return box;
}

/*
 * The picture, if the file's own first bytes say it is one.
 *
 * Sized in the page rather than on the server: a real thumbnailer needs an
 * image library that shared hosts do not reliably have, and one that silently
 * did nothing where GD was missing would be worse than an honest full-size
 * image scaled by the browser. The size is stated so nobody is surprised by
 * what they just asked the connection to carry.
 */
function inspectImage(data) {
  const wrap = document.createElement("div");

  const img = document.createElement("img");
  img.className = "thumb";
  img.loading = "lazy";
  img.alt = data.name;
  img.src = `thumb.php?path=${encodeURIComponent(data.path)}`;
  img.addEventListener("error", () => {
    img.replaceWith(note("The preview could not be loaded."));
  });
  wrap.appendChild(img);

  const said = data.image.width
    ? `${data.image.format.toUpperCase()}, ${data.image.width}\u00d7${data.image.height} pixels, `
      + `${humanBytes(data.facts.size_bytes)} sent in full and scaled here.`
    : `${data.image.format.toUpperCase()}, ${humanBytes(data.facts.size_bytes)}.`;
  wrap.appendChild(note(said));

  return section("Preview", wrap);
}

function inspectHistory(data) {
  const history = data.history;

  if (!history.recorded || history.rows.length < 2) {
    return section("Size over time", note(history.recorded
      ? "Recorded, but only once so far \u2014 there is no change to draw yet."
      : "This path is not one of the ones kept between scans. Only the biggest files "
        + "and directories, and the biggest of each category, are recorded each time; "
        + "everything else is measured and not stored."));
  }

  const holder = document.createElement("div");
  const canvas = document.createElement("canvas");
  holder.appendChild(canvas);

  const first = Number(history.rows[0].bytes);
  const last = Number(history.rows[history.rows.length - 1].bytes);
  const change = last - first;

  holder.appendChild(note(
    change === 0
      ? `Unchanged across ${count(history.rows.length, "scan")}.`
      : `${change > 0 ? "Grew" : "Shrank"} by ${humanBytes(Math.abs(change))} across `
        + `${count(history.rows.length, "scan")}, from ${humanBytes(first)} to ${humanBytes(last)}.`
  ));

  const wrap = section("Size over time", holder);

  // Drawn after it is in the document, or the canvas has no width to size to.
  requestAnimationFrame(() => drawLineChart(
    canvas,
    history.rows,
    [{ key: "bytes", label: data.name }],
    { xKey: "started_at", axis: bytesAxis, zeroBased: true, empty: "No history" }
  ));

  return wrap;
}

/*
 * What can be done to it, and what cannot, and why.
 *
 * The refusals are the useful half. "Delete unavailable" sends somebody hunting
 * for another way to do it; "this is not in a category the config allows
 * deleting from" tells them which line to change if they disagree with the
 * policy.
 */
function inspectActions(data) {
  const wrap = document.createElement("div");
  wrap.className = "inspect-actions";

  const p = data.permissions;

  if (p.view) {
    const view = document.createElement("button");
    view.textContent = "Read this log";
    view.addEventListener("click", () => {
      showView("storage-files");
      showLogPath(data.path);
    });
    wrap.appendChild(view);
  } else if (p.view_reason && data.kind !== "dir") {
    // Not for directories: "a directory has no contents to read" is answering a
    // question nobody asked and reads as a claim that the directory is empty.
    wrap.appendChild(note(p.view_reason));
  }

  if (p.delete) {
    const remove = document.createElement("button");
    remove.className = "danger";
    remove.textContent = data.kind === "dir"
      ? "Delete this directory and everything in it"
      : "Delete this file";
    remove.addEventListener("click", () => deleteFromInspect(data));
    wrap.appendChild(remove);
  } else if (p.delete_reason) {
    wrap.appendChild(note(p.delete_reason));
  }

  return section("What you can do with it", wrap);
}

async function deleteFromInspect(data) {
  const what = data.kind === "dir"
    ? `Delete ${data.path} and everything inside it? `
      + `That is ${humanBytes(data.contents.bytes)} in ${humanCount(data.contents.files)} files, `
      + "and it cannot be undone."
    : `Delete ${data.path}? That is ${humanBytes(data.facts.size_bytes)}, and it cannot be undone.`;

  if (!confirm(what)) return;

  try {
    const result = data.kind === "dir"
      ? await post("delete-dir", { path: data.path })
      : await post("delete-file", { path: data.path });

    if (data.kind === "dir") {
      notice(
        `Removed ${humanBytes(result.bytes)} in ${count(result.files, "file")}`
          + (result.skipped?.length ? `. ${count(result.skipped.length, "path")} could not be removed.` : "."),
        !result.skipped?.length
      );
    } else {
      notice(`Removed ${humanBytes(result.bytes_freed ?? data.facts.size_bytes)}.`, true);
    }

    $("inspectPanel").hidden = true;
    treeState.selected = null;
    // Back to the parent: the thing that was being looked at is gone, and
    // redrawing the level it was in is what you want to see next.
    await refreshTreemap(data.kind === "dir" ? data.parent : treeState.path);
  } catch (error) {
    notice(String(error.message || error));
  }
}

/*
 * How to set the host collector up, for whoever has to do it.
 *
 * Written as steps with a tick against the ones already done, because the
 * failure this replaces is a panel that says "no host readings" and leaves the
 * reader to work out which of five things is missing.
 */
async function refreshHostSchedule() {
  const body = $("hostScheduleBody");
  if (!state.signedIn) {
    body.replaceChildren(note("Sign in to see the setup instructions."));
    return;
  }

  if (!body.childElementCount) body.replaceChildren(spinner());

  let data;
  try {
    data = await api("host-setup");
  } catch (error) {
    body.replaceChildren(note(String(error.message || error)));
    return;
  }

  const parts = [];

  const state_ = document.createElement("p");
  state_.className = data.database_readable ? "ok" : "muted";
  state_.textContent = data.database_readable
    ? `Working. The collector's database is at ${data.database} and this account can read it.`
    : data.database_exists
      ? `The database exists at ${data.database} but this account cannot read it — `
        + "which is the ownership step below."
      : "The collector has never run. Nothing on this site that describes the whole "
        + "machine will have anything in it until it does.";
  parts.push(state_);

  const list = document.createElement("ol");
  list.className = "steps";
  for (const step of data.steps) {
    const li = document.createElement("li");
    li.className = step.done ? "step done" : "step";

    const title = document.createElement("strong");
    title.textContent = `${step.done ? "\u2713 " : ""}${step.title}`;
    li.appendChild(title);

    const p = document.createElement("p");
    p.className = "muted small";
    p.textContent = step.body;
    li.appendChild(p);

    list.appendChild(li);
  }
  parts.push(section("What has to happen", list));

  parts.push(section("The line to paste, as root", paste(data.cron_line)));

  /*
   * The no-root path, said plainly rather than left out.
   *
   * Plenty of people running this have shared hosting and will never have root.
   * Telling them only "run this as root" leaves them stuck; telling them what
   * the account-side run does and does not collect lets them decide.
   */
  const without = document.createDocumentFragment();
  without.appendChild(note("No root? The collector runs as an ordinary account too. It "
    + "collects less, and here is exactly what less means."));

  const compare = document.createElement("div");
  compare.className = "compare";
  compare.appendChild(compareColumn("Only as root", data.as_root));
  compare.appendChild(compareColumn("Also as your own account", data.as_account));
  without.appendChild(compare);

  without.appendChild(paste(data.account_cron_line));
  parts.push(section("If you do not have a host account", without));

  body.replaceChildren(...parts);
}

function compareColumn(title, items) {
  const box = document.createElement("div");
  const heading = document.createElement("h4");
  heading.textContent = title;
  box.appendChild(heading);
  box.appendChild(listOf(items));
  return box;
}

/** A block of text meant to be copied, with a button that copies it. */
function paste(text) {
  const wrap = document.createElement("div");
  wrap.className = "paste-wrap";

  const pre = document.createElement("pre");
  pre.className = "paste";
  pre.textContent = text;
  wrap.appendChild(pre);

  const button = document.createElement("button");
  button.className = "link";
  button.textContent = "Copy";
  button.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(text);
      button.textContent = "Copied";
      setTimeout(() => { button.textContent = "Copy"; }, 1500);
    } catch {
      // Clipboard access is refused outside a secure context, which is exactly
      // where somebody runs this over plain HTTP on a LAN. The text is on
      // screen and selectable either way.
      button.textContent = "Select it and copy";
    }
  });
  wrap.appendChild(button);

  return wrap;
}

/*
 * What gets emailed, and to whom.
 *
 * The conditions are checkboxes over the thresholds the alerting code already
 * reads - unticking one sets its threshold to zero, which is exactly how the
 * rules have always been turned off. A second flag beside the threshold could
 * disagree with it, and then no answer on this page would be trustworthy.
 */
async function refreshEmail() {
  const body = $("emailBody");
  if (!state.signedIn) {
    body.replaceChildren(note("Sign in to change the email settings."));
    return;
  }

  if (!body.childElementCount) body.replaceChildren(spinner());

  let data;
  try {
    data = await api("email");
  } catch (error) {
    body.replaceChildren(note(String(error.message || error)));
    return;
  }

  renderEmail(data);
}

function renderEmail(data) {
  const body = $("emailBody");
  state.email = data;
  const parts = [];

  if (!data.mail_available) {
    parts.push(note("This PHP has no mail() function, so nothing can be sent from here "
      + "whatever is set below. That is a host setting, not one of these."));
  }

  const form = document.createElement("div");
  form.className = "settings";

  form.appendChild(fieldRow("Send alerts by email", (() => {
    const box = document.createElement("input");
    box.type = "checkbox";
    box.id = "emailEnabled";
    box.checked = data.enabled;
    return box;
  })(), "Nothing is sent while this is off, however bad things get."));

  form.appendChild(fieldRow("Send to", (() => {
    const input = document.createElement("input");
    input.type = "email";
    input.id = "emailTo";
    input.value = data.to;
    input.placeholder = "you@example.com";
    input.size = 34;
    return input;
  })(), "Where the alerts go."));

  form.appendChild(fieldRow("Send from", (() => {
    const input = document.createElement("input");
    input.type = "email";
    input.id = "emailFrom";
    input.value = data.from;
    input.placeholder = "monitor@example.com";
    input.size = 34;
    return input;
  })(), "Optional. Some hosts reject mail with no From header; others rewrite it "
    + "whatever you put here."));

  form.appendChild(fieldRow("Do not repeat within", (() => {
    const select = document.createElement("select");
    select.id = "emailCooldown";
    for (const [seconds, label] of [
      [3600, "an hour"], [21600, "six hours"], [43200, "twelve hours"], [86400, "a day"],
    ]) {
      const option = document.createElement("option");
      option.value = String(seconds);
      option.textContent = label;
      option.selected = Number(data.cooldown_seconds) === seconds;
      select.appendChild(option);
    }
    return select;
  })(), "However bad things get. An alert that arrives every minute becomes a filter rule."));

  parts.push(section("Where to send", form));

  const list = document.createElement("div");
  list.className = "conditions";
  for (const condition of data.conditions) {
    const label = document.createElement("label");
    label.className = "condition";

    const box = document.createElement("input");
    box.type = "checkbox";
    box.checked = condition.on;
    box.dataset.condition = condition.key;
    label.appendChild(box);

    const text = document.createElement("span");
    const name = document.createElement("strong");
    name.textContent = condition.name;
    text.appendChild(name);

    const detail = document.createElement("span");
    detail.className = "muted small";
    detail.textContent = condition.detail;
    text.appendChild(detail);

    label.appendChild(text);
    list.appendChild(label);
  }
  parts.push(section("What to send about", list));

  const save = document.createElement("div");
  save.className = "inspect-actions";
  const button = document.createElement("button");
  button.id = "emailSave";
  button.textContent = "Save these settings";
  button.disabled = !data.writable;
  save.appendChild(button);

  if (!data.writable) {
    save.appendChild(note("lib/config.php is not writable by the web server, so this page "
      + "can show the settings but not change them. Use setup.php, or edit the file."));
  }
  parts.push(save);

  if (data.recent.length) {
    parts.push(section("Recently sent", simpleTable(
      ["When", "To", "Result", "About"],
      data.recent.map(r => [
        humanTime(r.at), r.target || "\u2014", r.outcome, r.detail || "\u2014",
      ]),
      [null, "name", null, null]
    )));
  }

  body.replaceChildren(...parts);
  button.addEventListener("click", saveEmailSettings);
}

function fieldRow(label, control, hint) {
  const row = document.createElement("div");
  row.className = "settings-row";

  const name = document.createElement("label");
  name.className = "settings-label";
  name.textContent = label;
  name.htmlFor = control.id;
  row.appendChild(name);

  const wrap = document.createElement("div");
  wrap.appendChild(control);
  if (hint) {
    const small = document.createElement("div");
    small.className = "muted small";
    small.textContent = hint;
    wrap.appendChild(small);
  }
  row.appendChild(wrap);

  return row;
}

async function saveEmailSettings() {
  const conditions = [...document.querySelectorAll("[data-condition]")]
    .filter(box => box.checked)
    .map(box => box.dataset.condition)
    .join(",");

  try {
    const result = await post("email-save", {
      enabled: $("emailEnabled").checked ? "1" : "0",
      to: $("emailTo").value.trim(),
      from: $("emailFrom").value.trim(),
      cooldown_seconds: $("emailCooldown").value,
      conditions,
    });
    notice("Saved. New settings apply from the next alerting run.", true);
    // Rendered from what the save wrote, not from a fresh read - see the note
    // on clearstatcache in doEmailSave.
    if (result.settings) renderEmail(result.settings);
    else await refreshEmail();
  } catch (error) {
    notice(String(error.message || error));
  }
}

/*
 * The server's own logs, and whether this account can actually read them.
 *
 * Listed even when unreadable. "Configured but refused by the host" is a
 * different state from "not set up", and only the first one means there is
 * nothing more you can do from in here.
 */
async function refreshHostLogs() {
  const panel = $("hostLogPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }

  let data;
  try {
    data = await api("host-logs");
  } catch {
    panel.hidden = true;
    return;
  }

  if (!data.rows.length) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  renderTable("hostLogs", data.rows, [
    { label: "Log", className: "name", render: row => row.path },
    {
      label: "Size",
      className: "size",
      render: row => (row.exists ? humanBytes(row.bytes) : "\u2014"),
    },
    {
      label: "Last written",
      render: row => (row.modified_at ? humanTime(row.modified_at) : "\u2014"),
    },
    {
      label: "State",
      render: row => {
        const span = document.createElement("span");
        if (!row.exists) {
          span.className = "muted";
          span.textContent = "not on this machine";
        } else if (row.readable) {
          span.className = "ok";
          span.textContent = "readable";
        } else {
          span.className = "muted";
          span.textContent = "refused by the host";
          span.title = "The file is there and this account may not open it. That needs a "
            + "group membership or an ACL granted by root \u2014 nothing in here can change it.";
        }
        return span;
      },
    },
    {
      label: "",
      render: row => {
        if (!row.readable) return "";
        const button = document.createElement("button");
        button.className = "link";
        button.textContent = "View";
        button.addEventListener("click", () => showLogPath(row.path));
        return button;
      },
    },
  ]);
}

/* ------------------------------------------------------------ capabilities -- */

/*
 * What this account can run, and what the machine has that it cannot reach.
 *
 * The states are the point. "Not available" sends somebody to install a
 * package; "installed on the host and not exposed" sends them to cagefsctl.
 * Only one of those will work, and a tick-or-cross display cannot tell them
 * apart - so the mark has three values and the reason is always in reach.
 */
const CAP_MARKS = {
  available: { mark: "✓", className: "cap-yes" },
  alternate: { mark: "✓", className: "cap-part" },
  host_only: { mark: "—", className: "cap-part" },
  unusable: { mark: "—", className: "cap-part" },
  missing: { mark: "—", className: "cap-no" },
  unknown_host: { mark: "—", className: "cap-no" },
};

const CAP_SHORT = {
  available: "",
  alternate: "alternate path",
  host_only: "host-only",
  unusable: "not executable",
  missing: "not installed",
  unknown_host: "not here",
};

async function refreshCapabilities() {
  const body = $("capBody");
  if (!body) return;

  let data;
  try {
    data = await api("capabilities");
  } catch (error) {
    body.replaceChildren(note(String(error.message || error)));
    $("capSummary").replaceChildren();
    return;
  }

  state.capabilities = data;

  if (!data.available) {
    $("capSummary").replaceChildren();
    body.replaceChildren(note(data.reason || "Nothing checked yet."));
    return;
  }

  renderCapSummary(data);
  renderCapList(data);
}

function renderCapSummary(data) {
  const s = data.summary;
  const facts = [
    ["Worth having", `${s.key_available} of ${s.key_total} available here`],
    ["Whole catalogue", `${s.counts.available} of ${s.total} available here`],
  ];
  if (s.counts.host_only) facts.push(["On the host, not reachable", String(s.counts.host_only)]);
  if (s.counts.alternate) facts.push(["Only under another path", String(s.counts.alternate)]);
  if (s.counts.missing) facts.push(["Not installed anywhere", String(s.counts.missing)]);
  if (s.counts.unknown_host) {
    facts.push(["Host not asked", `${s.counts.unknown_host} — press “Ask the host”`]);
  }

  const when = [];
  if (data.checked_at) when.push(`Account checked ${ago(data.checked_at)}`);
  if (data.host_checked_at) when.push(`host checked ${ago(data.host_checked_at)}`);

  const frag = document.createDocumentFragment();
  frag.appendChild(factGrid(facts));
  if (when.length) frag.appendChild(note(when.join(", ") + "."));

  for (const task of data.tasks || []) {
    if (!task.pending) continue;
    frag.appendChild(note(`Requested ${ago(task.requested_at)}: ${task.task} — waiting for the `
      + "host collector's next run."));
  }

  $("capSummary").replaceChildren(frag);
}

function renderCapList(data) {
  const filter = $("capFilter").value;
  const rows = data.rows.filter(row => {
    if (filter === "key") return row.key;
    if (filter === "gap") return row.state !== "available";
    return true;
  });

  const body = $("capBody");
  if (!rows.length) {
    body.replaceChildren(note(filter === "gap"
      ? "Everything in the catalogue is available here."
      : "Nothing matches."));
    return;
  }

  const byGroup = new Map();
  for (const row of rows) {
    if (!byGroup.has(row.group_name)) byGroup.set(row.group_name, []);
    byGroup.get(row.group_name).push(row);
  }

  const frag = document.createDocumentFragment();

  for (const [group, members] of byGroup) {
    const box = document.createElement("div");
    box.className = "cap-group";

    const head = document.createElement("h3");
    head.textContent = data.groups[group] || group;
    box.appendChild(head);

    const list = document.createElement("div");
    list.className = "cap-list";

    for (const row of members) {
      const shown = CAP_MARKS[row.state] || CAP_MARKS.missing;

      const mark = document.createElement("div");
      mark.className = `cap-mark ${shown.className}`;
      mark.textContent = shown.mark;

      const does = document.createElement("div");
      does.className = "cap-does";
      does.textContent = row.does || row.name;
      if (row.reason) {
        const why = document.createElement("div");
        why.className = "cap-why";
        why.textContent = row.reason;
        does.appendChild(why);
      }

      const prog = document.createElement("div");
      prog.className = "cap-prog";
      prog.appendChild(document.createTextNode(row.name));

      /*
       * The version is shown, not merely hovered.
       *
       * It used to be set as a title attribute and nothing else, so the answer
       * to "what version of php is this" was there and invisible. The number
       * alone goes in the column; the whole line stays as the tooltip, since
       * "8.7.1" is what somebody is reading for and "curl 8.7.1
       * (x86_64-apple-darwin25) libcurl/8.7.1" is what they need when the
       * number turns out not to be enough.
       */
      const versionText = row.version_short || row.host_version_short;
      if (versionText) {
        const version = document.createElement("span");
        version.className = "cap-version";
        version.textContent = " " + versionText;
        version.title = row.version || row.host_version;
        prog.appendChild(version);
      }

      const label = CAP_SHORT[row.state];
      if (label) {
        const tag = document.createElement("span");
        tag.className = "cap-tag";
        tag.textContent = " " + label;
        prog.appendChild(tag);
      }

      /*
       * Only where it can do anything.
       *
       * Offering this against a program the host does not have would open a
       * dialog full of commands that cannot work.
       */
      if (row.state === "host_only" && row.expose) {
        const open = document.createElement("button");
        open.className = "link cap-icon";
        open.textContent = "🔑";
        open.setAttribute("aria-label", `How to expose ${row.name} to this account`);
        open.title = `How to expose ${row.name} to this account`;
        open.addEventListener("click", () => showExposeDialog(row));
        prog.appendChild(open);
      }

      /*
       * A link to the documentation, not to a search for it.
       *
       * Points at the manual page for the tools that have one and at the real
       * documentation for the ones whose man page is a stub - nobody has ever
       * learned jq from man(1).
       */
      if (row.help) {
        const help = document.createElement("a");
        help.className = "cap-icon";
        help.href = row.help;
        help.target = "_blank";
        help.rel = "noopener noreferrer";
        help.textContent = "?";
        help.setAttribute("aria-label", `Documentation for ${row.name}`);
        help.title = `Documentation for ${row.name}`;
        prog.appendChild(help);
      }

      list.append(mark, does, prog);
    }

    box.appendChild(list);
    frag.appendChild(box);
  }

  body.replaceChildren(frag);
}

/*
 * What CageFS is, written for somebody meeting it for the first time.
 *
 * Shown only where it applies. On a host without CageFS this whole explanation
 * is noise, and a panel that explains something irrelevant every time teaches
 * people to skip the panel.
 */
function renderCagefsExplainer(mounts, capabilities) {
  const holder = $("cagefsExplainer");
  if (!holder) return;

  const cage = mounts?.cagefs;
  const installed = capabilities?.cagefs?.installed || cage?.present;

  if (!installed) {
    holder.replaceChildren();
    return;
  }

  const box = document.createElement("div");
  box.className = "explainer";

  const head = document.createElement("h3");
  head.textContent = "What CageFS is";
  box.appendChild(head);

  const paragraphs = [
    "CageFS gives every account its own private view of the filesystem. Instead of the "
    + "machine's real /usr and /bin, an account sees a small curated copy built from a "
    + "skeleton at /usr/share/cagefs-skeleton — enough to run a website and nothing else.",

    "So a program the host has in /usr/sbin is not hidden from you by permissions. It is "
    + "simply not present in the filesystem you are looking at. When the account says a "
    + "program is not found, it is telling the truth about a world where that program does "
    + "not exist — which is why “installed” and “available here” are different questions on "
    + "this machine, and why this page answers both.",

    "Adding a program for accounts means listing its path in /etc/cagefs/conf.d and running "
    + "cagefsctl --force-update as root. That changes what every caged account can run, so "
    + "this tool reports the exact commands rather than running them for you.",
  ];

  if (capabilities?.cagefs?.note) paragraphs.push(capabilities.cagefs.note);

  for (const text of paragraphs) {
    const p = document.createElement("p");
    p.textContent = text;
    box.appendChild(p);
  }

  holder.replaceChildren(box);
}

async function refreshMounts() {
  const body = $("mountBody");
  if (!body) return;

  let data;
  try {
    data = await api("mounts");
  } catch (error) {
    body.replaceChildren(note(String(error.message || error)));
    return;
  }

  renderCagefsExplainer(data, state.capabilities);

  if (!data.available) {
    body.replaceChildren(note(data.reason || "No mount table."));
    return;
  }

  const frag = document.createDocumentFragment();

  if (data.own_view) {
    const lines = ["Read from this account's own /proc/self/mountinfo — the account's view of "
      + "the filesystem, not the machine's."];

    /*
     * Why the account's view cannot answer the CageFS question.
     *
     * Inside a cage the skeleton's contents appear as /usr and /lib; the
     * /usr/share/cagefs-skeleton mounts that assemble them live in the host's
     * namespace and are invisible from in here. Reporting their absence as
     * "CageFS is not installed" told people sitting inside a cage that they
     * were not in one.
     */
    if (data.cagefs?.inside_cage) {
      lines.push("This looks like the inside of a CageFS cage: "
        + data.cagefs.cage_signals.join("; ") + ".");
    }
    lines.push("Run the host collector as root to see the machine's own mount table: "
      + "php host-cron.php --capabilities");

    frag.appendChild(note(lines.join(" ")));
  }

  /*
   * Filesystems, not mount points, and the difference is the whole point.
   *
   * Seventy bind mounts of one device is one filesystem's worth of space. A
   * table with a row per mount point invites adding a column of sizes, and
   * that column would be wrong by a factor of seventy.
   */
  frag.appendChild(section(`Filesystems — ${count(data.filesystems.length, "device")}`,
    simpleTable(
      ["Mounted at", "Type", "Source", "Mounts", "Binds", "Read-only"],
      data.filesystems.map(fs => [
        fs.mount_point, fs.fs_type, fs.source,
        String(fs.mounts), String(fs.binds), fs.read_only ? "yes" : "no",
      ]),
      ["name", null, "name", "size", "size", null]
    )));

  const cage = data.cagefs;
  const cageBox = document.createDocumentFragment();
  cageBox.appendChild(note(cage.note));

  if (cage.present) {
    cageBox.appendChild(simpleTable(
      ["Path inside the skeleton", "Type", "From", "Bind", "Read-only"],
      cage.mounts.map(m => [
        m.relative, m.fs_type, m.fs_root === "/" ? m.source : m.fs_root,
        m.bind ? "yes" : "no", m.read_only ? "yes" : "no",
      ]),
      ["name", null, "name", null, null]
    ));
  }

  frag.appendChild(section(
    cage.present ? `CageFS skeleton — ${count(cage.count, "mount")}` : "CageFS",
    cageBox
  ));

  body.replaceChildren(frag);
}


/*
 * The commands to expose one program, ready to copy.
 *
 * Shown rather than run. Exposing a program edits /etc/cagefs/conf.d and
 * rebuilds the skeleton for every caged account on the machine, which is not
 * something a web button should do quietly on somebody's behalf.
 */
function showExposeDialog(row) {
  $("listDialogTitle").textContent = `Expose ${row.name} to this account`;
  $("listDialogNote").textContent = row.reason || "";

  const body = $("listDialogBody");
  body.className = "";

  const frag = document.createDocumentFragment();

  const intro = document.createElement("p");
  intro.className = "muted small";
  intro.textContent = `${row.name} is installed at ${row.host_path} and is not visible inside `
    + "this account. Run these as root on the host. They write a CageFS config fragment and "
    + "rebuild the skeleton — which changes what every caged account can run, so nothing here "
    + "does it for you.";
  frag.appendChild(intro);

  const pre = document.createElement("pre");
  pre.className = "commands";
  pre.textContent = row.expose.commands.join("\n");
  frag.appendChild(pre);

  const buttons = document.createElement("p");

  const copy = document.createElement("button");
  copy.textContent = "Copy";
  copy.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(row.expose.commands.join("\n"));
      copy.textContent = "Copied";
      setTimeout(() => { copy.textContent = "Copy"; }, 1500);
    } catch {
      /*
       * Clipboard access is refused outside a secure context, which is
       * exactly where a self-hosted panel often lives. Selecting the text is
       * the fallback, and saying so beats a button that silently does nothing.
       */
      copy.textContent = "Select it and copy";
      const range = document.createRange();
      range.selectNodeContents(pre);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
  });
  buttons.appendChild(copy);

  const queue = document.createElement("button");
  queue.className = "link";
  queue.style.marginLeft = "10px";
  queue.textContent = "Ask the collector to check afterwards";
  queue.title = "Queues a capability sweep so the next host run confirms whether it worked.";
  queue.addEventListener("click", async () => {
    queue.disabled = true;
    try {
      await post("request-host-task", { task: "expose", program: row.name });
      notice(`Queued. The host collector will confirm ${row.name} on its next run.`);
      await refreshCapabilities();
    } catch (error) {
      notice(String(error.message || error));
      queue.disabled = false;
    }
  });
  buttons.appendChild(queue);

  frag.appendChild(buttons);

  const note = document.createElement("p");
  note.className = "muted small";
  note.textContent = "These files are INI. A bare path without the [section] header makes "
    + "cagefsctl refuse to run at all until the file is removed.";
  frag.appendChild(note);

  body.replaceChildren(frag);
  $("listDialog").showModal();
}

/* --------------------------------------------------- capability changes -- */

const changeState = { data: null, bars: [], hover: null };

async function refreshCapabilityChanges() {
  const canvas = $("capChanges");
  if (!canvas) return;

  const to = Math.floor(Date.now() / 1000);
  try {
    changeState.data = await api("capability-changes", { from: to - state.range, to });
  } catch (error) {
    changeState.data = null;
    $("capChangeNote").textContent = String(error.message || error);
  }

  drawChangeChart();
}

/*
 * A stacked bar per bucket, one colour per group.
 *
 * Bars rather than a line because these are counts of discrete events, and a
 * line between "three changes on Tuesday" and "none on Wednesday" draws a slope
 * through days on which nothing happened - implying a rate where there is only
 * a tally.
 */
function drawChangeChart() {
  const canvas = $("capChanges");
  rememberDraw(canvas, drawChangeChart);
  if (canvas.clientWidth === 0) return;

  const ratio = window.devicePixelRatio || 1;
  const width = canvas.clientWidth;
  const height = 200;

  canvas.width = Math.max(1, Math.floor(width * ratio));
  canvas.height = Math.max(1, Math.floor(height * ratio));
  canvas.style.height = height + "px";

  const ctx = canvas.getContext("2d");
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.clearRect(0, 0, width, height);

  const data = changeState.data;
  changeState.bars = [];

  if (!data || !data.buckets.length) {
    ctx.fillStyle = "#9a9a9a";
    ctx.font = "13px system-ui, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(
      data ? "Nothing has changed in this period." : "No history yet.",
      width / 2, height / 2);
    $("capChangeLegend").replaceChildren();
    renderChangeNote();
    return;
  }

  const pad = { left: 34, right: 8, top: 10, bottom: 22 };
  const plotW = Math.max(1, width - pad.left - pad.right);
  const plotH = Math.max(1, height - pad.top - pad.bottom);

  const span = Math.max(1, data.to - data.from);
  const tallest = Math.max(...data.buckets.map(b => b.total));
  const scale = plotH / Math.max(1, tallest);

  // A bar is one bucket wide, with a floor so a single change is still visible.
  const barW = Math.max(3, Math.min(28, (plotW / (span / data.bucket_seconds)) - 1));

  const groups = data.groups;
  const colour = group => seriesColor(groups.indexOf(group));

  ctx.strokeStyle = "#333";
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(pad.left, pad.top + plotH + .5);
  ctx.lineTo(pad.left + plotW, pad.top + plotH + .5);
  ctx.stroke();

  // Whole numbers only: half a change does not exist.
  ctx.fillStyle = "#9a9a9a";
  ctx.font = "10px system-ui, sans-serif";
  ctx.textAlign = "right";
  for (const value of [0, tallest]) {
    const y = pad.top + plotH - value * scale;
    ctx.fillText(String(value), pad.left - 6, y + 3);
  }

  for (const bucket of data.buckets) {
    const x = pad.left + ((bucket.at - data.from) / span) * plotW;
    let y = pad.top + plotH;

    for (const group of groups) {
      const n = bucket.counts[group] || 0;
      if (!n) continue;
      const h = n * scale;
      y -= h;
      ctx.fillStyle = colour(group);
      ctx.fillRect(x, y, barW, h);
    }

    changeState.bars.push({
      at: bucket.at, total: bucket.total, counts: bucket.counts,
      x, y, w: barW, h: pad.top + plotH - y,
    });
  }

  if (changeState.hover) {
    const bar = changeState.bars.find(b => b.at === changeState.hover);
    if (bar) {
      ctx.strokeStyle = "#fff";
      ctx.lineWidth = 1.5;
      ctx.strokeRect(bar.x - 1, bar.y - 1, bar.w + 2, bar.h + 2);
    }
  }

  ctx.textAlign = "center";
  ctx.fillStyle = "#9a9a9a";
  for (const at of [data.from, data.to]) {
    const x = pad.left + ((at - data.from) / span) * plotW;
    ctx.fillText(humanTime(at).split(",")[0],
      Math.min(Math.max(x, pad.left + 20), pad.left + plotW - 20),
      height - 6);
  }

  const legend = $("capChangeLegend");
  legend.replaceChildren(...groups.map(group => {
    const span2 = document.createElement("span");
    span2.textContent = data.group_names[group] || group;
    span2.style.setProperty("--dot", colour(group));
    return span2;
  }));

  renderChangeNote();
}

function renderChangeNote() {
  const data = changeState.data;
  const note = $("capChangeNote");
  if (!data) return;

  const parts = [`${count(data.total, "change")} in this period, one bar per ${data.bucket_unit}.`];
  if (data.truncated) {
    parts.push(`Only the most recent ${data.changes.length} are listed when a bar is clicked.`);
  }
  if (data.total) parts.push("Click a bar for the detail.");
  note.textContent = parts.join(" ");
}

function barAt(x, y) {
  for (const bar of changeState.bars) {
    // Generous vertically: a bar one change tall is three pixels, and nobody
    // can be asked to hit that.
    if (x >= bar.x - 2 && x <= bar.x + bar.w + 2) return bar;
  }
  return null;
}

$("capChanges").addEventListener("mousemove", event => {
  const rect = event.currentTarget.getBoundingClientRect();
  const bar = barAt(event.clientX - rect.left, event.clientY - rect.top);
  const at = bar ? bar.at : null;
  if (at !== changeState.hover) {
    changeState.hover = at;
    drawChangeChart();
  }
  event.currentTarget.style.cursor = bar ? "pointer" : "default";
});

$("capChanges").addEventListener("mouseleave", () => {
  changeState.hover = null;
  drawChangeChart();
});

$("capChanges").addEventListener("click", event => {
  const rect = event.currentTarget.getBoundingClientRect();
  const bar = barAt(event.clientX - rect.left, event.clientY - rect.top);
  if (bar) showChangesIn(bar);
});

/*
 * What actually changed inside one bar.
 *
 * The chart says how many; this says which, from what to what. Without it a
 * spike is a mystery with a tooltip.
 */
function showChangesIn(bar) {
  const data = changeState.data;
  const upper = bar.at + data.bucket_seconds;
  const rows = data.changes.filter(c => c.at >= bar.at && c.at < upper);

  $("listDialogTitle").textContent =
    `${count(bar.total, "change")} — ${humanTime(bar.at)}`;
  $("listDialogNote").textContent = rows.length < bar.total
    ? `Showing ${rows.length} of ${bar.total}; the rest are beyond the detail limit.`
    : "When a check noticed the change, not necessarily when it happened.";

  const body = $("listDialogBody");
  body.className = "change-list";

  const frag = document.createDocumentFragment();

  for (const row of rows) {
    const box = document.createElement("div");
    box.className = "change-row";

    const when = document.createElement("div");
    when.className = "change-when";
    when.textContent = humanTime(row.at);
    box.appendChild(when);

    const what = document.createElement("div");
    what.className = "change-what";

    const prog = document.createElement("span");
    prog.className = "prog";
    prog.textContent = row.name;
    what.appendChild(prog);

    if (row.does) {
      const does = document.createElement("span");
      does.className = "muted";
      does.textContent = ` — ${row.does}`;
      what.appendChild(does);
    }

    const detail = document.createElement("div");
    detail.className = "muted";

    /*
     * State and version are reported separately.
     *
     * A program can gain a version without changing state (an upgrade) or
     * change state without a version (exposed through CageFS), and running the
     * two together produces sentences like "absent 1.2 to available 1.2" that
     * hide which of the two actually moved.
     */
    const bits = [];
    if (row.old_state !== row.new_state) {
      const gained = row.new_state === "available" || row.new_state === "present";
      bits.push(`<state>${row.old_state} → ${row.new_state}</state>${gained ? "+" : "-"}`);
    }
    if (row.old_version_short !== row.new_version_short) {
      bits.push(`version ${row.old_version_short || "unknown"} → ${row.new_version_short || "unknown"}`);
    }

    for (const bit of bits) {
      const piece = document.createElement("div");
      const gained = bit.endsWith("+");
      const loss = bit.endsWith("-");
      piece.className = gained ? "change-gain" : loss ? "change-loss" : "change-arrow";
      piece.textContent = bit.replace(/<\/?state>/g, "").replace(/[+-]$/, "");
      detail.appendChild(piece);
    }

    const side = document.createElement("div");
    side.className = "muted";
    side.textContent = row.side === "host" ? "seen by the host" : "seen by this account";
    detail.appendChild(side);

    what.appendChild(detail);
    box.appendChild(what);
    frag.appendChild(box);
  }

  body.replaceChildren(frag);
  $("listDialog").showModal();
}


/* ------------------------------------------------------------------ backups -- */

/*
 * Backup storage, opening with the number that reframes the disk.
 *
 * "Disk 55% full" is a fact nobody can act on. "76 GiB of it is backups of an
 * account holding 4.5 GiB" is the same disk described in a way that says what
 * to do next.
 */
/*
 * What JetBackup reports about its own backups.
 *
 * Worth having beside the directory sizes because the directories are named
 * `files0000000005` and say nothing at all - which account, which date, whether
 * it is still current. Only JetBackup knows that, and only when it can be run.
 */
function renderJetBackup(jet) {
  const out = document.createDocumentFragment();

  if (jet.backups?.length) {
    out.appendChild(section(`${count(jet.backups.length, "backup")} JetBackup knows about`,
      simpleTable(
        ["Account", "When", "Type", "Size", "Destination"],
        jet.backups.map(b => [
          b.account ?? "\u2014",
          b.created_at ? humanTime(b.created_at) : "\u2014",
          b.type ?? "\u2014",
          b.bytes ? humanBytes(b.bytes) : "not reported",
          b.destination ?? "\u2014",
        ]),
        ["name", null, null, "size", null]
      )));
  }

  if (jet.destinations?.length) {
    out.appendChild(section("Destinations", simpleTable(
      ["Name", "Type", "Path"],
      jet.destinations.map(d => [d.name ?? "\u2014", d.type ?? "\u2014", d.path ?? "\u2014"]),
      ["name", null, "name"]
    )));
  }

  if (!out.childElementCount) {
    out.appendChild(note("JetBackup answered, but listed no backups and no destinations."));
  }

  return out;
}

async function refreshBackups() {
  const holder = $("backupBody");
  if (!holder) return;

  const targets = ["backupBody", "backupActionsBody", "backupOtherBody", "backupJetBody"];
  const everywhere = text => {
    for (const id of targets) {
      const el = $(id);
      if (el) el.replaceChildren(note(text));
    }
  };

  for (const id of targets) {
    const el = $(id);
    // Something has to be on screen while a walk of /backup is being read.
    if (el && !el.childElementCount) el.replaceChildren(spinner("Reading the last walk\u2026"));
  }

  let data;
  try {
    data = await api("backups");
  } catch (error) {
    everywhere(String(error.message || error));
    return;
  }

  if (!data.available) {
    everywhere(data.reason || "Nothing recorded.");
    return;
  }

  const body = document.createDocumentFragment();

  const headline = document.createElement("div");
  headline.className = "headline";
  headline.textContent = humanBytes(data.total_bytes);
  body.appendChild(headline);

  const sub = document.createElement("div");
  sub.className = "headline-sub";
  sub.textContent = data.share_percent != null
    ? `${data.share_percent}% of the used space on ${data.mount || "this filesystem"}`
    : "share of the disk unknown — no filesystem reading recorded";
  body.appendChild(sub);

  if (!data.complete || data.unmeasured) {
    body.appendChild(note("The disk walk ran out of time, so this total is a lower bound"
      + (data.unmeasured
          ? ` — ${count(data.unmeasured, "directory", "directories")} under the backup roots `
            + "were never opened."
          : ".")));
  }

  // Growth, when there is more than one walk to compare.
  const growth = data.growth;
  if (growth?.reason) {
    body.appendChild(note(growth.reason));
  } else if (growth) {
    const rows = [];
    for (const [label, point] of [["24 hours ago", growth.day], ["7 days ago", growth.week]]) {
      if (!point) continue;
      /*
       * The real span, not the one that was asked for.
       *
       * Disk walks are a manual or weekly job, so "24 hours ago" is often the
       * nearest walk five days back — the same trap as the growth views.
       */
      rows.push([label, `${humanBytes(point.bytes)} — ${point.grew >= 0 ? "+" : ""}`
        + `${humanBytes(point.grew)} over ${humanDuration(point.span)}`]);
    }
    if (rows.length) body.appendChild(section("Growth", factGrid(rows)));
  }

  if (data.accounts.length) {
    const wrap = document.createDocumentFragment();
    if (data.accounts.some(r => r.unattributed)) {
      wrap.appendChild(note("Some of this could not be tied to an account. Nothing under the "
        + "backup root carried a name matching a directory in /home, and JetBackup's own "
        + "records were not readable — so it is reported as unattributed rather than assigned "
        + "to the only account on the machine, which would be a guess."));
    }

    if (data.accounts[0].inferred) {
      wrap.appendChild(note("Accounts inferred from directory names under the backup root — "
        + "JetBackup's own metadata was not readable, so these are a reasonable guess rather "
        + "than its records."));
    }

    wrap.appendChild(simpleTable(
      ["Account", "Live data", "Backups", "Ratio", "Copies", "Oldest", "Newest"],
      data.accounts.map(row => [
        // The unattributed remainder has no account, and an empty cell reads
        // as a missing value rather than as the deliberate refusal it is.
        row.unattributed ? "not attributed" : row.account,
        row.live_bytes == null ? "—" : humanBytes(row.live_bytes),
        humanBytes(row.backup_bytes),
        row.ratio == null ? "—" : `${row.ratio}×`,
        row.copies == null ? "—" : String(row.copies),
        row.oldest ? humanTime(row.oldest) : "—",
        row.newest ? humanTime(row.newest) : "—",
      ]),
      [null, "size", "size", "size", "size", null, null]
    ));

    for (const row of data.accounts) {
      if (!row.verdict) continue;
      const verdict = document.createElement("p");
      verdict.className = "muted small";
      verdict.textContent = `${row.account}: ${row.verdict}`;
      wrap.appendChild(verdict);
    }

    body.appendChild(section("By account", wrap));
  }

  // What JetBackup itself says, or plainly that it did not say - on its own
  // page, because it is a question about one product rather than about backups.
  const jetBody = $("backupJetBody");
  if (jetBody) {
    const jet = data.jetbackup;
    jetBody.replaceChildren(jet.available
      ? renderJetBackup(jet)
      : note(jet.reason));
  }

  for (const root of data.roots) {
    if (!root.children.length) continue;

    const wrap = document.createDocumentFragment();
    const missed = root.children.filter(c => !c.measured).length;
    if (missed) {
      /*
       * An unopened directory is not an empty one.
       *
       * The walk gives every child a share of the time left, but a big enough
       * tree still runs out - and saying "0 B" for something nobody looked at
       * is how an entire backup destination came to be missing from this
       * panel without anybody noticing.
       */
      wrap.appendChild(note(`${count(missed, "entry", "entries")} here could not be measured `
        + "before the walk ran out of time. They are listed with no size rather than omitted. "
        + "Raise host.walk_seconds and run it again for a complete figure."));
    }

    wrap.appendChild(simpleTable(
      ["Path", "Size", "Files"],
      root.children.map(c => [
        c.name,
        /*
         * Three states, not two.
         *
         * Incomplete with a size is a partial walk - a genuine lower bound,
         * and saying "not measured" throws away a real number. Incomplete with
         * nothing is a directory that was never opened. Collapsing them either
         * loses information or invents it.
         */
        c.measured ? humanBytes(c.bytes)
          : (c.bytes > 0 ? `at least ${humanBytes(c.bytes)}` : "not measured"),
        c.measured ? humanCount(c.files) : (c.files > 0 ? `${humanCount(c.files)}+` : "—"),
      ]),
      ["name", "size", "size"]
    ));

    body.appendChild(section(`Inside ${root.path}`, wrap));
  }

  if (data.other.length) {
    const wrap = document.createDocumentFragment();
    wrap.appendChild(note("These are outside the backup system, so no retention policy will "
      + "ever remove them. Matched on their names, which is evidence rather than proof — a "
      + "directory called \\u201carchive\\u201d might be a live dataset."));
    wrap.appendChild(simpleTable(
      ["Path", "Size", "Files", "Matched"],
      data.other.map(r => [r.path, humanBytes(r.bytes), humanCount(r.files), r.matched]),
      ["name", "size", "size", null]
    ));
    const otherBody = $("backupOtherBody");
    if (otherBody) otherBody.replaceChildren(wrap);
  }

  const actionsBody = $("backupActionsBody");
  if (actionsBody) {
    actionsBody.replaceChildren(data.actions
      ? backupActionList(data)
      : note("Nothing to suggest from this reading."));
  }

  holder.replaceChildren(body);
}

/*
 * Actions grouped by how safe they are, with who can do them.
 *
 * Safety and reach are separate on purpose. "Run JetBackup cleanup" is safe and
 * needs root; telling somebody with no root to run it is a dead end, which is
 * why the ones they cannot do come with a message they can send instead.
 */
function backupActionList(data) {
  const wrap = document.createDocumentFragment();

  const REACH = {
    here: "you can do this here",
    root: "needs root or WHM",
    provider: "ask your hosting provider",
  };

  for (const level of ["safe", "review", "dangerous"]) {
    const members = data.actions.filter(a => a.level === level);
    if (!members.length) continue;

    const box = document.createElement("div");
    box.className = "cap-group";

    const head = document.createElement("h3");
    head.textContent = {
      safe: "Safe — returns space the backup system already considers disposable",
      review: "Worth reviewing — a decision, not a chore",
      dangerous: "Dangerous — listed so it can be named, not recommended",
    }[level];
    box.appendChild(head);

    for (const action of members) {
      const row = document.createElement("div");
      row.className = "action-row";

      const tag = document.createElement("span");
      tag.className = `level level-${level}`;
      tag.textContent = level;
      row.appendChild(tag);

      const reach = document.createElement("span");
      reach.className = "reach";
      reach.textContent = " · " + REACH[action.reach];
      row.appendChild(reach);

      const title = document.createElement("div");
      title.className = "action-title";
      title.textContent = action.title;
      row.appendChild(title);

      const detail = document.createElement("div");
      detail.className = "action-detail";
      detail.textContent = action.detail;
      row.appendChild(detail);

      if (action.command) {
        const cmd = document.createElement("div");
        cmd.className = "action-command";
        cmd.textContent = action.command;
        row.appendChild(cmd);
      }

      box.appendChild(row);
    }

    wrap.appendChild(box);
  }

  // The message for whoever does have root.
  const ask = document.createElement("p");
  const button = document.createElement("button");
  button.textContent = "Message for your hosting provider";
  button.addEventListener("click", () => showProviderMessage(data.message));
  ask.appendChild(button);
  wrap.appendChild(ask);

  return wrap;
}

function showProviderMessage(message) {
  $("listDialogTitle").textContent = "Message for your hosting provider";
  $("listDialogNote").textContent = "Describes what was measured and asks for the safe "
    + "actions only. It deliberately does not ask anybody to delete anything from the "
    + "backup directory.";

  const body = $("listDialogBody");
  body.className = "";

  const pre = document.createElement("pre");
  pre.className = "commands";
  pre.style.whiteSpace = "pre-wrap";
  pre.textContent = message;

  const copy = document.createElement("button");
  copy.textContent = "Copy";
  copy.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(message);
      copy.textContent = "Copied";
      setTimeout(() => { copy.textContent = "Copy"; }, 1500);
    } catch {
      copy.textContent = "Select it and copy";
      const range = document.createRange();
      range.selectNodeContents(pre);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
  });

  const holder = document.createDocumentFragment();
  holder.appendChild(pre);
  const buttons = document.createElement("p");
  buttons.appendChild(copy);
  holder.appendChild(buttons);

  body.replaceChildren(holder);
  $("listDialog").showModal();
}


/* ------------------------------------------------------------------- shell -- */

/*
 * One view at a time, and only the visible one costs anything.
 *
 * This began as a single page that rendered everything and polled all of it on
 * a timer. That is fine with four panels and absurd with twenty-two: a
 * dashboard nobody was looking at still walked the process table, re-read a
 * year of vitals and asked the host database eleven questions, every ten
 * seconds, for ever.
 *
 * So each view declares what it needs, and the poll only ever refreshes the
 * one on screen. Everything else keeps whatever it last rendered, which is
 * both correct - it is still true, it is just older - and free.
 */
const VIEWS = {
  overview: { label: "Overview", refresh: () => refreshVitals() },

  storage: { label: "Storage", parent: null, refresh: () => refreshScans() },
  "storage-io": { label: "How hard the disks are working", parent: "storage", refresh: () => refreshHost() },
  "storage-files": {
    label: "Largest & growth", parent: "storage",
    refresh: async () => {
      await refreshOffenders();
      await refreshHostLogs();
      if (state.historyPathId) await showHistory(state.historyPathId, true);
      if (state.log.pathId) await showLog(state.log.pathId, true);
      else if (state.log.path) await showLogPath(state.log.path, true);
    },
  },
  "storage-map": {
    label: "Tree map", parent: "storage",
    refresh: async () => {
      if (state.signedIn && !treeState.level) await refreshTreemap().catch(() => {});
    },
  },

  backups: { label: "Backups", refresh: () => refreshBackups() },
  "backups-actions": { label: "What can be done", parent: "backups", refresh: () => refreshBackups() },
  "backups-other": { label: "Other probable backups", parent: "backups", refresh: () => refreshBackups() },
  "backups-jetbackup": { label: "JetBackup", parent: "backups", refresh: () => refreshBackups() },

  "host-disk": { label: "Filesystems", parent: "storage", refresh: () => refreshHostDisk() },
  "disk-dirs": { label: "Biggest directories", parent: "storage", refresh: () => refreshHostDisk() },
  "disk-deleted": { label: "Deleted, still held", parent: "storage", refresh: () => refreshHostDisk() },
  "disk-procs": { label: "Processes on disk", parent: "storage", refresh: () => refreshHost() },
  "disk-rootfiles": { label: "Root-only files", parent: "storage", refresh: () => refreshHost() },
  "host-layout": { label: "Filesystem layout", parent: "storage", refresh: () => refreshMounts() },

  host: { label: "Memory", refresh: () => refreshHost() },

  processes: { label: "Processes", refresh: () => refreshProcesses() },
  services: { label: "Services", parent: "processes", refresh: () => refreshHost() },
  busy: { label: "Last busy", parent: "processes", refresh: () => refreshBusy() },

  capabilities: {
    label: "Capabilities",
    refresh: async () => {
      await refreshCapabilities();
      await refreshCapabilityChanges();
    },
  },

  alerts: { label: "Alerts", refresh: () => refreshAlerts() },
  uptime: { label: "Uptime", refresh: () => refreshUptime() },
  deletions: { label: "Actions", refresh: () => refreshActionLog() },
  email: { label: "Email alerts", refresh: () => refreshEmail() },
  schedule: {
    label: "Schedule",
    refresh: async () => {
      await refreshSchedule();
      await refreshHostSchedule();
    },
  },
  system: {
    label: "System",
    refresh: async () => {
      await refreshHealth();
      await refreshProblems();
    },
  },
};

const DEFAULT_VIEW = "overview";

/*
 * The address bar is the state.
 *
 * #/storage?range=604800 is a link somebody can send, and the person who opens
 * it sees the same view over the same window. That is the whole feature: a
 * screenshot with a URL attached, rather than "scroll down to the third panel
 * and set the dropdown to a week".
 */
function readRoute() {
  const raw = location.hash.replace(/^#\/?/, "");
  const [path, query] = raw.split("?");
  const params = new URLSearchParams(query || "");

  return {
    view: VIEWS[path] ? path : DEFAULT_VIEW,
    range: Number(params.get("range")) || null,
    units: params.get("units"),
    at: Number(params.get("at")) || null,
  };
}

function writeRoute(replace = false) {
  const params = new URLSearchParams();
  params.set("range", String(state.range));
  if (state.units !== "bytes") params.set("units", state.units);
  if (state.frozenAt) params.set("at", String(state.frozenAt));

  const url = `#/${state.view}?${params.toString()}`;
  if (replace) history.replaceState(null, "", url);
  else if (location.hash !== url) history.pushState(null, "", url);
}

function applyRoute() {
  const route = readRoute();

  if (route.range && route.range !== state.range) {
    state.range = route.range;
    const select = $("range");
    if (select && [...select.options].some(o => Number(o.value) === route.range)) {
      select.value = String(route.range);
    }
  }
  if (route.units && route.units !== state.units) {
    state.units = route.units;
    const select = $("units");
    if (select) select.value = route.units;
  }
  state.frozenAt = route.at;

  showView(route.view, false);
}

function showView(view, push = true) {
  if (!VIEWS[view]) view = DEFAULT_VIEW;

  const changed = state.view !== view;
  state.view = view;

  for (const section of document.querySelectorAll("[data-view]")) {
    section.hidden = section.dataset.view !== view;
  }
  /*
   * Sub-items appear only under the section you are in.
   *
   * Fifteen entries listed flat is a worse menu than the single page it
   * replaced. Showing a section's children only while that section is open
   * keeps the sidebar to eight rows plus whatever is relevant.
   */
  const parent = VIEWS[view].parent || view;

  for (const link of document.querySelectorAll("[data-nav]")) {
    const id = link.dataset.nav;
    const owner = link.dataset.parent;

    link.classList.toggle("current", id === view);
    if (id === view) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");

    if (owner) link.hidden = owner !== parent;
  }

  document.title = `${VIEWS[view].label} · Hostscope`;

  // The units picker only means anything where something is charted against a
  // unit; showing it on Capabilities is a control that does nothing.
  const unitsWrap = $("unitsWrap");
  if (unitsWrap) unitsWrap.hidden = !["overview", "storage"].includes(view);

  closeNav();
  if (push) writeRoute();

  /*
   * Anything drawn while this view was hidden is redrawn now that it has a
   * width. Cheap - it is a replay of the last draw, not a refetch.
   */
  const section = document.querySelector(`[data-view="${view}"]`);
  if (section) redrawCharts(section);

  // The banner is the dashboard's, so leaving the dashboard has to take it away
  // rather than waiting for the next poll to notice.
  renderAlertBanner((alertState.rows || []).filter(row => !row.acknowledged_at));

  /*
   * And again once the refresh has landed.
   *
   * A panel that stays hidden until its data arrives has no width during the
   * redraw above, so its chart is skipped - and nothing else would ever come
   * back for it. This is the second half of that pair, not a duplicate of it.
   */
  if (changed || !state.rendered.has(view)) {
    Promise.resolve(refreshCurrent()).finally(() => {
      if (state.view === view && section) redrawCharts(section);
    });
  }
}

/* ------------------------------------------------------------------- poll -- */

/*
 * A ring that says what the page is doing.
 *
 * Spinning while a request is in flight, then draining as the cooldown runs
 * down to the next one. The previous "updated 12s ago" told you how stale
 * things were and nothing about whether anything was being done about it - so
 * a stalled poll and a healthy one looked identical until the number got
 * conspicuously large.
 */
const poll = { busy: false, nextAt: 0, every: 0, raf: null };

function setPollState(busy, every) {
  poll.busy = busy;
  if (!busy && every) {
    poll.every = every;
    poll.nextAt = Date.now() + every;
  }
  const el = $("pollState");
  if (el) el.classList.toggle("busy", busy);
  drawPoll();
}

function drawPoll() {
  const fill = document.querySelector(".poll-fill");
  if (!fill) return;

  const circumference = 2 * Math.PI * 15;
  fill.style.strokeDasharray = String(circumference);

  if (poll.busy) {
    // A quarter-arc, spun by CSS: an indeterminate wait needs an
    // indeterminate indicator rather than a progress bar that is lying.
    fill.style.strokeDashoffset = String(circumference * 0.75);
  } else if (poll.every > 0) {
    const left = Math.max(0, poll.nextAt - Date.now());
    const done = 1 - left / poll.every;
    fill.style.strokeDashoffset = String(circumference * (1 - done));
    const el = $("pollState");
    if (el) {
      el.title = `Next refresh in ${Math.ceil(left / 1000)}s — click to refresh now`;
    }
  }

  cancelAnimationFrame(poll.raf);
  if (!poll.busy && poll.every > 0) {
    poll.raf = requestAnimationFrame(drawPoll);
  }
}

async function refreshCurrent() {
  if (document.hidden) return;

  const view = VIEWS[state.view] || VIEWS[DEFAULT_VIEW];
  setPollState(true);

  try {
    // The session and the alert count are cheap and belong to every view: the
    // sign-in button and the navigation badges are on screen whatever is.
    await refreshShared();
    await view.refresh();
    state.rendered.add(state.view);
    clearStaleNotice();
  } catch (error) {
    notice(String(error.message || error));
  } finally {
    setPollState(false, pollInterval());
  }
}

function pollInterval() {
  if (state.live) return LIVE_POLL_MS;
  if (state.scanRunning) return SCAN_POLL_MS;
  return IDLE_POLL_MS;
}

/* --------------------------------------------------------------- navigation -- */

function openNav() {
  document.body.classList.add("nav-open");
  $("navToggle").setAttribute("aria-expanded", "true");
  $("scrim").hidden = false;
}

function closeNav() {
  document.body.classList.remove("nav-open");
  $("navToggle")?.setAttribute("aria-expanded", "false");
  const scrim = $("scrim");
  if (scrim) scrim.hidden = true;
}

/*
 * Badges on the navigation, so a problem is visible from any view.
 *
 * The point of splitting one page into eight is that you are only looking at
 * one - which would also mean only noticing problems in the one you happen to
 * be on. The counts come from the shared refresh, so they stay current
 * wherever you are.
 */
function setBadge(view, count, level = "warn") {
  const el = document.querySelector(`[data-badge="${view}"]`);
  if (!el) return;

  if (!count) {
    // Cleared as well as hidden: a stale number left in the node flashes on
    // the next unhide before the new one is written.
    el.hidden = true;
    el.textContent = "";
    return;
  }

  el.hidden = false;
  el.textContent = count > 99 ? "99+" : String(count);
  el.className = `nav-badge badge-${level}`;
}

/* ----------------------------------------------------------------- polling -- */

function schedule() {
  if (state.timer) clearInterval(state.timer);
  state.timer = setInterval(tick, pollInterval());
  setPollState(false, pollInterval());
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

  await refreshCurrent();
}

/* -------------------------------------------------------------------- wire -- */

$("units").addEventListener("change", () => {
  state.units = $("units").value;
  state.rendered.clear();
  writeRoute();
  refreshCurrent();
});

/*
 * The only range where live updates mean anything.
 *
 * Live takes a fresh reading every ten seconds. On a year-long chart those
 * land inside a bucket a day wide and change nothing anybody can see, while
 * still writing a row to the database every ten seconds - all of the cost and
 * none of the point.
 */
const LIVE_RANGE = 600;

function syncLiveAvailability() {
  const button = $("live");
  const allowed = state.range === LIVE_RANGE;

  button.disabled = !allowed;
  button.title = allowed
    ? "Take a fresh reading every ten seconds."
    : "Live updates are only available on the last 10 minutes — at any longer range "
      + "a ten-second reading lands inside a bucket that hides it.";

  if (!allowed && state.live) {
    // Turned off rather than left on and ineffective: a Live indicator that is
    // lit while nothing is being sampled is worse than no indicator.
    state.live = false;
    button.setAttribute("aria-pressed", "false");
    schedule();
    notice("Live updates switched off — they only apply to the last 10 minutes.");
  }
}

$("range").addEventListener("change", () => {
  state.range = Number($("range").value);
  syncLiveAvailability();
  // Everything already drawn was drawn for the old window.
  state.rendered.clear();
  writeRoute();
  refreshCurrent();
});

let redrawTimer = null;
window.addEventListener("resize", () => {
  // Canvases are laid out in pixels, so they have to be told when the pixels
  // change. Debounced: a drag across the screen is one resize, not forty.
  clearTimeout(redrawTimer);
  redrawTimer = setTimeout(() => redrawCharts(), 150);
});

$("live").addEventListener("click", () => {
  if (state.range !== LIVE_RANGE) return;
  state.live = !state.live;
  $("live").setAttribute("aria-pressed", String(state.live));
  schedule();
  if (state.live) tick();
});

for (const id of ["offCategory", "offKind", "offSort"]) {
  $(id).addEventListener("change", () => refreshOffenders().catch(e => notice(String(e.message || e))));
}

/*
 * Reopen whichever way the current log was named.
 *
 * Every control here used to pass state.log.pathId, which is null for a log
 * opened by path - so paging or searching a host log would silently do nothing.
 */
const reopenLog = () => (state.log.pathId
  ? showLog(state.log.pathId, true)
  : showLogPath(state.log.path, true));

$("logClose").addEventListener("click", () => {
  state.log = { pathId: null, path: null, page: 0, needle: "" };
  $("logPanel").hidden = true;
});

$("logOlder").addEventListener("click", () => {
  state.log.page += 1;
  reopenLog();
});

$("logNewer").addEventListener("click", () => {
  state.log.page = Math.max(0, state.log.page - 1);
  reopenLog();
});

$("logLines").addEventListener("change", () => {
  state.log.page = 0;
  if (state.log.pathId || state.log.path) reopenLog();
});

// Searching restarts at the newest match rather than keeping the page number,
// which would otherwise land somewhere arbitrary in a different set of results.
let searchTimer = null;
$("logSearch").addEventListener("input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    state.log.needle = $("logSearch").value.trim();
    state.log.page = 0;
    if (state.log.pathId || state.log.path) reopenLog();
  }, 350);
});

$("scanNow").addEventListener("click", async () => {
  const button = $("scanNow");
  button.disabled = true;
  try {
    await post("scan-now");
    notice("Scan started. It runs in the background — the tables update when it finishes.", true);
    /*
     * Polled faster while it runs.
     *
     * A scan is the one thing here that takes minutes and then changes
     * everything at once, and waiting up to thirty seconds to notice it has
     * finished makes the button feel broken.
     */
    state.scanRunning = true;
    schedule();
    await refreshAll();
  } catch (error) {
    notice(String(error.message || error));
    button.disabled = false;
  }
});

$("procBy").addEventListener("change", () => {
  refreshProcesses().catch(e => notice(String(e.message || e)));
});

$("procRefresh").addEventListener("click", () => {
  refreshProcesses().catch(e => notice(String(e.message || e)));
});

$("hostMetric").addEventListener("change", () => {
  state.hostMetric = $("hostMetric").value;
  refreshServiceChart().catch(e => notice(String(e.message || e)));
});

$("problemClear").addEventListener("click", async () => {
  if (!confirm("Clear the problem log?\n\nThe entries are removed for good. Anything "
      + "still broken will write a fresh one on its next failure, which is the point: "
      + "what is left afterwards is what is still wrong.")) return;

  try {
    const result = await post("clear-problems");
    notice(`Cleared ${count(result.removed, "entry", "entries")}.`, true);
  } catch (error) {
    notice(String(error.message || error));
  }
  refreshProblems().catch(() => {});
});

$("hostRefresh").addEventListener("click", () => {
  refreshHost().catch(e => notice(String(e.message || e)));
});

$("procGroup").addEventListener("change", () => {
  refreshProcesses().catch(e => notice(String(e.message || e)));
});

$("patternForm").addEventListener("submit", event => {
  event.preventDefault();
  previewPattern().catch(e => notice(String(e.message || e)));
});

$("symlinkCheck").addEventListener("click", () => {
  checkSymlinks().catch(e => notice(String(e.message || e)));
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

$("listDialogClose").addEventListener("click", () => $("listDialog").close());

$("capFilter").addEventListener("change", () => {
  if (state.capabilities?.available) renderCapList(state.capabilities);
});

$("capCheck").addEventListener("click", async () => {
  const button = $("capCheck");
  button.disabled = true;
  button.textContent = "Checking…";
  try {
    const result = await post("check-capabilities");
    notice(`Checked ${count(result.total, "program")} in ${result.seconds}s`
      + (result.changes ? `, ${count(result.changes, "change")} since last time.` : "."));
    await refreshCapabilities();
    await refreshMounts();
    await refreshCapabilityChanges();
  } catch (error) {
    notice(String(error.message || error));
  } finally {
    button.disabled = false;
    button.textContent = "Check now";
  }
});

$("capHostCheck").addEventListener("click", async () => {
  try {
    await post("request-host-task", { task: "capabilities" });
    notice("Requested. The host collector will check on its next run.");
    await refreshCapabilities();
  } catch (error) {
    notice(String(error.message || error));
  }
});

$("incidentClose").addEventListener("click", () => {
  $("incidentPanel").hidden = true;
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

// Redrawing on resize is a canvas thing, not a data thing - and this said so
// while calling refreshAll, which re-fetched every endpoint on the view for
// every resize. Replaying the last draw is what a wider window needs.
let resizeTimer = null;
window.addEventListener("resize", () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    const section = document.querySelector(`[data-view="${state.view}"]`);
    redrawCharts(section || document);
  }, 200);
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) refreshAll();
});

/* ------------------------------------------------------------------ start -- */

for (const link of document.querySelectorAll("[data-nav]")) {
  link.addEventListener("click", event => {
    event.preventDefault();
    showView(link.dataset.nav);
  });
}

$("navToggle").addEventListener("click", () => {
  document.body.classList.contains("nav-open") ? closeNav() : openNav();
});
$("scrim").addEventListener("click", closeNav);

document.addEventListener("keydown", event => {
  if (event.key === "Escape") closeNav();
});

// Back and forward move between views, which is what people expect of
// something that puts its state in the address bar.
window.addEventListener("hashchange", applyRoute);

$("pollState").addEventListener("click", () => {
  if (!poll.busy) refreshCurrent();
});

for (const [id, key] of [["actionKind", "kind"], ["actionWho", "who"], ["actionResult", "result"]]) {
  $(id).addEventListener("change", event => {
    actionState[key] = event.target.value;
    refreshActionLog().catch(error => notice(String(error.message || error)));
  });
}

for (const id of ["hostDaemonRefresh", "diskProcsRefresh", "rootFilesRefresh"]) {
  $(id).addEventListener("click",
    () => refreshHost().catch(error => notice(String(error.message || error))));
}

$("offenderDelete").addEventListener("click",
  () => deletePicked().catch(error => notice(String(error.message || error))));

$("hostLogRefresh").addEventListener("click",
  () => refreshHostLogs().catch(error => notice(String(error.message || error))));

$("busyExpand").addEventListener("click", () => {
  const all = [...document.querySelectorAll("#processHistory details")];
  const opening = all.some(d => !d.open);
  // Set .open and let the toggle listener record it, so the remembered set and
  // what is on screen cannot drift apart.
  for (const d of all) d.open = opening;
  $("busyExpand").textContent = opening ? "Close all" : "Open all";
});

for (const id of ["pieShowCategories", "typeGrowthMode"]) {
  $(id).addEventListener("change", () => {
    if (state.scans) {
      renderFileTypes(state.scans);
      renderTypeGrowth(state.scans);
    }
  });
}

$("alertUnreadOnly").addEventListener("change", event => {
  alertState.unreadOnly = event.target.checked;
  refreshAlerts().catch(error => notice(String(error.message || error)));
});

$("alertAckAll").addEventListener("click", () => alertAction("ack-all", []));
$("alertAckSome").addEventListener("click",
  () => alertAction("ack", [...alertState.selected]));

$("alertDeleteSome").addEventListener("click", () => {
  const ids = [...alertState.selected];
  if (!ids.length) return;
  // Deleting is the one thing here that cannot be undone from the interface.
  if (!confirm(`Delete ${count(ids.length, "alert")}? The history goes with them.`)) return;
  alertAction("delete", ids);
});

$("shareLink").addEventListener("click", async () => {
  /*
   * A link to exactly this, frozen at this moment.
   *
   * Without the timestamp the recipient opens the same view over the last day
   * *from when they clicked*, which is a different chart. Pinning it is the
   * difference between "look at this" and "look at roughly this".
   */
  state.frozenAt = state.frozenAt || Math.floor(Date.now() / 1000);
  writeRoute(true);

  try {
    await navigator.clipboard.writeText(location.href);
    notice("Link copied. It opens this view over this exact window.", true);
  } catch {
    notice(location.href);
  }
});

// The Live button starts disabled unless the page opens on the short range,
// so it can never be lit while nothing is being sampled.
syncLiveAvailability();

applyRoute();
writeRoute(true);
schedule();
