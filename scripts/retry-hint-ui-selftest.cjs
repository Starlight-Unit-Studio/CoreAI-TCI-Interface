// CoreAI 0.8.31: the note above the input (with the set assistant name) and the retry at an answer whose run broke off.
// node scripts/retry-hint-ui-selftest.cjs
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const app = read('app.html');
const client = read('js/console-app.js');
const css = read('css/console.css');

// The note sits above the input, in the composer, before the attachments; it is not hidden on phones.
const note = 'Mara kann Fehler machen. Bricht ein Lauf ab, tippe unter der Antwort auf ↻.';
assert(app.includes('<p class="composer-ai-note" id="composerAiNote">' + note + '</p>'), 'the note is in the composer');
assert(app.indexOf('id="composerAiNote"') > app.indexOf('<footer class="composer">')
  && app.indexOf('id="composerAiNote"') < app.indexOf('id="attachList"'), 'the note stands above the input');
// No rule anywhere (also inside the phone media queries) hides the note.
const noteRules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter(m => m[1].includes('composer-ai-note'));
assert(noteRules.length >= 2 && noteRules.every(m => !/display\s*:\s*none|visibility\s*:\s*hidden/.test(m[2])),
  'the note stays visible on phones');
assert(css.includes('.composer-ai-note {') && css.includes('.msg-retry-hint {') && css.includes('.msg-retry-btn {')
  && css.includes(':root[data-coreui-theme="dark"] .msg-retry-hint'), 'note, hint and button have light and dark styles');

const pick = name => {
  const start = client.indexOf('function ' + name + '(');
  assert(start >= 0, 'missing ' + name);
  let depth = 0;
  for (let i = client.indexOf('{', start); i < client.length; i++) {
    if (client[i] === '{') depth++;
    if (client[i] === '}' && --depth === 0) return client.slice(start, i + 1);
  }
  throw new Error('unclosed ' + name);
};

// The note names the assistant the person has set, and follows a later change of the name.
const noteEl = { textContent: note };
const named = { state: { assistantName: 'Nova' }, document: { getElementById: id => (id === 'composerAiNote' ? noteEl : null) } };
vm.createContext(named);
vm.runInContext(pick('refreshComposerAiNote') + '\nthis.refreshComposerAiNote = refreshComposerAiNote;', named);
named.refreshComposerAiNote();
assert.equal(noteEl.textContent, 'Nova kann Fehler machen. Bricht ein Lauf ab, tippe unter der Antwort auf ↻.', 'the note uses the set name');
named.state.assistantName = '';
named.refreshComposerAiNote();
assert.equal(noteEl.textContent, note, 'without a set name the note says Mara');
assert(/function refreshComposerPlaceholder\(\) \{\s*refreshComposerAiNote\(\);/.test(client),
  'the note is refreshed wherever the name is (login, profile, chat switch)');

// Behaviour of the retry hint, with a minimal document.
const states = client.match(/var RETRY_STATES = \[[^\]]*\];/);
assert(states, 'the retry states are defined');
const element = tag => ({
  tag, className: '', textContent: '', type: '', attributes: {}, children: [], listeners: {},
  setAttribute(k, v) { this.attributes[k] = String(v); },
  appendChild(c) { this.children.push(c); return c; },
  addEventListener(t, f) { this.listeners[t] = f; }
});
const started = [];
const sandbox = {
  document: { createElement: element },
  startGenerationAction: (record, mode, button) => started.push([record.id, mode, button.className])
};
vm.createContext(sandbox);
vm.runInContext(states[0] + '\n' + pick('runNeedsRetry') + '\n' + pick('buildRetryHint')
  + '\nthis.runNeedsRetry = runNeedsRetry; this.buildRetryHint = buildRetryHint;', sandbox);
const base = { id: 41, reply_to_id: 40 };
const withRun = (status, task) => Object.assign({}, base, { agent_run: { id: 'a'.repeat(32), status, task: task ? { status: task } : null } });
const withEvents = events => Object.assign({}, base, { work_events: events });
for (const [record, why] of [
  [withRun('interrupted'), 'an interrupted run'],
  [withRun('failed'), 'a failed run'],
  [withRun('completed', 'interrupted'), 'an interrupted task'],
  [withEvents([{ kind: 'run', state: 'error', title: 'Projektauftrag ohne bestaetigten Abschluss unterbrochen' }]), 'a run error in the work timeline'],
  [withEvents([{ kind: 'tool', state: 'error', title: 'Korrektur nicht lesbar' }]), 'a tool reply that stayed unreadable'],
]) {
  assert.equal(sandbox.runNeedsRetry(record), true, 'retry offered for ' + why);
  const hint = sandbox.buildRetryHint(record);
  assert(hint && hint.className === 'msg-retry-hint', 'hint shown for ' + why);
  assert.equal(hint.children[0].textContent, 'Dieser Lauf ist nicht abgeschlossen.');
  assert.equal(hint.children[1].textContent, '↻ Erneut versuchen');
  assert.equal(hint.children[1].attributes['aria-label'], 'Lauf erneut versuchen');
}
for (const [record, why] of [
  [withRun('completed', 'completed'), 'a completed run'],
  [withRun('cancelled', 'cancelled'), 'a run the person cancelled'],
  [withEvents([{ kind: 'tool', state: 'error', title: 'Websuche ohne Treffer' }, { kind: 'run', state: 'success', title: 'Antwort gespeichert' }]), 'a recovered tool error'],
  [base, 'an answer without run data'],
  [Object.assign(withRun('failed'), { id: 0 }), 'an answer that is not saved yet'],
  [null, 'no record'],
]) {
  assert.equal(sandbox.buildRetryHint(record), null, 'no hint for ' + why);
}
const failed = withRun('interrupted');
const hint = sandbox.buildRetryHint(failed);
hint.children[1].listeners.click();
assert.deepEqual(started, [[41, 'regenerate', 'msg-retry-btn']], 'the button starts a new answer for this turn');

// The hint stands above the action bar; the ↻ in the bar is marked for such an answer.
const hintAt = client.indexOf("var retryHint = role === 'ember' ? buildRetryHint(record || null) : null;\n    if (retryHint) content.appendChild(retryHint);");
assert(hintAt > 0 && hintAt < client.indexOf("var actions = buildMessageActions(role, text, record || null);"),
  'the hint is added to the answer, before the action bar');
assert(client.includes("if (runNeedsRetry(record)) regenerate.classList.add('is-retry-suggested');")
  && css.includes('.msg-action.is-retry-suggested'), 'the retry button of the action bar is highlighted');
console.log('CoreAI retry hint: note above the input on every screen, retry at an answer whose run broke off OK');
