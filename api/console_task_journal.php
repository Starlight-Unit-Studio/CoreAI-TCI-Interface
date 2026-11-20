<?php
declare(strict_types=1);

/**
 * Owner-scoped, durable task journal for all console agent runs.
 *
 * This module never executes a model or tool, never stores prompts, source
 * code, provider secrets, stdout or thinking, and never replays side effects.
 * It records authoritative, hashed tool claims and verified receipts.
 * An uncertain prior claim is STOPPED, not treated as permission to retry.
 */
function coreui_task_journal_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT run_id,user_id,session_id,turn_id,status,phase,checkpoint_version,'
      . 'last_step,last_verified_step,requirements_json,evidence_json FROM stu_console_task_journal LIMIT 0');
    $pdo->query('SELECT id,run_id,user_id,session_id,step_no,tool_name,input_sha256,'
      . 'state,proof_kind,proof_sha256,proof_json FROM stu_console_task_actions LIMIT 0');
    return true;
  } catch (Throwable $e) { return false; }
}

function coreui_task_journal_valid_ref(int $uid, string $session, string $run): bool {
  return $uid > 0
    && preg_match('/^[A-Za-z0-9_-]{8,40}$/D',$session) === 1
    && preg_match('/^[a-f0-9]{32}$/D',$run) === 1;
}

/** The owned source turn is the canonical goal, not a model-created summary. */
function coreui_task_journal_begin(PDO $pdo, int $uid, string $session,
    string $run, int $turn, string $kind = 'agent'): bool {
  if (!coreui_task_journal_valid_ref($uid,$session,$run) || $turn <= 0
      || !in_array($kind,['agent','plan','browser','research','image','chat'],true)
      || !coreui_task_journal_schema_ready($pdo)) return false;
  $s = $pdo->prepare(
    "INSERT IGNORE INTO stu_console_task_journal
      (run_id,user_id,session_id,turn_id,task_kind,status,phase,
       requirements_json,evidence_json)
    SELECT r.run_id,r.user_id,r.session_id,r.turn_id,?,'running','prepare','[]','{}'
    FROM stu_console_agent_runs r
    WHERE r.run_id=? AND r.user_id=? AND r.session_id=?
      AND r.turn_id=? AND r.status='running'");
  $s->execute([$kind,$run,$uid,$session,$turn]);
  $check=$pdo->prepare('SELECT run_id FROM stu_console_task_journal '
    . 'WHERE run_id=? AND user_id=? AND session_id=? AND turn_id=? LIMIT 1');
  $check->execute([$run,$uid,$session,$turn]);
  return (bool)$check->fetchColumn();
}

function coreui_task_journal_get(PDO $pdo, int $uid, string $session, string $run): ?array {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)) return null;
  $s=$pdo->prepare('SELECT run_id,turn_id,status,phase,checkpoint_version,last_step,'
    . 'last_verified_step,last_error_code,requirements_json,evidence_json,updated_at '
    . 'FROM stu_console_task_journal WHERE run_id=? AND user_id=? AND session_id=? LIMIT 1');
  $s->execute([$run,$uid,$session]);
  $row=$s->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/** Tool arguments are not persisted. Only the cryptographic identity is. */
function coreui_task_journal_tool_sha(array $tool): string {
  return hash('sha256', (string)($tool['tool'] ?? '') . "\n" . (string)($tool['input'] ?? ''));
}

/**
 * Atomic insert/compare. A duplicate step is never executed twice, even
 * when the original worker died before recording its result.
 * `claimed` means this worker acquired a NEW claim, not a prior claim.
 */
function coreui_task_journal_claim(PDO $pdo, int $uid, string $session, string $run,
    int $step, array $tool): array {
  if (!coreui_task_journal_valid_ref($uid,$session,$run) || $step <= 0
      || $step > 100000000 || !isset($tool['tool'],$tool['input'])
      || !is_string($tool['tool']) || !is_string($tool['input'])
      || preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$tool['tool']) !== 1) {
    return ['status'=>'invalid'];
  }
  $hash=coreui_task_journal_tool_sha($tool);
  try {
    $insert=$pdo->prepare(
      'INSERT IGNORE INTO stu_console_task_actions '
      . '(run_id,user_id,session_id,step_no,tool_name,input_sha256,state) '
      . "SELECT j.run_id,j.user_id,j.session_id,?,?,?,'claimed' "
      . 'FROM stu_console_task_journal j '
      . 'JOIN stu_console_agent_runs r ON r.run_id=j.run_id '
      . 'WHERE j.run_id=? AND j.user_id=? AND j.session_id=? '
      . "AND j.status='running' AND r.status='running' AND r.cancel_requested_at IS NULL");
    $insert->execute([$step,$tool['tool'],$hash,$run,$uid,$session]);
    if ($insert->rowCount() === 1) return ['status'=>'claimed','sha256'=>$hash];
    $read=$pdo->prepare('SELECT tool_name,input_sha256,state,proof_kind,proof_sha256 '
      . 'FROM stu_console_task_actions WHERE run_id=? AND user_id=? '
      . 'AND session_id=? AND step_no=? LIMIT 1');
    $read->execute([$run,$uid,$session,$step]);
    $row=$read->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return ['status'=>'unavailable'];
    if (!hash_equals((string)$row['input_sha256'],$hash)
        || $row['tool_name'] !== $tool['tool']) return ['status'=>'conflict'];
    // Even a previously VERIFIED step is not rerun. A future resume uses
    // stored receipts or a NEW, explicitly authorized read.
    return ['status'=>'existing','state'=>(string)$row['state'],
      'proof_kind'=>(string)($row['proof_kind'] ?? ''),
      'proof_sha256'=>(string)($row['proof_sha256'] ?? '')];
  } catch (Throwable $e) {
    error_log('[CoreAI] task claim: '.get_class($e));
    return ['status'=>'unavailable'];
  }
}

/** Server-controlled, bounded proof. Raw output is hashed but never logged. */
function coreui_task_journal_proof(string $result, string $state,
    array $tool, array $actual = []): array {
  $kind = (string)($tool['tool'] ?? '');
  $type = in_array($kind,['pfile_read','pfile_write','pfile_edit'],true) ? 'file'
    : (in_array($kind,['prun','pproc_start','pproc_output','pproc_input'],true) ? 'process'
    : (in_array($kind,['pserver_start','pserver_fetch'],true) ? 'http'
    : (in_array($kind,['pvcs_commit','pvcs_branch','pvcs_merge'],true) ? 'version' : 'tool')));
  $proof = ['kind'=>$type,'result_bytes'=>strlen($result),
    'result_sha256'=>hash('sha256',$result)];
  if (isset($actual['file_revision']) && is_numeric($actual['file_revision']))
    $proof['file_revision']=max(0,(int)$actual['file_revision']);
  if (isset($actual['file_sha256']) && is_string($actual['file_sha256'])
      && preg_match('/^[a-f0-9]{64}$/D',$actual['file_sha256']))
    $proof['file_sha256']=$actual['file_sha256'];
  if (isset($actual['exit_code']) && is_numeric($actual['exit_code']))
    $proof['exit_code']=(int)$actual['exit_code'];
  if (isset($actual['http_code']) && is_numeric($actual['http_code']))
    $proof['http_code']=max(0,min(599,(int)$actual['http_code']));
  $proof['verified']=$state==='success';
  return $proof;
}

/**
 * CAS: only an earlier owned, claimed action can finish. Advance checkpoint
 * in the SAME transaction as the verified step receipt. Fail closed on
 * duplicates and unknown outcomes; never fabricate a successful file.
 */
function coreui_task_journal_finish(PDO $pdo, int $uid, string $session, string $run,
    int $step, array $tool, string $state, string $result,
    array $actual = []): bool {
  if (!coreui_task_journal_valid_ref($uid,$session,$run) || $step <= 0
      || !in_array($state,['success','error','info'],true)) return false;
  $proof=coreui_task_journal_proof($result,$state,$tool,$actual);
  $proofState=$state==='success' ? 'verified' : ($state==='error' ? 'error' : 'uncertain');
  $hash=coreui_task_journal_tool_sha($tool);
  $outer=$pdo->inTransaction();
  if ($outer) $pdo->exec('SAVEPOINT coreui_task_journal_step');
  else $pdo->beginTransaction();
  try {
    $update=$pdo->prepare('UPDATE stu_console_task_actions SET '
      . 'state=?,proof_kind=?,proof_sha256=?,proof_json=?,updated_at=NOW(3) '
      . "WHERE run_id=? AND user_id=? AND session_id=? AND step_no=? "
      . "AND input_sha256=? AND state='claimed'");
    $update->execute([$proofState,$proof['kind'],(string)$proof['result_sha256'],
      json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
      $run,$uid,$session,$step,$hash]);
    if ($update->rowCount() !== 1) throw new RuntimeException('task_step_already_finalized');
    $journal=$pdo->prepare("UPDATE stu_console_task_journal SET phase='execute',"
      . 'last_step=GREATEST(last_step,?),'
      . 'last_verified_step=GREATEST(last_verified_step,?),'
      . 'checkpoint_version=checkpoint_version+1,'
      . 'evidence_json=?,updated_at=NOW(3) '
      . "WHERE run_id=? AND user_id=? AND session_id=? AND status='running'");
    // The checkpoint stores only a small receipt, not the raw source.
    $summary=json_encode(['step'=>$step,'tool'=>$tool['tool'],'proof'=>$proof],
      JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $journal->execute([$step,$state==='success' ? $step : 0,$summary,
      $run,$uid,$session]);
    if ($journal->rowCount() !== 1) throw new RuntimeException('task_checkpoint_conflict');
    if ($outer) $pdo->exec('RELEASE SAVEPOINT coreui_task_journal_step');
    else $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($outer) $pdo->exec('ROLLBACK TO SAVEPOINT coreui_task_journal_step');
    elseif ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[CoreAI] task checkpoint: '.get_class($e));
    return false;
  }
}

/** A prior claimed step with uncertain outcome forbids automatic replay. */
function coreui_task_journal_resume_check(PDO $pdo, int $uid, string $session, string $run): array {
  $state=coreui_task_journal_get($pdo,$uid,$session,$run);
  if ($state===null) return ['safe'=>false,'reason'=>'checkpoint_missing'];
  $s=$pdo->prepare('SELECT step_no,state,tool_name FROM stu_console_task_actions '
    . "WHERE run_id=? AND user_id=? AND session_id=? AND state IN ('claimed','uncertain') "
    . 'ORDER BY step_no ASC LIMIT 1');
  $s->execute([$run,$uid,$session]);
  $row=$s->fetch(PDO::FETCH_ASSOC);
  if (is_array($row)) return ['safe'=>false,'reason'=>'unconfirmed_side_effect',
    'step'=>(int)$row['step_no'],'tool'=>(string)$row['tool_name']];
  if (in_array((string)$state['status'],['cancelled','completed','failed'],true))
    return ['safe'=>false,'reason'=>'already_terminal'];
  if (!in_array((string)$state['status'],['interrupted','blocked'],true))
    return ['safe'=>false,'reason'=>'run_still_active'];
  return ['safe'=>true,'last_step'=>(int)$state['last_step'],
    'last_verified_step'=>(int)$state['last_verified_step'],
    'checkpoint_version'=>(int)$state['checkpoint_version']];
}

/**
 * Projection from the real agent-run state. This is safe to call for plan,
 * browse, image and research jobs, even before their specialized adapters
 * report individual proofs.
 */
function coreui_task_journal_sync(PDO $pdo, int $uid, string $session, string $run,
    string $status, ?string $reason = null): void {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)
      || !in_array($status,['queued','running','waiting_tool','completed',
        'interrupted','blocked','failed','cancelled'],true)) return;
  if ($reason!==null && preg_match('/^[a-z0-9_]{1,64}$/D',$reason)!==1) $reason=null;
  $s=$pdo->prepare('UPDATE stu_console_task_journal SET status=?,'
    . "phase=CASE WHEN ?='completed' THEN 'finalize' ELSE phase END,"
    . 'last_error_code=?,updated_at=NOW(3),'
    . "finished_at=CASE WHEN ? IN ('completed','interrupted','blocked','failed','cancelled') "
    . 'THEN NOW(3) ELSE NULL END '
    . 'WHERE run_id=? AND user_id=? AND session_id=?');
  $s->execute([$status,$status,$reason,$status,$run,$uid,$session]);
}

/** A model statement cannot override incomplete or uncertain tool evidence. */
function coreui_task_journal_can_finish(PDO $pdo, int $uid, string $session, string $run): bool {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)) return false;
  $s=$pdo->prepare('SELECT COUNT(*) AS total,'
    . "COALESCE(SUM(state='verified'),0) AS verified,"
    . "COALESCE(SUM(state IN ('claimed','uncertain')),0) AS pending "
    . 'FROM stu_console_task_actions WHERE run_id=? AND user_id=? AND session_id=?');
  $s->execute([$run,$uid,$session]);
  $row=$s->fetch(PDO::FETCH_ASSOC) ?: [];
  return (int)($row['total']??0)>0 && (int)($row['verified']??0)>0
    && (int)($row['pending']??0)===0;
}
