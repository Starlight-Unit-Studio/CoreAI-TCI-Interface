/* CoreAI profile editors: accessible mobile modals, shared by Operator and Mara.
 * Existing server-side avatar/crop controllers remain the single source of truth.
 */
(function (root, doc) {
  'use strict';
  function create() {
    if (!doc) return null;
    var dialogs = {
      user: doc.getElementById('profileEditorModal'),
      assistant: doc.getElementById('assistantProfileModal')
    };
    if (!dialogs.user || !dialogs.assistant) return null;
    var active = null;
    var priorFocus = null;
    var priorScroll = '';
    function open(kind) {
      if (!dialogs[kind]) return false;
      if (active === kind) return true;
      if (active) close();
      var modal = dialogs[kind];
      priorFocus = doc.activeElement;
      priorScroll = doc.body.style.overflow;
      active = kind;
      doc.body.style.overflow = 'hidden';
      modal.hidden = false;
      var target = modal.querySelector('input[type="text"],button');
      if (target && typeof target.focus === 'function') target.focus({preventScroll: true});
      else modal.focus({preventScroll: true});
      return true;
    }
    function close() {
      if (!active) return;
      dialogs[active].hidden = true;
      doc.body.style.overflow = priorScroll;
      active = null;
      if (priorFocus && typeof priorFocus.focus === 'function') priorFocus.focus({preventScroll: true});
      priorFocus = null;
    }
    Object.keys(dialogs).forEach(function (kind) {
      var modal = dialogs[kind];
      modal.addEventListener('click', function (event) {
        if (event.target === modal || (event.target.closest &&
            event.target.closest('[data-profile-close]'))) close();
      });
    });
    var pencil = doc.getElementById('profileEditJump');
    var assistant = doc.getElementById('assistantProfileEdit');
    if (pencil) pencil.addEventListener('click', function () { open('user'); });
    if (assistant) assistant.addEventListener('click', function () { open('assistant'); });
    doc.addEventListener('keydown', function (event) {
      if (!active) return;
      var crop = doc.getElementById('profileCropDialog');
      if (crop && !crop.hidden) return;
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key !== 'Tab') return;
      var modal = dialogs[active];
      var list = Array.prototype.filter.call(
        modal.querySelectorAll('button:not([disabled]):not([hidden]),input:not([disabled]):not([hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]'),
        function (node) { return !node.closest('[hidden]') && node.getAttribute('aria-hidden') !== 'true'; }
      );
      if (!list.length) { event.preventDefault(); modal.focus(); return; }
      var first = list[0], last = list[list.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    return {open: open, close: close, current: function () { return active; }};
  }
  root.CoreUIProfileEditors = {create: create};
  if (doc) root.CoreUIProfileEditors.instance = create();
})(typeof window !== 'undefined' ? window : globalThis,
   typeof document !== 'undefined' ? document : null);
if (typeof module !== 'undefined') module.exports = {create: function (doc) {
  return globalThis.CoreUIProfileEditors.create.call(null, doc);
}};
