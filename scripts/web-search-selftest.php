<?php
declare(strict_types=1);
// CoreAI 0.5.77: Maras Websuche gehaertet. Offline with a scripted SearXNG:
// a question that finds nothing is searched again with its key words, the
// same page with tracking parameters counts once, snippets lose HTML and
// entities, only http(s) links survive, and Mara sees the date of a hit.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$chat = (string)file_get_contents($root . '/api/chat.php');

$calls = [];
$answers = [];
function ember_cfg(string $key, $default = null) { return $default; }
function ember_web_enabled(): bool { return true; }
function ember_http_get_json(string $url, int $timeout = 6): ?array {
  global $calls, $answers;
  parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
  $calls[] = $q['q'] ?? '';
  return array_shift($answers);
}
foreach (['ember_web_url', 'ember_web_limit', 'ember_web_timeout', 'ember_web_max_chars', 'ember_web_snippet_chars',
  'ember_web_query_variants', 'ember_web_url_key', 'ember_web_clean_rows', 'ember_web_search', 'ember_web_results_block'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $chat, $m) !== 1) { fwrite(STDERR, "missing {$fn}\n"); exit(1); }
  eval($m[0]);
}

function web_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Web search FAIL: {$why}\n"); exit(1); }
}

// Key words of a question.
web_test(ember_web_query_variants('Wie ist das Wetter morgen in Mönchengladbach?')
  === ['Wie ist das Wetter morgen in Mönchengladbach?', 'Wetter morgen Mönchengladbach'], 'question to key words');
web_test(ember_web_query_variants('gemma4 12b') === ['gemma4 12b'], 'key words stay as they are');
web_test(ember_web_query_variants('"Gemma 4" Größe') === ['"Gemma 4" Größe', 'Gemma 4 Größe'], 'numbers stay');
web_test(ember_web_query_variants('  ') === [], 'empty');
web_test(count(explode(' ', ember_web_query_variants(str_repeat('Wort ', 3) . 'eins zwei drei vier fuenf sechs sieben acht neun')[1])) === 8,
  'at most eight key words');

// The same page counts once.
web_test(ember_web_url_key('https://www.Example.org/a/?utm_source=x&b=2&a=1#top') === 'example.org/a?a=1&b=2', 'url key');
$rows = ember_web_clean_rows([
  ['title'=>'Gemma &amp; Co', 'url'=>'https://ollama.com/library/gemma4?utm_source=searx', 'content'=>'<b>12b</b>  braucht&nbsp;8 GB',
   'publishedDate'=>'2026-10-20T08:00:00'],
  ['title'=>'Doppelt', 'url'=>'https://www.ollama.com/library/gemma4/', 'content'=>'gleiches Ziel'],
  ['title'=>'Skript', 'url'=>'javascript:alert(1)', 'content'=>'x'],
  ['title'=>'', 'url'=>'https://leer.example/', 'content'=>''],
  'kaputt',
  ['title'=>'Zweite Seite', 'url'=>'https://blog.example/gemma', 'content'=>'Test', 'publishedDate'=>'kein Datum'],
], 5);
web_test(count($rows) === 2, 'duplicates, scripts and empty rows removed');
web_test($rows[0] === ['title'=>'Gemma & Co', 'url'=>'https://ollama.com/library/gemma4?utm_source=searx', 'content'=>'12b braucht 8 GB',
  'date'=>'2026-10-20'], 'snippet without HTML, with date');
web_test(!str_contains($rows[0]['content'], '<') && !str_contains($rows[0]['title'], '&amp;'), 'no tags or entities');
web_test($rows[1]['date'] === '', 'unknown date stays empty');
web_test(count(ember_web_clean_rows(array_fill(0, 9, ['title'=>'x', 'url'=>'', 'content'=>'y']), 5)) === 1, 'same title without link once');

// A question that finds nothing is searched again with its key words.
$answers = [['results'=>[]], ['results'=>[['title'=>'Wetter Mönchengladbach', 'url'=>'https://wetter.example/mg', 'content'=>'12 Grad']]]];
$calls = [];
$found = ember_web_search('Wie ist das Wetter morgen in Mönchengladbach?', 4, 5);
web_test($calls === ['Wie ist das Wetter morgen in Mönchengladbach?', 'Wetter morgen Mönchengladbach'] && count($found) === 1,
  'second try with key words');
$answers = [['results'=>[['title'=>'T', 'url'=>'https://a.example/', 'content'=>'c']]]];
$calls = [];
ember_web_search('Wie ist das Wetter?', 4, 5);
web_test(count($calls) === 1, 'no second search when the first finds something');
$answers = [null, null];
$calls = [];
web_test(ember_web_search('Was ist Nebelfalke?', 4, 5) === [] && count($calls) === 2, 'nothing anywhere: empty');

// Mara sees the date of a hit.
$answers = [['results'=>[['title'=>'Ollama 0.13', 'url'=>'https://ollama.com/blog', 'content'=>'neu', 'publishedDate'=>'2026-10-27']]]];
$block = ember_web_results_block('ollama neu');
web_test(str_starts_with($block, '1. Ollama 0.13 (vom 2026-10-27) [https://ollama.com/blog]'), 'date in the results block: ' . $block);

echo "CoreAI web search: key words retry, no duplicates, clean snippets, dates OK\n";
