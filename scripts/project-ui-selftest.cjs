'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const read = p => fs.readFileSync(require('node:path').join(__dirname,'..',p),'utf8');
const html=read('app.html');
const js=read('js/coreui-projects.js');
const app=read('js/console-app.js');
const css=read('css/coreui-projects.css');
const api=read('api/console_sessions.php');
const store=read('api/console_project_store.php');
const knowledge=read('api/knowledge_store.php');
const prompts=read('api/chat.php');
const migration=read('database/migrations/037_chat_projects.sql');
assert(!html.includes('project-mode-switch') && !html.includes('Work wird separat entwickelt'), 'CoreAI chat already is the work area');
assert(!css.includes('project-mode-switch'), 'no dead separate mode CSS remains');
for (const id of ['projectsCreateBtn','projectsNavList','coreuiProjectHome','projectTabChats',
  'projectTabSources','projectSourceFile','projectModalSettings','projectSettingsInstructions',
  'projectSettingsMode','projectHomeChatForm','projectModalShare','projectHomeDrawerToggle']) {
  assert(html.includes('id="'+id+'"'), 'missing project control: '+id);
}
assert(css.includes('.project-modal-scrim[hidden]') && css.includes('#projectSourcesPanel[hidden]'),
  'hidden dialogs and tabs must not appear over the project homepage');
assert(css.includes('body.project-home-active .drawer') && css.includes('.project-chat-result-main'),
  'reference mode sidebar and multi-line chat cards exist');
assert(js.includes('chatAction(\'Chat umbenennen\'') && js.includes('chatAction(\'Chat löschen\''),
  'project chat ellipsis menu is actionable');
assert(app.includes('window.CoreAIProjects.refresh().catch'), 'project list reloads after authentication');
assert(css.includes('.project-drawer-brand {display:none;}'),
  'reference-only brand must stay hidden in normal chat');
assert(js.includes("linkedProject && projects.some"),
  'project deep links reopen after authentication');

assert(js.includes('startProjectChat(current,msg)'), 'project chat should invoke original chat');
assert(app.includes('state.draftProjectId') && app.includes('project_id: projectId'), 'new chat must have a server-owned project ID');
assert(api.includes("stu_require_csrf()"), 'mutations require CSRF');
assert(store.includes('coreui_project_session_scope') && store.includes('coreui_project_knowledge_block'), 'secure server-side project retrieval');
assert(knowledge.includes('s.project_id IS NULL'), 'global RAG must exclude project files');
assert(prompts.includes('COREUI_PROJECT_ISOLATED') && prompts.includes('projectKnowledgeBlock'), 'AI must honor isolated project mode');
assert(migration.includes('stu_console_projects') && migration.includes('project_id'), 'migration needs project and source scope');
console.log('CoreAI Projects: reference UI, private sources, context boundaries and chat integration OK');
