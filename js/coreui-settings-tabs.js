/* CoreAI 0.5.79: the settings page shows one category at a time. The tabs on
   the left (on phones a bar at the top) switch the view in place, like a
   frame, and the address keeps the open tab (#ki-profil). Tiles and the
   overview figures on the start view jump to their category. Admin Core
   categories stay hidden until admin.js has confirmed the role; an address
   that points there waits for that and opens once they show. Each switch
   sends "coreai:view" so a category can load its data when it opens. */
(function (root) {
  'use strict';

  var LEGACY = {about: 'system', overview: 'admin-uebersicht', users: 'admin-benutzer', runtime: 'admin-betrieb',
    memories: 'admin-memory', lore: 'admin-lore', logs: 'admin-protokoll', audit: 'admin-audit'};

  function create(doc, win) {
    var nav = doc.getElementById('cfgNav');
    if (!nav) return null;
    var tabs = Array.prototype.slice.call(nav.querySelectorAll('.cfg-tab[data-view]'));
    var current = '';
    var pending = '';

    function tabOf(name) {
      return tabs.filter(function (tab) { return tab.getAttribute('data-view') === name; })[0] || null;
    }
    function available(tab) {
      if (!tab) return false;
      for (var node = tab; node && node !== nav; node = node.parentNode) {
        if (node.hidden) return false;
      }
      return true;
    }
    function visibleTabs() { return tabs.filter(available); }
    // Keeps the open tab visible inside the tab bar (it scrolls sideways on
    // phones) without moving the page itself.
    function reveal(tab) {
      if (nav.scrollWidth > nav.clientWidth) {
        var left = tab.offsetLeft - 8;
        var right = tab.offsetLeft + tab.offsetWidth + 8;
        if (left < nav.scrollLeft) nav.scrollLeft = left;
        else if (right > nav.scrollLeft + nav.clientWidth) nav.scrollLeft = right - nav.clientWidth;
      }
      if (nav.scrollHeight > nav.clientHeight) {
        var top = tab.offsetTop - 8;
        var bottom = tab.offsetTop + tab.offsetHeight + 8;
        if (top < nav.scrollTop) nav.scrollTop = top;
        else if (bottom > nav.scrollTop + nav.clientHeight) nav.scrollTop = bottom - nav.clientHeight;
      }
    }
    function wanted() {
      var name = String(win.location.hash || '').replace(/^#/, '');
      try { name = decodeURIComponent(name); } catch (e) { /* keep as is */ }
      return LEGACY[name] || name;
    }

    function show(name, opts) {
      opts = opts || {};
      var tab = tabOf(name);
      if (!available(tab)) {
        if (tab) pending = name;
        tab = tabOf('start');
        name = 'start';
      } else if (name === pending) {
        pending = '';
      }
      tabs.forEach(function (other) {
        var on = other === tab;
        other.setAttribute('aria-selected', on ? 'true' : 'false');
        other.setAttribute('tabindex', on ? '0' : '-1');
        other.classList.toggle('active', on);
        var view = doc.getElementById(other.getAttribute('aria-controls'));
        if (view) view.hidden = !on;
      });
      var changed = name !== current;
      current = name;
      if (!opts.keepHash && win.history && typeof win.history.replaceState === 'function') {
        win.history.replaceState(null, '', name === 'start' ? win.location.pathname + win.location.search : '#' + name);
      }
      if (opts.focus && typeof tab.focus === 'function') tab.focus();
      if (changed) {
        reveal(tab);
        if (opts.scroll !== false && typeof win.scrollTo === 'function') win.scrollTo(0, 0);
        if (typeof win.CustomEvent === 'function') {
          doc.dispatchEvent(new win.CustomEvent('coreai:view', {detail: {view: name}}));
        }
      }
      return name;
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { show(tab.getAttribute('data-view')); });
      tab.addEventListener('keydown', function (event) {
        var list = visibleTabs();
        var at = list.indexOf(tab);
        var next = -1;
        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (at + 1) % list.length;
        else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (at - 1 + list.length) % list.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = list.length - 1;
        if (next < 0) return;
        if (typeof event.preventDefault === 'function') event.preventDefault();
        show(list[next].getAttribute('data-view'), {focus: true});
      });
    });
    Array.prototype.forEach.call(doc.querySelectorAll('[data-goto]'), function (button) {
      button.addEventListener('click', function () { show(button.getAttribute('data-goto')); });
    });
    win.addEventListener('hashchange', function () {
      var name = wanted();
      if (name && name !== current) show(name, {keepHash: true});
    });

    show(wanted() || 'start', {keepHash: true, scroll: false});

    return {
      show: show,
      current: function () { return current; },
      /** Called when hidden tabs appear (admin.js): opens a tab the address asked for. */
      refresh: function () {
        if (pending && available(tabOf(pending))) return show(pending, {keepHash: true, scroll: false});
        return current;
      }
    };
  }

  root.CoreAISettingsTabs = {create: create};
  if (root.document && root.document.getElementById) {
    root.CoreAISettingsTabs.instance = create(root.document, root);
  }
})(typeof window !== 'undefined' ? window : this);
