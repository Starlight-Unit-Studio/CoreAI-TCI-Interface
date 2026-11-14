'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'settings.html'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'css/settings.css'), 'utf8');
const settings = fs.readFileSync(path.join(root, 'js/settings.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'js/coreui-profile-editors.js'), 'utf8');

for (const id of ['profileEditorModal', 'assistantProfileModal', 'profileEditJump',
  'assistantProfileEdit', 'profileDisplayName', 'assistantDisplayName',
  'saveProfile', 'saveAssistantProfile', 'assistantAvatarPreview', 'assistantSummaryAvatar']) {
  assert.equal((html.match(new RegExp('id="' + id + '"', 'g')) || []).length, 1,
    'exactly one profile control ' + id);
}
for (let n = 1; n <= 4; n++) {
  const slot = 'assistant_ref_' + n;
  assert(html.includes('data-mara-slot="' + slot + '"'));
  assert(html.includes('data-mara-upload="' + slot + '"'));
  assert(html.includes('data-mara-remove="' + slot + '"'));
  assert(html.includes('id="maraExtraFile' + n + '"'));
}
assert(!html.includes('>PROFILE SPEICHERN</button>\n          </header>'),
  'old page-level profile save button is gone');
assert(/<\/section>\s*<\/div>\s*<div id="profileEditorModal"/.test(html),
  'user editor stays outside the visible profile card grid');
assert(styles.includes('.profile-edit-backdrop') && styles.includes('.mara-mini-grid'));
assert(settings.includes("openAvatarCrop(slot, file)") && settings.includes("deleteAvatar(slot)"));
assert(settings.includes("action:'assistant_name'") && settings.includes("action:'display_name'"));

class Node {
  constructor(id) {
    this.id = id;
    this.hidden = id.endsWith('Modal');
    this.style = {};
    this.events = {};
    this.children = [];
    this.attributes = {};
    this.focused = false;
  }
  addEventListener(name, listener) { this.events[name] = listener; }
  querySelector() { return this.children[0] || null; }
  querySelectorAll() { return this.children; }
  focus() { this.focused = true; documentStub.activeElement = this; }
  getAttribute(name) { return this.attributes[name] || null; }
  closest() { return null; }
  fire(name, data) { return this.events[name]?.(data || {target: this}); }
}
const ids = ['profileEditorModal', 'assistantProfileModal', 'profileEditJump',
  'assistantProfileEdit', 'profileCropDialog'];
const nodes = Object.fromEntries(ids.map(id => [id, new Node(id)]));
nodes.profileCropDialog.hidden = true;
for (const id of ['profileEditorModal', 'assistantProfileModal']) {
  nodes[id].children.push(new Node(id + '_field'));
}
const documentStub = {
  body: {style: {overflow: 'auto'}},
  activeElement: nodes.profileEditJump,
  getElementById: id => nodes[id] || null,
  events: {},
  addEventListener(name, callback) { this.events[name] = callback; },
  querySelectorAll() { return []; },
};
const sandbox = {window: {}, document: documentStub};
vm.runInNewContext(controller, sandbox, {filename: 'coreui-profile-editors.js'});
const active = sandbox.window.CoreUIProfileEditors.instance;
assert(active);
nodes.profileEditJump.fire('click');
assert.equal(nodes.profileEditorModal.hidden, false);
assert.equal(nodes.assistantProfileModal.hidden, true);
assert.equal(documentStub.body.style.overflow, 'hidden');
assert.equal(active.current(), 'user');
documentStub.events.keydown({key: 'Escape', preventDefault() {}});
assert.equal(nodes.profileEditorModal.hidden, true);
assert.equal(documentStub.body.style.overflow, 'auto');
nodes.assistantProfileEdit.fire('click');
assert.equal(active.current(), 'assistant');
assert.equal(nodes.assistantProfileModal.hidden, false);
nodes.assistantProfileModal.fire('click', {target: nodes.assistantProfileModal});
assert.equal(active.current(), null);
assert.equal(nodes.assistantProfileModal.hidden, true);

console.log('Profile modals: pencil, Mara card, four reference slots, private save actions, Escape and backdrop OK');
