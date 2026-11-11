<?php
declare(strict_types=1);
// CoreAI 0.7.14: Settings > Stimme on the PHP side. A stand-in voice service (php -S)
// answers like the real one and logs which voice it was asked for. Checked: the voices
// that may be chosen (registry "choices", free licenses only, Eve the default), the plan
// and every spoken part in the chosen voice (Eve keeps the old ids and asks for no voice),
// the preview sentence (shared, cached), the transcript language ("Automatisch erkennen"),
// the recordings key, the wiring (endpoints, guests, CSRF, nginx, migration 036, export,
// dictation endpoint) and, with a database, the account's choices (rolled back).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/voice_store.php';
require_once $root . '/api/voice_core.php';

function vs_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Voice settings FAIL: {$why}\n"); exit(1); }
}
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);

// --- The voices ------------------------------------------------------------------------------
$choices = coreui_voice_choices();
$names = array_column($choices, 'name');
vs_test(count($choices) === 20 && $names[0] === 'eve' && coreui_voice_default_name() === 'eve', 'twenty voices, Eve first and the default');
vs_test(array_unique(array_merge(...array_column($choices, 'languages'))) === ['de', 'en']
  && count(array_filter($choices, static fn(array $v): bool => $v['languages'] === ['de', 'en'])) === 20, 'every voice speaks German and English');
vs_test(!array_intersect($names, ['cosette', 'jean', 'juergen', 'giovanni', 'lola', 'rafael', 'daan']), 'no NC voice, none without a documented source');
vs_test(count(array_filter($choices, static fn(array $v): bool => in_array($v['license'], ['CC BY 4.0', 'CC0 1.0'], true))) === 20, 'free licenses only');
vs_test(count(array_filter($choices, static fn(array $v): bool => $v['gender'] === 'weiblich')) === 11
  && count(array_filter($choices, static fn(array $v): bool => $v['gender'] === 'männlich')) === 9, 'female and male voices');
vs_test(coreui_voice_choice_valid('anna', 'de') && coreui_voice_choice_valid('george', 'en') && !coreui_voice_choice_valid('anna', 'fr')
  && !coreui_voice_choice_valid('cosette', 'de') && !coreui_voice_choice_valid('../eve', 'de') && !coreui_voice_choice_valid('', 'de'),
  'only registered voices in Mara\'s languages');
$labels = array_column($choices, 'label', 'name');
vs_test($labels['caro_davy'] === 'Caro' && $labels['bill_boerst'] === 'Bill' && $labels['eve'] === 'Eve', 'short names for the dialog');

// --- Plans, parts and the preview through a stand-in service ------------------------------------
$base = sys_get_temp_dir() . '/coreai-voice-settings-' . bin2hex(random_bytes(4));
mkdir($base . '/cache', 0700, true);
file_put_contents($base . '/router.php', <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'];
$body = json_decode((string)file_get_contents('php://input'), true);
$voice = is_array($body) && isset($body['voice']) ? (string)$body['voice'] : '-';
file_put_contents(__DIR__ . '/calls.log', $_SERVER['REQUEST_METHOD'] . ' ' . $uri . ' ' . (string)($body['language'] ?? '') . ' ' . $voice . "\n", FILE_APPEND);
$text = is_array($body) ? (string)($body['text'] ?? '') : '';
header('Content-Type: application/json');
if ($uri === '/plan') {
  $english = str_contains($text, 'ENGLISH');
  echo json_encode(['ok' => true, 'parts' => ['Hallo.'], 'textprep' => $english ? 'en-1' : 'de-2', 'language' => $english ? 'en' : 'de']);
  return;
}
if ($uri === '/speak') {
  if ($voice === 'jane') { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'voice_loading']); return; }
  header('Content-Type: audio/wav');
  echo 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\0", 20) . 'data' . pack('V', 0) . $voice . ':' . $text;
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
$log = static fn(): string => (string)@file_get_contents($base . '/calls.log');

try {
  vs_test(coreui_voice_plan_id('x') === coreui_voice_plan_id('x', 'de', ['de' => 'eve', 'en' => 'eve'])
    && coreui_voice_plan_id('x', 'de', ['de' => 'cosette']) === coreui_voice_plan_id('x'), 'with Eve (or an unknown voice) the id stays as before');
  vs_test(coreui_voice_plan_id('x', 'de', ['de' => 'anna']) !== coreui_voice_plan_id('x')
    && coreui_voice_plan_id('x', 'de', ['de' => 'anna']) !== coreui_voice_plan_id('x', 'de', ['en' => 'anna']), 'another voice, another id');
  vs_test(coreui_voice_plan_voices(['en' => 'george', 'de' => 'anna', 'fr' => 'anna']) === ['de' => 'anna', 'en' => 'george']
    && coreui_voice_plan_voices(['de' => 'eve', 'en' => 'cosette']) === [], 'voices in a fixed order, only real choices');

  $dir = coreui_voice_user_dir(coreui_voice_cache_root(), 7, true);
  $eve = coreui_voice_plan($dir, 'Eine Antwort.', 'de', ['de' => 'eve', 'en' => 'eve']);
  $plain = json_decode((string)file_get_contents($dir . '/' . $eve['id'] . '.json'), true);
  vs_test(!isset($plain['voices']) && $eve['id'] === coreui_voice_plan_id('Eine Antwort.'), 'an Eve plan is the plan from before 0.7.14');
  coreui_voice_audio($dir, $eve['id'], 0);
  vs_test(str_contains($log(), 'POST /speak de -'), 'Eve: the service is asked for no voice');

  $anna = coreui_voice_plan($dir, 'Eine Antwort.', 'de', ['de' => 'anna', 'en' => 'george']);
  $stored = json_decode((string)file_get_contents($dir . '/' . $anna['id'] . '.json'), true);
  vs_test($anna['id'] !== $eve['id'] && $stored['voices'] === ['de' => 'anna', 'en' => 'george'], 'the plan keeps the chosen voices');
  $file = coreui_voice_audio($dir, $anna['id'], 0);
  vs_test(str_ends_with((string)file_get_contents($file), 'anna:Hallo.') && str_contains($log(), 'POST /speak de anna'), 'a German part in Anna\'s voice');
  $english = coreui_voice_plan($dir, 'ENGLISH answer.', 'de', ['de' => 'anna', 'en' => 'george']);
  vs_test(str_ends_with((string)file_get_contents(coreui_voice_audio($dir, $english['id'], 0)), 'george:Hallo.'), 'an English part in George\'s voice');
  // A plan written by hand with a voice that is not registered (an old registry) falls back to Eve.
  file_put_contents($dir . '/' . str_repeat('c', 64) . '.json', json_encode(['parts' => ['Alt.'], 'language' => 'de', 'voices' => ['de' => 'cosette']]));
  vs_test(str_ends_with((string)file_get_contents(coreui_voice_audio($dir, str_repeat('c', 64), 0)), '-:Alt.'), 'an unknown voice in a plan is never sent');

  // Preview: Mara's short sentence, shared by all accounts, computed once.
  $calls = substr_count($log(), 'POST /speak');
  $preview = coreui_voice_preview('anna', 'de');
  vs_test(str_ends_with((string)file_get_contents($preview), 'anna:' . COREUI_VOICE_PREVIEW_TEXT['de']) && dirname($preview) === coreui_voice_cache_root() . '/preview',
    'preview in the voice, in the shared preview folder');
  vs_test(coreui_voice_preview('anna', 'de') === $preview && substr_count($log(), 'POST /speak') === $calls + 1, 'preview from the cache the second time');
  vs_test(str_ends_with((string)file_get_contents(coreui_voice_preview('eve', 'en')), '-:' . COREUI_VOICE_PREVIEW_TEXT['en']), 'Eve\'s preview asks for no voice');
  $threw = static function (callable $fn, string $class, string $code): bool {
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class && $e->getMessage() === $code; }
    return false;
  };
  vs_test($threw(fn() => coreui_voice_preview('cosette', 'de'), InvalidArgumentException::class, 'invalid_voice')
    && $threw(fn() => coreui_voice_preview('anna', 'fr'), InvalidArgumentException::class, 'invalid_voice'), 'no preview for unknown voices or languages');
  vs_test($threw(fn() => coreui_voice_preview('jane', 'en'), RuntimeException::class, 'voice_loading') && count(glob(dirname($preview) . '/*.wav')) === 2,
    'a voice still loading is passed on, nothing cached');
  vs_test(str_contains(COREUI_VOICE_PREVIEW_TEXT['de'], 'ich bin Mara') && str_contains(COREUI_VOICE_PREVIEW_TEXT['en'], "I'm Mara"), 'Mara speaks the preview herself');
} finally {
  proc_terminate($server);
  proc_close($server);
  exec('rm -rf ' . escapeshellarg($base));
}

// --- Transcript language, recordings key ----------------------------------------------------------
require_once $root . '/api/voice_transcribe.php';
vs_test(COREUI_VOICE_INPUT_LANGUAGES === ['de', 'en', 'fr', 'it', 'pt', 'es']
  && array_keys(COREUI_VOICE_TRANSCRIBE_LANGUAGES) === COREUI_VOICE_INPUT_LANGUAGES, 'every offered language has a template name');
vs_test(str_starts_with(coreui_voice_transcribe_prompt(coreui_voice_language_name('auto:en')),
  'Transcribe the following speech segment into text in the language in which it is spoken. Do not translate it. If the language is unclear, assume English.'),
  'automatic detection with the main language as fallback');
vs_test(str_starts_with(coreui_voice_transcribe_prompt('German'), 'Transcribe the following speech segment in German into German text.'), 'a fixed language as before');
vs_test(preg_match('/^[0-9a-f]{24}$/', coreui_voice_recordings_key(7)) === 1 && coreui_voice_recordings_key(7) !== coreui_voice_recordings_key(8)
  && coreui_voice_recordings_key(7) === substr(hash('sha256', 'coreai-recordings|7'), 0, 24), 'a recordings key per account, no user id in clear');

// --- Wiring ------------------------------------------------------------------------------------------
$api = $read('api/voice.php');
vs_test(strpos($api, "if (\$guest) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, "\$action === 'prefs'")
  && strpos($api, "\$action === 'prefs'") < strpos($api, "\$action === 'preview'"), 'prefs and preview only for accounts, never guests');
vs_test(strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'settings') {")
  && str_contains($api, "array_flip(['voice_de', 'voice_en', 'input_language', 'dictation'])"), 'settings with CSRF, only the known keys');
vs_test(str_contains($api, "coreui_voice_plan(\$dir, (string)\$row['message'], \$hint, coreui_voice_prefs(\$pdo, \$uid)['voices']);"), 'read aloud in the chosen voices');
vs_test(str_contains($read('api/voice_live.php'), "coreui_voice_plan(\$dir, \$text, (string)(coreui_ai_runtime_settings()['main_language'] ?? 'de'), coreui_voice_prefs(\$pdo, \$uid)['voices']);")
  && str_contains($read('api/voice_live.php'), "coreui_voice_language_name(coreui_voice_input_code(\$pdo, \$uid, "), 'live: the chosen voices and the transcript language');
$attachments = $read('api/ember_attachments.php');
vs_test(substr_count($attachments, 'coreui_voice_input_code($pdo, $uid, ') === 2, 'Stimmsequenzen: before the answer and afterwards in the chosen language');
$dictate = $read('api/voice_dictate.php');
vs_test(strpos($dictate, "if (coreui_guest_is(\$pdo, \$uid)) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($dictate, 'stu_require_csrf();')
  && strpos($dictate, 'stu_require_csrf();') < strpos($dictate, "\$_FILES['audio']")
  && strpos($dictate, "['dictation']) stu_json(['ok' => false, 'error' => 'dictation_off'], 403);") < strpos($dictate, "\$_FILES['audio']")
  && str_contains($dictate, "@unlink((string)\$upload['tmp_name']);") && str_contains($dictate, 'COREUI_VOICE_DICTATION_MAX_BYTES')
  && str_contains($dictate, "'language' => coreui_voice_input_code(\$pdo, \$uid, ") && str_contains($dictate, "'terms' => coreui_voice_terms_load(\$pdo, \$uid),"),
  'dictation: no guests, CSRF, only while on, size limit, the chosen language and terms, nothing kept');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $template) {
  vs_test(str_contains($read($template), '|voice_input|voice_live|voice_dictate|storage|'), "nginx allows dictation: $template");
}
$migration = $read('database/migrations/036_voice_choice.sql');
vs_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS voice_de VARCHAR(32) NULL') && str_contains($migration, 'ADD COLUMN IF NOT EXISTS input_language VARCHAR(8) NULL')
  && str_contains($migration, 'ADD COLUMN IF NOT EXISTS dictation TINYINT(1) NULL') && str_contains($migration, "VALUES ('036_voice_choice')"), 'migration 036');
vs_test(str_contains($read('api/account_export.php'), "'voice' => coreui_voice_prefs(\$pdo, (int)\$uid) + ['auto_read' => coreui_voice_auto_read(\$pdo, (int)\$uid)],"),
  'the export carries the voice settings');
$settings = $read('settings.html');
foreach (['id="vsVoiceOpen"', 'id="vsLanguage"', 'id="vsDictation"', 'id="vsRecordingsOpen"', 'id="vsVoiceDialog"', 'id="vsRecDialog"',
  'id="voiceAutoReadRow"', 'id="voiceTermsRow"', 'js/coreui-recordings.js', 'js/coreui-voice-settings.js'] as $needle) {
  vs_test(str_contains($settings, $needle), 'settings page: ' . $needle);
}
vs_test(!str_contains($settings, 'Weitere Stimmen werden hier erst angeboten'), 'no promise left that is kept now');
vs_test(strpos($read('app.html'), 'js/coreui-recordings.js') < strpos($read('app.html'), 'js/coreui-voice-input.js'), 'the chat keeps recordings before the microphone script');

// --- With a database: the account's choices, rolled back ------------------------------------------
$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI voice settings: voices, plans and parts in the chosen voice, preview, transcript language, recordings key and wiring OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
vs_test(coreui_voice_prefs_ready($pdo), 'migration 036 applied');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 4, NOW())')
    ->execute([$uid, 'voice-settings-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  vs_test(coreui_voice_prefs($pdo, $uid) === ['voices' => ['de' => 'eve', 'en' => 'eve'], 'input_language' => '', 'dictation' => true],
    'defaults: Eve, the main language, dictation on');
  vs_test(coreui_voice_input_code($pdo, $uid, 'en') === 'en' && coreui_voice_input_code($pdo, $uid, 'xx') === 'de', 'unset: the main language');
  $saved = coreui_voice_prefs_save($pdo, $uid, ['voice_de' => 'anna', 'voice_en' => 'george', 'input_language' => 'auto', 'dictation' => false]);
  vs_test($saved === ['voices' => ['de' => 'anna', 'en' => 'george'], 'input_language' => 'auto', 'dictation' => false], 'saved and read back');
  vs_test(coreui_voice_input_code($pdo, $uid, 'de') === 'auto:de', 'automatic detection with the main language');
  vs_test(coreui_voice_auto_read($pdo, $uid) === false && coreui_voice_terms_load($pdo, $uid) === [], 'reading aloud and terms untouched');
  coreui_voice_set_auto_read($pdo, $uid, true);
  vs_test(coreui_voice_prefs($pdo, $uid)['voices']['de'] === 'anna', 'switching reading aloud keeps the voices');
  $saved = coreui_voice_prefs_save($pdo, $uid, ['voice_en' => 'eve', 'input_language' => 'fr']);
  $row = $pdo->query('SELECT voice_de, voice_en, input_language, dictation FROM stu_user_voice_settings WHERE user_id = ' . (int)$uid)->fetch(PDO::FETCH_ASSOC);
  vs_test($row === ['voice_de' => 'anna', 'voice_en' => null, 'input_language' => 'fr', 'dictation' => 0] && $saved['voices']['en'] === 'eve',
    'Eve is stored as "no choice", only the named keys change');
  vs_test(coreui_voice_input_code($pdo, $uid, 'de') === 'fr', 'a fixed language');
  foreach ([['voice_de' => 'cosette'], ['voice_en' => 7], ['input_language' => 'xx'], ['input_language' => null], ['dictation' => 'vielleicht']] as $bad) {
    try { coreui_voice_prefs_save($pdo, $uid, $bad); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
    vs_test($ok, 'refused: ' . json_encode($bad));
  }
  vs_test(coreui_voice_prefs($pdo, $uid)['voices']['de'] === 'anna', 'a refused change leaves the choice');
  $pdo->exec("UPDATE stu_user_voice_settings SET voice_de = 'cosette', input_language = 'zz' WHERE user_id = " . (int)$uid);
  vs_test(coreui_voice_prefs($pdo, $uid)['voices']['de'] === 'eve' && coreui_voice_prefs($pdo, $uid)['input_language'] === '',
    'a value that is no longer offered reads as the default');
  vs_test(coreui_voice_prefs_save($pdo, $uid, []) === coreui_voice_prefs($pdo, $uid), 'nothing to save, nothing changed');
  vs_test(coreui_voice_prefs($pdo, 0)['voices'] === ['de' => 'eve', 'en' => 'eve'], 'nobody: defaults');
  vs_test($pdo->inTransaction(), 'still inside the transaction');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "CoreAI voice settings: voices, plans and parts in the chosen voice, preview, transcript language, recordings key, wiring and the account's choices on the database (rolled back) OK\n";
