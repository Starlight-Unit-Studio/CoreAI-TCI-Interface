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
coreui_gemma_assert(str_contains($template, 'FROM gemma4:e4b'),
  'missing FROM base model');
coreui_gemma_assert(str_contains($template, 'SYSTEM """'),
  'must retain Mara TCI system instructions');
function coreui_gemma_modelfile_parameters(string $file): array {
  preg_match_all('/^PARAMETER[ \t]+(\S+)[ \t]+([^\r\n]+)$/m', $file, $matches, PREG_SET_ORDER);
  return array_map(static fn(array $line): string => $line[1] . ' ' . trim($line[2]), $matches);
}
$expectedParameters = ['temperature 1','top_k 64','top_p 0.95','draft_num_predict 0'];
coreui_gemma_assert(coreui_gemma_modelfile_parameters($template) === $expectedParameters,
  'must retain exactly the owner-approved Modelfile parameters');
coreui_gemma_assert(!preg_match('/^PARAMETER[ \t]+(?:num_ctx|num_predict)\b/m', $template),
  'Modelfile must omit forbidden num_ctx and unintended num_predict');
coreui_gemma_assert(str_contains($template, 'CoreAI') && str_contains($template, 'Gamer-Note'),
  'Mara TCI and natural vision-inspired style must be present');
foreach (['TEMPLATE {{ .Prompt }}','RENDERER gemma4','PARSER gemma4','Du bist Mara Thorne,','E.M.B.E.R. CoreUI','E.M.B.E.R.-Framework','CoreAI namens Ember'] as $required) {
  coreui_gemma_assert(str_contains($template, $required), 'missing approved directive: ' . $required);
}

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

$installer = (string)file_get_contents($root . '/scripts/install.sh');
coreui_gemma_assert(!str_contains($installer, 'verbotene PARAMETER-Zeilen')
  && str_contains($installer, 'Modelfile.generated')
  && str_contains($installer, 'ollama create "$MODEL_NAME"'),
  'Installer must validate and rebuild owned Gemma model on update');

$stream = (string)file_get_contents($root . '/api/console_stream.php');
$chat = (string)file_get_contents($root . '/api/chat.php');
coreui_gemma_assert(str_contains($chat, 'gemma_synthetic_warmup_disabled'),
  'do not start tiny forced-parameter warmup on heavy local Gemma');
coreui_gemma_assert(str_contains($stream, "if (\$options !== []) \$payload['options'] = \$options;")
  && str_contains($chat, "if (\$options !== []) \$payload['options'] = \$options;"),
  'SSE and sync payloads must omit options entirely for Gemma');


// Owner-approved E2B archival reference, not an E4B base or installer input.
$ownerReference = (string)file_get_contents(
  $root . '/docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt');
coreui_gemma_assert(
  sha1('blob ' . strlen($ownerReference) . "\0" . $ownerReference) === 'bdf70ae29fde3d9172d823720be0f002fa518549',
  'owner E2B reference content changed');
coreui_gemma_assert(preg_match('/^SYSTEM "(.+?)^"$/ms', $ownerReference, $ownerSystem) === 1,
  'E2B reference SYSTEM missing');
coreui_gemma_assert(preg_match('/^SYSTEM """(.+?)^"""$/ms', $template, $activeSystem) === 1,
  'active E4B SYSTEM missing');
function coreui_gemma_non_tool_persona(string $system): ?string {
  $normalized = str_replace(["\r\n", "\r"], "\n", $system);
  $start = strpos($normalized, "\nWerkzeugprotokoll:\n");
  $end = strpos($normalized, "\nDu bist Mara Thorne, die TCI und CoreAI der Ravager Thorn.");
  if ($start === false || $end === false || $end <= $start) return null;
  $nonTools = substr($normalized, 0, $start) . substr($normalized, $end);
  return preg_replace('/\s+/u', ' ', trim($nonTools));
}
$ownerPersona = coreui_gemma_non_tool_persona($ownerSystem[1]);
$activePersona = coreui_gemma_non_tool_persona($activeSystem[1]);
coreui_gemma_assert($ownerPersona !== null && $ownerPersona === $activePersona,
  'persona changed outside the approved Werkzeugprotokoll section');
$ownerParameterSet = coreui_gemma_modelfile_parameters($ownerReference);
$templateParameterSet = coreui_gemma_modelfile_parameters($template);
sort($ownerParameterSet);
sort($templateParameterSet);
coreui_gemma_assert($ownerParameterSet === $templateParameterSet,
  'E4B parameter set differs from approved E2B reference');
coreui_gemma_assert(substr_count($ownerReference, "\nFROM ") === 2
  && str_contains($ownerReference, 'DRAFT /usr/share/ollama/.ollama/models/blobs/sha256-05612e54110f8f68bade01dfcc546cdc6914d11dde0b650a65f263f0f87bbbd2')
  && str_contains($ownerReference, 'FROM /usr/share/ollama/.ollama/models/blobs/sha256-370c2879f17648d337b0ab4e208fdce348e24d1060064e93d7645daafd56d39c')
  && str_contains($ownerReference, 'FROM /usr/share/ollama/.ollama/models/blobs/sha256-4cb21b935cd7e79daed76f3154e82f2f160ad7a9d8d22cb5f14c7b93d5fb38ba'),
  'archived E2B source model structure incomplete');
coreui_gemma_assert(!str_contains($template, 'sha256-370c2879f17648d337b0ab4e208fdce348e24d1060064e93d7645daafd56d39c'),
  'E2B blob must never replace E4B base model');

$installedFile = $root . '/var/Modelfile.generated';
if (is_file($installedFile)) {
  $saved = (string)file_get_contents($installedFile);
  coreui_gemma_assert(coreui_gemma_modelfile_parameters($saved) === $expectedParameters,
    'generated Modelfile differs from approved parameters; rebuild the owned model');
}
echo "CoreUI Gemma approved Modelfile and API inheritance selftest: OK\n";
