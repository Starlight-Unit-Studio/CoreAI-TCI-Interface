<?php
declare(strict_types=1);
// CoreAI 0.5.83: who may use which Core. Mara's Cores (Gemma 4 tiers, local
// or cloud, and Cores from her Modelfile) are open to every account; other
// models in Ollama (own names, such as a private cloud model) are the
// Inhaber's, who releases single ones per account. Checked on the list, on
// the choice and when a turn starts. Offline with an in-memory SQLite.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/model_access.php';

function access_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Model access FAIL: {$why}\n"); exit(1); }
}

// What belongs to CoreAI.
foreach (['gemma4:e2b', 'gemma4:e4b', 'gemma4:12b', 'gemma4:26b', 'gemma4:31b-cloud', 'ember-coreui:latest', 'ember-coreui:12b', 'Gemma4:E4B'] as $core) {
  access_test(coreui_model_is_coreai($core), 'CoreAI Core: ' . $core);
}
foreach (['gpt-oss:120b-cloud', 'llama3.2:latest', 'gemma3:4b', 'qwen3:8b', 'mistral'] as $other) {
  access_test(!coreui_model_is_coreai($other), 'Not CoreAI: ' . $other);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER, is_guest INTEGER)');
$pdo->exec('INSERT INTO stu_users (id, permission_level, is_guest) VALUES (1, 0, 0), (2, 1, 0), (3, 4, 0), (4, 4, 1), (5, 4, 0)');
// Without migration 020 nobody but the Inhaber gets a foreign model, and nothing breaks.
access_test(!coreui_model_access_ready($pdo) && coreui_model_access_grants($pdo, 3) === [], 'no table: no releases');
access_test(coreui_model_access_allowed($pdo, 1, 'gpt-oss:120b-cloud') && !coreui_model_access_allowed($pdo, 3, 'gpt-oss:120b-cloud'),
  'no table: Inhaber yes, others no');
try { coreui_model_access_save($pdo, 3, ['gpt-oss:120b-cloud'], 1); access_test(false, 'save without table'); }
catch (RuntimeException $e) { access_test($e->getMessage() === 'missing_schema_020', 'save names the migration'); }
$pdo->exec('CREATE TABLE stu_user_model_access (user_id INTEGER NOT NULL, model_name TEXT NOT NULL, granted_by INTEGER, '
  . 'created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, model_name))');

// Mara's Cores and the default are open to everyone (guests stay on the default elsewhere).
foreach ([1, 2, 3, 5] as $uid) {
  access_test(coreui_model_access_allowed($pdo, $uid, '') && coreui_model_access_allowed($pdo, $uid, 'gemma4:e4b')
    && coreui_model_access_allowed($pdo, $uid, 'gemma4:31b-cloud'), 'CoreAI Cores for account ' . $uid);
}
// Foreign models: Inhaber always, everyone else only with a release.
access_test(coreui_model_access_allowed($pdo, 1, 'gpt-oss:120b-cloud'), 'Inhaber uses his own model');
access_test(!coreui_model_access_allowed($pdo, 2, 'gpt-oss:120b-cloud'), 'an Admin needs a release too');
access_test(!coreui_model_access_allowed($pdo, 3, 'gpt-oss:120b-cloud'), 'a Nutzer without release');
access_test(!coreui_model_access_allowed($pdo, 4, 'gpt-oss:120b-cloud') && !coreui_model_access_allowed($pdo, 99, 'gpt-oss:120b-cloud'),
  'guests and unknown accounts never');

// Releases: replaced as a whole, CoreAI names ignored, invalid names refused.
$saved = coreui_model_access_save($pdo, 3, ['gpt-oss:120b-cloud', 'gemma4:e4b', 'llama3.2:latest', 'GPT-OSS:120b-cloud'], 1);
access_test($saved === ['GPT-OSS:120b-cloud', 'llama3.2:latest'] || $saved === ['gpt-oss:120b-cloud', 'llama3.2:latest'],
  'stored without CoreAI Cores and duplicates: ' . implode(',', $saved));
access_test(coreui_model_access_allowed($pdo, 3, 'gpt-oss:120b-cloud') && coreui_model_access_allowed($pdo, 3, 'llama3.2'),
  'released models (any case, :latest optional)');
access_test(!coreui_model_access_allowed($pdo, 5, 'gpt-oss:120b-cloud'), 'a release is per account');
access_test(coreui_model_access_save($pdo, 3, ['llama3.2:latest'], 1) === ['llama3.2:latest']
  && !coreui_model_access_allowed($pdo, 3, 'gpt-oss:120b-cloud'), 'withdrawing works');
foreach ([['../etc/passwd'], ['bad name'], ['']] as $bad) {
  try { coreui_model_access_save($pdo, 3, $bad, 1); access_test(false, 'invalid name accepted'); }
  catch (InvalidArgumentException $e) { access_test($e->getMessage() === 'invalid_model_name', 'invalid name refused'); }
}
access_test(coreui_model_access_grants($pdo, 3) === ['llama3.2:latest'], 'a refused save changes nothing');
access_test(coreui_model_access_all($pdo) === ['3' => ['llama3.2:latest']], 'all releases by account');

// The list each account sees.
$list = [['name'=>'ember-coreui:latest'], ['name'=>'gemma4:e2b'], ['name'=>'gemma4:31b-cloud'], ['name'=>'gpt-oss:120b-cloud'], ['name'=>'llama3.2:latest']];
$names = static fn(array $l): array => array_map(static fn(array $m): string => $m['name'], $l);
access_test($names(coreui_model_access_filter($pdo, 1, $list)) === $names($list), 'Inhaber sees everything');
access_test($names(coreui_model_access_filter($pdo, 3, $list)) === ['ember-coreui:latest', 'gemma4:e2b', 'gemma4:31b-cloud', 'llama3.2:latest'],
  'Nutzer sees CoreAI and his release');
access_test($names(coreui_model_access_filter($pdo, 2, $list)) === ['ember-coreui:latest', 'gemma4:e2b', 'gemma4:31b-cloud'], 'Admin without release');
access_test($names(coreui_model_access_filter($pdo, 4, $list)) === ['ember-coreui:latest', 'gemma4:e2b', 'gemma4:31b-cloud'], 'guest sees CoreAI only');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
access_test(str_contains($read('api/models.php'), '$models = coreui_model_access_filter(stu_pdo(), $uid, $models);'), 'the list is filtered');
$settings = $read('api/user_settings.php');
access_test(substr_count($settings, "stu_json(['ok'=>false, 'error'=>'model_not_allowed'], 403);") === 2, 'chat menu and KI-Profil refuse');
access_test(str_contains($settings, "if (!coreui_model_access_allowed(\$pdo, \$uid, (string)\$settings['model_override'])) \$settings['model_override'] = '';"),
  'a withdrawn Core no longer shows as chosen');
access_test(str_contains($read('api/ai_settings.php'), "if (!coreui_model_access_allowed(\$pdo, \$uid, (string)\$settings['model_override'])) \$settings['model_override'] = '';"),
  'a withdrawn Core falls back at the next message');
$admin = $read('api/admin.php');
access_test(substr_count($admin, "if (\$actorLevel !== 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);") === 2
  && str_contains($admin, "coreui_admin_audit(\$pdo, \$actorId, 'model_access_save', 'user'")
  && str_contains($admin, "'error' => 'owner_has_all'") && str_contains($admin, "'error' => 'guest_core_locked'"), 'admin API: Inhaber only, audited');
access_test(str_contains($read('js/admin.js'), "post('model_access_save', { user_id: Number(user.id), models: chosen })")
  && str_contains($read('settings.html'), '<th>SITZUNGEN</th><th>CORES</th><th>AKTION</th>'), 'Admin Core: CORES column');
access_test(str_contains($read('js/coreui-core-mode.js'), "'Dieser Core ist für dein Konto nicht freigegeben.'"), 'chat explains a refusal');
$migration = $read('database/migrations/020_model_access.sql');
access_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_user_model_access')
  && str_contains($migration, 'ON DELETE CASCADE') && str_contains($migration, "VALUES ('020_model_access')"), 'migration 020');
access_test(str_contains($read('api/health.php'), "'stu_user_model_access',"), 'health checks the table');

echo "CoreAI model access: Mara's Cores for all, foreign models for the Inhaber and released accounts, list, choice and runtime OK\n";
