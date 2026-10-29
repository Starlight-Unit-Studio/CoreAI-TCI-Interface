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

// Limits: the slider offers only the allowed windows; an own choice above is cut.
$capped = ['tools' => $all, 'daily_messages' => null, 'max_num_ctx' => 16384];
policy_test(coreui_user_policy_ctx_choices([4096, 8192, 16384, 32768, 65536], $capped) === [4096, 8192, 16384], 'slider up to the limit');
policy_test(coreui_user_policy_ctx_choices([4096, 8192], coreui_user_policy_default()) === [4096, 8192], 'no limit: all windows');
policy_test(coreui_user_policy_ctx_clamp(65536, $capped) === 16384 && coreui_user_policy_ctx_clamp(8192, $capped) === 8192
  && coreui_user_policy_ctx_clamp(null, $capped) === null, 'an own choice above is cut, the Core default stays');

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
policy_test(str_contains($read('api/chat.php'), "  coreui_user_policy_reserve_turn(\$pdo, (int)\$uid);")
  && substr_count($read('api/console_actions.php'), "    coreui_user_policy_reserve_turn(\$pdo, (int)\$uid);") === 2,
  'the daily limit counts at send, regenerate and edit');
$settingsApi = $read('api/user_settings.php');
policy_test(str_contains($settingsApi, "'num_ctx_choices' => coreui_user_policy_ctx_choices(coreui_ai_num_ctx_choices(), coreui_user_policy_load(\$pdo, (int)\$uid)),")
  && str_contains($settingsApi, "stu_json(['ok'=>false, 'error'=>'num_ctx_not_allowed'], 403);"), 'KI-Profil: slider and saving respect the limit');
policy_test(str_contains($read('api/ai_settings.php'), "\$settings['num_ctx'] = coreui_user_policy_ctx_clamp(\$settings['num_ctx'], \$policy);"), 'runtime cuts the window');
policy_test(str_contains($read('js/console-app.js'), "daily_message_limit: 'Dein Tageslimit an Nachrichten ist erreicht.") , 'the chat explains the limit');
policy_test(str_contains($read('js/admin.js'), "post('user_policy', { user_id: Number(user.id), daily_messages: dailyValue, max_num_ctx: ctx.value === '' ? null : Number(ctx.value) })"),
  'Admin Core: limits section');
policy_test(str_contains($read('api/ai_settings.php'), "\$policy = coreui_user_policy_load(\$pdo, \$uid);")
  && str_contains($read('api/ai_settings.php'), "\$GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] = coreui_user_policy_tools(coreui_operator_runtime_load(\$pdo), \$policy);"),
  'the block applies when a turn starts');
$adminApi = $read('api/admin.php');
policy_test(str_contains($adminApi, "if (\$action === 'user_policy') {") && str_contains($adminApi, "if (\$refusal === 'use_account_settings') \$refusal = 'own_policy';")
  && str_contains($adminApi, "coreui_admin_audit(\$pdo, \$actorId, 'user_policy', 'user'") && str_contains($adminApi, "\$policies = coreui_user_policy_all(\$pdo);") && str_contains($adminApi, "'policies' => \$policies,")
  && str_contains($adminApi, "'used_today'] = coreui_user_policy_used_today(\$pdo, (int)\$policyUid);"),
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
  echo "CoreAI user policy: tools and limits per account, rows and wiring OK (no database here)\n";
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
  policy_test(isset(coreui_user_policy_all($pdo)[(string)$uid]), 'listed with its rules');
  // Limits: stored, checked, the tools stay as they were.
  $saved = coreui_user_policy_save($pdo, $uid, ['daily_messages' => 2, 'max_num_ctx' => 16384], 1, $choices);
  policy_test($saved['daily_messages'] === 2 && $saved['max_num_ctx'] === 16384 && $saved['tools']['python'] === false, 'limits saved, tools kept');
  foreach ([['daily_messages' => -1], ['daily_messages' => 'viele'], ['daily_messages' => 200000], ['daily_messages' => 2.5]] as $bad) {
    try { coreui_user_policy_save($pdo, $uid, $bad, 1, $choices); policy_test(false, 'bad daily limit accepted'); }
    catch (InvalidArgumentException $e) { policy_test($e->getMessage() === 'invalid_daily_messages', 'bad daily limit refused'); }
  }
  try { coreui_user_policy_save($pdo, $uid, ['max_num_ctx' => 12345], 1, $choices); policy_test(false, 'unknown window accepted'); }
  catch (InvalidArgumentException $e) { policy_test($e->getMessage() === 'invalid_max_num_ctx', 'unknown window refused'); }
  // The daily limit: two messages, the third is refused and not counted.
  $now = time();
  $t1 = coreui_user_policy_take_turn($pdo, $uid, $now);
  $t2 = coreui_user_policy_take_turn($pdo, $uid, $now + 1);
  $t3 = coreui_user_policy_take_turn($pdo, $uid, $now + 2);
  policy_test($t1['ok'] && $t1['left'] === 1 && $t2['ok'] && $t2['left'] === 0, 'two messages within the limit');
  policy_test(!$t3['ok'] && $t3['retry_at'] >= $now + COREUI_USER_POLICY_DAY - 5, 'the third is refused until the window ends');
  policy_test(coreui_user_policy_used_today($pdo, $uid) === 2, 'a refused message is not counted');
  policy_test(coreui_user_policy_take_turn($pdo, $other, $now) === null, 'accounts without a limit pass untouched');
  // The window: an own choice above the limit is cut when the turn starts.
  $pdo->prepare('INSERT INTO stu_user_ai_settings (user_id, num_ctx) VALUES (?, 65536)')->execute([$uid]);
  policy_test(coreui_ai_runtime_apply($pdo, $uid)['num_ctx'] === 16384, 'window cut to the limit');
  unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);
  $pdo->prepare('UPDATE stu_user_ai_settings SET num_ctx = 8192 WHERE user_id = ?')->execute([$uid]);
  policy_test(coreui_ai_runtime_apply($pdo, $uid)['num_ctx'] === 8192, 'a choice below the limit stays');
  unset($GLOBALS['COREUI_AI_NUM_CTX_FIXED']);

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

echo "CoreAI user policy: tools and limits per account, rows, wiring, the block, the daily limit and the window on the database (rolled back) OK\n";
