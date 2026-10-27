/* CoreAI 0.5.70 (#113): context window slider in the KI-Profil.
 * Discrete stops (4K to 128K) plus "Core-Standard übernehmen". Every stop
 * shows a rounded memory guide value for the selected Core; nothing here
 * changes the Modelfile or Ollama. Text only, never injected HTML. */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function shortTokens(n) {
    n = Number(n) || 0;
    return n >= 1024 ? Math.round(n / 1024) + 'K' : String(n);
  }

  function fullTokens(n) {
    return Number(n).toLocaleString('de-DE') + ' Tokens';
  }

  function gb(value) {
    return Number(value).toLocaleString('de-DE', {maximumFractionDigits: 1});
  }

  /** "ca. 18 GB RAM", "etwa 18 bis 21 GB RAM" or null when not estimable. */
  function guide(stop, label) {
    if (!stop || stop.low_gb == null || stop.high_gb == null) return null;
    var unit = ' GB ' + (label || 'Arbeitsspeicher');
    return Number(stop.low_gb) === Number(stop.high_gb)
      ? 'ca. ' + gb(stop.low_gb) + unit
      : 'etwa ' + gb(stop.low_gb) + ' bis ' + gb(stop.high_gb) + unit;
  }

  function create(opts) {
    var root = opts.root;
    var range = opts.range;
    var output = opts.output;
    var useDefault = opts.useDefault;
    var stopsList = opts.stops;
    var estimate = opts.estimate;
    var warning = opts.warning;
    var status = opts.status;
    var fetchJson = opts.fetchJson;
    var choices = [4096, 8192, 16384, 32768, 65536, 131072];
    var saved = null;
    var available = true;
    var info = null;
    var model = '';
    var token = 0;
    var lastChoice = 32768;

    function index() {
      var i = Math.round(Number(range.value) || 0);
      return Math.max(0, Math.min(choices.length - 1, i));
    }

    function nearest(tokens) {
      var best = 0;
      choices.forEach(function (c, i) {
        if (Math.abs(c - tokens) < Math.abs(choices[best] - tokens)) best = i;
      });
      return best;
    }

    /** The value saving sends: null keeps the Core default. */
    function value() {
      return useDefault.checked ? null : choices[index()];
    }

    function stopFor(tokens) {
      var rows = info && info.stops ? info.stops : [];
      for (var i = 0; i < rows.length; i++) if (Number(rows[i].tokens) === tokens) return rows[i];
      return null;
    }

    function render() {
      var cloud = !!(info && info.applicable === false);
      var label = info && info.memory_label ? info.memory_label : 'Arbeitsspeicher';
      var chosen = value();
      root.classList.toggle('ctx-cloud', cloud);
      root.classList.toggle('ctx-default', chosen === null);
      useDefault.disabled = cloud || !available;
      range.disabled = cloud || !available || chosen === null;
      range.max = String(choices.length - 1);
      output.textContent = cloud ? 'nicht anwendbar'
        : chosen === null ? 'Core-Standard' : shortTokens(chosen);

      stopsList.textContent = '';
      choices.forEach(function (tokens, i) {
        var stop = stopFor(tokens);
        var text = guide(stop, label);
        var item = el('li', 'ctx-stop');
        var button = el('button', 'ctx-stop-button');
        button.type = 'button';
        button.disabled = range.disabled;
        button.appendChild(el('b', '', shortTokens(tokens)));
        button.appendChild(el('span', '', cloud ? 'nicht anwendbar'
          : !info ? 'wird ermittelt' : (text || 'nicht schätzbar')));
        if (stop && stop.warn) button.appendChild(el('em', '', 'zu wenig ' + (label === 'VRAM' ? 'VRAM' : 'RAM') + '?'));
        if (stop && stop.beyond_model) button.appendChild(el('em', '', 'über Trainingsfenster'));
        button.setAttribute('aria-pressed', String(chosen === tokens));
        button.addEventListener('click', function () {
          range.value = String(i);
          lastChoice = tokens;
          render();
        });
        item.className = 'ctx-stop' + (chosen === tokens ? ' active' : '')
          + (stop && stop.warn ? ' warn' : '');
        item.appendChild(button);
        stopsList.appendChild(item);
      });

      var current = chosen === null ? (info && info.default) : stopFor(chosen);
      var text = guide(current, label);
      if (!available) {
        estimate.textContent = 'Kontextfenster erst nach Migration 017 wählbar. Bis dahin gilt der Core-Standard.';
      } else if (cloud) {
        estimate.textContent = 'Für Cloud-Cores nicht anwendbar: das Kontextfenster bestimmt der Cloud-Anbieter. '
          + 'CoreAI sendet dorthin nie num_ctx.';
      } else if (!info) {
        estimate.textContent = 'geschätzt: wird für diesen Core ermittelt';
      } else if (chosen === null) {
        estimate.textContent = info.default_context
          ? 'Core-Standard: ' + fullTokens(info.default_context) + ' · geschätzt: '
            + (text || (label === 'RAM' ? 'RAM-Bedarf' : 'Speicherbedarf') + ' derzeit nicht zuverlässig schätzbar')
          : 'Core-Standard: Fenster aus dem Modelfile dieses Core, derzeit nicht ermittelbar';
      } else {
        estimate.textContent = 'geschätzt: ' + (text
          || (label === 'RAM' ? 'RAM-Bedarf' : 'Speicherbedarf') + ' derzeit nicht zuverlässig schätzbar');
      }
      var warn = !cloud && current && current.warn;
      warning.hidden = !warn;
      warning.textContent = 'Möglicherweise zu wenig ' + (label === 'VRAM' ? 'VRAM' : 'RAM')
        + ' für diese Einstellung' + (info && info.available_gb ? ' (verfügbar etwa ' + gb(info.available_gb) + ' GB)' : '')
        + '. Nichts wird gesperrt; im Zweifel ein kleineres Fenster wählen.';

      // "gewählt", "verwendet" and "geladen" are three different facts.
      if (cloud) {
        status.textContent = 'gewählt: ' + (saved === null ? 'Core-Standard' : shortTokens(saved))
          + ' · gilt nur für lokale Cores';
      } else {
        var used = saved !== null ? fullTokens(saved)
          : info && info.default_context ? fullTokens(info.default_context) : 'Core-Standard';
        status.textContent = 'gewählt: ' + (saved === null ? 'Core-Standard' : shortTokens(saved))
          + ' · für Anfragen verwendet: ' + used
          + ' · von Ollama geladen: ' + (info && info.loaded_context ? fullTokens(info.loaded_context) : 'derzeit nicht geladen')
          + (chosen !== saved ? ' · ungespeicherte Änderung' : '');
      }
    }

    range.addEventListener('input', function () { lastChoice = choices[index()]; render(); });
    useDefault.addEventListener('change', function () {
      if (!useDefault.checked) {
        var start = saved !== null ? saved
          : info && info.default_context ? info.default_context : lastChoice;
        range.value = String(nearest(start));
      }
      render();
    });

    return {
      /** Saved settings from api/user_settings.php. */
      apply: function (savedValue, allowed, isAvailable) {
        if (allowed && allowed.length) choices = allowed.map(Number);
        available = isAvailable !== false;
        saved = savedValue == null ? null : Number(savedValue);
        useDefault.checked = saved === null;
        if (saved !== null) {
          lastChoice = saved;
          range.value = String(nearest(saved));
        }
        render();
      },
      /** Memory guide values for one Core; a late answer never wins. */
      load: function (name) {
        model = String(name || '');
        var mine = ++token;
        info = null;
        render();
        return fetchJson('./api/user_settings.php?context_model=' + encodeURIComponent(model))
          .then(function (data) {
            if (mine !== token) return;
            info = data && data.context ? data.context : {stops: []};
            if (useDefault.checked && info.default_context) range.value = String(nearest(info.default_context));
            render();
          })
          .catch(function () {
            if (mine !== token) return;
            info = {stops: [], applicable: true};
            render();
          });
      },
      value: value,
      model: function () { return model; }
    };
  }

  window.CoreUIContextSlider = {create: create, guide: guide, shortTokens: shortTokens};
})();
