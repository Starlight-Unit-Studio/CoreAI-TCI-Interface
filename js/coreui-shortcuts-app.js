/* CoreAI 0.7.29: the shortcuts of Settings > Tastatur in the chat (app.html).
   Every action uses the control that already exists (the same button, the same confirmation), so a
   shortcut never does more than a click would. Two small windows are new: "Tastenkürzel" (the list)
   and "Chat wechseln" (search a chat by its title). The send key stays with the chat input. */
(function () {
  'use strict';
  var S = window.CoreAIShortcuts;
  if (!S) return;
  var $ = function (id) { return document.getElementById(id); };
  var MOBILE = '(max-width: 899px)';

  function app() { return window.CoreAIChatApp || null; }
  // Drawn on screen (offsetParent is null for fixed windows, so the boxes count).
  function shown(node) { return !!(node && !node.hidden && node.getClientRects().length > 0); }
  function visible(node) { return shown(node) && !node.disabled; }

  var toastNode = null;
  var toastTimer = 0;
  function toast(text) {
    if (!toastNode) {
      toastNode = document.createElement('p');
      toastNode.id = 'shortcutToast';
      toastNode.className = 'chat-menu-toast';
      toastNode.setAttribute('role', 'status');
      toastNode.hidden = true;
      document.body.appendChild(toastNode);
    }
    toastNode.textContent = text;
    toastNode.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastNode.hidden = true; }, 3200);
  }

  // --- Small windows ---------------------------------------------------------------------------------------
  var lastFocus = null;
  function dialog(id, title, extraClass) {
    var root = $(id);
    if (root) return root;
    root = document.createElement('div');
    root.className = 'chat-dialog sc-dialog';
    root.id = id;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-labelledby', id + 'Title');
    root.hidden = true;
    var box = document.createElement('div');
    box.className = 'chat-dialog-box ' + extraClass;
    var head = document.createElement('div');
    head.className = 'chat-dialog-head';
    var h = document.createElement('h2');
    h.id = id + 'Title';
    h.textContent = title;
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'chat-dialog-close';
    close.setAttribute('aria-label', 'Schließen');
    close.textContent = '×';
    close.addEventListener('click', function () { hide(root); });
    head.appendChild(h);
    head.appendChild(close);
    box.appendChild(head);
    root.appendChild(box);
    root.addEventListener('click', function (e) { if (e.target === root) hide(root); });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !e.shiftKey) { e.preventDefault(); e.stopPropagation(); hide(root); }
    });
    document.body.appendChild(root);
    return root;
  }
  function show(root, focus) {
    if (root.hidden) lastFocus = document.activeElement;
    root.hidden = false;
    if (focus) focus.focus(); // at once, so the first letter typed after the keys lands in the window
  }
  function hide(root) {
    if (!root || root.hidden) return;
    root.hidden = true;
    if (lastFocus && document.contains(lastFocus) && lastFocus.focus) lastFocus.focus();
    lastFocus = null;
  }
  function openDialog() {
    var open = document.querySelectorAll('[role="dialog"][aria-modal="true"]');
    for (var i = 0; i < open.length; i++) if (shown(open[i])) return open[i];
    return null;
  }

  // "Tastenkürzel anzeigen": what is on, grouped like the settings page.
  function showHelp() {
    var root = dialog('shortcutHelp', 'Tastenkürzel', 'sc-help-box');
    var box = root.firstChild;
    Array.prototype.slice.call(box.querySelectorAll('.sc-help-group, .sc-help-foot')).forEach(function (n) { n.remove(); });
    var map = S.load();
    S.sections.forEach(function (section) {
      var rows = S.actions.filter(function (a) { return a.section === section.id && !a.planned && map[a.id].on && map[a.id].keys; });
      if (!rows.length) return;
      var group = document.createElement('section');
      group.className = 'sc-help-group';
      var h = document.createElement('h3');
      h.textContent = section.title;
      var list = document.createElement('dl');
      rows.forEach(function (a) {
        var dt = document.createElement('dt');
        dt.textContent = a.label;
        var dd = document.createElement('dd');
        var kbd = document.createElement('kbd');
        kbd.textContent = S.label(map[a.id].keys);
        dd.appendChild(kbd);
        list.appendChild(dt);
        list.appendChild(dd);
      });
      group.appendChild(h);
      group.appendChild(list);
      box.appendChild(group);
    });
    var foot = document.createElement('p');
    foot.className = 'sc-help-foot';
    var link = document.createElement('a');
    link.href = 'settings.html#tastatur';
    link.textContent = 'Ändern unter Einstellungen > Tastatur';
    foot.appendChild(link);
    box.appendChild(foot);
    show(root, box.querySelector('.chat-dialog-close'));
  }

  // "Chat wechseln …": the chats of the side bar (pinned first), filtered by title; Enter opens.
  var switchIndex = 0;
  function chats() {
    var a = app();
    var list = a && a.getSessions ? a.getSessions() : [];
    var current = a && a.active ? a.active() : null;
    list = list.filter(function (s) { return s && !s.archived; });
    return list.filter(function (s) { return !!s.pinned; }).concat(list.filter(function (s) { return !s.pinned; }))
      .map(function (s) { return {id: String(s.id), title: String(s.title || '').trim() || 'Neuer Chat', pinned: !!s.pinned,
        active: !!(current && current.id === String(s.id))}; });
  }
  function drawSwitch() {
    var root = $('chatSwitch');
    var q = $('chatSwitchInput').value.trim().toLowerCase();
    var list = $('chatSwitchList');
    list.textContent = '';
    var found = chats().filter(function (c) { return !q || c.title.toLowerCase().indexOf(q) >= 0; }).slice(0, 50);
    if (switchIndex >= found.length) switchIndex = Math.max(0, found.length - 1);
    found.forEach(function (c, i) {
      var li = document.createElement('li');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'sc-switch-item' + (i === switchIndex ? ' is-current' : '');
      b.setAttribute('role', 'option');
      b.setAttribute('aria-selected', i === switchIndex ? 'true' : 'false');
      b.setAttribute('data-session-id', c.id);
      var name = document.createElement('span');
      name.textContent = c.title;
      b.appendChild(name);
      if (c.pinned || c.active) {
        var tag = document.createElement('small');
        tag.textContent = c.active ? 'Geöffnet' : 'Angepinnt';
        b.appendChild(tag);
      }
      b.addEventListener('click', function () { openChat(c.id); });
      b.addEventListener('mousemove', function () {
        if (switchIndex === i) return;
        switchIndex = i;
        markSwitch();
      });
      li.appendChild(b);
      list.appendChild(li);
    });
    $('chatSwitchEmpty').hidden = found.length > 0;
    $('chatSwitchEmpty').textContent = q ? 'Kein Chat mit „' + $('chatSwitchInput').value.trim() + '“ im Titel.' : 'Noch keine Chats.';
    root.setAttribute('data-count', String(found.length));
  }
  function markSwitch() {
    var items = $('chatSwitchList').querySelectorAll('.sc-switch-item');
    Array.prototype.forEach.call(items, function (b, i) {
      b.classList.toggle('is-current', i === switchIndex);
      b.setAttribute('aria-selected', i === switchIndex ? 'true' : 'false');
      if (i === switchIndex && b.scrollIntoView) b.scrollIntoView({block: 'nearest'});
    });
  }
  function openChat(id) {
    var a = app();
    hide($('chatSwitch'));
    if (!a || !a.select) return;
    a.select(id).catch(function () { toast('Dieser Chat ließ sich nicht öffnen.'); });
  }
  function showSwitch() {
    var root = $('chatSwitch');
    if (!root) {
      root = dialog('chatSwitch', 'Chat wechseln', 'sc-switch-box');
      var box = root.firstChild;
      var input = document.createElement('input');
      input.id = 'chatSwitchInput';
      input.type = 'search';
      input.className = 'sc-switch-input';
      input.placeholder = 'Chat suchen …';
      input.setAttribute('aria-label', 'Chat suchen');
      input.setAttribute('aria-controls', 'chatSwitchList');
      input.autocomplete = 'off';
      var list = document.createElement('ul');
      list.id = 'chatSwitchList';
      list.className = 'sc-switch-list';
      list.setAttribute('role', 'listbox');
      list.setAttribute('aria-label', 'Chats');
      var empty = document.createElement('p');
      empty.id = 'chatSwitchEmpty';
      empty.className = 'chat-dialog-note';
      empty.hidden = true;
      box.appendChild(input);
      box.appendChild(list);
      box.appendChild(empty);
      input.addEventListener('input', function () { switchIndex = 0; drawSwitch(); });
      input.addEventListener('keydown', function (e) {
        var count = $('chatSwitchList').querySelectorAll('.sc-switch-item').length;
        if (e.key === 'ArrowDown' && count) { e.preventDefault(); switchIndex = (switchIndex + 1) % count; markSwitch(); }
        if (e.key === 'ArrowUp' && count) { e.preventDefault(); switchIndex = (switchIndex - 1 + count) % count; markSwitch(); }
        if (e.key === 'Enter' && !e.isComposing) {
          e.preventDefault();
          var current = $('chatSwitchList').querySelectorAll('.sc-switch-item')[switchIndex];
          if (current) openChat(current.getAttribute('data-session-id'));
        }
      });
    }
    $('chatSwitchInput').value = '';
    switchIndex = 0;
    drawSwitch();
    show(root, $('chatSwitchInput'));
  }

  // --- Actions ---------------------------------------------------------------------------------------------
  function focusInput() {
    var input = $('inputMessage');
    if (!input) return;
    input.focus();
    var end = input.value.length;
    try { input.setSelectionRange(end, end); } catch (e) {}
  }
  function chatAction(name, none) {
    var a = app();
    if (!a || !a.active || !a.active()) { toast(none); return; }
    var item = document.querySelector('[data-chat-action="' + name + '"]');
    if (item) item.click();
  }
  function toggleSidebar() {
    var a = app();
    var drawer = $('drawer');
    if (window.matchMedia && window.matchMedia(MOBILE).matches) {
      if (drawer && drawer.classList.contains('open')) { if (a && a.closeDrawer) a.closeDrawer(); }
      else if (a && a.openDrawer) a.openDrawer();
      return;
    }
    var control = $('sidebarControl');
    if (!control) return;
    control.checked = !control.checked;
    control.dispatchEvent(new Event('change', {bubbles: true}));
  }
  var RUN = {
    focus: focusInput,
    effort: function () {
      var chip = $('reasonEffortChip');
      if (visible(chip)) chip.click();
      else toast('Für diesen Core lässt sich das Denken nicht einstellen.');
    },
    dictation: function () {
      var mic = $('btnMic');
      if (visible(mic)) mic.click();
      else toast('Das Mikrofon ist aus. Du schaltest es unter Einstellungen > Stimme ein.');
    },
    attach: function () {
      var input = $('fileInput');
      if (input && !input.disabled) input.click();
    },
    chatDelete: function () { chatAction('delete', 'Öffne zuerst den Chat, den du löschen möchtest.'); },
    chatSearch: function () { chatAction('search', 'Öffne zuerst einen Chat, um darin zu suchen.'); },
    newChat: function () {
      var btn = $('btnNewSession');
      if (btn) btn.click();
      setTimeout(focusInput, 0);
    },
    help: showHelp,
    switchChat: showSwitch,
    sidebar: toggleSidebar
  };

  document.addEventListener('keydown', function (event) {
    if (event.defaultPrevented || event.repeat) return;
    var id = S.match(event);
    if (!id || !RUN[id]) return;
    // Another window (a confirmation, the PIN, the live view) has the keyboard; only our own two may be swapped.
    var top = openDialog();
    if (top && top.id !== 'shortcutHelp' && top.id !== 'chatSwitch') return;
    event.preventDefault();
    event.stopPropagation();
    if (top) {
      hide(top);
      if (id === 'help' && top.id === 'shortcutHelp') return; // the same keys close the list again
      if (id === 'switchChat' && top.id === 'chatSwitch') return;
    }
    RUN[id]();
  }, true);

  window.CoreAIShortcutsApp = Object.freeze({run: function (id) { if (RUN[id]) RUN[id](); }, showHelp: showHelp, showSwitch: showSwitch});
})();
