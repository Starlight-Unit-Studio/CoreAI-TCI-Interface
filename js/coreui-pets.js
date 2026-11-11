/* CoreAI: TCI Interface 0.7.1: pets, the animated companions of Settings > Pets.
   A pet is a sprite sheet (a row of square frames). CoreAIPets.make() builds one animated pet;
   one shared timer steps every pet on the page (about seven frames a second; still with
   prefers-reduced-motion). In the chat the chosen pet stands at the start of Mara's work line
   (js/coreui-work-events.js), loaded once with CoreAIPets.loadCurrent() (api/pets.php). */
(function () {
  'use strict';

  var FRAME_MS = 140;
  var timer = null;
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function step() {
    var pets = document.querySelectorAll('.coreai-pet[data-frames]');
    if (!pets.length) { window.clearInterval(timer); timer = null; return; }
    Array.prototype.forEach.call(pets, function (node) {
      var frames = Number(node.getAttribute('data-frames')) || 1;
      var frame = ((Number(node.getAttribute('data-frame')) || 0) + 1) % frames;
      show(node, frame, frames);
    });
  }
  function show(node, frame, frames) {
    node.setAttribute('data-frame', String(frame));
    node.style.backgroundPosition = (frames > 1 ? (frame / (frames - 1)) * 100 : 0) + '% 0';
  }
  function start() {
    if (!timer && !still) timer = window.setInterval(step, FRAME_MS);
  }

  /** One animated pet ({url, frames, frame_px, name}) of size px, or null. */
  function make(pet, size, className) {
    if (!pet || !pet.url) return null;
    var frames = Math.max(1, Math.min(24, Number(pet.frames) || 1));
    var node = document.createElement('span');
    node.className = 'coreai-pet' + (className ? ' ' + className : '');
    node.setAttribute('aria-hidden', 'true');
    node.setAttribute('data-frames', String(frames));
    node.setAttribute('data-pet', String(pet.id || ''));
    node.style.width = size + 'px';
    node.style.height = size + 'px';
    node.style.backgroundImage = 'url("' + String(pet.url).replace(/"/g, '%22') + '")';
    node.style.backgroundSize = (frames * 100) + '% 100%';
    // Pixel art stays sharp; large drawn frames are scaled smoothly.
    if ((Number(pet.frame_px) || 32) <= 64) node.classList.add('is-pixel');
    show(node, 0, frames);
    start();
    return node;
  }

  var api = {
    current: null,
    make: make,
    /** The pet of this account for the chat; null for "Pet nicht anzeigen". */
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
