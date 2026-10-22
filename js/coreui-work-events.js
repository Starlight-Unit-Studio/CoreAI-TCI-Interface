// P06: operational events live inline in E.M.B.E.R.'s normal chat answer.
// Events are supplied by the authenticated message history (or SSE), not a
// second per-message HTTP request. Never render model-authored HTML here.
(function () {
  'use strict';

  var STATES = {running:true,success:true,error:true,info:true};
  var KINDS = {run:true,model:true,tool:true,artifact:true,error:true};
  var DETAIL_LABELS = {
    model:'Modell', job_id:'Job', exit_code:'Exitcode', duration_ms:'Laufzeit (ms)',
    error_type:'Fehlertyp', file_name:'Datei', file_count:'Dateien', attempt:'Versuch',
    http_code:'HTTP', reason:'Grund', source:'Quelle', elapsed_ms:'Dauer (ms)',
    source_chars:'Quelltext (Zeichen)', eval_count:'Ausgabe-Tokens',
    prompt_eval_count:'Eingabe-Tokens'
  };

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
        if (typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value))) {
          meta[key] = String(value).slice(0, 110);
        }
      });
    }
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

  function add(body, raw) {
    if (!body) return false;
    var event = normalize(raw);
    if (!event) return false;
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
      event.state === 'success' ? '\u2713' :
      event.state === 'error' ? '!' : event.state === 'running' ? '\u2022' : 'i'));
    var info = el('div', 'work-info');
    info.appendChild(el('div','work-title',event.title));
    var keys = Object.keys(event.meta);
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
    return true;
  }

  function create(options) {
    options = options || {};
    var events = merge(options.events, []);
    var live = !!options.live;
    if (!live && events.length === 0) return null;
    var timeline = el('section','work-panel');
    timeline.setAttribute('aria-label','E.M.B.E.R. CoreAI Arbeitsschritte');
    var toggle = el('button','work-toggle');
    toggle.type = 'button';
    var expanded = options.expanded === true || live;
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggle.appendChild(el('span','work-chevron','\u25b8'));
    var caption = el('span','work-caption');
    function updateCaption() {
      caption.textContent = live ? 'E.M.B.E.R. CoreAI arbeitet'
        : 'Arbeitsweg (' + events.length + ' Schritte) ' + (expanded ? 'ausblenden' : 'anzeigen');
      toggle.setAttribute('aria-label', caption.textContent);
    }
    updateCaption();
    toggle.appendChild(caption);
    var body = el('div','work-body');
    body.hidden = !expanded;
    if (live) body.appendChild(el('p','work-empty','Warte auf den ersten Arbeitsschritt ...'));
    events.forEach(function (event) { add(body,event); });
    toggle.addEventListener('click',function () {
      expanded = !expanded;
      toggle.setAttribute('aria-expanded',expanded ? 'true' : 'false');
      body.hidden = !expanded;
      updateCaption();
    });
    timeline.appendChild(toggle);
    timeline.appendChild(body);
    return timeline;
  }

  window.CoreUIWorkEvents = {create:create,add:add,normalize:normalize,merge:merge};
}());
