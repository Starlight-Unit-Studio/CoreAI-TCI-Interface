/* CoreAI 0.6.71: settings navigation only.
   Search, mobile drawer and browser-local expert visibility do not grant
   permissions or activate unfinished backend features. */
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
    var expertGroup = doc.getElementById('cfgExpertGroup');
    var adminGroup = doc.getElementById('adminLinkGroup');
    var adminRoleGroup = doc.getElementById('adminNavGroup');
    var groups = Array.prototype.slice.call(nav.querySelectorAll('.cfg-nav-group'));
    var KEY = 'coreai:expert-settings:v1';
    var mobile = typeof win.matchMedia === 'function'
      ? win.matchMedia('(max-width: 980px)') : {matches:false};
    var opened = false;
    function stored() {
      try { return !!win.localStorage && win.localStorage.getItem(KEY) === '1'; }
      catch (error) { return false; }
    }
    function persist(value) {
      try { if (win.localStorage) win.localStorage.setItem(KEY, value ? '1' : '0'); }
      catch (error) { /* storage denied: this visit still works */ }
    }
    function reflect() {
      if (expertGroup && expertToggle) expertGroup.hidden = !expertToggle.checked;
      if (adminGroup && adminGroup.dataset.authorized !== '1') adminGroup.hidden = true;
      if (adminRoleGroup && adminRoleGroup.dataset.authorized !== '1') adminRoleGroup.hidden = true;
      filter();
    }
    function filter() {
      var query = search ? String(search.value || '').trim().toLocaleLowerCase() : '';
      var matches = 0;
      groups.forEach(function (group) {
        var expertOff = group === expertGroup && expertToggle && !expertToggle.checked;
        var adminOff = (group === adminGroup || group === adminRoleGroup)
          && group.dataset.authorized !== '1';
        var tabs = Array.prototype.slice.call(group.querySelectorAll('.cfg-tab'));
        var visible = 0;
        tabs.forEach(function (tab) {
          var text = String(tab.textContent || '').toLocaleLowerCase();
          var found = !query || text.indexOf(query) !== -1;
          tab.hidden = !found || expertOff || adminOff;
          if (!tab.hidden) visible++;
        });
        group.hidden = !!expertOff || !!adminOff || (!!query && visible === 0);
        matches += visible;
      });
      if (noResults) noResults.hidden = !query || matches > 0;
    }
    function setOpen(value, focusToggle) {
      opened = !!value && !!mobile.matches;
      nav.classList.toggle('is-open', opened);
      if (openButton) openButton.setAttribute('aria-expanded', opened ? 'true' : 'false');
      if (backdrop) backdrop.hidden = !opened;
      if (doc.body) doc.body.classList.toggle('cfg-menu-opened', opened);
      if (opened && search && typeof search.focus === 'function') search.focus();
      if (!opened && focusToggle && openButton && typeof openButton.focus === 'function') openButton.focus();
    }
    if (expertToggle) {
      expertToggle.checked = stored();
      expertToggle.addEventListener('change', function () {
        persist(expertToggle.checked);
        reflect();
        var tabs = win.CoreAISettingsTabs && win.CoreAISettingsTabs.instance;
        if (!expertToggle.checked && tabs && /^(ki-profil|wissen|memory|system)$/.test(tabs.current())) {
          tabs.show('start');
        } else if (expertToggle.checked && tabs && typeof tabs.refresh === 'function') {
          // A bookmarked advanced page can open after its user-enabled group appears.
          tabs.refresh();
        }
      });
    }
    if (search) search.addEventListener('input', filter);
    if (openButton) openButton.addEventListener('click', function () { setOpen(!opened, false); });
    if (closeButton) closeButton.addEventListener('click', function () { setOpen(false, true); });
    if (backdrop) backdrop.addEventListener('click', function () { setOpen(false, true); });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && opened) { event.preventDefault(); setOpen(false, true); }
    });
    doc.addEventListener('coreai:view', function () { setOpen(false, false); });
    nav.addEventListener('click', function (event) {
      var node = event.target;
      if (node && node.closest && node.closest('.cfg-tab')) setOpen(false, false);
    });
    if (typeof mobile.addEventListener === 'function') {
      mobile.addEventListener('change', function () { setOpen(false, false); });
    } else if (typeof mobile.addListener === 'function') {
      mobile.addListener(function () { setOpen(false, false); });
    }
    reflect();
    return {
      isExpert: function () { return !!(expertToggle && expertToggle.checked); },
      refresh: reflect,
      close: function () { setOpen(false, false); }
    };
  }
  root.CoreAISettingsMenu = {create:create};
  if (root.document && root.document.getElementById) {
    root.CoreAISettingsMenu.instance = create(root.document, root);
  }
})(typeof window !== 'undefined' ? window : this);
