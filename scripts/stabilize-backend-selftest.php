<?php
declare(strict_types=1);
// CoreAI 0.8.34: stabilisation of the chat and stream backend.
//   php scripts/stabilize-backend-selftest.php
// Offline (no Ollama, no MariaDB, no internet): a small Ollama/OpenAI stand-in server on 127.0.0.1
// and an in-memory SQLite database with the MariaDB statements translated.
//  B1  a stream that pauses ends by stall detection (not after a fixed 600 s), keeps the text that
//      arrived, a provider refusal (HTTP 429, also with a multi-line JSON body) is not sent again;
//  B2  an action whose worker is gone is closed (reconcile, expire, early exit, worker exit), an
//      error never replaces a saved answer, a new variant is possible again;
//  B3  a Core call waits while a live worker holds the model (see also model-queue-selftest.php);
//  B4  memory and reflection run after the answer is sent;
//  B5  edit and new variant wait for a running agent run; no reply to a hidden turn;
//  B6  a crashing command line script exits with an error code;
//  B7  bookkeeping after a saved reply cannot turn it into an error;
//  L1  an unopenable lock file is not "busy" for ever; old lock files are removed;
//  L2  SSE events with invalid UTF-8 stay valid JSON;
//  P4  one turn instant for both prompts; the stream's first tool reply starts the work loop
//      without a second identical first call;
//  P7  a Core used by a running agent run is not unloaded by another call;
//  measurement: load, prompt and writing time of each Ollama call.
// Test data uses example.test only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$GLOBALS['stab_log'] = [];
function stu__log_error(array $entry): void { $GLOBALS['stab_log'][] = $entry; }
function stab_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Backend stabilisation FAIL: {$why}\n"); exit(1); }
}
const STAB_KEY = 'sk-test-stabilise-0123456789SECRET';

// --- The stand-in server (Ollama /api/* and an OpenAI-compatible /v1/*) ---------------------------------------------
$tmp = sys_get_temp_dir() . '/coreai-stab-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
// Diagnostics of the library (missing tables of the SQLite stand-in) stay out of the test output.
ini_set('error_log', $tmp . '/php-error.log');
$stub = <<<'STUB'
<?php
$raw = (string)file_get_contents('php://input');
$uri = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode($raw, true) ?: [];
$last = '';
foreach ((array)($body['messages'] ?? []) as $m) $last = is_string($m['content'] ?? null) ? $m['content'] : '';
file_put_contents(getenv('STUB_LOG'), json_encode(['uri'=>$uri, 'stream'=>!empty($body['stream']),
  'mode'=>preg_match('~MODE:([a-z0-9]+)~', $last, $mm) ? $mm[1] : '']) . "\n", FILE_APPEND);
$flush = static function (): void { @ob_flush(); flush(); };
if ($uri === '/api/ps') {
  header('Content-Type: application/json');
  $ps = is_file(__DIR__ . '/ps.json') ? (array)json_decode((string)file_get_contents(__DIR__ . '/ps.json'), true) : [];
  echo json_encode(['models'=>array_map(static fn($n) => ['name'=>$n, 'model'=>$n], $ps)]);
  exit;
}
if ($uri === '/search') {
  header('Content-Type: application/json');
  echo json_encode(['results'=>[['title'=>'Wetter Berlin', 'url'=>'https://wetter.example.test/berlin', 'content'=>'Sonnig, 20 Grad']]]);
  exit;
}
if ($uri === '/api/generate') { header('Content-Type: application/json'); echo json_encode(['done'=>true]); exit; }
$timing = ['load_duration'=>1500000000, 'prompt_eval_duration'=>61000000000, 'eval_duration'=>2500000000,
  'total_duration'=>65000000000];
if ($uri === '/api/chat') {
  if (str_contains($last, 'MODE:http500pretty')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo "{\n  \"error\": \"model requires more system memory\"\n}\n";
    exit;
  }
  if (empty($body['stream'])) {
    header('Content-Type: application/json');
    // P4: the first call of a turn asks for a web search, every later one answers.
    $content = 'Antwort vom Stub';
    if (str_contains($last, 'MODE:p4') && is_file(__DIR__ . '/p4_first')) {
      unlink(__DIR__ . '/p4_first');
      $content = "Ich schaue kurz nach.\n[WEB: Wetter Berlin]";
    }
    echo json_encode(['message'=>['role'=>'assistant', 'content'=>$content], 'done'=>true, 'done_reason'=>'stop',
      'eval_count'=>12, 'prompt_eval_count'=>3400] + $timing);
    exit;
  }
  header('Content-Type: application/x-ndjson');
  if (str_contains($last, 'MODE:silent')) { sleep(6); echo json_encode(['message'=>['content'=>'zu spaet'], 'done'=>true]) . "\n"; exit; }
  foreach (['Ein langer ', 'Anfang'] as $piece) {
    echo json_encode(['message'=>['role'=>'assistant', 'content'=>$piece], 'done'=>false]) . "\n";
    $flush();
  }
  if (str_contains($last, 'MODE:stall')) { sleep(6); }
  echo json_encode(['message'=>['role'=>'assistant', 'content'=>' und Ende.'], 'done'=>false]) . "\n";
  echo json_encode(['message'=>['role'=>'assistant', 'content'=>''], 'done'=>true, 'done_reason'=>'stop',
    'eval_count'=>3, 'prompt_eval_count'=>3400] + $timing) . "\n";
  exit;
}
if ($uri === '/v1/chat/completions') {
  if (str_contains($last, 'MODE:ratelimit')) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo "{\n  \"error\": {\n    \"message\": \"Rate limit reached for requests\",\n    \"type\": \"requests\",\n"
      . "    \"code\": \"rate_limit_exceeded\"\n  }\n}\n";
    exit;
  }
  header('Content-Type: text/event-stream');
  echo 'data: ' . json_encode(['choices'=>[['index'=>0, 'delta'=>['content'=>'Hallo extern']]]]) . "\n\n";
  echo 'data: ' . json_encode(['choices'=>[['index'=>0, 'delta'=>[], 'finish_reason'=>'stop']]]) . "\n\n";
  echo "data: [DONE]\n\n";
  exit;
}
http_response_code(404);
STUB;
file_put_contents($tmp . '/stub.php', $stub);
$port = 0;
for ($i = 0; $i < 20 && $port === 0; $i++) {
  $try = random_int(21000, 39000);
  $probe = @fsockopen('127.0.0.1', $try, $errno, $errstr, 0.2);
  if ($probe === false) $port = $try; else fclose($probe);
}
stab_test($port > 0, 'a free port for the stand-in server');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/stub.php'], [0=>['file', '/dev/null', 'r'],
  1=>['file', $tmp . '/server.out', 'w'], 2=>['file', $tmp . '/server.out', 'a']], $pipes, $tmp,
  ['STUB_LOG'=>$tmp . '/requests.log', 'PHP_CLI_SERVER_WORKERS'=>'4', 'PATH'=>(string)getenv('PATH')]);
register_shutdown_function(static function () use ($server, $tmp): void {
  if (is_resource($server)) { proc_terminate($server); proc_close($server); }
  exec('rm -rf ' . escapeshellarg($tmp));
});
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
  $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
  if ($probe !== false) { fclose($probe); $up = true; } else usleep(100000);
}
stab_test($up, 'the stand-in server starts');
$requests = static function () use ($tmp): array {
  $lines = is_file($tmp . '/requests.log') ? file($tmp . '/requests.log', FILE_IGNORE_NEW_LINES) : [];
  @unlink($tmp . '/requests.log');
  return array_map(static fn(string $l): array => (array)json_decode($l, true), $lines ?: []);
};

// --- The library, pointed at the stand-in and at private temporary directories -----------------------------------
putenv('COREUI_OLLAMA_URL=http://127.0.0.1:' . $port . '/api/chat');
putenv('COREUI_EXTERNAL_API_KEY=' . STAB_KEY);
putenv('COREUI_DB_PORT=1'); // never a real database
define('STU_EMBER_OLLAMA_URL', 'http://127.0.0.1:' . $port . '/api/chat');
define('STU_EXTERNAL_API_KEY', STAB_KEY);
define('STU_EMBER_SINGLE_RESIDENT', true);
define('STU_EMBER_CACHE_DIR', $tmp . '/cache');
define('STU_EMBER_CORE_IN_USE_DIR', $tmp . '/cores-in-use');
define('STU_EMBER_WEB_URL', 'http://127.0.0.1:' . $port . '/search');
define('STU_EMBER_PY_ENABLED', false);
define('STU_EMBER_STREAM_FIRST_BYTE_SECONDS', 3);
define('STU_EMBER_STREAM_STALL_SECONDS', 1);
define('STU_CHAT_LIB', 1);
set_error_handler(static fn(int $no, string $msg): bool => $no === E_WARNING && str_contains($msg, 'already defined'));
require $root . '/api/chat.php';
restore_error_handler();
require_once $root . '/api/console_action_store.php';
require_once $root . '/api/console_agent_runs.php';
stab_test(ember_url() === 'http://127.0.0.1:' . $port . '/api/chat', 'local Ollama is the stand-in');
date_default_timezone_set('UTC');
$streamSource = (string)file_get_contents($root . '/api/console_stream.php');
foreach (['sse_send', 'console_stream_ollama'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $streamSource, $m) !== 1) stab_test(false, 'missing ' . $fn);
  eval($m[0]);
}
$GLOBALS['COREUI_MODEL_LOCK_DEPTH'] = 5; // inside an already held model slot (re-entrant), no database needed
$chatSource = (string)file_get_contents($root . '/api/chat.php');
$actionSource = (string)file_get_contents($root . '/api/console_action_store.php');

// === B6: a crashing command line script fails ========================================================================
// (A script file: "php -r" never calls an exception handler.)
file_put_contents($tmp . '/crash.php', "<?php\nrequire " . var_export($root . '/api/helpers.php', true)
  . ";\nthrow new RuntimeException('selftest crash');\n");
$child = proc_open([PHP_BINARY, $tmp . '/crash.php'], [1=>['pipe', 'w'], 2=>['pipe', 'w']], $childPipes);
$childOut = stream_get_contents($childPipes[1]);
$childErr = stream_get_contents($childPipes[2]);
fclose($childPipes[1]); fclose($childPipes[2]);
$childExit = proc_close($child);
stab_test($childExit === 1 && str_contains((string)$childErr, 'Uncaught RuntimeException') && str_contains((string)$childErr, 'selftest crash'),
  'an uncaught exception in a CLI script exits with 1 and names the error: exit ' . $childExit . ' ' . json_encode([$childOut, $childErr]));
$actionsTest = (string)file_get_contents($root . '/scripts/message-actions-selftest.php');
$contextTest = (string)file_get_contents($root . '/scripts/ai-context-selftest.php');
stab_test(!str_contains($actionsTest, '"\'event_id\'=>$eventId"') && !str_contains($contextTest, '"\'keep_alive\' => $keepAlive"'),
  'the two selftests no longer interpolate an undefined variable');

// === L2: SSE events stay valid JSON ==================================================================================
ob_start();
sse_send('done', ['text'=>"Teil\xC3", 'id'=>7]);
$event = (string)ob_get_clean();
$payload = preg_match('~^data: (.*)$~m', $event, $dm) === 1 ? json_decode($dm[1], true) : null;
stab_test(is_array($payload) && $payload['id'] === 7 && str_starts_with((string)$payload['text'], 'Teil')
  && preg_match('//u', (string)$payload['text']) === 1, 'invalid UTF-8 becomes a replacement character, not an empty event: ' . json_encode($event));

// === B1: stream limits, stall detection, failure classes ============================================================
$limits = ember_stream_limits(ember_timeout_for_model('gemma4:e4b'));
stab_test($limits === ['first_byte'=>3, 'stall'=>1, 'total'=>max(ember_timeout_for_model('gemma4:e4b'), 3600)],
  'configured stream limits: ' . json_encode($limits));
stab_test(ember_stream_limits(600, 90) === ['first_byte'=>90, 'stall'=>90, 'total'=>90], 'a short internal call keeps its fixed limit');
stab_test(ember_stream_stall_reason(100.0, 0.0, 99.5, ['first_byte'=>600, 'stall'=>180]) === null
  && ember_stream_stall_reason(1000.0, 0.0, 999.0, ['first_byte'=>600, 'stall'=>180]) === null,
  'a stream that keeps sending is never cut, however long it runs');
stab_test(ember_stream_stall_reason(400.0, 0.0, null, ['first_byte'=>600, 'stall'=>180]) === null
  && ember_stream_stall_reason(600.0, 0.0, null, ['first_byte'=>600, 'stall'=>180]) === 'first_byte_timeout'
  && ember_stream_stall_reason(500.0, 0.0, 300.0, ['first_byte'=>600, 'stall'=>180]) === 'stall_timeout',
  'first byte and pauses are limited');
stab_test(ember_stream_failure(true, 200, 0, '', '', false) === ''
  && ember_stream_failure(false, 429, 0, '', '', false) === 'provider'
  && ember_stream_failure(false, 200, 0, 'overloaded', '', false) === 'provider'
  && ember_stream_failure(false, 200, 28, '', '', false) === 'timeout'
  && ember_stream_failure(false, 200, 42, '', 'stall_timeout', false) === 'timeout'
  && ember_stream_failure(false, 200, 42, '', '', true) === 'cancelled'
  && ember_stream_failure(false, 0, 7, '', '', false) === 'transport'
  && ember_stream_failure(false, 200, 0, '', '', false) === 'incomplete', 'failure classes');
$plan = static fn(array $acc, string $visible): string => ember_stream_failure_plan($acc, $visible);
stab_test($plan(['ok'=>true, 'done'=>true], 'x') === 'complete'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'timeout'], 'Ein langer Anfang') === 'keep_partial'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'provider'], 'Teil') === 'keep_partial'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'provider'], '') === 'provider_error'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'timeout'], '') === 'timeout_error'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'transport'], '') === 'sync_fallback'
  && $plan(['ok'=>false, 'done'=>false, 'failure'=>'cancelled'], 'x') === 'cancelled'
  && $plan(['thinking'=>'', 'content'=>''], '') === 'sync_fallback',
  'after a broken stream: keep the text, never resend after a refusal or a stopped Core, retry only a lost connection');
foreach ([ember_stream_failure_text('provider_error', true, 429), ember_stream_failure_text('provider_error', false, 500),
    ember_stream_failure_text('timeout_error', false, 0)] as $text) {
  stab_test($text !== '' && !preg_match('~[\x{2013}\x{2014}]|Modell~u', $text) && str_contains($text, 'nicht'),
    'short German explanation without long dashes or "Modell": ' . $text);
}
stab_test(str_contains(ember_stream_failure_text('provider_error', true, 429), 'HTTP 429'), 'the refusal names its HTTP status');

// The real stream function against the stand-in: a pause after the first words.
$requests();
$GLOBALS['stab_log'] = [];
$started = microtime(true);
$s = console_stream_ollama('gemma4:e4b', '', 'Erzaehl lange. MODE:stall', false, null, null, false);
$took = microtime(true) - $started;
stab_test($took < 4.5 && !$s['ok'] && ($s['failure'] ?? '') === 'timeout' && $s['content'] === 'Ein langer Anfang'
  && ember_stream_failure_plan($s, $s['content']) === 'keep_partial',
  'a stream that pauses ends after the stall limit and keeps its text: ' . round($took, 1) . ' s ' . json_encode($s));
$s = console_stream_ollama('gemma4:e4b', '', 'Erzaehl. MODE:silent', false, null, null, false);
stab_test(!$s['ok'] && ($s['failure'] ?? '') === 'timeout' && $s['content'] === ''
  && ember_stream_failure_plan($s, '') === 'timeout_error', 'no first byte in time: a clear error, no second request');
$s = console_stream_ollama('gemma4:e4b', '', 'Erzaehl. MODE:http500pretty', false, null, null, false);
stab_test(!$s['ok'] && ($s['failure'] ?? '') === 'provider' && (int)$s['http_code'] === 500
  && ember_stream_failure_plan($s, '') === 'provider_error', 'a Core error (HTTP 500) is not asked again: ' . json_encode($s));
$s = console_stream_ollama('gemma4:e4b', '', 'Erzaehl.', false, null, null, false);
stab_test($s['ok'] && $s['content'] === 'Ein langer Anfang und Ende.' && ($s['failure'] ?? null) === ''
  && ($s['prompt_eval_ms'] ?? 0) === 61000 && ($s['load_ms'] ?? 0) === 1500 && ($s['eval_ms'] ?? 0) === 2500,
  'a complete stream with Ollama\'s timing: ' . json_encode($s));
$streams = array_values(array_filter($requests(), static fn(array $r): bool => $r['uri'] === '/api/chat'));
stab_test(count($streams) === 4, 'exactly one request per stream, nothing sent twice: ' . json_encode($streams));

// The external API: HTTP 429 with a pretty-printed JSON body.
$translator = coreui_provider_stream_translator(STAB_KEY);
$out = [];
foreach (['{', '  "error": {', '    "message": "Rate limit reached for requests",', '    "code": "rate_limit_exceeded"', '  }', '}'] as $line) {
  $out = array_merge($out, ($translator['feed'])($line));
}
$out = array_merge($out, ($translator['finish'])());
stab_test(count($out) === 1 && (json_decode($out[0], true)['error'] ?? '') === 'Rate limit reached for requests',
  'a multi-line JSON error body keeps its message: ' . json_encode($out));
$translator = coreui_provider_stream_translator(STAB_KEY);
$out = array_merge(($translator['feed'])('{'), ($translator['feed'])('  "error": "cut'), ($translator['finish'])());
stab_test(count($out) === 1 && isset(json_decode($out[0], true)['error']), 'an unfinished JSON body is still an error');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'Test',
  'base_url'=>'http://127.0.0.1:' . $port . '/v1', 'model'=>'stub-model']];
stab_test(coreui_provider_active() !== null, 'the external API is active for this account');
$GLOBALS['stab_log'] = [];
$s = console_stream_ollama('gemma4:e4b', '', 'Hallo. MODE:ratelimit', false, null, null, false);
$logged = array_values(array_filter($GLOBALS['stab_log'], static fn(array $e): bool => ($e['type'] ?? '') === 'console_stream_incomplete'));
stab_test(!$s['ok'] && ($s['failure'] ?? '') === 'provider' && (int)$s['http_code'] === 429 && !empty($s['external'])
  && ember_stream_failure_plan($s, '') === 'provider_error'
  && ($logged[0]['api_error'] ?? '') === 'Rate limit reached for requests',
  'HTTP 429 of the external API: recorded with its reason, not sent again: ' . json_encode([$s, $logged]));
$sent = array_values(array_filter($requests(), static fn(array $r): bool => $r['uri'] === '/v1/chat/completions'));
stab_test(count($sent) === 1, 'one paid request only');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];

// The main flow uses the plan: the reply path runs again only after a lost connection.
$planAt = strpos($streamSource, '$streamPlan = ($fileTaskHandled || $nativeToolHandled)');
$fallbackAt = strpos($streamSource, '$fallback = ember_generate_reply(');
stab_test($planAt !== false && $fallbackAt !== false && $planAt < $fallbackAt
  && str_contains($streamSource, "\$streamUnsafe = \$streamPlan === 'sync_fallback';")
  && !str_contains($streamSource, "\$streamUnsafe = empty(\$acc['ok'])")
  && str_contains($streamSource, "\$reply = ember_stream_failure_text(\$streamPlan, !empty(\$acc['external']), (int)(\$acc['http_code'] ?? 0));")
  && str_contains($streamSource, "CURLOPT_TIMEOUT        => \$timeoutOverride === null\n      ? \$limits['total']")
  && str_contains($streamSource, "CURLOPT_LOW_SPEED_TIME  => \$limits['first_byte'],"),
  'console_stream.php decides by the plan and limits the stream by pauses');

// === The SQLite stand-in for MariaDB (statements translated, real SQL semantics) ===================================
final class StabSqlite extends PDO {
  /** Statements matching one of these patterns fail like a database error. */
  public array $failOn = [];
  public function __construct() {
    parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $this->sqliteCreateFunction('NOW', static fn(...$a): string => gmdate('Y-m-d H:i:s'), -1);
    $this->sqliteCreateFunction('GREATEST', static fn(...$a) => max($a), -1);
  }
  public static function translate(string $sql): string {
    $sql = (string)preg_replace('~DATE_SUB\(NOW\(3?\),\s*INTERVAL\s+(.+?)\s+SECOND\)~s', "datetime('now', '-' || ($1) || ' seconds')", $sql);
    $sql = (string)preg_replace('~DATE_(ADD|SUB)\(NOW\(3?\),\s*INTERVAL\s+(\d+)\s+MINUTE\)~', "datetime('now', '__$1__$2 minutes')", $sql);
    $sql = str_replace(['__ADD__', '__SUB__'], ['+', '-'], $sql);
    $sql = str_replace('NOW(3)', 'NOW()', $sql);
    $sql = (string)preg_replace('~\s+FOR UPDATE\b~i', '', $sql);
    $sql = (string)preg_replace('~^(\s*UPDATE\b.*?)\s+LIMIT\s+\d+\s*$~is', '$1', $sql);
    if (str_contains($sql, 'ON DUPLICATE KEY UPDATE run_id=run_id')) {
      $sql = (string)preg_replace('~^\s*INSERT INTO~', 'INSERT OR IGNORE INTO',
        str_replace(' ON DUPLICATE KEY UPDATE run_id=run_id', '', $sql));
    }
    return $sql;
  }
  private function guard(string $sql): void {
    foreach ($this->failOn as $pattern) {
      if (preg_match($pattern, $sql) === 1) throw new PDOException('SQLSTATE[HY000]: simulated failure');
    }
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    $this->guard($query);
    return parent::prepare(self::translate($query), $options);
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    $this->guard($query);
    return $fetchMode === null ? parent::query(self::translate($query)) : parent::query(self::translate($query), $fetchMode, ...$fetchModeArgs);
  }
  public function exec(string $statement): int|false {
    $this->guard($statement);
    return parent::exec(self::translate($statement));
  }
}
function stab_db(): StabSqlite {
  $db = new StabSqlite();
  $db->exec("CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, title TEXT, since_id INTEGER, last_message_id INTEGER,
    last_read_message_id INTEGER, created_at TEXT, updated_at TEXT, archived_at TEXT)");
  $db->exec("CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT, alliance_id INTEGER, session_id TEXT,
    user_id INTEGER, character_id TEXT, character_name TEXT, message TEXT, thinking_content TEXT, file_uuid TEXT, image_url TEXT,
    reply_to_id INTEGER, created_at TEXT, deleted_at TEXT)");
  $db->exec("CREATE TABLE stu_console_generation_requests (id TEXT PRIMARY KEY, user_id INTEGER, session_id TEXT, trigger_message_id INTEGER,
    source_response_id INTEGER, response_floor_id INTEGER, mode TEXT, status TEXT, response_message_id INTEGER, browse_job_id INTEGER,
    error_code TEXT, created_at TEXT, started_at TEXT, finished_at TEXT, expires_at TEXT)");
  $db->exec("CREATE TABLE stu_console_agent_runs (run_id TEXT PRIMARY KEY, user_id INTEGER, session_id TEXT, turn_id INTEGER, run_key TEXT,
    response_message_id INTEGER, browse_job_id INTEGER, cancel_requested_at TEXT, status TEXT, error_code TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, started_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT,
    UNIQUE (user_id, session_id, turn_id, run_key))");
  $db->exec("CREATE TABLE stu_ember_browse_jobs (id INTEGER PRIMARY KEY, channel TEXT, recipient_uid INTEGER, session_id TEXT,
    trigger_message_id INTEGER, status TEXT, finished_at TEXT, created_at TEXT)");
  $db->exec("INSERT INTO stu_console_sessions (id, user_id, title, created_at, updated_at) VALUES ('session_demo', 7, 'Test', NOW(), NOW())");
  $ember = $db->quote(ember_character_id());
  $name = $db->quote(ember_character_name());
  // 23: the user's turn, 24: Mara's reply, 25: a later turn, 26: Mara's reply to it.
  $db->exec("INSERT INTO stu_chat_messages (id, channel, session_id, user_id, character_id, character_name, message, reply_to_id, created_at)
    VALUES (23, 'console', 'session_demo', 7, 'user-char', 'Nutzer', 'Erste Frage', NULL, datetime('now', '-10 minutes')),
           (24, 'console', 'session_demo', 7, $ember, $name, 'Erste Antwort', 23, datetime('now', '-9 minutes')),
           (25, 'console', 'session_demo', 7, 'user-char', 'Nutzer', 'Zweite Frage', NULL, datetime('now', '-8 minutes')),
           (26, 'console', 'session_demo', 7, $ember, $name, 'Zweite Antwort', 25, datetime('now', '-7 minutes'))");
  return $db;
}
$rid = str_repeat('a', 32);
$runRow = static function (StabSqlite $db, string $runKey, int $turn, string $status, string $updated = 'NOW()'): void {
  $db->prepare("INSERT INTO stu_console_agent_runs (run_id, user_id, session_id, turn_id, run_key, status, created_at, updated_at)
    VALUES (?, 7, 'session_demo', ?, ?, ?, NOW(), $updated)")->execute([bin2hex(random_bytes(16)), $turn, $runKey, $status]);
};
$requestRow = static function (StabSqlite $db, string $id, string $status, int $responseId = 0): void {
  $db->prepare("INSERT INTO stu_console_generation_requests (id, user_id, session_id, trigger_message_id, source_response_id,
    response_floor_id, mode, status, response_message_id, created_at, started_at, expires_at)
    VALUES (?, 7, 'session_demo', 25, 26, 26, 'regenerate', ?, ?, NOW(), NOW(), datetime('now', '+40 minutes'))")
    ->execute([$id, $status, $responseId > 0 ? $responseId : null]);
};
$requestStatus = static fn(StabSqlite $db, string $id): array
  => (array)$db->query("SELECT status, error_code, response_message_id FROM stu_console_generation_requests WHERE id='$id'")->fetch(PDO::FETCH_ASSOC);

// === B2: an action whose worker is gone is closed ===================================================================
// The audit's case: the worker of a running variant died (its run was reaped as interrupted).
$db = stab_db();
$requestRow($db, $rid, 'running');
$runRow($db, $rid, 25, 'interrupted');
$req = coreui_console_generation_reconcile($db, 7, $rid, coreui_console_generation_load($db, 7, $rid));
stab_test($req['status'] === 'error' && $req['error_code'] === 'agent_run_interrupted'
  && $requestStatus($db, $rid)['status'] === 'error', 'reconcile closes an action whose run was interrupted: ' . json_encode($req));
// The worker vanished without a sign of life for 10 minutes: expire reaps the run and closes the action.
$db = stab_db();
$requestRow($db, $rid, 'running');
$runRow($db, $rid, 25, 'running', "datetime('now', '-10 minutes')");
try {
  coreui_console_generation_prepare($db, 7, 'session_demo', 26, 'regenerate');
  $newRequest = true;
} catch (RuntimeException $e) {
  $newRequest = $e->getMessage();
}
stab_test($newRequest === true && $requestStatus($db, $rid)['status'] === 'error'
  && $db->query("SELECT status FROM stu_console_agent_runs WHERE run_key='$rid'")->fetchColumn() === 'interrupted',
  'after a worker loss a new variant is possible at once, not after 40 minutes: ' . json_encode([$newRequest, $requestStatus($db, $rid)]));
// A live worker (recent sign of life) keeps its action and the chat busy.
$db = stab_db();
$requestRow($db, $rid, 'running');
$runRow($db, $rid, 25, 'running');
coreui_console_generation_expire($db, 7);
try {
  coreui_console_generation_prepare($db, 7, 'session_demo', 26, 'regenerate');
  $busy = 'none';
} catch (RuntimeException $e) {
  $busy = $e->getMessage();
}
stab_test($requestStatus($db, $rid)['status'] === 'running' && $busy === 'generation_busy', 'a live worker keeps its action: ' . $busy);
// A browser job keeps the action open; reconcile waits for the job, not for the run.
$db = stab_db();
$requestRow($db, $rid, 'running');
$db->exec("UPDATE stu_console_generation_requests SET browse_job_id=55 WHERE id='$rid'");
$db->exec("INSERT INTO stu_ember_browse_jobs (id, channel, recipient_uid, session_id, trigger_message_id, status) VALUES (55, 'console', 7, 'session_demo', 25, 'running')");
$runRow($db, $rid, 25, 'waiting_tool');
coreui_console_generation_expire($db, 7);
stab_test($requestStatus($db, $rid)['status'] === 'running', 'a running browser job keeps its action open');
// An error never replaces a saved answer; a saved answer wins over an earlier error.
$db = stab_db();
$requestRow($db, $rid, 'done', 26);
stab_test(!coreui_console_generation_finish($db, 7, $rid, 0, 'generation_failed') && $requestStatus($db, $rid) === ['status'=>'done',
  'error_code'=>null, 'response_message_id'=>26], 'a late exception does not overwrite a finished action: ' . json_encode($requestStatus($db, $rid)));
$db->exec("UPDATE stu_console_generation_requests SET status='error', error_code='worker_exit', response_message_id=NULL WHERE id='$rid'");
stab_test(coreui_console_generation_finish($db, 7, $rid, 26) && $requestStatus($db, $rid)['status'] === 'done',
  'a saved answer still completes an action that was marked failed');
// The stream closes the action on its early exit and when its worker ends without an answer.
$earlyExit = substr($streamSource, (int)strpos($streamSource, "if (\$priorState !== 'queued' && !\$resumedFromCheckpoint) {"), 1400);
$shutdown = substr($streamSource, (int)strpos($streamSource, 'register_shutdown_function(static function () use ($pdo, $uid, $sessionId, $agentRunId'), 900);
stab_test(str_contains($earlyExit, "coreui_console_generation_finish(\$pdo, (int)\$uid, \$generationRequestId, 0,")
  && strpos($earlyExit, 'coreui_console_generation_finish') < strpos($earlyExit, "sse_send('error', ['error'=>'agent_run_' . \$priorState]);")
  && str_contains($shutdown, "if (\$lost && \$generationRequestId !== '') {")
  && str_contains($shutdown, "coreui_console_generation_finish(\$pdo, (int)\$uid, \$generationRequestId, 0, 'worker_exit');"),
  'the stream closes its action on the early exit and on worker exit');

// === B5: edit and new variant wait for a running agent run; no reply to a hidden turn ===============================
$db = stab_db();
$runRow($db, 'base', 25, 'running');
$refused = [];
foreach ([static fn() => coreui_console_assert_idle_for_edit($db, 7, 'session_demo'),
    static fn() => coreui_console_generation_prepare($db, 7, 'session_demo', 24, 'regenerate')] as $attempt) {
  try { $attempt(); $refused[] = 'none'; } catch (RuntimeException $e) { $refused[] = $e->getMessage(); }
}
stab_test($refused === ['generation_busy', 'generation_busy'], 'an edit and a variant wait for the running answer of a new message: ' . json_encode($refused));
$db->exec("UPDATE stu_console_agent_runs SET updated_at=datetime('now', '-10 minutes')");
coreui_console_assert_idle_for_edit($db, 7, 'session_demo');
stab_test($db->query("SELECT status FROM stu_console_agent_runs WHERE run_key='base'")->fetchColumn() === 'interrupted',
  'a run whose worker is gone blocks nothing');
// The edit of message 23 hid turn 25; the worker that answered 25 must not save an orphan.
$db = stab_db();
$db->exec("UPDATE stu_chat_messages SET deleted_at=NOW() WHERE id>23");
unset($GLOBALS['COREUI_INSERT_REFUSED']);
$saved = ember_insert($db, 'Spaete Antwort auf eine ausgeblendete Frage', 'console', null, 7, 'session_demo', 25);
stab_test($saved === 0 && ($GLOBALS['COREUI_INSERT_REFUSED'] ?? '') === 'turn_deleted'
  && (int)$db->query("SELECT COUNT(*) FROM stu_chat_messages WHERE reply_to_id=25 AND deleted_at IS NULL")->fetchColumn() === 0
  && !$db->inTransaction(), 'no reply to a turn that an edit hid');
stab_test(str_contains($streamSource, "if (\$emberMsgId <= 0 && (\$GLOBALS['COREUI_INSERT_REFUSED'] ?? '') === 'turn_deleted') {")
  && str_contains($streamSource, "AND session_id=? AND reply_to_id=? AND deleted_at IS NULL"),
  'the stream ends such a run as cancelled and never picks a hidden reply as its answer');

// === B7: bookkeeping after the save cannot turn a saved reply into an error ========================================
$db = stab_db();
$db->exec('CREATE TABLE stu_notification_prefs (user_id INTEGER, category TEXT, channels TEXT)');
$db->exec('CREATE TABLE stu_push_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, endpoint TEXT, p256dh TEXT, auth TEXT, failures INTEGER)');
$db->exec("INSERT INTO stu_push_subscriptions (id, user_id, endpoint, p256dh, auth, failures) VALUES (1, 7, 'https://push.example.test/x', 'k', 'a', 0)");
$GLOBALS['COREUI_NOTIFY_INLINE'] = true;
$GLOBALS['COREUI_NOTIFY_TEST_PUSH'] = static function (): array { throw new RuntimeException('push service down'); };
try {
  $savedId = ember_insert($db, 'Antwort nach langer Arbeit', 'console', null, 7, 'session_demo', 25);
} catch (Throwable $e) {
  $savedId = 'exception: ' . $e->getMessage();
}
unset($GLOBALS['COREUI_NOTIFY_INLINE'], $GLOBALS['COREUI_NOTIFY_TEST_PUSH']);
stab_test(is_int($savedId) && $savedId > 26
  && (string)$db->query("SELECT message FROM stu_chat_messages WHERE id=" . (int)$savedId)->fetchColumn() === 'Antwort nach langer Arbeit',
  'a failing notification after the save still returns the saved reply: ' . json_encode($savedId));

// === B3: a Core call waits while a live worker holds the model =====================================================
// (The queue itself is tested in scripts/model-queue-selftest.php.)
// Review 0.8.34: only the Core calls of a chat turn (streamed answer, work loop steps) wait for a busy holder, at
// most 900 s by default; background calls (memory, reflection, helpers) keep the 90 s, so a long generation cannot
// make every PHP worker wait.
if (preg_match('~^function ember_queue_holder_wait\(.*?^}~ms', $chatSource, $holderFn) === 1 && !function_exists('ember_queue_holder_wait')) eval($holderFn[0]);
stab_test(function_exists('ember_queue_holder_wait') && ember_queue_holder_wait(null, true, 900) === 900
  && ember_queue_holder_wait(null, false, 900) === 0 && ember_queue_holder_wait(120, true, 900) === 0
  && ember_queue_holder_wait(null, true, 99999) === 3600 && ember_queue_holder_wait(null, true, -5) === 0,
  'holder wait: only for chat-turn calls without an explicit wait, capped at one hour');
stab_test(str_contains($chatSource, 'function ember_global_lock_acquire(?int $timeoutSeconds = null, bool $holdWhileBusy = false): mixed {')
  && str_contains($chatSource, "(int)ember_cfg('STU_EMBER_QUEUE_HOLDER_WAIT_SECONDS', 900));")
  && str_contains($chatSource, "holderWaitSeconds: \$holderWait,\n      longJobLock: ember_lock_name('global_ollama_image')\n    );")
  && substr_count($chatSource, "\$GLOBALS['COREUI_QUEUE_HOLD_WHILE_BUSY'] = true;") === 1
  && substr_count($chatSource, "unset(\$GLOBALS['COREUI_QUEUE_HOLD_WHILE_BUSY']);") === 1
  && substr_count($streamSource, '$modelSlot = ember_global_lock_acquire(null, true);') === 1
  && substr_count($chatSource, '$modelSlot = ember_global_lock_acquire();') === 1,
  'the streamed answer and the work loop steps keep their place while the model is busy; other calls wait 90 s');

// === B4: memory and reflection after the answer is out =============================================================
$doneAt = strpos($streamSource, "  sse_send('done', [\n    'agent_task'   => \$taskSnapshot,");
$afterAt = strpos($streamSource, 'ember_after_insert_tasks($pdo, $char, $message, $replyFinal);');
$insertAt = strpos($streamSource, '$emberMsgId = ember_insert(');
stab_test($doneAt !== false && $afterAt !== false && $afterAt > $doneAt && $insertAt < $doneAt
  && substr_count($streamSource, 'ember_after_insert_tasks(') === 1
  && $afterAt < strpos($streamSource, 'coreui_compact_with_core($pdo, (int)$uid, $sessionId, false);'),
  'the saved answer is sent before memory, reflection and relationship run');

// === L1: per-turn lock files =========================================================================================
$lockDir = ember_generation_lock_dir();
stab_test(str_starts_with($lockDir, $tmp), 'locks in the test directory');
$char = ['user_id'=>7, 'id'=>'user-char'];
$first = ember_generation_lock_acquire('console', 23, $char, 'Hallo');
stab_test(is_resource($first) && ember_generation_lock_acquire('console', 23, $char, 'Hallo') === false,
  'a held turn lock is busy for a second request');
ember_generation_lock_release($first);
$blockedPath = $lockDir . '/' . ember_generation_lock_key('console', 24, $char, 'Hallo') . '.lock';
mkdir($blockedPath); // a path that cannot be opened as a file
$GLOBALS['stab_log'] = [];
$unopened = ember_generation_lock_acquire('console', 24, $char, 'Hallo');
stab_test($unopened !== false && !is_resource($unopened)
  && in_array('ember_generation_lock_unavailable', array_column($GLOBALS['stab_log'], 'type'), true),
  'a lock file that cannot be opened is logged, the turn is not "busy" for ever');
ember_generation_lock_release($unopened);
rmdir($blockedPath);
$old = $lockDir . '/' . str_repeat('b', 40) . '.lock';
$fresh = $lockDir . '/' . str_repeat('c', 40) . '.lock';
$heldOld = $lockDir . '/' . str_repeat('d', 40) . '.lock';
$other = $lockDir . '/cooldown_u7.txt';
foreach ([$old, $fresh, $heldOld, $other] as $file) file_put_contents($file, '1');
foreach ([$old, $heldOld, $other] as $file) touch($file, time() - 3 * 86400);
$holder = fopen($heldOld, 'c+');
flock($holder, LOCK_EX);
$removed = ember_generation_lock_cleanup($lockDir, 86400, true);
flock($holder, LOCK_UN);
fclose($holder);
stab_test($removed === 1 && !is_file($old) && is_file($fresh) && is_file($heldOld) && is_file($other),
  'old lock files nobody holds are removed, fresh, held and other files stay');
stab_test(ember_generation_lock_cleanup($lockDir) === 0, 'the cleanup runs at most once an hour');

// === P4: one instant per turn; the stream's first tool reply starts the work loop ==================================
unset($GLOBALS['COREUI_TURN_CLOCK']);
$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;
$firstBuild = ember_formatted_chat_time('console');
$GLOBALS['COREUI_TURN_CLOCK'] = time() - 125; // the turn began two minutes ago, the minute changed meanwhile
$expected = (new DateTime('@' . (time() - 125)))->setTimezone(new DateTimeZone(ember_tz()));
$days = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
stab_test(ember_formatted_chat_time('console') === $days[(int)$expected->format('w')] . ', ' . $expected->format('d.m.Y') . ', '
    . $expected->format('H:i') . ' Uhr, ' . ember_tz() && ember_formatted_chat_time('console') === ember_formatted_chat_time('console'),
  'both prompts of a turn carry the turn\'s instant, not the minute of their build');
$GLOBALS['COREUI_TURN_CLOCK'] = time() - 7200;
ember_formatted_chat_time('console');
stab_test($firstBuild !== '' && $GLOBALS['COREUI_TURN_CLOCK'] >= time() - 2, 'a request older than an hour takes a fresh instant');
unset($GLOBALS['COREUI_TURN_CLOCK']);
$web = ember_agent_loop_first_tool("Ich schaue kurz nach.\n[WEB: Wetter Berlin heute]", ['web', 'python', 'image']);
stab_test(($web['tool'] ?? '') === 'web' && str_contains((string)($web['input'] ?? ''), 'Wetter Berlin'),
  'a complete first reply with [WEB:] gives the loop its first tool: ' . json_encode($web));
stab_test(ember_agent_loop_first_tool('[WEB: x]', ['python']) === null
  && ember_agent_loop_first_tool("[PY]\nprint(1)\n[/PY]\n[WEB: x]", ['web', 'python']) === null
  && ember_agent_loop_first_tool('[BROWSE: example.test]', ['web', 'browse']) === null
  && ember_agent_loop_first_tool('Einfach eine Antwort.', ['web']) === null,
  'Python, browser, tools that are off and plain answers keep their own routes');
$generate = substr($chatSource, (int)strpos($chatSource, 'function ember_generate_reply('));
stab_test(str_contains($generate, "?array \$projectDecision = null,\n  ?string \$firstReply = null\n): ?string {")
  && str_contains($generate, "if (\$buildEligible && !\$fileTask && trim(\$attachBlock) === '' && \$projectDecision === null) {")
  && str_contains($generate, "if (\$projectDecision !== null && is_string(\$firstReply) && trim(\$firstReply) !== '') \$out = \$firstReply;")
  && strpos($generate, "if (\$projectDecision !== null) {\n    // The stream already checked this complete decision.")
    < strpos($generate, "\$out = ember_call_ollama(\$primaryModel, \$sys, \$u, ember_timeout_for_model(\$primaryModel),")
  && str_contains($streamSource, "\$firstTool = ember_agent_loop_first_tool(\$reply, ember_agent_allowed_tools((int)\$uid, \$sessionId,")
  && str_contains($streamSource, "        \$projectDecision,\n        \$firstReply\n      );"),
  'the reply path starts the loop from the stream\'s decision and skips its own first call and plan check');

// The real reply path with a counting stand-in Core: the turn asks for a web search first.
$turnDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$turnDb->sqliteCreateFunction('NOW', static fn(...$a): string => gmdate('Y-m-d H:i:s'), -1);
$turnDb->exec("CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, title TEXT, since_id INTEGER, last_message_id INTEGER,
  last_read_message_id INTEGER, created_at TEXT, updated_at TEXT, archived_at TEXT)");
$turnDb->exec("CREATE TABLE stu_chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT, alliance_id INTEGER, session_id TEXT,
  user_id INTEGER, character_id TEXT, character_name TEXT, message TEXT, thinking_content TEXT, file_uuid TEXT, image_url TEXT,
  reply_to_id INTEGER, created_at TEXT, deleted_at TEXT)");
$turnDb->exec("INSERT INTO stu_console_sessions (id, user_id, title) VALUES ('session_demo', 7, 'Test')");
$turnDb->exec("INSERT INTO stu_chat_messages (id, channel, session_id, user_id, character_id, character_name, message, created_at)
  VALUES (23, 'console', 'session_demo', 7, 'user-char', 'Nutzer', 'Wie ist das Wetter in Berlin? MODE:p4', NOW())");
$p4Calls = static fn(): int => count(array_filter($requests(), static fn(array $r): bool => $r['uri'] === '/api/chat'));
$question = 'Wie ist das Wetter in Berlin? MODE:p4';
$p4Char = ['id'=>'user-char', 'name'=>'Nutzer', 'user_id'=>7];
// Before: the stream's first reply carried [WEB:], and the reply path asked the Core the same first question again.
$requests();
touch($tmp . '/p4_first');
$oldRoute = ember_generate_reply($turnDb, $p4Char, $question, null, 'console', 7, 'session_demo', null, 23, 0);
$oldCalls = $p4Calls();
// Now: the stream hands over its checked first reply; the loop starts with the web search at once.
@unlink($tmp . '/p4_first');
$streamed = "Ich schaue kurz nach.\n[WEB: Wetter Berlin]";
$decision = ember_agent_loop_first_tool($streamed, ember_agent_allowed_tools(7, 'session_demo'));
$newRoute = ember_generate_reply($turnDb, $p4Char, $question, null, 'console', 7, 'session_demo', null, 23, 0, $decision, $streamed);
$newCalls = $p4Calls();
stab_test($oldRoute === 'Antwort vom Stub' && $oldCalls === 2 && ($decision['tool'] ?? '') === 'web'
  && $newRoute === 'Antwort vom Stub' && $newCalls === 1,
  'with the stream\'s first reply the turn needs one Core call (the loop step) instead of two: '
    . json_encode(['old'=>[$oldRoute, $oldCalls], 'new'=>[$newRoute, $newCalls], 'decision'=>$decision]));

// === P7: a Core in use by a running agent run stays loaded =========================================================
$GLOBALS['COREUI_WORK_CONTEXT'] = ['user_id'=>7, 'session_id'=>'session_demo', 'turn_id'=>23, 'run_key'=>'base',
  'agent_run_id'=>str_repeat('e', 32)];
ember_core_in_use_mark('qwen3:8b');
unset($GLOBALS['COREUI_WORK_CONTEXT']);
stab_test(ember_core_in_use_models() === ['qwen3:8b'], 'a running run marks its Core: ' . json_encode(ember_core_in_use_models()));
$unloaded = [];
$unload = static function (string $name) use (&$unloaded): bool { $unloaded[] = $name; return true; };
$ps = static fn(): array => ['qwen3:8b', 'Llava:13B', 'gemma4:31b-cloud'];
stab_test(ember_ollama_single_resident('gemma4:e4b', $ps, $unload) === ['Llava:13B'] && $unloaded === ['Llava:13B'],
  'another call does not unload the Core a running run uses: ' . json_encode($unloaded));
$unloaded = [];
stab_test(ember_ollama_single_resident('gemma4:e4b', $ps, $unload, static fn(): array => []) === ['qwen3:8b', 'Llava:13B'],
  'without a running run the other local Cores leave the RAM as before');
touch(STU_EMBER_CORE_IN_USE_DIR . '/' . str_repeat('e', 32) . '.json', time() - 600);
stab_test(ember_core_in_use_models() === [], 'the mark of a lost worker goes stale like its run');
$GLOBALS['COREUI_WORK_CONTEXT'] = ['user_id'=>7, 'session_id'=>'session_demo', 'turn_id'=>23, 'run_key'=>'base',
  'agent_run_id'=>str_repeat('f', 32)];
ember_core_in_use_mark('gemma4:e4b');
ember_core_in_use_mark('llava:13b');
unset($GLOBALS['COREUI_WORK_CONTEXT']);
stab_test(ember_core_in_use_models() === ['gemma4:e4b', 'llava:13b'], 'a run keeps every Core it used');
ember_core_in_use_release();
stab_test(ember_core_in_use_models() === [] && !is_file(STU_EMBER_CORE_IN_USE_DIR . '/' . str_repeat('f', 32) . '.json'),
  'the mark goes when the run ends');
stab_test(str_contains($streamSource, "ember_core_in_use_mark(\$model);\n    ember_ollama_single_resident(\$model);")
  && str_contains($chatSource, "ember_core_in_use_mark(\$model);\n    ember_ollama_single_resident(\$model);")
  && str_contains($chatSource, "  ember_core_in_use_touch();\n  \$db = \$GLOBALS['COREUI_WORK_PDO'] ?? null;")
  && str_contains($streamSource, "ember_core_in_use_release();\n  ember_generation_lock_release(\$turnLock ?? null);"),
  'both call paths mark the Core, the heartbeat keeps the mark, the stream releases it');

// === Measurement: load, prompt and writing time of each Ollama call ================================================
stab_test(ember_ollama_timing_ms(['load_duration'=>1500000000, 'prompt_eval_duration'=>61000000000, 'eval_duration'=>2500000000])
    === ['load_ms'=>1500, 'prompt_eval_ms'=>61000, 'eval_ms'=>2500]
  && ember_ollama_timing_ms([]) === ['load_ms'=>0, 'prompt_eval_ms'=>0, 'eval_ms'=>0], 'nanoseconds to milliseconds');
$requests();
stab_test(ember_call_ollama('gemma4:e4b', '', 'Hallo', 30, ['__skills'=>'none']) === 'Antwort vom Stub'
  && ($GLOBALS['STU_EMBER_LAST_CALL']['prompt_eval_ms'] ?? null) === 61000 && ($GLOBALS['STU_EMBER_LAST_CALL']['load_ms'] ?? null) === 1500
  && ($GLOBALS['STU_EMBER_LAST_CALL']['eval_ms'] ?? null) === 2500
  && ember_last_call_timing() === ['eval_count'=>12, 'prompt_eval_count'=>3400, 'load_ms'=>1500, 'prompt_eval_ms'=>61000, 'eval_ms'=>2500],
  'the last call records Ollama\'s timing: ' . json_encode($GLOBALS['STU_EMBER_LAST_CALL'] ?? null));
$safe = coreui_work_safe_meta(['load_ms'=>1500, 'prompt_eval_ms'=>61000, 'eval_ms'=>2500, 'eval_count'=>12, 'prompt'=>'geheim']);
stab_test(($safe['load_ms'] ?? null) === 1500 && ($safe['prompt_eval_ms'] ?? null) === 61000 && ($safe['eval_ms'] ?? null) === 2500
  && ($safe['eval_count'] ?? null) === 12 && !isset($safe['prompt']), 'the work view keeps the timing, never text: ' . json_encode($safe));
stab_test(str_contains($chatSource, "'Modellantwort erhalten', ['model'=>\$primaryModel] + ember_last_call_timing());")
  && str_contains($chatSource, "['model'=>\$primaryModel] + \$fileModelStatus + ember_last_call_timing());")
  && str_contains($streamSource, "'content_chars'=>strlen(\$contentAcc)] + \$timing);"),
  'each loop step, the first reply and the stream record their timing in the work view');

echo "Backend stabilisation OK: stream pauses and refusals, closed actions, queue wait, answer before memory, "
  . "busy edits, no orphan replies, CLI exit codes, saved replies, lock files, SSE encoding, one turn instant, "
  . "first tool reply reused, Cores in use kept, call timing\n";
