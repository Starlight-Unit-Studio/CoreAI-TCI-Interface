<?php
declare(strict_types=1);
// CoreAI 0.7.74 (P09 C): conversation branches.
//   php scripts/chat-branch-selftest.php
// Offline: the branch title, which messages a branch takes (variants, continuations, earlier
// questions), when the compaction summary may come along, the store against SQLite (copy,
// attachments, project, origin, the original unchanged, other accounts, busy chats, Mara's
// replies only, the line after the branch point) and the wiring in the API and the chat.
// With COREUI_SELFTEST_DB=1 also against MariaDB (migration 046, the summary with its archive,
// deleting the original keeps the branch and its files). Test data uses example.test only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_branch_store.php';
function cbt_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Chat branch FAIL: {$why}\n"); exit(1); }
}

// --- Title (pure) -----------------------------------------------------------------------------
cbt_test(coreui_branch_title('Spiel bauen') === 'Zweig: Spiel bauen', 'title');
cbt_test(coreui_branch_title('Zweig: Spiel bauen') === 'Zweig: Spiel bauen'
  && coreui_branch_title('zweig: Zweig:  Spiel') === 'Zweig: Spiel', 'a branch of a branch keeps one prefix');
cbt_test(coreui_branch_title('   ') === 'Zweig: Chat' && mb_strlen(coreui_branch_title(str_repeat('ü', 90))) === 64, 'empty and long titles');

// --- Which messages (pure) --------------------------------------------------------------------
$rows = [
  ['id'=>1, 'reply_to_id'=>null, 'mara'=>false, 'message'=>'Bau mir ein Spiel'],
  ['id'=>2, 'reply_to_id'=>1, 'mara'=>true, 'message'=>'Hier ist es.'],
  ['id'=>3, 'reply_to_id'=>null, 'mara'=>false, 'message'=>'Mach es blau'],
  ['id'=>4, 'reply_to_id'=>3, 'mara'=>true, 'message'=>'Blau, Teil 1'],
  ['id'=>5, 'reply_to_id'=>3, 'mara'=>true, 'message'=>'Andere Variante'],
  ['id'=>6, 'reply_to_id'=>3, 'mara'=>true, 'message'=>'Teil 2'],
  ['id'=>7, 'reply_to_id'=>null, 'mara'=>false, 'message'=>'Und rot?'],
  ['id'=>8, 'reply_to_id'=>7, 'mara'=>true, 'message'=>'Rot.'],
];
$ids = static fn(array $picked): array => array_map(static fn(array $r): int => (int)$r['id'], $picked);
$at4 = coreui_branch_pick($rows, 4, [6=>4]);
cbt_test($ids($at4) === [1, 2, 3, 4], 'branch at a reply: everything before it, not its other variant: ' . json_encode($ids($at4)));
cbt_test($at4[3]['message'] === "Blau, Teil 1\n\nTeil 2" && $at4[3]['parts'] === [4, 6], 'a continuation is merged into its reply');
cbt_test($ids(coreui_branch_pick($rows, 6, [6=>4])) === [1, 2, 3, 4], 'a click on the continuation branches at the whole reply');
$at5 = coreui_branch_pick($rows, 5, [6=>4]);
cbt_test($ids($at5) === [1, 2, 3, 5] && $at5[3]['message'] === 'Andere Variante', 'branch at the other variant: ' . json_encode($ids($at5)));
$at8 = coreui_branch_pick($rows, 8, [6=>4]);
cbt_test($ids($at8) === [1, 2, 3, 4, 5, 7, 8] && $at8[3]['message'] === "Blau, Teil 1\n\nTeil 2",
  'earlier questions keep what Mara saw (both variants, the continuation merged): ' . json_encode($ids($at8)));
cbt_test(coreui_branch_pick($rows, 3) === [] && coreui_branch_pick($rows, 99) === [], 'only at one of Mara\'s replies');
cbt_test($ids(coreui_branch_pick($rows, 4, [4=>6, 6=>4])) !== [], 'a continuation loop does not hang');
cbt_test($ids(coreui_branch_pick($rows, 4, [6=>404])) === [1, 2, 3, 4] && coreui_branch_pick($rows, 4, [6=>404])[3]['message'] === 'Blau, Teil 1',
  'a continuation of a missing reply is not merged anywhere');

// --- Compaction summary (pure) ----------------------------------------------------------------
$map = [1=>101, 2=>102, 3=>103, 4=>104];
$kept = coreui_branch_compact_state(['upto_id'=>2, 'archived_upto'=>2, 'summary'=>'Spiel gebaut.', 'folded'=>2], 4, $map);
cbt_test(is_array($kept) && $kept['upto_id'] === 102 && $kept['archived_upto'] === 102 && $kept['folded'] === 2 && $kept['summary'] === 'Spiel gebaut.',
  'a summary that ends before the branch point comes along with the branch ids');
cbt_test(coreui_branch_compact_state(['upto_id'=>6, 'archived_upto'=>6, 'summary'=>'x'], 4, $map) === null, 'a summary of later messages stays behind');
cbt_test(coreui_branch_compact_state(['upto_id'=>2, 'archived_upto'=>6, 'summary'=>'x'], 4, $map) === null, 'an archive of later messages stays behind');
cbt_test(coreui_branch_compact_state(['upto_id'=>2, 'summary'=>' '], 4, $map) === null && coreui_branch_compact_state(null, 4, $map) === null
  && coreui_branch_compact_state(['upto_id'=>0, 'summary'=>'x'], 4, $map) === null, 'no summary, nothing to take');
cbt_test(coreui_branch_compact_state(['upto_id'=>3, 'archived_upto'=>0, 'summary'=>'x'], 4, [2=>102, 4=>104])['upto_id'] === 102,
  'a folded message that is not in the branch maps to the one before it');

// --- Wiring (static) --------------------------------------------------------------------------
$actions = (string)file_get_contents($root . '/api/console_actions.php');
cbt_test(str_contains($actions, "if (\$action === 'branch') {") && str_contains($actions, "require_once __DIR__ . '/console_branch_store.php';")
  && strpos($actions, "if (\$action === 'branch') {") > strpos($actions, 'stu_require_csrf();'), 'the branch action sits behind the CSRF check');
cbt_test(str_contains($actions, "'branch_migration_required'], true) ? 503") || str_contains($actions, "'branch_migration_required'], 503"), 'missing migration answers 503');
$messages = (string)file_get_contents($root . '/api/console_messages.php');
cbt_test(str_contains($messages, "'branch' => \$branchInfo,") && str_contains($messages, 'coreui_branch_info($pdo, (int)$uid, $sessionId)'), 'the history names the branch origin');
$js = (string)file_get_contents($root . '/js/console-app.js');
foreach (["createActionButton('branch', 'In neuem Chat abzweigen')", "action: 'branch'", 'function placeBranchLine(ses)',
  "'Ursprungschat öffnen'", 'state.branchBySession[String(ses.id)] = d.branch'] as $needle) {
  cbt_test(str_contains($js, $needle), 'chat wiring: ' . $needle);
}
$migration = (string)file_get_contents($root . '/database/migrations/046_chat_branches.sql');
cbt_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS branch_source_id') && str_contains($migration, "VALUES ('046_chat_branches')")
  && !str_contains($migration, 'FOREIGN KEY'), 'migration 046 without a foreign key (the original may be deleted)');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Gespraechszweige: Titel, Auswahl, Zusammenfassung und Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- The store against SQLite -----------------------------------------------------------------
function cbt_sqlite(): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, branch_source_id TEXT, branch_source_message_id INTEGER,
    branch_point_id INTEGER, title TEXT, since_id INTEGER, last_message_id INTEGER, last_read_message_id INTEGER, created_at TEXT, updated_at TEXT,
    archived_at TEXT, pinned_at TEXT, locked_at TEXT)');
  $pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT, alliance_id INTEGER, session_id TEXT, user_id INTEGER,
    character_id TEXT, character_name TEXT, message TEXT, thinking_content TEXT, image_url TEXT, file_uuid TEXT, reply_to_id INTEGER, created_at TEXT, deleted_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_message_attachments (message_id INTEGER, media_uuid TEXT, user_id INTEGER, position INTEGER, created_at TEXT,
    PRIMARY KEY (message_id, media_uuid), UNIQUE (message_id, position))');
  $pdo->exec('CREATE TABLE stu_console_generation_requests (id TEXT, user_id INTEGER, session_id TEXT, trigger_message_id INTEGER, response_floor_id INTEGER, mode TEXT, status TEXT,
    source_response_id INTEGER, response_message_id INTEGER, created_at TEXT, expires_at TEXT)');
  $pdo->exec('CREATE TABLE stu_kv (user_id INTEGER, k TEXT, value TEXT, updated_at TEXT, PRIMARY KEY (user_id, k))');
  return $pdo;
}
/** A chat like the pure test above; returns [session id, message ids by name]. */
function cbt_chat(PDO $pdo, int $uid, string $projectId, string $sessionId): array {
  $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, project_id, title, since_id, created_at, updated_at) VALUES (?, ?, ?, ?, 0, NOW(), NOW())')
    ->execute([$sessionId, $uid, $projectId !== '' ? $projectId : null, 'Spiel bauen']);
  $ins = $pdo->prepare("INSERT INTO stu_chat_messages (channel, alliance_id, session_id, user_id, character_id, character_name, message, thinking_content,
      reply_to_id, created_at, deleted_at) VALUES ('console', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $id = [];
  $add = static function (string $name, bool $mara, string $text, ?string $reply, ?string $deleted = null) use ($pdo, $ins, $uid, $sessionId, &$id): void {
    $ins->execute([$sessionId, $uid, $mara ? 'ember' : 'gast', $mara ? 'Mara' : 'Pilot', $text, $mara ? 'gedacht' : null,
      $reply !== null ? $id[$reply] : null, '2026-11-16 10:00:0' . count($id), $deleted]);
    $id[$name] = (int)$pdo->lastInsertId();
  };
  $add('q1', false, 'Bau mir ein Spiel', null);
  $add('a1', true, 'Hier ist es.', 'q1');
  $add('old', false, 'Alte Frage', null, '2026-11-16 10:30:00');
  $add('q2', false, 'Mach es blau', null);
  $add('a2', true, 'Blau, Teil 1', 'q2');
  $add('a2b', true, 'Andere Variante', 'q2');
  $add('a2c', true, 'Teil 2', 'q2');
  $add('q3', false, 'Und rot?', null);
  $add('a3', true, 'Rot.', 'q3');
  cbt_generation($pdo, $uid, $sessionId, $id['q2'], 'continue', 'done', $id['a2'], $id['a2c'], '2026-01-01 00:00:00');
  return $id;
}
function cbt_generation(PDO $pdo, int $uid, string $sessionId, int $trigger, string $mode, string $status, int $source, ?int $response, string $expires): string {
  $gid = bin2hex(random_bytes(16));
  $pdo->prepare('INSERT INTO stu_console_generation_requests (id, user_id, session_id, trigger_message_id, response_floor_id, mode, status,
      source_response_id, response_message_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
    ->execute([$gid, $uid, $sessionId, $trigger, $source, $mode, $status, $source, $response, $expires]);
  return $gid;
}
function cbt_snapshot(PDO $pdo, string $sessionId): string {
  $st = $pdo->prepare('SELECT id, message, reply_to_id, deleted_at FROM stu_chat_messages WHERE session_id=? ORDER BY id');
  $st->execute([$sessionId]);
  $att = $pdo->prepare('SELECT a.message_id, a.media_uuid, a.position FROM stu_console_message_attachments a
    JOIN stu_chat_messages m ON m.id=a.message_id WHERE m.session_id=? ORDER BY a.message_id, a.position');
  $att->execute([$sessionId]);
  $ses = $pdo->prepare('SELECT title, project_id, last_message_id, branch_source_id FROM stu_console_sessions WHERE id=?');
  $ses->execute([$sessionId]);
  return json_encode([$st->fetchAll(PDO::FETCH_ASSOC), $att->fetchAll(PDO::FETCH_ASSOC), $ses->fetch(PDO::FETCH_ASSOC)]);
}
function cbt_branch_rows(PDO $pdo, string $sessionId): array {
  $st = $pdo->prepare('SELECT id, character_id, message, thinking_content, reply_to_id, created_at FROM stu_chat_messages WHERE session_id=? AND deleted_at IS NULL ORDER BY id');
  $st->execute([$sessionId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cbt_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

$pdo = cbt_sqlite();
$project = str_repeat('a', 32);
$pdo->prepare("INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?, 7, 'Spiel', 'open', '', NULL, NOW())")->execute([$project]);
$id = cbt_chat($pdo, 7, $project, 'cs_source_chat_7');
$attIns = $pdo->prepare('INSERT INTO stu_console_message_attachments VALUES (?, ?, 7, ?, NOW())');
$attIns->execute([$id['q1'], 'media-photo', 0]);
$attIns->execute([$id['a2'], 'media-image-1', 0]);
$attIns->execute([$id['a2c'], 'media-image-2', 0]);
$attIns->execute([$id['a2c'], 'media-image-1', 1]);
$pdo->prepare('INSERT INTO stu_kv VALUES (7, ?, ?, NOW())')
  ->execute(['cmp_cs_source_chat_7', json_encode(['upto_id'=>$id['q3'], 'archived_upto'=>$id['q3'], 'summary'=>'Spaeter erst.', 'folded'=>6])]);
$before = cbt_snapshot($pdo, 'cs_source_chat_7');

$r = coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['a2']);
$b = (string)$r['session']['id'];
$copy = cbt_branch_rows($pdo, $b);
cbt_test($r['copied'] === 4 && count($copy) === 4 && $r['session']['title'] === 'Zweig: Spiel bauen' && $r['session']['project_id'] === $project,
  'the branch: four messages, title, project: ' . json_encode($r));
cbt_test(array_column($copy, 'message') === ['Bau mir ein Spiel', 'Hier ist es.', 'Mach es blau', "Blau, Teil 1\n\nTeil 2"],
  'the branch keeps the visible messages up to the reply, the continuation merged, no deleted or later ones: ' . json_encode(array_column($copy, 'message')));
cbt_test((int)$copy[1]['reply_to_id'] === (int)$copy[0]['id'] && (int)$copy[3]['reply_to_id'] === (int)$copy[2]['id'] && $copy[0]['reply_to_id'] === null,
  'replies point at the branch\'s own questions');
cbt_test($copy[3]['created_at'] === '2026-11-16 10:00:04' && $copy[3]['thinking_content'] === 'gedacht' && $copy[3]['character_id'] === 'ember',
  'time, thoughts and author stay');
cbt_test($r['point_id'] === (int)$copy[3]['id'] && $r['attachments'] === 3, 'branch point and attachments: ' . json_encode($r));
$att = $pdo->prepare('SELECT message_id, media_uuid, position FROM stu_console_message_attachments WHERE message_id IN (?, ?) ORDER BY message_id, position');
$att->execute([(int)$copy[0]['id'], (int)$copy[3]['id']]);
cbt_test($att->fetchAll(PDO::FETCH_NUM) === [[(int)$copy[0]['id'], 'media-photo', 0], [(int)$copy[3]['id'], 'media-image-1', 0], [(int)$copy[3]['id'], 'media-image-2', 1]],
  'attachments follow their messages, a merged continuation brings its own (no doubles)');
cbt_test(cbt_snapshot($pdo, 'cs_source_chat_7') === $before, 'the original chat is not changed at all');
$ses = $pdo->query("SELECT * FROM stu_console_sessions WHERE id='" . $b . "'")->fetch(PDO::FETCH_ASSOC);
cbt_test($ses['branch_source_id'] === 'cs_source_chat_7' && (int)$ses['branch_source_message_id'] === $id['a2'] && (int)$ses['branch_point_id'] === $r['point_id']
  && (int)$ses['last_message_id'] === $r['point_id'] && (int)$ses['last_read_message_id'] === $r['point_id'], 'the branch knows its origin and has nothing unread');
cbt_test(!$r['summary_kept'] && $pdo->query("SELECT COUNT(*) FROM stu_kv WHERE k='cmp_" . $b . "'")->fetchColumn() == 0,
  'a summary of later messages does not come along');

$info = coreui_branch_info($pdo, 7, $b);
cbt_test($info === ['point_id'=>$r['point_id'], 'source_state'=>'active', 'source_id'=>'cs_source_chat_7', 'source_title'=>'Spiel bauen'], 'origin line: ' . json_encode($info));
cbt_test(coreui_branch_info($pdo, 7, 'cs_source_chat_7') === null, 'a chat that is no branch has no line');
$pdo->exec("UPDATE stu_console_sessions SET archived_at=NOW() WHERE id='cs_source_chat_7'");
$info = coreui_branch_info($pdo, 7, $b);
cbt_test($info['source_state'] === 'archived' && $info['source_id'] === null && $info['source_title'] === 'Spiel bauen', 'an archived origin');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['a2']), 'session_not_found'), 'no branch from an archived chat');
$pdo->exec("UPDATE stu_console_sessions SET archived_at=NULL, locked_at=NOW() WHERE id='cs_source_chat_7'");
$info = coreui_branch_info($pdo, 7, $b);
cbt_test($info['source_state'] === 'gone' && $info['source_title'] === null && $info['source_id'] === null, 'a locked origin stays anonymous');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['a2']), 'session_not_found'), 'no branch from a locked chat');
$pdo->exec("UPDATE stu_console_sessions SET locked_at=NULL WHERE id='cs_source_chat_7'");

cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 9, 'cs_source_chat_7', $id['a2']), 'session_not_found'), 'another account cannot branch this chat');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['q2']), 'branch_needs_reply'), 'only at Mara\'s replies');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['old']), 'message_not_found'), 'not at a deleted message');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, $b, $id['a2']), 'message_not_found'), 'a message of another chat');
$busy = cbt_generation($pdo, 7, 'cs_source_chat_7', $id['q3'], 'regenerate', 'running', $id['a3'], null, '2099-01-01 00:00:00');
cbt_test(cbt_throws(static fn() => coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['a1']), 'session_busy'), 'not while Mara is still writing');
$pdo->prepare('DELETE FROM stu_console_generation_requests WHERE id=?')->execute([$busy]);

$r2 = coreui_branch_create($pdo, 7, $b, $r['point_id']);
cbt_test($r2['session']['title'] === 'Zweig: Spiel bauen' && $r2['copied'] === 4, 'a branch of a branch');
$pdo->exec("DELETE FROM stu_console_projects");
$r3 = coreui_branch_create($pdo, 7, 'cs_source_chat_7', $id['a1']);
cbt_test($r3['session']['project_id'] === null && $r3['copied'] === 2, 'a project that is gone is not carried over');
cbt_test(cbt_snapshot($pdo, 'cs_source_chat_7') !== '' && count(cbt_branch_rows($pdo, 'cs_source_chat_7')) === 8, 'the original still has its eight visible messages');

$pdo2 = cbt_sqlite();
$pdo2->exec('ALTER TABLE stu_console_sessions RENAME COLUMN branch_point_id TO other_column');
cbt_test(!coreui_branch_ready($pdo2) && cbt_throws(static fn() => coreui_branch_create($pdo2, 7, 'cs_source_chat_7', 1), 'branch_migration_required')
  && coreui_branch_info($pdo2, 7, 'cs_source_chat_7') === null, 'without migration 046 nothing breaks');

// --- MariaDB (optional, never the live database) ----------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/046_chat_branches.sql')))) as $sql) $db->exec($sql);
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('cbt-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  $archives = [];
  try {
    $source = 'cs_cbt_' . bin2hex(random_bytes(6));
    $mid = cbt_chat($db, $uid, '', $source);
    $media = 'cbt' . bin2hex(random_bytes(8));
    $db->prepare("INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, mime_type, file_size, rel_path, public_url, created_at)
        VALUES (?, ?, 'image', 'foto.png', ?, 'image/png', 10, ?, NULL, NOW())")
      ->execute([$media, $uid, 'selftest-' . $media . '.png', 'var/console_media/selftest-' . $media . '.png']);
    $db->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position, created_at) VALUES (?, ?, ?, 0, NOW())')
      ->execute([$mid['q1'], $media, $uid]);
    $archive = coreui_compact_archive_path($uid, $source);
    $archives[] = $archive;
    @mkdir(dirname($archive), 0770, true);
    file_put_contents($archive, "# Chat-Archiv\n\nBau mir ein Spiel\n");
    coreui_compact_save($db, $uid, $source, ['upto_id'=>$mid['a1'], 'archived_upto'=>$mid['a1'], 'summary'=>'Ein Spiel wurde gebaut.', 'folded'=>2]);

    $res = coreui_branch_create($db, $uid, $source, $mid['a2']);
    $branch = (string)$res['session']['id'];
    $archives[] = coreui_compact_archive_path($uid, $branch);
    $copy = cbt_branch_rows($db, $branch);
    $state = coreui_compact_load($db, $uid, $branch);
    cbt_test($res['copied'] === 4 && $res['summary_kept'] && is_array($state) && (int)$state['upto_id'] === (int)$copy[1]['id']
      && $state['summary'] === 'Ein Spiel wurde gebaut.' && is_file(coreui_compact_archive_path($uid, $branch)),
      'MariaDB: the summary with its archive comes along: ' . json_encode([$res, $state]));
    cbt_test(coreui_branch_info($db, $uid, $branch)['source_state'] === 'active', 'MariaDB: origin');

    // Deleting the original keeps the branch, its messages and the shared photo.
    $db->prepare('UPDATE stu_console_sessions SET archived_at=NOW() WHERE id=? AND user_id=?')->execute([$source, $uid]);
    coreui_console_session_delete($db, $uid, $source);
    $left = $db->prepare('SELECT COUNT(*) FROM stu_console_media WHERE uuid=?');
    $left->execute([$media]);
    cbt_test(count(cbt_branch_rows($db, $branch)) === 4 && (int)$left->fetchColumn() === 1
      && coreui_branch_info($db, $uid, $branch)['source_state'] === 'gone' && is_file(coreui_compact_archive_path($uid, $branch)),
      'MariaDB: deleting the original keeps the branch and the photo it uses');
    $mariadb = ', MariaDB (Migration 046, Zusammenfassung mit Archiv, Ursprung loeschen)';
  } finally {
    $db->prepare('DELETE FROM stu_console_sessions WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_console_media WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_kv WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
    foreach ($archives as $file) if (is_file($file)) @unlink($file);
    @rmdir(dirname(coreui_compact_archive_path($uid, 'cs_none_000')));
  }
}

echo "CoreAI Gespraechszweige: Titel, Auswahl (Varianten, Fortsetzungen), Zusammenfassung, Kopie mit Anhaengen, Ursprung unveraendert, fremde Konten, laufende Antwort, Herkunftszeile, Verdrahtung OK (SQLite{$mariadb})\n";
