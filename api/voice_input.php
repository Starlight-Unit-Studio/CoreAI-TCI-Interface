<?php
declare(strict_types=1);

/*
 * 0.6.17: voice messages (P20.1). Signed-in accounts only (Inhaber, Admins,
 * Nutzer), never guests.
 *
 *   GET ?action=status               allowed, hears (true, false or null), ready, max_seconds
 *   GET ?action=transcript&uuid=..   state: done (text), pending, silent, failed or none
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

if ($method !== 'GET') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

if ($action === 'status') {
  if ($guest) stu_json(['ok' => true, 'allowed' => false]);
  coreui_ai_runtime_apply($pdo, $uid);
  stu_json(['ok' => true, 'allowed' => true, 'hears' => coreui_voice_core_hears(ember_ollama_show_json(ember_model())),
    'ready' => coreui_voice_bin('ffmpeg') !== null, 'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS,
    'csrf_token' => stu_csrf_token()]);
}

if ($guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);

if ($action === 'transcript') {
  $uuid = strtolower(trim((string)($_GET['uuid'] ?? '')));
  if (preg_match('~^[a-f0-9]{32}$~', $uuid) !== 1) stu_json(['ok' => false, 'error' => 'invalid_uuid'], 400);
  // Only the account's own audio attachments.
  $st = $pdo->prepare('SELECT created_at FROM stu_console_media WHERE uuid = ? AND user_id = ? AND kind = ? LIMIT 1');
  $st->execute([$uuid, $uid, 'audio']);
  $created = $st->fetchColumn();
  if ($created === false) stu_json(['ok' => false, 'error' => 'not_found'], 404);
  $data = coreui_voice_transcript_load($uid, $uuid);
  if (is_array($data) && ($data['state'] ?? '') === 'done') {
    stu_json(['ok' => true, 'state' => 'done', 'text' => (string)($data['text'] ?? ''), 'truncated' => !empty($data['truncated'])]);
  }
  if (is_array($data) && in_array($data['state'] ?? '', ['pending', 'silent', 'failed'], true)) {
    stu_json(['ok' => true, 'state' => (string)$data['state']]);
  }
  // Not started yet: the transcript follows Mara's answer, which can take a while.
  $fresh = strtotime((string)$created) >= time() - COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS;
  stu_json(['ok' => true, 'state' => $fresh ? 'pending' : 'none']);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
