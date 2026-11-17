<?php
declare(strict_types=1);
// CoreAI 0.7.82 (P11 C): the lifetime of the files Mara made.
//   php scripts/artifact-lifecycle-selftest.php
// Offline: the retention choices, the day a file goes, the short version, the wiring (checksum header
// on the very bytes streamed, sweep before cards and lists, notes, Admin Core, the chat cards).
// The store against SQLite with real files: cards with version, kept and project; the retention
// deletes only old files that are neither kept nor in a project chat, never another account's, and
// leaves notes; deleting by the person leaves a note and takes the favourite; Settings > Speicher
// deletes the same way and shows the day; without the favourites or the notes table nothing is
// deleted by the retention. With COREUI_SELFTEST_DB=1 against MariaDB (migration 051, applied twice;
// the notes go with their chat). Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/coreai-artifacts-' . bin2hex(random_bytes(4));
mkdir($work . '/artifacts', 0700, true);
define('STU_EMBER_PY_WORKDIR', $work);
$GLOBALS['ALS_SETTINGS'] = [];
// The app settings of the test (the real ones live in stu_app_settings).
function stu_app_setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
  return $GLOBALS['ALS_SETTINGS'][$key] ?? $default;
}
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/files_store.php';
function als_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Artifact lifecycle FAIL: {$why}\n"); exit(1); }
}
function als_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}
register_shutdown_function(static function () use ($work): void { exec('rm -rf ' . escapeshellarg($work)); });

// --- Pure ------------------------------------------------------------------------------------------
als_test(coreui_artifact_retention_normalise('30') === 30 && coreui_artifact_retention_normalise(365) === 365
  && coreui_artifact_retention_normalise('45') === 0 && coreui_artifact_retention_normalise('-30') === 0
  && coreui_artifact_retention_normalise('abc') === 0 && coreui_artifact_retention_normalise(null) === 0, 'retention choices');
als_test(coreui_artifact_expires_at('2026-01-01 10:00:00', 30, false, false) === '2026-01-31 10:00:00'
  && coreui_artifact_expires_at('2026-01-01 10:00:00', 30, true, false) === null
  && coreui_artifact_expires_at('2026-01-01 10:00:00', 30, false, true) === null
  && coreui_artifact_expires_at('2026-01-01 10:00:00', 0, false, false) === null, 'the day a file goes: kept, project and no retention stay');
als_test(coreui_artifact_version(str_repeat('ab', 32)) === 'abababababab' && coreui_artifact_version('x') === '', 'short version');

// --- Wiring (static) ---------------------------------------------------------------------------------
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$download = $read('api/console_artifact.php');
als_test(strpos($download, "header('X-CoreAI-SHA256: ' . (string)\$row['sha256']);") > strpos($download, '$fh = coreui_console_artifact_open_verified($row);')
  && str_contains($download, "header('ETag: \"' . (string)\$row['sha256'] . '\"');"), 'download: the checksum of the verified bytes');
$list = $read('api/console_artifacts.php');
als_test(strpos($list, 'coreui_artifact_sweep($pdo, $uid);') < strpos($list, '$map = coreui_artifact_cards($pdo, $uid, $ids);')
  && str_contains($list, "'csrf_token'=>\$manage ? stu_csrf_token() : ''") && str_contains($list, '$manage = !coreui_guest_is($pdo, $uid);'),
  'chat cards: retention first, managing only for accounts, never guests');
$files = $read('api/files.php');
als_test(strpos($files, 'coreui_artifact_sweep($pdo, $uid);') < strpos($files, "if (\$method === 'GET' && \$action === 'summary') {"),
  'Settings > Speicher: retention before anything is shown');
als_test(str_contains($read('api/files_store.php'), "coreui_artifact_delete(\$pdo, \$uid, (string)\$row['id'], 'person');"), 'Speicher deletes with a note');
$admin = $read('api/admin.php');
als_test(str_contains($admin, "'artifact_retention_days' => '0',") && str_contains($admin, "'storage_limit_mb', 'artifact_retention_days',")
  && str_contains($admin, "if (\$key === 'artifact_retention_days') \$value = (string)coreui_artifact_retention_normalise(\$value);")
  && str_contains($read('js/admin.js'), "artifact_retention_days: document.getElementById('artifactRetentionDays').value,")
  && str_contains($read('admin/index.html'), '<option value="0">Unbegrenzt</option>'), 'Admin Core > Betrieb: retention, never by default');
$cards = $read('js/coreui-artifacts.js');
als_test(str_contains($cards, "response.headers.get('X-CoreAI-SHA256') !== file.sha256")
  && str_contains($cards, "var IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];")
  && str_contains($cards, "'image/svg+xml'") && str_contains($cards, 'download.addEventListener(\'click\', function () { saveBlob(blob, file.name); });'),
  'chat cards: same version for preview and download, SVG never as a picture');
$migration = $read('database/migrations/051_artifact_lifecycle.sql');
als_test(substr_count($migration, 'ON DELETE CASCADE') === 3 && str_contains($migration, "reason ENUM('person','retention') NOT NULL")
  && str_contains($migration, 'ADD INDEX IF NOT EXISTS idx_console_artifact_age (user_id, created_at)')
  && str_contains($migration, "VALUES ('051_artifact_lifecycle')"), 'migration 051');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Lebensdauer von Maras Dateien: Fristen, Version, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite with real files --------------------------------------------------------------
function als_sqlite(bool $favorites = true, bool $notes = true): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-11-17 12:00:00', 0);
  $pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER, storage_mb INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT)');
  $pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY, user_id INTEGER, session_id TEXT, channel TEXT, deleted_at TEXT, reply_to_id INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_message_revisions (message_id INTEGER, revision_no INTEGER)');
  $pdo->exec('CREATE TABLE stu_ember_py_jobs (id INTEGER PRIMARY KEY, channel TEXT, recipient_uid INTEGER, session_id TEXT, trigger_message_id INTEGER,
    trigger_revision INTEGER, status TEXT, artifacts_json TEXT)');
  $pdo->exec('CREATE TABLE stu_console_artifacts (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, session_id TEXT NOT NULL, message_id INTEGER NOT NULL,
    job_id INTEGER NOT NULL, producer TEXT, storage_key TEXT NOT NULL, orig_name TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL,
    sha256 TEXT NOT NULL, partial INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
  if ($favorites) $pdo->exec('CREATE TABLE stu_user_file_favorites (user_id INTEGER, item_key TEXT, UNIQUE (user_id, item_key))');
  if ($notes) {
    $pdo->exec('CREATE TABLE stu_console_artifact_tombstones (artifact_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, session_id TEXT NOT NULL,
      message_id INTEGER NOT NULL, orig_name TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, sha256 TEXT NOT NULL,
      reason TEXT NOT NULL, created_at TEXT NOT NULL, deleted_at TEXT NOT NULL)');
  }
  $pdo->exec("INSERT INTO stu_users VALUES (7, 3, NULL), (9, 3, NULL)");
  $pdo->exec("INSERT INTO stu_console_projects VALUES ('p1', 7, 'Spiel')");
  $pdo->exec("INSERT INTO stu_console_sessions VALUES ('cs_a', 7, NULL, 'Auswertung'), ('cs_p', 7, 'p1', 'Spielchat'), ('cs_o', 9, NULL, 'Fremd')");
  $pdo->exec("INSERT INTO stu_chat_messages VALUES (1, 7, 'cs_a', 'console', NULL, NULL), (2, 7, 'cs_a', 'console', NULL, 1),
    (3, 7, 'cs_p', 'console', NULL, NULL), (4, 7, 'cs_p', 'console', NULL, 3), (5, 9, 'cs_o', 'console', NULL, NULL), (6, 9, 'cs_o', 'console', NULL, 5)");
  $pdo->exec("INSERT INTO stu_ember_py_jobs VALUES (11, 'console', 7, 'cs_a', 1, 0, 'done', NULL), (12, 'console', 7, 'cs_p', 3, 0, 'done', NULL),
    (13, 'console', 9, 'cs_o', 5, 0, 'done', NULL)");
  return $pdo;
}
$make = static function (PDO $pdo, int $uid, string $session, int $message, int $job, string $name, string $mime, string $content, string $created) use ($work): string {
  $key = $job . '/' . bin2hex(random_bytes(16));
  @mkdir($work . '/artifacts/' . $job, 0700, true);
  file_put_contents($work . '/artifacts/' . $key, $content);
  $id = bin2hex(random_bytes(16));
  $pdo->prepare("INSERT INTO stu_console_artifacts VALUES (?, ?, ?, ?, ?, 'python', ?, ?, ?, ?, ?, 0, ?)")
    ->execute([$id, $uid, $session, $message, $job, $key, $name, $mime, strlen($content), hash('sha256', $content), $created]);
  return $id;
};
$pdo = als_sqlite();
$old = $make($pdo, 7, 'cs_a', 2, 11, 'bericht.csv', 'text/csv', "a;b\n1;2\n", '2026-01-01 10:00:00');
$kept = $make($pdo, 7, 'cs_a', 2, 11, 'bild.png', 'image/png', 'PNGDATA', '2026-01-02 10:00:00');
$fresh = $make($pdo, 7, 'cs_a', 2, 11, 'neu.txt', 'text/plain', "neu\n", '2026-11-16 10:00:00');
$project = $make($pdo, 7, 'cs_p', 4, 12, 'level.txt', 'text/plain', "level\n", '2026-01-01 09:00:00');
$foreign = $make($pdo, 9, 'cs_o', 6, 13, 'fremd.txt', 'text/plain', "fremd\n", '2026-01-01 08:00:00');
$path = static fn(string $id): string => $work . '/artifacts/' . (string)$pdo->query("SELECT storage_key FROM stu_console_artifacts WHERE id='{$id}'")->fetchColumn();
$oldPath = $path($old);

$cards = coreui_artifact_cards($pdo, 7, [2, 4]);
$byId = static function (array $cards): array {
  $out = [];
  foreach ($cards as $files) foreach ($files as $file) $out[$file['id']] = $file;
  return $out;
};
$c = $byId($cards);
als_test(count($c) === 4 && $c[$old]['version'] === substr(hash('sha256', "a;b\n1;2\n"), 0, 12) && $c[$old]['available'] === true
  && $c[$old]['kept'] === false && $c[$old]['expires_at'] === null && $c[$project]['project'] === 'Spiel' && !isset($c[$foreign]),
  'cards: version, kept, project, no retention by default, only the account\'s own: ' . json_encode($c));
als_test(coreui_artifact_sweep($pdo, 7) === 0 && file_exists($oldPath), 'without a retention nothing goes');

// Retention 30 days: old, not kept, not in a project goes.
$GLOBALS['ALS_SETTINGS']['artifact_retention_days'] = '30';
$pdo->prepare('INSERT INTO stu_user_file_favorites VALUES (7, ?)')->execute(['a:' . $kept]);
$c = $byId(coreui_artifact_cards($pdo, 7, [2, 4]));
als_test($c[$old]['expires_at'] === '2026-01-31 10:00:00' && $c[$kept]['kept'] === true && $c[$kept]['expires_at'] === null
  && $c[$project]['expires_at'] === null && $c[$fresh]['expires_at'] === '2026-12-16 10:00:00', 'cards show the day a file goes');
als_test(coreui_artifact_sweep($pdo, 7) === 1, 'the retention deletes exactly the one old, unkept, non-project file');
als_test(!file_exists($oldPath) && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_artifacts WHERE id='{$old}'")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_artifacts WHERE id IN ('{$kept}','{$fresh}','{$project}','{$foreign}')")->fetchColumn() === 4,
  'its entry and file are gone; kept, recent, project and another account\'s file stay');
$cards = coreui_artifact_cards($pdo, 7, [2]);
$note = array_values(array_filter($cards[2], static fn(array $f): bool => !empty($f['deleted'])));
als_test(count($note) === 1 && $note[0]['id'] === $old && $note[0]['name'] === 'bericht.csv' && $note[0]['deleted_reason'] === 'retention'
  && $note[0]['deleted_at'] === '2026-11-17 12:00:00' && $note[0]['available'] === false && $note[0]['version'] === $c[$old]['version'],
  'the chat message keeps a note of the deleted file: ' . json_encode($note));
als_test(coreui_artifact_sweep($pdo, 7) === 0, 'a second sweep finds nothing');

// Deleting by the person: a note, the favourite goes, never another account's file.
$freed = coreui_artifact_delete($pdo, 7, $kept);
als_test($freed === ['freed'=>7, 'name'=>'bild.png'] && (int)$pdo->query("SELECT COUNT(*) FROM stu_user_file_favorites WHERE item_key='a:{$kept}'")->fetchColumn() === 0
  && $pdo->query("SELECT reason FROM stu_console_artifact_tombstones WHERE artifact_id='{$kept}'")->fetchColumn() === 'person', 'deleting by the person');
als_test(als_throws(static fn() => coreui_artifact_delete($pdo, 7, $foreign), 'artifact_not_found')
  && als_throws(static fn() => coreui_artifact_delete($pdo, 7, $kept), 'artifact_not_found')
  && als_throws(static fn() => coreui_artifact_delete($pdo, 7, '../x'), 'artifact_not_found')
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_artifacts WHERE id='{$foreign}'")->fetchColumn() === 1, 'never another account\'s file, never twice');

// Settings > Speicher: the day in the list, deleting with a note.
$items = coreui_files_list($pdo, 7, ['view'=>'files', 'tab'=>'all', 'type'=>'mara']);
$byKey = array_column($items['items'], null, 'id');
als_test($byKey['a:' . $fresh]['expires_at'] === '2026-12-16 10:00:00' && $byKey['a:' . $project]['expires_at'] === null
  && $byKey['a:' . $fresh]['version'] === substr(hash('sha256', "neu\n"), 0, 12), 'Speicher shows version and the day: ' . json_encode($items['items']));
als_test(coreui_files_delete($pdo, 7, 'a:' . $fresh) === 4
  && $pdo->query("SELECT reason FROM stu_console_artifact_tombstones WHERE artifact_id='{$fresh}'")->fetchColumn() === 'person', 'Speicher deletes with a note');

// Without the favourites table the retention deletes nothing (nobody could have kept a file); without the notes table neither.
foreach ([[false, true, 'favourites'], [true, false, 'notes']] as [$favorites, $notes, $what]) {
  $other = als_sqlite($favorites, $notes);
  $id = $make($other, 7, 'cs_a', 2, 11, 'alt.txt', 'text/plain', "alt\n", '2026-01-01 10:00:00');
  als_test(coreui_artifact_sweep($other, 7) === 0 && (int)$other->query("SELECT COUNT(*) FROM stu_console_artifacts WHERE id='{$id}'")->fetchColumn() === 1,
    "without the {$what} table the retention deletes nothing");
}
$plain = als_sqlite(true, false);
$id = $make($plain, 7, 'cs_a', 2, 11, 'alt.txt', 'text/plain', "alt\n", '2026-01-01 10:00:00');
als_test(coreui_artifact_delete($plain, 7, $id)['freed'] === 4 && coreui_artifact_tombstones($plain, 7, [2]) === [], 'without migration 051 deleting still works');
$GLOBALS['ALS_SETTINGS']['artifact_retention_days'] = '45';
als_test(coreui_artifact_retention_days($pdo) === 0, 'an unknown retention counts as never');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['051_artifact_lifecycle.sql', '051_artifact_lifecycle.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('als-" . bin2hex(random_bytes(3)) . "@example.test', 0, 3, NOW())");
  $uid = (int)$db->lastInsertId();
  $session = 'cs_als_' . bin2hex(random_bytes(6));
  try {
    $db->prepare('INSERT INTO stu_console_sessions (id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')->execute([$session, $uid, 'Lebensdauer']);
    $msg = $db->prepare("INSERT INTO stu_chat_messages (user_id, character_id, character_name, message, session_id, channel, reply_to_id, created_at)
      VALUES (?, 'coreui', 'Mara', ?, ?, 'console', ?, NOW())");
    $msg->execute([$uid, 'Bitte eine Tabelle', $session, null]);
    $trigger = (int)$db->lastInsertId();
    $msg->execute([$uid, 'Hier ist sie', $session, $trigger]);
    $answer = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO stu_ember_py_jobs (status, code, channel, recipient_uid, session_id, trigger_message_id, trigger_revision)
      VALUES ('done', 'print(1)', 'console', ?, ?, ?, 0)")->execute([$uid, $session, $trigger]);
    $job = (int)$db->lastInsertId();
    $id = $make($db, $uid, $session, $answer, $job, 'tabelle.csv', 'text/csv', "x;y\n", '2026-01-01 10:00:00');
    $kept = $make($db, $uid, $session, $answer, $job, 'behalten.csv', 'text/csv', "k\n", '2026-01-01 10:00:00');
    $db->prepare('INSERT INTO stu_user_file_favorites (user_id, item_key) VALUES (?, ?)')->execute([$uid, 'a:' . $kept]);
    $c = $byId(coreui_artifact_cards($db, $uid, [$answer]));
    als_test(isset($c[$id], $c[$kept]) && $c[$id]['available'] === true, 'MariaDB: cards of a real chat');
    $GLOBALS['ALS_SETTINGS']['artifact_retention_days'] = '30';
    als_test(coreui_artifact_sweep($db, $uid) === 1, 'MariaDB: the retention (CONCAT for the kept key)');
    $note = $db->prepare('SELECT reason FROM stu_console_artifact_tombstones WHERE artifact_id=?');
    $note->execute([$id]);
    als_test($note->fetchColumn() === 'retention', 'MariaDB: the note (migration 051)');
    $db->prepare('DELETE FROM stu_console_sessions WHERE id=? AND user_id=?')->execute([$session, $uid]);
    $left = $db->prepare('SELECT COUNT(*) FROM stu_console_artifact_tombstones WHERE user_id=?');
    $left->execute([$uid]);
    als_test((int)$left->fetchColumn() === 0, 'MariaDB: the notes go with their chat');
    $mariadb = ', MariaDB (Migration 051)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
  }
}

echo "CoreAI Lebensdauer von Maras Dateien: Fristen, Version, Verdrahtung, Karten mit Version und Frist, Aufbewahrung nur fuer alte, "
  . "nicht behaltene Dateien ausserhalb von Projekten, Hinweise im Chat, Loeschen mit Hinweis, Speicher, fehlende Tabellen OK (SQLite{$mariadb})\n";
