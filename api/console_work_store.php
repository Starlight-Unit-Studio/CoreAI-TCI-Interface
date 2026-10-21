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
      'title'=>$title,'meta'=>$safe,'time'=>gmdate('c')];
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

/** Whitelisted event payload, never raw stderr, prompts, or tool code. */
function coreui_work_public_event(array $row): array {
  $details = json_decode((string)($row['details_json'] ?? ''), true);
  $epoch = (int)($row['unix_time'] ?? 0);
  return [
    'id'=>(int)$row['id'],
    'kind'=>(string)$row['kind'],
    'state'=>(string)$row['state'],
    'title'=>(string)$row['title'],
    'meta'=>coreui_work_safe_meta(is_array($details) ? $details : []),
    'time'=>$epoch > 0 ? gmdate('c', $epoch) : '',
  ];
}

/** Batch read: only messages already authorized by console_messages.php. */
function coreui_work_map_for_responses(PDO $pdo, int $uid, string $sessionId, array $responseIds): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $responseIds),
    static fn(int $id): bool => $id > 0)));
  if ($uid <= 0 || $sessionId === '' || !$ids || !coreui_work_schema_ready($pdo)) return [];
  $ids = array_slice($ids, 0, 100);
  $placeholder = implode(',', array_fill(0, count($ids), '?'));
  // P06: A response may be committed even when the separate work-event
  // response binding update was interrupted. The agent run already stores
  // the authoritative response ID for this exact owner/session/turn/run_key.
  // Read it through the JOIN without mutating chat history or sharing events
  // across regeneration variants.
  $stmt = $pdo->prepare('SELECT w.id,'
    . ' COALESCE(w.response_message_id,r.response_message_id) AS response_message_id,'
    . ' w.kind,w.state,w.title,w.details_json,'
    . ' UNIX_TIMESTAMP(w.created_at) AS unix_time FROM stu_console_work_events w'
    . ' LEFT JOIN stu_console_agent_runs r ON r.user_id=w.user_id'
    . ' AND r.session_id=w.session_id AND r.turn_id=w.turn_id AND BINARY r.run_key=BINARY w.run_key'
    . ' WHERE w.user_id=? AND w.session_id=? AND (w.response_message_id IN ('
    . $placeholder . ') OR (w.response_message_id IS NULL AND r.response_message_id IN ('
    . $placeholder . '))) ORDER BY w.id ASC LIMIT 2000');
  $stmt->execute(array_merge([$uid, $sessionId], $ids, $ids));
  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $responseId = (int)($row['response_message_id'] ?? 0);
    if ($responseId <= 0) continue;
    if (!isset($map[$responseId])) $map[$responseId] = [];
    if (count($map[$responseId]) >= 150) continue;
    $map[$responseId][] = coreui_work_public_event($row);
  }
  return $map;
}

function coreui_work_list(PDO $pdo, int $uid, string $sessionId, int $turnId, int $responseId): array {
  if ($uid <= 0 || $turnId <= 0 || $responseId <= 0 || $sessionId === '') return [];
  $stmt = $pdo->prepare('SELECT w.id,w.kind,w.state,w.title,w.details_json,'
    . ' UNIX_TIMESTAMP(w.created_at) AS unix_time FROM stu_console_work_events w'
    . ' LEFT JOIN stu_console_agent_runs r ON r.user_id=w.user_id'
    . ' AND r.session_id=w.session_id AND r.turn_id=w.turn_id AND BINARY r.run_key=BINARY w.run_key'
    . ' WHERE w.user_id=? AND w.session_id=? AND w.turn_id=?'
    . ' AND (w.response_message_id=? OR (w.response_message_id IS NULL'
    . ' AND r.response_message_id=?)) ORDER BY w.id ASC LIMIT 150');
  $stmt->execute([$uid, $sessionId, $turnId, $responseId, $responseId]);
  return array_map('coreui_work_public_event', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}
