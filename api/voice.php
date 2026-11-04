<?php
declare(strict_types=1);

/*
 * 0.6.1: read aloud (P20.3). Signed-in accounts only, never guests; an
 * answer is read only for the account that owns it.
 *
 *   GET  ?action=status                    allowed, available, loading, auto_read
 *   POST ?action=settings {auto_read}      KI-Profil: read answers aloud automatically
 *   POST ?action=plan {session_id, message_id}  -> {id, parts}
 *   GET  ?action=audio&id=..&part=N         WAV of one part (cached 7 days)
 */

// The chat library (as for console_actions.php): who owns an answer, and which answers are Mara's.
define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/console_action_store.php';
require_once __DIR__ . '/voice_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$guest = coreui_guest_is($pdo, $uid);

if ($method === 'GET' && $action === 'status') {
  if ($guest) stu_json(['ok' => true, 'allowed' => false, 'available' => false, 'loading' => false, 'auto_read' => false]);
  $health = coreui_voice_health();
  stu_json(['ok' => true, 'allowed' => true, 'available' => $health['available'], 'loading' => $health['loading'],
    'voice' => $health['voice'], 'auto_read' => coreui_voice_auto_read($pdo, $uid), 'settings_ready' => coreui_voice_settings_ready($pdo),
    'csrf_token' => stu_csrf_token()]);
}

if ($guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);

if ($method === 'GET' && $action === 'audio') {
  $dir = coreui_voice_user_dir(coreui_voice_cache_root(), $uid, false);
  try {
    if ($dir === null) throw new RuntimeException('plan_not_found');
    $file = coreui_voice_audio($dir, (string)($_GET['id'] ?? ''), (int)($_GET['part'] ?? -1));
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => 'invalid_part'], 400);
  } catch (RuntimeException $e) {
    $code = $e->getMessage();
    $status = $code === 'plan_not_found' ? 404 : ($code === 'voice_timeout' ? 504 : 503);
    stu_json(['ok' => false, 'error' => $code], $status);
  }
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: audio/wav');
  header('Content-Length: ' . (string)filesize($file));
  header('Cache-Control: private, max-age=604800');
  header('X-Content-Type-Options: nosniff');
  readfile($file);
  exit;
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();

if ($action === 'settings') {
  $on = filter_var($body['auto_read'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($on === null) stu_json(['ok' => false, 'error' => 'invalid_auto_read'], 400);
  try {
    stu_json(['ok' => true, 'auto_read' => coreui_voice_set_auto_read($pdo, $uid, $on)]);
  } catch (RuntimeException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
  }
}

if ($action === 'plan') {
  try {
    $row = coreui_console_action_message($pdo, $uid, (string)($body['session_id'] ?? ''), (int)($body['message_id'] ?? 0), true);
  } catch (Throwable $e) {
    stu_json(['ok' => false, 'error' => 'message_not_found'], 404);
  }
  $dir = coreui_voice_user_dir(coreui_voice_cache_root(), $uid, true);
  if ($dir === null) stu_json(['ok' => false, 'error' => 'voice_cache_failed'], 500);
  coreui_voice_cleanup($dir);
  try {
    // 0.6.10: the account's main language (KI-Profil) decides when an answer's language is unclear.
    $hint = function_exists('coreui_ai_settings_load') ? (string)(coreui_ai_settings_load($pdo, $uid)['main_language'] ?? 'de') : 'de';
    $plan = coreui_voice_plan($dir, (string)$row['message'], $hint);
  } catch (RuntimeException $e) {
    $code = $e->getMessage();
    stu_json(['ok' => false, 'error' => $code], $code === 'nothing_to_read' ? 422 : 503);
  }
  stu_json(['ok' => true, 'id' => $plan['id'], 'parts' => $plan['parts'], 'language' => $plan['language']]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
