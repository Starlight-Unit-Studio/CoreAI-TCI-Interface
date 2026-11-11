<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.31 (P20.4): the live conversation. Signed-in accounts only, never guests.
 *
 *   GET  ?action=status                       allowed, core, core_label, hears, ready, voice, name
 *        (0.7.0: allowed false with reason personal_off when the account switched it off)
 *   POST ?action=warm  {session_id, character_id, since_id}
 *        loads the live Core with the opening of the conversation (cache)
 *   POST ?action=turn  {session_id, character_id, since_id, uuid}
 *        server-sent events: heard {text, user_message_id} | silence | sentence {index, text}
 *        | done {user_message_id, reply_id, interrupted, timings} | error {error}
 *
 * 0.6.33: the live Core writes the transcript again (0.6.32 had Mara Schnell write it; on the
 * operator's server that saved no time and she misheard short phrases: "Hallo" became "Hallo
 * Bergisch Gladbach", "Na dann" became "Schopner danke gesagt").
 *   POST ?action=say   {text}                  Eve: plan of one sentence {id, parts} (audio: api/voice.php)
 *   POST ?action=end   {session_id, pairs: [[user_message_id, reply_id], ...]}
 *        Mara's memory reflection for the rounds, after the conversation (not during it)
 *
 * The recording of a round is an ordinary upload (api/console_upload.php, named like a
 * Stimmsequenz). Both sides end up as normal messages of the chat: the Stimmsequenz with
 * its transcript, then Mara's answer. See api/voice_live_lib.php.
 */

define('STU_CHAT_LIB', 1);
require __DIR__ . '/chat.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/voice_store.php';
require_once __DIR__ . '/voice_live_lib.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'status')));
$guest = coreui_guest_is($pdo, $uid);

/** The Core that answers live for this account (Mara Normal when installed, allowed and hearing). */
function coreui_live_core(PDO $pdo, int $uid): string {
  $chatCore = ember_model();
  try {
    $installed = array_map(static fn(array $m): string => (string)$m['name'], coreui_ollama_models());
  } catch (Throwable $e) {
    return $chatCore;
  }
  return coreui_live_pick_core($chatCore, $installed, coreui_core_mara_family(), 'coreui_core_tier_of',
    static fn(string $m): ?bool => coreui_voice_core_hears(ember_ollama_show_json($m)),
    static fn(string $m): bool => coreui_model_access_allowed($pdo, $uid, $m));
}

/** The name Mara calls the account by. */
function coreui_live_name(PDO $pdo, int $uid): string {
  try {
    $name = trim((string)(coreui_profile_load($pdo, $uid)['display_name'] ?? ''));
  } catch (Throwable $e) {
    $name = '';
  }
  return $name !== '' ? $name : 'du';
}

/** Plain transcripts of the Stimmsequenzen of one message (the same text in every round). */
function coreui_live_transcript_of(PDO $pdo, int $uid, int $messageId): string {
  try {
    $st = $pdo->prepare('SELECT m.uuid FROM stu_console_message_attachments a JOIN stu_console_media m '
      . 'ON m.uuid = a.media_uuid AND m.user_id = a.user_id WHERE a.user_id = ? AND a.message_id = ? AND m.kind = ? ORDER BY a.position');
    $st->execute([$uid, $messageId, 'audio']);
    $texts = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uuid) {
      $t = coreui_voice_transcript_load($uid, (string)$uuid);
      if (is_array($t) && ($t['state'] ?? '') === 'done' && trim((string)($t['text'] ?? '')) !== '') $texts[] = trim((string)$t['text']);
    }
    return implode(' ', $texts);
  } catch (Throwable $e) {
    return '';
  }
}

/** The opening and history of a live call for this session. */
function coreui_live_call_messages(PDO $pdo, int $uid, array $char, string $sessionId, int $sinceId, string $core): array {
  $date = (new DateTime('now', new DateTimeZone(ember_tz())))->format('d.m.Y');
  $memory = '';
  try {
    $limit = ember_memory_limit();
    if ($limit > 0) $memory = ember_mem_block($pdo, $uid, (string)$char['id'], $limit, '', 6000);
  } catch (Throwable $e) {}
  $context = coreui_live_context(coreui_live_name($pdo, $uid), $date, coreui_ai_user_prompt_block(), $memory);
  $history = coreui_live_history($pdo, $uid, $sessionId, $sinceId, ember_character_id(),
    static fn(int $id): string => coreui_live_transcript_of($pdo, $uid, $id));
  // The operator's Modelfile SYSTEM stays in force: a system message only for a Core without one.
  return coreui_live_messages($context, $history, coreui_voice_system_without_think(ember_persona_system_for($core)));
}

// 0.7.0: Settings > Personalisierung > Live-Gespraech off hides the button and refuses new rounds.
$liveOn = (coreui_personalization_load($pdo, $uid)['live_voice'] ?? true) !== false;

if ($method === 'GET' && $action === 'status') {
  if ($guest) stu_json(['ok' => true, 'allowed' => false]);
  if (!$liveOn) stu_json(['ok' => true, 'allowed' => false, 'reason' => 'personal_off']);
  coreui_ai_runtime_apply($pdo, $uid);
  $core = coreui_live_core($pdo, $uid);
  $voice = coreui_voice_health();
  stu_json(['ok' => true, 'allowed' => true, 'core' => $core, 'core_label' => coreui_core_label($core),
    'hears' => coreui_voice_core_hears(ember_ollama_show_json($core)), 'ready' => coreui_voice_bin('ffmpeg') !== null,
    'voice' => ['available' => (bool)$voice['available'], 'loading' => (bool)$voice['loading']],
    'name' => coreui_live_name($pdo, $uid), 'csrf_token' => stu_csrf_token()]);
}

if ($guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);
if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
// "end" still runs: Mara's reflection of a conversation that was already held.
if (!$liveOn && $action !== 'end') stu_json(['ok' => false, 'error' => 'live_off'], 403);
stu_require_csrf();
$body = stu_read_json_body();
coreui_ai_runtime_apply($pdo, $uid);

if ($action === 'say') {
  $text = trim((string)($body['text'] ?? ''));
  if ($text === '' || mb_strlen($text) > 1200) stu_json(['ok' => false, 'error' => 'invalid_text'], 400);
  $dir = coreui_voice_user_dir(coreui_voice_cache_root(), $uid, true);
  if ($dir === null) stu_json(['ok' => false, 'error' => 'voice_cache_failed'], 500);
  coreui_voice_cleanup($dir);
  try {
    // 0.7.14: in the voices chosen under Settings > Stimme.
    $plan = coreui_voice_plan($dir, $text, (string)(coreui_ai_runtime_settings()['main_language'] ?? 'de'), coreui_voice_prefs($pdo, $uid)['voices']);
  } catch (RuntimeException $e) {
    $code = $e->getMessage();
    stu_json(['ok' => false, 'error' => $code], $code === 'nothing_to_read' ? 422 : 503);
  }
  stu_json(['ok' => true, 'id' => $plan['id'], 'parts' => $plan['parts']]);
}

$sessionId = coreui_console_session_normalize_id((string)($body['session_id'] ?? ''));
try {
  coreui_console_session_require($pdo, $uid, $sessionId);
} catch (Throwable $e) {
  stu_json(['ok' => false, 'error' => 'session_not_found'], 404);
}

if ($action === 'end') {
  // Mara decides what goes into her long-term memory, after the conversation, as in the chat.
  $done = 0;
  foreach (array_slice((array)($body['pairs'] ?? []), 0, 40) as $pair) {
    [$userId, $replyId] = [(int)($pair[0] ?? 0), (int)($pair[1] ?? 0)];
    if ($userId <= 0 || $replyId <= 0) continue;
    $st = $pdo->prepare('SELECT id, character_id, message FROM stu_chat_messages WHERE id IN (?, ?) AND user_id = ? AND session_id = ? '
      . "AND channel = 'console' AND deleted_at IS NULL");
    $st->execute([$userId, $replyId, $uid, $sessionId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $rows[(int)$row['id']] = $row;
    if (!isset($rows[$userId], $rows[$replyId])) continue;
    $said = coreui_live_transcript_of($pdo, $uid, $userId);
    $reply = (string)$rows[$replyId]['message'];
    $cid = (string)$rows[$userId]['character_id'];
    if ($said === '' || trim($reply) === '') continue;
    try {
      if (ember_should_reflect_exchange($said, $reply)) ember_reflect_maybe_store($pdo, $uid, $cid, $said, $reply);
      ember_reputation_maybe_update($pdo, $uid, $cid, $said, $reply);
      $done++;
    } catch (Throwable $e) {}
  }
  stu_json(['ok' => true, 'reflected' => $done]);
}

$char = chat_require_character($pdo, $uid, trim((string)($body['character_id'] ?? '')));
$sinceId = max(0, (int)($body['since_id'] ?? 0));
$core = coreui_live_core($pdo, $uid);

/** One streamed Core call in Mara's queue; $onPiece(string) gets the text as it comes, returns false to stop. */
function coreui_live_stream(string $model, array $messages, callable $onPiece): array {
  $body = ['model' => $model, 'stream' => true, 'think' => false, 'messages' => $messages];
  $keep = ember_keep_alive();
  if ($keep !== null) $body['keep_alive'] = $keep;
  $numCtx = ember_profile_num_ctx($model);
  if ($numCtx !== null && $numCtx >= 256) $body['options'] = ['num_ctx' => $numCtx];
  $state = ['buf' => '', 'text' => '', 'final' => [], 'error' => '', 'stopped' => false];
  $lock = ember_global_lock_acquire(600);
  if ($lock === false) return ['error' => 'queue_timeout', 'text' => '', 'final' => [], 'stopped' => false];
  try {
    $ch = curl_init(ember_url());
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 300,
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$state, $onPiece): int {
        $state['buf'] .= $chunk;
        while (($nl = strpos($state['buf'], "\n")) !== false) {
          $line = trim(substr($state['buf'], 0, $nl));
          $state['buf'] = substr($state['buf'], $nl + 1);
          $j = $line !== '' ? json_decode($line, true) : null;
          if (!is_array($j)) continue;
          if (isset($j['error'])) $state['error'] = (string)$j['error'];
          $piece = (string)($j['message']['content'] ?? '');
          if ($piece !== '') {
            $state['text'] .= $piece;
            if ($onPiece($piece) === false) { $state['stopped'] = true; return 0; }
          }
          if (!empty($j['done'])) $state['final'] = $j;
        }
        return strlen($chunk);
      },
    ]);
    curl_exec($ch);
    if ($state['error'] === '' && !$state['stopped'] && curl_errno($ch) !== 0) $state['error'] = curl_error($ch);
    curl_close($ch);
  } finally {
    ember_generation_lock_release($lock);
  }
  return $state;
}

if ($action === 'warm') {
  // The opening and history go into the Core's cache before the first round.
  $start = microtime(true);
  $messages = coreui_live_call_messages($pdo, $uid, $char, $sessionId, $sinceId, $core);
  $messages[] = ['role' => 'user', 'content' => 'Das Live-Gespraech beginnt gleich. Antworte nur mit: Bereit.'];
  if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
  $r = coreui_live_stream($core, $messages, static fn(string $p): bool => true);
  ember_debug_log('coreui_live_warm', ['core' => $core, 'seconds' => round(microtime(true) - $start, 1)] + coreui_live_prompt_stats($r['final']));
  stu_json(['ok' => $r['error'] === '', 'error' => $r['error'], 'seconds' => round(microtime(true) - $start, 1),
    'core' => $core, 'core_label' => coreui_core_label($core)]);
}

if ($action !== 'turn') stu_json(['ok' => false, 'error' => 'unknown_action'], 404);

// --- One round ------------------------------------------------------------------
$uuid = strtolower(trim((string)($body['uuid'] ?? '')));
if (preg_match('~^[a-f0-9]{32}$~', $uuid) !== 1) stu_json(['ok' => false, 'error' => 'invalid_uuid'], 400);
$st = $pdo->prepare('SELECT uuid, rel_path, orig_name FROM stu_console_media WHERE uuid = ? AND user_id = ? AND kind = ? LIMIT 1');
$st->execute([$uuid, $uid, 'audio']);
$media = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($media)) stu_json(['ok' => false, 'error' => 'recording_not_found'], 404);
$source = dirname(__DIR__) . '/' . ltrim((string)$media['rel_path'], '/');
if (!is_file($source)) stu_json(['ok' => false, 'error' => 'recording_not_found'], 404);
if (coreui_voice_core_hears(ember_ollama_show_json($core)) === false) stu_json(['ok' => false, 'error' => 'core_without_audio'], 409);
coreui_user_policy_reserve_turn($pdo, $uid);

if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
ignore_user_abort(true);
@set_time_limit(0);
while (ob_get_level() > 0) @ob_end_clean();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
$send = static function (string $event, array $data): bool {
  if (connection_aborted()) return false;
  echo coreui_live_sse($event, $data);
  @flush();
  return !connection_aborted();
};
$send('start', ['core' => $core, 'core_label' => coreui_core_label($core)]);

$t0 = microtime(true);
$work = coreui_voice_workdir();
// The WAVs go away in every case, also after an early end (exit skips finally).
register_shutdown_function(static function () use ($work): void { coreui_voice_workdir_remove($work); });
$heard = '';
$parts = [];
$failure = '';
$wavs = $work !== null ? coreui_voice_prepare_native($source, $work, COREUI_VOICE_INPUT_MAX_SECONDS) : ['ok' => false, 'error' => 'convert_failed'];
if (empty($wavs['ok'])) {
  $failure = ($wavs['error'] ?? '') === 'no_speech' ? 'silence' : 'convert_failed';
} else {
  $language = coreui_voice_language_name(coreui_voice_input_code($pdo, $uid, (string)(coreui_ai_runtime_settings()['main_language'] ?? 'de')));
  $terms = function_exists('ember_attach_voice_terms') ? ember_attach_voice_terms($pdo, $uid) : [];
  foreach ((array)$wavs['paths'] as $i => $path) {
    $h = coreui_voice_transcribe_part(ember_url(), $core, (string)@file_get_contents($path), $language, 600,
      ember_keep_alive(), ember_profile_num_ctx($core), $terms);
    if (empty($h['ok'])) {
      if (($h['error'] ?? '') === 'missed') continue;
      $failure = (string)($h['error'] ?? 'core_failed');
      break;
    }
    if (($h['kind'] ?? '') === 'heard' && trim((string)$h['text']) !== '') {
      [$a, $b] = $wavs['ranges'][$i] ?? [0.0, 0.0];
      $parts[] = [(float)$a, (float)$b, trim((string)$h['text'])];
    }
  }
  $heard = trim(implode(' ', array_column($parts, 2)));
}
if ($failure !== '' && $failure !== 'silence') {
  $send('error', ['error' => $failure]);
  exit;
}
$transcriptS = microtime(true) - $t0;
if ($heard === '') {
  coreui_voice_transcript_save($uid, $uuid, ['state' => 'silent']);
  $send('silence', []);
  exit;
}
coreui_voice_transcript_save($uid, $uuid, ['state' => 'done', 'text' => $heard, 'parts' => $parts,
  'seconds' => $wavs['seconds'] ?? null, 'source_seconds' => $wavs['source_seconds'] ?? null, 'truncated' => !empty($wavs['truncated'])]);

// The Stimmsequenz as a normal message of the chat (empty text, the recording attached).
$pdo->beginTransaction();
try {
  $rows = coreui_console_attachment_validate($pdo, $uid, [$uuid], true);
  if (stu_schema_has_column($pdo, 'stu_chat_messages', 'file_uuid')) {
    $pdo->prepare('INSERT INTO stu_chat_messages (channel, alliance_id, session_id, user_id, character_id, character_name, message, file_uuid, created_at) '
      . "VALUES ('console', NULL, ?, ?, ?, ?, '', ?, NOW())")
      ->execute([$sessionId, $uid, (string)$char['id'], (string)$char['name'], $uuid]);
  } else {
    $pdo->prepare('INSERT INTO stu_chat_messages (channel, alliance_id, session_id, user_id, character_id, character_name, message, created_at) '
      . "VALUES ('console', NULL, ?, ?, ?, ?, '', NOW())")
      ->execute([$sessionId, $uid, (string)$char['id'], (string)$char['name']]);
  }
  $userMessageId = (int)$pdo->lastInsertId();
  coreui_console_attachment_store($pdo, $userMessageId, $uid, [$uuid], $rows);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $send('error', ['error' => 'message_store_failed']);
  exit;
}
coreui_console_session_touch($pdo, $uid, $sessionId, $userMessageId);
$send('heard', ['text' => $heard, 'user_message_id' => $userMessageId, 'seconds' => round($transcriptS, 1)]);

// Mara answers the text; whole sentences go to the browser for Eve as soon as they are there.
$messages = coreui_live_call_messages($pdo, $uid, $char, $sessionId, $sinceId, $core);
$buffer = '';
$index = 0;
$firstSentence = null;
$t1 = microtime(true);
$result = coreui_live_stream($core, $messages, static function (string $piece) use (&$buffer, &$index, &$firstSentence, $t1, $send): bool {
  $buffer .= $piece;
  foreach (coreui_live_take_sentences($buffer) as $sentence) {
    $sentence = coreui_live_spoken(coreui_live_clean_reply($sentence));
    if ($sentence === '') continue;
    if ($firstSentence === null) $firstSentence = microtime(true) - $t1;
    if (!$send('sentence', ['index' => $index++, 'text' => $sentence])) return false;
  }
  return true;
});
$interrupted = !empty($result['stopped']) || connection_aborted();
if (!$interrupted) {
  foreach (coreui_live_take_sentences($buffer, true) as $sentence) {
    $sentence = coreui_live_spoken(coreui_live_clean_reply($sentence));
    if ($sentence === '') continue;
    if ($firstSentence === null) $firstSentence = microtime(true) - $t1;
    $send('sentence', ['index' => $index++, 'text' => $sentence]);
  }
}
$reply = coreui_live_clean_reply((string)$result['text']);
$replyId = 0;
if ($reply !== '') {
  $replyId = ember_insert($pdo, $reply, 'console', null, $uid, $sessionId, $userMessageId);
}
$timings = ['transcript_s' => round($transcriptS, 1), 'first_sentence_s' => $firstSentence !== null ? round($transcriptS + $firstSentence, 1) : null,
  'core' => $core] + coreui_live_prompt_stats($result['final']);
ember_debug_log('coreui_live_turn', $timings + ['interrupted' => $interrupted, 'chars' => mb_strlen($reply)]);
if ($result['error'] !== '' && $reply === '') {
  $send('error', ['error' => 'core_failed', 'user_message_id' => $userMessageId]);
  exit;
}
$send('done', ['user_message_id' => $userMessageId, 'reply_id' => $replyId, 'interrupted' => $interrupted, 'timings' => $timings]);
