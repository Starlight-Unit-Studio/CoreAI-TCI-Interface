<?php
declare(strict_types=1);
// CoreAI 0.5.85: settings per account under Admin Core > Benutzer (BEARBEITEN).
// Offline: who may change whom and the wiring. With a database
// (api/config.local.php, or COREUI_SELFTEST_DB=1 with COREUI_DB_* set): new
// password and signing out everywhere, name and e-mail, inside a transaction
// that is rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/account_admin.php';

function manage_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Account settings FAIL: {$why}\n"); exit(1); }
}

// Who may change whom; on the own account only name and e-mail.
$owner = 10; $admin = 11;
$cases = [
  [$owner, 0, ['id' => 20, 'permission_level' => 4], false, ''],
  [$owner, 0, ['id' => 21, 'permission_level' => 1], false, ''],
  [$owner, 0, ['id' => 30, 'permission_level' => 0], false, 'protected_account'],
  [$owner, 0, ['id' => $owner, 'permission_level' => 0], false, 'use_account_settings'],
  [$owner, 0, ['id' => $owner, 'permission_level' => 0], true, ''],
  [$owner, 0, ['id' => STU_EMBER_USER_ID, 'permission_level' => 0], true, 'protected_account'],
  [$admin, 1, ['id' => 20, 'permission_level' => 4], false, ''],
  [$admin, 1, ['id' => 21, 'permission_level' => 1], false, 'protected_account'],
  [$admin, 1, ['id' => 30, 'permission_level' => 0], true, 'protected_account'],
  [$admin, 1, ['id' => $admin, 'permission_level' => 1], true, ''],
  [12, 4, ['id' => 20, 'permission_level' => 4], false, 'insufficient_permissions'],
  [$owner, 0, ['id' => 0], false, 'invalid_user'],
];
foreach ($cases as $i => [$actor, $level, $target, $self, $expected]) {
  $got = coreui_account_manage_refusal($actor, $level, $target, $self);
  manage_test($got === $expected, "rule {$i}: expected '{$expected}', got '{$got}'");
}

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$adminApi = $read('api/admin.php');
manage_test(str_contains($adminApi, "require_once __DIR__ . '/account_admin.php';")
  && str_contains($adminApi, "if (in_array(\$action, ['user_password', 'user_logout_all', 'user_identity'], true)) {")
  && strpos($adminApi, 'stu_require_csrf();') < strpos($adminApi, "['user_password', 'user_logout_all', 'user_identity']"), 'admin API behind CSRF');
manage_test(str_contains($adminApi, "\$refusal = coreui_account_manage_refusal(\$actorId, \$actorLevel, \$target, \$action === 'user_identity');")
  && str_contains($adminApi, "'user_password_set', 'user'") && str_contains($adminApi, "'user_logout_all', 'user'") && str_contains($adminApi, "'user_identity', 'user'"),
  'rules and audit for every change');
manage_test(!preg_match("~coreui_admin_audit\([^;]*'password'~", $adminApi), 'the password never goes into the audit');
manage_test(str_contains($adminApi, 'AS login_count'), 'the user list counts active logins');
$adminJs = $read('js/admin.js');
manage_test(str_contains($adminJs, "post('user_password', { user_id: Number(user.id), password: pass.value })")
  && str_contains($adminJs, "post('user_logout_all', { user_id: Number(user.id) })")
  && str_contains($adminJs, "post('user_identity', { user_id: Number(user.id), display_name: nameInput.value, username: mailInput.value })")
  && str_contains($adminJs, 'function canManage(user)'), 'Admin Core: BEARBEITEN panel');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI account settings: rules and wiring OK (no database here)\n";
  exit(0);
}

require_once $root . '/api/db.php';
$pdo = stu_pdo();
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $other = $uid + 1;
  $guest = $uid + 2;
  $s = bin2hex(random_bytes(4));
  $user = $pdo->prepare('INSERT INTO stu_users (id, username, password_hash, is_guest, permission_level, created_at) VALUES (?, ?, ?, ?, 4, NOW())');
  $user->execute([$uid, "lena-{$s}@coreui.invalid", password_hash('alt-passwort-123', PASSWORD_DEFAULT), 0]);
  $user->execute([$other, "max-{$s}@coreui.invalid", password_hash('anderes-passwort', PASSWORD_DEFAULT), 0]);
  $user->execute([$guest, "gast-{$s}", null, 1]);
  $login = $pdo->prepare('INSERT INTO stu_auth_sessions (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))');
  $login->execute([$uid, hash('sha256', "a{$s}")]);
  $login->execute([$uid, hash('sha256', "b{$s}")]);
  $login->execute([$other, hash('sha256', "c{$s}")]);
  $pdo->prepare("INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name) VALUES (?, 'Lena', 'Mara')")->execute([$uid]);

  manage_test(coreui_account_login_count($pdo, $uid) === 2, 'two active logins');
  // New password: old one no longer works, every login of the account ends, others keep theirs.
  try { coreui_account_set_password($pdo, $uid, 'kurz'); manage_test(false, 'short password accepted'); }
  catch (InvalidArgumentException $e) { manage_test($e->getMessage() === 'invalid_password', 'short password refused'); }
  manage_test(coreui_account_set_password($pdo, $uid, 'neues-passwort-2026') === 2, 'two logins ended');
  $hash = $pdo->prepare('SELECT password_hash, password_changed_at FROM stu_users WHERE id = ?');
  $hash->execute([$uid]);
  $row = $hash->fetch(PDO::FETCH_ASSOC);
  manage_test(password_verify('neues-passwort-2026', (string)$row['password_hash']) && !password_verify('alt-passwort-123', (string)$row['password_hash'])
    && !empty($row['password_changed_at']), 'new password stored, old one gone');
  manage_test(coreui_account_login_count($pdo, $uid) === 0 && coreui_account_login_count($pdo, $other) === 1, 'only this account signed out');
  try { coreui_account_set_password($pdo, $guest, 'gast-passwort-123'); manage_test(false, 'guest got a password'); }
  catch (RuntimeException $e) { manage_test($e->getMessage() === 'guest_account', 'guests have no password'); }
  // Signing out everywhere.
  $login->execute([$uid, hash('sha256', "d{$s}")]);
  manage_test(coreui_account_logout_all($pdo, $uid) === 1 && coreui_account_login_count($pdo, $uid) === 0, 'logout everywhere');
  // Name and e-mail.
  $done = coreui_account_set_identity($pdo, $uid, '  Lena  Berg ', " LENA.BERG-{$s}@Example.test ");
  manage_test($done['username'] === "lena.berg-{$s}@example.test" && $done['display_name'] === 'Lena Berg' && $done['email_changed'], 'name and e-mail cleaned and stored');
  $check = $pdo->prepare('SELECT u.username, p.display_name FROM stu_users u JOIN stu_coreui_profiles p ON p.user_id = u.id WHERE u.id = ?');
  $check->execute([$uid]);
  manage_test($check->fetch(PDO::FETCH_ASSOC) === ['username' => "lena.berg-{$s}@example.test", 'display_name' => 'Lena Berg'], 'stored in the database');
  try { coreui_account_set_identity($pdo, $uid, 'Lena', "max-{$s}@coreui.invalid"); manage_test(false, 'taken e-mail accepted'); }
  catch (RuntimeException $e) { manage_test($e->getMessage() === 'username_taken', 'an e-mail belongs to one account'); }
  try { coreui_account_set_identity($pdo, $uid, 'Lena', 'keine-mail'); manage_test(false, 'invalid e-mail accepted'); }
  catch (InvalidArgumentException $e) { manage_test($e->getMessage() === 'invalid_username', 'invalid e-mail refused'); }
  try { coreui_account_set_identity($pdo, $uid, '   ', "lena.berg-{$s}@example.test"); manage_test(false, 'empty name accepted'); }
  catch (InvalidArgumentException $e) { manage_test($e->getMessage() === 'invalid_display_name', 'empty name refused'); }
  $g = coreui_account_set_identity($pdo, $guest, 'Besuch', 'versuch@example.test');
  manage_test($g['username'] === "gast-{$s}" && !$g['email_changed'] && $g['display_name'] === 'Besuch', 'a guest keeps its login, gets a name (profile created)');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
$gone = $pdo->prepare('SELECT COUNT(*) FROM stu_users WHERE id = ?');
$gone->execute([$uid]);
manage_test((int)$gone->fetchColumn() === 0, 'no test account stays behind');
// The user list query with the login count runs on MariaDB.
$list = $pdo->query('SELECT u.id, (SELECT COUNT(*) FROM stu_auth_sessions a WHERE a.user_id = u.id AND a.revoked_at IS NULL AND a.expires_at > NOW()) AS login_count '
  . 'FROM stu_users u LEFT JOIN stu_console_sessions s ON s.user_id = u.id GROUP BY u.id LIMIT 1');
manage_test($list !== false, 'user list query');

echo "CoreAI account settings: rules, wiring, password, signing out, name and e-mail on the database (rolled back) OK\n";
