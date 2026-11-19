<?php
declare(strict_types=1);
// CoreAI 0.7.90 (P13 A): fixed revisions of a skill, and skills as packages.
//   php scripts/skill-revisions-selftest.php
// Offline: SKILL.md written and read (also the plain format other tools use), what a revision
// changed; the wiring (migration 054, endpoint, export headers, upload, Mara's work event). The store
// against SQLite: every change a revision (none without a change), a change after someone else's is
// refused, history with who and what, one revision with its files, restoring as a new revision on top
// (project gone, sharing, short name taken), a skill from before the revisions, the oldest beyond 100
// go with their contents. Packages: export of the skill or one revision, import as a new skill, as a
// new revision of the own one, under another short name; refused: no ZIP, no SKILL.md, two of them,
// paths that leave the package, absolute paths, links, binary or too large files, too many files or
// entries, a newer format, a wrong front matter; skipped and named: files outside the four folders.
// Nothing changes when a package is refused. The acceptance of P13: exported, imported again and
// set back to an earlier state. Mara keeps the revision of her turn. With COREUI_SELFTEST_DB=1
// against MariaDB (migrations 052 to 054, 054 twice). Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$GLOBALS['SKR_EVENTS'] = [];
// The work view of the test (the real one writes to stu_work_events).
function coreui_work_record(PDO $pdo, string $kind, string $level, string $title, array $meta = []): void {
  $GLOBALS['SKR_EVENTS'][] = [$title, $meta];
}
require_once $root . '/api/skill_packages_store.php';
function skr_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Skill revisions FAIL: {$why}\n"); exit(1); }
}
function skr_throws(callable $fn, string $error, string $path = ''): bool {
  try { $fn(); } catch (Throwable $e) {
    return $e->getMessage() === $error && ($path === '' || ($e instanceof CoreUISkillPackageError && $e->path === $path));
  }
  return false;
}
$work = sys_get_temp_dir() . '/coreai-skill-rev-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
register_shutdown_function(static function () use ($work): void { exec('rm -rf ' . escapeshellarg($work)); });
/** A ZIP from name => content (or name => [content, unix mode]). */
function skr_zip(string $dir, string $name, array $entries): string {
  $path = $dir . '/' . $name;
  $zip = new ZipArchive();
  $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  foreach ($entries as $entry => $content) {
    $zip->addFromString((string)$entry, is_array($content) ? $content[0] : $content);
    if (is_array($content)) $zip->setExternalAttributesName((string)$entry, ZipArchive::OPSYS_UNIX, $content[1] << 16);
  }
  $zip->close();
  return $path;
}
function skr_entries(string $path): array {
  $zip = new ZipArchive();
  $zip->open($path, ZipArchive::RDONLY);
  $out = [];
  for ($i = 0; $i < $zip->numFiles; $i++) $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
  $zip->close();
  ksort($out);
  return $out;
}

// --- Pure ------------------------------------------------------------------------------------------
$meta = ['slug'=>'wochenbericht', 'title'=>'Wochen-"Bericht" für Köln', 'description'=>'Fasst die Woche zusammen: Erledigtes, Offenes.',
  'triggers'=>'wochenbericht, statusbericht', 'requires_tools'=>'web,python', 'priority'=>1];
$md = coreui_skillrev_skill_md($meta, "Schreibe knapp.\nNutze templates/bericht.md.\n\n");
skr_test(str_starts_with($md, "---\nname: wochenbericht\ntitle: \"Wochen-\\\"Bericht\\\" für Köln\"\n") && str_ends_with($md, "---\n\nSchreibe knapp.\nNutze templates/bericht.md.\n")
  && str_contains($md, "\ntriggers: [\"wochenbericht\",\"statusbericht\"]\nrequires: [\"web\",\"python\"]\npriority: high\ncoreai_skill_format: 1\n"), 'SKILL.md written');
[$values, $body, $ignored] = coreui_skillrev_parse_skill_md($md);
skr_test($values['name'] === 'wochenbericht' && $values['title'] === 'Wochen-"Bericht" für Köln' && $values['triggers'] === ['wochenbericht', 'statusbericht']
  && $values['requires'] === ['web', 'python'] && $values['priority'] === 'high' && $body === "Schreibe knapp.\nNutze templates/bericht.md." && $ignored === [],
  'SKILL.md read back');
[$values, $body, $ignored] = coreui_skillrev_parse_skill_md("\xEF\xBB\xBF---\r\nname: pdf-werkzeug\r\ndescription: Arbeitet mit PDF-Dateien, auch 'Formularen'\r\nlicense: Siehe LICENSE.txt\r\n"
  . "allowed-tools:\r\n  - Read\r\ntriggers:\r\n  - pdf\r\n  - 'formular'\r\n---\r\n# PDF\r\nLies zuerst.");
skr_test($values === ['name'=>'pdf-werkzeug', 'description'=>"Arbeitet mit PDF-Dateien, auch 'Formularen'", 'triggers'=>['pdf', 'formular']]
  && $body === "# PDF\nLies zuerst." && $ignored === ['license', 'allowed-tools'], 'the plain front matter of other tools, with YAML lists');
foreach (["name: x\n", "---\nname: x\n", "---\n: x\n---\n", "---\ntitle: [\"offen\n---\nText", "---\nname x\n---\nText"] as $bad) {
  skr_test(skr_throws(static fn() => coreui_skillrev_parse_skill_md($bad), 'skill_package_bad_skill_md'), 'bad front matter: ' . json_encode($bad));
}
$r1 = ['meta'=>json_encode(['slug'=>'a', 'title'=>'A', 'enabled'=>true]), 'body_sha'=>'1'];
$r2 = ['meta'=>json_encode(['slug'=>'a', 'title'=>'B', 'enabled'=>true]), 'body_sha'=>'2'];
skr_test(coreui_skillrev_changes($r1, $r2, ['x.md'=>['sha256'=>'a'], 'y.md'=>['sha256'=>'b']], ['x.md'=>['sha256'=>'c'], 'z.md'=>['sha256'=>'d']])
  === ['fields'=>['title'], 'body'=>true, 'files'=>['added'=>['z.md'], 'removed'=>['y.md'], 'changed'=>['x.md']]]
  && coreui_skillrev_changes(null, $r1, [], ['x.md'=>['sha256'=>'a']]) === ['fields'=>[], 'body'=>false, 'files'=>['added'=>['x.md'], 'removed'=>[], 'changed'=>[]]],
  'what a revision changed');

// --- Wiring (static) ---------------------------------------------------------------------------------
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$migration = $read('database/migrations/054_skill_revisions.sql');
skr_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 0') && substr_count($migration, 'ON DELETE CASCADE') === 3
  && str_contains($migration, 'REFERENCES stu_users(id) ON DELETE SET NULL') && str_contains($migration, "VALUES ('054_skill_revisions')"), 'migration 054');
$endpoint = $read('api/skill_packages.php');
$csrfAt = strpos($endpoint, 'stu_require_csrf();');
skr_test($csrfAt !== false && $csrfAt < strpos($endpoint, "if (\$action === 'import') {")
  && str_contains($endpoint, "is_uploaded_file((string)\$upload['tmp_name'])") && str_contains($endpoint, "header('Content-Type: application/zip');")
  && str_contains($endpoint, "@unlink(\$zip['path']);") && str_contains($endpoint, "case 'restore':")
  && str_contains($endpoint, "'skill_conflict'], true) => 409,"), 'endpoint: import with CSRF and a real upload, export as a download, conflicts as 409');
skr_test(str_contains($read('api/skill_packages_store.php'), "require_once __DIR__ . '/skill_revisions_store.php';")
  && str_contains($read('api/skills.php'), "['source'=>\$id] + (\$revision > 0 ? ['revision'=>\$revision] : [])"), 'the store and Mara\'s work event');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !class_exists('ZipArchive')) {
  echo "CoreAI Skill-Revisionen: SKILL.md, Aenderungen, Verdrahtung OK; SQLite or ZipArchive not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite -------------------------------------------------------------------------------
function skr_sqlite(bool $revisions = true): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER NOT NULL, is_guest INTEGER NOT NULL DEFAULT 0)');
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT)');
  $pdo->exec("CREATE TABLE stu_skill_packages (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, slug TEXT NOT NULL, title TEXT NOT NULL,
    description TEXT NOT NULL, body TEXT NOT NULL, triggers TEXT NOT NULL DEFAULT '', requires_tools TEXT NOT NULL DEFAULT '', project_id TEXT,
    enabled INTEGER NOT NULL DEFAULT 1, shared INTEGER NOT NULL DEFAULT 0, priority INTEGER NOT NULL DEFAULT 0"
    . ($revisions ? ', revision INTEGER NOT NULL DEFAULT 0' : '') . ", created_at TEXT, updated_at TEXT, UNIQUE (user_id, slug))");
  $pdo->exec('CREATE TABLE stu_skill_package_files (skill_id TEXT NOT NULL, path TEXT NOT NULL, kind TEXT NOT NULL, content TEXT NOT NULL,
    size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, updated_at TEXT, PRIMARY KEY (skill_id, path))');
  $pdo->exec('CREATE TABLE stu_skill_package_optouts (user_id INTEGER NOT NULL, skill_id TEXT NOT NULL, created_at TEXT, PRIMARY KEY (user_id, skill_id))');
  $pdo->exec('CREATE TABLE stu_console_session_skills (session_id TEXT NOT NULL, user_id INTEGER NOT NULL, ref TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT, PRIMARY KEY (session_id, ref))');
  if ($revisions) {
    $pdo->exec('CREATE TABLE stu_skill_blobs (skill_id TEXT NOT NULL, sha256 TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_at TEXT,
      PRIMARY KEY (skill_id, sha256))');
    $pdo->exec('CREATE TABLE stu_skill_revisions (skill_id TEXT NOT NULL, rev INTEGER NOT NULL, user_id INTEGER, kind TEXT NOT NULL, note TEXT NOT NULL DEFAULT \'\',
      meta TEXT NOT NULL, body_sha TEXT NOT NULL, created_at TEXT, PRIMARY KEY (skill_id, rev))');
    $pdo->exec('CREATE TABLE stu_skill_revision_files (skill_id TEXT NOT NULL, rev INTEGER NOT NULL, path TEXT NOT NULL, kind TEXT NOT NULL, sha256 TEXT NOT NULL,
      size_bytes INTEGER NOT NULL, PRIMARY KEY (skill_id, rev, path))');
  }
  $pdo->exec('INSERT INTO stu_users (id, permission_level, is_guest) VALUES (1, 0, 0), (7, 4, 0), (8, 4, 0)');
  return $pdo;
}
$pdo = skr_sqlite();
$uid = 7;
$project = str_repeat('c', 32);
$pdo->prepare("INSERT INTO stu_console_projects (id, user_id, name, context_mode) VALUES (?, 7, 'Kunden', 'open')")->execute([$project]);
$base = ['slug'=>'wochenbericht', 'title'=>'Wochenbericht', 'description'=>'Fasst die Woche zusammen.', 'body'=>'Nutze templates/bericht.md.',
  'triggers'=>'wochenbericht', 'requires'=>['web'], 'enabled'=>true];
$s = coreui_skillpkg_save($pdo, $uid, '', $base);
$id = $s['id'];
skr_test($s['revision'] === 1 && coreui_skillrev_list($pdo, $uid, $id)['revisions'][0]['kind'] === 'create', 'a new skill is revision 1');
$s = coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>1, 'title'=>'Wochenbericht Team', 'body'=>'Schreibe knapp.'] + $base);
skr_test($s['revision'] === 2, 'saving makes revision 2');
$h = coreui_skillrev_list($pdo, $uid, $id);
skr_test($h['revision'] === 2 && $h['revisions'][0]['rev'] === 2 && $h['revisions'][0]['label'] === 'Gespeichert' && $h['revisions'][0]['by'] === 'du'
  && $h['revisions'][0]['current'] === true && $h['revisions'][0]['changes'] === ['fields'=>['title'], 'body'=>true, 'files'=>['added'=>[], 'removed'=>[], 'changed'=>[]]],
  'history: who, what');
skr_test(skr_throws(static fn() => coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>1, 'title'=>'Ueberschrieben'] + $base), 'skill_conflict')
  && coreui_skillpkg_get($pdo, $uid, $id)['title'] === 'Wochenbericht Team', 'a change after someone else\'s is refused, nothing overwritten');
skr_test(skr_throws(static fn() => coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>'zwei', 'title'=>'X'] + $base), 'skill_conflict'), 'a strange base counts as stale');
// Between the check and the record someone else moved on: the record refuses too, with and without a change.
skr_test(skr_throws(static fn() => coreui_skillrev_record($pdo, $id, $uid, 'save', '', 1), 'skill_conflict'), 'a stale record without a change is refused');
$pdo->prepare("UPDATE stu_skill_packages SET description = 'Zwischendurch' WHERE id = ?")->execute([$id]);
skr_test(skr_throws(static fn() => coreui_skillrev_record($pdo, $id, $uid, 'save', '', 1), 'skill_conflict'), 'a stale record with a change is refused');
$pdo->prepare("UPDATE stu_skill_packages SET description = 'Fasst die Woche zusammen.' WHERE id = ?")->execute([$id]);
// The edge: the content is back to what an older revision had, the counter is not; still a conflict.
$pdo->prepare("UPDATE stu_skill_packages SET title = 'Wochenbericht', body = 'Nutze templates/bericht.md.' WHERE id = ?")->execute([$id]);
skr_test(skr_throws(static fn() => coreui_skillrev_record($pdo, $id, $uid, 'save', '', 1), 'skill_conflict'), 'a stale record that looks unchanged is refused');
$pdo->prepare("UPDATE stu_skill_packages SET title = 'Wochenbericht Team', body = 'Schreibe knapp.' WHERE id = ?")->execute([$id]);
$s = coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>2, 'title'=>'Wochenbericht Team', 'body'=>'Schreibe knapp.'] + $base);
skr_test($s['revision'] === 2, 'no revision without a change');
$s = coreui_skillpkg_file_save($pdo, $uid, $id, 'templates/bericht.md', "# Bericht\n", '', 2);
$s = coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/teams.csv', "team\nNord\n", '', 3);
skr_test($s['revision'] === 4 && skr_throws(static fn() => coreui_skillpkg_file_save($pdo, $uid, $id, 'assets/x.csv', 'x', '', 3), 'skill_conflict')
  && skr_throws(static fn() => coreui_skillpkg_file_delete($pdo, $uid, $id, 'assets/teams.csv', 3), 'skill_conflict'), 'files make revisions and check the base too');
$s = coreui_skillpkg_file_save($pdo, $uid, $id, 'references/bericht.md', "# Bericht\n", 'templates/bericht.md', 4);
$s = coreui_skillpkg_file_delete($pdo, $uid, $id, 'assets/teams.csv', 5);
$h = coreui_skillrev_list($pdo, $uid, $id);
skr_test($s['revision'] === 6 && array_column($h['revisions'], 'note') === ['assets/teams.csv gelöscht', 'templates/bericht.md umbenannt in references/bericht.md',
  'assets/teams.csv', 'templates/bericht.md', '', ''] && $h['revisions'][1]['changes']['files'] === ['added'=>['references/bericht.md'],
  'removed'=>['templates/bericht.md'], 'changed'=>[]], 'file notes and what they changed');
$r4 = coreui_skillrev_get($pdo, $uid, $id, 4);
skr_test($r4['rev'] === 4 && $r4['title'] === 'Wochenbericht Team' && $r4['body'] === 'Schreibe knapp.' && array_column($r4['files'], 'path') === ['assets/teams.csv', 'templates/bericht.md']
  && $r4['files'][0]['content'] === "team\nNord\n" && $r4['triggers'] === ['wochenbericht'] && $r4['requires'] === ['web'], 'one revision with its files');
skr_test(skr_throws(static fn() => coreui_skillrev_get($pdo, $uid, $id, 99), 'skill_revision_not_found')
  && skr_throws(static fn() => coreui_skillrev_get($pdo, 8, $id, 4), 'skill_not_found')
  && skr_throws(static fn() => coreui_skillrev_list($pdo, 8, $id), 'skill_not_found'), 'never another account\'s history');

// Restoring.
coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>6, 'title'=>'Ganz anders', 'body'=>'Neu.', 'project_id'=>$project] + $base);
$s = coreui_skillrev_restore($pdo, $uid, $id, 4, 7);
skr_test($s['revision'] === 8 && $s['title'] === 'Wochenbericht Team' && $s['project'] === null && array_column($s['files'], 'path') === ['assets/teams.csv', 'templates/bericht.md']
  && coreui_skillpkg_get($pdo, $uid, $id)['body'] === 'Schreibe knapp.', 'restoring brings revision 4 back');
$h = coreui_skillrev_list($pdo, $uid, $id);
skr_test(count($h['revisions']) === 8 && $h['revisions'][0]['label'] === 'Wiederhergestellt' && $h['revisions'][0]['note'] === 'Wiederhergestellt aus Revision 4'
  && coreui_skillrev_get($pdo, $uid, $id, 7)['title'] === 'Ganz anders', 'as a new revision on top; the ones in between stay unchanged');
skr_test(skr_throws(static fn() => coreui_skillrev_restore($pdo, $uid, $id, 2, 7), 'skill_conflict') && skr_throws(static fn() => coreui_skillrev_restore($pdo, $uid, $id, 42, 8), 'skill_revision_not_found')
  && skr_throws(static fn() => coreui_skillrev_restore($pdo, $uid, $id, 'x', 8), 'invalid_skill_revision')
  && skr_throws(static fn() => coreui_skillrev_restore($pdo, 8, $id, 2, null), 'skill_not_found'), 'restoring: base, revision, account');
coreui_skillrev_restore($pdo, $uid, $id, 7, 8);
skr_test(coreui_skillpkg_get($pdo, $uid, $id)['project'] === ['id'=>$project, 'name'=>'Kunden'], 'a project comes back while it exists');
$pdo->prepare('DELETE FROM stu_console_projects WHERE id = ?')->execute([$project]);
coreui_skillrev_restore($pdo, $uid, $id, 4, 9);
coreui_skillrev_restore($pdo, $uid, $id, 7, 10);
skr_test(coreui_skillpkg_get($pdo, $uid, $id)['project'] === null, 'a project that is gone is not brought back');
coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>11, 'slug'=>'neuer-name'] + $base);
coreui_skillpkg_save($pdo, $uid, '', $base);
skr_test(skr_throws(static fn() => coreui_skillrev_restore($pdo, $uid, $id, 4, 12), 'skill_exists'), 'restoring never takes a short name that is in use');

// A skill from before the revisions, and the oldest beyond 100.
$pdo->prepare("INSERT INTO stu_skill_packages (id, user_id, slug, title, description, body, triggers, requires_tools, enabled, revision, created_at, updated_at)
  VALUES (?, 7, 'alt', 'Alt', 'Von frueher', 'Alte Anleitung', '', '', 1, 0, '2026-11-01', '2026-11-01')")->execute([str_repeat('e', 32)]);
skr_test(coreui_skillrev_list($pdo, $uid, str_repeat('e', 32))['revisions'] === [], 'no revisions yet, and reading writes none');
$old = coreui_skillpkg_save($pdo, $uid, str_repeat('e', 32), ['base_revision'=>0, 'slug'=>'alt', 'title'=>'Alt', 'description'=>'Neu', 'body'=>'Neue Anleitung', 'enabled'=>true]);
$h = coreui_skillrev_list($pdo, $uid, str_repeat('e', 32));
skr_test($old['revision'] === 2 && $h['revisions'][1]['note'] === 'Stand vor den Revisionen' && coreui_skillrev_get($pdo, $uid, str_repeat('e', 32), 1)['body'] === 'Alte Anleitung',
  'the state before the revisions is kept first');
$many = coreui_skillpkg_save($pdo, $uid, '', ['slug'=>'viele'] + $base);
for ($i = 2; $i <= 103; $i++) coreui_skillpkg_save($pdo, $uid, $many['id'], ['slug'=>'viele', 'base_revision'=>$i - 1, 'body'=>'Fassung ' . $i] + $base);
$h = coreui_skillrev_list($pdo, $uid, $many['id']);
$blobs = $pdo->prepare('SELECT COUNT(*) FROM stu_skill_blobs WHERE skill_id = ?');
$blobs->execute([$many['id']]);
skr_test($h['revision'] === 103 && count($h['revisions']) === 100 && end($h['revisions'])['rev'] === 4 && (int)$blobs->fetchColumn() === 100
  && skr_throws(static fn() => coreui_skillrev_get($pdo, $uid, $many['id'], 3), 'skill_revision_not_found'), 'at most 100, contents nothing refers to go too');

// --- Packages ------------------------------------------------------------------------------------------
$pdo->prepare('DELETE FROM stu_skill_packages WHERE slug = ? AND user_id = ?')->execute(['wochenbericht', $uid]);
$r = coreui_skillrev_restore($pdo, $uid, $id, 4, 12);
$s = coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>$r['revision'], 'slug'=>'wochenbericht', 'title'=>'Wochenbericht Team', 'description'=>'Fasst die Woche zusammen.',
  'body'=>'Schreibe knapp. Nutze templates/bericht.md.', 'triggers'=>'wochenbericht, statusbericht', 'requires'=>['web', 'python'], 'priority'=>'high', 'enabled'=>true]);
$zip = coreui_skillrev_export($pdo, $uid, $id);
$entries = skr_entries($zip['path']);
skr_test($zip['name'] === 'wochenbericht.zip' && array_keys($entries) === ['wochenbericht/SKILL.md', 'wochenbericht/assets/teams.csv', 'wochenbericht/templates/bericht.md']
  && $entries['wochenbericht/templates/bericht.md'] === "# Bericht\n"
  && $entries['wochenbericht/SKILL.md'] === coreui_skillrev_skill_md(coreui_skillrev_meta($pdo->query("SELECT * FROM stu_skill_packages WHERE id = '{$id}'")->fetch(PDO::FETCH_ASSOC)),
    'Schreibe knapp. Nutze templates/bericht.md.'), 'export: one folder, SKILL.md and the files');
$zip4 = coreui_skillrev_export($pdo, $uid, $id, 4);
skr_test($zip4['name'] === 'wochenbericht-revision-4.zip' && str_contains(skr_entries($zip4['path'])['wochenbericht/SKILL.md'], "\n\nSchreibe knapp.\n")
  && skr_throws(static fn() => coreui_skillrev_export($pdo, 8, $id), 'skill_not_found'), 'export of one revision, never another account\'s');
// Import into another account, as a new skill.
$imported = coreui_skillrev_import($pdo, 8, $zip['path'], 'new', '', null, 'wochenbericht.zip');
$copy = coreui_skillpkg_get($pdo, 8, $imported['skill']['id']);
$orig = coreui_skillpkg_get($pdo, $uid, $id);
$strip = static fn(array $files): array => array_map(static fn(array $f): array => [$f['path'], $f['kind'], $f['content'], $f['sha256']], $files);
skr_test($imported['created'] === true && $copy['slug'] === 'wochenbericht' && $copy['title'] === $orig['title'] && $copy['description'] === $orig['description']
  && $copy['body'] === $orig['body'] && $copy['triggers'] === $orig['triggers'] && $copy['requires'] === $orig['requires'] && $copy['priority'] === 'high'
  && $strip($copy['files']) === $strip($orig['files']) && $copy['revision'] === 1 && $copy['enabled'] === true && $copy['shared'] === false && $copy['project'] === null,
  'export and import keep instruction, files and details');
skr_test(coreui_skillrev_list($pdo, 8, $copy['id'])['revisions'][0]['note'] === 'Importiert aus wochenbericht.zip', 'the import is named in the history');
// The same package again: refused as new, taken as a new revision, or under another short name.
try {
  coreui_skillrev_import($pdo, 8, $zip['path'], 'new');
  skr_test(false, 'a short name in use is not taken silently');
} catch (CoreUISkillImportExists $e) {
  skr_test($e->getMessage() === 'skill_exists' && $e->slug === 'wochenbericht' && $e->title === 'Wochenbericht Team', 'the skill that has the short name is named');
}
skr_test(skr_throws(static fn() => coreui_skillrev_import($pdo, 8, $zip['path'], 'update', 'gibt-es-nicht'), 'skill_not_found')
  && skr_throws(static fn() => coreui_skillrev_import($pdo, 8, $zip['path'], 'sonst'), 'invalid_skill_import_mode'), 'update needs the skill');
$other = coreui_skillrev_import($pdo, 8, $zip['path'], 'new', 'wochenbericht-kopie');
skr_test($other['skill']['slug'] === 'wochenbericht-kopie' && $other['skill']['body'] === $orig['body'], 'under another short name');

// The acceptance of P13: exported, changed, imported again, set back to the earlier state.
$before = coreui_skillpkg_get($pdo, $uid, $id);
coreui_skillpkg_save($pdo, $uid, $id, ['base_revision'=>$before['revision'], 'slug'=>'wochenbericht', 'title'=>'Verbessert', 'description'=>'Neu.', 'body'=>'Ganz neu.',
  'triggers'=>'', 'requires'=>[], 'enabled'=>true]);
coreui_skillpkg_file_delete($pdo, $uid, $id, 'assets/teams.csv', $before['revision'] + 1);
$back = coreui_skillrev_import($pdo, $uid, $zip['path'], 'update', '', $before['revision'] + 2, 'wochenbericht.zip');
$now = coreui_skillpkg_get($pdo, $uid, $id);
skr_test($back['created'] === false && $now['revision'] === $before['revision'] + 3 && $now['body'] === $before['body'] && $strip($now['files']) === $strip($before['files'])
  && $now['title'] === $before['title'], 'imported again: the package\'s state as a new revision');
$restored = coreui_skillrev_restore($pdo, $uid, $id, $before['revision'] + 1, $now['revision']);
skr_test($restored['title'] === 'Verbessert' && $restored['body'] === 'Ganz neu.' && array_column($restored['files'], 'path') === ['assets/teams.csv', 'templates/bericht.md'],
  'and set back to an earlier revision with its files');
$restored = coreui_skillrev_restore($pdo, $uid, $id, $before['revision'] + 2, $restored['revision']);
skr_test(array_column($restored['files'], 'path') === ['templates/bericht.md'], 'a revision without a file brings the skill back without it');

// Refused packages change nothing.
$count = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM stu_skill_packages')->fetchColumn()
  + (int)$pdo->query('SELECT COUNT(*) FROM stu_skill_revisions')->fetchColumn();
$total = $count();
$good = "---\nname: probe\ndescription: Eine Probe.\n---\nTu es.";
file_put_contents($work . '/kein.zip', 'kein zip');
$bad = [
  [$work . '/kein.zip', 'skill_package_invalid', ''],
  [skr_zip($work, 'leer.zip', ['probe/README.md'=>'x']), 'skill_package_no_skill_md', ''],
  [skr_zip($work, 'zwei.zip', ['a/SKILL.md'=>$good, 'b/SKILL.md'=>$good]), 'skill_package_invalid', 'a/SKILL.md, b/SKILL.md'],
  [skr_zip($work, 'ausbruch.zip', ['probe/SKILL.md'=>$good, 'probe/../../evil.md'=>'x']), 'skill_package_unsafe', 'probe/../../evil.md'],
  [skr_zip($work, 'absolut.zip', ['probe/SKILL.md'=>$good, '/etc/evil.md'=>'x']), 'skill_package_unsafe', '/etc/evil.md'],
  [skr_zip($work, 'link.zip', ['probe/SKILL.md'=>$good, 'probe/references/link.md'=>['/etc/passwd', 0120777]]), 'skill_package_unsafe', 'probe/references/link.md'],
  [skr_zip($work, 'binaer.zip', ['probe/SKILL.md'=>$good, 'probe/assets/bild.png'=>"\x89PNG\r\n\x1a\n\0\0"]), 'invalid_skill_file', 'probe/assets/bild.png'],
  [skr_zip($work, 'gross.zip', ['probe/SKILL.md'=>$good, 'probe/references/gross.md'=>str_repeat('x', 8001)]), 'skill_file_too_large', 'probe/references/gross.md'],
  [skr_zip($work, 'zuviele.zip', ['probe/SKILL.md'=>$good] + array_combine(array_map(static fn($i) => "probe/assets/d{$i}.txt", range(1, 21)), array_fill(0, 21, 'x'))),
    'skill_files_limit', ''],
  [skr_zip($work, 'eintraege.zip', ['probe/SKILL.md'=>$good] + array_combine(array_map(static fn($i) => "probe/sonst/d{$i}.txt", range(1, 100)), array_fill(0, 100, 'x'))),
    'skill_package_too_large', ''],
  [skr_zip($work, 'entpackt.zip', ['probe/SKILL.md'=>$good, 'probe/sonst/a.txt'=>str_repeat('a', 600000), 'probe/sonst/b.txt'=>str_repeat('b', 600000)]),
    'skill_package_too_large', ''],
  [skr_zip($work, 'format.zip', ['probe/SKILL.md'=>"---\nname: probe\ndescription: D\ncoreai_skill_format: 2\n---\nTu es."]), 'skill_package_format', 'SKILL.md'],
  [skr_zip($work, 'kopf.zip', ['probe/SKILL.md'=>"Kein Kopf"]), 'skill_package_bad_skill_md', 'SKILL.md'],
  [skr_zip($work, 'ohne.zip', ['probe/SKILL.md'=>"---\nname: probe\n---\nTu es."]), 'invalid_skill_description', ''],
  [skr_zip($work, 'eingebaut.zip', ['python/SKILL.md'=>"---\nname: python\ndescription: D\n---\nTu es."]), 'skill_builtin_name', ''],
  [skr_zip($work, 'leererkoerper.zip', ['probe/SKILL.md'=>"---\nname: probe\ndescription: D\n---\n"]), 'invalid_skill_body', ''],
];
foreach ($bad as [$path, $error, $entry]) {
  skr_test(skr_throws(static fn() => coreui_skillrev_import($pdo, $uid, $path, 'new'), $error, $entry), 'refused: ' . basename($path) . ' (' . $error . ')');
}
skr_test($count() === $total, 'a refused package changes nothing');
// Files outside the four folders are skipped and named; the plain format of other tools is taken.
$plain = coreui_skillrev_import($pdo, $uid, skr_zip($work, 'fremd.zip', ['pdf-werkzeug/SKILL.md'=>"---\nname: pdf-werkzeug\ndescription: PDF-Dateien lesen\nlicense: MIT\n---\n# PDF\nLies.",
  'pdf-werkzeug/LICENSE.txt'=>'MIT', 'pdf-werkzeug/scripts/lesen.py'=>"print(1)\n", 'pdf-werkzeug/references/tief/x.md'=>'x', 'pdf-werkzeug/references/mit leer.md'=>'x',
  'anderes/datei.md'=>'x', '__MACOSX/pdf-werkzeug/._SKILL.md'=>'x']), 'new');
skr_test($plain['skill']['slug'] === 'pdf-werkzeug' && $plain['skill']['title'] === 'pdf-werkzeug' && array_column($plain['skill']['files'], 'path') === ['scripts/lesen.py']
  && $plain['ignored'] === ['license'] && $plain['skipped'] === [['name'=>'pdf-werkzeug/LICENSE.txt', 'reason'=>'outside_folders'],
    ['name'=>'pdf-werkzeug/references/tief/x.md', 'reason'=>'outside_folders'], ['name'=>'pdf-werkzeug/references/mit leer.md', 'reason'=>'invalid_skill_path'],
    ['name'=>'anderes/datei.md', 'reason'=>'outside_skill']], 'other files are skipped and named, unknown keys too');
$top = coreui_skillrev_import($pdo, $uid, skr_zip($work, 'oben.zip', ['SKILL.md'=>"---\nname: oben\ndescription: D\n---\nTu es.", 'templates/a.md'=>'A']), 'new');
skr_test(array_column($top['skill']['files'], 'path') === ['templates/a.md'], 'SKILL.md at the top works too');

// --- Mara keeps the revision of her turn ----------------------------------------------------------------
$GLOBALS['SKR_EVENTS'] = [];
coreui_skills_prepare($pdo, '/skill wochenbericht bitte', 'console', 4, ['uid'=>$uid, 'project'=>'', 'tools'=>['web'], 'session'=>'']);
$rev = coreui_skillpkg_get($pdo, $uid, $id)['revision'];
skr_test(in_array(['Skill geladen: wochenbericht', ['source'=>'wochenbericht', 'revision'=>$rev]], $GLOBALS['SKR_EVENTS'], true)
  && coreui_skills_own()['wochenbericht']['revision'] === $rev, 'the work view names the revision Mara uses');
coreui_skillpkg_file_save($pdo, $uid, $id, 'templates/bericht.md', "# Geaendert waehrend des Laufs\n", '', $rev);
coreui_skills_tool_result('wochenbericht/templates/bericht.md');
skr_test(str_contains((string)$GLOBALS['COREUI_SKILLS_BLOCK'], "# Bericht\n[ENDE VORLAGE]") && !str_contains((string)$GLOBALS['COREUI_SKILLS_BLOCK'], 'Geaendert'),
  'a change during her turn does not reach it: she keeps the revision with its files');
coreui_skills_prepare($pdo, '/skill wochenbericht bitte', 'console', 4, ['uid'=>$uid, 'project'=>'', 'tools'=>['web'], 'session'=>'']);
coreui_skills_tool_result('wochenbericht/templates/bericht.md');
skr_test(str_contains((string)$GLOBALS['COREUI_SKILLS_BLOCK'], 'Geaendert waehrend des Laufs') && coreui_skills_own()['wochenbericht']['revision'] === $rev + 1,
  'the next turn takes the new revision');

// Deleting, and without migration 054.
coreui_skillpkg_delete($pdo, $uid, $id);
$left = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stu_skill_revisions WHERE skill_id = ?) + (SELECT COUNT(*) FROM stu_skill_revision_files WHERE skill_id = ?)
  + (SELECT COUNT(*) FROM stu_skill_blobs WHERE skill_id = ?)');
$left->execute([$id, $id, $id]);
skr_test((int)$left->fetchColumn() === 0, 'revisions and contents go with their skill');
$plainDb = skr_sqlite(false);
$p = coreui_skillpkg_save($plainDb, $uid, '', $base);
$p = coreui_skillpkg_save($plainDb, $uid, $p['id'], ['base_revision'=>5] + $base);
skr_test(!coreui_skillrev_ready($plainDb) && $p['revision'] === 0 && skr_throws(static fn() => coreui_skillrev_list($plainDb, $uid, $p['id']), 'skill_migration_required')
  && skr_throws(static fn() => coreui_skillrev_restore($plainDb, $uid, $p['id'], 1, null), 'skill_migration_required'), 'without migration 054 skills work as before');
$z = coreui_skillrev_export($plainDb, $uid, $p['id']);
skr_test(count(skr_entries($z['path'])) === 1, 'export works without revisions');
foreach ([$zip, $zip4, $z] as $made) @unlink($made['path']);

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['052_skill_packages.sql', '053_skill_sharing.sql', '054_skill_revisions.sql', '054_skill_revisions.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $tag = bin2hex(random_bytes(3));
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('skr-{$tag}@example.test', 0, 4, NOW())");
  $dbUid = (int)$db->lastInsertId();
  try {
    $m = coreui_skillpkg_save($db, $dbUid, '', ['slug'=>'bericht-' . $tag] + $base);
    $m = coreui_skillpkg_file_save($db, $dbUid, $m['id'], 'templates/bericht.md', "# Bericht ä\n", '', 1);
    $m = coreui_skillpkg_save($db, $dbUid, $m['id'], ['slug'=>'bericht-' . $tag, 'base_revision'=>2, 'body'=>'Anders.'] + $base);
    skr_test($m['revision'] === 3 && skr_throws(static fn() => coreui_skillpkg_save($db, $dbUid, $m['id'], ['slug'=>'bericht-' . $tag, 'base_revision'=>2] + $base), 'skill_conflict'),
      'MariaDB: revisions and conflicts');
    $back = coreui_skillrev_restore($db, $dbUid, $m['id'], 2, 3);
    skr_test($back['revision'] === 4 && $back['body'] === 'Nutze templates/bericht.md.', 'MariaDB: restoring');
    $mz = coreui_skillrev_export($db, $dbUid, $m['id']);
    $mi = coreui_skillrev_import($db, $dbUid, $mz['path'], 'new', 'kopie-' . $tag);
    @unlink($mz['path']);
    skr_test($mi['skill']['files'][0]['content'] === "# Bericht ä\n", 'MariaDB: export and import');
    $db->prepare('DELETE FROM stu_skill_packages WHERE id = ?')->execute([$m['id']]);
    $n = $db->prepare('SELECT (SELECT COUNT(*) FROM stu_skill_revisions WHERE skill_id = ?) + (SELECT COUNT(*) FROM stu_skill_blobs WHERE skill_id = ?)');
    $n->execute([$m['id'], $m['id']]);
    skr_test((int)$n->fetchColumn() === 0, 'MariaDB: revisions go with their skill');
    $mariadb = ', MariaDB (Migration 052 bis 054)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$dbUid]);
  }
}

echo "CoreAI Skill-Revisionen: SKILL.md schreiben und lesen, jede Aenderung eine Revision, Konflikte, Verlauf, Wiederherstellen als neue Revision, "
  . "Bestand vor den Revisionen, hoechstens 100, Export (auch einer Revision), Import neu, als Revision, unter anderer Kennung, abgewiesene Pakete "
  . "ohne Aenderung, uebersprungene Dateien, Abnahme exportiert-importiert-zurueckgesetzt, Mara behaelt die Revision ihres Zugs OK (SQLite{$mariadb})\n";
