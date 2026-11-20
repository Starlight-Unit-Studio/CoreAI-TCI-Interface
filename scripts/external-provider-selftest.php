<?php
declare(strict_types=1);
// CoreAI 0.8.11 (CF-003, CF-005): the external OpenAI-compatible adapter.
// 0.8.15: external Cores (several, read from the provider's list), chosen in the Core menu per
// account ("extern:<name>"), released by the Inhaber like Cores outside CoreAI, never for guests;
// the connection test works before the adapter is switched on; Mara's SYSTEM text for external Cores.
//   php scripts/external-provider-selftest.php
// Offline: addresses, model names, the offered Cores, the operator's configuration and who may use
// it, the request (messages, images, only OpenAI options, no think, keep_alive or num_ctx), the
// answer and the event stream back in Ollama's shape (content, reasoning, finish reasons, usage,
// errors, a cut stream), keys hidden in provider messages, the provider list and the connection
// test, the Ollama line unchanged, Mara's SYSTEM text, and the wiring. Per account with an
// in-memory SQLite: the Core menu's list, the turn (Inhaber, Admin and Nutzer with and without
// release, guest, adapter off, Core no longer offered), the old Core-Pfad. With COREUI_SELFTEST_DB=1
// the same against MariaDB, including the choice in the Core menu. End to end against a small
// OpenAI-compatible stand-in server on 127.0.0.1: the real ember_call_ollama() and
// console_stream_ollama() send the same prompt to the external API with the key in the header only,
// read the answer, its reasoning and token counts, notice a cut answer, stream a file preview, refuse
// a wrong key without logging it, and still reach local Ollama for an account without the external
// API. Test data uses example.test only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$GLOBALS['ext_log'] = [];
function stu__log_error(array $entry): void { $GLOBALS['ext_log'][] = $entry; }
function ext_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "External provider FAIL: {$why}\n"); exit(1); }
}
const EXT_KEY = 'sk-test-0123456789abcdefSECRET';

// --- The stand-in server ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/coreai-ext-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$stub = <<<'STUB'
<?php
$raw = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$uri = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode($raw, true) ?: [];
file_put_contents(getenv('STUB_LOG'), json_encode(['uri'=>$uri, 'auth'=>$headers['authorization'] ?? '',
  'accept'=>$headers['accept'] ?? '', 'body'=>$body]) . "\n", FILE_APPEND);
$last = '';
foreach ((array)($body['messages'] ?? []) as $m) $last = is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? '');
header('Content-Type: application/json');
// 0.8.16: the loaded local Cores (ps.json next to this file) and the unload request.
if ($uri === '/api/ps') {
  $ps = is_file(__DIR__ . '/ps.json') ? (array)json_decode((string)file_get_contents(__DIR__ . '/ps.json'), true) : [];
  echo json_encode(['models'=>array_map(static fn($n) => ['name'=>$n, 'model'=>$n], $ps)]);
  exit;
}
if ($uri === '/api/generate') { echo json_encode(['done'=>true]); exit; }
if ($uri === '/api/chat') {
  echo json_encode(['message'=>['role'=>'assistant', 'content'=>'lokal ok'], 'done'=>true, 'done_reason'=>'stop',
    'eval_count'=>3, 'prompt_eval_count'=>9]);
  exit;
}
if ($uri === '/v1/models') {
  echo json_encode(['object'=>'list', 'data'=>[['id'=>'stub-model'], ['id'=>'other-model']]]);
  exit;
}
if ($uri !== '/v1/chat/completions') { http_response_code(404); echo '{"error":{"message":"not found"}}'; exit; }
if (($headers['authorization'] ?? '') !== 'Bearer ' . getenv('STUB_KEY') || str_contains($last, 'MODE:401')) {
  http_response_code(401);
  echo json_encode(['error'=>['message'=>'Incorrect API key provided: ' . getenv('STUB_KEY') . '. See https://example.test/keys.',
    'type'=>'invalid_request_error', 'code'=>'invalid_api_key']]);
  exit;
}
if (str_contains($last, 'MODE:429')) { http_response_code(429); echo '{"error":{"message":"Rate limit reached"}}'; exit; }
if (str_contains($last, 'MODE:empty')) {
  echo json_encode(['choices'=>[['index'=>0,'message'=>['role'=>'assistant','content'=>''],'finish_reason'=>'length']],
    'usage'=>['prompt_tokens'=>3120,'completion_tokens'=>2048,
      'completion_tokens_details'=>['reasoning_tokens'=>2048]]]);exit;
}
if (str_contains($last, 'MODE:200error')) { echo '{"error":{"message":"API rejected request"}}'; exit; }

$finish = str_contains($last, 'MODE:length') || str_contains($last, 'MODE:window') ? 'length' : 'stop';
if (empty($body['stream'])) {
  // MODE:window: a cut answer whose token sum looks like a full local window (4000 + 96 = 4096).
  $usage = str_contains($last, 'MODE:window') ? ['prompt_tokens'=>4000, 'completion_tokens'=>96] : ['prompt_tokens'=>42, 'completion_tokens'=>7, 'total_tokens'=>49];
  echo json_encode(['id'=>'c1', 'object'=>'chat.completion', 'choices'=>[['index'=>0, 'message'=>['role'=>'assistant',
    'content'=>'Hallo vom Stub', 'reasoning_content'=>'kurz nachgedacht'], 'finish_reason'=>$finish]], 'usage'=>$usage]);
  exit;
}
header('Content-Type: text/event-stream');
$event = static function (array $data): void { echo 'data: ' . json_encode($data) . "\n\n"; @ob_flush(); flush(); };
$event(['choices'=>[['index'=>0, 'delta'=>['role'=>'assistant', 'reasoning'=>'Ich ']]]]);
$event(['choices'=>[['index'=>0, 'delta'=>['reasoning'=>'denke']]]]);
foreach (['<h1>', 'Hal', 'lo ', 'Strom</h1>'] as $piece) $event(['choices'=>[['index'=>0, 'delta'=>['content'=>$piece]]]]);
if (str_contains($last, 'MODE:error-event')) { $event(['error'=>['message'=>'The server is overloaded']]); exit; }
if (str_contains($last, 'MODE:cut')) exit;
$event(['choices'=>[['index'=>0, 'delta'=>[], 'finish_reason'=>$finish]], 'usage'=>['prompt_tokens'=>50, 'completion_tokens'=>4]]);
if (!str_contains($last, 'MODE:no-done')) echo "data: [DONE]\n\n";
STUB;
file_put_contents($tmp . '/stub.php', $stub);
$port = 0;
for ($i = 0; $i < 20 && $port === 0; $i++) {
  $try = random_int(21000, 39000);
  $probe = @fsockopen('127.0.0.1', $try, $errno, $errstr, 0.2);
  if ($probe === false) $port = $try; else fclose($probe);
}
ext_test($port > 0, 'a free port for the stand-in server');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/stub.php'], [0=>['file', '/dev/null', 'r'],
  1=>['file', $tmp . '/server.out', 'w'], 2=>['file', $tmp . '/server.out', 'a']], $pipes, $tmp,
  ['STUB_LOG'=>$tmp . '/requests.log', 'STUB_KEY'=>EXT_KEY, 'PATH'=>(string)getenv('PATH')]);
register_shutdown_function(static function () use ($server, $tmp): void {
  if (is_resource($server)) { proc_terminate($server); proc_close($server); }
  exec('rm -rf ' . escapeshellarg($tmp));
});
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
  $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
  if ($probe !== false) { fclose($probe); $up = true; } else usleep(100000);
}
ext_test($up, 'the stand-in server starts');
$requests = static function () use ($tmp): array {
  $lines = is_file($tmp . '/requests.log') ? file($tmp . '/requests.log', FILE_IGNORE_NEW_LINES) : [];
  @unlink($tmp . '/requests.log');
  return array_map(static fn(string $l): array => (array)json_decode($l, true), $lines ?: []);
};

// The library, with local Ollama pointing at the stand-in and the key in the environment.
putenv('COREUI_OLLAMA_URL=http://127.0.0.1:' . $port . '/api/chat');
putenv('COREUI_EXTERNAL_API_KEY=' . EXT_KEY);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
$streamSource = (string)file_get_contents($root . '/api/console_stream.php');
foreach (['console_stream_ollama'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $streamSource, $m) !== 1) ext_test(false, 'missing ' . $fn);
  eval($m[0]);
}
function sse_send(string $event, $data): void {}
$base = 'http://127.0.0.1:' . $port . '/v1';

// --- Addresses, names, configuration (pure) -----------------------------------------------------------
ext_test(coreui_provider_base_url('https://api.example.test/v1/') === 'https://api.example.test/v1'
  && coreui_provider_base_url('http://127.0.0.1:4000/v1') === 'http://127.0.0.1:4000/v1'
  && coreui_provider_base_url('http://localhost:4000') === 'http://localhost:4000', 'https, or http to this machine');
foreach (['http://api.example.test/v1', 'ftp://api.example.test', 'https://user:pw@api.example.test/v1', 'https://api.example.test/v1?key=x',
    'https://api.example.test/v1#x', 'api.example.test/v1', '', 'http://10.0.0.5/v1', 'http://127.0.0.2/v1'] as $bad) {
  ext_test(coreui_provider_base_url($bad) === '', 'refused address: ' . $bad);
}
ext_test(coreui_provider_model_name('gpt-4o-mini') === 'gpt-4o-mini' && coreui_provider_model_name('openai/gpt-4o') === 'openai/gpt-4o'
  && coreui_provider_model_name('claude-x@2025') === 'claude-x@2025' && coreui_provider_model_name('a b') === ''
  && coreui_provider_model_name('x;rm') === '' && coreui_provider_model_name('') === '', 'model names');
// "extern:" plus the name fits the 160 characters of a Core choice and of a release.
ext_test(coreui_provider_model_name(str_repeat('m', 153)) === str_repeat('m', 153) && coreui_provider_model_name(str_repeat('m', 154)) === ''
  && strlen(COREUI_PROVIDER_PREFIX . str_repeat('m', 153)) === 160, 'at most 153 characters');
// An account's choice of an external Core.
ext_test(coreui_provider_is_choice('extern:gpt-4o') && coreui_provider_is_choice(' EXTERN:x') && coreui_provider_is_choice('extern:a b')
  && !coreui_provider_is_choice('gemma4:e4b') && !coreui_provider_is_choice('') && !coreui_provider_is_choice('externo:x'), 'what is an external choice');
ext_test(coreui_provider_choice('extern:gpt-4o') === 'gpt-4o' && coreui_provider_choice('extern:claude-x@2025') === 'claude-x@2025'
  && coreui_provider_choice('extern:a b') === '' && coreui_provider_choice('extern:') === '' && coreui_provider_choice('gemma4:e4b') === '',
  'the Core of a choice');
// The offered Cores: a JSON list, valid and unique, at most 50; the single name of 0.8.11 only without a list.
ext_test(coreui_provider_models_parse('["b-model","a-model","b-model","a b",7,"x;rm"]') === ['b-model', 'a-model']
  && coreui_provider_models_parse('', 'alt-model') === ['alt-model'] && coreui_provider_models_parse('[]', 'alt-model') === []
  && coreui_provider_models_parse('kaputt', 'alt-model') === ['alt-model'] && coreui_provider_models_parse('', 'a b') === []
  && coreui_provider_models_parse(['m1', 'm2']) === ['m1', 'm2'], 'the offered Cores');
$many = [];
for ($i = 0; $i < 70; $i++) $many[] = 'model-' . $i;
ext_test(count(coreui_provider_models_parse((string)json_encode($many))) === COREUI_PROVIDER_MODELS_MAX && COREUI_PROVIDER_MODELS_MAX === 50, 'at most 50 offered');
$full = ['external_api_enabled'=>'1', 'external_api_label'=>'Testanbieter', 'external_api_base_url'=>$base,
  'external_api_models'=>'["stub-model","other-model"]', 'external_api_model'=>'alt-model'];
$cap = coreui_provider_capability($full, true);
ext_test($cap['configured'] && $cap['status'] === 'ready' && $cap['missing'] === [] && $cap['base_url'] === $base && $cap['connection_ready']
  && $cap['models'] === ['stub-model', 'other-model'] && $cap['label'] === 'Testanbieter' && !isset($cap['model']), 'a complete configuration is ready: ' . json_encode($cap));
$legacy = coreui_provider_capability(['external_api_models'=>''] + $full, true);
ext_test($legacy['configured'] && $legacy['models'] === ['alt-model'], 'an adapter set up with 0.8.11 keeps its Core');
$off = coreui_provider_capability(['external_api_enabled'=>'0'] + $full, true);
ext_test(!$off['configured'] && $off['status'] === 'disabled' && $off['connection_ready'] && $off['missing'] === [], 'switched off, but ready to be checked');
$incomplete = coreui_provider_capability(['external_api_base_url'=>'http://api.example.test/v1', 'external_api_models'=>'["a b"]'] + $full, false);
ext_test(!$incomplete['configured'] && $incomplete['status'] === 'incomplete' && !$incomplete['connection_ready']
  && $incomplete['missing'] === ['base_url_invalid', 'key', 'models'], 'what is missing: ' . json_encode($incomplete['missing']));
$noModels = coreui_provider_capability(['external_api_models'=>'[]'] + $full, true);
ext_test(!$noModels['configured'] && $noModels['missing'] === ['models'] && $noModels['connection_ready'], 'no Core offered: not usable, but checkable');
ext_test(coreui_provider_offered($cap, 'stub-model') && coreui_provider_offered($cap, 'other-model') && !coreui_provider_offered($cap, 'Stub-Model')
  && !coreui_provider_offered($cap, 'alt-model') && !coreui_provider_offered($cap, ''), 'which Cores are offered');
ext_test(coreui_provider_account_external($cap, 'other-model', true) === ['label'=>'Testanbieter', 'base_url'=>$base, 'model'=>'other-model']
  && coreui_provider_account_external($cap, 'other-model', false) === null
  && coreui_provider_account_external($cap, 'gone-model', true) === null
  && coreui_provider_account_external($off, 'stub-model', true) === null
  && coreui_provider_account_external($incomplete, 'stub-model', true) === null, 'a turn: allowed, offered, configured');

// --- The key pasted in Admin Core (sealed, never shown; the server configuration wins) ---------------------
ext_test(coreui_provider_key_valid('  gsk_live_A1b2C3d4E5  ') === 'gsk_live_A1b2C3d4E5' && coreui_provider_key_valid('kurz') === ''
  && coreui_provider_key_valid('mit leer zeichen1') === '' && coreui_provider_key_valid("zeile\nzwei12345") === ''
  && coreui_provider_key_valid(str_repeat('a', 401)) === '', 'what counts as a key');
$keys = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);
ext_test(coreui_provider_stored_key($keys) === ['key'=>'', 'unreadable'=>false], 'no stored key yet');
coreui_provider_key_store($keys, '  gsk_live_A1b2C3d4E5  ');
$sealed = (string)stu_app_setting_get($keys, COREUI_PROVIDER_KEY_SETTING, '');
ext_test(str_starts_with($sealed, 's1:') && !str_contains($sealed, 'gsk_live') && !str_contains(base64_decode(substr($sealed, 3)), 'gsk_live'),
  'the key is stored sealed: ' . $sealed);
ext_test(coreui_provider_stored_key($keys) === ['key'=>'gsk_live_A1b2C3d4E5', 'unreadable'=>false], 'and read back');
ext_test(coreui_provider_key($keys) === EXT_KEY && coreui_provider_key_state($keys) === ['source'=>'server', 'unreadable'=>false],
  'a key in the server configuration wins');
$refusedKey = false;
try { coreui_provider_key_store($keys, 'mit leer zeichen1'); } catch (InvalidArgumentException $e) { $refusedKey = $e->getMessage() === 'external_key_invalid'; }
ext_test($refusedKey && coreui_provider_stored_key($keys)['key'] === 'gsk_live_A1b2C3d4E5', 'an invalid key is refused, the stored one stays');
stu_app_setting_set($keys, COREUI_PROVIDER_KEY_SETTING, coreui_secret_seal('gsk_live_A1b2C3d4E5', 'another-purpose'));
unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);
ext_test(coreui_provider_stored_key($keys) === ['key'=>'', 'unreadable'=>true], 'a key sealed for something else is unreadable');
coreui_provider_key_delete($keys);
ext_test(coreui_provider_stored_key($keys) === ['key'=>'', 'unreadable'=>false] && stu_app_setting_get($keys, COREUI_PROVIDER_KEY_SETTING, 'x') === '',
  'the stored key can be removed');
$fromAdmin = coreui_provider_capability($full, true, 'admin');
$unreadable = coreui_provider_capability($full, false, '', true);
ext_test($fromAdmin['configured'] && $fromAdmin['key_source'] === 'admin' && !isset($fromAdmin['key'])
  && !$unreadable['configured'] && $unreadable['missing'] === ['key_unreadable'] && $unreadable['key_source'] === ''
  && !$unreadable['connection_ready'], 'where the key comes from');
unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);

// --- The request (pure) ----------------------------------------------------------------------------------
$png = base64_encode("\x89PNG\r\n\x1a\nrest");
$payload = ['model'=>'gemma4:e4b', 'stream'=>false, 'keep_alive'=>-1, 'think'=>'high',
  'options'=>['num_ctx'=>8192, 'num_predict'=>512, 'top_k'=>40, 'temperature'=>0.7, 'top_p'=>0.9, 'seed'=>3, 'stop'=>['<end>', '']],
  'messages'=>[['role'=>'system', 'content'=>'Du bist Mara.'], ['role'=>'user', 'content'=>'Was siehst du?', 'images'=>[$png, base64_encode("\xff\xd8\xffjpeg")]]]];
$body = coreui_provider_body($payload, 'stub-model');
ext_test($body['model'] === 'stub-model' && $body['stream'] === false && !isset($body['think']) && !isset($body['keep_alive'])
  && !isset($body['options']) && !isset($body['num_ctx']) && !isset($body['top_k']) && !isset($body['max_tokens'])
  && $body['temperature'] === 0.7 && $body['top_p'] === 0.9 && $body['seed'] === 3 && $body['stop'] === ['<end>'], 'only OpenAI options: ' . json_encode($body));
ext_test($body['messages'][0] === ['role'=>'system', 'content'=>'Du bist Mara.']
  && $body['messages'][1]['content'][0] === ['type'=>'text', 'text'=>'Was siehst du?']
  && $body['messages'][1]['content'][1]['image_url']['url'] === 'data:image/png;base64,' . $png
  && str_starts_with($body['messages'][1]['content'][2]['image_url']['url'], 'data:image/jpeg;base64,'), 'messages and pictures');
ext_test(coreui_provider_image_mime(base64_encode('GIF89a...')) === 'image/gif'
  && coreui_provider_image_mime(base64_encode('RIFF1234WEBPVP8 ')) === 'image/webp', 'picture types');
$wire = coreui_provider_wire(['label'=>'T', 'base_url'=>$base, 'model'=>'stub-model'], 'http://127.0.0.1:11434/api/chat', ['stream'=>true] + $payload, 'gemma4:e4b');
ext_test($wire['external'] && $wire['url'] === $base . '/chat/completions' && $wire['model'] === 'extern:stub-model'
  && in_array('Authorization: Bearer ' . EXT_KEY, $wire['headers'], true) && in_array('Accept: text/event-stream', $wire['headers'], true)
  && !str_contains((string)json_encode($wire['body']), EXT_KEY), 'the line to the external API, the key only in the header');
$local = coreui_provider_wire(null, 'http://127.0.0.1:11434/api/chat', $payload, 'gemma4:e4b');
ext_test(!$local['external'] && $local['url'] === 'http://127.0.0.1:11434/api/chat' && $local['body'] === $payload
  && $local['headers'] === ['Content-Type: application/json'] && $local['model'] === 'gemma4:e4b'
  && ($local['feed'])('{"x":1}') === ['{"x":1}'] && ($local['finish'])() === [] && ($local['decode'])('raw') === 'raw', 'the Ollama line is unchanged');

// --- The answer (pure) -------------------------------------------------------------------------------------
$decoded = json_decode(coreui_provider_decode((string)json_encode(['choices'=>[['message'=>['content'=>'Antwort', 'reasoning'=>'Gedanke'],
  'finish_reason'=>'length']], 'usage'=>['prompt_tokens'=>10, 'completion_tokens'=>20]])), true);
ext_test($decoded === ['message'=>['role'=>'assistant', 'content'=>'Antwort', 'thinking'=>'Gedanke'], 'done'=>true, 'done_reason'=>'length',
  'eval_count'=>20, 'prompt_eval_count'=>10], 'an answer in Ollama\'s shape: ' . json_encode($decoded));
$parts = json_decode(coreui_provider_decode('{"choices":[{"message":{"content":[{"type":"text","text":"A"},{"type":"text","text":"B"}]},"finish_reason":"end_turn"}]}'), true);
ext_test($parts['message']['content'] === 'AB' && $parts['done_reason'] === 'stop' && $parts['eval_count'] === 0, 'text parts, other finish words');
$null = json_decode(coreui_provider_decode('{"choices":[{"message":{"content":null},"finish_reason":"content_filter"}]}'), true);
ext_test($null['message']['content'] === '' && $null['done_reason'] === 'content_filter', 'no content');
$error = json_decode(coreui_provider_decode('{"error":{"message":"Incorrect API key provided: ' . EXT_KEY . '","code":"invalid_api_key"}}', EXT_KEY), true);
ext_test(is_string($error['error'] ?? null) && !str_contains($error['error'], EXT_KEY) && !str_contains($error['error'], 'SECRET')
  && str_contains($error['error'], '[Schlüssel ausgeblendet]'), 'an error, the key hidden: ' . json_encode($error));
ext_test(coreui_provider_redact('Bad key sk-proj-abcd1234efgh and Bearer xyz.123 and api-version 2024') === 'Bad key [Schlüssel ausgeblendet] and Bearer [Schlüssel ausgeblendet] and api-version 2024'
  && coreui_provider_redact('invalid credential ZZQ9876543210QQZ given', 'ZZQ9876543210QQZ') === 'invalid credential [Schlüssel ausgeblendet] given'
  && coreui_provider_error_message('{"detail":"Not Found"}') === 'Not Found' && coreui_provider_error_message('nonsense') === ''
  && mb_strlen(coreui_provider_error_message('{"error":"' . str_repeat('x', 500) . '"}')) === COREUI_PROVIDER_ERROR_CHARS + 3, 'messages: hidden keys, other shapes, cut');
ext_test(json_decode(coreui_provider_decode('{"choices":[]}'), true)['error'] === 'Die externe API hat keine Antwort geliefert.'
  && coreui_provider_decode('kein json') === 'kein json', 'no choice, no JSON');

// --- The event stream (pure) ----------------------------------------------------------------------------------
$t = coreui_provider_stream_translator(EXT_KEY);
$lines = [];
foreach ([': keep-alive', 'data: {"choices":[{"delta":{"role":"assistant","reasoning_content":"Hm"}}]}', 'event: ping',
    'data: {"choices":[{"delta":{"content":"Hal"}}]}', 'data: {"choices":[{"delta":{"content":"lo"},"finish_reason":null}]}',
    'data: {"choices":[{"delta":{},"finish_reason":"length"}],"usage":{"prompt_tokens":5,"completion_tokens":2}}', 'data: [DONE]',
    'data: {"choices":[{"delta":{"content":"zu spaet"}}]}'] as $line) {
  foreach (($t['feed'])($line) as $out) $lines[] = json_decode($out, true);
}
foreach (($t['finish'])() as $out) $lines[] = json_decode($out, true);
ext_test($lines === [['message'=>['role'=>'assistant', 'thinking'=>'Hm'], 'done'=>false], ['message'=>['role'=>'assistant', 'content'=>'Hal'], 'done'=>false],
  ['message'=>['role'=>'assistant', 'content'=>'lo'], 'done'=>false], ['done'=>true, 'done_reason'=>'length', 'eval_count'=>2, 'prompt_eval_count'=>5]],
  'a stream in Ollama lines: ' . json_encode($lines));
$t = coreui_provider_stream_translator();
$out = array_merge(($t['feed'])('data: {"choices":[{"delta":{"content":"x"},"finish_reason":"stop"}]}'), ($t['finish'])());
ext_test(count($out) === 2 && json_decode($out[1], true)['done'] === true, 'a finish without [DONE] is a complete answer');
$t = coreui_provider_stream_translator();
ext_test(array_merge(($t['feed'])('data: {"choices":[{"delta":{"content":"x"}}]}'), ($t['finish'])()) === ['{"message":{"role":"assistant","content":"x"},"done":false}'],
  'a cut stream stays not done');
$t = coreui_provider_stream_translator(EXT_KEY);
$err = ($t['feed'])('{"error":{"message":"invalid key ' . EXT_KEY . '"}}');
ext_test(count($err) === 1 && str_contains($err[0], '"error"') && !str_contains($err[0], EXT_KEY), 'an error body in a stream, the key hidden');

// --- The provider list and the connection test (pure transport) --------------------------------------------------
$seen = [];
$fake = static function (array $answer) use (&$seen): callable {
  return static function (string $url, array $headers) use ($answer, &$seen): array { $seen = [$url, $headers]; return $answer; };
};
$list = coreui_provider_list($cap, EXT_KEY, $fake(['code'=>200, 'ms'=>12,
  'body'=>'{"data":[{"id":"zeta-2"},{"id":"Alpha"},{"id":"zeta-10"},{"id":"zeta-2"},{"id":"bad name"},{"name":"named-model"},"plain-model",{"x":1}]}']));
ext_test($list['ok'] && $list['reason'] === 'listed' && $list['ids'] === ['Alpha', 'named-model', 'plain-model', 'zeta-2', 'zeta-10']
  && $list['elapsed_ms'] === 12 && $seen[0] === $base . '/models' && in_array('Authorization: Bearer ' . EXT_KEY, $seen[1], true),
  'the list: valid names, unique, sorted: ' . json_encode($list));
ext_test(coreui_provider_list($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"models":[{"name":"m1"}]}']))['ids'] === ['m1']
  && coreui_provider_list($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[]}']))['reason'] === 'no_model_list'
  && coreui_provider_list($cap, EXT_KEY, $fake(['code'=>405, 'body'=>'']))['reason'] === 'no_model_list'
  && coreui_provider_list($cap, EXT_KEY, $fake(['code'=>0, 'body'=>'', 'error'=>'curl_6']))['reason'] === 'unreachable', 'the list: other shapes');
$bigList = [];
for ($i = 0; $i < 1200; $i++) $bigList[] = ['id'=>sprintf('m-%04d', $i)];
ext_test(count(coreui_provider_list($cap, EXT_KEY, $fake(['code'=>200, 'body'=>json_encode(['data'=>$bigList])]))['ids']) === COREUI_PROVIDER_LIST_MAX,
  'the list is bounded');
$seen = [];
$notReady = coreui_provider_list($incomplete, EXT_KEY, $fake(['code'=>200, 'body'=>'{}']));
ext_test(!$notReady['ok'] && $notReady['reason'] === 'not_ready' && $notReady['missing'] === ['base_url_invalid', 'key'] && $seen === []
  && coreui_provider_list($cap, '', $fake(['code'=>200, 'body'=>'{}']))['reason'] === 'not_ready' && $seen === [],
  'without address or key nothing is sent');
$r = coreui_provider_test($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"stub-model"},{"id":"other-model"},{"id":"x"}]}', 'ms'=>12]));
ext_test($r['ok'] && $r['reason'] === 'models_listed' && $r['models_count'] === 3 && $r['models_not_listed'] === [] && $r['enabled']
  && $r['models'] === ['stub-model', 'other-model'] && !isset($r['ids']), 'test: all offered Cores listed: ' . json_encode($r));
$r = coreui_provider_test($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"stub-model"}]}']));
ext_test($r['ok'] && $r['reason'] === 'models_not_listed' && $r['models_not_listed'] === ['other-model'], 'test: names the Core the provider does not list');
// 0.8.15: the check works before the adapter is switched on and says so; with no Core offered it still connects.
$r = coreui_provider_test($off, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"stub-model"},{"id":"other-model"}]}']));
ext_test($r['ok'] && $r['reason'] === 'models_listed' && $r['enabled'] === false, 'test while switched off: ' . json_encode($r));
$r = coreui_provider_test($noModels, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"a"},{"id":"b"}]}']));
ext_test($r['ok'] && $r['reason'] === 'connected' && $r['models_count'] === 2 && $r['models'] === [], 'test without an offered Core');
ext_test(coreui_provider_test($cap, EXT_KEY, $fake(['code'=>404, 'body'=>'']))['ok'] === true
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>404, 'body'=>'']))['reason'] === 'no_model_list'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>0, 'body'=>'', 'error'=>'curl_6']))['reason'] === 'unreachable'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>429, 'body'=>'{"error":"slow"}']))['reason'] === 'rate_limited'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>500, 'body'=>'{}']))['reason'] === 'http_error', 'test: other answers');
$refused = coreui_provider_test($cap, EXT_KEY, $fake(['code'=>401, 'body'=>'{"error":{"message":"bad key ' . EXT_KEY . '"}}']));
ext_test(!$refused['ok'] && $refused['reason'] === 'key_refused' && !str_contains((string)json_encode($refused), EXT_KEY), 'test: refused key, hidden');
$seen = [];
$nr = coreui_provider_test($incomplete, EXT_KEY, $fake(['code'=>200, 'body'=>'{}']));
ext_test(!$nr['ok'] && $nr['reason'] === 'not_ready' && $nr['missing'] === ['base_url_invalid', 'key'] && $seen === [], 'test: not ready, nothing sent');
$real = coreui_provider_test($cap, EXT_KEY);
ext_test($real['ok'] && $real['reason'] === 'models_listed' && $real['models_count'] === 2, 'test against the stand-in: ' . json_encode($real));
$realList = coreui_provider_list($off, EXT_KEY);
ext_test($realList['ok'] && $realList['ids'] === ['other-model', 'stub-model'], 'the list from the stand-in, switched off: ' . json_encode($realList));
$requests();

// --- End to end: ember_call_ollama --------------------------------------------------------------------------------
$GLOBALS['COREUI_MODEL_LOCK_DEPTH'] = 5;   // inside an already held model slot (re-entrant), no database needed
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'Test', 'base_url'=>$base, 'model'=>'stub-model']];
ext_test(coreui_provider_active() === ['label'=>'Test', 'base_url'=>$base, 'model'=>'stub-model'], 'active for this turn');
$reply = ember_call_ollama('gemma4:e4b', 'Du bist Mara.', 'Sag hallo.', 30, ['__skills'=>'none']);
$sent = $requests();
ext_test($reply === 'Hallo vom Stub', 'the external answer: ' . var_export($reply, true));
ext_test(count($sent) === 1 && $sent[0]['uri'] === '/v1/chat/completions' && $sent[0]['auth'] === 'Bearer ' . EXT_KEY
  && $sent[0]['body']['model'] === 'stub-model' && $sent[0]['body']['stream'] === false && !isset($sent[0]['body']['think'])
  && !isset($sent[0]['body']['keep_alive']) && $sent[0]['body']['messages'][0] === ['role'=>'system', 'content'=>'Du bist Mara.']
  && str_contains($sent[0]['body']['messages'][1]['content'], 'Sag hallo.'), 'the same prompt went out: ' . json_encode($sent));
$last = $GLOBALS['STU_EMBER_LAST_CALL'];
ext_test($last['ok'] && $last['provider'] === 'external' && $last['model'] === 'extern:stub-model' && $last['done_reason'] === 'stop'
  && $last['eval_count'] === 7 && $last['prompt_eval_count'] === 42 && $last['context_tokens'] === 0
  && $GLOBALS['STU_EMBER_LAST_THINKING'] === 'kurz nachgedacht' && !ember_last_call_was_truncated(), 'what the call noted: ' . json_encode($last));
ember_call_ollama('gemma4:e4b', '', 'MODE:length', 30, ['__skills'=>'none']);
ext_test(ember_last_call_was_truncated(), 'a cut answer is noticed');
// A cut external answer never teaches the local Core a context window (its sum looks like one).
unset($GLOBALS['COREUI_OLLAMA_CONTEXT_MEMO']);
ember_call_ollama('gemma4:e4b', '', 'MODE:window', 30, ['__skills'=>'none']);
ext_test(ember_last_call_was_truncated() && $GLOBALS['STU_EMBER_LAST_CALL']['context_tokens'] === 0
  && !isset($GLOBALS['COREUI_OLLAMA_CONTEXT_MEMO']['gemma4:e4b']), 'no local window learnt from an external answer');
$GLOBALS['ext_log'] = [];
ext_test(ember_call_ollama('gemma4:e4b', '', 'MODE:401', 30, ['__skills'=>'none']) === null
  && $GLOBALS['STU_EMBER_LAST_CALL']['code'] === 401, 'a refused key gives no answer');
$logged = (string)json_encode($GLOBALS['ext_log']);
ext_test(count($GLOBALS['ext_log']) === 1 && $GLOBALS['ext_log'][0]['type'] === 'ember_ollama_fail' && str_contains($logged, 'Incorrect API key')
  && !str_contains($logged, EXT_KEY) && !str_contains($logged, 'SECRET'), 'the log names the error, never the key: ' . $logged);
ember_call_ollama('gemma4:e4b', '', 'MODE:429', 30, ['__skills'=>'none']);
ext_test($GLOBALS['STU_EMBER_LAST_CALL']['code'] === 429 && str_contains((string)json_encode($GLOBALS['ext_log']), 'Rate limit'), 'a rate limit is logged');
// A reasoning-heavy completion can use tokens and finish without visible text.
// This is one of the real causes of an empty follow-up, distinct from HTTP 429.
ext_test(ember_call_ollama('gemma4:e4b', '', 'MODE:empty', 30, ['__skills'=>'none']) === null,
  'external completion with zero visible text remains empty');
$emptyStatus = ember_agent_followup_last_call();
ext_test($emptyStatus['provider']==='external'
  && $emptyStatus['http_code']===200
  && $emptyStatus['done_seen'] && $emptyStatus['done']
  && $emptyStatus['done_reason']==='length'
  && $emptyStatus['eval_count']===2048 && $emptyStatus['prompt_eval_count']===3120
  && coreui_agent_empty_followup_retryable($emptyStatus),
  'completed, empty reasoning-heavy answer qualifies for exactly one lean project recovery');
ext_test(ember_call_ollama('gemma4:e4b', '', 'MODE:200error', 30, ['__skills'=>'none']) === null,
  'HTTP 200 with provider error is not a usable assistant reply');
ext_test(!coreui_agent_empty_followup_retryable(ember_agent_followup_last_call()),
  'provider errors with no completed answer never trigger paid recovery');
$requests();

// The file preview of the source-only writer streams through the same translator.
$requests();
$chunks = [];
$GLOBALS['COREUI_SOURCE_PREVIEW'] = ['publish'=>static function (array $event) use (&$chunks): void { $chunks[] = $event; },
  'file_name'=>'index.html', 'attempt'=>1];
$source = ember_call_ollama('gemma4:e4b', '', 'Schreib index.html', 30, ['__skills'=>'none']);
unset($GLOBALS['COREUI_SOURCE_PREVIEW']);
$sent = $requests();
ext_test($source === '<h1>Hallo Strom</h1>' && $sent[0]['body']['stream'] === true && $sent[0]['accept'] === 'text/event-stream'
  && array_column($chunks, 'phase') === ['begin', 'chunk', 'chunk', 'chunk', 'chunk', 'end']
  && implode('', array_column(array_filter($chunks, static fn(array $c): bool => $c['phase'] === 'chunk'), 'text')) === '<h1>Hallo Strom</h1>',
  'the file preview streams: ' . json_encode([$source, $chunks]));

// --- End to end: console_stream_ollama -----------------------------------------------------------------------------
$s = console_stream_ollama('gemma4:e4b', 'Du bist Mara.', 'Erzaehl was.', false, 30, null, false);
$sent = $requests();
ext_test($s['ok'] && $s['done'] && !$s['truncated'] && $s['content'] === '<h1>Hallo Strom</h1>' && $s['thinking'] === 'Ich denke'
  && $s['eval_count'] === 4 && $s['prompt_eval_count'] === 50 && $s['context_tokens'] === 0, 'the stream: ' . json_encode($s));
ext_test($sent[0]['uri'] === '/v1/chat/completions' && $sent[0]['auth'] === 'Bearer ' . EXT_KEY && $sent[0]['body']['stream'] === true
  && !isset($sent[0]['body']['think']), 'the stream request');
$s = console_stream_ollama('gemma4:e4b', '', 'MODE:length', false, 30, null, false);
ext_test($s['ok'] && $s['truncated'] && $s['done_reason'] === 'length', 'a cut stream answer');
$s = console_stream_ollama('gemma4:e4b', '', 'MODE:no-done', false, 30, null, false);
ext_test($s['ok'] && $s['done'], 'a stream without [DONE]');
$s = console_stream_ollama('gemma4:e4b', '', 'MODE:cut', false, 30, null, false);
ext_test(!$s['ok'] && !$s['done'] && $s['content'] === '<h1>Hallo Strom</h1>', 'a stream that breaks off is not complete');
$s = console_stream_ollama('gemma4:e4b', '', 'MODE:error-event', false, 30, null, false);
ext_test(!$s['ok'], 'an error event fails the stream');
$s = console_stream_ollama('gemma4:e4b', '', 'MODE:401', false, 30, null, false);
ext_test(!$s['ok'] && $s['content'] === '', 'a refused key in the stream');
$requests();

// --- An account without the external API stays on local Ollama ------------------------------------------------------
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];
ext_test(coreui_provider_active() === null, 'local account');
// 0.8.16: before a local call the other local Cores leave the RAM (cloud Cores take none here).
file_put_contents($tmp . '/ps.json', json_encode(['qwen3.5:35b', 'gemma4:e4b', 'gpt-oss:120b-cloud', 'ember-coreui:gen']));
ext_test(ember_call_ollama('gemma4:e4b', '', 'Hallo', 30, ['__skills'=>'none']) === 'lokal ok', 'local answer');
$sent = $requests();
$chatSent = array_values(array_filter($sent, static fn(array $r): bool => $r['uri'] === '/api/chat'));
$unloaded = array_map(static fn(array $r): string => (string)$r['body']['model'] . '/' . json_encode($r['body']['keep_alive'] ?? null),
  array_values(array_filter($sent, static fn(array $r): bool => $r['uri'] === '/api/generate')));
ext_test(count($chatSent) === 1 && $chatSent[0]['auth'] === '' && $chatSent[0]['body']['model'] === 'gemma4:e4b'
  && array_key_exists('think', $chatSent[0]['body']) && $GLOBALS['STU_EMBER_LAST_CALL']['provider'] === 'ollama', 'local Ollama, no key: ' . json_encode($sent));
ext_test($unloaded === ['qwen3.5:35b/0', 'ember-coreui:gen/0'] && array_search('/api/chat', array_column($sent, 'uri')) > array_search('/api/generate', array_column($sent, 'uri')),
  'the other local Cores are unloaded before the call, the cloud Core stays: ' . json_encode($unloaded));
file_put_contents($tmp . '/ps.json', json_encode(['gemma4:e4b']));
ember_call_ollama('gemma4:e4b', '', 'Hallo', 30, ['__skills'=>'none']);
ext_test(array_values(array_filter($requests(), static fn(array $r): bool => $r['uri'] === '/api/generate')) === [], 'nothing to unload when only this Core is loaded');
// The external API never unloads a local Core on its own (the Core switch does, see user_settings.php).
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'Test', 'base_url'=>$base, 'model'=>'stub-model']];
file_put_contents($tmp . '/ps.json', json_encode(['qwen3.5:35b']));
ember_call_ollama('gemma4:e4b', '', 'Hallo', 30, ['__skills'=>'none']);
ext_test(array_values(array_filter($requests(), static fn(array $r): bool => in_array($r['uri'], ['/api/ps', '/api/generate'], true))) === [],
  'an external call does not touch the local Cores');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];
@unlink($tmp . '/ps.json');
// The rule itself (pure): only other local Cores, never the cloud, same name with or without :latest.
$unloadedPure = [];
ext_test(ember_ollama_single_resident('ember-coreui', static fn(): array => ['ember-coreui:latest', 'Qwen3:8B', 'x:20b-cloud', ''],
    static function (string $n) use (&$unloadedPure): bool { $unloadedPure[] = $n; return true; }) === ['Qwen3:8B']
  && $unloadedPure === ['Qwen3:8B']
  && ember_ollama_single_resident('gemma4:31b-cloud', static fn(): array => ['qwen3:8b'], static fn(string $n): bool => true) === []
  && ember_ollama_single_resident('gemma4:e4b', static fn(): array => ['qwen3:8b'], static fn(string $n): bool => false) === [],
  'single resident Core: rule');
// A provider in the runtime settings without the server key, or with a bad address, is never used.
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'T', 'base_url'=>'http://10.0.0.5/v1', 'model'=>'m']];
ext_test(coreui_provider_active() === null, 'a bad address is never called');

// --- Mara's SYSTEM text for an external Core ------------------------------------------------------------------------
// An external Core has no Modelfile: it gets Mara's SYSTEM text from the operator Modelfile, unchanged.
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'Test', 'base_url'=>$base, 'model'=>'stub-model']];
$mara = ember_mara_system_text();
ext_test($mara !== '' && ember_turn_persona_system() === $mara, 'an external Core gets Mara\'s SYSTEM text');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];
ext_test(ember_turn_persona_system() === '' && ember_turn_persona_system() === ember_persona_system_for(ember_model()),
  'her own local Core gets none (its Modelfile carries it)');

// --- Per account (in-memory SQLite) -----------------------------------------------------------------------------------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER, is_guest INTEGER)');
$db->exec('INSERT INTO stu_users (id, permission_level, is_guest) VALUES (1, 0, 0), (2, 1, 0), (3, 4, 0), (4, 4, 1), (5, 4, 0)');
$db->exec('CREATE TABLE stu_user_model_access (user_id INTEGER NOT NULL, model_name TEXT NOT NULL, granted_by INTEGER, '
  . 'created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, model_name))');
// The app settings table of this database (helpers install it once per process, for the first one).
$db->exec('CREATE TABLE stu_app_settings (k TEXT NOT NULL PRIMARY KEY, value TEXT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE stu_user_ai_settings (user_id INTEGER PRIMARY KEY, system_prompt TEXT, memory_enabled INTEGER, thinking_enabled INTEGER, '
  . 'memory_limit INTEGER, num_predict INTEGER, temperature REAL, provider TEXT, model_override TEXT, num_ctx INTEGER NULL, '
  . 'main_language TEXT, updated_at TEXT)');
foreach (['external_api_enabled'=>'1', 'external_api_label'=>'Testanbieter', 'external_api_base_url'=>$base,
    'external_api_models'=>'["stub-model","other-model"]'] as $k => $v) {
  stu_app_setting_set($db, $k, $v);
}
ext_test((int)$db->query("SELECT COUNT(*) FROM stu_app_settings WHERE k LIKE 'external_api_%'")->fetchColumn() === 4,
  'the settings are in the test database (not in the fallback file)');
unset($GLOBALS['COREUI_PROVIDER_STORED_KEY']);
coreui_model_access_save($db, 3, ['extern:other-model', 'extern:claude-x@2025'], 1);
ext_test(coreui_model_access_grants($db, 3) === ['extern:claude-x@2025', 'extern:other-model'], 'releases of external Cores (with @)');
$refusedRelease = 0;
foreach ([['extern:'], ['extern:a b'], ['extern:' . str_repeat('m', 154)]] as $bad) {
  try { coreui_model_access_save($db, 5, $bad, 1); } catch (InvalidArgumentException $e) { $refusedRelease += $e->getMessage() === 'invalid_model_name' ? 1 : 0; }
}
ext_test($refusedRelease === 3 && coreui_model_access_grants($db, 5) === [], 'invalid external releases are refused');
$names = static fn(array $rows): array => array_column($rows, 'name');
ext_test($names(coreui_ai_external_cores($db, 1)) === ['extern:stub-model', 'extern:other-model'], 'the Inhaber sees every external Core');
ext_test(coreui_ai_external_cores($db, 2) === [] && coreui_ai_external_cores($db, 5) === [], 'Admin and Nutzer without release see none');
ext_test(coreui_ai_external_cores($db, 3) === [['name'=>'extern:other-model', 'model'=>'other-model', 'label'=>'other-model', 'provider'=>'Testanbieter']],
  'a Nutzer sees his release (a released Core not offered stays hidden)');
ext_test(coreui_ai_external_cores($db, 4) === [] && coreui_ai_external_cores($db, 99) === [], 'guests and unknown accounts never');
ext_test(coreui_ai_external_usable($db, 1, 'stub-model') && coreui_ai_external_usable($db, 3, 'other-model') && !coreui_ai_external_usable($db, 3, 'stub-model')
  && !coreui_ai_external_usable($db, 2, 'stub-model') && !coreui_ai_external_usable($db, 4, 'stub-model') && !coreui_ai_external_usable($db, 1, 'gone-model'),
  'who may chat with which external Core');
// An external Core named like a TCI tier is still no Core of CoreAI: it needs the release too.
stu_app_setting_set($db, 'external_api_models', '["stub-model","other-model","google/gemma-4-e4b-it"]');
ext_test(coreui_ai_external_cores($db, 5) === [] && !coreui_ai_external_usable($db, 5, 'google/gemma-4-e4b-it')
  && coreui_ai_external_usable($db, 1, 'google/gemma-4-e4b-it') && coreui_core_label('extern:google/gemma-4-e4b-it') === 'extern:google/gemma-4-e4b-it',
  'a tier-like name of an external Core gives no access');
stu_app_setting_set($db, 'external_api_models', '["stub-model","other-model"]');
$setChoice = static function (int $uid, string $choice, string $provider = 'local') use ($db): void {
  $db->prepare('DELETE FROM stu_user_ai_settings WHERE user_id = ?')->execute([$uid]);
  $db->prepare('INSERT INTO stu_user_ai_settings (user_id, system_prompt, memory_enabled, thinking_enabled, memory_limit, num_predict, temperature, '
    . 'provider, model_override, main_language) VALUES (?, \'\', 1, 1, 16, 6500, 1.0, ?, ?, \'de\')')->execute([$uid, $provider, $choice]);
};
$turn = static function (int $uid) use ($db): array {
  unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);
  coreui_ai_runtime_apply($db, $uid);
  $run = coreui_ai_runtime_settings();
  return [$run['provider'] ?? '', $run['external']['model'] ?? null, (string)($run['model_override'] ?? ''), coreui_provider_active()];
};
$setChoice(1, 'extern:other-model');
[$provider, $model, $override, $active] = $turn(1);
ext_test($provider === 'external' && $model === 'other-model' && $override === ''
  && $active === ['label'=>'Testanbieter', 'base_url'=>$base, 'model'=>'other-model'], 'the Inhaber\'s turn goes to his external Core');
$setChoice(3, 'extern:other-model');
ext_test($turn(3)[1] === 'other-model', 'a Nutzer with release');
$setChoice(3, 'extern:stub-model');
[$provider, $model, $override, $active] = $turn(3);
ext_test($provider === 'local' && $model === null && $override === '' && $active === null, 'a Nutzer without release chats with the default Core');
$setChoice(2, 'extern:stub-model');
ext_test($turn(2)[0] === 'local', 'an Admin needs a release too');
$setChoice(4, 'extern:stub-model');
ext_test($turn(4)[0] === 'local' && $turn(4)[3] === null, 'a guest never');
$setChoice(1, 'extern:gone-model');
ext_test($turn(1)[0] === 'local', 'a Core no longer offered falls back');
$setChoice(1, 'extern:stub-model');
stu_app_setting_set($db, 'external_api_enabled', '0');
ext_test($turn(1)[0] === 'local', 'adapter switched off: local');
stu_app_setting_set($db, 'external_api_enabled', '1');
ext_test($turn(1)[1] === 'stub-model', 'switched on again: the stored choice is back');
$setChoice(1, 'gemma4:e4b');
[$provider, $model, $override] = $turn(1);
ext_test($provider === 'local' && $model === null && $override === 'gemma4:e4b', 'a local Core stays local');
// The Core-Pfad "Externe API" of 0.8.11 becomes the first offered external Core.
$setChoice(1, 'qwen3.5:35b', 'external');
$loaded = coreui_ai_settings_load($db, 1);
ext_test($loaded['model_override'] === 'extern:stub-model' && $loaded['provider'] === 'local' && $turn(1)[1] === 'stub-model',
  'the old Core-Pfad becomes a choice: ' . json_encode([$loaded['model_override'], $loaded['provider']]));
ext_test(coreui_ai_settings_normalise($db, ['model_override'=>'extern:claude-x@2025'])['model_override'] === 'extern:claude-x@2025'
  && coreui_ai_settings_normalise($db, ['model_override'=>'extern:a b'])['model_override'] === ''
  && coreui_ai_settings_normalise($db, ['model_override'=>'gemma4:e4b', 'provider'=>'local'])['model_override'] === 'gemma4:e4b'
  && coreui_ai_settings_normalise($db, ['provider'=>'external'])['provider'] === 'local', 'stored choices');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];

// --- MariaDB (COREUI_SELFTEST_DB=1): the choice in the Core menu ends the old Core-Pfad -----------------------------------
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $maria = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $tag = bin2hex(random_bytes(3));
  $maria->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('ext-{$tag}@example.test', 0, 0, NOW())");
  $mUid = (int)$maria->lastInsertId();
  $before = [];
  foreach (['external_api_enabled', 'external_api_label', 'external_api_base_url', 'external_api_models', 'external_api_model'] as $k) {
    $before[$k] = stu_app_setting_get($maria, $k, null);
  }
  try {
    foreach (['external_api_enabled'=>'1', 'external_api_label'=>'Testanbieter', 'external_api_base_url'=>$base,
        'external_api_models'=>'["stub-model","other-model"]', 'external_api_model'=>''] as $k => $v) stu_app_setting_set($maria, $k, $v);
    $maria->prepare("INSERT INTO stu_user_ai_settings (user_id, system_prompt, memory_enabled, thinking_enabled, memory_limit, num_predict, temperature, provider, model_override) "
      . "VALUES (?, '', 1, 1, 16, 6500, 1.0, 'external', 'qwen3.5:35b')")->execute([$mUid]);
    ext_test(coreui_ai_settings_load($maria, $mUid)['model_override'] === 'extern:stub-model', 'MariaDB: the old Core-Pfad becomes a choice');
    coreui_ai_settings_patch($maria, $mUid, ['model_override'=>'gemma4:e4b']);
    $row = $maria->query('SELECT provider, model_override FROM stu_user_ai_settings WHERE user_id = ' . $mUid)->fetch(PDO::FETCH_ASSOC);
    ext_test($row === ['provider'=>'local', 'model_override'=>'gemma4:e4b'] && coreui_ai_settings_load($maria, $mUid)['model_override'] === 'gemma4:e4b',
      'MariaDB: a local Core chosen in the menu stays chosen: ' . json_encode($row));
    coreui_ai_settings_patch($maria, $mUid, ['model_override'=>'extern:claude-x@2025']);
    ext_test(coreui_ai_settings_load($maria, $mUid)['model_override'] === 'extern:claude-x@2025', 'MariaDB: an external choice is stored as is');
    coreui_ai_settings_patch($maria, $mUid, ['model_override'=>'extern:other-model']);
    unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);
    coreui_ai_runtime_apply($maria, $mUid);
    ext_test((coreui_ai_runtime_settings()['external']['model'] ?? '') === 'other-model', 'MariaDB: the turn of the Inhaber');
    coreui_model_access_save($maria, $mUid, ['extern:other-model'], $mUid);
    ext_test(coreui_model_access_grants($maria, $mUid) === ['extern:other-model'], 'MariaDB: a release of an external Core');
  } finally {
    $maria->prepare('DELETE FROM stu_user_model_access WHERE user_id = ?')->execute([$mUid]);
    $maria->prepare('DELETE FROM stu_user_ai_settings WHERE user_id = ?')->execute([$mUid]);
    $maria->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$mUid]);
    foreach ($before as $k => $v) {
      if ($v === null) $maria->prepare('DELETE FROM stu_app_settings WHERE `k` = ?')->execute([$k]);
      else stu_app_setting_set($maria, $k, $v);
    }
  }
  $GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'local', 'external'=>null];
}

// --- Wiring (static) -------------------------------------------------------------------------------------------------
$chat = (string)file_get_contents($root . '/api/chat.php');
$settings = (string)file_get_contents($root . '/api/ai_settings.php');
$userSettings = (string)file_get_contents($root . '/api/user_settings.php');
$admin = (string)file_get_contents($root . '/api/admin.php');
$models = (string)file_get_contents($root . '/api/models.php');
ext_test(str_contains($chat, '$wire = coreui_provider_wire(coreui_provider_active(), $url, $payload, $model);')
  && str_contains($streamSource, '$wire = coreui_provider_wire(coreui_provider_active(), $url, $payload, $model);')
  && str_contains($streamSource, "coreui_work_record(\$workDb, 'model', 'running', 'Modellstream gestartet', ['model'=>\$wire['model']]);")
  && substr_count($chat, 'ember_global_lock_acquire()') >= 1, 'both model calls use the line, the work view names the external Core');
ext_test(substr_count($chat, "  if (!\$wire['external']) ember_ollama_single_resident(\$model);") === 1
  && substr_count($streamSource, "  if (!\$wire['external']) ember_ollama_single_resident(\$model);") === 1
  && str_contains($userSettings, 'coreui_core_unload_local();'), 'both model calls keep one local Core, the external switch unloads');
ext_test(str_contains($chat, '  $sys = ember_turn_persona_system();') && str_contains($chat, '  $sysBase = ember_turn_persona_system();')
  && substr_count($chat, 'ember_persona_system_for(ember_model())') === 1, 'both chat turns take the persona of this turn\'s Core');
ext_test(str_contains($settings, "\$externalChoice = coreui_provider_choice((string)\$settings['model_override']);")
  && str_contains($settings, "coreui_ai_external_usable(\$pdo, \$uid, \$externalChoice, \$capability));")
  && str_contains($settings, "if (array_key_exists('model_override', \$patch)) \$updates .= \", provider = 'local'\";"), 'each turn checks the account');
ext_test(str_contains($admin, "if (\$action === 'external_api_key_save' || \$action === 'external_api_key_delete') {\n  if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);")
  && str_contains($admin, "if (coreui_provider_server_key() !== '') stu_json(['ok' => false, 'error' => 'external_key_in_server_config'], 409);")
  && str_contains($admin, "coreui_admin_audit(\$pdo, \$actorId, \$action, 'app_settings', 'external_api_key');"),
  'Admin Core: only the Inhaber stores or removes the key, never over the server configuration, the audit without the key');
ext_test(str_contains($admin, "if (\$action === 'external_api_save') {\n  if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);")
  && str_contains($admin, '$baseUrl = coreui_provider_base_url($baseUrl);') && str_contains($admin, "if (\$action === 'external_api_models') {")
  && str_contains($admin, "if (\$action === 'external_api_test') {")
  && str_contains($admin, "foreach ((array)coreui_ai_external_capability(\$pdo)['models'] as \$model) \$offered[] = COREUI_PROVIDER_PREFIX . \$model;")
  && !str_contains($admin, "'external_api_enabled', 'external_api_label', 'external_api_base_url', 'external_api_model',"),
  'Admin Core: only the Inhaber sets the adapter up, checks the address, lists, tests; releases list the external Cores');
ext_test(str_contains($userSettings, "stu_json(['ok'=>false, 'error'=>'external_not_available'], 409);")
  && str_contains($userSettings, "'label'=>\$external, 'provider'=>(string)\$capability['label'], 'preload'=>'external',")
  && !str_contains($userSettings, 'external_not_allowed') && str_contains($models, "'external'=>\$externalCores,"),
  'the Core menu lists and chooses external Cores, nothing is loaded');
$adminJs = (string)file_get_contents($root . '/js/admin.js');
$settingsJs = (string)file_get_contents($root . '/js/settings.js');
$coreModeJs = (string)file_get_contents($root . '/js/coreui-core-mode.js');
ext_test(str_contains($adminJs, "post('external_api_key_save', { key: value })") && str_contains($adminJs, "field.value = '';")
  && str_contains($adminJs, "field.disabled = server || !owner;"), 'the key field: sent once, emptied, locked for admins and server keys');
ext_test(str_contains($adminJs, "post('external_api_test', {})") && str_contains($adminJs, "post('external_api_save', form)")
  && str_contains($adminJs, "post('external_api_models', {})") && str_contains($adminJs, "document.getElementById('externalTest').addEventListener('click', testExternal);")
  && !str_contains($settingsJs, 'aiProvider') && str_contains($coreModeJs, "data.preload === 'external'"), 'the pages');

echo "CoreAI externer Adapter: Adressen, Namen, angebotene externe Cores, Einrichtung, Anfrage, Antwort, Strom, Fehler ohne Schluessel, "
  . "Liste des Anbieters, Verbindungstest auch ausgeschaltet, Ollama unveraendert, Maras SYSTEM-Text, je Konto (Inhaber, Freigaben, Gast, "
  . "aus, nicht mehr angeboten, alter Core-Pfad), Verdrahtung, Ende zu Ende (Antwort, Denken, Tokens, abgeschnitten, Dateivorschau, Strom, "
  . "abgelehnter Schluessel, lokales Konto) OK\n";
