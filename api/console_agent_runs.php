<?php
declare(strict_types=1);

/**
 * P05 durable console runs. Never store prompts, raw reasoning or tool output.
 * All reads/writes are scoped to user + session + run ID.
 */
function coreui_agent_run_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT run_id,user_id,session_id,turn_id,run_key,response_message_id,status,'
      . 'error_code,created_at,started_at,updated_at,finished_at FROM stu_console_agent_runs LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_agent_run_valid_key(string $runKey): bool {
  return (bool)preg_match('/^[a-z0-9_-]{1,48}$/D', $runKey);
}

function coreui_agent_run_allowed_transition(string $from, string $to): bool {
  $allowed = [
    'queued' => ['running','cancelled'],
    'running' => ['waiting_tool','completed','failed','interrupted','cancelled'],
    'waiting_tool' => ['completed','failed','interrupted','cancelled'],
    'interrupted' => ['cancelled'],
  ];
  return in_array($to, $allowed[$from] ?? [], true);
}

function coreui_agent_run_get(PDO $pdo, int $uid, string $sessionId, string $runId): ?array {
  if ($uid <= 0 || !preg_match('/^[a-z0-9_-]{8,40}$/iD', $sessionId)
      || !preg_match('/^[a-f0-9]{32}$/D', $runId)) return null;
  $stmt = $pdo->prepare('SELECT run_id,turn_id,run_key,response_message_id,status,error_code,'
    . 'created_at,started_at,updated_at,finished_at FROM stu_console_agent_runs '
    . 'WHERE run_id=? AND user_id=? AND session_id=? LIMIT 1');
  $stmt->execute([$runId,$uid,$sessionId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/**
 * Idempotent across concurrent PHP workers and reconnects. The unique index
 * ensures that the same turn + action cannot create a second run.
 */
function coreui_agent_run_ensure(
  PDO $pdo, int $uid, string $sessionId, int $turnId, string $runKey = 'base'
): array {
  if ($uid <= 0 || $turnId <= 0
      || !preg_match('/^[a-z0-9_-]{8,40}$/iD', $sessionId)
      || !coreui_agent_run_valid_key($runKey)) {
    throw new InvalidArgumentException('invalid_agent_run');
  }
  $stmt = $pdo->prepare('SELECT id FROM stu_console_sessions WHERE id=? AND user_id=? LIMIT 1');
  $stmt->execute([$sessionId,$uid]);
  if (!$stmt->fetchColumn()) throw new RuntimeException('session_not_found');

  $stmt = $pdo->prepare("SELECT id FROM stu_chat_messages WHERE id=? AND user_id=? "
    . "AND session_id=? AND channel='console' AND deleted_at IS NULL LIMIT 1");
  $stmt->execute([$turnId,$uid,$sessionId]);
  if (!$stmt->fetchColumn()) throw new RuntimeException('turn_not_found');

  $newId = bin2hex(random_bytes(16));
  $stmt = $pdo->prepare('INSERT INTO stu_console_agent_runs '
    . '(run_id,user_id,session_id,turn_id,run_key,status) '
    . "VALUES (?,?,?,?,?,'queued') ON DUPLICATE KEY UPDATE run_id=run_id");
  $stmt->execute([$newId,$uid,$sessionId,$turnId,$runKey]);

  $stmt = $pdo->prepare('SELECT run_id,turn_id,run_key,response_message_id,status,error_code,'
    . 'created_at,started_at,updated_at,finished_at FROM stu_console_agent_runs '
    . 'WHERE user_id=? AND session_id=? AND turn_id=? AND run_key=? LIMIT 1');
  $stmt->execute([$uid,$sessionId,$turnId,$runKey]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) throw new RuntimeException('agent_run_unavailable');
  return $row;
}

/** Compare-and-swap transition: a second worker cannot claim an active run. */
function coreui_agent_run_transition(
  PDO $pdo, int $uid, string $sessionId, string $runId,
  string $from, string $to, ?string $errorCode = null
): bool {
  if (!coreui_agent_run_allowed_transition($from,$to)) {
    throw new InvalidArgumentException('invalid_agent_transition');
  }
  if ($errorCode !== null && !preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode)) {
    throw new InvalidArgumentException('invalid_agent_error');
  }
  $stmt = $pdo->prepare(
    "UPDATE stu_console_agent_runs SET status=?,error_code=?,updated_at=NOW(3),"
    . "started_at=CASE WHEN ?='running' THEN COALESCE(started_at,NOW(3)) ELSE started_at END,"
    . "finished_at=CASE WHEN ? IN ('completed','failed','cancelled') THEN NOW(3) ELSE finished_at END "
    . "WHERE run_id=? AND user_id=? AND session_id=? AND status=?"
  );
  $stmt->execute([$to,$errorCode,$to,$to,$runId,$uid,$sessionId,$from]);
  return $stmt->rowCount() === 1;
}

/** Only a real, owned assistant response to this turn can complete a run. */
function coreui_agent_run_bind_response(
  PDO $pdo, int $uid, string $sessionId, string $runId, int $responseId
): bool {
  if ($uid <= 0 || $responseId <= 0) return false;
  $stmt = $pdo->prepare(
    "UPDATE stu_console_agent_runs r SET r.response_message_id=?,r.updated_at=NOW(3) "
    . "WHERE r.run_id=? AND r.user_id=? AND r.session_id=? AND r.status='running' "
    . "AND r.response_message_id IS NULL "
    . "AND EXISTS (SELECT 1 FROM stu_chat_messages m WHERE m.id=? AND m.user_id=r.user_id "
    . "AND m.session_id=r.session_id AND m.reply_to_id=r.turn_id "
    . "AND m.channel='console' AND m.deleted_at IS NULL)"
  );
  $stmt->execute([$responseId,$runId,$uid,$sessionId,$responseId]);
  return $stmt->rowCount() === 1;
}
