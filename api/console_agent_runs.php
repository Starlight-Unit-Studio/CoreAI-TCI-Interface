<?php
declare(strict_types=1);

/**
 * P05 agent run state contract. Database operations are scoped to the owning
 * account and session. No model requests are issued by this module.
 */
function coreui_agent_run_start(PDO $db, int $userId, string $sessionId, int $turnId): string {
    if ($userId <= 0 || $turnId <= 0 || $sessionId === '') {
        throw new InvalidArgumentException('invalid_agent_turn');
    }
    $runId = bin2hex(random_bytes(16));
    $stmt = $db->prepare(
        "INSERT INTO stu_console_agent_runs
          (run_id,user_id,session_id,turn_id,status,created_at,updated_at)
         VALUES (?,?,?,?,'queued',NOW(),NOW())"
    );
    $stmt->execute([$runId,$userId,$sessionId,$turnId]);
    return $runId;
}

function coreui_agent_run_transition(
    PDO $db, int $userId, string $sessionId, string $runId,
    string $from, string $to, ?string $errorCode = null
): bool {
    $allowed = [
        'queued' => ['running','cancelled'],
        'running' => ['completed','failed','cancelled','interrupted'],
        'interrupted' => ['queued','cancelled'],
    ];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        throw new InvalidArgumentException('invalid_agent_transition');
    }
    $stmt = $db->prepare(
        'UPDATE stu_console_agent_runs SET status=?,error_code=?,updated_at=NOW(),
         finished_at=CASE WHEN ? IN (\'completed\',\'failed\',\'cancelled\') THEN NOW() ELSE NULL END
         WHERE run_id=? AND user_id=? AND session_id=? AND status=?'
    );
    $stmt->execute([$to,$errorCode,$to,$runId,$userId,$sessionId,$from]);
    return $stmt->rowCount() === 1;
}

function coreui_agent_run_get(PDO $db, int $userId, string $sessionId, string $runId): ?array {
    $stmt = $db->prepare(
        'SELECT run_id,turn_id,response_message_id,status,error_code,created_at,updated_at,finished_at
         FROM stu_console_agent_runs WHERE run_id=? AND user_id=? AND session_id=? LIMIT 1'
    );
    $stmt->execute([$runId,$userId,$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
