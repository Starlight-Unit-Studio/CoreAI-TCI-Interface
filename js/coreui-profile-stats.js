/* CoreAI: TCI Interface 0.6.84: Settings > Profil, the overview modeled on the ChatGPT profile
   page: picture, name, role, the figures (tokens counted since 0.6.84, longest task, streaks),
   the usage raster of the last 53 weeks (Täglich, Wöchentlich, Kumulativ), what Mara made, the
   top tools and insights. Public profile, sharing and highlights are shown as planned. All
   numbers come from api/profile_stats.php and belong to the signed-in account only. */
(function () {
  'use strict';

  var view = document.getElementById('view-profil');
  var box = document.getElementById('profileOverview');
  if (!view || !box) return;

  var API = './api/profile_stats.php';
  var $ = function (id) { return document.getElementById(id); };
  var data = null;
  var loading = false;
  var loaded = false;
  var mode = 'day';
  var DAYS = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'];
  var MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
  var TOOL_ICONS = {web: 'i-search', browse: 'i-browser', python: 'i-code', image: 'i-image', files: 'i-file', skills: 'i-spark'};

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function icon(id) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', '#' + id);
    svg.appendChild(use);
    return svg;
  }

  /* 834, 12,3 Tsd., 195,4 Mio., 2,1 Mrd. (German, like the reference). */
  function compact(n) {
    n = Math.max(0, Number(n) || 0);
    var one = function (v) { return v.toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1}); };
    if (n >= 1e9) return one(n / 1e9) + ' Mrd.';
    if (n >= 1e6) return one(n / 1e6) + ' Mio.';
    if (n >= 1e4) return one(n / 1e3) + ' Tsd.';
    return n.toLocaleString('de-DE');
  }

  /* 1 Std. 50 Min., 12 Min., 45 Sek. */
  function duration(sec) {
    sec = Math.max(0, Math.round(Number(sec) || 0));
    if (sec === 0) return '-';
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + ' Std.' + (m > 0 ? ' ' + m + ' Min.' : '');
    if (m > 0) return m + ' Min.';
    return sec + ' Sek.';
  }

  function days(n) { return n === 1 ? '1 Tag' : (n || 0) + ' Tage'; }

  function parseDay(d) {
    var p = String(d || '').split('-');
    return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
  }

  function dateText(d) {
    var dt = parseDay(d);
    return isNaN(dt.getTime()) ? '' : dt.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit', year: 'numeric'});
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    return ((parts[0] || 'O').charAt(0) + (parts[1] || parts[0] || 'P').charAt(parts[1] ? 0 : 1)).toUpperCase();
  }

  function renderHead(profile) {
    profile = profile || {};
    var name = profile.display_name || 'Profil';
    $('profileOverviewName').textContent = name;
    var avatar = $('profileOverviewAvatar');
    avatar.textContent = initials(name);
    avatar.classList.toggle('has-image', !!profile.avatar);
    avatar.style.backgroundImage = profile.avatar ? 'url("' + String(profile.avatar).replace(/"/g, '%22') + '")' : '';
    var since = profile.member_since ? parseDay(profile.member_since) : null;
    // 0.6.86: the @handle from Settings > Konto comes first, like the reference.
    var sub = [profile.username ? '@' + profile.username : '', profile.role || ''];
    if (since && !isNaN(since.getTime())) sub.push('dabei seit ' + since.toLocaleDateString('de-DE', {month: 'long', year: 'numeric'}));
    $('profileOverviewSub').textContent = sub.filter(Boolean).join(' · ');
    var assistant = profile.assistant_name || 'Mara';
    $('poMadeTitle').textContent = 'Von ' + assistant + ' erstellt';
    $('poMadeEmpty').querySelector('p').textContent = assistant + ' hat für dich noch keine Bilder oder Dateien erstellt.';
  }

  function renderFigures(s) {
    var t = s.tokens || {};
    $('poTokensTotal').textContent = t.ready ? compact(t.total) : '-';
    $('poTokensPeak').textContent = t.ready ? compact(t.peak) : '-';
    $('poTokensPeak').parentNode.title = t.peak_day ? 'Höchster Tag: ' + dateText(t.peak_day) : '';
    $('poLongestTask').textContent = duration(s.longest_task_seconds);
    $('poStreakLongest').textContent = days((s.streak || {}).longest);
    $('poStreakCurrent').textContent = days((s.streak || {}).current);
    $('poTokensNote').textContent = t.ready
      ? 'Tokens zählt CoreAI seit dem ' + (t.since ? dateText(t.since) : 'Update auf 0.6.84') + '. Serien und Aufgaben stammen aus deinem ganzen Verlauf.'
      : 'Die Token-Zählung startet, sobald das Update vollständig eingespielt ist (Datenbank-Migration 029).';
  }

  /* Levels 0 to 4 against the highest value of the same kind (tokens or messages). */
  function level(v, max) {
    if (!v || !max) return 0;
    var r = v / max;
    return r > 0.66 ? 4 : r > 0.33 ? 3 : r > 0.1 ? 2 : 1;
  }

  function renderRaster() {
    var raster = $('poRaster');
    var months = $('poMonths');
    raster.textContent = '';
    months.textContent = '';
    if (!data) return;
    var s = data.stats;
    var t = s.tokens || {};
    var since = t.ready && t.since ? t.since : '9999-12-31';
    var list = (s.raster || []).map(function (r) {
      var counted = r.d >= since;
      return {d: r.d, kind: counted ? 't' : 'm', v: counted ? Number(r.t) || 0 : Number(r.m) || 0, m: Number(r.m) || 0, t: Number(r.t) || 0};
    });
    if (!list.length) return;
    // Columns are weeks from Monday; the first column starts with empty cells.
    var first = parseDay(list[0].d);
    var pad = (first.getDay() + 6) % 7;
    var cells = [];
    for (var p = 0; p < pad; p++) cells.push(null);
    list.forEach(function (x) { cells.push(x); });
    var weeks = Math.ceil(cells.length / 7);
    raster.style.setProperty('--po-weeks', weeks);
    months.style.setProperty('--po-weeks', weeks);

    var value = function (x) { return x.v; };
    if (mode === 'week') {
      for (var w = 0; w < weeks; w++) {
        var week = cells.slice(w * 7, w * 7 + 7).filter(Boolean);
        var kind = week.some(function (x) { return x.kind === 't'; }) ? 't' : 'm';
        var sum = week.reduce(function (a, x) { return a + (kind === 't' ? x.t : x.m); }, 0);
        week.forEach(function (x) { x.week = sum; x.weekKind = kind; });
      }
      value = function (x) { return x.week; };
    } else if (mode === 'sum') {
      var run = {t: 0, m: 0};
      list.forEach(function (x) { run[x.kind] += x.v; x.sum = run[x.kind]; });
      value = function (x) { return x.sum; };
    }
    var kindOf = function (x) { return mode === 'week' ? x.weekKind : x.kind; };
    var max = {t: 0, m: 0};
    list.forEach(function (x) { max[kindOf(x)] = Math.max(max[kindOf(x)], value(x) || 0); });

    var lastMonth = -1;
    for (var c = 0; c < cells.length; c++) {
      var x = cells[c];
      var dot = el('span', 'po-dot');
      if (x) {
        var k = kindOf(x);
        dot.className = 'po-dot ' + (k === 't' ? 'tok' : 'msg') + ' l' + level(value(x), max[k]);
        var dt = parseDay(x.d);
        var amount = mode === 'week' ? 'Woche: ' : mode === 'sum' ? 'bis hier: ' : '';
        dot.title = DAYS[dt.getDay()] + ', ' + dateText(x.d) + ': ' + amount
          + (k === 't' ? (value(x) || 0).toLocaleString('de-DE') + ' Tokens' : (value(x) || 0).toLocaleString('de-DE') + ' Nachrichten');
        if (c % 7 === 0 || c === pad) {
          var month = dt.getMonth();
          if (month !== lastMonth && dt.getDate() <= 7) {
            var label = el('span', '', MONTHS[month]);
            label.style.gridColumnStart = String(Math.floor(c / 7) + 1);
            months.appendChild(label);
          }
          lastMonth = month;
        }
      } else {
        dot.className = 'po-dot empty';
      }
      raster.appendChild(dot);
    }
    var legend = $('poLegend');
    legend.textContent = '';
    var add = function (cls, text) {
      var item = el('span', 'po-legend-item');
      item.appendChild(el('i', 'po-dot ' + cls + ' l3'));
      item.appendChild(document.createTextNode(text));
      legend.appendChild(item);
    };
    add('tok', t.ready ? 'Tokens (gezählt seit ' + dateText(t.since) + ')' : 'Tokens (ab Migration 029)');
    add('msg', 'Davor: deine Nachrichten pro Tag');
  }

  function renderMade(s) {
    var made = s.made || {};
    var list = $('poMadeList');
    list.textContent = '';
    var latest = made.latest || [];
    $('poMadeCount').textContent = (made.images || 0) + ' Bilder · ' + (made.files || 0) + ' Dateien';
    $('poMadeEmpty').hidden = latest.length > 0;
    latest.forEach(function (item) {
      var li = el('li', 'po-made-item ' + (item.type === 'image' ? 'image' : 'file'));
      var a = el('a');
      a.href = './app.html' + (item.session ? '?session=' + encodeURIComponent(item.session) : '');
      if (item.type === 'image' && /^[a-f0-9]{32}$/.test(item.uuid || '')) {
        var img = el('img');
        img.src = './api/console_media.php?uuid=' + encodeURIComponent(item.uuid);
        img.alt = 'Bild vom ' + dateText(String(item.at).slice(0, 10));
        img.loading = 'lazy';
        a.appendChild(img);
      } else {
        a.appendChild(icon('i-file'));
        a.appendChild(el('span', 'po-made-name', item.name || 'Datei'));
      }
      a.appendChild(el('small', '', dateText(String(item.at).slice(0, 10))));
      li.appendChild(a);
      list.appendChild(li);
    });
  }

  function renderTools(s) {
    var list = $('poTools');
    list.textContent = '';
    var tools = s.tools || [];
    $('poToolsEmpty').hidden = tools.length > 0;
    tools.forEach(function (tool) {
      var li = el('li', 'po-tool');
      li.setAttribute('data-tool', tool.id);
      li.appendChild(icon(TOOL_ICONS[tool.id] || 'i-spark'));
      li.appendChild(el('strong', '', tool.label));
      li.appendChild(el('small', '', (tool.count || 0).toLocaleString('de-DE') + '×'));
      list.appendChild(li);
    });
  }

  function renderInsights(s) {
    var i = s.insights || {};
    var dl = $('poInsights');
    dl.textContent = '';
    var none = 'noch keine Daten';
    var rows = [
      ['TCI Core Schnell', i.calls ? i.fast_share + ' % der Durchläufe' : none],
      ['Meistgenutzter Core', i.top_core ? i.top_core.label + ' · ' + i.top_core.share + ' %' : none],
      ['Meistgenutzter Denkaufwand', i.top_thinking ? i.top_thinking.label + ' · ' + i.top_thinking.share + ' %' : none],
      ['Entdeckte Skills', String(i.skills_found || 0)],
      ['Insgesamt genutzte Skills', (i.skills_used || 0).toLocaleString('de-DE')],
      ['Nachrichten insgesamt', (s.messages || 0).toLocaleString('de-DE')],
      ['Chats', (s.chats || 0).toLocaleString('de-DE')],
      ['Aktive Tage', String((s.streak || {}).active_days || 0)],
    ];
    rows.forEach(function (r) {
      var row = el('div', 'po-insight');
      row.appendChild(el('dt', '', r[0]));
      row.appendChild(el('dd', '', r[1]));
      dl.appendChild(row);
    });
  }

  function render(result) {
    data = result;
    renderHead(result.profile);
    renderFigures(result.stats || {});
    renderRaster();
    renderMade(result.stats || {});
    renderTools(result.stats || {});
    renderInsights(result.stats || {});
    box.removeAttribute('aria-busy');
  }

  function load() {
    if (loading) return;
    loading = true;
    fetch(API, {credentials: 'include', cache: 'no-store', headers: {'Accept': 'application/json'}}).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (!response.ok || !json.ok) throw new Error(json.error || ('HTTP ' + response.status));
        return json;
      });
    }).then(function (json) {
      loaded = true;
      render(json);
    }).catch(function () {
      $('poTokensNote').textContent = 'Die Profilübersicht konnte nicht geladen werden.';
      box.removeAttribute('aria-busy');
    }).then(function () { loading = false; });
  }

  Array.prototype.forEach.call(document.querySelectorAll('input[name="poRasterMode"]'), function (input) {
    input.addEventListener('change', function () {
      if (!input.checked) return;
      mode = input.value;
      renderRaster();
    });
  });
  $('profileEditJump').addEventListener('click', function () {
    var target = $('profileEditorStart');
    if (target && target.scrollIntoView) target.scrollIntoView({behavior: 'smooth', block: 'start'});
    var name = $('profileDisplayName');
    if (name) window.setTimeout(function () { name.focus({preventScroll: true}); }, 250);
  });
  // A saved name or picture shows up in the head at once (js/settings.js).
  document.addEventListener('coreai:profile', function (event) {
    var p = event && event.detail ? event.detail : null;
    if (!p || !data) return;
    data.profile = Object.assign({}, data.profile, {display_name: p.display_name || data.profile.display_name,
      assistant_name: p.assistant_name || data.profile.assistant_name, avatar: p.avatars ? p.avatars.user : data.profile.avatar});
    renderHead(data.profile);
  });
  // 0.6.86: a Benutzername saved under Konto shows up in the head at once.
  document.addEventListener('coreai:username', function (event) {
    if (!data || !event || !event.detail) return;
    data.profile = Object.assign({}, data.profile, {username: event.detail.username || null});
    renderHead(data.profile);
  });
  document.addEventListener('coreai:view', function (event) {
    var name = event && event.detail ? event.detail.view : '';
    if (name === 'profil' && !loaded) load();
  });
  if (!view.hidden) load();
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (!loaded && tabs && typeof tabs.current === 'function' && tabs.current() === 'profil') load();
})();
