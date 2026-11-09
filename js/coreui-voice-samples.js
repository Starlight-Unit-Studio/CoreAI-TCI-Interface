/* CoreAI: TCI Interface 0.5.94: Admin Core > Betrieb > Stimmproben.
   Shows the voice test (P20.0, scripts/tts-spike.sh) to the Inhaber: every
   candidate voice with its samples, real-time factor, first audio, RAM, load
   time and licenses. Admins never see the card; the server answers them
   owner_only anyway. Loads when the Betrieb tab opens. */
(function () {
  'use strict';

  // Works from the dedicated /admin/ page as well as the root app URL.
  var apiRoot = window.EMBER_COREUI && window.EMBER_COREUI.apiUrl
    ? window.EMBER_COREUI.apiUrl : new URL('../api/', document.baseURI).href;
  var ADMIN_API = new URL('admin.php', apiRoot).href;

  var card = document.getElementById('ttsSamplesCard');
  if (!card) return;
  var audio = document.getElementById('ttsAudio');
  var runPick = card.querySelector('.tts-run-pick');
  var runSelect = document.getElementById('ttsRunSelect');
  var loaded = false;
  var loading = false;
  var stamp = '';
  var playing = null;

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
    return fetch(ADMIN_API + '?action=tts_runs' + (query ? '&' + query : ''), {
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

  function sampleUrl(file) {
    return ADMIN_API + '?action=tts_sample&run=' + encodeURIComponent(stamp) + '&file=' + encodeURIComponent(file);
  }

  function stop() {
    if (audio) audio.pause();
    if (playing) playing.classList.remove('playing');
    playing = null;
  }

  function play(button, file) {
    if (!audio) return;
    if (playing === button) { stop(); return; }
    stop();
    playing = button;
    button.classList.add('playing');
    audio.src = sampleUrl(file);
    var started = audio.play();
    if (started && typeof started.catch === 'function') {
      started.catch(function () { if (playing === button) stop(); });
    }
  }

  function metric(label, value, unit) {
    var box = el('div', 'tts-metric');
    box.appendChild(el('span', '', label));
    box.appendChild(el('strong', '', value + (unit && value !== '-' ? ' ' + unit : '')));
    return box;
  }

  function renderVoice(voice, sentences) {
    var row = el('article', 'tts-voice' + (voice.ok ? '' : ' failed'));
    row.setAttribute('role', 'listitem');
    row.dataset.voice = voice.id;
    var head = el('div', 'tts-voice-head');
    var name = el('div', 'tts-voice-name');
    name.appendChild(el('strong', '', voice.label));
    name.appendChild(el('small', '', [voice.gender, voice.model].filter(Boolean).join(', ')));
    head.appendChild(name);
    var badge = el('span', 'tts-badge ' + (voice.status === 'frei' ? 'free' : 'check'), voice.status === 'frei' ? 'FREI' : 'PRÜFEN');
    badge.title = voice.status === 'frei' ? 'Stimme und Gewichte frei lizenziert' : 'Lizenz vor einer Auslieferung prüfen';
    head.appendChild(badge);
    row.appendChild(head);

    var metrics = el('div', 'tts-metrics');
    metrics.appendChild(metric('ECHTZEITFAKTOR', num(voice.rtf_median, 2), ''));
    metrics.appendChild(metric('ERSTER TON', num(voice.first_audio_median_s, 2), 's'));
    metrics.appendChild(metric('RAM', num(voice.peak_rss_mb, 0), 'MB'));
    metrics.appendChild(metric('LADEN', num(voice.load_s, 1), 's'));
    row.appendChild(metrics);

    var samples = el('div', 'tts-samples');
    var byId = {};
    (voice.samples || []).forEach(function (sample) { byId[sample.sentence] = sample; });
    // 0.6.6: German voices speak the German sentences, English voices the English ones.
    sentences.filter(function (sentence) { return (sentence.language || 'de') === (voice.language || 'de'); }).forEach(function (sentence, index) {
      var sample = byId[sentence.id];
      var button = el('button', 'tts-play', String(index + 1));
      button.type = 'button';
      if (!sample) {
        button.disabled = true;
        button.setAttribute('aria-label', 'Satz ' + (index + 1) + ' fehlt');
      } else {
        button.dataset.file = sample.file;
        button.setAttribute('aria-label', voice.label + ', Satz ' + (index + 1) + ' abspielen: ' + sentence.topic);
        button.title = (sentence.spoken || sentence.text) + ' (' + num(sample.audio_s, 1) + ' s, Echtzeitfaktor ' + num(sample.rtf, 2) + ')';
        button.addEventListener('click', function () { play(button, sample.file); });
      }
      samples.appendChild(button);
    });
    row.appendChild(samples);

    var license = el('dl', 'tts-license');
    [['Stimme', voice.voice_license], ['Gewichte', voice.weights_license], ['Engine', voice.code_license], ['Herkunft', voice.origin]]
      .forEach(function (pair) {
        if (!pair[1]) return;
        license.appendChild(el('dt', '', pair[0]));
        license.appendChild(el('dd', '', pair[1]));
      });
    row.appendChild(license);
    if (voice.note) row.appendChild(el('p', 'tts-note', voice.note));
    if (!voice.ok) row.appendChild(el('p', 'tts-error', 'Keine Hörprobe: ' + (voice.error || 'nicht gemessen')));
    return row;
  }

  function renderGroups(groups) {
    var box = document.getElementById('ttsGroups');
    box.innerHTML = '';
    var wrap = el('div', 'tts-table-wrap');
    var table = el('table', 'tts-table');
    var head = el('tr');
    ['Modell', 'Kerne', 'Laden s', 'Aufwärmen s', 'RAM MB', 'Echtzeitfaktor', 'Erster Ton s', 'Status'].forEach(function (title) {
      head.appendChild(el('th', '', title));
    });
    var thead = el('thead');
    thead.appendChild(head);
    table.appendChild(thead);
    var body = el('tbody');
    groups.forEach(function (group) {
      group.runs.forEach(function (run) {
        var tr = el('tr', run.ok ? '' : 'failed');
        [group.label, String(run.cores), num(run.load_s, 1), num(run.warmup_s, 2), num(run.peak_rss_mb, 0), num(run.rtf_median, 2),
          num(run.first_audio_median_s, 2), run.ok ? 'ok' : (run.error || 'Fehler')].forEach(function (cell) {
          tr.appendChild(el('td', '', cell));
        });
        body.appendChild(tr);
      });
    });
    table.appendChild(body);
    wrap.appendChild(table);
    box.appendChild(wrap);
    var seen = {};
    groups.forEach(function (group) {
      if (seen[group.engine_label]) return;
      seen[group.engine_label] = true;
      box.appendChild(el('p', 'tts-engine-note', group.engine_label + ' (' + group.package + '), Code ' + group.code_license
        + (group.code_note ? '. ' + group.code_note : '')));
    });
  }

  function render(data) {
    var runs = data.runs || [];
    var run = data.run;
    card.hidden = false;
    stop();
    runPick.hidden = runs.length < 2;
    runSelect.innerHTML = '';
    runs.forEach(function (item) {
      var option = el('option', '', when(item.stamp) + ' (' + item.spoken + ' von ' + item.voices + ')');
      option.value = item.stamp;
      runSelect.appendChild(option);
    });
    document.getElementById('ttsEmpty').hidden = !!run;
    document.getElementById('ttsBody').hidden = !run;
    if (!run) return;
    stamp = run.stamp;
    runSelect.value = stamp;
    var spoken = run.voices.filter(function (voice) { return voice.ok; }).length;
    document.getElementById('ttsMeta').textContent = 'Lauf vom ' + when(run.stamp) + ': ' + spoken + ' von ' + run.voices.length
      + ' Stimmen gesprochen, Hörproben mit ' + run.sample_cores + ' Kernen' + (run.errors ? ', ' + run.errors + ' Fehler (siehe report.md)' : '') + '.';
    var list = document.getElementById('ttsSentences');
    list.innerHTML = '';
    run.sentences.forEach(function (sentence) {
      var item = el('li');
      item.appendChild(el('b', '', sentence.topic + ': '));
      if (sentence.language === 'en') item.lang = 'en';
      item.appendChild(document.createTextNode(sentence.text));
      // 0.5.98: numbers, dates and file names are spoken as words.
      if (sentence.spoken && sentence.spoken !== sentence.text) item.appendChild(el('small', 'tts-spoken', 'Gesprochen als: ' + sentence.spoken));
      list.appendChild(item);
    });
    var voices = document.getElementById('ttsVoices');
    voices.innerHTML = '';
    run.voices.forEach(function (voice) { voices.appendChild(renderVoice(voice, run.sentences)); });
    renderGroups(run.groups || []);
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
      document.getElementById('ttsEmpty').hidden = true;
      document.getElementById('ttsBody').hidden = true;
      var note = card.querySelector('.tts-load-error') || card.appendChild(el('p', 'tts-error tts-load-error'));
      note.textContent = 'Stimmproben: ' + error.message;
    }).then(function () { loading = false; });
  }

  if (audio) {
    audio.addEventListener('ended', stop);
    audio.addEventListener('error', function () {
      if (!playing) return;
      playing.classList.add('broken');
      stop();
    });
  }
  runSelect.addEventListener('change', function () { load(runSelect.value); });
  document.getElementById('ttsReload').addEventListener('click', function () { load(stamp); });
  document.addEventListener('coreai:view', function (event) {
    var view = event && event.detail ? event.detail.view : '';
    if (view === 'admin-betrieb' && !loaded) load('');
    if (view !== 'admin-betrieb') stop();
  });
  var tabs = window.CoreAISettingsTabs && window.CoreAISettingsTabs.instance;
  if (tabs && typeof tabs.current === 'function' && tabs.current() === 'admin-betrieb') load('');
})();
