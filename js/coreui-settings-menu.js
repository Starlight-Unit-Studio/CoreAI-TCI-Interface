/* CoreAI 0.6.71: global per-control Expert toggle and category navigation.
   Expert mode only changes visibility of elements tagged data-expert. All
   categories and their stored values remain available; backend rights are
   always enforced independently. */
(function (root) {
  'use strict';
  function create(doc, win) {
    var nav = doc.getElementById('cfgNav');
    var openButton = doc.getElementById('settingsMenuToggle');
    var closeButton = doc.getElementById('settingsMenuClose');
    var backdrop = doc.getElementById('settingsMenuBackdrop');
    if (!nav) return null;
    var search = doc.getElementById('settingsNavSearch');
    var noResults = doc.getElementById('settingsMenuSearchStatus');
    var expertToggle = doc.getElementById('settingsExpertToggle');
    var expertFields = Array.prototype.slice.call(doc.querySelectorAll('[data-expert]'));
    var adminGroup = doc.getElementById('adminLinkGroup');
    var adminRoleGroup = doc.getElementById('adminNavGroup');
    var groups = Array.prototype.slice.call(nav.querySelectorAll('.cfg-nav-group'));
    var KEY = 'coreai:expert-settings:v1';
    var mobile = typeof win.matchMedia === 'function'
      ? win.matchMedia('(max-width: 980px)') : {matches:false};
    var opened = false;
    var desktopCollapsed = false;
    var swipe = null;
    function stored() {
      try { return !!win.localStorage && win.localStorage.getItem(KEY) === '1'; }
      catch (error) { return false; }
    }
    function persist(value) {
      try { if (win.localStorage) win.localStorage.setItem(KEY, value ? '1' : '0'); }
      catch (error) { /* storage denied: this visit still works */ }
    }
    function reflect() {
      var enabled = expertToggle ? expertToggle.checked : stored();
      expertFields.forEach(function (field) { field.hidden = !enabled; });
      if (adminGroup && adminGroup.dataset.authorized !== '1') adminGroup.hidden = true;
      if (adminRoleGroup && adminRoleGroup.dataset.authorized !== '1') adminRoleGroup.hidden = true;
      filter();
    }
    function filter() {
      var query = search ? String(search.value || '').trim().toLocaleLowerCase() : '';
      var matches = 0;
      groups.forEach(function (group) {
        var adminOff = (group === adminGroup || group === adminRoleGroup)
          && group.dataset.authorized !== '1';
        var tabs = Array.prototype.slice.call(group.querySelectorAll('.cfg-tab'));
        var visible = 0;
        tabs.forEach(function (tab) {
          var text = String(tab.textContent || '').toLocaleLowerCase();
          var found = !query || text.indexOf(query) !== -1;
          tab.hidden = !found || adminOff;
          if (!tab.hidden) visible++;
        });
        group.hidden = !!adminOff || (!!query && visible === 0);
        matches += visible;
      });
      if (noResults) noResults.hidden = !query || matches > 0;
    }
    function setOpen(value, focusToggle) {
      // Desktop: the categories form a collapsible sidebar alongside the
      // selected settings page. Mobile: they slide over the content as in Chat.
      if (mobile.matches) {
        opened = !!value;
        desktopCollapsed = false;
      } else {
        opened = false;
        desktopCollapsed = !value;
      }
      nav.classList.toggle('is-open', opened);
      if (doc.body) {
        doc.body.classList.toggle('cfg-menu-opened', opened);
        doc.body.classList.toggle('cfg-nav-collapsed', desktopCollapsed);
      }
      if (openButton) {
        openButton.setAttribute('aria-expanded',
          mobile.matches ? (opened ? 'true' : 'false') : (desktopCollapsed ? 'false' : 'true'));
        openButton.setAttribute('aria-label',
          (mobile.matches ? opened : !desktopCollapsed) ? 'Kategorien schließen' : 'Kategorien öffnen');
      }
      if (backdrop) backdrop.hidden = !opened;
      if (opened && closeButton && typeof closeButton.focus === 'function') closeButton.focus();
      if (!opened && focusToggle && openButton && typeof openButton.focus === 'function') openButton.focus();
    }
    if (expertToggle) {
      expertToggle.checked = stored();
      expertToggle.addEventListener('change', function () {
        persist(expertToggle.checked);
        reflect();
        // Expert mode never changes the active page or its saved values.
      });
    }
    if (search) search.addEventListener('input', filter);
    if (openButton) openButton.addEventListener('click', function () {
      setOpen(mobile.matches ? !opened : desktopCollapsed, false);
    });
    if (closeButton) closeButton.addEventListener('click', function () { setOpen(false, true); });
    if (backdrop) backdrop.addEventListener('click', function () { setOpen(false, true); });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && opened) { event.preventDefault(); setOpen(false, true); }
    });
    doc.addEventListener('coreai:view', function (event) {
      // Page selection only closes the mobile drawer. Desktop navigation
      // remains present until deliberately collapsed.
      if (!mobile.matches || !opened) return;
      setOpen(false, false);
      var name = event.detail && event.detail.view;
      var target = name && doc.getElementById('view-' + name);
      if (target && typeof target.focus === 'function') {
        try { target.focus({preventScroll:true}); } catch (error) { target.focus(); }
      }
    });
    nav.addEventListener('click', function (event) {
      var node = event.target;
      if (mobile.matches && node && node.closest && node.closest('.cfg-tab')) setOpen(false, false);
    });
    function resetForViewport() {
      opened = false;
      desktopCollapsed = false;
      setOpen(!mobile.matches, false);
    }
    if (typeof mobile.addEventListener === 'function') {
      mobile.addEventListener('change', resetForViewport);
    } else if (typeof mobile.addListener === 'function') {
      mobile.addListener(resetForViewport);
    }
    // A short right-swipe from the left edge opens the same drawer as the
    // categories button. A left-swipe on the open drawer closes it.
    // Ignore vertical gestures and gestures started on form controls.
    doc.addEventListener('touchstart', function (event) {
      swipe = null;
      if (!mobile.matches || !event.touches || event.touches.length !== 1) return;
      var point = event.touches[0], target = event.target;
      var interactive = target && target.closest &&
        target.closest('input, textarea, select, button, a, [role="slider"]');
      var fromLeft = !opened && point.clientX <= 44 && !interactive;
      var fromDrawer = opened && target && nav.contains(target);
      if (fromLeft || fromDrawer) {
        swipe = { x: point.clientX, y: point.clientY,
          type: fromLeft ? 'open' : 'close' };
      }
    }, {passive:true});
    doc.addEventListener('touchend', function (event) {
      if (!swipe || !event.changedTouches || !event.changedTouches.length) return;
      var move = swipe; swipe = null;
      var point = event.changedTouches[0], dx = point.clientX - move.x;
      var dy = point.clientY - move.y;
      if (Math.abs(dx) < 68 || Math.abs(dx) < Math.abs(dy) * 1.35) return;
      if (move.type === 'open' && dx > 0) setOpen(true, false);
      else if (move.type === 'close' && dx < 0) setOpen(false, true);
    }, {passive:true});
    doc.addEventListener('touchcancel', function () { swipe = null; }, {passive:true});
    resetForViewport();
    reflect();
    return {
      isExpert: function () { return !!(expertToggle && expertToggle.checked); },
      revealCategory: function () {
        // A quick link can override a search filter, not the expert preference.
        if (search && search.value) search.value = '';
        reflect();
      },
      refresh: reflect,
      close: function () { setOpen(false, false); }
    };
  }
  root.CoreAISettingsMenu = {create:create};
  if (root.document && root.document.getElementById) {
    root.CoreAISettingsMenu.instance = create(root.document, root);
  }
})(typeof window !== 'undefined' ? window : this);
