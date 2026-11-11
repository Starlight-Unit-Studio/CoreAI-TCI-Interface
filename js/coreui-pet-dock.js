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
    event.preventDefault();
  });
  dock.addEventListener('pointermove', function (event) {
    if (!gesture || gesture.id !== event.pointerId) return;
    var dx = event.clientX - gesture.startX;
    var dy = event.clientY - gesture.startY;
    if (Math.hypot(dx, dy) > 7) gesture.moved = true;
    if (!gesture.moved) return;
    dock.classList.add('is-moving');
    at(gesture.originLeft + dx, gesture.originTop + dy);
    event.preventDefault();
  });
  function endPointer(event) {
    if (!gesture || gesture.id !== event.pointerId) return;
    var moved = gesture.moved;
    gesture = null;
    dock.classList.remove('is-moving');
    if (dock.hasPointerCapture(event.pointerId)) dock.releasePointerCapture(event.pointerId);
    if (moved) savePosition();
    else if (event.type === 'pointerup' && pets.playTemporary) pets.playTemporary(sprite, 'waving', 1100);
  }
  dock.addEventListener('pointerup', endPointer);
  dock.addEventListener('pointercancel', endPointer);
  // Keyboard users can greet their pet without needing drag gestures.
  dock.addEventListener('click', function (event) {
    if (event.detail === 0 && pets.playTemporary) pets.playTemporary(sprite, 'waving', 1100);
  });

  document.addEventListener('coreai:pet', render);
  document.addEventListener('coreai:pet-activity', function () {
    if (sprite && pets.setState) pets.setState(sprite, pets.getActivity());
    label();
  });
  window.addEventListener('resize', render);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', layout);
  if (typeof ResizeObserver === 'function') new ResizeObserver(layout).observe(area);
  render();
})();
