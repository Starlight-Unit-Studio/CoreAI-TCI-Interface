<?php
declare(strict_types=1);
// CoreAI 0.5.84: deleting an account under Admin Core > Benutzer.
// Offline: who may delete whom, the typed confirmation, file removal only in
// its own folder, and the wiring. With a database (api/config.local.php, or
// COREUI_SELFTEST_DB=1 with COREUI_DB_* set): a test account with chats,
// files, knowledge and memories is deleted inside a transaction that is rolled
// back, so no test row stays in the database.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/account_delete.php';

function delete_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Account delete FAIL: {$why}\n"); exit(1); }
}

// Who may delete whom: never oneself, never an Inhaber or Mara, an Admin only Nutzer and Gaeste.
$owner = 10; $admin = 11;
$cases = [
  [$owner, 0, ['id' => 20, 'permission_level' => 4], ''],
  [$owner, 0, ['id' => 21, 'permission_level' => 1], ''],
  [$owner, 0, ['id' => 22, 'permission_level' => 4, 'is_guest' => 1], ''],
  [$owner, 0, ['id' => $owner, 'permission_level' => 0], 'self_delete_blocked'],
  [$owner, 0, ['id' => 30, 'permission_level' => 0], 'protected_account'],
  [$owner, 0, ['id' => STU_EMBER_USER_ID, 'permission_level' => 4], 'protected_account'],
  [$admin, 1, ['id' => 20, 'permission_level' => 4], ''],
  [$admin, 1, ['id' => 21, 'permission_level' => 1], 'protected_account'],
  [$admin, 1, ['id' => 30, 'permission_level' => 0], 'protected_account'],
  [$admin, 1, ['id' => $admin, 'permission_level' => 1], 'self_delete_blocked'],
  [12, 4, ['id' => 20, 'permission_level' => 4], 'protected_account'],
  [$owner, 0, ['id' => 0], 'invalid_user'],
];
foreach ($cases as $i => [$actor, $level, $target, $expected]) {
  $got = coreui_account_delete_refusal($actor, $level, $target);
  delete_test($got === $expected, "rule {$i}: expected '{$expected}', got '{$got}'");
}
// The typed confirmation names the account.
delete_test(coreui_account_delete_confirmed(['id' => 5, 'username' => 'Lena@Example.test'], ' lena@example.test '), 'confirmation ignores case and spaces');
delete_test(!coreui_account_delete_confirmed(['id' => 5, 'username' => 'lena@example.test'], 'lena'), 'a partial name is not enough');
delete_test(!coreui_account_delete_confirmed(['id' => 5, 'username' => 'lena@example.test'], ''), 'empty is not enough');
delete_test(coreui_account_delete_confirmed(['id' => 5, 'username' => ''], '5'), 'accounts without e-mail confirm with their id');

// Files only by bare name in their own folder; no traversal, no symlink out.
$work = sys_get_temp_dir() . '/coreai-delete-test-' . bin2hex(random_bytes(4));
mkdir($work . '/box', 0777, true);
file_put_contents($work . '/box/a.pdf', 'a');
file_put_contents($work . '/outside.txt', 'keep');
symlink($work . '/outside.txt', $work . '/box/link.txt');
delete_test(coreui_account_delete_named_files($work . '/box', ['a.pdf', '../outside.txt', 'link.txt', 'missing.bin', '']) === 1, 'one file deleted');
delete_test(!is_file($work . '/box/a.pdf') && is_file($work . '/outside.txt'), 'nothing outside the folder touched');
@unlink($work . '/box/link.txt'); @unlink($work . '/outside.txt'); @rmdir($work . '/box'); @rmdir($work);

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$adminApi = $read('api/admin.php');
delete_test(str_contains($adminApi, "require_once __DIR__ . '/account_delete.php';") && str_contains($adminApi, "if (\$action === 'user_delete') {")
  && strpos($adminApi, 'stu_require_csrf();') < strpos($adminApi, "if (\$action === 'user_delete') {"), 'admin API: user_delete behind CSRF');
delete_test(str_contains($adminApi, '$refusal = coreui_account_delete_refusal($actorId, $actorLevel, $target);')
  && str_contains($adminApi, "'error' => 'delete_confirmation_required'") && str_contains($adminApi, "coreui_admin_audit(\$pdo, \$actorId, 'user_delete', 'user'")
  && str_contains($adminApi, "'error' => 'account_busy'], 409"), 'admin API: rules, confirmation, busy, audit');
$adminJs = $read('js/admin.js');
delete_test(str_contains($adminJs, "post('user_delete', { user_id: Number(user.id), confirm: input.value.trim() })")
  && str_contains($adminJs, 'function canDelete(user)') && str_contains($adminJs, "'ENDGÜLTIG LÖSCHEN'"), 'Admin Core: delete button with typed confirmation');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI account delete: rules, confirmation, files and wiring OK (no database here)\n";
  exit(0);
}

require_once $root . '/api/db.php';
$pdo = stu_pdo();
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $other = $uid + 1;
  $boss = $uid + 2;
  $s = bin2hex(random_bytes(4));
  $user = $pdo->prepare('INSERT INTO stu_users (id, username, password_hash, is_guest, permission_level, created_at) VALUES (?, ?, NULL, 0, ?, NOW())');
  $user->execute([$uid, "delete-me-{$s}@coreui.invalid", 4]);
  $user->execute([$other, "keep-me-{$s}@coreui.invalid", 4]);
  $user->execute([$boss, "owner-{$s}@coreui.invalid", 0]);
  $pdo->prepare('INSERT INTO stu_characters (id, user_id, name, name_norm) VALUES (?, ?, ?, ?)')->execute(["c_{$s}", $uid, 'Del', "del{$s}"]);
  $session = $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, title, since_id, created_at, updated_at, archived_at) VALUES (?, ?, ?, 0, NOW(), NOW(), ?)');
  $session->execute(["cs_del_a_{$s}", $uid, 'Aktiv', null]);
  $session->execute(["cs_del_b_{$s}", $uid, 'Archiv', date('Y-m-d H:i:s')]);
  $session->execute(["cs_keep_{$s}", $other, 'Fremd', null]);
  $media = $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, orig_name, stored_name, rel_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())');
  $media->execute(["m1{$s}", $uid, 'a.pdf', "m1{$s}.pdf", "var/console_media/m1{$s}.pdf", 'application/pdf']);
  $media->execute(["m2{$s}", $uid, 'b.png', "m2{$s}.png", "var/console_media/m2{$s}.png", 'image/png']);
  $msg = $pdo->prepare("INSERT INTO stu_chat_messages (channel, session_id, user_id, character_id, character_name, message, file_uuid, reply_to_id, created_at) VALUES ('console', ?, ?, ?, ?, ?, ?, ?, NOW())");
  $msg->execute(["cs_del_a_{$s}", $uid, "c_{$s}", 'Del', 'Hallo', "m1{$s}", null]);
  $turn = (int)$pdo->lastInsertId();
  $msg->execute(["cs_del_a_{$s}", $uid, 'ember', 'Mara', 'Antwort', null, $turn]);
  $msg->execute(["cs_del_b_{$s}", $uid, "c_{$s}", 'Del', 'Alt', null, null]);
  $msg->execute(["cs_keep_{$s}", $other, 'x', 'Keep', 'Bleibt', null, null]);
  $pdo->prepare('INSERT INTO stu_user_knowledge_sources (uuid, user_id, title, original_name, stored_name, mime_type) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([str_pad($s, 32, 'a'), $uid, 'Handbuch', 'h.pdf', "k{$s}.pdf", 'application/pdf']);
  $pdo->prepare('INSERT INTO stu_user_knowledge_chunks (source_uuid, user_id, title, chunk_no, chunk_text) VALUES (?, ?, ?, 1, ?)')
    ->execute([str_pad($s, 32, 'a'), $uid, 'Handbuch', 'Text']);
  $memory = $pdo->prepare('INSERT INTO ember_memories (fact, relevance, scope, user_id, character_id, fact_hash) VALUES (?, 5, ?, ?, ?, ?)');
  $memory->execute(['Mag Tee', 'user', $uid, null, md5("u{$s}")]);
  $memory->execute(['Figur mag Regen', 'character', null, "c_{$s}", md5("c{$s}")]);
  $memory->execute(['Server steht in Bonn', 'global', $uid, null, md5("g{$s}")]);
  $memory->execute(['Mag Kaffee', 'user', $other, null, md5("o{$s}")]);
  $pdo->prepare('INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, ?, NOW())')->execute([$uid, 'compact_x', '{}']);
  $pdo->prepare('INSERT INTO stu_user_ai_settings (user_id) VALUES (?)')->execute([$uid]);
  $pdo->prepare('INSERT INTO stu_coreui_profiles (user_id, display_name) VALUES (?, ?)')->execute([$uid, 'Del']);
  $pdo->prepare("INSERT INTO stu_coreui_profile_media (uuid, user_id, slot, original_name, stored_name, mime_type, file_size) VALUES (?, ?, 'user', 'me.png', ?, 'image/png', 1)")
    ->execute([str_pad($s, 32, 'b'), $uid, "p{$s}.png"]);
  $pdo->prepare('INSERT INTO stu_auth_sessions (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))')->execute([$uid, hash('sha256', $s)]);
  $pdo->prepare('INSERT INTO stu_user_model_access (user_id, model_name, granted_by) VALUES (?, ?, ?)')->execute([$uid, 'gpt-oss:120b-cloud', $boss]);
  $job = $pdo->prepare("INSERT INTO stu_ember_browse_jobs (status, goal, channel, recipient_uid, trigger_user_id) VALUES (?, 'Suche', 'console', ?, ?)");
  $job->execute(['done', $uid, $uid]);
  $doneJob = (int)$pdo->lastInsertId();

  // The Inhaber and Mara are never deleted, even when asked directly.
  foreach ([$boss, STU_EMBER_USER_ID] as $protected) {
    $pdo->exec('SAVEPOINT protected_try');
    try { coreui_account_delete_tx($pdo, $protected); delete_test(false, 'protected account deleted: ' . $protected); }
    catch (RuntimeException $e) { delete_test(in_array($e->getMessage(), ['protected_account', 'user_not_found'], true), 'protected: ' . $e->getMessage()); }
    $pdo->exec('ROLLBACK TO SAVEPOINT protected_try');
  }
  // Running work: the account waits, nothing is deleted.
  $pdo->exec('SAVEPOINT busy_try');
  $job->execute(['running', $uid, $uid]);
  try { coreui_account_delete_tx($pdo, $uid); delete_test(false, 'deleted while a job runs'); }
  catch (RuntimeException $e) { delete_test($e->getMessage() === 'account_busy', 'busy: ' . $e->getMessage()); }
  $pdo->exec('ROLLBACK TO SAVEPOINT busy_try');

  $result = coreui_account_delete_tx($pdo, $uid);
  delete_test($result['sessions_deleted'] === 2 && $result['messages_deleted'] === 3, 'chats and messages: ' . json_encode($result));
  delete_test($result['knowledge_sources_deleted'] === 1 && $result['memories_deleted'] === 2, 'knowledge and memories');
  sort($result['media_paths']);
  delete_test($result['media_paths'] === ["uploads/ember_browse/job_{$doneJob}.png", "var/console_media/m1{$s}.pdf", "var/console_media/m2{$s}.png"],
    'files to remove: ' . json_encode($result['media_paths']));
  delete_test($result['knowledge_files'] === ["k{$s}.pdf"] && $result['profile_files'] === ["p{$s}.png"], 'knowledge and avatar files');

  $left = [];
  foreach ([
    'stu_users WHERE id = ?', 'stu_characters WHERE user_id = ?', 'stu_console_sessions WHERE user_id = ?', 'stu_chat_messages WHERE user_id = ?',
    'stu_console_media WHERE user_id = ?', 'stu_user_knowledge_sources WHERE user_id = ?', 'stu_user_knowledge_chunks WHERE user_id = ?',
    "ember_memories WHERE user_id = ?", 'stu_kv WHERE user_id = ?', 'stu_user_ai_settings WHERE user_id = ?', 'stu_coreui_profiles WHERE user_id = ?',
    'stu_coreui_profile_media WHERE user_id = ?', 'stu_auth_sessions WHERE user_id = ?', 'stu_user_model_access WHERE user_id = ?',
    'stu_ember_browse_jobs WHERE recipient_uid = ?',
  ] as $where) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $where);
    $count->execute([$uid]);
    if ((int)$count->fetchColumn() !== 0) $left[] = $where;
  }
  delete_test($left === [], 'rows left: ' . implode(', ', $left));
  $check = static function (string $sql, array $params) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
  };
  delete_test($check("SELECT COUNT(*) FROM ember_memories WHERE fact_hash = ? AND scope = 'global' AND user_id IS NULL", [md5("g{$s}")]) === 1,
    'a global fact stays for everyone, unlinked');
  delete_test($check('SELECT COUNT(*) FROM ember_memories WHERE user_id = ?', [$other]) === 1
    && $check('SELECT COUNT(*) FROM stu_chat_messages WHERE user_id = ?', [$other]) === 1
    && $check('SELECT COUNT(*) FROM stu_console_sessions WHERE user_id = ?', [$other]) === 1
    && $check('SELECT COUNT(*) FROM stu_users WHERE id IN (?, ?)', [$other, $boss]) === 2, 'other accounts untouched');
  delete_test((int)$pdo->query('SELECT @@in_transaction')->fetchColumn() === 1, 'still inside the transaction (no implicit commit)');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}
$gone = $pdo->prepare('SELECT COUNT(*) FROM stu_users WHERE id = ?');
$gone->execute([$uid]);
delete_test((int)$gone->fetchColumn() === 0, 'no test account stays behind');

echo "CoreAI account delete: rules, confirmation, files, wiring and a full deletion on the database (rolled back) OK\n";
