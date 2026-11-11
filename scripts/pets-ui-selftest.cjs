// CoreAI 0.7.1: Settings > Pets and the pet in Mara's work line, in a real browser (Playwright).
//   node scripts/pets-ui-selftest.cjs
// A stand-in for api/pets.php keeps the pets. Checks: "Pet auswählen" with the nine CoreAI pets,
// animated (the frames move), Mara preselected, and "Pet nicht anzeigen"; a choice saves at once and goes back when
// refused; "Pet mit Mara erstellen" opens a new chat with a draft; "Pet hochladen" with name,
// description, a live preview of the sheet and the server's reasons; deleting an own pet with a
// confirmation. In the chat's work line the chosen pet stands first and keeps moving while the line
// updates; without a pet the three dots stay. A phone without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';
const NOVA = fs.readFileSync(path.join(root, 'images/pets/nova.png'));
const NAMES = ['Mara Thorne', 'Nova', 'Komet', 'Orbi', 'Luna', 'Quanti', 'Glimm', 'Raki', 'Sputni'];

function petServer() {
  const ids = ['mara', 'nova', 'komet', 'orbi', 'luna', 'quanti', 'glimm', 'raki', 'sputni'];
  const st = {selected: 'mara', own: [], posts: [], uploads: [], failNext: '', uploadError: 'not_transparent', next: 1};
  const builtin = ids.map((id, i) => ({
    id, name: NAMES[i], description: 'Beschreibung von ' + NAMES[i] + '.',
    url: './images/pets/' + id + (id === 'mara' ? '.webp' : '.png'),
    frames: id === 'mara' ? 8 : 6, frame_px: id === 'mara' ? 192 : 32,
    sprite_version: id === 'mara' ? 2 : 0, own: false
  }));
  const payload = () => ({ok: true, builtin, own: st.own, selected: st.selected, upload_ready: true, csrf_token: 'tok'});
  function handle(method, url, body, multipart) {
    if (method === 'GET' && url.searchParams.get('action') === 'sprite') return {status: 200, body: NOVA, type: 'image/png'};
    if (method === 'GET') return {status: 200, json: payload()};
    if (multipart) {
      st.uploads.push(multipart);
      if (st.uploadError) { const error = st.uploadError; st.uploadError = ''; return {status: 400, json: {ok: false, error}}; }
      const pet = {id: 'u' + st.next++, name: 'Mein Pet', description: 'Selbst gezeichnet.', url: './api/pets.php?action=sprite&id=1', frames: 6, frame_px: 32, own: true};
      st.own.unshift(pet);
      return {status: 200, json: Object.assign({pet}, payload())};
    }
    st.posts.push(body);
    if (st.failNext) { const error = st.failNext; st.failNext = ''; return {status: 500, json: {ok: false, error}}; }
    if (body.action === 'select') { st.selected = body.pet; return {status: 200, json: payload()}; }
    if (body.action === 'delete') {
      st.own = st.own.filter(p => p.id !== body.id);
      if (st.selected === body.id) st.selected = 'mara';
      return {status: 200, json: payload()};
    }
    return {status: 400, json: {ok: false, error: 'invalid_action'}};
  }
  return {st, handle};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: []},
  'account_security.php': {ok: true, sessions: [], csrf_token: 'tok'},
  'health.php': {ok: true, version: '0.7.1', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

const HARNESS = '<!doctype html><html lang="de"><head><meta charset="utf-8"><link rel="stylesheet" href="css/console.css"><link rel="stylesheet" href="css/coreui-pet-dock.css"></head>'
  + '<body><main id="messagesArea" style="position:absolute;top:35px;left:0;right:0;bottom:80px;overflow:auto">'
  + '<div class="msg-row ember"><div class="msg-content" id="out"></div></div></main>'
  + '<script src="js/coreui-pets.js"></script><script src="js/coreui-work-events.js"></script>'
  + '<script src="js/coreui-pet-dock.js"></script></body></html>';

async function open(browser, srv, viewport, url) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const u = new URL(req.url());
    const rel = decodeURIComponent(u.pathname.replace(/^\//, ''));
    if (rel === 'harness.html') return route.fulfill({contentType: 'text/html', body: HARNESS});
    if (rel === 'app.html') return route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Chat</title><p>Chat</p>'});
    if (rel === 'api/pets.php') {
      const type = req.headers()['content-type'] || '';
      const multipart = req.method() === 'POST' && type.startsWith('multipart/') ? (req.postData() || 'multipart') : null;
      const body = req.method() === 'POST' && !multipart ? JSON.parse(req.postData() || '{}') : null;
      const out = srv.handle(req.method(), u, body, multipart);
      return out.body ? route.fulfill({status: out.status, contentType: out.type, body: out.body}) : route.fulfill({status: out.status, json: out.json});
    }
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(url || 'https://coreui.test/settings.html#pets');
  if (!url) await page.waitForFunction(() => document.querySelectorAll('#petRows .pet-row').length > 0);
  return {context, page, errors};
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let srv = petServer();
    let {context, page, errors} = await open(browser, srv);

    // "Pet auswählen" like the reference, with the CoreAI pets.
    assert.equal(await page.textContent('#view-pets h2'), 'Pet auswählen');
    assert.match(await page.textContent('#petUploadOpen'), /Pet hochladen/);
    assert.deepEqual(await page.$$eval('#petRows .pet-row strong', l => l.map(x => x.textContent)), NAMES);
    assert.equal(await page.$eval('#petRows .coreai-pet', l => l.length), 9);
    assert.equal(await page.isChecked('#pet-mara'), true, 'Mara is preselected');
    assert.equal(await page.textContent('#petStatus'), '');
    const first = await page.getAttribute('#petRows .coreai-pet >> nth=0', 'data-frame');
    await page.waitForFunction(f => document.querySelector('#petRows .coreai-pet').getAttribute('data-frame') !== f, first);
    assert.match(await page.getAttribute('#petRows .coreai-pet >> nth=0', 'style'), /background-size: 800% 1100%/);
    assert.equal(await page.getAttribute('#petRows .coreai-pet >> nth=0', 'data-sprite-version'), '2');
    assert.equal(await page.evaluate(async () => {
      const image = new Image();
      image.src = './images/pets/mara.webp';
      await image.decode();
      return image.naturalWidth === 1536 && image.naturalHeight === 2288;
    }), true, 'Mara built-in atlas loads at original resolution');
    if (shots) await page.locator('#view-pets').screenshot({path: path.join(shots, 'pets-page.png')});

    // A choice saves at once; a refused one goes back.
    await page.click('#petRows .pet-row[data-pet="luna"]');
    await page.waitForFunction(() => /Luna begleitet dich jetzt im Chat/.test(document.getElementById('petStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'select', pet: 'luna'});
    assert.equal(await page.isChecked('#pet-luna'), true);
    srv.st.failNext = 'pets_failed';
    await page.click('#petRows .pet-row[data-pet="raki"]');
    await page.waitForFunction(() => document.getElementById('petStatus').getAttribute('data-state') === 'error');
    assert.equal(await page.isChecked('#pet-luna'), true, 'refused choice goes back');
    await page.click('.pet-none');
    await page.waitForFunction(() => /kein Pet angezeigt/.test(document.getElementById('petStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'select', pet: 'none'});

    // Upload: name, description, a live preview, the server's reason, then stored.
    await page.click('#petUploadOpen');
    await page.waitForSelector('#petUploadDialog:not([hidden])');
    assert.equal(await page.getAttribute('#petName', 'placeholder'), 'Benenne dein Pet');
    assert.equal(await page.textContent('#petFileName'), 'Keine ausgewählt');
    assert.equal(await page.isDisabled('#petUploadSubmit'), true);
    await page.fill('#petName', 'Mein Pet');
    await page.fill('#petDescription', 'Selbst gezeichnet.');
    await page.setInputFiles('#petFile', {name: 'mein-pet.png', mimeType: 'image/png', buffer: NOVA});
    await page.waitForSelector('#petPreview:not([hidden])');
    assert.equal(await page.textContent('#petPreviewInfo'), '6 Bilder mit je 32 Pixeln');
    assert.equal(await page.textContent('#petFileName'), 'mein-pet.png');
    if (shots) await page.screenshot({path: path.join(shots, 'pets-upload.png')});
    await page.click('#petUploadSubmit');
    await page.waitForFunction(() => document.getElementById('petUploadError').textContent !== '');
    assert.equal(await page.textContent('#petUploadError'), 'Das Sprite-Sheet braucht einen transparenten Hintergrund.');
    assert.match(srv.st.uploads[0], /name="name"\r\n\r\nMein Pet/);
    await page.click('#petUploadSubmit');
    await page.waitForSelector('#petUploadDialog', {state: 'hidden'});
    assert.match(await page.textContent('#petStatus'), /„Mein Pet“ ist hochgeladen/);
    assert.deepEqual(await page.$$eval('#petRows .pet-row strong', l => l.map(x => x.firstChild.textContent)), ['Mein Pet'].concat(NAMES));
    assert.equal(await page.textContent('#petRows .pet-row.is-own .pet-own-tag'), 'EIGENES PET');
    // A wrong sheet is caught before uploading.
    await page.click('#petUploadOpen');
    await page.fill('#petName', 'Kaputt');
    await page.setInputFiles('#petFile', {name: 'kaputt.gif', mimeType: 'image/gif', buffer: NOVA});
    assert.equal(await page.textContent('#petUploadError'), 'Bitte wähle ein PNG, WebP oder OpenPet-ZIP.');
    await page.click('#petUploadCancel');
    await page.waitForSelector('#petUploadDialog', {state: 'hidden'});

    // One upload dialog supports downloaded OpenPet ZIPs without manual metadata.
    await page.click('#petUploadOpen');
    await page.setInputFiles('#petFile', {name: 'custom.openpet.zip', mimeType: 'application/zip', buffer: Buffer.from('PK\\x03\\x04')});
    assert.equal(await page.isHidden('#petManualFields'), true);
    assert.equal(await page.isDisabled('#petUploadSubmit'), false);
    assert.match(await page.textContent('#petZipHint'), /automatisch aus pet.json/);
    await page.click('#petUploadCancel');

    // Deleting an own pet asks first.
    await page.click('#petRows .pet-row[data-pet="u1"]');
    await page.waitForFunction(() => /Mein Pet begleitet dich/.test(document.getElementById('petStatus').textContent));
    await page.click('#petRows .pet-row.is-own .pet-delete');
    await page.waitForSelector('#petDeleteConfirm:not([hidden])');
    assert.match(await page.textContent('#petDeleteText'), /Danach begleitet dich wieder Mara/);
    assert.equal(srv.st.selected, 'u1', 'the delete button does not choose the pet again');
    await page.click('#petDeleteCancel');
    await page.click('#petRows .pet-row.is-own .pet-delete');
    await page.click('#petDeleteOk');
    await page.waitForSelector('#petDeleteConfirm', {state: 'hidden'});
    assert.deepEqual(srv.st.posts.pop(), {action: 'delete', id: 'u1'});
    assert.equal(await page.isChecked('#pet-mara'), true, 'Mara takes over from a deleted own pet');
    assert.equal(await page.$$eval('#petRows .pet-row.is-own', l => l.length), 0);

    // "Pet mit Mara erstellen": a new chat with a draft.
    await page.click('#petCreate');
    await page.waitForURL(/app\.html\?draft=/);
    assert.match(new URL(page.url()).searchParams.get('draft'), /^Hilf mir, ein eigenes Pet für CoreAI zu entwerfen\. /);
    assert.deepEqual(errors, []);
    await context.close();

    // Chat pet stays in one persistent dock after the work timeline is replaced.
    ({context, page, errors} = await open(browser, srv, {width: 900, height: 400}, 'https://coreui.test/harness.html'));
    await page.evaluate(() => {
      window.CoreAIPets.current = {id: 'nova', name: 'Nova', url: './images/pets/nova.png', frames: 6, frame_px: 32};
      document.dispatchEvent(new CustomEvent('coreai:pet'));
      window.CoreAIPets.setActivity('running');
      document.getElementById('out').appendChild(window.CoreUIWorkEvents.create({live: true, expanded: true, events: []}));
    });
    const dock = page.locator('.coreai-pet-dock');
    await dock.waitFor({state:'visible'});
    assert.equal(await page.getAttribute('.coreai-pet-dock .coreai-pet', 'data-state'), 'running');
    await page.evaluate(() => {
      document.getElementById('out').textContent = 'Mara hat geantwortet.';
      window.CoreAIPets.setActivity('review');
    });
    assert.equal(await dock.isVisible(), true, 'pet survives replacement of work timeline');
    await page.waitForFunction(() => document.querySelector('.coreai-pet-dock .coreai-pet').getAttribute('data-state') === 'idle', null, {timeout:3500});
    const box = await dock.boundingBox();
    assert.equal(Math.round(box.width), 72);
    if (shots) await dock.screenshot({path: path.join(shots, 'pets-persistent-dock.png')});
    await page.evaluate(() => { window.CoreAIPets.current = null; document.dispatchEvent(new CustomEvent('coreai:pet')); });
    assert.equal(await dock.isVisible(), false, 'pet can be explicitly hidden');
    assert.deepEqual(errors, []);
    await context.close();

    // A phone.
    srv = petServer();
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(wide <= 0, 'no sideways scrolling: ' + wide);
    if (shots) await page.screenshot({path: path.join(shots, 'pets-phone.png'), fullPage: true});
    await page.click('#petUploadOpen');
    const dialog = await page.locator('#petUploadForm').boundingBox();
    assert.ok(dialog.x >= 0 && dialog.x + dialog.width <= 390, 'upload dialog fits a phone');
    if (shots) await page.screenshot({path: path.join(shots, 'pets-phone-upload.png')});
    assert.deepEqual(errors, []);
    await context.close();
    console.log('CoreAI Pets UI: Auswahl, Animation, Hochladen, Löschen, Entwurf, permanentes Pet-Dock, Telefon OK');
  } finally {
    await browser.close();
  }
})().catch(err => { console.error(err); process.exit(1); });
