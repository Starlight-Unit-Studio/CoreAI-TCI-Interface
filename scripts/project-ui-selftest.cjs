'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const read = p => fs.readFileSync(require('node:path').join(__dirname,'..',p),'utf8');
const html=read('app.html');
const js=read('js/coreui-projects.js');
const app=read('js/console-app.js');
const css=read('css/coreui-projects.css');
const theme=read('css/coreui-project-theme.css');
const consoleCss=read('css/console.css');
const api=read('api/console_sessions.php');
const store=read('api/console_project_store.php');
const knowledge=read('api/knowledge_store.php');
const prompts=read('api/chat.php');
const migration=read('database/migrations/037_chat_projects.sql');
const descriptionMigration=read('database/migrations/055_project_descriptions.sql');
assert(!html.includes('project-mode-switch') && !html.includes('Work wird separat entwickelt'), 'CoreAI chat already is the work area');
assert(!css.includes('project-mode-switch'), 'no dead separate mode CSS remains');
for (const id of ['projectsCreateBtn','projectsNavList','coreuiProjectHome','projectTabChats',
  'projectTabSources','projectSourceFile','projectModalSettings','projectSettingsInstructions',
  'projectSettingsMode','projectSettingsDescription','projectHomeDescription',
  'projectComposerSlot','projectModalShare','projectHomeDrawerToggle',
  'coreuiProjectDirectory','projectDirectoryList','projectDirectorySearch',
  'projectDirectorySort','projectHomeBreadcrumbBack','projectSettingsCancel','sidebarProjectsToggle']) {
  assert(html.includes('id="'+id+'"'), 'missing project control: '+id);
}
assert(css.includes('.project-modal-scrim[hidden]') && css.includes('#projectSourcesPanel[hidden]'),
  'hidden dialogs and tabs must not appear over the project homepage');
assert(theme.includes('body.project-home-active .drawer') && css.includes('.project-chat-result-main'),
  'native CoreAI drawer and multi-line chat cards must coexist');
assert(!css.includes('background:#151515 !important') && !css.includes('body.project-home-active .drawer::before'),
  'old GPT-only project drawer overrides must remain removed');
assert(js.includes("label:'Chat umbenennen'") && js.includes("label:'Chat löschen'") &&
  js.includes("showFloatingMenu(trigger,'chat'") &&
  js.includes('document.body.appendChild(floatingMenu)'),
  'project chat ellipsis menu must escape clipped cards and offer real actions');
assert(app.includes('window.CoreAIProjects.refresh().catch'), 'project list reloads after authentication');
assert(css.includes('.project-drawer-brand {display:none;}'),
  'reference-only brand must stay hidden in normal chat');
assert(js.includes("linkedProject && projects.some"),
  'project deep links reopen after authentication');

assert(html.includes('id="projectComposerSlot"') &&
  !html.includes('id="projectHomeChatForm"') &&
  js.includes('mountComposer()') &&
  js.includes('restoreComposer()') &&
  js.includes('app.prepareProjectDraft(id)') &&
  app.includes('prepareProjectDraft: function (projectId)') &&
  app.includes('state.draftProjectId = String(projectId)') &&
  app.includes("project_id: projectId"),
  'project page must reuse native CoreAI composer and bind the project before sending');
assert(app.includes('state.draftProjectId') && app.includes('project_id: projectId'), 'new chat must have a server-owned project ID');
assert(api.includes("stu_require_csrf()"), 'mutations require CSRF');
assert(store.includes('coreui_project_session_scope') && store.includes('coreui_project_knowledge_block'), 'secure server-side project retrieval');
assert(knowledge.includes('s.project_id IS NULL'), 'global RAG must exclude project files');
assert(prompts.includes('COREUI_PROJECT_ISOLATED') && prompts.includes('projectKnowledgeBlock'), 'AI must honor isolated project mode');
assert(migration.includes('stu_console_projects') && migration.includes('project_id'), 'migration needs project and source scope');
assert(descriptionMigration.includes('ADD COLUMN IF NOT EXISTS description') &&
  store.includes('description=?') && api.includes("$body['description']"),
  'project descriptions must be stored separately from AI instructions');
assert(js.includes("$('projectSettingsDescription').value") &&
  js.includes('description.textContent=p.description') &&
  js.includes("description:$('projectSettingsDescription').value"),
  'details dialog saves descriptions and project page displays them');
assert(js.includes("$('projectDirectorySearch').addEventListener('input',renderDirectory)") &&
  js.includes("$('projectDirectorySort').addEventListener('change',renderDirectory)") &&
  js.includes("$('sidebarProjectsToggle').addEventListener('click',openDirectory)"),
  'mobile project directory search, sort and navigation are connected');
const sidebarFold=read('js/coreui-sidebar-fold.js');
assert(html.includes('class="sidebar-projects-link"') &&
  !html.includes('id="projectsDirectoryBtn"') &&
  !html.includes('data-sidebar-fold="projects"') &&
  !sidebarFold.includes("'pinned','projects','recent'"),
  'projects must be a single link, not an expanding section');
const sidebarBlock=js.slice(js.indexOf('function renderSidebar()'),js.indexOf('function refreshLocked()'));
assert(!sidebarBlock.includes('projects.forEach(') &&
  !sidebarBlock.includes('project-nav-children-') &&
  !sidebarBlock.includes('project-nav-folder-toggle') &&
  sidebarBlock.includes('if (lockedChats.length)'),
  'no project folders or project chat sublists in the drawer; locked chats remain available');
assert(js.includes("openProject(p.id);") && js.includes("label:'Projekt öffnen'") &&
  js.includes("showFloatingMenu($('projectHomeMore'),'project',projectActions(p))"),
  'projects retain direct opening and workspace actions on their actual project pages');
assert(js.includes('if (lockedChats.length)') &&
  js.includes("CoreAIChatLock.unlockChat(chat)"),
  'protected chat folder and PIN unlock must remain available');
assert(html.includes('id="projectHomeMode"') &&
  js.includes("$('projectHomeMode').textContent"),
  'project start page must display its actual memory isolation mode');
assert(theme.includes('.project-nav-children[hidden]') &&
  theme.includes('.sidebar-projects-link') &&
  theme.includes('.session-zone .session-item'),
  'single project directory link and compact sidebar chat rows must have responsive styles');
assert(theme.includes('.project-composer-slot > .composer') &&
  theme.includes('body.project-home-active .project-empty-state') &&
  theme.includes('.project-floating-menu') &&
  theme.includes('position:fixed!important'),
  'native composer, empty-state and non-clipping floating menus must be styled');
// Regression: On phones, the drawer is deliberately elevated to z=999 and
// its backdrop to z=998. The popup is portalled to document.body, outside
// the clipped drawer, so it MUST live above both layers to be tappable.
// Previously it was z=180: the menu opened invisibly behind the drawer.
const mobileCss = consoleCss.slice(consoleCss.indexOf('CRITICAL MOBILE VIEWPORT FIX'));
const layer = (source, pattern, label) => {
  const match = source.match(pattern);
  assert(match, 'missing stacking rule: ' + label);
  return Number(match[1]);
};
const drawerLayer = layer(mobileCss,
  /\.drawer\s*\{[^}]*z-index:\s*(\d+)\s*!important\s*;/, 'mobile drawer');
const overlayLayer = layer(mobileCss,
  /\.drawer-overlay\s*\{[^}]*z-index:\s*(\d+)\s*!important\s*;/, 'mobile backdrop');
const popupLayer = layer(theme,
  /\.project-floating-menu,\s*body\.project-home-active \.project-floating-menu\s*\{[^}]*z-index:\s*(\d+)\s*!important\s*;/,
  'body-level project popup');
assert(popupLayer > drawerLayer && popupLayer > overlayLayer,
  'project three-dot popup must render ABOVE mobile drawer and backdrop: popup=' +
  popupLayer + ', drawer=' + drawerLayer + ', backdrop=' + overlayLayer);
assert(js.includes('document.body.appendChild(floatingMenu)') &&
  js.includes("showFloatingMenu(menu,'project',projectActions(p))"),
  'menu remains portalled to body and reachable from project folder ellipsis');
assert(js.includes('delete_permanently') &&
  js.includes("proof!=='LÖSCHEN'") &&
  js.includes('archive_first:true'),
  'project chat deletion must keep server confirmation and archive-first');
assert(theme.includes('.project-modal h2::before') &&
  theme.includes('.project-modal-primary') &&
  theme.includes('clip-path:polygon(') &&
  theme.includes('linear-gradient(90deg,var(--cx),var(--amb)'),
  'native blue/gold HUD must style the project home and creation modal');
console.log('CoreAI Projects: single sidebar link, project directory, native workspace and security OK');
