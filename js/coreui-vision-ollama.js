/* Ollama catalog adapter for the Vision Core dialog.
   Reuses the authenticated local Core library's serialized pull worker, CSRF,
   installed inventory and protections. Does NOT claim Ollama generates images
   on this Linux host or switch the active stable-diffusion.cpp renderer. */
(function () {
  'use strict';

  function isImageFamily(name) {
    if (typeof name !== 'string') return false;
    var family = name.split(':')[0].split('/').pop().toLowerCase();
    return /^flux2[-_]klein(?:[-_][a-z0-9_-]+)?$/.test(family)
      || /^z[-_]image[-_]turbo(?:[-_][a-z0-9_-]+)?$/.test(family);
  }

  function create(doc, fetchFn, scheduleFn) {
    var root = doc.getElementById('visionLibrary');
    var panel = doc.getElementById('visionLibraryOllamaPanel');
    if (!root || !panel) return null;
    var schedule = scheduleFn || function (fn, ms) { return setTimeout(fn, ms); };
    var el = {};
    ['Form','Query','Results','Installed','Detail','Back','Title',
      'Description','Variant','Install','Link','Progress','ProgressText',
      'ProgressFill','Status'].forEach(function (name) {
      el[name] = doc.getElementById('visionLibraryOllama' + name);
    });
    var state = {csrf:'', initialized:false, querySerial:0, detailSerial:0,
      detail:null, confirming:'', removing:'', installing:'', busy:false, timer:null};
    function visible() { return !root.hidden && !panel.hidden; }
    function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
    function add(node, tag, cls, value) {
      var e = doc.createElement(tag);
      if (cls) e.className = cls;
      if (value !== undefined) e.textContent = value;
      node.appendChild(e);
      return e;
    }
    function status(value, isError) {
      el.Status.textContent = value || '';
      el.Status.dataset.error = isError ? 'true' : 'false';
    }
    function api(query, payload) {
      var opts = {method:payload ? 'POST' : 'GET', credentials:'include',
        cache:'no-store', headers:{Accept:'application/json'}};
      if (payload) {
        if (!state.csrf) return Promise.reject(Error('csrf_unavailable'));
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CoreUI-CSRF'] = state.csrf;
        opts.body = JSON.stringify(payload);
      }
      return fetchFn('./api/user_settings.php' + (query ? '?' + query : ''), opts)
        .then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (data) {
            if (!response.ok || !data.ok) throw Error(data.error || 'HTTP ' + response.status);
            if (data.csrf_token) state.csrf = String(data.csrf_token);
            return data;
          });
        });
    }
    function renderInstalled(cores) {
      clear(el.Installed);
      var matches = (Array.isArray(cores) ? cores : []).filter(function (item) {
        return isImageFamily(item.name) && item.kind !== 'cloud';
      });
      if (!matches.length) {
        add(el.Installed, 'p', 'core-library-empty', 'Noch keine Ollama-Bild-Cores lokal installiert.');
        return;
      }
      matches.forEach(function (core) {
        var card = add(el.Installed, 'article', 'vision-library-model');
        var info = add(card, 'div', 'vision-library-model-copy');
        add(info, 'strong', '', core.name);
        var gb = Number(core.size_bytes || 0) / 1073741824;
        add(info, 'small', '', 'Bei Ollama installiert' + (gb ? ' · ' + gb.toFixed(1).replace('.', ',') + ' GB' : '')
          + ' · nicht als CoreAI-Bildgenerator aktiv');
        var actions = add(card, 'div', 'vision-library-actions');
        var remove = add(actions, 'button', 'core-install-btn core-install-btn--remove',
          state.removing === core.name ? 'Löschen bestätigen' : 'Deinstallieren');
        remove.type = 'button';
        remove.disabled = state.busy || core.removable === false;
        if (core.removable === false) add(info, 'small', 'vision-library-safe', core.protected_reason || 'Geschützter Core');
        remove.addEventListener('click', function () {
          if (state.busy || !core.removable) return;
          if (state.removing !== core.name) {
            state.removing = core.name;
            renderInstalled(matches);
            status('Achtung: ' + core.name + ' wird auch aus der allgemeinen Ollama-Core-Liste entfernt. Erneut bestätigen.');
            return;
          }
          state.removing = '';
          state.busy = true;
          remove.disabled = true;
          status('Deinstalliert ' + core.name + ' bei Ollama …');
          api('', {action:'installed_core_uninstall', model:core.name}).then(function () {
            state.busy = false;
            status('Ollama-Core deinstalliert. Die integrierte FLUX-Bildengine bleibt unverändert.');
            loadInstalled();
          }).catch(function (error) {
            state.busy = false;
            status('Deinstallation fehlgeschlagen: ' + error.message, true);
            loadInstalled();
          });
        });
      });
    }
    function loadInstalled() {
      return api('core_installed=1').then(function (data) {
        if (visible()) renderInstalled(data.cores);
        return data;
      }).catch(function (error) {
        if (visible()) status('Ollama-Inventar nicht erreichbar: ' + error.message, true);
      });
    }
    function renderSearch(models) {
      clear(el.Results);
      var candidates = (Array.isArray(models) ? models : []).filter(function (item) {
        return isImageFamily(item.name);
      });
      if (!candidates.length) {
        add(el.Results, 'p', 'core-library-empty',
          'Keine Ollama-Bild-Cores gefunden. Suche nach flux2-klein oder z-image.');
        return;
      }
      candidates.forEach(function (model) {
        var button = add(el.Results, 'button', 'core-library-result');
        button.type = 'button';
        add(button, 'strong', '', model.name);
        add(button, 'small', '', model.summary || 'Ollama-Katalog');
        button.addEventListener('click', function () { loadDetail(model.name); });
      });
    }
    function search() {
      var query = String(el.Query.value || '').trim();
      var serial = ++state.querySerial;
      el.Detail.hidden = true;
      state.detail = null;
      state.confirming = '';
      status('Suche nach Bild-Cores in Ollamas offiziellem Katalog …');
      return api('ollama_search=' + encodeURIComponent(query)).then(function (data) {
        if (!visible() || serial !== state.querySerial) return;
        renderSearch(data.models);
        status('Ollama-Katalog geladen. Installation ist möglich, direkte Bildgenerierung auf Linux derzeit nicht.');
      }).catch(function (error) {
        if (!visible() || serial !== state.querySerial) return;
        clear(el.Results);
        status('Ollama-Suche fehlgeschlagen: ' + error.message, true);
        if (isImageFamily(query) && /^[A-Za-z0-9._/-]+$/.test(query)) {
          var direct = add(el.Results, 'button', 'core-library-result', 'Details für ' + query + ' direkt laden');
          direct.type = 'button';
          direct.addEventListener('click', function () { loadDetail(query); });
        }
      });
    }
    function selected() {
      var variants = state.detail && state.detail.tags || [];
      return variants.filter(function (item) { return item.name === el.Variant.value; })[0] || null;
    }
    function renderVariant() {
      var item = selected();
      el.Install.disabled = !item || item.installed || state.busy;
      el.Install.textContent = !item ? 'Nicht verfügbar'
        : item.installed ? 'Bereits installiert' : state.confirming === item.name ? 'Download bestätigen'
          : 'Installieren';
    }
    function loadDetail(name) {
      if (!isImageFamily(name)) return Promise.resolve();
      var serial = ++state.detailSerial;
      status('Lädt lokale Ollama-Varianten …');
      return api('ollama_detail=' + encodeURIComponent(name)).then(function (data) {
        if (!visible() || serial !== state.detailSerial) return;
        var detail = data.detail || {name:name, tags:[]};
        state.detail = detail;
        state.confirming = '';
        el.Detail.hidden = false;
        el.Title.textContent = detail.name;
        el.Description.textContent = detail.description || 'Varianten aus Ollamas offizieller Bibliothek.';
        if (/^https:\/\/ollama\.com\//.test(detail.url || '')) el.Link.href = detail.url;
        clear(el.Variant);
        (detail.tags || []).forEach(function (variant) {
          var opt = add(el.Variant, 'option', '', variant.name + ' · ' + variant.size
            + (variant.installed ? ' · installiert' : ''));
          opt.value = variant.name;
        });
        el.Variant.disabled = !(detail.tags || []).length;
        renderVariant();
        status('Wähle eine lokale Variante. Sie wird als Ollama-Core installiert, nicht als Bildgenerator aktiviert.');
      }).catch(function (error) {
        if (visible() && serial === state.detailSerial)
          status('Ollama-Details nicht erreichbar: ' + error.message, true);
      });
    }
    function poll() {
      if (!state.installing || !visible()) return;
      api('core_install_status=1').then(function (data) {
        if (!visible() || !state.installing) return;
        var job = data.install || {};
        if (job.kind !== 'library' || job.core !== state.installing) {
          state.installing = '';
          state.busy = false;
          el.Progress.hidden = true;
          status('Installationsstatus nicht mehr verfügbar. Bitte Ollama-Inventar prüfen.', true);
          loadInstalled();
          return;
        }
        el.Progress.hidden = false;
        var percent = Math.max(0, Math.min(100, Number(job.percent) || 0));
        el.ProgressFill.style.width = percent + '%';
        el.ProgressText.textContent = (job.step || 'Ollama lädt den Core …') + ' · ' + percent + '%';
        if (job.running && job.phase !== 'error') {
          state.timer = schedule(poll, 1500);
          return;
        }
        state.busy = false;
        state.installing = '';
        el.Progress.hidden = true;
        if (job.phase === 'done') {
          status('Core wurde bei Ollama installiert. Der aktive Bildgenerator bleibt unverändert.');
          loadInstalled();
          if (state.detail) loadDetail(state.detail.name);
        } else {
          status('Ollama-Installation fehlgeschlagen: ' + (job.message || job.error || 'Unbekannter Fehler'), true);
          renderVariant();
        }
      }).catch(function (error) {
        if (visible() && state.installing) {
          status('Installationsstatus: ' + error.message, true);
          state.timer = schedule(poll, 2500);
        }
      });
    }
    function install() {
      var item = selected();
      if (!item || item.installed || state.busy) return;
      if (state.confirming !== item.name) {
        state.confirming = item.name;
        renderVariant();
        status('Download ' + item.name + ' (' + item.size + ') vom Ollama-Server bestätigen.');
        return;
      }
      state.confirming = '';
      state.busy = true;
      state.installing = item.name;
      renderVariant();
      status('Ollama-Download startet …');
      api('', {action:'ollama_library_install', model:item.name}).then(function () {
        el.Progress.hidden = false;
        state.timer = schedule(poll, 700);
      }).catch(function (error) {
        state.busy = false;
        state.installing = '';
        renderVariant();
        status('Download fehlgeschlagen: ' + error.message, true);
      });
    }
    function refresh() {
      if (!visible()) return;
      var first = !state.initialized;
      state.initialized = true;
      state.removing = '';
      loadInstalled().then(function () {
        if (!visible()) return;
        if (first) search();
        api('core_install_status=1').then(function (data) {
          var job = data.install || {};
          if (!visible() || state.installing || !job.running || job.kind !== 'library'
              || !isImageFamily(job.core)) return;
          state.installing = job.core;
          state.busy = true;
          state.timer = schedule(poll, 700);
        }).catch(function () {});
      });
    }
    el.Form.addEventListener('submit', function (event) {
      if (event && event.preventDefault) event.preventDefault();
      search();
    });
    el.Back.addEventListener('click', function () { ++state.detailSerial; el.Detail.hidden = true; });
    el.Variant.addEventListener('change', function () { state.confirming = ''; renderVariant(); });
    el.Install.addEventListener('click', install);
    return {refresh:refresh, search:search, loadDetail:loadDetail, loadInstalled:loadInstalled,
      state:function () { return {installing:state.installing, busy:state.busy, initialized:state.initialized}; }};
  }

  window.CoreUIVisionOllama = {create:create, isImageFamily:isImageFamily};
  if (window.document && window.fetch) {
    var start = function () {
      window.CoreUIVisionOllama.instance =
        create(window.document, window.fetch.bind(window));
    };
    if (window.document.readyState === 'loading')
      window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
