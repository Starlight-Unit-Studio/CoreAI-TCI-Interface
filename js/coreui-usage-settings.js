/* CoreAI 0.7.30: Settings > Nutzung after the reference screenshots (api/usage.php).
   Übersicht: the plan, the daily message limit (a 24-hour window from the first message), storage,
   messages today by hour or the last 7 days. Analysen: tokens by Core or thinking level, the top
   chats, the days, tokens by Core over time, tools and skills, messages (all or by kind).
   Only what CoreAI counts; the charts are drawn here as SVG. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var view = $('view-nutzung');
  if (!view || !$('nuMain')) return;

  var COLORS = ['#5b92ff', '#ca852a', '#299b70', '#8a6ff2', '#13a6b5', '#d0567c'];
  var MONTHS = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'];
  var WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  var state = {loaded: false, loading: false, overview: null, now: 'today', days: 7, back: 0, split: 'core', msg: 'all', core: '',
    analytics: null, chats: [], chatsMore: false, chatsOffset: 0, daysShown: 5, seq: 0, timer: 0};

  // --- Formatting -------------------------------------------------------------------------------------------
  function num(n) { return Number(n || 0).toLocaleString('de-DE'); }
  function pct(n) { return Number(n || 0).toLocaleString('de-DE', {maximumFractionDigits: 1}) + ' %'; }
  function plural(n, one, many) { return num(n) + ' ' + (Number(n) === 1 ? one : many); }
  function axis(n) {
    if (n >= 1e6) return (n / 1e6).toLocaleString('de-DE', {maximumFractionDigits: 1}) + ' Mio.';
    if (n >= 1e4) return Math.round(n / 1e3).toLocaleString('de-DE') + ' Tsd.';
    return num(n);
  }
  function size(bytes) {
    var n = Number(bytes) || 0;
    var de = function (v, d) { return v.toLocaleString('de-DE', {maximumFractionDigits: d}); };
    if (n >= 1073741824) return de(n / 1073741824, 2) + ' GB';
    if (n >= 1048576) return de(n / 1048576, n >= 104857600 ? 0 : 1) + ' MB';
    return de(Math.max(n > 0 ? 1 : 0, Math.ceil(n / 1024)), 0) + ' KB';
  }
  function parts(d) { var p = String(d).split('-'); return {y: +p[0], m: +p[1], d: +p[2]}; }
  function day(d) { var p = parts(d); return p.d + '. ' + MONTHS[p.m - 1]; }
  function longDay(d) {
    var p = parts(d);
    return WEEKDAYS[new Date(p.y, p.m - 1, p.d).getDay()] + ', ' + p.d + '. ' + MONTHS[p.m - 1];
  }
  function rangeLabel(from, to) {
    var a = parts(from), b = parts(to);
    if (a.y === b.y && a.m === b.m) return a.d + '.–' + b.d + '. ' + MONTHS[b.m - 1] + ' ' + b.y;
    if (a.y === b.y) return a.d + '. ' + MONTHS[a.m - 1] + ' – ' + b.d + '. ' + MONTHS[b.m - 1] + ' ' + b.y;
    return a.d + '. ' + MONTHS[a.m - 1] + ' ' + a.y + ' – ' + b.d + '. ' + MONTHS[b.m - 1] + ' ' + b.y;
  }
  function until(iso) {
    var ms = new Date(iso).getTime() - Date.now();
    if (!(ms > 0)) return 'gleich';
    var min = Math.ceil(ms / 60000);
    var h = Math.floor(min / 60);
    return h > 0 ? h + ' Std., ' + (min % 60) + ' Min.' : min + ' Min.';
  }
  function status(text, error) {
    var node = $('nuStatus');
    node.textContent = text || '';
    if (error) node.setAttribute('data-state', 'error'); else node.removeAttribute('data-state');
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function request(params) {
    return fetch('api/usage.php?' + params, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok || !d.ok) { var e = new Error(d.error || ('http_' + r.status)); e.code = d.error || ''; e.status = r.status; throw e; }
          return d;
        });
      });
  }

  // --- Charts -----------------------------------------------------------------------------------------------
  var NS = 'http://www.w3.org/2000/svg';
  function svgEl(tag, attrs) {
    var n = document.createElementNS(NS, tag);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    return n;
  }
  function niceMax(v) {
    if (!(v > 0)) return 1;
    var exp = Math.pow(10, Math.floor(Math.log10(v)));
    var steps = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < steps.length; i++) if (steps[i] * exp >= v) return steps[i] * exp;
    return 10 * exp;
  }
  /* opts: {type: 'bars'|'lines', labels: [full label per point], ticks: [index ...], series: [{label, color, values}],
     unit: 'Tokens', empty: 'text', title: 'aria'} */
  function chart(box, opts) {
    box.textContent = '';
    box._opts = opts;
    var series = opts.series.filter(function (s) { return s.values.some(function (v) { return v > 0; }); });
    var n = opts.labels.length;
    if (!series.length || !n) {
      box.appendChild(el('p', 'nu-chart-empty', opts.empty || 'In diesem Zeitraum gibt es nichts zu zeigen.'));
      return;
    }
    var W = Math.max(260, box.clientWidth || 600), H = 190, L = 54, R = 8, T = 10, B = 26;
    var pw = W - L - R, ph = H - T - B, band = pw / n;
    var totals = [];
    for (var i = 0; i < n; i++) {
      var sum = 0, top = 0;
      series.forEach(function (s) { sum += s.values[i] || 0; top = Math.max(top, s.values[i] || 0); });
      totals.push(opts.type === 'bars' ? sum : top);
    }
    // Every value here is a whole number (messages, calls, tokens): the top and the middle line are too.
    var max = Math.max(2, niceMax(Math.max.apply(null, totals)));
    if (max % 2) max = Math.ceil(max / 2) * 2;
    var y = function (v) { return T + ph - (v / max) * ph; };
    var svg = svgEl('svg', {viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', 'aria-label': opts.title || ''});
    [0, max / 2, max].forEach(function (v) {
      svg.appendChild(svgEl('line', {x1: L, x2: W - R, y1: y(v), y2: y(v), class: 'nu-grid'}));
      var t = svgEl('text', {x: L - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'nu-axis'});
      t.textContent = axis(v);
      svg.appendChild(t);
    });
    (opts.ticks || [0, Math.floor((n - 1) / 2), n - 1]).forEach(function (i) {
      var t = svgEl('text', {x: L + band * (i + 0.5), y: H - 6, 'text-anchor': 'middle', class: 'nu-axis'});
      t.textContent = opts.tickLabels ? opts.tickLabels[i] : opts.labels[i];
      svg.appendChild(t);
    });
    if (opts.type === 'bars') {
      var bw = Math.max(3, Math.min(34, band * 0.68));
      for (var b = 0; b < n; b++) {
        var acc = 0;
        series.forEach(function (s) {
          var v = s.values[b] || 0;
          if (v <= 0) return;
          var y0 = y(acc), y1 = y(acc + v);
          svg.appendChild(svgEl('rect', {x: L + band * b + (band - bw) / 2, y: y1, width: bw, height: Math.max(1, y0 - y1), fill: s.color, rx: 2}));
          acc += v;
        });
      }
    } else {
      series.forEach(function (s) {
        var d = s.values.map(function (v, i) { return (i ? 'L' : 'M') + (L + band * (i + 0.5)).toFixed(1) + ' ' + y(v || 0).toFixed(1); }).join(' ');
        svg.appendChild(svgEl('path', {d: d, fill: 'none', stroke: s.color, 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}));
      });
    }
    var guide = svgEl('line', {x1: 0, x2: 0, y1: T, y2: T + ph, class: 'nu-guide', visibility: 'hidden'});
    svg.appendChild(guide);
    box.appendChild(svg);
    var tip = el('div', 'nu-tip');
    tip.hidden = true;
    box.appendChild(tip);
    function show(clientX) {
      var rect = svg.getBoundingClientRect();
      var x = (clientX - rect.left) * (W / rect.width);
      var i = Math.max(0, Math.min(n - 1, Math.floor((x - L) / band)));
      var cx = L + band * (i + 0.5);
      guide.setAttribute('x1', cx);
      guide.setAttribute('x2', cx);
      guide.setAttribute('visibility', 'visible');
      tip.textContent = '';
      var total = 0;
      series.forEach(function (s) { total += s.values[i] || 0; });
      tip.appendChild(el('b', '', opts.type === 'bars' || series.length === 1 ? num(total) + ' ' + (opts.unit || '') : opts.labels[i]));
      if (opts.type === 'bars' || series.length === 1) tip.appendChild(el('small', '', opts.labels[i]));
      if (series.length > 1) {
        series.forEach(function (s) {
          var line = el('span', 'nu-tip-row');
          var dot = el('i');
          dot.style.background = s.color;
          line.appendChild(dot);
          line.appendChild(el('span', '', s.label));
          line.appendChild(el('b', '', num(s.values[i] || 0)));
          tip.appendChild(line);
        });
      }
      tip.hidden = false;
      var left = (cx / W) * rect.width - tip.offsetWidth / 2;
      tip.style.left = Math.max(0, Math.min(rect.width - tip.offsetWidth, left)) + 'px';
    }
    svg.addEventListener('pointermove', function (e) { show(e.clientX); });
    svg.addEventListener('pointerdown', function (e) { show(e.clientX); });
    svg.addEventListener('pointerleave', function (e) {
      if (e.pointerType === 'mouse') { tip.hidden = true; guide.setAttribute('visibility', 'hidden'); }
    });
  }
  function legend(box, series, total, unit) {
    box.textContent = '';
    series.forEach(function (s) {
      if (!(s.total > 0)) return;
      var item = el('div', 'nu-legend-item');
      item.style.setProperty('--nu-color', s.color);
      item.appendChild(el('span', '', s.label));
      item.appendChild(el('b', '', total > 0 ? pct(Math.round(s.total * 1000 / total) / 10) : num(s.total)));
      item.title = num(s.total) + ' ' + unit;
      box.appendChild(item);
    });
  }
  function colored(list) {
    return list.map(function (s, i) { return {label: s.label, total: s.total, values: s.days, color: COLORS[i % COLORS.length], id: s.id}; });
  }
  var resizeTimer = 0;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      Array.prototype.forEach.call(view.querySelectorAll('.nu-chart'), function (box) { if (box._opts && box.offsetParent) chart(box, box._opts); });
    }, 150);
  });

  // --- Übersicht --------------------------------------------------------------------------------------------
  function limitRow(title, sub, right, fraction, foot, action) {
    var row = el('div', 'nu-limit');
    var head = el('div', 'nu-limit-head');
    head.appendChild(el('strong', '', title));
    var line = el('div', 'nu-limit-line');
    line.appendChild(el('span', '', sub));
    line.appendChild(el('b', '', right));
    row.appendChild(head);
    row.appendChild(line);
    if (fraction !== null) {
      var bar = el('div', 'nu-bar' + (fraction <= 0.1 ? ' is-low' : ''));
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.setAttribute('aria-valuenow', String(Math.round(fraction * 100)));
      bar.setAttribute('aria-label', title + ': ' + right);
      var fill = el('i');
      fill.style.width = Math.max(0, Math.min(100, fraction * 100)) + '%';
      bar.appendChild(fill);
      row.appendChild(bar);
    }
    if (foot || action) {
      var f = el('p', 'nu-limit-foot', foot || '');
      if (action) f.appendChild(action);
      row.appendChild(f);
    }
    return row;
  }
  function renderLimits() {
    var o = state.overview;
    $('nuPlanIntro').textContent = 'Dein Plan: ' + o.plan.label + '. ' + o.plan.note;
    var box = $('nuLimits');
    box.textContent = '';
    var d = o.daily;
    if (d.limit !== null) {
      var left = d.limit > 0 ? d.left / d.limit : 0;
      box.appendChild(limitRow('Tageslimit',
        d.started && d.reset_at ? 'Bis zum Zurücksetzen: ' + until(d.reset_at) : 'Dein 24-Stunden-Fenster beginnt mit der nächsten Nachricht.',
        pct(Math.round(left * 100)) + ' übrig', left, plural(d.used, 'Nachricht', 'Nachrichten') + ' von ' + num(d.limit) + ' verwendet.'));
    } else {
      box.appendChild(limitRow('Tageslimit', 'Für dein Konto gibt es kein Tageslimit.', 'Ohne Grenze', null));
    }
    var s = o.storage;
    var go = el('button', 'pz-link nu-go', 'Speicher verwalten');
    go.type = 'button';
    go.setAttribute('data-goto', 'speicher');
    if (s.limit) {
      var free = Math.max(0, 1 - s.used / s.limit);
      box.appendChild(limitRow('Speicher', size(s.used) + ' von ' + size(s.limit) + ' verwendet', pct(Math.round(free * 100)) + ' übrig', free, '', go));
    } else {
      box.appendChild(limitRow('Speicher', size(s.used) + ' verwendet', 'Ohne Grenze', null, '', go));
    }
    var list = $('nuFeatures');
    list.textContent = '';
    o.plan.features.forEach(function (f) { list.appendChild(el('li', '', f)); });
    if (o.plan.server_off.length) list.appendChild(el('li', 'is-off', 'Auf diesem Server aus: ' + o.plan.server_off.join(', ')));
  }
  function renderNow() {
    var o = state.overview;
    Array.prototype.forEach.call(view.querySelectorAll('[data-nu-now]'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-nu-now') === state.now ? 'true' : 'false');
    });
    if (state.now === 'today') {
      $('nuNowBig').textContent = plural(o.messages_today, 'Nachricht', 'Nachrichten');
      $('nuNowSub').textContent = 'Heute · ' + num(o.tokens_today) + ' Tokens';
      var hours = o.hours.map(function (_, h) { return h + ' Uhr'; });
      chart($('nuNowChart'), {type: 'bars', labels: hours.map(function (h, i) { return 'Heute, ' + i + '–' + (i + 1) + ' Uhr'; }),
        tickLabels: hours, ticks: [0, 6, 12, 18, 23], unit: 'Nachrichten', title: 'Nachrichten heute nach Stunde',
        series: [{label: 'Nachrichten', color: COLORS[0], values: o.hours}], empty: 'Heute hast du Mara noch nicht geschrieben.'});
    } else {
      var total = o.week.reduce(function (s, x) { return s + x.n; }, 0);
      $('nuNowBig').textContent = plural(total, 'Nachricht', 'Nachrichten');
      $('nuNowSub').textContent = 'Letzte 7 Tage · ' + rangeLabel(o.week[0].d, o.week[6].d);
      chart($('nuNowChart'), {type: 'bars', labels: o.week.map(function (x) { return longDay(x.d); }),
        tickLabels: o.week.map(function (x) { return day(x.d); }), unit: 'Nachrichten', title: 'Nachrichten der letzten 7 Tage',
        series: [{label: 'Nachrichten', color: COLORS[0], values: o.week.map(function (x) { return x.n; })}],
        empty: 'In den letzten 7 Tagen hast du Mara nicht geschrieben.'});
    }
  }

  // --- Analysen ---------------------------------------------------------------------------------------------
  function ticksFor(n) { return n <= 7 ? [0, Math.floor((n - 1) / 2), n - 1] : [0, Math.floor(n / 2), n - 1]; }
  function renderAnalytics() {
    var a = state.analytics;
    var list = a.range.list;
    var labels = list.map(longDay);
    var tickLabels = list.map(day);
    var ticks = ticksFor(list.length);
    $('nuRangeLabel').textContent = rangeLabel(a.range.from, a.range.to);
    $('nuPrev').disabled = !!a.range.oldest;
    $('nuNext').disabled = !!a.range.latest;
    Array.prototype.forEach.call(view.querySelectorAll('[data-nu-days]'), function (b) {
      b.setAttribute('aria-pressed', Number(b.getAttribute('data-nu-days')) === state.days ? 'true' : 'false');
    });
    var since = a.tokens.since ? ' Gezählt seit ' + day(a.tokens.since) + ' ' + parts(a.tokens.since).y + '.' : '';

    // Tokenverlauf.
    Array.prototype.forEach.call(view.querySelectorAll('[data-nu-split]'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-nu-split') === state.split ? 'true' : 'false');
    });
    $('nuTokensIntro').textContent = 'So viele Tokens haben deine Cores gelesen und geschrieben.' + since;
    var split = colored(state.split === 'core' ? a.tokens.by_core : a.tokens.by_thinking);
    chart($('nuTokenBars'), {type: 'bars', labels: labels, tickLabels: tickLabels, ticks: ticks, unit: 'Tokens', series: split,
      title: 'Tokenverlauf ' + rangeLabel(a.range.from, a.range.to) + ': ' + num(a.tokens.total) + ' Tokens',
      empty: a.tokens.ready ? 'In diesem Zeitraum hat kein Core für dich gearbeitet.' : 'Tokens werden auf diesem Server noch nicht gezählt.'});
    legend($('nuTokenLegend'), split, a.tokens.total, 'Tokens');

    // Tage im Überblick.
    renderDays();

    // Tokens nach Core.
    var cores = colored(a.tokens.by_core);
    if (state.core && !cores.some(function (c) { return c.label === state.core; })) state.core = '';
    var shown = state.core ? cores.filter(function (c) { return c.label === state.core; }) : cores;
    $('nuCoreSelect').querySelector('span').textContent = state.core || 'Alle Cores';
    $('nuCoreSelect').disabled = cores.length === 0;
    $('nuCoreIntro').textContent = 'Tokens je Core im Lauf der Zeit.' + since;
    $('nuCoreTotal').textContent = num(shown.reduce(function (s, c) { return s + c.total; }, 0));
    chart($('nuCoreLines'), {type: 'lines', labels: labels, tickLabels: tickLabels, ticks: ticks, unit: 'Tokens', series: shown,
      title: 'Tokens nach Core', empty: 'In diesem Zeitraum hat kein Core für dich gearbeitet.'});
    legend($('nuCoreLegend'), shown, 0, 'Tokens');

    // Werkzeuge und Skills.
    var tools = colored(a.tools.series);
    $('nuToolsTotal').textContent = num(a.tools.total);
    chart($('nuToolLines'), {type: 'lines', labels: labels, tickLabels: tickLabels, ticks: ticks, unit: 'Aufrufe', series: tools,
      title: 'Werkzeuge aufgerufen: ' + num(a.tools.total), empty: 'In diesem Zeitraum hat Mara keine Werkzeuge aufgerufen.'});
    legend($('nuToolLegend'), tools, 0, 'Aufrufe');
    $('nuSkillsTotal').textContent = num(a.skills.total);
    chart($('nuSkillLines'), {type: 'lines', labels: labels, tickLabels: tickLabels, ticks: ticks, unit: 'Verwendungen',
      series: [{label: 'Skills', color: COLORS[3], values: a.skills.days}], title: 'Verwendete Skills: ' + num(a.skills.total),
      empty: 'In diesem Zeitraum hat Mara keine Skills verwendet.'});
    $('nuSkillNames').textContent = a.skills.names.length ? 'Am häufigsten: ' + a.skills.names.map(function (s) { return s.name + ' (' + num(s.n) + ')'; }).join(', ') : '';

    // Nachrichten.
    Array.prototype.forEach.call(view.querySelectorAll('[data-nu-msg]'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-nu-msg') === state.msg ? 'true' : 'false');
    });
    var kinds = colored(a.messages.series);
    var msgSeries = state.msg === 'kind' ? kinds : [{label: 'Nachrichten', total: a.messages.total, values: a.messages.days, color: COLORS[0]}];
    $('nuMsgTotal').textContent = num(a.messages.total);
    chart($('nuMsgLines'), {type: 'lines', labels: labels, tickLabels: tickLabels, ticks: ticks, unit: 'Nachrichten', series: msgSeries,
      title: 'Nachrichten: ' + num(a.messages.total), empty: 'In diesem Zeitraum hast du nichts geschrieben.'});
    legend($('nuMsgLegend'), state.msg === 'kind' ? kinds : [], a.messages.total, 'Nachrichten');
  }

  function renderDays() {
    var a = state.analytics;
    var box = $('nuDays');
    box.textContent = '';
    var rows = [];
    a.range.list.forEach(function (d, i) {
      var tokens = 0, cores = [], tools = [];
      a.tokens.by_core.forEach(function (c) { if (c.days[i] > 0) { tokens += c.days[i]; cores.push(c.label + ': ' + num(c.days[i])); } });
      a.tools.series.forEach(function (t) { if (t.days[i] > 0) tools.push(t.label + ': ' + num(t.days[i])); });
      var skills = a.skills.days[i] || 0;
      var msgs = a.messages.days[i] || 0;
      if (msgs || tokens || tools.length || skills) rows.push({d: d, msgs: msgs, tokens: tokens, cores: cores, tools: tools, skills: skills});
    });
    rows.reverse();
    $('nuDaysEmpty').hidden = rows.length > 0;
    rows.slice(0, state.daysShown).forEach(function (r, idx) {
      var item = el('div', 'nu-day');
      var head = el('button', 'nu-day-head');
      head.type = 'button';
      head.setAttribute('aria-expanded', idx === 0 ? 'true' : 'false');
      head.setAttribute('aria-controls', 'nuDay-' + r.d);
      var name = el('span', 'nu-day-name', longDay(r.d));
      name.insertAdjacentHTML('beforeend', '<svg aria-hidden="true"><use href="#i-chevron" /></svg>');
      head.appendChild(name);
      head.appendChild(el('b', '', plural(r.msgs, 'Nachricht', 'Nachrichten')));
      var body = el('div', 'nu-day-body');
      body.id = 'nuDay-' + r.d;
      body.hidden = idx !== 0;
      var line = function (label, value) {
        var p = el('p', 'nu-day-line');
        p.appendChild(el('span', '', label));
        p.appendChild(el('b', '', value));
        body.appendChild(p);
      };
      line('Tokens', num(r.tokens));
      r.cores.forEach(function (c) { var i = c.lastIndexOf(': '); line(c.slice(0, i), c.slice(i + 2)); });
      r.tools.forEach(function (t) { var i = t.lastIndexOf(': '); line(t.slice(0, i), t.slice(i + 2)); });
      if (r.skills) line('Skills', num(r.skills));
      head.addEventListener('click', function () {
        var open = head.getAttribute('aria-expanded') !== 'true';
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
      });
      item.appendChild(head);
      item.appendChild(body);
      box.appendChild(item);
    });
    $('nuDaysMore').hidden = rows.length <= state.daysShown;
  }

  function renderChats() {
    var body = $('nuChats').querySelector('tbody');
    body.textContent = '';
    state.chats.forEach(function (c) {
      var tr = el('tr');
      var th = el('th');
      th.scope = 'row';
      if (c.id) {
        var a = el('a', '', c.title);
        a.href = 'app.html?session=' + encodeURIComponent(c.id);
        th.appendChild(a);
      } else {
        th.appendChild(el('span', 'nu-locked', c.title));
      }
      if (c.archived) th.appendChild(el('small', '', ' archiviert'));
      tr.appendChild(th);
      tr.appendChild(el('td', '', num(c.messages)));
      tr.appendChild(el('td', '', pct(c.share)));
      tr.appendChild(el('td', '', c.tools ? num(c.tools) : '–'));
      body.appendChild(tr);
    });
    $('nuChatsEmpty').hidden = state.chats.length > 0;
    $('nuChats').hidden = state.chats.length === 0;
    $('nuChatsMore').hidden = !state.chatsMore;
  }

  function loadAnalytics() {
    var seq = ++state.seq;
    var q = 'days=' + state.days + '&back=' + state.back;
    status('Analysen werden geladen …');
    return Promise.all([request('action=analytics&' + q), request('action=chats&' + q + '&offset=0')]).then(function (res) {
      if (seq !== state.seq) return;
      state.analytics = res[0];
      state.chats = res[1].items || [];
      state.chatsMore = !!res[1].has_more;
      state.chatsOffset = state.chats.length;
      state.daysShown = 5;
      status('');
      renderAnalytics();
      renderChats();
    }).catch(function (err) {
      if (seq !== state.seq) return;
      status('Die Analysen ließen sich nicht laden. Bitte versuche es gleich noch einmal.', true);
    });
  }

  // --- Wiring -----------------------------------------------------------------------------------------------
  function setTab(tab) {
    Array.prototype.forEach.call(view.querySelectorAll('[data-nu-tab]'), function (b) {
      var on = b.getAttribute('data-nu-tab') === tab;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
    });
    $('nuOverview').hidden = tab !== 'overview';
    $('nuAnalytics').hidden = tab !== 'analytics';
    if (tab === 'analytics') {
      if (!state.analytics) loadAnalytics();
      else renderAnalytics();
    } else if (state.overview) {
      renderNow();
    }
  }
  Array.prototype.forEach.call(view.querySelectorAll('[data-nu-tab]'), function (b) {
    b.addEventListener('click', function () { setTab(b.getAttribute('data-nu-tab')); });
    b.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      e.preventDefault();
      var next = b.getAttribute('data-nu-tab') === 'overview' ? 'analytics' : 'overview';
      setTab(next);
      view.querySelector('[data-nu-tab="' + next + '"]').focus();
    });
  });
  Array.prototype.forEach.call(view.querySelectorAll('[data-nu-now]'), function (b) {
    b.addEventListener('click', function () { state.now = b.getAttribute('data-nu-now'); renderNow(); });
  });
  Array.prototype.forEach.call(view.querySelectorAll('[data-nu-days]'), function (b) {
    b.addEventListener('click', function () {
      var days = Number(b.getAttribute('data-nu-days'));
      if (days === state.days) return;
      state.days = days;
      state.back = 0;
      loadAnalytics();
    });
  });
  $('nuPrev').addEventListener('click', function () { state.back += 1; loadAnalytics(); });
  $('nuNext').addEventListener('click', function () { if (state.back > 0) { state.back -= 1; loadAnalytics(); } });
  Array.prototype.forEach.call(view.querySelectorAll('[data-nu-split]'), function (b) {
    b.addEventListener('click', function () { state.split = b.getAttribute('data-nu-split'); renderAnalytics(); });
  });
  Array.prototype.forEach.call(view.querySelectorAll('[data-nu-msg]'), function (b) {
    b.addEventListener('click', function () { state.msg = b.getAttribute('data-nu-msg'); renderAnalytics(); });
  });
  $('nuDaysMore').addEventListener('click', function () { state.daysShown += 5; renderDays(); });
  $('nuChatsMore').addEventListener('click', function () {
    var btn = $('nuChatsMore');
    btn.disabled = true;
    request('action=chats&days=' + state.days + '&back=' + state.back + '&offset=' + state.chatsOffset).then(function (d) {
      state.chats = state.chats.concat(d.items || []);
      state.chatsMore = !!d.has_more;
      state.chatsOffset = state.chats.length;
      renderChats();
    }).catch(function () {
      status('Weitere Chats ließen sich nicht laden.', true);
    }).then(function () { btn.disabled = false; });
  });

  // The Core menu.
  var menu = $('nuCoreMenu');
  var coreBtn = $('nuCoreSelect');
  function closeMenu(focus) {
    if (menu.hidden) return;
    menu.hidden = true;
    coreBtn.setAttribute('aria-expanded', 'false');
    if (focus) coreBtn.focus();
  }
  coreBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (!menu.hidden) { closeMenu(false); return; }
    menu.textContent = '';
    [''].concat(state.analytics.tokens.by_core.map(function (c) { return c.label; })).forEach(function (label) {
      var item = el('button', 'arc-option pz-option');
      item.type = 'button';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('aria-checked', label === state.core ? 'true' : 'false');
      item.setAttribute('data-value', label);
      var copy = el('span');
      copy.appendChild(el('b', '', label || 'Alle Cores'));
      item.appendChild(copy);
      item.insertAdjacentHTML('beforeend', '<svg aria-hidden="true"><use href="#i-check" /></svg>');
      item.addEventListener('click', function (ev) {
        ev.stopPropagation();
        state.core = label;
        closeMenu(true);
        renderAnalytics();
      });
      menu.appendChild(item);
    });
    menu.hidden = false;
    coreBtn.setAttribute('aria-expanded', 'true');
    placeMenu();
    var first = menu.querySelector('[aria-checked="true"]') || menu.firstChild;
    if (first) first.focus({preventScroll: true});
  });
  // Like the other menus of the settings: below the button, above it when there is no room.
  function placeMenu() {
    var rect = coreBtn.getBoundingClientRect();
    var width = menu.offsetWidth, height = menu.offsetHeight;
    var top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8 && rect.top - 8 - height >= 8) top = rect.top - 8 - height;
    menu.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }
  menu.addEventListener('keydown', function (e) {
    var items = Array.prototype.slice.call(menu.querySelectorAll('.arc-option'));
    var i = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus({preventScroll: true}); }
    if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus({preventScroll: true}); }
    if (e.key === 'Escape') { e.preventDefault(); closeMenu(true); }
  });
  document.addEventListener('click', function (e) { if (!menu.hidden && !menu.contains(e.target)) closeMenu(false); });
  document.addEventListener('scroll', function (e) {
    if (menu.hidden || menu.contains(e.target)) return;
    var rect = coreBtn.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) closeMenu(false);
    else placeMenu();
  }, true);
  window.addEventListener('resize', function () { closeMenu(false); });

  function loadOverview() {
    if (state.loading) return;
    state.loading = true;
    request('action=overview').then(function (o) {
      state.overview = o;
      state.loaded = true;
      status('');
      $('nuMain').hidden = false;
      renderLimits();
      renderNow();
      clearInterval(state.timer);
      // The countdown of the daily limit window.
      state.timer = setInterval(function () { if (state.overview && !view.hidden) renderLimits(); }, 60000);
    }).catch(function (err) {
      if (err.status === 403) status('Die Nutzung gibt es nur für angemeldete Konten.', true);
      else status('Die Nutzung ließ sich nicht laden. Bitte versuche es gleich noch einmal.', true);
    }).then(function () { state.loading = false; });
  }
  function maybeLoad() { if (!view.hidden && !state.loaded) loadOverview(); }
  new MutationObserver(maybeLoad).observe(view, {attributes: true, attributeFilter: ['hidden']});
  maybeLoad();
  window.CoreAIUsageSettings = Object.freeze({reload: function () { state.loaded = false; state.analytics = null; maybeLoad(); }});
})();
