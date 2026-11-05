<?php
declare(strict_types=1);
// CoreAI 0.6.17 (P20.1): voice messages and audio files, heard by Mara herself.
//   php scripts/voice-input-selftest.php
// Offline: cutting at pauses (at most 60 s per WAV, Gemma 4's limit), the
// audio budget of the context window, the task for Mara, the transcription
// request for the history (Google's template, neutral SYSTEM text, no
// thinking, no options except the KI-Profil context window) and the wiring of
// upload, chat call, stream, history, endpoint, page and nginx.
// With ffmpeg (as in the php container): a real Opus recording becomes WAVs
// for Mara's own call (frames folder, images field); guests and Cores without
// audio get the honest note; audio-only WebM is audio, not video. Against a
// stand-in Ollama (php -S) with the model queue counted: the transcript after
// the answer (one queued call per part, one retry when Core claims there was
// no recording, silence never sent, cancel, full queue), its cache and the
// lines it gives the history.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$frames = sys_get_temp_dir() . '/coreai-vi-frames-' . bin2hex(random_bytes(4));
define('STU_EMBER_VIDEO_FRAME_DIR', $frames);

function vi_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Voice input FAIL: {$why}\n"); exit(1); }
}

// The model queue, cancel check and the bits of api/chat.php the attachments need, counted here.
function vi_reset(): void {
  $GLOBALS['VI'] = ['acquire' => 0, 'release' => 0, 'depth' => 0, 'max_depth' => 0, 'waits' => [], 'refuse' => false, 'cancel' => false,
    'hears' => ['completion', 'vision', 'audio'], 'url' => $GLOBALS['VI']['url'] ?? '', 'guest' => false, 'language' => 'de', 'ctx' => null];
}
vi_reset();
function ember_global_lock_acquire(int $timeoutSeconds = 90): mixed {
  $v = &$GLOBALS['VI'];
  if ($v['refuse']) return false;
  $v['acquire']++;
  $v['waits'][] = $timeoutSeconds;
  $v['depth']++;
  $v['max_depth'] = max($v['max_depth'], $v['depth']);
  return 'db_lock';
}
function ember_generation_lock_release($slot): void { $GLOBALS['VI']['release']++; $GLOBALS['VI']['depth']--; }
function ember_agent_cancel_requested(bool $force = false): bool { return $GLOBALS['VI']['cancel']; }
function ember_cfg(string $name, $default = null) { return defined($name) ? constant($name) : $default; }
function ember_debug_log(string $type, array $meta = []): void {}
function ember_model(): string { return 'core-test:latest'; }
function ember_url(): string { return $GLOBALS['VI']['url']; }
function ember_keep_alive() { return -1; }
function ember_profile_num_ctx(string $model): ?int { return null; }
function ember_ollama_context_window(string $model): ?int { return $GLOBALS['VI']['ctx']; }
function ember_ollama_show_json(string $model): ?array {
  return $GLOBALS['VI']['hears'] === null ? null : ['capabilities' => $GLOBALS['VI']['hears']];
}
function coreui_guest_is(PDO $pdo, int $uid): bool { return $GLOBALS['VI']['guest']; }
function coreui_ai_runtime_settings(): array { return ['main_language' => $GLOBALS['VI']['language']]; }
require_once $root . '/api/ember_attachments.php';

// --- Offline ---------------------------------------------------------------------------------
vi_test(COREUI_VOICE_INPUT_MAX_SECONDS === 120 && COREUI_VOICE_FILE_MAX_SECONDS === 600 && COREUI_VOICE_PART_MAX_SECONDS === 60,
  'microphone 2 minutes, files up to 10 minutes, at most 60 seconds per WAV');
vi_test(coreui_voice_native_budget(null) === 600 && coreui_voice_native_budget(65536) === 600 && coreui_voice_native_budget(16384) === 262
  && coreui_voice_native_budget(4096) === 65 && coreui_voice_native_budget(1024) === 60,
  'audio takes at most 40 % of the context window (25 tokens per second), at least one WAV');
vi_test(coreui_voice_is_voice_message('Sprachnachricht-2026-11-05_10-00-00.webm') && !coreui_voice_is_voice_message('besprechung.webm'),
  'a recording from the microphone is a voice message');
vi_test(coreui_voice_language_name('de') === 'German' && coreui_voice_language_name('en') === 'English'
  && coreui_voice_language_name('fr') === 'German', 'the account language picks the template language');
vi_test(coreui_voice_core_hears(['capabilities' => ['completion', 'vision', 'audio']]) === true
  && coreui_voice_core_hears(['capabilities' => ['completion', 'vision']]) === false
  && coreui_voice_core_hears(null) === null && coreui_voice_core_hears(['details' => []]) === null,
  'a Core hears when Ollama lists audio; unknown when Ollama does not say');

$log = "[silencedetect @ 0x1] silence_start: 25.0012\n[silencedetect @ 0x1] silence_end: 26.004 | silence_duration: 1.0\n"
  . "size=N/A time=00:00:30\n[silencedetect @ 0x1] silence_start: 51\n[silencedetect @ 0x1] silence_end: 52 | silence_duration: 1\n"
  . "[silencedetect @ 0x1] silence_start: 118.5\n";
vi_test(coreui_voice_parse_silences($log, 120.0) === [[25.0012, 26.004], [51.0, 52.0], [118.5, 120.0]], 'pauses read, a pause at the end included');
$partsOk = static function (array $parts, float $total): bool {
  $pos = 0.0;
  foreach ($parts as [$a, $b]) {
    if (abs($a - $pos) > 0.001 || $b - $a > 60.0 || $b <= $a) return false;
    $pos = $b;
  }
  return abs($pos - $total) < 0.001;
};
vi_test(coreui_voice_parts(50.0, []) === [[0.0, 50.0]], 'a short recording is one WAV');
vi_test(coreui_voice_parts(150.0, []) === [[0.0, 60.0], [60.0, 120.0], [120.0, 150.0]], 'without pauses: hard cuts at 60 s');
$cut = coreui_voice_parts(150.0, [[10.0, 11.0], [29.0, 30.0], [54.0, 55.0], [100.0, 101.2]]);
vi_test($cut === [[0.0, 54.5], [54.5, 100.6], [100.6, 150.0]] && $partsOk($cut, 150.0), 'cut in the middle of the latest pause before 60 s');
vi_test(coreui_voice_parts(120.1, []) === [[0.0, 60.0], [60.0, 120.0]], 'a rest under 0.3 s is dropped');
vi_test(coreui_voice_part_silent([0.0, 5.0], [[0.0, 5.0]]) && !coreui_voice_part_silent([0.0, 5.0], [[1.0, 5.0]]), 'a part that is all pause');
vi_test(coreui_voice_stamp(75.9) === '01:15' && coreui_voice_stamp(600) === '10:00', 'time marks');

$task = coreui_voice_listen_task('Patrick', '', [['voice' => true, 'seconds' => 120.0, 'paths' => ['a', 'b', 'c'],
  'stamps' => ['00:00', '00:51', '01:43'], 'truncated' => true]]);
vi_test(str_starts_with($task, 'Patrick hat dir gesprochen:') && str_contains($task, '[Angehaengt: Sprachaufnahme, 120 Sekunden] eine Sprachnachricht')
  && str_contains($task, 'als 3 Audioteile nacheinander (Beginn 00:00, 00:51, 01:43)') && str_contains($task, 'nur die ersten 02:00 Minuten')
  && str_contains($task, 'Du hoerst die Aufnahme selbst.') && !str_contains($task, 'schreibt'), 'Mara is told she hears the recording, in how many WAVs');
$task = coreui_voice_listen_task('Patrick', 'Was meinst du?', [['voice' => false, 'name' => 'besprechung.mp3', 'seconds' => 42.0,
  'paths' => ['a'], 'stamps' => ['00:00']]]);
vi_test(str_contains($task, 'die Audiodatei "besprechung.mp3", als Audio.') && str_contains($task, 'Dazu schreibt Patrick: "Was meinst du?"')
  && str_contains($task, 'stammen nicht von Patrick'), 'an audio file with text: both reach Mara');

$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'German');
vi_test($payload['stream'] === false && $payload['think'] === false && !array_key_exists('options', $payload)
  && !array_key_exists('keep_alive', $payload), 'transcript: no thinking, no options');
vi_test($payload['messages'][0] === ['role' => 'system', 'content' => coreui_voice_transcribe_system()]
  && $payload['messages'][1]['content'] === coreui_voice_transcribe_prompt('German') && $payload['messages'][1]['images'] === ['QUJD'],
  'transcript: neutral SYSTEM text, Google\'s template, the audio in images');
$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'English', -1, 8192);
vi_test($payload['options'] === ['num_ctx' => 8192] && $payload['keep_alive'] === -1
  && str_starts_with($payload['messages'][1]['content'], 'Transcribe the following speech segment in English into English text.'),
  'English template; the KI-Profil context window is kept so Core is not reloaded');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$noDash = static fn(string $t): bool => preg_match('/[\x{2013}\x{2014}]/u', $t) !== 1;
vi_test($noDash($read('api/voice_transcribe.php') . $read('js/coreui-voice-input.js') . $read('api/voice_input.php')), 'no long dashes');
$chat = $read('api/chat.php');
vi_test(str_contains($chat, '$listenThink = $hasAudio ? false : null;')
  && str_contains($chat, "      [],\n      \$imageUrl,\n      \$listenThink\n    );"), 'Mara hears without thinking (operator decision)');
vi_test(str_contains($chat, "if (\$hasAudio && is_string(\$imgReply) && coreui_voice_reply_kind(\$imgReply) === 'missed') {"),
  'one retry when Core claims there was no recording');
vi_test(str_contains($chat, "      \$imageUrl,\n      \$listenThink\n    );\n    // Servergenerierte") || str_contains($chat, "\$imgReply,\n      \$imageUrl,\n      \$listenThink\n    );"),
  'a continued answer keeps thinking off');
vi_test(str_contains($chat, "  ?bool \$thinkOverride = null\n): ?string {") && str_contains($chat, "      \$imageUrl,\n      \$thinkOverride\n    );"),
  'the continuation passes the think switch on');
vi_test(str_contains($chat, "ember_attach_audio_cleanup(\$meta['dir'] ?? null);") && str_contains($chat, '$visionPrompt = coreui_voice_listen_task($senderName,'),
  'the WAVs are removed after the call; Mara gets the listening task');
vi_test(str_contains($chat, '"SELECT id, user_id, character_id, character_name, message FROM stu_chat_messages')
  && str_contains($chat, 'ember_attach_audio_history_notes($pdo, $consoleUid,'), 'later turns read what was said');
$stream = $read('api/console_stream.php');
$doneAt = strpos($stream, "  sse_send('done', [\n    'work_events'");
$transcriptAt = strpos($stream, 'ember_attach_audio_transcribe_after($pdo, (int)$uid, $attachmentIds);');
vi_test($doneAt !== false && $transcriptAt > $doneAt && $transcriptAt < strpos($stream, 'coreui_compact_with_core($pdo, (int)$uid, $sessionId, false);'),
  'the transcript is written after the answer is out, before the summary');
vi_test(str_contains($read('api/console_upload.php'), "if (coreui_voice_has_video_stream((string)\$file['tmp_name']) === false) {"),
  'audio-only WebM is stored as audio');
$app = $read('js/console-app.js');
vi_test(str_contains($app, 'window.CoreUIComposer = {') && str_contains($app, "aud.setAttribute('data-uuid', String(att.uuid));")
  && str_contains($app, 'pendingSendAfterUpload = {text: inputEl ? String(inputEl.value || \'\') : \'\', sessionId: String(ses.id)};'),
  'the chat sends a voice message at once, with the text in the field');
$api = $read('api/voice_input.php');
vi_test(!str_contains($api, '$_FILES') && !str_contains($api, "'transcribe'")
  && strpos($api, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, "if (\$action === 'transcript') {")
  && str_contains($api, "WHERE uuid = ? AND user_id = ? AND kind = ? LIMIT 1"), 'endpoint: status and the own transcript only, no guests');
$js = $read('js/coreui-voice-input.js');
vi_test(str_contains($js, 'composer.sendVoice(file)') && str_contains($js, "var name = 'Sprachnachricht-'")
  && !str_contains($js, "getElementById('inputMessage')") && str_contains($js, 'VOICED_MIN_MS') && str_contains($js, 'if (!state.allowed) return;'),
  'page: sends the recording, no text in the field, silence check, nothing for guests');
vi_test(str_contains($read('js/coreui-core-mode.js'), "new CustomEvent('coreui:core'"), 'a Core change re-checks the microphone');
$page = $read('app.html');
vi_test(strpos($page, 'js/coreui-voice-input.js') !== false && strpos($page, 'js/coreui-voice-input.js') < strpos($page, 'js/console-app.js'),
  'chat page loads the voice input');
vi_test(str_contains($read('css/console.css'), '.composer-inner.has-mic {') && str_contains($read('css/console.css'), '.msg-transcript {'),
  'room for the button, a small transcript line');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  $conf = $read($nginx);
  $routes = preg_match('#location ~ \^/api/\(\?:([a-z_|]+)\)#', $conf, $m) === 1 ? explode('|', $m[1]) : [];
  vi_test(in_array('voice_input', $routes, true) && in_array('console_upload', $routes, true), $nginx . ' lets upload and voice input through');
  vi_test(substr_count($conf, 'camera=(), microphone=(self), geolocation=()') === 3 && !str_contains($conf, 'microphone=()'),
    $nginx . ': the page itself may use the microphone, camera and location stay off');
}
$theme = $read('css/coreui-theme.css');
vi_test(str_contains($theme, 'html[data-coreui-theme="dark"] .composer-voice-state {') && str_contains($theme, 'html[data-coreui-theme="dark"] .msg-transcript {'),
  'night mode: strip and transcript line use the dark palette');
vi_test(str_contains($read('api/voice_store.php'), '|t-[0-9a-f]{64}\.json)$/'), 'transcripts expire with the read-aloud cache (7 days)');

$ffmpeg = coreui_voice_bin('ffmpeg');
if ($ffmpeg === null || coreui_voice_bin('ffprobe') === null || !function_exists('proc_open') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI voice input: WAV parts, budget, task, transcript request and wiring OK (no ffmpeg or SQLite here: audio not run)\n";
  exit(0);
}

// --- With ffmpeg and a stand-in Ollama -------------------------------------------------------
$base = sys_get_temp_dir() . '/coreai-vi-test-' . bin2hex(random_bytes(4));
mkdir($base, 0700);
mkdir($frames, 0700);
file_put_contents($base . '/router.php', <<<'PHP'
<?php
$dir = __DIR__;
$body = (string)file_get_contents('php://input');
$n = count(glob($dir . '/req-*.json') ?: []);
file_put_contents($dir . '/req-' . $n . '.json', $body);
$replies = json_decode((string)@file_get_contents($dir . '/replies.json'), true) ?: [];
$reply = array_shift($replies);
file_put_contents($dir . '/replies.json', json_encode($replies));
header('Content-Type: application/json');
if (is_array($reply)) { http_response_code((int)$reply['status']); echo json_encode(['error' => $reply['error']]); return; }
echo json_encode(['model' => 'core-test:latest', 'message' => ['role' => 'assistant', 'content' => (string)$reply], 'done' => true]);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $base . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$GLOBALS['VI']['url'] = "http://127.0.0.1:{$port}/api/chat";
putenv('COREUI_VOICE_CACHE_DIR=' . $base . '/cache');

$ff = static function (array $args) use ($ffmpeg): void {
  exec(escapeshellarg($ffmpeg) . ' -nostdin -hide_banner -loglevel error -y ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $o, $s);
  vi_test($s === 0, 'test audio made: ' . implode(' ', $o));
};
// 150 s like a browser recording (Opus in WebM): 25 s of sound, 1 s of silence, repeated.
$tone = static fn(int $seconds): string => "aevalsrc='if(lt(mod(t,26),25),0.5*sin(2*PI*440*t),0)':s=48000:d={$seconds}";
$long = $base . '/recording.webm';
$ff(['-f', 'lavfi', '-i', $tone(150), '-c:a', 'libopus', '-b:a', '32k', $long]);
$short = $base . '/short.m4a';
$ff(['-f', 'lavfi', '-i', $tone(10), '-c:a', 'aac', $short]);
$quiet = $base . '/quiet.ogg';
$ff(['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', '6', '-c:a', 'libopus', $quiet]);
$video = $base . '/clip.webm';
$ff(['-f', 'lavfi', '-i', 'testsrc=d=2:s=160x120', '-f', 'lavfi', '-i', 'sine=d=2', '-shortest', '-c:v', 'libvpx', '-c:a', 'libopus', $video]);
$cover = $base . '/song.mp3';
$ff(['-f', 'lavfi', '-i', 'sine=d=2', '-f', 'lavfi', '-i', 'color=c=red:s=64x64:d=1', '-map', '0', '-map', '1', '-c:a', 'libmp3lame',
  '-c:v', 'mjpeg', '-disposition:v', 'attached_pic', '-frames:v', '1', $cover]);
$junk = $base . '/junk.webm';
file_put_contents($junk, str_repeat('kein Audio ', 400));

$replies = static fn(array $list) => file_put_contents($base . '/replies.json', json_encode($list));
$requests = static function () use ($base): array {
  $files = glob($base . '/req-*.json') ?: [];
  natsort($files);
  $out = array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true), array_values($files));
  foreach ($files as $f) unlink($f);
  return $out;
};
$workdirs = static fn(): int => count(glob(rtrim(sys_get_temp_dir(), '/') . '/coreai-voice-*') ?: []);
$options = static fn(array $extra = []): array => $extra + ['url' => $GLOBALS['VI']['url'], 'model' => 'core-test:latest',
  'language' => 'de', 'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'queue_wait' => 300];
$rel = static fn(string $file): string => str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($file, '/');
$before = $workdirs();

try {
  // Upload: a recording without a picture is audio; a real video and cover art are told apart.
  vi_test(coreui_voice_has_video_stream($long) === false && coreui_voice_has_video_stream($video) === true
    && coreui_voice_has_video_stream($cover) === false && coreui_voice_has_video_stream($junk) === null,
    'audio-only WebM is audio, a real video is video, cover art is no video');

  // Conversion: Opus in WebM -> 16 kHz mono 16 bit, cut at 2 minutes and said so.
  $wav = $base . '/check.wav';
  $converted = coreui_voice_to_wav($long, $wav, 120);
  vi_test($converted['ok'] && abs($converted['seconds'] - 120.0) < 0.1 && $converted['truncated'] === true
    && abs((float)$converted['source_seconds'] - 150.0) < 0.5, 'converted, 2 minutes kept, the cut is reported');
  vi_test(coreui_wav_gemma_ready(coreui_voice_wav_file_info($wav)), 'the WAV is exactly what Gemma accepts');
  $pauses = coreui_voice_silences($wav, 120.0);
  vi_test(count($pauses) === 4 && abs($pauses[0][0] - 25.0) < 0.2 && abs($pauses[3][1] - 104.0) < 0.2, 'the four pauses found');
  vi_test(!coreui_voice_to_wav($junk, $base . '/junk.wav', 120)['ok'], 'something that is no audio is refused');

  // WAVs for Mara: at most 60 s each, cut in the pauses, nothing but silence leaves nothing.
  $target = $base . '/native';
  mkdir($target, 0700);
  $native = coreui_voice_prepare_native($long, $target, 120);
  vi_test($native['ok'] && count($native['paths']) === 3 && $native['stamps'] === ['00:00', '00:51', '01:43'] && $native['truncated'] === true,
    'three WAVs from two minutes, starts at the pauses');
  foreach ($native['paths'] as $path) {
    $info = coreui_voice_wav_file_info($path);
    vi_test(dirname($path) === $target && coreui_wav_gemma_ready($info) && $info['seconds'] <= 60.0, 'each WAV valid and at most 60 s');
  }
  $quietDir = $base . '/quiet';
  mkdir($quietDir, 0700);
  $none = coreui_voice_prepare_native($quiet, $quietDir, 120);
  vi_test(!$none['ok'] && $none['error'] === 'no_speech' && glob($quietDir . '/*') === [], 'pure silence: nothing for Mara');
  vi_test($workdirs() === $before, 'no temporary files left');

  // The chat: a voice message becomes WAVs in the images field of Mara's own call.
  $pdo = new PDO('sqlite::memory:');
  $pdo->exec('CREATE TABLE stu_console_media (uuid TEXT, user_id INTEGER, kind TEXT, rel_path TEXT, orig_name TEXT, mime_type TEXT,'
    . ' file_size INTEGER, public_url TEXT, created_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_message_attachments (message_id INTEGER, media_uuid TEXT, user_id INTEGER, position INTEGER)');
  $addMedia = static function (string $uuid, string $file, string $name) use ($pdo, $rel): void {
    $pdo->prepare('INSERT INTO stu_console_media VALUES (?, 7, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$uuid, 'audio', $rel($file), $name, 'audio/webm', filesize($file), '', gmdate('Y-m-d H:i:s')]);
  };
  $voiceId = str_repeat('a1', 16);
  $fileId = str_repeat('b2', 16);
  $quietId = str_repeat('c3', 16);
  $addMedia($voiceId, $long, 'Sprachnachricht-2026-11-05_10-00-00.webm');
  $addMedia($fileId, $long, 'besprechung.webm');
  $addMedia($quietId, $quiet, 'Sprachnachricht-2026-11-05_10-05-00.webm');
  vi_test(ember_attach_needs_vision_path($pdo, $voiceId, 7), 'a voice message takes the media call, not the text stream');

  vi_reset();
  $message = '[file:' . $voiceId . ']';
  unset($GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'], $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META']);
  $block = ember_attachment_block($pdo, $message, 7);
  $images = $GLOBALS['STU_EMBER_ATTACHMENT_IMAGES'] ?? [];
  $meta = $GLOBALS['STU_EMBER_ATTACHMENT_VISION_META'] ?? [];
  vi_test($message === '' && str_contains($block, 'Das ist eine Sprachnachricht (Laenge etwa 02:30') && str_contains($block, 'in 3 Teilen nacheinander')
    && str_contains($block, 'nur die ersten 02:00 Minuten'), 'Mara is told: a voice message, its length, 3 parts, 2 minutes kept');
  vi_test(count($images) === 3 && count($meta) === 1 && $meta[0]['type'] === 'audio' && $meta[0]['voice'] === true && $meta[0]['paths'] === $images,
    'the three WAVs travel in the images field like video frames');
  $clipDir = (string)$meta[0]['dir'];
  vi_test(str_starts_with($clipDir, $frames . '/audio_') && dirname($images[0]) === $clipDir, 'in the frame folder that api/chat.php sends from');
  vi_test($GLOBALS['VI']['acquire'] === 0 && $requests() === [], 'hearing needs no extra Core call before the answer');
  ember_attach_audio_cleanup($base);
  vi_test(is_dir($base), 'cleanup never touches a folder outside the frame folder');
  ember_attach_audio_cleanup($clipDir);
  vi_test(!is_dir($clipDir), 'the WAVs are removed after the call');

  // An uploaded file: the whole file, or what the context window allows (said openly).
  vi_reset();
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  $note = ember_attachment_single_block($pdo, $fileId, 7, 4000);
  $clips = $GLOBALS['STU_EMBER_AUDIO_CLIPS'] ?? [];
  vi_test(str_contains($note, 'Das ist eine Audiodatei') && count($clips['paths'] ?? []) === 3 && empty($clips['truncated']) && $clips['voice'] === false,
    'an audio file of 2:30 is heard completely');
  ember_attach_audio_cleanup($clips['dir'] ?? null);
  vi_reset();
  $GLOBALS['VI']['ctx'] = 4096;
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  $note = ember_attachment_single_block($pdo, $fileId, 7, 4000);
  $clips = $GLOBALS['STU_EMBER_AUDIO_CLIPS'] ?? [];
  vi_test(count($clips['paths'] ?? []) === 2 && !empty($clips['truncated']) && str_contains($note, 'nur die ersten 01:05 Minuten'),
    'a small context window: the first 65 s, said openly');
  ember_attach_audio_cleanup($clips['dir'] ?? null);

  // Guests and Cores without audio: the honest note, nothing prepared, nothing sent.
  vi_reset();
  $GLOBALS['VI']['guest'] = true;
  unset($GLOBALS['STU_EMBER_AUDIO_CLIPS']);
  vi_test(str_contains(ember_attachment_single_block($pdo, $voiceId, 7, 4000), 'Du kannst sie NICHT anhoeren') && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS'])
    && !ember_attach_needs_vision_path($pdo, $voiceId, 7), 'guests: honest note, no audio');
  vi_reset();
  $GLOBALS['VI']['hears'] = ['completion', 'vision'];
  vi_test(str_contains(ember_attachment_single_block($pdo, $voiceId, 7, 4000), 'keinen Audio-Eingang') && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS']),
    'a Core without audio: honest note, no audio');
  vi_reset();
  vi_test(str_contains(ember_attachment_single_block($pdo, $quietId, 7, 4000), 'nur Stille') && !isset($GLOBALS['STU_EMBER_AUDIO_CLIPS']),
    'a silent recording is named as such');
  vi_test(glob($frames . '/audio_*') === [], 'no WAVs left behind');

  // After the answer: the transcript for the history, through the queue, with one retry.
  vi_reset();
  $replies(['Bitte stellen Sie die Aufnahme zur Verfügung.', 'Erster Teil.', '(keine Sprache)', 'Dritter Teil.']);
  vi_test(ember_attach_audio_transcribe_after($pdo, 7, [$voiceId, 'nichts', $quietId]) === 2, 'two transcripts written (voice and silence)');
  $sent = $requests();
  vi_test(count($sent) === 4 && $GLOBALS['VI']['acquire'] === 4 && $GLOBALS['VI']['release'] === 4 && $GLOBALS['VI']['max_depth'] === 1
    && $GLOBALS['VI']['waits'] === [600, 600, 600, 600], 'one queued call per part and one retry; silence is never sent');
  foreach ($sent as $call) {
    vi_test($call['think'] === false && !isset($call['options']) && $call['messages'][1]['content'] === coreui_voice_transcribe_prompt('German')
      && $call['messages'][0]['content'] === coreui_voice_transcribe_system(), 'transcript: template, neutral SYSTEM text, no thinking');
    $info = coreui_wav_info((string)base64_decode((string)$call['messages'][1]['images'][0], true));
    vi_test(coreui_wav_gemma_ready($info) && $info['seconds'] <= 60.0, 'each transcript part a valid WAV of at most 60 s');
  }
  $done = coreui_voice_transcript_load(7, $voiceId);
  vi_test(is_array($done) && $done['state'] === 'done' && $done['text'] === 'Erster Teil. Dritter Teil.', 'transcript kept for the account');
  vi_test((coreui_voice_transcript_load(7, $quietId)['state'] ?? '') === 'silent', 'a silent recording is marked as such');
  vi_test(ember_attach_audio_transcribe_after($pdo, 7, [$voiceId]) === 0 && $requests() === [], 'written once, then from the cache');
  vi_test(coreui_voice_transcript_load(8, $voiceId) === null, 'another account does not see it');
  coreui_voice_transcript_save(7, $fileId, ['state' => 'pending', 'started' => time() - 2 * COREUI_VOICE_TRANSCRIPT_PENDING_SECONDS]);
  vi_test(coreui_voice_transcript_load(7, $fileId) === null, 'a transcript that never finished is no longer pending');

  // Later turns: what was said, per message.
  foreach ([[10, $voiceId], [11, $fileId], [12, $quietId]] as [$msg, $uuid]) {
    $pdo->prepare('INSERT INTO stu_console_message_attachments VALUES (?, ?, 7, 0)')->execute([$msg, $uuid]);
  }
  $notes = ember_attach_audio_history_notes($pdo, 7, [10, 11, 12, 13]);
  vi_test(($notes[10] ?? '') === '[Sprachnachricht, Mitschrift: Erster Teil. Dritter Teil.]' && ($notes[11] ?? '') === '[Audiodatei "besprechung.webm"]'
    && ($notes[12] ?? '') === '[Sprachnachricht ohne gesprochene Worte]' && !isset($notes[13]), 'the history knows what was said');
  vi_test(ember_attach_audio_history_notes($pdo, 8, [10, 11]) === [], 'never from another account');

  // The transcript itself: errors, cancel, full queue, outside the queue.
  vi_reset();
  $replies(['Es ist kein Audio zur Analyse vorhanden.', 'Bitte stellen Sie die Aufnahme zur Verfügung.']);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'missed' && count($requests()) === 2, 'one retry only, then "not understood"');
  vi_reset();
  $replies([['status' => 500, 'error' => 'this model does not support audio input']]);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'core_without_audio' && $GLOBALS['VI']['release'] === 1, 'a Core without audio is named');
  $requests();
  vi_reset();
  $replies(['Hello there.']);
  $result = coreui_voice_transcribe_file($short, $options(['language' => 'en', 'num_ctx' => 8192, 'keep_alive' => -1]));
  $sent = $requests();
  vi_test($result['ok'] && $result['text'] === 'Hello there.' && str_contains($sent[0]['messages'][1]['content'], 'in English into English text')
    && $sent[0]['options'] === ['num_ctx' => 8192], 'an English account gets the English template, the KI-Profil window stays');
  vi_reset();
  $GLOBALS['VI']['refuse'] = true;
  vi_test(coreui_voice_transcribe_file($short, $options())['error'] === 'queue_timeout' && $requests() === [], 'full queue: nothing sent');
  vi_reset();
  $GLOBALS['VI']['cancel'] = true;
  vi_test(coreui_voice_transcribe_file($short, $options())['error'] === 'cancelled' && $requests() === [], 'a cancelled run sends nothing');
  $probe = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($root . '/api/voice_transcribe.php', true) . ';'
    . ' echo coreui_voice_transcribe_part("http://127.0.0.1:1/api/chat", "m", "x", "German", 5)["error"];');
  vi_test(trim((string)shell_exec($probe)) === 'queue_unavailable', 'no Core call outside the model queue');
  vi_test($workdirs() === $before, 'no temporary files left');
} finally {
  proc_terminate($server);
  proc_close($server);
  exec('rm -rf ' . escapeshellarg($base) . ' ' . escapeshellarg($frames));
}
echo "CoreAI voice input: Mara hears voice messages and audio files herself (WAVs of at most 60 s in her own call, no thinking),"
  . " guests and Cores without audio get the honest note, audio-only WebM is audio, the transcript after the answer"
  . " (queue, retry, silence, cache) and the history OK\n";
