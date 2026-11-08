/* CoreAI profile cropper: adapted from STU Game's touch square crop modal.
   Cropping never serializes or replaces the uploaded source in the browser.
   The server receives normalized coordinates and renders a private derivative. */
(function (root, doc) {
  'use strict';

  function clamp(value, min, max) { return Math.max(min, Math.min(max, value)); }
  function normalize(rect, width, height) {
    if (!rect || !(width > 0) || !(height > 0)) throw new Error('crop_dimensions');
    return {
      x: clamp(rect.x / width, 0, 1),
      y: clamp(rect.y / height, 0, 1),
      w: clamp(rect.w / width, 0, 1),
      h: clamp(rect.h / height, 0, 1)
    };
  }
  function fromNormalized(crop, width, height) {
    if (!crop || !['x','y','w','h'].every(function (key) { return Number.isFinite(crop[key]); })) return null;
    var x = clamp(crop.x * width, 0, width - 1);
    var y = clamp(crop.y * height, 0, height - 1);
    var w = clamp(crop.w * width, 1, width - x);
    var h = clamp(crop.h * height, 1, height - y);
    return {x: x, y: y, w: w, h: h};
  }
  function centered(width, height, ratio, fraction) {
    fraction = clamp(fraction || 0.9, 0.1, 1);
    var w = Math.min(width, height * ratio) * fraction;
    var h = w / ratio;
    return {x: (width - w)/2, y: (height - h)/2, w: w, h: h};
  }
  function fitRect(rect, width, height) {
    var w = clamp(rect.w, 1, width), h = clamp(rect.h, 1, height);
    return {x:clamp(rect.x, 0, width-w), y:clamp(rect.y, 0, height-h), w:w, h:h};
  }

  function create() {
    if (!root || !doc) return null;
    var modal = doc.getElementById('profileCropDialog');
    var canvas = doc.getElementById('profileCropCanvas');
    var box = doc.getElementById('profileCropBox');
    if (!modal || !canvas || !box) return null;
    var context = canvas.getContext('2d');
    if (!context) return null;
    var title = doc.getElementById('profileCropTitle');
    var note = doc.getElementById('profileCropNote');
    var status = doc.getElementById('profileCropStatus');
    var size = doc.getElementById('profileCropSize');
    var widthSlider = doc.getElementById('profileCropWidth');
    var heightSlider = doc.getElementById('profileCropHeight');
    var sizeFields = doc.getElementById('profileCropSizeFields');
    var freeFields = doc.getElementById('profileCropFreeFields');
    var ratios = Array.prototype.slice.call(doc.querySelectorAll('[data-crop-ratio]'));
    var apply = doc.getElementById('profileCropApply');
    var cancel = doc.getElementById('profileCropCancel');
    var reset = doc.getElementById('profileCropReset');
    var pointers = new Map();
    var current = null;
    var source = null;
    var objectUrl = '';
    var rect = null;
    var aspect = 'free';
    var drag = null;
    var pinch = null;
    var busy = false;
    var previousFocus = null;
    var previousOverflow = '';
    var generation = 0;

    function say(message, bad) {
      if (status) {
        status.textContent = message || '';
        status.dataset.error = bad ? '1' : '0';
      }
    }
    function draw() {
      if (!source || !rect) return;
      context.clearRect(0, 0, canvas.width, canvas.height);
      context.drawImage(source, 0, 0, canvas.width, canvas.height);
      box.style.left = rect.x + 'px';
      box.style.top = rect.y + 'px';
      box.style.width = rect.w + 'px';
      box.style.height = rect.h + 'px';
      if (size) size.value = String(Math.round(100 * Math.min(rect.w/canvas.width, rect.h/canvas.height)));
      if (widthSlider) widthSlider.value = String(Math.round(100 * rect.w/canvas.width));
      if (heightSlider) heightSlider.value = String(Math.round(100 * rect.h/canvas.height));
    }
    function refreshControls() {
      var square = current && current.slot === 'user';
      if (sizeFields) sizeFields.hidden = aspect === 'free';
      if (freeFields) freeFields.hidden = aspect !== 'free';
      ratios.forEach(function (button) {
        var active = button.dataset.cropRatio === aspect;
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.hidden = !!square && button.dataset.cropRatio !== '1:1';
      });
    }
    function setRect(next) {
      rect = fitRect(next, canvas.width, canvas.height);
      draw();
    }
    function setAspect(next) {
      if (current && current.slot === 'user' && next !== '1:1') return;
      aspect = next;
      if (next !== 'free') {
        var ratio = next === '2:3' ? 2/3 : next === '3:4' ? 3/4 : 1;
        var amount = rect ? Math.min(1, rect.w/Math.min(canvas.width, canvas.height*ratio)) : 0.9;
        setRect(centered(canvas.width, canvas.height, ratio, amount));
      }
      refreshControls();
      draw();
    }
    function resetCrop() {
      if (!source) return;
      aspect = current.slot === 'user' ? '1:1' : 'free';
      rect = aspect === 'free'
        ? {x:0, y:0, w:canvas.width, h:canvas.height}
        : centered(canvas.width, canvas.height, 1, 0.9);
      refreshControls();
      draw();
      say('Ausschnitt zurückgesetzt.');
    }
    function fitCanvas(preserve) {
      if (!source) return;
      var previous = preserve && rect ? normalize(rect, canvas.width, canvas.height) : null;
      var maxWidth = Math.max(160, Math.min(520, root.innerWidth - 70));
      var maxHeight = Math.max(130, Math.min(430, root.innerHeight * 0.47));
      var factor = Math.min(1, maxWidth/source.naturalWidth, maxHeight/source.naturalHeight);
      canvas.width = Math.max(1, Math.round(source.naturalWidth * factor));
      canvas.height = Math.max(1, Math.round(source.naturalHeight * factor));
      canvas.style.width = canvas.width + 'px';
      canvas.style.height = canvas.height + 'px';
      var restored = previous || current.crop;
      rect = restored ? fromNormalized(restored, canvas.width, canvas.height) : null;
      if (!rect) resetCrop();
      else { refreshControls(); draw(); }
    }
    function releaseUrl() {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = '';
    }
    function close() {
      if (busy) return;
      generation++;
      modal.hidden = true;
      releaseUrl();
      source = null;
      current = null;
      rect = null;
      drag = null;
      pinch = null;
      pointers.clear();
      doc.body.style.overflow = previousOverflow;
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }
    function open(options) {
      options = options || {};
      if (!options.file && !options.url) return false;
      if (options.slot !== 'user' && options.slot !== 'assistant') return false;
      if (busy) return false;
      if (!modal.hidden) close();
      generation++;
      var thisGeneration = generation;
      current = options;
      source = null;
      rect = null;
      drag = null;
      pinch = null;
      pointers.clear();
      aspect = options.slot === 'user' ? '1:1' : 'free';
      previousFocus = doc.activeElement;
      previousOverflow = doc.body.style.overflow;
      doc.body.style.overflow = 'hidden';
      if (title) title.textContent = options.slot === 'user' ? 'Benutzerbild zuschneiden' : 'CoreAI-Referenzbild zuschneiden';
      if (note) note.textContent = options.slot === 'user'
        ? 'Verschiebe den quadratischen Ausschnitt mit dem Finger. Das Original bleibt erhalten.'
        : 'Wähle Gesicht und Oberkörper oder behalte das ganze Bild. Das Original bleibt erhalten.';
      modal.hidden = false;
      refreshControls();
      say('Foto wird geöffnet …');
      var image = new Image();
      image.onload = function () {
        if (thisGeneration !== generation) return;
        if (!image.naturalWidth || !image.naturalHeight) { say('Bild ist ungültig.', true); return; }
        source = image;
        fitCanvas(false);
        say('Ausschnitt mit dem Finger verschieben, über die Regler anpassen.');
        if (apply) apply.focus();
      };
      image.onerror = function () {
        if (thisGeneration === generation) say('Originalbild konnte nicht geöffnet werden.', true);
      };
      releaseUrl();
      if (options.file) objectUrl = URL.createObjectURL(options.file);
      image.src = options.file ? objectUrl : options.url;
      return true;
    }
    function localPoint(event) {
      var bounds = canvas.getBoundingClientRect();
      return {
        x:(event.clientX-bounds.left)*canvas.width/Math.max(1,bounds.width),
        y:(event.clientY-bounds.top)*canvas.height/Math.max(1,bounds.height)
      };
    }
    function pinchDistance() {
      var a = Array.from(pointers.values());
      return a.length < 2 ? 0 : Math.hypot(a[0].x-a[1].x, a[0].y-a[1].y);
    }
    function updateSize(w,h) {
      if (!rect) return;
      var cx = rect.x + rect.w/2, cy = rect.y + rect.h/2;
      if (aspect !== 'free') {
        var ratio = aspect === '2:3' ? 2/3 : aspect === '3:4' ? 3/4 : 1;
        w = Math.min(w, canvas.width, canvas.height*ratio);
        h = w/ratio;
      }
      w = clamp(w, Math.min(24,canvas.width), canvas.width);
      h = clamp(h, Math.min(24,canvas.height), canvas.height);
      setRect({x:cx-w/2, y:cy-h/2, w:w, h:h});
    }

    canvas.addEventListener('pointerdown', function (event) {
      if (!rect || busy) return;
      var p = localPoint(event);
      pointers.set(event.pointerId, p);
      if (typeof canvas.setPointerCapture === 'function') canvas.setPointerCapture(event.pointerId);
      if (pointers.size === 2) {
        drag = null;
        pinch = {distance:Math.max(1,pinchDistance()), rect:Object.assign({},rect)};
        return;
      }
      if (p.x < rect.x || p.y < rect.y || p.x > rect.x+rect.w || p.y > rect.y+rect.h) return;
      var handle = p.x >= rect.x+rect.w-24 && p.y >= rect.y+rect.h-24;
      drag = {id:event.pointerId, mode:handle?'resize':'move', x:p.x, y:p.y, rect:Object.assign({},rect)};
    });
    canvas.addEventListener('pointermove', function (event) {
      if (!pointers.has(event.pointerId) || !rect || busy) return;
      var p = localPoint(event);
      pointers.set(event.pointerId,p);
      if (pinch && pointers.size >= 2) {
        var factor = pinch.distance/Math.max(1,pinchDistance());
        var w = pinch.rect.w*factor, h = pinch.rect.h*factor;
        if (aspect !== 'free') {
          var ratio = aspect === '2:3' ? 2/3 : aspect === '3:4' ? 3/4 : 1;
          w = Math.min(w, canvas.width, canvas.height*ratio);
          h = w/ratio;
        }
        var midX=pinch.rect.x+pinch.rect.w/2, midY=pinch.rect.y+pinch.rect.h/2;
        setRect({x:midX-w/2, y:midY-h/2, w:Math.max(24,w), h:Math.max(24,h)});
        return;
      }
      if (!drag || drag.id !== event.pointerId) return;
      var dx=p.x-drag.x,dy=p.y-drag.y;
      if (drag.mode === 'move') {
        setRect({x:drag.rect.x+dx,y:drag.rect.y+dy,w:drag.rect.w,h:drag.rect.h});
      } else if (aspect === 'free') {
        setRect({x:drag.rect.x,y:drag.rect.y,
          w:clamp(drag.rect.w+dx,24,canvas.width-drag.rect.x),
          h:clamp(drag.rect.h+dy,24,canvas.height-drag.rect.y)});
      } else {
        var ratio2 = aspect === '2:3' ? 2/3 : aspect === '3:4' ? 3/4 : 1;
        var w2 = clamp(drag.rect.w+dx,24,Math.min(canvas.width-drag.rect.x,(canvas.height-drag.rect.y)*ratio2));
        setRect({x:drag.rect.x,y:drag.rect.y,w:w2,h:w2/ratio2});
      }
    });
    function finishPointer(event) {
      pointers.delete(event.pointerId);
      if (drag && drag.id === event.pointerId) drag = null;
      if (pointers.size < 2) pinch = null;
    }
    canvas.addEventListener('pointerup',finishPointer);
    canvas.addEventListener('pointercancel',finishPointer);
    ratios.forEach(function (button) {
      button.addEventListener('click',function () { if (!busy && source) setAspect(button.dataset.cropRatio); });
    });
    if (size) size.addEventListener('input',function () {
      if (!source || busy || aspect === 'free') return;
      var ratio = aspect === '2:3' ? 2/3 : aspect === '3:4' ? 3/4 : 1;
      updateSize(Math.min(canvas.width,canvas.height*ratio)*Number(size.value)/100,0);
    });
    if (widthSlider) widthSlider.addEventListener('input',function () {
      if (source && !busy && aspect === 'free') updateSize(canvas.width*Number(widthSlider.value)/100,rect.h);
    });
    if (heightSlider) heightSlider.addEventListener('input',function () {
      if (source && !busy && aspect === 'free') updateSize(rect.w,canvas.height*Number(heightSlider.value)/100);
    });
    if (reset) reset.addEventListener('click',function () { if (!busy) resetCrop(); });
    if (cancel) cancel.addEventListener('click',close);
    modal.addEventListener('click',function (event) { if (event.target === modal) close(); });
    doc.addEventListener('keydown',function (event) {
      if (modal.hidden) return;
      if (event.key === 'Escape' && !busy) { event.preventDefault(); close(); }
      if (event.key === 'Tab') {
        var buttons = Array.prototype.slice.call(modal.querySelectorAll('button:not([hidden]):not([disabled]),input:not([hidden]):not([disabled])')).filter(function (el) {
          return el.offsetParent !== null;
        });
        if (!buttons.length) return;
        var start = buttons[0], end = buttons[buttons.length-1];
        if (event.shiftKey && doc.activeElement === start) { event.preventDefault(); end.focus(); }
        else if (!event.shiftKey && doc.activeElement === end) { event.preventDefault(); start.focus(); }
      }
    });
    root.addEventListener('resize',function () { if (!modal.hidden && source && !busy) fitCanvas(true); });
    if (apply) apply.addEventListener('click',function () {
      if (!source || !rect || busy || typeof current.onConfirm !== 'function') return;
      var crop = normalize(rect,canvas.width,canvas.height);
      // Keep the edges within one source pixel of the normalized image.
      crop = {x:+crop.x.toFixed(7),y:+crop.y.toFixed(7),w:+crop.w.toFixed(7),h:+crop.h.toFixed(7)};
      busy = true;
      apply.disabled = true;
      if (cancel) cancel.disabled = true;
      say('Originalbild und Zuschnitt werden gespeichert …');
      Promise.resolve().then(function () { return current.onConfirm(crop); }).then(function () {
        say('Gespeichert.');
        busy = false;
        close();
      }).catch(function (error) {
        busy = false;
        say('Speichern fehlgeschlagen: ' + String(error && error.message || error),true);
      }).finally(function () { apply.disabled = false; if (cancel) cancel.disabled = false; });
    });
    return {open:open, close:close};
  }

  var exported = {create:create, normalize:normalize, fromNormalized:fromNormalized, centered:centered, fitRect:fitRect};
  if (root) root.CoreUIProfileCrop = exported;
  if (typeof module !== 'undefined' && module.exports) module.exports = exported;
})(typeof window !== 'undefined' ? window : null, typeof document !== 'undefined' ? document : null);
