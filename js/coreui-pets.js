/* CoreAI pets: legacy horizontal strips and stateful 9/11-row sprite atlases.
   One timer animates all sprites. Chat activity is independent of message DOM. */
(function () {
  'use strict';

  var LEGACY_FRAME_MS = 140;
  var TICK_MS = 45;
  var timer = null;
  var transientTimer = null;
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  var still = !!(reducedMotion && reducedMotion.matches);
  var activity = 'idle';

  // Shared with the 8-column hatch-pet atlas contract, 192 x 208 per cell.
  // Durations belong to the frame being displayed, including the final pause.
  var STATES = {
    idle:            {row: 0, ms: [280, 110, 110, 140, 140, 320]},
    'running-right': {row: 1, ms: [120, 120, 120, 120, 120, 120, 120, 220]},
    'running-left':  {row: 2, ms: [120, 120, 120, 120, 120, 120, 120, 220]},
    waving:          {row: 3, ms: [140, 140, 140, 280]},
    jumping:         {row: 4, ms: [140, 140, 140, 140, 280]},
    failed:          {row: 5, ms: [140, 140, 140, 140, 140, 140, 140, 240]},
    waiting:         {row: 6, ms: [150, 150, 150, 150, 150, 260]},
    running:         {row: 7, ms: [120, 120, 120, 120, 120, 220]},
    review:          {row: 8, ms: [150, 150, 150, 150, 150, 280]}
  };

  function version(node) {
    var v = Number(node.getAttribute('data-sprite-version')) || 0;
    return v === 1 || v === 2 ? v : 0;
  }
  function stateOf(node) {
    var name = node.getAttribute('data-state') || 'idle';
    return STATES[name] ? name : 'idle';
  }
  function durations(node) {
    if (version(node)) return STATES[stateOf(node)].ms;
    var n = Math.max(1, Math.min(24, Number(node.getAttribute('data-frames')) || 1));
    return Array(n).fill(LEGACY_FRAME_MS);
  }
  function show(node, frame) {
    node.setAttribute('data-frame', String(frame));
    var v = version(node);
    if (v) {
      var row = STATES[stateOf(node)].row;
      node.style.backgroundPosition = ((frame / 7) * 100) + '% ' + ((row / ((v === 2 ? 11 : 9) - 1)) * 100) + '%';
    } else {
      var frames = Math.max(1, Math.min(24, Number(node.getAttribute('data-frames')) || 1));
      node.style.backgroundPosition = (frames > 1 ? (frame / (frames - 1)) * 100 : 0) + '% 0';
    }
  }
  function step() {
    if (document.hidden || still) return;
    var nodes = document.querySelectorAll('.coreai-pet[data-frames]');
    if (!nodes.length) {
      window.clearInterval(timer);
      timer = null;
      return;
    }
    var now = Date.now();
    Array.prototype.forEach.call(nodes, function (node) {
      if (now < (Number(node.getAttribute('data-next-frame')) || 0)) return;
      var ms = durations(node);
      var next = ((Number(node.getAttribute('data-frame')) || 0) + 1) % ms.length;
      show(node, next);
      node.setAttribute('data-next-frame', String(now + ms[next]));
    });
  }
  function start() {
    if (!timer && !still) timer = window.setInterval(step, TICK_MS);
  }
  function setState(node, desired) {
    if (!node) return;
    var state = STATES[desired] ? desired : 'idle';
    if (node.getAttribute('data-state') === state) return;
    node.setAttribute('data-state', state);
    show(node, 0);
    node.setAttribute('data-next-frame', String(Date.now() + durations(node)[0]));
    start();
  }

  /** Build one sprite. Legacy: a strip of square cells. V1/V2: 192x208 atlas cells. */
  function make(pet, size, className) {
    if (!pet || !pet.url) return null;
    var v = Number(pet.sprite_version) || 0;
    if (v !== 1 && v !== 2) v = 0;
    var frames = v ? (v === 2 ? 73 : 57) : Math.max(1, Math.min(24, Number(pet.frames) || 1));
    size = Math.max(16, Math.min(256, Number(size) || 32));
    var node = document.createElement('span');
    node.className = 'coreai-pet' + (className ? ' ' + className : '');
    node.setAttribute('aria-hidden', 'true');
    node.setAttribute('data-frames', String(frames));
    node.setAttribute('data-sprite-version', String(v));
    node.setAttribute('data-state', 'idle');
    node.setAttribute('data-pet', String(pet.id || ''));
    node.style.width = size + 'px';
    node.style.height = Math.round(size * (v ? 208 / 192 : 1)) + 'px';
    node.style.backgroundImage = 'url("' + String(pet.url).replace(/"/g, '%22') + '")';
    node.style.backgroundSize = v ? '800% ' + (v === 2 ? 1100 : 900) + '%' : (frames * 100) + '% 100%';
    if (!v && (Number(pet.frame_px) || 32) <= 64) node.classList.add('is-pixel');
    show(node, 0);
    node.setAttribute('data-next-frame', String(Date.now() + durations(node)[0]));
    start();
    return node;
  }
  function setActivity(next) {
    next = STATES[next] ? next : 'idle';
    if (activity === next) return;
    activity = next;
    if (transientTimer) window.clearTimeout(transientTimer);
    transientTimer = null;
    document.dispatchEvent(new CustomEvent('coreai:pet-activity', {detail: {state: activity}}));
    if (next === 'review' || next === 'failed') {
      transientTimer = window.setTimeout(function () {
        if (activity === next) setActivity('idle');
      }, next === 'review' ? 1500 : 1800);
    }
  }
  function playTemporary(node, state, ms) {
    if (!node) return;
    setState(node, state);
    window.setTimeout(function () {
      if (node.isConnected && node.getAttribute('data-state') === state) setState(node, activity);
    }, Math.max(300, Math.min(3000, Number(ms) || 1000)));
  }
  if (reducedMotion && typeof reducedMotion.addEventListener === 'function') {
    reducedMotion.addEventListener('change', function (event) {
      still = event.matches;
      if (still && timer) { window.clearInterval(timer); timer = null; }
      if (still) Array.prototype.forEach.call(document.querySelectorAll('.coreai-pet[data-frames]'), function (node) {
        show(node, 0);
      });
      else start();
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      Array.prototype.forEach.call(document.querySelectorAll('.coreai-pet[data-frames]'), function (node) {
        node.setAttribute('data-next-frame', String(Date.now() + durations(node)[0]));
      });
      start();
    }
  });

  var api = {
    current: null,
    make: make,
    setState: setState,
    setActivity: setActivity,
    getActivity: function () { return activity; },
    playTemporary: playTemporary,
    loadCurrent: function () {
      return fetch('./api/pets.php?action=current', {credentials: 'include', cache: 'no-store', headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          api.current = d && d.ok && d.pet ? d.pet : null;
          document.dispatchEvent(new CustomEvent('coreai:pet', {detail: {pet: api.current}}));
          return api.current;
        })
        .catch(function () { return api.current; });
    }
  };
  window.CoreAIPets = api;
})();
