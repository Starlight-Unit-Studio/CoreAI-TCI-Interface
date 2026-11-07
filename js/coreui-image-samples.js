/* CoreAI: TCI Interface 0.6.37: Admin Core > Betrieb > Bildproben.
   Shows the image test (P20.5 A, scripts/image-spike.sh) to the Inhaber: every model
   with its test images, the time per image (whole call, sampling), RAM and loading,
   the prompts with their seeds, the registered files with revision, SHA-256 and
   license, and the server it ran on. Admins never see the card; the server answers
   them owner_only anyway. Loads when the Betrieb tab opens. */
(function () {
  'use strict';

  var card = document.getElementById('imageSamplesCard');
  if (!card) return;
  var runPick = card.querySelector('.tts-run-pick');
  var runSelect = document.getElementById('imageRunSelect');
  var loaded = false;
  var loading = false;
  var stamp = '';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function num(value, digits) {
    if (typeof value !== 'number' || !isFinite(value)) return '-';
    return value.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }

  function when(stampText) {
    var m = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})/.exec(stampText || '');
    return m ? (m[3] + '.' + m[2] + '.' + m[1] + ', ' + m[4] + ':' + m[5] + ' Uhr') : stampText;
  }

  function api(query) {
    return fetch('./api/admin.php?action=image_runs' + (query ? '&' + query : ''), {
      credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data.ok) {
          var err = new Error(data.error || ('HTTP_' + response.status));
          err.status = response.status;
          throw err;
        }
        return data;
      });
    });
  }

  function imageUrl(file) {
    return './api/admin.php?action=image_sample&run=' + encodeURIComponent(stamp) + '&file=' + encodeURIComponent(file);
  }

  function median(values) {
    var list = values.filter(function (v) { return typeof v === 'number' && isFinite(v); }).sort(function (a, b) { return a - b; });
    if (!list.length) return null;
    var mid = Math.floor(list.length / 2);
    return list.length % 2 ? list[mid] : (list[mid - 1] + list[mid]) / 2;
  }

  function metric(label, value, unit) {
    var box = el('div', 'tts-metric');
    box.appendChild(el('span', '', label));
    box.appendChild(el('strong', '', value + (unit && value !== '-' ? ' ' + unit : '')));
    return box;
  }

  function pixels(size) {
    var m = /^(\d+)x(\d+)$/.exec(size || '');
    return m ? Number(m[1]) * Number(m[2]) : 0;
  }

  function sizeLabel(size) {
    return String(size || '').replace('x', ' × ');
  }

  function renderGroup(group, prompts) {
    var row = el('article', 'tts-voice img-model' + (group.ok ? '' : ' failed'));
    row.setAttribute('role', 'listitem');
    row.dataset.group = group.id;
    var head = el('div', 'tts-voice-head');
    var name = el('div', 'tts-voice-name');
    name.appendChild(el('strong', '', group.label));
    name.appendChild(el('small', '', [group.steps ? group.steps + ' Schritte' : '', group.threads ? group.threads + ' Threads' : '']
      .filter(Boolean).join(', ')));
    head.appendChild(name);
    var badge = el('span', 'tts-badge ' + (/^apache|^mit/i.test(group.license) ? 'free' : 'check'), String(group.license || '?').toUpperCase());
    badge.title = 'Lizenz der Gewichte';
    head.appendChild(badge);
    row.appendChild(head);

    var ok = (group.images || []).filter(function (image) { return image.ok; });
    var bySize = {};
    ok.forEach(function (image) { (bySize[image.size] = bySize[image.size] || []).push(image); });
    var metrics = el('div', 'tts-metrics');
    // Smallest size first (512 × 512, then 1024 × 1024), not in text order.
    Object.keys(bySize).sort(function (a, b) { return pixels(a) - pixels(b); }).forEach(function (size) {
      metrics.appendChild(metric(sizeLabel(size), num(median(bySize[size].map(function (i) { return i.wall_s; })), 0), 's'));
    });
    var peak = Math.max.apply(null, ok.map(function (i) { return i.peak_rss_mb || 0; }).concat([0]));
    metrics.appendChild(metric('RAM', num(peak ? peak / 1024 : null, 1), 'GB'));
    metrics.appendChild(metric('LADEN', num(median(ok.map(function (i) { return i.load_s; })), 0), 's'));
    row.appendChild(metrics);

    var topics = {};
    prompts.forEach(function (prompt) { topics[prompt.id] = prompt; });
    var grid = el('div', 'img-grid');
    (group.images || []).forEach(function (image) {
      var shot = el('figure', 'img-shot' + (image.ok ? '' : ' failed'));
      var prompt = topics[image.prompt] || {};
      var caption = (prompt.topic || image.prompt) + ' · ' + sizeLabel(image.size);
      if (image.ok && image.file) {
        var link = el('a', 'img-open');
        link.href = imageUrl(image.file);
        link.target = '_blank';
        link.rel = 'noopener';
        link.setAttribute('aria-label', group.label + ', ' + caption + ' in voller Größe öffnen');
        var img = el('img');
        img.src = imageUrl(image.file);
        img.alt = group.label + ': ' + (prompt.text || caption);
        img.loading = 'lazy';
        img.decoding = 'async';
        link.appendChild(img);
        shot.appendChild(link);
      } else {
        shot.appendChild(el('div', 'img-missing', 'Kein Bild: ' + (image.error || 'Fehler')));
      }
      var figcaption = el('figcaption');
      figcaption.appendChild(el('b', '', caption));
      if (image.ok) {
        figcaption.appendChild(el('span', '', num(image.wall_s, 0) + ' s gesamt, Schritte ' + num(image.sampling_s, 0) + ' s'));
      }
      shot.appendChild(figcaption);
      shot.title = 'Laden ' + num(image.load_s, 1) + ' s, Text ' + num(image.text_s, 1) + ' s, Schritte ' + num(image.sampling_s, 1)
        + ' s, Dekodieren ' + num(image.decode_s, 1) + ' s, RAM ' + num(image.peak_rss_mb ? image.peak_rss_mb / 1024 : null, 1) + ' GB';
      grid.appendChild(shot);
    });
    row.appendChild(grid);

    if (group.origin) row.appendChild(el('p', 'tts-note', group.origin));
    if (!group.ok) row.appendChild(el('p', 'tts-error', 'Keine Bilder: ' + (group.error || 'nicht gemessen')));
    return row;
  }

  function renderFiles(run) {
    var box = document.getElementById('imageFiles');
    box.innerHTML = '';
    var list = el('ul', 'img-files');
    run.groups.forEach(function (group) {
      group.files.forEach(function (file) {
        var item = el('li');
        item.appendChild(el('b', '', group.label + ', ' + file.role + ': '));
        item.appendChild(document.createTextNode(file.repo + '/' + file.path + ' @ ' + String(file.revision || '').slice(0, 12)
          + ', SHA-256 ' + String(file.sha256 || '').slice(0, 16) + '…, ' + file.license));
        list.appendChild(item);
      });
    });
    box.appendChild(list);
    box.appendChild(el('p', 'tts-engine-note', 'stable-diffusion.cpp ' + run.runtime.tag + ' (' + String(run.runtime.commit).slice(0, 12)
      + '), Code ' + run.runtime.license + '.'));
    if (run.host) box.appendChild(el('pre', 'img-host', run.host));
  }

  function render(data) {
    var runs = data.runs || [];
    var run = data.run;
    card.hidden = false;
    runPick.hidden = runs.length < 2;
    runSelect.innerHTML = '';
    runs.forEach(function (item) {
      var option = el('option', '', when(item.stamp) + ' (' + item.made + ' von ' + item.images + ')');
      option.value = item.stamp;
      runSelect.appendChild(option);
    });
    document.getElementById('imageEmpty').hidden = !!run;
    document.getElementById('imageBody').hidden = !run;
    if (!run) return;
    stamp = run.stamp;
    runSelect.value = stamp;
    var images = 0;
    var made = 0;
    run.groups.forEach(function (group) {
      images += group.images.length;
      made += group.images.filter(function (image) { return image.ok; }).length;
    });
    document.getElementById('imageMeta').textContent = 'Lauf vom ' + when(run.stamp) + ': ' + made + ' von ' + images
      + ' Bildern erzeugt' + (run.errors ? ', ' + run.errors + ' Fehler (siehe report.md)' : '') + '.';
    var list = document.getElementById('imagePrompts');
    list.innerHTML = '';
    run.prompts.forEach(function (prompt) {
      var item = el('li');
      item.lang = 'en';
      item.appendChild(el('b', '', prompt.topic + ' (Seed ' + prompt.seed + '): '));
      item.appendChild(document.createTextNode(prompt.text));
      list.appendChild(item);
    });
    var models = document.getElementById('imageModels');
    models.innerHTML = '';
    run.groups.forEach(function (group) { models.appendChild(renderGroup(group, run.prompts)); });
    renderFiles(run);
  }

  function load(which) {
    if (loading) return;
    loading = true;
    api(which ? 'run=' + encodeURIComponent(which) : '').then(function (data) {
      loaded = true;
      render(data);
    }).catch(function (error) {
      // Admins and users get owner_only: the card stays hidden for them.
      if (error.status === 403 || error.status === 401) { card.hidden = true; loaded = true; return; }
      card.hidden = false;
      document.getElementById('imageEmpty').hidden = true;
      document.getElementById('imageBody').hidden = true;
      var note = card.querySelector('.tts-load-error') || card.appendChild(el('p', 'tts-error tts-load-error'));
      note.textContent = 'Bildproben: ' + error.message;
    }).then(function () { loading = false; });
  }

  runSelect.addEventListener('change', function () { load(runSelect.value); });
  document.getElementById('imageReload').addEventListener('click', function () { load(stamp); });
  document.addEventListener('coreai:view', function (event) {
    var view = event && event.detail ? event.detail.view : '';
    if (view === 'admin-betrieb' && !loaded) load('');
  });
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (tabs && typeof tabs.current === 'function' && tabs.current() === 'admin-betrieb') load('');
})();
