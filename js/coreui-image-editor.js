/* CoreAI: TCI Interface 0.6.64 | Touch annotation tools for image editing.
 * All annotations are local visual guidance for FLUX. The original is never
 * overwritten; the existing private upload/serial image queue is reused.
 */
(function () {
  'use strict';

  var dialog, canvas, ctx, image, original, current, feedback, command, sizePicker, sendButton;
  var brush, toolbar, shapePalette, colorPalette, textPalette, textInput, markPreview, markThumb;
  var buttons = {}, paletteButtons = [];
  var annotations = [], undone = [], activeStroke = null, selected = -1;
  var tool = 'brush', shape = 'line', color = '#ef3256', brushSize = 28;
  var opener = null, loading = false, epoch = 0, pendingOpen = null;
  var MAX_WIDTH = 1024, MAX_PROMPT = 600, MAX_UPLOAD = 8 * 1024 * 1024;
  var SHAPES = [
    ['line','Linie'], ['arrow','Pfeil'], ['rectangle','Rechteck'], ['circle','Kreis'],
    ['triangle','Dreieck'], ['diamond','Raute'], ['star','Stern'], ['heart','Herz']
  ];
  var COLORS = [
    '#ffffff','#7c879e','#994818','#ef3256','#ff7c22','#f4a827',
    '#16a45e','#08978a','#08b6d8','#2868e8','#634bf0','#a32edc','#d72a86'
  ];
  var ICON = {
    select:'<path d="m5 3 14 9-7 1-3 7-4-17z"/>',
    brush:'<path d="M14 5 19 2l3 3-9 10-4 1 1-4 4-7Z"/><path d="M9 16c-2 0-4 2-4 4s-2 2-3 2c4 1 9-1 9-5"/>',
    text:'<path d="M4 6V3h16v3M12 3v18M8 21h8"/>',
    shapes:'<rect x="2" y="3" width="10" height="10" rx="1"/><circle cx="17" cy="17" r="5"/>',
    eraser:'<path d="m20 20-6-6M8 20 2 14a2 2 0 0 1 0-3L11 2a2 2 0 0 1 3 0l8 8a2 2 0 0 1 0 3l-7 7H8zM9 20h13"/>',
    undo:'<path d="M9 14 4 9l5-5M4 9h9a7 7 0 0 1 0 14"/>',
    redo:'<path d="m15 14 5-5-5-5M20 9h-9a7 7 0 0 0 0 14"/>',
    close:'<path d="M18 6 6 18M6 6l12 12"/>',
    line:'<path d="m4 20 16-16"/>',
    arrow:'<path d="M4 20 20 4M9 4h11v11"/>',
    rectangle:'<rect x="3" y="4" width="18" height="16" rx="1"/>',
    circle:'<circle cx="12" cy="12" r="9"/>',
    triangle:'<path d="M12 3 22 21H2Z"/>',
    diamond:'<path d="m12 2 10 10-10 10L2 12Z"/>',
    star:'<path d="m12 2 3 7 7 1-5 5 1 7-6-4-6 4 1-7-5-5 7-1Z"/>',
    heart:'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0l-1 1-1-1a5.5 5.5 0 0 0-7.8 7.8L12 22l8.8-9.6a5.5 5.5 0 0 0 0-7.8Z"/>'
  };

  function el(tag, cls, txt) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (txt !== undefined) node.textContent = txt;
    return node;
  }
  function icon(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[name] + '</svg>';
  }
  function button(cls, label, name) {
    var node = el('button', cls);
    node.type = 'button';
    node.title = label;
    node.setAttribute('aria-label', label);
    if (name) node.innerHTML = icon(name);
    else node.textContent = label;
    return node;
  }
  function safeURL(value) {
    try {
      var url = new URL(String(value || ''), document.baseURI);
      return /^https?:$/.test(url.protocol) && url.origin === window.location.origin ? url.href : '';
    } catch (_) { return ''; }
  }
  function safeName(value) {
    return String(value || 'CoreAI-Bild.png').split(/[\\/]/).pop()
      .replace(/[<>:"|?*\u0000-\u001f]/g, '_').slice(0, 120) || 'CoreAI-Bild.png';
  }
  function snapshot() {
    return JSON.parse(JSON.stringify(annotations));
  }
  function remember() {
    undone = [];
    history.push(snapshot());
  }
  var history = [];

  function ensure() {
    if (dialog) return;
    dialog = el('div', 'coreai-image-editor');
    dialog.hidden = true;
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', 'Bild bearbeiten');

    var header = el('header', 'coreai-image-editor-header');
    header.appendChild(el('strong', '', '✦ Bild bearbeiten'));
    buttons.headerClose = button('coreai-image-editor-close', 'Bildbearbeitung schließen', 'close');
    header.appendChild(buttons.headerClose);
    buttons.headerClose.addEventListener('click', close);

    var body = el('div', 'coreai-image-editor-body');
    var frame = el('div', 'coreai-image-editor-frame');
    canvas = el('canvas', 'coreai-image-editor-canvas');
    canvas.setAttribute('aria-label', 'Bild mit Zeichenwerkzeugen bearbeiten');
    canvas.setAttribute('role', 'img');
    canvas.style.touchAction = 'none';
    ctx = canvas.getContext('2d');
    frame.appendChild(canvas);

    var sizePopover = el('div', 'coreai-image-editor-size-popover');
    sizePopover.hidden = true;
    var sizeCaption = el('span', 'coreai-image-editor-size-caption', '28 px');
    brush = el('input');
    brush.type = 'range';
    brush.min = '8'; brush.max = '96'; brush.step = '2'; brush.value = String(brushSize);
    brush.setAttribute('aria-label', 'Pinselgröße');
    sizePopover.appendChild(sizeCaption);
    sizePopover.appendChild(brush);
    frame.appendChild(sizePopover);
    brush.addEventListener('input', function () {
      brushSize = Number(brush.value) || 28;
      sizeCaption.textContent = brushSize + ' px';
      syncBrushTrack();
    });
    body.appendChild(frame);

    var barAnchor = el('div', 'coreai-image-editor-toolbar-anchor');
    toolbar = el('div', 'coreai-image-editor-toolbar');
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Bild-Werkzeuge');
    [['select','Verschieben','select'],['brush','Freihand zeichnen','brush'],
     ['text','Text hinzufügen','text'],['shapes','Formen auswählen','shapes'],
     ['color','Zeichenfarbe auswählen',null],['eraser','Radierer','eraser'],
     ['undo','Rückgängig','undo'],['redo','Wiederholen','redo'],
     ['close','Werkzeuge schließen','close']].forEach(function (item) {
      var b = button('coreai-image-editor-tool coreai-image-editor-tool-' + item[0], item[1], item[2]);
      buttons[item[0]] = b;
      if (item[0] === 'color') {
        b.textContent = '';
        b.appendChild(el('span', 'coreai-image-editor-color-dot'));
      }
      toolbar.appendChild(b);
    });
    barAnchor.appendChild(toolbar);
    body.appendChild(barAnchor);

    shapePalette = el('div', 'coreai-image-editor-popover coreai-image-editor-shape-palette');
    shapePalette.hidden = true;
    shapePalette.setAttribute('aria-label', 'Formen wählen');
    SHAPES.forEach(function (entry) {
      var b = button('coreai-image-editor-shape-option', entry[1], entry[0]);
      b.dataset.shape = entry[0];
      b.addEventListener('click', function () {
        shape = entry[0];
        setTool('shape');
        hidePalettes();
        updateControls();
      });
      shapePalette.appendChild(b);
    });
    barAnchor.appendChild(shapePalette);

    colorPalette = el('div', 'coreai-image-editor-popover coreai-image-editor-color-palette');
    colorPalette.hidden = true;
    colorPalette.setAttribute('aria-label', 'Zeichenfarbe wählen');
    var customLabel = el('label', 'coreai-image-editor-color-custom');
    customLabel.title = 'Eigene Farbe wählen';
    var picker = el('input');
    picker.type = 'color';
    picker.value = color;
    picker.setAttribute('aria-label', 'Eigene Zeichenfarbe');
    customLabel.appendChild(picker);
    customLabel.appendChild(el('span', '', '◉'));
    colorPalette.appendChild(customLabel);
    picker.addEventListener('input', function () { changeColor(picker.value); });
    COLORS.forEach(function (value) {
      var swatch = button('coreai-image-editor-color-option', 'Farbe ' + value);
      swatch.style.setProperty('--swatch', value);
      swatch.dataset.color = value;
      swatch.addEventListener('click', function () {
        changeColor(value);
        picker.value = value;
        hidePalettes();
      });
      paletteButtons.push(swatch);
      colorPalette.appendChild(swatch);
    });
    barAnchor.appendChild(colorPalette);

    textPalette = el('div', 'coreai-image-editor-popover coreai-image-editor-text-palette');
    textPalette.hidden = true;
    var textLabel = el('label', '', 'Text für die Markierung');
    textInput = el('input', 'coreai-image-editor-text-value');
    textInput.type = 'text';
    textInput.maxLength = 90;
    textInput.placeholder = 'Text eingeben, dann Bild antippen';
    textInput.setAttribute('aria-label', 'Markierungstext');
    textLabel.appendChild(textInput);
    textPalette.appendChild(textLabel);
    var textConfirm = button('coreai-image-editor-text-apply', 'Text platzieren');
    textPalette.appendChild(textConfirm);
    textConfirm.addEventListener('click', function () {
      if (!textInput.value.trim()) { textInput.focus(); return; }
      setTool('text');
      hidePalettes();
      feedback.textContent = 'Auf das Bild tippen, um den Text zu platzieren.';
    });
    textInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        textConfirm.click();
      }
    });
    barAnchor.appendChild(textPalette);

    buttons.select.addEventListener('click', function () { setTool('select'); });
    buttons.brush.addEventListener('click', function () { setTool('brush'); });
    buttons.eraser.addEventListener('click', function () { setTool('eraser'); });
    buttons.shapes.addEventListener('click', function () {
      setTool('shape');
      togglePalette(shapePalette);
    });
    buttons.color.addEventListener('click', function () { togglePalette(colorPalette); });
    buttons.text.addEventListener('click', function () {
      setTool('text');
      togglePalette(textPalette);
      if (!textPalette.hidden) textInput.focus();
    });
    buttons.undo.addEventListener('click', function () {
      if (!history.length) return;
      undone.push(snapshot());
      annotations = history.pop();
      selected = -1;
      render();
      updateControls();
    });
    buttons.redo.addEventListener('click', function () {
      if (!undone.length) return;
      history.push(snapshot());
      annotations = undone.pop();
      selected = -1;
      render();
      updateControls();
    });
    buttons.close.addEventListener('click', close);

    markPreview = el('div', 'coreai-image-editor-mark-preview');
    markPreview.hidden = true;
    markThumb = el('canvas', 'coreai-image-editor-mark-thumb');
    markThumb.width = 56; markThumb.height = 56;
    markPreview.appendChild(markThumb);
    markPreview.appendChild(el('span', '', 'Markierung für FLUX'));
    var clearButton = button('coreai-image-editor-mark-clear', 'Alle Markierungen entfernen', 'close');
    clearButton.addEventListener('click', function () {
      if (!annotations.length) return;
      remember();
      annotations = [];
      selected = -1;
      render();
      updateControls();
    });
    markPreview.appendChild(clearButton);
    body.appendChild(markPreview);

    body.appendChild(el('p', 'coreai-image-editor-note',
      'Zeichnungen, Formen, Farben und Text sind Hinweise für FLUX, keine pixelgenaue Maske. Andere Bereiche können sich ändern.'));

    var prompt = el('label', 'coreai-image-editor-prompt-label', 'Was soll Mara ändern?');
    command = el('textarea', 'coreai-image-editor-input');
    command.rows = 3;
    command.maxLength = MAX_PROMPT;
    command.placeholder = 'Zum Beispiel: Ändere nur den markierten Bereich. Gesicht und Umgebung erhalten.';
    prompt.appendChild(command);
    body.appendChild(prompt);

    var opts = el('div', 'coreai-image-editor-options');
    opts.appendChild(el('span', '', 'Ausgabe:'));
    sizePicker = el('select', 'coreai-image-editor-size');
    [['normal','Normal · 512 × 512'],['large','Groß · 1024 × 1024']].forEach(function (p) {
      var opt = el('option', '', p[1]);
      opt.value = p[0];
      sizePicker.appendChild(opt);
    });
    sizePicker.setAttribute('aria-label', 'Ausgabegröße');
    opts.appendChild(sizePicker);
    body.appendChild(opts);
    feedback = el('p', 'coreai-image-editor-feedback');
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    body.appendChild(feedback);
    sendButton = button('coreai-image-editor-submit', 'Bearbeitung starten');
    sendButton.textContent = '✦ Bearbeitung starten';
    sendButton.addEventListener('click', submit);
    body.appendChild(sendButton);

    dialog.appendChild(header);
    dialog.appendChild(body);
    document.body.appendChild(dialog);
    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerEnd);
    canvas.addEventListener('pointercancel', onPointerEnd);
    document.addEventListener('pointerdown', function (event) {
      if (!active() || !barAnchor.contains(event.target)) hidePalettes();
    });
    changeColor(color);
    setTool('brush');
  }

  function togglePalette(target) {
    var open = target.hidden;
    hidePalettes();
    if (open) target.hidden = false;
    buttons.shapes.setAttribute('aria-expanded', String(!shapePalette.hidden));
    buttons.color.setAttribute('aria-expanded', String(!colorPalette.hidden));
    buttons.text.setAttribute('aria-expanded', String(!textPalette.hidden));
  }
  function hidePalettes() {
    if (!shapePalette) return;
    shapePalette.hidden = true;
    colorPalette.hidden = true;
    textPalette.hidden = true;
    ['shapes','color','text'].forEach(function (name) {
      buttons[name].setAttribute('aria-expanded', 'false');
    });
  }
  function changeColor(value) {
    if (!/^#[0-9a-f]{6}$/i.test(value)) return;
    color = value.toLowerCase();
    if (buttons.color) {
      buttons.color.style.setProperty('--selected-color', color);
      buttons.color.setAttribute('title', 'Zeichenfarbe ' + color);
    }
    paletteButtons.forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.color.toLowerCase() === color));
    });
  }
  function setTool(name) {
    tool = name;
    if (name !== 'shape' && name !== 'text') hidePalettes();
    selected = -1;
    updateControls();
    render();
  }
  function syncBrushTrack() {
    if (!brush) return;
    var min = Number(brush.min) || 8, max = Number(brush.max) || 96;
    var size = Math.max(min, Math.min(max, Number(brush.value) || min));
    brush.style.setProperty('--editor-brush-fill', ((size-min)/(max-min)*100).toFixed(1) + '%');
  }
  function pointer(event) {
    var bounds = canvas.getBoundingClientRect();
    return {
      x: Math.max(0,Math.min(canvas.width,(event.clientX-bounds.left)*canvas.width/bounds.width)),
      y: Math.max(0,Math.min(canvas.height,(event.clientY-bounds.top)*canvas.height/bounds.height))
    };
  }
  function onPointerDown(event) {
    if (!image || loading || event.button > 0) return;
    var p = pointer(event);
    hidePalettes();
    if (tool === 'text') {
      var value = String(textInput.value || '').trim();
      if (!value) {
        feedback.textContent = 'Erst einen Text eingeben, danach das Bild antippen.';
        textPalette.hidden = false;
        textInput.focus();
        return;
      }
      remember();
      annotations.push({type:'text',x:p.x,y:p.y,text:value,color:color,size:Math.max(22,brushSize+10)});
      feedback.textContent = '';
      render();
      updateControls();
      return;
    }
    if (tool === 'select') {
      selected = hitAt(p);
      if (selected >= 0) {
        remember();
        activeStroke = {type:'move',index:selected,point:p};
        try {canvas.setPointerCapture(event.pointerId);} catch (_) {}
      }
      render(); updateControls();
      return;
    }
    if (tool === 'eraser') {
      activeStroke = {type:'erase',recorded:false};
      eraseAt(p);
      try {canvas.setPointerCapture(event.pointerId);} catch (_) {}
      render();updateControls();
      return;
    }
    if (tool !== 'brush' && tool !== 'shape') return;
    remember();
    var obj = tool === 'brush' ?
      {type:'brush',color:color,width:brushSize,points:[p]} :
      {type:'shape',shape:shape,color:color,width:Math.max(3,brushSize/5),start:p,end:p};
    annotations.push(obj);
    activeStroke = {type:tool,annotation:obj};
    selected = -1;
    try {canvas.setPointerCapture(event.pointerId);} catch (_) {}
    render();updateControls();
  }
  function onPointerMove(event) {
    if (!activeStroke || !image || loading) return;
    event.preventDefault();
    var p = pointer(event);
    if (activeStroke.type === 'brush') activeStroke.annotation.points.push(p);
    else if (activeStroke.type === 'shape') activeStroke.annotation.end = p;
    else if (activeStroke.type === 'move') {
      var dx=p.x-activeStroke.point.x,dy=p.y-activeStroke.point.y;
      translate(annotations[activeStroke.index],dx,dy);
      activeStroke.point=p;
    } else if (activeStroke.type === 'erase') eraseAt(p);
    render();
  }
  function onPointerEnd(event) {
    if (!activeStroke) return;
    if (activeStroke.type === 'shape') activeStroke.annotation.end = pointer(event);
    try {canvas.releasePointerCapture(event.pointerId);} catch (_) {}
    activeStroke = null;
    render();
    updateControls();
    updatePreview();
  }
  function translate(item,dx,dy) {
    if (!item) return;
    if (item.type === 'brush') item.points.forEach(function (p) {p.x+=dx;p.y+=dy;});
    else if (item.type === 'shape') {
      item.start.x+=dx; item.start.y+=dy; item.end.x+=dx;item.end.y+=dy;
    } else if (item.type === 'text') {item.x+=dx;item.y+=dy;}
  }
  function bounds(item) {
    if (item.type === 'text') {
      return {left:item.x-8,top:item.y-item.size,right:item.x+item.text.length*item.size*.7,bottom:item.y+8};
    }
    var coords=item.type === 'brush' ? item.points : [item.start,item.end];
    return {
      left:Math.min.apply(null,coords.map(function(p){return p.x;}))-15,
      top:Math.min.apply(null,coords.map(function(p){return p.y;}))-15,
      right:Math.max.apply(null,coords.map(function(p){return p.x;}))+15,
      bottom:Math.max.apply(null,coords.map(function(p){return p.y;}))+15
    };
  }
  function hitAt(p) {
    for (var i=annotations.length-1;i>=0;i--) {
      var r=bounds(annotations[i]);
      if (p.x>=r.left&&p.x<=r.right&&p.y>=r.top&&p.y<=r.bottom) return i;
    }
    return -1;
  }
  function eraseAt(p) {
    var hit=hitAt(p);
    if (hit<0) return;
    if (activeStroke && !activeStroke.recorded) {remember();activeStroke.recorded=true;}
    annotations.splice(hit,1);
    selected=-1;
    updateControls();
  }
  function drawShape(target,item) {
    var x=item.start.x,y=item.start.y,ex=item.end.x,ey=item.end.y;
    var left=Math.min(x,ex), top=Math.min(y,ey), w=Math.abs(ex-x),h=Math.abs(ey-y);
    var cx=left+w/2,cy=top+h/2;
    target.beginPath();
    switch(item.shape) {
      case 'line':
      case 'arrow':
        target.moveTo(x,y);target.lineTo(ex,ey);
        if (item.shape==='arrow') {
          var angle=Math.atan2(ey-y,ex-x),size=Math.max(12,item.width*3);
          target.moveTo(ex,ey);
          target.lineTo(ex-size*Math.cos(angle-.55),ey-size*Math.sin(angle-.55));
          target.moveTo(ex,ey);
          target.lineTo(ex-size*Math.cos(angle+.55),ey-size*Math.sin(angle+.55));
        }
        break;
      case 'rectangle':target.rect(left,top,w,h);break;
      case 'circle':
        target.ellipse(cx,cy,Math.max(w/2,1),Math.max(h/2,1),0,0,Math.PI*2);break;
      case 'triangle':
        target.moveTo(cx,top);target.lineTo(left+w,top+h);target.lineTo(left,top+h);target.closePath();break;
      case 'diamond':
        target.moveTo(cx,top);target.lineTo(left+w,cy);target.lineTo(cx,top+h);target.lineTo(left,cy);target.closePath();break;
      case 'star':
        for(var i=0;i<10;i++) {
          var a=(-Math.PI/2)+(i*Math.PI/5),ratio=i%2===0?1:.43;
          var px=cx+Math.cos(a)*w*.5*ratio,py=cy+Math.sin(a)*h*.5*ratio;
          if(i===0)target.moveTo(px,py);else target.lineTo(px,py);
        }
        target.closePath();break;
      case 'heart':
        target.moveTo(cx,top+h);
        target.bezierCurveTo(left-w*.35,top+h*.42,left,top-h*.15,cx,top+h*.22);
        target.bezierCurveTo(left+w,top-h*.15,left+w*1.35,top+h*.42,cx,top+h);
        target.closePath();break;
    }
    target.stroke();
  }
  function drawAnnotations(target, showSelection) {
    target.save();
    target.lineCap='round';target.lineJoin='round';
    annotations.forEach(function(item,i) {
      target.strokeStyle=item.color;target.fillStyle=item.color;target.lineWidth=item.width||4;
      if(item.type==='brush') {
        var pts=item.points;
        if(pts.length===1) {
          target.beginPath();target.arc(pts[0].x,pts[0].y,item.width/2,0,Math.PI*2);target.fill();
        } else if(pts.length>1) {
          target.beginPath();target.moveTo(pts[0].x,pts[0].y);
          for(var j=1;j<pts.length;j++)target.lineTo(pts[j].x,pts[j].y);
          target.stroke();
        }
      } else if(item.type==='shape')drawShape(target,item);
      else if(item.type==='text') {
        target.font='700 '+item.size+'px Rajdhani, sans-serif';
        target.textBaseline='alphabetic';
        target.lineWidth=2;
        target.strokeStyle='rgba(0,0,0,.55)';
        target.strokeText(item.text,item.x,item.y);
        target.fillText(item.text,item.x,item.y);
      }
      if(showSelection && i===selected) {
        var rect=bounds(item);
        target.setLineDash([7,5]);target.lineWidth=2;target.strokeStyle='#e29b39';
        target.strokeRect(rect.left,rect.top,rect.right-rect.left,rect.bottom-rect.top);
        target.setLineDash([]);
      }
    });
    target.restore();
  }
  function render() {
    if (!image || !ctx) return;
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.drawImage(image,0,0,canvas.width,canvas.height);
    drawAnnotations(ctx,true);
  }
  function updatePreview() {
    if (!markThumb || !image || !annotations.length) return;
    var target=markThumb.getContext('2d'), scale=Math.min(markThumb.width/canvas.width,markThumb.height/canvas.height);
    target.clearRect(0,0,markThumb.width,markThumb.height);
    target.save();
    target.translate((markThumb.width-canvas.width*scale)/2,(markThumb.height-canvas.height*scale)/2);
    target.scale(scale,scale);
    target.drawImage(image,0,0,canvas.width,canvas.height);
    drawAnnotations(target,false);
    target.restore();
  }
  function updateControls() {
    if (!dialog) return;
    ['select','brush','text','eraser','shapes'].forEach(function(name) {
      buttons[name].setAttribute('aria-pressed',String((name==='shapes'?'shape':name)===tool));
    });
    buttons.undo.disabled=!history.length;
    buttons.redo.disabled=!undone.length;
    Array.prototype.forEach.call(shapePalette.querySelectorAll('[data-shape]'),function(button){
      button.setAttribute('aria-pressed',String(button.dataset.shape===shape));
    });
    var sizePopover=dialog.querySelector('.coreai-image-editor-size-popover');
    if(sizePopover)sizePopover.hidden=!(tool==='brush'||tool==='shape'||tool==='eraser');
    canvas.classList.toggle('is-marking',tool!=='select');
    markPreview.hidden=!annotations.length;
    if(annotations.length)updatePreview();
  }
  function active() {return !!dialog&&!dialog.hidden;}
  function close() {
    if(!active())return;
    epoch+=1;
    if(pendingOpen){pendingOpen.abort();pendingOpen=null;}
    loading=false;dialog.hidden=true;
    hidePalettes();
    annotations=[];history=[];undone=[];activeStroke=null;selected=-1;image=null;original=null;current=null;
    document.body.classList.remove('coreai-image-editor-open');
    if(opener&&opener.isConnected&&opener.focus)opener.focus();
    opener=null;
  }
  async function open(item) {
    ensure();
    if(active()||loading)return false;
    var url=safeURL(item&&item.src);
    if(!url)return false;
    opener=document.activeElement;
    epoch+=1;
    var openEpoch=epoch;
    var controller=new AbortController();
    pendingOpen=controller;
    annotations=[];history=[];undone=[];selected=-1;activeStroke=null;
    brushSize=28;brush.value='28';syncBrushTrack();
    tool='brush';shape='line';textInput.value='';
    changeColor('#ef3256');
    sizePicker.value='normal';command.value='';
    current={src:url,name:safeName(item.name),mime:String(item.mime||'')};
    feedback.textContent='Bild wird vorbereitet…';
    loading=true;sendButton.disabled=true;
    dialog.hidden=false;
    document.body.classList.add('coreai-image-editor-open');
    updateControls();
    try{
      var response=await fetch(url,{credentials:'same-origin',signal:controller.signal});
      if(!response.ok)throw new Error('HTTP '+response.status);
      var blob=await response.blob();
      if(!/^image\/(png|jpeg|webp|gif)$/i.test(blob.type))throw new Error('Nicht unterstütztes Bildformat');
      if(!active()||epoch!==openEpoch)return false;
      original=blob;
      var temp=URL.createObjectURL(blob),img=new Image();
      try{
        await new Promise(function(resolve,reject){
          img.onload=resolve;img.onerror=function(){reject(new Error('Bild kann nicht gelesen werden'));};
          img.src=temp;
        });
      }finally{URL.revokeObjectURL(temp);}
      if(!active()||epoch!==openEpoch)return false;
      image=img;
      var scale=Math.min(1,MAX_WIDTH/Math.max(img.naturalWidth,img.naturalHeight));
      canvas.width=Math.max(1,Math.round(img.naturalWidth*scale));
      canvas.height=Math.max(1,Math.round(img.naturalHeight*scale));
      render();updateControls();
      feedback.textContent='';
      return true;
    }catch(error){
      if(active()&&epoch===openEpoch&&error.name!=='AbortError')
        feedback.textContent='Bild konnte nicht geladen werden: '+(error.message||error);
      return false;
    }finally{
      if(epoch===openEpoch){pendingOpen=null;loading=false;sendButton.disabled=!image;}
    }
  }
  function toBlob(source,type,quality) {
    return new Promise(function(resolve,reject){
      source.toBlob(function(blob){if(blob)resolve(blob);else reject(new Error('Bildvorbereitung fehlgeschlagen'));},type,quality);
    });
  }
  async function filesForEdit() {
    var mainBlob=original,type=mainBlob.type,mainName=current.name;
    if(mainBlob.size>MAX_UPLOAD) {
      var resized=el('canvas');resized.width=canvas.width;resized.height=canvas.height;
      resized.getContext('2d').drawImage(image,0,0,resized.width,resized.height);
      mainBlob=await toBlob(resized,'image/jpeg',.88);
      type='image/jpeg';mainName='CoreAI-Referenz.jpg';
      if(mainBlob.size>MAX_UPLOAD)throw new Error('Referenzbild ist zu groß');
    }
    var files=[new File([mainBlob],mainName,{type:type})];
    if(annotations.length){
      var marked=el('canvas');marked.width=canvas.width;marked.height=canvas.height;
      var markedCtx=marked.getContext('2d');
      markedCtx.drawImage(image,0,0,marked.width,marked.height);
      drawAnnotations(markedCtx,false);
      var blob=await toBlob(marked,'image/png');
      if(blob.size>MAX_UPLOAD)throw new Error('Markierung ist zu groß');
      files.push(new File([blob],'CoreAI-Bereichsmarkierung.png',{type:'image/png'}));
    }
    return files;
  }
  async function submit() {
    if(!image||loading||!current)return;
    var request=String(command.value||'').trim();
    if(request.length<3){feedback.textContent='Beschreibe zuerst die gewünschte Änderung.';command.focus();return;}
    if(request.length>MAX_PROMPT){feedback.textContent='Die Beschreibung ist zu lang.';return;}
    if(!window.CoreUIComposer||typeof window.CoreUIComposer.sendImageEdit!=='function'){
      feedback.textContent='Bildbearbeitung ist in dieser Chat-Sitzung nicht verfügbar.';
      return;
    }
    var submitEpoch=epoch;
    loading=true;sendButton.disabled=true;
    feedback.textContent='Referenzbild wird vorbereitet…';
    try{
      var files=await filesForEdit();
      if(!active()||epoch!==submitEpoch)return;
      var instruction='Erzeuge eine bearbeitete Version von Referenzbild 1, kein neues unabhängiges Motiv. '
        +'Ändere: '+request+'. Identität, Körperproportionen und übrige Bildbereiche möglichst bewahren.';
      if(annotations.length)instruction+=' Referenzbild 2 enthält farbige Zeichnungen, Formen oder Text als Hinweise '
        +'auf die gewünschten Änderungen. Die Markierungen dürfen NICHT im Ergebnis erscheinen, '
        +'außer der Nutzer verlangt ausdrücklich sichtbare Schrift. Referenzbild 1 bleibt die Bildvorlage.';
      if(instruction.length>1100)throw new Error('Bearbeitungsanweisung ist zu lang');
      var result=window.CoreUIComposer.sendImageEdit(files,instruction,sizePicker.value);
      if(result!=='ok')throw new Error(({
        busy:'Mara bearbeitet gerade eine andere Anfrage.',
        editing:'Bitte erst die aktuelle Nachrichtenbearbeitung abschließen.',
        full:'Bitte die übrigen Anhänge im Chat entfernen.',
        draft:'Im Chat liegt noch ein ungesendeter Entwurf. Bitte zuerst senden oder löschen.',
        no_session:'Keine aktive Unterhaltung vorhanden.',
        invalid:'Das Bildformat ist nicht zugelassen.'
      })[result]||'Das Bild konnte nicht an den Chat übergeben werden.');
      loading=false;close();
      if(window.CoreAIImageViewer&&window.CoreAIImageViewer.isOpen())window.CoreAIImageViewer.close();
    }catch(error){
      if(active()&&epoch===submitEpoch){feedback.textContent=error.message||String(error);loading=false;sendButton.disabled=false;}
    }
  }
  document.addEventListener('keydown',function(event){
    if(!active())return;
    if(event.key==='Escape'){
      event.preventDefault();event.stopImmediatePropagation();
      if(!shapePalette.hidden||!colorPalette.hidden||!textPalette.hidden){hidePalettes();return;}
      if(!loading)close();
      return;
    }
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='z'){
      event.preventDefault();
      if(event.shiftKey)buttons.redo.click();else buttons.undo.click();
      return;
    }
    if(event.key==='Tab'){
      var all=Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), textarea, input:not([disabled]), select'))
        .filter(function(node){return node.getClientRects().length>0;});
      if(!all.length)return;
      if(event.shiftKey&&document.activeElement===all[0]){event.preventDefault();all[all.length-1].focus();}
      else if(!event.shiftKey&&document.activeElement===all[all.length-1]){event.preventDefault();all[0].focus();}
    }
  },true);
  window.addEventListener('popstate',function(){if(active()){loading=false;close();}});
  window.CoreAIImageEditor=Object.freeze({open:open,close:close,isOpen:active});
})();
