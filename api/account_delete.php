<?php
declare(strict_types=1);

/*
 * 0.5.84: deleting an account under Admin Core > Benutzer.
 *
 * Everything the account owns goes: chats (with their messages, attachments,
 * files, browser and Python jobs, work events, plans), uploads, knowledge,
 * Mara's memories about it, profile and avatars, settings, Core releases,
 * logins. The database part is one transaction (all or nothing); files are
 * removed only after the commit, and only under the known storage folders.
 * Who may delete whom is decided by coreui_account_delete_refusal().
 */

require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/knowledge_store.php';
require_once __DIR__ . '/profile_store.php';

/**
 * Why the actor may not delete the target ('' = allowed). Pure, so the rules
 * are tested without a database: never oneself, never an Inhaber (that also
 * covers Mara's system account), an Admin only Nutzer and Gaeste.
 */
function coreui_account_delete_refusal(int $actorId, int $actorLevel, array $target): string {
  $targetId = (int)($target['id'] ?? 0);
  $level = (int)($target['permission_level'] ?? 4);
  if ($targetId <= 0) return 'invalid_user';
  if ($targetId === $actorId) return 'self_delete_blocked';
  if ($targetId === (int)(defined('STU_EMBER_USER_ID') ? STU_EMBER_USER_ID : 1) || $level === 0) return 'protected_account';
  if ($actorLevel > 0 && $level <= $actorLevel) return 'protected_account';
  if ($actorLevel > 1) return 'insufficient_permissions';
  return '';
}

/** The typed confirmation must name the account (its e-mail, or its id for accounts without one). */
function coreui_account_delete_confirmed(array $target, string $typed): bool {
  $typed = mb_strtolower(trim($typed), 'UTF-8');
  $name = mb_strtolower(trim((string)($target['username'] ?? '')), 'UTF-8');
  if ($name === '') $name = (string)(int)($target['id'] ?? 0);
  return $typed !== '' && hash_equals($name, $typed);
}

/** Deletes rows; a table or column an installation does not have counts as nothing to delete. */
function coreui_account_delete_rows(PDO $pdo, string $sql, array $params): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
  } catch (PDOException $e) {
    $state = (string)$e->getCode();
    if ($state === '42S02' || $state === '42S22' || str_contains($e->getMessage(), 'no such table')) return 0;
    throw $e;
  }
}

function coreui_account_delete_column(PDO $pdo, string $sql, array $params): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  } catch (PDOException $e) {
    $state = (string)$e->getCode();
    if ($state === '42S02' || $state === '42S22' || str_contains($e->getMessage(), 'no such table')) return [];
    throw $e;
  }
}

/**
 * The database part, inside a transaction the caller started (the self test
 * runs it and rolls back). Returns counts and the files to remove afterwards.
 */
function coreui_account_delete_tx(PDO $pdo, int $uid): array {
  if (!$pdo->inTransaction()) throw new LogicException('delete_transaction_required');
  $st = $pdo->prepare('SELECT id, username, permission_level FROM stu_users WHERE id = ? LIMIT 1 FOR UPDATE');
  $st->execute([$uid]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($user)) throw new RuntimeException('user_not_found');
  if ((int)$user['permission_level'] === 0 || $uid === (int)(defined('STU_EMBER_USER_ID') ? STU_EMBER_USER_ID : 1)) {
    throw new RuntimeException('protected_account');
  }

  // Work still running for this account (outside its chats) must finish first.
  $busy = coreui_account_delete_column($pdo,
    "SELECT id FROM stu_ember_browse_jobs WHERE recipient_uid = ? AND status IN ('queued','running') LIMIT 1", [$uid]);
  $busy = array_merge($busy, coreui_account_delete_column($pdo,
    "SELECT id FROM stu_ember_py_jobs WHERE recipient_uid = ? AND status IN ('queued','running') LIMIT 1", [$uid]));
  $busy = array_merge($busy, coreui_account_delete_column($pdo,
    "SELECT queue_id FROM stu_console_model_queue WHERE user_id = ? AND status IN ('queued','running') LIMIT 1", [$uid]));
  if ($busy !== []) throw new RuntimeException('account_busy');

  $counts = ['sessions_deleted' => 0, 'messages_deleted' => 0, 'knowledge_sources_deleted' => 0, 'memories_deleted' => 0];
  $artifactKeys = [];
  $mediaPaths = [];

  // Chats: the tested chat deletion, one by one (it refuses a chat that is busy).
  $pdo->prepare('UPDATE stu_console_sessions SET archived_at = NOW() WHERE user_id = ? AND archived_at IS NULL')->execute([$uid]);
  foreach (coreui_account_delete_column($pdo, 'SELECT id FROM stu_console_sessions WHERE user_id = ? ORDER BY id', [$uid]) as $sessionId) {
    try {
      $result = coreui_console_session_delete_tx($pdo, $uid, $sessionId);
    } catch (RuntimeException $e) {
      if ($e->getMessage() === 'session_busy') throw new RuntimeException('account_busy');
      throw $e;
    }
    $counts['sessions_deleted']++;
    $counts['messages_deleted'] += (int)($result['messages_deleted'] ?? 0);
    $artifactKeys = array_merge($artifactKeys, is_array($result['artifact_keys'] ?? null) ? $result['artifact_keys'] : []);
    $mediaPaths = array_merge($mediaPaths, is_array($result['file_paths'] ?? null) ? $result['file_paths'] : []);
  }

  // Browser and Python jobs outside a chat.
  foreach (coreui_account_delete_column($pdo, 'SELECT id FROM stu_ember_browse_jobs WHERE recipient_uid = ?', [$uid]) as $jobId) {
    $mediaPaths[] = 'uploads/ember_browse/job_' . (int)$jobId . '.png';
    coreui_account_delete_rows($pdo, 'DELETE FROM stu_ember_browse_frames WHERE job_id = ?', [(int)$jobId]);
    coreui_account_delete_rows($pdo, 'DELETE FROM stu_ember_browse_steps WHERE job_id = ?', [(int)$jobId]);
  }
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_ember_browse_jobs WHERE recipient_uid = ?', [$uid]);
  $artifactKeys = array_merge($artifactKeys, coreui_account_delete_column($pdo,
    'SELECT storage_key FROM stu_console_artifacts WHERE user_id = ?', [$uid]));
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_ember_py_jobs WHERE recipient_uid = ?', [$uid]);

  // Uploads that never reached a chat, knowledge, avatars, old profile photos.
  $mediaPaths = array_merge($mediaPaths, coreui_account_delete_column($pdo, 'SELECT rel_path FROM stu_console_media WHERE user_id = ?', [$uid]));
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_console_media WHERE user_id = ?', [$uid]);
  $knowledgeFiles = coreui_account_delete_column($pdo, 'SELECT stored_name FROM stu_user_knowledge_sources WHERE user_id = ?', [$uid]);
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_user_knowledge_chunks WHERE user_id = ?', [$uid]);
  $counts['knowledge_sources_deleted'] = coreui_account_delete_rows($pdo, 'DELETE FROM stu_user_knowledge_sources WHERE user_id = ?', [$uid]);
  $profileFiles = coreui_account_delete_column($pdo, 'SELECT stored_name FROM stu_coreui_profile_media WHERE user_id = ?', [$uid]);
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_coreui_profile_media WHERE user_id = ?', [$uid]);
  $photoPaths = [];
  foreach (['pending_relpath', 'approved_relpath'] as $column) {
    $photoPaths = array_merge($photoPaths, coreui_account_delete_column($pdo,
      "SELECT {$column} FROM stu_profile_photos WHERE user_id = ? AND {$column} IS NOT NULL AND {$column} <> ''", [$uid]));
  }
  coreui_account_delete_rows($pdo, 'DELETE FROM stu_profile_photos WHERE user_id = ?', [$uid]);

  // Mara's memories about the account and its characters; global facts it
  // once added stay for everyone, only without the link to the account.
  $counts['memories_deleted'] = coreui_account_delete_rows($pdo,
    "DELETE FROM ember_memories WHERE (scope = 'user' AND user_id = ?) "
    . "OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))", [$uid, $uid]);
  coreui_account_delete_rows($pdo, "UPDATE ember_memories SET user_id = NULL WHERE user_id = ?", [$uid]);
  foreach ([
    'DELETE FROM stu_console_model_queue WHERE user_id = ?',
    'DELETE FROM stu_chat_reactions WHERE user_id = ?',
    'DELETE FROM stu_chat_presence WHERE user_id = ?',
    'DELETE FROM stu_chat_media WHERE uploader_user_id = ?',
    'DELETE FROM stu_ember_reputation WHERE user_id = ?',
    'DELETE FROM stu_user_log WHERE user_id = ?',
    'DELETE FROM stu_mail_participants WHERE user_id = ?',
    'DELETE FROM stu_password_resets WHERE user_id = ?',
    'DELETE FROM stu_kv WHERE user_id = ?',
    'DELETE FROM stu_characters WHERE user_id = ?',
  ] as $sql) {
    coreui_account_delete_rows($pdo, $sql, [$uid]);
  }
  $counts['messages_deleted'] += coreui_account_delete_rows($pdo, 'DELETE FROM stu_chat_messages WHERE user_id = ?', [$uid]);

  // The account itself; settings, profile, logins, releases and the rest
  // follow by ON DELETE CASCADE.
  $del = $pdo->prepare('DELETE FROM stu_users WHERE id = ? AND permission_level <> 0');
  $del->execute([$uid]);
  if ($del->rowCount() !== 1) throw new RuntimeException('user_delete_conflict');

  return $counts + [
    'username' => (string)($user['username'] ?? ''),
    'permission_level' => (int)$user['permission_level'],
    'artifact_keys' => array_values(array_unique($artifactKeys)),
    'media_paths' => array_values(array_unique($mediaPaths)),
    'knowledge_files' => $knowledgeFiles,
    'profile_files' => $profileFiles,
    'photo_paths' => $photoPaths,
  ];
}

/** Removes files by bare name from one folder (no paths, no symlinks out). */
function coreui_account_delete_named_files(string $dir, array $names): int {
  $deleted = 0;
  $base = @realpath($dir);
  if ($base === false) return 0;
  foreach (array_unique($names) as $name) {
    $name = basename(trim((string)$name));
    if ($name === '' || $name === '.' || $name === '..') continue;
    $real = @realpath($base . '/' . $name);
    if ($real !== false && dirname($real) === $base && is_file($real) && @unlink($real)) $deleted++;
  }
  return $deleted;
}

/** The whole deletion: database first (all or nothing), then the files. */
function coreui_account_delete(PDO $pdo, int $uid): array {
  if ($pdo->inTransaction()) throw new LogicException('delete_nested_transaction');
  $pdo->beginTransaction();
  try {
    $result = coreui_account_delete_tx($pdo, $uid);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  $root = dirname(__DIR__);
  $files = 0;
  try {
    $artifacts = coreui_console_artifact_delete_files($result['artifact_keys']);
    $files += (int)($artifacts['artifact_files_deleted'] ?? $artifacts['files_deleted'] ?? 0);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) stu__log_error(['type' => 'account_delete_artifacts', 'user' => $uid, 'message' => $e->getMessage()]);
  }
  $media = coreui_console_session_delete_media_files($result['media_paths']);
  $files += (int)($media['files_deleted'] ?? 0);
  $files += coreui_account_delete_named_files(coreui_knowledge_upload_dir(), $result['knowledge_files']);
  $files += coreui_account_delete_named_files(coreui_profile_media_dir(), $result['profile_files']);
  foreach ($result['photo_paths'] as $rel) {
    $rel = str_replace('\\', '/', (string)$rel);
    if (preg_match('~^assets/profile_photos/(pending|approved)/([^/]+)$~', $rel, $m)) {
      $files += coreui_account_delete_named_files($root . '/assets/profile_photos/' . $m[1], [$m[2]]);
    }
  }
  // Markdown archives of compacted chats (var/console_media/archives/<id>).
  $archives = $root . '/var/console_media/archives/' . $uid;
  if (is_dir($archives) && !is_link($archives)) {
    $files += coreui_account_delete_named_files($archives, array_map('basename', glob($archives . '/*.md') ?: []));
    @rmdir($archives);
  }

  foreach (['artifact_keys', 'media_paths', 'knowledge_files', 'profile_files', 'photo_paths'] as $key) unset($result[$key]);
  return $result + ['files_deleted' => $files];
}
