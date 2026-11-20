<?php
declare(strict_types=1);
// CoreAI 0.8.11 (CF-003, CF-005): the external OpenAI-compatible adapter.
//   php scripts/external-provider-selftest.php
// Offline: addresses, model names, the operator's configuration and who may use it, the request
// (messages, images, only OpenAI options, no think, keep_alive or num_ctx), the answer and the
// event stream back in Ollama's shape (content, reasoning, finish reasons, usage, errors, a cut
// stream), keys hidden in provider messages, the connection test, the Ollama line unchanged, and
// the wiring. End to end against a small OpenAI-compatible stand-in server on 127.0.0.1: the real
// ember_call_ollama() and console_stream_ollama() send the same prompt to the external API with the
// key in the header only, read the answer, its reasoning and token counts, notice a cut answer,
// stream a file preview, refuse a wrong key without logging it, and still reach local Ollama for an
// account without the external API. Test data uses example.test only.
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
$full = ['external_api_enabled'=>'1', 'external_api_label'=>'Testanbieter', 'external_api_base_url'=>$base, 'external_api_model'=>'stub-model'];
$cap = coreui_provider_capability($full, true);
ext_test($cap['configured'] && $cap['status'] === 'ready' && $cap['missing'] === [] && $cap['base_url'] === $base
  && $cap['model'] === 'stub-model' && $cap['label'] === 'Testanbieter', 'a complete configuration is ready: ' . json_encode($cap));
ext_test(!coreui_provider_capability(['external_api_enabled'=>'0'] + $full, true)['configured']
  && coreui_provider_capability(['external_api_enabled'=>'0'] + $full, true)['status'] === 'disabled', 'switched off');
$incomplete = coreui_provider_capability(['external_api_base_url'=>'http://api.example.test/v1', 'external_api_model'=>'a b'] + $full, false);
ext_test(!$incomplete['configured'] && $incomplete['status'] === 'incomplete'
  && $incomplete['missing'] === ['base_url_invalid', 'model_invalid', 'key'], 'what is missing: ' . json_encode($incomplete['missing']));
ext_test(coreui_provider_account_external($cap, 'external', 0, false) === ['label'=>'Testanbieter', 'base_url'=>$base, 'model'=>'stub-model']
  && coreui_provider_account_external($cap, 'external', 1, false) !== null
  && coreui_provider_account_external($cap, 'external', 4, false) === null
  && coreui_provider_account_external($cap, 'external', 0, true) === null
  && coreui_provider_account_external($cap, 'external', -1, false) === null
  && coreui_provider_account_external($cap, 'local', 0, false) === null
  && coreui_provider_account_external($incomplete, 'external', 0, false) === null, 'only the Inhaber and Admins, never guests, only when configured');

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

// --- The connection test (pure transport) ---------------------------------------------------------------------
$seen = [];
$fake = static function (array $answer) use (&$seen): callable {
  return static function (string $url, array $headers) use ($answer, &$seen): array { $seen = [$url, $headers]; return $answer; };
};
$r = coreui_provider_test($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"stub-model"}]}', 'ms'=>12]));
ext_test($r['ok'] && $r['reason'] === 'model_listed' && $r['models_count'] === 1 && $seen[0] === $base . '/models'
  && in_array('Authorization: Bearer ' . EXT_KEY, $seen[1], true), 'test: model listed');
ext_test(coreui_provider_test($cap, EXT_KEY, $fake(['code'=>200, 'body'=>'{"data":[{"id":"x"}]}']))['reason'] === 'model_not_listed'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>404, 'body'=>'']))['ok'] === true
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>0, 'body'=>'', 'error'=>'curl_6']))['reason'] === 'unreachable'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>429, 'body'=>'{"error":"slow"}']))['reason'] === 'rate_limited'
  && coreui_provider_test($cap, EXT_KEY, $fake(['code'=>500, 'body'=>'{}']))['reason'] === 'http_error', 'test: other answers');
$refused = coreui_provider_test($cap, EXT_KEY, $fake(['code'=>401, 'body'=>'{"error":{"message":"bad key ' . EXT_KEY . '"}}']));
ext_test(!$refused['ok'] && $refused['reason'] === 'key_refused' && !str_contains((string)json_encode($refused), EXT_KEY), 'test: refused key, hidden');
ext_test(coreui_provider_test($incomplete, EXT_KEY)['reason'] === 'not_configured', 'test: not configured, nothing sent');
$real = coreui_provider_test($cap, EXT_KEY);
ext_test($real['ok'] && $real['reason'] === 'model_listed' && $real['models_count'] === 2, 'test against the stand-in: ' . json_encode($real));
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
ext_test(ember_call_ollama('gemma4:e4b', '', 'Hallo', 30, ['__skills'=>'none']) === 'lokal ok', 'local answer');
$sent = $requests();
ext_test(count($sent) === 1 && $sent[0]['uri'] === '/api/chat' && $sent[0]['auth'] === '' && $sent[0]['body']['model'] === 'gemma4:e4b'
  && array_key_exists('think', $sent[0]['body']) && $GLOBALS['STU_EMBER_LAST_CALL']['provider'] === 'ollama', 'local Ollama, no key: ' . json_encode($sent));
// A provider in the runtime settings without the server key, or with a bad address, is never used.
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['provider'=>'external', 'external'=>['label'=>'T', 'base_url'=>'http://10.0.0.5/v1', 'model'=>'m']];
ext_test(coreui_provider_active() === null, 'a bad address is never called');

// --- Wiring (static) -------------------------------------------------------------------------------------------------
$chat = (string)file_get_contents($root . '/api/chat.php');
$settings = (string)file_get_contents($root . '/api/ai_settings.php');
$userSettings = (string)file_get_contents($root . '/api/user_settings.php');
$admin = (string)file_get_contents($root . '/api/admin.php');
ext_test(str_contains($chat, '$wire = coreui_provider_wire(coreui_provider_active(), $url, $payload, $model);')
  && str_contains($streamSource, '$wire = coreui_provider_wire(coreui_provider_active(), $url, $payload, $model);')
  && str_contains($streamSource, "coreui_work_record(\$workDb, 'model', 'running', 'Modellstream gestartet', ['model'=>\$wire['model']]);")
  && substr_count($chat, 'ember_global_lock_acquire()') >= 1, 'both model calls use the line, the work view names the external Core');
ext_test(str_contains($settings, "\$settings['external'] = coreui_provider_account_external(coreui_ai_external_capability(\$pdo), 'external',")
  && str_contains($settings, "coreui_ai_account_level(\$pdo, \$uid), coreui_ai_account_is_guest(\$pdo, \$uid));")
  && str_contains($settings, "if (\$settings['external'] === null) \$settings['provider'] = 'local';"), 'each turn checks the account');
ext_test(str_contains($userSettings, "stu_json(['ok'=>false, 'error'=>'external_not_allowed'], 403);")
  && str_contains($admin, "if (\$action === 'external_api_test') {") && str_contains($admin, '$value = coreui_provider_base_url($value);'),
  'Settings refuse it for other accounts; Admin Core tests the connection and checks the address');
$adminJs = (string)file_get_contents($root . '/js/admin.js');
$settingsJs = (string)file_get_contents($root . '/js/settings.js');
ext_test(str_contains($adminJs, "post('external_api_test', {})") && str_contains($adminJs, "document.getElementById('externalTest').addEventListener('click', testExternal);")
  && str_contains($settingsJs, 'var externalUsable = externalConfigured && external.allowed === true;'), 'the pages');

echo "CoreAI externer Adapter: Adressen, Namen, Einrichtung, nur Inhaber und Admins, Anfrage, Antwort, Strom, Fehler ohne Schluessel, "
  . "Verbindungstest, Ollama unveraendert, Verdrahtung, Ende zu Ende (Antwort, Denken, Tokens, abgeschnitten, Dateivorschau, Strom, "
  . "abgelehnter Schluessel, lokales Konto) OK\n";
