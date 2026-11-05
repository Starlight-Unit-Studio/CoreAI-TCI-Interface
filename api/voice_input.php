<?php
declare(strict_types=1);

/*
 * 0.6.11: voice input (P20.1). Signed-in accounts only (Inhaber, Admins,
 * Nutzer), never guests.
 *
 *   GET  ?action=status       allowed, hears (true, false or null), ready, max_seconds
 *   POST ?action=transcribe   multipart field "audio": the browser recording -> {text, seconds}
 *
 * The transcript goes back into the input field, where the person checks
 * and sends it. The recording is deleted right after the transcript is
 * written; nothing of it stays on the server. Core writes the transcript in
 * the account's main language (KI-Profil) and through Mara's model queue.
 */

// The chat library (as for voice.php): model queue, the chosen Core, Ollama URL.
define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/voice_transcribe.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$guest = coreui_guest_is($pdo, $uid);

/** The Core this account chats with (chat menu), and whether it can hear. */
function coreui_voice_input_core(PDO $pdo, int $uid): array {
  coreui_ai_runtime_apply($pdo, $uid);
  $model = ember_model();
  return ['model' => $model, 'hears' => coreui_voice_core_hears(ember_ollama_show_json($model))];
}

if ($method === 'GET' && $action === 'status') {
  if ($guest) stu_json(['ok' => true, 'allowed' => false]);
  $core = coreui_voice_input_core($pdo, $uid);
  stu_json(['ok' => true, 'allowed' => true, 'hears' => $core['hears'], 'ready' => coreui_voice_bin('ffmpeg') !== null,
    'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'csrf_token' => stu_csrf_token()]);
}

if ($guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);
if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
if ($action !== 'transcribe') stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
stu_require_csrf();

$upload = $_FILES['audio'] ?? null;
$tmp = is_array($upload) ? (string)($upload['tmp_name'] ?? '') : '';
if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
  $tooLarge = is_array($upload) && in_array((int)($upload['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
  stu_json(['ok' => false, 'error' => $tooLarge ? 'too_large' : 'no_audio'], $tooLarge ? 413 : 400);
}
if ((int)@filesize($tmp) > COREUI_VOICE_INPUT_MAX_BYTES) {
  @unlink($tmp);
  stu_json(['ok' => false, 'error' => 'too_large', 'message' => coreui_voice_error_text('too_large')], 413);
}

$core = coreui_voice_input_core($pdo, $uid);
if ($core['hears'] === false) {
  @unlink($tmp);
  stu_json(['ok' => false, 'error' => 'core_without_audio', 'message' => coreui_voice_error_text('core_without_audio')], 409);
}

// One transcript per account at a time; the model queue orders all accounts.
$lock = @fopen(rtrim(sys_get_temp_dir(), '/') . '/coreai-voice-input-' . $uid . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
  @unlink($tmp);
  stu_json(['ok' => false, 'error' => 'busy', 'message' => coreui_voice_error_text('busy')], 429);
}
@set_time_limit(900);
// stu_json() ends the request, so the result is built first and the recording removed in finally.
try {
  $language = (string)(coreui_ai_settings_load($pdo, $uid)['main_language'] ?? 'de');
  $result = coreui_voice_transcribe_file($tmp, [
    'url' => ember_url(), 'model' => $core['model'], 'language' => $language,
    'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'queue_wait' => 300,
    'keep_alive' => ember_keep_alive(), 'num_ctx' => ember_profile_num_ctx($core['model']),
  ]);
} catch (Throwable $e) {
  $result = ['ok' => false, 'error' => $e->getMessage() === 'agent_cancelled' ? 'cancelled' : 'core_failed'];
} finally {
  // "Sofort loeschen": the recording is gone before the answer leaves the server.
  @unlink($tmp);
  flock($lock, LOCK_UN);
  fclose($lock);
}

ember_debug_log('voice_input', ['ok' => $result['ok'], 'error' => (string)($result['error'] ?? ''),
  'seconds' => isset($result['seconds']) ? round((float)$result['seconds'], 1) : null,
  'parts' => isset($result['parts']) ? count($result['parts']) : 0]);

if (!$result['ok']) {
  $code = (string)($result['error'] ?? 'core_failed');
  $status = in_array($code, ['no_speech', 'missed', 'empty_audio'], true) ? 422
    : ($code === 'core_without_audio' ? 409 : ($code === 'convert_failed' ? 415
    : (in_array($code, ['queue_timeout', 'queue_unavailable', 'ffmpeg_missing'], true) ? 503 : 502)));
  stu_json(['ok' => false, 'error' => $code, 'message' => coreui_voice_error_text($code)], $status);
}
stu_json(['ok' => true, 'text' => $result['text'], 'seconds' => round((float)$result['seconds'], 1),
  'truncated' => (bool)$result['truncated']]);
