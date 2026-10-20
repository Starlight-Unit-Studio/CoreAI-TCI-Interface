<?php
declare(strict_types=1);

// E.M.B.E.R. operational timeline. Never store raw prompts, reasoning, code,
// stdout, stderr or arbitrary model-produced HTML in this user-facing table.
function coreui_work_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id,user_id,session_id,turn_id,run_key,response_message_id,kind,state,title,details_json FROM stu_console_work_events LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_work_safe_meta(array $raw): array {
  $allowedNumbers = ['job_id','exit_code','duration_ms','file_count','attempt','http_code','elapsed_ms'];
  $allowedStrings = ['model','error_type','file_name','reason','source'];
  $safe = [];
  foreach ($allowedNumbers as $key) {
    if (isset($raw[$key]) && (is_int($raw[$key]) || is_numeric($raw[$key]))) {
      $safe[$key] = max(-1, min(100000000, (int)$raw[$key]));
    }
  }
  foreach ($allowedStrings as $key) {
    if (!isset($raw[$key]) || !is_string($raw[$key])) continue;
    $value = trim($raw[$key]);
    if ($key === 'file_name') $value = basename(str_replace('\\', '/', $value));
    if (preg_match('/^[a-zA-Z0-9._:\/-]{1,110}$/D', $value)) $safe[$key] = $value;
  }
  return $safe;
}

function coreui_work_python_diagnostic(?array $result): array {
  if (!is_array($result)) return ['error_type'=>'worker_timeout', 'exit_code'=>-1];
  $meta = [
    'job_id'=>(int)($result['id'] ?? 0),
    'exit_code'=>(int)($result['exit_code'] ?? -1),
    'duration_ms'=>(int)($result['duration_ms'] ?? 0),
  ];
  $stderr = (string)($result['stderr'] ?? '');
  $known = ['FileNotFoundError', 'PermissionError', 'ModuleNotFoundError',
            'SyntaxError', 'IndentationError', 'NameError', 'ImportError',
            'TimeoutError', 'TypeError', 'ValueError', 'OSError'];
  foreach ($known as $name) {
    if (str_contains($stderr, $name)) {
      $meta['error_type'] = $name;
      break;
    }
  }
  if (!isset($meta['error_type'])) {
    $code = (string)($result['error'] ?? '');
    $meta['error_type'] = preg_match('/^[a-z_]{1,60}$/', $code) ? $code : 'execution_failed';
  }
  if ($meta['error_type'] === 'FileNotFoundError'
      && preg_match('/FileNotFoundError:[^\r\n]*[\x27\x22]([^\x27\x22\r\n]{1,180})[\x27\x22]/', $stderr, $m)) {
    $meta['file_name'] = basename(str_replace('\\', '/', $m[1]));
  }
  return coreui_work_safe_meta($meta);
}

function coreui_work_record(PDO $pdo, string $kind, string $state, string $title, array $meta=[]): ?array {
  $ctx = $GLOBALS['COREUI_WORK_CONTEXT'] ?? null;
  if (!is_array($ctx)) return null;
  if (!in_array($kind, ['run','model','tool','artifact','error'], true)
      || !in_array($state, ['running','success','error','info'], true)) return null;
  $title = trim($title);
  if (!preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $title)) return null;
  $safe = coreui_work_safe_meta($meta);
  try {
    $stmt = $pdo->prepare('INSERT INTO stu_console_work_events
       (user_id,session_id,turn_id,run_key,response_message_id,kind,state,title,details_json,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,NOW(3))');
    $stmt->execute([(int)$ctx['user_id'],(string)$ctx['session_id'],(int)$ctx['turn_id'],
      (string)($ctx['run_key'] ?? 'base'), isset($ctx['response_message_id']) ? (int)$ctx['response_message_id'] : null,
      $kind,$state,$title,json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $event = ['id'=>(int)$pdo->lastInsertId(),'kind'=>$kind,'state'=>$state,
      'title'=>$title,'meta'=>$safe,'time'=>gmdate('H:i:s')];
    $publish = $GLOBALS['COREUI_WORK_PUBLISH'] ?? null;
    if (is_callable($publish)) $publish($event);
    return $event;
  } catch (Throwable $e) {
    // Logging failures must never interrupt a user's model or file job.
    error_log('[CoreUI] Work event write failed: ' . get_class($e));
    return null;
  }
}

function coreui_work_bind_response(PDO $pdo, int $uid, string $sessionId, int $turnId,
                                   string $runKey, int $responseId): void {
  if ($uid <= 0 || $turnId <= 0 || $responseId <= 0 || $runKey === '') return;
  $stmt = $pdo->prepare('UPDATE stu_console_work_events SET response_message_id=?
    WHERE user_id=? AND session_id=? AND turn_id=? AND run_key=? AND response_message_id IS NULL');
  $stmt->execute([$responseId,$uid,$sessionId,$turnId,$runKey]);
}

function coreui_work_list(PDO $pdo, int $uid, string $sessionId, int $turnId, int $responseId): array {
  if ($uid <= 0 || $turnId <= 0 || $responseId <= 0 || $sessionId === '') return [];
  $stmt = $pdo->prepare('SELECT id,kind,state,title,details_json,
    created_at AS event_time
    FROM stu_console_work_events
    WHERE user_id=? AND session_id=? AND turn_id=? AND response_message_id=? ORDER BY id ASC LIMIT 150');
  $stmt->execute([$uid,$sessionId,$turnId,$responseId]);
  $result=[];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $details=json_decode((string)($r['details_json'] ?? ''),true);
    $result[]=['id'=>(int)$r['id'],'kind'=>(string)$r['kind'],
      'state'=>(string)$r['state'],'title'=>(string)$r['title'],
      'meta'=>coreui_work_safe_meta(is_array($details)?$details:[]),
      'time'=>(string)$r['event_time']];
  }
  return $result;
}
