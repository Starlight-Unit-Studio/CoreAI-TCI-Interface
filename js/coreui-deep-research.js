/* CoreAI 0.7.38: Deep Research in the chat, after the operator's reference screenshots.
   Mara's reply "[[deep-research:ID]] ..." becomes a card (js/console-app.js asks card(text)):
     plan     title and steps; Bearbeiten, Abbrechen, Starten with the countdown of the server
     running  step states (todo, active, done), "Ich recherchiere ...", searches, a bar, Stopp
     done     "Recherche abgeschlossen in ... · N Quellenangaben · M Suchen" and the report card
   The report opens in a full view with a table of contents, the download (Markdown with sources)
   and a sheet with the sources (by domain, numbered as the report cites them) and the activity.
   Data: api/deep_research.php. Bearbeiten puts the plan into the input field (js/coreui-composer-menu.js). */
(function () {
  'use strict';
  var RE = /^\s*\[\[deep-research:(\d{1,18})\]\]/;
  var ACTIVE = { planned: 1, editing: 1, queued: 1, running: 1, writing: 1 };
  var runs = {};      // id -> {data, cards: [], timer, deadline, details}
  var csrf = '';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = String(text);
    return node;
  }
  var ICON = {
    deep: '<path d="M4 16l7.5-7.5 2.5 2.5L6.5 18.5z"/><path d="M11.5 8.5l2-2 3.5 3.5-2 2"/><path d="M17 6l2-2M9 20h7M12.5 15.5V20"/>',
    check: '<path d="M7 12.5l3.2 3.2L17 9"/>',
    stop: '<rect x="8" y="8" width="8" height="8"/>',
    download: '<path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19h14"/>',
    expand: '<path d="M14 5h5v5M10 19H5v-5M19 5l-6 6M5 19l6-6"/>',
    close: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
    toc: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
    links: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    doc: '<path d="M7 3.5h7l4 4V20.5H7z"/><path d="M14 3.5v4h4M9.5 12h6M9.5 15.5h6"/>',
    globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.4 3.6 5.2 3.6 8.5s-1 6.1-3.6 8.5c-2.6-2.4-3.6-5.2-3.6-8.5s1-6.1 3.6-8.5z"/>'
  };
  function svg(name, cls) {
    return '<svg class="' + (cls || 'dr-icon') + '" viewBox="0 0 24 24" aria-hidden="true">' + ICON[name] + '</svg>';
  }
  function iconButton(name, label, cls) {
    var b = el('button', cls || 'dr-iconbtn');
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.title = label;
    b.innerHTML = svg(name);
    return b;
  }
  function plural(n, one, many) { return Number(n).toLocaleString('de-DE') + ' ' + (Number(n) === 1 ? one : many); }
  /** The download's file name, as api/deep_research_store.php names it (coreui_dr_file_name). */
  function fileName(title) {
    var map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'Ä': 'Ae', 'Ö': 'Oe', 'Ü': 'Ue', 'ß': 'ss' };
    var slug = String(title || '').replace(/[äöüÄÖÜß]/g, function (c) { return map[c]; })
      .replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase().slice(0, 60);
    return 'deep-research-' + (slug || 'bericht') + '.md';
  }
  function duration(sec) {
    sec = Math.max(0, Math.round(Number(sec) || 0));
    if (sec < 60) return sec + ' Sek.';
    var min = Math.round(sec / 60);
    return min < 60 ? min + ' Min.' : Math.floor(min / 60) + ' Std. ' + (min % 60) + ' Min.';
  }

  // --- Server ---------------------------------------------------------------------------------------
  function request(method, query, body) {
    var opts = { method: method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
    if (method === 'POST') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CoreUI-CSRF'] = csrf;
      opts.body = JSON.stringify(body);
    }
    return fetch('./api/deep_research.php' + (query || ''), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (d && d.csrf_token) csrf = d.csrf_token;
        if (!r.ok && !(d && d.run)) { var e = new Error(d.error || 'http_' + r.status); e.code = d.error || ''; throw e; }
        return d;
      });
    });
  }
  function load(id, details) {
    return request('GET', '?action=status&id=' + id + (details ? '&details=1' : '')).then(function (d) {
      var run = runs[id];
      run.data = d.run;
      if (details) run.details = d.run;
      if (d.run.status === 'planned' && d.run.autostart_in != null) run.deadline = Date.now() + d.run.autostart_in * 1000;
      renderAll(id);
      schedule(id);
      return d.run;
    }).catch(function (err) {
      var run = runs[id];
      run.error = err.code === 'not_found' ? 'Diese Recherche gibt es nicht mehr.' : 'Die Recherche lässt sich gerade nicht laden.';
      renderAll(id);
      if (err.code !== 'not_found') schedule(id, 10000);
    });
  }
  function schedule(id, delay) {
    var run = runs[id];
    clearTimeout(run.timer);
    var status = run.data && run.data.status;
    if (!delay && (!status || !ACTIVE[status])) return;
    run.timer = setTimeout(function () {
      if (!run.cards.some(function (c) { return c.isConnected; })) { run.timer = 0; return; }  // the chat was left
      load(id, false);
    }, delay || (document.hidden ? 10000 : status === 'planned' || status === 'editing' ? 4000 : 2500));
  }
  function act(id, action) {
    var run = runs[id];
    run.busy = true;
    renderAll(id);
    return request('POST', '', { action: action, id: id }).then(function (d) {
      run.data = d.run;
      return d.run;
    }).catch(function () {
      run.notice = 'Das hat nicht geklappt. Bitte versuche es noch einmal.';
    }).then(function (data) {
      run.busy = false;
      renderAll(id);
      schedule(id);
      return data;
    });
  }

  // --- The card in the chat -----------------------------------------------------------------------
  function card(text) {
    var m = RE.exec(String(text || ''));
    if (!m) return null;
    var id = Number(m[1]);
    var node = el('section', 'dr-card');
    node.setAttribute('data-dr-id', String(id));
    node.setAttribute('aria-live', 'polite');
    if (!runs[id]) {
      runs[id] = { cards: [], data: null };
      runs[id].cards.push(node);
      node.appendChild(el('p', 'dr-loading', 'Rechercheplan wird geladen …'));
      load(id, false);
    } else {
      runs[id].cards.push(node);
      render(id, node);
      if (!runs[id].timer) schedule(id);
    }
    return node;
  }
  function renderAll(id) {
    var run = runs[id];
    // A card is kept until it was in the chat and left it (a new answer list, another chat).
    run.cards = run.cards.filter(function (c) { if (c.isConnected) c.drSeen = true; return c.isConnected || !c.drSeen; }).slice(-20);
    run.cards.forEach(function (c) { render(id, c); });
  }
  function stepList(d) {
    var list = el('ol', 'dr-steps');
    d.steps.forEach(function (step, i) {
      var state = d.states[i] || 'todo';
      if (d.status === 'cancelled' || d.status === 'replaced' || d.status === 'error') state = state === 'done' ? 'done' : 'todo';
      var li = el('li', 'dr-step');
      li.setAttribute('data-state', state);
      var mark = el('span', 'dr-step-mark');
      mark.innerHTML = state === 'done' ? svg('check') : '';
      mark.setAttribute('aria-label', state === 'done' ? 'erledigt' : state === 'active' ? 'läuft' : 'offen');
      li.appendChild(mark);
      li.appendChild(el('span', 'dr-step-text', step));
      list.appendChild(li);
    });
    return list;
  }
  function button(label, cls, onClick, disabled) {
    var b = el('button', 'dr-btn ' + (cls || ''), label);
    b.type = 'button';
    b.disabled = !!disabled;
    b.addEventListener('click', onClick);
    return b;
  }
  function render(id, node) {
    var run = runs[id];
    var d = run.data;
    node.innerHTML = '';
    if (!d) {
      node.appendChild(el('p', 'dr-loading', run.error || 'Rechercheplan wird geladen …'));
      return;
    }
    node.setAttribute('data-status', d.status);
    if (d.status === 'done') { renderDone(id, node, d); return; }
    var head = el('header', 'dr-head');
    head.innerHTML = svg('deep', 'dr-head-icon');
    head.appendChild(el('h3', 'dr-title', d.title));
    node.appendChild(head);
    node.appendChild(stepList(d));
    if (run.notice) node.appendChild(el('p', 'dr-notice', run.notice));
    if (d.status === 'planned' || d.status === 'editing') {
      if (d.status === 'editing') node.appendChild(el('p', 'dr-hint', 'Schreib mir unten, was ich am Plan ändern soll. Ich mache dann einen neuen.'));
      var actions = el('div', 'dr-actions');
      actions.appendChild(button('Bearbeiten', '', function () { edit(id); }, run.busy || d.status === 'editing'));
      actions.appendChild(button('Abbrechen', '', function () { act(id, 'cancel'); }, run.busy));
      var start = button('Starten', 'dr-primary', function () { act(id, 'start'); }, run.busy);
      if (d.status === 'planned' && run.deadline) {
        var left = Math.max(0, Math.ceil((run.deadline - Date.now()) / 1000));
        var ring = el('span', 'dr-countdown', String(left));
        ring.setAttribute('aria-label', 'startet in ' + left + ' Sekunden');
        ring.style.setProperty('--dr-left', String(Math.min(1, left / 45)));
        start.appendChild(ring);
        clearTimeout(run.tick);
        run.tick = setTimeout(function () { if (left > 0) renderAll(id); else load(id, false); }, left > 0 ? 1000 : 1500);
      }
      actions.appendChild(start);
      node.appendChild(actions);
      return;
    }
    if (ACTIVE[d.status]) {
      var foot = el('div', 'dr-run');
      var line = d.cancelling ? 'Ich höre auf …' : d.status === 'queued' ? 'Ich warte, bis mein Core frei ist …'
        : d.status === 'writing' ? 'Ich schreibe den Bericht …' : 'Ich recherchiere …';
      foot.appendChild(el('p', 'dr-run-line', line));
      if (d.searches) foot.appendChild(el('p', 'dr-run-count', plural(d.searches, 'Suchvorgang', 'Suchvorgänge')));
      var bar = el('div', 'dr-bar');
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.setAttribute('aria-valuenow', String(d.percent || 0));
      var fill = el('i');
      fill.style.width = Math.max(2, d.percent || 0) + '%';
      bar.appendChild(fill);
      var stop = iconButton('stop', 'Recherche stoppen', 'dr-iconbtn dr-stop');
      stop.disabled = run.busy || d.cancelling;
      stop.addEventListener('click', function () { act(id, 'cancel'); });
      var row = el('div', 'dr-bar-row');
      row.appendChild(bar);
      row.appendChild(stop);
      foot.appendChild(row);
      node.appendChild(foot);
      return;
    }
    var ending = d.status === 'cancelled' ? 'Recherche abgebrochen.'
      : d.status === 'replaced' ? 'Diesen Plan habe ich durch einen neuen ersetzt.'
      : d.error === 'worker_lost' ? 'Die Recherche ist nicht fertig geworden: Mein Hintergrunddienst wurde unterbrochen.'
      : d.error === 'research_search_backend_unavailable'
        ? 'Die Recherche konnte nicht durchgeführt werden: Der Suchdienst ist nicht erreichbar. Ich habe keine Quellen erfunden.'
      : d.error === 'research_no_search_results'
        ? 'Die Suchdienste haben keine Treffer geliefert. Bitte prüfe SearXNG und versuche die Recherche erneut.'
      : d.error === 'research_no_verifiable_sources'
        ? 'Ich habe gesucht, konnte aber keine passenden Webseiten vollständig lesen und überprüfen. Die Recherche wurde nicht als erfolgreich gewertet.'
      : d.error === 'research_no_verified_claims'
        ? 'Die gefundenen Seiten reichen nicht aus, um die Aussagen im Bericht verlässlich zu belegen.'
      : 'Die Recherche ist nicht fertig geworden.';
    node.appendChild(el('p', 'dr-ended', ending));
  }
  function renderDone(id, node, d) {
    node.appendChild(el('p', 'dr-summary', (Number(d.citations || 0) ? 'Recherche abgeschlossen in ' : 'Nicht belegbare Recherche aus ') + duration(d.elapsed) + ' · '
      + plural(d.citations, 'Quellenangabe', 'Quellenangaben') + ' · ' + plural(d.searches, 'Suche', 'Suchen')));
    var report = el('article', 'dr-report-card');
    var bar = el('header', 'dr-report-head');
    var badge = el('span', 'dr-report-badge');
    badge.innerHTML = svg('doc');
    bar.appendChild(badge);
    bar.appendChild(el('strong', 'dr-report-title', d.title));
    var dl = el('a', 'dr-iconbtn');
    dl.href = './api/deep_research.php?action=download&id=' + id;
    dl.setAttribute('download', fileName(d.title));
    dl.setAttribute('aria-label', 'Bericht herunterladen');
    dl.title = 'Bericht herunterladen';
    dl.innerHTML = svg('download');
    bar.appendChild(dl);
    var open = iconButton('expand', 'Bericht öffnen');
    open.addEventListener('click', function () { openViewer(id); });
    bar.appendChild(open);
    report.appendChild(bar);
    var preview = el('div', 'dr-preview msg-markdown');
    preview.setAttribute('role', 'button');
    preview.tabIndex = 0;
    preview.setAttribute('aria-label', 'Bericht öffnen');
    preview.addEventListener('click', function () { openViewer(id); });
    preview.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openViewer(id); } });
    report.appendChild(preview);
    node.appendChild(report);
    var run = runs[id];
    if (run.details && run.details.report) fillPreview(preview, run.details.report);
    else if (!run.loadingDetails) {
      run.loadingDetails = true;
      load(id, true).then(function () { run.loadingDetails = false; });
    }
  }
  function fillPreview(node, report) {
    renderMarkdown(node, String(report).slice(0, 1400));
  }

  // --- Markdown with citations ----------------------------------------------------------------------
  function renderMarkdown(node, text) {
    if (window.CoreUIMarkdown && typeof window.CoreUIMarkdown.render === 'function') window.CoreUIMarkdown.render(node, text);
    else node.textContent = text;
  }
  function citations(root, onCite) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while (walker.nextNode()) if (/\[\d{1,3}\]/.test(walker.currentNode.nodeValue) && !walker.currentNode.parentNode.closest('code,pre,a')) nodes.push(walker.currentNode);
    nodes.forEach(function (t) {
      var frag = document.createDocumentFragment();
      String(t.nodeValue).split(/(\[\d{1,3}\])/).forEach(function (part) {
        var m = /^\[(\d{1,3})\]$/.exec(part);
        if (!m) { if (part) frag.appendChild(document.createTextNode(part)); return; }
        var b = el('button', 'dr-cite', m[1]);
        b.type = 'button';
        b.setAttribute('aria-label', 'Quelle ' + m[1]);
        b.addEventListener('click', function () { onCite(Number(m[1])); });
        frag.appendChild(b);
      });
      t.parentNode.replaceChild(frag, t);
    });
  }

  // --- The full view -------------------------------------------------------------------------------
  var viewer = null;
  function openViewer(id) {
    var run = runs[id];
    if (!run.details) { load(id, true).then(function () { if (runs[id].details) openViewer(id); }); return; }
    closeViewer();
    var d = run.details;
    var lastFocus = document.activeElement;
    var box = el('div', 'dr-viewer');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Deep Research: ' + d.title);
    var top = el('header', 'dr-viewer-top');
    var pill = el('span', 'dr-pill');
    pill.innerHTML = svg('deep') + '<span>Deep Research</span>';
    top.appendChild(pill);
    var close = iconButton('close', 'Bericht schließen', 'dr-iconbtn dr-close');
    close.addEventListener('click', closeViewer);
    top.appendChild(close);
    box.appendChild(top);
    var body = el('div', 'dr-viewer-body');
    var article = el('article', 'dr-article msg-markdown');
    renderMarkdown(article, d.report);
    citations(article, function (n) { openSheet('sources', n); });
    body.appendChild(article);
    box.appendChild(body);
    var tools = el('nav', 'dr-tools');
    tools.setAttribute('aria-label', 'Bericht');
    var tocBtn = iconButton('toc', 'Inhaltsverzeichnis');
    tocBtn.addEventListener('click', function () { openSheet('toc'); });
    var dl = el('a', 'dr-iconbtn');
    dl.href = './api/deep_research.php?action=download&id=' + id;
    dl.setAttribute('download', fileName(d.title));
    dl.setAttribute('aria-label', 'Bericht herunterladen');
    dl.title = 'Bericht herunterladen';
    dl.innerHTML = svg('download');
    var srcBtn = iconButton('links', 'Quellen und Aktivität');
    srcBtn.addEventListener('click', function () { openSheet('sources'); });
    tools.appendChild(tocBtn);
    tools.appendChild(dl);
    tools.appendChild(srcBtn);
    box.appendChild(tools);
    document.body.appendChild(box);
    document.body.classList.add('dr-viewer-open');
    viewer = { box: box, body: body, article: article, data: d, lastFocus: lastFocus, sheet: null };
    close.focus();
  }
  function closeViewer() {
    if (!viewer) return;
    var last = viewer.lastFocus;
    viewer.box.remove();
    document.body.classList.remove('dr-viewer-open');
    viewer = null;
    if (last && document.contains(last)) last.focus();
  }
  function closeSheet() {
    if (!viewer || !viewer.sheet) return;
    var opener = viewer.sheet.opener;
    viewer.sheet.node.remove();
    viewer.sheet = null;
    if (opener && document.contains(opener)) opener.focus();
  }
  function openSheet(kind, focusSource) {
    if (!viewer) return;
    closeSheet();
    var d = viewer.data;
    var shade = el('div', 'dr-sheet-shade');
    var sheet = el('section', 'dr-sheet');
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    var head = el('header', 'dr-sheet-head');
    var close = iconButton('close', 'Schließen', 'dr-iconbtn dr-close');
    close.addEventListener('click', closeSheet);
    var content = el('div', 'dr-sheet-body');
    if (kind === 'toc') {
      sheet.setAttribute('aria-label', 'Inhaltsverzeichnis');
      head.appendChild(el('h3', 'dr-sheet-title', 'Inhaltsverzeichnis'));
      var list = el('ol', 'dr-toc');
      Array.prototype.forEach.call(viewer.article.querySelectorAll('h1, h2, h3'), function (h) {
        var li = el('li', 'dr-toc-' + h.tagName.toLowerCase());
        var b = el('button', 'dr-toc-link', h.textContent);
        b.type = 'button';
        b.addEventListener('click', function () { closeSheet(); h.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
        li.appendChild(b);
        list.appendChild(li);
      });
      content.appendChild(list);
    } else {
      sheet.setAttribute('aria-label', 'Quellen und Aktivität');
      var tabs = el('div', 'dr-tabs');
      tabs.setAttribute('role', 'tablist');
      var tSrc = el('button', 'dr-tab', 'Quellen');
      var tAct = el('button', 'dr-tab', 'Aktivität · ' + duration(d.elapsed));
      [tSrc, tAct].forEach(function (t) { t.type = 'button'; t.setAttribute('role', 'tab'); tabs.appendChild(t); });
      head.appendChild(tabs);
      var show = function (which) {
        tSrc.setAttribute('aria-selected', which === 'src' ? 'true' : 'false');
        tAct.setAttribute('aria-selected', which === 'act' ? 'true' : 'false');
        content.innerHTML = '';
        content.appendChild(which === 'src' ? sourcesList(d, focusSource) : activityList(d));
      };
      tSrc.addEventListener('click', function () { show('src'); });
      tAct.addEventListener('click', function () { show('act'); });
      show('src');
    }
    head.appendChild(close);
    sheet.appendChild(head);
    sheet.appendChild(content);
    shade.appendChild(sheet);
    shade.addEventListener('click', function (e) { if (e.target === shade) closeSheet(); });
    viewer.box.appendChild(shade);
    viewer.sheet = { node: shade, opener: document.activeElement };
    var target = focusSource ? content.querySelector('[data-n="' + focusSource + '"]') : null;
    if (target) { target.scrollIntoView({ block: 'center' }); target.classList.add('is-focus'); target.querySelector('a').focus(); }
    else close.focus();
  }
  function sourcesList(d, focusSource) {
    var wrap = el('div', 'dr-sources');
    var cited = d.sources.filter(function (s) { return s.cited; });
    var shown = cited.length ? cited : d.sources;
    wrap.appendChild(el('p', 'dr-sheet-sub', 'Quellenangaben · ' + shown.length));
    var groups = {};
    var order = [];
    shown.forEach(function (s) { if (!groups[s.domain]) { groups[s.domain] = []; order.push(s.domain); } groups[s.domain].push(s); });
    order.forEach(function (domain) {
      var g = el('div', 'dr-src-group');
      var dh = el('p', 'dr-src-domain');
      dh.innerHTML = svg('globe');
      dh.appendChild(el('span', '', domain));
      g.appendChild(dh);
      groups[domain].forEach(function (s) {
        var item = el('div', 'dr-src');
        item.setAttribute('data-n', String(s.n));
        item.appendChild(el('span', 'dr-src-n', s.n));
        var a = el('a', 'dr-src-link');
        a.href = s.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.appendChild(el('strong', '', s.title || s.url));
        a.appendChild(el('small', '', s.url));
        item.appendChild(a);
        g.appendChild(item);
      });
      wrap.appendChild(g);
    });
    if (!shown.length) wrap.appendChild(el('p', 'dr-empty', 'Keine Quellen.'));
    return wrap;
  }
  function activityList(d) {
    var wrap = el('div', 'dr-activity');
    wrap.appendChild(el('p', 'dr-sheet-sub', 'Rechercheaktivität'));
    d.events.forEach(function (ev) {
      var item = el('div', 'dr-ev dr-ev-' + ev.kind);
      if (ev.kind === 'search') {
        var h = el('p', 'dr-ev-head');
        h.innerHTML = svg('globe');
        h.appendChild(el('span', '', 'Suche: ' + ev.text));
        item.appendChild(h);
        var chips = el('div', 'dr-chips');
        ev.domains.slice(0, 4).forEach(function (dm) { chips.appendChild(el('span', 'dr-chip', dm)); });
        if (ev.domains.length > 4) chips.appendChild(el('span', 'dr-chip dr-chip-more', (ev.domains.length - 4) + ' mehr'));
        if (ev.domains.length) item.appendChild(chips);
      } else if (ev.kind === 'read') {
        item.appendChild(el('p', 'dr-ev-text', 'Gelesen: ' + ev.text + (ev.domains[0] ? ' (' + ev.domains[0] + ')' : '')));
      } else {
        item.appendChild(el(ev.kind === 'step' ? 'p' : 'p', ev.kind === 'step' ? 'dr-ev-step' : 'dr-ev-text', ev.text));
      }
      wrap.appendChild(item);
    });
    if (!d.events.length) wrap.appendChild(el('p', 'dr-empty', 'Keine Aktivität.'));
    return wrap;
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !viewer) return;
    e.preventDefault();
    if (viewer.sheet) closeSheet(); else closeViewer();
  });

  // --- Bearbeiten: the plan goes into the input field -----------------------------------------------
  function edit(id) {
    act(id, 'edit').then(function (data) {
      if (!data || data.status !== 'editing') return;
      if (window.CoreAIComposerMenu && typeof window.CoreAIComposerMenu.editDeep === 'function') {
        window.CoreAIComposerMenu.editDeep(id, data.title);
      }
    });
  }

  // When the details arrive, every report card of the run gets its preview.
  var baseRenderAll = renderAll;
  renderAll = function (id) {
    baseRenderAll(id);
    var run = runs[id];
    if (run && run.details && run.details.report) {
      run.cards.forEach(function (c) {
        var p = c.querySelector('.dr-preview');
        if (p && !p.childNodes.length) fillPreview(p, run.details.report);
      });
    }
  };

  window.CoreAIDeepResearch = {
    /** The run of Mara's reply, 0 without the mark (pure). */
    match: function (text) { var m = RE.exec(String(text || '')); return m ? Number(m[1]) : 0; },
    card: card,
    open: openViewer
  };
})();
