<?php
declare(strict_types=1);
// CoreAI 0.7.0: Settings > Personalisierung after the reference screenshots, offline.
//   php scripts/personalization-page-selftest.php
// Pure parts: the seven base styles (older values move to the closest one), the fields of
// "Benutzerdefinierte Anweisungen", what Mara reads about the person, "an image of me" for the
// reference photos, the Erinnerungszusammenfassung prompt and its cleaning. With
// COREUI_SELFTEST_DB=1 on MariaDB (migration 033): only changed keys are saved, reference photos
// set, replaced and cleaned up, the image references of a turn, the stored overview and when it
// is written again, "Erinnerung loeschen und ausschalten" for one account only. Then the wiring.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_answer_style.php';
require_once $root . '/api/image_gen.php';
require_once $root . '/api/memory_summary_store.php';

function pz_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Personalisierung FAIL: {$why}\n"); exit(1); }
}
function pz_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// --- Pure: settings -----------------------------------------------------------------------------------
$d = coreui_personalization_defaults();
pz_test($d['voice_history'] === true && $d['live_voice'] === true && $d['reference_portrait'] === '' && $d['reference_body'] === ''
  && $d['nickname'] === '' && $d['occupation'] === '' && $d['about'] === '' && $d['role'] === '', 'new defaults');
pz_test(COREUI_PERSONALIZATION_TONES === ['standard', 'professionell', 'freundlich', 'aufrichtig', 'skurril', 'effizient', 'zynisch'], 'the seven base styles of the reference');
foreach (['locker' => 'freundlich', 'kreativ' => 'skurril', 'praezise' => 'effizient', 'zynisch' => 'zynisch', 'gibtsnicht' => 'standard'] as $in => $out) {
  pz_test(coreui_personalization_normalise(['tone' => $in])['tone'] === $out, "tone $in becomes $out");
}
$n = coreui_personalization_normalise(['nickname' => "  Kai\n\tder Tester ", 'occupation' => str_repeat('B', 300), 'about' => "Zeile 1\nZeile 2\x07",
  'role' => 'hacker', 'voice_history' => false, 'live_voice' => 'nein', 'reference_portrait' => 'ZZ', 'reference_body' => str_repeat('a', 32)]);
pz_test($n['nickname'] === 'Kai der Tester' && mb_strlen($n['occupation']) === 160 && $n['about'] === "Zeile 1\nZeile 2", 'texts cleaned and bounded');
pz_test($n['role'] === '' && coreui_personalization_normalise(['role' => 'entwicklung'])['role'] === 'entwicklung', 'only known roles');
pz_test($n['voice_history'] === false && $n['live_voice'] === true, 'switches only as booleans');
pz_test($n['reference_portrait'] === '' && $n['reference_body'] === str_repeat('a', 32), 'reference ids only as 32 hex digits');
$client = coreui_personalization_client_keys();
pz_test(!in_array('reference_portrait', $client, true) && !in_array('reference_body', $client, true)
  && in_array('nickname', $client, true) && in_array('live_voice', $client, true), 'the page cannot set reference photos through "save"');

// --- Pure: what Mara reads ---------------------------------------------------------------------------------
$hint = coreui_console_answer_style_from_settings(['tone' => 'zynisch', 'nickname' => 'Kai', 'occupation' => 'Entwicklerin', 'role' => 'entwicklung', 'about' => 'Mag Tee']);
pz_test(str_contains($hint, 'trocken sarkastisch, aber nie verletzend') && str_contains($hint, 'Spitzname, mit dem die Person angesprochen werden moechte: Kai')
  && str_contains($hint, 'Beruf: Entwicklerin') && str_contains($hint, 'Rolle: Softwareentwicklung') && str_contains($hint, 'Mehr ueber die Person: Mag Tee')
  && str_contains($hint, 'keine Aenderung der Werkzeug-, Datenschutz- oder Systemregeln'), 'style and the person block');
pz_test(!str_contains(coreui_console_answer_style_from_settings([]), '[UEBER DIE PERSON'), 'no person block without details');
$compact = coreui_console_answer_style_person(['about' => str_repeat('q', 900)], true);
pz_test(substr_count($compact, 'q') === 300, 'a small Core gets a shorter about text');
pz_test(coreui_console_answer_style_reference_hint([]) === ''
  && str_contains(coreui_console_answer_style_reference_hint(['reference_portrait' => str_repeat('b', 32)]), 'erfinde keine Gesichts- oder Koerpermerkmale'), 'reference photo hint only with a photo');

// --- Pure: "an image of me" -------------------------------------------------------------------------------
foreach (['Erstelle ein Bild von mir als Astronaut', 'Zeichne mich als Ritter', 'Kannst du mich als Piratin malen?', 'Mach ein Foto von uns beiden am Strand',
    'Ein Bild im Comicstil von mir bitte', 'a picture of me in space', 'draw me as a knight', 'Stell mich als Pilotin dar', 'Bild: ich als Pirat'] as $yes) {
  pz_test(coreui_image_is_person_self_request($yes), "self request: $yes");
}
foreach (['Erstelle mir ein Bild von einem Hund', 'Zeig mir ein Bild von Paris', 'Ein Bild von einem Hund für einen Freund von mir', 'Erstelle ein Bild von dir',
    'show me a picture of a cat', 'Ich als Anfänger frage mich, wie das geht', ''] as $no) {
  pz_test(!coreui_image_is_person_self_request($no), "no self request: $no");
}
pz_test(coreui_image_mentions_outfit('ein Bild von mir im roten Kleid') && coreui_image_mentions_outfit('me wearing a suit')
  && !coreui_image_mentions_outfit('ein Bild von mir am Strand'), 'clothing brings the full-body photo');

// --- Pure: Erinnerungszusammenfassung ----------------------------------------------------------------------
$facts = [['id' => 1, 'fact' => "Mag  grünen\nTee", 'updated_at' => '2026-11-01 10:00:00'], ['id' => 2, 'fact' => str_repeat('L', 500), 'updated_at' => '2026-11-02 10:00:00']];
$prompt = coreui_memory_summary_prompt($facts);
pz_test(str_contains($prompt, '- Mag grünen Tee') && str_contains($prompt, '## Überblick') && str_contains($prompt, '## Tiefer eintauchen')
  && str_contains($prompt, 'Genau drei Zeilen') && str_contains($prompt, 'erfinde nichts dazu'), 'summary prompt');
pz_test(!str_contains(coreui_memory_summary_prompt($facts, 100), 'LLLL'), 'facts beyond the budget stay out');
$clean = coreui_memory_summary_clean("<think>geheim</think>```markdown\n# Überblick\nDu magst Tee. [WEB: tee]\n\n### Vorlieben\n* Tee\n\n\n\n## Tiefer eintauchen\n* Welcher Tee?\n```");
pz_test($clean === "## Überblick\nDu magst Tee. \n\n## Vorlieben\n- Tee\n\n## Tiefer eintauchen\n- Welcher Tee?", 'cleaned overview: ' . json_encode($clean));
pz_test(coreui_memory_summary_clean('Nur Text.') === "## Überblick\nNur Text." && coreui_memory_summary_clean('<b></b>') === '', 'text without heading; empty');
$h1 = coreui_memory_summary_hash($facts);
pz_test($h1 === coreui_memory_summary_hash(array_reverse($facts)) && $h1 !== coreui_memory_summary_hash([$facts[0]])
  && $h1 !== coreui_memory_summary_hash([$facts[0], ['updated_at' => '2026-11-03 10:00:00'] + $facts[1]]), 'fingerprint of the memories');

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) -----------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  pz_test(coreui_personalization_schema_ready($my) && coreui_memory_summary_ready($my), 'MariaDB: migrations 022 and 033 applied');
  $tag = bin2hex(random_bytes(4));
  $ins = $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)");
  $ins->execute(["pz-a-$tag@example.test"]);
  $ua = (int)$my->lastInsertId();
  $ins->execute(["pz-b-$tag@example.test"]);
  $ub = (int)$my->lastInsertId();
  $dir = $root . '/assets/chat_media';
  $files = [];
  $media = static function (int $uid) use ($my, $dir, &$files): string {
    $uuid = bin2hex(random_bytes(16));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $uuid . '.png', $png);
    $files[] = $dir . '/' . $uuid . '.png';
    $my->prepare("INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, rel_path, public_url, mime_type, file_size)
      VALUES (?, ?, 'image', 'foto.png', ?, ?, ?, 'image/png', ?)")
      ->execute([$uuid, $uid, $uuid . '.png', 'assets/chat_media/' . $uuid . '.png', '/assets/chat_media/' . $uuid . '.png', strlen($png)]);
    return $uuid;
  };
  $mediaCount = static function (string $uuid) use ($my): int {
    $st = $my->prepare('SELECT COUNT(*) FROM stu_console_media WHERE uuid = ?');
    $st->execute([$uuid]);
    return (int)$st->fetchColumn();
  };
  try {
    // Saving only what changed; the reference photos never through "save".
    coreui_personalization_save($my, $ua, ['tone' => 'aufrichtig', 'web_search' => false]);
    $saved = coreui_personalization_update($my, $ua, ['voice_history' => false, 'reference_portrait' => str_repeat('c', 32)]);
    pz_test($saved['voice_history'] === false && $saved['tone'] === 'aufrichtig' && $saved['web_search'] === false
      && $saved['reference_portrait'] === '', 'MariaDB: only the changed key, no reference through save');

    // Reference photos.
    $p1 = $media($ua);
    $body = $media($ua);
    $foreign = $media($ub);
    pz_test(pz_code(fn() => coreui_personalization_reference_set($my, $ua, 'portrait', $foreign)) === 'invalid_reference'
      && pz_code(fn() => coreui_personalization_reference_set($my, $ua, 'face', $p1)) === 'invalid_slot', 'MariaDB: own image and known slot only');
    coreui_personalization_reference_set($my, $ua, 'portrait', $p1);
    $prefs = coreui_personalization_reference_set($my, $ua, 'body', $body);
    pz_test($prefs['reference_portrait'] === $p1 && $prefs['reference_body'] === $body && $prefs['tone'] === 'aufrichtig', 'MariaDB: both photos stored');
    $view = coreui_personalization_references_view($my, $ua, $prefs);
    pz_test($view['portrait']['url'] === '/assets/chat_media/' . $p1 . '.png' && $view['body']['uuid'] === $body, 'MariaDB: photos for the page');
    pz_test(coreui_personalization_image_references($my, $ua, $prefs, 'Erstelle ein Bild von mir als Astronautin') === ['upload:' . $p1 . '.png'], 'MariaDB: portrait for an image of me');
    pz_test(coreui_personalization_image_references($my, $ua, $prefs, 'Zeichne mich in einem roten Kleid') === ['upload:' . $p1 . '.png', 'upload:' . $body . '.png'], 'MariaDB: full body for clothing');
    pz_test(coreui_personalization_image_references($my, $ua, $prefs, 'Erstelle ein Bild von einem Leuchtturm') === [], 'MariaDB: no photo for other images');
    pz_test(coreui_personalization_image_references($my, $ub, $prefs, 'Erstelle ein Bild von mir') === [], 'MariaDB: never another account\'s photo');
    // Replacing the portrait deletes the old one; a photo already sent in a chat stays.
    $p2 = $media($ua);
    coreui_personalization_reference_set($my, $ua, 'portrait', $p2);
    pz_test($mediaCount($p1) === 0 && !is_file($dir . '/' . $p1 . '.png') && $mediaCount($p2) === 1, 'MariaDB: replaced photo removed with its file');
    $my->prepare("INSERT INTO stu_chat_messages (channel, user_id, character_id, character_name, message, file_uuid) VALUES ('console', ?, 'pz', 'Test', 'Bild', ?)")->execute([$ua, $body]);
    $prefs = coreui_personalization_reference_set($my, $ua, 'body', null);
    pz_test($prefs['reference_body'] === '' && $mediaCount($body) === 1, 'MariaDB: a photo used in a chat is kept');

    // Erinnerungszusammenfassung.
    pz_test(coreui_memory_summary_get($my, $ua, null)['fact_count'] === 0, 'MariaDB: no memories, no overview');
    $fact = $my->prepare("INSERT INTO ember_memories (fact, relevance, scope, user_id, fact_hash) VALUES (?, ?, ?, ?, ?)");
    $fact->execute(['Testperson A mag Tee.', 6, 'user', $ua, md5("a1$tag")]);
    $fact->execute(['Testperson A baut ein Spiel.', 8, 'user', $ua, md5("a2$tag")]);
    $fact->execute(['Testperson B mag Kaffee.', 5, 'user', $ub, md5("b1$tag")]);
    $fact->execute(["Globaler Testfakt $tag.", 5, 'global', null, md5("g1$tag")]);
    $calls = 0;
    $seen = '';
    $writer = static function (string $prompt) use (&$calls, &$seen): string {
      $calls++;
      $seen = $prompt;
      return "## Überblick\nDu baust ein Spiel.\n\n## Tiefer eintauchen\n- Welcher Tee passt?";
    };
    $first = coreui_memory_summary_get($my, $ua, $writer);
    pz_test($calls === 1 && $first['fact_count'] === 2 && str_starts_with((string)$first['summary']['markdown'], '## Überblick'), 'MariaDB: Mara writes the overview');
    pz_test(str_contains($seen, 'baut ein Spiel') && !str_contains($seen, 'Kaffee') && !str_contains($seen, 'Globaler Testfakt')
      && strpos($seen, 'baut ein Spiel') < strpos($seen, 'mag Tee'), 'MariaDB: own memories only, most relevant first');
    $again = coreui_memory_summary_get($my, $ua, $writer);
    pz_test($calls === 1 && $again['fresh'] === true, 'MariaDB: unchanged memories, no new call');
    $fact->execute(['Testperson A wohnt am Meer.', 3, 'user', $ua, md5("a3$tag")]);
    $stale = coreui_memory_summary_get($my, $ua, null);
    pz_test($stale['fresh'] === false && $stale['summary'] !== null && $stale['fact_count'] === 3, 'MariaDB: a new memory makes it stale');
    pz_test(pz_code(fn() => coreui_memory_summary_get($my, $ua, static fn(string $p): ?string => null)) === 'summary_failed', 'MariaDB: a failed write is reported');
    coreui_memory_summary_get($my, $ua, $writer);
    pz_test($calls === 2 && coreui_memory_summary_export($my, $ua)['fact_count'] === 3 && coreui_memory_summary_export($my, $ub) === [], 'MariaDB: written again and exported');

    // "Erinnerung loeschen und ausschalten" for one account only.
    pz_test(coreui_memory_summary_wipe($my, $ua) === 3 && coreui_memory_summary_get($my, $ua, null)['fact_count'] === 0
      && coreui_memory_summary_cached($my, $ua) === null, 'MariaDB: memories and overview deleted');
    $left = $my->prepare("SELECT COUNT(*) FROM ember_memories WHERE (scope = 'user' AND user_id = ?) OR fact = ?");
    $left->execute([$ub, "Globaler Testfakt $tag."]);
    pz_test((int)$left->fetchColumn() === 2, 'MariaDB: other accounts and global facts stay');
    $dbChecked = true;
  } finally {
    foreach ([$ua, $ub] as $u) {
      $my->prepare('DELETE FROM ember_memories WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_memory_summaries WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_chat_messages WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_console_media WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_user_personalization WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$u]);
    }
    $my->prepare('DELETE FROM ember_memories WHERE fact = ?')->execute(["Globaler Testfakt $tag."]);
    foreach ($files as $f) if (is_file($f)) unlink($f);
  }
}

// --- Wiring ------------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$mig = $read('database/migrations/033_memory_summary.sql');
pz_test(str_contains($mig, 'CREATE TABLE IF NOT EXISTS stu_memory_summaries') && str_contains($mig, 'ON DELETE CASCADE') && str_contains($mig, "('033_memory_summary')"), 'migration 033');
$chat = $read('api/chat.php');
pz_test(substr_count($chat, "\$GLOBALS['COREUI_TURN_USER_TEXT'] = \$channel === 'console' ? (string)\$userMsg : '';") === 2
  && str_contains($chat, "\$GLOBALS['COREUI_TURN_USER_TEXT'] = \$voiceRequest;")
  && str_contains($chat, '$personRefs = coreui_personalization_image_references($pdo, $uid, $personalization,')
  && str_contains($chat, "if (is_array(\$personalization) && empty(\$GLOBALS['COREUI_TURN_IMAGE_STYLE_ONLY'])) {"), 'chat: reference photos for images of the person');
pz_test(str_contains($chat, "\$voiceHistory = ((\$GLOBALS['COREUI_PERSONALIZATION_CONTEXT']['voice_history'] ?? true) !== false);")
  && str_contains($chat, 'array_map(static fn($r): int => (int)($r[\'id\'] ?? 0), $rows), $voiceHistory)')
  && str_contains($read('api/ember_attachments.php'), 'if ($voice && !$voiceWords) {'), 'chat: earlier Stimmsequenzen can be left out');
$live = $read('api/voice_live.php');
pz_test(str_contains($live, "\$liveOn = (coreui_personalization_load(\$pdo, \$uid)['live_voice'] ?? true) !== false;")
  && str_contains($live, "if (!\$liveOn) stu_json(['ok' => true, 'allowed' => false, 'reason' => 'personal_off']);")
  && str_contains($live, "if (!\$liveOn && \$action !== 'end') stu_json(['ok' => false, 'error' => 'live_off'], 403);"), 'live conversation follows the switch');
$api = $read('api/personalization.php');
pz_test(str_contains($api, "if (\$action === 'reference') {") && str_contains($api, 'coreui_personalization_update($pdo,(int)$uid,$body[\'settings\'])')
  && str_contains($api, "'available' => coreui_personalization_available(\$pdo,(int)\$uid),"), 'personalization API');
$sum = $read('api/memory_summary.php');
pz_test(str_contains($sum, '$slot = ember_global_lock_acquire(300);') && str_contains($sum, 'ember_generation_lock_release($slot);')
  && str_contains($sum, "if ((\$body['confirm'] ?? null) !== true) stu_json(['ok' => false, 'error' => 'confirmation_required'], 400);"), 'summary API: queue and confirmation');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $template) {
  pz_test(str_contains($read($template), '|personalization|memory_summary|'), "nginx allows memory_summary: $template");
}
pz_test(str_contains($read('api/account_export.php'), "'memory_summary' => coreui_memory_summary_export(\$pdo, (int)\$uid),"), 'export');
$html = $read('settings.html');
foreach (['pzMain', 'personaMemoryEnabled', 'pzSummaryOpen', 'pzSummaryDialog', 'pzSummaryMore', 'pzSummaryMenu', 'pzSummaryInfo', 'pzSummaryWipe', 'pzInfo',
    'pzWipeConfirm', 'pzAskForm', 'personaAdaptStyle', 'pzReferences', 'pzRefFile', 'personaTone', 'personaWarmth', 'personaEnthusiasm', 'personaEmoji',
    'personaVisual', 'personaHeadings', 'pzMenu', 'pzSub-instructions', 'personaInstructions', 'personaNickname', 'personaOccupation', 'personaAbout',
    'personaRole', 'pzInstructionsSave', 'pzInstructionsCancel', 'pzInstructionsBack', 'personaQuick', 'personaSuggestions', 'personaVoiceHistory',
    'personaWeb', 'personaBrowse', 'personaLive', 'personaKnowledge', 'personaConnectors', 'personalizationStatus'] as $id) {
  pz_test(str_contains($html, 'id="' . $id . '"'), "settings.html: $id");
}
pz_test(!str_contains($html, 'id="personalizationSave"') && str_contains($html, '<h3 class="sec-heading">Mara-Erinnerung</h3>')
  && str_contains($html, '<h3 class="sec-heading">Weitere Mara-Einstellungen</h3>') && str_contains($html, '<strong id="pzLabel-voice">Frühere Stimmsequenzen berücksichtigen</strong>')
  && str_contains($html, '<span>Erinnerung löschen und ausschalten</span>') && str_contains($html, 'placeholder="Fragen oder ergänzen"'), 'page after the reference, no save button');
pz_test(substr_count($html, '<span class="notify-planned">IN VORBEREITUNG</span>', strpos($html, 'id="view-personalisierung"'), strpos($html, '<!-- WISSEN -->') - strpos($html, 'id="view-personalisierung"')) === 2,
  'exactly Codex Cloud and Konnektorsuche are planned');
pz_test(!str_contains($html, '<button class="cfg-tab is-planned" type="button" role="tab" id="tab-archivierte-chats"'), 'Archivierte Chats is no longer marked planned in the menu');
$js = $read('js/coreui-personalization-settings.js');
pz_test(str_contains($js, "request(API, 'POST', {action: 'save', settings: changes})") && str_contains($js, "{action: 'reference', slot: slot, uuid: uuid}")
  && str_contains($js, "request(SUMMARY_API, 'POST', {action: 'generate'})") && str_contains($js, "{action: 'wipe', confirm: true}")
  && str_contains($js, "location.href = './app.html?draft=' + encodeURIComponent(text);"), 'page script calls the server');
pz_test(str_contains($read('js/console-app.js'), 'var draft = takeLinkedDraft();'), 'chat takes a draft link');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $pf) {
  $text = $read($pf);
  pz_test(str_contains($text, 'database/migrations/033_memory_summary.sql') && str_contains($text, 'api/memory_summary_store.php') && str_contains($text, 'api/memory_summary.php')
    && str_contains($text, 'scripts/personalization-page-selftest.php') && str_contains($text, 'scripts/personalization-ui-selftest.cjs'), "$pf lists and runs the page");
}

echo "CoreAI Personalisierung: Stile, Anweisungen, Bild von mir, Zusammenfassung, Verdrahtung OK"
  . ($dbChecked ? ', MariaDB (Speichern, Referenzfotos, Zusammenfassung, Loeschen) OK' : ', MariaDB-Teil uebersprungen (COREUI_SELFTEST_DB nicht gesetzt)') . "\n";
