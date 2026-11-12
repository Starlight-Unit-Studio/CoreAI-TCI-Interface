/* Project STΛRLIɢHT: Ember CoreUI
   Lokale Oberflaechen-Einstellungen ohne Abhaengigkeit von fremden Pfaden. */
(function () {
  'use strict';

  var STORAGE_KEY = 'emberCoreUI.preferences.v1';
  var defaults = {
    theme: 'system',
    uiSkin: 'coreai', // Independent of the light/dark colour scheme.
    uiLanguage: 'auto',
    // Keyboard behavior is a local presentation preference, not a model setting.
    sendKey: 'enter',
    reduceMotion: false,
    compactFeed: false,
    thinkingExpanded: false,
    liveFrameMotion: true,
    showLiveBrowser: true,
    showTelemetry: true,
    motif: 'holo', accent: 'blue',
    backgroundLight: '#f0f4fa', foregroundLight: '#101626',
    backgroundDark: '#0c1427', foregroundDark: '#eef4ff',
    uiFontSize: 14, contentFontSize: 16, codeFontSize: 13,
    contrast: 60, motionMode: 'system', fullWidth: false,
    diffMarkers: 'color',
    // 0.6.80: font family, separate light/dark look, smart punctuation in the chat input.
    fontFamily: 'coreai', separateModes: false,
    motifDark: 'holo', accentDark: 'blue', fontFamilyDark: 'coreai',
    smartPunctuation: false
  };
  var ACCENTS = ['blue','cyan','green','violet','amber'];
  // 0.6.80: CSS uses var(--coreui-font, "Rajdhani"); monospace labels keep Share Tech Mono.
  var FONT_STACKS = {
    coreai: '"Rajdhani"',
    system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial'
  };
  var MOTIF_FORMAT = 'coreai-motiv';
  var MOTIF_MAX_CHARS = 4000;

  function safeHex(input, fallback) {
    return typeof input === 'string' && /^#[0-9a-fA-F]{6}$/.test(input) ? input.toLowerCase() : fallback;
  }
  function boundedNumber(input, fallback, min, max, step) {
    var number = Number(input);
    return Number.isFinite(number) && number >= min && number <= max
      ? Math.round(number / step) * step : fallback;
  }
  function mixHex(a, b, weight) {
    function ch(hex, offset) { return parseInt(hex.slice(offset, offset + 2), 16); }
    var out = '#';
    for (var i = 1; i <= 5; i += 2) {
      out += Math.round(ch(a, i) * (1 - weight) + ch(b, i) * weight).toString(16).padStart(2, '0');
    }
    return out;
  }

  function pickMotif(value, fallback) { return value === 'quiet' ? 'quiet' : (value === 'holo' ? 'holo' : fallback); }
  function pickAccent(value, fallback) { return ACCENTS.indexOf(value) >= 0 ? value : fallback; }
  function pickFont(value, fallback) { return value === 'system' ? 'system' : (value === 'coreai' ? 'coreai' : fallback); }

  function normalise(input) {
    var source = input && typeof input === 'object' ? input : {};
    var motif = pickMotif(source.motif, 'holo');
    var accent = pickAccent(source.accent, 'blue');
    var fontFamily = pickFont(source.fontFamily, 'coreai');
    return {
      theme: source.theme === 'light' || source.theme === 'dark' ? source.theme : 'system',
      uiSkin: source.uiSkin === 'softglass' ? 'softglass' : 'coreai',
      uiLanguage: source.uiLanguage === 'de' || source.uiLanguage === 'en' ? source.uiLanguage : 'auto',
      sendKey: source.sendKey === 'mod-enter' ? 'mod-enter' : 'enter',
      reduceMotion: source.reduceMotion === true,
      compactFeed: source.compactFeed === true,
      thinkingExpanded: source.thinkingExpanded === true,
      liveFrameMotion: source.liveFrameMotion !== false,
      showLiveBrowser: source.showLiveBrowser !== false,
      showTelemetry: source.showTelemetry !== false,
      motif: motif,
      accent: accent,
      backgroundLight: safeHex(source.backgroundLight, '#f0f4fa'),
      foregroundLight: safeHex(source.foregroundLight, '#101626'),
      backgroundDark: safeHex(source.backgroundDark, '#0c1427'),
      foregroundDark: safeHex(source.foregroundDark, '#eef4ff'),
      uiFontSize: boundedNumber(source.uiFontSize, 14, 12, 24, 1),
      contentFontSize: boundedNumber(source.contentFontSize, 16, 12, 26, 1),
      codeFontSize: boundedNumber(source.codeFontSize, 13, 10, 22, 1),
      contrast: boundedNumber(source.contrast, 60, 40, 100, 5),
      motionMode: ['system','on','off'].indexOf(source.motionMode) >= 0
        ? source.motionMode : (source.reduceMotion === true ? 'on' : 'system'),
      fullWidth: source.fullWidth === true,
      diffMarkers: source.diffMarkers === 'symbols' ? 'symbols' : 'color',
      fontFamily: fontFamily,
      separateModes: source.separateModes === true,
      // Without its own value the dark look follows the shared one.
      motifDark: pickMotif(source.motifDark, motif),
      accentDark: pickAccent(source.accentDark, accent),
      fontFamilyDark: pickFont(source.fontFamilyDark, fontFamily),
      smartPunctuation: source.smartPunctuation === true
    };
  }

  /** 0.6.80: the look of one mode (pure). Shared unless "Hell und Dunkel getrennt" is on. */
  function modeLook(value, mode) {
    var prefs = normalise(value);
    var dark = mode === 'dark';
    var own = dark && prefs.separateModes;
    return {
      motif: own ? prefs.motifDark : prefs.motif,
      accent: own ? prefs.accentDark : prefs.accent,
      fontFamily: own ? prefs.fontFamilyDark : prefs.fontFamily,
      background: dark ? prefs.backgroundDark : prefs.backgroundLight,
      foreground: dark ? prefs.foregroundDark : prefs.foregroundLight
    };
  }

  /** 0.6.80: the motif as a portable object (pure): design, accent, font and colors per mode. */
  function exportMotif(value) {
    var prefs = normalise(value);
    function part(mode) {
      var look = modeLook(prefs, mode);
      return {motif: look.motif, accent: look.accent, font: look.fontFamily,
        background: look.background, foreground: look.foreground};
    }
    return {format: MOTIF_FORMAT, version: 1, separateModes: prefs.separateModes,
      light: part('light'), dark: part('dark')};
  }

  /**
   * 0.6.80: preferences with an imported motif (pure), or null. Accepts the object or its JSON
   * text (at most 4000 characters); only known fields are taken, every value is validated.
   * Everything that is not part of a motif (fonts sizes, motion, send key ...) stays as it is.
   */
  function importMotif(input, current) {
    var data = input;
    if (typeof input === 'string') {
      if (input.length > MOTIF_MAX_CHARS) return null;
      try { data = JSON.parse(input); } catch (error) { return null; }
    }
    if (!data || typeof data !== 'object' || data.format !== MOTIF_FORMAT || data.version !== 1) return null;
    var light = data.light && typeof data.light === 'object' ? data.light : null;
    var dark = data.dark && typeof data.dark === 'object' ? data.dark : null;
    if (!light || !dark) return null;
    var hex = /^#[0-9a-fA-F]{6}$/;
    var parts = [light.background, light.foreground, dark.background, dark.foreground];
    for (var i = 0; i < parts.length; i++) if (typeof parts[i] !== 'string' || !hex.test(parts[i])) return null;
    if (pickMotif(light.motif, null) === null || pickAccent(light.accent, null) === null || pickFont(light.font, null) === null) return null;
    var separate = data.separateModes === true;
    if (separate && (pickMotif(dark.motif, null) === null || pickAccent(dark.accent, null) === null
      || pickFont(dark.font, null) === null)) return null;
    var next = Object.assign({}, normalise(current), {
      motif: light.motif, accent: light.accent, fontFamily: light.font,
      separateModes: separate,
      motifDark: separate ? dark.motif : light.motif,
      accentDark: separate ? dark.accent : light.accent,
      fontFamilyDark: separate ? dark.font : light.font,
      backgroundLight: light.background, foregroundLight: light.foreground,
      backgroundDark: dark.background, foregroundDark: dark.foreground
    });
    return normalise(next);
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
  try {
    if (typeof window.matchMedia === 'function') {
      systemDark = window.matchMedia('(prefers-color-scheme: dark)');
    }
  } catch (ignored) { /* Old WebViews continue in light mode. */ }

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
    root.setAttribute('data-coreui-skin', prefs.uiSkin);
    // Only a supported interface locale is used; independent of Mara's answer language.
    var browserLang = (window.navigator && window.navigator.language) || 'de';
    var interfaceLang = prefs.uiLanguage === 'auto'
      ? (/^en(?:-|$)/i.test(browserLang) ? 'en' : 'de') : prefs.uiLanguage;
    root.setAttribute('lang', interfaceLang);
    root.setAttribute('data-coreui-ui-lang', interfaceLang);
    root.setAttribute('data-coreui-theme-choice', prefs.theme);
    if (root.style) root.style.colorScheme = activeTheme;
    // Android browser chrome should match the currently displayed surface.
    var themeMeta = doc.querySelector && doc.querySelector('meta[name="theme-color"]');
    if (themeMeta) themeMeta.setAttribute('content', activeTheme === 'dark' ? '#0c1427' : '#f0f4fa');
    var schemeMeta = doc.querySelector && doc.querySelector('meta[name="color-scheme"]');
    if (schemeMeta) schemeMeta.setAttribute('content', activeTheme);

    var reduce = prefs.motionMode === 'on'
      || (prefs.motionMode === 'system' && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true);
    root.classList.toggle('pref-reduced-motion', reduce);
    var look = modeLook(prefs, activeTheme);
    root.classList.toggle('pref-motif-quiet', look.motif === 'quiet');
    root.classList.toggle('pref-font-system', look.fontFamily === 'system');
    root.classList.toggle('pref-full-width', prefs.fullWidth);
    root.classList.toggle('pref-diff-symbols', prefs.diffMarkers === 'symbols');
    var colors = {background: look.background, foreground: look.foreground};
    root.classList.toggle('pref-custom-background', colors.background !==
      (activeTheme === 'dark' ? '#0c1427' : '#f0f4fa'));
    var accents = {blue: activeTheme === 'dark' ? '#91b7ff' : '#5b92ff',
      cyan: '#13a6b5', green: '#299b70', violet: '#8a6ff2', amber: '#ca852a'};
    if (root.style && typeof root.style.setProperty === 'function') {
      root.style.setProperty('--bg', colors.background);
      root.style.setProperty('--cx', accents[look.accent]);
      var accent = accents[look.accent];
      var rgb = [1,3,5].map(function (i) { return parseInt(accent.slice(i, i+2), 16); }).join(',');
      root.style.setProperty('--line', 'rgba(' + rgb + ',.25)');
      root.style.setProperty('--line-strong', 'rgba(' + rgb + ',.52)');
      root.style.setProperty('--panel-cx', 'rgba(' + rgb + ',.10)');
      root.style.setProperty('--pref-ui-font', prefs.uiFontSize + 'px');
      root.style.setProperty('--pref-content-font', prefs.contentFontSize + 'px');
      root.style.setProperty('--pref-code-font', prefs.codeFontSize + 'px');
      root.style.setProperty('--coreui-font', FONT_STACKS[look.fontFamily]);
      var strength = (prefs.contrast - 60) / 40;
      var textColor = strength < 0
        ? mixHex(colors.foreground, colors.background, -strength * .38)
        : mixHex(colors.foreground, activeTheme === 'dark' ? '#ffffff' : '#000000', strength * .45);
      root.style.setProperty('--txt', textColor);
      root.style.setProperty('--pref-surface', colors.background);
    }
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
    apply: apply,
    resolvedTheme: resolvedTheme,
    modeLook: modeLook,
    exportMotif: exportMotif,
    importMotif: importMotif,
    motifFormat: MOTIF_FORMAT
  });

  apply(document, load());

  function onSystemChange() {
    var prefs = load();
    if (prefs.theme === 'system' || prefs.motionMode === 'system') apply(document, prefs);
  }
  if (systemDark) {
    if (typeof systemDark.addEventListener === 'function') {
      systemDark.addEventListener('change', onSystemChange);
    } else if (typeof systemDark.addListener === 'function') {
      systemDark.addListener(onSystemChange);
    }
  }
  // Motion follows the device independently of the color scheme.
  var systemReducedMotion = null;
  try {
    if (typeof window.matchMedia === 'function') {
      systemReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    }
  } catch (ignored) { /* older WebViews */ }
  if (systemReducedMotion) {
    if (typeof systemReducedMotion.addEventListener === 'function') {
      systemReducedMotion.addEventListener('change', onSystemChange);
    } else if (typeof systemReducedMotion.addListener === 'function') {
      systemReducedMotion.addListener(onSystemChange);
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
