<?php
declare(strict_types=1);
// CoreAI 0.7.84 (P12 B): shared skills, precedence, and the skills fixed for a chat.
//   php scripts/skill-sharing-selftest.php
// Offline: precedence and references, which variant serves a short name; the wiring (migration 053,
// endpoint, chat context, the chat page, the + menu). The store against SQLite: only the Inhaber and
// Admins share (never a project skill, never a guest), every account sees the shared skills with who
// shared them, switches one off for itself, sees which skill serves instead; a demoted sharer's
// skills disappear. A chat fixes up to three skills (one variant per short name, own, shared or
// built-in, only its account's chat), gone ones are named. Mara: fixed skills load first in exactly
// that variant, shared skills say whose they are, precedence decides when more fit than the window
// takes, /skill still wins. With COREUI_SELFTEST_DB=1 against MariaDB (migrations 052 and 053, 053
// applied twice; fixed skills go with their chat, opt-outs with their skill). Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/skill_packages_store.php';
function sks_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Skill sharing FAIL: {$why}\n"); exit(1); }
}
function sks_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ------------------------------------------------------------------------------------------
sks_test(coreui_skillpkg_priority('high') === 1 && coreui_skillpkg_priority(' LOW ') === -1 && coreui_skillpkg_priority('') === 0
  && coreui_skillpkg_priority('normal') === 0 && coreui_skillpkg_priority(1) === 1, 'precedence');
sks_test(sks_throws(static fn() => coreui_skillpkg_priority('urgent'), 'invalid_skill_priority')
  && sks_throws(static fn() => coreui_skillpkg_priority(5), 'invalid_skill_priority'), 'only three precedences');
sks_test(coreui_skillpkg_priority_name(1) === 'high' && coreui_skillpkg_priority_name(0) === 'normal' && coreui_skillpkg_priority_name(-1) === 'low', 'names');
sks_test(coreui_skillpkg_ref(str_repeat('a', 32)) === str_repeat('a', 32) && coreui_skillpkg_ref('B:Python') === 'b:python'
  && coreui_skillpkg_ref('b:zip-delivery') === 'b:zip-delivery', 'references');
foreach (['b:', 'b:../x', str_repeat('a', 31), 'own:abc', 'b:a--b'] as $bad) {
  sks_test(sks_throws(static fn() => coreui_skillpkg_ref($bad), 'invalid_skill_ref'), 'invalid reference: ' . $bad);
}
$row = static fn(string $id, string $variant, int $prio, string $created): array
  => ['id'=>$id, 'slug'=>'bericht', 'variant'=>$variant, 'priority'=>$prio, 'created_at'=>$created];
$own = $row('o', 'own', -1, '2026-11-02');
$proj = $row('p', 'project', -1, '2026-11-03');
$sharedHigh = $row('h', 'shared', 1, '2026-11-04');
$sharedOld = $row('s', 'shared', 0, '2026-11-01');
$sharedNew = $row('n', 'shared', 0, '2026-11-05');
sks_test(coreui_skillpkg_resolve([$sharedHigh, $own, $proj], [])['use']['bericht']['id'] === 'p', 'a project skill serves first');
sks_test(coreui_skillpkg_resolve([$sharedHigh, $own], [])['use']['bericht']['id'] === 'o', 'then the account\'s own, whatever the precedence');
sks_test(coreui_skillpkg_resolve([$sharedNew, $sharedOld, $sharedHigh], [])['use']['bericht']['id'] === 'h'
  && coreui_skillpkg_resolve([$sharedNew, $sharedOld], [])['use']['bericht']['id'] === 's', 'between shared ones: precedence, then the older');
$pinned = coreui_skillpkg_resolve([$own, $proj, $sharedNew], ['n']);
sks_test($pinned['use']['bericht']['id'] === 'n' && $pinned['use']['bericht']['pinned'] === true && $pinned['shadowed']['o']['id'] === 'n'
  && $pinned['shadowed']['p']['id'] === 'n', 'a chat\'s own choice serves before everything');

// --- Wiring (static) ---------------------------------------------------------------------------------
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$migration = $read('database/migrations/053_skill_sharing.sql');
sks_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS shared TINYINT(1) NOT NULL DEFAULT 0')
  && str_contains($migration, 'ADD COLUMN IF NOT EXISTS priority TINYINT NOT NULL DEFAULT 0')
  && substr_count($migration, 'ON DELETE CASCADE') === 4 && str_contains($migration, "VALUES ('053_skill_sharing')"), 'migration 053');
$endpoint = $read('api/skill_packages.php');
sks_test(substr_count($endpoint, "coreui_console_session_require(\$pdo, \$uid, ") === 2
  && strpos($endpoint, 'stu_require_csrf();') < strpos($endpoint, "case 'use':")
  && strpos($endpoint, 'stu_require_csrf();') < strpos($endpoint, "case 'chat_set':")
  && str_contains($endpoint, "'can_share'=>coreui_skillpkg_can_share(\$pdo, \$uid)")
  && str_contains($endpoint, "\$error === 'skill_share_forbidden' => 403,"), 'endpoint: chats through the console guard, changes with CSRF');
sks_test(str_contains($read('api/chat.php'), "return ['uid'=>\$uid, 'project'=>\$project, 'tools'=>\$tools, 'session'=>\$sessionId];"),
  'chat: the chat goes along for its fixed skills');
sks_test(str_contains($read('js/console-app.js'), "window.dispatchEvent(new CustomEvent('coreai:active-session', {detail: {id: String(sessionId || '')}}));"),
  'the chat app says which chat is open');
$menu = $read('js/coreui-composer-menu.js');
sks_test(str_contains($menu, 'registerSkills: function (def) {') && str_contains($menu, "box.appendChild(section('Skills', [{ id: 'skills', icon: 'book', label: 'Skills für diesen Chat'"),
  'the + menu has room for the skills of a chat');
$app = $read('app.html');
sks_test(preg_match('~<script src="\./js/coreui-chat-skills\.js\?v=\d+"></script>~', $app) === 1
  && preg_match('~<link rel="stylesheet" href="\./css/coreui-chat-skills\.css\?v=\d+" />~', $app) === 1
  && strpos($app, 'js/coreui-composer-menu.js') < strpos($app, 'js/coreui-chat-skills.js'), 'chat page loads the module after the + menu');
sks_test(!str_contains($read('js/coreui-chat-skills.js'), 'innerHTML'), 'the chat module writes text only');
$settings = $read('settings.html');
sks_test(str_contains($settings, '<h3 class="sec-heading" id="skSharedHead" hidden>Gemeinsam</h3>'), 'Settings > Skills: Gemeinsam');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI gemeinsame Skills: Vorrang, Verweise, Varianten, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite -------------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER NOT NULL, is_guest INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE stu_coreui_profiles (user_id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
$pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT)');
$pdo->exec("CREATE TABLE stu_skill_packages (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, slug TEXT NOT NULL, title TEXT NOT NULL,
  description TEXT NOT NULL, body TEXT NOT NULL, triggers TEXT NOT NULL DEFAULT '', requires_tools TEXT NOT NULL DEFAULT '', project_id TEXT,
  enabled INTEGER NOT NULL DEFAULT 1, shared INTEGER NOT NULL DEFAULT 0, priority INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT,
  UNIQUE (user_id, slug))");
$pdo->exec('CREATE TABLE stu_skill_package_files (skill_id TEXT NOT NULL, path TEXT NOT NULL, kind TEXT NOT NULL, content TEXT NOT NULL,
  size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, updated_at TEXT, PRIMARY KEY (skill_id, path))');
$pdo->exec('CREATE TABLE stu_skill_package_optouts (user_id INTEGER NOT NULL, skill_id TEXT NOT NULL, created_at TEXT, PRIMARY KEY (user_id, skill_id))');
$pdo->exec('CREATE TABLE stu_console_session_skills (session_id TEXT NOT NULL, user_id INTEGER NOT NULL, ref TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0,
  created_at TEXT, PRIMARY KEY (session_id, ref))');
$owner = 1; $admin = 2; $user = 7; $guest = 8;
$pdo->exec("INSERT INTO stu_users (id, permission_level, is_guest) VALUES (1, 0, 0), (2, 1, 0), (7, 4, 0), (8, 4, 1)");
$pdo->exec("INSERT INTO stu_coreui_profiles (user_id, display_name) VALUES (1, 'Ines')");
$project = str_repeat('c', 32);
$pdo->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, 7, 'Kunden', 'open')")->execute([$project]);
$pdo->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, 1, 'Eigenes', 'open')")->execute([str_repeat('d', 32)]);
$pdo->prepare("INSERT INTO stu_console_sessions (id, user_id, project_id, title) VALUES ('cs_free0001', 7, NULL, 'Frei'), ('cs_proj0001', 7, ?, 'Projekt'),
  ('cs_other001', 2, NULL, 'Fremd')")->execute([$project]);
sks_test(coreui_skillpkg_sharing_ready($pdo), 'readiness with migration 053');
sks_test(coreui_skillpkg_can_share($pdo, $owner) && coreui_skillpkg_can_share($pdo, $admin) && !coreui_skillpkg_can_share($pdo, $user)
  && !coreui_skillpkg_can_share($pdo, $guest) && !coreui_skillpkg_can_share($pdo, 99), 'only the Inhaber and Admins share');
$skill = static fn(int $uid, string $slug, array $more = []) => coreui_skillpkg_save($pdo, $uid, '', $more + ['slug'=>$slug,
  'title'=>ucfirst($slug), 'description'=>'Beschreibung ' . $slug, 'body'=>'Anleitung ' . $slug . ' von ' . $uid . '.', 'enabled'=>true]);

$ines = $skill($owner, 'wochenbericht', ['title'=>'Wochenbericht Ines', 'shared'=>true, 'priority'=>'high', 'triggers'=>'wochenbericht']);
$adminWb = $skill($admin, 'wochenbericht', ['title'=>'Wochenbericht Admin', 'shared'=>true]);
$protokoll = $skill($admin, 'protokoll', ['title'=>'Protokoll', 'shared'=>true, 'requires'=>['python']]);
coreui_skillpkg_file_save($pdo, $admin, $protokoll['id'], 'templates/protokoll.md', "# Protokoll\n");
sks_test($ines['shared'] === true && $ines['priority'] === 'high' && $adminWb['shared'] === true && $adminWb['priority'] === 'normal', 'shared with precedence');
sks_test(sks_throws(static fn() => $skill($user, 'teilen', ['shared'=>true]), 'skill_share_forbidden')
  && sks_throws(static fn() => $skill($owner, 'projekt', ['shared'=>true, 'project_id'=>str_repeat('d', 32)]), 'skill_share_project')
  && sks_throws(static fn() => $skill($owner, 'vorrang', ['priority'=>'urgent']), 'invalid_skill_priority'), 'no sharing for Nutzer, never a project skill');

$list = static fn(int $uid): array => array_column(coreui_skillpkg_shared_list($pdo, $uid), null, 'id');
$shared = $list($user);
sks_test(count($shared) === 3 && $shared[$ines['id']]['owner'] === 'Ines' && $shared[$adminWb['id']]['owner'] === 'Admin'
  && $shared[$ines['id']]['used'] === true && $shared[$ines['id']]['shadowed_by'] === null
  && $shared[$adminWb['id']]['shadowed_by'] === ['variant'=>'shared', 'title'=>'Wochenbericht Ines', 'owner'=>'Ines']
  && !isset($shared[$ines['id']]['body']), 'every account sees the shared skills, who shared them and which serves');
sks_test(array_keys($list($owner)) === [$protokoll['id'], $adminWb['id']], 'never one\'s own as shared (sorted by short name)');
sks_test($list($guest) === [] && array_filter(coreui_skillpkg_candidates($pdo, $guest, ''), static fn($r) => $r['variant'] === 'shared') === [],
  'guests get no shared skills');
$mine = $skill($user, 'wochenbericht', ['title'=>'Mein Wochenbericht', 'triggers'=>'wochenbericht']);
$shared = $list($user);
sks_test($shared[$ines['id']]['shadowed_by'] === ['variant'=>'own', 'title'=>'Mein Wochenbericht']
  && $shared[$adminWb['id']]['shadowed_by']['variant'] === 'own', 'the account\'s own skill serves before shared ones');
$view = coreui_skillpkg_shared_get($pdo, $user, $protokoll['id']);
sks_test($view['body'] === 'Anleitung protokoll von 2.' && $view['files'][0]['content'] === "# Protokoll\n" && $view['owner'] === 'Admin'
  && sks_throws(static fn() => coreui_skillpkg_shared_get($pdo, $user, $mine['id']), 'skill_not_found')
  && sks_throws(static fn() => coreui_skillpkg_shared_get($pdo, $guest, $protokoll['id']), 'skill_not_found'), 'read a shared skill, never another\'s private one');
coreui_skillpkg_use_shared($pdo, $user, $ines['id'], false);
sks_test($list($user)[$ines['id']]['used'] === false && !in_array($ines['id'], array_column(coreui_skillpkg_candidates($pdo, $user, ''), 'id'), true)
  && in_array($ines['id'], array_column(coreui_skillpkg_candidates($pdo, $admin, ''), 'id'), true), 'switched off only for this account');
coreui_skillpkg_use_shared($pdo, $user, $ines['id'], true);
coreui_skillpkg_use_shared($pdo, $user, $ines['id'], true);
sks_test($list($user)[$ines['id']]['used'] === true, 'and on again');
sks_test(sks_throws(static fn() => coreui_skillpkg_use_shared($pdo, $user, $mine['id'], false), 'skill_not_found'), 'only shared skills of others');
$pdo->exec('UPDATE stu_users SET permission_level = 4 WHERE id = 2');
sks_test(array_keys($list($user)) === [$ines['id']] && !coreui_skillpkg_can_share($pdo, $admin), 'a demoted sharer\'s skills disappear');
$pdo->exec('UPDATE stu_users SET permission_level = 1 WHERE id = 2');
coreui_skillpkg_save($pdo, $admin, $adminWb['id'], ['slug'=>'wochenbericht', 'title'=>'Wochenbericht Admin', 'description'=>'Beschreibung', 'body'=>'Neu.',
  'enabled'=>true, 'shared'=>false]);
sks_test(!isset($list($user)[$adminWb['id']]), 'not shared any more');
coreui_skillpkg_save($pdo, $admin, $adminWb['id'], ['slug'=>'wochenbericht', 'title'=>'Wochenbericht Admin', 'description'=>'Beschreibung', 'body'=>'Neu.',
  'enabled'=>true, 'shared'=>true]);

// --- A chat's own choice ------------------------------------------------------------------------------
$options = coreui_skillpkg_chat_options($pdo, $user, 'cs_free0001');
$byRef = array_column($options['options'], null, 'ref');
sks_test($options['chosen'] === [] && $options['gone'] === [] && $options['max'] === 3 && $options['project'] === false
  && $byRef[$mine['id']]['used'] === true && $byRef[$ines['id']]['used'] === false && $byRef[$protokoll['id']]['used'] === true
  && $byRef[$ines['id']]['variant'] === 'shared' && $byRef[$ines['id']]['owner'] === 'Ines' && $byRef[$ines['id']]['priority'] === 'high'
  && isset($byRef['b:python']) && $byRef['b:python']['variant'] === 'builtin' && !isset($byRef['b:werkzeuge']), 'a chat\'s options, every variant');
$byRefProj = array_column(coreui_skillpkg_chat_options($pdo, $user, 'cs_proj0001')['options'], null, 'ref');
$kunden = $skill($user, 'kundenbrief', ['project_id'=>$project]);
$byRefProj = array_column(coreui_skillpkg_chat_options($pdo, $user, 'cs_proj0001')['options'], null, 'ref');
sks_test(isset($byRefProj[$kunden['id']]) && $byRefProj[$kunden['id']]['variant'] === 'project'
  && !isset(array_column(coreui_skillpkg_chat_options($pdo, $user, 'cs_free0001')['options'], null, 'ref')[$kunden['id']]), 'project skills only in their project');
$set = coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [$adminWb['id'], 'b:python']);
$byRef = array_column($set['options'], null, 'ref');
sks_test($set['chosen'] === [$adminWb['id'], 'b:python'] && $byRef[$adminWb['id']]['chosen'] === true && $byRef[$adminWb['id']]['used'] === true
  && $byRef[$mine['id']]['used'] === false, 'a chat fixes a variant: it serves before the account\'s own');
sks_test(sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [$adminWb['id'], $ines['id']]), 'skill_variant_twice')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', ['b:python', 'b:zip-delivery', $protokoll['id'], $mine['id']]), 'skill_chat_limit')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [str_repeat('f', 32)]), 'skill_not_found')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', ['b:werkzeuge']), 'skill_not_found')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [$kunden['id']]), 'skill_not_found')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_other001', ['b:python']), 'session_not_found')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', 'b:python'), 'invalid_skill_ref')
  && sks_throws(static fn() => coreui_skillpkg_chat_set($pdo, $user, 'x', []), 'invalid_session'), 'the limits of a chat\'s choice');
sks_test(coreui_skillpkg_chat_refs($pdo, $user, 'cs_free0001') === [$adminWb['id'], 'b:python'], 'a refused choice changes nothing');
sks_test(coreui_skillpkg_chat_refs($pdo, $admin, 'cs_free0001') === [], 'only for its own account');

// --- Mara ----------------------------------------------------------------------------------------------
$prepare = static function (string $request, string $session, int $budget = 4, string $project = '', string $channel = 'console') use ($pdo, $user): string {
  coreui_skills_prepare($pdo, $request, $channel, $budget, ['uid'=>$user, 'project'=>$project, 'tools'=>['web'], 'session'=>$session]);
  return (string)$GLOBALS['COREUI_SKILLS_BLOCK'];
};
$block = $prepare('Hallo, wie geht es?', 'cs_free0001');
sks_test(array_slice($GLOBALS['COREUI_SKILLS_LOADED'], 0, 2) === ['wochenbericht', 'python'], 'the fixed skills load first, in their order');
sks_test(str_contains($block, "[GEMEINSAMER SKILL wochenbericht: Wochenbericht Admin, von Admin]\n(Fuer diesen Chat festgelegt: nutze genau diese Fassung.)\nNeu.\n")
  && !str_contains($block, 'Mein Wochenbericht'), 'exactly the fixed variant, saying whose it is');
sks_test(str_contains($block, "- protokoll: Beschreibung protokoll (gemeinsamer Skill von Admin, braucht Python, fehlt hier)\n"), 'shared skills in the library');
$block = $prepare('Bitte den Wochenbericht', 'cs_proj0001', 4, $project);
sks_test(str_contains($block, "[EIGENER SKILL wochenbericht: Mein Wochenbericht]\n") && !str_contains($block, 'Fuer diesen Chat festgelegt')
  && str_contains($block, '- kundenbrief: Beschreibung kundenbrief (Projekt-Skill)'), 'another chat of the account: its own variant');
$block = $prepare('Hallo', 'cs_free0001', 1);
sks_test($GLOBALS['COREUI_SKILLS_LOADED'] === ['wochenbericht']
  && str_contains($block, 'Fuer diesen Chat festgelegt, aber noch nicht geladen: python; lade sie, wenn die Aufgabe sie braucht.'),
  'a small window: the rest of the fixed skills are named');
sks_test(str_contains(coreui_skills_tool_result('wochenbericht'), 'schon geladen'), 'loading by name gives the fixed variant that is there');
$prepare('Hallo', 'cs_free0001', 4, '', 'game');
sks_test(($GLOBALS['COREUI_SKILLS_PINNED'] ?? null) === [] && (string)$GLOBALS['COREUI_SKILLS_BLOCK'] === '', 'nothing in Game E.M.B.E.R.');
coreui_skills_prepare($pdo, 'Hallo', 'console', 4);
sks_test($GLOBALS['COREUI_SKILLS_PINNED'] === [] && coreui_skills_own() === [], 'every turn starts without the skills of an earlier one');

// Precedence when more fit than the window takes; /skill still wins.
$skill($user, 'zorbel-niedrig', ['triggers'=>'zorbel, quint', 'priority'=>'low']);
$skill($user, 'zorbel-hoch', ['triggers'=>'zorbel', 'priority'=>'high']);
$prepare('zorbel quint', '', 1);
sks_test($GLOBALS['COREUI_SKILLS_LOADED'] === ['zorbel-hoch'], 'high precedence first, even with fewer matching words');
$prepare('zorbel quint', '', 2);
sks_test($GLOBALS['COREUI_SKILLS_LOADED'] === ['zorbel-hoch', 'zorbel-niedrig'], 'then the rest');
$prepare('/skill zorbel-niedrig zorbel', '', 1);
sks_test($GLOBALS['COREUI_SKILLS_LOADED'] === ['zorbel-niedrig'], '/skill before precedence');

// Gone and deleted.
coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [$protokoll['id'], 'b:python']);
coreui_skillpkg_use_shared($pdo, $user, $protokoll['id'], false);
coreui_skillpkg_delete($pdo, $admin, $protokoll['id']);
$left = $pdo->prepare('SELECT COUNT(*) FROM stu_skill_package_optouts WHERE skill_id = ?');
$left->execute([$protokoll['id']]);
$gone = coreui_skillpkg_chat_options($pdo, $user, 'cs_free0001');
sks_test($gone['gone'] === [$protokoll['id']] && (int)$left->fetchColumn() === 0, 'a deleted skill: named as gone in the chat, no opt-out left');
$after = coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', ['b:python']);
sks_test($after['gone'] === [] && $after['chosen'] === ['b:python'], 'saving again drops it');
sks_test(coreui_skillpkg_chat_set($pdo, $user, 'cs_free0001', [])['chosen'] === [], 'Mara chooses herself again');

// Without migration 053: P12 A as before.
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$old->exec('CREATE TABLE stu_skill_packages (id TEXT PRIMARY KEY, user_id INTEGER, slug TEXT, title TEXT, description TEXT, body TEXT, triggers TEXT,
  requires_tools TEXT, project_id TEXT, enabled INTEGER, created_at TEXT, updated_at TEXT)');
$old->exec('CREATE TABLE stu_skill_package_files (skill_id TEXT, path TEXT, kind TEXT, content TEXT, size_bytes INTEGER, sha256 TEXT, updated_at TEXT)');
$old->exec('CREATE TABLE stu_console_projects (id TEXT, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
$old->exec('CREATE TABLE stu_console_sessions (id TEXT, user_id INTEGER, project_id TEXT)');
$plain = coreui_skillpkg_save($old, $user, '', ['slug'=>'alt', 'title'=>'Alt', 'description'=>'D', 'body'=>'B', 'enabled'=>true]);
sks_test(!coreui_skillpkg_sharing_ready($old) && $plain['shared'] === false && $plain['priority'] === 'normal'
  && sks_throws(static fn() => coreui_skillpkg_save($old, $user, '', ['slug'=>'teilen', 'title'=>'T', 'description'=>'D', 'body'=>'B', 'shared'=>true]),
    'skill_migration_required')
  && sks_throws(static fn() => coreui_skillpkg_chat_options($old, $user, 'cs_free0001'), 'skill_migration_required')
  && coreui_skillpkg_shared_list($old, $user) === [] && array_keys(coreui_skillpkg_for_chat($old, $user, '', 'cs_free0001')) === ['alt'],
  'without migration 053 own skills work as before, nothing shared');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['052_skill_packages.sql', '053_skill_sharing.sql', '053_skill_sharing.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $tag = bin2hex(random_bytes(3));
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('sks-o-{$tag}@example.test', 0, 0, NOW())");
  $dbOwner = (int)$db->lastInsertId();
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('sks-u-{$tag}@example.test', 0, 4, NOW())");
  $dbUser = (int)$db->lastInsertId();
  try {
    $s = coreui_skillpkg_save($db, $dbOwner, '', ['slug'=>'wochenbericht-' . $tag, 'title'=>'Wochenbericht', 'description'=>'D', 'body'=>'B',
      'enabled'=>true, 'shared'=>true, 'priority'=>'high']);
    sks_test($s['shared'] === true && $s['priority'] === 'high', 'MariaDB: shared with precedence');
    $seen = array_column(coreui_skillpkg_shared_list($db, $dbUser), null, 'id');
    sks_test(isset($seen[$s['id']]) && $seen[$s['id']]['owner'] === 'Inhaber', 'MariaDB: another account sees it');
    coreui_skillpkg_use_shared($db, $dbUser, $s['id'], false);
    $session = 'cs_sks_' . $tag;
    $db->prepare('INSERT INTO stu_console_sessions (id, user_id, title, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')->execute([$session, $dbUser, 'Skills']);
    coreui_skillpkg_use_shared($db, $dbUser, $s['id'], true);
    $chosen = coreui_skillpkg_chat_set($db, $dbUser, $session, [$s['id'], 'b:python']);
    sks_test($chosen['chosen'] === [$s['id'], 'b:python'], 'MariaDB: a chat fixes skills');
    coreui_skillpkg_use_shared($db, $dbUser, $s['id'], false);
    $db->prepare('DELETE FROM stu_console_sessions WHERE id = ?')->execute([$session]);
    $n = $db->prepare('SELECT COUNT(*) FROM stu_console_session_skills WHERE session_id = ?');
    $n->execute([$session]);
    sks_test((int)$n->fetchColumn() === 0, 'MariaDB: fixed skills go with their chat');
    $db->prepare('DELETE FROM stu_skill_packages WHERE id = ?')->execute([$s['id']]);
    $n = $db->prepare('SELECT COUNT(*) FROM stu_skill_package_optouts WHERE skill_id = ?');
    $n->execute([$s['id']]);
    sks_test((int)$n->fetchColumn() === 0, 'MariaDB: opt-outs go with their skill');
    $mariadb = ', MariaDB (Migration 052 und 053)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id IN (?, ?)')->execute([$dbOwner, $dbUser]);
  }
}

echo "CoreAI gemeinsame Skills: Vorrang, Verweise, Varianten, Verdrahtung, Teilen nur fuer Inhaber und Admins, Gemeinsam mit Absender und "
  . "Abschalten je Konto, Skills je Chat (hoechstens drei, eine Fassung je Kennung, verschwundene benannt), Mara laedt festgelegte zuerst "
  . "in genau dieser Fassung, Vorrang bei knappem Kontextfenster OK (SQLite{$mariadb})\n";
