/* CoreAI 0.6.79: one mobile-first, local-only appearance controller.
 * All reference-image controls are ordinary, not Expert settings.
 * Reuses CoreUIPreferences; never touches server models or user records.
 */
(function (root) {
  'use strict';
  var doc = root.document;
  var prefs = root.CoreUIPreferences;
  if (!doc || !prefs) return;
  var ids = ['appearanceMotif','appearanceAccent','appearanceAccentColor',
    'appearanceBackground','appearanceBackgroundColor',
    'appearanceForeground','appearanceForegroundColor','appearanceFont',
    'appearanceSeparate','appearanceUiSize','appearanceContentSize','appearanceCodeSize',
    'appearanceContrast','appearanceContrastValue','appearanceSmartPunctuation',
    'appearanceFullWidth','appearanceExport','appearanceCopy','appearanceStatus',
    'appearanceActiveMode'];
  var els = {};
  ids.forEach(function (id) { els[id] = doc.getElementById(id); });
  if (!els.appearanceMotif || !els.appearanceAccent) return;

  function currentMode() {
    return doc.documentElement.getAttribute('data-coreui-theme') === 'dark' ? 'dark' : 'light';
  }
  function toneFor(accent, custom, mode) {
    if (accent === 'custom') return custom;
    var dark = {blue:'#91b7ff',green:'#72e1b0',violet:'#c0adff',amber:'#f0b96b'};
    var light = {blue:'#5b92ff',green:'#168663',violet:'#7755cf',amber:'#ad671b'};
    return (mode === 'dark' ? dark : light)[accent] || '#5b92ff';
  }
  function getVisual(a, mode) { return a.separate ? a[mode] : a; }
  function clone(a) { return JSON.parse(JSON.stringify(a)); }
  function commit(edit) {
    var p = prefs.load();
    var a = clone(p.appearance);
    edit(a, currentMode());
    p.appearance = a;
    prefs.save(p);
  }
  function show(message, problem) {
    if (!els.appearanceStatus) return;
    els.appearanceStatus.textContent = message || '';
    els.appearanceStatus.setAttribute('data-error', problem ? 'true' : 'false');
  }
  function contrastRatio(one, two) {
    function luminance(hex) {
      return [1,3,5].map(function (i) {
        var v = parseInt(hex.slice(i, i + 2), 16) / 255;
        return v <= .04045 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4);
      }).reduce(function (sum, val, i) {return sum + val * [.2126,.7152,.0722][i];}, 0);
    }
    var x = luminance(one), y = luminance(two);
    return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);
  }
  function render() {
    var p = prefs.load(), a = p.appearance, mode = currentMode();
    var v = getVisual(a, mode), c = a[mode];
    els.appearanceMotif.value = v.motif;
    els.appearanceAccent.value = v.accent;
    els.appearanceAccentColor.value = toneFor(v.accent, v.accentHex, mode);
    els.appearanceAccentColor.title = v.accent === 'custom' ? 'Eigene Akzentfarbe bearbeiten'
      : 'Farbe anpassen und als eigene Akzentfarbe übernehmen';
    els.appearanceBackground.value = c.background.toUpperCase();
    els.appearanceBackgroundColor.value = c.background;
    els.appearanceForeground.value = c.foreground.toUpperCase();
    els.appearanceForegroundColor.value = c.foreground;
    els.appearanceFont.value = v.font;
    els.appearanceSeparate.checked = a.separate;
    els.appearanceUiSize.value = a.uiSize;
    els.appearanceContentSize.value = a.contentSize;
    els.appearanceCodeSize.value = a.codeSize;
    els.appearanceContrast.value = a.contrast;
    els.appearanceContrastValue.textContent = String(a.contrast);
    els.appearanceSmartPunctuation.checked = a.smartPunctuation;
    els.appearanceFullWidth.checked = a.fullWidth;
    doc.querySelectorAll('input[name="appearanceMotion"]').forEach(function (r) {
      r.checked = r.value === a.motion;
    });
    doc.querySelectorAll('input[name="appearanceDiffMarkers"]').forEach(function (r) {
      r.checked = r.value === a.diffMarkers;
    });
    if (els.appearanceActiveMode) {
      els.appearanceActiveMode.textContent = 'Aktuell bearbeitet: ' + (mode === 'dark' ? 'Dunkel' : 'Hell') +
        (a.separate ? ' (getrennte Motive und Akzente)' : ' (gemeinsames Motiv und Akzent)') +
        '. Die Hintergrund- und Textfarben bleiben für Hell/Dunkel getrennt, damit sie lesbar sind.';
    }
    var ratio = contrastRatio(c.background, c.foreground);
    if (ratio < 4.5) show('Hinweis: Text und Hintergrund haben nur ' +
      ratio.toFixed(1) + ':1 Kontrast. Für gute Lesbarkeit sind 4,5:1 empfehlenswert.', true);
    else if (els.appearanceStatus && els.appearanceStatus.getAttribute('data-error') === 'true') show('', false);
  }
  function setVisual(key, val) {
    commit(function (a, mode) {
      if (a.separate) a[mode][key] = val;
      else a[key] = val;
    });
  }
  function setModeColor(key, text) {
    if (!/^#[0-9a-f]{6}$/i.test(text)) {
      show('Bitte einen Hex-Farbwert im Format #RRGGBB eingeben.', true);
      render();
      return;
    }
    commit(function (a, mode) { a[mode][key] = text.toLowerCase(); });
  }
  function numeric(id, key, lo, hi) {
    var el = els[id];
    if (!el) return;
    el.addEventListener('change', function () {
      var n = Number(el.value);
      if (!Number.isFinite(n)) { render(); return; }
      commit(function (a) { a[key] = Math.max(lo,Math.min(hi,Math.round(n))); });
    });
  }
  ['motif','accent','font'].forEach(function (key) {
    var el = els['appearance' + key.charAt(0).toUpperCase() + key.slice(1)];
    el.addEventListener('change', function () { setVisual(key, el.value); });
  });
  els.appearanceAccentColor.addEventListener('input', function () {
    commit(function (a, mode) {
      if (a.separate) { a[mode].accent = 'custom'; a[mode].accentHex = els.appearanceAccentColor.value; }
      else { a.accent = 'custom'; a.accentHex = els.appearanceAccentColor.value; }
    });
  });
  [['appearanceBackground','background'],['appearanceForeground','foreground']].forEach(function (pair) {
    var textInput = els[pair[0]];
    var picker = els[pair[0] + 'Color'];
    textInput.addEventListener('change', function () { setModeColor(pair[1], textInput.value.trim()); });
    picker.addEventListener('input', function () { setModeColor(pair[1], picker.value); });
  });
  els.appearanceSeparate.addEventListener('change', function () {
    var on = els.appearanceSeparate.checked;
    commit(function (a, mode) {
      if (on && !a.separate) {
        ['light','dark'].forEach(function (name) {
          a[name].motif = a.motif;
          a[name].accent = a.accent;
          a[name].accentHex = a.accentHex;
          a[name].font = a.font;
        });
      } else if (!on && a.separate) {
        a.motif = a[mode].motif;
        a.accent = a[mode].accent;
        a.accentHex = a[mode].accentHex;
        a.font = a[mode].font;
      }
      a.separate = on;
    });
  });
  numeric('appearanceUiSize','uiSize',10,24);
  numeric('appearanceContentSize','contentSize',10,28);
  numeric('appearanceCodeSize','codeSize',10,24);
  els.appearanceContrast.addEventListener('input', function () {
    els.appearanceContrastValue.textContent = els.appearanceContrast.value;
    commit(function (a) { a.contrast = Number(els.appearanceContrast.value); });
  });
  [['appearanceSmartPunctuation','smartPunctuation'],['appearanceFullWidth','fullWidth']].forEach(function (pair) {
    els[pair[0]].addEventListener('change', function () {
      commit(function (a) { a[pair[1]] = els[pair[0]].checked; });
    });
  });
  doc.querySelectorAll('input[name="appearanceMotion"]').forEach(function (el) {
    el.addEventListener('change', function () {
      if (el.checked) commit(function (a) { a.motion = el.value; });
    });
  });
  doc.querySelectorAll('input[name="appearanceDiffMarkers"]').forEach(function (el) {
    el.addEventListener('change', function () {
      if (el.checked) commit(function (a) { a.diffMarkers = el.value; });
    });
  });
  els.appearanceCopy.addEventListener('click', function () {
    var mode = currentMode();
    commit(function (a) {
      var v = getVisual(a, mode), other = mode === 'dark' ? 'light' : 'dark';
      ['motif','accent','accentHex','font'].forEach(function (key) {
        a[other][key] = v[key];
      });
      a[mode].motif = v.motif; a[mode].accent = v.accent;
      a[mode].accentHex = v.accentHex; a[mode].font = v.font;
      a.separate = true;
    });
    show('Motiv, Akzent und Schriftmotiv in den anderen Modus kopiert. Hintergrund/Textfarbe bleiben für Lesbarkeit erhalten.', false);
  });
  els.appearanceExport.addEventListener('click', function () {
    var p = prefs.load();
    var data = {format:'coreai-appearance',version:1,theme:p.theme,appearance:p.appearance};
    if (typeof root.Blob !== 'function' || !root.URL || typeof root.URL.createObjectURL !== 'function') {
      show('In diesem Browser ist der Datei-Export leider nicht verfügbar.', true);
      return;
    }
    var url = root.URL.createObjectURL(new root.Blob([JSON.stringify(data,null,2)],{type:'application/json'}));
    var anchor = doc.createElement('a');
    anchor.href = url;
    anchor.download = 'coreai-motiv.json';
    anchor.hidden = true;
    doc.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    root.setTimeout(function () { root.URL.revokeObjectURL(url); }, 1000);
    show('Motiv als JSON exportiert (ohne Konto- oder Chatdaten).', false);
  });
  root.addEventListener('coreui:preferences', render);
  render();
  root.CoreUIAppearanceSettings = {render:render, contrastRatio:contrastRatio};
})(window);
