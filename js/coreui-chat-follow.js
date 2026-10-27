/* CoreAI conversation follow: preserve the reader's position during Mara's work.
   This controller owns only viewport movement, not data or agent execution. */
(function (root) {
  'use strict';

  function create(area, button, isWorking, activeRow, content) {
    if (!area || !button) return null;
    var following = true;
    var scheduled = false;
    var gestureAt = 0; // last wheel/touch movement by the reader
    var draggingBar = false;
    var lastTop = Number(area.scrollTop) || 0;
    var touchY = null;
    var bottomMargin = 76;
    var raf = typeof root.requestAnimationFrame === 'function'
      ? root.requestAnimationFrame.bind(root)
      : function (fn) { fn(); };

    function latestRect() {
      var row = activeRow && activeRow();
      if (!row || typeof row.getBoundingClientRect !== 'function'
          || typeof area.getBoundingClientRect !== 'function') return null;
      return {row:row.getBoundingClientRect(),viewport:area.getBoundingClientRect()};
    }
    function contentEnd() {
      // The feed-shell has historically stretched to the full viewport,
      // including decorative padding. That empty tail is not chat content.
      var rect = latestRect();
      if (!rect) return Number(area.scrollHeight) || 0;
      return Math.min(Number(area.scrollHeight) || 0,
        Math.max(0,(Number(area.scrollTop)||0) +
          rect.row.bottom - rect.viewport.top + 18));
    }
    function gap() {
      return Math.max(0,contentEnd() -
        (Number(area.clientHeight)||0) - (Number(area.scrollTop)||0));
    }

    function refresh() {
      var away = gap() > bottomMargin;
      var working = away && !!(isWorking && isWorking());
      button.hidden = !away;
      button.classList.toggle('visible', away);
      button.classList.toggle('working', working);
      button.setAttribute('aria-label', working
        ? 'Mara arbeitet. Zum aktuellen Arbeitsweg springen'
        : 'Zur neuesten Nachricht springen');
      return away;
    }

    function activeTarget() {
      var max = Math.max(0,(Number(area.scrollHeight)||0) -
        (Number(area.clientHeight)||0));
      var rect = latestRect();
      if (rect) {
        // Follow the END of the latest message: that is where Mara's live
        // work history writes. Never target an empty tail beyond it.
        var target = (Number(area.scrollTop)||0) + rect.row.bottom
          - rect.viewport.bottom + 18;
        return Math.max(0,Math.min(max,target));
      }
      return max;
    }

    function follow(force) {
      if (force) following = true;
      if (!following) { refresh(); return; }
      if (scheduled) return;
      scheduled = true;
      raf(function () {
        scheduled = false;
        if (!following) { refresh(); return; }
        area.scrollTop = activeTarget();
        lastTop = Number(area.scrollTop) || 0;
        refresh();
      });
    }

    // Only the reader stops the camera: wheel, touch, keys or the scrollbar.
    // Layout changes, scroll anchoring and re-rendering never count.
    function now() { return Date.now(); }
    // A gesture explains scroll events shortly after it (including momentum),
    // never a layout shift long after a gesture the inner work view consumed.
    function readerGesture() { return now() - gestureAt < 700; }
    function onScroll() {
      var top = Number(area.scrollTop) || 0;
      if (gap() <= bottomMargin) following = true;
      else if (readerGesture() || draggingBar) following = false;
      lastTop = top;
      refresh();
    }

    area.addEventListener('scroll', onScroll, {passive:true});
    // A press on the scroll container itself (not a message) is its scrollbar.
    area.addEventListener('pointerdown', function (e) {
      if (e && e.target === area) draggingBar = true;
    }, {passive:true});
    if (typeof root.addEventListener === 'function') {
      root.addEventListener('pointerup', function () { draggingBar = false; }, {passive:true});
      root.addEventListener('pointercancel', function () { draggingBar = false; }, {passive:true});
    }
    // Typed work notes, previews and images grow the conversation between
    // server events. While the reader follows, the camera grows with them.
    if (content && typeof root.ResizeObserver === 'function') {
      new root.ResizeObserver(function () { if (following) follow(); }).observe(content);
    }
    area.addEventListener('wheel', function (e) {
      gestureAt = now();
      if (e.deltaY < 0) following = false;
    }, {passive:true});
    area.addEventListener('touchstart', function (e) {
      touchY = e.touches && e.touches.length ? e.touches[0].clientY : null;
    }, {passive:true});
    area.addEventListener('touchmove', function (e) {
      if (touchY === null || !e.touches || !e.touches.length) return;
      var next = e.touches[0].clientY;
      if (next > touchY + 4) following = false; // Swipe down: read older messages.
      if (Math.abs(next - touchY) > 3) gestureAt = now();
      touchY = next;
    }, {passive:true});
    area.addEventListener('keydown', function (e) {
      if (['ArrowUp','PageUp','Home'].indexOf(e.key) >= 0
          || (e.key === ' ' && e.shiftKey)) following = false;
    });
    button.addEventListener('click', function () {
      following = true;
      var target = activeTarget();
      var reduced = root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (typeof area.scrollTo === 'function')
        area.scrollTo({top:target,behavior:reduced ? 'auto' : 'smooth'});
      else area.scrollTop = target;
      refresh();
    });
    refresh();
    return {
      follow:follow,refresh:refresh,atBottom:function(){return gap() <= bottomMargin;},
      isFollowing:function(){return following;},
      reset:function(){following=true;lastTop=0;follow(true);}
    };
  }

  root.CoreUIChatFollow = {create:create};
}(window));
