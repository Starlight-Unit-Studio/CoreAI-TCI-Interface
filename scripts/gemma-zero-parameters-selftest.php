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
coreui_gemma_assert(preg_match('/^FROM gemma4:e4b$/m', $template) === 1
  && preg_match_all('/^FROM /m', $template) === 1
  && !preg_match('/^DRAFT[ \t]+/m', $template),
  'portable E4B base must contain one model tag and no exported DRAFT blob');
coreui_gemma_assert(str_contains($template, "SYSTEM \"\"\"<|think|>\n")
  && preg_match('~^"""[ \t]*\r?\nRENDERER gemma4\r?\nPARSER gemma4[ \t]*$~m', $template) === 1,
  'active Mara SYSTEM framing and Gemma renderer/parser structure invalid');
coreui_gemma_assert(preg_match('/^SYSTEM """(.+?)^"""$/ms', $template, $activeSystem) === 1
  && trim((string)$activeSystem[1]) !== '',
  'active Mara SYSTEM is missing or empty');
function coreui_gemma_modelfile_parameters(string $file): array {
  preg_match_all('/^PARAMETER[ \t]+(\S+)[ \t]+([^\r\n]+)$/m', $file, $matches, PREG_SET_ORDER);
  return array_map(static fn(array $line): string => $line[1] . ' ' . trim($line[2]), $matches);
}
$activeParameters = coreui_gemma_modelfile_parameters($template);
$rawParameterCount = preg_match_all('/^PARAMETER(?:[ \t]|$)/m', $template);
coreui_gemma_assert($rawParameterCount === count($activeParameters),
  'a PARAMETER directive is malformed or has no value');
$seen = [];
foreach ($activeParameters as $spec) {
  [$name, $value] = explode(' ', $spec, 2);
  coreui_gemma_assert(preg_match('/^[a-z][a-z0-9_]*$/D', $name) === 1
    && trim($value) !== '',
    'invalid active Modelfile parameter syntax');
  coreui_gemma_assert(!isset($seen[$name]) || $name === 'stop',
    'duplicated active Modelfile parameter: ' . $name);
  $seen[$name] = true;
  if (in_array($name, ['num_predict','draft_num_predict','num_ctx','num_thread','top_k'], true)) {
    coreui_gemma_assert(preg_match('/^-?[0-9]+$/D', $value) === 1,
      'invalid integer value for active parameter: ' . $name);
  }
  if (in_array($name, ['temperature','top_p','min_p','repeat_penalty'], true)) {
    coreui_gemma_assert(is_numeric($value) && is_finite((float)$value),
      'invalid numeric value for active parameter: ' . $name);
  }
  if (in_array($name, ['top_p','min_p'], true)) {
    coreui_gemma_assert((float)$value >= 0.0 && (float)$value <= 1.0,
      'sampling probability must be between zero and one: ' . $name);
  }
}
// Parameter order and values belong to the owner-maintained template.
// This test validates their syntax and the unchanged model/API contract, not
// an archived historical export or an arbitrary earlier parameter list.
coreui_gemma_assert(preg_match('/\bMara\b/u', (string)$activeSystem[1]) === 1,
  'active operator SYSTEM must identify Mara');
foreach (['TEMPLATE {{ .Prompt }}','RENDERER gemma4','PARSER gemma4'] as $required) {
  coreui_gemma_assert(str_contains($template, $required),
    'required Gemma template/renderer/parser directive missing: ' . $required);
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
// 0.5.70 (#113): the single exception is an explicit KI-Profil window. It
// adds num_ctx and nothing else; without a choice Gemma stays parameter-free.
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = ['num_ctx'=>16384];
foreach (['ember-coreui:latest','gemma4:e4b'] as $model) {
  coreui_gemma_assert(ember_ollama_known_good_options($model,
      ['temperature'=>0.1,'num_ctx'=>65536,'num_thread'=>20,'top_p'=>0.1]) === ['num_ctx'=>16384],
    'a KI-Profil window must be the only Gemma option: ' . $model);
}
coreui_gemma_assert(ember_ollama_known_good_options('gemma4:31b-cloud') === [],
  'a cloud Gemma never receives num_ctx');
$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = [];
coreui_gemma_assert(ember_ollama_known_good_options('ember-coreui:latest') === [],
  'without a KI-Profil choice Gemma stays parameter-free');
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
// The E2B export is immutable historical evidence, not the live E4B policy.
// The operator revised the active Mara SYSTEM independently after 0.5.44.
coreui_gemma_assert(coreui_gemma_modelfile_parameters($ownerReference)
  === ['draft_num_predict 0','temperature 1','top_k 64','top_p 0.95'],
  'archived E2B export must remain unchanged');
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
  coreui_gemma_assert(coreui_gemma_modelfile_parameters($saved) === $activeParameters,
    'generated Modelfile parameters differ from active template; rerun the normal installer');
  coreui_gemma_assert(preg_match('/^SYSTEM """(.+?)^"""$/ms', $saved, $installedSystem) === 1
    && $installedSystem[1] === $activeSystem[1],
    'generated Modelfile SYSTEM differs from active template; rerun the normal installer');
}
echo "CoreUI Gemma active Modelfile structure, generated-file sync and API inheritance selftest: OK\n";
