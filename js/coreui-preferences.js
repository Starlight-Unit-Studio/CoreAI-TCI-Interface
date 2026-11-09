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
    motif: 'holo', accent: 'blue',
    backgroundLight: '#f0f4fa', foregroundLight: '#101626',
    backgroundDark: '#0c1427', foregroundDark: '#eef4ff',
    uiFontSize: 14, contentFontSize: 16, codeFontSize: 13,
    contrast: 60, motionMode: 'system', fullWidth: false,
    diffMarkers: 'color'
  };

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

  function normalise(input) {
    var source = input && typeof input === 'object' ? input : {};
    return {
      theme: source.theme === 'light' || source.theme === 'dark' ? source.theme : 'system',
      uiLanguage: source.uiLanguage === 'de' || source.uiLanguage === 'en' ? source.uiLanguage : 'auto',
      sendKey: source.sendKey === 'mod-enter' ? 'mod-enter' : 'enter',
      reduceMotion: source.reduceMotion === true,
      compactFeed: source.compactFeed === true,
      thinkingExpanded: source.thinkingExpanded === true,
      liveFrameMotion: source.liveFrameMotion !== false,
      showLiveBrowser: source.showLiveBrowser !== false,
      showTelemetry: source.showTelemetry !== false,
      motif: source.motif === 'quiet' ? 'quiet' : 'holo',
      accent: ['blue','cyan','green','violet','amber'].indexOf(source.accent) >= 0 ? source.accent : 'blue',
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
      diffMarkers: source.diffMarkers === 'symbols' ? 'symbols' : 'color'
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
    root.classList.toggle('pref-motif-quiet', prefs.motif === 'quiet');
    root.classList.toggle('pref-full-width', prefs.fullWidth);
    root.classList.toggle('pref-diff-symbols', prefs.diffMarkers === 'symbols');
    var colors = activeTheme === 'dark'
      ? {background: prefs.backgroundDark, foreground: prefs.foregroundDark}
      : {background: prefs.backgroundLight, foreground: prefs.foregroundLight};
    var accents = {blue: activeTheme === 'dark' ? '#91b7ff' : '#5b92ff',
      cyan: '#13a6b5', green: '#299b70', violet: '#8a6ff2', amber: '#ca852a'};
    if (root.style && typeof root.style.setProperty === 'function') {
      root.style.setProperty('--bg', colors.background);
      root.style.setProperty('--cx', accents[prefs.accent]);
      root.style.setProperty('--pref-ui-font', prefs.uiFontSize + 'px');
      root.style.setProperty('--pref-content-font', prefs.contentFontSize + 'px');
      root.style.setProperty('--pref-code-font', prefs.codeFontSize + 'px');
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
    apply: apply
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
  window.addEventListener('storage', function (event) {
    if (event.key !== STORAGE_KEY && event.key !== null) return;
    var prefs = load();
    apply(document, prefs);
    try {
      window.dispatchEvent(new CustomEvent('coreui:preferences', { detail: prefs }));
    } catch (ignored) {}
  });
})();
