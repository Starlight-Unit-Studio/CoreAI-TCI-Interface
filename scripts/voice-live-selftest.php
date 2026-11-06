<?php
declare(strict_types=1);
// CoreAI 0.6.31 (P20.4): the live conversation.
//   php scripts/voice-live-selftest.php
// Offline: the live Core (Mara Normal when installed, hearing and allowed), whole sentences
// for Eve (numbers, abbreviations and short pieces do not cut), the reply as it is spoken,
// the fixed opening, no system message for a Core with its own SYSTEM text, the events,
// and the wiring of endpoint, nginx, page and composer.
// Only with COREUI_SELFTEST_DB=1 (never in the preflight on a server with real data): the
// real endpoint over HTTP, signed in, against a stand-in Core and the database: status,
// warming up, two rounds (transcript, the Stimmsequenz and the answer as chat messages,
// sentences as they come, no audio in Mara's call, the first round's call is the beginning
// of the second one, so Ollama can keep it), an interrupted round, the memory step at the
// end; every row and file it made is removed again.
// 0.6.32: Mara Schnell writes the transcripts when Ollama keeps both Cores in memory (warm-up
// loads her and checks /api/ps); when Mara Normal was unloaded or Mara Schnell fails, Mara
// Normal writes again and the browser is told.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/voice_live_lib.php';

function live_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Live FAIL: {$why}\n"); exit(1); }
}

// --- The live Core ------------------------------------------------------------------
$tiers = ['ember-coreui:latest' => 'normal', 'ember-coreui:12b' => 'plus', 'gemma4:e4b' => 'normal', 'ember-coreui:e2b' => 'schnell',
  'gemma4:e4b-cloud' => 'normal'];
$tierOf = static fn(string $m): ?string => $tiers[$m] ?? null;
$hears = static fn(string $m): ?bool => true;
$all = static fn(string $m): bool => true;
$installed = ['gemma4:e4b', 'ember-coreui:12b', 'ember-coreui:latest', 'ember-coreui:e2b', 'gemma4:e4b-cloud'];
live_test(coreui_live_pick_core('ember-coreui:12b', $installed, 'ember-coreui', $tierOf, $hears, $all) === 'ember-coreui:latest',
  'Mara Normal answers live, Mara\'s own Core before a plain one');
live_test(coreui_live_pick_core('ember-coreui:12b', $installed, 'ember-coreui', $tierOf, $hears,
  static fn(string $m): bool => $m !== 'ember-coreui:latest') === 'gemma4:e4b', 'a Core the account may not use is skipped');
live_test(coreui_live_pick_core('ember-coreui:12b', $installed, 'ember-coreui', $tierOf, static fn(string $m): ?bool => false, $all)
  === 'ember-coreui:12b', 'no Normal that hears: the chat Core');
live_test(coreui_live_pick_core('ember-coreui:12b', ['ember-coreui:12b', 'gemma4:e4b-cloud'], 'ember-coreui', $tierOf, $hears, $all)
  === 'ember-coreui:12b', 'a cloud Core is never the live Core');

// --- 0.6.32: who writes the transcript --------------------------------------------------
live_test(coreui_live_pick_hear_core('ember-coreui:latest', $installed, 'ember-coreui', $tierOf, $hears, $all) === 'ember-coreui:e2b',
  'Mara Schnell writes the transcript');
live_test(coreui_live_pick_hear_core('ember-coreui:latest', $installed, 'ember-coreui', $tierOf, $hears,
  static fn(string $m): bool => $m !== 'ember-coreui:e2b') === '', 'not when the account may not use her');
live_test(coreui_live_pick_hear_core('ember-coreui:latest', $installed, 'ember-coreui', $tierOf,
  static fn(string $m): ?bool => $m !== 'ember-coreui:e2b', $all) === '', 'not when she does not hear');
live_test(coreui_live_pick_hear_core('ember-coreui:e2b', $installed, 'ember-coreui', $tierOf, $hears, $all) === '',
  'not when she answers live herself');
live_test(coreui_live_both_loaded(['ember-coreui', 'ember-coreui:e2b'], 'ember-coreui:latest', 'ember-coreui:12b') === false
  && coreui_live_both_loaded(['ember-coreui', 'ember-coreui:e2b'], 'ember-coreui:latest', 'Ember-Coreui:e2b') === true
  && coreui_live_both_loaded(['ember-coreui:e2b'], 'ember-coreui:latest', 'ember-coreui:e2b') === false,
  'both in memory (":latest" and case as Ollama lists them), or not');

// --- Sentences for Eve --------------------------------------------------------------
$buffer = 'Ja. Gerne, das mache ich. Am 3. November ist es z. B. kalt. Und dann';
live_test(coreui_live_take_sentences($buffer) === ['Ja. Gerne, das mache ich.', 'Am 3. November ist es z. B. kalt.'] && $buffer === 'Und dann',
  'whole sentences; numbers, abbreviations and short pieces do not cut: ' . json_encode($buffer, JSON_UNESCAPED_UNICODE));
live_test(coreui_live_take_sentences($buffer) === [] && $buffer === 'Und dann', 'an unfinished sentence waits');
$buffer .= ' sehen wir weiter!';
live_test(coreui_live_take_sentences($buffer, true) === ['Und dann sehen wir weiter!'] && $buffer === '', 'the end takes the rest');
$buffer = "Das Wetter morgen\nwird schön und trocken. ";
live_test(coreui_live_take_sentences($buffer) === ['Das Wetter morgen', 'wird schön und trocken.'], 'a line break ends a piece');
$buffer = 'Wirklich?! Das ist ja toll… Und';
live_test(coreui_live_take_sentences($buffer) === ['Wirklich?! Das ist ja toll…'], 'several marks and an ellipsis');

// --- The reply as it is spoken and kept -----------------------------------------------
live_test(coreui_live_clean_reply("**Hallo** Rick! [WEB: Wetter] Das ist *schön*.\n- Punkt `eins`") === "Hallo Rick! Das ist schön.\nPunkt eins",
  'no markers, no Markdown marks');

// --- The fixed opening and the messages ------------------------------------------------
$context = coreui_live_context('Rick', '06.11.2026', "--- BENUTZERKONFIGURATION ---\nKurz bitte.", "--- MEMORY ---\nRick wohnt in Bergisch Gladbach.");
live_test(str_starts_with($context, 'LIVE-GESPRAECH (gesprochen, 06.11.2026)') && str_contains($context, 'Rick spricht gerade live mit dir.')
  && str_contains($context, 'automatische Mitschriften') && str_contains($context, 'Keine Listen, Tabellen')
  && str_contains($context, 'Kurz bitte.') && str_contains($context, 'Bergisch Gladbach') && !preg_match('~\d{1,2}:\d{2}~', $context),
  'the opening: who speaks, spoken style, profile, memory, no clock time (so it stays the same)');
$history = [['id' => 1, 'role' => 'user', 'content' => 'Hallo'], ['id' => 2, 'role' => 'assistant', 'content' => 'Hallo Rick!'],
  ['id' => 3, 'role' => 'user', 'content' => '  ']];
$messages = coreui_live_messages($context, $history);
live_test($messages === [['role' => 'user', 'content' => $context], ['role' => 'user', 'content' => 'Hallo'],
  ['role' => 'assistant', 'content' => 'Hallo Rick!']], 'no system message: the Modelfile SYSTEM stays; empty turns left out');
live_test(coreui_live_messages($context, [], 'Du bist Mara.')[0] === ['role' => 'system', 'content' => 'Du bist Mara.'],
  'a Core without its own SYSTEM text gets Mara\'s');
live_test(coreui_live_sse('sentence', ['index' => 0, 'text' => 'Hallo Rick!']) === "event: sentence\ndata: {\"index\":0,\"text\":\"Hallo Rick!\"}\n\n",
  'a server-sent event');

// --- Wiring ------------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/voice_live.php');
live_test(str_contains($endpoint, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);")
  && str_contains($endpoint, 'stu_require_csrf();') && str_contains($endpoint, "'think' => false")
  && str_contains($endpoint, 'ember_global_lock_acquire(600)') && str_contains($endpoint, 'ember_generation_lock_release($lock)')
  && str_contains($endpoint, 'coreui_user_policy_reserve_turn($pdo, $uid);') && str_contains($endpoint, "header('X-Accel-Buffering: no');")
  && str_contains($endpoint, 'ignore_user_abort(true);') && str_contains($endpoint, 'coreui_voice_transcript_save($uid, $uuid,')
  && str_contains($endpoint, 'ember_insert($pdo, $reply, \'console\', null, $uid, $sessionId, $userMessageId)')
  && str_contains($endpoint, 'coreui_model_access_allowed($pdo, $uid, $m)')
  && str_contains($endpoint, 'coreui_live_both_loaded(coreui_ollama_loaded_names(), $hearCandidate, $core)')
  && str_contains($endpoint, "'hear_core' => \$hearCore"),
  'endpoint: never guests, CSRF, no thinking, Mara\'s queue, daily limit, no proxy buffering, transcript, both sides kept, Core permissions,'
  . ' Mara Schnell writes only with both Cores in memory');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $tpl) {
  live_test(str_contains((string)file_get_contents($root . '/' . $tpl), '|voice_input|voice_live|'), $tpl . ' lets the live endpoint through');
}
$app = (string)file_get_contents($root . '/app.html');
live_test(preg_match('~coreui-voice-input\.js\?v=\d+"></script>\s*<script src="\./js/coreui-voice-live\.js\?v=\d+"></script>~', $app) === 1,
  'the page loads the live view after the microphone');
$composer = (string)file_get_contents($root . '/js/console-app.js');
live_test(str_contains($composer, 'liveContext: function ()') && str_contains($composer, 'reload: function ()'),
  'the chat tells the live view its session and reloads afterwards');

$dbWanted = getenv('COREUI_SELFTEST_DB') === '1';
$ffmpeg = trim((string)shell_exec('command -v ffmpeg 2>/dev/null'));
if (!$dbWanted || $ffmpeg === '' || !function_exists('proc_open') || !function_exists('curl_init')) {
  echo "CoreAI live conversation: live Core, sentences for Eve, spoken reply, fixed opening, Modelfile SYSTEM kept, wiring OK"
    . ($dbWanted ? " (no ffmpeg or proc_open here)" : " (HTTP run only with COREUI_SELFTEST_DB=1)") . "\n";
  exit(0);
}

// =========================================================================================
// The real endpoint over HTTP, signed in, against a stand-in Core and the database.
// =========================================================================================
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
$base = sys_get_temp_dir() . '/coreai-live-' . bin2hex(random_bytes(4));
mkdir($base . '/voice', 0700, true);
$port = random_int(28200, 28900);
$appPort = $port + 1;
$uid = random_int(1500000000, 1900000000);
$cid = 'live' . bin2hex(random_bytes(6));
$sessionId = 'cs_' . bin2hex(random_bytes(12));
$servers = [];

file_put_contents($base . '/core.php', <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'];
$body = json_decode((string)file_get_contents('php://input'), true);
$log = __DIR__ . '/calls.jsonl';
if (str_starts_with($uri, '/api/show')) { header('Content-Type: application/json'); echo json_encode(['capabilities' => ['completion', 'vision', 'audio']]); return; }
if (str_starts_with($uri, '/api/tags')) { header('Content-Type: application/json'); echo json_encode(['models' => [['name' => 'ember-coreui:12b'], ['name' => 'ember-coreui:latest'], ['name' => 'ember-coreui:e2b']]]); return; }
if (str_starts_with($uri, '/api/ps')) {
  // What Ollama holds in memory: both Cores, unless the test says otherwise.
  $names = is_file(__DIR__ . '/ps.json') ? json_decode((string)file_get_contents(__DIR__ . '/ps.json'), true) : ['ember-coreui:latest', 'ember-coreui:e2b'];
  header('Content-Type: application/json');
  echo json_encode(['models' => array_map(static fn($n) => ['name' => $n, 'model' => $n], $names)]);
  return;
}
if (!str_starts_with($uri, '/api/chat')) { http_response_code(404); return; }
$messages = (array)($body['messages'] ?? []);
$last = (array)end($messages);
file_put_contents($log, json_encode(['model' => $body['model'] ?? '', 'stream' => !empty($body['stream']), 'think' => $body['think'] ?? null,
  'num_ctx' => $body['options']['num_ctx'] ?? null,
  'messages' => array_map(static fn($m) => ['role' => $m['role'] ?? '', 'content' => $m['content'] ?? '', 'images' => count((array)($m['images'] ?? []))], $messages)]) . "\n", FILE_APPEND);
if ($messages === []) {
  // Only loading the Core.
  header('Content-Type: application/json');
  echo json_encode(['model' => $body['model'] ?? '', 'done_reason' => 'load', 'done' => true]);
  return;
}
if (empty($body['stream']) && ($body['model'] ?? '') === 'ember-coreui:e2b' && is_file(__DIR__ . '/e2b.fails')) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'model failed']);
  return;
}
if (empty($body['stream'])) {
  // A transcription: the round number decides what was said.
  $n = (int)@file_get_contents(__DIR__ . '/heard.count') + 1;
  file_put_contents(__DIR__ . '/heard.count', (string)$n);
  $said = [1 => 'Wie wird das Wetter morgen?', 2 => 'Und am Wochenende?', 3 => 'Erzähl mir was Langes.'][$n] ?? 'Danke.';
  header('Content-Type: application/json');
  echo json_encode(['message' => ['role' => 'assistant', 'content' => $said], 'done' => true]);
  return;
}
header('Content-Type: application/x-ndjson');
$question = (string)($last['content'] ?? '');
$text = str_contains($question, 'Bereit') ? 'Bereit.'
  : (str_contains($question, 'Langes') ? str_repeat('Das ist ein langer Satz über das Wetter. ', 12)
  : (str_contains($question, 'Wochenende') ? 'Am Wochenende wird es sonnig. Samstag bis 12 Grad, Sonntag etwas kühler.'
  : 'Morgen wird es **trocken** und mild. Ich schaue gern später noch genauer nach!'));
$slow = str_contains($question, 'Langes');
foreach (preg_split('~(?<=\s)~u', $text) as $piece) {
  echo json_encode(['message' => ['content' => $piece], 'done' => false]) . "\n";
  @ob_flush(); flush();
  usleep($slow ? 120000 : 5000);
  if (connection_aborted()) return;
}
echo json_encode(['message' => ['content' => ''], 'done' => true, 'prompt_eval_count' => 77]) . "\n";
PHP);

file_put_contents($base . '/app.php', <<<'PHP'
<?php
// Signs the test account in and runs the real endpoint.
$root = getenv('COREAI_ROOT');
require_once $root . '/api/helpers.php';
stu_start_session();
$_SESSION['stu_uid'] = (int)getenv('LIVE_UID');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/csrf') { echo stu_csrf_token(); return; }
if ($path === '/api/voice_live.php') { require $root . '/api/voice_live.php'; return; }
http_response_code(404);
PHP);

$env = array_merge(getenv(), ['COREAI_ROOT' => $root, 'LIVE_UID' => (string)$uid,
  'COREUI_OLLAMA_URL' => "http://127.0.0.1:{$port}/api/chat", 'COREUI_EMBER_MODEL' => 'ember-coreui:latest',
  'COREUI_VOICE_CACHE_DIR' => $base . '/voice', 'COREUI_VOICE_URL' => 'http://127.0.0.1:1']);
$start = static function (int $p, string $router) use (&$servers, $env, $base): void {
  $servers[] = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-S', "127.0.0.1:{$p}", $router],
    [1 => ['file', $base . '/server.log', 'a'], 2 => ['file', $base . '/server.log', 'a']], $pipes, $base, $env);
};
$cleanup = static function () use ($pdo, $uid, $sessionId, $base, &$servers): void {
  foreach ($servers as $s) { @proc_terminate($s); @proc_close($s); }
  try {
    $pdo->prepare('DELETE FROM stu_console_message_attachments WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM stu_chat_messages WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM stu_console_media WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM stu_console_sessions WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM stu_characters WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$uid]);
  } catch (Throwable $e) {}
  exec('rm -rf ' . escapeshellarg($base));
};
register_shutdown_function($cleanup);

// The account, its character, profile name, a chat with one earlier message, three recordings.
$pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 0, NOW())')
  ->execute([$uid, 'live-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
$pdo->prepare('INSERT INTO stu_characters (id, user_id, name, name_norm) VALUES (?, ?, ?, ?)')->execute([$cid, $uid, 'Rick', $cid]);
$pdo->prepare('INSERT INTO stu_coreui_profiles (user_id, display_name) VALUES (?, ?)')->execute([$uid, 'Rick']);
// The chat runs on Mara Plus, as on the operator's server.
$pdo->prepare('INSERT INTO stu_user_ai_settings (user_id, model_override) VALUES (?, ?)')->execute([$uid, 'ember-coreui:12b']);
$pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
  ->execute([$sessionId, $uid, 'Live']);
$pdo->prepare("INSERT INTO stu_chat_messages (channel, alliance_id, session_id, user_id, character_id, character_name, message, created_at) "
  . "VALUES ('console', NULL, ?, ?, ?, 'Rick', 'Hallo Mara, ich bin Rick.', NOW())")->execute([$sessionId, $uid, $cid]);
$sinceId = (int)$pdo->lastInsertId();
$rel = static fn(string $file): string => str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($file, '/');
$recordings = [];
foreach ([1, 2, 3] as $n) {
  $file = $base . "/Sprachnachricht-2026-11-06_17-0{$n}-00.wav";
  exec(escapeshellarg($ffmpeg) . ' -loglevel error -y -f lavfi -i ' . escapeshellarg('sine=frequency=' . (200 + 90 * $n) . ':duration=3')
    . ' -af volume=0.6 ' . escapeshellarg($file));
  $uuid = bin2hex(random_bytes(16));
  $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, character_id, kind, orig_name, stored_name, rel_path, public_url, mime_type, file_size) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$uuid, $uid, $cid, 'audio', basename($file), basename($file), $rel($file), '', 'audio/wav', filesize($file)]);
  $recordings[] = $uuid;
}

$start($port, $base . '/core.php');
$start($appPort, $base . '/app.php');
$up = static function (int $p): bool {
  for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $p); if ($c) { fclose($c); return true; } usleep(100000); }
  return false;
};
live_test($up($port) && $up($appPort), 'stand-in Core and app server start');
$cookies = $base . '/cookies.txt';
$http = static function (string $method, string $path, ?array $body = null, ?callable $onChunk = null, string $csrf = '') use ($appPort, $cookies): array {
  $ch = curl_init("http://127.0.0.1:{$appPort}{$path}");
  $out = '';
  curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CoreUI-CSRF: ' . $csrf],
    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$out, $onChunk): int {
      $out .= $chunk;
      return ($onChunk !== null && $onChunk($out) === false) ? 0 : strlen($chunk);
    }]);
  if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $out];
};
$events = static function (string $raw): array {
  $list = [];
  foreach (preg_split("~\n\n~", trim($raw)) ?: [] as $block) {
    if (preg_match('~^event: (\w+)\ndata: (.*)$~s', trim($block), $m) === 1) $list[] = [$m[1], json_decode($m[2], true)];
  }
  return $list;
};
$calls = static function () use ($base): array {
  $lines = array_filter(explode("\n", (string)@file_get_contents($base . '/calls.jsonl')));
  @unlink($base . '/calls.jsonl');
  return array_map(static fn(string $l): array => json_decode($l, true), array_values($lines));
};

[, $csrf] = $http('GET', '/csrf');
[$code, $raw] = $http('GET', '/api/voice_live.php?action=status');
$status = json_decode($raw, true);
live_test($code === 200 && ($status['allowed'] ?? false) === true && $status['core'] === 'ember-coreui:latest'
  && $status['core_label'] === 'TCI: Mara Normal' && $status['name'] === 'Rick' && $status['hears'] === true,
  'status: allowed, Mara Normal answers live although the chat uses Plus: ' . $raw);
[$code, $raw] = $http('POST', '/api/voice_live.php?action=turn', ['session_id' => $sessionId], null, 'falsch');
live_test($code === 403 && str_contains($raw, 'csrf_failed'), 'no round without the CSRF token');

// Warming up: Mara Schnell is loaded, then the opening goes into Mara Normal's cache.
$turnBody = ['session_id' => $sessionId, 'character_id' => $cid, 'since_id' => $sinceId];
[$code, $raw] = $http('POST', '/api/voice_live.php?action=warm', $turnBody, null, $csrf);
$warm = json_decode($raw, true);
$sent = $calls();
live_test($code === 200 && ($warm['ok'] ?? false) === true && count($sent) === 2
  && $sent[0]['model'] === 'ember-coreui:e2b' && $sent[0]['messages'] === []
  && $sent[1]['model'] === 'ember-coreui:latest' && $sent[1]['stream'] === true && $sent[1]['think'] === false
  && str_starts_with($sent[1]['messages'][0]['content'], 'LIVE-GESPRAECH'),
  'warm: Mara Schnell is loaded (no prompt), Mara Normal loads the opening of the conversation: ' . $raw);
live_test(($warm['hear_core'] ?? '') === 'ember-coreui:e2b' && ($warm['hear_label'] ?? '') === 'TCI: Mara Schnell',
  'both in memory: Mara Schnell writes the transcripts: ' . $raw);
file_put_contents($base . '/ps.json', json_encode(['ember-coreui:latest']));
[, $raw] = $http('POST', '/api/voice_live.php?action=warm', $turnBody, null, $csrf);
live_test((json_decode($raw, true)['hear_core'] ?? '') === 'ember-coreui:latest', 'Ollama could not keep both: Mara Normal writes: ' . $raw);
@unlink($base . '/ps.json');
$calls();
$turnBody['hear'] = 'ember-coreui:e2b';

// Round 1.
[$code, $raw] = $http('POST', '/api/voice_live.php?action=turn', $turnBody + ['uuid' => $recordings[0]], null, $csrf);
$ev = $events($raw);
$names = array_column($ev, 0);
$byName = [];
foreach ($ev as [$n, $d]) $byName[$n][] = $d;
live_test($code === 200 && $names[0] === 'start' && $names[1] === 'heard' && end($names) === 'done',
  'round 1: start, heard, sentences, done: ' . json_encode($names));
live_test($byName['heard'][0]['text'] === 'Wie wird das Wetter morgen?', 'what was heard');
live_test(array_column($byName['sentence'], 'text') === ['Morgen wird es trocken und mild.', 'Ich schaue gern später noch genauer nach!'],
  'sentences for Eve as they come, without Markdown: ' . json_encode($byName['sentence'], JSON_UNESCAPED_UNICODE));
$done1 = $byName['done'][0];
$sent1 = $calls();
live_test(count($sent1) === 2 && $sent1[0]['stream'] === false && $sent1[0]['messages'][count($sent1[0]['messages']) - 1]['images'] === 1
  && $sent1[0]['model'] === 'ember-coreui:e2b' && $sent1[1]['model'] === 'ember-coreui:latest',
  'the transcript: the recording to Mara Schnell, no stream; Mara Normal answers');
live_test(!in_array('hear', $names, true) && ($byName['done'][0]['timings']['hear_core'] ?? '') === 'ember-coreui:e2b',
  'both stay in memory: no change of who writes');
$answer1 = $sent1[1]['messages'];
live_test($sent1[1]['think'] === false && array_sum(array_column($answer1, 'images')) === 0
  && end($answer1)['content'] === 'Wie wird das Wetter morgen?' && end($answer1)['role'] === 'user'
  && $answer1[1]['content'] === 'Hallo Mara, ich bin Rick.', 'Mara answers the text: no audio, history before the live start, the new round last');
$st = $pdo->prepare('SELECT id, character_id, message, reply_to_id FROM stu_chat_messages WHERE user_id = ? AND id > ? ORDER BY id');
$st->execute([$uid, $sinceId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
live_test(count($rows) === 2 && (int)$rows[0]['id'] === (int)$done1['user_message_id'] && $rows[0]['message'] === ''
  && (int)$rows[1]['id'] === (int)$done1['reply_id'] && (int)$rows[1]['reply_to_id'] === (int)$rows[0]['id']
  && $rows[1]['message'] === 'Morgen wird es trocken und mild. Ich schaue gern später noch genauer nach!',
  'both sides are chat messages: the Stimmsequenz, then the answer as its reply: ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
$att = $pdo->prepare('SELECT media_uuid FROM stu_console_message_attachments WHERE message_id = ?');
$att->execute([(int)$rows[0]['id']]);
live_test($att->fetchColumn() === $recordings[0], 'the recording is attached to the Stimmsequenz');
putenv('COREUI_VOICE_CACHE_DIR=' . $base . '/voice');
require_once $root . '/api/voice_transcribe.php';
$kept = coreui_voice_transcript_load($uid, $recordings[0]);
live_test(($kept['state'] ?? '') === 'done' && $kept['text'] === 'Wie wird das Wetter morgen?', 'the transcript stands under the Stimmsequenz');

// Round 2: the call of round 1 plus its answer is the beginning of the call of round 2 (cache).
// Meanwhile Ollama had to unload Mara Normal: from the next round on she writes again.
file_put_contents($base . '/ps.json', json_encode(['ember-coreui:e2b']));
[$code, $raw] = $http('POST', '/api/voice_live.php?action=turn', $turnBody + ['uuid' => $recordings[1]], null, $csrf);
@unlink($base . '/ps.json');
$ev2 = $events($raw);
$sent2 = $calls();
$hear2 = array_values(array_filter($ev2, static fn($e) => $e[0] === 'hear'));
live_test($sent2[0]['model'] === 'ember-coreui:e2b' && count($hear2) === 1 && $hear2[0][1]['core'] === 'ember-coreui:latest',
  'Mara Normal no longer in memory: the browser is told that she writes again: ' . json_encode($hear2));
$answer2 = $sent2[1]['messages'];
$prefix = array_merge($answer1, [['role' => 'assistant', 'content' => $rows[1]['message'], 'images' => 0]]);
live_test(array_slice($answer2, 0, count($prefix)) === $prefix && end($answer2)['content'] === 'Und am Wochenende?',
  'round 2 starts word for word like round 1 and its answer, so Ollama can keep it');
$sentences2 = array_column(array_column(array_filter($ev2, static fn($e) => $e[0] === 'sentence'), 1), 'text');
live_test($sentences2 === ['Am Wochenende wird es sonnig.', 'Samstag bis 12 Grad, Sonntag etwas kühler.'], 'round 2 sentences: ' . json_encode($sentences2, JSON_UNESCAPED_UNICODE));
$done2 = end($ev2)[1];

// Round 3 interrupted: the person speaks while I talk; the browser ends the round.
// Mara Schnell fails here: Mara Normal writes this round, and the browser is told.
touch($base . '/e2b.fails');
$seen = 0;
[$code, $raw] = $http('POST', '/api/voice_live.php?action=turn', $turnBody + ['uuid' => $recordings[2]],
  static function (string $sofar) use (&$seen): bool { $seen = substr_count($sofar, 'event: sentence'); return $seen < 2; }, $csrf);
live_test($seen >= 2 && !str_contains($raw, 'event: done'), 'the round stops when the browser ends it');
live_test(str_contains($raw, "event: hear\ndata: {\"core\":\"ember-coreui:latest\"") && str_contains($raw, 'Erzähl mir was Langes.'),
  'Mara Schnell failed: Mara Normal wrote the transcript: ' . substr($raw, 0, 300));
$wait = 0;
do {
  usleep(200000);
  $st = $pdo->prepare('SELECT message FROM stu_chat_messages WHERE user_id = ? AND character_id <> ? ORDER BY id DESC LIMIT 1');
  $st->execute([$uid, $cid]);
  $partial = (string)$st->fetchColumn();
} while (!str_contains($partial, 'langer Satz') && ++$wait < 50);
live_test(str_contains($partial, 'langer Satz') && mb_strlen($partial) < mb_strlen(str_repeat('Das ist ein langer Satz über das Wetter. ', 12)) - 20,
  'what was said up to the interruption is kept, not the whole answer: ' . mb_strlen($partial) . ' characters');
$calls();

// Eve is not running here: the sentence plan fails honestly.
[$code, $raw] = $http('POST', '/api/voice_live.php?action=say', ['text' => 'Hallo Rick!'], null, $csrf);
live_test($code === 503 && str_contains($raw, '"ok":false'), 'say without the voice service: an honest error: ' . $raw);

// The end: my memory step over the rounds (only rounds of this account and chat).
[$code, $raw] = $http('POST', '/api/voice_live.php?action=end', ['session_id' => $sessionId,
  'pairs' => [[$done1['user_message_id'], $done1['reply_id']], [$done2['user_message_id'], $done2['reply_id']], [1, 2]]], null, $csrf);
live_test($code === 200 && (json_decode($raw, true)['reflected'] ?? -1) === 2, 'end: two rounds handed to the memory step, a foreign pair ignored: ' . $raw);

echo "CoreAI live conversation: live Core, sentences for Eve, spoken reply, fixed opening, Modelfile SYSTEM kept, wiring;"
  . " over HTTP with the database: status, CSRF, warm up, two rounds kept as chat messages with transcript, the same beginning for the cache,"
  . " Mara Schnell writes with both Cores in memory, Mara Normal again when unloaded or failing,"
  . " interruption, Eve unavailable, memory step at the end OK\n";
