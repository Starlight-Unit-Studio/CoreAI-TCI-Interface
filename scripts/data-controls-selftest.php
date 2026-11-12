<?php
declare(strict_types=1);
// CoreAI 0.7.32: Settings > Datenkontrollen on the PHP side. Checked: the wiring (actions in
// api/console_sessions.php with CSRF, the page without the rows the operator crossed out, the export
// kept) and, with a database: the counts, "Alle Chats archivieren" (needs its confirmation, removes pins,
// never a locked chat or another account's), "Alle Chats löschen" (needs its confirmation, deletes active
// and archived chats with messages and files through the same delete as the chat menu, skips a chat
// Mara is still working in and puts an active one back, never a locked chat or another account's).
// "Alle löschen" runs its own transactions, so this test uses its own accounts and removes them at the end.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);

$cleanup = null;
function dc_test(bool $ok, string $why): void {
  if ($ok) return;
  if (is_callable($GLOBALS['cleanup'] ?? null)) ($GLOBALS['cleanup'])();
  fwrite(STDERR, "Data controls FAIL: {$why}\n");
  exit(1);
}

// --- Wiring -------------------------------------------------------------------------------------
$api = $read('api/console_sessions.php');
dc_test(str_contains($api, "|| in_array(\$action, ['archive_all', 'delete_all'], true)) stu_require_csrf();"), 'both actions need the CSRF token');
dc_test(str_contains($api, "stu_json(['ok'=>true, 'archived_now'=>\$archived] + coreui_data_controls_summary(\$pdo, (int)\$uid));"),
  'the number archived now does not hide the archive count');
dc_test(strpos($api, "\$action === 'data_summary'") < strpos($api, '$sessionId = coreui_console_session_normalize_id(')
  && strpos($api, "\$action === 'delete_all'") < strpos($api, '$sessionId = coreui_console_session_normalize_id('), 'the actions need no chat id');
$store = $read('api/data_controls_store.php');
dc_test(str_contains($store, "const COREUI_DATA_CONTROLS_ARCHIVE_CONFIRM = 'ARCHIVE_ALL_CHATS';")
  && str_contains($store, "const COREUI_DATA_CONTROLS_DELETE_CONFIRM = 'DELETE_ALL_CHATS';")
  && str_contains($store, '$result = coreui_console_session_delete($pdo, $uid, $id);'), 'confirmations and the delete of the chat menu');
$settings = $read('settings.html');
$at = strpos($settings, 'id="view-datenkontrollen"');
$page = substr($settings, $at, strpos($settings, '<!-- CATEGORY FOUNDATION: teams-start') - $at);
foreach (['Geteilte Links', 'Persönliche Daten zusammenführen', 'Daten exportieren', 'Archivierte Chats', 'Alle Chats archivieren', 'Alle Chats löschen',
  'id="exportAccountData"', 'data-goto="archivierte-chats"', 'id="dcArchiveAll"', 'id="dcDeleteAll"', 'id="dcDeleteWord"'] as $part) {
  dc_test(str_contains($page, $part), 'the page has ' . $part);
}
foreach (['Modell für alle verbessern', 'Netzwerkzugriff', 'Work zurücksetzen', 'OpenAI'] as $gone) dc_test(!str_contains($page, $gone), 'crossed out: ' . $gone);
dc_test(substr_count($page, 'In Vorbereitung') === 2, 'two rows are honestly In Vorbereitung');
dc_test(str_contains($settings, 'js/coreui-data-controls.js'), 'the page script is loaded');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI data controls: wiring, page and confirmations OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
require_once $root . '/api/data_controls_store.php';
$pdo = stu_pdo();
$lockReady = coreui_console_chat_lock_ready($pdo);
$pinReady = coreui_console_session_pin_ready($pdo);

$uid = random_int(1500000000, 1900000000);
$other = $uid + 1;
$files = [];
$cleanup = static function () use ($pdo, $uid, $other, &$files): void {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $ids = implode(',', [$uid, $other]);
  foreach (["DELETE FROM stu_ember_browse_jobs WHERE recipient_uid IN ($ids)", "DELETE FROM stu_console_message_attachments WHERE user_id IN ($ids)",
    "DELETE FROM stu_chat_messages WHERE user_id IN ($ids)", "DELETE FROM stu_console_media WHERE user_id IN ($ids)",
    "DELETE FROM stu_console_sessions WHERE user_id IN ($ids)", "DELETE FROM stu_users WHERE id IN ($ids)"] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
  foreach ($files as $f) @unlink($f);
};
$GLOBALS['cleanup'] = $cleanup;

try {
  $ins = $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 3, NOW())');
  $ins->execute([$uid, 'dc-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  $ins->execute([$other, 'dc-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  $session = static function (int $user, string $title, array $flags = []) use ($pdo, $lockReady, $pinReady): string {
    $id = 'cs_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, title) VALUES (?, ?, ?)')->execute([$id, $user, $title]);
    if (!empty($flags['archived'])) $pdo->prepare('UPDATE stu_console_sessions SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    if (!empty($flags['locked']) && $lockReady) $pdo->prepare('UPDATE stu_console_sessions SET locked_at = NOW() WHERE id = ?')->execute([$id]);
    if (!empty($flags['pinned']) && $pinReady) $pdo->prepare('UPDATE stu_console_sessions SET pinned_at = NOW() WHERE id = ?')->execute([$id]);
    if (!empty($flags['busy'])) {
      $pdo->prepare("INSERT INTO stu_ember_browse_jobs (status, goal, channel, recipient_uid, session_id) VALUES ('queued', 'Test', 'console', ?, ?)")
        ->execute([$user, $id]);
    }
    return $id;
  };
  $message = static function (int $user, string $sid) use ($pdo): int {
    $pdo->prepare("INSERT INTO stu_chat_messages (channel, session_id, user_id, character_id, character_name, message) VALUES ('console', ?, ?, 'user', 'Lena', 'Hallo')")
      ->execute([$sid, $user]);
    return (int)$pdo->lastInsertId();
  };

  $a1 = $session($uid, 'Eins', ['pinned' => true]);
  $a2 = $session($uid, 'Zwei');
  $a3 = $session($uid, 'Drei');
  $busy1 = $session($uid, 'Läuft', ['busy' => true]);
  $arch = $session($uid, 'Alt', ['archived' => true]);
  $locked = $session($uid, 'Geheim', ['locked' => true]);
  $foreign = $session($other, 'Fremd');
  $m1 = $message($uid, $a1);
  $message($uid, $locked);
  $message($other, $foreign);
  // A file attached in "Eins" goes with the chat.
  $uuid = bin2hex(random_bytes(16));
  $rel = 'var/console_media/' . $uuid . '.txt';
  @mkdir($root . '/var/console_media', 0770, true);
  file_put_contents($root . '/' . $rel, 'Datei');
  $files[] = $root . '/' . $rel;
  $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, rel_path, mime_type, file_size) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([$uuid, $uid, 'document', 'notiz.txt', $uuid . '.txt', $rel, 'text/plain', 5]);
  $pdo->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position) VALUES (?, ?, ?, 0)')->execute([$m1, $uuid, $uid]);

  $s = coreui_data_controls_summary($pdo, $uid);
  dc_test($s === ['active' => 4, 'archived' => 1, 'locked' => $lockReady ? 1 : 0] || (!$lockReady && $s['active'] === 5), 'the counts (locked chats apart)');

  // Alle Chats archivieren.
  try { coreui_data_controls_archive_all($pdo, $uid, 'ja'); $ok = false; } catch (InvalidArgumentException $e) { $ok = $e->getMessage() === 'archive_all_confirmation_required'; }
  dc_test($ok, 'archiving all needs its confirmation');
  $n = coreui_data_controls_archive_all($pdo, $uid, COREUI_DATA_CONTROLS_ARCHIVE_CONFIRM);
  dc_test($n === ($lockReady ? 4 : 5), 'every active chat archived');
  dc_test(coreui_data_controls_summary($pdo, $uid)['active'] === 0, 'no active chat left');
  if ($pinReady) {
    $pin = $pdo->prepare('SELECT pinned_at FROM stu_console_sessions WHERE id = ?');
    $pin->execute([$a1]);
    dc_test($pin->fetchColumn() === null, 'an archived chat is no longer pinned');
  }
  if ($lockReady) {
    $st = $pdo->prepare('SELECT archived_at FROM stu_console_sessions WHERE id = ?');
    $st->execute([$locked]);
    dc_test($st->fetchColumn() === null, 'a locked chat is not archived');
  }
  $st = $pdo->prepare('SELECT archived_at FROM stu_console_sessions WHERE id = ?');
  $st->execute([$foreign]);
  dc_test($st->fetchColumn() === null, 'another account is untouched');

  // Two more active chats, one of them busy: delete all.
  $a4 = $session($uid, 'Vier');
  $busy2 = $session($uid, 'Läuft auch', ['busy' => true]);
  try { coreui_data_controls_delete_all($pdo, $uid, 'ARCHIVE_ALL_CHATS'); $ok = false; } catch (InvalidArgumentException $e) { $ok = $e->getMessage() === 'delete_all_confirmation_required'; }
  dc_test($ok, 'deleting all needs its own confirmation');
  $r = coreui_data_controls_delete_all($pdo, $uid, COREUI_DATA_CONTROLS_DELETE_CONFIRM);
  dc_test($r['deleted'] === ($lockReady ? 5 : 6) && $r['busy'] === 2 && $r['failed'] === 0 && $r['locked'] === ($lockReady ? 1 : 0), 'deleted, busy and locked counted');
  dc_test($r['files'] === 1 && !is_file($root . '/' . $rel), 'the attached file is gone');
  $exists = static function (string $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT archived_at FROM stu_console_sessions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
  };
  foreach ([$a1, $a2, $a3, $arch, $a4] as $id) dc_test($exists($id) === null, 'deleted: ' . $id);
  dc_test($exists($busy1) !== null && $exists($busy1)['archived_at'] !== null, 'a busy chat that was archived stays archived');
  dc_test($exists($busy2) !== null && $exists($busy2)['archived_at'] === null, 'a busy active chat is put back');
  if ($lockReady) dc_test($exists($locked) !== null, 'a locked chat is never deleted');
  dc_test($exists($foreign) !== null, 'another account keeps its chat');
  $count = $pdo->prepare('SELECT COUNT(*) FROM stu_chat_messages WHERE session_id = ?');
  $count->execute([$a1]);
  dc_test((int)$count->fetchColumn() === 0, 'the messages are gone');
  $after = coreui_data_controls_summary($pdo, $uid);
  dc_test($after['active'] === 1 && $after['archived'] === 1, 'the two busy chats remain');
} finally {
  $cleanup();
}
$st = $pdo->prepare('SELECT COUNT(*) FROM stu_users WHERE id IN (?, ?)');
$st->execute([$uid, $other]);
dc_test((int)$st->fetchColumn() === 0, 'the test accounts are removed again');
echo "CoreAI data controls: counts, archiving all, deleting all (messages, files, busy and locked chats, other accounts) and wiring on the database OK\n";
