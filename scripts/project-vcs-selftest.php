<?php
declare(strict_types=1);
// CoreAI 0.7.80 (P11 A): versions and working states of a project, and the Git export.
//   php scripts/project-vcs-selftest.php
// Offline: names and messages, changes between states, Git objects, trees and index, and the
// store against SQLite: the first version, saving, nothing to save, working states that carry or
// keep their unsaved changes, two working states that never change each other, restoring with
// an automatic save first, what a version changed, deleting a working state with its own
// versions, limits, other accounts, the storage count and a missing migration. With git on the
// machine, the exported ZIP is checked with git itself (fsck, log, branch, status, show).
// With COREUI_SELFTEST_DB=1 against MariaDB (migration 049, everything goes with its project).
// The acceptance of P11: a change is saved on its own working state, taken back under control and
// exported as a usable ZIP; the other working state stays as it was. Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/project_vcs_store.php';
function pvc_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project versions FAIL: {$why}\n"); exit(1); }
}
function pvc_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ------------------------------------------------------------------------------------------
foreach (['main', 'versuch-1', 'feature/neuer.titel', 'v2_0', 'a/b/c'] as $good) {
  pvc_test(coreui_pvcs_branch_name(" {$good} ") === $good, 'branch name ' . $good);
}
foreach (['', '-x', '.x', 'a..b', 'a b', 'a/', '/a', 'a//b', 'x.lock', 'a.lock/b', 'x.', 'ä', 'a~1', 'a^', 'a:b', 'a?', 'a*', 'a[b', 'a\\b',
    'HEAD@{1}', str_repeat('a', 61)] as $bad) {
  pvc_test(pvc_throws(static fn() => coreui_pvcs_branch_name($bad), 'invalid_branch_name'), 'refused branch name ' . json_encode($bad));
}
pvc_test(coreui_pvcs_message("  Neue   Farbe\nfuer den Titel ") === 'Neue Farbe fuer den Titel', 'message on one line');
foreach (['', '   ', str_repeat('x', 201), "\xC3\x28"] as $bad) {
  pvc_test(pvc_throws(static fn() => coreui_pvcs_message($bad), 'invalid_message'), 'refused message ' . json_encode($bad, JSON_INVALID_UTF8_SUBSTITUTE));
}
pvc_test(coreui_pvcs_changes(['a'=>'1', 'b'=>'2', 'c'=>'3'], ['a'=>'1', 'b'=>'9', 'd'=>'4'])
  === ['added'=>['d'], 'modified'=>['b'], 'deleted'=>['c'], 'clean'=>false]
  && coreui_pvcs_changes(['a'=>'1'], ['a'=>'1'])['clean'], 'changes between states');
pvc_test(coreui_pvcs_tree_digest(['b'=>'2', 'a'=>'1']) === hash('sha256', "a\0" . "1\n" . "b\0" . "2\n"), 'tree digest as the workers build it');
[$blob, $data] = coreui_pvcs_git_object('blob', "hello\n");
pvc_test($blob === 'ce013625030ba8dba906f756967f9e9ca394464a' && gzuncompress($data) === "blob 6\0hello\n", 'a Git blob as Git names it');
[$tree] = coreui_pvcs_git_trees(['hello.txt'=>$blob]);
pvc_test($tree === 'aaa96ced2d9a1c8e72c56b253a0e2fe78393feb7', 'a Git tree as Git names it: ' . $tree);
[, $trees] = coreui_pvcs_git_trees(['a.txt'=>$blob, 'a/b.txt'=>$blob, 'a-b.txt'=>$blob]);
$top = gzuncompress(end($trees));
pvc_test(strpos($top, "100644 a-b.txt\0") < strpos($top, "100644 a.txt\0") && strpos($top, "100644 a.txt\0") < strpos($top, "40000 a\0"),
  'tree entries in Git order (a folder sorts as its name with /): a-b.txt, a.txt, a/');
[, $trees] = coreui_pvcs_git_trees(['9'=>$blob, '10'=>$blob]);
$top = gzuncompress(end($trees));
pvc_test(strpos($top, "100644 10\0") < strpos($top, "100644 9\0"), 'names that look like numbers sort as text, as Git sorts them');
$index = coreui_pvcs_git_index(['b.txt'=>[$blob, 6], 'a.txt'=>[$blob, 6]]);
pvc_test(substr($index, 0, 12) === 'DIRC' . pack('NN', 2, 2) && sha1(substr($index, 0, -20), true) === substr($index, -20)
  && strpos($index, 'a.txt') < strpos($index, 'b.txt') && (strlen($index) - 12 - 20) % 8 === 0, 'a Git index, sorted, padded, with its checksum');
pvc_test(coreui_pvcs_git_person('person') === 'CoreAI-Konto <konto@coreai.invalid>' && coreui_pvcs_git_person('mara') === 'Mara Thorne <mara@coreai.invalid>',
  'no personal data in the Git authors');

// --- Wiring (static) ---------------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/project_files.php');
foreach (['vcs_commit', 'vcs_branch', 'vcs_switch', 'vcs_restore', 'vcs_branch_delete'] as $action) {
  pvc_test(strpos($endpoint, "case '{$action}':") > strpos($endpoint, 'stu_require_csrf();'), "endpoint: {$action} behind CSRF");
}
pvc_test(str_contains($endpoint, "if (isset(\$_GET['vcs_zip'])) {\n      \$zip = coreui_pvcs_git_zip(\$pdo, \$uid, \$projectId);")
  && str_contains($endpoint, "coreui_pfiles_send_download(\$zip['name'], 'application/zip', \$zip['path']);"), 'endpoint: the Git ZIP as a download');
$migration = (string)file_get_contents($root . '/database/migrations/049_project_versions.sql');
pvc_test(substr_count($migration, 'REFERENCES stu_console_projects(id) ON DELETE CASCADE') === 4 && str_contains($migration, "VALUES ('049_project_versions')")
  && str_contains($migration, "ENUM('build','create','edit','rename','restore','mara','branch')"), 'migration 049');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Projektversionen: Namen, Nachrichten, Aenderungen, Git-Objekte, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite ----------------------------------------------------------------------------
function pvc_sqlite(): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
  $pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_media (user_id INTEGER, kind TEXT, file_size INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_files (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, path TEXT NOT NULL,
    content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, UNIQUE (project_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_file_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id TEXT NOT NULL, project_id TEXT NOT NULL,
    user_id INTEGER NOT NULL, revision INTEGER NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL,
    source TEXT NOT NULL, session_id TEXT, message_id INTEGER, created_at TEXT, UNIQUE (file_id, revision))');
  $pdo->exec('CREATE TABLE stu_console_project_blobs (project_id TEXT NOT NULL, sha256 TEXT NOT NULL, user_id INTEGER NOT NULL, content TEXT NOT NULL,
    size_bytes INTEGER NOT NULL, created_at TEXT, PRIMARY KEY (project_id, sha256))');
  $pdo->exec('CREATE TABLE stu_console_project_branches (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, name TEXT NOT NULL,
    head_commit_id INTEGER, worktree_saved INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE (project_id, name))');
  $pdo->exec('CREATE TABLE stu_console_project_commits (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, branch_id INTEGER,
    parent_id INTEGER, message TEXT NOT NULL, author TEXT NOT NULL, files_count INTEGER NOT NULL, bytes INTEGER NOT NULL, tree_sha256 TEXT NOT NULL, created_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_commit_files (commit_id INTEGER NOT NULL, path TEXT NOT NULL, sha256 TEXT NOT NULL, PRIMARY KEY (commit_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_worktrees (branch_id INTEGER NOT NULL, path TEXT NOT NULL, sha256 TEXT NOT NULL, PRIMARY KEY (branch_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_vcs (project_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, active_branch_id INTEGER NOT NULL, updated_at TEXT)');
  $pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
  return $pdo;
}
$pdo = pvc_sqlite();
$p = str_repeat('a', 32); $o = str_repeat('c', 32);
$ins = $pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)');
$ins->execute([$p, 7, 'Web Projekt', 'open', '', null, '2026-11-17']);
$ins->execute([$o, 9, 'Fremd', 'open', '', null, '2026-11-17']);
$page = "<!doctype html>\n<h1>Alter Titel</h1>\n<link rel=\"stylesheet\" href=\"style.css\">\n";
coreui_pfiles_write($pdo, 7, $p, 'index.html', $page, 'build');
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: red; }\n", 'build');
coreui_pfiles_write($pdo, 7, $p, 'kopie.css', "h1 { color: red; }\n", 'build');
$file = static fn(string $path): string => (string)coreui_pfiles_get($pdo, 7, $p, $path)['content'];

// The first look: "main" with a first version of what is there.
$s = coreui_pvcs_state($pdo, 7, $p);
pvc_test($s['active']['name'] === 'main' && count($s['branches']) === 1 && count($s['log']) === 1 && $s['log'][0]['message'] === 'Ausgangsstand'
  && $s['log'][0]['author'] === 'auto' && $s['log'][0]['files_count'] === 3 && $s['status']['clean'] && !$s['active']['unsaved'],
  'first look: main with its first version: ' . json_encode($s));
pvc_test((int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_blobs WHERE project_id='{$p}'")->fetchColumn() === 2,
  'two files with the same content share one stored content');
coreui_pvcs_state($pdo, 7, $p);
pvc_test((int)$pdo->query('SELECT COUNT(*) FROM stu_console_project_branches')->fetchColumn() === 1, 'the first look happens once');

// Save a change; nothing to save twice.
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: blue; }\n", 'edit');
$s = coreui_pvcs_state($pdo, 7, $p);
pvc_test($s['status'] === ['added'=>[], 'modified'=>['style.css'], 'deleted'=>[], 'clean'=>false] && $s['active']['unsaved'], 'unsaved change shown');
$farbe = coreui_pvcs_commit($pdo, 7, $p, 'Blaue Ueberschrift');
pvc_test($farbe['message'] === 'Blaue Ueberschrift' && $farbe['author'] === 'person' && $farbe['parent_id'] === $s['log'][0]['id']
  && coreui_pvcs_state($pdo, 7, $p)['status']['clean'], 'a version of the change');
pvc_test(pvc_throws(static fn() => coreui_pvcs_commit($pdo, 7, $p, 'Nochmal'), 'nothing_to_commit'), 'nothing to save twice');
$changes = coreui_pvcs_commit_changes($pdo, 7, $p, $farbe['id']);
pvc_test($changes['changes'] === ['added'=>[], 'modified'=>['style.css'], 'deleted'=>[], 'clean'=>false], 'what a version changed');
pvc_test(coreui_pvcs_file($pdo, 7, $p, $s['log'][0]['id'], 'style.css')['content'] === "h1 { color: red; }\n"
  && coreui_pvcs_file($pdo, 7, $p, $farbe['id'], 'style.css')['content'] === "h1 { color: blue; }\n"
  && coreui_pvcs_file($pdo, 7, $p, 'work', 'style.css')['content'] === "h1 { color: blue; }\n"
  && coreui_pvcs_file($pdo, 7, $p, $farbe['id'], 'neu.txt') === ['path'=>'neu.txt', 'exists'=>false, 'content'=>''], 'a file in a version and now');

// The acceptance: a change on its own working state (carried along), main stays as it was.
coreui_pfiles_write($pdo, 7, $p, 'index.html', str_replace('Alter Titel', 'Neuer Titel', $page), 'edit');
$s = coreui_pvcs_branch_create($pdo, 7, $p, 'neuer-titel', true);
pvc_test($s['active']['name'] === 'neuer-titel' && $s['status']['modified'] === ['index.html'] && str_contains($file('index.html'), 'Neuer Titel'),
  'the unsaved change moves along to the new working state');
$main = array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === 'main'))[0];
pvc_test(!$main['unsaved'] && !$main['active'], 'main is left at its newest version');
$titel = coreui_pvcs_commit($pdo, 7, $p, 'Neuer Titel');
$s = coreui_pvcs_switch($pdo, 7, $p, $main['id']);
pvc_test($s['active']['name'] === 'main' && $s['status']['clean'] && str_contains($file('index.html'), 'Alter Titel'), 'main still has the old title');
$rev = $pdo->query("SELECT source FROM stu_console_project_file_revisions WHERE path='index.html' ORDER BY id DESC LIMIT 1")->fetchColumn();
pvc_test($rev === 'branch', 'a file changed by a switch has a revision of that kind: ' . $rev);
$branchId = array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === 'neuer-titel'))[0]['id'];

// Two working states never change each other: unsaved work stays where it was made.
coreui_pfiles_write($pdo, 7, $p, 'notiz.txt', "Nur auf main\n", 'create');
$s = coreui_pvcs_switch($pdo, 7, $p, $branchId);
pvc_test($s['active']['name'] === 'neuer-titel' && !in_array('notiz.txt', array_column(coreui_pfiles_list($pdo, 7, $p)['files'], 'path'), true)
  && str_contains($file('index.html'), 'Neuer Titel') && $s['status']['clean'], 'the other working state does not see the unsaved note');
$m = array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === 'main'))[0];
pvc_test($m['unsaved'], 'main keeps its unsaved note');
$s = coreui_pvcs_switch($pdo, 7, $p, $main['id']);
pvc_test($file('notiz.txt') === "Nur auf main\n" && $s['status']['added'] === ['notiz.txt'], 'back on main, the note is there again');
$s = coreui_pvcs_branch_create($pdo, 7, $p, 'leer', false);
pvc_test($s['active']['name'] === 'leer' && $s['status']['clean'] && !in_array('notiz.txt', array_column(coreui_pfiles_list($pdo, 7, $p)['files'], 'path'), true),
  'a new working state without carrying starts clean');
$s = coreui_pvcs_switch($pdo, 7, $p, $main['id']);
pvc_test($file('notiz.txt') === "Nur auf main\n", 'and main still has its note');

// Restore under control: the unsaved note is saved first, the old state comes back as a new version.
$before = count(coreui_pvcs_state($pdo, 7, $p)['log']);
$first = coreui_pvcs_state($pdo, 7, $p)['log'][$before - 1];
$s = coreui_pvcs_restore($pdo, 7, $p, $first['id']);
pvc_test(count($s['log']) === $before + 2 && $s['log'][0]['message'] === 'Wiederhergestellt: Ausgangsstand'
  && $s['log'][1]['message'] === 'Automatisch gesichert vor dem Wiederherstellen' && $s['log'][1]['author'] === 'auto'
  && $file('style.css') === "h1 { color: red; }\n" && !in_array('notiz.txt', array_column(coreui_pfiles_list($pdo, 7, $p)['files'], 'path'), true)
  && $s['status']['clean'], 'restore: unsaved note saved first, old state back as a new version: ' . json_encode(array_column($s['log'], 'message')));
pvc_test(coreui_pvcs_file($pdo, 7, $p, $s['log'][1]['id'], 'notiz.txt')['content'] === "Nur auf main\n", 'the saved note is still in its version');
pvc_test(pvc_throws(static fn() => coreui_pvcs_restore($pdo, 7, $p, $first['id']), 'already_current'), 'restoring what is there does nothing');
pvc_test(str_contains(coreui_pvcs_file($pdo, 7, $p, $titel['id'], 'index.html')['content'], 'Neuer Titel'), 'the other working state keeps its version');
$rev = $pdo->query("SELECT source FROM stu_console_project_file_revisions WHERE path='style.css' ORDER BY id DESC LIMIT 1")->fetchColumn();
pvc_test($rev === 'restore', 'a restored file has a restore revision');

// The usable ZIP with Git.
$zip = coreui_pvcs_git_zip($pdo, 7, $p);
$git = trim((string)shell_exec('command -v git 2>/dev/null'));
$gitChecked = 'git nicht installiert, ZIP nur geoeffnet';
$za = new ZipArchive();
pvc_test($za->open($zip['path']) === true && $za->getFromName('index.html') === $file('index.html')
  && $za->getFromName('.git/HEAD') === "ref: refs/heads/main\n" && $za->locateName('.git/refs/heads/neuer-titel') !== false
  && $zip['name'] === 'Web-Projekt-git.zip', 'the ZIP has the files and a Git repository');
$za->close();
if ($git !== '') {
  $dir = sys_get_temp_dir() . '/coreai-vcs-' . bin2hex(random_bytes(4));
  mkdir($dir);
  $za = new ZipArchive();
  $za->open($zip['path']);
  $za->extractTo($dir);
  $za->close();
  $run = static function (string $cmd) use ($dir): array {
    exec('cd ' . escapeshellarg($dir) . ' && GIT_CONFIG_NOSYSTEM=1 HOME=' . escapeshellarg($dir) . ' ' . $cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
  };
  [$c1, $fsck] = $run('git fsck --strict --no-dangling');
  [$c2, $log] = $run('git log --format=%s main');
  [$c3, $status] = $run('git status --porcelain');
  [$c4, $branches] = $run("git branch --format='%(refname:short)'");
  [$c5, $show] = $run('git show neuer-titel:index.html');
  [$c6, $author] = $run('git log -1 --format=%an main');
  pvc_test($c1 === 0, 'git fsck accepts the repository: ' . $fsck);
  pvc_test($c2 === 0 && $log === "Wiederhergestellt: Ausgangsstand\nAutomatisch gesichert vor dem Wiederherstellen\nBlaue Ueberschrift\nAusgangsstand",
    'git log shows the versions of main: ' . $log);
  pvc_test($c3 === 0 && $status === '', 'git status is clean: ' . $status);
  pvc_test($c4 === 0 && $branches === "leer\nmain\nneuer-titel", 'every working state is a branch: ' . $branches);
  pvc_test($c5 === 0 && str_contains($show, 'Neuer Titel'), 'the other branch has its version');
  pvc_test($c6 === 0 && $author === 'CoreAI-Konto', 'the author is not a person: ' . $author);
  // With an unsaved change git status shows it.
  coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: green; }\n", 'edit');
  exec('rm -rf ' . escapeshellarg($dir));
  mkdir($dir);
  $zip2 = coreui_pvcs_git_zip($pdo, 7, $p);
  $za = new ZipArchive();
  $za->open($zip2['path']);
  $za->extractTo($dir);
  $za->close();
  [$c7, $status] = $run('git status --porcelain');
  [$c8, $diff] = $run('git diff --stat');
  pvc_test($c7 === 0 && $status === ' M style.css' && str_contains($diff, 'style.css'), 'an unsaved change shows in git status: ' . $status);
  @unlink($zip2['path']);
  exec('rm -rf ' . escapeshellarg($dir));
  coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: red; }\n", 'edit');
  $gitChecked = 'git fsck, log, status, branch und show gepruefter Export';
}
@unlink($zip['path']);

// Deleting a working state: its own versions go, shared ones stay; the active one cannot go.
$s = coreui_pvcs_state($pdo, 7, $p);
$leer = array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === 'leer'))[0]['id'];
pvc_test(pvc_throws(static fn() => coreui_pvcs_branch_delete($pdo, 7, $p, $s['active']['id']), 'branch_active'), 'the active working state cannot go');
$s = coreui_pvcs_branch_delete($pdo, 7, $p, $branchId);
pvc_test(count($s['branches']) === 2 && pvc_throws(static fn() => coreui_pvcs_commit_changes($pdo, 7, $p, $titel['id']), 'commit_not_found')
  && coreui_pvcs_commit_changes($pdo, 7, $p, $farbe['id'])['commit']['id'] === $farbe['id'], 'its own version goes, shared ones stay');
$titleBlob = hash('sha256', str_replace('Alter Titel', 'Neuer Titel', $page));
pvc_test((int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_blobs WHERE sha256='{$titleBlob}'")->fetchColumn() === 0, 'and its content too');
coreui_pvcs_branch_delete($pdo, 7, $p, $leer);

// Limits, other accounts, names.
pvc_test(pvc_throws(static fn() => coreui_pvcs_branch_create($pdo, 7, $p, 'main', true), 'branch_exists')
  && pvc_throws(static fn() => coreui_pvcs_branch_create($pdo, 7, $p, 'main/x', true), 'branch_exists')
  && pvc_throws(static fn() => coreui_pvcs_branch_create($pdo, 7, $p, 'a b', true), 'invalid_branch_name'), 'names of working states');
for ($i = 1; $i < COREUI_PVCS_BRANCHES; $i++) coreui_pvcs_branch_create($pdo, 7, $p, 'b' . $i, true);
pvc_test(pvc_throws(static fn() => coreui_pvcs_branch_create($pdo, 7, $p, 'zuviel', true), 'branch_limit'), 'at most 10 working states');
pvc_test(pvc_throws(static fn() => coreui_pvcs_state($pdo, 9, $p), 'project_not_found')
  && pvc_throws(static fn() => coreui_pvcs_switch($pdo, 9, $p, $main['id']), 'project_not_found')
  && pvc_throws(static fn() => coreui_pvcs_git_zip($pdo, 9, $p), 'project_not_found'), 'nobody else sees or changes them');
coreui_pfiles_write($pdo, 9, $o, 'x.txt', "x\n", 'build');
$other = coreui_pvcs_state($pdo, 9, $o);
pvc_test(pvc_throws(static fn() => coreui_pvcs_switch($pdo, 7, $p, $other['active']['id']), 'branch_not_found')
  && pvc_throws(static fn() => coreui_pvcs_restore($pdo, 7, $p, $other['log'][0]['id']), 'commit_not_found'), 'no working state or version of another project');
$fill = $pdo->prepare("INSERT INTO stu_console_project_commits (project_id, user_id, message, author, files_count, bytes, tree_sha256, created_at)
  VALUES (?, 7, 'x', 'auto', 0, 0, 'x', '2026-11-17')");
$count = (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id='{$p}'")->fetchColumn();
for ($i = $count; $i < COREUI_PVCS_COMMITS; $i++) $fill->execute([$p]);
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: gold; }\n", 'edit');
pvc_test(pvc_throws(static fn() => coreui_pvcs_commit($pdo, 7, $p, 'Zu viel'), 'history_full'), 'at most 200 versions per project');
$usage = coreui_pfiles_usage($pdo, 7)['bytes'];
$blobs = (int)$pdo->query('SELECT SUM(size_bytes) FROM stu_console_project_blobs WHERE user_id=7')->fetchColumn();
$revs = (int)$pdo->query('SELECT SUM(size_bytes) FROM stu_console_project_file_revisions WHERE user_id=7')->fetchColumn();
pvc_test($usage === $blobs + $revs && $blobs > 0, 'the stored versions count toward the account storage');
coreui_pfiles_purge($pdo, 7, $p);
pvc_test((int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_blobs WHERE project_id='{$p}'")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_branches WHERE project_id='{$p}'")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_vcs WHERE project_id='{$p}'")->fetchColumn() === 0, 'everything goes with the project');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
pvc_test(!coreui_pvcs_ready($old) && pvc_throws(static fn() => coreui_pvcs_init($old, 7, $p), 'project_vcs_migration_required'), 'without migration 049 no versions');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql', '049_project_versions.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('pvc-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  try {
    $project = coreui_project_create($db, $uid, 'Selbsttest Versionen', 'open');
    $pid = $project['id'];
    coreui_pfiles_write($db, $uid, $pid, 'index.html', "<h1>Eins</h1>\n", 'build');
    $s = coreui_pvcs_state($db, $uid, $pid);
    pvc_test(count($s['log']) === 1 && $s['active']['name'] === 'main', 'MariaDB: main with its first version');
    coreui_pfiles_write($db, $uid, $pid, 'index.html', "<h1>Zwei</h1>\n", 'edit');
    $s = coreui_pvcs_branch_create($db, $uid, $pid, 'zwei', true);
    coreui_pvcs_commit($db, $uid, $pid, 'Zwei');
    $mainId = array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === 'main'))[0]['id'];
    $s = coreui_pvcs_switch($db, $uid, $pid, $mainId);
    pvc_test(coreui_pfiles_get($db, $uid, $pid, 'index.html')['content'] === "<h1>Eins</h1>\n" && $s['status']['clean'], 'MariaDB: main keeps its state');
    $rev = $db->prepare("SELECT source FROM stu_console_project_file_revisions WHERE project_id=? ORDER BY id DESC LIMIT 1");
    $rev->execute([$pid]);
    pvc_test($rev->fetchColumn() === 'branch', 'MariaDB: the revision kind "branch" (migration 049)');
    $zip = coreui_pvcs_git_zip($db, $uid, $pid);
    pvc_test(is_file($zip['path']) && filesize($zip['path']) > 0, 'MariaDB: the Git ZIP');
    @unlink($zip['path']);
    coreui_project_delete($db, $uid, $pid);
    foreach (['stu_console_project_blobs', 'stu_console_project_branches', 'stu_console_project_commits', 'stu_console_project_vcs'] as $table) {
      $left = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE project_id=?");
      $left->execute([$pid]);
      pvc_test((int)$left->fetchColumn() === 0, "MariaDB: {$table} goes with the project");
    }
    $mariadb = ', MariaDB (Migration 049)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
  }
}

echo "CoreAI Projektversionen: Namen, Nachrichten, Aenderungen, Git-Objekte, Sichern, Arbeitsstaende mit eigenen Dateien, Wiederherstellen mit Sicherung, Loeschen, Grenzen, fremde Konten, Speicher OK; {$gitChecked} (SQLite{$mariadb})\n";
