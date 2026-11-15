<?php
declare(strict_types=1);
// Offline SearXNG regression: progressive batches must not skip or repeat
// results when SearXNG's own page size exceeds CoreAI's configured limit.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$chat = (string)file_get_contents($root . '/api/chat.php');
require_once $root . '/api/console_web_research.php';

function ember_cfg(string $key, $fallback = null) { return $fallback; }
function ember_web_enabled(): bool { return true; }
function ember_weather_live_sources(string $query): array { return []; }
$calls = [];
$answers = [];
function ember_http_get_json(string $url, int $timeout = 6): ?array {
  global $calls, $answers;
  parse_str((string)parse_url($url, PHP_URL_QUERY), $args);
  $calls[] = ['q'=>$args['q'] ?? '', 'page'=>(int)($args['pageno'] ?? 0),
    'safe'=>(string)($args['safesearch'] ?? '')];
  $query = (string)($args['q'] ?? '');
  $page = (int)($args['pageno'] ?? 0);
  return $answers[$query][$page] ?? ['results'=>[]];
}
foreach (['ember_web_url','ember_web_limit','ember_web_timeout','ember_web_max_chars',
  'ember_web_snippet_chars','ember_web_query_variants','ember_web_url_key',
  'ember_web_clean_rows','ember_web_search','ember_web_results_block'] as $fn) {
  if (preg_match('~^function ' . $fn . '\\(.*?^}~ms', $chat, $m) !== 1) {
    fwrite(STDERR, "missing {$fn}\n"); exit(1);
  }
  eval($m[0]);
}
function test(bool $ok, string $reason): void {
  if (!$ok) { fwrite(STDERR, "Progressive Websuche FAIL: {$reason}\n"); exit(1); }
}
function hit(int $n): array {
  return ['title'=>"Quelle {$n}", 'url'=>"https://example.org/a{$n}",
    'content'=>"Belegbarer Auszug {$n}"];
}
$answers['Gemma 4 Vergleich'][1] = ['results'=>array_map('hit',range(1,10))];
$answers['Gemma 4 Vergleich'][2] = ['results'=>array_merge([hit(1)],
  array_map('hit',range(11,16)))];
$answers['Gemma 4 Vergleich'][3] = ['results'=>[]];
$state = [];
$a = coreui_web_research_next('Gemma 4 Vergleich',$state,4,12);
test(array_column($a,'title') === ['Quelle 1','Quelle 2','Quelle 3','Quelle 4'],
  'first chunk delivers four actual sources');
test($calls === [['q'=>'Gemma 4 Vergleich','page'=>1,'safe'=>'1']],
  'first chunk fetches one SearXNG page');
$b = coreui_web_research_next('WEITER',$state,4,12);
test(array_column($b,'title') === ['Quelle 5','Quelle 6','Quelle 7','Quelle 8'],
  'WEITER drains existing page without a new HTTP call');
test(count($calls) === 1, 'buffered sources cost no network call');
$c = coreui_web_research_next('Gemma 4 Vergleich',$state,4,12);
test(array_column($c,'title') === ['Quelle 9','Quelle 10'],
  'end of first SearXNG page is not skipped or padded');
test(count($calls) === 1, 'no premature fetch of page two');
$d = coreui_web_research_next('WEITER',$state,4,12);
test(array_column($d,'title') === ['Quelle 11','Quelle 12','Quelle 13','Quelle 14'],
  'page two starts at eleven, duplicate source is filtered');
test(count($calls) === 2 && $calls[1]['page'] === 2,
  'pageno increments only after the buffered page is consumed');
$e = coreui_web_research_next('WEITER',$state,4,12);
test(array_column($e,'title') === ['Quelle 15','Quelle 16'],
  'second page remainder is delivered, without another fetch');
$f = coreui_web_research_next('WEITER',$state,4,12);
test($f === [] && count($calls) === 3 && $calls[2]['page'] === 3,
  'empty last page stops further requests');
coreui_web_research_next('WEITER',$state,4,12);
test(count($calls) === 3, 'exhausted query stays exhausted');
$answers['andere Suche'][1] = ['results'=>[hit(2),hit(30)]];
$other = coreui_web_research_next('andere Suche',$state,4,12);
test(array_column($other,'title') === ['Quelle 30'],
  'other queries reuse the source ledger, no duplicates');
test(count($state['seen']) === 17, 'ledger records 16 pages plus one fresh source');
$fresh = [];
test(count(coreui_web_research_next('Gemma 4 Vergleich',$fresh,4,12)) === 4,
  'new chat response gets a fresh source ledger');
$noPrevious = [];
test(coreui_web_research_next('WEITER',$noPrevious,4,12) === [], 'WEITER without context does not invent a search');
$answers['Wer ist Nebelfalke?'][1] = ['results'=>[]];
$answers['Nebelfalke'][1] = ['results'=>[hit(99)]];
$retry = [];
$start = count($calls);
$found = coreui_web_research_next('Wer ist Nebelfalke?',$retry,4,12);
test(array_column($found,'title') === ['Quelle 99'], 'question has keywords fallback');
test(count($calls) === $start + 2 && $calls[$start]['q'] === 'Wer ist Nebelfalke?'
  && $calls[$start+1]['q'] === 'Nebelfalke',
  'only first page uses the established question fallback');

$answers['Quellensuche'][1] = ['results'=>[
  ['title'=>'Wichtiger Treffer','url'=>'https://example.org/news?utm_source=test',
   'content'=>'<b>Untrusted</b> Text'],
  ['title'=>'Skript','url'=>'javascript:alert(1)','content'=>'x']
]];
$web = [];
$seen = null;
$text = ember_web_results_block('Quellensuche',
  static function(array $rows) use (&$seen): void { $seen = $rows; }, $web);
test(str_starts_with($text,'1. Wichtiger Treffer [https://example.org/news?utm_source=test]')
  && str_contains($text,'Untrusted Text') && !str_contains($text,'<b>'),
  'progressive results reuse the existing sanitized snippet formatting');
test(count($seen) === 1 && $seen[0]['title'] === 'Wichtiger Treffer'
  && $seen[0]['url'] === 'https://example.org/news?utm_source=test',
  'only real safe hits reach SSE source events');
$none = null;
ember_web_results_block('WEITER',static function(array $rows) use (&$none): void { $none = $rows; },$web);
test($none === [], 'no fabricated hits when next SearXNG page is empty');
echo "CoreAI progressive Websuche: chunks, no skip, pagination, dedup, fallback and SSE OK\n";
