<?php
declare(strict_types=1);
// CoreAI 0.5.79: CoreAI roles instead of game ranks, and guests on the
// default Core. Roles are Inhaber (0), Admin (1) and Nutzer (4), guests are a
// flag; game and chat moderators (2, 3) become Nutzer. A guest cannot switch
// the Core or the context window, because all accounts share the RAM.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/helpers.php';
require_once $root . '/api/ai_settings.php';

function roles_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Roles FAIL: {$why}\n"); exit(1); }
}

// Roles.
roles_test(COREUI_ROLE_LEVELS === [0, 1, 4], 'three roles');
roles_test(coreui_role_name(0) === 'Inhaber' && coreui_role_name(1) === 'Admin' && coreui_role_name(4) === 'Nutzer'
  && coreui_role_name(4, true) === 'Gast' && coreui_role_name(2) === 'Nutzer', 'visible names');
roles_test(stu_permission_label(0) === 'owner' && stu_permission_label(1) === 'admin' && stu_permission_label(3) === 'user', 'machine names');
roles_test(coreui_role_level(2) === 4 && coreui_role_level(3) === 4 && coreui_role_level(1) === 1 && coreui_role_level(9) === 4, 'old ranks fold into Nutzer');
roles_test(stu_is_staff_level(0) && stu_is_staff_level(1) && !stu_is_staff_level(2) && !stu_is_staff_level(4), 'staff are Inhaber and Admin');
$helpers = (string)file_get_contents($root . '/api/helpers.php');
roles_test(!str_contains($helpers, 'game_moderator') && !str_contains($helpers, 'chat_moderator'), 'no game ranks left in the helpers');
$migration = (string)file_get_contents($root . '/database/migrations/019_coreai_roles.sql');
roles_test(str_contains($migration, 'UPDATE stu_users SET permission_level = 4 WHERE permission_level IN (2, 3);')
  && str_contains($migration, "VALUES ('019_coreai_roles')"), 'migration 019');
$chat = (string)file_get_contents($root . '/api/chat.php');
roles_test(str_contains($chat, 'return ($perm >= 0 && $perm <= COREUI_ROLE_ADMIN);')
  && str_contains($chat, "if (!in_array(\$lvl, COREUI_ROLE_LEVELS, true)) return ['ok'=>false,'error'=>'invalid_level'"), 'chat commands know the roles');
$admin = (string)file_get_contents($root . '/api/admin.php');
roles_test(str_contains($admin, "=== COREUI_ROLE_ADMIN ? COREUI_ROLE_ADMIN : COREUI_ROLE_USER;")
  && str_contains($admin, "if (!in_array(\$requestedLevel, COREUI_ROLE_LEVELS, true)) stu_json(['ok' => false, 'error' => 'invalid_role'], 400);")
  && str_contains($admin, "'error' => 'guest_role_fixed'"), 'admin: new Admin or Nutzer, only valid roles, guests stay guests');
$adminJs = (string)file_get_contents($root . '/js/admin.js');
roles_test(str_contains($adminJs, "var ROLE_OPTIONS = [[0, 'INHABER'], [1, 'ADMIN'], [4, 'NUTZER']];") && str_contains($adminJs, "[[4, 'GAST']]")
  && !str_contains($adminJs, 'CHAT-MOD'), 'admin page shows the roles');
$adminHtml = (string)file_get_contents($root . '/admin/index.html');
roles_test(str_contains($adminHtml, '<option value="4">NUTZER</option><option value="1">ADMIN</option>') && !str_contains($adminHtml, 'CHAT-MOD'), 'new account form');
roles_test(str_contains((string)file_get_contents($root . '/js/settings.js'), "data.is_guest ? 'GAST' : (lvl === 0 ? 'INHABER'"), 'settings show the role');
roles_test(str_contains((string)file_get_contents($root . '/api/auth.php'), "'role' => coreui_role_name(\$lvl, \$guest !== null),"), 'me tells the role');

// Guests on the default Core.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, is_guest INTEGER)');
$pdo->exec('INSERT INTO stu_users (id, is_guest) VALUES (1, 0), (2, 1)');
roles_test(!coreui_ai_account_is_guest($pdo, 1) && coreui_ai_account_is_guest($pdo, 2) && !coreui_ai_account_is_guest($pdo, 99), 'guest detection');
$ai = (string)file_get_contents($root . '/api/ai_settings.php');
roles_test(str_contains($ai, "if (coreui_ai_account_is_guest(\$pdo, \$uid)) {\n    \$settings['model_override'] = '';\n    \$settings['num_ctx'] = null;"),
  'at runtime a guest always has the default Core and window');
$settings = (string)file_get_contents($root . '/api/user_settings.php');
$core = substr($settings, (int)strpos($settings, "if ((\$body['action'] ?? '') === 'core') {"));
roles_test(strpos($core, "if (\$isGuest) stu_json(['ok'=>false, 'error'=>'guest_core_locked'], 403);") < strpos($core, 'coreui_core_preload('),
  'a guest cannot switch the Core (and cannot unload it for others)');
roles_test(str_contains($settings, "if (\$wantsModel || \$wantsCtx) stu_json(['ok'=>false, 'error'=>'guest_core_locked'], 403);")
  && str_contains($settings, "'is_guest' => \$isGuest,"), 'KI-Profil: no Core or window for guests');
$mode = (string)file_get_contents($root . '/js/coreui-core-mode.js');
roles_test(str_contains($mode, 'state.guest = data.is_guest === true;') && str_contains($mode, 'Als Gast chattest du mit dem Standard-Core.'),
  'Core menu explains it');
roles_test(str_contains((string)file_get_contents($root . '/api/core_install.php'), 'const COREUI_CORE_INSTALL_PERMISSION_MAX = 1;'),
  'install, update, removal and cloud stay admin only');

echo "CoreAI roles and guests: Inhaber, Admin, Nutzer, Gast; old ranks folded; guests on the default Core OK\n";
