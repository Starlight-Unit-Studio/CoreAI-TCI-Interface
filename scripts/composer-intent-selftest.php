<?php
declare(strict_types=1);
// CoreAI 0.6.43: a tool chosen in the + menu of the input field.
//   php scripts/composer-intent-selftest.php
// Offline: the marks ([[bild]], [[bild:gross]], [[websuche]], [[recherche]]) are read only at
// the very start and taken out of the text; the chip labels; the instruction in Mara's prompt
// (and the friendly "not available" when the account may not use the tool); a reply without
// the tool's marker gets one with the person's own words; the endpoint's answer for accounts
// and guests; the archive and summary read "(Bild erstellen) ..." (SQLite); and the wiring
// (chat, stream, endpoint, nginx, page).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/image_gen.php';
require_once $root . '/api/console_compaction.php';

function intent_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Composer intent FAIL: {$why}\n"); exit(1); }
}

// --- Marks --------------------------------------------------------------------------------
intent_test(coreui_composer_intent_peek('[[bild]] ein Fuchs') === ['tool' => 'image', 'size' => 'normal'], 'image');
intent_test(coreui_composer_intent_peek("  [[bild:gross]]\nein Schloss") === ['tool' => 'image', 'size' => 'large'], 'large image, leading space');
intent_test(coreui_composer_intent_peek('[[websuche]] Wetter') === ['tool' => 'web', 'size' => 'normal'], 'web');
intent_test(coreui_composer_intent_peek('[[recherche]] Fahrplan') === ['tool' => 'browse', 'size' => 'normal'], 'browse');
intent_test(coreui_composer_intent_peek('[[recherche]]') === ['tool' => 'browse', 'size' => 'normal'], 'a mark alone (files only)');
foreach (['Ein [[bild]] im Text', '[[BILD]] x', '[[bild:klein]] x', '[bild] x', '[[ bild ]] x', '', '[BILD: a fox]'] as $text) {
  intent_test(coreui_composer_intent_peek($text) === null, 'no mark: ' . $text);
}
intent_test(coreui_composer_intent_strip('[[bild]] ein Fuchs') === 'ein Fuchs', 'strip');
intent_test(coreui_composer_intent_strip("[[websuche]]\n\nWetter") === 'Wetter', 'strip the line break');
intent_test(coreui_composer_intent_strip('[[bild]] [[bild]] x') === '[[bild]] x', 'only the first mark');
intent_test(coreui_composer_intent_strip('ohne Marke') === 'ohne Marke', 'nothing to strip');
$m = '[[bild:gross]] ein Leuchtturm';
$taken = coreui_composer_intent_take($m);
intent_test($taken === ['tool' => 'image', 'size' => 'large'] && $m === 'ein Leuchtturm', 'take');
$m = 'nur Text';
intent_test(coreui_composer_intent_take($m) === null && $m === 'nur Text', 'take without a mark');

// --- Labels and readable form --------------------------------------------------------------
intent_test(coreui_composer_intent_label(['tool' => 'image', 'size' => 'normal']) === 'Bild erstellen', 'label image');
intent_test(coreui_composer_intent_label(['tool' => 'image', 'size' => 'large']) === 'Bild erstellen (groß)', 'label large');
intent_test(coreui_composer_intent_label(['tool' => 'web']) === 'Websuche' && coreui_composer_intent_label(['tool' => 'browse']) === 'Recherche', 'labels');
intent_test(coreui_composer_intent_label([]) === '', 'no label');
intent_test(coreui_composer_intent_readable('[[bild:gross]] ein Leuchtturm') === '(Bild erstellen (groß)) ein Leuchtturm', 'readable');
intent_test(coreui_composer_intent_readable('[[websuche]]') === '(Websuche)', 'readable without text');
intent_test(coreui_composer_intent_readable('Hallo [[bild]]') === 'Hallo [[bild]]', 'readable without a mark');

// --- Mara's instruction ---------------------------------------------------------------------
$hint = coreui_composer_intent_hint(['tool' => 'image', 'size' => 'normal'], true);
intent_test(str_contains($hint, 'Mara bzw. dein Rufname')
  && str_contains($hint, 'erfinde kein anderes Gesicht'),
  'image composer explains named self-portraits using the account visual identity');
intent_test(str_starts_with($hint, 'AUSWAHL IM MENUE: Die Person hat "Bild erstellen" gewaehlt.')
  && str_contains($hint, 'antworte NUR mit [BILD: ...] oder [BILD ICH: ...] und sonst nichts.'), 'image instruction');
intent_test(str_contains(coreui_composer_intent_hint(['tool' => 'image', 'size' => 'large'], true), '[BILD GROSS: ...] oder [BILD GROSS ICH: ...]'),
  'large image instruction');
intent_test(str_contains(coreui_composer_intent_hint(['tool' => 'web'], true), 'antworte NUR mit [WEB: genaue Suchbegriffe] und sonst nichts.'), 'web instruction');
intent_test(str_contains(coreui_composer_intent_hint(['tool' => 'browse'], true), 'antworte NUR mit [BROWSE: was du herausfinden sollst] und sonst nichts.'),
  'browse instruction');
$offHint = coreui_composer_intent_hint(['tool' => 'image', 'size' => 'normal'], false);
intent_test(str_contains($offHint, 'gerade nicht freigegeben ist (Bilder zu malen)') && str_contains($offHint, 'Sag das kurz und freundlich')
  && !str_contains($offHint, '[BILD'), 'a tool that is off: say so, no marker');
intent_test(str_contains(coreui_composer_intent_hint(['tool' => 'web'], false), '(im Netz zu suchen)'), 'web off');
intent_test(str_contains(coreui_composer_intent_hint(['tool' => 'browse'], false), '(Webseiten zu recherchieren)'), 'browse off');
foreach ([$hint, $offHint] as $h) intent_test(!preg_match('/Modell/u', $h), 'no "Modell" in the instruction');

// --- Not left to chance ---------------------------------------------------------------------
$img = ['tool' => 'image', 'size' => 'normal'];
intent_test(coreui_composer_intent_ensure($img, '[BILD: a red fox in the snow]', 'ein Fuchs', true) === '[BILD: a red fox in the snow]', 'marker kept');
intent_test(coreui_composer_intent_ensure($img, '[BILD: Mara as Superwoman]', 'Zeig dich als Superwoman', true)
  === '[BILD ICH: Mara as Superwoman]', 'old marker upgraded only for explicit self portrait');
intent_test(coreui_composer_intent_ensure($img, '[BILD: faithful photo of Mara Thorne]', 
  'wie siehst du eigentlich aus wenn ich fragen darf? kannste mir das auch auf einem bild zeigen?', true)
  === '[BILD ICH: faithful photo of Mara Thorne]',
  'natural Mara appearance request with image chip still upgrades to reference');
intent_test(coreui_composer_intent_ensure($img, '[BILD ICH: Superwoman over the city]', 'Zeichne Superwoman', true)
  === '[BILD: Superwoman over the city]', 'fictional role cannot force a Mara reference onto unrelated pictures');
intent_test(coreui_composer_intent_ensure($img, '[BILD: a woman with black hair in a spaceship]', 'Mara im Cockpit', true)
  === '[BILD ICH: a woman with black hair in a spaceship]',
  'scene-based Mara requests upgrade generic generated prompts to canonical references');
intent_test(coreui_composer_intent_ensure($img, '[BILD: red dress, cinematic lighting]', 'Ein Bild mit Ember am Strand', true, 'Ember')
  === '[BILD ICH: red dress, cinematic lighting]',
  'the account assistant nickname selects its own portrait identity');
intent_test(coreui_composer_intent_ensure($img, '[BILD ICH: Mara in a red evening gown]', 'diesmal im roten Kleid', true)
  === '[BILD ICH: Mara in a red evening gown]',
  'elliptical follow-up does not silently discard an explicit ICH identity marker');
intent_test(coreui_composer_intent_ensure(['tool'=>'image','size'=>'large'], '[BILD GROSS ICH: Mara on a bridge]',
  'noch eins', true) === '[BILD GROSS ICH: Mara on a bridge]',
  'repeated larger portrait keeps the previously chosen ICH binding');
intent_test(coreui_composer_intent_ensure($img, '[BILD ICH: generic woman]', 'Mara, male eine fremde Frau', true)
  === '[BILD: generic woman]', 'vocative Mara plus a DIFFERENT requested woman does not use her photo');

intent_test(coreui_composer_intent_ensure($img, 'Gern male ich dir einen Fuchs!', 'ein roter Fuchs im Schnee', true)
  === '[BILD: ein roter Fuchs im Schnee]', 'no marker: the person\'s words');
intent_test(coreui_composer_intent_ensure(['tool' => 'image', 'size' => 'large'], 'Klar!', 'ein [Schloss] bei Nacht', true)
  === '[BILD GROSS: ein Schloss bei Nacht]', 'large, brackets out of the words');
intent_test(coreui_composer_intent_ensure($img, 'Gern!', 'Zeig dich als Superwoman', true)
  === '[BILD ICH: Zeig dich als Superwoman]', 'self portrait from composer intent');
intent_test(coreui_composer_intent_ensure($img, 'Gern!', 'Nova als Superwoman', true, 'Nova')
  === '[BILD ICH: Nova als Superwoman]', 'renamed TCI in roleplay keeps personal reference');
intent_test(coreui_composer_intent_ensure($img, 'Klar!', 'ab', true) === 'Klar!', 'too few words: nothing forced');
intent_test(coreui_composer_intent_ensure($img, 'Klar!', '', true) === 'Klar!', 'a Stimmsequenz: Mara heard it, nothing forced');
intent_test(coreui_composer_intent_ensure($img, 'Das darf ich hier nicht.', 'ein Fuchs', false) === 'Das darf ich hier nicht.', 'off: nothing forced');
intent_test(coreui_composer_intent_ensure(['tool' => 'web'], 'Moment.', "Wetter\n  Köln morgen", true) === '[WEB: Wetter Köln morgen]', 'web');
intent_test(coreui_composer_intent_ensure(['tool' => 'web'], '[WEB: Wetter Köln]', 'x y z', true) === '[WEB: Wetter Köln]', 'web marker kept');
intent_test(coreui_composer_intent_ensure(['tool' => 'browse'], 'Ich schaue nach.', 'Öffnungszeiten Zoo Köln', true)
  === '[BROWSE: Öffnungszeiten Zoo Köln]', 'browse');
intent_test(coreui_composer_intent_ensure(['tool' => 'browse'], '[browse: Zoo Köln Zeiten]', 'x y z', true) === '[browse: Zoo Köln Zeiten]', 'browse marker kept');
$long = str_repeat('Wort ', 400);
intent_test(mb_strlen(coreui_composer_intent_ensure(['tool' => 'web'], 'x', $long, true)) <= 300 + 7, 'web words capped');
intent_test(mb_strlen(coreui_composer_intent_ensure($img, 'x', $long, true)) <= 1000 + 8, 'image words capped');

// --- The endpoint's answer ------------------------------------------------------------------
$a = coreui_composer_tools_answer(false, true, true, false);
intent_test($a['ok'] === true && $a['guest'] === false && $a['tools']['image'] === ['on' => true, 'why' => '']
  && $a['tools']['browse'] === ['on' => false, 'why' => 'Für dein Konto gerade nicht freigegeben']
  && $a['planned'] === ['deep_research', 'connectors'], 'account answer');
$g = coreui_composer_tools_answer(true, true, true, true);
intent_test($g['tools']['image'] === ['on' => false, 'why' => 'Für Gäste nicht verfügbar'] && $g['tools']['web']['on'] === true, 'guests paint no images');

// --- Archive and summary (SQLite) -----------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, channel TEXT, user_id INT, session_id TEXT, alliance_id INT,
  deleted_at TEXT, character_id TEXT, character_name TEXT, message TEXT, created_at TEXT)');
$ins = $pdo->prepare("INSERT INTO stu_chat_messages (id, channel, user_id, session_id, character_id, character_name, message, created_at)
  VALUES (?, 'console', 7, 'cs_a', ?, ?, ?, '2026-11-07 10:00:00')");
$ins->execute([1, 'c1', 'Lena', '[[bild]] ein Fuchs']);
$ins->execute([2, 'ember', 'Mara', '[[bild]] bleibt wie es ist']);
$ins->execute([3, 'c1', 'Lena', 'Danke!']);
$rows = coreui_compact_messages($pdo, 7, 'cs_a', 0);
intent_test(array_column($rows, 'message') === ['(Bild erstellen) ein Fuchs', '[[bild]] bleibt wie es ist', 'Danke!'], 'archive rows');
$md = coreui_compact_markdown($rows);
intent_test(str_contains($md, "(Bild erstellen) ein Fuchs\n") && !str_contains($md, '[[bild]] ein Fuchs'), 'archive markdown');
intent_test(str_contains(coreui_compact_prompt('', $rows, 4000), 'Nutzer: (Bild erstellen) ein Fuchs'), 'summary prompt');

// --- Wiring ---------------------------------------------------------------------------------
$chat = (string)file_get_contents($root . '/api/chat.php');
intent_test(str_contains($chat, "require_once __DIR__ . '/console_composer_intent.php';"), 'chat loads the library');
intent_test(substr_count($chat, "\$composerIntent = \$channel === 'console' ? coreui_composer_intent_take(\$userMsg) : null;") === 2,
  'both prompt builders take the mark out');
intent_test(substr_count($chat, "\$intentHint = ember_composer_intent_hint(\$composerIntent, \$imageUrl, \$consoleUid);") === 2, 'both build the instruction');
intent_test(str_contains($chat, "foreach ([\$webHint ?? '', \$browseHint ?? '', \$pyHint ?? '', \$imageHint ?? '', \$intentHint ?? ''] as \$hint) {"),
  'the work loop gets the prompt without the instruction');
intent_test(str_contains($chat, "'image' => ember_image_allowed(\$uid),") && str_contains($chat, "'web' => ember_web_enabled(),")
  && str_contains($chat, "'browse' => ember_browse_enabled(),"), 'the same switches as the work loop');
intent_test(str_contains($chat, "if (\$composerIntent !== null && !\$fileTask && \$imageUrl === null && is_string(\$out)) {"), 'ensure runs before the tools');
intent_test(str_contains($chat, "      coreui_composer_intent_strip(\$message)\n"), 'the chat title without the mark');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
intent_test(str_contains($stream, "\$fileTaskHandled = !\$isVisionTurn && \$attachmentIds === [] && \$composerIntentStream === null")
  && str_contains($stream, "|| (\$composerIntentStream !== null && !\$isVisionTurn && !\$fileTaskHandled);"), 'the stream takes the tool path');
$endpoint = (string)file_get_contents($root . '/api/composer_tools.php');
intent_test(str_contains($endpoint, "define('STU_CHAT_LIB', 1);") && str_contains($endpoint, "header('Cache-Control: private, no-store');"), 'endpoint');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $f) {
  intent_test(str_contains((string)file_get_contents($root . '/' . $f), '|composer_tools|'), $f);
}
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $f) {
  $pre = (string)file_get_contents($root . '/' . $f);
  foreach (['api/console_composer_intent.php', 'api/composer_tools.php', 'js/coreui-composer-menu.js'] as $need) {
    intent_test(str_contains($pre, $need), $f . ' lists ' . $need);
  }
}

echo "CoreAI composer intent: marks only at the start, strip/take, labels, readable archive form, Mara's instruction (and 'not available'), "
  . "marker with the person's words, endpoint answer for accounts and guests, archive and summary (SQLite), wiring OK\n";

foreach ([1, 2, 4] as $count) {
  foreach (['', ':gross'] as $size) {
    $parsed = coreui_composer_intent_peek('[[bild' . $size . ':' . $count . ']] fox');
    if (($parsed['count'] ?? null) !== $count || coreui_composer_intent_strip('[[bild' . $size . ':' . $count . ']] fox') !== 'fox') throw new RuntimeException('Image count round trip');
  }
}
if (coreui_composer_intent_peek('[[bild:8]] fox') !== null) throw new RuntimeException('Invalid count accepted');
