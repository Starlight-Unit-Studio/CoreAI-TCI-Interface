<?php
declare(strict_types=1);
// Pure regression tests: never connect to Ollama, remove a tag or modify DB.
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/installed_cores.php';
function installed_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Installed Cores FAIL: " . $message . "\n"); exit(1); }
}
$standard = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
$base = 'gemma4:26b';
$models = [
  ['name'=>'qwen3:8b','size'=>4900000000,'parameter_size'=>'8B','family'=>'qwen3'],
  ['name'=>'ember-core:12b','size'=>8200000000],
  ['name'=>$standard,'size'=>5600000000],
  ['name'=>$base,'size'=>18400000000],
  ['name'=>'gemma4:31b-cloud','size'=>0],
  ['name'=>'hf.co/owner/llm:Q4_K_M','size'=>2900000000],
  ['name'=>'broken name!','size'=>44],
];
$rows = coreui_installed_cores_rows($models, $standard, $base);
$byName = array_column($rows, null, 'name');
installed_test(count($rows) === 6 && !isset($byName['broken name!']), 'only installed valid Ollama tags are listed');
installed_test($byName['hf.co/owner/llm:Q4_K_M']['kind'] === 'local'
  && $byName['hf.co/owner/llm:Q4_K_M']['removable'],
  'already imported HF namespaced LLMs appear without adding an HF installer');
installed_test($byName[$standard]['kind'] === 'tci' && !$byName[$standard]['removable']
  && str_contains($byName[$standard]['protected_reason'], 'Standard-Core'),
  'default TCI Core must be protected');
installed_test(!$byName[$base]['removable']
  && str_contains($byName[$base]['protected_reason'], 'Basis'),
  'the standard Core\'s source tag must be protected');
installed_test($byName['ember-core:12b']['removable'] && $byName['ember-core:12b']['kind'] === 'tci',
  'other Mara TCI tiers can be removed one at a time');
installed_test($byName['qwen3:8b']['removable'] && $byName['qwen3:8b']['kind'] === 'local'
  && $byName['qwen3:8b']['size_bytes'] === 4900000000
  && $byName['qwen3:8b']['parameter_size'] === '8B',
  'independent local LLMs retain Ollama size and parameters');
installed_test($byName['gemma4:31b-cloud']['kind'] === 'cloud'
  && $byName['gemma4:31b-cloud']['removable'],
  'existing cloud stubs remain visible and individually removable');
installed_test(coreui_installed_cores_target($models, 'ember-core:12b', $standard, $base) === 'ember-core:12b'
  && coreui_installed_cores_target($models, 'qwen3:8b', $standard, $base) === 'qwen3:8b',
  'only exact existing tags can be returned for reuse in coreui_core_uninstall');
foreach ([
  [$standard, 'core_protected'],
  [$base, 'core_protected'],
  // A double-colon tag is malformed and must be rejected before inventory lookup.
  ['qwen3:8b:latest', 'invalid_model_name'],
  ['qwen3:7b', 'model_not_installed'],
  ['http://127.0.0.1', 'invalid_model_name'],
  ['https://evil.test', 'invalid_model_name'],
] as [$target, $expected]) {
  try {
    coreui_installed_cores_target($models, $target, $standard, $base);
    installed_test(false, 'unauthorized deletion accepted: ' . $target);
  } catch (RuntimeException $e) {
    installed_test($e->getMessage() === $expected, 'deletion guard for ' . $target);
  }
}
installed_test($byName[$standard]['updatable'] && $byName[$base]['updatable']
  && !$byName['gemma4:31b-cloud']['updatable'],
  'protected local Cores can update while Cloud stubs cannot be pulled');
$plans = [
  ['base'=>$base, 'tier'=>'pro', 'cores'=>[['name'=>$standard, 'source'=>'mara']]],
  ['base'=>'gemma4:12b', 'tier'=>'plus', 'cores'=>[['name'=>'ember-core:12b', 'source'=>'template']]],
];
$selected = coreui_installed_cores_update_selection($models, $standard, $standard, $base, $plans);
installed_test($selected['kind'] === 'tci' && $selected['plan'][0]['base'] === $base,
  'standard Core update rebuilds from its verified base');
$selected = coreui_installed_cores_update_selection($models, $base, $standard, $base, $plans);
installed_test($selected['kind'] === 'tci' && $selected['plan'][0]['base'] === $base,
  'updating the protected default source rebuilds Mara with it');
$selected = coreui_installed_cores_update_selection($models, 'ember-core:12b', $standard, $base, $plans);
installed_test($selected['kind'] === 'tci' && $selected['plan'][0]['tier'] === 'plus',
  'Mara tier updates use the existing Core rebuild rather than an invalid Ollama pull');
$selected = coreui_installed_cores_update_selection($models, 'qwen3:8b', $standard, $base, $plans);
installed_test($selected === ['kind'=>'pull','model'=>'qwen3:8b'],
  'ordinary local Ollama tags update via the shared pull worker');
foreach ([
  ['gemma4:31b-cloud', 'update_not_supported'],
  ['qwen3:7b', 'model_not_installed'],
  ['https://evil.test', 'invalid_model_name'],
] as [$name, $reason]) {
  try {
    coreui_installed_cores_update_selection($models, $name, $standard, $base, $plans);
    installed_test(false, 'unsafe installed Core update allowed: ' . $name);
  } catch (RuntimeException $e) {
    installed_test($e->getMessage() === $reason, 'update validation: ' . $name);
  }
}
$api = (string)file_get_contents(dirname(__DIR__) . '/api/user_settings.php');
installed_test(str_contains($api, "=== 'installed_core_uninstall'")
  && str_contains($api, "array_key_exists('core_installed',")
  && str_contains($api, 'coreui_core_install_lock()')
  && str_contains($api, 'coreui_core_uninstall($pdo, [$target])')
  && str_contains($api, "'installed_core_update'")
  && str_contains($api, 'coreui_installed_cores_update_selection(')
  && str_contains($api, "coreui_core_job_run(\$lock, 'coreui_core_update_run'")
  && str_contains($api, "coreui_core_job_run(\$lock, 'coreui_library_run'")
  && str_contains($api, 'stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo)'),
  'inventory, deletion and update must share the existing permission, lock and worker paths');
echo "CoreAI installed Cores: inventory, protected defaults, exact deletion targets, reusable uninstall flow OK\n";
