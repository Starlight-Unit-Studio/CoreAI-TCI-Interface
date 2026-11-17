<?php
declare(strict_types=1);

require_once __DIR__ . '/project_files_store.php';

/*
 * 0.7.80 (P11 A): versions and working states of a project.
 *
 * A version ("Sicherung", a commit) keeps every file of one moment as a path and the sha256 of
 * its content; each content is stored once per project (stu_console_project_blobs). A working
 * state ("Arbeitsstand", a branch) points at its newest version. The files of the active working
 * state are the project files themselves, so the editor, the preview, runs, processes and Mara
 * keep working on them unchanged. When another working state becomes active, the unsaved files of
 * the previous one are kept with it (stu_console_project_worktrees): two working states never
 * change each other.
 *
 * Restoring a version first saves unsaved changes as a version of their own and then adds the
 * restored state as a new version, so nothing is lost. The export is a ZIP of the active working
 * state with a real Git repository (.git) built here from the versions: git log, git status and
 * git checkout work after unpacking. No git program is needed on the server.
 *
 * 0.7.81 (P11 B): another working state can be merged into the active one (migration 050). The
 * merge starts from the newest version both have in common and takes every change of either side;
 * where both changed the same lines differently, the person decides (their version, the other one,
 * or a text of their own) before anything changes. Unsaved changes are saved first, the other
 * working state stays as it is, and the merge is a new version with two parents, so it can be
 * taken back like every other version.
 */

const COREUI_PVCS_BRANCHES = 10;
const COREUI_PVCS_COMMITS = 200;
const COREUI_PVCS_MESSAGE_CHARS = 200;
const COREUI_PVCS_LOG = 50;
const COREUI_PVCS_MAIN = 'main';
// 0.7.81: how far the line comparison of a merge searches before it treats a part as changed as a whole.
const COREUI_PVCS_DIFF_EDITS = 600;

function coreui_pvcs_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      foreach (['stu_console_project_blobs', 'stu_console_project_branches', 'stu_console_project_commits',
          'stu_console_project_commit_files', 'stu_console_project_worktrees', 'stu_console_project_vcs'] as $table) {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
      }
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** 0.7.81 (P11 B): merging needs migration 050 (a version with a second parent). */
function coreui_pvcs_merge_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT merge_parent_id FROM stu_console_project_commits LIMIT 0');
      $memo[$key] = coreui_pvcs_ready($pdo);
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** A working state's name as Git accepts it for a branch (pure). */
function coreui_pvcs_branch_name($value): string {
  $name = trim((string)$value);
  if ($name === '' || strlen($name) > 60 || preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$~D', $name) !== 1
      || str_contains($name, '..') || str_ends_with($name, '.') || str_ends_with($name, '.lock') || preg_match('~\.lock/~', $name) === 1) {
    throw new InvalidArgumentException('invalid_branch_name');
  }
  return $name;
}

/** The message of a version (pure): one line, 1 to 200 characters. */
function coreui_pvcs_message($value): string {
  $message = trim((string)preg_replace('~\s+~u', ' ', (string)$value));
  if ($message === '' || preg_match('//u', $message) !== 1 || mb_strlen($message, 'UTF-8') > COREUI_PVCS_MESSAGE_CHARS) {
    throw new InvalidArgumentException('invalid_message');
  }
  return $message;
}

/** One digest over path and content hash of every file, sorted by path (pure). */
function coreui_pvcs_tree_digest(array $meta): string {
  ksort($meta, SORT_STRING);
  $h = hash_init('sha256');
  foreach ($meta as $path => $sha) hash_update($h, $path . "\0" . $sha . "\n");
  return hash_final($h);
}

/** What changed from one state to another (pure): added, modified and deleted paths, sorted. */
function coreui_pvcs_changes(array $from, array $to): array {
  $added = array_values(array_diff(array_keys($to), array_keys($from)));
  $deleted = array_values(array_diff(array_keys($from), array_keys($to)));
  $modified = [];
  foreach ($to as $path => $sha) if (isset($from[$path]) && $from[$path] !== $sha) $modified[] = (string)$path;
  $added = array_map('strval', $added);
  $deleted = array_map('strval', $deleted);
  sort($added, SORT_STRING); sort($deleted, SORT_STRING); sort($modified, SORT_STRING);
  return ['added'=>$added, 'modified'=>$modified, 'deleted'=>$deleted, 'clean'=>$added === [] && $modified === [] && $deleted === []];
}

function coreui_pvcs_insert_ignore(PDO $pdo): string {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
}

/** The project files of the active working state: path => sha256. */
function coreui_pvcs_working(PDO $pdo, int $uid, string $projectId): array {
  $st = $pdo->prepare('SELECT path, sha256 FROM stu_console_project_files WHERE project_id=? AND user_id=? ORDER BY path');
  $st->execute([$projectId, $uid]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(string)$row['path']] = (string)$row['sha256'];
  return $out;
}

function coreui_pvcs_commit_files(PDO $pdo, ?int $commitId): array {
  if ($commitId === null) return [];
  $st = $pdo->prepare('SELECT path, sha256 FROM stu_console_project_commit_files WHERE commit_id=? ORDER BY path');
  $st->execute([$commitId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(string)$row['path']] = (string)$row['sha256'];
  return $out;
}

function coreui_pvcs_worktree(PDO $pdo, int $branchId): array {
  $st = $pdo->prepare('SELECT path, sha256 FROM stu_console_project_worktrees WHERE branch_id=? ORDER BY path');
  $st->execute([$branchId]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(string)$row['path']] = (string)$row['sha256'];
  return $out;
}

/** The content of a stored file of the project. */
function coreui_pvcs_blob(PDO $pdo, string $projectId, string $sha): string {
  $st = $pdo->prepare('SELECT content FROM stu_console_project_blobs WHERE project_id=? AND sha256=?');
  $st->execute([$projectId, $sha]);
  $content = $st->fetchColumn();
  if ($content === false) throw new RuntimeException('blob_missing');
  return (string)$content;
}

/** The current project files are stored as contents (each once); returns the bytes newly stored. */
function coreui_pvcs_store_working(PDO $pdo, int $uid, string $projectId): int {
  $st = $pdo->prepare('SELECT f.sha256, f.content, f.size_bytes FROM stu_console_project_files f
    LEFT JOIN stu_console_project_blobs b ON b.project_id=f.project_id AND b.sha256=f.sha256
    WHERE f.project_id=? AND f.user_id=? AND b.sha256 IS NULL');
  $st->execute([$projectId, $uid]);
  $insert = $pdo->prepare(coreui_pvcs_insert_ignore($pdo) . ' INTO stu_console_project_blobs (project_id, sha256, user_id, content, size_bytes, created_at)
    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
  $bytes = 0;
  $seen = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    if (isset($seen[$row['sha256']])) continue;
    $seen[$row['sha256']] = true;
    $insert->execute([$projectId, $row['sha256'], $uid, $row['content'], (int)$row['size_bytes']]);
    $bytes += (int)$row['size_bytes'];
  }
  return $bytes;
}

function coreui_pvcs_lock(PDO $pdo, string $projectId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_vcs WHERE project_id=?' . coreui_pfiles_lock_sql($pdo));
  $st->execute([$projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('vcs_not_ready');
  return $row;
}

function coreui_pvcs_branch(PDO $pdo, string $projectId, int $branchId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_branches WHERE id=? AND project_id=?');
  $st->execute([$branchId, $projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('branch_not_found');
  return $row;
}

function coreui_pvcs_commit_row(PDO $pdo, string $projectId, int $commitId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_commits WHERE id=? AND project_id=?');
  $st->execute([$commitId, $projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('commit_not_found');
  return $row;
}

/**
 * A new version of $files (path => sha256, contents already stored) on a working state.
 * $mergeParent: 0.7.81, the newest version of the working state merged in (a second parent).
 */
function coreui_pvcs_add_commit(PDO $pdo, int $uid, string $projectId, array $branch, array $files, string $message, string $author,
    ?int $mergeParent = null): int {
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id=?');
  $st->execute([$projectId]);
  if ((int)$st->fetchColumn() >= COREUI_PVCS_COMMITS) throw new RuntimeException('history_full');
  $bytes = 0;
  if ($files !== []) {
    // Two files with the same content share one stored content.
    $unique = array_values(array_unique(array_map('strval', array_values($files))));
    $marks = implode(',', array_fill(0, count($unique), '?'));
    $sizes = $pdo->prepare("SELECT sha256, size_bytes FROM stu_console_project_blobs WHERE project_id=? AND sha256 IN ($marks)");
    $sizes->execute([$projectId, ...$unique]);
    $size = [];
    foreach ($sizes->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $size[$row['sha256']] = (int)$row['size_bytes'];
    foreach ($files as $sha) $bytes += $size[$sha] ?? 0;
  }
  $pdo->prepare('INSERT INTO stu_console_project_commits (project_id, user_id, branch_id, parent_id, message, author, files_count, bytes,
    tree_sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')
    ->execute([$projectId, $uid, (int)$branch['id'], $branch['head_commit_id'] === null ? null : (int)$branch['head_commit_id'], $message,
      $author, count($files), $bytes, coreui_pvcs_tree_digest($files)]);
  $id = (int)$pdo->lastInsertId();
  if ($mergeParent !== null) {
    $pdo->prepare('UPDATE stu_console_project_commits SET merge_parent_id=? WHERE id=?')->execute([$mergeParent, $id]);
  }
  $insert = $pdo->prepare('INSERT INTO stu_console_project_commit_files (commit_id, path, sha256) VALUES (?, ?, ?)');
  foreach ($files as $path => $sha) $insert->execute([$id, $path, $sha]);
  $pdo->prepare('UPDATE stu_console_project_branches SET head_commit_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id, (int)$branch['id']]);
  return $id;
}

/** The working state of a project starts with "main" and, when there are files, a first version. */
function coreui_pvcs_init(PDO $pdo, int $uid, string $projectId): void {
  if (!coreui_pvcs_ready($pdo)) throw new RuntimeException('project_vcs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_vcs WHERE project_id=?');
  $st->execute([$project['id']]);
  if ((int)$st->fetchColumn() > 0) return;
  coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project): void {
    $pdo->prepare('INSERT INTO stu_console_project_branches (project_id, user_id, name, head_commit_id, worktree_saved, created_at, updated_at)
      VALUES (?, ?, ?, NULL, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')->execute([$project['id'], $uid, COREUI_PVCS_MAIN]);
    $branchId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stu_console_project_vcs (project_id, user_id, active_branch_id, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
      ->execute([$project['id'], $uid, $branchId]);
    $working = coreui_pvcs_working($pdo, $uid, $project['id']);
    if ($working !== []) {
      coreui_pvcs_store_working($pdo, $uid, $project['id']);
      coreui_pvcs_add_commit($pdo, $uid, $project['id'], coreui_pvcs_branch($pdo, $project['id'], $branchId), $working, 'Ausgangsstand', 'auto');
    }
  });
}

function coreui_pvcs_commit_public(array $row): array {
  $merge = $row['merge_parent_id'] ?? null;
  return ['id'=>(int)$row['id'], 'parent_id'=>$row['parent_id'] === null ? null : (int)$row['parent_id'],
    'merge_parent_id'=>$merge === null ? null : (int)$merge, 'message'=>(string)$row['message'],
    'author'=>(string)$row['author'], 'files_count'=>(int)$row['files_count'], 'bytes'=>(int)$row['bytes'],
    'branch_id'=>$row['branch_id'] === null ? null : (int)$row['branch_id'], 'created_at'=>(string)$row['created_at']];
}

/** 0.7.81: the parents of every version of a project: id => [first parent, merged parent] (only those there). */
function coreui_pvcs_graph(PDO $pdo, string $projectId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_commits WHERE project_id=?');
  $st->execute([$projectId]);
  $graph = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $parents = [];
    foreach ([$row['parent_id'] ?? null, $row['merge_parent_id'] ?? null] as $parent) if ($parent !== null) $parents[] = (int)$parent;
    $graph[(int)$row['id']] = $parents;
  }
  return $graph;
}

/** A version and every version before it, over both parents (pure): id => true. */
function coreui_pvcs_ancestors(array $graph, ?int $id): array {
  $seen = [];
  $todo = $id === null ? [] : [$id];
  while ($todo !== []) {
    $next = array_pop($todo);
    if (isset($seen[$next]) || !isset($graph[$next])) continue;
    $seen[$next] = true;
    foreach ($graph[$next] as $parent) $todo[] = $parent;
  }
  return $seen;
}

/**
 * The newest version two versions have in common (pure), or null. Versions only get younger
 * than their parents, so the highest common number is never an ancestor of another common one.
 */
function coreui_pvcs_merge_base(array $graph, ?int $a, ?int $b): ?int {
  $common = array_intersect_key(coreui_pvcs_ancestors($graph, $a), coreui_pvcs_ancestors($graph, $b));
  return $common === [] ? null : max(array_keys($common));
}

/** The versions of a working state, newest first, over both parents of a merge. */
function coreui_pvcs_log(PDO $pdo, string $projectId, ?int $headId, int $limit = COREUI_PVCS_LOG): array {
  $ids = array_keys(coreui_pvcs_ancestors(coreui_pvcs_graph($pdo, $projectId), $headId));
  rsort($ids, SORT_NUMERIC);
  $out = [];
  $st = $pdo->prepare('SELECT * FROM stu_console_project_commits WHERE id=? AND project_id=?');
  foreach (array_slice($ids, 0, $limit) as $id) {
    $st->execute([$id, $projectId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $out[] = coreui_pvcs_commit_public($row);
  }
  return $out;
}

/** Everything the panel shows: working states, unsaved changes of the active one, its versions. */
function coreui_pvcs_state(PDO $pdo, int $uid, string $projectId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $vcs = $pdo->prepare('SELECT active_branch_id FROM stu_console_project_vcs WHERE project_id=?');
  $vcs->execute([$project['id']]);
  $activeId = (int)$vcs->fetchColumn();
  $st = $pdo->prepare('SELECT * FROM stu_console_project_branches WHERE project_id=? ORDER BY name');
  $st->execute([$project['id']]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  // 0.7.81: a working state whose newest version the active one already contains has nothing to merge.
  $activeHead = null;
  foreach ($rows as $row) if ((int)$row['id'] === $activeId && $row['head_commit_id'] !== null) $activeHead = (int)$row['head_commit_id'];
  $contained = coreui_pvcs_ancestors(coreui_pvcs_graph($pdo, $project['id']), $activeHead);
  $branches = [];
  $active = null;
  foreach ($rows as $row) {
    $head = $row['head_commit_id'] === null ? null : (int)$row['head_commit_id'];
    $isActive = (int)$row['id'] === $activeId;
    if ($isActive) {
      $unsaved = !coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $head), coreui_pvcs_working($pdo, $uid, $project['id']))['clean'];
    } else {
      $unsaved = (int)$row['worktree_saved'] === 1
        && !coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $head), coreui_pvcs_worktree($pdo, (int)$row['id']))['clean'];
    }
    $item = ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'head_commit_id'=>$head, 'active'=>$isActive, 'unsaved'=>$unsaved,
      'merged'=>$head === null || isset($contained[$head]), 'updated_at'=>(string)$row['updated_at']];
    $branches[] = $item;
    if ($isActive) $active = $item;
  }
  if ($active === null) throw new RuntimeException('vcs_not_ready');
  $status = coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $active['head_commit_id']), coreui_pvcs_working($pdo, $uid, $project['id']));
  $count = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id=?');
  $count->execute([$project['id']]);
  return ['active'=>$active, 'branches'=>$branches, 'status'=>$status, 'log'=>coreui_pvcs_log($pdo, $project['id'], $active['head_commit_id']),
    'limits'=>['branches'=>COREUI_PVCS_BRANCHES, 'commits'=>COREUI_PVCS_COMMITS, 'used_commits'=>(int)$count->fetchColumn()],
    'can_merge'=>coreui_pvcs_merge_ready($pdo)];
}

/** Save the project files as a new version of the active working state. */
function coreui_pvcs_commit(PDO $pdo, int $uid, string $projectId, $message, string $author = 'person'): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $message = coreui_pvcs_message($message);
  if (!in_array($author, ['person', 'mara', 'auto'], true)) throw new InvalidArgumentException('invalid_author');
  $id = coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $message, $author): int {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    $branch = coreui_pvcs_branch($pdo, $project['id'], (int)$vcs['active_branch_id']);
    $working = coreui_pvcs_working($pdo, $uid, $project['id']);
    $head = $branch['head_commit_id'] === null ? null : (int)$branch['head_commit_id'];
    if (coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $head), $working)['clean']) throw new RuntimeException('nothing_to_commit');
    $bytes = coreui_pvcs_store_working($pdo, $uid, $project['id']);
    if ($bytes > 0 && function_exists('coreui_files_room') && coreui_files_room($pdo, $uid, $bytes) !== null) throw new RuntimeException('storage_full');
    return coreui_pvcs_add_commit($pdo, $uid, $project['id'], $branch, $working, $message, $author);
  });
  return coreui_pvcs_commit_public(coreui_pvcs_commit_row($pdo, $project['id'], $id));
}

/** One version with what it changed against the version before it. */
function coreui_pvcs_commit_changes(PDO $pdo, int $uid, string $projectId, int $commitId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $row = coreui_pvcs_commit_row($pdo, $project['id'], $commitId);
  $parent = $row['parent_id'] === null ? null : (int)$row['parent_id'];
  return ['commit'=>coreui_pvcs_commit_public($row),
    'changes'=>coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $parent), coreui_pvcs_commit_files($pdo, $commitId))];
}

/** A file as it was in a version; 'work' is the project file now, '' a file that is not there. */
function coreui_pvcs_file(PDO $pdo, int $uid, string $projectId, $commit, $path): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $path = coreui_pfiles_path($path);
  if ($commit === 'work') {
    $files = coreui_pvcs_working($pdo, $uid, $project['id']);
    if (!isset($files[$path])) return ['path'=>$path, 'exists'=>false, 'content'=>''];
    $row = coreui_pfiles_row($pdo, $uid, $project['id'], $path);
    return ['path'=>$path, 'exists'=>true, 'content'=>(string)$row['content']];
  }
  $row = coreui_pvcs_commit_row($pdo, $project['id'], (int)$commit);
  $files = coreui_pvcs_commit_files($pdo, (int)$row['id']);
  if (!isset($files[$path])) return ['path'=>$path, 'exists'=>false, 'content'=>''];
  return ['path'=>$path, 'exists'=>true, 'content'=>coreui_pvcs_blob($pdo, $project['id'], $files[$path])];
}

/**
 * The project files become $target (path => sha256, contents stored). Files that change get a
 * revision of kind $source; files that are not in $target go. Returns the number of changed paths.
 */
function coreui_pvcs_apply(PDO $pdo, int $uid, string $projectId, array $target, string $source): int {
  $st = $pdo->prepare('SELECT id, path, sha256, revision FROM stu_console_project_files WHERE project_id=? AND user_id=?');
  $st->execute([$projectId, $uid]);
  $current = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $current[(string)$row['path']] = $row;
  $changed = 0;
  $dropRevisions = $pdo->prepare('DELETE FROM stu_console_project_file_revisions WHERE file_id=? AND user_id=?');
  $dropFile = $pdo->prepare('DELETE FROM stu_console_project_files WHERE id=? AND user_id=?');
  foreach ($current as $path => $row) {
    if (isset($target[$path])) continue;
    $dropRevisions->execute([$row['id'], $uid]);
    $dropFile->execute([$row['id'], $uid]);
    $changed++;
  }
  $insert = $pdo->prepare('INSERT INTO stu_console_project_files (id, project_id, user_id, path, content, size_bytes, sha256, revision,
    created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
  $update = $pdo->prepare('UPDATE stu_console_project_files SET content=?, size_bytes=?, sha256=?, revision=?, updated_at=CURRENT_TIMESTAMP
    WHERE id=? AND user_id=?');
  foreach ($target as $path => $sha) {
    $path = (string)$path;
    if (isset($current[$path]) && $current[$path]['sha256'] === $sha) continue;
    $content = coreui_pvcs_blob($pdo, $projectId, $sha);
    if (!isset($current[$path])) {
      $id = bin2hex(random_bytes(16));
      $insert->execute([$id, $projectId, $uid, $path, $content, strlen($content), $sha]);
      coreui_pfiles_add_revision($pdo, $id, $projectId, $uid, 1, $path, $content, $source, null, null);
    } else {
      $revision = (int)$current[$path]['revision'] + 1;
      $update->execute([$content, strlen($content), $sha, $revision, $current[$path]['id'], $uid]);
      coreui_pfiles_add_revision($pdo, (string)$current[$path]['id'], $projectId, $uid, $revision, $path, $content, $source, null, null);
    }
    $changed++;
  }
  return $changed;
}

/** The unsaved project files stay with the working state that is left. */
function coreui_pvcs_park(PDO $pdo, int $uid, string $projectId, array $branch): void {
  $working = coreui_pvcs_working($pdo, $uid, $projectId);
  $pdo->prepare('DELETE FROM stu_console_project_worktrees WHERE branch_id=?')->execute([(int)$branch['id']]);
  $head = $branch['head_commit_id'] === null ? null : (int)$branch['head_commit_id'];
  if (coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $head), $working)['clean']) {
    $pdo->prepare('UPDATE stu_console_project_branches SET worktree_saved=0 WHERE id=?')->execute([(int)$branch['id']]);
    return;
  }
  coreui_pvcs_store_working($pdo, $uid, $projectId);
  $insert = $pdo->prepare('INSERT INTO stu_console_project_worktrees (branch_id, path, sha256) VALUES (?, ?, ?)');
  foreach ($working as $path => $sha) $insert->execute([(int)$branch['id'], $path, $sha]);
  $pdo->prepare('UPDATE stu_console_project_branches SET worktree_saved=1 WHERE id=?')->execute([(int)$branch['id']]);
}

/** The files a working state shows when it becomes active: its kept files, else its newest version. */
function coreui_pvcs_branch_files(PDO $pdo, array $branch): array {
  if ((int)$branch['worktree_saved'] === 1) return coreui_pvcs_worktree($pdo, (int)$branch['id']);
  return coreui_pvcs_commit_files($pdo, $branch['head_commit_id'] === null ? null : (int)$branch['head_commit_id']);
}

function coreui_pvcs_activate(PDO $pdo, string $projectId, array $branch): void {
  $pdo->prepare('DELETE FROM stu_console_project_worktrees WHERE branch_id=?')->execute([(int)$branch['id']]);
  $pdo->prepare('UPDATE stu_console_project_branches SET worktree_saved=0, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$branch['id']]);
  $pdo->prepare('UPDATE stu_console_project_vcs SET active_branch_id=?, updated_at=CURRENT_TIMESTAMP WHERE project_id=?')
    ->execute([(int)$branch['id'], $projectId]);
}

/**
 * A new working state from the newest version of the active one, and it becomes active.
 * $carry: the unsaved changes move along to the new working state (the old one is left at its
 * newest version); otherwise they stay with the old one and the new one starts clean.
 */
function coreui_pvcs_branch_create(PDO $pdo, int $uid, string $projectId, $name, bool $carry): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $name = coreui_pvcs_branch_name($name);
  coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $name, $carry): void {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    $st = $pdo->prepare('SELECT name FROM stu_console_project_branches WHERE project_id=?');
    $st->execute([$project['id']]);
    $names = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (in_array($name, $names, true)) throw new RuntimeException('branch_exists');
    foreach ($names as $other) {
      // Git keeps branches as files: "a" and "a/b" cannot both exist.
      if (str_starts_with($other, $name . '/') || str_starts_with($name, $other . '/')) throw new RuntimeException('branch_exists');
    }
    if (count($names) >= COREUI_PVCS_BRANCHES) throw new RuntimeException('branch_limit');
    $from = coreui_pvcs_branch($pdo, $project['id'], (int)$vcs['active_branch_id']);
    $pdo->prepare('INSERT INTO stu_console_project_branches (project_id, user_id, name, head_commit_id, worktree_saved, created_at, updated_at)
      VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
      ->execute([$project['id'], $uid, $name, $from['head_commit_id'] === null ? null : (int)$from['head_commit_id']]);
    $new = coreui_pvcs_branch($pdo, $project['id'], (int)$pdo->lastInsertId());
    if ($carry) {
      // The old working state goes back to its newest version; the files stay as they are.
      $pdo->prepare('DELETE FROM stu_console_project_worktrees WHERE branch_id=?')->execute([(int)$from['id']]);
      $pdo->prepare('UPDATE stu_console_project_branches SET worktree_saved=0 WHERE id=?')->execute([(int)$from['id']]);
    } else {
      coreui_pvcs_park($pdo, $uid, $project['id'], $from);
      coreui_pvcs_apply($pdo, $uid, $project['id'], coreui_pvcs_branch_files($pdo, $new), 'branch');
    }
    coreui_pvcs_activate($pdo, $project['id'], $new);
  });
  return coreui_pvcs_state($pdo, $uid, $projectId);
}

/** Another working state becomes active; the unsaved files of the current one stay with it. */
function coreui_pvcs_switch(PDO $pdo, int $uid, string $projectId, int $branchId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $branchId): void {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    $target = coreui_pvcs_branch($pdo, $project['id'], $branchId);
    if ((int)$vcs['active_branch_id'] === $branchId) return;
    coreui_pvcs_park($pdo, $uid, $project['id'], coreui_pvcs_branch($pdo, $project['id'], (int)$vcs['active_branch_id']));
    coreui_pvcs_apply($pdo, $uid, $project['id'], coreui_pvcs_branch_files($pdo, $target), 'branch');
    coreui_pvcs_activate($pdo, $project['id'], $target);
  });
  return coreui_pvcs_state($pdo, $uid, $projectId);
}

/**
 * A version comes back on the active working state. Unsaved changes are saved first as a version
 * of their own; the restored state becomes a new version on top, so every earlier one stays.
 */
function coreui_pvcs_restore(PDO $pdo, int $uid, string $projectId, int $commitId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $commitId): void {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    $source = coreui_pvcs_commit_row($pdo, $project['id'], $commitId);
    $target = coreui_pvcs_commit_files($pdo, $commitId);
    $branch = coreui_pvcs_branch($pdo, $project['id'], (int)$vcs['active_branch_id']);
    $working = coreui_pvcs_working($pdo, $uid, $project['id']);
    if (coreui_pvcs_changes($working, $target)['clean']) throw new RuntimeException('already_current');
    $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id=?');
    $st->execute([$project['id']]);
    $head = $branch['head_commit_id'] === null ? null : (int)$branch['head_commit_id'];
    $unsaved = !coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $head), $working)['clean'];
    if ((int)$st->fetchColumn() + ($unsaved ? 2 : 1) > COREUI_PVCS_COMMITS) throw new RuntimeException('history_full');
    if ($unsaved) {
      coreui_pvcs_store_working($pdo, $uid, $project['id']);
      coreui_pvcs_add_commit($pdo, $uid, $project['id'], $branch, $working, 'Automatisch gesichert vor dem Wiederherstellen', 'auto');
      $branch = coreui_pvcs_branch($pdo, $project['id'], (int)$branch['id']);
    }
    coreui_pvcs_apply($pdo, $uid, $project['id'], $target, 'restore');
    $message = 'Wiederhergestellt: ' . (string)$source['message'];
    if (mb_strlen($message, 'UTF-8') > COREUI_PVCS_MESSAGE_CHARS) $message = mb_substr($message, 0, COREUI_PVCS_MESSAGE_CHARS - 1, 'UTF-8') . '…';
    coreui_pvcs_add_commit($pdo, $uid, $project['id'], $branch, $target, $message, 'person');
  });
  return coreui_pvcs_state($pdo, $uid, $projectId);
}

/** A working state that is not active goes; versions no other working state reaches go with it. */
function coreui_pvcs_branch_delete(PDO $pdo, int $uid, string $projectId, int $branchId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pfiles_atomic($pdo, static function () use ($pdo, $project, $branchId): void {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    coreui_pvcs_branch($pdo, $project['id'], $branchId);
    if ((int)$vcs['active_branch_id'] === $branchId) throw new RuntimeException('branch_active');
    $pdo->prepare('DELETE FROM stu_console_project_worktrees WHERE branch_id=?')->execute([$branchId]);
    $pdo->prepare('UPDATE stu_console_project_commits SET branch_id=NULL WHERE branch_id=?')->execute([$branchId]);
    $pdo->prepare('DELETE FROM stu_console_project_branches WHERE id=? AND project_id=?')->execute([$branchId, $project['id']]);
    coreui_pvcs_prune($pdo, $project['id']);
  });
  return coreui_pvcs_state($pdo, $uid, $projectId);
}

/** Versions no working state reaches and contents nothing refers to are removed. */
function coreui_pvcs_prune(PDO $pdo, string $projectId): void {
  $graph = coreui_pvcs_graph($pdo, $projectId);
  $heads = $pdo->prepare('SELECT head_commit_id FROM stu_console_project_branches WHERE project_id=? AND head_commit_id IS NOT NULL');
  $heads->execute([$projectId]);
  $keep = [];
  // 0.7.81: a merged version keeps both of its parents.
  foreach ($heads->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) $keep += coreui_pvcs_ancestors($graph, (int)$id);
  $drop = array_values(array_diff(array_keys($graph), array_keys($keep)));
  if ($drop !== []) {
    $marks = implode(',', array_fill(0, count($drop), '?'));
    $pdo->prepare("DELETE FROM stu_console_project_commit_files WHERE commit_id IN ($marks)")->execute($drop);
    $pdo->prepare("UPDATE stu_console_project_commits SET parent_id=NULL WHERE parent_id IN ($marks)")->execute($drop);
    if (coreui_pvcs_merge_ready($pdo)) {
      $pdo->prepare("UPDATE stu_console_project_commits SET merge_parent_id=NULL WHERE merge_parent_id IN ($marks)")->execute($drop);
    }
    $pdo->prepare("DELETE FROM stu_console_project_commits WHERE project_id=? AND id IN ($marks)")->execute([$projectId, ...$drop]);
  }
  $pdo->prepare('DELETE FROM stu_console_project_blobs WHERE project_id=? AND sha256 NOT IN (
      SELECT cf.sha256 FROM stu_console_project_commit_files cf JOIN stu_console_project_commits c ON c.id=cf.commit_id WHERE c.project_id=?)
    AND sha256 NOT IN (SELECT w.sha256 FROM stu_console_project_worktrees w JOIN stu_console_project_branches b ON b.id=w.branch_id WHERE b.project_id=?)')
    ->execute([$projectId, $projectId, $projectId]);
}

/** Everything of the versions of a project (the project is being deleted). */
function coreui_pvcs_purge(PDO $pdo, int $uid, string $projectId): void {
  if (!coreui_pvcs_ready($pdo)) return;
  $pdo->prepare('DELETE FROM stu_console_project_commit_files WHERE commit_id IN (SELECT id FROM stu_console_project_commits WHERE project_id=? AND user_id=?)')
    ->execute([$projectId, $uid]);
  $pdo->prepare('DELETE FROM stu_console_project_worktrees WHERE branch_id IN (SELECT id FROM stu_console_project_branches WHERE project_id=? AND user_id=?)')
    ->execute([$projectId, $uid]);
  foreach (['stu_console_project_vcs', 'stu_console_project_commits', 'stu_console_project_branches', 'stu_console_project_blobs'] as $table) {
    $pdo->prepare("DELETE FROM {$table} WHERE project_id=? AND user_id=?")->execute([$projectId, $uid]);
  }
}

// --- Merge (0.7.81, P11 B) -------------------------------------------------------------------------

/** The lines of a text, each with its line end (pure). */
function coreui_pvcs_lines(string $text): array {
  if ($text === '') return [];
  return preg_split('~(?<=\n)~', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Myers' shortest edit between two lists of lines (pure): the lines they have in common as
 * [[index in $a, index in $b], ...]; [] when it needs more than $maxEdits steps (or one is empty).
 */
function coreui_pvcs_myers(array $a, array $b, int $maxEdits): array {
  $n = count($a);
  $m = count($b);
  if ($n === 0 || $m === 0) return [];
  $v = [1 => 0];
  $trace = [];
  $found = false;
  for ($d = 0; $d <= min($n + $m, $maxEdits); $d++) {
    $trace[] = $v;
    for ($k = -$d; $k <= $d; $k += 2) {
      $x = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $v[$k + 1] : $v[$k - 1] + 1;
      $y = $x - $k;
      while ($x < $n && $y < $m && $a[$x] === $b[$y]) { $x++; $y++; }
      $v[$k] = $x;
      if ($x >= $n && $y >= $m) { $found = true; break 2; }
    }
  }
  if (!$found) return [];
  $pairs = [];
  $x = $n;
  $y = $m;
  for ($d = count($trace) - 1; $d >= 0; $d--) {
    $v = $trace[$d];
    $k = $x - $y;
    $prevK = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $k + 1 : $k - 1;
    $prevX = $v[$prevK];
    $prevY = $prevX - $prevK;
    while ($x > $prevX && $y > $prevY) { $x--; $y--; $pairs[] = [$x, $y]; }
    $x = $prevX;
    $y = $prevY;
  }
  return array_reverse($pairs);
}

/**
 * The lines two texts have in common, in order (pure). Equal lines at the start and at the end
 * first, the rest with Myers; when that part differs too much, it counts as changed as a whole
 * (a merge then asks instead of guessing).
 */
function coreui_pvcs_common_lines(array $a, array $b, int $maxEdits = COREUI_PVCS_DIFF_EDITS): array {
  $n = count($a);
  $m = count($b);
  $start = 0;
  while ($start < $n && $start < $m && $a[$start] === $b[$start]) $start++;
  $end = 0;
  while ($end < $n - $start && $end < $m - $start && $a[$n - 1 - $end] === $b[$m - 1 - $end]) $end++;
  $pairs = [];
  for ($i = 0; $i < $start; $i++) $pairs[] = [$i, $i];
  $middle = coreui_pvcs_myers(array_slice($a, $start, $n - $start - $end), array_slice($b, $start, $m - $start - $end), $maxEdits);
  foreach ($middle as [$i, $j]) $pairs[] = [$i + $start, $j + $start];
  for ($i = 0; $i < $end; $i++) $pairs[] = [$n - $end + $i, $m - $end + $i];
  return $pairs;
}

/**
 * Three-way merge of one text (pure). Every change of either side is taken; where both sides
 * changed the same or neighbouring lines differently, both stay between conflict markers
 * ("<<<<<<< ours", "=======", ">>>>>>> theirs"). Returns ['content', 'conflicts', 'clean'].
 */
function coreui_pvcs_merge3(string $base, string $ours, string $theirs, string $oursLabel = 'ours', string $theirsLabel = 'theirs'): array {
  $o = coreui_pvcs_lines($base);
  $a = coreui_pvcs_lines($ours);
  $b = coreui_pvcs_lines($theirs);
  $ma = [];
  foreach (coreui_pvcs_common_lines($o, $a) as [$i, $j]) $ma[$i] = $j;
  $mb = [];
  foreach (coreui_pvcs_common_lines($o, $b) as [$i, $j]) $mb[$i] = $j;
  $nl = str_contains($ours, "\r\n") ? "\r\n" : "\n";
  $block = static function (array $lines) use ($nl): string {
    $text = implode('', $lines);
    return $text === '' || str_ends_with($text, "\n") ? $text : $text . $nl;
  };
  [$no, $na, $nb] = [count($o), count($a), count($b)];
  [$lo, $la, $lb] = [0, 0, 0];
  $out = '';
  $conflicts = 0;
  while (true) {
    // Lines all three have at the same place stay.
    $i = 0;
    while ($lo + $i < $no && ($ma[$lo + $i] ?? -1) === $la + $i && ($mb[$lo + $i] ?? -1) === $lb + $i) $i++;
    if ($i > 0) {
      $out .= implode('', array_slice($o, $lo, $i));
      $lo += $i; $la += $i; $lb += $i;
      continue;
    }
    // Up to the next line both sides still have from the base, at least one side changed something.
    $next = $lo;
    while ($next < $no && !(isset($ma[$next]) && isset($mb[$next]))) $next++;
    [$ea, $eb] = $next < $no ? [$ma[$next], $mb[$next]] : [$na, $nb];
    $co = array_slice($o, $lo, $next - $lo);
    $ca = array_slice($a, $la, $ea - $la);
    $cb = array_slice($b, $lb, $eb - $lb);
    if ($co === [] && $ca === [] && $cb === []) break;
    if ($ca === $co) {
      $out .= implode('', $cb);
    } elseif ($cb === $co || $ca === $cb) {
      $out .= implode('', $ca);
    } else {
      $out .= '<<<<<<< ' . $oursLabel . $nl . $block($ca) . '=======' . $nl . $block($cb) . '>>>>>>> ' . $theirsLabel . $nl;
      $conflicts++;
    }
    [$lo, $la, $lb] = [$next, $ea, $eb];
    if ($next >= $no) break;
  }
  return ['content'=>$out, 'conflicts'=>$conflicts, 'clean'=>$conflicts === 0];
}

/** Whether a text still has the conflict markers a merge of these two working states put in (pure). */
function coreui_pvcs_has_markers(string $text, string $oursLabel, string $theirsLabel): bool {
  return preg_match('~^<{7} ' . preg_quote($oursLabel, '~') . '\r?$~m', $text) === 1
    || preg_match('~^>{7} ' . preg_quote($theirsLabel, '~') . '\r?$~m', $text) === 1;
}

/**
 * Which files a merge decides by itself (pure); $base, $ours, $theirs: path => sha256. A file only
 * one side changed takes that change; a file both changed is merged line by line ('lines'); a file
 * one side deleted and the other changed is a conflict. 'take': path => sha256 of what stays.
 */
function coreui_pvcs_merge_trees(array $base, array $ours, array $theirs): array {
  $take = [];
  $lines = [];
  $conflicts = [];
  $paths = array_values(array_unique(array_map('strval', array_merge(array_keys($base), array_keys($ours), array_keys($theirs)))));
  sort($paths, SORT_STRING);
  foreach ($paths as $path) {
    $b = $base[$path] ?? null;
    $o = $ours[$path] ?? null;
    $t = $theirs[$path] ?? null;
    if ($o === $t) { if ($o !== null) $take[$path] = $o; continue; }
    if ($o === $b) { if ($t !== null) $take[$path] = $t; continue; }
    if ($t === $b) { if ($o !== null) $take[$path] = $o; continue; }
    if ($o !== null && $t !== null) { $lines[] = $path; continue; }
    $conflicts[$path] = $o === null ? 'deleted_ours' : 'deleted_theirs';
  }
  return ['take'=>$take, 'lines'=>$lines, 'conflicts'=>$conflicts];
}

function coreui_pvcs_merge_message(string $fromName): string {
  return 'Zusammengeführt: ' . $fromName;
}

/** Everything a merge of working state $fromId into the active one would do (reads only). */
function coreui_pvcs_merge_plan(PDO $pdo, int $uid, string $projectId, int $activeId, int $fromId): array {
  $from = coreui_pvcs_branch($pdo, $projectId, $fromId);
  if ($fromId === $activeId) throw new RuntimeException('merge_same');
  $into = coreui_pvcs_branch($pdo, $projectId, $activeId);
  $oursHead = $into['head_commit_id'] === null ? null : (int)$into['head_commit_id'];
  $theirsHead = $from['head_commit_id'] === null ? null : (int)$from['head_commit_id'];
  $graph = coreui_pvcs_graph($pdo, $projectId);
  if ($theirsHead === null || isset(coreui_pvcs_ancestors($graph, $oursHead)[$theirsHead])) throw new RuntimeException('already_merged');
  $baseId = coreui_pvcs_merge_base($graph, $oursHead, $theirsHead);
  $base = coreui_pvcs_commit_files($pdo, $baseId);
  $ours = coreui_pvcs_working($pdo, $uid, $projectId);
  $theirs = coreui_pvcs_commit_files($pdo, $theirsHead);
  $tree = coreui_pvcs_merge_trees($base, $ours, $theirs);
  $texts = [];
  $merged = [];
  $conflicts = [];
  foreach ($tree['lines'] as $path) {
    $result = coreui_pvcs_merge3(isset($base[$path]) ? coreui_pvcs_blob($pdo, $projectId, $base[$path]) : '',
      (string)coreui_pfiles_row($pdo, $uid, $projectId, $path)['content'], coreui_pvcs_blob($pdo, $projectId, $theirs[$path]),
      (string)$into['name'], (string)$from['name']);
    if ($result['clean']) {
      $texts[$path] = $result['content'];
      $merged[] = $path;
    } else {
      $conflicts[$path] = ['kind'=>isset($base[$path]) ? 'content' : 'added', 'count'=>$result['conflicts'], 'merged'=>$result['content']];
    }
  }
  foreach ($tree['conflicts'] as $path => $kind) $conflicts[(string)$path] = ['kind'=>$kind, 'count'=>1, 'merged'=>null];
  ksort($conflicts, SORT_STRING);
  return ['into'=>$into, 'from'=>$from, 'ours_head'=>$oursHead, 'theirs_head'=>$theirsHead, 'base_id'=>$baseId, 'ours'=>$ours,
    'theirs'=>$theirs, 'take'=>$tree['take'], 'texts'=>$texts, 'merged'=>$merged, 'conflicts'=>$conflicts,
    'ours_tree'=>coreui_pvcs_tree_digest($ours), 'unsaved'=>!coreui_pvcs_changes(coreui_pvcs_commit_files($pdo, $oursHead), $ours)['clean']];
}

/**
 * What merging working state $fromId into the active one changes, before anything changes:
 * files taken over, files merged line by line, and the conflicts with both versions and a text
 * with conflict markers to edit. ours_tree and theirs_head go back with the merge.
 */
function coreui_pvcs_merge_preview(PDO $pdo, int $uid, string $projectId, int $fromId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  if (!coreui_pvcs_merge_ready($pdo)) throw new RuntimeException('project_merge_migration_required');
  $vcs = $pdo->prepare('SELECT active_branch_id FROM stu_console_project_vcs WHERE project_id=?');
  $vcs->execute([$project['id']]);
  $plan = coreui_pvcs_merge_plan($pdo, $uid, $project['id'], (int)$vcs->fetchColumn(), $fromId);
  $result = $plan['take'];
  foreach ($plan['texts'] as $path => $text) $result[$path] = hash('sha256', $text);
  $conflicts = [];
  foreach ($plan['conflicts'] as $path => $conflict) {
    $path = (string)$path;
    $conflicts[] = ['path'=>$path, 'kind'=>$conflict['kind'], 'count'=>$conflict['count'], 'merged'=>$conflict['merged'],
      'ours'=>isset($plan['ours'][$path]) ? (string)coreui_pfiles_row($pdo, $uid, $project['id'], $path)['content'] : null,
      'theirs'=>isset($plan['theirs'][$path]) ? coreui_pvcs_blob($pdo, $project['id'], $plan['theirs'][$path]) : null];
  }
  $from = $plan['from'];
  $fromUnsaved = (int)$from['worktree_saved'] === 1
    && !coreui_pvcs_changes($plan['theirs'], coreui_pvcs_worktree($pdo, (int)$from['id']))['clean'];
  return ['into'=>['id'=>(int)$plan['into']['id'], 'name'=>(string)$plan['into']['name']],
    'from'=>['id'=>(int)$from['id'], 'name'=>(string)$from['name'], 'unsaved'=>$fromUnsaved],
    'base_commit_id'=>$plan['base_id'], 'theirs_head'=>$plan['theirs_head'], 'ours_tree'=>$plan['ours_tree'], 'unsaved'=>$plan['unsaved'],
    'changes'=>coreui_pvcs_changes(array_diff_key($plan['ours'], $plan['conflicts']), $result), 'merged'=>$plan['merged'],
    'conflicts'=>$conflicts, 'message'=>coreui_pvcs_merge_message((string)$from['name'])];
}

/**
 * Merge working state $fromId into the active one. $opts: ours_tree and theirs_head as the
 * preview gave them (anything changed in between: 'merge_changed'), resolutions (path =>
 * ['choice' => 'ours'|'theirs'|'text', 'text' => ...]) for every conflict ('merge_unresolved'
 * otherwise) and message. Unsaved changes are saved first; the merge is a new version with the
 * other working state's newest version as its second parent. The other working state stays.
 */
function coreui_pvcs_merge(PDO $pdo, int $uid, string $projectId, int $fromId, array $opts = [], string $author = 'person'): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  if (!coreui_pvcs_merge_ready($pdo)) throw new RuntimeException('project_merge_migration_required');
  if (!in_array($author, ['person', 'mara'], true)) throw new InvalidArgumentException('invalid_author');
  $message = trim((string)($opts['message'] ?? '')) !== '' ? coreui_pvcs_message($opts['message']) : null;
  $resolutions = $opts['resolutions'] ?? [];
  if (!is_array($resolutions)) throw new InvalidArgumentException('invalid_resolution');
  $id = coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $fromId, $opts, $author, $message, $resolutions): int {
    $vcs = coreui_pvcs_lock($pdo, $project['id']);
    $plan = coreui_pvcs_merge_plan($pdo, $uid, $project['id'], (int)$vcs['active_branch_id'], $fromId);
    if ((array_key_exists('ours_tree', $opts) && (string)$opts['ours_tree'] !== $plan['ours_tree'])
        || (array_key_exists('theirs_head', $opts) && (int)$opts['theirs_head'] !== $plan['theirs_head'])) {
      throw new RuntimeException('merge_changed');
    }
    foreach (array_keys($resolutions) as $path) {
      if (!isset($plan['conflicts'][(string)$path])) throw new InvalidArgumentException('invalid_resolution');
    }
    $final = $plan['take'];
    $texts = $plan['texts'];
    foreach ($plan['conflicts'] as $path => $conflict) {
      $path = (string)$path;
      $resolution = $resolutions[$path] ?? null;
      if (!is_array($resolution)) throw new RuntimeException('merge_unresolved');
      $choice = (string)($resolution['choice'] ?? '');
      if ($choice === 'ours' || $choice === 'theirs') {
        $side = $choice === 'ours' ? $plan['ours'] : $plan['theirs'];
        if (isset($side[$path])) $final[$path] = $side[$path];
      } elseif ($choice === 'text' && is_string($resolution['text'] ?? null)) {
        $text = coreui_pfiles_text($resolution['text']);
        if (coreui_pvcs_has_markers($text, (string)$plan['into']['name'], (string)$plan['from']['name'])) {
          throw new InvalidArgumentException('conflict_markers');
        }
        $texts[$path] = $text;
      } else {
        throw new InvalidArgumentException('invalid_resolution');
      }
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id=?');
    $count->execute([$project['id']]);
    if ((int)$count->fetchColumn() + ($plan['unsaved'] ? 2 : 1) > COREUI_PVCS_COMMITS) throw new RuntimeException('history_full');
    $branch = $plan['into'];
    if ($plan['unsaved']) {
      coreui_pvcs_store_working($pdo, $uid, $project['id']);
      coreui_pvcs_add_commit($pdo, $uid, $project['id'], $branch, $plan['ours'], 'Automatisch gesichert vor dem Zusammenführen', 'auto');
      $branch = coreui_pvcs_branch($pdo, $project['id'], (int)$branch['id']);
    }
    // Merged and edited texts become stored contents.
    $has = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_blobs WHERE project_id=? AND sha256=?');
    $insert = $pdo->prepare(coreui_pvcs_insert_ignore($pdo) . ' INTO stu_console_project_blobs (project_id, sha256, user_id, content,
      size_bytes, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    $newBytes = 0;
    foreach ($texts as $path => $text) {
      $sha = hash('sha256', $text);
      $has->execute([$project['id'], $sha]);
      if ((int)$has->fetchColumn() === 0) {
        $insert->execute([$project['id'], $sha, $uid, $text, strlen($text)]);
        $newBytes += strlen($text);
      }
      $final[(string)$path] = $sha;
    }
    $sizes = $pdo->prepare('SELECT sha256, size_bytes FROM stu_console_project_blobs WHERE project_id=?');
    $sizes->execute([$project['id']]);
    $size = [];
    foreach ($sizes->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $size[$row['sha256']] = (int)$row['size_bytes'];
    $bytes = 0;
    foreach ($final as $sha) $bytes += $size[$sha] ?? 0;
    if (count($final) > COREUI_PFILES_MAX_FILES || $bytes > COREUI_PFILES_PROJECT_BYTES) throw new RuntimeException('project_full');
    if ($newBytes > 0 && function_exists('coreui_files_room') && coreui_files_room($pdo, $uid, $newBytes) !== null) {
      throw new RuntimeException('storage_full');
    }
    coreui_pvcs_apply($pdo, $uid, $project['id'], $final, 'merge');
    return coreui_pvcs_add_commit($pdo, $uid, $project['id'], $branch, $final,
      $message ?? coreui_pvcs_merge_message((string)$plan['from']['name']), $author, (int)$plan['theirs_head']);
  });
  return ['commit'=>coreui_pvcs_commit_public(coreui_pvcs_commit_row($pdo, $project['id'], $id)),
    'state'=>coreui_pvcs_state($pdo, $uid, $projectId)];
}

// --- Git -----------------------------------------------------------------------------------------

/** One Git object (pure): [sha1 hex, the zlib-compressed bytes as Git stores them loose]. */
function coreui_pvcs_git_object(string $type, string $body): array {
  $raw = $type . ' ' . strlen($body) . "\0" . $body;
  return [sha1($raw), (string)gzcompress($raw)];
}

/**
 * The trees of one set of files (pure). $files: path => blob sha1 hex. Returns the root tree's sha1
 * and every tree object (sha1 => compressed). Entries are sorted as Git sorts them (a folder as
 * its name with "/").
 */
function coreui_pvcs_git_trees(array $files): array {
  $root = [];
  foreach ($files as $path => $sha) {
    $node = &$root;
    $parts = explode('/', (string)$path);
    $leaf = array_pop($parts);
    foreach ($parts as $part) {
      if (!isset($node[$part]) || !is_array($node[$part])) $node[$part] = [];
      $node = &$node[$part];
    }
    $node[$leaf] = $sha;
    unset($node);
  }
  $objects = [];
  $build = static function (array $dir) use (&$build, &$objects): string {
    $entries = [];
    foreach ($dir as $name => $value) {
      $name = (string)$name;
      if (is_array($value)) $entries[] = [$name . '/', '40000 ' . $name . "\0" . hex2bin($build($value))];
      else $entries[] = [$name, '100644 ' . $name . "\0" . hex2bin((string)$value)];
    }
    usort($entries, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    [$sha, $data] = coreui_pvcs_git_object('tree', implode('', array_column($entries, 1)));
    $objects[$sha] = $data;
    return $sha;
  };
  $sha = $build($root);
  return [$sha, $objects];
}

/** A Git index (version 2) for $files (path => [blob sha1 hex, size]) (pure). */
function coreui_pvcs_git_index(array $files): string {
  ksort($files, SORT_STRING);
  $out = 'DIRC' . pack('NN', 2, count($files));
  foreach ($files as $path => [$sha, $size]) {
    $path = (string)$path;
    $entry = pack('NNNNNNNNNN', 0, 0, 0, 0, 0, 0, 0100644, 0, 0, (int)$size) . hex2bin($sha) . pack('n', min(strlen($path), 0xFFF)) . $path;
    $entry .= str_repeat("\0", 8 - (strlen($entry) % 8));
    $out .= $entry;
  }
  return $out . sha1($out, true);
}

/** Who a version is from in Git (pure); no personal data leaves CoreAI. */
function coreui_pvcs_git_person(string $author): string {
  return [
    'mara' => 'Mara Thorne <mara@coreai.invalid>',
    'auto' => 'CoreAI <automatisch@coreai.invalid>',
  ][$author] ?? 'CoreAI-Konto <konto@coreai.invalid>';
}

/**
 * The project as a ZIP with a Git repository: the files of the active working state at the top,
 * every working state as a branch with its versions, HEAD on the active one and an index of its
 * newest version (unsaved changes show in git status). ['path' => tmp, 'name' => download name].
 */
function coreui_pvcs_git_zip(PDO $pdo, int $uid, string $projectId): array {
  coreui_pvcs_init($pdo, $uid, $projectId);
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  if (!class_exists('ZipArchive')) throw new RuntimeException('zip_unavailable');
  $vcs = $pdo->prepare('SELECT active_branch_id FROM stu_console_project_vcs WHERE project_id=?');
  $vcs->execute([$project['id']]);
  $activeId = (int)$vcs->fetchColumn();
  $st = $pdo->prepare('SELECT * FROM stu_console_project_commits WHERE project_id=? ORDER BY id');
  $st->execute([$project['id']]);
  $commits = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $objects = [];
  $blobSha = [];
  $gitCommit = [];
  $blobOf = static function (string $sha256) use ($pdo, $project, &$objects, &$blobSha): array {
    if (!isset($blobSha[$sha256])) {
      $content = coreui_pvcs_blob($pdo, $project['id'], $sha256);
      [$sha, $data] = coreui_pvcs_git_object('blob', $content);
      $objects[$sha] = $data;
      $blobSha[$sha256] = [$sha, strlen($content)];
    }
    return $blobSha[$sha256];
  };
  foreach ($commits as $row) {
    $files = [];
    foreach (coreui_pvcs_commit_files($pdo, (int)$row['id']) as $path => $sha256) $files[$path] = $blobOf($sha256)[0];
    [$tree, $trees] = coreui_pvcs_git_trees($files);
    $objects += $trees;
    $when = (int)strtotime((string)$row['created_at']);
    $person = coreui_pvcs_git_person((string)$row['author']);
    $body = 'tree ' . $tree . "\n";
    if ($row['parent_id'] !== null && isset($gitCommit[(int)$row['parent_id']])) $body .= 'parent ' . $gitCommit[(int)$row['parent_id']] . "\n";
    // 0.7.81: a merge has the merged working state's version as its second parent.
    $merge = $row['merge_parent_id'] ?? null;
    if ($merge !== null && isset($gitCommit[(int)$merge])) $body .= 'parent ' . $gitCommit[(int)$merge] . "\n";
    $body .= 'author ' . $person . ' ' . $when . " +0000\n" . 'committer ' . $person . ' ' . $when . " +0000\n\n" . (string)$row['message'] . "\n";
    [$sha, $data] = coreui_pvcs_git_object('commit', $body);
    $objects[$sha] = $data;
    $gitCommit[(int)$row['id']] = $sha;
  }
  $st = $pdo->prepare('SELECT * FROM stu_console_project_branches WHERE project_id=? ORDER BY name');
  $st->execute([$project['id']]);
  $branches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $files = $pdo->prepare('SELECT path, content FROM stu_console_project_files WHERE project_id=? AND user_id=? ORDER BY path');
  $files->execute([$project['id'], $uid]);
  $working = $files->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $tmp = tempnam(sys_get_temp_dir(), 'coreui-git-');
  if ($tmp === false) throw new RuntimeException('zip_failed');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  foreach ($working as $row) $zip->addFromString((string)$row['path'], (string)$row['content']);
  $activeName = COREUI_PVCS_MAIN;
  $index = null;
  foreach ($branches as $branch) {
    if ((int)$branch['id'] === $activeId) {
      $activeName = (string)$branch['name'];
      if ($branch['head_commit_id'] !== null) {
        $entries = [];
        foreach (coreui_pvcs_commit_files($pdo, (int)$branch['head_commit_id']) as $path => $sha256) $entries[$path] = $blobOf($sha256);
        $index = coreui_pvcs_git_index($entries);
      }
    }
    if ($branch['head_commit_id'] !== null && isset($gitCommit[(int)$branch['head_commit_id']])) {
      $zip->addFromString('.git/refs/heads/' . $branch['name'], $gitCommit[(int)$branch['head_commit_id']] . "\n");
    }
  }
  $zip->addFromString('.git/HEAD', 'ref: refs/heads/' . $activeName . "\n");
  $zip->addFromString('.git/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = false\n\tbare = false\n\tlogallrefupdates = true\n");
  $zip->addFromString('.git/description', "CoreAI: TCI Interface project export\n");
  $zip->addEmptyDir('.git/refs/tags');
  $zip->addEmptyDir('.git/objects/info');
  $zip->addEmptyDir('.git/objects/pack');
  if ($index !== null) $zip->addFromString('.git/index', $index);
  foreach ($objects as $sha => $data) $zip->addFromString('.git/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2), $data);
  if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  $name = coreui_pfiles_zip_name((string)$project['name']);
  return ['path'=>$tmp, 'name'=>substr($name, 0, -4) . '-git.zip', 'commits'=>count($commits)];
}
