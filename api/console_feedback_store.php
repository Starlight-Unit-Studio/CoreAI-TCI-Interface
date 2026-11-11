<?php
declare(strict_types=1);

require_once __DIR__ . '/console_action_store.php';

/** The reaction itself is already stored in stu_chat_reactions since migration 008. */
function coreui_feedback_events_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,user_id,session_id,message_id,rating,status,attempts,response_message_id '
      . 'FROM stu_console_feedback_events LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** Selftests use an outer rollback transaction; use savepoints in that case. */
function coreui_feedback_tx_begin(PDO $pdo, string $name): bool {
  $own = !$pdo->inTransaction();
  if ($own) $pdo->beginTransaction();
  else $pdo->exec('SAVEPOINT ' . $name);
  return $own;
}
function coreui_feedback_tx_commit(PDO $pdo, string $name, bool $own): void {
  if ($own) $pdo->commit();
  else $pdo->exec('RELEASE SAVEPOINT ' . $name);
}
function coreui_feedback_tx_rollback(PDO $pdo, string $name, bool $own): void {
  if (!$pdo->inTransaction()) return;
  if ($own) $pdo->rollBack();
  else {
    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
    $pdo->exec('RELEASE SAVEPOINT ' . $name);
  }
}

/** Called INSIDE the reaction transaction, so a rating and its event never diverge. */
function coreui_feedback_event_append(PDO $pdo, int $uid, string $sessionId,
                                      int $messageId, string $rating): int {
  if (!in_array($rating, ['up', 'down'], true)) throw new InvalidArgumentException('invalid_feedback');
  $st = $pdo->prepare("INSERT INTO stu_console_feedback_events
    (user_id,session_id,message_id,rating,status,created_at)
    VALUES (?,?,?,?,'pending',NOW())");
  $st->execute([$uid, $sessionId, $messageId, $rating]);
  return (int)$pdo->lastInsertId();
}

/** A repeated tap on the selected thumb is idempotent, but can retry a failed event. */
function coreui_feedback_latest_retry(PDO $pdo, int $uid, string $sessionId,
                                      int $messageId, string $rating): ?int {
  $st = $pdo->prepare("SELECT id,status,attempts,rating FROM stu_console_feedback_events
    WHERE user_id=? AND session_id=? AND message_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$uid,$sessionId,$messageId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (string)$row['rating'] !== $rating
      || !in_array((string)$row['status'], ['pending','failed'], true)
      || (int)$row['attempts'] >= 2) return null;
  return (int)$row['id'];
}

/**
 * A POST must claim the event before touching Ollama. A duplicate click/request
 * may not launch a second inference, even in another tab.
 */
function coreui_feedback_event_claim(PDO $pdo, int $uid, string $sessionId,
                                     int $eventId): array {
  if ($uid <= 0 || $eventId <= 0) throw new InvalidArgumentException('invalid_feedback_event');
  coreui_console_session_require($pdo, $uid, $sessionId);
  $ownTransaction = coreui_feedback_tx_begin($pdo, 'coreui_feedback_claim');
  try {
    $st = $pdo->prepare("SELECT id,user_id,session_id,message_id,rating,status,attempts,response_message_id
      FROM stu_console_feedback_events WHERE id=? AND user_id=? AND session_id=? FOR UPDATE");
    $st->execute([$eventId,$uid,$sessionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('feedback_event_not_found');
    $status = (string)$row['status'];
    if (!in_array($status, ['pending','failed'], true) || (int)$row['attempts'] >= 2) {
      coreui_feedback_tx_commit($pdo, 'coreui_feedback_claim', $ownTransaction);
      return ['status'=>$status, 'response_message_id'=>(int)($row['response_message_id'] ?? 0)];
    }
    $messageId = (int)$row['message_id'];
    $latest = $pdo->prepare('SELECT MAX(id) FROM stu_console_feedback_events WHERE user_id=? AND message_id=?');
    $latest->execute([$uid,$messageId]);
    $selected = coreui_console_feedback_map($pdo, $uid, [$messageId])[$messageId] ?? null;
    if ((int)$latest->fetchColumn() !== $eventId || $selected !== $row['rating']) {
      $pdo->prepare("UPDATE stu_console_feedback_events
        SET status='skipped',finished_at=NOW() WHERE id=?")->execute([$eventId]);
      coreui_feedback_tx_commit($pdo, 'coreui_feedback_claim', $ownTransaction);
      return ['status'=>'skipped','response_message_id'=>0];
    }
    $source = coreui_console_action_message($pdo, $uid, $sessionId, $messageId, true);
    $pdo->prepare("UPDATE stu_console_feedback_events
      SET status='running',attempts=attempts+1,started_at=NOW() WHERE id=?")->execute([$eventId]);
    coreui_feedback_tx_commit($pdo, 'coreui_feedback_claim', $ownTransaction);
    return [
      'status'=>'claimed', 'id'=>$eventId, 'message_id'=>$messageId,
      'rating'=>(string)$row['rating'], 'source'=>$source,
    ];
  } catch (Throwable $e) {
    coreui_feedback_tx_rollback($pdo, 'coreui_feedback_claim', $ownTransaction);
    throw $e;
  }
}

/** Save exactly one additional Mara message, independent of response variants. */
function coreui_feedback_event_complete(PDO $pdo, int $uid, string $sessionId,
                                        int $eventId, string $text): array {
  $text = trim($text);
  if ($text === '') throw new InvalidArgumentException('feedback_reply_empty');
  $ownTransaction = coreui_feedback_tx_begin($pdo, 'coreui_feedback_complete');
  try {
    $st = $pdo->prepare("SELECT message_id,rating,status,response_message_id
      FROM stu_console_feedback_events WHERE id=? AND user_id=? AND session_id=? FOR UPDATE");
    $st->execute([$eventId,$uid,$sessionId]);
    $event = $st->fetch(PDO::FETCH_ASSOC);
    if (!$event) throw new RuntimeException('feedback_event_not_found');
    if ((string)$event['status'] !== 'running') {
      coreui_feedback_tx_commit($pdo, 'coreui_feedback_complete', $ownTransaction);
      return ['status'=>(string)$event['status'],
        'response_message_id'=>(int)($event['response_message_id'] ?? 0)];
    }
    $messageId = (int)$event['message_id'];
    // Serialize against rating updates on this exact assistant message.
    $stLock = $pdo->prepare('SELECT id FROM stu_chat_messages WHERE id=? FOR UPDATE');
    $stLock->execute([$messageId]);
    $latest = $pdo->prepare('SELECT MAX(id) FROM stu_console_feedback_events WHERE user_id=? AND message_id=?');
    $latest->execute([$uid,$messageId]);
    $actual = coreui_console_feedback_map($pdo, $uid, [$messageId])[$messageId] ?? null;
    if (!$stLock->fetchColumn() || (int)$latest->fetchColumn() !== $eventId
        || $actual !== $event['rating']) {
      $pdo->prepare("UPDATE stu_console_feedback_events
        SET status='skipped',finished_at=NOW() WHERE id=?")->execute([$eventId]);
      coreui_feedback_tx_commit($pdo, 'coreui_feedback_complete', $ownTransaction);
      return ['status'=>'skipped','response_message_id'=>0];
    }
    // reply_to_id NULL: this is an actual new chat message, not a competing
    // alternative to the original answer, and it cannot be thumb-rated recursively.
    $write = $pdo->prepare("INSERT INTO stu_chat_messages
      (channel,alliance_id,session_id,user_id,character_id,character_name,
       message,thinking_content,reply_to_id,created_at)
      VALUES ('console',NULL,?,?,?,?,?,NULL,NULL,NOW())");
    $write->execute([$sessionId,$uid,ember_character_id(),ember_character_name(),$text]);
    $responseId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE stu_console_feedback_events SET status='done',
      response_message_id=?,finished_at=NOW() WHERE id=?")->execute([$responseId,$eventId]);
    coreui_console_session_touch($pdo, $uid, $sessionId, $responseId);
    coreui_feedback_tx_commit($pdo, 'coreui_feedback_complete', $ownTransaction);
    return ['status'=>'done','response_message_id'=>$responseId];
  } catch (Throwable $e) {
    coreui_feedback_tx_rollback($pdo, 'coreui_feedback_complete', $ownTransaction);
    throw $e;
  }
}

function coreui_feedback_event_fail(PDO $pdo, int $uid, string $sessionId,
                                    int $eventId): void {
  $pdo->prepare("UPDATE stu_console_feedback_events
    SET status='failed',finished_at=NOW()
    WHERE id=? AND user_id=? AND session_id=? AND status='running'")
    ->execute([$eventId,$uid,$sessionId]);
}
