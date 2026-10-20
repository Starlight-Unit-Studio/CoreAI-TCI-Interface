<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_work_store.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  stu_json(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$uid = (int)stu_require_user_id();
$pdo = stu_pdo();
stu_enforce_maintenance($pdo, $uid);
$sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
$turnId = filter_var($_GET['turn_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$responseId = filter_var($_GET['response_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($sessionId === '' || $turnId === false || $turnId === null || $responseId === false || $responseId === null) {
  stu_json(['ok'=>false,'error'=>'invalid_request'],400);
}
try {
  coreui_console_session_require($pdo,$uid,$sessionId,true);
  $check = $pdo->prepare("SELECT id FROM stu_chat_messages
    WHERE id=? AND user_id=? AND session_id=? AND channel='console'
      AND deleted_at IS NULL LIMIT 1");
  $check->execute([(int)$turnId,$uid,$sessionId]);
  if (!$check->fetchColumn()) stu_json(['ok'=>false,'error'=>'turn_not_found'],404);
  $checkResponse = $pdo->prepare("SELECT id FROM stu_chat_messages WHERE id=? AND user_id=? AND session_id=?
    AND reply_to_id=? AND channel='console' AND deleted_at IS NULL LIMIT 1");
  $checkResponse->execute([(int)$responseId,$uid,$sessionId,(int)$turnId]);
  if (!$checkResponse->fetchColumn()) stu_json(['ok'=>false,'error'=>'reply_not_found'],404);
  if (!coreui_work_schema_ready($pdo)) stu_json(['ok'=>false,'error'=>'work_events_migration_required'],503);
  stu_json(['ok'=>true,'events'=>coreui_work_list($pdo,$uid,$sessionId,(int)$turnId,(int)$responseId)]);
} catch (Throwable $e) {
  // Avoid leaking SQL, filesystem paths or other accounts' activity.
  stu_json(['ok'=>false,'error'=>'work_events_unavailable'],500);
}
