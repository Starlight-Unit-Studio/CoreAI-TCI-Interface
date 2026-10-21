<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';

function coreui_gemma_assert(bool $condition, string $error): void {
  if (!$condition) {
    fwrite(STDERR, 'Gemma parameter policy: ' . $error . "\n");
    exit(1);
  }
}

$root = dirname(__DIR__);
$template = (string)file_get_contents($root . '/config/Modelfile.template');
coreui_gemma_assert(str_contains($template, 'FROM {{BASE_MODEL}}'),
  'missing FROM base model');
coreui_gemma_assert(str_contains($template, 'SYSTEM """'),
  'must retain Ember system instructions');
// The operator explicitly approved these four lines. They must survive
// installation; only the Gemma context directive is forbidden.
$expectedParameters = [
  'PARAMETER temperature 1.0',
  'PARAMETER top_p 0.95',
  'PARAMETER top_k 64',
  'PARAMETER repeat_penalty 1.08',
];
preg_match_all('/^[ \t]*PARAMETER[ \t]+[^\r\n]+/im',$template,$matches);
$actualParameters = array_map('trim',$matches[0] ?? []);
coreui_gemma_assert($actualParameters === $expectedParameters,
  'Gemma template must retain exactly four approved settings, no num_ctx');

foreach (['ember-coreui:latest','ember-coreui','gemma4:e4b','gemma4:latest','gemma3:latest','gemma2:latest','gemma:latest'] as $model) {
  coreui_gemma_assert(ember_model_is_parameter_free_gemma($model),
    'local Gemma model not recognized: ' . $model);
  coreui_gemma_assert(ember_ollama_known_good_options($model) === [],
    'local Gemma still receives model tuning: ' . $model);
  coreui_gemma_assert(
    ember_ollama_known_good_options($model,
      ['temperature'=>0.1,'num_ctx'=>65536,'num_thread'=>20,'num_predict'=>2048,
       'top_p'=>0.1,'repeat_penalty'=>1.5]) === [],
    'local Gemma overrides must not bypass the no-parameter policy'
  );
}
foreach (['gpt-oss:120b','llama3.2:latest','my-ember-coreui:latest'] as $other) {
  coreui_gemma_assert(!ember_model_is_parameter_free_gemma($other),
    'other providers must retain their own policy: ' . $other);
}
$cloud = ember_ollama_known_good_options('gpt-oss:120b');
coreui_gemma_assert(isset($cloud['temperature'],$cloud['top_p'],$cloud['top_k']),
  'Cloud sampler defaults unexpectedly removed');

foreach (['scripts/install.sh','scripts/install-native.sh'] as $installerPath) {
  $installer = (string)file_get_contents($root . '/' . $installerPath);
  coreui_gemma_assert(str_contains($installer, 'grep -Eiq')
    && str_contains($installer, 'PARAMETER[[:space:]]+num_ctx')
    && str_contains($installer, 'Modelfile.generated')
    && str_contains($installer, 'ollama create "$MODEL_NAME"'),
    'Installer must forbid only num_ctx and rebuild its Gemma model: ' . $installerPath);
  coreui_gemma_assert(!str_contains($installer, "define('STU_EMBER_NUM_CTX',"),
    'Fresh Gemma config should not explicitly pin num_ctx: ' . $installerPath);
}

$stream = (string)file_get_contents($root . '/api/console_stream.php');
$chat = (string)file_get_contents($root . '/api/chat.php');
coreui_gemma_assert(str_contains($chat, 'gemma_synthetic_warmup_disabled'),
  'do not start tiny forced-parameter warmup on heavy local Gemma');
coreui_gemma_assert(str_contains($stream, "if (\$options !== []) \$payload['options'] = \$options;")
  && str_contains($chat, "if (\$options !== []) \$payload['options'] = \$options;"),
  'SSE and sync payloads must omit options entirely for Gemma');

$installedFile = $root . '/var/Modelfile.generated';
if (is_file($installedFile)) {
  $saved = (string)file_get_contents($installedFile);
  preg_match_all('/^[ \t]*PARAMETER[ \t]+[^\r\n]+/im',$saved,$savedMatches);
  coreui_gemma_assert(array_map('trim',$savedMatches[0] ?? []) === $expectedParameters,
    'existing generated Modelfile does not match approved four parameters');
}
echo "CoreUI Gemma approved sampler settings and no-context-override selftest: OK\n";
