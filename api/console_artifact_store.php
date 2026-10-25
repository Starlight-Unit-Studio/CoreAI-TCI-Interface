<?php
declare(strict_types=1);

/** Generated outputs are registered from trusted worker results, never from model links. */
require_once __DIR__ . '/console_plan_store.php';

function coreui_console_artifact_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,user_id,session_id,message_id,job_id,storage_key,orig_name,mime_type,file_size,sha256,partial FROM stu_console_artifacts LIMIT 0');
    $pdo->query('SELECT session_id,trigger_message_id,trigger_revision,artifacts_json FROM stu_ember_py_jobs LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_console_artifact_root(): string {
  $work = defined('STU_EMBER_PY_WORKDIR') ? (string)STU_EMBER_PY_WORKDIR : dirname(__DIR__) . '/var/ember_py';
  if ($work === '') $work = dirname(__DIR__) . '/var/ember_py';
  if ($work[0] !== '/') $work = dirname(__DIR__) . '/' . $work;
  return rtrim($work, '/') . '/artifacts';
}

function coreui_console_artifact_storage_key_valid(string $key): bool {
  return preg_match('~^[1-9][0-9]{0,9}/[a-f0-9]{32}$~D', $key) === 1;
}

function coreui_console_artifact_path(string $key): string {
  if (!coreui_console_artifact_storage_key_valid($key)) throw new RuntimeException('artifact_invalid_path');
  $root = coreui_console_artifact_root();
  $job = $root . '/' . explode('/', $key, 2)[0];
  $path = $root . '/' . $key;
  clearstatcache(true);
  if (is_link($root) || is_link($job) || is_link($path)) throw new RuntimeException('artifact_invalid_path');
  $base = realpath($root);
  $real = realpath($path);
  if ($base === false || $real === false) throw new RuntimeException('artifact_file_missing');
  if ($real !== $base . '/' . $key) throw new RuntimeException('artifact_invalid_path');
  return $real;
}

/** Validate and rewind the very handle which the download endpoint will stream. */
function coreui_console_artifact_open_verified(array $row) {
  $path = coreui_console_artifact_path((string)($row['storage_key'] ?? ''));
  $expectedSize = $row['file_size'] ?? $row['size_bytes'] ?? -1;
  $expectedHash = (string)($row['sha256'] ?? '');
  if (!is_numeric($expectedSize) || (int)$expectedSize < 0 || (int)$expectedSize > 25 * 1024 * 1024
      || !preg_match('~^[a-f0-9]{64}$~D', $expectedHash)) throw new RuntimeException('artifact_integrity_failed');
  $before = @lstat($path);
  if (!$before || ($before['mode'] & 0170000) !== 0100000 || (int)$before['nlink'] !== 1) {
    throw new RuntimeException('artifact_invalid_path');
  }
  $fh = @fopen($path, 'rb');
  if ($fh === false) throw new RuntimeException('artifact_file_missing');
  try {
    $opened = fstat($fh);
    if (!$opened || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
        || ($opened['mode'] & 0170000) !== 0100000 || (int)$opened['nlink'] !== 1
        || (int)$opened['size'] !== (int)$expectedSize) throw new RuntimeException('artifact_integrity_failed');
    $hash = hash_init('sha256');
    $bytes = 0;
    while (!feof($fh)) {
      $part = fread($fh, 262144);
      if ($part === false || ($part === '' && !feof($fh))) throw new RuntimeException('artifact_integrity_failed');
      $bytes += strlen($part);
      if ($bytes > (int)$expectedSize) throw new RuntimeException('artifact_integrity_failed');
      hash_update($hash, $part);
    }
    clearstatcache(true, $path);
    $after = @lstat($path);
    if (!$after || $after['dev'] !== $opened['dev'] || $after['ino'] !== $opened['ino']
        || ($after['mode'] & 0170000) !== 0100000 || (int)$after['nlink'] !== 1
        || (int)$after['size'] !== $bytes || $bytes !== (int)$expectedSize
        || !hash_equals($expectedHash, hash_final($hash))) throw new RuntimeException('artifact_integrity_failed');
    if (fseek($fh, 0) !== 0) throw new RuntimeException('artifact_integrity_failed');
    return $fh;
  } catch (Throwable $e) { fclose($fh); throw $e; }
}

function coreui_console_artifact_validate_manifest(array $job): array {
  $raw = $job['artifacts_json'] ?? null;
  if ($raw === null || $raw === '') return [];
  $manifest = json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR);
  if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
      || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])
      || count($manifest['files']) > 10) throw new RuntimeException('artifact_invalid_manifest');
  $files = [];
  $seen = [];
  $total = 0;
  $jobPrefix = (string)(int)($job['id'] ?? 0) . '/';
  foreach ($manifest['files'] as $file) {
    if (!is_array($file) || !is_string($file['storage_key'] ?? null) || !is_string($file['name'] ?? null)
        || !is_int($file['size_bytes'] ?? null) || !is_string($file['sha256'] ?? null)
        || !is_string($file['mime_type'] ?? null) || !is_bool($file['partial'] ?? null)) {
      throw new RuntimeException('artifact_invalid_manifest');
    }
    $key = $file['storage_key'];
    $name = $file['name'];
    if (!coreui_console_artifact_storage_key_valid($key) || !str_starts_with($key, $jobPrefix)
        || isset($seen[$key]) || $name === '' || $name === '.' || $name === '..' || strlen($name) > 255
        || preg_match('//u', $name) !== 1 || preg_match('~[\\\\/\x00-\x1f\x7f]~', $name)
        || $file['size_bytes'] < 0 || $file['size_bytes'] > 25 * 1024 * 1024
        || !preg_match('~^[a-f0-9]{64}$~D', $file['sha256'])
        || strlen($file['mime_type']) > 128
        || !preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $file['mime_type'])) {
      throw new RuntimeException('artifact_invalid_manifest');
    }
    $total += $file['size_bytes'];
    if ($total > 100 * 1024 * 1024) throw new RuntimeException('artifact_invalid_manifest');
    $fh = coreui_console_artifact_open_verified($file);
    fclose($fh);
    $seen[$key] = true;
    $files[] = [
      'storage_key'=>$key, 'name'=>$name, 'size_bytes'=>$file['size_bytes'],
      'sha256'=>$file['sha256'], 'mime_type'=>$file['mime_type'],
      'partial'=>$file['partial'] || ($job['status'] ?? '') === 'error',
    ];
  }
  return $files;
}

function coreui_console_artifact_revision(PDO $pdo, int $triggerId, bool $lock = false): int {
  // A locking read sees the current revision even inside an older repeatable-read transaction.
  $st = $pdo->prepare('SELECT revision_no FROM stu_console_message_revisions WHERE message_id=? ORDER BY revision_no DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([$triggerId]);
  return (int)($st->fetchColumn() ?: 0);
}

function coreui_console_artifact_require_turn(PDO $pdo, int $uid, string $sessionId, int $triggerId, int $revision, bool $lock = false): void {
  if ($uid <= 0 || $triggerId <= 0 || $revision < 0) throw new RuntimeException('artifact_context_changed');
  $st = $pdo->prepare('SELECT id FROM stu_console_sessions WHERE id=? AND user_id=?' . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([$sessionId, $uid]);
  if (!$st->fetchColumn()) throw new RuntimeException('artifact_context_changed');
  $st = $pdo->prepare("SELECT id FROM stu_chat_messages WHERE id=? AND user_id=? AND session_id=? AND channel='console' AND deleted_at IS NULL" . ($lock ? ' FOR UPDATE' : ''));
  $st->execute([$triggerId, $uid, $sessionId]);
  if (!$st->fetchColumn() || coreui_console_artifact_revision($pdo, $triggerId, $lock) !== $revision) {
    throw new RuntimeException('artifact_context_changed');
  }
}

function coreui_console_artifact_prepare_job(PDO $pdo, int $uid, string $sessionId, int $triggerId, int $revision, int $jobId): array {
  if (!coreui_console_artifact_schema_ready($pdo)) throw new RuntimeException('artifact_migration_required');
  coreui_console_artifact_require_turn($pdo, $uid, $sessionId, $triggerId, $revision);
  $st = $pdo->prepare("SELECT * FROM stu_ember_py_jobs WHERE id=? AND channel='console' AND recipient_uid=? AND session_id=? AND trigger_message_id=? AND trigger_revision=? AND status IN ('done','error')");
  $st->execute([$jobId, $uid, $sessionId, $triggerId, $revision]);
  $job = $st->fetch(PDO::FETCH_ASSOC);
  if (!$job) throw new RuntimeException('artifact_context_changed');
  return ['uid'=>$uid, 'session_id'=>$sessionId, 'trigger_id'=>$triggerId, 'revision'=>$revision,
    'job_id'=>$jobId, 'files'=>coreui_console_artifact_validate_manifest($job)];
}

function coreui_console_artifact_pending(int $uid, string $sessionId, int $triggerId): ?array {
  $pending = $GLOBALS['COREUI_PENDING_ARTIFACTS'] ?? null;
  return is_array($pending) && ($pending['uid'] ?? null) === $uid && ($pending['session_id'] ?? null) === $sessionId
    && ($pending['trigger_id'] ?? null) === $triggerId && !empty($pending['files']) ? $pending : null;
}

/** The message and every output share the caller's transaction. */
function coreui_console_artifact_attach(PDO $pdo, int $uid, string $sessionId, int $triggerId, int $messageId, array $pending): void {
  if (!$pdo->inTransaction()) throw new LogicException('artifact_transaction_required');
  if (($pending['uid'] ?? null) !== $uid || ($pending['session_id'] ?? null) !== $sessionId
      || ($pending['trigger_id'] ?? null) !== $triggerId) throw new RuntimeException('artifact_context_changed');
  $revision = (int)($pending['revision'] ?? -1);
  coreui_console_artifact_require_turn($pdo, $uid, $sessionId, $triggerId, $revision, true);
  $st = $pdo->prepare("SELECT * FROM stu_ember_py_jobs WHERE id=? AND recipient_uid=? AND session_id=? AND trigger_message_id=? AND trigger_revision=? AND channel='console' AND status IN ('done','error') FOR UPDATE");
  $st->execute([(int)$pending['job_id'], $uid, $sessionId, $triggerId, $revision]);
  $job = $st->fetch(PDO::FETCH_ASSOC);
  if (!$job) throw new RuntimeException('artifact_context_changed');
  $st = $pdo->prepare("SELECT id FROM stu_chat_messages WHERE id=? AND user_id=? AND session_id=? AND reply_to_id=? AND channel='console' AND deleted_at IS NULL FOR UPDATE");
  $st->execute([$messageId, $uid, $sessionId, $triggerId]);
  if (!$st->fetchColumn()) throw new RuntimeException('artifact_context_changed');
  $files = coreui_console_artifact_validate_manifest($job);
  if ($files !== ($pending['files'] ?? null)) throw new RuntimeException('artifact_context_changed');
  $ins = $pdo->prepare('INSERT INTO stu_console_artifacts (id,user_id,session_id,message_id,job_id,producer,storage_key,orig_name,mime_type,file_size,sha256,partial,created_at) VALUES (?,?,?,?,?,\'python\',?,?,?,?,?,?,NOW())');
  foreach ($files as $file) {
    $ins->execute([bin2hex(random_bytes(16)), $uid, $sessionId, $messageId, (int)$job['id'],
      $file['storage_key'], $file['name'], $file['mime_type'], $file['size_bytes'], $file['sha256'], (int)$file['partial']]);
  }
}

/** Ownership and revision must remain valid for history, export and direct downloads alike. */
function coreui_console_artifact_select_sql(): string {
  return "SELECT a.* FROM stu_console_artifacts a
    JOIN stu_console_sessions s ON s.id=a.session_id AND s.user_id=a.user_id
    JOIN stu_chat_messages m ON m.id=a.message_id AND m.user_id=a.user_id AND m.session_id=a.session_id
      AND m.channel='console' AND m.deleted_at IS NULL
    JOIN stu_ember_py_jobs j ON j.id=a.job_id AND j.recipient_uid=a.user_id AND j.session_id=a.session_id
      AND j.channel='console' AND j.status IN ('done','error') AND j.trigger_message_id=m.reply_to_id
    JOIN stu_chat_messages t ON t.id=j.trigger_message_id AND t.user_id=a.user_id AND t.session_id=a.session_id
      AND t.channel='console' AND t.deleted_at IS NULL
    WHERE j.trigger_revision=(SELECT COALESCE(MAX(r.revision_no),0) FROM stu_console_message_revisions r WHERE r.message_id=t.id)";
}

function coreui_console_artifact_public(array $row): array {
  $available = false;
  try {
    $path = coreui_console_artifact_path((string)$row['storage_key']);
    $stat = @lstat($path);
    $available = $stat && ($stat['mode'] & 0170000) === 0100000 && (int)$stat['nlink'] === 1
      && (int)$stat['size'] === (int)$row['file_size'];
  } catch (Throwable $e) {}
  $url = 'api/console_artifact.php?id=' . rawurlencode((string)$row['id']);
  if (function_exists('stu_public_path')) $url = stu_public_path($url);
  return ['id'=>(string)$row['id'], 'name'=>(string)$row['orig_name'], 'mime_type'=>(string)$row['mime_type'],
    'size_bytes'=>(int)$row['file_size'], 'sha256'=>(string)$row['sha256'], 'partial'=>(bool)$row['partial'],
    'available'=>(bool)$available, 'download_url'=>$url];
}

function coreui_console_artifact_map(PDO $pdo, int $uid, array $messageIds): array {
  if ($uid <= 0 || $messageIds === [] || !coreui_console_artifact_schema_ready($pdo)) return [];
  $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));
  $map = [];
  foreach (array_chunk($ids, 1000) as $chunk) {
    $st = $pdo->prepare(coreui_console_artifact_select_sql() . ' AND a.user_id=? AND a.message_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY a.message_id,a.created_at,a.id');
    $st->execute(array_merge([$uid], $chunk));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $map[(int)$row['message_id']][] = coreui_console_artifact_public($row);
  }
  // Native Markdown plans are durable downloadable files as well, but
  // never enter the sandbox artifact table or its Python-job associations.
  foreach (coreui_plan_artifact_map($pdo, $uid, $ids) as $messageId=>$plans) {
    foreach ($plans as $plan) $map[(int)$messageId][] = $plan;
  }
  return $map;
}

function coreui_console_artifact_for_message(PDO $pdo, int $uid, int $messageId): array {
  return coreui_console_artifact_map($pdo, $uid, [$messageId])[$messageId] ?? [];
}

function coreui_console_artifact_delete_files(array $keys): array {
  $result = ['artifact_files_deleted'=>0, 'artifact_files_missing'=>0, 'artifact_files_failed'=>0];
  foreach (array_unique($keys) as $key) {
    try {
      $path = coreui_console_artifact_path((string)$key);
      $stat = @lstat($path);
      if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || (int)$stat['nlink'] !== 1) throw new RuntimeException('artifact_invalid_path');
      if (!@unlink($path)) throw new RuntimeException('artifact_delete_failed');
      $result['artifact_files_deleted']++;
      @rmdir(dirname($path));
    } catch (Throwable $e) {
      $result[$e->getMessage() === 'artifact_file_missing' ? 'artifact_files_missing' : 'artifact_files_failed']++;
    }
  }
  return $result;
}
