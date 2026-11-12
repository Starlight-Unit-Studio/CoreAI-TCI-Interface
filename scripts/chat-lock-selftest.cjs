'use strict';
// CoreAI 0.7.22 locked-chat contract: no direct chat or media reads may bypass PIN.
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const html=read('app.html'), menu=read('js/coreui-chat-menu.js');
const lock=read('js/coreui-chat-lock.js'), projects=read('js/coreui-projects.js');
const sessions=read('api/console_session_store.php'), api=read('api/console_sessions.php');
const store=read('api/console_chat_lock_store.php'), media=read('api/console_media.php');
new Function(lock);new Function(projects);
assert(html.includes('coreui-chat-lock.js?v=') && html.includes('data-chat-action="lock"'));
assert(menu.includes('CoreAIChatLock.lockChat(chat)'));
assert(lock.includes('Konto-PIN bestätigen') && lock.includes("request('chat_lock_setup'"));
assert(lock.includes("request('chat_unlock',{id:chatId,pin:pin})"));
assert(lock.includes("'X-COREUI-CSRF'"));
assert(projects.includes('action=chat_locked_list') &&
  projects.includes('if (lockedChats.length)') &&
  projects.includes('CoreAIChatLock.unlockChat(chat)'));
assert(api.includes("stu_require_csrf()") &&
  api.includes("'chat_lock_status'") &&
  api.includes("'chat_locked_list'"));
assert(sessions.includes('AND locked_at IS NULL'));
assert(store.includes('password_hash($pin') && store.includes('password_verify($pin') &&
  store.includes('blocked_until') && store.includes('coreui_chat_lock_verify($pdo,$uid,$pin)'));
assert(media.includes('s.locked_at IS NOT NULL') &&
  media.includes('m.file_uuid=?') &&
  media.includes('a.media_uuid=?') &&
  media.includes("console_media_fail(403, 'chat locked')"));
console.log('CoreAI 0.7.22 chat lock routing and media PIN guard OK');
