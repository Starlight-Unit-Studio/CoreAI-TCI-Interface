<?php
declare(strict_types=1);

/**
 * 0.7.69 (P09): the files of a chat project. Text files only, stored in the
 * database with every earlier state as a revision. Builds in a project chat
 * land here, the account edits single files, and the current state leaves as
 * a ZIP. Nothing here is executed, and nothing is ever served as HTML.
 */
require_once __DIR__ . '/console_project_store.php';
require_once __DIR__ . '/files_store.php';

const COREUI_PFILES_FILE_BYTES = 512 * 1024;
const COREUI_PFILES_MAX_FILES = 200;
const COREUI_PFILES_PROJECT_BYTES = 10 * 1024 * 1024;
const COREUI_PFILES_KEEP_REVISIONS = 20;
const COREUI_PFILES_ZIP_ENTRIES = 300;

function coreui_pfiles_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id, project_id, user_id, path, content, size_bytes, sha256, revision, updated_at FROM stu_console_project_files LIMIT 0');
    $pdo->query('SELECT file_id, revision, path, content, size_bytes, sha256, source, session_id, message_id, created_at FROM stu_console_project_file_revisions LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_pfiles_limits(): array {
  return ['file_bytes'=>COREUI_PFILES_FILE_BYTES, 'files'=>COREUI_PFILES_MAX_FILES,
    'project_bytes'=>COREUI_PFILES_PROJECT_BYTES, 'revisions'=>COREUI_PFILES_KEEP_REVISIONS];
}

/** Text formats a project keeps. Images and other binary files stay in the chat. */
function coreui_pfiles_extensions(): array {
  return ['html','htm','css','js','mjs','cjs','jsx','ts','tsx','vue','svelte','json','txt','md','markdown',
    'svg','py','csv','tsv','xml','yml','yaml','toml','ini','cfg','conf','sql','sh','php','java','kt','c','h',
    'cpp','hpp','cs','go','rs','rb','lua','gd','swift','dart','r','tex','log','gitignore'];
}

/**
 * A safe relative path: folders and a file name of letters, digits, space and
 * "._-()+", no "." or ".." parts, at most eight levels and 200 characters,
 * and a known text extension. Throws InvalidArgumentException otherwise.
 */
function coreui_pfiles_path($value): string {
  $path = str_replace('\\', '/', trim((string)$value));
  $path = (string)preg_replace('~/{2,}~', '/', $path);
  while (str_starts_with($path, './')) $path = substr($path, 2);
  $path = trim($path, '/');
  if ($path === '' || preg_match('//u', $path) !== 1 || mb_strlen($path, 'UTF-8') > 200) {
    throw new InvalidArgumentException('invalid_path');
  }
  $parts = explode('/', $path);
  if (count($parts) > 8) throw new InvalidArgumentException('invalid_path');
  foreach ($parts as $part) {
    if ($part === '.' || $part === '..' || $part !== trim($part)
        || preg_match('~^[\p{L}\p{N}_.][\p{L}\p{N}_. ()+-]{0,79}$~uD', $part) !== 1) {
      throw new InvalidArgumentException('invalid_path');
    }
  }
  $name = strtolower((string)end($parts));
  $ext = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : '';
  if ($ext === '' || !in_array($ext, coreui_pfiles_extensions(), true)) {
    throw new InvalidArgumentException('unsupported_type');
  }
  return $path;
}

/** Valid UTF-8 text without NUL bytes, at most COREUI_PFILES_FILE_BYTES. */
function coreui_pfiles_text(string $bytes): string {
  if (strlen($bytes) > COREUI_PFILES_FILE_BYTES) throw new InvalidArgumentException('file_too_large');
  if (str_contains($bytes, "\0") || preg_match('//u', $bytes) !== 1) throw new InvalidArgumentException('not_text');
  return $bytes;
}

function coreui_pfiles_lock_sql(PDO $pdo): string {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function coreui_pfiles_require(PDO $pdo, int $uid, string $projectId): array {
  $project = coreui_project_require($pdo, $uid, $projectId);
  if (!coreui_pfiles_schema_ready($pdo)) throw new RuntimeException('project_files_migration_required');
  return $project;
}

function coreui_pfiles_public(array $row): array {
  return ['path'=>(string)$row['path'], 'size_bytes'=>(int)$row['size_bytes'], 'sha256'=>(string)$row['sha256'],
    'revision'=>(int)$row['revision'], 'updated_at'=>(string)$row['updated_at']];
}

/** Files of a project, sorted by path, with the project's totals. */
function coreui_pfiles_list(PDO $pdo, int $uid, string $projectId): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $st = $pdo->prepare('SELECT path, size_bytes, sha256, revision, updated_at FROM stu_console_project_files '
    . 'WHERE project_id=? AND user_id=? ORDER BY path');
  $st->execute([$project['id'], $uid]);
  $files = array_map('coreui_pfiles_public', $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
  $bytes = 0;
  foreach ($files as $file) $bytes += $file['size_bytes'];
  return ['files'=>$files, 'count'=>count($files), 'bytes'=>$bytes, 'limits'=>coreui_pfiles_limits()];
}

function coreui_pfiles_row(PDO $pdo, int $uid, string $projectId, string $path, bool $lock = false): ?array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_files WHERE project_id=? AND user_id=? AND path=? LIMIT 1'
    . ($lock ? coreui_pfiles_lock_sql($pdo) : ''));
  $st->execute([$projectId, $uid, $path]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/** One file with its content and the list of its revisions (newest first). */
function coreui_pfiles_get(PDO $pdo, int $uid, string $projectId, string $path): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $path = coreui_pfiles_path($path);
  $row = coreui_pfiles_row($pdo, $uid, $project['id'], $path);
  if ($row === null) throw new RuntimeException('file_not_found');
  $st = $pdo->prepare('SELECT revision, path, size_bytes, sha256, source, created_at FROM stu_console_project_file_revisions '
    . 'WHERE file_id=? AND user_id=? ORDER BY revision DESC');
  $st->execute([$row['id'], $uid]);
  $revisions = array_map(static fn(array $r): array => ['revision'=>(int)$r['revision'], 'path'=>(string)$r['path'],
    'size_bytes'=>(int)$r['size_bytes'], 'sha256'=>(string)$r['sha256'], 'source'=>(string)$r['source'],
    'created_at'=>(string)$r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
  return coreui_pfiles_public($row) + ['content'=>(string)$row['content'], 'revisions'=>$revisions];
}

/** The content of one earlier state of a file. */
function coreui_pfiles_revision(PDO $pdo, int $uid, string $projectId, string $path, int $revision): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $row = coreui_pfiles_row($pdo, $uid, $project['id'], coreui_pfiles_path($path));
  if ($row === null) throw new RuntimeException('file_not_found');
  $st = $pdo->prepare('SELECT revision, path, content, size_bytes, sha256, source, created_at FROM stu_console_project_file_revisions '
    . 'WHERE file_id=? AND user_id=? AND revision=? LIMIT 1');
  $st->execute([$row['id'], $uid, $revision]);
  $rev = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($rev)) throw new RuntimeException('revision_not_found');
  return ['revision'=>(int)$rev['revision'], 'path'=>(string)$rev['path'], 'content'=>(string)$rev['content'],
    'size_bytes'=>(int)$rev['size_bytes'], 'sha256'=>(string)$rev['sha256'], 'source'=>(string)$rev['source'],
    'created_at'=>(string)$rev['created_at']];
}

/** Runs $work inside the caller's transaction (as a savepoint) or its own. */
function coreui_pfiles_atomic(PDO $pdo, callable $work) {
  $outer = $pdo->inTransaction();
  if ($outer) $pdo->exec('SAVEPOINT coreui_pfiles'); else $pdo->beginTransaction();
  try {
    $result = $work();
    if ($outer) $pdo->exec('RELEASE SAVEPOINT coreui_pfiles'); else $pdo->commit();
    return $result;
  } catch (Throwable $e) {
    if ($outer) $pdo->exec('ROLLBACK TO SAVEPOINT coreui_pfiles'); elseif ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Room in the project (files and bytes) and in the account's storage for one write. */
function coreui_pfiles_check_room(PDO $pdo, int $uid, string $projectId, ?array $current, int $newBytes): void {
  $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS bytes FROM stu_console_project_files WHERE project_id=? AND user_id=?');
  $st->execute([$projectId, $uid]);
  $totals = $st->fetch(PDO::FETCH_ASSOC) ?: ['n'=>0, 'bytes'=>0];
  $count = (int)$totals['n'] + ($current === null ? 1 : 0);
  $bytes = (int)$totals['bytes'] - ($current === null ? 0 : (int)$current['size_bytes']) + $newBytes;
  if ($count > COREUI_PFILES_MAX_FILES || $bytes > COREUI_PFILES_PROJECT_BYTES) throw new RuntimeException('project_full');
  // Every saved state stays as a revision, so a write adds its full size to the account's storage.
  if (function_exists('coreui_files_room') && coreui_files_room($pdo, $uid, $newBytes) !== null) {
    throw new RuntimeException('storage_full');
  }
}

function coreui_pfiles_add_revision(PDO $pdo, string $fileId, string $projectId, int $uid, int $revision, string $path,
    string $content, string $source, ?string $sessionId, ?int $messageId): void {
  $st = $pdo->prepare('INSERT INTO stu_console_project_file_revisions (file_id, project_id, user_id, revision, path, content, '
    . 'size_bytes, sha256, source, session_id, message_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
  $st->execute([$fileId, $projectId, $uid, $revision, $path, $content, strlen($content), hash('sha256', $content), $source,
    $sessionId, $messageId]);
  // Keep the newest revisions only; the current state is always one of them.
  $prune = $pdo->prepare('DELETE FROM stu_console_project_file_revisions WHERE file_id=? AND user_id=? AND revision<=?');
  $prune->execute([$fileId, $uid, $revision - COREUI_PFILES_KEEP_REVISIONS]);
}

/**
 * Write one file. $opts: base_sha256 (the state the editor started from; a
 * different current state is a conflict), create_only (the path must be new),
 * session_id and message_id (where a build came from). Returns the file and
 * whether anything changed; the same content writes nothing.
 */
function coreui_pfiles_write(PDO $pdo, int $uid, string $projectId, string $path, string $content,
    string $source, array $opts = []): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  // 0.7.73: "mara" is a change Mara made with her file tools in a project chat.
  if (!in_array($source, ['build', 'create', 'edit', 'restore', 'mara'], true)) throw new InvalidArgumentException('invalid_source');
  $path = coreui_pfiles_path($path);
  $content = coreui_pfiles_text($content);
  $sha = hash('sha256', $content);
  return coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $path, $content, $sha, $source, $opts): array {
    $row = coreui_pfiles_row($pdo, $uid, $project['id'], $path, true);
    if ($row !== null && !empty($opts['create_only'])) throw new RuntimeException('file_exists');
    if ($row === null && $source === 'edit') throw new RuntimeException('file_not_found');
    if ($row !== null && isset($opts['base_sha256']) && (string)$opts['base_sha256'] !== (string)$row['sha256']) {
      throw new RuntimeException('file_changed');
    }
    if ($row !== null && hash_equals((string)$row['sha256'], $sha)) {
      return ['file'=>coreui_pfiles_public($row), 'changed'=>false];
    }
    coreui_pfiles_check_room($pdo, $uid, $project['id'], $row, strlen($content));
    $sessionId = isset($opts['session_id']) && is_string($opts['session_id']) ? $opts['session_id'] : null;
    $messageId = isset($opts['message_id']) && (int)$opts['message_id'] > 0 ? (int)$opts['message_id'] : null;
    if ($row === null) {
      $id = bin2hex(random_bytes(16));
      $st = $pdo->prepare('INSERT INTO stu_console_project_files (id, project_id, user_id, path, content, size_bytes, sha256, '
        . 'revision, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
      $st->execute([$id, $project['id'], $uid, $path, $content, strlen($content), $sha]);
      coreui_pfiles_add_revision($pdo, $id, $project['id'], $uid, 1, $path, $content,
        in_array($source, ['build', 'mara'], true) ? $source : 'create', $sessionId, $messageId);
    } else {
      $id = (string)$row['id'];
      $revision = (int)$row['revision'] + 1;
      $st = $pdo->prepare('UPDATE stu_console_project_files SET content=?, size_bytes=?, sha256=?, revision=?, '
        . 'updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
      $st->execute([$content, strlen($content), $sha, $revision, $id, $uid]);
      coreui_pfiles_add_revision($pdo, $id, $project['id'], $uid, $revision, $path, $content,
        $source === 'create' ? 'edit' : $source, $sessionId, $messageId);
    }
    return ['file'=>coreui_pfiles_public(coreui_pfiles_row($pdo, $uid, $project['id'], $path)), 'changed'=>true];
  });
}

/** Rename or move a file; its history goes with it. */
function coreui_pfiles_rename(PDO $pdo, int $uid, string $projectId, string $path, string $newPath): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $path = coreui_pfiles_path($path);
  $newPath = coreui_pfiles_path($newPath);
  if ($path === $newPath) throw new InvalidArgumentException('same_path');
  return coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $path, $newPath): array {
    $row = coreui_pfiles_row($pdo, $uid, $project['id'], $path, true);
    if ($row === null) throw new RuntimeException('file_not_found');
    if (coreui_pfiles_row($pdo, $uid, $project['id'], $newPath) !== null) throw new RuntimeException('file_exists');
    $revision = (int)$row['revision'] + 1;
    $st = $pdo->prepare('UPDATE stu_console_project_files SET path=?, revision=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
    $st->execute([$newPath, $revision, $row['id'], $uid]);
    coreui_pfiles_add_revision($pdo, (string)$row['id'], $project['id'], $uid, $revision, $newPath, (string)$row['content'],
      'rename', null, null);
    return coreui_pfiles_public(coreui_pfiles_row($pdo, $uid, $project['id'], $newPath));
  });
}

/** Delete a file and its history. */
function coreui_pfiles_delete(PDO $pdo, int $uid, string $projectId, string $path): bool {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $path = coreui_pfiles_path($path);
  return coreui_pfiles_atomic($pdo, static function () use ($pdo, $uid, $project, $path): bool {
    $row = coreui_pfiles_row($pdo, $uid, $project['id'], $path, true);
    if ($row === null) throw new RuntimeException('file_not_found');
    $pdo->prepare('DELETE FROM stu_console_project_file_revisions WHERE file_id=? AND user_id=?')->execute([$row['id'], $uid]);
    $pdo->prepare('DELETE FROM stu_console_project_files WHERE id=? AND user_id=?')->execute([$row['id'], $uid]);
    return true;
  });
}

/** Bring back an earlier state as the newest one; the history stays complete. */
function coreui_pfiles_restore(PDO $pdo, int $uid, string $projectId, string $path, int $revision): array {
  $old = coreui_pfiles_revision($pdo, $uid, $projectId, $path, $revision);
  return coreui_pfiles_write($pdo, $uid, $projectId, $path, $old['content'], 'restore');
}

/** Every file and revision of a project (the project is being deleted). */
function coreui_pfiles_purge(PDO $pdo, int $uid, string $projectId): void {
  if (!coreui_pfiles_schema_ready($pdo)) return;
  // 0.7.80 (P11): the versions and working states go too (MariaDB also removes them by cascade).
  if (function_exists('coreui_pvcs_purge')) coreui_pvcs_purge($pdo, $uid, $projectId);
  $pdo->prepare('DELETE FROM stu_console_project_file_revisions WHERE project_id=? AND user_id=?')->execute([$projectId, $uid]);
  $pdo->prepare('DELETE FROM stu_console_project_files WHERE project_id=? AND user_id=?')->execute([$projectId, $uid]);
}

/** Paths and lines that contain $query (case-insensitive), at most 50 files and three lines each. */
function coreui_pfiles_search(PDO $pdo, int $uid, string $projectId, string $query): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $query = trim((string)preg_replace('~\s+~u', ' ', $query));
  if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 100) throw new InvalidArgumentException('invalid_query');
  $like = '%' . strtr($query, ['!'=>'!!', '%'=>'!%', '_'=>'!_']) . '%';
  $st = $pdo->prepare("SELECT path, content FROM stu_console_project_files WHERE project_id=? AND user_id=? "
    . "AND (path LIKE ? ESCAPE '!' OR content LIKE ? ESCAPE '!') ORDER BY path LIMIT 50");
  $st->execute([$project['id'], $uid, $like, $like]);
  $results = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $lines = [];
    foreach (preg_split('~\R~u', (string)$row['content']) ?: [] as $i => $line) {
      if (mb_stripos($line, $query, 0, 'UTF-8') === false) continue;
      $text = trim($line);
      $lines[] = ['line'=>$i + 1, 'text'=>mb_strlen($text, 'UTF-8') > 160 ? mb_substr($text, 0, 159, 'UTF-8') . '…' : $text];
      if (count($lines) === 3) break;
    }
    $inPath = mb_stripos((string)$row['path'], $query, 0, 'UTF-8') !== false;
    if ($lines === [] && !$inPath) continue;
    $results[] = ['path'=>(string)$row['path'], 'in_path'=>$inPath, 'lines'=>$lines];
  }
  return $results;
}

/** A file name for the download: the project name in plain letters. */
function coreui_pfiles_zip_name(string $projectName): string {
  $name = strtr($projectName, ['ä'=>'ae', 'ö'=>'oe', 'ü'=>'ue', 'Ä'=>'Ae', 'Ö'=>'Oe', 'Ü'=>'Ue', 'ß'=>'ss']);
  $name = trim((string)preg_replace('~[^A-Za-z0-9._-]+~', '-', $name), '-.');
  return ($name === '' ? 'projekt' : substr($name, 0, 60)) . '.zip';
}

/** The current state as a ZIP in a temporary file: ['path' => tmp, 'name' => download name, 'count' => n]. */
function coreui_pfiles_zip(PDO $pdo, int $uid, string $projectId): array {
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  if (!class_exists('ZipArchive')) throw new RuntimeException('zip_unavailable');
  $st = $pdo->prepare('SELECT path, content FROM stu_console_project_files WHERE project_id=? AND user_id=? ORDER BY path');
  $st->execute([$project['id'], $uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) throw new RuntimeException('no_files');
  $tmp = tempnam(sys_get_temp_dir(), 'coreui-project-');
  if ($tmp === false) throw new RuntimeException('zip_failed');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  foreach ($rows as $row) $zip->addFromString((string)$row['path'], (string)$row['content']);
  if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('zip_failed'); }
  return ['path'=>$tmp, 'name'=>coreui_pfiles_zip_name((string)$project['name']), 'count'=>count($rows)];
}

/** Bytes and count the project files hold for the account's storage (Settings > Speicher). */
function coreui_pfiles_usage(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_pfiles_schema_ready($pdo)) return ['count'=>0, 'bytes'=>0];
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_files WHERE user_id=?');
  $st->execute([$uid]);
  $count = (int)$st->fetchColumn();
  $st = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM stu_console_project_file_revisions WHERE user_id=?');
  $st->execute([$uid]);
  $bytes = (int)$st->fetchColumn();
  // 0.7.80 (P11): the contents kept for versions count too (each content once per project).
  try {
    $st = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM stu_console_project_blobs WHERE user_id=?');
    $st->execute([$uid]);
    $bytes += (int)$st->fetchColumn();
  } catch (Throwable $e) {
    // Without migration 049 there are no versions.
  }
  return ['count'=>$count, 'bytes'=>$bytes];
}

/**
 * The text entries of a ZIP: relative path => bytes. Directories, macOS
 * leftovers, unsafe names and anything bigger than one project file are left
 * out (their names in $skipped). One common top folder is removed.
 */
function coreui_pfiles_zip_entries(string $zipPath, array &$skipped): array {
  $entries = [];
  if (!class_exists('ZipArchive')) { $skipped[] = ['name'=>basename($zipPath), 'reason'=>'zip_unavailable']; return []; }
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) { $skipped[] = ['name'=>basename($zipPath), 'reason'=>'zip_unreadable']; return []; }
  try {
    $names = [];
    for ($i = 0; $i < min($zip->numFiles, COREUI_PFILES_ZIP_ENTRIES); $i++) {
      $stat = $zip->statIndex($i);
      if (!is_array($stat)) continue;
      $name = str_replace('\\', '/', (string)$stat['name']);
      if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')
          || basename($name) === '.DS_Store') continue;
      if ((int)$stat['size'] > COREUI_PFILES_FILE_BYTES) { $skipped[] = ['name'=>$name, 'reason'=>'file_too_large']; continue; }
      $names[$i] = $name;
    }
    if ($zip->numFiles > COREUI_PFILES_ZIP_ENTRIES) $skipped[] = ['name'=>basename($zipPath), 'reason'=>'too_many_entries'];
    // A ZIP that wraps everything in one folder ("projekt/index.html") keeps the files at the top.
    $tops = array_unique(array_map(static fn(string $n): string => str_contains($n, '/') ? explode('/', $n, 2)[0] : '', $names));
    $strip = count($tops) === 1 && reset($tops) !== '' ? reset($tops) . '/' : '';
    foreach ($names as $i => $name) {
      $relative = $strip !== '' ? substr($name, strlen($strip)) : $name;
      try {
        $path = coreui_pfiles_path($relative);
        $bytes = $zip->getFromIndex($i, COREUI_PFILES_FILE_BYTES + 1);
        if (!is_string($bytes)) throw new InvalidArgumentException('zip_unreadable');
        $entries[$path] = coreui_pfiles_text($bytes);
      } catch (InvalidArgumentException $e) {
        $skipped[] = ['name'=>$name, 'reason'=>$e->getMessage()];
      }
    }
  } finally {
    $zip->close();
  }
  return $entries;
}

/**
 * Write the entries of one build into the project (path => bytes). Same
 * content writes nothing; a full project or storage stops the rest honestly.
 * Returns ['saved' => [paths], 'unchanged' => n, 'skipped' => [[name, reason]]].
 */
function coreui_pfiles_import_entries(PDO $pdo, int $uid, string $projectId, array $entries,
    ?string $sessionId = null, ?int $messageId = null, array $skipped = []): array {
  $saved = [];
  $unchanged = 0;
  foreach ($entries as $path => $bytes) {
    try {
      $result = coreui_pfiles_write($pdo, $uid, $projectId, (string)$path, (string)$bytes, 'build',
        ['session_id'=>$sessionId, 'message_id'=>$messageId]);
      if ($result['changed']) $saved[] = $result['file']['path']; else $unchanged++;
    } catch (InvalidArgumentException $e) {
      $skipped[] = ['name'=>(string)$path, 'reason'=>$e->getMessage()];
    } catch (RuntimeException $e) {
      if (!in_array($e->getMessage(), ['project_full', 'storage_full'], true)) throw $e;
      $skipped[] = ['name'=>(string)$path, 'reason'=>$e->getMessage()];
    }
  }
  return ['saved'=>$saved, 'unchanged'=>$unchanged, 'skipped'=>$skipped];
}

/**
 * Explicitly promote verified download artifacts from one owned assistant
 * message into a real project workspace. This does not run a command or trust
 * user/model-provided file paths; the database and hashes are authoritative.
 * The owning chat moves only after the operator presses the import action.
 */
function coreui_pfiles_promote_chat_artifacts(PDO $pdo, int $uid, int $messageId, string $name): array {
  if ($uid <= 0 || $messageId <= 0) throw new InvalidArgumentException('invalid_message');
  if (!coreui_pfiles_schema_ready($pdo) || !coreui_console_artifact_schema_ready($pdo)) {
    throw new RuntimeException('artifact_migration_required');
  }
  $name = coreui_project_name($name);
  $message = $pdo->prepare("SELECT m.session_id FROM stu_chat_messages m
    JOIN stu_console_sessions s ON s.id=m.session_id AND s.user_id=m.user_id
    WHERE m.id=? AND m.user_id=? AND m.channel='console'
      AND m.reply_to_id IS NOT NULL AND m.deleted_at IS NULL LIMIT 1");
  $message->execute([$messageId,$uid]);
  $sessionId = $message->fetchColumn();
  if (!is_string($sessionId) || $sessionId === '') throw new RuntimeException('artifact_not_found');

  $st = $pdo->prepare(coreui_console_artifact_select_sql()
    . " AND a.user_id=? AND a.message_id=? ORDER BY a.created_at,a.id");
  $st->execute([$uid,$messageId]);
  $records = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($records === []) throw new RuntimeException('no_artifacts');
  $files = [];
  foreach ($records as $row) {
    if (!empty($row['partial'])) continue;
    $verified = [
      'storage_key'=>(string)$row['storage_key'],
      'name'=>(string)$row['orig_name'],
      'size_bytes'=>(int)$row['file_size'],
      'sha256'=>(string)$row['sha256'],
      'partial'=>false
    ];
    $fh = coreui_console_artifact_open_verified($verified);
    fclose($fh);
    $files[] = $verified;
  }
  if ($files === []) throw new RuntimeException('no_complete_artifacts');

  $ownTransaction = !$pdo->inTransaction();
  if ($ownTransaction) $pdo->beginTransaction();
  else $pdo->exec('SAVEPOINT coreui_promote_artifacts');
  try {
    $scope = coreui_project_session_scope($pdo,$uid,$sessionId);
    if (empty($scope['available'])) throw new RuntimeException('project_not_found');
    if (($scope['id'] ?? '') !== '') {
      $project = coreui_project_require($pdo,$uid,(string)$scope['id']);
    } else {
      $project = coreui_project_create($pdo,$uid,$name,'open');
      coreui_project_assign($pdo,$uid,$sessionId,(string)$project['id']);
    }
    $imported = coreui_pfiles_import_build($pdo,$uid,$sessionId,$messageId,$files);
    if (!is_array($imported) || ($imported['saved'] === [] && (int)$imported['unchanged'] === 0)) {
      throw new RuntimeException('no_usable_project_files');
    }
    if ($ownTransaction) $pdo->commit();
    else $pdo->exec('RELEASE SAVEPOINT coreui_promote_artifacts');
    return ['project_id'=>(string)$project['id'],'project_name'=>(string)$project['name'],
      'session_id'=>$sessionId,'saved'=>$imported['saved'],'unchanged'=>(int)$imported['unchanged'],
      'skipped'=>$imported['skipped']];
  } catch (Throwable $e) {
    if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
    elseif (!$ownTransaction) $pdo->exec('ROLLBACK TO SAVEPOINT coreui_promote_artifacts');
    throw $e;
  }
}

/**
 * After a Python job in a project chat: its text files and the text files in
 * its ZIPs become project files (loose files win over the same path in a ZIP).
 * $files are the verified artifact entries of the turn (storage_key, name,
 * size_bytes, sha256, partial). Chats outside a project are left alone.
 */
function coreui_pfiles_import_build(PDO $pdo, int $uid, string $sessionId, int $messageId, array $files): ?array {
  if ($uid <= 0 || $files === [] || !function_exists('coreui_console_artifact_open_verified')
      || !coreui_pfiles_schema_ready($pdo)) return null;
  $scope = coreui_project_session_scope($pdo, $uid, $sessionId);
  if (empty($scope['available']) || ($scope['id'] ?? '') === '') return null;
  $entries = [];
  $loose = [];
  $skipped = [];
  foreach ($files as $file) {
    $name = (string)($file['name'] ?? '');
    if (!empty($file['partial'])) { $skipped[] = ['name'=>$name, 'reason'=>'partial']; continue; }
    try {
      $handle = coreui_console_artifact_open_verified($file);
      try {
        if (str_ends_with(strtolower($name), '.zip')) {
          fclose($handle);
          $handle = null;
          $entries += coreui_pfiles_zip_entries(coreui_console_artifact_path((string)$file['storage_key']), $skipped);
          continue;
        }
        $path = coreui_pfiles_path($name);
        if ((int)($file['size_bytes'] ?? 0) > COREUI_PFILES_FILE_BYTES) throw new InvalidArgumentException('file_too_large');
        $loose[$path] = coreui_pfiles_text((string)stream_get_contents($handle, COREUI_PFILES_FILE_BYTES + 1));
      } finally {
        if (is_resource($handle)) fclose($handle);
      }
    } catch (InvalidArgumentException $e) {
      $skipped[] = ['name'=>$name, 'reason'=>$e->getMessage()];
    } catch (RuntimeException $e) {
      $skipped[] = ['name'=>$name, 'reason'=>'artifact_unavailable'];
    }
  }
  foreach ($loose as $path => $bytes) $entries[$path] = $bytes;
  if ($entries === [] && $skipped === []) return null;
  $result = coreui_pfiles_import_entries($pdo, $uid, (string)$scope['id'], $entries, $sessionId, $messageId, $skipped);
  if (function_exists('coreui_work_record') && $result['saved'] !== []) {
    $n = count($result['saved']);
    coreui_work_record($pdo, 'artifact', 'success', $n === 1 ? '1 Datei im Projekt gespeichert' : $n . ' Dateien im Projekt gespeichert',
      ['source'=>'project_files', 'count'=>$n, 'skipped'=>count($result['skipped'])]);
  }
  return $result + ['project_id'=>(string)$scope['id']];
}
