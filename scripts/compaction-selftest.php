<?php
declare(strict_types=1);
// CoreAI 0.5.77: Chat komprimieren. Offline with an in-memory SQLite: older
// messages outside Mara's window are folded into a running summary, the
// archive keeps them word for word (written before the model call, never
// twice), a failed summary loses nothing, Mara's prompt carries the summary,
// the archive search finds details, and deleting the chat removes both.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';

function compact_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Compaction FAIL: {$why}\n"); exit(1); }
}

// MySQL statements of the store, translated for SQLite.
final class CompactTestPdo extends PDO {
  public function prepare(string $query, array $options = []): PDOStatement|false {
    $query = str_replace(
      ['ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()', 'NOW()'],
      ['ON CONFLICT(user_id, k) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'],
      $query);
    return parent::prepare($query, $options);
  }
}
$pdo = new CompactTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_kv (user_id INTEGER, k TEXT, value TEXT, updated_at TEXT, PRIMARY KEY (user_id, k))');
$pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, channel TEXT, user_id INTEGER, session_id TEXT,
  alliance_id INTEGER NULL, deleted_at TEXT NULL, character_id TEXT, character_name TEXT, message TEXT, created_at TEXT)');
$uid = 990077;
$session = 'cmptest' . getmypid();
$add = static function (int $n) use ($pdo, $uid, $session): void {
  static $count = 0;
  $st = $pdo->prepare("INSERT INTO stu_chat_messages (channel, user_id, session_id, character_id, character_name, message, created_at)
    VALUES ('console', ?, ?, ?, ?, ?, '2026-10-28 12:00:00')");
  for ($i = 0; $i < $n; $i++) {
    $count++;
    $mara = $count % 2 === 0;
    $st->execute([$uid, $session, $mara ? 'ember' : 'op_1', $mara ? 'Mara' : 'Akashi',
      $count === 3 ? 'Das Projekt heisst Nebelfalke und nutzt Port 8443.' : 'Nachricht ' . $count]);
  }
};
$archive = coreui_compact_archive_path($uid, $session);
compact_test(str_ends_with($archive, '/var/console_media/archives/' . $uid . '/' . $session . '.md'), 'archive next to the chat media');
compact_test(!str_contains(coreui_compact_archive_path(1, '../../etc/passwd'), '..'), 'session id cannot leave the archive folder');

// Pure parts.
$rows = array_map(static fn(int $i): array => ['id'=>$i, 'mara'=>$i % 2 === 0, 'name'=>'Akashi', 'message'=>'m' . $i, 'created_at'=>''], range(1, 20));
compact_test(count(coreui_compact_slice($rows, 12, 4, 40, false)) === 8, 'everything outside the window of 12');
compact_test(coreui_compact_slice(array_slice($rows, 0, 15), 12, 4, 40, false) === [], 'automatically only from 4 messages on');
compact_test(count(coreui_compact_slice(array_slice($rows, 0, 15), 12, 4, 40, true)) === 3, 'the button folds what is outside');
compact_test(coreui_compact_slice(array_slice($rows, 0, 10), 12, 4, 40, true) === [], 'nothing outside, nothing to fold');
compact_test(count(coreui_compact_slice(array_fill(0, 100, $rows[0]), 12, 4, 40, false)) === 40, 'at most 40 per run');
$md = coreui_compact_markdown([$rows[0], $rows[1]]);
compact_test(str_contains($md, "### Akashi (#1)\n\nm1") && str_contains($md, "### Mara (#2)\n\nm2"), 'archive word for word with names');
$prompt = coreui_compact_prompt('- alt', [['id'=>1, 'mara'=>false, 'name'=>'A', 'message'=>str_repeat('x', 5000), 'created_at'=>'']], 1000);
compact_test(str_contains($prompt, "BISHERIGE ZUSAMMENFASSUNG:\n- alt") && str_contains($prompt, '[…]') && mb_strlen($prompt) < 3000,
  'summary prompt keeps the old summary and shortens long messages');
compact_test(coreui_compact_context_block(null) === '' && str_contains(coreui_compact_context_block(['summary'=>'- Ziel', 'folded'=>18, 'upto_id'=>1]),
  'BISHERIGER VERLAUF (zusammengefasst, 18 aeltere Nachrichten'), 'summary block for the prompt');
compact_test(coreui_compact_clean_summary('ok') === null && coreui_compact_clean_summary('[WEB: x] - Ziel: ein Spiel bauen, Port 8443') === '- Ziel: ein Spiel bauen, Port 8443',
  'empty summaries are refused, markers removed');
compact_test(coreui_compact_budget(4096) === 6000 && coreui_compact_budget(131072) === 16000 && coreui_compact_budget(null) === 16000, 'budget follows the window');
compact_test(coreui_extract_archive_marker('Moment. [ARCHIV: Port Nebelfalke]') === 'Port Nebelfalke' && coreui_extract_archive_marker('`[ARCHIV: x]` erklaert') === null,
  'archive marker, mentions in code are not calls');

// A real run against the store.
@unlink($archive);
$add(30);
$calls = [];
$ok = static function (string $p) use (&$calls): ?string { $calls[] = $p; return "- Projekt Nebelfalke, Port 8443\n- Nutzer baut ein Spiel"; };
$r = coreui_compact_run($pdo, $uid, $session, $ok, false);
compact_test($r['status'] === 'done' && $r['folded'] === 18 && $r['total'] === 18 && count($calls) === 1, 'first run folds 18 of 30');
$state = coreui_compact_load($pdo, $uid, $session);
compact_test($state['upto_id'] === 18 && $state['archived_upto'] === 18 && str_contains($state['summary'], 'Nebelfalke'), 'state saved');
$text = (string)file_get_contents($archive);
compact_test(str_starts_with($text, '# Chat-Archiv') && substr_count($text, '### ') === 18 && str_contains($text, 'Port 8443'), 'archive has the 18 messages');
compact_test(coreui_compact_run($pdo, $uid, $session, $ok, false)['status'] === 'not_needed', 'nothing new outside the window');

// A failed summary keeps the archive and retries without writing twice.
$add(5);
$fail = static fn(string $p): ?string => null;
$r = coreui_compact_run($pdo, $uid, $session, $fail, false);
compact_test($r['status'] === 'failed' && $r['reason'] === 'summary_empty', 'failed summary is reported');
compact_test(substr_count((string)file_get_contents($archive), '### ') === 23 && coreui_compact_load($pdo, $uid, $session)['upto_id'] === 18,
  'archive written first, summary state unchanged');
$r = coreui_compact_run($pdo, $uid, $session, $ok, false);
compact_test($r['status'] === 'done' && $r['folded'] === 5 && $r['total'] === 23 && substr_count((string)file_get_contents($archive), '### ') === 23,
  'retry folds the same messages, archive not doubled');
compact_test(str_contains(end($calls), "BISHERIGE ZUSAMMENFASSUNG:\n- Projekt Nebelfalke"), 'the running summary is carried on');

// Archive search for Mara.
$hits = coreui_compact_archive_search((string)file_get_contents($archive), 'Welcher Port beim Nebelfalke?');
compact_test(count($hits) >= 1 && str_contains($hits[0], 'Port 8443'), 'archive search finds the detail');
compact_test(str_contains(coreui_compact_archive_block($uid, $session, 'Nebelfalke'), 'Fundstellen im Archiv')
  && str_contains(coreui_compact_archive_block($uid, $session, 'Zebrastreifen'), 'steht nichts'), 'archive block for the next step');

// Deleting the chat removes summary and archive.
coreui_compact_forget($pdo, $uid, $session);
compact_test(coreui_compact_load($pdo, $uid, $session) === null && !is_file($archive), 'delete removes both');
@rmdir(dirname($archive));

// Wiring.
$chat = (string)file_get_contents($root . '/api/chat.php');
compact_test(str_contains($chat, "\$summary = coreui_compact_context_block(coreui_compact_load(\$pdo, \$consoleUid, (string)\$sessionId));"),
  'Mara\'s prompt carries the summary');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
compact_test(strpos($stream, "sse_send('done', [") < strpos($stream, 'coreui_compact_with_core($pdo, (int)$uid, $sessionId, false);'),
  'automatic compaction after the answer is out');
$actions = (string)file_get_contents($root . '/api/console_actions.php');
compact_test(str_contains($actions, "if (\$action === 'compact') {") && str_contains($actions, "coreui_compact_with_core(\$pdo, (int)\$uid, \$sessionId, true)")
  && str_contains($actions, "header('Content-Type: text/markdown; charset=utf-8');"), 'button and archive download');
compact_test(str_contains((string)file_get_contents($root . '/api/console_session_store.php'), 'coreui_compact_forget($pdo, $uid'), 'deleting a chat forgets it');
$app = (string)file_get_contents($root . '/js/console-app.js');
compact_test(str_contains($app, "/^\\s*\\/(?:komprimieren|compact)\\s*$/i") && str_contains($app, "action: 'compact', session_id: sessionId"),
  'chat command and button');
compact_test(str_contains((string)file_get_contents($root . '/app.html'), 'id="compactMenu"'), 'menu in the chat');

echo "CoreAI compaction: window, running summary, archive first, retry, search, delete OK\n";
