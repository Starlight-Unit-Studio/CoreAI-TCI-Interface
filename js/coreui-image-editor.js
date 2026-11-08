/* CoreAI: TCI Interface 0.6.63 - reference-guided image editor.
 * Uses the existing private image-upload, chat and serial FLUX.2 Klein pipeline.
 * Brush strokes are a visual SECOND reference, not a hard inpainting mask.
 * Original media is left untouched; nothing is stored as a memory entry.
 */
(function () {
  'use strict';
  var dialog, canvas, ctx, image, original, current, area, feedback, command, sizePicker;
  var brush, modeButton, undoButton, redoButton, clearButton, sendButton;
  var strokes = [], undone = [], painting = null, brushSize = 28;
  var opener = null, loading = false, epoch = 0, pendingOpen = null;
  var MAX_WIDTH = 1024, MAX_PROMPT = 600, MAX_UPLOAD = 8 * 1024 * 1024;
  var i18n = {
    title:'Bild bearbeiten', whole:'Ganzes Bild', select:'Bereich markieren',
    help:'Markierungen sind Hinweise für FLUX, keine pixelgenaue Maske. Auch andere Bereiche können sich ändern.',
    submit:'Bearbeitung starten', close:'Bearbeitung schließen'
  };

  function el(tag, cls, txt) {
    var x = document.createElement(tag);
    if (cls) x.className = cls;
    if (txt !== undefined) x.textContent = txt;
    return x;
  }
  function button(cls, text, title) {
    var x = el('button', cls, text);
    x.type = 'button';
    if (title) { x.title = title; x.setAttribute('aria-label', title); }
    return x;
  }
  function safeURL(value) {
    try {
      var u = new URL(String(value || ''), document.baseURI);
      return /^https?:$/.test(u.protocol) && u.origin === window.location.origin ? u.href : '';
    } catch (err) { return ''; }
  }
  function safeName(value) {
    return String(value || 'CoreAI-Bild.png').split(/[\\/]/).pop().replace(/[<>:"|?*\u0000-\u001f]/g, '_').slice(0, 120) || 'CoreAI-Bild.png';
  }
  function ensure() {
    if (dialog) return;
    dialog = el('div', 'coreai-image-editor');
    dialog.hidden = true;
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', i18n.title);
    var bar = el('header', 'coreai-image-editor-header');
    bar.appendChild(el('strong', '', '✦ ' + i18n.title));
    var closeButton = button('coreai-image-editor-close', '×', i18n.close);
    bar.appendChild(closeButton);
    closeButton.addEventListener('click', close);

    var body = el('div', 'coreai-image-editor-body');
    var frame = el('div', 'coreai-image-editor-frame');
    canvas = el('canvas', 'coreai-image-editor-canvas');
    canvas.setAttribute('aria-label', 'Bild mit optionaler Bereichsmarkierung');
    canvas.setAttribute('role', 'img');
    canvas.style.touchAction = 'none';
    ctx = canvas.getContext('2d');
    frame.appendChild(canvas);
    body.appendChild(frame);

    var tool = el('div', 'coreai-image-editor-tools');
    modeButton = button('coreai-image-editor-tool', i18n.select, 'Bereiche mit dem Finger markieren');
    modeButton.setAttribute('aria-pressed', 'false');
    tool.appendChild(modeButton);
    var brushLabel = el('label', 'coreai-image-editor-brush-label', 'Pinsel');
    brush = el('input');
    brush.type = 'range';
    brush.min = '8'; brush.max = '96'; brush.step = '2'; brush.value = String(brushSize);
    brush.setAttribute('aria-label', 'Pinselgröße');
    brush.disabled = true;
    syncBrushTrack();
    brushLabel.appendChild(brush);
    tool.appendChild(brushLabel);
    undoButton = button('coreai-image-editor-tool', '↶', 'Markierung rückgängig');
    redoButton = button('coreai-image-editor-tool', '↷', 'Markierung wiederherstellen');
    clearButton = button('coreai-image-editor-tool', '✕ Auswahl', 'Alle Markierungen löschen');
    tool.appendChild(undoButton);
    tool.appendChild(redoButton);
    tool.appendChild(clearButton);
    body.appendChild(tool);

    var note = el('p', 'coreai-image-editor-note', i18n.help);
    body.appendChild(note);

    var promptLabel = el('label', 'coreai-image-editor-prompt-label', 'Was soll Mara ändern?');
    command = el('textarea', 'coreai-image-editor-input');
    command.rows = 3;
    command.maxLength = MAX_PROMPT;
    command.placeholder = 'Zum Beispiel: Ersetze die Jacke durch einen roten Mantel. Gesicht und Szene beibehalten.';
    promptLabel.appendChild(command);
    body.appendChild(promptLabel);

    var opts = el('div', 'coreai-image-editor-options');
    opts.appendChild(el('span', '', 'Ausgabe:'));
    sizePicker = el('select', 'coreai-image-editor-size');
    [['normal','Normal · 512 × 512'],['large','Groß · 1024 × 1024']].forEach(function (p) {
      var opt = el('option', '', p[1]); opt.value = p[0]; sizePicker.appendChild(opt);
    });
    sizePicker.setAttribute('aria-label', 'Ausgabegröße');
    opts.appendChild(sizePicker);
    body.appendChild(opts);

    feedback = el('p', 'coreai-image-editor-feedback');
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    body.appendChild(feedback);
    sendButton = button('coreai-image-editor-submit', '✦ ' + i18n.submit, 'Bildreferenz in Maras Bildwerkzeug bearbeiten');
    body.appendChild(sendButton);

    dialog.appendChild(bar);
    dialog.appendChild(body);
    document.body.appendChild(dialog);

    modeButton.addEventListener('click', function () {
      area = !area;
      brush.disabled = !area;
      modeButton.textContent = area ? i18n.whole : i18n.select;
      modeButton.setAttribute('aria-pressed', area ? 'true' : 'false');
      updateControls();
      render();
    });
    brush.addEventListener('input', function () {
      brushSize = Number(brush.value) || 28;
      syncBrushTrack();
    });
    undoButton.addEventListener('click', function () {
      if (strokes.length) undone.push(strokes.pop());
      updateControls(); render();
    });
    redoButton.addEventListener('click', function () {
      if (undone.length) strokes.push(undone.pop());
      updateControls(); render();
    });
    clearButton.addEventListener('click', function () {
      strokes = []; undone = []; painting = null; updateControls(); render();
    });
    sendButton.addEventListener('click', submit);

    canvas.addEventListener('pointerdown', function (event) {
      if (!area || !image || loading || event.button > 0) return;
      event.preventDefault();
      var p = pointer(event);
      painting = {width:brushSize,points:[p]};
      strokes.push(painting);
      undone = [];
      try { canvas.setPointerCapture(event.pointerId); } catch (err) {}
      updateControls(); render();
    });
    canvas.addEventListener('pointermove', function (event) {
      if (!painting || !area || !image) return;
      event.preventDefault();
      painting.points.push(pointer(event));
      render();
    });
    function stop(event) {
      if (!painting) return;
      try { canvas.releasePointerCapture(event.pointerId); } catch (err) {}
      painting = null;
      updateControls();
    }
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
  }

  function syncBrushTrack() {
    // The TCI range track follows the actual brush size (8..96).
    if (!brush) return;
    var min = Number(brush.min) || 8;
    var max = Number(brush.max) || 96;
    var value = Math.max(min, Math.min(max, Number(brush.value) || min));
    brush.style.setProperty('--editor-brush-fill', ((value - min) / (max - min) * 100).toFixed(1) + '%');
  }

  function pointer(event) {
    var box = canvas.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(canvas.width, (event.clientX - box.left) * canvas.width / box.width)),
      y: Math.max(0, Math.min(canvas.height, (event.clientY - box.top) * canvas.height / box.height))
    };
  }
  function drawMarks(targetCtx) {
    if (!strokes.length) return;
    targetCtx.save();
    targetCtx.strokeStyle = 'rgba(255,40,117,.75)';
    targetCtx.fillStyle = 'rgba(255,40,117,.75)';
    targetCtx.lineCap = 'round';
    targetCtx.lineJoin = 'round';
    strokes.forEach(function (stroke) {
      var pts = stroke.points;
      targetCtx.lineWidth = stroke.width;
      if (pts.length === 1) {
        targetCtx.beginPath();
        targetCtx.arc(pts[0].x, pts[0].y, stroke.width/2, 0, Math.PI*2);
        targetCtx.fill();
      } else if (pts.length > 1) {
        targetCtx.beginPath();
        targetCtx.moveTo(pts[0].x, pts[0].y);
        for (var i=1;i<pts.length;i++) targetCtx.lineTo(pts[i].x,pts[i].y);
        targetCtx.stroke();
      }
    });
    targetCtx.restore();
  }
  function render() {
    if (!image || !ctx) return;
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.drawImage(image,0,0,canvas.width,canvas.height);
    drawMarks(ctx);
  }
  function updateControls() {
    if (!dialog) return;
    undoButton.disabled = !strokes.length;
    redoButton.disabled = !undone.length;
    clearButton.disabled = !strokes.length;
    canvas.classList.toggle('is-marking', !!area);
  }
  function active() { return !!dialog && !dialog.hidden; }
  function close() {
    if (!active()) return;
    epoch += 1;
    if (pendingOpen) { pendingOpen.abort(); pendingOpen = null; }
    loading = false;
    dialog.hidden = true;
    dialog.classList.remove('is-loading');
    strokes = []; undone = []; painting = null; image = null; original = null; current = null;
    document.body.classList.remove('coreai-image-editor-open');
    if (opener && opener.isConnected && opener.focus) opener.focus();
    opener = null;
  }

  async function open(item) {
    ensure();
    if (active() || loading) return false;
    var source = safeURL(item && item.src);
    if (!source) return false;
    opener = document.activeElement;
    epoch += 1;
    var openEpoch = epoch;
    var controller = new AbortController();
    pendingOpen = controller;
    area = false; painting = null; strokes = []; undone = []; brushSize = 28;
    brush.value = '28'; brush.disabled = true; syncBrushTrack();
    modeButton.textContent = i18n.select;
    modeButton.setAttribute('aria-pressed','false');
    sizePicker.value = 'normal';
    command.value = '';
    current = {src:source, name:safeName(item.name), mime:String(item.mime || '')};
    feedback.textContent = 'Bild wird vorbereitet…';
    loading = true; sendButton.disabled = true;
    dialog.hidden = false;
    document.body.classList.add('coreai-image-editor-open');
    try {
      var response = await fetch(source, {credentials:'same-origin', signal:controller.signal});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var blob = await response.blob();
      if (!/^image\/(png|jpeg|webp|gif)$/i.test(blob.type)) throw new Error('Nicht unterstütztes Bildformat');
      if (!active() || epoch !== openEpoch) return false;
      original = blob;
      var temp = URL.createObjectURL(blob);
      var img = new Image();
      try {
        await new Promise(function (resolve,reject) {
          img.onload = resolve;
          img.onerror = function () { reject(new Error('Bild kann nicht gelesen werden')); };
          img.src = temp;
        });
      } finally { URL.revokeObjectURL(temp); }
      if (!active() || epoch !== openEpoch) return false;
      image = img;
      var scale = Math.min(1, MAX_WIDTH / Math.max(img.naturalWidth, img.naturalHeight));
      canvas.width = Math.max(1,Math.round(img.naturalWidth * scale));
      canvas.height = Math.max(1,Math.round(img.naturalHeight * scale));
      updateControls(); render();
      feedback.textContent = '';
      command.focus();
      return true;
    } catch (err) {
      if (active() && epoch === openEpoch && err.name !== 'AbortError')
        feedback.textContent = 'Bild konnte nicht geladen werden: ' + (err.message || err);
      return false;
    } finally {
      if (epoch === openEpoch) {
        pendingOpen = null;
        loading = false;
        sendButton.disabled = !image;
      }
    }
  }

  function toBlob(c, type, quality) {
    return new Promise(function (resolve, reject) {
      c.toBlob(function (b) {
        if (b) resolve(b);
        else reject(new Error('Bildvorbereitung fehlgeschlagen'));
      }, type, quality);
    });
  }

  async function filesForEdit() {
    var mainBlob = original;
    var type = mainBlob.type;
    var mainName = current.name;
    if (mainBlob.size > MAX_UPLOAD) {
      // Client-only downscale for the existing 8 MB image-upload limit.
      var resized = el('canvas');
      resized.width=canvas.width; resized.height=canvas.height;
      resized.getContext('2d').drawImage(image,0,0,resized.width,resized.height);
      mainBlob = await toBlob(resized,'image/jpeg',0.88);
      type = 'image/jpeg';
      mainName = 'CoreAI-Referenz.jpg';
      if (mainBlob.size > MAX_UPLOAD) throw new Error('Referenzbild ist zu groß');
    }
    var files = [new File([mainBlob], mainName, {type:type})];
    if (strokes.length) {
      var marked = el('canvas');
      marked.width=canvas.width; marked.height=canvas.height;
      var markedCtx=marked.getContext('2d');
      markedCtx.drawImage(image,0,0,marked.width,marked.height);
      drawMarks(markedCtx);
      var blob = await toBlob(marked,'image/png');
      if (blob.size > MAX_UPLOAD) throw new Error('Markierung ist zu groß');
      files.push(new File([blob], 'CoreAI-Bereichsmarkierung.png', {type:'image/png'}));
    }
    return files;
  }

  async function submit() {
    if (!image || loading || !current) return;
    var request = String(command.value || '').trim();
    if (request.length < 3) { feedback.textContent = 'Beschreibe zuerst die gewünschte Änderung.'; command.focus(); return; }
    if (request.length > MAX_PROMPT) { feedback.textContent = 'Die Beschreibung ist zu lang.'; return; }
    if (!window.CoreUIComposer || typeof window.CoreUIComposer.sendImageEdit !== 'function') {
      feedback.textContent = 'Bildbearbeitung ist in dieser Chat-Sitzung nicht verfügbar.';
      return;
    }
    var submitEpoch = epoch;
    loading = true; sendButton.disabled = true;
    feedback.textContent = 'Referenzbild wird vorbereitet…';
    try {
      var files = await filesForEdit();
      if (!active() || epoch !== submitEpoch) return;
      var instruction = 'Erzeuge eine bearbeitete Version von Referenzbild 1, kein neues unabhängiges Motiv. '
        + 'Ändere: ' + request + '. Identität, Körperproportionen und übrige Bildbereiche möglichst bewahren.';
      if (strokes.length) instruction += ' Referenzbild 2 enthält PINKFARBENE Markierungen: '
        + 'Sie kennzeichnen den Bereich, der geändert werden soll. '
        + 'Die Markierungen und ihre Farbe dürfen NICHT im Ergebnis erscheinen. '
        + 'Nutze Referenzbild 1 als eigentliche Vorlage.';
      if (instruction.length > 1080) throw new Error('Bearbeitungsanweisung ist zu lang');
      var result = window.CoreUIComposer.sendImageEdit(files, instruction, sizePicker.value);
      if (result !== 'ok') throw new Error(({
        busy:'Mara bearbeitet gerade eine andere Anfrage.',
        editing:'Bitte erst die aktuelle Nachrichtenbearbeitung abschließen.',
        full:'Bitte die übrigen Anhänge im Chat entfernen.',
        draft:'Im Chat liegt noch ein ungesendeter Entwurf. Bitte zuerst senden oder löschen.',
        no_session:'Keine aktive Unterhaltung vorhanden.',
        invalid:'Das Bildformat ist nicht zugelassen.'
      })[result] || 'Das Bild konnte nicht an den Chat übergeben werden.');
      loading = false;
      close();
      if (window.CoreAIImageViewer && window.CoreAIImageViewer.isOpen()) window.CoreAIImageViewer.close();
    } catch (err) {
      if (active() && epoch === submitEpoch) {
        feedback.textContent = err.message || String(err);
        loading = false; sendButton.disabled = false;
      }
    }
  }

  document.addEventListener('keydown', function (event) {
    if (!active()) return;
    // Capture: the viewer beneath must not process this Escape/arrow key.
    if (event.key === 'Escape') {
      event.preventDefault(); event.stopImmediatePropagation();
      if (!loading) close();
      return;
    }
    if (event.key === 'Tab') {
      var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), textarea, input:not([disabled]), select'))
        .filter(function (x) { return x.getClientRects().length>0; });
      if (!focusable.length) return;
      if (event.shiftKey && document.activeElement === focusable[0]) {
        event.preventDefault(); focusable[focusable.length-1].focus();
      } else if (!event.shiftKey && document.activeElement === focusable[focusable.length-1]) {
        event.preventDefault(); focusable[0].focus();
      }
    }
  }, true);

  window.addEventListener('popstate', function () {
    if (active()) {
      // If the user leaves the viewer by Android Back, clean both overlays.
      loading = false;
      close();
    }
  });

  window.CoreAIImageEditor = Object.freeze({open:open, close:close, isOpen:active});
})();
