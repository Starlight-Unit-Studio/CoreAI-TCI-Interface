<?php
declare(strict_types=1);
// CoreAI 0.6.87: the chat menu and Settings > Archivierte Chats, offline.
//   php scripts/chat-menu-selftest.php
// Pure parts: LIKE patterns with %, _ and ! taken literally, snippets around a hit (umlauts),
// file names for "Teilen". With COREUI_SELFTEST_DB=1 on MariaDB (migration 032): pinned chats on
// top, archiving releases the pin, the archive searched by title and filtered by files or images,
// "Hochgeladene Dateien" without Mara's images, "Im Chat suchen" over every message, "Teilen" as
// Markdown, other accounts never seen. Then the wiring of the API, the app, the page, the
// settings and the preflights.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_chat_tools.php';

function c_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Chat menu FAIL: {$why}\n"); exit(1); }
}
function c_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// --- Pure ------------------------------------------------------------------------------------------
c_test(coreui_chat_like('100% ja_nein!') === '%100!% ja!_nein!!%', 'LIKE pattern escapes % _ !');
$sq = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sq->exec("CREATE TABLE t (v TEXT)");
foreach (['Rezept 100% vegan', 'Rezept 1000 vegan', 'a_b', 'axb', 'wow!'] as $v) $sq->prepare('INSERT INTO t VALUES (?)')->execute([$v]);
$like = static function (string $q) use ($sq): array {
  $st = $sq->prepare("SELECT v FROM t WHERE v LIKE ? ESCAPE '!' ORDER BY v");
  $st->execute([coreui_chat_like($q)]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
};
c_test($like('100%') === ['Rezept 100% vegan'] && $like('_') === ['a_b'] && $like('!') === ['wow!'], 'LIKE literal on SQLite');
c_test(coreui_chat_snippet('Wie wird das Wetter morgen in Köln?', 'köln') === 'Wie wird das Wetter morgen in Köln?', 'short text stays whole');
$long = str_repeat('Vorher ', 40) . 'der Treffer Köln steht hier ' . str_repeat('danach ', 40);
$snip = coreui_chat_snippet($long, 'KÖLN', 60);
c_test(str_starts_with($snip, '…') && str_ends_with($snip, '…') && mb_stripos($snip, 'Köln') !== false && mb_strlen($snip) <= 62, 'snippet around the hit');
c_test(coreui_chat_file_name('Wetter in Köln: Süß & Groß?') === 'wetter-in-koeln-suess-gross.md' && coreui_chat_file_name('!!!') === 'chat.md'
  && strlen(coreui_chat_file_name(str_repeat('a', 200))) === 63, 'file names');

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) ---------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  c_test(coreui_console_session_pin_ready($my), 'MariaDB: migration 032 applied');
  $tag = bin2hex(random_bytes(4));
  $ins = $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)");
  $ins->execute(["chat-a-$tag@example.test"]);
  $ua = (int)$my->lastInsertId();
  $ins->execute(["chat-b-$tag@example.test"]);
  $ub = (int)$my->lastInsertId();
  $media = [];
  try {
    $a = coreui_console_session_create($my, $ua, 'Wetter in Köln');
    $b = coreui_console_session_create($my, $ua, 'Rezept 100% vegan');
    $c = coreui_console_session_create($my, $ua, 'Bilder');
    $other = coreui_console_session_create($my, $ub, 'Fremder Chat in Köln');
    $msg = $my->prepare("INSERT INTO stu_chat_messages (channel, session_id, user_id, character_id, character_name, message, file_uuid) VALUES ('console', ?, ?, ?, ?, ?, ?)");
    $assistant = coreui_chat_assistant_id();
    $msg->execute([$a['id'], $ua, 'op_' . $tag, 'Lena', 'Wie wird das Wetter morgen in Köln?', null]);
    $msg->execute([$a['id'], $ua, $assistant, 'Mara', 'Morgen wird es in Köln sonnig.', null]);
    $msg->execute([$other['id'], $ub, 'op_b' . $tag, 'Ben', 'Köln geheim', null]);
    $med = $my->prepare('INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, rel_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $media = ["doc-$tag", "img-$tag"];
    $med->execute(["doc-$tag", $ua, 'document', 'plan.pdf', 'x', "var/console_media/$tag/plan.pdf", 'application/pdf', 2048]);
    $med->execute(["img-$tag", $ua, 'image', 'mara.png', 'x', COREUI_CHAT_GENERATED_PREFIX . "$tag.png", 'image/png', 4096]);
    $msg->execute([$b['id'], $ua, 'op_' . $tag, 'Lena', 'Hier ist mein Plan', "doc-$tag"]);
    $msg->execute([$c['id'], $ua, $assistant, 'Mara', 'Hier ist dein Bild', null]);
    $my->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position) VALUES (?, ?, ?, 0)')
      ->execute([(int)$my->lastInsertId(), "img-$tag", $ua]);

    c_test(coreui_chat_pin($my, $ua, $a['id'], true) === true, 'MariaDB: pin');
    $list = coreui_console_session_list($my, $ua);
    c_test($list[0]['id'] === $a['id'] && $list[0]['pinned'] === true && $list[1]['pinned'] === false && !array_key_exists('pinned_at', $list[0]), 'MariaDB: pinned on top');
    c_test(c_code(fn() => coreui_chat_pin($my, $ub, $a['id'], true)) === 'session_not_found', 'MariaDB: no pin in a foreign chat');
    c_test(array_column(coreui_chat_files($my, $ua, $b['id']), 'name') === ['plan.pdf'] && coreui_chat_files($my, $ua, $c['id']) === [], 'MariaDB: uploads, not Mara\'s images');
    c_test(c_code(fn() => coreui_chat_files($my, $ub, $b['id'])) === 'session_not_found', 'MariaDB: no files of a foreign chat');
    $hits = coreui_chat_search($my, $ua, $a['id'], 'KÖLN');
    c_test(count($hits) === 2 && $hits[0]['mara'] === false && $hits[1]['mara'] === true && $hits[0]['id'] < $hits[1]['id'], 'MariaDB: search over the chat');
    c_test(coreui_chat_search($my, $ua, $a['id'], 'k') === [] && coreui_chat_search($my, $ua, $a['id'], '100%') === [], 'MariaDB: short or missing');
    $exp = coreui_chat_export($my, $ua, $a['id']);
    c_test($exp['filename'] === 'wetter-in-koeln.md' && $exp['messages'] === 2 && str_starts_with($exp['markdown'], "# Wetter in Köln\n")
      && str_contains($exp['markdown'], 'Wie wird das Wetter morgen in Köln?') && str_contains($exp['markdown'], 'Morgen wird es in Köln sonnig.'), 'MariaDB: Markdown');
    foreach ([$a, $b, $c] as $s) {
      coreui_console_session_archive($my, $ua, $s['id']);
      coreui_chat_unpin_archived($my, $ua, $s['id']);
    }
    $pins = $my->prepare('SELECT COUNT(*) FROM stu_console_sessions WHERE user_id = ? AND pinned_at IS NOT NULL');
    $pins->execute([$ua]);
    c_test((int)$pins->fetchColumn() === 0, 'MariaDB: archiving releases the pin');
    $arch = coreui_chat_archived($my, $ua);
    c_test(count($arch) === 3 && !in_array($other['id'], array_column($arch, 'id'), true), 'MariaDB: own archive only');
    $byTitle = array_column($arch, null, 'title');
    c_test($byTitle['Rezept 100% vegan']['has_files'] && !$byTitle['Rezept 100% vegan']['has_images'] && $byTitle['Bilder']['has_images']
      && !$byTitle['Bilder']['has_files'] && $byTitle['Wetter in Köln']['message_count'] === 2 && $byTitle['Bilder']['project'] === null, 'MariaDB: flags and counts');
    c_test(array_column(coreui_chat_archived($my, $ua, '100%'), 'title') === ['Rezept 100% vegan'] && coreui_chat_archived($my, $ua, '_') === []
      && array_column(coreui_chat_archived($my, $ua, 'köln'), 'title') === ['Wetter in Köln'], 'MariaDB: search by title');
    c_test(array_column(coreui_chat_archived($my, $ua, '', 'files'), 'title') === ['Rezept 100% vegan']
      && array_column(coreui_chat_archived($my, $ua, '', 'images'), 'title') === ['Bilder'], 'MariaDB: filters');
    c_test(c_code(fn() => coreui_chat_pin($my, $ua, $a['id'], true)) === 'session_not_found', 'MariaDB: an archived chat cannot be pinned');
    $dbChecked = true;
  } finally {
    foreach ([$ua, $ub] as $u) {
      $my->prepare('DELETE FROM stu_console_message_attachments WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_chat_messages WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_console_media WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_console_sessions WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$u]);
    }
  }
}

// --- Wiring ---------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$mig = $read('database/migrations/032_chat_menu.sql');
c_test(str_contains($mig, 'ADD COLUMN IF NOT EXISTS pinned_at DATETIME NULL') && str_contains($mig, "('032_chat_menu')"), 'migration 032');
$api = $read('api/console_sessions.php');
c_test(str_contains($api, "require_once __DIR__ . '/console_chat_tools.php';") && str_contains($api, "if (\$action === 'archived') {")
  && str_contains($api, "if (in_array(\$action, ['files', 'search', 'export'], true) && \$method === 'GET') {")
  && str_contains($api, "if (\$action === 'pin') {") && str_contains($api, "coreui_chat_unpin_archived(\$pdo, (int)\$uid, \$sessionId);"), 'API actions');
c_test(str_contains($api, "if (!empty(\$body['archive_first'])) {") && str_contains($api, "if (\$archivedNow) coreui_console_session_restore(\$pdo, (int)\$uid, \$sessionId);")
  && str_contains($api, "hash_equals('DELETE:' . \$sessionId"), 'delete in one step, restored when it fails, confirmation kept');
$store = $read('api/console_session_store.php');
c_test(str_contains($store, "(s.pinned_at IS NULL), s.pinned_at DESC, ") && str_contains($store, "throw new RuntimeException('session_must_be_archived')"), 'list order; delete still only archived');
$appJs = $read('js/console-app.js');
c_test(str_contains($appJs, 'window.CoreAIChatApp = {') && str_contains($appJs, 'reveal: revealMessage')
  && str_contains($appJs, "if (window.CoreAIChatMenu && typeof window.CoreAIChatMenu.sync === 'function') window.CoreAIChatMenu.sync();")
  && str_contains($appJs, "if (s.pinned && !s.archived) metaBits.unshift('ANGEPINNT');"), 'app hooks and pinned marker');
c_test(str_contains($appJs, 'var linked = takeLinkedSession();') && str_contains($appJs, 'if (target && !target.archived) return selectSession(target.id);'), 'a chat link opens its chat');
$app = $read('app.html');
foreach (['chatMenuBtn', 'chatMenu', 'chatArchiveDialog', 'chatDeleteDialog', 'chatFilesDialog', 'chatHomeDialog', 'chatSearch'] as $id) c_test(str_contains($app, 'id="' . $id . '"'), "app.html: $id");
c_test((bool)preg_match('~<script src="\./js/coreui-chat-menu\.js\?v=\d{25}"></script>~', $app), 'chat menu script with version');
c_test(str_contains($app, 'Möchtest du diesen Chat wirklich archivieren?<br />Du kannst archivierte Chats in den Einstellungen anzeigen.'), 'archive text of the reference');
$settings = $read('settings.html');
c_test(str_contains($settings, 'id="view-archivierte-chats"') && !str_contains($settings, 'class="cfg-view cfg-view-planned" id="view-archivierte-chats"')
  && str_contains($settings, 'placeholder="Archivierte Chats durchsuchen"') && str_contains($settings, '<option value="all">Alle Chats</option>')
  && str_contains($settings, '<option value="all">Alle Projekte</option>') && str_contains($settings, '>Keine archivierten Chats<'), 'settings: Archivierte Chats');
c_test((bool)preg_match('~<script src="\./js/coreui-archived-chats\.js\?v=\d{25}"></script>~', $settings), 'archive script with version');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $pf) {
  $text = $read($pf);
  c_test(str_contains($text, 'database/migrations/032_chat_menu.sql') && str_contains($text, 'api/console_chat_tools.php') && str_contains($text, 'js/coreui-chat-menu.js')
    && str_contains($text, 'js/coreui-archived-chats.js') && str_contains($text, 'scripts/chat-menu-selftest.php') && str_contains($text, 'scripts/chat-menu-ui-selftest.cjs')
    && str_contains($text, 'scripts/archived-chats-ui-selftest.cjs'), "$pf lists and runs the chat menu");
}

echo "CoreAI chat menu: LIKE escaping, snippets, file names, wiring OK"
  . ($dbChecked ? ', MariaDB (pin order, archive, filters, files, search, Markdown, other accounts) OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
