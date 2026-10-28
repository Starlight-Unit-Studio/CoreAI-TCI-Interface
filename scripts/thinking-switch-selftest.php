<?php
declare(strict_types=1);
// CoreAI 0.5.75: the thinking switch in the chat really switches. The
// Modelfile no longer hard-wires <|think|>, the switch saves only its own
// value, the global channel keeps thinking, and a leading "[Analyse: ...]"
// block from a turn without thinking never reaches the reader.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/ai_settings.php';
require_once $root . '/api/console_build_intent.php';

function think_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Thinking switch FAIL: {$why}\n"); exit(1); }
}

// Modelfile: the request's think flag decides (gemma4 renderer adds <|think|>).
$modelfile = (string)file_get_contents($root . '/config/Modelfile.template');
think_test(!str_contains($modelfile, '<|think|>') && str_contains($modelfile, "SYSTEM \"\"\"Du bist Mara Thorne")
  && str_contains($modelfile, 'RENDERER gemma4'), 'Modelfile without a fixed thinking token, persona unchanged');

// Leading analysis blocks (real reply from the 28.10.2026 server run).
$real = '[Analyse: Der Nutzer hat eine Sprachnachricht mit einer Konversation beigefügt, die humorvolles Smalltalk '
  . 'und etwas informellen Wortwitz enthält. Ich soll darauf antworten, als wäre ich in diesem Gespräch, kurz und '
  . 'natürlich, maximal drei Sätze.] Na klar, hab ich. Aber lass mich raten: Du meinst die kleinen Hotels mit den '
  . 'gestohlenen Handtüchern? Ist ja lustig, was die Leute da machen. 😊';
think_test(str_starts_with(ember_sanitize_public_reply($real), 'Na klar, hab ich.'), 'real analysis block removed');
think_test(ember_sanitize_public_reply("[Überlegung: a]\n[Analysis: b] Hallo!") === 'Hallo!', 'several blocks, German and English');
think_test(ember_sanitize_public_reply('[WEB: wetter berlin heute]') === '[WEB: wetter berlin heute]', 'tool markers stay');
think_test(ember_sanitize_public_reply('[BROWSE: Preise vergleichen]') === '[BROWSE: Preise vergleichen]', 'browse marker stays');
think_test(ember_sanitize_public_reply('Siehe [Analyse: Seite 3] im Bericht.') === 'Siehe [Analyse: Seite 3] im Bericht.',
  'only a leading block is meta, text in the middle stays');
think_test(ember_sanitize_public_reply('[Hinweis: bleibt] Hallo') === '[Hinweis: bleibt] Hallo', 'other labels stay');
think_test(coreui_build_intent_decode("[Analyse: Plan ist freigegeben]\nSTART_BUILD") === 'build'
  && coreui_build_intent_decode('[Analyse: nur Plaudern] CHAT') === 'chat'
  && coreui_build_intent_decode('Ich denke START_BUILD') === null, 'build decision tolerates a leading block, nothing else');

// The switch saves only thinking_enabled; every other KI-Profil value stays.
final class ThinkStmt extends PDOStatement {
  public static array $params = [];
  protected function __construct() {}
  public function execute(?array $params = null): bool { self::$params = $params ?? []; return true; }
}
final class ThinkPdo extends PDO {
  public array $sql = [];
  public function __construct() {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->exec('CREATE TABLE stu_user_ai_settings (user_id INTEGER, thinking_enabled INTEGER)');
    $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ThinkStmt::class, []]);
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    $this->sql[] = $query;
    return parent::prepare('SELECT 1');
  }
}
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  $pdo = new ThinkPdo();
  think_test(coreui_ai_settings_patch($pdo, 7, ['thinking_enabled'=>false]) === ['thinking_enabled'=>false],
    'switch returns the saved value');
  $sql = (string)end($pdo->sql); // defaults may read stu_app_settings first
  think_test(ThinkStmt::$params[0] === 7 && ThinkStmt::$params[3] === 0 && ThinkStmt::$params[8] === null
    && count(ThinkStmt::$params) === 9, 'user and value are bound, a new row starts from defaults');
  think_test(str_contains($sql, 'ON DUPLICATE KEY UPDATE thinking_enabled = VALUES(thinking_enabled), updated_at = NOW()')
    && substr_count($sql, '= VALUES(') === 1, 'an existing row changes only thinking_enabled');
  // Core choice from the chat menu: only model_override changes.
  think_test(coreui_ai_settings_patch($pdo, 7, ['model_override'=>'gemma4:e4b']) === ['model_override'=>'gemma4:e4b'],
    'Core choice saved');
  $sql = (string)end($pdo->sql);
  think_test(str_contains($sql, 'ON DUPLICATE KEY UPDATE model_override = VALUES(model_override), updated_at = NOW()')
    && substr_count($sql, '= VALUES(') === 1 && ThinkStmt::$params[8] === 'gemma4:e4b', 'an existing row changes only the Core');
  coreui_ai_settings_patch($pdo, 7, ['model_override'=>'']);
  think_test(ThinkStmt::$params[8] === null, 'server default is stored as NULL');
  foreach ([[0, ['thinking_enabled'=>true]], [7, []], [7, ['system_prompt'=>'x']]] as [$user, $patch]) {
    try { coreui_ai_settings_patch($pdo, $user, $patch); think_test(false, 'invalid patch refused'); }
    catch (InvalidArgumentException $e) { think_test(in_array($e->getMessage(), ['invalid_user', 'invalid_patch'], true), 'invalid patch refused'); }
  }
} else {
  // Without pdo_sqlite (some native installs) the statement is checked in the source.
  $settingsSource = (string)file_get_contents($root . '/api/ai_settings.php');
  think_test(str_contains($settingsSource, "\$allowed = ['thinking_enabled', 'model_override'];")
    && str_contains($settingsSource, "'ON DUPLICATE KEY UPDATE ' . \$updates . ', updated_at = NOW()'"),
    'only the patched columns change');
}

// Endpoint: the switch path answers before the full KI-Profil save, and a
// KI-Profil save without the field keeps the stored value.
$endpoint = (string)file_get_contents($root . '/api/user_settings.php');
$switchAt = strpos($endpoint, "if ((\$body['action'] ?? '') === 'thinking')");
$keepAt = strpos($endpoint, "if (!array_key_exists('thinking_enabled', \$body))");
$saveAt = strpos($endpoint, 'coreui_ai_settings_save($pdo, $uid, $body)');
think_test($switchAt !== false && $keepAt !== false && $saveAt !== false && $switchAt < $keepAt && $keepAt < $saveAt
  && str_contains($endpoint, 'stu_require_csrf();'), 'switch path, stored value kept, CSRF required');
think_test(str_contains($endpoint, "'error'=>'invalid_thinking'"), 'a value that is not a boolean is refused');

// Core choice from the chat menu: only installed Cores, answer first, then a
// local Core is loaded through Mara's queue with the options of a chat turn.
$coreAt = strpos($endpoint, "if ((\$body['action'] ?? '') === 'core')");
$coreBlock = $coreAt === false ? '' : substr($endpoint, $coreAt, (int)strpos($endpoint, "\n}\n", $coreAt) - $coreAt);
$existsAt = strpos($coreBlock, 'coreui_ollama_model_exists($name)');
$patchAt = strpos($coreBlock, "coreui_ai_settings_patch(\$pdo, \$uid, ['model_override'=>\$name])");
$echoAt = strpos($coreBlock, 'echo json_encode(');
$finishAt = strpos($coreBlock, 'fastcgi_finish_request()');
$preloadAt = strpos($coreBlock, 'coreui_core_preload($pdo, $uid, $model)');
think_test($coreAt !== false && $coreAt < $saveAt && $existsAt !== false && $patchAt !== false && $echoAt !== false
  && $finishAt !== false && $preloadAt !== false && $existsAt < $patchAt && $patchAt < $echoAt && $echoAt < $finishAt
  && $finishAt < $preloadAt, 'Core choice: checked, saved, answered, then loaded in the background');
// 0.5.77: an already loaded choice still clears the other local Cores; a cloud choice never touches the RAM.
think_test(str_contains($coreBlock, "if (\$preload !== 'started' && !\$unloadOthers) exit;")
  && str_contains($coreBlock, "\$unloadOthers = !\$cloud && count(\$loadedNow) > (\$preload === 'loaded' ? 1 : 0);")
  && str_contains($coreBlock, 'session_write_close()'),
  'cloud Cores are not loaded, loaded ones not again, others leave the RAM; the session lock is released first');
think_test(str_contains($endpoint, "array_key_exists('core_status', \$_GET)") && str_contains($endpoint, "'loaded'=>coreui_ollama_loaded_names()"),
  'loading state comes from Ollama /api/ps');
require_once $root . '/api/ai_context_estimate.php';
think_test(coreui_ollama_model_key('Ember-CoreUI') === 'ember-coreui:latest' && coreui_ollama_model_key('gemma4:e4b') === 'gemma4:e4b',
  'model names compare like /api/ps reports them');
$preload = (string)file_get_contents($root . '/api/core_preload.php');
think_test(str_contains($preload, 'ember_global_lock_acquire(120)') && str_contains($preload, 'ember_generation_lock_release($lock)')
  && str_contains($preload, 'ember_ollama_known_good_options($model)') && str_contains($preload, "'prompt'=>''")
  && str_contains($preload, 'ember_model_is_cloud($model)') && str_contains($preload, "coreui_ollama_api_url('generate')"),
  'preload waits in the queue, sends the chat options and an empty prompt, skips cloud Cores');
think_test(!preg_match('~/api/(?:create|delete|pull|copy|push)\b~', $preload), 'preload never changes models');

// The global channel keeps thinking; the private chat follows the switch.
$chat = (string)file_get_contents($root . '/api/chat.php');
think_test(substr_count($chat, "if (\$channel !== 'console') \$GLOBALS['COREUI_AI_RUNTIME_SETTINGS']['thinking_enabled'] = true;") === 2,
  'both prompt builders keep thinking for the global channel');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['thinking_enabled'=>false];
think_test(ember_thinking_enabled() === false, 'the stored switch reaches the request');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['thinking_enabled'=>true];
think_test(ember_thinking_enabled() === true, 'switch on');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
think_test(str_contains($stream, "'think'    => \$thinkOverride ?? ember_thinking_enabled(),"), 'chat stream sends the switch as think');

echo "CoreAI thinking switch and Core choice: Modelfile, save, preload, global channel and analysis filter OK\n";
