/* Project STΛRLIɢHT: Ember CoreUI
   Lokale Oberflaechen-Einstellungen ohne Abhaengigkeit von fremden Pfaden. */
(function () {
  'use strict';

  var STORAGE_KEY = 'emberCoreUI.preferences.v1';
  var defaults = {
    theme: 'system',
    uiLanguage: 'auto',
    // Keyboard behavior is a local presentation preference, not a model setting.
    sendKey: 'enter',
    reduceMotion: false,
    compactFeed: false,
    thinkingExpanded: false,
    liveFrameMotion: true,
    showLiveBrowser: true,
    showTelemetry: true,
    appearance: {
      separate: false, motif: 'holo', accent: 'blue', accentHex: '#5b92ff', font: 'coreai',
      light: {motif: 'holo', accent: 'blue', accentHex: '#5b92ff',
        font: 'coreai', background: '#f0f4fa', foreground: '#101626'},
      dark: {motif: 'holo', accent: 'blue', accentHex: '#91b7ff',
        font: 'coreai', background: '#0c1427', foreground: '#eef4ff'},
      uiSize: 14, contentSize: 14, codeSize: 12, motion: 'system',
      contrast: 60, smartPunctuation: false, fullWidth: false, diffMarkers: 'color'
    }
  };

  function bounded(value, min, max, fallback) {
    var n = Number(value);
    return Number.isFinite(n) ? Math.min(max, Math.max(min, Math.round(n))) : fallback;
  }
  function hex(value, fallback) {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)
      ? value.toLowerCase() : fallback;
  }
  function select(value, allowed, fallback) { return allowed.indexOf(value) >= 0 ? value : fallback; }
  function palette(src, base) {
    src = src && typeof src === 'object' ? src : {};
    return {
      motif: select(src.motif, ['holo','terminal','minimal'], base.motif),
      accent: select(src.accent, ['blue','green','violet','amber','custom'], base.accent),
      accentHex: hex(src.accentHex, base.accentHex),
      font: select(src.font, ['coreai','system','mono'], base.font),
      background: hex(src.background, base.background),
      foreground: hex(src.foreground, base.foreground)
    };
  }
  function appearanceOf(value, legacyReduce) {
    var a = value && typeof value === 'object' ? value : {};
    var defs = defaults.appearance;
    return {
      separate: a.separate === true,
      motif: select(a.motif, ['holo','terminal','minimal'], defs.motif),
      accent: select(a.accent, ['blue','green','violet','amber','custom'], defs.accent),
      accentHex: hex(a.accentHex, defs.accentHex),
      font: select(a.font, ['coreai','system','mono'], defs.font),
      light: palette(a.light, defs.light),
      dark: palette(a.dark, defs.dark),
      uiSize: bounded(a.uiSize, 10, 24, defs.uiSize),
      contentSize: bounded(a.contentSize, 10, 28, defs.contentSize),
      codeSize: bounded(a.codeSize, 10, 24, defs.codeSize),
      motion: select(a.motion, ['system','on','off'], legacyReduce === true ? 'on' : 'system'),
      contrast: bounded(a.contrast, 0, 100, defs.contrast),
      smartPunctuation: a.smartPunctuation === true,
      fullWidth: a.fullWidth === true,
      diffMarkers: select(a.diffMarkers, ['color','symbols'], defs.diffMarkers)
    };
  }

  function normalise(input) {
    var source = input && typeof input === 'object' ? input : {};
    var appearance = appearanceOf(source.appearance, source.reduceMotion);
    return {
      theme: source.theme === 'light' || source.theme === 'dark' ? source.theme : 'system',
      uiLanguage: source.uiLanguage === 'de' || source.uiLanguage === 'en' ? source.uiLanguage : 'auto',
      sendKey: source.sendKey === 'mod-enter' ? 'mod-enter' : 'enter',
      reduceMotion: appearance.motion === 'on',
      compactFeed: source.compactFeed === true,
      thinkingExpanded: source.thinkingExpanded === true,
      liveFrameMotion: source.liveFrameMotion !== false,
      showLiveBrowser: source.showLiveBrowser !== false,
      showTelemetry: source.showTelemetry !== false,
      appearance: appearance
    };
  }

  function load() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      return raw ? normalise(JSON.parse(raw)) : normalise(defaults);
    } catch (error) {
      return normalise(defaults);
    }
  }

  var systemDark = null;
  var systemReduced = null;
  try {
    if (typeof window.matchMedia === 'function') {
      systemDark = window.matchMedia('(prefers-color-scheme: dark)');
    }
  } catch (ignored) { /* Old WebViews continue in light mode. */ }

  try {
    if (typeof window.matchMedia === 'function') {
      systemReduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    }
  } catch (ignored) { /* Preference still works without OS motion detection. */ }

  function rgb(value) {
    return [1, 3, 5].map(function (pos) { return parseInt(value.slice(pos, pos + 2), 16); });
  }
  function rgbString(value, alpha) {
    var x = rgb(value);
    return 'rgba(' + x[0] + ',' + x[1] + ',' + x[2] + ',' + alpha + ')';
  }
  function mix(one, two, fraction) {
    var a = rgb(one), b = rgb(two);
    return '#' + a.map(function (v, i) {
      return Math.round(v * fraction + b[i] * (1 - fraction)).toString(16).padStart(2, '0');
    }).join('');
  }
  function accentFor(value, theme) {
    if (value.accent === 'custom') return value.accentHex;
    var choices = theme === 'dark'
      ? {blue:'#91b7ff',green:'#72e1b0',violet:'#c0adff',amber:'#f0b96b'}
      : {blue:'#5b92ff',green:'#168663',violet:'#7755cf',amber:'#ad671b'};
    return choices[value.accent] || choices.blue;
  }

  function resolvedTheme(choice) {
    return choice === 'dark' || (choice === 'system' && systemDark && systemDark.matches)
      ? 'dark' : 'light';
  }

  function apply(targetDocument, value) {
    var doc = targetDocument || document;
    var root = doc.documentElement;
    var prefs = normalise(value || load());
    if (!root) return prefs;

    var activeTheme = resolvedTheme(prefs.theme);
    root.setAttribute('data-coreui-theme', activeTheme);
    // Only a supported interface locale is used; independent of Mara's answer language.
    var browserLang = (window.navigator && window.navigator.language) || 'de';
    var interfaceLang = prefs.uiLanguage === 'auto'
      ? (/^en(?:-|$)/i.test(browserLang) ? 'en' : 'de') : prefs.uiLanguage;
    root.setAttribute('lang', interfaceLang);
    root.setAttribute('data-coreui-ui-lang', interfaceLang);
    root.setAttribute('data-coreui-theme-choice', prefs.theme);
    var a = prefs.appearance;
    var part = a[activeTheme];
    var visual = a.separate ? part : a;
    var accent = accentFor(visual, activeTheme);
    root.setAttribute('data-coreui-motif', visual.motif);
    root.setAttribute('data-coreui-font', visual.font);
    root.setAttribute('data-coreui-diff', a.diffMarkers);
    root.setAttribute('data-coreui-motion', a.motion);
    if (root.style) {
      root.style.colorScheme = activeTheme;
      if (typeof root.style.setProperty === 'function') {
        var colors = {
          '--bg': part.background, '--txt': part.foreground, '--cx': accent,
          '--line': rgbString(accent, .25), '--line-strong': rgbString(accent, .55),
          '--panel-cx': rgbString(accent, .09), '--glow-cx': '0 0 24px ' + rgbString(accent, .3),
          '--appearance-ui-scale': String(a.uiSize / 14),
          '--appearance-content-scale': String(a.contentSize / 14),
          '--appearance-code-scale': String(a.codeSize / 12)
        };
        if (a.contrast !== 60) {
          colors['--muted'] = mix(part.foreground, part.background, .38 + a.contrast * .003);
          colors['--line'] = rgbString(accent, .12 + a.contrast * .004);
        } else root.style.removeProperty('--muted');
        Object.keys(colors).forEach(function (key) { root.style.setProperty(key, colors[key]); });
      }
    }
    // Android browser chrome should match the currently displayed surface.
    var themeMeta = doc.querySelector && doc.querySelector('meta[name="theme-color"]');
    if (themeMeta) themeMeta.setAttribute('content', activeTheme === 'dark' ? '#0c1427' : '#f0f4fa');
    var schemeMeta = doc.querySelector && doc.querySelector('meta[name="color-scheme"]');
    if (schemeMeta) schemeMeta.setAttribute('content', activeTheme);

    root.classList.toggle('pref-reduced-motion',
      a.motion === 'on' || (a.motion === 'system' && !!(systemReduced && systemReduced.matches)));
    root.classList.toggle('pref-full-width', a.fullWidth);
    root.classList.toggle('pref-smart-punctuation', a.smartPunctuation);
    root.classList.toggle('pref-compact-feed', prefs.compactFeed);
    root.classList.toggle('pref-thinking-expanded', prefs.thinkingExpanded);
    root.classList.toggle('pref-live-static', !prefs.liveFrameMotion);
    root.classList.toggle('pref-hide-browser', !prefs.showLiveBrowser);
    root.classList.toggle('pref-hide-telemetry', !prefs.showTelemetry);
    return prefs;
  }

  function save(value) {
    var prefs = normalise(value);
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch (error) {}
    apply(document, prefs);
    try {
      window.dispatchEvent(new CustomEvent('coreui:preferences', { detail: prefs }));
    } catch (error) {}
    return prefs;
  }

  function reset() {
    try {
      window.localStorage.removeItem(STORAGE_KEY);
    } catch (error) {}
    return save(defaults);
  }

  window.CoreUIPreferences = Object.freeze({
    key: STORAGE_KEY,
    defaults: Object.freeze(normalise(defaults)),
    load: load,
    save: save,
    reset: reset,
    apply: apply
  });

  apply(document, load());

  function onSystemChange() {
    var prefs = load();
    if (prefs.theme === 'system') apply(document, prefs);
  }
  if (systemDark) {
    if (typeof systemDark.addEventListener === 'function') {
      systemDark.addEventListener('change', onSystemChange);
    } else if (typeof systemDark.addListener === 'function') {
      systemDark.addListener(onSystemChange);
    }
  }
  if (systemReduced) {
    var onMotionChange = function () {
      var prefs = load();
      if (prefs.appearance.motion === 'system') apply(document, prefs);
    };
    if (typeof systemReduced.addEventListener === 'function') {
      systemReduced.addEventListener('change', onMotionChange);
    } else if (typeof systemReduced.addListener === 'function') {
      systemReduced.addListener(onMotionChange);
    }
  }
  window.addEventListener('storage', function (event) {
    if (event.key !== STORAGE_KEY && event.key !== null) return;
    var prefs = load();
    apply(document, prefs);
    try {
      window.dispatchEvent(new CustomEvent('coreui:preferences', { detail: prefs }));
    } catch (ignored) {}
  });
})();
