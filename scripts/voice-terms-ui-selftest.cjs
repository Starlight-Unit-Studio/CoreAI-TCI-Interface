// CoreAI 0.6.22: names and terms for voice messages in the KI-Profil.
//   node scripts/voice-terms-ui-selftest.cjs
// The real settings page with mocked APIs (Playwright). Reported by the
// operator: Core heard "Bergisch Gladbach" as "Berlin", "Berchtesgaden" or
// "der Tisch ist glatt". The field shows the account's list, saves it two
// seconds after typing and when it is left (with the CSRF token), takes the
// cleaned list from the server, reports a failed save, stays hidden for guests
// and is switched off until migration 025 ran. Its text is never below 11 px
// and nothing scrolls sideways on a phone, in the night and the day theme.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2'};

function server(options) {
  const posts = [];
  const state = {terms: ['Bergisch Gladbach', 'EVK'], fail: false};
  return {posts, state, async route(route) {
    const request = route.request();
    const url = new URL(request.url());
    const rel = decodeURIComponent(url.pathname).replace(/^\/+/, '');
    if (rel === 'api/voice_input.php') {
      const action = url.searchParams.get('action');
      if (action === 'status') {
        if (options.guest) return route.fulfill({json: {ok: true, allowed: false}});
        return route.fulfill({json: {ok: true, allowed: true, hears: true, ready: true, max_seconds: 120, terms: state.terms,
          terms_ready: !options.noSchema, terms_max: 40, csrf_token: 'tok-terms'}});
      }
      if (action === 'terms' && request.method() === 'POST') {
        posts.push({body: JSON.parse(request.postData() || '{}'), csrf: request.headers()['x-coreui-csrf']});
        if (state.fail) return route.fulfill({status: 503, json: {ok: false, error: 'db_error'}});
        // The server's cleaning: split at lines and commas, trim, no duplicates.
        const seen = new Set();
        state.terms = String(posts[posts.length - 1].body.terms).split(/[\r\n,;]+/).map(t => t.trim()).filter(t => {
          const key = t.toLowerCase();
          if (!t || seen.has(key)) return false;
          seen.add(key);
          return true;
        });
        return route.fulfill({json: {ok: true, terms: state.terms}});
      }
      return route.fulfill({status: 404, json: {ok: false}});
    }
    if (rel === 'api/auth.php') {
      return route.fulfill({json: {ok: true, user_id: 9, username: 'rick@starlight.test', display_name: 'Rick', permission_level: options.guest ? 3 : 2,
        is_guest: !!options.guest, csrf_token: 'tok', avatars: {}}});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: {ok: true, csrf_token: 'tok'}});
    const file = path.join(root, rel || 'settings.html');
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  }};
}

async function open(browser, options, viewport) {
  const srv = server(options);
  const page = await browser.newPage({viewport: viewport || {width: 1280, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(theme => {
    try { localStorage.setItem('emberCoreUI.preferences.v1', JSON.stringify({theme: theme})); } catch (e) {}
  }, options.theme || 'dark');
  await page.route('http://coreui.test/**', route => srv.route(route));
  await page.goto('http://coreui.test/settings.html#ki-profil');
  return {page, srv, errors};
}

const smallText = page => page.evaluate(() => {
  const out = [];
  document.querySelectorAll('#voiceTermsRow, #voiceTermsRow *').forEach(node => {
    const own = Array.from(node.childNodes).some(child => child.nodeType === 3 && child.textContent.trim());
    if (!own && node.tagName !== 'TEXTAREA') return;
    const size = parseFloat(getComputedStyle(node).fontSize);
    if (size < 11) out.push(node.tagName + ' ' + size);
  });
  return out;
});

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    for (const theme of ['dark', 'light']) {
      const {page, srv, errors} = await open(browser, {theme});
      await page.waitForSelector('#voiceTermsRow:not([hidden])');
      assert.equal(await page.getAttribute('html', 'data-coreui-theme'), theme, 'the ' + theme + ' theme is on');
      assert.equal(await page.inputValue('#voiceTerms'), 'Bergisch Gladbach\nEVK', 'the account\'s list, one term per line');
      assert.equal(await page.textContent('#voiceTermsState'), '2 von 40 Begriffen');
      assert.equal(await page.isVisible('#voiceTermsRow'), true, 'in the KI-Profil');
      assert.deepEqual(await smallText(page), [], theme + ': no text below 11 px');

      // Typing saves two seconds after the last key; leaving the field takes the cleaned list.
      await page.click('#voiceTerms');
      await page.keyboard.press('Control+End');
      await page.keyboard.type('\nNicki, evk\nGladbach');
      assert.equal(await page.textContent('#voiceTermsState'), 'Wird gleich gespeichert …');
      await page.waitForFunction(() => document.getElementById('voiceTermsState').textContent.startsWith('Gespeichert'), null, {timeout: 5000});
      assert.equal(srv.posts.length, 1, 'one save after typing');
      assert.equal(srv.posts[0].csrf, 'tok-terms', 'with the CSRF token');
      assert.equal(srv.posts[0].body.terms, 'Bergisch Gladbach\nEVK\nNicki, evk\nGladbach');
      assert.equal(await page.inputValue('#voiceTerms'), 'Bergisch Gladbach\nEVK\nNicki, evk\nGladbach', 'the text is not replaced while typing');
      await page.click('h3#aiTuneTitle');
      await page.waitForFunction(() => document.getElementById('voiceTerms').value === 'Bergisch Gladbach\nEVK\nNicki\nGladbach', null, {timeout: 5000});
      assert.equal(await page.textContent('#voiceTermsState'), 'Gespeichert · 4 von 40 Begriffen');
      assert.equal(srv.posts.length, 2, 'leaving the field saves once more and shows the cleaned list');
      await page.click('#voiceTerms');
      await page.click('h3#aiTuneTitle');
      await page.waitForTimeout(300);
      assert.equal(srv.posts.length, 2, 'nothing changed: nothing saved');

      // A failed save says so and keeps the text.
      srv.state.fail = true;
      await page.fill('#voiceTerms', 'Bergisch Gladbach');
      await page.click('h3#aiTuneTitle');
      await page.waitForFunction(() => document.getElementById('voiceTermsState').textContent.startsWith('Nicht gespeichert'), null, {timeout: 5000});
      assert.equal(await page.inputValue('#voiceTerms'), 'Bergisch Gladbach', 'the text stays for another try');
      assert.deepEqual(errors, [], 'no page errors');
      await page.close();
    }

    // Guests never see the field.
    let run = await open(browser, {guest: true});
    await run.page.waitForTimeout(800);
    assert.equal(await run.page.isVisible('#voiceTermsRow'), false, 'no field for guests');
    await run.page.close();

    // Before migration 025: visible, switched off, with the reason.
    run = await open(browser, {noSchema: true});
    await run.page.waitForSelector('#voiceTermsRow:not([hidden])');
    assert.equal(await run.page.isDisabled('#voiceTerms'), true);
    assert.equal(await run.page.textContent('#voiceTermsState'), 'Nach dem nächsten Update verfügbar.');
    await run.page.close();

    // A phone: nothing scrolls sideways.
    run = await open(browser, {}, {width: 390, height: 844});
    await run.page.waitForSelector('#voiceTermsRow:not([hidden])');
    const wide = await run.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(wide <= 1, 'no sideways scrolling on a phone (' + wide + ' px)');
    const box = await run.page.evaluate(() => { const r = document.getElementById('voiceTerms').getBoundingClientRect(); return {left: r.left, right: r.right}; });
    assert.ok(box.left >= 0 && box.right <= 390, 'the field fits the phone');
    await run.page.close();
  } finally {
    await browser.close();
  }
  console.log('CoreAI voice terms: KI-Profil list of names and terms shown, saved (typing pause and leaving), cleaned, errors said, guests none, before migration off, night and day, phone OK');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
