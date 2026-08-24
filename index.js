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
/** While a scan is running, so its finishing is noticed promptly. */
const SCAN_POLL_MS = 5000;

const state = {
  signedIn: false,
  csrf: null,
  range: 86400,
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
   * A slice writes inside itself only if the text actually fits.
   *
   * A share threshold alone is a guess about pixels: at 8% two neighbouring
   * wedges are each narrower than the words placed in them, and the labels
   * collide across the boundary. The wedge's width at the text's radius is a
   * chord - 2r·sin(θ/2) - so it can simply be measured against the widest of
   * the two lines, and the answer holds at any canvas size.
   */
  const textRadius = radius * 0.62;

  // "both" writes the share and the size, "percent" only the share, "none"
  // leaves both to the list. Degrading through the middle case labels a good
  // many more wedges than an all-or-nothing test at the same width.
  const inside = slices.map(slice => {
    const share = shareOf(slice);
    if (share < 0.04) {
      return "none";
    }

    const sweep = share * Math.PI * 2;
    const available = 2 * textRadius * Math.sin(Math.min(sweep, Math.PI) / 2);

    ctx.font = "bold 13px system-ui";
    const percentWidth = ctx.measureText(percentText(share)).width;
    ctx.font = "11px system-ui";
    const sizeWidth = ctx.measureText(humanBytes(slice.value)).width;
    ctx.font = "12px system-ui";

    if (available >= Math.max(percentWidth, sizeWidth) + 8) return "both";
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
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, radius, angle, angle + sweep);
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
  const startY = cy - ((slices.length - 1) * lineHeight) / 2;

  slices.forEach((slice, i) => {
    const y = startY + i * lineHeight;
    if (y < 12 || y > cssH - 4) return;

    const color = slice.color || PIE_COLORS[i % PIE_COLORS.length];
    ctx.fillStyle = color;
    ctx.fillRect(labelX, y - 8, 10, 10);

    ctx.fillStyle = "#ddd";
    ctx.fillText(listText[i], labelX + 16, y + 1);
  });

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

async function refreshAll() {
  if (state.busy) return;
  state.busy = true;
  const wasScanning = state.scanRunning;
  try {
    const to = Math.floor(Date.now() / 1000);
    const from = to - state.range;

    /*
     * Uptime is fetched here rather than in the batch below.
     *
     * The charts need its restart marks at the moment they draw. Left in the
     * parallel batch it landed afterwards, so the marks were simply absent on
     * load and appeared only after some later refresh - which looks exactly
     * like the feature not working.
     */
    const [overview, vitals, scans, uptime] = await Promise.all([
      api("overview"),
      api("vitals", { from, to, limit: 900 }),
      api("scans", { from, to }),
      api("uptime", { from, to }),
    ]);

    state.signedIn = overview.signed_in;
    state.csrf = overview.csrf;
    state.deletable = overview.deletable || [];
    state.viewable = overview.viewable || [];
    applySignedInChrome();

    state.categories = overview.categories ?? [];
    state.scanRunning = Boolean(overview.scan_running);
    syncCategoryOptions();
    syncScanButton(overview);

    // The card reads the trend the server worked out over exactly these rows.
    overview.trend = vitals.trend;
    renderCards(overview, vitals.rows);
    /*
     * The shading comes from the vitals payload, not from the uptime endpoint.
     *
     * Both compute the same thing, but the uptime call runs in the parallel
     * batch below and had not landed when the charts first drew - so the bands
     * were simply absent on load and appeared only after a later refresh. The
     * vitals response analyses exactly the rows it returns, which removes the
     * ordering question rather than working around it.
     */
    state.uptime = uptime;
    // The analysis from the vitals payload covers exactly the rows returned,
    // so the shading and the line can never describe different windows.
    state.uptime.analysis = vitals.uptime;
    state.restarts = restartMarks(uptime, from, to);
    renderVitalCharts(vitals.rows, vitals.uptime);
    renderCategoryChart(scans.rows, scans.categories ?? state.categories);
    $("lastSeen").textContent = overview.vitals
      ? `updated ${ago(overview.vitals.taken_at)}`
      : "";

    if (wasScanning && !state.scanRunning) {
      // Finished: back to the ordinary cadence.
      schedule();
      notice("Scan finished.", true);
    }

    await Promise.all([
      refreshOffenders(), refreshAlerts(), refreshActionLog(),
      refreshSchedule(), refreshHealth(), refreshProblems(), refreshProcesses(),
      refreshHost(), refreshUptime(),
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

  $("cpuNote").textContent = absolute && latest
    ? `Share of the machine in use, since the previous reading. Memory is bytes `
      + `consumed on the right, out of ${humanBytes(latest.mem_total_bytes)} total.`
    : "Share of the machine in use, since the previous reading.";
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
    {
      /*
       * Size, and a warning when the size is shared.
       *
       * A file with two directory entries occupies its bytes once. Deleting
       * this path frees nothing while the other link survives, so the number
       * beside it is not the number you would get back - and that is worth
       * knowing before pressing Delete rather than after.
       */
      label: "Size",
      className: "size",
      render: row => {
        const links = Number(row.links ?? 1);
        if (links <= 1) return humanBytes(row.bytes);

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

  renderBusyMoments(data.recorded ?? []);
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
    const section = document.createElement("div");
    section.className = "busy-moment";

    const heading = document.createElement("h4");
    heading.textContent = `${humanTime(moment.taken_at)} — high ${moment.reason}`;
    section.appendChild(heading);

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
    const first = moment.rows[0] || {};
    if (moment.reason === "cpu" && first.trigger_percent != null) {
      const trigger = Number(first.trigger_percent);
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
  const panel = $("hostPanel");
  if (!state.signedIn) {
    panel.hidden = true;
    return;
  }
  panel.hidden = false;

  let data;
  try {
    const to = Math.floor(Date.now() / 1000);
    // The selected metric goes with the request, so a background refresh comes
    // back with the series the user is looking at rather than the default.
    data = await api("host", { from: to - state.range, to, metric: state.hostMetric || "pss" });
  } catch (error) {
    $("hostBody").textContent = String(error.message || error);
    $("hostMemory").hidden = true;
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

    $("hostMemory").hidden = true;
    return;
  }

  $("hostBody").textContent = "";
  $("hostMemory").hidden = false;

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
function refreshUptime() {
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
  const every = state.live ? LIVE_POLL_MS : state.scanRunning ? SCAN_POLL_MS : IDLE_POLL_MS;
  state.timer = setInterval(tick, every);
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
