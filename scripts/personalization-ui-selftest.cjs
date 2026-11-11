// CoreAI 0.7.0: Settings > Personalisierung in a real browser (Playwright), after the reference screenshots.
//   node scripts/personalization-ui-selftest.cjs
// Stand-ins for api/personalization.php, api/memory_summary.php, api/console_upload.php and
// api/memories.php. Checks: the sections of the reference and no save button; every switch saves
// only its own key (and goes back when the server refuses); the style menus with the seven base
// styles; Lockdown and the operator switch for Websuche; the sub page "Benutzerdefinierte
// Anweisungen für Mara" (Zurück, Abbrechen, Speichern, Spitzname, Beruf, Mehr über dich, Rolle);
// the Erinnerungszusammenfassung (Mara writes it, "Tiefer eintauchen" and the question row open a
// new chat, "…" menu with Info and "Erinnerung löschen und ausschalten", a busy Core with the
// saved memories instead); reference photos; Konnektorsuche planned (0.7.1: no Codex Cloud row); a phone
// without sideways scrolling.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const types = {'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.webp': 'image/webp'};
const shots = process.env.COREUI_SHOTS || '';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

const SUMMARY = '## Überblick\nDu baust ein Browserspiel und arbeitest gern konzentriert.\n\n## Vorlieben\nMorgens trinkst du grünen Tee.\n\n'
  + '## Tiefer eintauchen\n- Welche Spielmechanik passt als Nächstes?\n- Empfiehl mir eine Teesorte.\n- Plane meinen Arbeitstag.';

function server(opts) {
  const st = {
    settings: {tone: 'standard', warmth: 'mehr', enthusiasm: 'standard', emoji: 'mehr', visual_answers: 'mehr', headings_lists: 'mehr',
      adapt_style: false, quick_answers: false, suggested_prompts: true, web_search: true, browse_search: true, knowledge_search: true,
      voice_history: true, live_voice: true, custom_instructions: '', nickname: '', occupation: '', about: '', role: '',
      reference_portrait: '', reference_body: ''},
    references: {portrait: null, body: null},
    memory: true, posts: [], summaryPosts: [], uploads: 0, failNext: '', generateError: (opts && opts.generateError) || '',
    available: Object.assign({web: true, browse: true, image: true, lockdown: false}, (opts && opts.available) || {}),
  };
  function personalization(method, body) {
    if (method === 'GET') return {status: 200, json: {ok: true, settings: st.settings, references: st.references, memory_enabled: st.memory,
      available: st.available, schema_available: true, csrf_token: 'tok'}};
    st.posts.push(body);
    if (st.failNext) { const error = st.failNext; st.failNext = ''; return {status: 500, json: {ok: false, error}}; }
    if (body.action === 'save') { st.settings = Object.assign({}, st.settings, body.settings); return {status: 200, json: {ok: true, settings: st.settings, references: st.references}}; }
    if (body.action === 'memory') { st.memory = body.enabled; return {status: 200, json: {ok: true, memory_enabled: st.memory}}; }
    if (body.action === 'reference') {
      st.references[body.slot] = body.uuid ? {uuid: body.uuid, url: '/assets/chat_media/' + body.uuid + '.png'} : null;
      st.settings['reference_' + body.slot] = body.uuid || '';
      return {status: 200, json: {ok: true, settings: st.settings, references: st.references}};
    }
    return {status: 400, json: {ok: false, error: 'invalid_action'}};
  }
  function summary(method, body) {
    if (method === 'GET') return {status: 200, json: {ok: true, ready: true, memory_enabled: st.memory, fact_count: 2, fresh: false, summary: null, csrf_token: 'tok'}};
    st.summaryPosts.push(body);
    if (body.action === 'generate') {
      if (st.generateError) return {status: 503, json: {ok: false, error: st.generateError}};
      return {status: 200, json: {ok: true, ready: true, memory_enabled: st.memory, fact_count: 2, fresh: true,
        summary: {markdown: SUMMARY, fact_count: 2, created_at: '2026-11-11T08:00:00+01:00'}}};
    }
    if (body.action === 'wipe') { st.memory = false; return {status: 200, json: {ok: true, deleted: 2, memory_enabled: false}}; }
    return {status: 400, json: {ok: false, error: 'invalid_action'}};
  }
  return {st, personalization, summary};
}

const api = {
  'auth.php': {ok: true, user_id: 9, username: 'lena@example.test', display_name: 'Lena', permission_level: 4, is_guest: false, csrf_token: 'tok', avatars: {}},
  'profile.php': {ok: true, csrf_token: 'tok', profile: {display_name: 'Lena', assistant_name: 'Mara', avatars: {}}},
  'user_settings.php': {ok: true, csrf_token: 'tok', default_model: 'ember-core:e4b', default_label: 'TCI Core Normal', core_label: 'TCI Core Normal',
    permission_level: 4, settings: {system_prompt: '', memory_enabled: true, thinking_enabled: true, memory_limit: 16, num_predict: 6500, temperature: 1,
      provider: 'local', model_override: '', num_ctx: null, main_language: 'de'},
    limits: {system_prompt_chars: 6000, num_ctx_choices: [4096], num_ctx_available: true, language_available: true}, external: {enabled: false, configured: false}},
  'knowledge.php': {ok: true, csrf_token: 'tok', sources: [], totals: {sources: 0, chunks: 0, global_chunks: 0}},
  'memories.php': {ok: true, items: [{id: 1, fact: 'Lena baut ein Browserspiel.', scope: 'user'}, {id: 2, fact: 'Globaler Fakt.', scope: 'global'},
    {id: 3, fact: 'Lena trinkt grünen Tee.', scope: 'character'}]},
  'account_security.php': {ok: true, sessions: [], csrf_token: 'tok'},
  'health.php': {ok: true, version: '0.7.0', model: 'ember-core:e4b', checks: {database: true, schema: true, ollama: true, storage: true}},
};

async function open(browser, srv, viewport) {
  const context = await browser.newContext({viewport: viewport || {width: 1200, height: 900}, locale: 'de-DE', timezoneId: 'Europe/Berlin'});
  await context.route('https://coreui.test/**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const rel = decodeURIComponent(url.pathname.replace(/^\//, ''));
    const body = req.method() === 'POST' && !(req.headers()['content-type'] || '').startsWith('multipart/') ? JSON.parse(req.postData() || '{}') : null;
    if (rel === 'api/personalization.php') { const out = srv.personalization(req.method(), body); return route.fulfill({status: out.status, json: out.json}); }
    if (rel === 'api/memory_summary.php') { const out = srv.summary(req.method(), body); return route.fulfill({status: out.status, json: out.json}); }
    if (rel === 'api/console_upload.php') {
      srv.st.uploads++;
      return route.fulfill({json: {ok: true, uuid: 'abcdef0123456789abcdef012345678' + srv.st.uploads, kind: 'image'}});
    }
    if (rel.startsWith('assets/chat_media/')) return route.fulfill({contentType: 'image/png', body: PNG});
    if (rel === 'app.html') return route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Chat</title><p>Chat</p>'});
    if (rel.startsWith('api/')) return route.fulfill({json: api[rel.slice(4)] || {ok: true}});
    const file = path.join(root, rel || 'index.html');
    if (!fs.existsSync(file)) return route.fulfill({status: 404, body: ''});
    return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://coreui.test/settings.html#personalisierung');
  await page.waitForSelector('#view-personalisierung:not([hidden])');
  await page.waitForFunction(() => document.getElementById('personalizationStatus').textContent === '');
  return {context, page, errors};
}

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PW_CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
  try {
    let srv = server();
    let {context, page, errors} = await open(browser, srv);

    // The page of the reference, without a save button.
    assert.deepEqual(await page.$$eval('#pzMain > .sec-heading', l => l.map(x => x.textContent)),
      ['Mara-Erinnerung', 'Schreibstil', 'Referenzfotos', 'Benutzerdefinierte Anweisungen', 'Weitere Mara-Einstellungen']);
    assert.equal(await page.$('#personalizationSave'), null);
    assert.equal(await page.textContent('#personalizationStatus'), '');
    assert.equal(await page.isChecked('#personaMemoryEnabled'), true);
    assert.equal(await page.isChecked('#personaVoiceHistory'), true);
    assert.equal(await page.isChecked('#personaLive'), true);
    assert.equal(await page.isChecked('#personaAdaptStyle'), false);
    // 0.7.1: the chat already covers code, so the Codex Cloud row is gone; only Konnektorsuche is planned.
    assert.deepEqual(await page.$$eval('#view-personalisierung .sec-row.is-planned strong', l => l.map(x => x.textContent)), ['Konnektorsuche']);
    assert.equal(await page.$$eval('#view-personalisierung .sec-row strong', l => l.filter(x => /Codex/.test(x.textContent)).length), 0);
    assert.equal(await page.isDisabled('#personaConnectors'), true);
    assert.equal(await page.textContent('#personaEmoji span'), 'Mehr');
    if (shots) await page.locator('#view-personalisierung').screenshot({path: path.join(shots, 'personalization-page.png')});

    // A switch saves only its own key; a refused save goes back.
    await page.click('label[for="personaWeb"]');
    await page.waitForFunction(() => /Websuche gespeichert/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'save', settings: {web_search: false}});
    srv.st.failNext = 'personalization_failed';
    await page.click('label[for="personaVoiceHistory"]');
    await page.waitForFunction(() => document.getElementById('personalizationStatus').getAttribute('data-state') === 'error');
    assert.equal(await page.isChecked('#personaVoiceHistory'), true, 'refused save goes back');
    assert.match(await page.textContent('#personalizationStatus'), /Frühere Stimmsequenzen konnte nicht gespeichert werden/);
    await page.click('label[for="personaLive"]');
    await page.waitForFunction(() => /Live-Gespräch gespeichert/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'save', settings: {live_voice: false}});

    // The memory switch is the server switch of Memory.
    await page.click('label[for="personaMemoryEnabled"]');
    await page.waitForFunction(() => /Erinnerung deaktiviert/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'memory', enabled: false});
    await page.click('label[for="personaMemoryEnabled"]');
    await page.waitForFunction(() => /Erinnerung aktiviert/.test(document.getElementById('personalizationStatus').textContent));

    // The style menu with the seven base styles of the reference.
    await page.click('#personaTone');
    await page.waitForSelector('#pzMenu:not([hidden])');
    assert.equal(await page.getAttribute('#personaTone', 'aria-expanded'), 'true');
    assert.deepEqual(await page.$$eval('#pzMenu .pz-option b', l => l.map(x => x.textContent)),
      ['Standard', 'Professionell', 'Freundlich', 'Aufrichtig', 'Skurril', 'Effizient', 'Zynisch']);
    assert.deepEqual(await page.$$eval('#pzMenu .pz-option small', l => l.map(x => x.textContent)),
      ['Voreingestellter Stil und Tonfall', 'Ausgereift und präzise', 'Herzlich und gesprächig', 'Direkt und motivierend',
        'Verspielt und fantasievoll', 'Prägnant und klar', 'Kritisch und sarkastisch']);
    assert.deepEqual(await page.$$eval('#pzMenu [aria-checked="true"] b', l => l.map(x => x.textContent)), ['Standard']);
    const box = await page.locator('#pzMenu').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 1200, 'menu inside the window');
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-tone-menu.png')});
    await page.click('#pzMenu [data-value="effizient"]');
    await page.waitForSelector('#pzMenu', {state: 'hidden'});
    await page.waitForFunction(() => /Basisstil und -tonfall gespeichert/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'save', settings: {tone: 'effizient'}});
    assert.equal(await page.textContent('#personaTone span'), 'Effizient');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'personaTone');
    await page.click('#personaEmoji');
    assert.deepEqual(await page.$$eval('#pzMenu .pz-option b', l => l.map(x => x.textContent)), ['Mehr', 'Standard', 'Weniger', 'Keine']);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#pzMenu', {state: 'hidden'});
    assert.equal(await page.evaluate(() => document.activeElement.id), 'personaEmoji');

    // Sub page "Benutzerdefinierte Anweisungen für Mara".
    await page.click('.pz-nav .sec-copy');
    await page.waitForSelector('#pzSub-instructions:not([hidden])');
    assert.equal(await page.isHidden('#pzMain'), true);
    assert.equal(await page.textContent('#pzInstructionsTitle'), 'Benutzerdefinierte Anweisungen für Mara');
    assert.equal(await page.isDisabled('#pzInstructionsSave'), true, 'nothing changed yet');
    assert.deepEqual(await page.$$eval('#pzSub-instructions .pz-field strong', l => l.map(x => x.textContent)), ['Spitzname', 'Beruf', 'Mehr über dich', 'Rolle']);
    assert.equal(await page.getAttribute('#personaAbout', 'placeholder'), 'Interessen, Werte oder Vorlieben, die berücksichtigt werden sollen');
    await page.fill('#personaNickname', 'Lenchen');
    assert.equal(await page.isDisabled('#pzInstructionsSave'), false);
    const before = srv.st.posts.length;
    await page.click('#pzInstructionsCancel');
    await page.waitForSelector('#pzMain:not([hidden])');
    assert.equal(srv.st.posts.length, before, 'Abbrechen sends nothing');
    await page.click('.pz-nav .sec-open');
    assert.equal(await page.inputValue('#personaNickname'), '', 'cancelled text is gone');
    await page.fill('#personaInstructions', 'Bitte knapp antworten.');
    assert.equal(await page.textContent('#personaInstructionsCount'), '22 / 2400');
    await page.fill('#personaNickname', 'Lenchen');
    await page.fill('#personaOccupation', 'Entwicklerin');
    await page.fill('#personaAbout', 'Mag Tee und Science-Fiction.');
    await page.selectOption('#personaRole', 'entwicklung');
    if (shots) await page.locator('#view-personalisierung').screenshot({path: path.join(shots, 'personalization-instructions.png')});
    await page.click('#pzInstructionsSave');
    await page.waitForSelector('#pzMain:not([hidden])');
    assert.deepEqual(srv.st.posts.pop(), {action: 'save', settings: {custom_instructions: 'Bitte knapp antworten.', nickname: 'Lenchen',
      occupation: 'Entwicklerin', about: 'Mag Tee und Science-Fiction.', role: 'entwicklung'}});
    assert.match(await page.textContent('#personalizationStatus'), /Benutzerdefinierte Anweisungen gespeichert/);
    assert.equal(await page.textContent('#pzInstructionsPreview'), 'Bitte knapp antworten.');
    await page.click('.pz-nav .sec-open');
    await page.click('#pzInstructionsBack');
    await page.waitForSelector('#pzMain:not([hidden])');

    // Reference photos.
    let chooser = page.waitForEvent('filechooser');
    await page.click('[data-pz-ref="portrait"] .pz-ref-add');
    await (await chooser).setFiles({name: 'ich.png', mimeType: 'image/png', buffer: PNG});
    await page.waitForFunction(() => /Referenzfoto gespeichert/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'reference', slot: 'portrait', uuid: 'abcdef0123456789abcdef0123456781'});
    assert.equal(await page.isVisible('[data-pz-ref="portrait"] .pz-ref-thumb'), true);
    assert.equal(await page.textContent('[data-pz-ref="portrait"] .pz-ref-add span'), 'Ändern');
    assert.equal(await page.textContent('[data-pz-ref="body"] .pz-ref-add span'), 'Hinzufügen');
    chooser = page.waitForEvent('filechooser');
    await page.click('[data-pz-ref="body"] .pz-ref-add');
    await (await chooser).setFiles({name: 'ich.gif', mimeType: 'image/gif', buffer: PNG});
    await page.waitForFunction(() => /PNG, JPEG oder WebP/.test(document.getElementById('personalizationStatus').textContent));
    assert.equal(srv.st.uploads, 1, 'a GIF is not uploaded');
    if (shots) await page.locator('#pzReferences').screenshot({path: path.join(shots, 'personalization-references.png')});
    await page.click('[data-pz-ref="portrait"] .pz-ref-remove');
    await page.waitForFunction(() => /Referenzfoto entfernt/.test(document.getElementById('personalizationStatus').textContent));
    assert.deepEqual(srv.st.posts.pop(), {action: 'reference', slot: 'portrait', uuid: null});
    assert.equal(await page.isHidden('[data-pz-ref="portrait"] .pz-ref-thumb'), true);

    // Erinnerungszusammenfassung: Mara writes it.
    await page.click('#pzSummaryOpen');
    await page.waitForSelector('.pz-suggestion');
    assert.deepEqual(srv.st.summaryPosts.pop(), {action: 'generate'});
    assert.deepEqual(await page.$$eval('#pzSummaryText h4', l => l.map(x => x.textContent)), ['Überblick', 'Vorlieben', 'Tiefer eintauchen']);
    assert.deepEqual(await page.$$eval('.pz-suggestion span', l => l.map(x => x.textContent)),
      ['Welche Spielmechanik passt als Nächstes?', 'Empfiehl mir eine Teesorte.', 'Plane meinen Arbeitstag.']);
    assert.equal(await page.textContent('#pzSummaryState'), '');
    assert.equal(await page.isDisabled('#pzAskSend'), true);
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-summary.png')});
    await page.click('#pzSummaryMore');
    await page.waitForSelector('#pzSummaryMenu:not([hidden])');
    assert.deepEqual(await page.$$eval('#pzSummaryMenu .arc-option span', l => l.map(x => x.textContent)), ['Info zur Erinnerung', 'Erinnerung löschen und ausschalten']);
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-summary-menu.png')});
    await page.click('#pzSummaryInfo');
    await page.waitForSelector('#pzInfo:not([hidden])');
    assert.match(await page.textContent('#pzInfo'), /keine vollständige Liste/);
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-summary-info.png')});
    await page.click('#pzInfoOk');
    await page.waitForSelector('#pzInfo', {state: 'hidden'});
    // A suggestion opens a new chat with it in the input row.
    await page.click('.pz-suggestion >> nth=1');
    await page.waitForURL(/app\.html\?draft=/);
    assert.equal(new URL(page.url()).searchParams.get('draft'), 'Empfiehl mir eine Teesorte.');
    await page.goBack();
    await page.waitForFunction(() => document.getElementById('personalizationStatus').textContent === '');
    await page.click('#pzSummaryOpen');
    await page.waitForSelector('.pz-suggestion');
    await page.fill('#pzAsk', 'Was weißt du über meine Projekte?');
    await page.click('#pzAskSend');
    await page.waitForURL(/app\.html\?draft=/);
    assert.equal(new URL(page.url()).searchParams.get('draft'), 'Was weißt du über meine Projekte?');
    await page.goBack();
    await page.waitForFunction(() => document.getElementById('personalizationStatus').textContent === '');

    // "Erinnerung löschen und ausschalten".
    await page.click('#pzSummaryOpen');
    await page.waitForSelector('.pz-suggestion');
    await page.click('#pzSummaryMore');
    await page.click('#pzSummaryWipe');
    await page.waitForSelector('#pzWipeConfirm:not([hidden])');
    const wipes = srv.st.summaryPosts.length;
    await page.click('#pzWipeCancel');
    assert.equal(srv.st.summaryPosts.length, wipes, 'cancel sends nothing');
    await page.click('#pzSummaryMore');
    await page.click('#pzSummaryWipe');
    await page.click('#pzWipeOk');
    await page.waitForSelector('#pzSummaryDialog', {state: 'hidden'});
    assert.deepEqual(srv.st.summaryPosts.pop(), {action: 'wipe', confirm: true});
    assert.equal(await page.textContent('#personalizationStatus'), '2 Erinnerungen gelöscht. Die Erinnerung ist ausgeschaltet.');
    assert.equal(await page.isChecked('#personaMemoryEnabled'), false);
    assert.deepEqual(errors, []);
    await context.close();

    // A busy Core: the saved memories instead, and Erneut versuchen; Lockdown switches the web off.
    srv = server({generateError: 'core_busy', available: {lockdown: true, web: false, browse: false, image: false}});
    ({context, page, errors} = await open(browser, srv));
    assert.equal(await page.isDisabled('#personaWeb'), true);
    assert.equal(await page.isDisabled('#personaBrowse'), true);
    assert.equal(await page.textContent('[data-pz-tool="web"] .pz-blocked'), 'IM LOCKDOWN-MODUS AUS');
    assert.equal(await page.isDisabled('[data-pz-ref="portrait"] .pz-ref-add'), true, 'no photos without image creation');
    assert.match(await page.textContent('#pzRefNote'), /nicht eingerichtet oder für dein Konto nicht freigegeben/);
    await page.click('#pzSummaryOpen');
    await page.waitForSelector('#pzSummaryList:not([hidden])');
    assert.match(await page.textContent('#pzSummaryState'), /gerade mit anderen Anfragen beschäftigt/);
    assert.deepEqual(await page.$$eval('#pzSummaryList li', l => l.map(x => x.textContent)), ['Lena baut ein Browserspiel.', 'Lena trinkt grünen Tee.']);
    assert.equal(await page.isVisible('#pzSummaryRetry'), true);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#pzSummaryDialog', {state: 'hidden'});
    assert.deepEqual(errors, []);
    await context.close();

    // A phone: no sideways scrolling, no text under 11px, the sheet fits.
    srv = server();
    ({context, page, errors} = await open(browser, srv, {width: 390, height: 844}));
    const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(wide <= 0, 'no sideways scrolling: ' + wide);
    const small = await page.$$eval('#view-personalisierung *', l => l.filter(x => x.offsetParent && x.childNodes.length && Array.prototype.some.call(x.childNodes, n => n.nodeType === 3 && n.textContent.trim())
      && parseFloat(getComputedStyle(x).fontSize) < 11).map(x => x.textContent.trim().slice(0, 30)));
    assert.deepEqual(small, []);
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-phone.png'), fullPage: true});
    await page.click('#personaTone');
    const pbox = await page.locator('#pzMenu').boundingBox();
    assert.ok(pbox.x >= 0 && pbox.x + pbox.width <= 390, 'menu inside a phone');
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-phone-menu.png')});
    await page.keyboard.press('Escape');
    await page.click('#pzSummaryOpen');
    await page.waitForSelector('.pz-suggestion');
    const sheet = await page.locator('.pz-sheet').boundingBox();
    assert.ok(sheet.x >= 0 && sheet.x + sheet.width <= 390 && sheet.height <= 844, 'sheet fits the phone');
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-phone-summary.png')});
    await page.keyboard.press('Escape');
    await page.click('.pz-nav .sec-open');
    if (shots) await page.screenshot({path: path.join(shots, 'personalization-phone-instructions.png'), fullPage: true});
    assert.deepEqual(errors, []);
    await context.close();
    console.log('CoreAI Personalisierung UI: Seite, Schalter, Stile, Anweisungen, Referenzfotos, Zusammenfassung, Löschen, Telefon OK');
  } finally {
    await browser.close();
  }
})().catch(err => { console.error(err); process.exit(1); });
