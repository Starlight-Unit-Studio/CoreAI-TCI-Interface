<?php
declare(strict_types=1);
// CoreAI 0.6.89: offline security and parser checks for the official Ollama
// model browser. No network, no real downloads and no database modifications.
if (PHP_SAPI !== 'cli') exit(1);
$cache = sys_get_temp_dir() . '/coreui-ollama-lib-' . getmypid();
@mkdir($cache, 0700, true);
define('STU_EMBER_CACHE_DIR', $cache);
require_once dirname(__DIR__) . '/api/ollama_library.php';

function library_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, 'Ollama library FAIL: ' . $message . "\n"); exit(1); }
}
$search = <<<'HTML'
<html><body>
  <a href="/library/qwen3.5"><h2>qwen3.5</h2><p>Vision, tools, 9b, 27b, cloud</p></a>
  <a href="/library/glm-5.3"><h2>glm-5.3</h2><p>Cloud only, remote access</p></a>
  <a href="/library/gemma4"><h2>gemma4</h2><p>Local 12b 31b and cloud</p></a>
  <a href="/some_user/LoRA-test"><h2>LoRA-test</h2>Custom local model, 8b</a>
  <a href="https://evil.test/library/trap"><h2>trap</h2>1b</a>
  <a href="/blog">Blog</a>
  <a href="/library/qwen3.5"><h2>Duplicate</h2></a>
</body></html>
HTML;
$found = coreui_library_search_parse($search);
library_test(array_column($found, 'name') === ['qwen3.5', 'gemma4', 'some_user/LoRA-test'],
  'only valid, deduplicated local model cards from the official origin');
library_test($found[0]['url'] === 'https://ollama.com/library/qwen3.5'
  && $found[2]['url'] === 'https://ollama.com/some_user/LoRA-test',
  'model URLs always use Ollama origin');

$detail = <<<'HTML'
<html><head><meta name="description" content="Local Qwen family with vision"></head><body>
  <a href="/library/qwen3.5:latest">qwen3.5:latest 6.6 GB, text and image</a>
  <a href="/library/qwen3.5:9b">qwen3.5:9b 7.6GB, 256K context</a>
  <a href="/library/qwen3.5:cloud">qwen3.5:cloud remote 1M</a>
  <a href="/library/qwen3.5:1b">qwen3.5:1b Cloud only</a>
  <a href="https://evil.test/library/qwen3.5:8b">qwen3.5:8b 4GB</a>
  <a href="/library/another:3b">another:3b 2GB</a>
</body></html>
HTML;
$parsed = coreui_library_detail_parse($detail, 'qwen3.5');
library_test($parsed['name'] === 'qwen3.5' && $parsed['description'] === 'Local Qwen family with vision',
  'local model page summary');
library_test(array_column($parsed['tags'], 'name') === ['qwen3.5:latest', 'qwen3.5:9b'],
  'size-confirmed local tags only; cloud, foreign hosts and unrelated models refused');
library_test(coreui_library_install_allowed('qwen3.5:9b', $parsed)
  && !coreui_library_install_allowed('qwen3.5:cloud', $parsed)
  && !coreui_library_install_allowed('qwen3.5:1b', $parsed)
  && !coreui_library_install_allowed('ember-core:9b', ['tags'=>[['name'=>'ember-core:9b']]]),
  'only confirmed local registry tags, never CoreAI-owned model names');
library_test(!coreui_library_name_valid('../evil') && !coreui_library_name_valid('https://evil')
  && !coreui_library_tag_valid('cloud') && !coreui_library_tag_valid('my-cloud-2'),
  'invalid names and cloud variants cannot be installed');
try {
  coreui_library_fetch('http://127.0.0.1:8080/search');
  library_test(false, 'arbitrary address allowed');
} catch (RuntimeException $e) {
  library_test($e->getMessage() === 'invalid_registry_url', 'remote host or HTTP rejected before any request');
}
$job = coreui_library_begin('qwen3.5:9b');
$stored = json_decode((string)file_get_contents($cache . '/core-install.json'), true);
library_test($job['kind'] === 'library' && $stored['tier'] === '' && $stored['base'] === 'qwen3.5:9b'
  && $stored['core'] === 'qwen3.5:9b' && $stored['phase'] === 'pull',
  'a new job uses the existing shared install state without creating a TCI tier');
@unlink($cache . '/core-install.json');
@rmdir($cache);
echo "CoreAI Ollama registry: official-origin search parsing, local tags, install allowlist, SSRF guard and shared job state OK\n";
