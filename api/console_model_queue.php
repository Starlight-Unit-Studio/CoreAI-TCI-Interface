<?php
declare(strict_types=1);

/**
 * P05: FIFO admission for all Ollama HTTP calls, including non-console calls.
 * A MariaDB named lock remains the actual one-model-at-a-time guarantee.
 * Tickets provide a durable ordering and audit trail, never a second lock lane.
 */
function coreui_model_queue_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT queue_id,ticket_id,run_id,status,owner_connection_id FROM stu_console_model_queue LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_model_queue_acquire(
  PDO $pdo, string $lockName, int $timeoutSeconds, ?callable $cancelRequested = null
): string|false {
  $ticketId = bin2hex(random_bytes(16));
  $context = $GLOBALS['COREUI_WORK_CONTEXT'] ?? [];
  $runId = is_array($context) ? (string)($context['agent_run_id'] ?? '') : '';
  $uid = is_array($context) ? (int)($context['user_id'] ?? 0) : 0;
  if (!preg_match('/^[a-f0-9]{32}$/D', $runId)) $runId = '';
  $stmt = $pdo->prepare(
    "INSERT INTO stu_console_model_queue (ticket_id,run_id,user_id,status)
     VALUES (?,?,?,'queued')"
  );
  $stmt->execute([$ticketId, $runId !== '' ? $runId : null, $uid > 0 ? $uid : null]);
  $claimed = false;
  $cancelled = false;
  $deadline = microtime(true) + max(0, min(600, $timeoutSeconds));
  $lastHeartbeat = 0.0;
  $lastCleanup = 0.0;
  try {
    do {
      if ($cancelRequested !== null && $cancelRequested()) {
        $cancelled = true;
        throw new RuntimeException('agent_cancelled');
      }
      $now = microtime(true);
      if ($now - $lastHeartbeat >= 2.0) {
        $pdo->prepare(
          "UPDATE stu_console_model_queue SET updated_at=NOW(3)
           WHERE ticket_id=? AND status='queued'"
        )->execute([$ticketId]);
        $lastHeartbeat = $now;
      }
      if ($now - $lastCleanup >= 10.0) {
        // A dead waiting worker cannot block every subsequent request.
        $pdo->exec(
          "UPDATE stu_console_model_queue SET status='interrupted',finished_at=NOW(3)
           WHERE status='queued' AND updated_at < DATE_SUB(NOW(3),INTERVAL 25 SECOND)"
        );
        $owner = $pdo->prepare('SELECT IS_USED_LOCK(?)');
        $owner->execute([$lockName]);
        if ($owner->fetchColumn() === null) {
          // Do not assume an in-flight provider call has ended while its
          // connection still holds the named lock.
          $pdo->exec(
            "UPDATE stu_console_model_queue SET status='interrupted',finished_at=NOW(3)
             WHERE status='running' AND started_at < DATE_SUB(NOW(3),INTERVAL 3 SECOND)"
          );
        }
        // Keep the queue bounded; active and waiting tickets are never pruned.
        $pdo->exec(
          "DELETE FROM stu_console_model_queue
           WHERE status IN ('completed','cancelled','timed_out','interrupted')
             AND finished_at < DATE_SUB(NOW(3),INTERVAL 7 DAY)
           LIMIT 100"
        );
        $lastCleanup = $now;
      }
      $head = $pdo->query(
        "SELECT ticket_id FROM stu_console_model_queue
         WHERE status='queued' ORDER BY queue_id ASC LIMIT 1"
      );
      if ($head && hash_equals($ticketId, (string)($head->fetchColumn() ?: ''))) {
        $lock = $pdo->prepare('SELECT GET_LOCK(?,1)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() === 1) {
          try {
            // A cancellation might have arrived during GET_LOCK's wait.
            if ($cancelRequested !== null && $cancelRequested()) {
              $cancelled = true;
              throw new RuntimeException('agent_cancelled');
            }
            $claim = $pdo->prepare(
              "UPDATE stu_console_model_queue
               SET status='running',owner_connection_id=CONNECTION_ID(),
                   started_at=NOW(3),updated_at=NOW(3)
               WHERE ticket_id=? AND status='queued'"
            );
            $claim->execute([$ticketId]);
            if ($claim->rowCount() === 1) {
              $claimed = true;
              return $ticketId;
            }
          } finally {
            if (!$claimed) {
              $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
              $unlock->execute([$lockName]);
            }
          }
        }
      }
      if (function_exists('sse_send')
          && (!isset($GLOBALS['COREUI_QUEUE_SSE_AT'])
              || $now - (float)$GLOBALS['COREUI_QUEUE_SSE_AT'] >= 10)) {
        // A real SSE event keeps the browser watchdog alive while queued.
        sse_send('heartbeat', ['ok'=>true,'stage'=>'model_queue']);
        $GLOBALS['COREUI_QUEUE_SSE_AT'] = $now;
      }
      usleep(250000);
    } while (microtime(true) < $deadline);
    return false;
  } finally {
    if (!$claimed) {
      $status = $cancelled ? 'cancelled' : 'timed_out';
      $stmt = $pdo->prepare(
        "UPDATE stu_console_model_queue SET status=?,finished_at=NOW(3),updated_at=NOW(3)
         WHERE ticket_id=? AND status='queued'"
      );
      $stmt->execute([$status,$ticketId]);
    }
  }
}

function coreui_model_queue_release(PDO $pdo, string $lockName, string $ticketId): void {
  try {
    if (preg_match('/^[a-f0-9]{32}$/D', $ticketId)) {
      $stmt = $pdo->prepare(
        "UPDATE stu_console_model_queue
         SET status='completed',finished_at=NOW(3),updated_at=NOW(3)
         WHERE ticket_id=? AND status='running' AND owner_connection_id=CONNECTION_ID()"
      );
      $stmt->execute([$ticketId]);
    }
  } finally {
    // Always release the connection-bound lock even if bookkeeping fails.
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lockName]);
  }
}
