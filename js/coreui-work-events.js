// Ember CoreUI P06: visible *operational* steps, never raw model reasoning.
(function () {
  'use strict';

  var VALID_STATES = {running:true,success:true,error:true,info:true};
  var VALID_KINDS = {run:true,model:true,tool:true,artifact:true,error:true};
  var META_LABELS = {
    model:'Modell', job_id:'Job', exit_code:'Exitcode', duration_ms:'Laufzeit (ms)',
    error_type:'Fehlertyp', file_name:'Datei', file_count:'Dateien', attempt:'Versuch',
    http_code:'HTTP', reason:'Grund', source:'Quelle', elapsed_ms:'Dauer (ms)'
  };

  function text(tag, className, value) {
    var el = document.createElement(tag);
    if (className) el.className = className;
    el.textContent = String(value == null ? '' : value);
    return el;
  }
  function normalize(raw) {
    if (!raw || !VALID_STATES[raw.state] || !VALID_KINDS[raw.kind]) return null;
    var title = String(raw.title || '').slice(0, 120);
    if (!title) return null;
    var meta = {};
    if (raw.meta && typeof raw.meta === 'object' && !Array.isArray(raw.meta)) {
      Object.keys(META_LABELS).forEach(function (key) {
        var value = raw.meta[key];
        if (typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value))) {
          meta[key] = String(value).slice(0, 110);
        }
      });
    }
    return {id:Number(raw.id) || 0,kind:raw.kind,state:raw.state,
      title:title,time:String(raw.time || '').slice(0, 30),meta:meta};
  }
  function add(body, raw) {
    if (!body) return;
    var event = normalize(raw);
    if (!event) return;
    var log = body.querySelector('.work-entries');
    if (!log) {
      body.textContent = '';
      log = text('div', 'work-entries', '');
      body.appendChild(log);
    }
    if (event.id && log.querySelector('[data-work-id="' + String(event.id) + '"]')) return;
    var line = text('div', 'work-entry work-' + event.state, '');
    if (event.id) line.setAttribute('data-work-id', String(event.id));
    line.appendChild(text('span','work-state',
      event.state === 'running' ? '...' : event.state === 'success' ? 'OK' : event.state === 'error' ? '!' : 'i'));
    var info = text('div','work-info','');
    info.appendChild(text('div','work-title',event.title));
    var labels = Object.keys(event.meta);
    if (labels.length) {
      var detail = document.createElement('details');
      detail.className = 'work-detail';
      detail.appendChild(text('summary', '', 'Technische Details'));
      var dl = document.createElement('dl');
      labels.forEach(function (key) {
        var dt = text('dt','',META_LABELS[key]);
        var dd = text('dd','',event.meta[key]);
        dl.appendChild(dt);
        dl.appendChild(dd);
      });
      detail.appendChild(dl);
      info.appendChild(detail);
    }
    line.appendChild(info);
    if (event.time) line.appendChild(text('time','work-time',event.time));
    log.appendChild(line);
    while (log.children.length > 150) log.removeChild(log.firstChild);
  }

  function create(options) {
    options = options || {};
    var panel = text('section','work-panel','');
    panel.setAttribute('aria-label','Arbeitsverlauf von E.M.B.E.R.');
    var toggle = text('button','work-toggle','');
    toggle.type = 'button';
    var open = !!options.expanded;
    toggle.setAttribute('aria-expanded',String(open));
    var icon = text('span','work-chevron','\u25b8');
    var title = text('span','work-caption','ARBEITSSCHRITTE');
    toggle.appendChild(icon);
    toggle.appendChild(title);
    var body = text('div','work-body','');
    body.hidden = !open;
    body.appendChild(text('div','work-empty','Arbeitsereignisse laden ...'));
    panel.appendChild(toggle);
    panel.appendChild(body);
    var loaded = false;
    var loading = false;

    function load() {
      if (loaded || loading || !options.turnId || !options.responseId || !options.sessionId || !options.apiBase) return;
      loading = true;
      var url = options.apiBase + '/console_work_events.php?session_id='
        + encodeURIComponent(String(options.sessionId)) + '&turn_id=' + encodeURIComponent(String(options.turnId))
        + '&response_id=' + encodeURIComponent(String(options.responseId));
      fetch(url, {credentials:'include',cache:'no-store'})
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (result) {
          if (!result || !result.ok || !Array.isArray(result.events)) throw new Error('events_unavailable');
          body.textContent = '';
          if (!result.events.length) body.appendChild(text('div','work-empty','Fuer diesen Turn sind keine Arbeitsereignisse vorhanden.'));
          result.events.forEach(function (event) { add(body,event); });
          loaded = true;
        })
        .catch(function () {
          body.textContent = '';
          body.appendChild(text('div','work-empty','Arbeitsverlauf gerade nicht abrufbar. Erneut aufklappen.'));
        })
        .finally(function () { loading = false; });
    }
    toggle.addEventListener('click',function () {
      open = !open;
      toggle.setAttribute('aria-expanded',String(open));
      body.hidden = !open;
      if (open) load();
    });
    if (open) load();
    return panel;
  }
  window.CoreUIWorkEvents = {create:create,add:add};
}());
