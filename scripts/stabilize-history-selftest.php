<?php
declare(strict_types=1);
// CoreAI 0.8.34 stabilization: the saved states of a project file survive a change of working state.
// Switching to a working state without the file and back used to delete every saved state (revision 1
// again, older states gone). The revisions now stay and a file that comes back continues its history.
// Migration 058 moves the cascade from the file row to the project. Offline with SQLite.
//   php scripts/stabilize-history-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/project_vcs_store.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, 'Stabilize history FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1); });
function hist_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Stabilize history FAIL: {$why}\n"); exit(1); }
}
function hist_sqlite(): PDO {
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
$pdo = hist_sqlite();
$p = str_repeat('a', 32);
$pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)')
  ->execute([$p, 7, 'Blog', 'open', '', null, '2026-11-24']);
coreui_pfiles_write($pdo, 7, $p, 'index.html', "<h1>Start</h1>\n", 'build');
coreui_pvcs_state($pdo, 7, $p);
coreui_pvcs_branch_create($pdo, 7, $p, 'entwurf', false);
coreui_pfiles_write($pdo, 7, $p, 'notes.md', "Entwurf 1\n", 'create');
foreach (["Entwurf 2\n", "Entwurf 3\n", "Entwurf 4\n"] as $c) {
  $f = coreui_pfiles_get($pdo, 7, $p, 'notes.md');
  coreui_pfiles_write($pdo, 7, $p, 'notes.md', $c, 'edit', ['base_sha256' => $f['sha256']]);
}
$ids = [];
foreach (coreui_pvcs_state($pdo, 7, $p)['branches'] as $b) $ids[$b['name']] = $b['id'];
$count = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_file_revisions WHERE path='notes.md'")->fetchColumn();

coreui_pvcs_switch($pdo, 7, $p, $ids['main']);
hist_test(coreui_pfiles_row($pdo, 7, $p, 'notes.md') === null && $count() === 4, 'away from the working state the file is gone, its four states are kept');
coreui_pvcs_switch($pdo, 7, $p, $ids['entwurf']);
$back = coreui_pfiles_get($pdo, 7, $p, 'notes.md');
hist_test($back['content'] === "Entwurf 4\n" && (int)$back['revision'] === 5 && count($back['revisions']) === 5,
  'back on the working state the file continues at revision 5 with all states: ' . json_encode([$back['revision'], count($back['revisions'])]));
hist_test(coreui_pfiles_revision($pdo, 7, $p, 'notes.md', 2)['content'] === "Entwurf 2\n", 'revision 2 is still restorable');

// A file written again at a path whose history was kept also continues it (not revision 1).
coreui_pvcs_switch($pdo, 7, $p, $ids['main']);
coreui_pfiles_write($pdo, 7, $p, 'notes.md', "Neu auf main\n", 'create');
$main = coreui_pfiles_get($pdo, 7, $p, 'notes.md');
hist_test((int)$main['revision'] === 6 && $count() === 6, 'a new file at the same path continues the kept history: ' . $main['revision']);

// Explicit delete still removes the history; a fresh file there starts at 1.
coreui_pfiles_delete($pdo, 7, $p, 'notes.md');
hist_test($count() === 0, 'an explicit delete removes the saved states as before');
coreui_pfiles_write($pdo, 7, $p, 'notes.md', "Ganz neu\n", 'create');
hist_test((int)coreui_pfiles_get($pdo, 7, $p, 'notes.md')['revision'] === 1, 'after a delete a new file starts at revision 1');

// Another account's kept history at the same path is never taken over.
$q = str_repeat('c', 32);
$pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)')
  ->execute([$q, 9, 'Fremd', 'open', '', null, '2026-11-24']);
$pdo->prepare('INSERT INTO stu_console_project_file_revisions (file_id, project_id, user_id, revision, path, content, size_bytes, sha256, source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
  ->execute([str_repeat('d', 32), $q, 9, 3, 'fremd.md', 'x', 1, hash('sha256', 'x'), 'edit', '2026-11-24']);
coreui_pfiles_write($pdo, 7, $p, 'fremd.md', "eigen\n", 'create');
$own = coreui_pfiles_get($pdo, 7, $p, 'fremd.md');
hist_test((int)$own['revision'] === 1 && coreui_pfiles_history_trail($pdo, $p, 7, 'fremd.md') === null, 'another project or account is never continued');

// Migration 058: the cascade moves from the file row to the project, every step repeatable.
$sql = (string)file_get_contents($root . '/database/migrations/058_project_file_history.sql');
hist_test(str_contains($sql, 'DROP FOREIGN KEY fk_project_file_revisions_file')
  && str_contains($sql, 'FOREIGN KEY (project_id) REFERENCES stu_console_projects(id) ON DELETE CASCADE')
  && str_contains($sql, 'ADD KEY idx_project_file_revisions_path (project_id, user_id, path)')
  && substr_count($sql, 'FROM information_schema.') === 3
  && str_contains($sql, "INSERT INTO stu_schema_migrations (version) VALUES ('058_project_file_history')"), 'migration 058 content');
$vcs = (string)file_get_contents($root . '/api/project_vcs_store.php');
hist_test(!str_contains($vcs, 'DELETE FROM stu_console_project_file_revisions WHERE file_id=?'), 'a change of working state deletes no saved states');

echo "CoreAI stabilization history: saved file states survive a change of working state and continue, delete and other accounts unchanged OK\n";
