(function () {
  'use strict';
  function create(doc, fetchFn) {
    var dialog = doc.getElementById('visionLibrary');
    var opener = doc.getElementById('coreInstallVisionOpen');
    if (!dialog || !opener) return null;
    var state = {open:false, tab:'search', csrf:'', installed:[], found:[], busy:false,
      installing:'', confirm:'', requestId:0, poll:null};
    var byId = function (id) { return doc.getElementById('visionLibrary' + id); };
    var el = {close:byId('Close'),status:byId('Status'),searchTab:byId('SearchTab'),
      installedTab:byId('InstalledTab'),searchPanel:byId('SearchPanel'),
      installedPanel:byId('InstalledPanel'),query:byId('Query'),
      searchForm:byId('SearchForm'),results:byId('Results'),installedList:byId('InstalledList')};
    function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
    function add(parent, tag, cls, caption) {
      var element = doc.createElement(tag);
      if (cls) element.className = cls;
      if (caption !== undefined) element.textContent = caption;
      parent.appendChild(element);
      return element;
    }
    function status(message, error) {
      el.status.textContent = message;
      el.status.dataset.error = error ? 'true' : 'false';
    }
    function request(query, payload) {
      var opts = {method:payload ? 'POST' : 'GET', credentials:'include',
        cache:'no-store', headers:{'Accept':'application/json'}};
      if (payload) {
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CoreUI-CSRF'] = state.csrf;
        opts.body = JSON.stringify(payload);
      }
      return fetchFn('./api/vision_cores.php' + (query || ''), opts).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data.ok) throw Error(data.error || 'HTTP ' + response.status);
          if (data.csrf_token) state.csrf = data.csrf_token;
          return data;
        });
      });
    }
    function drawModel(parent, item, installed) {
      var box = add(parent, 'article', 'vision-library-model');
      var info = add(box, 'div', 'vision-library-model-copy');
      add(info, 'strong', '', item.name);
      add(info, 'small', '', installed ? (item.active ? 'Aktiver Vision Core' : 'Lokal installiert')
        : item.repo + ' / ' + item.path);
      if (item.size) add(info, 'small', '', (item.size / 1073741824).toFixed(1).replace('.', ',') + ' GB · ' + item.license);
      if (item.protected) add(info, 'small', 'vision-library-safe', 'Standard-Core · dauerhaft geschützt');
      var actions = add(box, 'div', 'vision-library-actions');
      if (!installed) {
        var install = add(actions, 'button', 'core-install-btn', state.confirm === item.id
          ? 'Download bestätigen' : item.installed ? 'Installiert' : 'Installieren');
        install.type = 'button'; install.disabled = state.busy || item.installed;
        install.addEventListener('click', function () {
          if (state.confirm !== item.id) {
            state.confirm = item.id;
            render();
            status('Download aus Hugging Face bestätigen: ' + item.name);
            return;
          }
          mutate('install', item.id);
        });
      } else {
        var choose = add(actions, 'button', 'core-install-btn' + (item.active ? ' core-install-btn--go' : ''),
          item.active ? 'Aktiv' : 'Als Vision Core verwenden');
        choose.type = 'button'; choose.disabled = item.active || state.busy;
        choose.addEventListener('click', function () { mutate('select', item.id); });
        if (!item.protected) {
          var remove = add(actions, 'button', 'core-install-btn core-install-btn--remove',
            state.confirm === item.id ? 'Löschen bestätigen' : 'Deinstallieren');
          remove.type = 'button'; remove.disabled = item.active || state.busy;
          remove.addEventListener('click', function () {
            if (state.confirm !== item.id) { state.confirm = item.id; render(); return; }
            mutate('uninstall', item.id);
          });
        }
      }
    }
    function render() {
      clear(el.results);
      clear(el.installedList);
      if (!state.found.length) add(el.results, 'p', '', 'Keine kompatiblen Vision Cores gefunden.');
      state.found.forEach(function (item) { drawModel(el.results, item, false); });
      state.installed.forEach(function (item) { drawModel(el.installedList, item, true); });
    }
    function refresh(silent) {
      return request('').then(function (data) {
        if (!state.open) return;
        state.installed = Array.isArray(data.models) ? data.models : [];
        state.installing = data.installing || '';
        state.busy = !!state.installing;
        render();
        if (state.installing) {
          var p = data.progress || {};
          var percent = p.total ? Math.floor(100 * p.have / p.total) : 0;
          status('Installiert ' + state.installing + ' · ' + percent + '% heruntergeladen');
          schedule();
        } else if (data.install_error) {
          status('Installation fehlgeschlagen: ' + data.install_error, true);
        } else if (!silent) {
          status('Vision Cores bereit. Der aktive Vision Core gilt für neue Bildgenerierungen.');
        }
      }).catch(function (error) { if (state.open) status('Vision Core nicht erreichbar: ' + error.message, true); });
    }
    function schedule() {
      if (state.poll !== null) return;
      state.poll = setTimeout(function () {
        state.poll = null;
        if (!state.open) return;
        refresh(true);
      }, 1500);
    }
    function search() {
      var serial = ++state.requestId;
      status('Suche kompatible Bildgeneratoren auf Hugging Face …');
      return request('?q=' + encodeURIComponent(el.query.value.trim())).then(function (data) {
        if (!state.open || serial !== state.requestId) return;
        state.found = Array.isArray(data.models) ? data.models : [];
        render();
        status(state.found.length + ' kompatible Vision-Core-Varianten gefunden.');
      }).catch(function (error) { if (state.open) status('Suche fehlgeschlagen: ' + error.message, true); });
    }
    function mutate(action, id) {
      if (state.busy) return;
      state.confirm = '';
      state.busy = true;
      render();
      status(action === 'install' ? 'Download wird gestartet …'
        : action === 'select' ? 'Vision Core wird aktiviert …' : 'Vision Core wird entfernt …');
      request('', {action:action, id:id}).then(function () {
        state.busy = false;
        return refresh(false).then(function () {
          if (action === 'install') { switchTab('installed'); schedule(); }
          else status(action === 'select' ? 'Vision Core erfolgreich gewechselt.'
            : 'Vision Core deinstalliert.');
        });
      }).catch(function (error) {
        state.busy = false;
        render();
        status('Aktion fehlgeschlagen: ' + error.message, true);
      });
    }
    function switchTab(tab) {
      state.tab = tab;
      el.searchPanel.hidden = tab !== 'search';
      el.installedPanel.hidden = tab !== 'installed';
      var ollamaTab = byId('OllamaTab');
      var ollamaPanel = byId('OllamaPanel');
      if (ollamaPanel) ollamaPanel.hidden = tab !== 'ollama';
      el.searchTab.classList.toggle('core-library-provider--active', tab === 'search');
      el.installedTab.classList.toggle('core-library-provider--active', tab === 'installed');
      if (ollamaTab) {
        ollamaTab.classList.toggle('core-library-provider--active', tab === 'ollama');
        ollamaTab.setAttribute('aria-selected', String(tab === 'ollama'));
      }
      el.searchTab.setAttribute('aria-selected', String(tab === 'search'));
      el.installedTab.setAttribute('aria-selected', String(tab === 'installed'));
      if (tab === 'search') search();
      else if (tab === 'ollama') {
        var adapter = window.CoreUIVisionOllama && window.CoreUIVisionOllama.instance;
        if (adapter) adapter.refresh();
      } else refresh(true);
    }
    function open() {
      if (state.open) return;
      state.open = true;
      dialog.hidden = false;
      state.confirm = '';
      el.close.focus();
      refresh(false).then(function () { if (state.open) switchTab('installed'); });
    }
    function close() {
      state.open = false;
      dialog.hidden = true;
      state.requestId++;
      if (state.poll !== null) { clearTimeout(state.poll); state.poll = null; }
      opener.focus();
    }
    opener.addEventListener('click', open);
    el.close.addEventListener('click', close);
    el.searchTab.addEventListener('click', function () { switchTab('search'); });
    if (byId('OllamaTab')) byId('OllamaTab').addEventListener('click', function () { switchTab('ollama'); });
    el.installedTab.addEventListener('click', function () { switchTab('installed'); });
    el.searchForm.addEventListener('submit', function (event) { event.preventDefault(); search(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) close(); });
    doc.addEventListener('keydown', function (event) { if (state.open && event.key === 'Escape') close(); });
    return {open:open, close:close, refresh:refresh};
  }
  window.CoreUIVisionLibrary = {create:create};
  if (window.document && window.fetch) {
    var start = function () {
      window.CoreUIVisionLibrary.instance = create(window.document, window.fetch.bind(window));
    };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', start);
    else start();
  }
})();
