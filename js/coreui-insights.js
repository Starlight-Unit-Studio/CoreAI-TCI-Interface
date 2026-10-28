/* CoreAI info boxes: Core, Kontext, Werkzeuge and Projekt of the active
   session. Wide screens show them as a right column; narrower screens get
   chip buttons that open one box at a time. Read-only, text-only DOM. */
(function (root) {
  'use strict';

  var BOXES = [
    {id:'core', label:'Core', icon:'◈'},
    {id:'context', label:'Kontext', icon:'◔'},
    {id:'tools', label:'Werkzeuge', icon:'⌁'},
    {id:'project', label:'Projekt', icon:'▤'}
  ];
  var SOURCES = {
    loaded:'geladener Core', modelfile:'Modelfile', profile:'KI-Profil',
    remembered:'zuletzt gemessen', 'default':'Ollama-Standard'
  };
  var STORAGE_KEY = 'coreai.insights.open';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = String(text);
    return node;
  }
  function count(value) {
    return Math.max(0, Math.round(Number(value) || 0)).toLocaleString('de-DE');
  }
  function shortTokens(value) {
    var n = Math.max(0, Number(value) || 0);
    if (n >= 1000) return String(Math.round(n / 100) / 10).replace('.', ',') + 'k';
    return String(Math.round(n));
  }
  function bytes(value) {
    var n = Math.max(0, Number(value) || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return String(Math.round(n / 102.4) / 10).replace('.', ',') + ' KB';
    return String(Math.round(n / 104857.6) / 10).replace('.', ',') + ' MB';
  }
  function safeUrl(value) {
    var url = String(value || '');
    return /^(?:\/|api\/)[A-Za-z0-9_./?=&%-]+$/.test(url) ? url : '';
  }

  function readOpen() {
    var fallback = {core:true, context:true, tools:false, project:true};
    try {
      var saved = JSON.parse(root.localStorage.getItem(STORAGE_KEY) || 'null');
      if (saved && typeof saved === 'object') {
        BOXES.forEach(function (box) {
          if (typeof saved[box.id] === 'boolean') fallback[box.id] = saved[box.id];
        });
      }
    } catch (e) {}
    return fallback;
  }
  function writeOpen(open) {
    try { root.localStorage.setItem(STORAGE_KEY, JSON.stringify(open)); } catch (e) {}
  }

  function rows(body, pairs) {
    var list = el('dl', 'insight-rows');
    pairs.forEach(function (pair) {
      if (!pair) return;
      var row = el('div', 'insight-row');
      row.appendChild(el('dt', '', pair[0]));
      row.appendChild(el('dd', '', pair[1]));
      list.appendChild(row);
    });
    body.appendChild(list);
  }

  function create(options) {
    options = options || {};
    var aside = options.aside;
    var chips = options.chips;
    if (!aside && !chips) return null;
    var apiBase = String(options.apiBase || 'api');
    var state = {data:null, live:{}, sessionId:'', open:readOpen(), chip:'', request:0, failed:false};
    var UNREACHABLE = 'Die Infodaten sind gerade nicht erreichbar. Nach der nächsten Antwort versuche ich es erneut.';
    var bodies = {};
    var heads = {};

    function core() {
      var data = state.data && state.data.core ? state.data.core : {};
      var model = state.live.model || data.model || '';
      var cloud = /(?:^|[:._-])cloud(?:$|[:._-])/i.test(model) || data.runtime === 'cloud';
      var windowSize = cloud ? null : (state.live.context_tokens || data.context_tokens || null);
      var profile = data.context_source === 'profile';
      return {model:model, cloud:cloud, windowSize:windowSize,
        source:profile ? 'profile' : (state.live.context_tokens ? 'loaded' : data.context_source),
        loaded:cloud ? null : (Number(data.context_loaded) || null),
        thinking:data.thinking !== false};
    }

    function renderCore(body) {
      var info = core();
      if (!info.model) {
        body.appendChild(el('p', 'insight-empty', 'Lade Core-Status ...'));
        return;
      }
      rows(body, [
        // 0.5.76: TCI name like in the Core chip; technical names are in its legend.
        ['Core', root.CoreUICoreMode && typeof root.CoreUICoreMode.label === 'function'
          ? root.CoreUICoreMode.label(info.model) : info.model],
        ['Ausführung', info.cloud ? 'Cloud über Ollama' : 'Lokal über Ollama'],
        ['Kontextfenster', info.cloud ? 'vom Cloud-Anbieter'
          : count(info.windowSize) + ' Tokens' + (SOURCES[info.source] ? ' · ' + SOURCES[info.source] : '')],
        info.loaded && info.windowSize && info.loaded !== Number(info.windowSize)
          ? ['Von Ollama geladen', count(info.loaded) + ' Tokens · wechselt mit der nächsten Anfrage'] : null,
        ['Denkmodus', info.thinking ? 'an' : 'aus'],
        state.data && state.data.session_title ? ['Sitzung', state.data.session_title] : null
      ]);
    }

    function renderContext(body) {
      var usage = state.live.usage || (state.data && state.data.usage) || null;
      if (!usage) {
        body.appendChild(el('p', 'insight-empty', 'Core hat in dieser Sitzung noch nicht geantwortet.'));
        return;
      }
      var prompt = Number(usage.prompt_tokens) || 0;
      var output = Number(usage.output_tokens) || 0;
      var info = core();
      var windowSize = info.cloud ? 0 : (Number(usage.context_tokens) || Number(info.windowSize) || 0);
      if (windowSize > 0) {
        var used = Math.min(windowSize, prompt + output);
        var percent = Math.round(used / windowSize * 1000) / 10;
        var head = el('div', 'insight-usage-head');
        head.appendChild(el('span', 'insight-usage-percent',
          String(percent).replace('.', ',') + ' % genutzt'));
        head.appendChild(el('span', 'insight-usage-total',
          shortTokens(used) + ' / ' + shortTokens(windowSize)));
        body.appendChild(head);
        var bar = el('div', 'insight-bar');
        var input = el('span', 'insight-bar-input');
        input.style.width = Math.min(100, prompt / windowSize * 100) + '%';
        var out = el('span', 'insight-bar-output');
        out.style.width = Math.min(100, output / windowSize * 100) + '%';
        bar.appendChild(input);
        bar.appendChild(out);
        body.appendChild(bar);
      }
      var legend = el('ul', 'insight-legend');
      [['input', 'Eingabe', prompt], ['output', 'Ausgabe', output],
       windowSize > 0 ? ['free', 'Frei', Math.max(0, windowSize - prompt - output)] : null]
        .forEach(function (item) {
          if (!item) return;
          var entry = el('li', 'insight-legend-' + item[0]);
          entry.appendChild(el('i', ''));
          entry.appendChild(el('span', 'insight-legend-label', item[1]));
          entry.appendChild(el('span', 'insight-legend-value', count(item[2])));
          legend.appendChild(entry);
        });
      body.appendChild(legend);
      body.appendChild(el('p', 'insight-note', windowSize > 0
        ? 'Stand der letzten Core-Antwort in dieser Sitzung.'
        : 'Stand der letzten Core-Antwort. Das Fenster bestimmt der Cloud-Anbieter.'));
    }

    function renderTools(body) {
      var tools = state.data && Array.isArray(state.data.tools) ? state.data.tools : [];
      if (!tools.length) {
        body.appendChild(el('p', 'insight-empty', state.failed ? UNREACHABLE : 'Lade Werkzeuge ...'));
        return;
      }
      var list = el('ul', 'insight-tools');
      tools.forEach(function (tool) {
        var item = el('li', 'insight-tool ' + (tool.on ? 'is-on' : 'is-off'));
        item.appendChild(el('i', ''));
        item.appendChild(el('span', 'insight-tool-label', tool.label));
        item.appendChild(el('span', 'insight-tool-detail', tool.on ? tool.detail : 'aus'));
        list.appendChild(item);
      });
      body.appendChild(list);
      var skills = state.data.skills || {};
      // 0.5.80: Mara picks from her whole library herself.
      var library = Number(skills.library) || 0;
      body.appendChild(el('p', 'insight-note', 'Skills: Werkzeuge (Pflicht)'
        + (library ? ', ' + library + ' weitere wählt Mara selbst' : '')));
    }

    function link(name, url, meta) {
      var item = el('li', 'insight-file');
      var href = safeUrl(url);
      var label = href ? el('a', 'insight-file-name', name) : el('span', 'insight-file-name', name);
      if (href) {
        label.href = href;
        label.setAttribute('download', '');
      }
      item.appendChild(label);
      item.appendChild(el('span', 'insight-file-meta', meta));
      return item;
    }

    function renderProject(body) {
      var plan = state.data && state.data.plan;
      var files = state.data && Array.isArray(state.data.files) ? state.data.files : [];
      if (!plan && !files.length) {
        body.appendChild(el('p', 'insight-empty', state.data
          ? 'Noch kein Projektplan und keine Dateien in dieser Sitzung.'
          : (state.failed ? UNREACHABLE : 'Lade Projekt ...')));
        return;
      }
      if (plan) {
        body.appendChild(el('div', 'insight-subhead', 'Projektplan'));
        var planList = el('ul', 'insight-files');
        planList.appendChild(link(plan.name, plan.download_url,
          (plan.draft ? 'Entwurf' : 'fertig') + ' · ' + bytes(plan.size_bytes)));
        body.appendChild(planList);
        if (plan.title) body.appendChild(el('p', 'insight-note', plan.title));
      }
      if (files.length) {
        body.appendChild(el('div', 'insight-subhead', 'Dateien'));
        var list = el('ul', 'insight-files');
        files.forEach(function (file) {
          list.appendChild(link(file.name, file.download_url, bytes(file.size_bytes)));
        });
        body.appendChild(list);
      }
    }

    var RENDER = {core:renderCore, context:renderContext, tools:renderTools, project:renderProject};

    function box(spec, inSheet) {
      var section = el('section', 'insight-box insight-' + spec.id);
      section.setAttribute('aria-label', spec.label);
      var head = el('button', 'insight-head');
      head.type = 'button';
      head.appendChild(el('span', 'insight-icon', spec.icon));
      head.appendChild(el('span', 'insight-title', spec.label));
      head.appendChild(el('span', 'insight-chevron', '▾'));
      var body = el('div', 'insight-body');
      section.appendChild(head);
      section.appendChild(body);
      if (!inSheet) {
        head.setAttribute('aria-expanded', state.open[spec.id] ? 'true' : 'false');
        body.hidden = !state.open[spec.id];
        head.addEventListener('click', function () {
          state.open[spec.id] = !state.open[spec.id];
          head.setAttribute('aria-expanded', state.open[spec.id] ? 'true' : 'false');
          body.hidden = !state.open[spec.id];
          writeOpen(state.open);
        });
      } else {
        head.setAttribute('aria-expanded', 'true');
        head.addEventListener('click', function () { setChip(''); });
      }
      return {section:section, body:body, head:head};
    }

    var sheet = null;
    var chipButtons = {};
    if (aside) {
      BOXES.forEach(function (spec) {
        var made = box(spec, false);
        bodies[spec.id] = made.body;
        heads[spec.id] = made.head;
        aside.appendChild(made.section);
      });
    }
    if (chips) {
      var bar = el('div', 'insight-chip-row');
      BOXES.forEach(function (spec) {
        var chip = el('button', 'insight-chip');
        chip.type = 'button';
        chip.setAttribute('aria-expanded', 'false');
        chip.appendChild(el('span', 'insight-icon', spec.icon));
        chip.appendChild(el('span', 'insight-chip-label', spec.label));
        chip.addEventListener('click', function () { setChip(state.chip === spec.id ? '' : spec.id); });
        chipButtons[spec.id] = chip;
        bar.appendChild(chip);
      });
      chips.appendChild(bar);
      sheet = el('div', 'insight-sheet');
      sheet.hidden = true;
      chips.appendChild(sheet);
    }

    function setChip(id) {
      state.chip = id;
      Object.keys(chipButtons).forEach(function (key) {
        chipButtons[key].setAttribute('aria-expanded', key === id ? 'true' : 'false');
        chipButtons[key].classList.toggle('is-open', key === id);
      });
      renderSheet();
    }

    function renderSheet() {
      if (!sheet) return;
      sheet.textContent = '';
      sheet.hidden = !state.chip;
      if (!state.chip) return;
      var spec = BOXES.filter(function (item) { return item.id === state.chip; })[0];
      var made = box(spec, true);
      RENDER[spec.id](made.body);
      sheet.appendChild(made.section);
    }

    function render() {
      Object.keys(bodies).forEach(function (id) {
        bodies[id].textContent = '';
        RENDER[id](bodies[id]);
      });
      renderSheet();
    }

    function refresh(sessionId) {
      var id = String(sessionId || '');
      if (!id || typeof root.fetch !== 'function') return Promise.resolve(null);
      if (id !== state.sessionId) {
        state.sessionId = id;
        state.data = null;
        state.live = {};
        render();
      }
      var ticket = ++state.request;
      return root.fetch(apiBase + '/console_insights.php?session_id=' + encodeURIComponent(id),
        {credentials:'include', cache:'no-store'})
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (ticket !== state.request || id !== state.sessionId) return null;
          if (!data || !data.ok || String(data.session_id) !== id) throw new Error('insights_unavailable');
          state.data = data;
          state.live = {};
          state.failed = false;
          render();
          return data;
        })
        .catch(function () {
          // Never leave a box "loading" forever; say so honestly instead.
          if (ticket === state.request && id === state.sessionId && !state.data) {
            state.failed = true;
            render();
          }
          return null;
        });
    }

    // Live work events update the boxes before the reply is saved.
    function observe(event) {
      if (!event || !event.meta) return;
      var meta = event.meta;
      if (meta.model) state.live.model = String(meta.model);
      if (Number(meta.context_tokens) > 0) state.live.context_tokens = Number(meta.context_tokens);
      if (Number(meta.prompt_eval_count) > 0) {
        state.live.usage = {
          prompt_tokens:Number(meta.prompt_eval_count),
          output_tokens:Number(meta.eval_count) || 0,
          context_tokens:Number(meta.context_tokens) || 0
        };
      }
      render();
    }

    render();
    return {refresh:refresh, observe:observe, render:render, setChip:setChip};
  }

  root.CoreUIInsights = {create:create};
}(window));
