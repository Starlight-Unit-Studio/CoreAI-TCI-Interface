<?php
declare(strict_types=1);

/*
 * 0.6.17: voice messages (P20.1). Signed-in accounts only (Inhaber, Admins,
 * Nutzer), never guests.
 *
 *   GET ?action=status               allowed, hears (true, false or null), ready, max_seconds,
 *                                    terms, terms_ready (0.6.22)
 *   GET ?action=transcript&uuid=..   state: done (text), pending, silent, failed or none
 *   POST ?action=terms {terms}       KI-Profil: names and terms for voice messages (0.6.22)
 *   0.7.14: also dictation (on unless switched off) and the key of the account's recordings on
 *   this device; dictation itself is api/voice_dictate.php.
 *
 * A voice message itself is an ordinary attachment (api/console_upload.php)
 * that is sent at once; Mara hears the WAV in her own call (api/chat.php).
 * The transcript for the history is written after her answer
 * (api/console_stream.php) and shown small under the voice message.
 */

// The chat library (as for voice.php): the chosen Core, Ollama, the account language.
define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/voice_transcribe.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$guest = coreui_guest_is($pdo, $uid);

if ($method === 'GET' && $action === 'status') {
  if ($guest) stu_json(['ok' => true, 'allowed' => false]);
  coreui_ai_runtime_apply($pdo, $uid);
  stu_json(['ok' => true, 'allowed' => true, 'hears' => coreui_voice_core_hears(ember_ollama_show_json(ember_model())),
    'ready' => coreui_voice_bin('ffmpeg') !== null, 'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS,
    'terms' => coreui_voice_terms_load($pdo, $uid), 'terms_ready' => coreui_voice_terms_ready($pdo),
    'terms_max' => COREUI_VOICE_TERMS_MAX, 'dictation' => coreui_voice_prefs($pdo, $uid)['dictation'],
    'recordings_key' => coreui_voice_recordings_key($uid), 'csrf_token' => stu_csrf_token()]);
}

if ($guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);

// 0.6.22: names and terms for voice messages (KI-Profil), one per line.
if ($method === 'POST' && $action === 'terms') {
  stu_require_csrf();
  $body = stu_read_json_body();
  $terms = $body['terms'] ?? null;
  if (!is_string($terms) && !is_array($terms)) stu_json(['ok' => false, 'error' => 'invalid_terms'], 400);
  if (is_string($terms) && strlen($terms) > 8000) stu_json(['ok' => false, 'error' => 'terms_too_long'], 400);
  try {
    stu_json(['ok' => true, 'terms' => coreui_voice_terms_save($pdo, $uid, $terms)]);
  } catch (RuntimeException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
  }
}

if ($method !== 'GET') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

if ($action === 'transcript') {
  $uuid = strtolower(trim((string)($_GET['uuid'] ?? '')));
  if (preg_match('~^[a-f0-9]{32}$~', $uuid) !== 1) stu_json(['ok' => false, 'error' => 'invalid_uuid'], 400);
  // Only the account's own audio attachments; 0.6.35: and videos (their sound track).
  $st = $pdo->prepare('SELECT created_at, kind FROM stu_console_media WHERE uuid = ? AND user_id = ? AND kind IN (?, ?) LIMIT 1');
  $st->execute([$uuid, $uid, 'audio', 'video']);
  $media = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($media)) stu_json(['ok' => false, 'error' => 'not_found'], 404);
  $created = $media['created_at'];
  $data = coreui_voice_transcript_load($uid, $uuid);
  if (is_array($data) && ($data['state'] ?? '') === 'done') {
    stu_json(['ok' => true, 'state' => 'done', 'text' => (string)($data['text'] ?? ''), 'truncated' => !empty($data['truncated'])]);
  }
  // 0.6.36: "none" ends a video without a sound track Mara could hear (no track, Core without audio).
  if (is_array($data) && in_array($data['state'] ?? '', ['pending', 'silent', 'failed', 'none'], true)) {
    stu_json(['ok' => true, 'state' => (string)$data['state']]);
  }
  // Not started yet: a voice message's transcript follows Mara's answer, a video's is written
  // while she prepares it; until then the page asks again (0.6.35 said "none" at once for a
  // video, and the page never asked again: no line under a fresh video).
  $fresh = strtotime((string)$created) >= time() - COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS;
  stu_json(['ok' => true, 'state' => $fresh ? 'pending' : 'none']);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
