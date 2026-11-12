/* Sidebar disclosure state. UI preference only, no chat or project data. */
(function () {
  'use strict';
  var key='coreui:sidebar:folds:v1';
  var allowed=['pinned','projects','recent'];
  var persisted={};
  try {
    var parsed=JSON.parse(localStorage.getItem(key)||'{}');
    if(parsed && typeof parsed==='object' && !Array.isArray(parsed)) persisted=parsed;
  } catch (_) {}
  function save() {
    try { localStorage.setItem(key,JSON.stringify(persisted)); } catch (_) {}
  }
  var sections={
    pinned: document.getElementById('pinnedZone'),
    projects: document.getElementById('projectsNav'),
    recent: document.querySelector('.session-zone')
  };
  var buttons={
    pinned: document.getElementById('sidebarPinnedToggle'),
    projects: document.getElementById('sidebarProjectsToggle'),
    recent: document.getElementById('sidebarRecentToggle')
  };
  function setFold(name, collapsed) {
    var section=sections[name],button=buttons[name];
    if(!section||!button)return;
    section.classList.toggle('sidebar-section-folded',!!collapsed);
    button.setAttribute('aria-expanded',collapsed?'false':'true');
    persisted[name]=!!collapsed;
    save();
  }
  allowed.forEach(function(name){
    if(!sections[name]||!buttons[name])return;
    setFold(name,persisted[name]===true);
    buttons[name].addEventListener('click',function(){
      setFold(name,!sections[name].classList.contains('sidebar-section-folded'));
    });
  });
})();
