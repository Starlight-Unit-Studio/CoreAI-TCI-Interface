'use strict';
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.join(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const h=read('app.html'), chat=read('js/console-app.js');
const project=read('js/coreui-projects.js');
const folding=read('js/coreui-sidebar-fold.js');
const navCSS=read('css/coreui-sidebar-refresh.css');
const themeCSS=read('css/coreui-project-theme.css');
const id=x=>(h.match(new RegExp('id="'+x+'"','g'))||[]).length;
for(const k of ['btnSettings','btnLogout','btnNewSession','pinnedZone',
  'pinnedSessionList','pinnedCount','sidebarPinnedToggle','sidebarProjectsToggle',
  'sidebarRecentToggle','projectsNav','projectsNavList','sessionZoneLabel',
  'sessionList','sessionArchiveToggle','coreuiProjectHome']){
  assert.equal(id(k),1,'unique sidebar item '+k);
}
assert(!h.includes('id="btnProtocols"'),'duplicate protocols sidebar shortcut removed');
assert(h.includes('class="ren-matrix-svg"') && h.includes('class="core-ticker"'),
  'original animated logo and ticker remain in place');
assert(h.indexOf('class="core-ticker"') < h.indexOf('id="btnNewSession"'),
  'navigation starts directly below the existing ticker');
assert(h.indexOf('id="pinnedZone"') < h.indexOf('id="projectsNav"') &&
  h.indexOf('id="projectsNav"') < h.indexOf('id="sessionList"'),
  'required pinned, projects, recent order');
assert(h.indexOf('id="btnSettings"') < h.indexOf('class="core-ticker"') &&
  h.indexOf('id="btnLogout"') < h.indexOf('class="core-ticker"'),
  'header icons not a space-consuming footer');
assert(chat.includes('pinnedChats.concat(recentChats)') &&
  chat.includes('pinnedSessionListEl : sessionListEl'),
  'pinned and recent chats rendered separately');
assert(chat.includes("dbSessionAction({action:'pin'") &&
  chat.includes('session-row-more'), 'pin/unpin reachable without opening chat');
assert(chat.includes('dbArchiveSession(s.id)') &&
  chat.includes('dbDeleteSession(s.id, !s.archived)') &&
  chat.includes('dbRenameSession(s.id'), 'existing chat management still reachable');
for(const name of ['pinned','projects','recent']){
  assert(folding.includes(name+': document.')||folding.includes(name+': document.querySelector'),
    'folded section wired '+name);
  assert(h.includes('data-sidebar-fold="'+name+'"'), 'toggle in markup '+name);
}
assert(folding.includes('localStorage.setItem') && folding.includes("aria-expanded"),
  'independent fold UI preference persisted and accessible');
assert(navCSS.includes('.sidebar-section-folded') &&
  navCSS.includes('.pinned-session-list') &&
  navCSS.includes('.drawer-footer') &&
  navCSS.includes('.core-ticker'), 'space-saving theme preserves ticker');
assert(themeCSS.includes('var(--cx)') &&
  themeCSS.includes('var(--panel)') &&
  themeCSS.includes('.project-modal') &&
  themeCSS.includes('.project-source-row') &&
  themeCSS.includes('body.project-home-active .drawer'),
  'project home, sources, settings and sidebar adopt native CoreAI theme');
assert(project.includes('coreui_project')===false || project.includes('project-nav-folder'),
  'project list remains available');

// 0.7.27: Native CoreAI shape, accents and collapsed desktop controls.
assert(themeCSS.includes('.app-body .chat-menu') &&
  themeCSS.includes('.app-body .chat-dialog-box') &&
  themeCSS.includes('linear-gradient(90deg,var(--cx),var(--amb),transparent)'),
  'chat menu/dialogs must retain CoreAI geometric accents');
assert(themeCSS.includes('.app-body #sidebarControl:not(:checked) ~ .drawer #btnSettings') &&
  themeCSS.includes('.app-body #sidebarControl:not(:checked) ~ .drawer #btnLogout') &&
  themeCSS.includes('.app-body #sidebarControl:not(:checked) ~ .drawer .drawer-close'),
  'collapsed desktop sidebar must expose fold, settings and logout buttons');
assert(themeCSS.includes('body.project-home-active .project-chat-result') &&
  themeCSS.includes('clip-path:polygon('),
  'projects and chat controls retain angular CoreAI geometry');
assert(h.includes('N-73 // v0.7.38'),
  'Ren matrix must display the same current application version');

const version=read('VERSION').trim();
assert.equal(version,'0.7.38','single merge advances version 0.7.38');
console.log('PASS: CoreAI sidebar sections and project theme 0.7.38');
