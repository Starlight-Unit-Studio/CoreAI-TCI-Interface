/* Project STΛRLIɢHT: Ember CoreUI
   Lokale Oberflaechen-Einstellungen ohne Abhaengigkeit von fremden Pfaden. */
(function () {
  'use strict';

  var STORAGE_KEY = 'emberCoreUI.preferences.v1';
  var defaults = {
    theme: 'system',
    reduceMotion: false,
    compactFeed: false,
    thinkingExpanded: false,
    liveFrameMotion: true,
    showLiveBrowser: true,
    showTelemetry: true
  };

  function normalise(input) {
    var source = input && typeof input === 'object' ? input : {};
    return {
      theme: source.theme === 'light' || source.theme === 'dark' ? source.theme : 'system',
      reduceMotion: source.reduceMotion === true,
      compactFeed: source.compactFeed === true,
      thinkingExpanded: source.thinkingExpanded === true,
      liveFrameMotion: source.liveFrameMotion !== false,
      showLiveBrowser: source.showLiveBrowser !== false,
      showTelemetry: source.showTelemetry !== false
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
    root.setAttribute('data-coreui-theme-choice', prefs.theme);
    if (root.style) root.style.colorScheme = activeTheme;
    // Android browser chrome should match the currently displayed surface.
    var themeMeta = doc.querySelector && doc.querySelector('meta[name="theme-color"]');
    if (themeMeta) themeMeta.setAttribute('content', activeTheme === 'dark' ? '#0c1427' : '#f0f4fa');
    var schemeMeta = doc.querySelector && doc.querySelector('meta[name="color-scheme"]');
    if (schemeMeta) schemeMeta.setAttribute('content', activeTheme);

    root.classList.toggle('pref-reduced-motion', prefs.reduceMotion);
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
  window.addEventListener('storage', function (event) {
    if (event.key !== STORAGE_KEY && event.key !== null) return;
    var prefs = load();
    apply(document, prefs);
    try {
      window.dispatchEvent(new CustomEvent('coreui:preferences', { detail: prefs }));
    } catch (ignored) {}
  });
})();
