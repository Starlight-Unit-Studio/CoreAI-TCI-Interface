// P06: operational events live inline in CoreAI's normal chat answer.
// Events are supplied by the authenticated message history (or SSE), not a
// second per-message HTTP request. Never render model-authored HTML here.
(function () {
  'use strict';

  var STATES = {running:true,success:true,error:true,info:true};
  var KINDS = {run:true,model:true,tool:true,artifact:true,error:true,commentary:true};
  var DETAIL_LABELS = {
    model:'Core', job_id:'Job', exit_code:'Exitcode', duration_ms:'Laufzeit (ms)',
    error_type:'Fehlertyp', file_name:'Datei', file_count:'Dateien', attempt:'Versuch',
    http_code:'HTTP', reason:'Grund', source:'Quelle', elapsed_ms:'Dauer (ms)',
    source_chars:'Quelltext (Zeichen)', eval_count:'Ausgabe-Tokens',
    prompt_eval_count:'Eingabe-Tokens', plan_version:'Planversion',
    step:'Planungsabschnitt', done_reason:'Core-Endgrund',
    content_chars:'Antworttext (Zeichen)', thinking_chars:'Denkstatus (Zeichen)',
    continuation_segments:'Fortsetzungen', response_bytes:'Antwort (Bytes)',
    discarded_tool_body_bytes:'Verworfener Marker (Bytes)',
    marker_incomplete:'Marker unvollständig',
    context_tokens:'Kontextfenster (Tokens)',
    knowledge_hits:'Treffer', knowledge_sources:'Quellen'
  };

  // Bounded shape reasons of an unreadable tool reply (never model text).
  var TOOL_SHAPES = {
    multiple_tool_blocks:'mehrere Werkzeugblöcke in einer Antwort',
    tool_marker_in_code_fence:'Werkzeugblock in einem Codeblock',
    tool_block_unclosed:'schließender Marker fehlt',
    tool_markers_mismatched:'Marker passen nicht zusammen',
    tool_name_unknown:'unbekanntes Werkzeug',
    tool_body_too_short:'Inhalt zu kurz',
    tool_text_around_block:'Text mit Klammern oder Code um den Block',
    tool_marker_without_block:'Marker ohne Werkzeugblock'
  };

  // Das ist ein oeffentliches, aus bestaetigten Arbeitsereignissen
  // abgeleitetes Protokoll, nicht das private interne Modell-Reasoning.
  // Unbekannte Ereignisse erhalten keine erfundene Erklaerung.
  function workNote(event) {
    if (!event) return '';
    if (event.kind === 'commentary') return event.meta.public_note || '';
    var file = event.meta.file_name ? '„' + event.meta.file_name + '“' : 'die angeforderte Datei';
    var windowSize = Number(event.meta.context_tokens) > 0
      ? ' (' + Number(event.meta.context_tokens) + ' Tokens)' : '';
    if (event.meta.reason === 'context_window_full' && event.state !== 'success') {
      return 'Das Kontextfenster von Core war voll' + windowSize + '. Auftrag, Denkphase und Antwort '
        + 'passten nicht gemeinsam hinein, deshalb endete die Ausgabe vorzeitig.';
    }
    switch (event.title) {
      case 'Anhang wird angesehen':
        return 'Ich sehe mir den Anhang an. Bilder, Videos und PDF-Seiten laufen bewusst über den Bildpfad von Core, nicht über den Textstream.';
      case 'Privates Wissen durchsucht':
        return Number(event.meta.knowledge_hits) > 0
          ? 'Ich habe in deinem hochgeladenen Wissen (RAG-Lite) gesucht und '
            + Number(event.meta.knowledge_hits) + ' passende Abschnitte aus '
            + Number(event.meta.knowledge_sources || 1) + ' Quelle(n) gefunden'
            + (event.meta.reason === 'followup_context' ? ', anhand des bisherigen Gesprächs' : '') + '.'
          : 'Ich habe in deinem hochgeladenen Wissen (RAG-Lite) gesucht, aber keinen passenden Abschnitt gefunden.';
      case 'Kontextfenster ermittelt':
        return 'Der lokale Core arbeitet mit einem Kontextfenster' + windowSize + '. Ich teile Auftrag '
          + 'und Plan pro Datei so zu, dass Platz für den eigentlichen Quelltext bleibt.';
      case 'Projektdateien geplant':
        return Array.isArray(event.meta.file_list) && event.meta.file_list.length
          ? 'Ich erstelle ' + event.meta.file_list.length + ' Dateien und prüfe jede einzeln: '
            + event.meta.file_list.join(', ') + '.'
          : 'Ich erstelle die Projektdateien einzeln und prüfe jede, bevor daraus ein ZIP entsteht.';
      case 'Werkzeugantwort nicht lesbar':
        return 'Meine Werkzeugantwort hatte eine Form, die ich nicht sicher auswerten kann'
          + (TOOL_SHAPES[event.meta.reason] ? ' (' + TOOL_SHAPES[event.meta.reason] + ')' : '')
          + '. Daraus führe ich nichts aus.';
      case 'Werkzeugantwort wird einmal korrigiert':
        return 'Ich schreibe die Werkzeugantwort einmal in der vorgesehenen Form neu.';
      case 'Werkzeugantwort korrigiert':
        return 'Die korrigierte Antwort ist eindeutig. Ich setze sie jetzt um.';
      case 'Korrektur nicht lesbar':
        return 'Auch die Korrektur war nicht eindeutig'
          + (TOOL_SHAPES[event.meta.reason] ? ' (' + TOOL_SHAPES[event.meta.reason] + ')' : '')
          + '. Deshalb starte ich keine Aktion.';
      case 'Unvollstaendiger Python-Code nicht ausgefuehrt':
        return 'Mein Python-Skript wurde beim Schreiben abgeschnitten. Ich führe es deshalb nicht aus '
          + 'und behaupte keine Datei.';
      case 'Auftrag gestartet':
        return 'Ich übernehme den Auftrag und beginne mit den notwendigen Prüfungen.';
      case 'Kontext vorbereitet':
        return 'Ich habe den Kontext vorbereitet. Als Nächstes prüfe ich, welche Antwort oder Werkzeugaktion erforderlich ist.';
      case 'Modellstream vorbereitet':
        return 'Ich bereite Core vor. Eine Antwort liegt noch nicht vor.';
      case 'Modellstream gestartet':
      case 'Modellaufruf gestartet':
        return 'Core arbeitet jetzt. Ich warte auf das vollständige Ergebnis.';
      case 'Modellstream abgeschnitten':
        return 'Meine Ausgabe endete vorzeitig. Ich kann nur den bestätigten Text weiterverwenden.';
      case 'Modellstream empfangen':
        return event.meta.reason === 'output_truncated'
          ? 'Core hat nur eine unvollständige Ausgabe geliefert. Ich prüfe, ob ich sie sicher fortsetzen kann.'
          : 'Meine Antwort ist eingetroffen. Ich prüfe jetzt, ob sie vollständig und für den Auftrag verwendbar ist.';
      case 'Modellstream fehlgeschlagen':
      case 'Modellaufruf ohne Antwort':
        return 'Core hat keine bestätigte vollständige Antwort geliefert. Ich prüfe die Ursache.';
      case 'Kurze Werkzeugentscheidung nicht bestaetigt':
        return 'Ich konnte die Werkzeugentscheidung nicht sicher bestätigen. Deshalb starte ich keinen unbelegten Projektbau.';
      case 'Mara hat die Umsetzung des gespeicherten Plans gewaehlt':
        return 'Ich setze den bereits gespeicherten Projektplan um. Zuerst prüfe ich die Freigabe und die benötigten Quelldateien.';
      case 'Mara hat ein natives Werkzeug angefordert':
      case 'Mara hat selbst den Projekt-Writer aufgerufen':
        return 'Ich habe eine Werkzeugaktion gewählt und prüfe vor der Ausführung die Freigabe sowie den passenden Ausführungsweg.';
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
        if (event.meta.reason === 'model_output_truncated'
            && Number(event.meta.source_chars) === 0) {
          return 'Core hat für ' + file + ' noch keinen sichtbaren Quelltext geliefert. Ich kann daraus keine Datei bestätigen; im Detail stehen Ausgabe- und Denkstatus-Längen.';
        }
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
        return 'Die Übertragung von Core war unvollständig. Ich prüfe den Werkzeug- oder Ersatzweg, ohne den Abbruch zu verschweigen.';
      case 'Antwort gespeichert':
        return 'Die Antwort ist im privaten Verlauf gesichert. Den bestätigten Arbeitsweg kannst du später erneut aufklappen.';
      case 'Auftrag auf Wunsch abgebrochen':
        return 'Der Auftrag wurde auf Wunsch abgebrochen. Ich starte keine weitere Ausführung.';
      default:
        // Future and plugin-defined work events also get a truthful note,
        // without shipping one dedicated model-specific UI workaround.
        var step = '„' + displayTitle(event.title) + '“';
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
    // Planned project files for the task card: basenames only, never paths.
    if (raw.meta && Array.isArray(raw.meta.file_list)) {
      var planned = raw.meta.file_list.filter(function (name) {
        return typeof name === 'string' && /^[A-Za-z0-9][A-Za-z0-9._-]{0,90}$/.test(name);
      }).slice(0, 30);
      if (planned.length) meta.file_list = planned;
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

  // Starlight lore: an AI here is a Core, not "a model". Internal event
  // names, API fields and logs keep their technical identifiers; only the
  // visible label is phrased naturally.
  var DISPLAY_TITLES = {
    'Modellstream vorbereitet':'Core wird vorbereitet',
    'Modellstream gestartet':'Core arbeitet',
    'Modellaufruf gestartet':'Core arbeitet',
    'Modellstream empfangen':'Antwort von Core empfangen',
    'Modellantwort empfangen':'Antwort von Core empfangen',
    'Modellantwort erhalten':'Antwort von Core erhalten',
    'Modellstream abgeschnitten':'Ausgabe von Core abgeschnitten',
    'Modellstream fehlgeschlagen':'Core ohne vollständige Antwort',
    'Modellaufruf ohne Antwort':'Core ohne Antwort',
    'Modellstream unvollstaendig':'Übertragung von Core unvollständig',
    'Modellwarteschlange nicht verfuegbar':'Warteschlange von Core nicht verfügbar'
  };
  function displayTitle(title) {
    var text = String(title || '');
    if (Object.prototype.hasOwnProperty.call(DISPLAY_TITLES, text)) return DISPLAY_TITLES[text];
    // Future titles: never show the word "Modell" in the interface.
    return text.replace(/Modellantwort/g, 'Antwort von Core')
      .replace(/Modell(?:stream|aufruf)?/g, 'Core');
  }

  // Claude-Code-like flow: Mara's meaningful progress reads as normal text,
  // routine operations fold into ONE muted, expandable line per sequence.
  var NARRATED = {
    'Kontextfenster ermittelt':1,'Projektdateien geplant':1,
    'Mara hat die Umsetzung des gespeicherten Plans gewaehlt':1,
    'Mara hat ein natives Werkzeug angefordert':1,
    'Mara hat selbst den Projekt-Writer aufgerufen':1,
    'Freigegebenes Webprojekt im Quelltextmodus gestartet':1,
    'Homepage-Projektdateien erkannt':1,'Projektquellen vollstaendig':1,
    'Downloaddateien gesichert':1,'Reparierte Dateien gesichert':1,
    'Markdown-Projektplan gespeichert':1,'Markdown-Projektplan abgeschlossen':1,
    'Werkzeugantwort wird einmal korrigiert':1,'Werkzeugantwort korrigiert':1,
    'Korrekturversuch vorbereitet':1,'Unvollstaendiger Python-Code nicht ausgefuehrt':1,
    'Antwort blieb nach Fortsetzung unvollstaendig':1,'Auftrag auf Wunsch abgebrochen':1
  };
  function isNarration(event) {
    return !!event && (event.kind === 'commentary' || event.state === 'error'
      || NARRATED[event.title] === 1);
  }
  function stepLabel(event) {
    return displayTitle(event.title) + (event.meta.file_name ? ' · ' + event.meta.file_name : '');
  }
  function updateRun(run) {
    var count = run._runCount;
    run._runToggle.textContent = '';
    run._runToggle.appendChild(el('span','work-run-text',
      (count === 1 ? '1 Schritt' : count + ' Schritte') + ' · ' + run._runLatest));
    run._runToggle.appendChild(el('span','work-run-chevron','▸'));
    run._runToggle.setAttribute('aria-expanded', run._runOpen ? 'true' : 'false');
  }
  function setRunOpen(run, open) {
    run._runOpen = open;
    run._runRow.hidden = !open;
    run._runMembers.forEach(function (member) { member.hidden = !open; });
    updateRun(run);
  }

  // Project file checklist, derived only from confirmed public events.
  var FILE_DONE = {'Quelldatei erkannt':1,'Quelldatei empfangen':1};
  function taskList(events) {
    var order = [];
    var states = {};
    function ensure(name) {
      if (Object.prototype.hasOwnProperty.call(states, name)) return;
      order.push(name);
      states[name] = 'pending';
    }
    events.forEach(function (event) {
      if (Array.isArray(event.meta.file_list)) event.meta.file_list.forEach(ensure);
      var name = event.meta.file_name;
      if (typeof name !== 'string' || !/\.(?:html?|css|js|json|svg)$/i.test(name)) return;
      ensure(name);
      if (FILE_DONE[event.title] === 1 && event.state !== 'error') states[name] = 'done';
      else if (states[name] !== 'done') states[name] = event.state === 'error' ? 'error' : 'running';
    });
    if (events.some(function (event) { return event.title === 'Projektquellen vollstaendig'; })) {
      order.forEach(function (name) { states[name] = 'done'; });
    }
    return order.map(function (name) { return {name:name,state:states[name]}; });
  }

  var ACTIVITY = {
    'Auftrag gestartet':'Ich starte den Auftrag','Kontext vorbereitet':'Kontext vorbereitet',
    'Modellstream vorbereitet':'Core wird vorbereitet','Modellstream gestartet':'Core denkt nach',
    'Modellaufruf gestartet':'Core denkt nach','Modellstream empfangen':'Ich prüfe die Antwort',
    'Einzeldatei angefordert':'Fordere {f} an','Fehlende Quelldatei angefordert':'Fordere {f} an',
    'Quelldatei wird erzeugt':'Schreibe {f}','Quelldatei empfangen':'Prüfe {f}',
    'Fehlender Quelltext-Teil wird erzeugt':'Setze {f} fort',
    'Fehlender JavaScript-Teil wird erzeugt':'Setze {f} fort',
    'Python-Job eingereiht':'Python läuft','Werkzeugantwort wird einmal korrigiert':'Korrigiere die Werkzeugantwort',
    'Mara hat selbst den Projekt-Writer aufgerufen':'Starte den Projekt-Writer',
    'Projektdateien geplant':'Plane die Projektdateien','Kontextfenster ermittelt':'Plane den Kontext',
    'Privates Wissen durchsucht':'Durchsuche dein Wissen','Anhang wird angesehen':'Sehe mir den Anhang an'
  };
  function activity(event) {
    if (!event) return 'Warte auf den ersten Schritt';
    var text = ACTIVITY[event.title];
    if (!text) return event.state === 'running' ? displayTitle(event.title) : 'Arbeite weiter';
    return text.replace('{f}', event.meta.file_name || 'die Datei');
  }
  function formatDuration(ms) {
    var seconds = Math.max(0, Math.round(ms / 1000));
    var minutes = Math.floor(seconds / 60);
    seconds = seconds % 60;
    return minutes > 0 ? minutes + ' Min. ' + seconds + ' Sek.' : seconds + ' Sek.';
  }
  function formatTokens(count) {
    return count >= 1000
      ? String(Math.round(count / 100) / 10).replace('.', ',') + 'k Tokens'
      : count + ' Tokens';
  }
  function eventMs(event) {
    var when = event && event.time ? Date.parse(event.time) : NaN;
    return Number.isNaN(when) ? 0 : when;
  }

  function appendNow(body, event, animate, finished) {
    var entries = body.querySelector('.work-entries');
    if (!entries) {
      body.textContent = '';
      entries = el('div', 'work-entries');
      body.appendChild(entries);
    }
    if (event.id && entries.querySelector('[data-work-id="' + event.id + '"]')) return false;

    var narration = isNarration(event);
    // Only narration is typed; a folded routine step appears at once.
    animate = animate && narration;
    var entry = el('div', 'work-entry work-' + event.state
      + (narration ? ' work-narration' : ' work-step'));
    if (event.id) entry.setAttribute('data-work-id', String(event.id));
    var icon = el('span', 'work-state',
      event.kind === 'commentary' ? '✎' : event.state === 'success' ? '\u2713' :
      event.state === 'error' ? '!' : event.state === 'running' ? '\u2022' : 'i');
    var info = el('div', 'work-info');
    var note = workNote(event);
    var title = el('div','work-title',narration ? displayTitle(event.title) : stepLabel(event));
    // Keep the operational event title available to assistive technology,
    // but avoid repeating it above the public chat-style note.
    if (note && narration) title.className += ' work-title-with-note';
    info.appendChild(title);
    var noteText = null;
    var detailTarget = info;
    if (note) {
      var annotation = el('div',narration ? 'work-note' : 'work-note work-step-note');
      // Public progress is a normal chat paragraph; no provenance badges
      // or repetitive technical labels between paragraphs.
      noteText = el('p','work-note-text',animate ? '' : note);
      annotation.appendChild(noteText);
      info.appendChild(annotation);
      detailTarget = annotation;
    }
    var keys = Object.keys(event.meta).filter(function (key) {
      return Object.prototype.hasOwnProperty.call(DETAIL_LABELS, key);
    });
    if (keys.length || event.time) {
      var details = el('details','work-detail');
      var summary = el('summary','work-detail-toggle','ⓘ');
      summary.setAttribute('aria-label','Technische Details');
      summary.title = 'Technische Details';
      details.appendChild(summary);
      var definition = el('dl','');
      keys.forEach(function (key) {
        definition.appendChild(el('dt','',DETAIL_LABELS[key]));
        definition.appendChild(el('dd','',event.meta[key]));
      });
      if (event.time) {
        definition.appendChild(el('dt','','Uhrzeit'));
        definition.appendChild(el('dd','',localTime(event.time)));
      }
      details.appendChild(definition);
      detailTarget.appendChild(details);
    }
    if (narration) {
      entries._openRun = null;
      entry.appendChild(icon);
      entry.appendChild(info);
    } else {
      var row = el('div','work-step-row');
      row.appendChild(icon);
      row.appendChild(info);
      var run = entries._openRun;
      if (run) {
        entry.appendChild(row);
        entry.hidden = !run._runOpen;
        run._runMembers.push(entry);
        run._runCount++;
        run._runLatest = stepLabel(event);
        updateRun(run);
      } else {
        var runToggle = el('button','work-run-toggle');
        runToggle.type = 'button';
        entry.className += ' work-run-head';
        entry._runToggle = runToggle;
        entry._runRow = row;
        entry._runMembers = [];
        entry._runCount = 1;
        entry._runLatest = stepLabel(event);
        entry._runOpen = false;
        runToggle.addEventListener('click', function () { setRunOpen(entry, !entry._runOpen); });
        row.hidden = true;
        entry.appendChild(runToggle);
        entry.appendChild(row);
        updateRun(entry);
        entries._openRun = entry;
      }
    }
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
      var codeOnEnd = panel.querySelector('.work-source-code');
      if (status) status.textContent = codeOnEnd && codeOnEnd.textContent
        ? 'Übertragung beendet · Prüfung läuft'
        : 'Kein sichtbarer Quelltext empfangen';
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
    var timeline = el('section','work-panel work-flow');
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
    var tasks = el('div','work-tasks');
    tasks.hidden = true;
    var status = live ? el('div','work-status') : null;
    var startedAt = Number(options.startedAt) || 0;

    function renderTasks() {
      var list = taskList(events);
      tasks.textContent = '';
      tasks.hidden = list.length < 2 || body.hidden;
      if (tasks.hidden) return;
      var done = list.filter(function (task) { return task.state === 'done'; }).length;
      tasks.appendChild(el('div','work-tasks-head','Projektdateien · ' + done + ' von ' + list.length + ' fertig'));
      var listEl = el('ul','work-task-list');
      list.forEach(function (task) {
        var item = el('li','work-task work-task-' + task.state);
        item.appendChild(el('span','work-task-mark',
          task.state === 'done' ? '\u2713' : task.state === 'error' ? '!' : ''));
        item.appendChild(el('span','work-task-name',task.name));
        listEl.appendChild(item);
      });
      tasks.appendChild(listEl);
    }

    // One live line like a terminal agent: what happens now, for how long,
    // and how many tokens Core has written in this run.
    function renderStatus() {
      if (!status) return;
      var tokens = events.reduce(function (sum, event) {
        return sum + (Number(event.meta.eval_count) || 0);
      }, 0);
      var start = startedAt || eventMs(events[0]) || Date.now();
      status.textContent = '';
      var dots = el('span','work-status-dots');
      dots.appendChild(el('i',''));
      dots.appendChild(el('i',''));
      dots.appendChild(el('i',''));
      status.appendChild(dots);
      status.appendChild(el('span','work-status-text',
        activity(events[events.length - 1]) + ' · ' + formatDuration(Date.now() - start)
        + (tokens > 0 ? ' · ' + formatTokens(tokens) : '')));
    }

    function updateSummary() {
      var countLabel = events.length + (events.length === 1 ? ' Schritt' : ' Schritte');
      heading.textContent = live ? 'Arbeitsweg · laufend'
        : 'Arbeitsweg (' + countLabel + ') ' + (expanded ? 'ausblenden' : 'anzeigen');
      toggle.setAttribute('aria-label', heading.textContent);
      var errors = events.filter(function (e) { return e.state === 'error'; }).length;
      var tools = events.filter(function (e) { return e.kind === 'tool'; }).length;
      var span = !live && events.length > 1
        ? eventMs(events[events.length - 1]) - eventMs(events[0]) : 0;
      counters.textContent = countLabel + (tools ? ' · ' + tools + (tools === 1 ? ' Werkzeug' : ' Werkzeuge') : '')
        + (errors ? ' · ' + errors + ' Fehler' : '')
        + (span >= 1000 && eventMs(events[0]) > 0 ? ' · ' + formatDuration(span) : '');
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
      renderTasks();
      renderStatus();
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
    timeline.appendChild(tasks);
    if (status) {
      timeline.appendChild(status);
      if (typeof window.setInterval === 'function') {
        var ticker = window.setInterval(function () {
          // The finished reply replaces the live row; stop with it.
          if (timeline.isConnected === false && timeline._wasConnected) {
            window.clearInterval(ticker);
            return;
          }
          if (timeline.isConnected) timeline._wasConnected = true;
          renderStatus();
        }, 1000);
      }
    }
    return timeline;
  }

  window.CoreUIWorkEvents = {create:create,add:add,source:source,flush:flushWork,normalize:normalize,
    merge:merge,displayTitle:displayTitle};
}());
