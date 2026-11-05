<?php
declare(strict_types=1);
// CoreAI 0.6.14 (P20.1): voice input and audio attachments become text.
//   php scripts/voice-input-selftest.php
// Offline: cutting at pauses, the transcription request (Google's template,
// neutral SYSTEM text, no thinking, no options except the KI-Profil context
// window), error texts and the wiring of endpoint, page and attachments.
// With ffmpeg (as in the php container): real conversion of a compressed
// recording, the 2-minute limit, pauses, and the whole way to the text against
// a stand-in Ollama (php -S), with the model queue counted: one queued call
// per part, one retry when Core claims there was no recording, silence never
// sent, cancel, a full queue, and an audio attachment heard once and then
// taken from the account's cache, never for guests or a Core without audio.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

function vi_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Voice input FAIL: {$why}\n"); exit(1); }
}

// The model queue, cancel check and the bits of api/chat.php the attachment needs, counted here.
$GLOBALS['VI'] = ['acquire' => 0, 'release' => 0, 'depth' => 0, 'max_depth' => 0, 'waits' => [], 'refuse' => false, 'cancel' => false,
  'hears' => ['completion', 'vision', 'audio'], 'url' => '', 'guest' => false, 'work' => [], 'language' => 'de'];
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
function ember_ollama_show_json(string $model): ?array {
  return $GLOBALS['VI']['hears'] === null ? null : ['capabilities' => $GLOBALS['VI']['hears']];
}
function coreui_guest_is(PDO $pdo, int $uid): bool { return $GLOBALS['VI']['guest']; }
function coreui_ai_runtime_settings(): array { return ['main_language' => $GLOBALS['VI']['language']]; }
function coreui_work_record(PDO $pdo, string $kind, string $state, string $title, array $meta = []): ?array {
  vi_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $title) === 1, 'work path title allowed: ' . $title);
  $GLOBALS['VI']['work'][] = $state . ':' . $title;
  return null;
}
require_once $root . '/api/ember_attachments.php';

// --- Offline ---------------------------------------------------------------------------------
vi_test(COREUI_VOICE_INPUT_MAX_SECONDS === 120 && COREUI_VOICE_FILE_MAX_SECONDS === 600 && COREUI_VOICE_PART_MAX_SECONDS === 60,
  'microphone 2 minutes, files 10 minutes, parts of at most 60 seconds');
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
vi_test(coreui_voice_parts(50.0, []) === [[0.0, 50.0]], 'a short recording is one part');
vi_test(coreui_voice_parts(150.0, []) === [[0.0, 60.0], [60.0, 120.0], [120.0, 150.0]], 'without pauses: hard cuts at 60 s');
$cut = coreui_voice_parts(150.0, [[10.0, 11.0], [29.0, 30.0], [54.0, 55.0], [100.0, 101.2]]);
vi_test($cut === [[0.0, 54.5], [54.5, 100.6], [100.6, 150.0]] && $partsOk($cut, 150.0), 'cut in the middle of the latest pause before 60 s');
vi_test(coreui_voice_parts(120.1, []) === [[0.0, 60.0], [60.0, 120.0]], 'a rest under 0.3 s is dropped');
vi_test(coreui_voice_part_silent([0.0, 5.0], [[0.0, 5.0]]) && !coreui_voice_part_silent([0.0, 5.0], [[1.0, 5.0]]), 'a part that is all pause');
vi_test(coreui_voice_stamp(75.9) === '01:15' && coreui_voice_stamp(600) === '10:00', 'time marks');
vi_test(coreui_voice_transcript_with_stamps([[0.0, 54.5, 'Hallo.'], [54.5, 100.6, ' Weiter. ']]) === "[00:00] Hallo.\n[00:54] Weiter.",
  'transcript with time marks');

$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'German');
vi_test($payload['stream'] === false && $payload['think'] === false && !array_key_exists('options', $payload)
  && !array_key_exists('keep_alive', $payload), 'no thinking, no options');
vi_test($payload['messages'][0] === ['role' => 'system', 'content' => coreui_voice_transcribe_system()]
  && $payload['messages'][1]['content'] === coreui_voice_transcribe_prompt('German') && $payload['messages'][1]['images'] === ['QUJD'],
  'neutral SYSTEM text, Google\'s template, the audio in images');
$payload = coreui_voice_transcribe_payload('core-test:latest', 'QUJD', 'English', -1, 8192);
vi_test($payload['options'] === ['num_ctx' => 8192] && $payload['keep_alive'] === -1
  && str_starts_with($payload['messages'][1]['content'], 'Transcribe the following speech segment in English into English text.'),
  'English template; the KI-Profil context window is kept so Core is not reloaded');
foreach (['no_speech', 'missed', 'core_without_audio', 'convert_failed', 'queue_timeout', 'busy', 'too_large'] as $code) {
  vi_test(coreui_voice_error_text($code) !== coreui_voice_error_text('unknown_code_x'), 'own message for ' . $code);
}
vi_test(!preg_match('/[\x{2013}\x{2014}]/u', (string)file_get_contents($root . '/api/voice_transcribe.php')
  . file_get_contents($root . '/js/coreui-voice-input.js') . file_get_contents($root . '/api/voice_input.php')), 'no long dashes');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$api = $read('api/voice_input.php');
vi_test(strpos($api, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, 'stu_require_csrf();')
  && strpos($api, 'stu_require_csrf();') < strpos($api, '$upload = $_FILES[\'audio\']'), 'guests refused, CSRF before the upload is touched');
vi_test(str_contains($api, "if (\$guest) stu_json(['ok' => true, 'allowed' => false]);"), 'status tells guests: no button');
vi_test(preg_match('~\} finally \{\s*// "Sofort loeschen".*?\n\s*@unlink\(\$tmp\);~s', $api) === 1
  && strpos($api, '} finally {') < strpos($api, "stu_json(['ok' => true, 'text' =>"), 'the recording is deleted before the answer leaves');
vi_test(str_contains($api, "'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS") && str_contains($api, 'flock($lock, LOCK_EX | LOCK_NB)')
  && str_contains($api, "(coreui_ai_settings_load(\$pdo, \$uid)['main_language'] ?? 'de')"),
  'two minutes, one transcript per account at a time, in the account language');
vi_test(str_contains($api, "if (\$core['hears'] === false) {"), 'a Core without audio is refused before any work');
$attach = $read('api/ember_attachments.php');
vi_test(str_contains($attach, "'max_seconds' => COREUI_VOICE_FILE_MAX_SECONDS") && !str_contains($attach, 'Gemma 4 26B-A4B hat laut Technical Report'),
  'audio attachments are heard up to 10 minutes');
vi_test(str_contains($read('api/voice_store.php'), '|t-[0-9a-f]{64}\.json)$/'), 'transcripts expire with the read-aloud cache (7 days)');
$js = $read('js/coreui-voice-input.js');
vi_test(str_contains($js, "'X-CoreUI-CSRF': state.csrf") && str_contains($js, "document.getElementById('inputMessage')")
  && str_contains($js, 'VOICED_MIN_MS') && str_contains($js, "if (!state.allowed) return;"),
  'page: CSRF, transcript into the input field, silence check, nothing for guests');
vi_test(str_contains($read('js/coreui-core-mode.js'), "new CustomEvent('coreui:core'"), 'a Core change re-checks the microphone');
$app = $read('app.html');
vi_test(strpos($app, 'js/coreui-voice-input.js') !== false && strpos($app, 'js/coreui-voice-input.js') < strpos($app, 'js/console-app.js'),
  'chat page loads the voice input');
vi_test(str_contains($read('css/console.css'), '.composer-inner.has-mic {'), 'input field has room for the button');
// 0.6.15: on the server nginx answered api/voice_input.php with 404 (not in the list of
// endpoints) and forbade the microphone for the whole page (Permissions-Policy).
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  $conf = $read($nginx);
  $routes = preg_match('#location ~ \^/api/\(\?:([a-z_|]+)\)#', $conf, $m) === 1 ? explode('|', $m[1]) : [];
  vi_test(in_array('voice_input', $routes, true) && in_array('voice', $routes, true), $nginx . ' lets api/voice_input.php through');
  vi_test(substr_count($conf, 'camera=(), microphone=(self), geolocation=()') === 3 && !str_contains($conf, 'microphone=()'),
    $nginx . ': the page itself may use the microphone, camera and location stay off');
}
$theme = $read('css/coreui-theme.css');
vi_test(str_contains($theme, 'html[data-coreui-theme="dark"] .composer-voice-state {') && str_contains($theme, 'html[data-coreui-theme="dark"] .composer-mic-btn {'),
  'night mode: button and strip use the dark surfaces, no bright band');

$ffmpeg = coreui_voice_bin('ffmpeg');
if ($ffmpeg === null || coreui_voice_bin('ffprobe') === null || !function_exists('proc_open')) {
  echo "CoreAI voice input: parts, request, wiring OK (no ffmpeg here: conversion and Core calls not run)\n";
  exit(0);
}

// --- With ffmpeg and a stand-in Ollama -------------------------------------------------------
$base = sys_get_temp_dir() . '/coreai-vi-test-' . bin2hex(random_bytes(4));
mkdir($base, 0700);
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
$reset = static function (): void {
  $GLOBALS['VI'] = ['acquire' => 0, 'release' => 0, 'depth' => 0, 'max_depth' => 0, 'waits' => [], 'refuse' => false, 'cancel' => false,
    'hears' => ['completion', 'vision', 'audio'], 'url' => $GLOBALS['VI']['url'], 'guest' => false, 'work' => [], 'language' => 'de'];
};
$options = static fn(array $extra = []): array => $extra + ['url' => $GLOBALS['VI']['url'], 'model' => 'core-test:latest',
  'language' => 'de', 'max_seconds' => COREUI_VOICE_INPUT_MAX_SECONDS, 'queue_wait' => 300];
$before = $workdirs();

try {
  // Conversion: Opus in WebM -> 16 kHz mono 16 bit, cut at 2 minutes and said so.
  $wav = $base . '/check.wav';
  $converted = coreui_voice_to_wav($long, $wav, 120);
  vi_test($converted['ok'] && abs($converted['seconds'] - 120.0) < 0.1 && $converted['truncated'] === true
    && abs((float)$converted['source_seconds'] - 150.0) < 0.5, 'converted, 2 minutes kept, the cut is reported');
  vi_test(coreui_wav_gemma_ready(coreui_voice_wav_file_info($wav)), 'the WAV is exactly what Gemma accepts');
  $pauses = coreui_voice_silences($wav, 120.0);
  vi_test(count($pauses) === 4 && abs($pauses[0][0] - 25.0) < 0.2 && abs($pauses[3][1] - 104.0) < 0.2, 'the four pauses found');
  $parts = coreui_voice_parts(120.0, $pauses);
  vi_test(count($parts) === 3 && abs($parts[0][1] - 51.5) < 0.2 && abs($parts[1][1] - 103.5) < 0.2 && $partsOk($parts, 120.0),
    'three parts, cut in the pauses at 51.5 s and 103.5 s');
  vi_test(!coreui_voice_to_wav($junk, $base . '/junk.wav', 120)['ok'], 'something that is no audio is refused');

  // Microphone: three parts; Core first claims there was no recording (one retry), one part is heard as silence.
  $reset();
  $replies(['Bitte stellen Sie die Aufnahme zur Verfügung.', 'Erster Teil.', '(keine Sprache)', 'Dritter Teil.']);
  $result = coreui_voice_transcribe_file($long, $options());
  $sent = $requests();
  vi_test($result['ok'] && $result['text'] === 'Erster Teil. Dritter Teil.' && $result['truncated'] === true
    && abs($result['seconds'] - 120.0) < 0.1, 'transcript joined, silence dropped, cut at 2 minutes');
  vi_test(count($sent) === 4, 'three parts and one retry: four calls');
  vi_test($GLOBALS['VI']['acquire'] === 4 && $GLOBALS['VI']['release'] === 4 && $GLOBALS['VI']['max_depth'] === 1
    && $GLOBALS['VI']['waits'] === [300, 300, 300, 300], 'every call through the model queue, one at a time, released');
  $lengths = [];
  foreach ($sent as $call) {
    vi_test($call['think'] === false && $call['stream'] === false && !isset($call['options']) && $call['model'] === 'core-test:latest',
      'transcription without thinking and without options');
    vi_test($call['messages'][0]['content'] === coreui_voice_transcribe_system() && $call['messages'][1]['content'] === coreui_voice_transcribe_prompt('German'),
      'neutral SYSTEM text and the German template');
    $info = coreui_wav_info((string)base64_decode((string)$call['messages'][1]['images'][0], true));
    vi_test(coreui_wav_gemma_ready($info) && $info['seconds'] <= 60.0, 'each part a valid WAV of at most 60 s');
    $lengths[] = round($info['seconds']);
  }
  vi_test($lengths[0] === $lengths[1] && $lengths[0] === 52.0, 'the retry sends the same part again');
  vi_test($workdirs() === $before, 'no temporary files left');

  // English account, own context window: the template follows, the window is kept.
  $reset();
  $replies(['Hello there.']);
  $result = coreui_voice_transcribe_file($short, $options(['language' => 'en', 'num_ctx' => 8192, 'keep_alive' => -1]));
  $sent = $requests();
  vi_test($result['ok'] && $result['text'] === 'Hello there.' && $result['truncated'] === false && count($sent) === 1
    && str_contains($sent[0]['messages'][1]['content'], 'in English into English text') && $sent[0]['options'] === ['num_ctx' => 8192],
    'an English account gets the English template, the KI-Profil window stays');

  // Core claims twice there was no recording: said openly.
  $reset();
  $replies(['Es ist kein Audio zur Analyse vorhanden.', 'Bitte stellen Sie die Aufnahme zur Verfügung.']);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'missed' && count($requests()) === 2, 'one retry only, then "not understood"');

  // Ollama refuses audio for this Core.
  $reset();
  $replies([['status' => 500, 'error' => 'this model does not support audio input']]);
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'core_without_audio' && $GLOBALS['VI']['release'] === 1, 'a Core without audio is named');
  $requests();

  // Pure silence never reaches Core; a full queue and a cancel send nothing.
  $reset();
  $result = coreui_voice_transcribe_file($quiet, $options());
  vi_test(!$result['ok'] && $result['error'] === 'no_speech' && $GLOBALS['VI']['acquire'] === 0 && $requests() === [], 'silence is not sent');
  $reset();
  $GLOBALS['VI']['refuse'] = true;
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'queue_timeout' && $requests() === [], 'full queue: nothing sent');
  $reset();
  $GLOBALS['VI']['cancel'] = true;
  $result = coreui_voice_transcribe_file($short, $options());
  vi_test(!$result['ok'] && $result['error'] === 'cancelled' && $requests() === [], 'a cancelled chat run sends nothing');

  // Without the model queue (chat library not loaded) nothing is ever sent.
  $probe = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($root . '/api/voice_transcribe.php', true) . ';'
    . ' echo coreui_voice_transcribe_part("http://127.0.0.1:1/api/chat", "m", "x", "German", 5)["error"];');
  vi_test(trim((string)shell_exec($probe)) === 'queue_unavailable', 'no Core call outside the model queue');

  // Audio attachment: heard once, then from the account's cache; guests and Cores without audio get the honest note.
  $reset();
  $pdo = new PDO('sqlite::memory:');
  $pdo->exec('CREATE TABLE stu_console_media (uuid TEXT, user_id INTEGER, kind TEXT, rel_path TEXT, orig_name TEXT, mime_type TEXT, file_size INTEGER, public_url TEXT)');
  $rel = str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($long, '/');
  vi_test(is_file($root . '/' . $rel), 'test file reachable like a stored attachment');
  $uuid = str_repeat('ab', 16);
  $pdo->prepare('INSERT INTO stu_console_media VALUES (?, 7, ?, ?, ?, ?, ?, ?)')
    ->execute([$uuid, 'audio', $rel, 'besprechung.webm', 'audio/webm', filesize($long), '']);
  $replies(['Teil eins.', 'Teil zwei.', 'Teil drei.']);
  $block = ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000);
  vi_test(str_contains($block, "[00:00] Teil eins.\n[00:51] Teil zwei.\n[01:43] Teil drei.") && str_contains($block, 'Laenge etwa 02:30')
    && str_contains($block, 'Core hat sie angehoert') && !str_contains($block, 'mitgeschrieben sind nur'),
    'the whole file (under 10 minutes) heard, with time marks and its length');
  vi_test(count($requests()) === 3 && $GLOBALS['VI']['waits'] === [600, 600, 600], 'three parts through the queue');
  vi_test($GLOBALS['VI']['work'] === ['running:Core hoert die Audiodatei (Abschnitt 1 von 3)', 'running:Core hoert die Audiodatei (Abschnitt 2 von 3)',
    'running:Core hoert die Audiodatei (Abschnitt 3 von 3)', 'success:Audiodatei mitgeschrieben'], 'progress in the work path');
  $cached = glob($base . '/cache/7/t-*.json') ?: [];
  vi_test(count($cached) === 1 && !str_contains((string)file_get_contents($cached[0]), 'besprechung'), 'transcript kept per account');
  vi_test(ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000) === $block && $requests() === [], 'asked again: from the cache, Core not asked');
  $GLOBALS['VI']['language'] = 'en';
  $replies(['Part one.', 'Part two.', 'Part three.']);
  vi_test(str_contains(ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000), '[00:00] Part one.') && count($requests()) === 3,
    'another account language is heard again');
  $reset();
  $GLOBALS['VI']['guest'] = true;
  $guestBlock = ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000);
  vi_test(str_contains($guestBlock, 'Du kannst sie NICHT anhoeren') && $GLOBALS['VI']['acquire'] === 0, 'guests: honest note, nothing heard');
  $reset();
  $GLOBALS['VI']['hears'] = ['completion', 'vision'];
  vi_test(str_contains(ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000), 'keinen Audio-Eingang') && $GLOBALS['VI']['acquire'] === 0,
    'a Core without audio: honest note, nothing sent');
  $reset();
  $pdo->prepare('UPDATE stu_console_media SET rel_path = ? WHERE uuid = ?')
    ->execute([str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($quiet, '/'), $uuid]);
  vi_test(str_contains(ember_attachment_single_block($pdo, $uuid, 7, 4000, 12000), 'darin wird aber nicht gesprochen') && $requests() === [],
    'a file without speech is named as such');
  vi_test($workdirs() === $before, 'no temporary files left');
} finally {
  proc_terminate($server);
  proc_close($server);
  exec('rm -rf ' . escapeshellarg($base));
}
echo "CoreAI voice input: parts, request, wiring, conversion with ffmpeg, the way to the text through the model queue,"
  . " retry, silence, cancel and audio attachments (cache, guests, Core without audio) OK\n";
