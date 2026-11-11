// CoreAI 0.7.7: regression guard for real persistent red/green thumbs.
// node scripts/feedback-ui-selftest.cjs
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root,p),'utf8');
const client = read('js/console-app.js');
const style = read('css/coreui-feedback.css');
const endpoint = read('api/console_actions.php');
const model = read('api/console_feedback.php');
const store = read('api/console_feedback_store.php');
const migration = read('database/migrations/035_feedback_followups.sql');

assert(client.includes("up: 'M1 21h4V9H1v12z") && client.includes("down: 'M1 3h4v12H1V3z"),
  'real thumb silhouettes replace both up/down arrows');
assert(client.includes("up.classList.add('is-feedback-up')")
  && client.includes("down.classList.add('is-feedback-down')"),
  'thumbs retain original message-action dimensions');
assert(style.includes('.msg-action.is-feedback-up.active')
  && style.includes('color: #188c49;')
  && style.includes('.msg-action.is-feedback-down.active')
  && style.includes('color: #c43a40;'),
  'green and red selection are persistent CSS states');
assert(client.includes("record.feedback = data.feedback || null")
  && client.includes("record && record.feedback === 'up'")
  && client.includes("record && record.feedback === 'down'"),
  'saved history and the immediate action both render the selected vote');
assert(client.includes("eventId > 0")
  && client.includes("action: 'feedback_reply'"), 'one server event triggers the next Mara response');
assert(endpoint.includes("stu_require_csrf()") && endpoint.includes('coreui_feedback_reply_run('),
  'reaction requires authentication and uses the verified server event');
assert(model.includes("['__skills'=>'none'], null, false, false")
  && model.includes("coreui_feedback_reply_prompt("),
  'Mara reaction explicitly disables thinking and extra skills/tools');
assert(store.includes("reply_to_id,created_at") && store.includes("NULL,NULL,NOW()"),
  'feedback follow-up is independent of answer variants and contains no thinking transcript');
assert(migration.includes('stu_console_feedback_events')
  && migration.includes('response_message_id BIGINT UNSIGNED NULL'),
  'database keeps an idempotent event and the real reply message ID');
console.log('CoreAI feedback thumbs: icons, colors, persistence, no-thinking follow-up and DB contract OK');
