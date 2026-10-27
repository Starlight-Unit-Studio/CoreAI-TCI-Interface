<?php
declare(strict_types=1);
// CoreAI 0.5.70 (#113): KI-Profil context window. Offline: no Ollama, no
// MariaDB; persistence runs on SQLite, the estimate on recorded metadata.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/ai_context_estimate.php';

function ctx_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "AI context FAIL: {$why}\n"); exit(1); }
}

// --- Whitelist ---------------------------------------------------------
$stops = [4096, 8192, 16384, 32768, 65536, 131072];
ctx_test(coreui_ai_num_ctx_choices() === $stops, 'six slider stops from 4K to 128K');
foreach ($stops as $n) {
  ctx_test(coreui_ai_num_ctx_normalise($n) === $n && coreui_ai_num_ctx_normalise((string)$n) === $n,
    'stop accepted: ' . $n);
}
foreach ([null, '', false, 0, -4096, 12345, 262144, 4096.5, '4096abc', 'model', [8192], true] as $bad) {
  ctx_test(coreui_ai_num_ctx_normalise($bad) === null, 'invalid window refused: ' . var_export($bad, true));
}

// --- Persistence (SQLite stand-in for stu_user_ai_settings) -------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_user_ai_settings (user_id INTEGER PRIMARY KEY, system_prompt TEXT,
  memory_enabled INTEGER, thinking_enabled INTEGER, memory_limit INTEGER, num_predict INTEGER,
  temperature REAL, provider TEXT, model_override TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO stu_user_ai_settings VALUES (7,'',1,1,16,6500,1.0,'local',NULL,'now')");
ctx_test(!coreui_ai_num_ctx_schema_ready($pdo), 'missing migration 017 detected');
ctx_test(coreui_ai_settings_load($pdo, 7)['num_ctx'] === null, 'old schema loads as Core-Standard');
try {
  coreui_ai_settings_save($pdo, 7, ['num_ctx'=>8192]);
  ctx_test(false, 'a chosen window must not be dropped silently without migration 017');
} catch (RuntimeException $e) {
  ctx_test($e->getMessage() === 'missing_schema_017', 'missing_schema_017 reported');
}

$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('CREATE TABLE stu_user_ai_settings (user_id INTEGER PRIMARY KEY, system_prompt TEXT,
  memory_enabled INTEGER, thinking_enabled INTEGER, memory_limit INTEGER, num_predict INTEGER,
  num_ctx INTEGER NULL, temperature REAL, provider TEXT, model_override TEXT, updated_at TEXT)');
$pdo2->exec("INSERT INTO stu_user_ai_settings VALUES (7,'',1,1,16,6500,8192,1.0,'local',NULL,'now')");
$pdo2->exec("INSERT INTO stu_user_ai_settings VALUES (8,'',1,1,16,6500,NULL,1.0,'local',NULL,'now')");
$pdo2->exec("INSERT INTO stu_user_ai_settings VALUES (9,'',1,1,16,6500,5000,1.0,'local',NULL,'now')");
ctx_test(coreui_ai_num_ctx_schema_ready($pdo2), 'migration 017 column detected');
ctx_test(coreui_ai_settings_load($pdo2, 7)['num_ctx'] === 8192, 'saved window loads unchanged');
ctx_test(coreui_ai_settings_load($pdo2, 8)['num_ctx'] === null, 'NULL stays Core-Standard');
ctx_test(coreui_ai_settings_load($pdo2, 9)['num_ctx'] === null, 'a manipulated row is normalised away');
ctx_test(coreui_ai_settings_load($pdo2, 99)['num_ctx'] === null, 'accounts without a row keep the default');
$saveSql = (string)file_get_contents($root . '/api/ai_settings.php');
ctx_test(str_contains($saveSql, "num_ctx = VALUES(num_ctx)") && str_contains($saveSql, "\$params[] = \$value['num_ctx'];"),
  'save writes num_ctx with the other profile values');
$migration = (string)file_get_contents($root . '/database/migrations/017_ai_num_ctx.sql');
ctx_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS num_ctx INT UNSIGNED NULL DEFAULT NULL')
  && str_contains($migration, "'017_ai_num_ctx'"), 'migration 017 is additive and registered');

// --- Per-run fixation ---------------------------------------------------
unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);
ctx_test(coreui_ai_runtime_apply($pdo2, 7)['num_ctx'] === 8192, 'run starts with the saved window');
$pdo2->exec('UPDATE stu_user_ai_settings SET num_ctx=32768 WHERE user_id=7');
ctx_test(coreui_ai_runtime_apply($pdo2, 7)['num_ctx'] === 8192
  && coreui_ai_num_ctx_choice() === 8192, 'a change during a running job waits for the next job');
unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);
ctx_test(coreui_ai_runtime_apply($pdo2, 7)['num_ctx'] === 32768, 'the next job uses the new window');

// --- Ollama options: only on explicit choice, never cloud ----------------
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['num_ctx'=>8192];
foreach (['ember-coreui:latest','gemma4:e4b','gemma3:latest'] as $gemma) {
  ctx_test(ember_ollama_known_good_options($gemma) === ['num_ctx'=>8192], 'Gemma gets exactly num_ctx: ' . $gemma);
  ctx_test(ember_ollama_known_good_options($gemma, ['temperature'=>0.1,'num_ctx'=>512,'top_p'=>0.2])
    === ['num_ctx'=>8192], 'no other Gemma parameter slips through: ' . $gemma);
  ctx_test(ember_ollama_context_window($gemma) === 8192
    && $GLOBALS['COREUI_OLLAMA_CONTEXT_SOURCE'][$gemma] === 'profile', 'budgets use the profile window: ' . $gemma);
}
foreach (['gemma4:31b-cloud','gpt-oss:120b-cloud'] as $cloud) {
  ctx_test(!array_key_exists('num_ctx', ember_ollama_known_good_options($cloud, ['num_ctx'=>4096])),
    'cloud never receives num_ctx: ' . $cloud);
  ctx_test(ember_ollama_context_window($cloud) === null, 'cloud window belongs to the provider: ' . $cloud);
}
ctx_test(ember_ollama_known_good_options('gemma4:31b-cloud') === [], 'cloud Gemma stays parameter-free');
$llama = ember_ollama_known_good_options('llama3.2:latest', ['num_ctx'=>512]);
ctx_test(($llama['num_ctx'] ?? null) === 8192 && isset($llama['temperature']),
  'other local Cores keep their sampler and use the profile window');
ctx_test(ember_ollama_context_observe('ember-coreui:latest', 'length', 7000, 1192) === 8192,
  'a full profile window is reported');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['num_ctx'=>12345];
ctx_test(ember_ollama_known_good_options('ember-coreui:latest') === [], 'invalid runtime value sends nothing');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['num_ctx'=>null];
ctx_test(ember_ollama_known_good_options('ember-coreui:latest') === [], 'Core-Standard stays parameter-free');
ctx_test(ember_profile_num_ctx('ember-coreui:latest') === null, 'no choice, no override');

// Both generation paths use the same option policy and omit empty options.
$stream = (string)file_get_contents($root . '/api/console_stream.php');
$chat = (string)file_get_contents($root . '/api/chat.php');
ctx_test(str_contains($stream, '$options = ember_ollama_known_good_options($model);')
  && str_contains($chat, '$options = ember_ollama_known_good_options($model, $optionOverrides);'),
  'SSE and sync share the option policy');
ctx_test(substr_count($chat, "if (\$channel !== 'console') \$GLOBALS['COREUI_AI_RUNTIME_SETTINGS']['num_ctx'] = null;") === 2,
  'the global channel and its queue keep the Core default');
ctx_test(!str_contains(substr($chat, (int)strpos($chat, 'function ember_profile_num_ctx'), 600), 'ember_num_ctx('),
  'the old 64K clamp is not used for the profile');

// --- Memory estimate -----------------------------------------------------
$gib = 1073741824;
$gemma27 = [
  'general.architecture'=>'gemma3', 'gemma3.block_count'=>62, 'gemma3.attention.head_count'=>32,
  'gemma3.attention.head_count_kv'=>16, 'gemma3.attention.key_length'=>128,
  'gemma3.attention.value_length'=>128, 'gemma3.attention.sliding_window'=>1024,
  'gemma3.context_length'=>131072, 'gemma3.embedding_length'=>5376,
];
$layout = coreui_ai_kv_layout($gemma27);
ctx_test($layout !== null && $layout['global_per_token'] === 10 * 16 * 256 * 2
  && $layout['local_per_token'] === 52 * 16 * 256 * 2 && $layout['window'] === 1024,
  'Gemma 3 rhythm: ten global and fifty two sliding layers');
$base = ['model'=>'gemma3:27b', 'weights_bytes'=>17 * $gib, 'model_info'=>$gemma27,
  'parameters'=>"num_ctx 131072\ntemperature 1", 'loaded'=>null, 'loaded_total'=>0,
  'mem_available'=>96 * $gib, 'choices'=>$stops];
$est = coreui_ai_context_estimate_calc($base);
ctx_test($est['applicable'] && $est['method'] === 'metadata' && count($est['stops']) === 6, 'six estimated stops');
$prev = 0.0;
foreach ($est['stops'] as $i => $stop) {
  ctx_test($stop['tokens'] === $stops[$i] && $stop['low_gb'] !== null && $stop['high_gb'] >= $stop['low_gb'],
    'every stop has a range: ' . $stops[$i]);
  ctx_test($stop['low_gb'] >= $prev, 'estimates grow with the window');
  ctx_test(!$stop['warn'], 'no warning with plenty of memory');
  $prev = $stop['low_gb'];
}
ctx_test($est['stops'][0]['low_gb'] >= 15 && $est['stops'][5]['high_gb'] <= 34,
  'a 27B Q4 Core stays in a plausible range');
ctx_test($est['default_context'] === 131072 && $est['default']['tokens'] === 131072, 'Core-Standard from the Modelfile');
ctx_test($est['memory_label'] === 'Arbeitsspeicher' && $est['loaded_context'] === null, 'unloaded Core');

$small = coreui_ai_context_estimate_calc(['weights_bytes'=>(int)(5.2 * $gib),
  'model_info'=>array_merge($gemma27, ['gemma3.block_count'=>34, 'gemma3.attention.head_count_kv'=>4])] + $base);
ctx_test($small['stops'][0]['low_gb'] < $est['stops'][0]['low_gb']
  && $small['stops'][5]['high_gb'] < $est['stops'][5]['high_gb'], 'a smaller quantised Core estimates less');

$tight = coreui_ai_context_estimate_calc(['mem_available'=>8 * $gib] + $base);
ctx_test($tight['stops'][0]['warn'] && $tight['default']['warn'] && $tight['available_gb'] === 8.0,
  'too little free memory warns, without blocking');
$fluctuating = coreui_ai_context_estimate_calc(['mem_available'=>20 * $gib] + $base);
ctx_test(!$fluctuating['stops'][0]['warn'] && $fluctuating['stops'][5]['warn'],
  'only the stops above the free memory warn');

$cpuLoaded = coreui_ai_context_estimate_calc(['loaded'=>['size'=>(int)(29 * $gib), 'size_vram'=>0,
  'context_length'=>131072], 'loaded_total'=>(int)(29 * $gib), 'mem_available'=>4 * $gib] + $base);
ctx_test($cpuLoaded['processor'] === 'cpu' && $cpuLoaded['memory_label'] === 'RAM'
  && $cpuLoaded['loaded_context'] === 131072, 'CPU runner is labelled RAM');
ctx_test(!$cpuLoaded['stops'][5]['warn'], 'memory of the loaded Core counts as usable');
$gpu = coreui_ai_context_estimate_calc(['loaded'=>['size'=>20 * $gib, 'size_vram'=>20 * $gib,
  'context_length'=>8192]] + $base);
ctx_test($gpu['memory_label'] === 'VRAM' && $gpu['processor'] === 'gpu', 'GPU runner is labelled VRAM');
$hybrid = coreui_ai_context_estimate_calc(['loaded'=>['size'=>20 * $gib, 'size_vram'=>9 * $gib,
  'context_length'=>8192]] + $base);
ctx_test($hybrid['memory_label'] === 'RAM + VRAM', 'hybrid runner names both');
$doubled = coreui_ai_context_estimate_calc(['loaded'=>['size'=>(int)(17 * $gib + 2 * 11.5 * $gib),
  'size_vram'=>0, 'context_length'=>131072]] + $base);
ctx_test($doubled['stops'][5]['low_gb'] > $est['stops'][5]['high_gb'],
  'a measured runner (for example two parallel slots) corrects the metadata estimate');

$noMeta = coreui_ai_context_estimate_calc(['model_info'=>[]] + $base);
ctx_test($noMeta['method'] === null && $noMeta['stops'][3]['low_gb'] === null,
  'missing metadata: no invented number');
$measured = coreui_ai_context_estimate_calc(['model_info'=>[], 'loaded'=>['size'=>(int)(21 * $gib),
  'size_vram'=>0, 'context_length'=>32768]] + $base);
ctx_test($measured['method'] === 'measured' && $measured['stops'][3]['low_gb'] !== null,
  'a loaded runner still allows a rough measured estimate');
$gemma4Unknown = ['general.architecture'=>'gemma4', 'gemma4.block_count'=>48, 'gemma4.attention.head_count'=>16,
  'gemma4.attention.head_count_kv'=>8, 'gemma4.attention.key_length'=>256, 'gemma4.attention.sliding_window'=>512];
ctx_test(coreui_ai_kv_layout($gemma4Unknown) === null, 'unknown sliding rhythm is not guessed');
$gemma4Pattern = $gemma4Unknown + ['gemma4.attention.sliding_window_pattern'=>[true, true, true, true, true, false]];
ctx_test(coreui_ai_kv_layout($gemma4Pattern)['global_per_token'] === 8 * 8 * 512 * 2,
  'an explicit per layer pattern is used');
$trained = coreui_ai_context_estimate_calc(['model_info'=>array_merge($gemma27, ['gemma3.context_length'=>32768])] + $base);
ctx_test(empty($trained['stops'][3]['beyond_model']) && !empty($trained['stops'][4]['beyond_model']),
  'stops above the trained window are marked');
$fallback = coreui_ai_context_estimate_calc(['parameters'=>'', 'loaded'=>['size'=>18 * $gib,
  'size_vram'=>0, 'context_length'=>4096]] + $base);
ctx_test($fallback['default_context'] === 4096, 'without Modelfile value the loaded window is the default');
$chosenLoaded = coreui_ai_context_estimate_calc(['parameters'=>'', 'chosen'=>8192, 'loaded'=>['size'=>18 * $gib,
  'size_vram'=>0, 'context_length'=>8192]] + $base);
ctx_test($chosenLoaded['default_context'] === null, 'a profile-loaded runner is not mistaken for the default');
$cloud = coreui_ai_context_estimate_calc(['model'=>'gpt-oss:120b-cloud', 'cloud'=>true, 'choices'=>$stops]);
ctx_test($cloud['applicable'] === false && $cloud['reason'] === 'cloud' && $cloud['stops'] === [],
  'cloud: not applicable');
ctx_test(coreui_mem_available_bytes("MemTotal: 100 kB\nMemAvailable:   2048 kB\n") === 2097152
  && coreui_mem_available_bytes('garbage') === null, 'MemAvailable parsing');

// --- Endpoint contract ---------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/user_settings.php');
ctx_test(str_contains($endpoint, "'invalid_num_ctx'], 400") && str_contains($endpoint, "'context_model'")
  && str_contains($endpoint, 'session_write_close()') && str_contains($endpoint, "'num_ctx_choices'")
  && str_contains($endpoint, 'coreui_model_name_valid($model)'), 'settings endpoint validates and estimates');
$estimateLib = (string)file_get_contents($root . '/api/ai_context_estimate.php');
ctx_test(!preg_match('~ollama\s+(?:create|rm|stop|pull)|/api/(?:create|delete|pull|generate|chat)\b~i', $estimateLib),
  'the estimate only reads from Ollama');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  ctx_test(str_contains((string)file_get_contents($root . '/' . $nginx), '|user_settings|'),
    'settings endpoint routed: ' . $nginx);
}
$template = (string)file_get_contents($root . '/config/Modelfile.template');
ctx_test(str_contains($template, 'FROM gemma4:e4b'), 'Modelfile template untouched');

echo "CoreAI KI-Profil context window: whitelist, persistence, run fixation, options and estimates OK\n";
