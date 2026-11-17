<?php
declare(strict_types=1);

/*
 * 0.7.82 (P11 C): the lifetime of the files Mara made (stu_console_artifacts).
 *
 * - Deleting a file leaves a note in its chat message (stu_console_artifact_tombstones, migration
 *   051): name, size, checksum, who or what deleted it and when. The message that pointed at the
 *   file keeps saying what happened instead of losing it silently.
 * - The owner can let files be deleted after 30, 90, 180 or 365 days (app setting
 *   artifact_retention_days, Admin Core > Betrieb; 0 = never, the default). A file the person keeps
 *   (a favourite in Settings > Speicher, "Behalten" in the chat) and a file in a project chat are
 *   never deleted by the retention; every other file shows the day it will go.
 * - The sweep runs when an account looks at its files (chat cards, Settings > Speicher), at most
 *   COREUI_ARTIFACT_SWEEP_BATCH files at a time, and only with the favourites table (migration 039),
 *   so nobody loses a file they could not have kept.
 */
require_once __DIR__ . '/console_artifact_store.php';

const COREUI_ARTIFACT_RETENTION_CHOICES = [0, 30, 90, 180, 365];
const COREUI_ARTIFACT_SWEEP_BATCH = 100;

function coreui_artifact_lifecycle_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT artifact_id, user_id, session_id, message_id, reason, deleted_at FROM stu_console_artifact_tombstones LIMIT 0');
      $memo[$key] = coreui_console_artifact_schema_ready($pdo);
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

function coreui_artifact_keep_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT user_id, item_key FROM stu_user_file_favorites LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

function coreui_artifact_projects_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT s.project_id, p.name FROM stu_console_sessions s JOIN stu_console_projects p ON p.id = s.project_id LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** The retention in days (pure): one of COREUI_ARTIFACT_RETENTION_CHOICES, anything else is 0 (never). */
function coreui_artifact_retention_normalise($value): int {
  $days = is_numeric($value) ? (int)$value : 0;
  return in_array($days, COREUI_ARTIFACT_RETENTION_CHOICES, true) ? $days : 0;
}

function coreui_artifact_retention_days(PDO $pdo): int {
  if (!function_exists('stu_app_setting_get')) return 0;
  return coreui_artifact_retention_normalise(stu_app_setting_get($pdo, 'artifact_retention_days', '0'));
}

/** When the retention deletes a file (pure): 'Y-m-d H:i:s', or null when it stays. */
function coreui_artifact_expires_at(string $createdAt, int $days, bool $kept, bool $inProject): ?string {
  if ($days <= 0 || $kept || $inProject) return null;
  try {
    return (new DateTimeImmutable($createdAt))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

/** Short form of a checksum for people (pure): the first 12 characters. */
function coreui_artifact_version(string $sha256): string {
  return preg_match('~^[a-f0-9]{64}$~D', $sha256) === 1 ? substr($sha256, 0, 12) : '';
}

function coreui_artifact_atomic(PDO $pdo, callable $work) {
  $outer = $pdo->inTransaction();
  if ($outer) $pdo->exec('SAVEPOINT coreui_artifact'); else $pdo->beginTransaction();
  try {
    $result = $work();
    if ($outer) $pdo->exec('RELEASE SAVEPOINT coreui_artifact'); else $pdo->commit();
    return $result;
  } catch (Throwable $e) {
    if ($outer) $pdo->exec('ROLLBACK TO SAVEPOINT coreui_artifact'); elseif ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Kept files and project chats of an account for some files: ['kept' => id => true, 'project' => session_id => name]. */
function coreui_artifact_context(PDO $pdo, int $uid, array $ids, array $sessionIds): array {
  $kept = [];
  if ($ids !== [] && coreui_artifact_keep_ready($pdo)) {
    $keys = array_map(static fn(string $id): string => 'a:' . $id, array_values(array_unique($ids)));
    $st = $pdo->prepare('SELECT item_key FROM stu_user_file_favorites WHERE user_id = ? AND item_key IN ('
      . implode(',', array_fill(0, count($keys), '?')) . ')');
    $st->execute([$uid, ...$keys]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $key) $kept[substr((string)$key, 2)] = true;
  }
  $project = [];
  $sessionIds = array_values(array_unique(array_filter(array_map('strval', $sessionIds))));
  if ($sessionIds !== [] && coreui_artifact_projects_ready($pdo)) {
    $st = $pdo->prepare('SELECT s.id, p.name FROM stu_console_sessions s JOIN stu_console_projects p ON p.id = s.project_id AND p.user_id = s.user_id
      WHERE s.user_id = ? AND s.id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ')');
    $st->execute([$uid, ...$sessionIds]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $project[(string)$row['id']] = (string)$row['name'];
  }
  return ['kept'=>$kept, 'project'=>$project];
}

/**
 * Deletes one file of the account for good and leaves a note in its chat message. $reason:
 * 'person' (deleted by the person) or 'retention'. Returns ['freed' => bytes, 'name' => file name].
 */
function coreui_artifact_delete(PDO $pdo, int $uid, string $id, string $reason = 'person'): array {
  if (!in_array($reason, ['person', 'retention'], true)) throw new InvalidArgumentException('invalid_reason');
  if (preg_match('~^[a-f0-9]{32}$~D', $id) !== 1) throw new InvalidArgumentException('artifact_not_found');
  $row = coreui_artifact_atomic($pdo, static function () use ($pdo, $uid, $id, $reason): array {
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $st = $pdo->prepare('SELECT * FROM stu_console_artifacts WHERE id = ? AND user_id = ?' . $lock);
    $st->execute([$id, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('artifact_not_found');
    if (coreui_artifact_lifecycle_ready($pdo)) {
      $pdo->prepare('INSERT INTO stu_console_artifact_tombstones (artifact_id, user_id, session_id, message_id, orig_name, mime_type, file_size,
        sha256, reason, created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$id, $uid, (string)$row['session_id'], (int)$row['message_id'], (string)$row['orig_name'], (string)$row['mime_type'],
          (int)$row['file_size'], (string)$row['sha256'], $reason, (string)$row['created_at']]);
    }
    $pdo->prepare('DELETE FROM stu_console_artifacts WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    if (coreui_artifact_keep_ready($pdo)) {
      $pdo->prepare('DELETE FROM stu_user_file_favorites WHERE user_id = ? AND item_key = ?')->execute([$uid, 'a:' . $id]);
    }
    return $row;
  });
  // The file goes after the entry: a failed unlink leaves an orphan file, never an entry without a file.
  coreui_console_artifact_delete_files([(string)$row['storage_key']]);
  return ['freed'=>(int)$row['file_size'], 'name'=>(string)$row['orig_name']];
}

/**
 * Deletes the account's files the retention has run out for: older than the set days, not kept,
 * not in a project chat. Returns how many went (at most COREUI_ARTIFACT_SWEEP_BATCH per call).
 */
function coreui_artifact_sweep(PDO $pdo, int $uid): int {
  $days = coreui_artifact_retention_days($pdo);
  if ($uid <= 0 || $days <= 0 || !coreui_artifact_lifecycle_ready($pdo) || !coreui_artifact_keep_ready($pdo)) return 0;
  $now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
  $cutoff = (new DateTimeImmutable($now))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
  $projects = coreui_artifact_projects_ready($pdo);
  $sql = 'SELECT a.id FROM stu_console_artifacts a'
    . ($projects ? ' LEFT JOIN stu_console_sessions s ON s.id = a.session_id AND s.user_id = a.user_id' : '')
    . ' WHERE a.user_id = ? AND a.created_at < ?'
    . ' AND NOT EXISTS (SELECT 1 FROM stu_user_file_favorites f WHERE f.user_id = a.user_id AND f.item_key = ' . coreui_artifact_concat_key($pdo) . ')'
    . ($projects ? ' AND s.project_id IS NULL' : '')
    . ' ORDER BY a.created_at, a.id LIMIT ' . COREUI_ARTIFACT_SWEEP_BATCH;
  $st = $pdo->prepare($sql);
  $st->execute([$uid, $cutoff]);
  $gone = 0;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
    try {
      coreui_artifact_delete($pdo, $uid, (string)$id, 'retention');
      $gone++;
    } catch (InvalidArgumentException $e) {
      // Deleted meanwhile (another tab): nothing to do.
    }
  }
  return $gone;
}

/** 'a:' || a.id as the database writes it. */
function coreui_artifact_concat_key(PDO $pdo): string {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "CONCAT('a:', a.id)" : "('a:' || a.id)";
}

/** The notes of deleted files for chat messages of the account: message_id => [card, ...]. */
function coreui_artifact_tombstones(PDO $pdo, int $uid, array $messageIds): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));
  if ($uid <= 0 || $ids === [] || !coreui_artifact_lifecycle_ready($pdo)) return [];
  $map = [];
  foreach (array_chunk($ids, 500) as $chunk) {
    $st = $pdo->prepare('SELECT * FROM stu_console_artifact_tombstones WHERE user_id = ? AND message_id IN ('
      . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY message_id, created_at, artifact_id');
    $st->execute([$uid, ...$chunk]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $map[(int)$row['message_id']][] = ['id'=>(string)$row['artifact_id'], 'name'=>(string)$row['orig_name'],
        'mime_type'=>(string)$row['mime_type'], 'size_bytes'=>(int)$row['file_size'], 'sha256'=>(string)$row['sha256'],
        'version'=>coreui_artifact_version((string)$row['sha256']), 'partial'=>false, 'available'=>false, 'deleted'=>true,
        'deleted_reason'=>(string)$row['reason'], 'deleted_at'=>(string)$row['deleted_at']];
    }
  }
  return $map;
}

/**
 * The cards of chat messages with their lifetime: every file gets its version, whether it is kept,
 * its project and the day the retention deletes it; deleted files come back as notes.
 */
function coreui_artifact_cards(PDO $pdo, int $uid, array $messageIds): array {
  $map = coreui_console_artifact_map($pdo, $uid, $messageIds);
  $ids = [];
  foreach ($map as $files) foreach ($files as $file) if (($file['kind'] ?? '') !== 'plan') $ids[] = (string)$file['id'];
  $rows = [];
  if ($ids !== []) {
    $st = $pdo->prepare('SELECT id, session_id, created_at FROM stu_console_artifacts WHERE user_id = ? AND id IN ('
      . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute([$uid, ...$ids]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $rows[(string)$row['id']] = $row;
  }
  $context = coreui_artifact_context($pdo, $uid, array_keys($rows), array_column($rows, 'session_id'));
  $days = coreui_artifact_retention_days($pdo);
  foreach ($map as $messageId => $files) {
    foreach ($files as $i => $file) {
      $row = $rows[(string)$file['id']] ?? null;
      if ($row === null) continue;
      $kept = isset($context['kept'][(string)$file['id']]);
      $project = $context['project'][(string)$row['session_id']] ?? '';
      $map[$messageId][$i] += ['version'=>coreui_artifact_version((string)$file['sha256']), 'kept'=>$kept, 'project'=>$project,
        'created_at'=>(string)$row['created_at'], 'expires_at'=>coreui_artifact_expires_at((string)$row['created_at'], $days, $kept, $project !== '')];
    }
  }
  foreach (coreui_artifact_tombstones($pdo, $uid, $messageIds) as $messageId => $notes) {
    foreach ($notes as $note) $map[$messageId][] = $note;
  }
  return $map;
}
