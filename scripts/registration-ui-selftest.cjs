/* CoreAI 0.5.77: sign-up on the login page. 0.5.79: both guest access and
   sign-up first solve the self-hosted captcha (proof of work) in the page. The "Registrieren" button shows
   only while the admin has public registration switched on (Runtime); the
   form asks for a repeated password and an optional display name and uses
   the existing api/auth.php?action=register. "Als Gast chatten" shows only
   while the guest access is on and logs a fresh guest account in. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');

class Element {
  constructor(id) {
    this.id = id;
    this.hidden = false;
    this.disabled = false;
    this.value = '';
    this.textContent = '';
    this.attributes = {};
    this.handlers = {};
    this.style = {};
    this.focused = false;
  }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(type, fn) { this.handlers[type] = fn; }
  focus() { this.focused = true; }
}

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'login.html'), 'utf8');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const source = scripts[scripts.length - 1];
assert(source && source.includes('loginModeToggle'), 'the login script handles sign-up');

function page(opts) {
  const ids = ['email', 'password', 'passwordRepeat', 'displayName', 'displayNameGroup', 'passwordRepeatGroup',
    'loginError', 'loginBtn', 'loginModeToggle', 'guestBtn'];
  const els = Object.fromEntries(ids.map(id => [id, new Element(id)]));
  // Markup defaults from login.html.
  for (const id of ['displayNameGroup', 'passwordRepeatGroup', 'loginModeToggle', 'guestBtn']) {
    assert(new RegExp('id="' + id + '"[^>]*hidden').test(html), id + ' starts hidden');
    els[id].hidden = true;
  }
  els.loginBtn.textContent = 'EINLOGGEN';
  els.loginModeToggle.textContent = 'NOCH KEIN KONTO? REGISTRIEREN';
  els.guestBtn.textContent = 'ALS GAST CHATTEN';
  const calls = [];
  const replaced = [];
  const reply = body => Promise.resolve({json: () => Promise.resolve(body)});
  const fetch = (url, init) => {
    calls.push({url, init: init || {}});
    if (url === './api/auth.php?action=me') {
      return reply({ok: false, error: 'not_authenticated', registration_enabled: opts.open === true, guest_enabled: opts.guest === true});
    }
    if (url === './api/auth.php?action=captcha') return reply(opts.captcha || {ok: true, required: false});
    if (url === './api/auth.php?action=guest') {
      const replies = opts.guestReplies;
      if (replies && replies.length) return reply(replies.shift());
      return reply(opts.guestReply || {ok: true, user_id: 9, guest: {limit: 5, used: 0, left: 5}});
    }
    if (url === './api/auth.php?action=register') return reply(opts.registerReply || {ok: true, user_id: 7, permission_level: 4});
    return reply({ok: false, error: 'invalid_credentials'});
  };
  const win = {};
  vm.runInNewContext(fs.readFileSync(path.join(root, 'js/coreui-captcha.js'), 'utf8'), {window: win, setTimeout, Promise});
  const sandbox = {document: {getElementById: id => els[id] || null}, fetch, location: {replace: url => replaced.push(url)},
    JSON, Promise, window: win, Object, Math, String, Number};
  vm.runInNewContext(source, sandbox);
  return {els, calls, replaced};
}
const flush = async (n = 4) => { for (let i = 0; i < n; i++) await new Promise(resolve => setImmediate(resolve)); };
// A real captcha task like auth.php?action=captcha sends one (small bound for the test).
function task(number) {
  const salt = crypto.randomBytes(12).toString('hex') + '?expires=1999999999';
  return {ok: true, required: true, algorithm: 'SHA-256', salt, maxnumber: 20000, signature: 'ab'.repeat(32),
    challenge: crypto.createHash('sha256').update(salt + number).digest('hex')};
}
const until = async (check) => { for (let i = 0; i < 400 && !check(); i++) await new Promise(r => setTimeout(r, 5)); };

(async () => {
  // Sign-up closed: no button, the page stays a login.
  let p = page({open: false});
  await flush();
  assert.equal(p.els.loginModeToggle.hidden, true, 'closed sign-up shows no button');

  // Sign-up open: the button appears and switches the form.
  p = page({open: true});
  await flush();
  assert.equal(p.els.loginModeToggle.hidden, false, 'open sign-up shows the button');
  p.els.loginModeToggle.handlers.click();
  assert.equal(p.els.displayNameGroup.hidden, false);
  assert.equal(p.els.passwordRepeatGroup.hidden, false);
  assert.equal(p.els.loginBtn.textContent, 'KONTO ERSTELLEN');
  assert.equal(p.els.loginModeToggle.textContent, 'SCHON EIN KONTO? EINLOGGEN');
  assert.equal(p.els.password.getAttribute('autocomplete'), 'new-password');

  // Checks before anything is sent.
  p.els.email.value = 'neu@example.org';
  p.els.password.value = 'kurz';
  p.els.passwordRepeat.value = 'kurz';
  p.els.loginBtn.handlers.click();
  assert.match(p.els.loginError.textContent, /min\. 8 Zeichen/);
  p.els.password.value = 'langes-passwort';
  p.els.passwordRepeat.value = 'anderes-passwort';
  p.els.loginBtn.handlers.click();
  assert.match(p.els.loginError.textContent, /stimmen nicht überein/);
  assert.equal(p.calls.filter(c => c.url.includes('register')).length, 0, 'nothing sent while the form is wrong');

  // A valid form registers and opens the app.
  p.els.passwordRepeat.value = 'langes-passwort';
  p.els.displayName.value = '  Akashi  ';
  p.els.loginBtn.handlers.click();
  await flush();
  const post = p.calls.find(c => c.url === './api/auth.php?action=register');
  assert.equal(post.init.method, 'POST');
  assert.deepEqual(JSON.parse(post.init.body), {username: 'neu@example.org', password: 'langes-passwort', display_name: 'Akashi', captcha: null},
    'captcha switched off: nothing to solve');
  assert.deepEqual(p.replaced, ['app.html']);

  // Back to login.
  p.els.loginModeToggle.handlers.click();
  assert.equal(p.els.passwordRepeatGroup.hidden, true);
  assert.equal(p.els.loginBtn.textContent, 'EINLOGGEN');
  assert.equal(p.els.password.getAttribute('autocomplete'), 'current-password');

  // Taken e-mail and a switch closed in the meantime.
  p = page({open: true, registerReply: {ok: false, error: 'username_taken'}});
  await flush();
  p.els.loginModeToggle.handlers.click();
  p.els.email.value = 'da@example.org';
  p.els.password.value = 'langes-passwort';
  p.els.passwordRepeat.value = 'langes-passwort';
  p.els.loginBtn.handlers.click();
  await flush();
  assert.match(p.els.loginError.textContent, /schon ein Konto/);
  assert.equal(p.els.loginBtn.disabled, false);
  p = page({open: true, registerReply: {ok: false, error: 'registration_disabled'}});
  await flush();
  p.els.loginModeToggle.handlers.click();
  p.els.email.value = 'neu@example.org';
  p.els.password.value = 'langes-passwort';
  p.els.passwordRepeat.value = 'langes-passwort';
  p.els.loginBtn.handlers.click();
  await flush();
  assert.match(p.els.loginError.textContent, /gerade geschlossen/);
  assert.equal(p.els.loginModeToggle.hidden, true, 'a closed sign-up hides the button again');
  assert.equal(p.els.loginBtn.textContent, 'EINLOGGEN');

  // Guest access: only while switched on; one tap logs a guest in.
  p = page({open: false, guest: false});
  await flush();
  assert.equal(p.els.guestBtn.hidden, true, 'closed guest access shows no button');
  p = page({open: false, guest: true});
  await flush();
  assert.equal(p.els.guestBtn.hidden, false, 'open guest access shows the button');
  assert.equal(p.els.loginModeToggle.hidden, true, 'independent of registration');
  p.els.guestBtn.handlers.click();
  assert.equal(p.els.guestBtn.disabled, true);
  await flush();
  const guestPost = p.calls.find(c => c.url === './api/auth.php?action=guest');
  assert.equal(guestPost.init.method, 'POST');
  assert.deepEqual(p.replaced, ['app.html'], 'straight into the chat');

  // 0.5.79: with the captcha on, the page solves the task first and sends the solution.
  const solved = task(12345);
  p = page({guest: true, captcha: solved});
  await flush();
  p.els.guestBtn.handlers.click();
  await until(() => p.calls.some(c => c.url === './api/auth.php?action=guest'));
  const sent = JSON.parse(p.calls.find(c => c.url === './api/auth.php?action=guest').init.body).captcha;
  assert.equal(sent.number, 12345, 'the right number was found');
  assert.equal(crypto.createHash('sha256').update(sent.salt + sent.number).digest('hex'), solved.challenge, 'the server check holds');
  assert.equal(sent.signature, solved.signature, 'signature passed back unchanged');
  await flush();
  assert.deepEqual(p.replaced, ['app.html']);
  // An expired solution is solved again once, automatically.
  p = page({guest: true, captcha: task(7), guestReplies: [{ok: false, error: 'captcha_expired'}]});
  await flush();
  p.els.guestBtn.handlers.click();
  await until(() => p.replaced.length > 0);
  assert.equal(p.calls.filter(c => c.url === './api/auth.php?action=captcha').length, 2, 'a fresh task for the retry');
  assert.deepEqual(p.replaced, ['app.html'], 'second try succeeds');
  // Sign-up solves it as well.
  p = page({open: true, captcha: task(4321)});
  await flush();
  p.els.loginModeToggle.handlers.click();
  p.els.email.value = 'neu@example.org';
  p.els.password.value = 'langes-passwort';
  p.els.passwordRepeat.value = 'langes-passwort';
  p.els.loginBtn.handlers.click();
  await until(() => p.calls.some(c => c.url === './api/auth.php?action=register'));
  assert.equal(JSON.parse(p.calls.find(c => c.url === './api/auth.php?action=register').init.body).captcha.number, 4321, 'sign-up sends its solution');
  p = page({guest: true, guestReply: {ok: false, error: 'guest_capacity'}});
  await flush();
  p.els.guestBtn.handlers.click();
  await flush();
  assert.match(p.els.loginError.textContent, /sehr viele Gäste/);
  assert.equal(p.els.guestBtn.disabled, false);
  p = page({guest: true, guestReply: {ok: false, error: 'guest_disabled'}});
  await flush();
  p.els.guestBtn.handlers.click();
  await flush();
  assert.equal(p.els.guestBtn.hidden, true, 'a closed guest access hides the button again');

  // Server side: the public answer carries the switch, register uses the same check.
  const auth = fs.readFileSync(path.join(root, 'api/auth.php'), 'utf8');
  assert(auth.includes("stu_json(['ok' => false, 'error' => 'not_authenticated', 'registration_enabled' => $registrationOpen,\n    'guest_enabled' => $guestOpen, 'captcha_enabled' => $captchaOn], 200);"),
    'the public answer carries the switches');
  assert(/<script src="\.\/js\/coreui-captcha\.js\?v=\d+"><\/script>/.test(html), 'login page loads the solver');
  assert(auth.includes("if (!coreui_registration_enabled($pdo)) {"), 'register checks the same switch');
  assert(auth.includes("stu_app_setting_get($pdo, 'registration_enabled',"), 'the switch is the admin setting');
  const admin = fs.readFileSync(path.join(root, 'js/admin.js'), 'utf8');
  assert(admin.includes("registration_enabled: document.getElementById('registrationEnabled').checked ? '1' : '0'"),
    'the admin switch saves the same key');
  const css = fs.readFileSync(path.join(root, 'css/console.css'), 'utf8');
  assert(/\.login-field-group\[hidden\],\s*\.login-mode-toggle\[hidden\],\s*\.login-guest\[hidden\]\s*\{\s*display: none;/.test(css), 'hidden fields take no space');

  console.log('CoreAI sign-up and guest access: buttons only while open, checks, calls and errors OK');
})().catch(error => { console.error(error); process.exit(1); });
