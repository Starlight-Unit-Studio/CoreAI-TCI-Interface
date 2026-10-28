<?php
declare(strict_types=1);

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/console_action_store.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/console_agent_runs.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, (int)$uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
  if ($method === 'GET') {
    // 0.5.77: archive of a compacted chat (download) and its state.
    $getAction = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($getAction === 'archive' || $getAction === 'compact_status') {
      $sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
      if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);
      $path = coreui_compact_archive_path((int)$uid, $sessionId);
      if ($getAction === 'compact_status') {
        $compact = coreui_compact_load($pdo, (int)$uid, $sessionId);
        stu_json(['ok'=>true, 'folded'=>(int)($compact['folded'] ?? 0), 'has_archive'=>is_file($path),
          'updated_at'=>$compact['updated_at'] ?? null]);
      }
      if (!is_file($path)) stu_json(['ok'=>false, 'error'=>'archive_not_found'], 404);
      if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
      header('Content-Type: text/markdown; charset=utf-8');
      header('Content-Disposition: attachment; filename="Chat-Archiv-' . substr($sessionId, 0, 12) . '.md"');
      header('X-Content-Type-Options: nosniff');
      header('Cache-Control: private, no-store');
      header('Content-Length: ' . (string)filesize($path));
      readfile($path);
      exit;
    }
    if ($getAction === 'run_status') {
      if (!coreui_agent_run_schema_ready($pdo)) {
        stu_json(['ok'=>false,'error'=>'agent_runs_migration_required'],503);
      }
      $sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
      $runId = strtolower(trim((string)($_GET['run_id'] ?? '')));
      $run = coreui_agent_run_get($pdo,(int)$uid,$sessionId,$runId);
      if ($run === null) stu_json(['ok'=>false,'error'=>'agent_run_not_found'],404);
      if ((string)$run['status'] === 'waiting_tool') {
        $run = coreui_agent_run_reconcile_waiting_tool($pdo,(int)$uid,$sessionId,$runId);
      }
      stu_json(['ok'=>true,'run_id'=>$runId,'status'=>(string)$run['status'],
        'cancel_requested'=>!empty($run['cancel_requested_at']),
        'error_code'=>$run['error_code'] ?? null]);
    }
    if (!coreui_console_actions_schema_ready($pdo)) {
      stu_json(['ok'=>false, 'error'=>'message_actions_migration_required'], 503);
    }
    $requestId = strtolower(trim((string)($_GET['request_id'] ?? '')));
    $request = coreui_console_generation_load($pdo, (int)$uid, $requestId);
    $sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
    if ($sessionId === '' || !hash_equals((string)$request['session_id'], $sessionId)) {
      stu_json(['ok'=>false, 'error'=>'generation_request_not_found'], 404);
    }
    if (coreui_agent_run_schema_ready($pdo)) {
      $runStmt = $pdo->prepare(
        "SELECT run_id FROM stu_console_agent_runs
         WHERE user_id=? AND session_id=? AND run_key=? AND status='waiting_tool' LIMIT 1"
      );
      $runStmt->execute([(int)$uid,$sessionId,$requestId]);
      $runId = (string)($runStmt->fetchColumn() ?: '');
      if ($runId !== '') {
        coreui_agent_run_reconcile_waiting_tool($pdo,(int)$uid,$sessionId,$runId);
      }
    }
    $request = coreui_console_generation_reconcile($pdo, (int)$uid, $requestId, $request);
    coreui_console_generation_expire($pdo, (int)$uid);
    if (in_array((string)$request['status'], ['issued', 'running'], true)) {
      $request = coreui_console_generation_load($pdo, (int)$uid, $requestId);
    }
    stu_json([
      'ok' => true,
      'request_id' => $requestId,
      'session_id' => $sessionId,
      'status' => (string)$request['status'],
      'mode' => (string)$request['mode'],
      'trigger_message_id' => (int)$request['trigger_message_id'],
      'source_response_id' => (int)$request['source_response_id'],
      'response_message_id' => (int)($request['response_message_id'] ?? 0),
      'error' => (string)($request['error_code'] ?? ''),
    ]);
  }

  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  stu_require_csrf();
  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? '')));
  $sessionId = coreui_console_session_normalize_id($body['session_id'] ?? '');
  if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);

  if ($action === 'cancel_run') {
    if (!coreui_agent_run_schema_ready($pdo)) {
      stu_json(['ok'=>false,'error'=>'agent_runs_migration_required'],503);
    }
    $runId = strtolower(trim((string)($body['run_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/D',$runId)) {
      stu_json(['ok'=>false,'error'=>'invalid_agent_run'],400);
    }
    $result = coreui_agent_run_request_cancel($pdo,(int)$uid,$sessionId,$runId);
    stu_json(['ok'=>true,'run_id'=>$runId] + $result);
  }

  if ($action === 'feedback') {
    $messageId = (int)($body['message_id'] ?? 0);
    $value = strtolower(trim((string)($body['value'] ?? '')));
    $characterId = trim((string)($body['character_id'] ?? ''));
    chat_require_character($pdo, (int)$uid, $characterId);
    $selected = coreui_console_feedback_toggle(
      $pdo,
      (int)$uid,
      $sessionId,
      $messageId,
      $value,
      $characterId
    );
    stu_json(['ok'=>true, 'message_id'=>$messageId, 'feedback'=>$selected]);
  }

  if ($action === 'prepare_generation') {
    $characterId = trim((string)($body['character_id'] ?? ''));
    chat_require_character($pdo, (int)$uid, $characterId);
    // 0.5.77: "Neu generieren" and "Weiter" count against a guest's limit.
    coreui_guest_reserve_turn($pdo, (int)$uid);
    $request = coreui_console_generation_prepare(
      $pdo,
      (int)$uid,
      $sessionId,
      (int)($body['source_response_id'] ?? 0),
      strtolower(trim((string)($body['mode'] ?? '')))
    );
    stu_json(['ok'=>true, 'request'=>$request], 201);
  }

  // 0.5.77: "Chat komprimieren": fold everything outside Mara's window into
  // the summary now (several rounds for a long chat), archive word for word.
  if ($action === 'compact') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(900);
    $folded = 0;
    $result = ['status'=>'not_needed', 'folded'=>0, 'total'=>0];
    for ($round = 0; $round < 3; $round++) {
      $run = coreui_compact_with_core($pdo, (int)$uid, $sessionId, true);
      if ($run['status'] !== 'done') {
        if ($folded === 0) $result = $run;
        break;
      }
      $folded += (int)$run['folded'];
      $result = $run;
    }
    $result['folded'] = $folded;
    stu_json(['ok'=>$result['status'] !== 'failed', 'error'=>$result['status'] === 'failed' ? 'compact_failed' : null,
      'status'=>$folded > 0 ? 'done' : $result['status'], 'folded'=>$folded, 'total'=>(int)($result['total'] ?? 0),
      'has_archive'=>is_file(coreui_compact_archive_path((int)$uid, $sessionId))], $result['status'] === 'failed' ? 502 : 200);
  }

  if ($action === 'edit_message') {
    $messageId = (int)($body['message_id'] ?? 0);
    $turn = coreui_console_action_message(
      $pdo,
      (int)$uid,
      $sessionId,
      $messageId,
      false
    );
    chat_require_character($pdo, (int)$uid, (string)($turn['character_id'] ?? ''));
    coreui_guest_reserve_turn($pdo, (int)$uid);
    $result = coreui_console_edit_prepare(
      $pdo,
      (int)$uid,
      $sessionId,
      $messageId,
      (string)($body['message'] ?? '')
    );
    stu_json(['ok'=>true] + $result, 201);
  }

  stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  $error = $e->getMessage();
  $status = str_contains($error, 'not_found') ? 404
    : (in_array($error, ['generation_busy', 'session_busy'], true) ? 409
      : (in_array($error, ['message_actions_migration_required', 'message_editing_migration_required', 'agent_runs_migration_required'], true) ? 503 : 400));
  stu_json(['ok'=>false, 'error'=>$error], $status);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_actions_exception', 'uid'=>(int)$uid, 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'console_action_failed'], 500);
}
