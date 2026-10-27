<?php
declare(strict_types=1);
// 0.5.68: Core, Kontext, Werkzeuge and Projekt info boxes of one session.

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_insights_store.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
// Ollama may take a few seconds; never hold the PHP session lock meanwhile.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (!coreui_console_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'session_migration_required'], 503);
}
$sessionId = coreui_console_session_normalize_id($_GET['session_id'] ?? '');
if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);
try {
  $session = coreui_console_session_require($pdo, $uid, $sessionId);
} catch (Throwable $e) {
  stu_json(['ok'=>false, 'error'=>'session_not_found'], 404);
}

if (function_exists('coreui_ai_runtime_apply')) coreui_ai_runtime_apply($pdo, $uid);
$tools = coreui_insights_tools($pdo, $uid);
stu_json([
  'ok'=>true,
  'session_id'=>$sessionId,
  'session_title'=>(string)($session['title'] ?? ''),
  'core'=>coreui_insights_core(ember_model()),
  'usage'=>coreui_insights_usage($pdo, $uid, $sessionId),
  'tools'=>$tools['tools'],
  'skills'=>$tools['skills'],
  'plan'=>coreui_insights_plan($pdo, $uid, $sessionId),
  'files'=>coreui_insights_files($pdo, $uid, $sessionId),
]);
