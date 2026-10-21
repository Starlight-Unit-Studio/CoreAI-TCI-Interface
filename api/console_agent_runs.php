<?php
declare(strict_types=1);

/**
 * P05 durable console runs. Never store prompts, raw reasoning or tool output.
 * All reads/writes are scoped to user + session + run ID.
 */
function coreui_agent_run_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT run_id,user_id,session_id,turn_id,run_key,response_message_id,browse_job_id,cancel_requested_at,status,'
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
  $stmt = $pdo->prepare('SELECT run_id,turn_id,run_key,response_message_id,browse_job_id,cancel_requested_at,status,error_code,'
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

  $stmt = $pdo->prepare('SELECT run_id,turn_id,run_key,response_message_id,browse_job_id,cancel_requested_at,status,error_code,'
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
    . "WHERE r.run_id=? AND r.user_id=? AND r.session_id=? AND r.status IN ('running','waiting_tool') "
    . "AND r.response_message_id IS NULL "
    . "AND EXISTS (SELECT 1 FROM stu_chat_messages m WHERE m.id=? AND m.user_id=r.user_id "
    . "AND m.session_id=r.session_id AND m.reply_to_id=r.turn_id "
    . "AND m.channel='console' AND m.deleted_at IS NULL)"
  );
  $stmt->execute([$responseId,$runId,$uid,$sessionId,$responseId]);
  return $stmt->rowCount() === 1;
}

/** Lightweight run state for already authorized chat history responses. */
function coreui_agent_run_map_for_responses(
  PDO $pdo, int $uid, string $sessionId, array $responseIds
): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $responseIds),
    static fn(int $id): bool => $id > 0)));
  if ($uid <= 0 || $sessionId === '' || $ids === [] || count($ids) > 100) return [];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare(
    'SELECT run_id,run_key,response_message_id,status,cancel_requested_at,error_code,created_at,started_at,finished_at '
    . 'FROM stu_console_agent_runs WHERE user_id=? AND session_id=? '
    . 'AND response_message_id IN (' . $placeholders . ')'
  );
  $stmt->execute(array_merge([$uid,$sessionId], $ids));
  $map = [];
  foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $messageId = (int)($row['response_message_id'] ?? 0);
    if ($messageId <= 0) continue;
    $map[$messageId] = [
      'id'=>(string)$row['run_id'],
      'key'=>(string)$row['run_key'],
      'status'=>(string)$row['status'],
      'cancel_requested'=>!empty($row['cancel_requested_at']),
      'error_code'=>$row['error_code'] !== null ? (string)$row['error_code'] : null,
      'started_at'=>$row['started_at'],
      'finished_at'=>$row['finished_at'],
    ];
  }
  return $map;
}

/**
 * Cancellation is cooperative: queued runs stop immediately; an active
 * provider request receives a cancellation flag, not a false "stopped" claim.
 */
function coreui_agent_run_request_cancel(
  PDO $pdo, int $uid, string $sessionId, string $runId
): array {
  $run = coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
  if ($run === null) throw new RuntimeException('agent_run_not_found');
  $queued = $pdo->prepare(
    "UPDATE stu_console_agent_runs SET status='cancelled',
      cancel_requested_at=NOW(3),finished_at=NOW(3),updated_at=NOW(3)
      WHERE run_id=? AND user_id=? AND session_id=? AND status='queued'"
  );
  $queued->execute([$runId,$uid,$sessionId]);
  if ($queued->rowCount() === 1) return ['status'=>'cancelled','cancel_requested'=>true];

  $active = $pdo->prepare(
    "UPDATE stu_console_agent_runs SET cancel_requested_at=COALESCE(cancel_requested_at,NOW(3)),
      updated_at=NOW(3)
      WHERE run_id=? AND user_id=? AND session_id=? AND status IN ('running','waiting_tool')"
  );
  $active->execute([$runId,$uid,$sessionId]);
  $run = coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
  if ($run === null) throw new RuntimeException('agent_run_not_found');
  return [
    'status'=>(string)$run['status'],
    'cancel_requested'=>!empty($run['cancel_requested_at']),
  ];
}

function coreui_agent_run_cancel_requested(
  PDO $pdo, int $uid, string $sessionId, string $runId
): bool {
  $run = coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
  return $run !== null
    && (!empty($run['cancel_requested_at']) || (string)$run['status']==='cancelled');
}

/** Bind the browser job to exactly the owned run, never to another session. */
function coreui_agent_run_attach_browse(
  PDO $pdo, int $uid, string $sessionId, string $runId, int $browseJobId
): bool {
  if ($browseJobId <= 0) return false;
  $st = $pdo->prepare(
    "UPDATE stu_console_agent_runs r SET r.browse_job_id=?,r.updated_at=NOW(3)
     WHERE r.run_id=? AND r.user_id=? AND r.session_id=? AND r.status='running'
       AND EXISTS (
         SELECT 1 FROM stu_ember_browse_jobs j
         WHERE j.id=? AND j.channel='console' AND j.recipient_uid=r.user_id
           AND j.session_id=r.session_id AND j.trigger_message_id=r.turn_id
       )"
  );
  $st->execute([$browseJobId,$runId,$uid,$sessionId,$browseJobId]);
  return $st->rowCount() === 1;
}

/**
 * Reconcile browser completion with persisted replies. Never re-run a model
 * or tool merely because a stream/browser worker disconnected.
 */
function coreui_agent_run_reconcile_waiting_tool(
  PDO $pdo, int $uid, string $sessionId, string $runId
): ?array {
  $run = coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
  if ($run === null || (string)$run['status'] !== 'waiting_tool') return $run;
  $jobId = (int)($run['browse_job_id'] ?? 0);
  if ($jobId <= 0) return $run;
  $job = $pdo->prepare(
    "SELECT status,finished_at FROM stu_ember_browse_jobs
     WHERE id=? AND channel='console' AND recipient_uid=?
       AND session_id=? AND trigger_message_id=? LIMIT 1"
  );
  $job->execute([$jobId,$uid,$sessionId,(int)$run['turn_id']]);
  $jobRow = $job->fetch(PDO::FETCH_ASSOC);
  if (!is_array($jobRow)) return $run;
  $jobStatus = (string)$jobRow['status'];
  if (!in_array($jobStatus,['done','error'],true)) return $run;

  if ($jobStatus === 'error') {
    coreui_agent_run_transition($pdo,$uid,$sessionId,$runId,'waiting_tool','failed','browse_failed');
    return coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
  }

  $responseId = 0;
  $key = (string)$run['run_key'];
  if ($key !== 'base' && preg_match('/^[a-f0-9]{32}$/D',$key)) {
    $reply = $pdo->prepare(
      "SELECT response_message_id FROM stu_console_generation_requests
       WHERE id=? AND user_id=? AND session_id=? AND trigger_message_id=?
         AND status='done' LIMIT 1"
    );
    $reply->execute([$key,$uid,$sessionId,(int)$run['turn_id']]);
    $responseId = (int)($reply->fetchColumn() ?: 0);
  } else {
    $reply = $pdo->prepare(
      "SELECT id FROM stu_chat_messages WHERE channel='console' AND user_id=?
       AND session_id=? AND reply_to_id=? AND deleted_at IS NULL
       AND character_id=? AND created_at>=?
       ORDER BY id ASC LIMIT 1"
    );
    $reply->execute([$uid,$sessionId,(int)$run['turn_id'],
      function_exists('ember_character_id') ? ember_character_id() : 'ember',
      (string)$run['created_at']]);
    $responseId = (int)($reply->fetchColumn() ?: 0);
  }
  if ($responseId > 0 && coreui_agent_run_bind_response($pdo,$uid,$sessionId,$runId,$responseId)) {
    // A completed, saved answer wins a race against a late cancel request.
    coreui_agent_run_transition($pdo,$uid,$sessionId,$runId,'waiting_tool','completed');
  } elseif (!empty($run['cancel_requested_at'])) {
    coreui_agent_run_transition($pdo,$uid,$sessionId,$runId,'waiting_tool','cancelled');
  } elseif ($responseId <= 0 && !empty($jobRow['finished_at'])
      && strtotime((string)$jobRow['finished_at']) < time() - 60) {
    coreui_agent_run_transition($pdo,$uid,$sessionId,$runId,
      'waiting_tool','interrupted','browse_result_missing');
  }
  return coreui_agent_run_get($pdo,$uid,$sessionId,$runId);
}

function coreui_agent_run_reconcile_session(PDO $pdo, int $uid, string $sessionId): void {
  $stmt = $pdo->prepare(
    "SELECT run_id FROM stu_console_agent_runs
     WHERE user_id=? AND session_id=? AND status='waiting_tool'
     ORDER BY created_at ASC LIMIT 10"
  );
  $stmt->execute([$uid,$sessionId]);
  foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $runId) {
    coreui_agent_run_reconcile_waiting_tool($pdo,$uid,$sessionId,(string)$runId);
  }
}
