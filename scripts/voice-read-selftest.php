<?php
declare(strict_types=1);
// CoreAI 0.6.1: read aloud (P20.3) on the PHP side. A stand-in voice service
// (php -S) answers like the real one. Checked: health, plan and audio cached
// per account (the second time nothing is computed), busy and broken answers,
// cleanup after 7 days, only own folders, service down. Wiring: guests never,
// only own Mara answers, CSRF, nginx, migration 023, account deletion, the
// chat button and the KI-Profil switch. With a database (api/config.local.php,
// or COREUI_SELFTEST_DB=1 with COREUI_DB_* set): the setting, rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/voice_store.php';

function voice_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Voice read FAIL: {$why}\n"); exit(1); }
}

$base = sys_get_temp_dir() . '/coreai-voice-' . bin2hex(random_bytes(4));
mkdir($base . '/cache', 0700, true);
file_put_contents($base . '/router.php', <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'];
$body = json_decode((string)file_get_contents('php://input'), true);
// 0.6.8: the language a part is spoken in goes into the log ("POST /speak en").
file_put_contents(__DIR__ . '/calls.log', $_SERVER['REQUEST_METHOD'] . ' ' . $uri . (is_array($body) && isset($body['language']) ? ' ' . $body['language'] : '') . "\n", FILE_APPEND);
$text = is_array($body) ? (string)($body['text'] ?? '') : '';
header('Content-Type: application/json');
if ($uri === '/health') { echo json_encode(['ok' => true, 'ready' => true, 'loading' => false, 'voice' => 'Testton']); return; }
if ($uri === '/plan') {
  $parts = str_contains($text, 'LEER') ? [] : (str_contains($text, 'BUSY') ? ['BUSY'] : (str_contains($text, 'KAPUTT') ? ['KAPUTT'] : ['Hallo.', 'Zweiter Teil.']));
  $english = str_contains($text, 'ENGLISH');
  echo json_encode(['ok' => true, 'parts' => $parts, 'textprep' => $english ? 'en-1' : 'de-1', 'language' => $english ? 'en' : 'de']);
  return;
}
if ($uri === '/speak') {
  if ($text === 'BUSY') { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'voice_busy']); return; }
  if ($text === 'KAPUTT') { header('Content-Type: text/html'); echo '<h1>oops</h1>'; return; }
  header('Content-Type: audio/wav');
  echo 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\0", 20) . 'data' . pack('V', 0) . $text;
  return;
}
http_response_code(404);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $base . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
putenv("COREUI_VOICE_URL=http://127.0.0.1:{$port}");
putenv('COREUI_VOICE_CACHE_DIR=' . $base . '/cache');
$calls = static fn(string $what): int => substr_count((string)@file_get_contents($base . '/calls.log'), $what);

try {
  voice_test(coreui_voice_url() === "http://127.0.0.1:{$port}" && coreui_voice_cache_root() === $base . '/cache', 'service and cache from the environment');
  voice_test(coreui_voice_health() === ['available' => true, 'loading' => false, 'voice' => 'Testton'], 'health');

  // Per account folder; never for nobody, never through a link.
  $root7 = coreui_voice_cache_root();
  voice_test(coreui_voice_user_dir($root7, 0, true) === null && coreui_voice_user_dir($root7, 7, false) === null, 'no folder for nobody, none made unasked');
  $dir = coreui_voice_user_dir($root7, 7, true);
  voice_test($dir === $root7 . '/7' && is_dir($dir), 'folder per account');
  @symlink('/tmp', $root7 . '/8');
  voice_test(coreui_voice_user_dir($root7, 8, true) === null, 'a link is never used');

  // Plan: computed once, then from the cache; another answer, another id.
  $plan = coreui_voice_plan($dir, 'Eine Antwort von Mara.');
  voice_test(preg_match(COREUI_VOICE_ID_RE, $plan['id']) === 1 && $plan['parts'] === 2 && $calls('POST /plan') === 1, 'plan computed');
  voice_test(coreui_voice_plan($dir, 'Eine Antwort von Mara.') === $plan && $calls('POST /plan') === 1, 'plan from the cache');
  voice_test(coreui_voice_plan($dir, 'Eine andere Antwort.')['id'] !== $plan['id'], 'another answer, another id');
  voice_test(coreui_voice_plan_id('x') === coreui_voice_plan_id('x') && strlen(coreui_voice_plan_id('x')) === 64, 'stable id');
  voice_test(COREUI_VOICE_PLAN_SOURCES === ['config/voice/mara-voice.json', 'tools/tts_spike/textprep_de.py', 'tools/voice/textprep_en.py',
    'tools/voice/speakable.py'],
    'the plan id follows registry, text preparation and splitting, so a new dictionary entry is heard at once');

  // Audio: computed once per part, then from the cache.
  $file = coreui_voice_audio($dir, $plan['id'], 0);
  voice_test($file === $dir . '/' . $plan['id'] . '-0.wav' && str_ends_with((string)file_get_contents($file), 'Hallo.') && $calls('POST /speak') === 1, 'part 0 spoken');
  voice_test(coreui_voice_audio($dir, $plan['id'], 0) === $file && $calls('POST /speak') === 1, 'part 0 from the cache');
  voice_test(str_ends_with((string)file_get_contents(coreui_voice_audio($dir, $plan['id'], 1)), 'Zweiter Teil.'), 'part 1 spoken');
  $expect = static function (callable $fn, string $error): bool {
    try { $fn(); return false; } catch (Throwable $e) { return $e->getMessage() === $error; }
  };
  voice_test($expect(fn() => coreui_voice_audio($dir, $plan['id'], 2), 'plan_not_found'), 'no part beyond the plan');
  voice_test($expect(fn() => coreui_voice_audio($dir, '../etc', 0), 'invalid_part') && $expect(fn() => coreui_voice_audio($dir, $plan['id'], -1), 'invalid_part'), 'bad id or part');
  voice_test($expect(fn() => coreui_voice_audio($dir, str_repeat('a', 64), 0), 'plan_not_found'), 'unknown plan');
  $other = coreui_voice_user_dir($root7, 9, true);
  voice_test($expect(fn() => coreui_voice_audio($other, $plan['id'], 0), 'plan_not_found'), 'another account cannot use this plan');
  $busy = coreui_voice_plan($dir, 'BUSY');
  voice_test($expect(fn() => coreui_voice_audio($dir, $busy['id'], 0), 'voice_busy') && !is_file($dir . '/' . $busy['id'] . '-0.wav'), 'busy passed on, nothing cached');
  $broken = coreui_voice_plan($dir, 'KAPUTT');
  voice_test($expect(fn() => coreui_voice_audio($dir, $broken['id'], 0), 'voice_unavailable'), 'a non-WAV answer is never cached or played');
  voice_test($expect(fn() => coreui_voice_plan($dir, 'LEER'), 'nothing_to_read'), 'nothing to read');

  // 0.6.8: the plan keeps the language the service detected; every part is spoken in it.
  voice_test($plan['language'] === 'de', 'a German plan');
  $english = coreui_voice_plan($dir, 'An ENGLISH answer from Mara.');
  voice_test($english['language'] === 'en' && json_decode((string)file_get_contents($dir . '/' . $english['id'] . '.json'), true)['language'] === 'en',
    'an English plan, stored with its language');
  coreui_voice_audio($dir, $english['id'], 0);
  voice_test($calls('POST /speak en') === 1 && $calls('POST /speak de') >= 1, 'English parts are spoken in English, German ones in German');
  // 0.6.10: the account's main language goes to the service as hint for unclear answers.
  $hinted = coreui_voice_plan($dir, 'Eine Antwort von Mara.', 'en');
  voice_test($hinted['id'] !== $plan['id'] && $calls('POST /plan en') === 1, 'the account language is the hint, a plan of its own');
  voice_test(coreui_voice_plan($dir, 'Eine Antwort von Mara.', 'xx')['id'] === $plan['id'], 'an unknown hint counts as German');
  // A plan stored before 0.6.8 has no language: German.
  $legacyId = coreui_voice_plan_id('Alte Antwort.');
  file_put_contents($dir . '/' . $legacyId . '.json', json_encode(['parts' => ['Alt.'], 'textprep' => 'de-1']));
  $before = $calls('POST /speak de');
  coreui_voice_audio($dir, $legacyId, 0);
  voice_test($calls('POST /speak de') === $before + 1 && coreui_voice_plan($dir, 'Alte Antwort.')['language'] === 'de', 'old plans are German');

  // Cleanup after 7 days; foreign files stay.
  touch($file, time() - 8 * 86400);
  touch($dir . '/' . $plan['id'] . '.json', time() - 8 * 86400);
  file_put_contents($dir . '/notiz.txt', 'bleibt');
  touch($dir . '/notiz.txt', time() - 30 * 86400);
  voice_test(coreui_voice_cleanup($dir) === 2 && !is_file($file) && is_file($dir . '/notiz.txt') && is_file($dir . '/' . $plan['id'] . '-1.wav'), 'old audio removed, the rest stays');

  // Service down: a free port nobody listens on. It must be a URL the store
  // accepts; otherwise it falls back to 18790, where the real Eve may be running.
  $closed = stream_socket_server('tcp://127.0.0.1:0');
  $downUrl = 'http://127.0.0.1:' . (int)substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
  fclose($closed);
  putenv('COREUI_VOICE_URL=' . $downUrl);
  voice_test(coreui_voice_url() === $downUrl, 'service down: really points at the closed port, not at the real service');
  voice_test(coreui_voice_health()['available'] === false, 'service down: not available');
  voice_test($expect(fn() => coreui_voice_plan($dir, 'Neu, ohne Dienst.'), 'voice_unavailable'), 'service down: plan says so');
  putenv('COREUI_VOICE_URL=http://example.com:80');
  voice_test(coreui_voice_url() === 'http://127.0.0.1:18790', 'only a loopback service is ever called');
} finally {
  proc_terminate($server);
  proc_close($server);
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $f) { ($f->isDir() && !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
  rmdir($base);
}

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$api = $read('api/voice.php');
$guestAt = strpos($api, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);");
voice_test($guestAt !== false && $guestAt < strpos($api, "if (\$method === 'GET' && \$action === 'audio')") && $guestAt < strpos($api, "if (\$action === 'plan')"),
  'guests are refused before audio and plan');
voice_test(str_contains($api, "if (\$guest) stu_json(['ok' => true, 'allowed' => false,"), 'guests see no button');
voice_test(strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'settings')"), 'CSRF before every change');
voice_test(str_contains($api, "coreui_console_action_message(\$pdo, \$uid, (string)(\$body['session_id'] ?? ''), (int)(\$body['message_id'] ?? 0), true);"),
  'only own Mara answers');
voice_test(str_contains($api, 'coreui_voice_user_dir(coreui_voice_cache_root(), $uid, false)'), 'audio only from the own folder');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  voice_test(str_contains($read($nginx), '|update|voice|health)\.php$ {'), $nginx . ' lets api/voice.php through');
}
$migration = $read('database/migrations/023_voice_settings.sql');
voice_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_user_voice_settings') && str_contains($migration, 'auto_read TINYINT(1) NOT NULL DEFAULT 0')
  && str_contains($migration, 'ON DELETE CASCADE') && str_contains($migration, "VALUES ('023_voice_settings')"), 'migration 023, off by default');
voice_test(str_contains($read('api/health.php'), "'stu_user_voice_settings',"), 'health checks the table');
voice_test(str_contains($read('api/account_delete.php'), "\$voice = \$root . '/var/voice_cache/' . \$uid;"), 'account deletion removes the audio');
$app = $read('js/console-app.js');
voice_test(str_contains($app, 'var speak = window.CoreAIVoiceRead.button(record, state.activeSessionId);')
  && str_contains($app, 'window.CoreAIVoiceRead.onAnswer({ id: emberId, session_id: sessionId });'), 'chat: button under Mara answers, auto-read after a new one');
voice_test(strpos($read('app.html'), 'js/coreui-voice-read.js') < strpos($read('app.html'), 'js/console-app.js'), 'script before the chat');
$settings = $read('settings.html');
voice_test(str_contains($settings, '<label class="setting-row" for="voiceAutoRead" id="voiceAutoReadRow" hidden>') && str_contains($settings, 'js/coreui-voice-read.js'),
  'KI-Profil switch, hidden until the account may use it');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI voice read: plan and audio cached per account, busy and broken answers, 7 day cleanup, loopback only, wiring OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
voice_test(coreui_voice_settings_ready($pdo), 'migration 023 applied');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 4, NOW())')
    ->execute([$uid, 'voice-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  voice_test(coreui_voice_auto_read($pdo, $uid) === false, 'off by default');
  voice_test(coreui_voice_set_auto_read($pdo, $uid, true) === true && coreui_voice_auto_read($pdo, $uid) === true, 'switched on');
  voice_test(coreui_voice_set_auto_read($pdo, $uid, false) === false, 'switched off');
  voice_test(coreui_voice_auto_read($pdo, 0) === false, 'nobody: off');
  // 0.6.22: names and terms for voice messages in the same row (migration 025).
  require_once $root . '/api/voice_transcribe.php';
  voice_test(coreui_voice_terms_ready($pdo), 'migration 025 applied');
  voice_test(coreui_voice_terms_load($pdo, $uid) === [], 'no terms by default');
  voice_test(coreui_voice_terms_save($pdo, $uid, "Bergisch Gladbach\nEVK, evk\n[WEB: x]") === ['Bergisch Gladbach', 'EVK', 'WEB x']
    && coreui_voice_terms_load($pdo, $uid) === ['Bergisch Gladbach', 'EVK', 'WEB x'], 'terms saved cleaned and read back');
  voice_test(coreui_voice_auto_read($pdo, $uid) === false, 'saving terms leaves reading aloud as it was');
  coreui_voice_set_auto_read($pdo, $uid, true);
  voice_test(coreui_voice_terms_load($pdo, $uid) === ['Bergisch Gladbach', 'EVK', 'WEB x'], 'switching reading aloud keeps the terms');
  voice_test(coreui_voice_terms_save($pdo, $uid, '  ') === [] && $pdo->query('SELECT vocabulary FROM stu_user_voice_settings WHERE user_id = ' . (int)$uid)
    ->fetchColumn() === null, 'an empty list is stored as nothing');
  voice_test($pdo->inTransaction() && (int)$pdo->query('SELECT @@in_transaction')->fetchColumn() === 1, 'still inside the transaction');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "CoreAI voice read: plan and audio cached per account, busy and broken answers, 7 day cleanup, loopback only, wiring and the settings (reading aloud, names and terms) on the database (rolled back) OK\n";
