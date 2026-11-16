<?php
declare(strict_types=1);
// No network needed: the fixture is Ollama's local /api/tags shape.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_EMBER_MODEL', 'ember-coreui:latest');
require_once $root . '/api/core_tier_variants.php';
function variant_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "TCI variant FAIL: {$why}\n"); exit(1); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$normalUC = 'mo-shakib/gemma4-e4b-uncensored:q4_k_m';
$proUC = 'eriktonon/gemma4-uncensored:26b';
$models = [
  ['name'=>'ember-coreui:latest'], ['name'=>$normalUC],
  ['name'=>'ember-core:26b'], ['name'=>$proUC],
  ['name'=>'gemma4:31b-cloud'], ['name'=>'ember-coreui:gen'],
  ['name'=>'qwen3:8b']
];
variant_test(coreui_core_tier_of($normalUC) === 'normal'
  && coreui_core_tier_of($proUC) === 'pro', 'live Gemma-4 tag naming');
variant_test(coreui_tier_variant_local('gemma4:e4b') && !coreui_tier_variant_local('gemma4:26b-cloud')
  && !coreui_tier_variant_local('ember-coreui:gen'), 'local-only selector');
variant_test(coreui_tier_variant_fallback($models, 'normal') === 'ember-coreui:latest'
  && coreui_tier_variant_fallback($models, 'pro') === 'ember-core:26b',
  'default to Mara original without an operator choice');
variant_test(coreui_tier_variant_map($pdo, $models)['normal'] === 'ember-coreui:latest',
  'administration reports the original before an explicit choice');
variant_test(coreui_tier_variant_select($pdo, 'normal', $normalUC, $models) === $normalUC
  && coreui_tier_variant_select($pdo, 'pro', $proUC, $models) === $proUC,
  'each tier stores its exact installed tag');
variant_test(coreui_tier_variant_active($pdo, 'normal', $models) === $normalUC
  && coreui_tier_variant_active($pdo, 'pro', $models) === $proUC,
  'stored choices survive another request');
variant_test(coreui_tier_variant_effective($pdo, 'ember-coreui:latest') === $normalUC
  && coreui_tier_variant_effective($pdo, 'ember-core:26b') === $proUC,
  'turn-time routing uses the operator selection');
variant_test(coreui_tier_variant_effective($pdo, 'gemma4:26b-cloud') === 'gemma4:26b-cloud'
  && coreui_tier_variant_effective($pdo, 'ember-coreui:gen') === 'ember-coreui:gen'
  && coreui_tier_variant_effective($pdo, 'qwen3:8b') === 'qwen3:8b',
  'cloud, generator and foreign Cores are never rewritten');
foreach ([['normal', 'gemma4:26b'], ['pro', $normalUC],
  ['premium', 'gemma4:31b-cloud'], ['normal', 'notinstalled:latest']] as $bad) {
  try {
    coreui_tier_variant_select($pdo, $bad[0], $bad[1], $models);
    variant_test(false, 'a wrong, missing or cloud tag was accepted');
  } catch (RuntimeException $e) {
    variant_test($e->getMessage() === 'model_not_installed', 'unknown tag is refused');
  }
}
try { coreui_tier_variant_select($pdo, 'generator', 'ember-coreui:gen', $models);
  variant_test(false, 'generator cannot be managed as a Gemma variant');
} catch (InvalidArgumentException $e) {
  variant_test($e->getMessage() === 'invalid_tier', 'non-tier selection refused');
}
variant_test(coreui_tier_variant_select($pdo, 'normal', 'ember-coreui:latest', $models) === 'ember-coreui:latest'
  && coreui_tier_variant_effective($pdo, 'ember-coreui:latest') === 'ember-coreui:latest'
  && coreui_tier_variant_active($pdo, 'pro', $models) === $proUC,
  'restoring Original changes only Normal');
coreui_tier_variant_clear_removed($pdo, [$proUC]);
variant_test(coreui_tier_variant_active($pdo, 'pro', $models) === 'ember-core:26b'
  && coreui_tier_variant_effective($pdo, 'ember-core:26b') === 'ember-core:26b',
  'uninstall resets the configured tag without touching Mara');
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$settings = $read('api/user_settings.php');
variant_test(str_contains($settings, "if ((\$body['action'] ?? '') === 'core_tier_variant')")
  && str_contains($settings, 'stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);')
  && str_contains($settings, 'coreui_tier_variant_select($pdo, $tier, $name, coreui_ollama_models())'),
  'the settings API validates operator access and installed tags');
variant_test(str_contains($read('api/ai_settings.php'), 'coreui_tier_variant_effective($pdo, $configuredModel)')
  && str_contains($read('api/core_install.php'), 'coreui_tier_variant_clear_removed($pdo, $removed)')
  && str_contains($read('api/models.php'), "'selected_tiers'=>\$selectedTiers"),
  'per-turn runtime, uninstall and picker use the same saved selection');
echo "CoreAI TCI Varianten: persisted, exact, local, scoped, admin-only, safe uninstall OK\n";
