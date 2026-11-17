<?php
declare(strict_types=1);
// CoreAI 0.7.83 (P12 A): the account's own skills.
//   php scripts/skill-packages-selftest.php
// Offline: the short name, trigger words, requirements, file paths and the files an instruction names
// but lacks; the wiring (endpoint for accounts only with CSRF, nginx, chat context, Settings > Skills,
// migration 052). The store against SQLite: create, change, limits, companion files, rename, delete,
// never another account's. Mara with her own skills: in the library with requirements, found by
// trigger and /skill, loaded with [SKILL: ...] with a template, a script only as text, data and
// references, missing tools and files named, project skills only in that project, nothing that is
// off, nothing in Game E.M.B.E.R. With COREUI_SELFTEST_DB=1 against MariaDB (migration 052, applied
// twice; skills go with their project and their account). Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/skill_packages_store.php';
function skp_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Skill packages FAIL: {$why}\n"); exit(1); }
}
function skp_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ------------------------------------------------------------------------------------------
skp_test(coreui_skillpkg_slug(' Wochen-Bericht ') === 'wochen-bericht' && coreui_skillpkg_slug('a1') === 'a1', 'short name');
foreach (['a', 'mit leerzeichen', '-vorne', 'hinten-', 'dop--pelt', 'umlaut-ä', str_repeat('a', 65), '../x'] as $bad) {
  skp_test(skp_throws(static fn() => coreui_skillpkg_slug($bad), 'invalid_skill_slug'), 'invalid short name: ' . $bad);
}
skp_test(skp_throws(static fn() => coreui_skillpkg_slug('python'), 'skill_builtin_name'), 'never the name of a built-in skill');
skp_test(coreui_skillpkg_triggers("Wochenbericht, Statusbericht\n wochenbericht ,") === ['wochenbericht', 'statusbericht'], 'trigger words');
skp_test(coreui_skillpkg_triggers(['A', 'b']) === ['a', 'b'], 'trigger words as a list');
skp_test(skp_throws(static fn() => coreui_skillpkg_triggers(implode(',', range(1, 13))), 'invalid_skill_triggers')
  && skp_throws(static fn() => coreui_skillpkg_triggers(str_repeat('x', 41)), 'invalid_skill_triggers'), 'at most 12 short trigger words');
skp_test(coreui_skillpkg_requires(['run', 'web', 'web']) === ['web', 'run'] && coreui_skillpkg_requires('python, files') === ['python', 'files']
  && coreui_skillpkg_requires('') === [], 'requirements in a fixed order');
skp_test(skp_throws(static fn() => coreui_skillpkg_requires(['shell']), 'invalid_skill_tool'), 'only known tools');
skp_test(coreui_skillpkg_path('templates/bericht.md') === ['templates/bericht.md', 'template']
  && coreui_skillpkg_path('scripts\\zaehlen.py') === ['scripts/zaehlen.py', 'script']
  && coreui_skillpkg_path('assets/liste.csv') === ['assets/liste.csv', 'asset']
  && coreui_skillpkg_path('references/plan.md') === ['references/plan.md', 'reference'], 'four folders');
foreach (['bericht.md', 'templates/', 'templates/ohne-endung', 'templates/../x.md', 'templates/a/b.md', 'bin/x.sh', '/templates/x.md', 'templates/.x.md'] as $bad) {
  skp_test(skp_throws(static fn() => coreui_skillpkg_path($bad), 'invalid_skill_path'), 'invalid path: ' . $bad);
}
skp_test(coreui_skillpkg_missing("Nutze templates/bericht.md und references/fehlt.md, nicht xtemplates/a.md.\nDann scripts/zaehlen.py.",
  ['templates/bericht.md']) === ['references/fehlt.md', 'scripts/zaehlen.py'], 'files the instruction names but the skill lacks');

// --- Wiring (static) ---------------------------------------------------------------------------------
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$endpoint = $read('api/skill_packages.php');
$guestAt = strpos($endpoint, "if (coreui_guest_is(\$pdo, \$uid)) stu_json(['ok'=>false, 'error'=>'guests_not_allowed'], 403);");
$csrfAt = strpos($endpoint, 'stu_require_csrf();');
skp_test($guestAt !== false && $csrfAt !== false && $guestAt < strpos($endpoint, "if (\$method === 'GET' && \$action === 'list') {")
  && $csrfAt < strpos($endpoint, "case 'save':")
  && str_contains($endpoint, "'tools'=>COREUI_SKILLPKG_TOOLS"), 'endpoint: accounts only, every change with CSRF');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $conf) {
  skp_test(str_contains($read($conf), '|files|project_files|skill_packages|'), 'nginx allows the endpoint: ' . $conf);
}
$chat = $read('api/chat.php');
skp_test(substr_count($chat, "ember_skill_context(\$consoleUid, (string)\$consoleSessionId) : null);") === 2
  && str_contains($chat, "require_once __DIR__ . '/skill_packages_store.php';")
  && str_contains($chat, "if (coreui_pftools_project(stu_pdo(), \$uid, \$sessionId) !== '') {\n      \$tools[] = 'files';"),
  'chat: own skills, the project and the tools of the chat for both prompt paths');
$settings = $read('settings.html');
skp_test(str_contains($settings, 'id="tab-skills" data-view="skills" aria-controls="view-skills"')
  && str_contains($settings, '<section class="cfg-view" id="view-skills" role="tabpanel" aria-labelledby="tab-skills" tabindex="-1" hidden>')
  && preg_match('~<script src="\./js/coreui-skills-settings\.js\?v=\d+"></script>~', $settings) === 1
  && preg_match('~<link rel="stylesheet" href="\./css/coreui-skills-settings\.css\?v=\d+" />~', $settings) === 1, 'Settings > Skills');
$js = $read('js/coreui-skills-settings.js');
// 0.7.84: every innerHTML of the page is a fixed icon, never data.
preg_match_all('~\.innerHTML = (.*);~', $js, $html);
$fixedIcons = $html[1] !== [] && array_filter($html[1], static fn(string $v): bool
  => preg_match('~^\'<svg aria-hidden="true"><use href="#i-[a-z]+" /></svg>(?:Skills)?\'$~', $v) !== 1) === [];
skp_test(str_contains($js, "opts.headers['X-CoreUI-CSRF'] = state.csrf;") && $fixedIcons
  && str_contains($js, "new MutationObserver(ensure).observe(view, {attributes: true, attributeFilter: ['hidden']});"),
  'page: CSRF on changes, only fixed icons as HTML, loaded when opened');
$migration = $read('database/migrations/052_skill_packages.sql');
skp_test(substr_count($migration, 'ON DELETE CASCADE') === 3 && str_contains($migration, 'UNIQUE KEY uq_skill_packages_slug (user_id, slug)')
  && str_contains($migration, "kind ENUM('reference','template','script','asset') NOT NULL")
  && str_contains($migration, "VALUES ('052_skill_packages')"), 'migration 052');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI eigene Skills: Kennung, Ausloeser, Voraussetzungen, Pfade, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite -------------------------------------------------------------------------------
function skp_sqlite(bool $migrated = true): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT,
    pinned_at TEXT, created_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT)');
  if (!$migrated) return $pdo;
  $pdo->exec('CREATE TABLE stu_skill_packages (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, slug TEXT NOT NULL, title TEXT NOT NULL,
    description TEXT NOT NULL, body TEXT NOT NULL, triggers TEXT NOT NULL DEFAULT \'\', requires_tools TEXT NOT NULL DEFAULT \'\', project_id TEXT,
    enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, UNIQUE (user_id, slug))');
  $pdo->exec('CREATE TABLE stu_skill_package_files (skill_id TEXT NOT NULL, path TEXT NOT NULL, kind TEXT NOT NULL, content TEXT NOT NULL,
    size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, updated_at TEXT, PRIMARY KEY (skill_id, path))');
  return $pdo;
}
$pdo = skp_sqlite();
$uid = 7;
$other = 8;
$projectA = str_repeat('a', 32);
$projectB = str_repeat('b', 32);
$pdo->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, ?, ?, 'open')")->execute([$projectA, $uid, 'Kunden']);
$pdo->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, ?, ?, 'open')")->execute([$projectB, $other, 'Fremd']);
$pdo->exec("INSERT INTO stu_console_sessions (id, user_id, project_id, title) VALUES ('cs_a', 7, '" . $projectA . "', 'Im Projekt'), ('cs_free', 7, NULL, 'Frei')");

skp_test(coreui_skillpkg_ready($pdo) && !coreui_skillpkg_ready(skp_sqlite(false)), 'readiness');
skp_test(skp_throws(static fn() => coreui_skillpkg_list(skp_sqlite(false), $uid), 'skill_migration_required')
  && coreui_skillpkg_for_chat(skp_sqlite(false), $uid, '') === [], 'without migration 052: a clear error, nothing in the chat');

$body = "Schreibe den Wochenbericht nach templates/bericht.md.\nZaehle mit scripts/zaehlen.py, nutze assets/teams.csv"
  . " und lies references/regeln.md.\nWichtig: references/fehlt.md.";
$skill = coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'wochenbericht', 'title'=>'  Wochen   bericht ', 'description'=>'Fasst die Woche fuer das Team zusammen.',
  'body'=>$body, 'triggers'=>'Wochenbericht, Statusbericht', 'requires'=>['web', 'python'], 'project_id'=>'', 'enabled'=>true]);
skp_test(preg_match('~^[a-f0-9]{32}$~D', $skill['id']) === 1 && $skill['slug'] === 'wochenbericht' && $skill['title'] === 'Wochen bericht'
  && $skill['triggers'] === ['wochenbericht', 'statusbericht'] && $skill['requires'] === ['web', 'python'] && $skill['project'] === null
  && $skill['enabled'] === true && $skill['body'] === $body && $skill['files'] === []
  && $skill['missing'] === ['assets/teams.csv', 'references/fehlt.md', 'references/regeln.md', 'scripts/zaehlen.py', 'templates/bericht.md'], 'create a skill');
$id = $skill['id'];
skp_test(skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'wochenbericht', 'title'=>'X', 'description'=>'Y', 'body'=>'Z']), 'skill_exists'),
  'one short name once per account');
$foreign = coreui_skillpkg_save($pdo, $other, '', ['slug'=>'wochenbericht', 'title'=>'Fremd', 'description'=>'Fremder Skill', 'body'=>'Fremd.',
  'enabled'=>true]);
skp_test($foreign['id'] !== $id, 'another account may use the same short name');
skp_test(skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'lang', 'title'=>'X', 'description'=>'Y', 'body'=>str_repeat('ä', 2001)]),
  'skill_body_too_large'), 'an instruction of at most 4000 bytes');
skp_test(skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'leer', 'title'=>'X', 'description'=>'Y', 'body'=>" \n "]), 'invalid_skill_body')
  && skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'leer', 'title'=>'', 'description'=>'Y', 'body'=>'Z']), 'invalid_skill_title')
  && skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'leer', 'title'=>'X', 'description'=>str_repeat('d', 301), 'body'=>'Z']),
    'invalid_skill_description'), 'name, description and instruction are needed');
skp_test(skp_throws(static fn() => coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'fremd', 'title'=>'X', 'description'=>'Y', 'body'=>'Z', 'project_id'=>$projectB]),
  'project_not_found'), 'never the project of another account');
skp_test(skp_throws(static fn() => coreui_skillpkg_get($pdo, $other, $id), 'skill_not_found')
  && skp_throws(static fn() => coreui_skillpkg_save($pdo, $other, $id, ['slug'=>'neu', 'title'=>'X', 'description'=>'Y', 'body'=>'Z']), 'skill_not_found')
  && skp_throws(static fn() => coreui_skillpkg_delete($pdo, $other, $id), 'skill_not_found')
  && skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $other, $id, 'templates/x.md', 'x'), 'skill_not_found'), 'never another account\'s skill');

// Companion files.
$template = "# Wochenbericht {{woche}}\n## Erledigt\n{{erledigt}}\n";
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'templates/bericht.md', $template);
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'scripts/zaehlen.py', "import sys\nprint(len(sys.stdin.read().split()))\n");
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/teams.csv', "team;leitung\nNord;Ada\n");
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'references/regeln.md', "Hoechstens eine Seite.\r\nKeine Namen von Kunden.");
skp_test(array_column($skill['files'], 'path') === ['assets/teams.csv', 'references/regeln.md', 'scripts/zaehlen.py', 'templates/bericht.md']
  && array_column($skill['files'], 'kind') === ['asset', 'reference', 'script', 'template'] && $skill['missing'] === ['references/fehlt.md']
  && $skill['files'][3]['content'] === $template && $skill['files'][3]['sha256'] === hash('sha256', $template)
  && $skill['files'][1]['content'] === "Hoechstens eine Seite.\nKeine Namen von Kunden.", 'companion files in four folders');
skp_test(!isset(coreui_skillpkg_list($pdo, $uid)[0]['files'][0]['content']) && !isset(coreui_skillpkg_list($pdo, $uid)[0]['body']),
  'the list carries no contents');
skp_test(skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/gross.txt', str_repeat('x', 8001)), 'skill_file_too_large')
  && skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/bin.dat', "a\0b"), 'invalid_skill_file')
  && skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/bin.dat', "\xff\xfe"), 'invalid_skill_file')
  && skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/x.txt', null), 'invalid_skill_file'), 'text only, at most 8000 bytes');
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'references/fehlt.md', 'Jetzt da.', 'references/regeln.md');
skp_test(array_column($skill['files'], 'path') === ['assets/teams.csv', 'references/fehlt.md', 'scripts/zaehlen.py', 'templates/bericht.md']
  && $skill['missing'] === ['references/regeln.md'], 'rename a file');
skp_test(skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'templates/bericht.md', 'x', 'assets/teams.csv'), 'skill_file_exists')
  && skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'templates/neu.md', 'x', 'assets/weg.csv'), 'skill_file_not_found'),
  'renaming never overwrites and needs the old file');
$skill = coreui_skillpkg_file_save($pdo, $uid, $id, 'references/regeln.md', "Hoechstens eine Seite.\nKeine Namen von Kunden.");
$skill = coreui_skillpkg_file_delete($pdo, $uid, $id, 'references/fehlt.md');
skp_test($skill['missing'] === ['references/fehlt.md'] && skp_throws(static fn() => coreui_skillpkg_file_delete($pdo, $uid, $id, 'references/fehlt.md'),
  'skill_file_not_found'), 'delete a file');
$many = coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'viele', 'title'=>'Viele', 'description'=>'Viele Dateien', 'body'=>'Viele.', 'enabled'=>false]);
for ($i = 1; $i <= 20; $i++) coreui_skillpkg_file_save($pdo, $uid, $many['id'], 'assets/d' . $i . '.txt', (string)$i);
skp_test(skp_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $many['id'], 'assets/d21.txt', '21'), 'skill_files_limit')
  && count(coreui_skillpkg_file_save($pdo, $uid, $many['id'], 'assets/d20.txt', 'neu')['files']) === 20, 'at most 20 files; changing one still works');

// Changing, the list and its search.
$skill = coreui_skillpkg_save($pdo, $uid, $id, ['slug'=>'wochenbericht', 'title'=>'Wochenbericht', 'description'=>'Fasst die Woche fuer das Team zusammen.',
  'body'=>$body . "\nNutze die Regeln.", 'triggers'=>'wochenbericht', 'requires'=>['web', 'python'], 'project_id'=>'', 'enabled'=>true]);
skp_test($skill['title'] === 'Wochenbericht' && count($skill['files']) === 4 && $skill['triggers'] === ['wochenbericht'], 'change a skill, files stay');
$kunden = coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'kundenbrief', 'title'=>'Kundenbrief', 'description'=>'Brief an Kunden im Ton des Projekts.',
  'body'=>'Schreibe freundlich und knapp.', 'triggers'=>'kundenbrief', 'requires'=>'files', 'project_id'=>$projectA, 'enabled'=>true]);
skp_test($kunden['project'] === ['id'=>$projectA, 'name'=>'Kunden'], 'a project skill');
skp_test(array_column(coreui_skillpkg_list($pdo, $uid), 'slug') === ['kundenbrief', 'viele', 'wochenbericht']
  && array_column(coreui_skillpkg_list($pdo, $uid, 'STATUS'), 'slug') === []
  && array_column(coreui_skillpkg_list($pdo, $uid, 'woche'), 'slug') === ['wochenbericht']
  && array_column(coreui_skillpkg_list($pdo, $other), 'slug') === ['wochenbericht'], 'the list of an account and its search');

// --- Mara with her own skills ------------------------------------------------------------------------
$prepare = static function (string $request, string $project, array $tools, int $budget = 4, string $channel = 'console') use ($pdo, $uid): string {
  coreui_skills_prepare($pdo, $request, $channel, $budget, ['uid'=>$uid, 'project'=>$project, 'tools'=>$tools]);
  return (string)$GLOBALS['COREUI_SKILLS_BLOCK'];
};
$block = $prepare('Wie geht es dir?', '', ['web', 'browse', 'image']);
skp_test(str_contains($block, "- wochenbericht: Fasst die Woche fuer das Team zusammen. (eigener Skill, braucht Python, fehlt hier)\n")
  && !str_contains($block, 'kundenbrief') && !str_contains($block, '- viele:'), 'library: own skills with what is missing; no project skill outside, nothing that is off');
skp_test(strpos($block, '- wochenbericht:') < strpos($block, '- python:') && str_contains($block, '- python: '), 'own skills first, the built-in ones stay');
skp_test(!str_contains($block, '[EIGENER SKILL'), 'nothing loaded for small talk');
$block = $prepare('Wie geht es dir?', $projectA, ['web', 'python', 'files']);
// 0.7.84 (P12 B): a project skill is named as such.
skp_test(str_contains($block, "- kundenbrief: Brief an Kunden im Ton des Projekts. (Projekt-Skill)\n")
  && str_contains($block, "- wochenbericht: Fasst die Woche fuer das Team zusammen. (eigener Skill)\n"), 'project skills in their project, nothing missing');
skp_test(str_contains($prepare('Wie geht es dir?', $projectA, ['web'], 2), 'kundenbrief (Kundenbrief)')
  && str_contains($prepare('Wie geht es dir?', $projectA, ['web'], 1), 'kundenbrief, wochenbericht,'), 'library sizes: titles and names');

// Found by trigger, loaded with all it brings.
$block = $prepare('Bitte den Wochenbericht fuer KW 46', '', ['web']);
skp_test($GLOBALS['COREUI_SKILLS_LOADED'] === ['wochenbericht'] && str_contains($block, "[EIGENER SKILL wochenbericht: Wochenbericht]\n"
  . "(Voraussetzung fehlt in diesem Chat: Python. Sag der Person, was ohne sie nicht geht, und behaupte kein Ergebnis davon.)\n"
  . "(Diese Begleitdateien nennt die Anleitung, sie fehlen aber: references/fehlt.md.)\n" . $body . "\nNutze die Regeln.\n"
  . "(Begleitdateien bei Bedarf: [SKILL: wochenbericht/assets/teams.csv], [SKILL: wochenbericht/references/regeln.md], "
  . "[SKILL: wochenbericht/scripts/zaehlen.py], [SKILL: wochenbericht/templates/bericht.md])\n"), 'found by trigger: instruction, requirement, missing file, files');
$result = coreui_skills_tool_result('wochenbericht/templates/bericht.md, wochenbericht/scripts/zaehlen.py');
$block = (string)$GLOBALS['COREUI_SKILLS_BLOCK'];
skp_test(str_contains($result, 'Geladen: wochenbericht/templates/bericht.md, wochenbericht/scripts/zaehlen.py.'), 'Mara loads two files: ' . $result);
skp_test(str_contains($block, "[VORLAGE wochenbericht/templates/bericht.md: Vorlage dieses Skills. Nutze sie als Geruest fuer dein Ergebnis, "
  . "fuelle sie mit dem Auftrag und lass keine Platzhalter stehen.]\n" . trim($template) . "\n[ENDE VORLAGE]\n"), 'a template to fill in');
skp_test(str_contains($block, "[SKRIPT wochenbericht/scripts/zaehlen.py: Skript dieses Skills, nur als Text. Es laeuft nicht von selbst; ausfuehren nur, "
  . "wenn die Person es will und ein Werkzeug dafuer in deinem Werkzeugprotokoll steht.]\nimport sys\n") && str_contains($block, "[ENDE SKRIPT]"),
  'a script only as text');
skp_test(str_contains($block, '(Begleitdateien bei Bedarf: [SKILL: wochenbericht/assets/teams.csv], [SKILL: wochenbericht/references/regeln.md])'),
  'the files not loaded yet stay offered');
$result = coreui_skills_tool_result('wochenbericht/assets/teams.csv');
skp_test(str_contains($result, 'In dieser Antwort sind schon 2 Referenzen geladen'), 'no more files than the window allows');
$prepare('Bitte den Wochenbericht', '', ['web', 'python']);
coreui_skills_tool_result('wochenbericht/assets/teams.csv, wochenbericht/references/regeln.md');
$block = (string)$GLOBALS['COREUI_SKILLS_BLOCK'];
skp_test(str_contains($block, "[DATEI wochenbericht/assets/teams.csv: Daten dieses Skills. Nutze sie wie angegeben.]\nteam;leitung\nNord;Ada\n[ENDE DATEI]")
  && str_contains($block, "[REFERENZ wochenbericht/references/regeln.md: Plan und Abnahme dieses Skills.") && !str_contains($block, 'Voraussetzung fehlt'),
  'data and a reference; with Python nothing is missing');
skp_test(str_contains(coreui_skills_tool_result('wochenbericht/references/weg.md'), 'Die Referenz "wochenbericht/references/weg.md" gibt es nicht. Vorhanden: '
  . 'wochenbericht/assets/teams.csv, wochenbericht/references/regeln.md, wochenbericht/scripts/zaehlen.py, wochenbericht/templates/bericht.md.'), 'an unknown file');

// Loaded by Mara herself, with /skill, and the small window.
$prepare('Wie geht es dir?', '', ['web']);
$result = coreui_skills_tool_result('wochenbericht');
skp_test(str_contains($result, 'Der Skill "wochenbericht" braucht Python; das fehlt in diesem Chat. Geladen: wochenbericht (Wochenbericht).')
  && str_contains((string)$GLOBALS['COREUI_SKILLS_BLOCK'], '[EIGENER SKILL wochenbericht: Wochenbericht]'), 'Mara loads her own skill with [SKILL: ...]');
$prepare('/skill kundenbrief bitte', $projectA, ['files']);
skp_test(($GLOBALS['COREUI_SKILLS_LOADED'][0] ?? '') === 'kundenbrief', '/skill names an own skill');
$prepare('/skill kundenbrief bitte', '', ['files']);
skp_test(!in_array('kundenbrief', $GLOBALS['COREUI_SKILLS_LOADED'], true)
  && str_contains(coreui_skills_tool_result('kundenbrief'), 'Einen Skill "kundenbrief" gibt es nicht.'), 'a project skill is not there outside its project');
$prepare('Bitte den Wochenbericht', '', ['web', 'python'], 1);
skp_test(str_contains(coreui_skills_tool_result('wochenbericht/templates/bericht.md'), 'Fuer Referenzen ist dein Kontextfenster zu klein'), 'no files at 4096 tokens');
coreui_skillpkg_file_save($pdo, $uid, $id, 'references/lang.md', str_repeat('Regel. ', 800));
$prepare('Bitte den Wochenbericht', '', ['web', 'python'], 2);
skp_test(str_contains(coreui_skills_tool_result('wochenbericht/references/lang.md'), 'Die Datei "wochenbericht/references/lang.md" ist zu gross fuer dein Kontextfenster.'),
  'a large file only with a large window');
$prepare('Bitte den Wochenbericht', '', ['web', 'python']);
skp_test(str_contains(coreui_skills_tool_result('wochenbericht/references/lang.md'), 'Geladen: wochenbericht/references/lang.md.'), 'large window: up to 8000 bytes');

// What is off, another account, another channel, an earlier turn.
coreui_skillpkg_save($pdo, $uid, $id, ['slug'=>'wochenbericht', 'title'=>'Wochenbericht', 'description'=>'Aus.', 'body'=>'Aus.', 'enabled'=>false]);
$block = $prepare('Bitte den Wochenbericht', '', ['web', 'python']);
skp_test(!str_contains($block, 'wochenbericht') && coreui_skills_own() === [], 'a skill that is off is not there');
coreui_skills_prepare($pdo, '/skill wochenbericht', 'console', 4, ['uid'=>$other, 'project'=>'', 'tools'=>[]]);
skp_test(str_contains((string)$GLOBALS['COREUI_SKILLS_BLOCK'], '[EIGENER SKILL wochenbericht: Fremd]') && array_keys(coreui_skills_own()) === ['wochenbericht'],
  'every account has only its own');
$prepare('Bitte den Wochenbericht', '', ['web'], 4, 'game');
skp_test(coreui_skills_own() === [] && (string)$GLOBALS['COREUI_SKILLS_BLOCK'] === '', 'nothing in Game E.M.B.E.R.');
coreui_skills_prepare($pdo, 'Bitte', 'console', 4);
skp_test(coreui_skills_own() === [] && ($GLOBALS['COREUI_SKILLS_TOOLS'] ?? null) === null, 'every turn starts without the skills of an earlier one');

// Deleting.
coreui_skillpkg_delete($pdo, $uid, $id);
$left = $pdo->prepare('SELECT COUNT(*) FROM stu_skill_package_files WHERE skill_id = ?');
$left->execute([$id]);
skp_test((int)$left->fetchColumn() === 0 && skp_throws(static fn() => coreui_skillpkg_get($pdo, $uid, $id), 'skill_not_found'), 'delete a skill with its files');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['052_skill_packages.sql', '052_skill_packages.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('skp-" . bin2hex(random_bytes(3)) . "@example.test', 0, 3, NOW())");
  $dbUid = (int)$db->lastInsertId();
  try {
    $project = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, ?, 'Kunden', 'open')")->execute([$project, $dbUid]);
    $s = coreui_skillpkg_save($db, $dbUid, '', ['slug'=>'wochenbericht', 'title'=>'Wochenbericht', 'description'=>'Fasst die Woche zusammen.',
      'body'=>'Nutze templates/bericht.md.', 'triggers'=>'wochenbericht', 'requires'=>['web'], 'project_id'=>'', 'enabled'=>true]);
    $s = coreui_skillpkg_file_save($db, $dbUid, $s['id'], 'templates/bericht.md', "# Bericht ä\n");
    skp_test($s['missing'] === [] && $s['files'][0]['size_bytes'] === 13 && $s['files'][0]['kind'] === 'template', 'MariaDB: a skill with a file');
    skp_test(skp_throws(static fn() => coreui_skillpkg_save($db, $dbUid, '', ['slug'=>'wochenbericht', 'title'=>'X', 'description'=>'Y', 'body'=>'Z']),
      'skill_exists'), 'MariaDB: the short name once per account');
    $p = coreui_skillpkg_save($db, $dbUid, '', ['slug'=>'kundenbrief', 'title'=>'Kundenbrief', 'description'=>'Brief.', 'body'=>'Brief.',
      'project_id'=>$project, 'enabled'=>true]);
    coreui_skillpkg_file_save($db, $dbUid, $p['id'], 'assets/ton.txt', 'freundlich');
    $chatSkills = coreui_skillpkg_for_chat($db, $dbUid, $project);
    skp_test(array_keys($chatSkills) === ['kundenbrief', 'wochenbericht'] && $chatSkills['wochenbericht']['files']['templates/bericht.md']['content'] === "# Bericht ä\n"
      && array_keys(coreui_skillpkg_for_chat($db, $dbUid, '')) === ['wochenbericht'], 'MariaDB: the skills of a chat');
    $db->prepare('DELETE FROM stu_console_projects WHERE id=? AND user_id=?')->execute([$project, $dbUid]);
    $count = $db->prepare('SELECT COUNT(*) FROM stu_skill_package_files f JOIN stu_skill_packages s ON s.id=f.skill_id WHERE s.user_id=?');
    $count->execute([$dbUid]);
    skp_test(array_column(coreui_skillpkg_list($db, $dbUid), 'slug') === ['wochenbericht'] && (int)$count->fetchColumn() === 1,
      'MariaDB: project skills go with their project');
    $mariadb = ', MariaDB (Migration 052)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$dbUid]);
  }
  $count = $db->prepare('SELECT COUNT(*) FROM stu_skill_packages WHERE user_id=?');
  $count->execute([$dbUid]);
  skp_test((int)$count->fetchColumn() === 0, 'MariaDB: skills go with their account');
}

echo "CoreAI eigene Skills: Kennung, Ausloeser, Voraussetzungen, Pfade, Verdrahtung, Speicher mit Begleitdateien und Grenzen, nur eigene, "
  . "Bibliothek mit fehlenden Werkzeugen, Laden per Ausloeser, /skill und [SKILL: ...], Vorlage, Skript nur als Text, Daten, Referenz, "
  . "Projekt-Skills, ausgeschaltet, Game E.M.B.E.R. OK (SQLite{$mariadb})\n";
