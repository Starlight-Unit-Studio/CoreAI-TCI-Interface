/* CoreAI chat pet dock. The pet belongs to the chat viewport, not a transient message. */
(function () {
  'use strict';

  var area = document.getElementById('messagesArea');
  var pets = window.CoreAIPets;
  if (!area || !pets || typeof pets.make !== 'function') return;

  var KEY = 'coreai.pet.dock.position.v1';
  var positionValue = {x: 0.02, y: 0.88};
  try {
    var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
      positionValue.x = clamp(saved.x);
      positionValue.y = clamp(saved.y);
    }
  } catch (ignored) {}

  function clamp(value) { return Math.max(0, Math.min(1, Number(value) || 0)); }
  function isCompact() { return window.innerWidth <= 600; }
  function spriteSize() { return isCompact() ? 48 : 64; }
  function savePosition() {
    try { localStorage.setItem(KEY, JSON.stringify(positionValue)); } catch (ignored) {}
  }

  var dock = document.createElement('button');
  dock.type = 'button';
  dock.className = 'coreai-pet-dock';
  dock.hidden = true;
  dock.title = 'Pet verschieben oder antippen';
  dock.setAttribute('aria-label', 'Pet verschieben oder antippen');
  document.body.appendChild(dock);

  var sprite = null;
  var gesture = null;
  var lastTapAt = 0;
  var lastTapX = 0;
  var lastTapY = 0;
  var touchLookTimer = null;

  function clearTouchLook() {
    if (touchLookTimer) window.clearTimeout(touchLookTimer);
    touchLookTimer = null;
    if (sprite && pets.clearLook) pets.clearLook(sprite);
  }
  function greet(clientX, clientY) {
    if (!sprite || !pets.playTemporary) return;
    var now = Date.now();
    var repeatTap = now - lastTapAt < 480
      && Math.hypot(clientX - lastTapX, clientY - lastTapY) < 36;
    lastTapAt = now;
    lastTapX = clientX;
    lastTapY = clientY;
    pets.playTemporary(sprite, repeatTap ? 'jumping' : 'waving', repeatTap ? 1100 : 1100);
  }

  function areaLimits() {
    var rect = area.getBoundingClientRect();
    var width = dock.offsetWidth || (spriteSize() + 8);
    var height = dock.offsetHeight || (spriteSize() * 208 / 192 + 8);
    var margin = 8;
    var x0 = rect.left + margin;
    var y0 = rect.top + margin;
    return {
      x0: x0, y0: y0,
      dx: Math.max(0, rect.width - width - margin * 2),
      dy: Math.max(0, rect.height - height - margin * 2)
    };
  }
  function layout() {
    if (dock.hidden) return;
    var limit = areaLimits();
    dock.style.left = Math.round(limit.x0 + positionValue.x * limit.dx) + 'px';
    dock.style.top = Math.round(limit.y0 + positionValue.y * limit.dy) + 'px';
  }
  function at(clientX, clientY) {
    var limit = areaLimits();
    positionValue.x = limit.dx ? clamp((clientX - limit.x0) / limit.dx) : 0;
    positionValue.y = limit.dy ? clamp((clientY - limit.y0) / limit.dy) : 0;
    layout();
  }
  function label() {
    var name = pets.current && pets.current.name ? pets.current.name : 'Pet';
    var state = pets.getActivity ? pets.getActivity() : 'idle';
    var descriptions = {idle:'ruht', running:'arbeitet', waiting:'wartet auf Eingabe',
      review:'prüft das Ergebnis', failed:'Fehler', waving:'winkt'};
    dock.setAttribute('aria-label', name + ': ' + (descriptions[state] || state) + '. Antippen oder verschieben.');
  }
  function render() {
    var pet = pets.current;
    if (!pet) {
      dock.hidden = true;
      dock.textContent = '';
      sprite = null;
      clearTouchLook();
      return;
    }
    var size = spriteSize();
    if (!sprite || sprite.getAttribute('data-pet') !== String(pet.id)
        || sprite.getAttribute('data-size') !== String(size)) {
      dock.textContent = '';
      sprite = pets.make(pet, size, 'coreai-dock-sprite');
      if (!sprite) { dock.hidden = true; return; }
      sprite.setAttribute('data-size', String(size));
      dock.appendChild(sprite);
    }
    dock.hidden = false;
    if (pets.setState) pets.setState(sprite, pets.getActivity ? pets.getActivity() : 'idle');
    label();
    layout();
  }

  dock.addEventListener('pointerdown', function (event) {
    if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return;
    gesture = {
      id: event.pointerId,
      startX: event.clientX, startY: event.clientY,
      originLeft: dock.getBoundingClientRect().left,
      originTop: dock.getBoundingClientRect().top,
      moved: false
    };
    dock.setPointerCapture(event.pointerId);
    // On a phone a drag and a tap share the same pointer gesture.
    event.preventDefault();
  });
  dock.addEventListener('pointermove', function (event) {
    if (!gesture || gesture.id !== event.pointerId) return;
    var dx = event.clientX - gesture.startX;
    var dy = event.clientY - gesture.startY;
    if (Math.hypot(dx, dy) > 7) gesture.moved = true;
    if (!gesture.moved) {
      if (sprite && pets.lookAt && event.pointerType !== 'mouse') {
        pets.lookAt(sprite, event.clientX, event.clientY);
      }
      return;
    }
    if (sprite && pets.clearLook) pets.clearLook(sprite);
    dock.classList.add('is-moving');
    if (sprite && pets.setState && Number(sprite.getAttribute('data-sprite-version')) > 0) {
      pets.setState(sprite, dx >= 0 ? 'running-right' : 'running-left');
    }
    at(gesture.originLeft + dx, gesture.originTop + dy);
    event.preventDefault();
  });
  function endPointer(event) {
    if (!gesture || gesture.id !== event.pointerId) return;
    var moved = gesture.moved;
    gesture = null;
    dock.classList.remove('is-moving');
    if (dock.hasPointerCapture(event.pointerId)) dock.releasePointerCapture(event.pointerId);
    clearTouchLook();
    if (moved) {
      savePosition();
      if (sprite && pets.setState) pets.setState(sprite, pets.getActivity ? pets.getActivity() : 'idle');
    } else if (event.type === 'pointerup') greet(event.clientX, event.clientY);
  }
  dock.addEventListener('pointerup', endPointer);
  dock.addEventListener('pointercancel', endPointer);
  // Mouse movement (desktop) offers v2's 16 gaze poses while idle.
  // On touch screens the tap, double tap and drag also expose distinct actions.
  dock.addEventListener('pointerenter', function (event) {
    if (event.pointerType === 'mouse' && sprite && pets.lookAt) pets.lookAt(sprite, event.clientX, event.clientY);
  });
  dock.addEventListener('pointerleave', function () {
    if (!gesture) clearTouchLook();
  });
  dock.addEventListener('click', function (event) {
    if (event.detail === 0) greet(dock.getBoundingClientRect().left, dock.getBoundingClientRect().top);
  });

  document.addEventListener('coreai:pet', render);
  document.addEventListener('coreai:pet-activity', function () {
    if (sprite && pets.setState) pets.setState(sprite, pets.getActivity());
    label();
  });
  window.addEventListener('pointermove', function (event) {
    if (!sprite || !pets.lookAt || gesture || event.pointerType !== 'mouse') return;
    pets.lookAt(sprite, event.clientX, event.clientY);
  }, {passive: true});
  window.addEventListener('resize', render);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', layout);
  if (typeof ResizeObserver === 'function') new ResizeObserver(layout).observe(area);
  render();
})();
