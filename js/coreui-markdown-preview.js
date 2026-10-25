/* Protected Markdown previews for saved plan versions and generated .md files.
 * This module never accepts external URLs, active HTML or model-authored HTML.
 * The existing CoreUIMarkdown renderer constructs inert DOM nodes. */
(function () {
  'use strict';

  var maxPreviewBytes = 512 * 1024;
  var draftMarker = '<!-- COREUI_PLAN_DRAFT -->';
  var waiting = new Set();
  var observer = typeof IntersectionObserver === 'function'
    ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        waiting.delete(entry.target);
        if (typeof entry.target._loadMarkdown === 'function') {
          entry.target._loadMarkdown();
          delete entry.target._loadMarkdown;
        }
      });
    }, { rootMargin: '250px 0px' })
    : null;

  function supports(file) {
    if (!file || file.available !== true || typeof file.name !== 'string'
        || !/\.(md|markdown)$/i.test(file.name)
        || !Number.isSafeInteger(file.size_bytes) || file.size_bytes < 0
        || file.size_bytes > maxPreviewBytes) return false;
    var mime = String(file.mime_type || '').split(';')[0].trim().toLowerCase();
    return file.kind === 'plan' || mime === 'text/markdown'
      || mime === 'text/x-markdown' || mime === 'text/plain';
  }

  function errorText(status) {
    if (status === 401 || status === 403) return 'Bitte erneut anmelden.';
    if (status === 404 || status === 410) return 'Diese Datei ist nicht mehr verfuegbar.';
    if (status === 409) return 'Die Datei hat die Integritaetspruefung nicht bestanden.';
    return 'Die Markdown-Datei konnte nicht geladen werden.';
  }

  function protectedFetch(file, endpoint) {
    // The caller derives the URL exclusively from an authenticated API route
    // and a validated server-side ID, never from model-authored content.
    var url = new URL(endpoint.href);
    var api = new URL('./api/', document.baseURI);
    if (url.origin !== api.origin || url.pathname !== new URL(
      file.kind === 'plan' ? 'console_plan.php' : 'console_artifact.php', api).pathname
      || url.searchParams.get('id') !== file.id) {
      return Promise.reject(new Error('Ungueltiger Dateipfad.'));
    }
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 60000);
    return fetch(url.href, {
      credentials: 'same-origin', cache: 'no-store',
      redirect: 'error', signal: controller.signal
    }).then(function (response) {
      if (!response.ok) throw new Error(errorText(response.status));
      if ((response.headers.get('Content-Type') || '').split(';')[0].trim()
          !== 'application/octet-stream') {
        throw new Error('Ungueltiges Dateiformat vom Server.');
      }
      var declared = response.headers.get('Content-Length');
      if (declared !== null && Number(declared) !== file.size_bytes) {
        throw new Error('Die Dateigroesse ist ungueltig.');
      }
      return response.blob();
    }).then(function (blob) {
      if (blob.size !== file.size_bytes || blob.size > maxPreviewBytes) {
        throw new Error('Die Markdown-Datei ist unvollstaendig.');
      }
      var expected = String(file.sha256 || '').toLowerCase();
      if (!/^[a-f0-9]{64}$/.test(expected)
          || !window.crypto || !window.crypto.subtle) return blob;
      return blob.arrayBuffer().then(function (bytes) {
        return window.crypto.subtle.digest('SHA-256', bytes);
      }).then(function (digest) {
        var actual = Array.from(new Uint8Array(digest)).map(function (byte) {
          return ('0' + byte.toString(16)).slice(-2);
        }).join('');
        if (actual !== expected) throw new Error('Die Datei hat die Integritaetspruefung nicht bestanden.');
        return blob;
      });
    }).finally(function () {
      clearTimeout(timer);
    });
  }

  function iconButton(label, path) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'md-preview-action';
    button.title = label;
    button.setAttribute('aria-label', label);
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    var shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    shape.setAttribute('d', path);
    svg.appendChild(shape);
    button.appendChild(svg);
    return button;
  }

  function saveFile(blob, name) {
    var objectUrl = URL.createObjectURL(new Blob([blob], { type: 'application/octet-stream' }));
    var anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = name;
    anchor.hidden = true;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
  }

  function create(file, endpoint) {
    var frame = document.createElement('article');
    frame.className = 'msg-artifact msg-artifact-preview';
    frame.setAttribute('aria-label', 'Markdown-Dokument ' + file.name);
    var header = document.createElement('div');
    header.className = 'md-preview-header';
    var identity = document.createElement('div');
    identity.className = 'md-preview-identity';
    var fileIcon = document.createElement('span');
    fileIcon.className = 'md-preview-file-icon';
    fileIcon.textContent = 'MD';
    fileIcon.setAttribute('aria-hidden', 'true');
    var fileInfo = document.createElement('div');
    fileInfo.className = 'md-preview-file-info';
    var name = document.createElement('strong');
    name.className = 'md-preview-name';
    name.textContent = file.name;
    name.title = file.name;
    var detail = document.createElement('small');
    detail.textContent = (file.size_bytes / 1024).toFixed(1) + ' KB · Markdown';
    var draft = document.createElement('small');
    draft.className = 'md-preview-draft';
    draft.textContent = 'Unvollstaendiger Entwurf';
    draft.hidden = true;
    fileInfo.appendChild(name);
    fileInfo.appendChild(detail);
    fileInfo.appendChild(draft);
    identity.appendChild(fileIcon);
    identity.appendChild(fileInfo);

    var tools = document.createElement('div');
    tools.className = 'md-preview-toolbar';
    tools.setAttribute('aria-label', 'Markdown-Dateiaktionen');
    var copy = iconButton('Markdown kopieren',
      'M8 4h10a2 2 0 0 1 2 2v12M6 8h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2z');
    var download = iconButton('Markdown herunterladen',
      'M12 3v12m-5-5 5 5 5-5M5 17v3h14v-3');
    var expand = iconButton('Vorschau vergroessern',
      'M8 3H5a2 2 0 0 0-2 2v3m13-5h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 1-2 2h-3');
    tools.appendChild(copy);
    tools.appendChild(download);
    tools.appendChild(expand);
    header.appendChild(identity);
    header.appendChild(tools);
    frame.appendChild(header);

    var scroller = document.createElement('div');
    scroller.className = 'md-preview-scroller';
    scroller.tabIndex = 0;
    scroller.setAttribute('role', 'region');
    scroller.setAttribute('aria-label', 'Scrollbare Markdown-Vorschau: ' + file.name);
    var content = document.createElement('div');
    content.className = 'msg-markdown md-preview-content';
    content.textContent = 'Markdown-Vorschau wird geladen ...';
    scroller.appendChild(content);
    frame.appendChild(scroller);
    var status = document.createElement('p');
    status.className = 'md-preview-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    frame.appendChild(status);

    var rawText = null;
    var copyTextValue = null;
    var loading = null;
    var modal = null;
    var anchorMarker = null;

    function load() {
      if (rawText !== null) return Promise.resolve(rawText);
      if (loading !== null) return loading;
      content.textContent = 'Markdown-Vorschau wird geladen ...';
      content.setAttribute('aria-busy', 'true');
      loading = protectedFetch(file, endpoint).then(function (blob) {
        return blob.arrayBuffer().then(function (bytes) {
          var decoded = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
          rawText = decoded;
          var isDraft = decoded.indexOf(draftMarker) === 0;
          copyTextValue = isDraft ? decoded.slice(draftMarker.length).replace(/^\s+/, '') : decoded;
          draft.hidden = !isDraft;
          if (window.CoreUIMarkdown && typeof window.CoreUIMarkdown.render === 'function') {
            window.CoreUIMarkdown.render(content, copyTextValue);
          } else {
            content.textContent = copyTextValue;
          }
          content.removeAttribute('aria-busy');
          return decoded;
        });
      }).catch(function (error) {
        loading = null;
        content.removeAttribute('aria-busy');
        while (content.firstChild) content.removeChild(content.firstChild);
        var message = document.createElement('p');
        message.textContent = error && error.message ? error.message : errorText(0);
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'md-preview-retry';
        retry.textContent = 'Vorschau erneut laden';
        retry.addEventListener('click', function () { load().catch(function () {}); });
        content.appendChild(message);
        content.appendChild(retry);
        throw error;
      });
      return loading;
    }

    copy.addEventListener('click', function () {
      copy.disabled = true;
      load().then(function () {
        var helper = window.CoreUIMarkdown && window.CoreUIMarkdown.copyText;
        if (typeof helper !== 'function') throw new Error('Kopieren ist nicht verfuegbar.');
        return helper(copyTextValue, document);
      }).then(function () {
        status.textContent = 'Markdown kopiert.';
      }).catch(function (error) {
        status.textContent = error && error.message ? error.message : 'Kopieren fehlgeschlagen.';
      }).finally(function () { copy.disabled = false; });
    });

    download.addEventListener('click', function () {
      download.disabled = true;
      status.textContent = 'Download wird vorbereitet ...';
      // A fresh authenticated request preserves the existing guarded
      // download policy and serves the original, unmodified .md bytes.
      protectedFetch(file, endpoint).then(function (blob) {
        saveFile(blob, file.name);
        status.textContent = 'Download gestartet.';
      }).catch(function (error) {
        status.textContent = error && error.message ? error.message : errorText(0);
      }).finally(function () { download.disabled = false; });
    });

    function closeModal() {
      if (!modal) return;
      modal.close();
    }
    function restore() {
      if (anchorMarker && anchorMarker.parentNode) {
        anchorMarker.parentNode.replaceChild(frame, anchorMarker);
      }
      if (modal) modal.remove();
      modal = null;
      anchorMarker = null;
      frame.classList.remove('md-preview-fullscreen');
      frame.classList.remove('md-preview-fallback');
      expand.title = 'Vorschau vergroessern';
      expand.setAttribute('aria-label', expand.title);
      expand.setAttribute('aria-expanded', 'false');
      expand.focus();
    }
    expand.setAttribute('aria-expanded', 'false');
    expand.addEventListener('click', function () {
      if (modal) { closeModal(); return; }
      if (typeof HTMLDialogElement === 'undefined'
          || typeof document.createElement('dialog').showModal !== 'function') {
        frame.classList.toggle('md-preview-fullscreen');
        frame.classList.toggle('md-preview-fallback');
        var on = frame.classList.contains('md-preview-fullscreen');
        expand.setAttribute('aria-expanded', String(on));
        expand.title = on ? 'Vorschau verkleinern' : 'Vorschau vergroessern';
        expand.setAttribute('aria-label', expand.title);
        return;
      }
      anchorMarker = document.createComment('Markdown preview location');
      frame.parentNode.insertBefore(anchorMarker, frame);
      modal = document.createElement('dialog');
      modal.className = 'md-preview-modal';
      modal.setAttribute('aria-label', 'Markdown-Dokument ' + file.name);
      modal.appendChild(frame);
      document.body.appendChild(modal);
      modal.addEventListener('close', restore, { once: true });
      modal.addEventListener('click', function (event) {
        if (event.target === modal) modal.close();
      });
      frame.classList.add('md-preview-fullscreen');
      expand.setAttribute('aria-expanded', 'true');
      expand.title = 'Vorschau verkleinern';
      expand.setAttribute('aria-label', expand.title);
      modal.showModal();
      scroller.focus();
    });

    // Load visible messages rather than all historical Markdown versions.
    if (observer) {
      frame._loadMarkdown = function () { load().catch(function () {}); };
      waiting.add(frame);
      observer.observe(frame);
    } else {
      load().catch(function () {});
    }
    return frame;
  }

  function cleanup() {
    if (!observer) return;
    waiting.forEach(function (node) {
      if (!node.isConnected) {
        observer.unobserve(node);
        waiting.delete(node);
        delete node._loadMarkdown;
      }
    });
  }

  window.CoreUIMarkdownPreview = Object.freeze({
    supports: supports, create: create, cleanup: cleanup
  });
}());
