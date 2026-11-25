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
    $pdo->query('SELECT run_id,user_id,session_id,turn_id,status,phase,checkpoint_version,resume_checkpoint_version,'
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

/**
 * Structural deliverables named explicitly by the user, not inferred from
 * general phrasing. A URL, code block or model-written guess cannot
 * silently enlarge the user's scope.
 */
function coreui_task_journal_requirements_from_goal(string $goal): array {
  if ($goal==='' || strlen($goal)>20000 || preg_match('//u',$goal)!==1) return [];
  $safe=(string)preg_replace('~```[\s\S]*?```~u','', $goal);
  $name='[A-Za-z0-9_+-]+(?:/[A-Za-z0-9_.+-]+){0,6}\\.(?:html?|css|js|mjs|json|md|txt|svg|py|ts|tsx|jsx|vue|svelte)';
  // Review 0.8.34: a name may end a sentence ("... und app.js."); a dot followed by more of a name may not.
  $file='(?<![A-Za-z0-9_./:@-])(' . $name . ')(?![A-Za-z0-9_/-]|\\.[A-Za-z0-9_])';
  // Stabilize 0.8.33: only a file the person asks to create or change is a deliverable that must exist
  // at the end. A library name ("Node.js", "Three.js"), a file to delete and the old name of a renamed
  // or moved file are no deliverables; a run with them could never finish.
  $create='~\b(?:erstell|anleg|leg\w*\s+\S+\s+an|schreib|bau|generier|erzeug|mach|programmier|entwickl|ergaenz|ergänz|'
    . 'hinzufueg|hinzufüg|fueg|füg|aender|änder|anpass|pass\w*\s+an|bearbeit|ueberarbeit|überarbeit|aktualisier|korrigier|'
    . 'verbesser|repar|implementier|neue?[nrsm]?\b|create|write|build|make|generate|add|implement|update|edit|modify|fix|change|'
    // Review 0.8.34: "Ich brauche eine Landingpage: index.html und style.css" asks for files as well.
    . 'brauch|benoetig|benötig|moecht|möcht|wuensch|wünsch|need|want)~iu';
  $removeWord='(?:l(?:ö|oe|o)sch|entfern|delete|remove|wegmach)\w*';
  $remove='~\b' . $removeWord . '~iu';
  $rename='~\b(?:benenn|umbenenn|rename|verschieb|move|mv)\w*~iu';
  $keep=[];
  // Review 0.8.34: a list that follows a request ("Erstelle folgende Dateien:" and lines "- index.html",
  // "Erstelle eine Seite." and bare names, "Dateien: a.html, b.js") belongs to that request. The intent
  // carries over list lines only; any other sentence ends it.
  $listLine='~^\s*(?:(?:[-*•+]|\d+[.)])(?:\s|$)|(?:dateien|datei|files?)\s*:|' . $file . ')~iu';
  $carry=false;
  // Sentences end at . ! ? ; or a line break that is not part of a file name.
  foreach (preg_split('~(?<=[.!?;])\s+|\R+~u',$safe) ?: [] as $sentence) {
    $isList=preg_match($listLine,$sentence)===1;
    $intent=preg_match($create,(string)preg_replace([$remove,$rename],' ',$sentence))===1;
    if (preg_match_all('~' . $file . '~iu',$sentence,$hits,PREG_OFFSET_CAPTURE)<1) {
      if ($intent) $carry=true;
      elseif (!$isList) $carry=false;
      continue;
    }
    $carried=$carry && $isList;
    $carry=$intent || $carried;
    $drop=[];
    if (preg_match($rename,$sentence)===1) {
      // "benenne app.js in main.js um", "rename a.js to b.js": the first name is gone afterwards.
      if (preg_match_all('~' . $file . '\s+(?:in|zu|nach|to|into|als|as)\s+(?:den\s+|die\s+|das\s+)?' . $file . '~iu',
          $sentence,$pairs)>0) {
        foreach ($pairs[1] as $old) $drop[strtolower((string)$old)]=true;
      }
    }
    if (preg_match($remove,$sentence)===1) {
      $list='(?:\s*(?:,|und|and|sowie|oder|or)\s*(?:die\s+|den\s+|das\s+|the\s+)?' . $file . ')*';
      // "loesche alt.html und alt.css", "alt.html bitte loeschen": these names are gone afterwards.
      foreach (['~\b' . $removeWord . '\s+(?:(?:die|den|das|the|datei|dateien|file|files)\s+)*' . $file . $list . '~iu',
          '~' . $file . $list . '\s+(?:\w+\s+){0,2}' . $removeWord . '~iu'] as $pattern) {
        if (preg_match_all($pattern,$sentence,$gone,PREG_SET_ORDER)>0) {
          foreach ($gone as $match) {
            preg_match_all('~' . $file . '~iu',$match[0],$names);
            foreach ($names[1] as $old) $drop[strtolower((string)$old)]=true;
          }
        }
      }
    }
    $creates=preg_match($create,(string)preg_replace([$remove,$rename],' ',$sentence))===1
      || (preg_match($rename,$sentence)===1 && $drop!==[]) || $carried;
    if (!$creates) continue;
    foreach ($hits[1] as [$found]) {
      $found=(string)$found;
      // A capitalised name.js without a folder is a library or product, not a file to create.
      if (!str_contains($found,'/') && preg_match('~^[A-Z][A-Za-z0-9_+-]*\.m?js$~D',$found)===1) continue;
      if (isset($drop[strtolower($found)])) continue;
      $keep[]=$found;
    }
  }
  $unique=[];
  foreach ($keep as $name) {
    try {
      if (!function_exists('coreui_pfiles_path')) continue;
      $path=coreui_pfiles_path($name);
      $unique[strtolower($path)]=['kind'=>'file','path'=>$path,'origin'=>'user_turn'];
    } catch (Throwable $ignored) {}
  }
  return array_values($unique);
}

/** Store explicit target paths only before the first tool claim. */
function coreui_task_journal_seed_requirements(PDO $pdo,int $uid,string $session,
    string $run,array $requirements): bool {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)) return false;
  $safe=[];
  foreach ($requirements as $r) {
    if (!is_array($r) || ($r['kind']??'')!=='file'
        || ($r['origin']??'')!=='user_turn') return false;
    $path=(string)($r['path']??'');
    try { if (!function_exists('coreui_pfiles_path')) return false;
      $path=coreui_pfiles_path($path);
    } catch(Throwable $e) { return false; }
    $safe[]=['kind'=>'file','path'=>$path,'origin'=>'user_turn'];
  }
  $payload=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  $q=$pdo->prepare('UPDATE stu_console_task_journal SET requirements_json=?,updated_at=NOW(3) '
    . "WHERE run_id=? AND user_id=? AND session_id=? AND checkpoint_version=0 "
    . "AND phase='prepare' AND requirements_json='[]'");
  $q->execute([$payload,$run,$uid,$session]);
  return $q->rowCount()===1 || $safe===[];
}

function coreui_task_journal_requirements_satisfied(PDO $pdo,int $uid,string $session,
    array $requirements): bool {
  if ($requirements===[])return true;
  if (!function_exists('coreui_pftools_project') || !function_exists('coreui_pfiles_list'))
    return false;
  try {
    $projectId=coreui_pftools_project($pdo,$uid,$session);
    if ($projectId==='') return false;
    $listing=coreui_pfiles_list($pdo,$uid,$projectId);
    $files=[];
    // Stabilize 0.8.33: "Index.html" in the request and a saved index.html are the same deliverable.
    foreach (($listing['files']??[]) as $f) {
      $files[strtolower((string)($f['path']??''))]=(string)($f['sha256']??'');
    }
    foreach ($requirements as $r) {
      if (!is_array($r) || ($r['kind']??'')!=='file')return false;
      $name=strtolower((string)($r['path']??''));
      if (!isset($files[$name]) || preg_match('/^[a-f0-9]{64}$/D',$files[$name])!==1)
        return false;
    }
    return true;
  } catch(Throwable $e) {return false;}
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
/** Stabilize 0.8.33: tools that only read or look; they leave nothing behind that could still be pending. */
const COREUI_TASK_JOURNAL_READ_ONLY = ['pfile_read','pproc_output','pserver_fetch','web','archive','skill','browse','python'];

/**
 * Stabilize 0.8.33: the durable state of a finished step. 'uncertain' blocked DONE and every resume for
 * the rest of the run, but a finished step with an 'info' result has a KNOWN outcome: a read that found
 * nothing new, a stop or input with no process running (nothing was executed), or a start the tool did
 * not confirm (a failed confirmation, so 'error'). Only a claim without a result stays open ('claimed').
 */
function coreui_task_journal_proof_state(array $tool, string $state, string $result): string {
  if ($state === 'success') return 'verified';
  if ($state === 'error') return 'error';
  $kind = (string)($tool['tool'] ?? '');
  $result = ltrim($result);
  if (in_array($kind, COREUI_TASK_JOURNAL_READ_ONLY, true)
      || str_starts_with($result, 'Gerade laeuft kein Prozess')
      || str_starts_with($result, 'In diesem Projekt wurde noch kein Prozess')) return 'verified';
  return 'error';
}

function coreui_task_journal_finish(PDO $pdo, int $uid, string $session, string $run,
    int $step, array $tool, string $state, string $result,
    array $actual = []): bool {
  if (!coreui_task_journal_valid_ref($uid,$session,$run) || $step <= 0
      || !in_array($state,['success','error','info'],true)) return false;
  $proof=coreui_task_journal_proof($result,$state,$tool,$actual);
  $proofState=coreui_task_journal_proof_state($tool,$state,$result);
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
  if ((int)$state['last_verified_step']<=0 || (int)$state['checkpoint_version']<=0)
    return ['safe'=>false,'reason'=>'no_verified_checkpoint'];
  if (($state['resume_checkpoint_version'] ?? null)!==null
      && (int)$state['resume_checkpoint_version']===(int)$state['checkpoint_version'])
    return ['safe'=>false,'reason'=>'checkpoint_already_resumed'];
  return ['safe'=>true,'last_step'=>(int)$state['last_step'],
    'last_verified_step'=>(int)$state['last_verified_step'],
    'checkpoint_version'=>(int)$state['checkpoint_version']];
}

/**
 * Atomic, owner-checked worker takeover. It may be triggered by an
 * authenticated EventSource reconnect, but ONLY when an interrupted run has
 * a new, fully verified checkpoint and no ambiguous in-flight side effect.
 * The single-use resume checkpoint prevents endless paid model retries.
 */
function coreui_task_journal_resume_claim(PDO $pdo,int $uid,string $session,
    string $run): array {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)
      || !coreui_task_journal_schema_ready($pdo))
    return ['ok'=>false,'reason'=>'checkpoint_unavailable'];
  $outer=$pdo->inTransaction();
  if ($outer) return ['ok'=>false,'reason'=>'transaction_in_progress'];
  try {
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT run_id,turn_id,run_key,status,response_message_id,'
      . 'cancel_requested_at FROM stu_console_agent_runs WHERE run_id=? '
      . 'AND user_id=? AND session_id=? FOR UPDATE');
    $q->execute([$run,$uid,$session]);
    $owner=$q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owner) || ($owner['status']??'')!=='interrupted'
        || ($owner['run_key']??'')!=='base'
        || !empty($owner['response_message_id']) || !empty($owner['cancel_requested_at']))
      throw new RuntimeException('run_not_resumable');
    $q=$pdo->prepare('SELECT checkpoint_version,resume_checkpoint_version,'
      . 'last_step,last_verified_step,status FROM stu_console_task_journal '
      . 'WHERE run_id=? AND user_id=? AND session_id=? FOR UPDATE');
    $q->execute([$run,$uid,$session]);
    $task=$q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($task) || ($task['status']??'')!=='interrupted'
        || (int)$task['checkpoint_version']<=0
        || (int)$task['last_verified_step']<=0
        || ($task['resume_checkpoint_version']!==null
          && (int)$task['resume_checkpoint_version']===(int)$task['checkpoint_version']))
      throw new RuntimeException('checkpoint_not_resumable');
    $q=$pdo->prepare("SELECT 1 FROM stu_console_task_actions WHERE run_id=? "
      . "AND user_id=? AND session_id=? AND state IN ('claimed','uncertain') LIMIT 1");
    $q->execute([$run,$uid,$session]);
    if ($q->fetchColumn()) throw new RuntimeException('unconfirmed_side_effect');
    $q=$pdo->prepare("UPDATE stu_console_agent_runs SET status='running', "
      . 'error_code=NULL,finished_at=NULL,updated_at=NOW(3) '
      . "WHERE run_id=? AND user_id=? AND session_id=? "
      . "AND status='interrupted' AND response_message_id IS NULL "
      . 'AND cancel_requested_at IS NULL');
    $q->execute([$run,$uid,$session]);
    if ($q->rowCount()!==1) throw new RuntimeException('run_claim_conflict');
    $q=$pdo->prepare("UPDATE stu_console_task_journal SET status='running',"
      . "phase='execute',last_error_code=NULL,finished_at=NULL,"
      . 'resume_checkpoint_version=checkpoint_version,updated_at=NOW(3) '
      . "WHERE run_id=? AND user_id=? AND session_id=? AND status='interrupted' "
      . 'AND checkpoint_version=?');
    $q->execute([$run,$uid,$session,(int)$task['checkpoint_version']]);
    if ($q->rowCount()!==1) throw new RuntimeException('checkpoint_claim_conflict');
    $pdo->commit();
    return ['ok'=>true,'last_step'=>(int)$task['last_step'],
      'checkpoint_version'=>(int)$task['checkpoint_version'],
      'turn_id'=>(int)$owner['turn_id']];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok'=>false,'reason'=>in_array($e->getMessage(),[
      'run_not_resumable','checkpoint_not_resumable','unconfirmed_side_effect',
      'run_claim_conflict','checkpoint_claim_conflict'],true)
      ? $e->getMessage() : 'checkpoint_claim_failed'];
  }
}

/**
 * Verified receipts only. The next model has the original owned request
 * and the current project file inventory, but cannot mistake stale raw code
 * for a current revision. Read current files using existing scoped tools.
 */
function coreui_task_journal_resume_context(PDO $pdo,int $uid,string $session,
    string $run): string {
  if (!coreui_task_journal_valid_ref($uid,$session,$run)) return '';
  $q=$pdo->prepare("SELECT step_no,tool_name,proof_kind,proof_json FROM "
    . "stu_console_task_actions WHERE run_id=? AND user_id=? AND session_id=? "
    . "AND state='verified' ORDER BY step_no DESC LIMIT 64");
  $q->execute([$run,$uid,$session]);
  $receipts=array_reverse($q->fetchAll(PDO::FETCH_ASSOC) ?: []);
  $lines=[];
  foreach ($receipts as $receipt) {
    $meta=json_decode((string)($receipt['proof_json']??''),true);
    if (!is_array($meta)) continue;
    $type=(string)($receipt['tool_name']??'');
    if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$type)!==1) continue;
    $line='Schritt '.(int)$receipt['step_no'].': '.$type.' bestaetigt';
    if (isset($meta['file_revision']))$line.=', Dateirevision '.(int)$meta['file_revision'];
    if (isset($meta['file_sha256']) && preg_match('/^[a-f0-9]{64}$/D',$meta['file_sha256']))
      $line.=', Datei-SHA256 '.$meta['file_sha256'];
    $lines[]=$line;
  }
  return "[COREAI_SERVER_CHECKPOINT]\n"
    . 'Bereits bestaetigte Werkzeugschritte (kein Volltext, keine erneute Ausfuehrung):'
    . "\n" . implode("\n",$lines)
    . "\nDein urspruenglicher Nutzerauftrag gilt weiter. Arbeite nur an offenen "
    . "Teilen weiter. Lese benoetigte aktuelle Dateien erneut, statt Schreibaktionen "
    . "zu wiederholen. Bei blockierter Arbeit einen BLOCKED-Status liefern; "
    . "bei gepruefter Erledigung einen DONE-Status, ansonsten exakt einen "
    . "autorisierten Werkzeugmarker.\n[/COREAI_SERVER_CHECKPOINT]";
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
    . "COALESCE(SUM(state IN ('claimed','uncertain')),0) AS pending,"
    . "COALESCE(SUM(tool_name IN ('web','archive','skill','browse','python','image')),0) AS research "
    . 'FROM stu_console_task_actions WHERE run_id=? AND user_id=? AND session_id=?');
  $s->execute([$run,$uid,$session]);
  $row=$s->fetch(PDO::FETCH_ASSOC) ?: [];
  if (!((int)($row['total']??0)>0 && (int)($row['verified']??0)>0
      && (int)($row['pending']??0)===0)) return false;
  // Stabilize 0.8.33: a run that only searched, looked up or loaded a guide made no project files;
  // file names in its question ("Was ist neu in Node.js 24?") are no deliverables of it.
  if ((int)($row['research']??0)===(int)$row['total']) return true;
  $task=coreui_task_journal_get($pdo,$uid,$session,$run);
  if ($task===null) return false;
  $requirements=json_decode((string)($task['requirements_json']??'[]'),true);
  return is_array($requirements)
    && coreui_task_journal_requirements_satisfied($pdo,$uid,$session,$requirements);
}
