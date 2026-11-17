/* Generated-file cards. No model URL or host path is ever used as a download.
 * 0.7.82 (P11 C): the version (SHA-256) of every file, a preview of pictures and text that shows
 * exactly the bytes the download gives (checked against the checksum), "Behalten" and "Löschen",
 * the day the retention deletes a file, and a note in the chat where a deleted file was. */
(function () {
  'use strict';
  var api = new URL('./api/', document.baseURI);
  var maxBytes = 25 * 1024 * 1024;
  var observed = false;
  // What the account may do with its files; the list endpoint says so with every answer.
  var manage = { can: false, keep: false, csrf: '', retention: 0 };
  var IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  var TEXT_TYPES = ['application/json', 'application/xml', 'application/javascript', 'application/x-python', 'image/svg+xml',
    'application/x-sh', 'application/sql', 'application/yaml', 'application/toml'];
  var TEXT_EXT = /\.(txt|csv|tsv|json|py|js|mjs|ts|tsx|jsx|css|html?|xml|svg|log|ya?ml|ini|toml|sql|sh|md|markdown)$/i;
  var PREVIEW_IMAGE_BYTES = 10 * 1024 * 1024;
  var PREVIEW_TEXT_BYTES = 2 * 1024 * 1024;
  var PREVIEW_TEXT_CHARS = 200000;

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

  function shortDate(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
  }

  function validSha(value) { return typeof value === 'string' && /^[a-f0-9]{64}$/.test(value); }

  /** 'image', 'text' or '' (pure): what kind of preview a file gets. SVG only ever as text. */
  function previewKind(file) {
    var type = String(file.mime_type || '').toLowerCase().split(';')[0].trim();
    if (IMAGE_TYPES.indexOf(type) >= 0) return file.size_bytes <= PREVIEW_IMAGE_BYTES ? 'image' : '';
    if (type.indexOf('text/') === 0 || TEXT_TYPES.indexOf(type) >= 0 || TEXT_EXT.test(file.name)) {
      return file.size_bytes <= PREVIEW_TEXT_BYTES ? 'text' : '';
    }
    return '';
  }

  function hex(buffer) {
    return Array.from(new Uint8Array(buffer)).map(function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  /**
   * The verified bytes of a file: the server checks the stored checksum before it streams and
   * names it in X-CoreAI-SHA256; it has to be the version this card shows, the size has to match,
   * and where the browser can, the checksum is computed here too.
   */
  function fetchVerified(file, url, signal) {
    return fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: signal })
      .then(function (response) {
        if (!response.ok) throw new Error(errorText(response.status));
        if ((response.headers.get('Content-Type') || '').split(';')[0].trim() !== 'application/octet-stream') {
          throw new Error('Der Server hat keine gueltige Downloaddatei geliefert.');
        }
        if (validSha(file.sha256) && response.headers.get('X-CoreAI-SHA256') !== file.sha256) {
          throw new Error('Die Datei ist nicht mehr dieselbe Version. Bitte den Chat neu laden.');
        }
        return response.blob();
      })
      .then(function (blob) {
        if (blob.size !== file.size_bytes) throw new Error('Der Download ist unvollstaendig. Bitte erneut versuchen.');
        var subtle = window.crypto && window.crypto.subtle;
        if (!validSha(file.sha256) || !subtle || typeof blob.arrayBuffer !== 'function') return blob;
        return blob.arrayBuffer().then(function (bytes) { return subtle.digest('SHA-256', bytes); }).then(function (digest) {
          if (hex(digest) !== file.sha256) throw new Error('Die Pruefsumme stimmt nicht. Die Datei wird nicht gezeigt.');
          return blob;
        });
      });
  }

  function saveBlob(blob, name) {
    // Never render HTML/SVG as an active page in the application's origin.
    var objectUrl = URL.createObjectURL(new Blob([blob], { type: 'application/octet-stream' }));
    var save = document.createElement('a');
    save.href = objectUrl;
    save.download = name;
    save.hidden = true;
    document.body.appendChild(save);
    save.click();
    save.remove();
    setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
  }

  function changed(node) {
    node.dispatchEvent(new CustomEvent('coreui-artifacts-changed', { bubbles: true }));
  }

  function manageRequest(action, body) {
    var url = new URL('files.php', api);
    url.searchParams.set('action', action);
    return fetch(url.href, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
      headers: { 'Content-Type': 'application/json', 'X-CoreUI-CSRF': manage.csrf }, body: JSON.stringify(body) })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || !data || data.ok !== true) {
            throw new Error(response.status === 403 ? 'Bitte die Seite neu laden und erneut versuchen.' : 'Das hat nicht geklappt. Bitte erneut versuchen.');
          }
          return data;
        });
      });
  }

  /** The note where a deleted file was. */
  function tombstone(file) {
    var box = document.createElement('div');
    box.className = 'msg-artifact is-deleted';
    var note = document.createElement('p');
    note.className = 'msg-artifact-deleted';
    var when = shortDate(file.deleted_at);
    note.textContent = '„' + file.name + '“ wurde' + (when ? ' am ' + when : '') + ' gelöscht'
      + (file.deleted_reason === 'retention' ? ', weil die Aufbewahrungsfrist abgelaufen war.' : ' (von dir).');
    box.appendChild(note);
    if (file.version) {
      var version = document.createElement('p');
      version.className = 'msg-artifact-life';
      version.textContent = 'Version ' + file.version;
      box.appendChild(version);
    }
    return box;
  }

  /** How long a file stays, and the buttons Vorschau, Behalten and Löschen. */
  function lifecycle(file, box, url, status) {
    var lines = [];
    if (file.version) lines.push('Version ' + file.version);
    if (file.expires_at) lines.push('Wird am ' + shortDate(file.expires_at) + ' automatisch gelöscht.');
    else if (manage.retention > 0 && file.kept === true) lines.push('Behalten: wird nicht automatisch gelöscht.');
    else if (manage.retention > 0 && file.project) lines.push('Bleibt im Projekt „' + file.project + '“.');
    if (lines.length) {
      var life = document.createElement('p');
      life.className = 'msg-artifact-life';
      life.textContent = lines.join(' · ');
      box.appendChild(life);
    }
    var kind = url && validSha(file.sha256) ? previewKind(file) : '';
    var isSandbox = file.kind !== 'plan' && typeof file.kept === 'boolean';
    if (!kind && !(manage.can && isSandbox)) return;
    var actions = document.createElement('div');
    actions.className = 'msg-artifact-actions';
    var view = null;
    function action(label, cls, handler) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'msg-artifact-action' + (cls ? ' ' + cls : '');
      b.textContent = label;
      b.addEventListener('click', handler);
      actions.appendChild(b);
      return b;
    }
    if (kind) {
      var previewButton = action('Vorschau', 'is-preview', function () {
        if (view) { view.remove(); view = null; previewButton.setAttribute('aria-expanded', 'false'); return; }
        previewButton.disabled = true;
        status.textContent = 'Vorschau wird geladen ...';
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 60000);
        fetchVerified(file, url, controller.signal).then(function (blob) {
          return (kind === 'text' ? blob.slice(0, PREVIEW_TEXT_CHARS * 4).text() : Promise.resolve('')).then(function (text) {
            view = previewPanel(file, kind, blob, text, function () {
              view.remove(); view = null; previewButton.setAttribute('aria-expanded', 'false'); previewButton.focus();
            });
            box.appendChild(view);
            previewButton.setAttribute('aria-expanded', 'true');
            status.textContent = '';
          });
        }).catch(function (error) {
          status.textContent = error && error.name === 'AbortError' ? 'Die Vorschau hat zu lange gedauert.'
            : ((error && error.message && error.name === 'Error') ? error.message : 'Die Vorschau konnte nicht geladen werden.');
        }).finally(function () { clearTimeout(timer); previewButton.disabled = false; });
      });
      previewButton.setAttribute('aria-expanded', 'false');
    }
    if (manage.keep && isSandbox) {
      var keep = action(file.kept ? 'Nicht mehr behalten' : 'Behalten', 'is-keep', function () {
        keep.disabled = true;
        manageRequest('favorite', { id: 'a:' + file.id, on: !file.kept }).then(function () { changed(box); })
          .catch(function (error) { status.textContent = error.message; keep.disabled = false; });
      });
      keep.setAttribute('aria-pressed', file.kept ? 'true' : 'false');
    }
    if (manage.can && isSandbox) {
      var remove = action('Löschen', 'is-delete', function () {
        if (!window.confirm('„' + file.name + '“ endgültig löschen? Im Chat bleibt ein Hinweis, dass die Datei gelöscht wurde.'
          + (file.kept ? ' Sie ist als behalten markiert.' : ''))) return;
        remove.disabled = true;
        manageRequest('delete', { id: 'a:' + file.id }).then(function () { changed(box); })
          .catch(function (error) { status.textContent = error.message; remove.disabled = false; });
      });
    }
    box.appendChild(actions);
  }

  /** A picture or text from exactly the verified bytes; the download here saves the same bytes. */
  function previewPanel(file, kind, blob, text, close) {
    var panel = document.createElement('div');
    panel.className = 'msg-artifact-view';
    var head = document.createElement('div');
    head.className = 'msg-artifact-view-head';
    var title = document.createElement('strong');
    title.textContent = 'Vorschau · Version ' + file.version;
    var note = document.createElement('small');
    note.textContent = 'Gleiche Version wie der Download (SHA-256 geprüft).';
    head.appendChild(title);
    head.appendChild(note);
    panel.appendChild(head);
    var urls = [];
    if (kind === 'image') {
      var img = document.createElement('img');
      img.className = 'msg-artifact-view-image';
      img.alt = 'Vorschau von ' + file.name;
      var imageUrl = URL.createObjectURL(new Blob([blob], { type: String(file.mime_type).split(';')[0].trim() }));
      urls.push(imageUrl);
      img.src = imageUrl;
      panel.appendChild(img);
    } else {
      var pre = document.createElement('pre');
      pre.className = 'msg-artifact-view-text';
      var shown = text.length > PREVIEW_TEXT_CHARS ? text.slice(0, PREVIEW_TEXT_CHARS) : text;
      pre.textContent = shown;
      panel.appendChild(pre);
      if (shown.length < text.length || blob.size > PREVIEW_TEXT_CHARS * 4) {
        var cut = document.createElement('p');
        cut.className = 'msg-artifact-life';
        cut.textContent = 'Die Vorschau zeigt den Anfang; der Download enthält die ganze Datei.';
        panel.appendChild(cut);
      }
    }
    var buttons = document.createElement('div');
    buttons.className = 'msg-artifact-actions';
    var download = document.createElement('button');
    download.type = 'button';
    download.className = 'msg-artifact-action is-download';
    download.textContent = 'Herunterladen';
    download.addEventListener('click', function () { saveBlob(blob, file.name); });
    var shut = document.createElement('button');
    shut.type = 'button';
    shut.className = 'msg-artifact-action';
    shut.textContent = 'Schließen';
    shut.addEventListener('click', function () { urls.forEach(function (u) { URL.revokeObjectURL(u); }); close(); });
    buttons.appendChild(download);
    buttons.appendChild(shut);
    panel.appendChild(buttons);
    return panel;
  }

  function errorText(status) {
    if (status === 401 || status === 403) return 'Bitte erneut anmelden. Der Download ist geschuetzt.';
    if (status === 404 || status === 410) return 'Diese Datei ist nicht mehr verfuegbar.';
    if (status === 409) return 'Die Datei konnte nicht unveraendert bestaetigt werden.';
    return 'Download fehlgeschlagen. Bitte erneut versuchen.';
  }

  function card(file) {
    if (file.deleted === true) return tombstone(file);
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
      lifecycle(file, box, null, status);
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
      fetchVerified(file, url, controller.signal)
        .then(function (blob) {
          saveBlob(blob, file.name);
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
    lifecycle(file, box, url, status);
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
      // Only verified Markdown files use the embedded safe viewer.
      // All other artifact formats retain the original download card.
      var viewer = window.CoreUIMarkdownPreview;
      if (file.deleted !== true && viewer && typeof viewer.supports === 'function'
          && typeof viewer.create === 'function' && viewer.supports(file)) {
        var path = file.kind === 'plan' ? 'console_plan.php' : 'console_artifact.php';
        var endpoint = new URL(path, api);
        endpoint.searchParams.set('id', file.id);
        section.appendChild(viewer.create(file, endpoint));
        // 0.7.82: a Markdown file keeps its viewer and gets its lifetime and buttons below it.
        if (typeof file.kept === 'boolean') {
          var extra = document.createElement('div');
          extra.className = 'msg-artifact';
          var extraStatus = document.createElement('p');
          extraStatus.className = 'msg-artifact-status';
          extraStatus.setAttribute('role', 'status');
          lifecycle(file, extra, null, extraStatus);
          extra.appendChild(extraStatus);
          if (extra.childNodes.length > 1) section.appendChild(extra);
        }
      } else {
        section.appendChild(card(file));
      }
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
      if (window.CoreUIMarkdownPreview && typeof window.CoreUIMarkdownPreview.cleanup === 'function') {
        window.CoreUIMarkdownPreview.cleanup();
      }
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
          manage.can = data.can_manage === true && typeof data.csrf_token === 'string' && data.csrf_token !== '';
          manage.keep = manage.can && data.can_keep === true;
          manage.csrf = manage.can ? data.csrf_token : '';
          manage.retention = Number(data.retention_days) > 0 ? Number(data.retention_days) : 0;
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
    // 0.7.82: after Behalten or Löschen the files of that message are read again.
    root.addEventListener('coreui-artifacts-changed', function (event) {
      var row = event.target && event.target.closest ? event.target.closest('.msg-row.ember[data-message-id]') : null;
      if (!row) return;
      visited.delete(row);
      scan();
    });
    scan();
  }

  window.CoreUIArtifacts = Object.freeze({ render: render, start: start });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}());
