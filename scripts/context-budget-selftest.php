<?php
declare(strict_types=1);
// CoreAI 0.5.67: local Ollama context window, per-file prompt budget and
// plan excerpts. Offline: no Ollama, no database, no Modelfile change.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_data_link_bridge.php';

function context_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Context budget FAIL: {$why}\n"); exit(1); }
}

$cacheDir = sys_get_temp_dir() . '/coreai-context-selftest-' . bin2hex(random_bytes(4));
@mkdir($cacheDir, 0700, true);
function ember_cfg(string $key, mixed $default = null): mixed {
  global $cacheDir;
  return match ($key) {
    'STU_EMBER_CACHE_DIR' => $cacheDir,
    // Nothing listens here: /api/ps is unavailable, like an unloaded model.
    'STU_EMBER_OLLAMA_URL' => 'http://127.0.0.1:9/api/chat',
    default => $default,
  };
}

// Load only the pure production helpers from chat.php.
$chat = (string)file_get_contents($root . '/api/chat.php');
$wanted = ['ember_url','ember_cache_dir','ember_http_get_json','ember_model_is_cloud',
  'ember_ollama_context_cache_file','ember_ollama_context_store','ember_ollama_context_observe',
  'ember_ollama_ps_context','ember_ollama_context_window'];
$tokens = token_get_all($chat);
for ($i = 0; $i < count($tokens); $i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
  $j = $i + 1;
  while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
  if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], $wanted, true)) continue;
  $code = ''; $depth = 0; $opened = false;
  for (; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $code .= is_array($token) ? $token[1] : $token;
    if ($token === '{') { $depth++; $opened = true; }
    if ($token === '}') $depth--;
    if ($opened && $depth === 0) break;
  }
  eval($code);
}
foreach ($wanted as $fn) context_test(function_exists($fn), 'missing helper ' . $fn);

// 1. Screenshot values: 3250 prompt + 846 output = 4096 with done_reason=length.
context_test(ember_ollama_context_observe('ember-coreui:latest', 'length', 3250, 846, 0) === 4096,
  'a full 4096 window must be recognized');
context_test(ember_ollama_context_window('ember-coreui:latest') === 4096, 'learned window not used');
unset($GLOBALS['COREUI_OLLAMA_CONTEXT_MEMO']);
context_test(ember_ollama_context_window('ember-coreui:latest') === 4096, 'learned window not persisted');
context_test(ember_ollama_context_observe('gemma4:e4b', 'length', 900, 2048, 2048) === null,
  'an explicit num_predict cap is not the context window');
context_test(ember_ollama_context_observe('gemma4:e4b', 'stop', 3250, 846, 0) === null,
  'a normal stop must not be learned');
context_test(ember_ollama_context_observe('gemma4:e4b', 'length', 3251, 846, 0) === null,
  'odd totals are not treated as a window size');
context_test(ember_ollama_context_observe('gpt-oss:120b-cloud', 'length', 3250, 846, 0) === null
  && ember_ollama_context_window('gpt-oss:120b-cloud') === null,
  'cloud models are never limited by the local window');
unset($GLOBALS['COREUI_OLLAMA_CONTEXT_MEMO']);
context_test(ember_ollama_context_window('qwen3:8b') === 4096,
  'an unknown local model must assume Ollama\'s CPU default');
// A larger window once reported by /api/ps survives the model being unloaded.
ember_ollama_context_store('gemma4:e4b', 65536);
unset($GLOBALS['COREUI_OLLAMA_CONTEXT_MEMO']);
context_test(ember_ollama_context_window('gemma4:e4b') === 65536,
  'a stored /api/ps window must be reused while the model is unloaded');

// 2. Budget: generous for cloud, output room first for a small local window.
context_test(coreui_console_source_prompt_budget(null) === ['plan'=>9000,'refs'=>7200,'compact_refs'=>false],
  'cloud/unknown budget must stay unchanged');
$small = coreui_console_source_prompt_budget(4096);
context_test($small['plan'] < 3000 && $small['refs'] >= 600 && $small['compact_refs'] === true,
  'a 4096 window must keep the plan excerpt small');
context_test(coreui_console_source_prompt_budget(32768)['plan'] === 9000,
  'a large window may use the full previous excerpt');

// 3. Plan excerpt keeps the sections this file needs, in plan order.
$plan = "# Anime-Welt Homepage\nPrivate Fan-Seite.\n\n## Design und Farben\n"
  . str_repeat("- Neon-Violett, Schrift Rajdhani.\n", 10)
  . "\n## Navigation\n" . str_repeat("- Kopfzeile mit allen Seiten.\n", 6)
  . "\n## Ueber mich (about.html)\n" . str_repeat("- Steckbrief und Hobbys.\n", 30)
  . "\n## Galerie (gallery.html)\n" . str_repeat("- Bildkarten mit Lightbox.\n", 30)
  . "\n## Kontakt (contact.html)\n" . str_repeat("- Formular ohne Backend.\n", 30);
$excerpt = coreui_console_plan_excerpt($plan, 'gallery.html', 1800);
context_test(str_contains($excerpt, '## Galerie (gallery.html)') && str_contains($excerpt, '## Design und Farben')
  && str_contains($excerpt, '## Navigation') && !str_contains($excerpt, '## Kontakt (contact.html)')
  && strpos($excerpt, '## Design und Farben') < strpos($excerpt, '## Galerie (gallery.html)')
  && str_contains($excerpt, '[PLANAUSZUG fuer gallery.html:'),
  'the gallery excerpt must contain its page, design and navigation in plan order');
context_test(coreui_console_plan_excerpt($plan, 'gallery.html', 100000) === trim($plan),
  'a plan that fits must be passed unchanged');

// 4. A previously generated page is summarized as its interface.
$index = '<!DOCTYPE html><html><head><title>Aether</title><link rel="stylesheet" href="style.css"></head>'
  . '<body><nav class="main-nav"><a href="index.html">Start</a><a href="about.html">Ueber</a></nav>'
  . '<section id="hero" class="hero glow"></section><script src="main.js"></script></body></html>';
$contract = coreui_console_dom_contract('index.html', $index, 600);
context_test(str_contains($contract, 'IDs: #hero') && str_contains($contract, '.main-nav')
  && str_contains($contract, 'about.html "Ueber"') && str_contains($contract, 'style.css'),
  'DOM contract must keep IDs, classes, links and assets');

// 5. End to end: the focused prompt for one file fits a 4096 window.
$files = ['index.html','about.html','gallery.html','contact.html','style.css','main.js'];
$request = 'Erstelle die folgenden vollstaendigen Projektdateien und stelle ein ZIP zum Download bereit: '
  . implode(', ', $files) . "\nErstelle projekt.zip. Keine Platzhalter und keine erfundenen Dateilinks.\n"
  . "[COREUI_PLANUNGSKONTEXT]\nAusfuehrungsauftrag vom Modell, als Projektanforderungen behandeln:\n"
  . "Setze den gespeicherten Plan um.\nDer folgende persistierte Plan ist verbindlicher Projektkontext ("
  . strlen($plan) . " Bytes, Version 3):\n" . $plan . "\n[COREUI_ENDE_PLANUNGSKONTEXT]\n";
$existing = ['index.html'=>['name'=>'index.html','bytes'=>$index . str_repeat('<!-- x -->', 500)]];
$localPrompt = coreui_console_local_source_prompt($request, 'gallery.html', $existing, false, 4096);
$cloudPrompt = coreui_console_local_source_prompt($request, 'gallery.html', $existing, false, null);
$legacyPrompt = coreui_console_local_source_prompt($request, 'gallery.html', $existing, false);
// Modelfile SYSTEM (~1500 chars) + prompt must leave >= 2400 tokens of a 4096 window.
context_test(strlen($localPrompt) + 1600 < (4096 - 2400) * 3.6,
  'the 4096-window file prompt is still too large: ' . strlen($localPrompt));
context_test(str_contains($localPrompt, 'gallery.html') && str_contains($localPrompt, 'Schnittstelle')
  && !str_contains($localPrompt, '<!-- x -->'),
  'the local prompt must use the excerpt and the compact interface');
context_test($cloudPrompt === $legacyPrompt && str_contains($cloudPrompt, '## Kontakt (contact.html)'),
  'cloud models must keep the previous full prompt');

array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);
echo "CoreAI context window, file prompt budget and plan excerpt: OK\n";
