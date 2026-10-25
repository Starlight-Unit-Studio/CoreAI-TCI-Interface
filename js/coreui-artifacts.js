/* Generated-file cards. No model URL or host path is ever used as a download. */
(function () {
  'use strict';
  var api = new URL('./api/', document.baseURI);
  var maxBytes = 25 * 1024 * 1024;
  var observed = false;

  function validFile(file) {
    return file && typeof file.id === 'string' && /^[a-f0-9]{32}$/.test(file.id)
      && typeof file.name === 'string' && file.name.length > 0
      && file.name !== '.' && file.name !== '..' && !/[\\/\x00-\x1f\x7f]/.test(file.name)
      && new TextEncoder().encode(file.name).length <= 255
      && Number.isSafeInteger(file.size_bytes) && file.size_bytes >= 0 && file.size_bytes <= maxBytes;
  }

  function sizeText(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function errorText(status) {
    if (status === 401 || status === 403) return 'Bitte erneut anmelden. Der Download ist geschuetzt.';
    if (status === 404 || status === 410) return 'Diese Datei ist nicht mehr verfuegbar.';
    if (status === 409) return 'Die Datei konnte nicht unveraendert bestaetigt werden.';
    return 'Download fehlgeschlagen. Bitte erneut versuchen.';
  }

  function card(file) {
    var box = document.createElement('div');
    box.className = 'msg-artifact';
    var link = document.createElement(file.available === true ? 'a' : 'div');
    link.className = 'msg-file msg-artifact-link';
    var icon = document.createElement('span');
    icon.className = 'msg-file-icon';
    icon.textContent = '\u21e9';
    icon.setAttribute('aria-hidden', 'true');
    var meta = document.createElement('div');
    meta.className = 'msg-file-meta';
    var name = document.createElement('span');
    name.className = 'msg-file-name';
    name.textContent = file.name;
    var detail = document.createElement('span');
    detail.className = 'msg-file-sub';
    detail.textContent = sizeText(file.size_bytes) + ' \u00b7 ' + String(file.mime_type || 'Datei');
    meta.appendChild(name);
    meta.appendChild(detail);
    link.appendChild(icon);
    link.appendChild(meta);
    box.appendChild(link);
    if (file.partial === true) {
      var partial = document.createElement('p');
      partial.className = 'msg-artifact-warning';
      partial.textContent = 'Teilergebnis: Der erzeugende Lauf wurde nicht erfolgreich abgeschlossen.';
      box.appendChild(partial);
    }
    var status = document.createElement('p');
    status.className = 'msg-artifact-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    box.appendChild(status);
    if (file.available !== true) {
      link.setAttribute('aria-disabled', 'true');
      status.textContent = 'Diese Datei ist nicht mehr verfuegbar.';
      return box;
    }
    // Derive the authenticated route only from a validated server-side ID.
    // Plan cards are served by their own authenticated, immutable MD route.
    // The tool/model cannot inject an external download URL.
    var url = new URL(file.kind === 'plan' ? 'console_plan.php' : 'console_artifact.php', api);
    url.searchParams.set('id', file.id);
    link.href = url.href;
    link.download = file.name;
    var busy = false;
    link.addEventListener('click', function (event) {
      // Modified clicks may use the native authenticated attachment route.
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (busy) return;
      busy = true;
      link.setAttribute('aria-busy', 'true');
      status.textContent = 'Download wird vorbereitet ...';
      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, 60000);
      fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal })
        .then(function (response) {
          if (!response.ok) throw new Error(errorText(response.status));
          if ((response.headers.get('Content-Type') || '').split(';')[0].trim() !== 'application/octet-stream') {
            throw new Error('Der Server hat keine gueltige Downloaddatei geliefert.');
          }
          return response.blob();
        })
        .then(function (blob) {
          if (blob.size !== file.size_bytes) throw new Error('Der Download ist unvollstaendig. Bitte erneut versuchen.');
          // Never render HTML/SVG as an active page in the application's origin.
          var objectUrl = URL.createObjectURL(new Blob([blob], { type: 'application/octet-stream' }));
          var save = document.createElement('a');
          save.href = objectUrl;
          save.download = file.name;
          save.hidden = true;
          document.body.appendChild(save);
          save.click();
          save.remove();
          setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
          status.textContent = 'Download gestartet.';
        })
        .catch(function (error) {
          status.textContent = error && error.name === 'AbortError'
            ? 'Der Download hat zu lange gedauert. Bitte erneut versuchen.'
            : ((error && error.message && error.name === 'Error') ? error.message : errorText(0));
        })
        .finally(function () {
          clearTimeout(timer);
          busy = false;
          link.removeAttribute('aria-busy');
        });
    });
    return box;
  }

  function render(files) {
    var section = document.createElement('section');
    section.className = 'msg-artifacts';
    section.setAttribute('aria-label', 'Erzeugte Dateien');
    var seen = new Set();
    (Array.isArray(files) ? files : []).slice(0, 10).forEach(function (file) {
      if (!validFile(file) || seen.has(file.id)) return;
      seen.add(file.id);
      section.appendChild(card(file));
    });
    return section;
  }

  function start() {
    var root = document.getElementById('messagesInner');
    if (!root || observed) return;
    observed = true;
    var visited = new WeakSet();
    var queued = new Set();
    var running = false;
    var timer = null;

    function place(row, section) {
      var content = row.querySelector('.msg-content');
      if (!content || !row.isConnected) return;
      var old = content.querySelector('.msg-artifacts');
      if (old) old.remove();
      if (section.childNodes.length) content.insertBefore(section, content.querySelector('.msg-actions, .msg-time'));
    }

    function scan() {
      root.querySelectorAll('.msg-row.ember[data-message-id]').forEach(function (row) {
        var id = row.getAttribute('data-message-id');
        if (!/^[1-9][0-9]{0,15}$/.test(id || '') || visited.has(row)) return;
        visited.add(row);
        queued.add(row);
      });
      if (!running && timer === null && queued.size) timer = setTimeout(load, 40);
    }

    function load() {
      timer = null;
      var rows = Array.from(queued).filter(function (row) { return row.isConnected; }).slice(0, 100);
      queued.forEach(function (row) { if (!row.isConnected) queued.delete(row); });
      rows.forEach(function (row) { queued.delete(row); });
      if (!rows.length) return;
      running = true;
      var ids = rows.map(function (row) { return row.getAttribute('data-message-id'); });
      var url = new URL('console_artifacts.php', api);
      url.searchParams.set('message_ids', Array.from(new Set(ids)).join(','));
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 15000);
      fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal })
        .then(function (response) {
          if (!response.ok) throw new Error('artifact_list_failed');
          return response.json();
        })
        .then(function (data) {
          if (!data || data.ok !== true || data.schema_ready === false || !data.artifacts || typeof data.artifacts !== 'object') {
            throw new Error('artifact_list_invalid');
          }
          rows.forEach(function (row, index) {
            if (row.isConnected && row.getAttribute('data-message-id') === ids[index]) {
              place(row, render(data.artifacts[ids[index]] || []));
            }
          });
        })
        .catch(function () {
          rows.forEach(function (row, index) {
            if (!row.isConnected || row.getAttribute('data-message-id') !== ids[index]) return;
            var section = document.createElement('section');
            section.className = 'msg-artifacts';
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'msg-artifact-retry';
            retry.textContent = 'Dateiausgaben erneut laden';
            retry.addEventListener('click', function () {
              section.remove();
              visited.delete(row);
              scan();
            });
            section.appendChild(retry);
            place(row, section);
          });
        })
        .finally(function () {
          clearTimeout(timeout);
          running = false;
          scan();
        });
    }
    // Stable message IDs exist for SSE, polling, history and response variants.
    // Hydrate in batches without replacing the legacy text/actions renderer.
    new MutationObserver(scan).observe(root, { childList: true, subtree: true });
    scan();
  }

  window.CoreUIArtifacts = Object.freeze({ render: render, start: start });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}());
