// Offline DOM and wiring contract for the assistant header and image-first replies.
// No network, browser, model, storage, or live server required.
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const app = read('js/console-app.js');
const css = read('css/coreui-chat-tci-layout.css');
const html = read('app.html');

const begin = app.indexOf('  // Same TCI identity in live, saved and browser work messages.');
const end = app.indexOf('  // ── DOM: Nachricht einfügen', begin);
assert(begin > 0 && end > begin, 'shared TCI heading helper exists');
const source = app.slice(begin, end);
const document = {
  createElement(tag) {
    return {
      tagName: tag.toUpperCase(),
      className: '',
      textContent: '',
      children: [],
      appendChild(child) { this.children.push(child); return child; }
    };
  }
};
const state = { assistantName: 'Mara' };
const context = vm.createContext({document, state});
vm.runInContext(source, context, {timeout: 1500});

function shape(heading) {
  return JSON.parse(JSON.stringify({
    element: heading.tagName,
    cls: heading.className,
    children: heading.children.map(item => ({cls:item.className, text:item.textContent}))
  }));
}
const expected = value => ({element: 'DIV', cls: 'msg-name msg-name-tci', children:[
  {cls:'msg-core-role', text:'TCI CORE:'},
  {cls:'msg-core-persona', text:value}
]});
assert.deepEqual(shape(context.buildTciMessageHeading()), expected('MARA'), 'Mara displays the two-line role and name');
state.assistantName = 'Mara Thorne';
assert.deepEqual(shape(context.buildTciMessageHeading()), expected('MARA THORNE'), 'the custom profile name is used');
state.assistantName = '<script>alert(1)</script>';
assert.deepEqual(shape(context.buildTciMessageHeading()), expected('<SCRIPT>ALERT(1)</SCRIPT>'), 'custom names are inert text nodes, never HTML');
assert.equal((app.match(/buildTciMessageHeading\(\)/g) || []).length, 4,
  'helper, saved message, live working response and browser status use the same heading');
assert(app.includes("role === 'ember' ? buildTciMessageHeading()"), 'only assistant messages receive TCI CORE label');
assert(css.includes('.msg-row.ember > .msg-avatar') && css.includes('position: absolute;'),
  'assistant avatar is taken out of the body text column');
assert(css.includes('min-height: var(--tci-avatar-size)') && css.includes('margin: 0 0 9px calc(var(--tci-avatar-size) + 13px)'),
  'assistant body is below the avatar');
assert(css.includes('max-width: 100%') && css.includes('@media (max-width: 420px)'),
  'assistant uses the full available column, including on phones');
assert(/coreui-chat-tci-layout\.css\?v=\d{25}/.test(html), 'chat loads its cache-keyed layout style');
assert(app.includes("item.kind === 'image' && item.generated === true")
  && app.includes('content.appendChild(bubble);\n      if (suggestionRow) content.appendChild(suggestionRow);'),
  'generated images precede Mara\'s final written reply and suggestion buttons');
console.log('CoreAI TCI message layout: shared custom header, safe names, full-width mobile flow, image-first reply OK');
