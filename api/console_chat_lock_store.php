<?php
declare(strict_types=1);

/**
 * CoreAI chat lock PIN, version 0.7.22.
 * A visibility lock with PIN-gated release, not encryption of stored messages.
 * No PIN appears in the session record, logs, URL or JSON response.
 */
function coreui_chat_lock_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT locked_at FROM stu_console_sessions LIMIT 0');
    $pdo->query('SELECT user_id, pin_hash, failed_attempts, blocked_until FROM stu_console_chat_lock_pins LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_chat_lock_pin_valid(string $pin): bool {
  return preg_match('/^[0-9]{4}$/D', $pin) === 1;
}

function coreui_chat_lock_require_schema(PDO $pdo): void {
  if (!coreui_chat_lock_schema_ready($pdo)) throw new RuntimeException('chat_lock_migration_required');
}

function coreui_chat_lock_has_pin(PDO $pdo, int $uid): bool {
  coreui_chat_lock_require_schema($pdo);
  $st = $pdo->prepare('SELECT 1 FROM stu_console_chat_lock_pins WHERE user_id=? LIMIT 1');
  $st->execute([$uid]);
  return (bool)$st->fetchColumn();
}

/** A PIN is registered ONCE per account. Cannot overwrite an existing PIN. */
function coreui_chat_lock_setup(PDO $pdo, int $uid, string $pin, string $confirmation): void {
  coreui_chat_lock_require_schema($pdo);
  if (!coreui_chat_lock_pin_valid($pin) || !hash_equals($pin, $confirmation)) {
    throw new InvalidArgumentException('invalid_pin_confirmation');
  }
  $hash = password_hash($pin, PASSWORD_DEFAULT);
  if (!is_string($hash) || $hash === '') throw new RuntimeException('pin_hash_failed');
  $st = $pdo->prepare('INSERT IGNORE INTO stu_console_chat_lock_pins (user_id,pin_hash) VALUES (?,?)');
  $st->execute([$uid,$hash]);
  if ($st->rowCount() !== 1) throw new RuntimeException('chat_pin_already_set');
}

/** All attempts are serialized by the account's PIN row. */
function coreui_chat_lock_verify(PDO $pdo, int $uid, string $pin): void {
  coreui_chat_lock_require_schema($pdo);
  if (!coreui_chat_lock_pin_valid($pin)) throw new InvalidArgumentException('invalid_pin');
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT pin_hash,failed_attempts,blocked_until,'
      . ' (blocked_until IS NOT NULL AND blocked_until > NOW()) AS is_blocked'
      . ' FROM stu_console_chat_lock_pins WHERE user_id=? LIMIT 1 FOR UPDATE');
    $st->execute([$uid]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); throw new RuntimeException('chat_pin_not_set'); }
    if ((int)($row['is_blocked'] ?? 0) === 1) {
      $pdo->rollBack();
      throw new RuntimeException('chat_pin_temporarily_blocked');
    }
    $ok=password_verify($pin,(string)$row['pin_hash']);
    if (!$ok) {
      $attempts=(int)$row['failed_attempts']+1;
      $blocked=$attempts>=5;
      $sql=$blocked
        ? 'UPDATE stu_console_chat_lock_pins SET failed_attempts=0, blocked_until=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id=?'
        : 'UPDATE stu_console_chat_lock_pins SET failed_attempts=?, blocked_until=NULL WHERE user_id=?';
      $st=$pdo->prepare($sql);
      $st->execute($blocked?[$uid]:[$attempts,$uid]);
      $pdo->commit();
      throw new RuntimeException($blocked?'chat_pin_temporarily_blocked':'chat_pin_incorrect');
    }
    $pdo->prepare('UPDATE stu_console_chat_lock_pins SET failed_attempts=0,blocked_until=NULL WHERE user_id=?')
      ->execute([$uid]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Change an established PIN only after proving the previous value. */
function coreui_chat_lock_change(PDO $pdo, int $uid, string $old, string $next, string $confirm): void {
  if (!coreui_chat_lock_pin_valid($next) || !hash_equals($next,$confirm)) {
    throw new InvalidArgumentException('invalid_pin_confirmation');
  }
  coreui_chat_lock_verify($pdo,$uid,$old);
  $hash=password_hash($next,PASSWORD_DEFAULT);
  if (!is_string($hash) || $hash==='') throw new RuntimeException('pin_hash_failed');
  $st=$pdo->prepare('UPDATE stu_console_chat_lock_pins SET pin_hash=?,failed_attempts=0,blocked_until=NULL WHERE user_id=?');
  $st->execute([$hash,$uid]);
}

/** The protected list contains only titles and IDs, never private messages. */
function coreui_chat_lock_list(PDO $pdo, int $uid): array {
  coreui_chat_lock_require_schema($pdo);
  $st=$pdo->prepare('SELECT id,title,project_id,locked_at FROM stu_console_sessions '
    . 'WHERE user_id=? AND locked_at IS NOT NULL AND archived_at IS NULL ORDER BY locked_at DESC LIMIT 100');
  $st->execute([$uid]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function coreui_chat_lock_set(PDO $pdo, int $uid, string $id): void {
  coreui_chat_lock_require_schema($pdo);
  if (!coreui_chat_lock_has_pin($pdo,$uid)) throw new RuntimeException('chat_pin_not_set');
  // The existing owner-scoped session guard excludes archived and locked rows.
  coreui_console_session_require($pdo,$uid,$id);
  if (function_exists('coreui_agent_run_working_sessions')) {
    $working=coreui_agent_run_working_sessions($pdo,$uid);
    if (isset($working[$id])) throw new RuntimeException('session_busy');
  }
  $st=$pdo->prepare('UPDATE stu_console_sessions SET locked_at=NOW(),pinned_at=NULL '
    . 'WHERE id=? AND user_id=? AND locked_at IS NULL AND archived_at IS NULL');
  $st->execute([$id,$uid]);
  if ($st->rowCount() !== 1) throw new RuntimeException('session_not_found');
}

/** PIN verification precedes the owner-scoped unlock write. */
function coreui_chat_lock_unset(PDO $pdo, int $uid, string $id, string $pin): void {
  coreui_chat_lock_require_schema($pdo);
  $st=$pdo->prepare('SELECT 1 FROM stu_console_sessions WHERE id=? AND user_id=? '
    . 'AND locked_at IS NOT NULL AND archived_at IS NULL LIMIT 1');
  $st->execute([$id,$uid]);
  if (!$st->fetchColumn()) throw new RuntimeException('session_not_found');
  coreui_chat_lock_verify($pdo,$uid,$pin);
  $st=$pdo->prepare('UPDATE stu_console_sessions SET locked_at=NULL WHERE id=? AND user_id=? AND locked_at IS NOT NULL');
  $st->execute([$id,$uid]);
  if ($st->rowCount() !== 1) throw new RuntimeException('session_not_found');
}
