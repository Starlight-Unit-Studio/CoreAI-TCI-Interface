/* CoreAI: TCI Interface 0.6.41 (P20.5): the image while Mara paints it.
   A square card in her live answer: a field of dots with a wandering glow in CoreAI blue,
   the stage above it ("Erste Skizze entsteht", "Details werden verfeinert", ...) and the
   percent in the corner. The percent follows the real work of the image service (loading,
   steps 1 to 4, decoding; events from api/image_gen.php via the work stream): between two
   events it moves on smoothly, but never past the end of the stage that was really reached.
   When the image is done it fades in inside the card while Mara writes her answer; the saved
   answer then shows it as usual. Without motion (reduced motion) the dots stand still. */
(function () {
  'use strict';

  var STAGE_TEXT = {
    start: 'Bild wird vorbereitet',
    load: 'Bild wird vorbereitet',
    text: 'Beschreibung wird gelesen',
    decode: 'Fast fertig',
    done: 'Fertig',
    failed: 'Kein Bild entstanden'
  };
  var STEP_TEXT = ['Erste Skizze entsteht', 'Die Szene nimmt Gestalt an', 'Details werden verfeinert', 'Letzter Feinschliff'];
  // Seconds per stage on the operator's server (07.11.2026, 20 threads), by image width.
  var EXPECT = {
    512: { start: 2, load: 3, text: 5, step: 13.5, decode: 6 },
    1024: { start: 2, load: 3, text: 6, step: 46.5, decode: 15 }
  };
  var GRID = 25;
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  function expectFor(px) { return px >= 1000 ? EXPECT[1024] : EXPECT[512]; }

  /** What an event of the work stream says about the image, or null (pure). */
  function read(event) {
    if (!event || typeof event !== 'object') return null;
    var title = String(event.title || '');
    var meta = event.meta && typeof event.meta === 'object' ? event.meta : {};
    if (/^Schritt [0-9]+ von [0-9]+: Bild$/.test(title) && event.state === 'running') return { stage: 'start' };
    if (title === 'Bild erzeugt') {
      return { stage: 'done', uuid: /^[a-f0-9]{32}$/.test(String(meta.image_uuid || '')) ? String(meta.image_uuid) : '',
        px: Number(meta.image_px) || 0 };
    }
    if (title === 'Bild nicht erzeugt') return { stage: 'failed' };
    if (meta.source !== 'image') return null;
    var reason = String(meta.reason || '');
    var phase = reason.indexOf('image_') === 0 ? reason.slice(6) : '';
    if (['load', 'text', 'sampling', 'decode', 'done'].indexOf(phase) < 0) return null;
    return { stage: phase === 'done' ? 'decode' : phase, step: Math.max(0, Number(meta.step) || 0),
      steps: Math.max(1, Number(meta.steps) || 4), px: Number(meta.image_px) || 0 };
  }

  /** Stage text and the percent range [low, high] of a state (pure). */
  function band(s) {
    if (s.stage === 'start') return { text: STAGE_TEXT.start, low: 0, high: 4 };
    if (s.stage === 'load') return { text: STAGE_TEXT.load, low: 4, high: 9 };
    if (s.stage === 'text') return { text: STAGE_TEXT.text, low: 9, high: 15 };
    if (s.stage === 'sampling') {
      var steps = s.steps || 4;
      var done = Math.min(steps, s.step || 0);
      var index = Math.min(steps - 1, done);
      var text = steps === 4 ? STEP_TEXT[index] : STEP_TEXT[Math.min(3, Math.floor(index * 4 / steps))];
      return { text: text, low: 15 + 75 * done / steps, high: 15 + 75 * Math.min(steps, done + 1) / steps };
    }
    if (s.stage === 'decode') return { text: STAGE_TEXT.decode, low: 90, high: 98 };
    if (s.stage === 'done') return { text: STAGE_TEXT.done, low: 100, high: 100 };
    return { text: STAGE_TEXT.failed, low: 0, high: 0 };
  }

  /** The percent shown after `seconds` in a stage (pure): eases toward the stage's end, never reaches past it. */
  function percent(s, seconds) {
    var b = band(s);
    if (s.stage === 'done') return 100;
    var expect = expectFor(s.px || 512);
    var duration = s.stage === 'sampling' ? expect.step : (expect[s.stage] || 4);
    var span = Math.max(0, b.high - b.low - 1);
    return b.low + span * (1 - Math.exp(-Math.max(0, seconds) * 2.2 / duration));
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function color(node) {
    var value = '';
    try { value = getComputedStyle(node).getPropertyValue('--cx').trim(); } catch (e) {}
    var m = /^#([0-9a-f]{6})$/i.exec(value);
    if (!m) return [91, 146, 255];
    return [parseInt(m[1].slice(0, 2), 16), parseInt(m[1].slice(2, 4), 16), parseInt(m[1].slice(4, 6), 16)];
  }

  function create(row) {
    var content = row.querySelector('.msg-content') || row;
    var card = el('div', 'img-progress');
    card.setAttribute('role', 'status');
    card.setAttribute('aria-live', 'polite');
    var stage = el('div', 'img-progress-stage', STAGE_TEXT.start);
    var frame = el('div', 'img-progress-frame');
    var canvas = el('canvas', 'img-progress-dots');
    canvas.setAttribute('aria-hidden', 'true');
    var pct = el('span', 'img-progress-pct', '0 %');
    frame.appendChild(canvas);
    frame.appendChild(pct);
    card.appendChild(stage);
    card.appendChild(frame);
    var before = content.querySelector('.agent-stop-button') || content.querySelector('.msg-bubble');
    content.insertBefore(card, before || null);
    card._p = { state: { stage: 'start' }, since: performance.now(), shown: 0, stage: stage, pct: pct, canvas: canvas,
      frame: frame, rgb: color(card), raf: 0, t0: performance.now() };
    loop(card);
    return card;
  }

  function draw(card, now) {
    var p = card._p;
    var canvas = p.canvas;
    var size = Math.max(60, Math.round(p.frame.clientWidth || 300));
    var ratio = Math.min(2, window.devicePixelRatio || 1);
    if (canvas.width !== size * ratio) { canvas.width = size * ratio; canvas.height = size * ratio; }
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, size, size);
    var t = reduced ? 0 : (now - p.t0) / 1000;
    var level = p.shown / 100;
    var c1x = 0.5 + 0.3 * Math.sin(t * 0.41), c1y = 0.5 + 0.28 * Math.cos(t * 0.33);
    var c2x = 0.5 + 0.32 * Math.cos(t * 0.27 + 1.7), c2y = 0.5 + 0.3 * Math.sin(t * 0.37 + 0.6);
    var gap = size / GRID;
    var rgb = p.rgb;
    for (var gy = 0; gy < GRID; gy++) {
      for (var gx = 0; gx < GRID; gx++) {
        var u = (gx + 0.5) / GRID, v = (gy + 0.5) / GRID;
        var d1 = (u - c1x) * (u - c1x) + (v - c1y) * (v - c1y);
        var d2 = (u - c2x) * (u - c2x) + (v - c2y) * (v - c2y);
        var glow = Math.exp(-d1 / 0.045) + 0.75 * Math.exp(-d2 / 0.03);
        var edge = Math.min(u, v, 1 - u, 1 - v);
        var fade = Math.min(1, edge * 6);
        var i = Math.min(1, (0.08 + 0.3 * level + 0.85 * glow) * fade);
        if (i < 0.03) continue;
        ctx.fillStyle = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + (0.18 + 0.82 * i).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc((gx + 0.5) * gap, (gy + 0.5) * gap, gap * (0.07 + 0.2 * i), 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function tick(card, now) {
    var p = card._p;
    var target = percent(p.state, (now - p.since) / 1000);
    if (p.state.stage !== 'failed') p.shown = Math.max(p.shown, target);
    var shownText = Math.floor(p.shown) + ' %';
    if (p.pct.textContent !== shownText) p.pct.textContent = shownText;
    var label = band(p.state).text;
    if (p.stage.textContent !== label) p.stage.textContent = label;
    draw(card, now);
  }

  function loop(card) {
    var p = card._p;
    function frame(now) {
      if (!card.isConnected) { p.raf = 0; return; }
      tick(card, now);
      var still = reduced || p.state.stage === 'failed' || card.classList.contains('is-revealed');
      p.raf = still ? 0 : requestAnimationFrame(frame);
    }
    p.raf = requestAnimationFrame(frame);
  }

  function reveal(card, uuid) {
    if (!uuid || card.querySelector('.img-progress-picture')) return;
    var img = el('img', 'img-progress-picture');
    img.alt = 'Bild von TCI: Mara';
    img.decoding = 'async';
    img.addEventListener('load', function () { card.classList.add('is-revealed'); });
    img.src = './api/console_media.php?uuid=' + encodeURIComponent(uuid);
    card._p.frame.insertBefore(img, card._p.pct);
  }

  /** One event of the live work stream for the typing row `row`. */
  function observe(row, event) {
    if (!row || !row.isConnected) return null;
    var next = read(event);
    if (!next) return null;
    var card = row.querySelector('.img-progress');
    if (!card) {
      if (next.stage === 'failed') return null;
      card = create(row);
    }
    var p = card._p;
    // A late, older event (it arrives after a newer one) changes nothing: neither text nor percent go back.
    if (next.stage !== 'done' && next.stage !== 'failed' && p.state.stage !== 'failed'
        && (p.state.stage === 'done' || band(next).low < band(p.state).low)) return card;
    var px = next.px || p.state.px || 0;
    var changed = next.stage !== p.state.stage || (next.step || 0) !== (p.state.step || 0);
    if (changed) {
      p.state = { stage: next.stage, step: next.step || 0, steps: next.steps || p.state.steps || 4, px: px };
      p.since = performance.now();
    }
    card.classList.toggle('is-done', next.stage === 'done');
    card.classList.toggle('is-failed', next.stage === 'failed');
    if (next.stage === 'done') reveal(card, next.uuid);
    if (!p.raf && !card.classList.contains('is-revealed')) loop(card);
    tick(card, performance.now());
    return card;
  }

  /** All events so far (a rebuilt typing row). */
  function replay(row, events) {
    var card = null;
    (Array.isArray(events) ? events : []).forEach(function (event) { card = observe(row, event) || card; });
    return card;
  }

  window.CoreAIImageProgress = { observe: observe, replay: replay, _read: read, _band: band, _percent: percent };
})();
