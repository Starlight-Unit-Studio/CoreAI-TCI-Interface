<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
  $data = file_get_contents($root . '/' . $path);
  if (!is_string($data) || $data === '') throw new RuntimeException('Missing: '.$path);
  return $data;
};
$check = static function (bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException('Source preview regression: '.$message);
};
$chat = $read('api/chat.php');
$stream = $read('api/console_stream.php');
$app = $read('js/console-app.js');
$work = $read('js/coreui-work-events.js');
$start = strpos($chat, 'function ember_call_ollama(');
$end = strpos($chat, 'function ember_last_call_was_truncated(', (int)$start);
$check($start !== false && $end !== false && $end > $start, 'Ollama source boundaries');
$ollama = substr($chat, $start, $end - $start);
$http = substr($chat, (int)strpos($chat,'function ember_http_get_json('), 1600);
$check(!str_contains($http, '$sourcePreview'), 'preview must not modify generic HTTP client');
$check(str_contains($ollama, '$previewActive') && str_contains($ollama, "$payload['stream'] = true"),
  'stream opt-in within Ollama provider call');
$check(str_contains($ollama, 'CURLOPT_WRITEFUNCTION'), 'real streaming transport');
$expectedLineSeparator = <<<'PHP'
strpos($previewBuffer,"\n")
PHP;
$check(str_contains($ollama, $expectedLineSeparator),
  'NDJSON must split on newline, not on literal backslash + n');
$delta = "<p>Gruesse aeo u und 🎮</p>\n";
preg_match_all('~.{1,700}~us', $delta, $parts);
$check(implode('', $parts[0]) === $delta, 'UTF-8/codepoint preservation');
$check(str_contains($ollama, "preg_match_all('~.{1,700}~us'"), 'UTF-8-safe SSE chunking');
$check(str_contains($stream, "sse_send('source_preview'"), 'SSE preview channel');
$check(str_contains($app, "addEventListener('source_preview'"), 'client SSE subscription');
$check(str_contains($work, 'function source(body, raw)') &&
    str_contains($work, 'code.textContent += raw.text'),
  'plain text, untruncated source rendering');
$check(!str_contains($work, 'Automatisch erläutert') &&
    !str_contains($work, 'Öffentliche Zwischenmeldung'),
  'no redundant user-facing provenance labels');
echo "CoreAI 0.5.62 live-source preview static regression: OK\n";
