<?php
declare(strict_types=1);
// CoreAI 0.5.85: own rules per account (Admin Core > Benutzer > BEARBEITEN).
// Offline: tools narrowed per account (global off stays off), rows read back,
// wiring. With a database (api/config.local.php, or COREUI_SELFTEST_DB=1 with
// COREUI_DB_* set): saving, validation and the block taking effect when a turn
// starts, inside a transaction that is rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/ai_settings.php';

function policy_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "User policy FAIL: {$why}\n"); exit(1); }
}

// Tools: a block narrows, it never switches on what is off for everyone.
$all = ['web' => true, 'browse' => true, 'python' => true];
$policy = coreui_user_policy_default();
policy_test(coreui_user_policy_tools($all, $policy) === $all, 'defaults change nothing');
$policy['tools']['python'] = false;
policy_test(coreui_user_policy_tools($all, $policy) === ['web' => true, 'browse' => true, 'python' => false], 'python blocked for the account');
policy_test(coreui_user_policy_tools(['web' => false, 'browse' => true, 'python' => true], coreui_user_policy_default())['web'] === false,
  'global off stays off');
$row = coreui_user_policy_from_row(['tool_web' => '0', 'tool_browse' => '1', 'tool_python' => '1', 'daily_messages' => '40', 'max_num_ctx' => '16384']);
policy_test($row === ['tools' => ['web' => false, 'browse' => true, 'python' => true], 'daily_messages' => 40, 'max_num_ctx' => 16384], 'row read back');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
policy_test(str_contains($read('api/ai_settings.php'),
  "\$GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] = coreui_user_policy_tools(coreui_operator_runtime_load(\$pdo), coreui_user_policy_load(\$pdo, \$uid));"),
  'the block applies when a turn starts');
$adminApi = $read('api/admin.php');
policy_test(str_contains($adminApi, "if (\$action === 'user_policy') {") && str_contains($adminApi, "if (\$refusal === 'use_account_settings') \$refusal = 'own_policy';")
  && str_contains($adminApi, "coreui_admin_audit(\$pdo, \$actorId, 'user_policy', 'user'") && str_contains($adminApi, "'policies' => coreui_user_policy_all(\$pdo)"),
  'admin API: rules, audit, list');
policy_test(str_contains($read('js/admin.js'), "post('user_policy', { user_id: Number(user.id), tools: chosen })"), 'Admin Core: tools section');
$migration = $read('database/migrations/021_user_policies.sql');
policy_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_user_policies') && str_contains($migration, 'ON DELETE CASCADE')
  && str_contains($migration, "VALUES ('021_user_policies')"), 'migration 021');
policy_test(str_contains($read('api/health.php'), "'stu_user_policies',"), 'health checks the table');
foreach (['web' => 'ember_web_enabled', 'browse' => 'ember_browse_enabled', 'python' => 'ember_py_enabled'] as $tool => $fn) {
  policy_test(str_contains($read('api/chat.php'), "function {$fn}(): bool {") && str_contains($read('api/chat.php'), "coreui_runtime_tool_enabled('{$tool}', \$configured)"),
    'every use of ' . $tool . ' goes through the runtime switch');
}

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI user policy: tools per account, rows and wiring OK (no database here)\n";
  exit(0);
}

require_once $root . '/api/db.php';
$pdo = stu_pdo();
policy_test(coreui_user_policy_ready($pdo), 'migration 021 applied');
// stu_app_settings is created once per process; DDL inside the transaction
// would commit it silently and leave the test rows behind.
stu_app_settings_install($pdo);
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $other = $uid + 1;
  $s = bin2hex(random_bytes(4));
  $user = $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, 4, NOW())');
  $user->execute([$uid, "policy-{$s}@coreui.invalid"]);
  $user->execute([$other, "free-{$s}@coreui.invalid"]);
  $choices = coreui_ai_num_ctx_choices();

  policy_test(coreui_user_policy_load($pdo, $uid) === coreui_user_policy_default(), 'no row: defaults');
  $saved = coreui_user_policy_save($pdo, $uid, ['tools' => ['python' => false, 'browse' => false]], 1, $choices);
  policy_test($saved['tools'] === ['web' => true, 'browse' => false, 'python' => false], 'tools saved');
  $saved = coreui_user_policy_save($pdo, $uid, ['tools' => ['browse' => true]], 1, $choices);
  policy_test($saved['tools'] === ['web' => true, 'browse' => true, 'python' => false], 'only the given tools change');
  foreach ([['tools' => ['shell' => false]], ['tools' => ['web' => 'no']], ['tools' => 'all']] as $bad) {
    try { coreui_user_policy_save($pdo, $uid, $bad, 1, $choices); policy_test(false, 'bad tools accepted'); }
    catch (InvalidArgumentException $e) { policy_test($e->getMessage() === 'invalid_tools', 'bad tools refused'); }
  }
  policy_test(array_keys(coreui_user_policy_all($pdo)) === [(string)$uid] || isset(coreui_user_policy_all($pdo)[(string)$uid]), 'listed with its rules');

  // When a turn starts: Python is off for this account only.
  coreui_ai_runtime_apply($pdo, $uid);
  policy_test(!coreui_runtime_tool_enabled('python', true) && coreui_runtime_tool_enabled('web', true), 'blocked for the account when its turn starts');
  coreui_ai_runtime_apply($pdo, $other);
  policy_test(coreui_runtime_tool_enabled('python', true), 'other accounts keep Python');
  policy_test($pdo->inTransaction() && (int)$pdo->query('SELECT @@in_transaction')->fetchColumn() === 1, 'still inside the transaction (no implicit commit)');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
policy_test(!isset(coreui_user_policy_all($pdo)[(string)$uid]), 'no test row stays behind');

echo "CoreAI user policy: tools per account, rows, wiring and the block on the database (rolled back) OK\n";
