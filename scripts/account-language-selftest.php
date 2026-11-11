<?php
declare(strict_types=1);
// CoreAI 0.6.10 (P20.3b): the main language per account, chosen in the KI-Profil.
//   php scripts/account-language-selftest.php
// German or English. It sets Mara's default answer language (she still answers
// in the language a person writes in) and decides the read-aloud voice when the
// language of an answer is unclear. The interface locale is a separate
// browser preference (DE/EN beta). Offline: rules, prompt and wiring. With a
// an isolated test database only (COREUI_SELFTEST_DB=1 and dedicated
// COREUI_SELFTEST_DB_DSN, _USER and _PASS): transaction rolled back.
// Regular production Preflight NEVER inserts into live stu_users.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/ai_settings.php';
require_once $root . '/api/voice_store.php';

function language_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Account language FAIL: {$why}\n"); exit(1); }
}

// Rules.
language_test(COREUI_AI_LANGUAGES === ['de', 'en'], 'German and English');
language_test(coreui_ai_language_normalise('en') === 'en' && coreui_ai_language_normalise(' EN ') === 'en', 'English');
foreach (['fr', '', null, 7, ['en'], 'english'] as $other) {
  language_test(coreui_ai_language_normalise($other) === 'de', 'anything else is German: ' . var_export($other, true));
}

// Prompt: German adds nothing (Modelfile default), English adds a short block; the KI-Profil prompt stays.
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['main_language' => 'de', 'system_prompt' => ''];
language_test(coreui_ai_user_prompt_block() === '', 'German: the prompt is as before');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['main_language' => 'en', 'system_prompt' => ''];
$english = coreui_ai_user_prompt_block();
language_test(str_contains($english, 'Answer in English by default.') && str_contains($english, 'answer in that language')
  && str_contains($english, 'German and English are your main languages.'), 'English: default English, other languages still answered');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['main_language' => 'en', 'system_prompt' => 'Kurz und freundlich.'];
$both = coreui_ai_user_prompt_block();
language_test(strpos($both, '--- SPRACHE ---') < strpos($both, 'Kurz und freundlich.') && str_contains($both, '--- BENUTZERKONFIGURATION'),
  'language block before the own KI-Profil prompt');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['main_language' => 'de', 'system_prompt' => 'Kurz und freundlich.'];
language_test(!str_contains(coreui_ai_user_prompt_block(), 'SPRACHE'), 'German with an own prompt: no language block');
unset($GLOBALS['COREUI_AI_RUNTIME_SETTINGS']);

// Read aloud: the account language is the hint for unclear answers and part of the plan.
language_test(coreui_voice_plan_id('OK') === coreui_voice_plan_id('OK', 'de'), 'German plans keep their id');
language_test(coreui_voice_plan_id('OK', 'en') !== coreui_voice_plan_id('OK', 'de'), 'an English hint is another plan');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$settingsApi = $read('api/user_settings.php');
language_test(strpos($settingsApi, "'error'=>'invalid_language'") !== false
  && strpos($settingsApi, "'error'=>'invalid_language'") < strpos($settingsApi, "\$requestedCtx = \$body['num_ctx'] ?? null;"),
  'an unknown language is refused before saving');
language_test(str_contains($settingsApi, "'language_available' => coreui_ai_language_schema_ready(\$pdo),"), 'the page learns whether it can be stored');
language_test(str_contains($settingsApi, "if (!array_key_exists('main_language', \$body)) {\n  \$body['main_language'] = coreui_ai_settings_load(\$pdo, \$uid)['main_language'];"),
  'a save without the language keeps the stored one');
$aiSettings = $read('api/ai_settings.php');
language_test(str_contains($aiSettings, "throw new RuntimeException('missing_schema_024');"), 'English is never dropped silently without migration 024');
$voiceApi = $read('api/voice.php');
language_test(str_contains($voiceApi, "coreui_ai_settings_load(\$pdo, \$uid)['main_language']") && str_contains($voiceApi, 'coreui_voice_plan($dir, (string)$row[\'message\'], $hint);'),
  'read aloud uses the account language as hint');
language_test(str_contains($read('api/voice_store.php'), "['text' => \$text, 'language' => \$hint]"), 'the hint goes to the voice service');
$migration = $read('database/migrations/024_account_language.sql');
language_test(str_contains($migration, "ADD COLUMN IF NOT EXISTS main_language CHAR(2) NOT NULL DEFAULT 'de'")
  && str_contains($migration, "VALUES ('024_account_language')"), 'migration 024, German by default');
$page = $read('settings.html');
language_test(str_contains($page, '<select id="aiLanguage"><option value="de">DEUTSCH</option><option value="en">ENGLISH</option></select>')
  && str_contains($page, 'Knöpfe und Menüs bleiben vorerst deutsch.'), 'KI-Profil: the choice and the honest note');
$script = $read('js/settings.js');
language_test(str_contains($script, "main_language: aiLanguage && aiLanguage.value === 'en' ? 'en' : 'de'"), 'the KI-Profil saves the language');

// The prior default tested against the LIVE production database whenever
// config.local.php existed. It produced lang-<random>@coreui.invalid rows on
// some installations despite the intended transaction rollback. Never write
// test records to any real account database during routine Preflight.
$dbWanted = getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI account language: German or English, prompt, read-aloud hint and wiring OK (live database untouched)\n";
  exit(0);
}
// Integration testing is an explicit separate task, not a production Preflight.
// Require dedicated credentials and an unmistakably named _selftest database.
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
$dsn = (string)(getenv('COREUI_SELFTEST_DB_DSN') ?: '');
$user = (string)(getenv('COREUI_SELFTEST_DB_USER') ?: '');
$pass = getenv('COREUI_SELFTEST_DB_PASS');
if (!str_starts_with($dsn, 'mysql:') || $user === '' || $pass === false) {
  fwrite(STDERR, "Account language FAIL: isolated COREUI_SELFTEST_DB_DSN, _USER and _PASS required\n");
  exit(1);
}
try {
  $pdo = new PDO($dsn, $user, (string)$pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
  if (!preg_match('/^[a-zA-Z0-9_]+_selftest$/D', $dbName)
      || strcasecmp($dbName, (string)STU_DB_NAME) === 0) {
    fwrite(STDERR, "Account language FAIL: dedicated *_selftest database required; production refused\n");
    exit(1);
  }
} catch (PDOException $e) {
  fwrite(STDERR, "Account language FAIL: isolated database unavailable\n");
  exit(1);
}
language_test(coreui_ai_language_schema_ready($pdo), 'migration 024 applied');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 4, NOW())')
    ->execute([$uid, 'lang-' . bin2hex(random_bytes(4)) . '@coreui.invalid']);
  language_test(coreui_ai_settings_load($pdo, $uid)['main_language'] === 'de', 'German by default');
  $saved = coreui_ai_settings_save($pdo, $uid, ['main_language' => 'en']);
  language_test($saved['main_language'] === 'en' && coreui_ai_settings_load($pdo, $uid)['main_language'] === 'en', 'English saved and loaded');
  coreui_ai_runtime_apply($pdo, $uid);
  language_test(str_contains(coreui_ai_user_prompt_block(), 'Answer in English by default.'), 'the next answer gets the English line');
  coreui_ai_settings_save($pdo, $uid, ['main_language' => 'de']);
  language_test(coreui_ai_settings_load($pdo, $uid)['main_language'] === 'de', 'back to German');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "CoreAI account language: German or English, prompt, read-aloud hint and wiring, and saving on the database (rolled back) OK\n";
