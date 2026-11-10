<?php
declare(strict_types=1);
// CoreAI 0.6.86: Settings > Konto (name, Benutzername, e-mail, plan, Erstellerprofil), offline.
//   php scripts/account-page-selftest.php
// Benutzername rules (3 to 20 of a-z, 0-9, ".", "_", "-", reserved names, "@" and capitals
// cleaned), the store with SQLite (set, taken, same again, free after a change, suggestion
// that avoids taken names), the four plans (templates fitted to the server's windows, words for
// the page, "angepasst", no plan, Individuell, Inhaber, Gastzugang, today's count), and the
// wiring of api/profile.php, Admin Core, the page, the profile head, the export, the preflights
// and the roadmap. With COREUI_SELFTEST_DB=1 also on MariaDB (migration 031: unique key, plan
// applied through the real rules, CASCADE).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/account_page_store.php';
require_once $root . '/api/abuse_guard.php';

function a_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Account page FAIL: {$why}\n"); exit(1); }
}
function a_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// --- Benutzername rules ---------------------------------------------------------------------------
a_test(coreui_username_clean('  @Lena.Berg ') === 'lena.berg', 'clean: trim, "@", lower case');
foreach (['abc', 'lena.berg', 'l_b-2', str_repeat('a', 20), '0.0'] as $ok) a_test(coreui_username_check($ok) === '', "valid: $ok");
foreach (['ab', str_repeat('a', 21), 'lena berg', 'lena@berg', 'Lena', 'läna', 'lena/berg', ''] as $bad) {
  a_test(coreui_username_check($bad) === 'username_invalid', "invalid: $bad");
}
foreach (['admin', 'mara', 'coreai', 'inhaber', 'root', 'terra-net'] as $reserved) a_test(coreui_username_check($reserved) === 'username_reserved', "reserved: $reserved");

// --- Plans: templates fitted to the server ---------------------------------------------------------------
$full = [4096, 8192, 16384, 32768, 65536, 131072];
$cat = coreui_plan_catalog($full);
a_test(array_keys($cat) === ['free', 'go', 'plus', 'pro'], 'four plans in order');
a_test(array_column($cat, 'label') === ['Free', 'Go', 'Plus', 'Pro'], 'plan names');
a_test($cat['free']['policy'] === ['tools' => ['web' => true, 'browse' => false, 'python' => false, 'image' => false], 'daily_messages' => null, 'max_num_ctx' => 8192], 'Free writes');
a_test($cat['go']['policy']['tools'] === ['web' => true, 'browse' => false, 'python' => false, 'image' => true] && $cat['go']['policy']['max_num_ctx'] === 16384, 'Go writes');
a_test(!in_array(false, $cat['plus']['policy']['tools'], true) && $cat['plus']['policy']['max_num_ctx'] === 65536, 'Plus writes');
a_test(!in_array(false, $cat['pro']['policy']['tools'], true) && $cat['pro']['policy']['max_num_ctx'] === null, 'Pro: the largest window of the server');
$small = coreui_plan_catalog([4096, 8192, 16384, 32768]);
a_test($small['plus']['policy']['max_num_ctx'] === 32768 && $small['free']['policy']['max_num_ctx'] === 8192, 'templates fit a smaller server');
a_test(coreui_plan_catalog([4096])['go']['policy']['max_num_ctx'] === 4096 && coreui_plan_ctx(2048, [4096, 8192]) === 4096 && coreui_plan_ctx(null, $full) === null, 'window fitting');
a_test(coreui_plan_features($cat['free']['policy'], $full) === ['Unbegrenzte Textchats', 'Websuche', 'Ohne Recherche im Browser, Python und Bilderstellung', 'Kontextfenster bis 8K'], 'Free in words');
a_test(coreui_plan_features($cat['pro']['policy'], $full) === ['Unbegrenzte Textchats', 'Alle Werkzeuge: Websuche, Recherche im Browser, Python und Bilderstellung',
  'Größtes Kontextfenster dieses Servers (128K)'], 'Pro in words');
a_test(coreui_plan_features(['tools' => ['web' => false, 'browse' => false, 'python' => false, 'image' => false], 'daily_messages' => 1500, 'max_num_ctx' => 4096], $full)
  === ['Bis zu 1.500 Nachrichten pro Tag', 'Ohne Websuche, Recherche im Browser, Python und Bilderstellung', 'Kontextfenster bis 4K'], 'limits in words');
a_test(coreui_plan_matches($cat['go']['policy'], $cat['go']['policy']) && !coreui_plan_matches(array_replace($cat['go']['policy'], ['daily_messages' => 5]), $cat['go']['policy']), 'matches');
a_test(a_code(fn() => coreui_plan_apply(new PDO('sqlite::memory:'), 5, 'gold', 1, $full)) === 'invalid_plan', 'unknown plan refused');

// --- Store with SQLite ---------------------------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
a_test(!coreui_username_ready($pdo) && !coreui_plan_ready($pdo) && coreui_username_get($pdo, 1) === null && coreui_plan_get($pdo, 1) === null, 'without migration 031');
a_test(a_code(fn() => coreui_username_set($pdo, 1, 'lena')) === 'missing_schema_031', 'saving needs migration 031');
$pdo->exec('CREATE TABLE stu_usernames (user_id INTEGER PRIMARY KEY, handle TEXT NOT NULL UNIQUE, changed_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE stu_user_policies (user_id INTEGER PRIMARY KEY, tool_web INTEGER NOT NULL DEFAULT 1, tool_browse INTEGER NOT NULL DEFAULT 1,
  tool_python INTEGER NOT NULL DEFAULT 1, tool_image INTEGER NOT NULL DEFAULT 1, daily_messages INTEGER NULL, max_num_ctx INTEGER NULL, plan TEXT NULL,
  updated_by INTEGER NULL, updated_at TEXT NULL)');
$pdo->exec('CREATE TABLE stu_abuse_counters (k TEXT PRIMARY KEY, window_start TEXT NOT NULL, hits INTEGER NOT NULL, expires_at TEXT NOT NULL)');
a_test(coreui_username_suggest($pdo, 1, 'lena.berg@example.test') === 'lena.berg', 'suggestion from the e-mail');
a_test(coreui_username_set($pdo, 1, '@Lena.Berg') === 'lena.berg' && coreui_username_get($pdo, 1) === 'lena.berg', 'set');
a_test(coreui_username_set($pdo, 1, 'lena.berg') === 'lena.berg', 'the same again is fine');
a_test(a_code(fn() => coreui_username_set($pdo, 2, 'LENA.BERG')) === 'username_taken', 'taken (any case)');
a_test(a_code(fn() => coreui_username_set($pdo, 2, 'mara')) === 'username_reserved' && a_code(fn() => coreui_username_set($pdo, 2, 'x')) === 'username_invalid', 'reserved and invalid');
a_test(coreui_username_suggest($pdo, 2, 'lena.berg@example.test') === 'lena.berg2', 'suggestion avoids a taken name');
a_test(coreui_username_suggest($pdo, 3, 'a@example.test') === 'nutzera' && coreui_username_suggest($pdo, 3, 'admin@example.test') === 'admin2', 'short and reserved local parts');
a_test(strlen(coreui_username_suggest($pdo, 3, str_repeat('x', 40) . '@example.test')) === 20, 'suggestion at most 20');
coreui_username_set($pdo, 1, 'lena_b');
a_test(coreui_username_owner($pdo, 'lena.berg') === 0 && coreui_username_set($pdo, 2, 'lena.berg') === 'lena.berg', 'a changed name is free again');
a_test((int)$pdo->query('SELECT COUNT(*) FROM stu_usernames')->fetchColumn() === 2, 'one row per account');

$global = ['web' => true, 'browse' => true, 'python' => true, 'image' => false];
$sum = coreui_account_plan_summary($pdo, 7, 4, false, $global, $full);
a_test($sum['key'] === null && $sum['label'] === 'Kein Plan' && $sum['note'] === 'Für dein Konto gelten keine eigenen Limits.', 'no rules: Kein Plan');
a_test(count($sum['plans']) === 4 && $sum['plans'][1]['label'] === 'Go' && $sum['server_off'] === ['Bilderstellung'] && $sum['role'] === 'Nutzer', 'cards and server switches');
$pdo->exec("INSERT INTO stu_user_policies (user_id, tool_web, tool_browse, tool_python, tool_image, daily_messages, max_num_ctx, plan) VALUES (7, 1, 0, 0, 1, NULL, 16384, 'go')");
$sum = coreui_account_plan_summary($pdo, 7, 4, false, $global, $full);
a_test($sum['key'] === 'go' && $sum['label'] === 'Go' && !$sum['customized'] && $sum['used_today'] === null, 'plan Go');
a_test($sum['features'] === ['Unbegrenzte Textchats', 'Websuche und Bilderstellung', 'Ohne Recherche im Browser und Python', 'Kontextfenster bis 16K'], 'Go in words');
$pdo->exec('UPDATE stu_user_policies SET daily_messages = 30 WHERE user_id = 7');
coreui_abuse_count($pdo, coreui_user_policy_day_key(7), COREUI_USER_POLICY_DAY, 4);
$sum = coreui_account_plan_summary($pdo, 7, 4, false, $global, $full);
a_test($sum['label'] === 'Go (angepasst)' && $sum['customized'] && $sum['daily_messages'] === 30 && $sum['used_today'] === 4, 'changed rule: angepasst, with today');
a_test(coreui_plan_all($pdo) === ['7' => 'go'], 'Admin Core list');
$pdo->exec("UPDATE stu_user_policies SET plan = 'gold' WHERE user_id = 7");
a_test(coreui_plan_get($pdo, 7) === null && coreui_account_plan_summary($pdo, 7, 4, false, $global, $full)['label'] === 'Individuell', 'unknown stored plan: Individuell');
$owner = coreui_account_plan_summary($pdo, 1, 0, false, $global, $full);
a_test($owner['label'] === 'Inhaber' && $owner['role'] === 'Inhaber' && str_contains($owner['note'], 'keine Limits'), 'Inhaber: no limits');
$guest = coreui_account_plan_summary($pdo, 9, 4, true, $global, $full);
a_test($guest['label'] === 'Gastzugang' && $guest['features'] === [] && $guest['role'] === 'Gast', 'guest');

// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) -------------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  require_once $root . '/api/ai_settings.php';
  $my = stu_pdo();
  a_test(coreui_username_ready($my) && coreui_plan_ready($my), 'MariaDB: migration 031 applied');
  $tag = bin2hex(random_bytes(4));
  $ins = $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)");
  $ins->execute(["account-a-$tag@example.test"]);
  $ua = (int)$my->lastInsertId();
  $ins->execute(["account-b-$tag@example.test"]);
  $ub = (int)$my->lastInsertId();
  try {
    $ctx = coreui_ai_num_ctx_choices();
    a_test(coreui_username_set($my, $ua, "t.$tag") === "t.$tag" && a_code(fn() => coreui_username_set($my, $ub, "T.$tag")) === 'username_taken', 'MariaDB: unique handle');
    coreui_username_set($my, $ua, "u.$tag");
    a_test(coreui_username_get($my, $ua) === "u.$tag" && coreui_username_owner($my, "t.$tag") === 0, 'MariaDB: ON DUPLICATE KEY');
    $r = coreui_plan_apply($my, $ub, 'free', $ua, $ctx);
    a_test($r['plan'] === 'free' && $r['policy']['tools']['browse'] === false && $r['policy']['tools']['image'] === false
      && $r['policy']['max_num_ctx'] === coreui_plan_ctx(8192, $ctx), 'MariaDB: Free written as real rules');
    a_test(coreui_user_policy_tools(['web' => true, 'browse' => true, 'python' => true, 'image' => true], coreui_user_policy_load($my, $ub))
      === ['web' => true, 'browse' => false, 'python' => false, 'image' => false], 'MariaDB: the runtime sees the plan');
    $r = coreui_plan_apply($my, $ub, 'pro', $ua, $ctx);
    a_test($r['plan'] === 'pro' && !in_array(false, $r['policy']['tools'], true) && $r['policy']['max_num_ctx'] === null, 'MariaDB: Pro');
    $r = coreui_plan_apply($my, $ub, null, $ua, $ctx);
    a_test($r['plan'] === null && !in_array(false, $r['policy']['tools'], true), 'MariaDB: no plan keeps the rules');
    coreui_plan_apply($my, $ub, 'go', $ua, $ctx);
    $dbChecked = true;
  } finally {
    $my->prepare('DELETE FROM stu_users WHERE id IN (?, ?)')->execute([$ua, $ub]);
  }
  $left = $my->prepare('SELECT (SELECT COUNT(*) FROM stu_usernames WHERE user_id IN (?, ?)) + (SELECT COUNT(*) FROM stu_user_policies WHERE user_id IN (?, ?))');
  $left->execute([$ua, $ub, $ua, $ub]);
  a_test((int)$left->fetchColumn() === 0, 'MariaDB: deleted with the account (CASCADE)');
}

// --- Wiring --------------------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$mig = $read('database/migrations/031_account_page.sql');
a_test(str_contains($mig, 'UNIQUE KEY uq_usernames_handle (handle)') && str_contains($mig, 'ON DELETE CASCADE')
  && str_contains($mig, 'ADD COLUMN IF NOT EXISTS plan') && str_contains($mig, "('031_account_page')"), 'migration 031');
$api = $read('api/profile.php');
a_test(str_contains($api, "require_once __DIR__ . '/account_page_store.php';") && str_contains($api, "if ((string)(\$_GET['view'] ?? '') === 'account') {")
  && strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'username') {"), 'profile.php: account view, saving behind CSRF');
a_test(str_contains($api, "if ((int)(\$row['is_guest'] ?? 1) === 1) stu_json(['ok' => false, 'error' => 'guest_not_allowed'], 403);")
  && str_contains($api, "coreui_profile_rate_limit('username', 10, 3600);"), 'profile.php: no guests, rate limit');
a_test(str_contains($api, "\$profile = coreui_profile_save(\$pdo, \$uid, \$name, (string)(\$current['assistant_name'] ?? 'Mara'));"), 'profile.php: the CoreAI keeps its name');
$admin = $read('api/admin.php');
a_test(str_contains($admin, "if (\$action === 'user_plan') {") && str_contains($admin, "coreui_admin_audit(\$pdo, \$actorId, 'user_plan', 'user'")
  && str_contains($admin, "\$refusal = coreui_account_manage_refusal(\$actorId, \$actorLevel, \$target, false);")
  && strpos($admin, 'stu_require_csrf();') < strpos($admin, "if (\$action === 'user_plan') {"), 'admin.php: user_plan with the same rights, audit, CSRF');
a_test(str_contains($admin, "'plans' => (object)coreui_plan_all(\$pdo)") && str_contains($admin, "'plan_catalog' => coreui_plan_catalog(coreui_ai_num_ctx_choices())"), 'admin.php: plans listed');
$adminJs = $read('js/admin.js');
a_test(str_contains($adminJs, "post('user_plan', { user_id: Number(user.id), plan: planSelect.value || null })") && str_contains($adminJs, "grid.insertBefore(planBox, tools);"), 'Admin Core: plan section');
$page = $read('settings.html');
foreach (['acctRow-name', 'acctRow-username', 'acctRow-email', 'acctRow-plan', 'acctRow-creator', 'acctNameDialog', 'acctUsernameDialog', 'acctSub-plan', 'settingsLogout'] as $id) {
  a_test(str_contains($page, 'id="' . $id . '"'), "settings.html: $id");
}
a_test(str_contains($page, 'Verwende 3 bis 20 Kleinbuchstaben, Zahlen, Punkte, Unterstriche oder Bindestriche.') && str_contains($page, '>Benutzernamen bearbeiten<'), 'dialog texts');
a_test((bool)preg_match('~<script src="\./js/coreui-account-settings\.js\?v=\d{25}"></script>~', $page), 'script tag with version');
a_test(str_contains($read('api/profile_stats.php'), "'username' => coreui_username_get(\$pdo, \$uid),")
  && str_contains($read('js/coreui-profile-stats.js'), "var sub = [profile.username ? '@' + profile.username : '', profile.role || ''];"), 'profile head shows the @name');
a_test(str_contains($read('api/account_export.php'), "'account_page' => ['username' => coreui_username_get(\$pdo, \$uid), 'plan' => coreui_plan_get(\$pdo, \$uid)],"), 'account export');
a_test(str_contains($read('js/settings.js'), "document.addEventListener('coreai:profile-saved', function (event) {"), 'name shared with Profil');
a_test(str_contains($read('ROADMAP.md'), '| **P30: Workspace und Intranet-Betrieb** |') && str_contains($read('ROADMAP.md'), '**Erstellerprofil**'), 'roadmap: Workspace with Erstellerprofil');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $pf) {
  $text = $read($pf);
  a_test(str_contains($text, 'api/account_page_store.php') && str_contains($text, 'database/migrations/031_account_page.sql')
    && str_contains($text, 'js/coreui-account-settings.js') && str_contains($text, 'scripts/account-page-selftest.php')
    && str_contains($text, 'scripts/account-ui-selftest.cjs'), "$pf lists and runs the account page");
}

echo "CoreAI account page: Benutzername rules and store (taken, reserved, suggestion), plans Free/Go/Plus/Pro (templates fitted to the server, "
  . "words, angepasst, Kein Plan, Individuell, Inhaber, Gastzugang, today), wiring OK"
  . ($dbChecked ? ', MariaDB statements OK' : ', MariaDB part skipped (COREUI_SELFTEST_DB not set)') . "\n";
