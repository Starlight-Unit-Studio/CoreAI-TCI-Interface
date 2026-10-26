// P06: operational events live inline in CoreAI's normal chat answer.
// Events are supplied by the authenticated message history (or SSE), not a
// second per-message HTTP request. Never render model-authored HTML here.
(function () {
  'use strict';

  var STATES = {running:true,success:true,error:true,info:true};
  var KINDS = {run:true,model:true,tool:true,artifact:true,error:true,commentary:true};
  var DETAIL_LABELS = {
    model:'Modell', job_id:'Job', exit_code:'Exitcode', duration_ms:'Laufzeit (ms)',
    error_type:'Fehlertyp', file_name:'Datei', file_count:'Dateien', attempt:'Versuch',
    http_code:'HTTP', reason:'Grund', source:'Quelle', elapsed_ms:'Dauer (ms)',
    source_chars:'Quelltext (Zeichen)', eval_count:'Ausgabe-Tokens',
    prompt_eval_count:'Eingabe-Tokens', plan_version:'Planversion',
    step:'Planungsabschnitt', done_reason:'Modell-Endgrund',
    content_chars:'Antworttext (Zeichen)', thinking_chars:'Denkstatus (Zeichen)',
    continuation_segments:'Fortsetzungen', response_bytes:'Antwort (Bytes)',
    discarded_tool_body_bytes:'Verworfener Marker (Bytes)',
    marker_incomplete:'Marker unvollständig'
  };

  // Das ist ein oeffentliches, aus bestaetigten Arbeitsereignissen
  // abgeleitetes Protokoll, nicht das private interne Modell-Reasoning.
  // Unbekannte Ereignisse erhalten keine erfundene Erklaerung.
  function workNote(event) {
    if (!event) return '';
    if (event.kind === 'commentary') return event.meta.public_note || '';
    var file = event.meta.file_name ? '„' + event.meta.file_name + '“' : 'die angeforderte Datei';
    switch (event.title) {
      case 'Auftrag gestartet':
        return 'Ich übernehme den Auftrag und beginne mit den notwendigen Prüfungen.';
      case 'Kontext vorbereitet':
        return 'Der Kontext ist vorbereitet. Als Nächstes prüfe ich, welche Antwort oder Werkzeugaktion erforderlich ist.';
      case 'Modellstream vorbereitet':
        return 'Ich bereite den Modellaufruf vor. Eine Modellantwort liegt zu diesem Zeitpunkt noch nicht vor.';
      case 'Modellstream gestartet':
      case 'Modellaufruf gestartet':
        return 'Ich habe den Modellaufruf gestartet und warte auf ein tatsächlich abgeschlossenes Ergebnis.';
      case 'Modellstream abgeschnitten':
        return 'Die Modellausgabe endete vorzeitig. Ich kann nur den bestätigten Text weiterverwenden.';
      case 'Modellstream empfangen':
        return event.meta.reason === 'output_truncated'
          ? 'Das Modell hat nur eine abgeschnittene Ausgabe geliefert. Ich prüfe, ob eine sichere Fortsetzung möglich ist.'
          : 'Die Modellantwort ist eingetroffen. Ich prüfe jetzt, ob sie vollständig und für den Auftrag verwendbar ist.';
      case 'Modellstream fehlgeschlagen':
      case 'Modellaufruf ohne Antwort':
        return 'Der Modellaufruf hat keine bestätigte vollständige Antwort geliefert. Die technischen Angaben helfen, die Ursache einzugrenzen.';
      case 'Kurze Werkzeugentscheidung nicht bestaetigt':
        return 'Ich konnte die Werkzeugentscheidung nicht sicher bestätigen. Deshalb starte ich keinen unbelegten Projektbau.';
      case 'Mara hat die Umsetzung des gespeicherten Plans gewaehlt':
        return 'Ich setze den bereits gespeicherten Projektplan um. Zuerst prüfe ich die Freigabe und die benötigten Quelldateien.';
      case 'Mara hat ein natives Werkzeug angefordert':
      case 'Mara hat selbst den Projekt-Writer aufgerufen':
        return 'Ich habe eine Werkzeugaktion gewählt. Vor der Ausführung prüft CoreUI die Freigabe und den passenden Ausführungsweg.';
      case 'Freigegebenes Webprojekt im Quelltextmodus gestartet':
        return 'Der Projektbau ist freigegeben. Ich erstelle und prüfe die Quelldateien einzeln; erst danach darf daraus ein ZIP entstehen.';
      case 'Homepage-Projektdateien erkannt':
        return 'Die Dateistruktur ist erkannt. Ich lasse die erforderlichen Quelldateien einzeln erzeugen und verifizieren.';
      case 'Einzeldatei angefordert':
      case 'Fehlende Quelldatei angefordert':
        return 'Ich fordere ' + file + ' an. Sie fehlt noch als bestätigte Quelldatei und muss vor der ZIP-Ausgabe geprüft werden.';
      case 'Quelldatei wird erzeugt':
        return 'Ich lasse ' + file + ' erzeugen. Ob der Inhalt verwendbar ist, steht erst nach der Prüfung fest.';
      case 'Quelldatei empfangen':
        return 'Ich habe ' + file + ' als gültige Quelle erhalten. Für das fertige Projekt müssen auch alle übrigen Dateien bestätigt sein.';
      case 'Quelldatei erkannt':
        return 'Ich habe ' + file + ' erfasst. Für das ZIP wird anschließend die vollständige Dateiliste geprüft.';
      case 'Quelldatei nicht lesbar':
      case 'Quelldatei ausgeblieben':
        return 'Für ' + file + ' fehlt noch ein nutzbares Ergebnis. Ich prüfe den Fehlergrund; ein fertiges Dateipaket kann ich damit nicht bestätigen.';
      case 'JavaScript-Ausgabe fortgesetzt':
      case 'Fehlender JavaScript-Teil wird erzeugt':
      case 'Quelldatei-Ausgabe fortgesetzt':
      case 'Fehlender Quelltext-Teil wird erzeugt':
        return 'Die Quelldatei war noch nicht vollständig. Ich lasse den fehlenden Abschnitt ergänzen, statt die bisherige Quelle zu verwerfen.';
      case 'JavaScript-Teil empfangen':
      case 'Quelltext-Teil empfangen':
        return 'Der zusätzliche Quelltextabschnitt ist eingetroffen. Jetzt muss die zusammengesetzte Datei nochmals geprüft werden.';
      case 'JavaScript-Fortsetzung unbrauchbar':
      case 'Quelldatei-Fortsetzung unbrauchbar':
        return 'Die ergänzte Quelldatei ist nicht verwendbar. Ich kann diese Quelle noch nicht als vollständig bestätigen.';
      case 'Projektquellen vollstaendig':
        return 'Alle angeforderten Quelltexte sind vorhanden. Als Nächstes muss das Dateipaket erstellt und sein Ergebnis geprüft werden.';
      case 'Projektquellen unvollstaendig':
      case 'Dateipaket unvollstaendig':
        return 'Mir fehlen noch gültige Projektdateien. Ich liefere deshalb kein unvollständiges ZIP als fertiges Ergebnis aus.';
      case 'Projektmanifest nicht nutzbar':
        return 'Der freigegebene Auftrag enthält noch kein gültiges Dateimanifest. Ohne dieses starte ich keinen unsicheren Build.';
      case 'Projektdateien konnten nicht gepackt werden':
        return 'Die Quelldateien konnten nicht sicher verpackt werden. Ich muss erst den gemeldeten Grund klären.';
      case 'Python-Job eingereiht':
        return 'Ich habe den Auftrag an den isolierten Python-Worker übergeben. Die Ausführung ist noch nicht bestätigt.';
      case 'Python-Ausfuehrung beendet':
        return 'Der Python-Worker ist erfolgreich fertig. Jetzt muss ich noch prüfen, welche Dateien wirklich registriert wurden.';
      case 'Python-Ausfuehrung fehlgeschlagen':
        return 'Der Python-Job ist fehlgeschlagen' + (event.meta.error_type ? ' (' + event.meta.error_type + ')' : '') + '. Ich prüfe die Diagnose, bevor ich eine Dateiausgabe behaupte.';
      case 'Python-Zeitlimit erreicht':
      case 'Dateiworker nicht erreichbar':
        return 'Der Dateiauftrag konnte nicht bestätigt abgeschlossen werden. Die Ausgabe darf daher nicht als fertig gelten.';
      case 'Korrekturversuch vorbereitet':
      case 'Dateiwerkzeug erneut angefordert':
      case 'Dateicode erneut angefordert':
        return 'Das erste Ergebnis hat noch nicht gereicht. Ich starte einen gezielten weiteren Versuch und prüfe das neue Ergebnis getrennt.';
      case 'Downloaddateien gesichert':
      case 'Reparierte Dateien gesichert':
        return 'Die erzeugten Dateien wurden als Downloads gesichert. Jetzt kann ich die bestätigten Ergebnisse ausliefern.';
      case 'Dateiregistrierung fehlgeschlagen':
      case 'Dateipaket nicht bereitgestellt':
      case 'Keine Downloaddatei bestaetigt':
        return 'Die Dateiübernahme wurde nicht bestätigt. Ich darf deshalb keinen funktionierenden Download versprechen.';
      case 'Markdown-Projektplan gespeichert':
      case 'Markdown-Projektplan abgeschlossen':
        return 'Ich habe die Projektplanung gespeichert. Bei der Umsetzung kann ich auf dieser Fassung aufbauen.';
      case 'Markdown-Planungsabschnitt gesichert':
        return 'Ich habe den bisherigen Planungsstand gesichert. Für einen fertigen Plan sind noch weitere Abschnitte erforderlich.';
      case 'Antwort blieb nach Fortsetzung unvollstaendig':
        return 'Meine Antwort ist trotz Fortsetzung noch unvollständig. Der vorhandene Teil bleibt erhalten; er ist kein bestätigter Abschluss.';
      case 'Modellstream unvollstaendig':
        return 'Die Modellübertragung war unvollständig. Ich prüfe den vorhandenen Werkzeug- oder Ersatzpfad, ohne den Abbruch zu verschweigen.';
      case 'Antwort gespeichert':
        return 'Die Antwort ist im privaten Verlauf gesichert. Den bestätigten Arbeitsweg kannst du später erneut aufklappen.';
      case 'Auftrag auf Wunsch abgebrochen':
        return 'Der Auftrag wurde auf Wunsch abgebrochen. Ich starte keine weitere Ausführung.';
      default:
        // Future and plugin-defined work events also get a truthful note,
        // without shipping one dedicated model-specific UI workaround.
        var step = '„' + event.title + '“';
        if (event.state === 'running') {
          return 'Ich führe gerade ' + step + ' aus. Der Abschluss steht noch aus.';
        }
        if (event.state === 'error') {
          return 'Bei ' + step + ' wurde ein Fehler protokolliert. Die technischen Angaben zeigen, was tatsächlich bekannt ist.';
        }
        if (event.state === 'success') {
          return 'Der Schritt ' + step + ' wurde erfolgreich abgeschlossen. Darauf kann der weitere Ablauf aufbauen.';
        }
        return 'Der Arbeitsschritt ' + step + ' wurde dokumentiert. Der weitere Verlauf zeigt, welches Ergebnis folgte.';
    }
  }

  function el(tag, cls, value) {
    var item = document.createElement(tag);
    if (cls) item.className = cls;
    if (value !== undefined) item.textContent = String(value);
    return item;
  }

  function normalize(raw) {
    if (!raw || !STATES[raw.state] || !KINDS[raw.kind]) return null;
    var title = String(raw.title || '').slice(0, 120);
    if (!title) return null;
    var meta = {};
    if (raw.meta && typeof raw.meta === 'object' && !Array.isArray(raw.meta)) {
      Object.keys(DETAIL_LABELS).forEach(function (key) {
        var value = raw.meta[key];
        if (typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value)) || typeof value === 'boolean') {
          meta[key] = String(value).slice(0, 110);
        }
      });
    }
    if (raw.kind === 'commentary' && raw.meta
        && typeof raw.meta.public_note === 'string') {
      var note = raw.meta.public_note.trim();
      if (note.length >= 12 && note.length <= 300
          && !/[<>\[\]{}\x00-\x1f]/.test(note)) meta.public_note = note;
    }
    if (raw.kind === 'commentary' && !meta.public_note) return null;
    return {
      id:Number(raw.id) || 0,kind:raw.kind,state:raw.state,
      title:title,meta:meta,time:String(raw.time || '').slice(0, 40)
    };
  }

  // Merge authenticated history with the SSE snapshot. Keep the server copy
  // authoritative, but do not lose live events if a reconnect returned no
  // timeline. Both sources pass the same strict public-event whitelist.
  function merge(serverEvents, liveEvents) {
    var combined = [];
    var seen = {};
    [serverEvents, liveEvents].forEach(function (source) {
      if (!Array.isArray(source)) return;
      source.forEach(function (raw) {
        var event = normalize(raw);
        if (!event) return;
        var key = event.id > 0
          ? 'id:' + event.id
          : [event.kind,event.state,event.title,event.time].join('|');
        if (seen[key]) return;
        seen[key] = true;
        combined.push(event);
      });
    });
    return combined.slice(-150);
  }

  function localTime(raw) {
    if (!raw) return '';
    var when = new Date(raw);
    if (!Number.isNaN(when.getTime())) {
      return when.toLocaleTimeString('de-DE', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    }
    return /^\d\d:\d\d(?::\d\d)?$/.test(raw) ? raw : '';
  }

  // Live events can arrive in a single SSE burst. Serialize only their visible
  // presentation, never the authenticated storage or the actual tool execution.
  // Reloaded history and reduced-motion users see the complete text immediately.
  function motionEnabled(body) {
    if (!body._workLive || body.hidden || typeof window.setTimeout !== 'function'
        || document.hidden === true) return false;
    return !(typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function followWork(body) {
    if (body && body._workLive && !body.hidden && body._workFollow !== false) {
      body.scrollTop = body.scrollHeight;
    }
  }

  function appendNow(body, event, animate, finished) {
    var entries = body.querySelector('.work-entries');
    if (!entries) {
      body.textContent = '';
      entries = el('div', 'work-entries');
      body.appendChild(entries);
    }
    if (event.id && entries.querySelector('[data-work-id="' + event.id + '"]')) return false;

    var entry = el('div', 'work-entry work-' + event.state);
    if (event.id) entry.setAttribute('data-work-id', String(event.id));
    entry.appendChild(el('span', 'work-state',
      event.kind === 'commentary' ? '✎' : event.state === 'success' ? '\u2713' :
      event.state === 'error' ? '!' : event.state === 'running' ? '\u2022' : 'i'));
    var info = el('div', 'work-info');
    var note = workNote(event);
    var title = el('div','work-title',event.title);
    // Keep the operational event title available to assistive technology,
    // but avoid repeating it above the public chat-style note.
    if (note) title.className += ' work-title-with-note';
    info.appendChild(title);
    var noteText = null;
    if (note) {
      var annotation = el('div','work-note');
      // Public notes read as natural chat prose. Provenance stays in the
      // validated event payload, never as a repeating UI badge.
      noteText = el('p','work-note-text',animate ? '' : note);
      annotation.appendChild(noteText);
      info.appendChild(annotation);
    }
    var keys = Object.keys(event.meta).filter(function (key) { return key !== 'public_note'; });
    if (keys.length) {
      var details = el('details','work-detail');
      details.appendChild(el('summary','', 'Technische Details'));
      var definition = el('dl','');
      keys.forEach(function (key) {
        definition.appendChild(el('dt','',DETAIL_LABELS[key]));
        definition.appendChild(el('dd','',event.meta[key]));
      });
      details.appendChild(definition);
      info.appendChild(details);
    }
    entry.appendChild(info);
    if (event.time) entry.appendChild(el('time','work-time',localTime(event.time)));
    entries.appendChild(entry);
    while (entries.children.length > 150) entries.removeChild(entries.firstChild);
    followWork(body);
    if (typeof body._onWorkEvent === 'function') body._onWorkEvent(event);

    if (!animate || !noteText || !note) {
      if (typeof finished === 'function') finished();
      return true;
    }
    var chars = Array.from(note);
    var active = {node:noteText,full:note,timer:0,pos:0};
    body._workActive = active;
    noteText.className += ' work-note-typing';
    function typeNext() {
      if (body._workActive !== active) return;
      if (!motionEnabled(body) || body.isConnected === false) {
        flushWork(body);
        return;
      }
      // Faster display after reconnect bursts, without discarding a single step.
      var backlog = (body._workQueue || []).length;
      var take = Math.min(18, 2 + backlog * 2);
      active.pos = Math.min(chars.length,active.pos + take);
      noteText.textContent = chars.slice(0, active.pos).join('');
      followWork(body);
      if (active.pos < chars.length) {
        active.timer = window.setTimeout(typeNext,Math.max(20,40 - backlog * 2));
      } else {
        noteText.className = 'work-note-text';
        body._workActive = null;
        finished();
      }
    }
    active.timer = window.setTimeout(typeNext,32);
    return true;
  }

  function drainWorkQueue(body) {
    if (!body || body._workBusy || body._workDelayTimer
        || !Array.isArray(body._workQueue) || !body._workQueue.length) return;
    if (!motionEnabled(body)) {
      flushWork(body);
      return;
    }
    var event = body._workQueue.shift();
    body._workBusy = true;
    if (!appendNow(body,event,true,function () {
      body._workBusy = false;
      if (body._workQueue.length) {
        body._workDelayTimer = window.setTimeout(function () {
          body._workDelayTimer = 0;
          drainWorkQueue(body);
        },80);
      }
    })) {
      body._workBusy = false;
      drainWorkQueue(body);
    }
  }

  function flushWork(body) {
    if (!body) return;
    if (body._workDelayTimer && typeof window.clearTimeout === 'function')
      window.clearTimeout(body._workDelayTimer);
    body._workDelayTimer = 0;
    var active = body._workActive;
    if (active) {
      if (active.timer && typeof window.clearTimeout === 'function')
        window.clearTimeout(active.timer);
      active.node.textContent = active.full;
      active.node.className = 'work-note-text';
      body._workActive = null;
    }
    body._workBusy = false;
    var remaining = Array.isArray(body._workQueue) ? body._workQueue.splice(0) : [];
    remaining.forEach(function (event) { appendNow(body,event,false); });
  }

  function add(body, raw) {
    if (!body) return false;
    var event = normalize(raw);
    if (!event) return false;
    var entries = body.querySelector('.work-entries');
    if (event.id && entries && entries.querySelector('[data-work-id="' + event.id + '"]'))
      return false;
    if (body._workQueue && event.id && body._workQueue.some(function (pending) {
      return pending.id === event.id;
    })) return false;

    if (!motionEnabled(body)) {
      if (body._workQueue && body._workQueue.length) flushWork(body);
      return appendNow(body,event,false);
    }
    if (!body._workQueue) body._workQueue = [];
    body._workQueue.push(event);
    drainWorkQueue(body);
    return true;
  }

  // Dedicated source-preview SSE events carry publicly generated file content,
  // never Ollama thinking. This ephemeral view is not written to the work log.
  function source(body, raw) {
    if (!body || !body._workLive || !raw || typeof raw !== 'object') return false;
    if (['begin','chunk','end'].indexOf(raw.phase) < 0) return false;
    var filename = String(raw.file_name || '');
    var attempt = Number(raw.attempt) || 1;
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|svg)$/.test(filename)
        || !Number.isInteger(attempt) || attempt < 1 || attempt > 50) return false;
    var panel = body._workSourcePanel || null;
    if (raw.phase === 'begin') {
      if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
      else if (panel && body.removeChild) body.removeChild(panel);
      panel = el('section','work-source');
      panel.setAttribute('aria-label','Live-Quelltext ' + filename);
      var header = el('div','work-source-header');
      header.appendChild(el('span','work-source-file',filename));
      header.appendChild(el('span','work-source-status','Wird geschrieben'));
      panel.appendChild(header);
      var pre = el('pre','work-source-scroll');
      pre.appendChild(el('code','work-source-code'));
      panel.appendChild(pre);
      pre._sourceFollow = true;
      pre.addEventListener('scroll', function () {
        pre._sourceFollow = pre.scrollHeight - pre.clientHeight - pre.scrollTop < 56;
      }, {passive:true});
      body.appendChild(panel);
      body._workSourcePanel = panel;
      body._workSourceName = filename;
      body._workSourceAttempt = attempt;
      return true;
    }
    if (!panel || body._workSourceName !== filename
        || body._workSourceAttempt !== attempt) return false;
    if (raw.phase === 'end') {
      var status = panel.querySelector('.work-source-status');
      if (status) status.textContent = 'Übertragung beendet · Prüfung läuft';
      return true;
    }
    if (typeof raw.text !== 'string' || !raw.text || raw.text.length > 2500)
      return false;
    var code = panel.querySelector('.work-source-code');
    if (!code) return false;
    code.textContent += raw.text; // Rendering must not hide the beginning of a valid file.
    var scroll = panel.querySelector('.work-source-scroll');
    if (scroll && scroll._sourceFollow !== false) scroll.scrollTop = scroll.scrollHeight;
    followWork(body);
    return true;
  }

  function create(options) {
    options = options || {};
    var initialEvents = merge(options.events, []);
    var live = !!options.live;
    var events = live ? [] : initialEvents;
    if (!live && events.length === 0) return null;
    var timeline = el('section','work-panel');
    timeline.setAttribute('aria-label','E.M.B.E.R. CoreAI Arbeitsweg und öffentliche Arbeitsnotizen');
    var toggle = el('button','work-toggle');
    toggle.type = 'button';
    var expanded = options.expanded === true || live;
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggle.appendChild(el('span','work-chevron','▸'));
    var heading = el('span','work-caption');
    toggle.appendChild(heading);
    var counters = el('span','work-counts');
    toggle.appendChild(counters);
    var digest = el('p','work-digest');
    var body = el('div','work-body');
    body._workLive = live;
    body._workFollow = true;
    body.addEventListener('scroll', function () {
      var gap = body.scrollHeight - body.clientHeight - body.scrollTop;
      body._workFollow = gap < 65;
    }, {passive:true});
    body.hidden = !expanded;
    if (live) body.appendChild(el('p','work-empty','Warte auf den ersten Arbeitsschritt ...'));

    function updateSummary() {
      var countLabel = events.length + (events.length === 1 ? ' Schritt' : ' Schritte');
      heading.textContent = live ? 'Arbeitsweg · laufend'
        : 'Arbeitsweg (' + countLabel + ') ' + (expanded ? 'ausblenden' : 'anzeigen');
      toggle.setAttribute('aria-label', heading.textContent);
      var errors = events.filter(function (e) { return e.state === 'error'; }).length;
      var tools = events.filter(function (e) { return e.kind === 'tool'; }).length;
      counters.textContent = countLabel + (tools ? ' · ' + tools + (tools === 1 ? ' Werkzeug' : ' Werkzeuge') : '')
        + (errors ? ' · ' + errors + ' Fehler' : '');
      var last = events.length ? events[events.length - 1] : null;
      // The final DB-save event must not conceal an unresolved file error.
      // Show the last substantive confirmed step in the collapsed preview.
      if (last && last.title === 'Antwort gespeichert') {
        var earlier = events.slice(0, -1).filter(function (item) {
          return workNote(item) && item.title !== 'Modellstream vorbereitet';
        });
        if (earlier.length) last = earlier[earlier.length - 1];
      }
      var note = workNote(last);
      digest.textContent = note;
      digest.hidden = !note || expanded;
    }

    body._onWorkEvent = function (event) {
      events = merge(events,[event]);
      updateSummary();
    };
    updateSummary();
    initialEvents.forEach(function (event) { add(body,event); });
    toggle.addEventListener('click',function () {
      expanded = !expanded;
      toggle.setAttribute('aria-expanded',expanded ? 'true' : 'false');
      body.hidden = !expanded;
      if (!expanded) flushWork(body);
      updateSummary();
      if (typeof options.onToggle === 'function') options.onToggle(expanded);
    });
    timeline.appendChild(toggle);
    timeline.appendChild(digest);
    timeline.appendChild(body);
    return timeline;
  }

  window.CoreUIWorkEvents = {create:create,add:add,source:source,flush:flushWork,normalize:normalize,merge:merge};
}());
