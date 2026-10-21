<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

// No live model, config.local.php, database or Ollama required. This test
// executes the exact options-builder source with controlled test doubles.
function gemma_test_assert(bool $value, string $message): void {
  if (!$value) throw new RuntimeException($message);
}
function ember_cfg(string $name, $default) {
  return $name === 'STU_EMBER_UNVERIFIED_TUNING_ENABLED' ? true : $default;
}
function ember_temperature(): float { return 1.0; }
function ember_top_p(): float { return 0.95; }
function ember_top_k(): int { return 64; }
function ember_num_ctx_for_model(string $model): int { return 32768; }
try {
  $root = dirname(__DIR__);
  $template = file_get_contents($root . '/config/Modelfile.template');
  gemma_test_assert(is_string($template) && str_contains($template,'FROM {{BASE_MODEL}}'),
    'Missing model template/base token.');
  gemma_test_assert(!preg_match('/^\s*PARAMETER\s+num_ctx\b/im',$template),
    'Gemma template still pins num_ctx.');
  foreach ([
    'temperature'=>'1.0','top_p'=>'0.95','top_k'=>'64',
    'repeat_penalty'=>'1.08'
  ] as $name=>$value) {
    gemma_test_assert(preg_match('/^PARAMETER ' . preg_quote($name,'/') . '\s+'
      . preg_quote($value,'/') . '$/m',$template)===1,
      'Existing Gemma model parameter changed: ' . $name);
  }
  $generated = str_replace('{{BASE_MODEL}}','gemma4:e4b',$template);
  gemma_test_assert(str_starts_with($generated, "FROM gemma4:e4b\n"),
    'Generated template has an invalid base model.');
  gemma_test_assert(!preg_match('/^\s*PARAMETER\s+num_ctx\b/im',$generated),
    'Generated Gemma Modelfile reintroduces num_ctx.');

  foreach (['scripts/install.sh','scripts/install-native.sh'] as $script) {
    $installer = file_get_contents($root . '/' . $script);
    gemma_test_assert(is_string($installer)
      && str_contains($installer,'config/Modelfile.template')
      && str_contains($installer,'ollama create')
      && str_contains($installer,"PARAMETER[[:space:]]+num_ctx"),
      'Installer is missing the template or the num_ctx guard: ' . $script);
    gemma_test_assert(!str_contains($installer,"define('STU_EMBER_NUM_CTX',"),
      'Fresh installer still forces Gemma num_ctx: ' . $script);
  }

  $source = file_get_contents($root . '/api/chat.php');
  gemma_test_assert(is_string($source),'chat.php missing.');
  $localBegin = strpos($source,'function ember_console_file_tool_is_local(');
  $localEnd = strpos($source,'function ember_console_file_tool_think(', (int)$localBegin);
  gemma_test_assert($localBegin !== false && $localEnd !== false
    && $localEnd > $localBegin, 'Gemma alias detection helper is missing.');
  eval(substr($source,$localBegin,$localEnd-$localBegin));

  $begin = strpos($source,'function ember_ollama_known_good_options(');
  $end = strpos($source,'function ember_call_ollama(', (int)$begin);
  gemma_test_assert($begin !== false && $end !== false && $end > $begin,
    'Ollama option builder could not be isolated.');
  // The function is from this trusted local source and is executed with only
  // the harmless test constants and stubbed parameter helpers above.
  eval(substr($source,$begin,$end-$begin));
  define('STU_EMBER_NUM_CTX',32768);
  foreach (['gemma4:e4b','Gemma4:27b','ember-coreui:latest','ember-coreui'] as $name) {
    $options = ember_ollama_known_good_options($name,['num_ctx'=>65536,'top_k'=>17]);
    gemma_test_assert(!array_key_exists('num_ctx',$options),
      'Gemma request still transmits num_ctx: ' . $name);
    gemma_test_assert(($options['top_k'] ?? null)===17
      && ($options['temperature'] ?? null)===1.0
      && ($options['top_p'] ?? null)===0.95,
      'Existing request tuning was changed: ' . $name);
  }
  $other = ember_ollama_known_good_options('llama3:8b',['num_ctx'=>24576]);
  gemma_test_assert(($other['num_ctx'] ?? null)===24576,
    'Explicit non-Gemma experimental context was unexpectedly removed.');
  echo "Gemma-Modelfile + Ollama context override selftest OK.\n";
} catch (Throwable $error) {
  fwrite(STDERR,"Gemma-Modelfile selftest FAILED: " . $error->getMessage() . "\n");
  exit(1);
}
