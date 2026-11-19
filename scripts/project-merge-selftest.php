<?php
declare(strict_types=1);
// CoreAI 0.7.81 (P11 B): merging working states with conflicts, and Mara with versions.
//   php scripts/project-merge-selftest.php
// Offline: lines, the shortest edit (against a plain LCS on random texts), the three-way merge of
// one text (each side, both sides, the same change, neighbouring lines, a missing line end, random
// properties), which files a merge decides, the newest common version, conflict markers. The store
// against SQLite: a merge without conflicts (taken over, merged line by line, deleted), a version
// with two parents, nothing left to merge, a conflict that changes nothing until it is solved
// (unsolved, markers left, changed meanwhile, other account), unsaved changes saved first, a file
// deleted on one side and changed on the other, the other working state unchanged, deleting a
// merged working state keeps its versions, limits, a missing migration 050. Mara's markers and tools
// ([VERSION_SICHERN], [ARBEITSSTAND], [ZUSAMMENFUEHREN]) in the work loop. With git on the machine,
// the exported ZIP is checked with git itself (two parents, merge-base, branch --merged, fsck).
// With COREUI_SELFTEST_DB=1 against MariaDB (migrations 049 and 050). Test data uses example.test.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/project_file_tools.php';
require_once $root . '/api/console_agent_loop.php';
function pmg_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project merge FAIL: {$why}\n"); exit(1); }
}
function pmg_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ------------------------------------------------------------------------------------------
pmg_test(coreui_pvcs_lines("a\nb\nc") === ["a\n", "b\n", 'c'] && coreui_pvcs_lines('') === [] && coreui_pvcs_lines("x\r\n") === ["x\r\n"],
  'lines keep their line ends');
mt_srand(81);
$lcs = static function (array $a, array $b): int {
  $prev = array_fill(0, count($b) + 1, 0);
  foreach ($a as $x) {
    $row = [0];
    foreach ($b as $j => $y) $row[] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $row[$j]);
    $prev = $row;
  }
  return end($prev);
};
for ($round = 0; $round < 300; $round++) {
  $a = []; $b = [];
  for ($i = mt_rand(0, 14); $i > 0; $i--) $a[] = chr(97 + mt_rand(0, 3));
  for ($i = mt_rand(0, 14); $i > 0; $i--) $b[] = chr(97 + mt_rand(0, 3));
  $pairs = coreui_pvcs_common_lines($a, $b);
  $ok = count($pairs) === $lcs($a, $b);
  $last = [-1, -1];
  foreach ($pairs as [$i, $j]) {
    $ok = $ok && $i > $last[0] && $j > $last[1] && $a[$i] === $b[$j];
    $last = [$i, $j];
  }
  pmg_test($ok, 'the shortest edit finds a longest common part: ' . json_encode([$a, $b, $pairs]));
}
pmg_test(coreui_pvcs_myers(['a', 'b'], ['c', 'd'], 1) === [], 'beyond the edit limit nothing counts as common');

$base = "a\nb\nc\nd\ne\n";
$m = coreui_pvcs_merge3($base, "a\nB\nc\nd\ne\n", "a\nb\nc\nD\ne\n");
pmg_test($m === ['content'=>"a\nB\nc\nD\ne\n", 'conflicts'=>0, 'clean'=>true], 'both sides changed different lines');
$m = coreui_pvcs_merge3($base, "a\nB\nc\nd\ne\n", "a\nX\nc\nd\ne\n", 'main', 'neu');
pmg_test($m === ['content'=>"a\n<<<<<<< main\nB\n=======\nX\n>>>>>>> neu\nc\nd\ne\n", 'conflicts'=>1, 'clean'=>false], 'the same line differently: a conflict');
pmg_test(coreui_pvcs_merge3($base, "a\nB\nc\nd\ne\n", "a\nB\nc\nd\ne\n")['content'] === "a\nB\nc\nd\ne\n", 'the same change on both sides');
pmg_test(coreui_pvcs_merge3($base, "0\na\nb\nc\nd\ne\n", "a\nb\nc\nd\ne\nf\n")['content'] === "0\na\nb\nc\nd\ne\nf\n", 'insert at the start and at the end');
pmg_test(coreui_pvcs_merge3($base, "a\nc\nd\ne\n", "a\nb\nc\nd\nE")['content'] === "a\nc\nd\nE", 'a line deleted, the last changed without line end');
pmg_test(!coreui_pvcs_merge3($base, "a\nB\nc\nd\ne\n", "a\nb\nC\nd\ne\n")['clean'], 'neighbouring lines changed on both sides: a conflict, as in Git');
pmg_test(coreui_pvcs_merge3('', "x\n", "y\n")['conflicts'] === 1 && coreui_pvcs_merge3('', "x\n", "x\n")['content'] === "x\n", 'a file both added');
pmg_test(coreui_pvcs_merge3("a\r\nb\r\n", "A\r\nb\r\n", "X\r\nb\r\n", 'o', 't')['content'] === "<<<<<<< o\r\nA\r\n=======\r\nX\r\n>>>>>>> t\r\nb\r\n",
  'markers keep Windows line ends');
for ($round = 0; $round < 200; $round++) {
  $lines = [];
  for ($i = mt_rand(0, 12); $i > 0; $i--) $lines[] = chr(97 + mt_rand(0, 5)) . "\n";
  $mutate = static function (array $lines): string {
    for ($k = mt_rand(0, 3); $k > 0; $k--) {
      $at = mt_rand(0, count($lines));
      if (mt_rand(0, 1) === 1 && $at < count($lines)) array_splice($lines, $at, 1); else array_splice($lines, $at, 0, [chr(65 + mt_rand(0, 5)) . "\n"]);
    }
    return implode('', $lines);
  };
  $o = implode('', $lines);
  $x = $mutate($lines);
  $y = $mutate($lines);
  pmg_test(coreui_pvcs_merge3($o, $x, $o)['content'] === $x && coreui_pvcs_merge3($o, $o, $y)['content'] === $y
    && coreui_pvcs_merge3($o, $x, $x)['content'] === $x, 'one side only, or the same on both, is taken as it is: ' . json_encode([$o, $x, $y]));
  $both = coreui_pvcs_merge3($o, $x, $y, 'o', 't');
  if ($both['clean']) {
    pmg_test(!str_contains($both['content'], '<<<<<<<'), 'a clean merge has no markers');
  } else {
    pmg_test(coreui_pvcs_has_markers($both['content'], 'o', 't'), 'a conflict is marked');
  }
}
pmg_test(coreui_pvcs_merge_trees(['a'=>'1', 'b'=>'2', 'c'=>'3', 'd'=>'4', 'e'=>'5', 'f'=>'6'],
    ['a'=>'1', 'b'=>'9', 'c'=>'3', 'd'=>'8', 'f'=>'7', 'g'=>'g'], ['a'=>'1', 'b'=>'2', 'c'=>'7', 'd'=>'5', 'e'=>'5', 'h'=>'h'])
  === ['take'=>['a'=>'1', 'b'=>'9', 'c'=>'7', 'g'=>'g', 'h'=>'h'], 'lines'=>['d'], 'conflicts'=>['f'=>'deleted_theirs']],
  'which files a merge decides: one side changed, both changed, deleted on one side');
pmg_test(coreui_pvcs_merge_trees(['x'=>'1'], [], ['x'=>'2'])['conflicts'] === ['x'=>'deleted_ours']
  && coreui_pvcs_merge_trees(['x'=>'1'], [], [])['take'] === [], 'deleted here and changed there; deleted on both');
$graph = [1=>[], 2=>[1], 3=>[2], 4=>[2], 5=>[3, 4], 6=>[4], 7=>[6, 5]];
pmg_test(coreui_pvcs_merge_base($graph, 3, 4) === 2 && coreui_pvcs_merge_base($graph, 5, 6) === 4 && coreui_pvcs_merge_base($graph, 7, 3) === 3
  && coreui_pvcs_merge_base($graph, null, 3) === null && array_keys(coreui_pvcs_ancestors($graph, 5)) === [5, 4, 2, 1, 3],
  'the newest common version, over both parents of a merge');
pmg_test(coreui_pvcs_has_markers("x\n<<<<<<< main\n", 'main', 'neu') && coreui_pvcs_has_markers(">>>>>>> neu\r\n", 'main', 'neu')
  && !coreui_pvcs_has_markers("<<<<<<< SUCHEN\n=======\n>>>>>>> ERSETZEN\n", 'main', 'neu'), 'only the markers of this merge count');

// Mara's markers.
pmg_test(coreui_pftools_extract_commit("Fertig.\n[VERSION_SICHERN: Neue Farbe fuer den Titel]") === 'Neue Farbe fuer den Titel'
  && coreui_pftools_extract_branch('[ARBEITSSTAND: mara/neue-farbe]') === 'mara/neue-farbe'
  && coreui_pftools_extract_merge('[ZUSAMMENFÜHREN: mara/neue-farbe]') === 'mara/neue-farbe'
  && coreui_pftools_extract_merge('[ZUSAMMENFUEHREN: x]') === 'x' && coreui_pftools_extract_commit('[VERSION_SICHERN: ]') === null, 'markers');
pmg_test(coreui_agent_next_tool('[ARBEITSSTAND: mara/x]', ['pvcs_branch']) === ['tool'=>'pvcs_branch', 'input'=>'mara/x']
  && coreui_agent_next_tool('[ARBEITSSTAND: mara/x]', ['pfile_read']) === null
  && coreui_agent_next_tool("[DATEI_NEU: a.txt]\nx\n[/DATEI_NEU]\n[VERSION_SICHERN: a]", ['pfile_write', 'pvcs_commit'])['tool'] === 'pfile_write',
  'the work loop takes them only where allowed, a file first');
pmg_test(coreui_agent_step_note(['tool'=>'pvcs_commit', 'input'=>'Neue Farbe']) === 'Ich sichere eine Version: Neue Farbe'
  && coreui_agent_step_note(['tool'=>'pvcs_branch', 'input'=>'mara/x']) === 'Ich arbeite auf dem Arbeitsstand mara/x'
  && coreui_agent_step_label(['tool'=>'pvcs_merge', 'input'=>'mara/x'], 1, 6) === 'Zusammengeführt: „mara/x“'
  && str_contains(coreui_agent_tool_menu(['pvcs_commit', 'pvcs_branch', 'pvcs_merge']), '[ZUSAMMENFUEHREN: name]')
  && !str_contains(coreui_agent_tool_menu(['pvcs_commit']), 'ZUSAMMENFUEHREN'), 'work view and tool menu');

// --- Wiring (static) ---------------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/project_files.php');
pmg_test(strpos($endpoint, "case 'vcs_merge':") > strpos($endpoint, 'stu_require_csrf();')
  && str_contains($endpoint, "stu_json(['ok'=>true, 'merge'=>coreui_pvcs_merge_preview(\$pdo, \$uid, \$projectId, (int)\$_GET['vcs_merge'])]);")
  && str_contains($endpoint, "'already_current', 'already_merged', 'merge_same', 'merge_changed', 'merge_unresolved'], true) => 409,"),
  'endpoint: preview, merge behind CSRF, conflicts as 409');
$chat = (string)file_get_contents($root . '/api/chat.php');
pmg_test(str_contains($chat, "if (coreui_pvcs_ready(stu_pdo())) array_push(\$tools, 'pvcs_commit', 'pvcs_branch');")
  && str_contains($chat, "if (coreui_pvcs_merge_ready(stu_pdo())) \$tools[] = 'pvcs_merge';")
  && str_contains($chat, "in_array(\$tool['tool'], ['pfile_read', 'pfile_edit', 'pfile_write', 'prun', 'pvcs_commit', 'pvcs_branch', 'pvcs_merge'], true)")
  && str_contains($chat, "['web', 'archive', 'skill', 'image', 'pfile_read', 'pfile_edit', 'pfile_write', 'prun', 'pvcs_commit', 'pvcs_branch', 'pvcs_merge']"),
  'chat.php: offered with the migrations, first step, dispatch');
pmg_test(ember_strip_stray_tool_markers_check($chat), 'chat.php: a version marker that did not run is no text');
function ember_strip_stray_tool_markers_check(string $chat): bool {
  if (!preg_match('~function ember_strip_stray_tool_markers\(string \$out\): string \{.*?\n\}~s', $chat, $m)) return false;
  eval(str_replace('function ember_strip_stray_tool_markers(', 'function pmg_strip(', $m[0]));
  return pmg_strip("Gesichert.\n[VERSION_SICHERN: Neue Farbe]\n[ARBEITSSTAND: mara/x] [ZUSAMMENFÜHREN: y]") === "Gesichert.\n\n";
}
$migration = (string)file_get_contents($root . '/database/migrations/050_project_merges.sql');
pmg_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS merge_parent_id BIGINT UNSIGNED NULL AFTER parent_id')
  && str_contains($migration, "ENUM('build','create','edit','rename','restore','mara','branch','merge')")
  && str_contains($migration, "VALUES ('050_project_merges')"), 'migration 050');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Zusammenfuehren: Zeilen, Dreiwege-Merge, Dateien, gemeinsame Version, Marker, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite ----------------------------------------------------------------------------
function pmg_sqlite(bool $merge = true): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
  $pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_media (user_id INTEGER, kind TEXT, file_size INTEGER)');
  $pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT, description TEXT)');
  $pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_files (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, path TEXT NOT NULL,
    content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, UNIQUE (project_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_file_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id TEXT NOT NULL, project_id TEXT NOT NULL,
    user_id INTEGER NOT NULL, revision INTEGER NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL,
    source TEXT NOT NULL, session_id TEXT, message_id INTEGER, created_at TEXT, UNIQUE (file_id, revision))');
  $pdo->exec('CREATE TABLE stu_console_project_blobs (project_id TEXT NOT NULL, sha256 TEXT NOT NULL, user_id INTEGER NOT NULL, content TEXT NOT NULL,
    size_bytes INTEGER NOT NULL, created_at TEXT, PRIMARY KEY (project_id, sha256))');
  $pdo->exec('CREATE TABLE stu_console_project_branches (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, name TEXT NOT NULL,
    head_commit_id INTEGER, worktree_saved INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE (project_id, name))');
  $pdo->exec('CREATE TABLE stu_console_project_commits (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, branch_id INTEGER,
    parent_id INTEGER, ' . ($merge ? 'merge_parent_id INTEGER, ' : '') . 'message TEXT NOT NULL, author TEXT NOT NULL, files_count INTEGER NOT NULL,
    bytes INTEGER NOT NULL, tree_sha256 TEXT NOT NULL, created_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_commit_files (commit_id INTEGER NOT NULL, path TEXT NOT NULL, sha256 TEXT NOT NULL, PRIMARY KEY (commit_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_worktrees (branch_id INTEGER NOT NULL, path TEXT NOT NULL, sha256 TEXT NOT NULL, PRIMARY KEY (branch_id, path))');
  $pdo->exec('CREATE TABLE stu_console_project_vcs (project_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, active_branch_id INTEGER NOT NULL, updated_at TEXT)');
  $pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
  return $pdo;
}
$pdo = pmg_sqlite();
$p = str_repeat('a', 32); $o = str_repeat('c', 32);
$ins = $pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)');
$ins->execute([$p, 7, 'Spiel', 'open', '', null, '2026-11-17']);
$ins->execute([$o, 9, 'Fremd', 'open', '', null, '2026-11-17']);
$pdo->exec("INSERT INTO stu_console_sessions VALUES ('cs_merge_chat', 7, '{$p}', 'Spiel', NULL, '2026-11-17', '2026-11-17')");
$page = "<!doctype html>\n<title>Spiel</title>\n<h1>Start</h1>\n<p>Willkommen</p>\n<script src=\"app.js\"></script>\n";
$app = "let punkte = 0;\nfunction start() {\n  punkte = 0;\n}\nfunction ende() {\n  return punkte;\n}\n";
coreui_pfiles_write($pdo, 7, $p, 'index.html', $page, 'build');
coreui_pfiles_write($pdo, 7, $p, 'app.js', $app, 'build');
coreui_pfiles_write($pdo, 7, $p, 'alt.txt', "alt\n", 'build');
coreui_pfiles_write($pdo, 7, $p, 'regeln.md', "# Regeln\n", 'build');
$file = static fn(string $path): ?string => coreui_pfiles_row($pdo, 7, $p, $path)['content'] ?? null;
$named = static fn(array $s, string $name): array => array_values(array_filter($s['branches'], static fn(array $b): bool => $b['name'] === $name))[0];
$s = coreui_pvcs_state($pdo, 7, $p);
pmg_test($s['can_merge'] === true && $s['active']['merged'], 'merging is there with migration 050');
$mainId = $s['active']['id'];

// A working state of its own: a new title, a new file, an old file deleted, one function changed.
$s = coreui_pvcs_branch_create($pdo, 7, $p, 'level-zwei', false);
$levelId = $s['active']['id'];
coreui_pfiles_write($pdo, 7, $p, 'index.html', str_replace('<h1>Start</h1>', '<h1>Level 2</h1>', $page), 'edit');
coreui_pfiles_write($pdo, 7, $p, 'level.js', "const level = 2;\n", 'create');
coreui_pfiles_delete($pdo, 7, $p, 'alt.txt');
coreui_pfiles_write($pdo, 7, $p, 'app.js', str_replace("  return punkte;\n", "  return punkte * 2;\n", $app), 'edit');
$levelCommit = coreui_pvcs_commit($pdo, 7, $p, 'Level zwei');
// Meanwhile on main: another line of app.js and the rules.
coreui_pvcs_switch($pdo, 7, $p, $mainId);
coreui_pfiles_write($pdo, 7, $p, 'app.js', str_replace("  punkte = 0;\n}", "  punkte = 10;\n}", $app), 'edit');
coreui_pfiles_write($pdo, 7, $p, 'regeln.md', "# Regeln\nSei fair.\n", 'edit');
$mainCommit = coreui_pvcs_commit($pdo, 7, $p, 'Startpunkte');
$s = coreui_pvcs_state($pdo, 7, $p);
pmg_test(!$named($s, 'level-zwei')['merged'], 'the other working state has something to merge');

// Before anything changes: what the merge does.
$before = coreui_pvcs_state($pdo, 7, $p);
$preview = coreui_pvcs_merge_preview($pdo, 7, $p, $levelId);
pmg_test($preview['changes'] === ['added'=>['level.js'], 'modified'=>['app.js', 'index.html'], 'deleted'=>['alt.txt'], 'clean'=>false]
  && $preview['merged'] === ['app.js'] && $preview['conflicts'] === [] && !$preview['unsaved'] && $preview['into']['name'] === 'main'
  && $preview['from']['name'] === 'level-zwei' && $preview['message'] === 'Zusammengeführt: level-zwei', 'preview: ' . json_encode($preview));
pmg_test(coreui_pvcs_state($pdo, 7, $p) == $before && $file('alt.txt') === "alt\n", 'a preview changes nothing');

// The merge: a new version with two parents; both sides' changes are there.
$merge = coreui_pvcs_merge($pdo, 7, $p, $levelId, ['ours_tree'=>$preview['ours_tree'], 'theirs_head'=>$preview['theirs_head']]);
$s = $merge['state'];
pmg_test($merge['commit']['parent_id'] === $mainCommit['id'] && $merge['commit']['merge_parent_id'] === $levelCommit['id']
  && $merge['commit']['message'] === 'Zusammengeführt: level-zwei' && $merge['commit']['author'] === 'person', 'a version with two parents');
pmg_test($file('index.html') === str_replace('<h1>Start</h1>', '<h1>Level 2</h1>', $page) && $file('level.js') === "const level = 2;\n"
  && $file('alt.txt') === null && $file('regeln.md') === "# Regeln\nSei fair.\n"
  && $file('app.js') === "let punkte = 0;\nfunction start() {\n  punkte = 10;\n}\nfunction ende() {\n  return punkte * 2;\n}\n",
  'every change of both sides is in the project files');
pmg_test($s['status']['clean'] && $named($s, 'level-zwei')['merged'] && in_array($levelCommit['id'], array_column($s['log'], 'id'), true)
  && $s['log'][0]['id'] === $merge['commit']['id'], 'nothing unsaved, the merged versions are in the log');
$rev = $pdo->query("SELECT source FROM stu_console_project_file_revisions WHERE path='app.js' ORDER BY id DESC LIMIT 1")->fetchColumn();
pmg_test($rev === 'merge', 'a file changed by a merge has a revision of that kind: ' . $rev);
pmg_test(pmg_throws(static fn() => coreui_pvcs_merge_preview($pdo, 7, $p, $levelId), 'already_merged')
  && pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId), 'already_merged')
  && pmg_throws(static fn() => coreui_pvcs_merge_preview($pdo, 7, $p, $mainId), 'merge_same'), 'nothing left to merge');
$s = coreui_pvcs_switch($pdo, 7, $p, $levelId);
pmg_test($file('regeln.md') === "# Regeln\n" && $file('alt.txt') === null && $s['status']['clean'], 'the merged working state stays as it was');

// A conflict: the same title differently on both sides, with unsaved work on main.
coreui_pfiles_write($pdo, 7, $p, 'index.html', str_replace('<h1>Start</h1>', '<h1>Bonuslevel</h1>', $page), 'edit');
coreui_pfiles_write($pdo, 7, $p, 'regeln.md', "# Regeln\nKeine.\n", 'edit');
coreui_pvcs_commit($pdo, 7, $p, 'Bonus');
$level = coreui_pvcs_state($pdo, 7, $p)['active'];
coreui_pvcs_switch($pdo, 7, $p, $mainId);
coreui_pfiles_write($pdo, 7, $p, 'index.html', str_replace('<h1>Start</h1>', '<h1>Endlevel</h1>', $page), 'edit');
coreui_pfiles_delete($pdo, 7, $p, 'regeln.md');
$count = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id='{$p}'")->fetchColumn();
$commits = $count();
$preview = coreui_pvcs_merge_preview($pdo, 7, $p, $levelId);
$byPath = array_column($preview['conflicts'], null, 'path');
pmg_test(array_keys($byPath) === ['index.html', 'regeln.md'] && $preview['unsaved'] && $byPath['index.html']['kind'] === 'content'
  && str_contains((string)$byPath['index.html']['merged'], "<<<<<<< main\n<h1>Endlevel</h1>\n=======\n<h1>Bonuslevel</h1>\n>>>>>>> level-zwei\n")
  && str_contains((string)$byPath['index.html']['ours'], 'Endlevel') && str_contains((string)$byPath['index.html']['theirs'], 'Bonuslevel')
  && $byPath['regeln.md']['kind'] === 'deleted_ours' && $byPath['regeln.md']['ours'] === null && $byPath['regeln.md']['theirs'] === "# Regeln\nKeine.\n",
  'conflicts: both versions, a text with markers, deleted here and changed there: ' . json_encode($preview['conflicts']));
$base = ['ours_tree'=>$preview['ours_tree'], 'theirs_head'=>$preview['theirs_head']];
$solved = str_replace('<h1>Start</h1>', '<h1>Endlevel mit Bonus</h1>', $page);
pmg_test(pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base), 'merge_unresolved')
  && pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base + ['resolutions'=>['index.html'=>['choice'=>'ours']]]), 'merge_unresolved')
  && pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base + ['resolutions'=>['index.html'=>['choice'=>'text',
    'text'=>(string)$byPath['index.html']['merged']], 'regeln.md'=>['choice'=>'ours']]]), 'conflict_markers')
  && pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base + ['resolutions'=>['index.html'=>['choice'=>'beide'],
    'regeln.md'=>['choice'=>'ours']]]), 'invalid_resolution')
  && pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base + ['resolutions'=>['index.html'=>['choice'=>'ours'],
    'regeln.md'=>['choice'=>'ours'], 'app.js'=>['choice'=>'ours']]]), 'invalid_resolution'),
  'without a solution for every conflict nothing goes');
pmg_test($count() === $commits && $file('regeln.md') === null && str_contains((string)$file('index.html'), 'Endlevel'), 'and nothing changed');
// Something changes meanwhile: the person sees the merge again.
coreui_pfiles_write($pdo, 7, $p, 'app.js', (string)$file('app.js') . "// neu\n", 'edit');
$resolutions = ['index.html'=>['choice'=>'text', 'text'=>$solved], 'regeln.md'=>['choice'=>'theirs']];
pmg_test(pmg_throws(static fn() => coreui_pvcs_merge($pdo, 7, $p, $levelId, $base + ['resolutions'=>$resolutions]), 'merge_changed'), 'changed meanwhile');
pmg_test(pmg_throws(static fn() => coreui_pvcs_merge($pdo, 9, $p, $levelId, ['resolutions'=>$resolutions]), 'project_not_found')
  && pmg_throws(static fn() => coreui_pvcs_merge_preview($pdo, 9, $p, $levelId), 'project_not_found'), 'nobody else merges');
$preview = coreui_pvcs_merge_preview($pdo, 7, $p, $levelId);
$merge = coreui_pvcs_merge($pdo, 7, $p, $levelId, ['ours_tree'=>$preview['ours_tree'], 'theirs_head'=>$preview['theirs_head'],
  'resolutions'=>$resolutions, 'message'=>'Bonus und Endlevel']);
$s = $merge['state'];
pmg_test($count() === $commits + 2 && $s['log'][1]['message'] === 'Automatisch gesichert vor dem Zusammenführen' && $s['log'][1]['author'] === 'auto'
  && $merge['commit']['message'] === 'Bonus und Endlevel' && $merge['commit']['parent_id'] === $s['log'][1]['id']
  && $merge['commit']['merge_parent_id'] === $level['head_commit_id'], 'unsaved work saved first, then the merge: ' . json_encode(array_column($s['log'], 'message')));
pmg_test($file('index.html') === $solved && $file('regeln.md') === "# Regeln\nKeine.\n" && str_ends_with((string)$file('app.js'), "// neu\n")
  && $s['status']['clean'], 'the solutions are in the project files, the unsaved line too');
pmg_test(str_contains(coreui_pvcs_file($pdo, 7, $p, $s['log'][1]['id'], 'index.html')['content'], 'Endlevel</h1>')
  && coreui_pvcs_file($pdo, 7, $p, $s['log'][1]['id'], 'regeln.md')['exists'] === false, 'the state before the merge stays as a version');

// Deleted there, changed here: keep the change.
coreui_pvcs_switch($pdo, 7, $p, $levelId);
coreui_pfiles_delete($pdo, 7, $p, 'level.js');
coreui_pvcs_commit($pdo, 7, $p, 'Ohne level.js');
coreui_pvcs_switch($pdo, 7, $p, $mainId);
coreui_pfiles_write($pdo, 7, $p, 'level.js', "const level = 3;\n", 'edit');
coreui_pvcs_commit($pdo, 7, $p, 'Level drei');
$preview = coreui_pvcs_merge_preview($pdo, 7, $p, $levelId);
pmg_test(array_column($preview['conflicts'], 'kind', 'path') === ['level.js'=>'deleted_theirs'], 'deleted there, changed here');
coreui_pvcs_merge($pdo, 7, $p, $levelId, ['resolutions'=>['level.js'=>['choice'=>'ours']]]);
pmg_test($file('level.js') === "const level = 3;\n", 'the change stays when chosen');

// The Git export: a merge with two parents that git understands.
$zip = coreui_pvcs_git_zip($pdo, 7, $p);
$git = trim((string)shell_exec('command -v git 2>/dev/null'));
$gitChecked = 'git nicht installiert';
if ($git !== '') {
  $dir = sys_get_temp_dir() . '/coreai-merge-' . bin2hex(random_bytes(4));
  mkdir($dir);
  $za = new ZipArchive();
  $za->open($zip['path']);
  $za->extractTo($dir);
  $za->close();
  $run = static function (string $cmd) use ($dir): array {
    exec('cd ' . escapeshellarg($dir) . ' && GIT_CONFIG_NOSYSTEM=1 HOME=' . escapeshellarg($dir) . ' ' . $cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
  };
  [$c1, $fsck] = $run('git fsck --strict --no-dangling');
  [$c2, $parents] = $run('git rev-list --parents -n 1 main');
  [$c3, $mergeBase] = $run('git merge-base main level-zwei');
  [$c4, $levelHead] = $run('git rev-parse level-zwei');
  [$c5, $merged] = $run("git branch --merged main --format='%(refname:short)'");
  [$c6, $status] = $run('git status --porcelain');
  [$c7, $merges] = $run('git log --merges --format=%s main');
  pmg_test($c1 === 0, 'git fsck accepts the merges: ' . $fsck);
  pmg_test($c2 === 0 && count(explode(' ', $parents)) === 3, 'the newest version has two parents in Git: ' . $parents);
  pmg_test($c3 === 0 && $c4 === 0 && $mergeBase === $levelHead && $c5 === 0 && $merged === "level-zwei\nmain", 'git sees level-zwei merged into main');
  pmg_test($c6 === 0 && $status === '', 'git status is clean');
  pmg_test($c7 === 0 && $merges === "Zusammengeführt: level-zwei\nBonus und Endlevel\nZusammengeführt: level-zwei", 'git log --merges: ' . $merges);
  exec('rm -rf ' . escapeshellarg($dir));
  $gitChecked = 'git fsck, rev-list, merge-base und branch --merged gepruefter Export';
}
@unlink($zip['path']);

// Deleting a merged working state keeps its versions (they are part of main now).
$s = coreui_pvcs_branch_delete($pdo, 7, $p, $levelId);
pmg_test(count($s['branches']) === 1 && coreui_pvcs_commit_changes($pdo, 7, $p, $levelCommit['id'])['commit']['id'] === $levelCommit['id'],
  'the versions of a merged working state stay with main');

// Mara with versions in a project chat.
$runTool = static fn(string $kind, string $input): string => coreui_pftools_run($pdo, 7, 'cs_merge_chat', ['tool'=>$kind, 'input'=>$input]);
pmg_test($runTool('pvcs_commit', 'Nichts neu') === 'Es gibt keine ungesicherten Aenderungen; nichts gesichert.', 'Mara: nothing to save');
$out = $runTool('pvcs_branch', 'mara/farbe');
pmg_test(str_starts_with($out, 'Neuer Arbeitsstand mara/farbe angelegt und aktiv.') && coreui_pvcs_state($pdo, 7, $p)['active']['name'] === 'mara/farbe',
  'Mara starts her own working state: ' . $out);
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: teal; }\n", 'mara', ['session_id'=>'cs_merge_chat']);
$out = $runTool('pvcs_commit', 'Neue Farbe fuer den Titel');
$s = coreui_pvcs_state($pdo, 7, $p);
pmg_test(str_starts_with($out, 'Version gesichert: "Neue Farbe fuer den Titel" auf dem Arbeitsstand mara/farbe') && $s['log'][0]['author'] === 'mara',
  'Mara saves a version as hers: ' . $out);
pmg_test($runTool('pvcs_branch', 'mara/farbe') === 'Der Arbeitsstand mara/farbe ist schon aktiv.'
  && str_starts_with($runTool('pvcs_branch', 'main'), 'Gewechselt auf den Arbeitsstand main') && $file('style.css') === null,
  'Mara switches back; main does not have her change');
$out = $runTool('pvcs_merge', 'mara/farbe');
pmg_test(str_starts_with($out, 'Zusammengefuehrt: mara/farbe in main (1 neu, 0 geaendert, 0 geloescht)') && $file('style.css') === "h1 { color: teal; }\n"
  && coreui_pvcs_state($pdo, 7, $p)['log'][0]['author'] === 'mara', 'Mara merges without conflicts: ' . $out);
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: red; }\n", 'edit');
coreui_pvcs_commit($pdo, 7, $p, 'Rot');
$runTool('pvcs_branch', 'mara/farbe');
coreui_pfiles_write($pdo, 7, $p, 'style.css', "h1 { color: gold; }\n", 'mara');
$runTool('pvcs_commit', 'Gold');
$runTool('pvcs_branch', 'main');
$commits = $count();
$out = $runTool('pvcs_merge', 'mara/farbe');
pmg_test(str_starts_with($out, 'NICHT zusammengefuehrt: 1 Konflikt(e) in style.css') && $count() === $commits && $file('style.css') === "h1 { color: red; }\n",
  'with a conflict Mara changes nothing and says so: ' . $out);
pmg_test(str_starts_with($runTool('pvcs_merge', 'gibt-es-nicht'), 'Das ging nicht: diesen Arbeitsstand gibt es im Projekt nicht')
  && str_starts_with($runTool('pvcs_branch', 'a b'), 'Das ging nicht: der Name eines Arbeitsstands'), 'Mara is told what went wrong');
pmg_test(coreui_pftools_run($pdo, 9, 'cs_merge_chat', ['tool'=>'pvcs_commit', 'input'=>'x']) === 'Projektdateien gibt es nur in einem Chat, der zu einem Projekt gehoert.',
  'not in another account\'s chat');
$hint = coreui_pftools_hint($pdo, 7, 'cs_merge_chat', false, true);
pmg_test(str_contains($hint, 'VERSIONEN: Aktiver Arbeitsstand ist main.') && str_contains($hint, '[ZUSAMMENFUEHREN: name]')
  && str_contains(coreui_pftools_hint($pdo, 7, 'cs_merge_chat', true, false), 'Version sichern: [VERSION_SICHERN: Beschreibung].'), 'Mara\'s hint names the versions');

// Without migration 050: versions yes, merging no.
$old = pmg_sqlite(false);
$old->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$p, 7, 'Alt', 'open', '', null, '2026-11-17']);
$old->exec("INSERT INTO stu_console_sessions VALUES ('cs_old', 7, '{$p}', 'Alt', NULL, '2026-11-17', '2026-11-17')");
coreui_pfiles_write($old, 7, $p, 'a.txt', "a\n", 'build');
$os = coreui_pvcs_branch_create($old, 7, $p, 'zwei', true);
pmg_test($os['can_merge'] === false && pmg_throws(static fn() => coreui_pvcs_merge_preview($old, 7, $p, $os['branches'][0]['id']), 'project_merge_migration_required')
  && !str_contains(coreui_pftools_hint($old, 7, 'cs_old', false, false), 'ZUSAMMENFUEHREN'), 'without migration 050 no merging');
coreui_pfiles_write($old, 7, $p, 'a.txt', "b\n", 'edit');
coreui_pvcs_commit($old, 7, $p, 'B');
coreui_pvcs_branch_delete($old, 7, $p, coreui_pvcs_switch($old, 7, $p, $os['branches'][0]['id'])['branches'][1]['id']);
pmg_test(count(coreui_pvcs_state($old, 7, $p)['branches']) === 1, 'and deleting still works there');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql', '049_project_versions.sql', '050_project_merges.sql', '050_project_merges.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('pmg-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  try {
    $project = coreui_project_create($db, $uid, 'Selbsttest Zusammenfuehren', 'open');
    $pid = $project['id'];
    coreui_pfiles_write($db, $uid, $pid, 'a.txt', "eins\nzwei\ndrei\nvier\n", 'build');
    $s = coreui_pvcs_state($db, $uid, $pid);
    $main = $s['active']['id'];
    pmg_test($s['can_merge'], 'MariaDB: merging is there (migration 050, applied twice)');
    $s = coreui_pvcs_branch_create($db, $uid, $pid, 'zwei', false);
    coreui_pfiles_write($db, $uid, $pid, 'a.txt', "EINS\nzwei\ndrei\nvier\n", 'edit');
    coreui_pvcs_commit($db, $uid, $pid, 'Eins gross');
    coreui_pvcs_switch($db, $uid, $pid, $main);
    coreui_pfiles_write($db, $uid, $pid, 'a.txt', "eins\nzwei\ndrei\nVIER\n", 'edit');
    $merge = coreui_pvcs_merge($db, $uid, $pid, $s['active']['id'], ['message'=>'Beide gross']);
    pmg_test(coreui_pfiles_get($db, $uid, $pid, 'a.txt')['content'] === "EINS\nzwei\ndrei\nVIER\n" && $merge['commit']['merge_parent_id'] !== null
      && $merge['state']['log'][1]['message'] === 'Automatisch gesichert vor dem Zusammenführen', 'MariaDB: a merge with two parents');
    $rev = $db->prepare("SELECT source FROM stu_console_project_file_revisions WHERE project_id=? ORDER BY id DESC LIMIT 1");
    $rev->execute([$pid]);
    pmg_test($rev->fetchColumn() === 'merge', 'MariaDB: the revision kind "merge" (migration 050)');
    coreui_project_delete($db, $uid, $pid);
    $left = $db->prepare('SELECT COUNT(*) FROM stu_console_project_commits WHERE project_id=?');
    $left->execute([$pid]);
    pmg_test((int)$left->fetchColumn() === 0, 'MariaDB: everything goes with the project');
    $mariadb = ', MariaDB (Migrationen 049 und 050)';
  } finally {
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
  }
}

echo "CoreAI Zusammenfuehren: Zeilen, Dreiwege-Merge, Dateien, gemeinsame Version, Marker, Vorschau, Zusammenfuehren mit zwei Eltern, Konflikte loesen, "
  . "unveraenderter anderer Arbeitsstand, Mara mit Versionen, fehlende Migration OK; {$gitChecked} (SQLite{$mariadb})\n";
