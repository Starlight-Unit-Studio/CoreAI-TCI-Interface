'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const read = p => fs.readFileSync(require('node:path').join(__dirname,'..',p),'utf8');
const html=read('app.html');
const js=read('js/coreui-projects.js');
const app=read('js/console-app.js');
const api=read('api/console_sessions.php');
const store=read('api/console_project_store.php');
const knowledge=read('api/knowledge_store.php');
const prompts=read('api/chat.php');
const migration=read('database/migrations/037_chat_projects.sql');
for (const id of ['projectsCreateBtn','projectsNavList','coreuiProjectHome','projectTabChats',
  'projectTabSources','projectSourceFile','projectModalSettings','projectSettingsInstructions',
  'projectSettingsMode','projectHomeChatForm','projectModalShare','projectHomeDrawerToggle']) {
  assert(html.includes('id="'+id+'"'), 'missing project control: '+id);
}
assert(js.includes('startProjectChat(current,msg)'), 'project chat should invoke original chat');
assert(app.includes('state.draftProjectId') && app.includes('project_id: projectId'), 'new chat must have a server-owned project ID');
assert(api.includes("stu_require_csrf()"), 'mutations require CSRF');
assert(store.includes('coreui_project_session_scope') && store.includes('coreui_project_knowledge_block'), 'secure server-side project retrieval');
assert(knowledge.includes('s.project_id IS NULL'), 'global RAG must exclude project files');
assert(prompts.includes('COREUI_PROJECT_ISOLATED') && prompts.includes('projectKnowledgeBlock'), 'AI must honor isolated project mode');
assert(migration.includes('stu_console_projects') && migration.includes('project_id'), 'migration needs project and source scope');
console.log('CoreAI Projects: reference UI, private sources, context boundaries and chat integration OK');
