<?php
declare(strict_types=1);

/*
 * 0.7.14: dictation (Settings > Stimme > Diktieren). Signed-in accounts only, never guests.
 *
 *   POST (multipart "audio", X-CoreUI-CSRF)  -> {ok, text, seconds, truncated}
 *
 * The recording is converted for the account's Core, which writes what was said in the
 * language set under Settings > Stimme > Sprache (with the names and terms of the account),
 * through Mara's model queue. Nothing is kept on the server: the upload is removed at once and
 * the text goes back to the page, which puts it into the chat input field to be checked.
 */

// The chat library (as for voice_input.php): the chosen Core, Ollama, the account language.
define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/voice_transcribe.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
if (coreui_guest_is($pdo, $uid)) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

if (!coreui_voice_prefs($pdo, $uid)['dictation']) stu_json(['ok' => false, 'error' => 'dictation_off'], 403);
$upload = $_FILES['audio'] ?? null;
if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
  stu_json(['ok' => false, 'error' => 'invalid_audio'], 400);
}
if ((int)$upload['size'] <= 0 || (int)$upload['size'] > COREUI_VOICE_DICTATION_MAX_BYTES) stu_json(['ok' => false, 'error' => 'audio_too_large'], 413);
coreui_ai_runtime_apply($pdo, $uid);
$model = ember_model();
if (coreui_voice_core_hears(ember_ollama_show_json($model)) === false) stu_json(['ok' => false, 'error' => 'core_without_audio'], 409);
if (coreui_voice_bin('ffmpeg') === null) stu_json(['ok' => false, 'error' => 'ffmpeg_missing'], 503);
if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
@set_time_limit(COREUI_VOICE_CALL_TIMEOUT * 2);
$result = coreui_voice_transcribe_file((string)$upload['tmp_name'], [
  'url' => ember_url(), 'model' => $model,
  'language' => coreui_voice_input_code($pdo, $uid, (string)(coreui_ai_runtime_settings()['main_language'] ?? 'de')),
  'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'queue_wait' => 300, 'keep_alive' => ember_keep_alive(),
  'num_ctx' => ember_profile_num_ctx($model), 'terms' => coreui_voice_terms_load($pdo, $uid),
]);
@unlink((string)$upload['tmp_name']);
if (!empty($result['ok'])) {
  stu_json(['ok' => true, 'text' => trim((string)$result['text']), 'seconds' => round((float)($result['seconds'] ?? 0), 1),
    'truncated' => !empty($result['truncated'])]);
}
$error = (string)($result['error'] ?? 'core_failed');
$status = ['no_speech' => 422, 'empty_audio' => 422, 'missed' => 422, 'convert_failed' => 400, 'core_without_audio' => 409,
  'queue_timeout' => 503, 'queue_unavailable' => 503, 'ffmpeg_missing' => 503][$error] ?? 502;
stu_json(['ok' => false, 'error' => $error], $status);
