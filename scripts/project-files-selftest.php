<?php
declare(strict_types=1);
// CoreAI 0.7.69 (P09): project files.
//   php scripts/project-files-selftest.php
// Offline against SQLite: paths and text, create, edit, conflicts, revisions and their limit,
// rename, delete, restore, search, ZIP out, ZIP in (top folder, leftovers, too big, binary),
// a build in a project chat (artifacts on disk with SHA-256), chats outside a project, other
// accounts and projects, project and storage limits, storage accounting, project deletion.
// With a test database (COREUI_SELFTEST_DB=1, never the live one): migration 044 against
// MariaDB, case-sensitive paths, emoji, FOR UPDATE, and the cascade when a project goes.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/coreai-project-files-' . bin2hex(random_bytes(4));
mkdir($tmp . '/ember_py/artifacts', 0700, true);
define('STU_EMBER_PY_WORKDIR', $tmp . '/ember_py');

// Settings > Speicher limit for all accounts in MiB ('' = none); the test switches it.
$GLOBALS['pf_storage_limit'] = '';
function stu_app_setting_get(PDO $pdo, string $key, string $default = ''): string {
  return $key === 'storage_limit_mb' ? (string)$GLOBALS['pf_storage_limit'] : $default;
}

require_once $root . '/api/console_artifact_store.php';
require_once $root . '/api/project_files_store.php';

function pf_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project files FAIL: {$why}\n"); exit(1); }
}
function pf_throws(callable $fn, string $error, string $why): void {
  try { $fn(); } catch (Throwable $e) {
    pf_test($e->getMessage() === $error, $why . ': expected ' . $error . ', got ' . get_class($e) . ' ' . $e->getMessage());
    return;
  }
  pf_test(false, $why . ': expected ' . $error . ', nothing was thrown');
}

// --- Paths and text (pure) -------------------------------------------------------------------
foreach (['index.html'=>'index.html', './src//main.js'=>'src/main.js', '\\css\\style.css'=>'css/style.css',
    'docs/Über uns.md'=>'docs/Über uns.md', 'a/b/c/d/e/f/g/h.txt'=>'a/b/c/d/e/f/g/h.txt', '.gitignore'=>'.gitignore',
    'data/report (2).csv'=>'data/report (2).csv'] as $in => $out) {
  pf_test(coreui_pfiles_path($in) === $out, 'path ' . $in);
}
foreach (['', '../secret.txt', 'a/../b.txt', '/', 'a/./b.txt', 'a/ lead.txt', 'a/trail /x.md', 'a/b/c/d/e/f/g/h/i.txt', 'x/' . str_repeat('a', 81) . '.txt',
    "nul\0.txt", 'semi;colon.txt', 'back`tick.md'] as $bad) {
  pf_throws(static fn() => coreui_pfiles_path($bad), 'invalid_path', 'refused path ' . json_encode($bad));
}
foreach (['photo.png', 'archive.zip', 'program.exe', 'Makefile', 'noext'] as $bad) {
  pf_throws(static fn() => coreui_pfiles_path($bad), 'unsupported_type', 'refused type ' . $bad);
}
pf_test(coreui_pfiles_text("Grüße 🙂\n") === "Grüße 🙂\n", 'UTF-8 text is kept byte for byte');
pf_throws(static fn() => coreui_pfiles_text("a\0b"), 'not_text', 'NUL bytes are not text');
pf_throws(static fn() => coreui_pfiles_text("\xff\xfe"), 'not_text', 'invalid UTF-8 is not text');
pf_throws(static fn() => coreui_pfiles_text(str_repeat('a', COREUI_PFILES_FILE_BYTES + 1)), 'file_too_large', 'one byte over the file limit');
pf_test(coreui_pfiles_text(str_repeat('a', COREUI_PFILES_FILE_BYTES)) !== '', 'exactly the file limit is fine');
pf_test(coreui_pfiles_zip_name('Mein Spiel: Ära 2!') === 'Mein-Spiel-Aera-2.zip' && coreui_pfiles_zip_name('***') === 'projekt.zip',
  'ZIP name from the project name');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Projektdateien: paths and text OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

pf_test(class_exists('ZipArchive'), 'the PHP extension zip is missing (needed for the ZIP of the project files)');

// --- Store against SQLite --------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER)');
$pdo->exec('CREATE TABLE stu_console_media (user_id INTEGER, kind TEXT, file_size INTEGER)');
$pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT,
  instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
$pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT,
  archived_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_project_files (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, user_id INTEGER NOT NULL,
  path TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
  created_at TEXT, updated_at TEXT, UNIQUE (project_id, path))');
$pdo->exec('CREATE TABLE stu_console_project_file_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id TEXT NOT NULL,
  project_id TEXT NOT NULL, user_id INTEGER NOT NULL, revision INTEGER NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL,
  size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, source TEXT NOT NULL, session_id TEXT, message_id INTEGER, created_at TEXT,
  UNIQUE (file_id, revision))');
$pdo->exec('INSERT INTO stu_users VALUES (7, 4), (9, 4)');
$a = str_repeat('a', 32); $b = str_repeat('b', 32); $c = str_repeat('c', 32);
$st = $pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)');
$st->execute([$a, 7, 'Spiel A', 'isolated', '', null, '2026-11-15']);
$st->execute([$b, 7, 'Projekt B', 'open', '', null, '2026-11-15']);
$st->execute([$c, 9, 'Fremdes Konto', 'open', '', null, '2026-11-15']);
$st = $pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)');
$st->execute(['cs_in_project_a', 7, $a, 'Bau', null, '2026-11-15', '2026-11-15']);
$st->execute(['cs_no_project_1', 7, null, 'Frei', null, '2026-11-15', '2026-11-15']);
pf_test(coreui_pfiles_schema_ready($pdo), 'schema ready');

$listed = coreui_pfiles_list($pdo, 7, $a);
pf_test($listed['files'] === [] && $listed['count'] === 0 && $listed['limits']['files'] === 200, 'empty project');
$created = coreui_pfiles_write($pdo, 7, $a, 'index.html', "<h1>Hallo</h1>\n", 'create', ['create_only'=>true]);
pf_test($created['changed'] && $created['file']['revision'] === 1 && $created['file']['sha256'] === hash('sha256', "<h1>Hallo</h1>\n"),
  'create: revision 1 with its SHA-256');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $a, 'index.html', 'x', 'create', ['create_only'=>true]), 'file_exists', 'create twice');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $a, 'nope.md', 'x', 'edit'), 'file_not_found', 'edit a missing file');
$sha1 = $created['file']['sha256'];
$same = coreui_pfiles_write($pdo, 7, $a, 'index.html', "<h1>Hallo</h1>\n", 'edit', ['base_sha256'=>$sha1]);
pf_test(!$same['changed'] && $same['file']['revision'] === 1, 'the same content writes nothing');
$edit = coreui_pfiles_write($pdo, 7, $a, 'index.html', "<h1>Hallo Welt</h1>\n", 'edit', ['base_sha256'=>$sha1]);
pf_test($edit['changed'] && $edit['file']['revision'] === 2, 'edit: revision 2');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $a, 'index.html', 'stale', 'edit', ['base_sha256'=>$sha1]), 'file_changed',
  'an editor that started from an older state is a conflict, nothing is overwritten');
$file = coreui_pfiles_get($pdo, 7, $a, 'index.html');
pf_test($file['content'] === "<h1>Hallo Welt</h1>\n" && count($file['revisions']) === 2 && $file['revisions'][0]['revision'] === 2
  && $file['revisions'][0]['source'] === 'edit' && $file['revisions'][1]['source'] === 'create', 'file with revisions, newest first');
pf_test(coreui_pfiles_revision($pdo, 7, $a, 'index.html', 1)['content'] === "<h1>Hallo</h1>\n", 'an earlier state');
pf_throws(static fn() => coreui_pfiles_revision($pdo, 7, $a, 'index.html', 9), 'revision_not_found', 'unknown revision');

// Restore, rename, revisions limit.
$restored = coreui_pfiles_restore($pdo, 7, $a, 'index.html', 1);
pf_test($restored['changed'] && $restored['file']['revision'] === 3
  && coreui_pfiles_get($pdo, 7, $a, 'index.html')['content'] === "<h1>Hallo</h1>\n"
  && coreui_pfiles_get($pdo, 7, $a, 'index.html')['revisions'][0]['source'] === 'restore', 'restore adds the old state as the newest');
$renamed = coreui_pfiles_rename($pdo, 7, $a, 'index.html', 'public/index.html');
pf_test($renamed['path'] === 'public/index.html' && $renamed['revision'] === 4
  && coreui_pfiles_get($pdo, 7, $a, 'public/index.html')['revisions'][0]['source'] === 'rename'
  && count(coreui_pfiles_get($pdo, 7, $a, 'public/index.html')['revisions']) === 4, 'rename keeps the history');
pf_throws(static fn() => coreui_pfiles_get($pdo, 7, $a, 'index.html'), 'file_not_found', 'the old path is gone');
coreui_pfiles_write($pdo, 7, $a, 'notes.md', 'v0', 'create');
pf_throws(static fn() => coreui_pfiles_rename($pdo, 7, $a, 'notes.md', 'public/index.html'), 'file_exists', 'rename onto another file');
for ($i = 1; $i <= 25; $i++) coreui_pfiles_write($pdo, 7, $a, 'notes.md', 'v' . $i, 'edit');
$notes = coreui_pfiles_get($pdo, 7, $a, 'notes.md');
pf_test($notes['revision'] === 26 && count($notes['revisions']) === COREUI_PFILES_KEEP_REVISIONS
  && $notes['revisions'][0]['revision'] === 26 && end($notes['revisions'])['revision'] === 7, 'only the newest 20 revisions stay');

// Search.
coreui_pfiles_write($pdo, 7, $a, 'src/game.js', "const speed = 5;\n// 100% fertig_neu\nfunction jump() { return speed * 2; }\n", 'create');
$found = coreui_pfiles_search($pdo, 7, $a, 'SPEED');
pf_test(count($found) === 1 && $found[0]['path'] === 'src/game.js' && count($found[0]['lines']) === 2
  && $found[0]['lines'][0]['line'] === 1 && $found[0]['lines'][1]['line'] === 3, 'search in content, case-insensitive, with line numbers');
pf_test(count(coreui_pfiles_search($pdo, 7, $a, '100% fertig_neu')) === 1 && coreui_pfiles_search($pdo, 7, $a, '%_') === [],
  'percent and underscore are searched literally');
pf_test(coreui_pfiles_search($pdo, 7, $a, 'public')[0]['in_path'] ?? false, 'a match in the path');
pf_throws(static fn() => coreui_pfiles_search($pdo, 7, $a, 'x'), 'invalid_query', 'one character is no search');

// Accounts and projects stay apart.
pf_throws(static fn() => coreui_pfiles_list($pdo, 9, $a), 'project_not_found', 'another account sees nothing');
pf_throws(static fn() => coreui_pfiles_get($pdo, 9, $a, 'notes.md'), 'project_not_found', 'another account reads nothing');
pf_throws(static fn() => coreui_pfiles_write($pdo, 9, $a, 'x.md', 'x', 'create'), 'project_not_found', 'another account writes nothing');
pf_test(coreui_pfiles_list($pdo, 7, $b)['files'] === [], 'another project of the same account is empty');
pf_throws(static fn() => coreui_pfiles_get($pdo, 7, $b, 'notes.md'), 'file_not_found', 'files do not cross projects');

// ZIP out.
$zip = coreui_pfiles_zip($pdo, 7, $a);
$za = new ZipArchive();
pf_test($za->open($zip['path']) === true && $za->numFiles === 3 && $za->getFromName('src/game.js') !== false
  && $za->getFromName('public/index.html') === "<h1>Hallo</h1>\n" && $zip['name'] === 'Spiel-A.zip', 'ZIP with the current state');
$za->close();
unlink($zip['path']);
pf_throws(static fn() => coreui_pfiles_zip($pdo, 7, $b), 'no_files', 'an empty project has no ZIP');

// Delete.
pf_test(coreui_pfiles_delete($pdo, 7, $a, 'notes.md') && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_file_revisions WHERE path='notes.md'")->fetchColumn() === 0,
  'delete takes the history along');
pf_throws(static fn() => coreui_pfiles_delete($pdo, 7, $a, 'notes.md'), 'file_not_found', 'delete twice');

// ZIP in.
$in = $tmp . '/in.zip';
$zi = new ZipArchive();
$zi->open($in, ZipArchive::CREATE);
$zi->addFromString('projekt/index.html', '<p>aus zip</p>');
$zi->addFromString('projekt/css/style.css', 'p{color:red}');
$zi->addFromString('projekt/logo.png', "\x89PNG\r\n\x1a\nbinary");
$zi->addFromString('projekt/broken.txt', "\xff\xfe");
$zi->addFromString('projekt/big.txt', str_repeat('b', COREUI_PFILES_FILE_BYTES + 10));
$zi->addFromString('__MACOSX/projekt/._index.html', 'junk');
$zi->addEmptyDir('projekt/empty');
$zi->close();
$skipped = [];
$entries = coreui_pfiles_zip_entries($in, $skipped);
$reasons = array_column($skipped, 'reason', 'name');
pf_test(array_keys($entries) === ['index.html', 'css/style.css'] && $entries['index.html'] === '<p>aus zip</p>',
  'ZIP entries: the common top folder is removed, only text files: ' . json_encode(array_keys($entries)));
pf_test(($reasons['projekt/logo.png'] ?? '') === 'unsupported_type' && ($reasons['projekt/broken.txt'] ?? '') === 'not_text'
  && ($reasons['projekt/big.txt'] ?? '') === 'file_too_large' && !isset($reasons['__MACOSX/projekt/._index.html']),
  'binary, broken and too big entries are named, macOS leftovers are ignored: ' . json_encode($reasons));

// A build in a project chat: artifacts on disk with their SHA-256.
function pf_artifact(string $root, int $job, string $name, string $bytes): array {
  $key = $job . '/' . bin2hex(random_bytes(16));
  @mkdir($root . '/ember_py/artifacts/' . $job, 0700, true);
  file_put_contents($root . '/ember_py/artifacts/' . $key, $bytes);
  return ['storage_key'=>$key, 'name'=>$name, 'size_bytes'=>strlen($bytes), 'sha256'=>hash('sha256', $bytes),
    'mime_type'=>'text/plain', 'partial'=>false];
}
$zipBytes = (string)file_get_contents($in);
$artifacts = [
  pf_artifact($tmp, 41, 'projekt.zip', $zipBytes),
  pf_artifact($tmp, 41, 'index.html', '<p>lose Datei gewinnt</p>'),
  pf_artifact($tmp, 41, 'bild.png', "\x89PNG\r\n\x1a\n"),
];
$artifacts[] = pf_artifact($tmp, 41, 'halb.txt', 'abgebrochen') + ['partial'=>true];
$artifacts[3]['partial'] = true;
pf_test(coreui_pfiles_import_build($pdo, 7, 'cs_no_project_1', 501, $artifacts) === null, 'a chat outside a project keeps its files in the chat only');
$build = coreui_pfiles_import_build($pdo, 7, 'cs_in_project_a', 502, $artifacts);
$skippedNames = array_column($build['skipped'], 'reason', 'name');
pf_test($build['project_id'] === $a && $build['saved'] === ['index.html', 'css/style.css'] && $build['unchanged'] === 0,
  'build: text files of the ZIP and the loose files land in the project: ' . json_encode($build['saved']));
pf_test(coreui_pfiles_get($pdo, 7, $a, 'index.html')['content'] === '<p>lose Datei gewinnt</p>', 'a loose file wins over the same path in the ZIP');
pf_test(($skippedNames['bild.png'] ?? '') === 'unsupported_type' && ($skippedNames['halb.txt'] ?? '') === 'partial'
  && ($skippedNames['projekt/logo.png'] ?? '') === 'unsupported_type', 'images and broken-off outputs stay in the chat: ' . json_encode($skippedNames));
$rev = $pdo->query("SELECT source, session_id, message_id FROM stu_console_project_file_revisions WHERE path='css/style.css'")->fetch(PDO::FETCH_ASSOC);
pf_test($rev['source'] === 'build' && $rev['session_id'] === 'cs_in_project_a' && (int)$rev['message_id'] === 502, 'a build remembers its chat and answer');
$again = coreui_pfiles_import_build($pdo, 7, 'cs_in_project_a', 503, $artifacts);
pf_test($again['saved'] === [] && $again['unchanged'] === 2, 'the same build again changes nothing');
$tampered = $artifacts;
file_put_contents($tmp . '/ember_py/artifacts/' . $tampered[1]['storage_key'], 'manipuliert');
$bad = coreui_pfiles_import_build($pdo, 7, 'cs_in_project_a', 504, [$tampered[1]]);
pf_test($bad['saved'] === [] && ($bad['skipped'][0]['reason'] ?? '') === 'artifact_unavailable'
  && coreui_pfiles_get($pdo, 7, $a, 'index.html')['content'] === '<p>lose Datei gewinnt</p>', 'a changed artifact on disk is not imported');

// Limits: files per project, bytes per project, account storage.
for ($i = coreui_pfiles_list($pdo, 7, $b)['count']; $i < COREUI_PFILES_MAX_FILES; $i++) coreui_pfiles_write($pdo, 7, $b, "f/$i.txt", 'x', 'create');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $b, 'one-more.txt', 'x', 'create'), 'project_full', 'file 201 does not fit');
pf_test(coreui_pfiles_write($pdo, 7, $b, 'f/1.txt', 'still editable', 'edit')['changed'], 'a full project can still be edited');
$pdo->exec("DELETE FROM stu_console_project_files WHERE project_id='$b'");
$block = str_repeat('z', COREUI_PFILES_FILE_BYTES);
for ($i = 0; $i < 20; $i++) coreui_pfiles_write($pdo, 7, $b, "big/$i.txt", $block, 'create');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $b, 'big/20.txt', $block . 'x', 'create'), 'file_too_large', 'one byte too many');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $b, 'big/20.txt', $block, 'create'), 'project_full', '10 MiB per project');
$GLOBALS['pf_storage_limit'] = '1';
$usage = coreui_files_usage($pdo, 7);
pf_test($usage['files']['bytes'] > COREUI_FILES_MIB && $usage['files']['count'] > 20, 'project files and their revisions count as storage');
pf_throws(static fn() => coreui_pfiles_write($pdo, 7, $a, 'more.txt', 'x', 'create'), 'storage_full', 'a full account storage stops new states');
$full = coreui_pfiles_import_entries($pdo, 7, $a, ['from-build.txt'=>'x']);
pf_test($full['saved'] === [] && ($full['skipped'][0]['reason'] ?? '') === 'storage_full', 'a build into a full storage says so');
$GLOBALS['pf_storage_limit'] = '';
pf_test((coreui_files_usage_all($pdo)['7'] ?? 0) === $usage['used'], 'Admin Core counts the same bytes');

// A project that is deleted takes its files along; its chats stay.
$deleted = coreui_project_delete($pdo, 7, $a);
pf_test($deleted['deleted'] && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_files WHERE project_id='$a'")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_file_revisions WHERE project_id='$a'")->fetchColumn() === 0
  && $deleted['chats_kept'] === 1, 'project deletion removes files and revisions, keeps the chats');

// --- Wiring --------------------------------------------------------------------------------
$api = (string)file_get_contents($root . '/api/project_files.php');
$chat = (string)file_get_contents($root . '/api/chat.php');
$migration = (string)file_get_contents($root . '/database/migrations/044_project_files.sql');
pf_test(str_contains($api, 'stu_require_csrf();') && str_contains($api, "header('Content-Type: text/plain; charset=utf-8');")
  && str_contains($api, "Content-Disposition: attachment") && !str_contains($api, 'Content-Disposition: inline')
  && !str_contains($api, "'inline'"), 'API: CSRF on writes, files only as text downloads');
pf_test(str_contains($chat, "require_once __DIR__ . '/project_files_store.php';")
  && str_contains($chat, 'coreui_pfiles_import_build($pdo, $uid, $sessionId, $insertedId,'), 'chat: builds in project chats are imported');
$ui = (string)file_get_contents($root . '/js/coreui-artifacts.js');
pf_test(str_contains($api, "case 'import_chat':")
  && str_contains($api, "stu_require_csrf();")
  && str_contains($api, 'coreui_pfiles_promote_chat_artifacts(')
  && str_contains($ui, 'In Projekt übernehmen')
  && str_contains($ui, "action: 'import_chat'")
  && str_contains($ui, 'window.CoreAIProjects.openProject(data.project_id)'),
  'operator explicitly promotes verified chat outputs into the current project workflow');
$store = (string)file_get_contents($root . '/api/project_files_store.php');
pf_test(str_contains($store, 'coreui_console_artifact_select_sql()')
  && str_contains($store, 'coreui_console_artifact_open_verified($verified)')
  && str_contains($store, 'coreui_project_assign($pdo,$uid,$sessionId,')
  && str_contains($store, "ROLLBACK TO SAVEPOINT coreui_promote_artifacts"),
  'project import is owner-scoped, checksum-verified and transactionally rollback-safe');

pf_test(preg_match("~INSERT INTO stu_schema_migrations \\(version\\) VALUES \\('044_project_files'\\)~", $migration) === 1
  && str_contains($migration, 'COLLATE utf8mb4_bin') && str_contains($migration, 'ON DELETE CASCADE'), 'migration 044 registers itself');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $conf) {
  $text = (string)file_get_contents($root . '/' . $conf);
  $allowed = preg_match('#\^/api/\(\?:([a-z_|]+)\)#', $text, $m) === 1 ? explode('|', $m[1]) : [];
  pf_test(in_array('project_files', $allowed, true) && in_array('console_project_sources', $allowed, true),
    $conf . ' allows project_files.php and console_project_sources.php');
}

// --- MariaDB (optional) --------------------------------------------------------------------
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (array_filter(array_map('trim', explode(";\n", $migration))) as $sql) $db->exec($sql);
  pf_test(coreui_pfiles_schema_ready($db), 'MariaDB: migration 044');
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('pf-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  try {
    $project = coreui_project_create($db, $uid, 'Selbsttest Projektdateien', 'open');
    $pid = $project['id'];
    coreui_pfiles_write($db, $uid, $pid, 'A.md', 'gross', 'create');
    coreui_pfiles_write($db, $uid, $pid, 'a.md', "klein 🙂 Grüße", 'create');
    pf_test(coreui_pfiles_get($db, $uid, $pid, 'a.md')['content'] === "klein 🙂 Grüße" && coreui_pfiles_list($db, $uid, $pid)['count'] === 2,
      'MariaDB: paths are case-sensitive, emoji stay');
    $sha = coreui_pfiles_get($db, $uid, $pid, 'a.md')['sha256'];
    pf_test(coreui_pfiles_write($db, $uid, $pid, 'a.md', 'neu', 'edit', ['base_sha256'=>$sha])['file']['revision'] === 2, 'MariaDB: edit with FOR UPDATE');
    pf_test(coreui_pfiles_rename($db, $uid, $pid, 'a.md', 'docs/a.md')['revision'] === 3, 'MariaDB: rename');
    pf_test(coreui_pfiles_search($db, $uid, $pid, 'NEU')[0]['path'] === 'docs/a.md', 'MariaDB: search');
    $db->beginTransaction();
    $res = coreui_pfiles_import_entries($db, $uid, $pid, ['ok.txt'=>'ok', 'bad.png'=>'x']);
    $db->commit();
    pf_test($res['saved'] === ['ok.txt'] && $res['skipped'][0]['reason'] === 'unsupported_type', 'MariaDB: import inside the caller\'s transaction');
    $db->prepare('DELETE FROM stu_console_projects WHERE id=?')->execute([$pid]);
    $left = $db->prepare('SELECT (SELECT COUNT(*) FROM stu_console_project_files WHERE project_id=?) + (SELECT COUNT(*) FROM stu_console_project_file_revisions WHERE project_id=?)');
    $left->execute([$pid, $pid]);
    pf_test((int)$left->fetchColumn() === 0, 'MariaDB: deleting the project row cascades');
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
  }
  echo "CoreAI Projektdateien: MariaDB OK\n";
}

array_map('unlink', array_filter(glob($tmp . '/ember_py/artifacts/41/*') ?: []));
@rmdir($tmp . '/ember_py/artifacts/41');
@unlink($in);
echo "CoreAI Projektdateien: Pfade, Text, Anlegen, Bearbeiten, Konflikt, Verlauf, Umbenennen, Loeschen, Wiederherstellen, Suche, ZIP raus und rein, Bau im Projekt-Chat, Grenzen, Speicher, Trennung und Projekt loeschen OK\n";
