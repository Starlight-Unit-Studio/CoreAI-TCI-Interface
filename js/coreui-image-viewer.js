/* CoreAI: TCI Interface 0.6.62 | Private, mobile-first image viewer.
 * Chat attachments, generated images, finished-image thumbnails and pending uploads
 * share one accessible overlay. Original bytes are never modified on download.
 */
(function () {
  'use strict';

  var overlay = null;
  var ui = {};
  var gallery = [];
  var index = 0;
  var zoomMode = 'fit';
  var zoom = 1;
  var sequence = 0;
  var previousFocus = null;
  var pendingFetch = null;
  var historyEntry = false;
  var scrollRaf = 0;
  var ICONS = {
    image: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
    chevron: '<path d="m6 9 6 6 6-6"/>',
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    download: '<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.7 10.7 6.6-4.4m-6.6 7 6.6 4.4"/>',
    expand: '<path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/>',
    shrink: '<path d="M3 8h5V3m13 5h-5V3M3 16h5v5m13-5h-5v5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    left: '<path d="m15 18-6-6 6-6"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    edit: '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4z"/>'
  };

  function icon(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
  }

  function action(name, label, title) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'coreai-image-viewer-action coreai-image-viewer-' + name;
    button.setAttribute('aria-label', label);
    button.title = title || label;
    button.innerHTML = icon(name);
    return button;
  }

  function isSafeImageUrl(value) {
    try {
      var url = new URL(String(value || ''), document.baseURI);
      // A user-controlled attachment URL must never send authenticated fetches cross-origin.
      return (url.protocol === 'http:' || url.protocol === 'https:') &&
        url.origin === window.location.origin ? url.href : '';
    } catch (e) {
      return '';
    }
  }

  function filename(value) {
    var name = String(value || 'CoreAI-Bild.png').split(/[\\/]/).pop().trim();
    return name.replace(/[<>:"|?*\u0000-\u001f]/g, '_').slice(0, 180) || 'CoreAI-Bild.png';
  }

  function bytes(value) {
    var n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return 'Unbekannt';
    if (n < 1024) return Math.round(n) + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(2) + ' MB';
  }

  function descriptor(img) {
    if (!img || img.tagName !== 'IMG') return null;
    if (img.closest('.attach-preview-button') && img.dataset.previewUrl) {
      var pendingUrl = isSafeImageUrl(img.dataset.previewUrl);
      if (!pendingUrl) return null;
      return {src:pendingUrl,name:filename(img.dataset.fileName),size:Number(img.dataset.fileSize)||0,
        mime:img.dataset.fileMime||'',origin:'Hochgeladen'};
    }
    var anchor = img.closest('a.msg-image-link, a.img-progress-thumb');
    var figure = img.closest('figure.msg-generated');
    var download = figure && figure.querySelector('a[download]');
    var source = isSafeImageUrl(anchor ? anchor.getAttribute('href') : img.getAttribute('src'));
    if (!source) return null;
    var name = img.getAttribute('data-file-name') ||
      (download && download.getAttribute('download')) ||
      img.getAttribute('alt') || 'CoreAI-Bild.png';
    return {
      src: source, name: filename(name),
      size: Number(img.getAttribute('data-file-size')) || 0,
      mime: img.getAttribute('data-file-mime') || '',
      origin: (figure || img.closest('.img-progress')) ? 'Generiert' : 'Hochgeladen'
    };
  }

  function collect(img) {
    var parent = img.closest('.msg-content, .composer-attachments, .img-progress');
    var images = parent ? Array.prototype.slice.call(parent.querySelectorAll(
      'img.msg-image, .img-progress-thumb img, .img-progress.is-revealed img.img-progress-picture, .attach-preview-button img'
    )) : [img];
    if (images.indexOf(img) < 0) images.push(img);
    var seen = Object.create(null);
    var items = [];
    images.forEach(function (node) {
      var item = descriptor(node);
      if (!item || seen[item.src]) return;
      seen[item.src] = true;
      items.push(item);
    });
    return items;
  }

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function ensure() {
    if (overlay) return;
    overlay = element('div', 'coreai-image-viewer');
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Bildbetrachter');
    overlay.setAttribute('tabindex', '-1');

    var toolbar = element('header', 'coreai-image-viewer-toolbar');
    ui.info = action('image', 'Dateiinformationen anzeigen');
    ui.info.classList.add('coreai-image-viewer-title');
    ui.info.innerHTML = icon('image') + '<span>Bild</span>' + icon('chevron');
    ui.info.setAttribute('aria-expanded', 'false');
    toolbar.appendChild(ui.info);

    var tools = element('div', 'coreai-image-viewer-tools');
    ui.zoomOut = action('minus', 'Verkleinern');
    ui.zoomText = element('button', 'coreai-image-viewer-zoom', '100 %');
    ui.zoomText.type = 'button';
    ui.zoomText.title = 'Zwischen Einpassen und Originalgröße wechseln';
    ui.zoomText.setAttribute('aria-label', 'Zoomstufe umschalten');
    ui.zoomIn = action('plus', 'Vergrößern');
    ui.download = action('download', 'Originalbild herunterladen');
    ui.share = action('share', 'Bild teilen');
    ui.fullscreen = action('expand', 'Vollbildmodus');
    ui.close = action('close', 'Bildansicht schließen');
    [ui.zoomOut, ui.zoomText, ui.zoomIn, ui.download, ui.share, ui.fullscreen, ui.close]
      .forEach(function (node) { tools.appendChild(node); });
    toolbar.appendChild(tools);

    ui.details = element('section', 'coreai-image-viewer-details');
    ui.details.hidden = true;
    ui.details.setAttribute('aria-label', 'Dateiinformationen');
    ui.detailsName = element('div');
    ui.detailsType = element('div');
    ui.detailsDimension = element('div');
    ui.detailsSize = element('div');
    [ui.detailsName, ui.detailsType, ui.detailsDimension, ui.detailsSize]
      .forEach(function (node) { ui.details.appendChild(node); });

    var stage = element('div', 'coreai-image-viewer-stage');
    ui.prev = action('left', 'Vorheriges Bild');
    ui.prev.classList.add('coreai-image-viewer-nav');
    ui.scroll = element('div', 'coreai-image-viewer-scroll');
    ui.scroll.setAttribute('tabindex', '0');
    ui.scroll.setAttribute('aria-label', 'Bild, zum Verschieben scrollen');
    ui.picture = element('img', 'coreai-image-viewer-picture');
    ui.picture.alt = '';
    ui.picture.draggable = false;
    ui.scroll.appendChild(ui.picture);
    ui.next = action('right', 'Nächstes Bild');
    ui.next.classList.add('coreai-image-viewer-nav');
    stage.appendChild(ui.prev);
    stage.appendChild(ui.scroll);
    stage.appendChild(ui.next);
    ui.edit = action('edit', 'Bild bearbeiten');
    ui.edit.innerHTML = icon('edit') + '<span>Bearbeiten</span>';
    stage.appendChild(ui.edit);

    ui.footer = element('footer', 'coreai-image-viewer-footer');
    ui.position = element('span', '', '');
    ui.status = element('span', 'coreai-image-viewer-status', '');
    ui.status.setAttribute('role', 'status');
    ui.status.setAttribute('aria-live', 'polite');
    ui.footer.appendChild(ui.position);
    ui.footer.appendChild(ui.status);
    overlay.appendChild(toolbar);
    overlay.appendChild(ui.details);
    overlay.appendChild(stage);
    overlay.appendChild(ui.footer);
    document.body.appendChild(overlay);

    ui.info.addEventListener('click', function () {
      ui.details.hidden = !ui.details.hidden;
      ui.info.setAttribute('aria-expanded', ui.details.hidden ? 'false' : 'true');
    });
    ui.close.addEventListener('click', close);
    ui.edit.addEventListener('click', function () {
      if (window.CoreAIImageEditor && typeof window.CoreAIImageEditor.open === 'function') {
        window.CoreAIImageEditor.open(gallery[index]);
      }
    });
    ui.prev.addEventListener('click', function () { show(index - 1); });
    ui.next.addEventListener('click', function () { show(index + 1); });
    ui.zoomOut.addEventListener('click', function () { stepZoom(-1); });
    ui.zoomIn.addEventListener('click', function () { stepZoom(1); });
    ui.zoomText.addEventListener('click', function () {
      if (zoomMode === 'fit') setZoom(1, false);
      else setZoom(0, true);
    });
    ui.download.addEventListener('click', function () { transfer(false); });
    ui.share.addEventListener('click', function () { transfer(true); });
    ui.fullscreen.addEventListener('click', toggleFullscreen);
    ui.picture.addEventListener('load', function () {
      if (overlay.hidden) return;
      ui.detailsDimension.textContent = 'Auflösung: ' + ui.picture.naturalWidth +
        ' × ' + ui.picture.naturalHeight + ' px';
      setZoom(0, true);
      ui.status.textContent = '';
    });
    ui.picture.addEventListener('error', function () {
      if (!overlay.hidden) ui.status.textContent = 'Bild konnte nicht geladen werden.';
    });
    ui.scroll.addEventListener('dblclick', function () {
      if (zoomMode === 'fit') setZoom(1, false);
      else setZoom(0, true);
    });
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay || event.target === stage || event.target === ui.scroll) close();
      if (!ui.details.hidden && !ui.details.contains(event.target) && !ui.info.contains(event.target)) {
        ui.details.hidden = true;
        ui.info.setAttribute('aria-expanded', 'false');
      }
    });
    window.addEventListener('resize', function () {
      if (overlay && !overlay.hidden) setZoom(zoomMode === 'fit' ? 0 : zoom, zoomMode === 'fit');
    });
    document.addEventListener('fullscreenchange', function () {
      if (!overlay) return;
      var active = document.fullscreenElement === overlay;
      ui.fullscreen.innerHTML = icon(active ? 'shrink' : 'expand');
      ui.fullscreen.setAttribute('aria-label', active ? 'Vollbild verlassen' : 'Vollbildmodus');
    });
    ui.share.hidden = !(navigator.share && window.File);
    ui.fullscreen.hidden = typeof overlay.requestFullscreen !== 'function';
  }

  function active() { return overlay && !overlay.hidden; }

  function fitScale() {
    if (!ui.picture.naturalWidth || !ui.picture.naturalHeight) return 1;
    return Math.max(0.05, Math.min(1,
      (ui.scroll.clientWidth - 16) / ui.picture.naturalWidth,
      (ui.scroll.clientHeight - 16) / ui.picture.naturalHeight));
  }

  function setZoom(requested, fit) {
    if (!active() || !ui.picture.naturalWidth || !ui.picture.naturalHeight) return;
    zoomMode = fit ? 'fit' : 'manual';
    zoom = fit ? fitScale() : Math.max(0.1, Math.min(4, requested));
    ui.picture.style.width = Math.max(1, Math.round(ui.picture.naturalWidth * zoom)) + 'px';
    ui.picture.style.height = Math.max(1, Math.round(ui.picture.naturalHeight * zoom)) + 'px';
    ui.zoomText.textContent = Math.round(zoom * 100) + ' %';
    ui.zoomText.setAttribute('aria-label', 'Zoom ' + Math.round(zoom * 100) + ' Prozent, umschalten');
    cancelAnimationFrame(scrollRaf);
    scrollRaf = requestAnimationFrame(function () {
      ui.scroll.scrollLeft = Math.max(0, (ui.scroll.scrollWidth - ui.scroll.clientWidth) / 2);
      ui.scroll.scrollTop = Math.max(0, (ui.scroll.scrollHeight - ui.scroll.clientHeight) / 2);
    });
  }

  function stepZoom(direction) {
    var levels = [0.1, 0.15, 0.2, 0.25, 0.33, 0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];
    var next = direction > 0 ? levels.find(function (v) { return v > zoom + 0.001; }) :
      levels.slice().reverse().find(function (v) { return v < zoom - 0.001; });
    if (next) setZoom(next, false);
  }

  function abortTransfer() {
    if (pendingFetch) {
      pendingFetch.abort();
      pendingFetch = null;
    }
    if (ui.download) ui.download.disabled = false;
    if (ui.share) ui.share.disabled = false;
  }

  function show(next) {
    if (!active() || next < 0 || next >= gallery.length) return;
    abortTransfer();
    sequence += 1;
    index = next;
    var item = gallery[index];
    zoomMode = 'fit';
    zoom = 1;
    ui.picture.removeAttribute('src');
    ui.picture.removeAttribute('style');
    ui.picture.alt = item.name;
    ui.detailsName.textContent = 'Datei: ' + item.name;
    ui.detailsType.textContent = 'Quelle: ' + item.origin + (item.mime ? ' · ' + item.mime : '');
    ui.detailsDimension.textContent = 'Auflösung: wird ermittelt';
    ui.detailsSize.textContent = 'Dateigröße: ' + bytes(item.size);
    ui.edit.disabled = !(window.CoreAIImageEditor && typeof window.CoreAIImageEditor.open === 'function');
    ui.position.textContent = gallery.length > 1 ? (index + 1) + ' / ' + gallery.length : '';
    ui.prev.hidden = gallery.length < 2;
    ui.next.hidden = gallery.length < 2;
    ui.prev.disabled = index === 0;
    ui.next.disabled = index === gallery.length - 1;
    ui.status.textContent = 'Bild wird geladen…';
    ui.picture.src = item.src;
  }

  function open(options) {
    ensure();
    options = options || {};
    var items = Array.isArray(options.items) ? options.items : [options];
    gallery = items.map(function (item) {
      var src = isSafeImageUrl(item.src);
      return src ? {
        src: src, name: filename(item.name), size: Number(item.size) || 0,
        mime: String(item.mime || ''), origin: String(item.origin || 'Bild')
      } : null;
    }).filter(Boolean);
    if (!gallery.length) return false;
    if (active()) {
      show(Math.min(gallery.length - 1, Math.max(0, Number(options.index) || 0)));
      return true;
    }
    previousFocus = document.activeElement;
    overlay.hidden = false;
    document.body.classList.add('coreai-image-viewer-open');
    ui.details.hidden = true;
    ui.info.setAttribute('aria-expanded', 'false');
    try {
      history.pushState({coreaiImageViewer: true}, '', window.location.href);
      historyEntry = true;
    } catch (e) { historyEntry = false; }
    show(Math.min(gallery.length - 1, Math.max(0, Number(options.index) || 0)));
    ui.close.focus();
    return true;
  }

  function hide() {
    if (!active()) return;
    sequence += 1;
    abortTransfer();
    cancelAnimationFrame(scrollRaf);
    if (document.fullscreenElement === overlay && document.exitFullscreen) {
      document.exitFullscreen().catch(function () {});
    }
    overlay.hidden = true;
    ui.picture.removeAttribute('src');
    gallery = [];
    document.body.classList.remove('coreai-image-viewer-open');
    if (previousFocus && previousFocus.isConnected && previousFocus.focus) previousFocus.focus();
    previousFocus = null;
  }

  function close() {
    if (!active()) return;
    hide();
    if (historyEntry) {
      historyEntry = false;
      history.back();
    }
  }

  function toggleFullscreen() {
    if (!active()) return;
    if (document.fullscreenElement === overlay) {
      document.exitFullscreen().catch(function () {});
    } else if (overlay.requestFullscreen) {
      overlay.requestFullscreen().catch(function () {
        ui.status.textContent = 'Vollbild ist in diesem Browser nicht verfügbar.';
      });
    }
  }

  async function transfer(share) {
    if (!active()) return;
    var item = gallery[index];
    var at = sequence;
    abortTransfer();
    var controller = new AbortController();
    pendingFetch = controller;
    ui.download.disabled = true;
    ui.share.disabled = true;
    ui.status.textContent = share ? 'Bild wird für Teilen vorbereitet…' : 'Originalbild wird heruntergeladen…';
    try {
      var response = await fetch(item.src, {credentials:'same-origin', signal:controller.signal});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var blob = await response.blob();
      if (!/^image\/(?:png|jpeg|webp|gif|avif|bmp)$/i.test(blob.type)) {
        throw new Error('Unerwarteter Dateityp');
      }
      if (!active() || at !== sequence) return;
      var name = filename(item.name);
      if (share) {
        if (!navigator.share || !window.File) throw new Error('Teilen wird nicht unterstützt');
        var file = new File([blob], name, {type:blob.type});
        if (navigator.canShare && !navigator.canShare({files:[file]})) throw new Error('Datei kann nicht geteilt werden');
        await navigator.share({files:[file], title:name});
        if (active() && at === sequence) ui.status.textContent = '';
      } else {
        var objectUrl = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = objectUrl;
        link.download = name;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
        if (active() && at === sequence) ui.status.textContent = 'Originalbild gespeichert.';
      }
    } catch (error) {
      if (error && (error.name === 'AbortError' || (share && error.name === 'NotAllowedError'))) {
        if (active() && at === sequence) ui.status.textContent = '';
      } else if (active() && at === sequence) {
        ui.status.textContent = 'Aktion fehlgeschlagen: ' + (error && error.message || 'Unbekannter Fehler');
      }
    } finally {
      if (pendingFetch === controller) {
        pendingFetch = null;
        ui.download.disabled = false;
        ui.share.disabled = false;
      }
    }
  }

  // Event delegation also covers saved chat history and cards inserted during streaming.
  document.addEventListener('click', function (event) {
    if (active() || !event.target || !event.target.closest) return;
    var hit = event.target.closest('a.msg-image-link, a.img-progress-thumb, img.msg-image, .img-progress.is-revealed img.img-progress-picture, .attach-preview-button');
    if (!hit || (!hit.closest('.msg-content') && !hit.closest('.composer-attachments')) || hit.closest('a[download]')) return;
    var img = hit.tagName === 'IMG' ? hit : hit.querySelector('img');
    var items = collect(img);
    var selected = descriptor(img);
    if (!selected || !items.length) return;
    event.preventDefault();
    open({items:items, index:items.findIndex(function (item) { return item.src === selected.src; })});
  }, false);

  document.addEventListener('keydown', function (event) {
    // Keep the viewer from swallowing keys while its editor is on top.
    if (window.CoreAIImageEditor && window.CoreAIImageEditor.isOpen()) return;
    if (!active()) {
      if ((event.key === 'Enter' || event.key === ' ') &&
        event.target && event.target.matches && event.target.matches('img.msg-image[tabindex="0"]')) {
        var source = descriptor(event.target);
        if (source) {
          event.preventDefault();
          var siblings = collect(event.target);
          open({items:siblings,index:siblings.findIndex(function (item) { return item.src === source.src; })});
        }
      }
      return;
    }
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); return; }
    if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); return; }
    if (event.key === '+' || event.key === '=') { event.preventDefault(); stepZoom(1); return; }
    if (event.key === '-') { event.preventDefault(); stepZoom(-1); return; }
    if (event.key !== 'Tab') return;
    var focusable = Array.prototype.slice.call(overlay.querySelectorAll('button:not([hidden]):not([disabled])'))
      .filter(function (node) { return node.getClientRects().length > 0; });
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  window.addEventListener('popstate', function () {
    if (active()) { historyEntry = false; hide(); }
  });

  window.CoreAIImageViewer = Object.freeze({open:open, close:close, isOpen:active});
})();
