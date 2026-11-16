<?php
declare(strict_types=1);
// CoreAI 0.7.71 (P09 B): Mara reads and changes single project files in a project chat.
//   php scripts/project-file-tools-selftest.php
// Offline: the markers [DATEI: pfad], [DATEI_AENDERN: pfad] (SUCHEN/ERSETZEN) and [DATEI_NEU: pfad],
// the change itself (exactly once, all or nothing, CRLF), line numbers, a wrapping code fence,
// the tools against SQLite (project chat only, other accounts, revisions from "mara", errors as
// sentences for Mara), the prompt hint, the work loop with a scripted Core (read, change, answer),
// the safety net that keeps file blocks out of the chat, and the wiring in chat.php.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/console_agent_loop.php';
require_once $root . '/api/project_file_tools.php';
$chatSource = (string)file_get_contents($root . '/api/chat.php');
foreach (['ember_without_inline_code', 'ember_extract_web_marker', 'ember_strip_stray_tool_markers'] as $fn) {
  if (preg_match('~^function ' . $fn . '\(.*?^}~ms', $chatSource, $m) !== 1) { fwrite(STDERR, "missing {$fn}\n"); exit(1); }
  eval($m[0]);
}
$GLOBALS['pft_events'] = [];
function coreui_work_record(PDO $pdo, string $kind, string $state, string $title, array $meta = []): ?array {
  $GLOBALS['pft_events'][] = [$kind, $state, $title];
  return null;
}
function pft_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project file tools FAIL: {$why}\n"); exit(1); }
}

// --- Markers (pure) ------------------------------------------------------------------------
pft_test(coreui_pftools_extract_read('Ich schaue nach. [DATEI: src/game.js]') === 'src/game.js', 'read marker');
pft_test(coreui_pftools_extract_read('[DATEI_AENDERN: a.md]') === null && coreui_pftools_extract_read('[DATEI: ]') === null, 'no read marker');
$edit = "Ich passe die Farbe an.\n[DATEI_AENDERN: css/style.css]\n<<<<<<< SUCHEN\nh1 { color: red; }\n=======\nh1 { color: blue; }\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]\nFertig.";
pft_test(coreui_pftools_extract_edit($edit) === "css/style.css\n<<<<<<< SUCHEN\nh1 { color: red; }\n=======\nh1 { color: blue; }\n>>>>>>> ERSETZEN",
  'edit block with prose around it: ' . json_encode(coreui_pftools_extract_edit($edit)));
pft_test(coreui_pftools_extract_edit("[DATEI_AENDERN: a.md]\n<<<<<<< SUCHEN\nx\n=======\ny\n>>>>>>> ERSETZEN") === null, 'an unclosed block is no change');
pft_test(coreui_pftools_extract_write("[DATEI_NEU: README.md]\n# Spiel\n[/DATEI_NEU]") === "README.md\n# Spiel", 'new file block');
pft_test(coreui_pftools_extract_write("[DATEI_NEU: e.md]\n[/DATEI_NEU]") === "e.md\n", 'an empty new file');
$pairs = coreui_pftools_parse_edits("<<<<<<<< SUCHEN\r\na\r\nb\r\n========\r\nc\r\n>>>>>>>> ERSETZEN\r\n\r\n<<<<<<< SUCHEN\nd\n=======\n>>>>>>> ERSETZEN");
pft_test($pairs === [["a\nb", 'c'], ['d', '']], 'two blocks, CRLF, eight characters, an empty replacement deletes: ' . json_encode($pairs));
pft_test(coreui_pftools_parse_edits('nur Text') === [], 'no blocks');

// --- Applying a change (pure) -----------------------------------------------------------------
$file = "<h1>Hallo</h1>\n<p>Text</p>\n<p>Text</p>\n<footer>alt</footer>\n";
$ok = coreui_pftools_apply_edits($file, [['<h1>Hallo</h1>', "<h1>Hallo Welt</h1>\n<h2>Neu</h2>"], ['<footer>alt</footer>', '<footer>neu</footer>']]);
pft_test($ok['ok'] && $ok['content'] === "<h1>Hallo Welt</h1>\n<h2>Neu</h2>\n<p>Text</p>\n<p>Text</p>\n<footer>neu</footer>\n"
  && $ok['removed'] === 2 && $ok['added'] === 3, 'two blocks applied, line counts');
$twice = coreui_pftools_apply_edits($file, [['<h1>Hallo</h1>', '<h1>X</h1>'], ['<p>Text</p>', '<p>Y</p>']]);
pft_test(!$twice['ok'] && $twice['content'] === $file && str_contains($twice['error'], 'Block 2') && str_contains($twice['error'], '2 Mal'),
  'an ambiguous second block changes nothing at all: ' . $twice['error']);
$missing = coreui_pftools_apply_edits($file, [['<h1>Hello</h1>', 'x']]);
pft_test(!$missing['ok'] && str_contains($missing['error'], 'nicht in der Datei vor') && str_contains($missing['error'], '[DATEI: pfad]'), 'a missing search text');
pft_test(!coreui_pftools_apply_edits($file, [["  \n", 'x']])['ok'] && !coreui_pftools_apply_edits($file, [])['ok'], 'empty search and no blocks are refused');
$crlf = coreui_pftools_apply_edits("a\r\nb\r\n", [["a\nb", "a\nB"]]);
pft_test($crlf['ok'] && $crlf['content'] === "a\r\nB\r\n", 'a CRLF file keeps CRLF');
pft_test(coreui_pftools_numbered("eins\nzwei") === " 1 | eins\n 2 | zwei" || coreui_pftools_numbered("eins\nzwei") === "1 | eins\n2 | zwei",
  'line numbers: ' . json_encode(coreui_pftools_numbered("eins\nzwei")));
pft_test(str_contains(coreui_pftools_numbered(str_repeat("zeile\n", 5000), 200), '... (gekuerzt nach Zeile'), 'long files are shortened');
pft_test(coreui_pftools_file_body("```html\n<p>x</p>\n```") === "<p>x</p>\n" && coreui_pftools_file_body("a") === "a\n"
  && coreui_pftools_file_body('') === '' && coreui_pftools_file_body("```\n```js\n```") === "```js\n", 'a wrapping code fence goes, a final newline comes');

// --- The work loop picks the file tools ---------------------------------------------------------
$files = ['pfile_read', 'pfile_edit', 'pfile_write', 'web'];
pft_test(coreui_agent_next_tool('[DATEI: index.html]', $files) === ['tool'=>'pfile_read', 'input'=>'index.html'], 'loop: read');
$codeWithWeb = "[DATEI_NEU: suche.js]\nconst q = '[WEB: nicht ausfuehren]';\n[/DATEI_NEU]";
pft_test(coreui_agent_next_tool('[WEB: wetter]', $files) === ['tool'=>'web', 'input'=>'wetter'], 'the real web marker reader is loaded');
pft_test(coreui_agent_next_tool($codeWithWeb, $files)['tool'] === 'pfile_write', 'a marker inside file code is code, not a search');
pft_test(coreui_agent_next_tool('[DATEI: index.html]', ['web']) === null, 'outside a project chat the tools do not exist');
pft_test(coreui_agent_step_note(['tool'=>'pfile_edit', 'input'=>"css/style.css\n<<<<<<< SUCHEN\nsecret"]) === 'Ich ändere die Datei css/style.css',
  'the work view shows the path, never the code');
pft_test(coreui_agent_step_label(['tool'=>'pfile_write', 'input'=>"a.md\nInhalt"], 1, 6) === 'Datei geschrieben: „a.md“', 'step label');
pft_test(str_contains(coreui_agent_tool_menu($files), '[DATEI_AENDERN: pfad]') && !str_contains(coreui_agent_tool_menu(['web']), 'DATEI'), 'tool menu');

// --- Safety net: no file block reaches the chat ------------------------------------------------
pft_test(ember_strip_stray_tool_markers("Erledigt.\n[DATEI_AENDERN: a.md]\n<<<<<<< SUCHEN\nx\n=======\ny\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]\nGruss") === "Erledigt.\n\nGruss",
  'a closed change block is removed');
pft_test(ember_strip_stray_tool_markers("Ich schreibe sie neu.\n[DATEI_NEU: a.md]\n# abgeschnitten mitten im") === "Ich schreibe sie neu.\n", 'a cut-off block is removed to the end');
pft_test(ember_strip_stray_tool_markers('Ich lese [DATEI: a.md] gleich.') === 'Ich lese  gleich.', 'a read marker is removed');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Datei-Werkzeuge: Marker, Aenderung und Schleife OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- The tools against SQLite ------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER)');
$pdo->exec('CREATE TABLE stu_console_media (user_id INTEGER, kind TEXT, file_size INTEGER)');
$pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_project_files (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, path TEXT NOT NULL,
  content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, UNIQUE (project_id, path))');
$pdo->exec('CREATE TABLE stu_console_project_file_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id TEXT NOT NULL, project_id TEXT NOT NULL,
  user_id INTEGER NOT NULL, revision INTEGER NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL,
  source TEXT NOT NULL, session_id TEXT, message_id INTEGER, created_at TEXT, UNIQUE (file_id, revision))');
$pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
$a = str_repeat('a', 32); $c = str_repeat('c', 32);
$pdo->prepare('INSERT INTO stu_console_projects VALUES (?,?,?,?,?,?,?)')->execute([$a, 7, 'Spiel', 'open', '', null, '2026-11-16']);
$pdo->prepare('INSERT INTO stu_console_projects VALUES (?,?,?,?,?,?,?)')->execute([$c, 9, 'Fremd', 'open', '', null, '2026-11-16']);
$st = $pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)');
$st->execute(['cs_project_chat', 7, $a, 'Bau', null, '2026-11-16', '2026-11-16']);
$st->execute(['cs_free_chat', 7, null, 'Frei', null, '2026-11-16', '2026-11-16']);
$st->execute(['cs_other_account', 9, $c, 'Fremd', null, '2026-11-16', '2026-11-16']);
coreui_pfiles_write($pdo, 7, $a, 'index.html', "<h1>Hallo</h1>\n<p>Start</p>\n", 'build');
coreui_pfiles_write($pdo, 7, $a, 'css/style.css', "h1 { color: red; }\n", 'build');
coreui_pfiles_write($pdo, 9, $c, 'secret.md', "fremd\n", 'create');

pft_test(coreui_pftools_project($pdo, 7, 'cs_project_chat') === $a && coreui_pftools_project($pdo, 7, 'cs_free_chat') === ''
  && coreui_pftools_project($pdo, 7, 'cs_other_account') === '', 'only the own project chat has file tools');
pft_test(coreui_pftools_run($pdo, 7, 'cs_free_chat', ['tool'=>'pfile_read', 'input'=>'index.html']) === 'Projektdateien gibt es nur in einem Chat, der zu einem Projekt gehoert.',
  'a chat outside a project gets a sentence, no file');
pft_test(str_starts_with(coreui_pftools_run($pdo, 7, 'cs_other_account', ['tool'=>'pfile_read', 'input'=>'secret.md']), 'Projektdateien gibt es nur'),
  'another account\'s chat stays closed');
$read = coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_read', 'input'=>'index.html']);
pft_test(str_contains($read, "Datei index.html (Stand 1, 28 Bytes):\n1 | <h1>Hallo</h1>\n2 | <p>Start</p>") , 'read with line numbers: ' . $read);
pft_test(str_contains(coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_read', 'input'=>'nope.md']), 'diese Datei gibt es im Projekt nicht')
  && str_contains(coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_read', 'input'=>'../secret.md']), 'der Pfad ist ungueltig')
  && str_contains(coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_read', 'input'=>'secret.md']), 'gibt es im Projekt nicht'),
  'missing, invalid and other projects\' files are sentences for Mara');
$changed = coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_edit',
  'input'=>"css/style.css\n<<<<<<< SUCHEN\nh1 { color: red; }\n=======\nh1 { color: blue; }\n>>>>>>> ERSETZEN"]);
pft_test(str_starts_with($changed, 'Datei css/style.css geaendert (jetzt Stand 2): 1 Zeilen ersetzt durch 1.')
  && coreui_pfiles_get($pdo, 7, $a, 'css/style.css')['content'] === "h1 { color: blue; }\n", 'a change lands as a new state: ' . $changed);
$rev = $pdo->query("SELECT source, session_id, message_id FROM stu_console_project_file_revisions WHERE path='css/style.css' AND revision=2")->fetch(PDO::FETCH_ASSOC);
pft_test($rev['source'] === 'mara' && $rev['session_id'] === 'cs_project_chat' && $rev['message_id'] === null, 'the revision says Mara and the chat');
$failed = coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_edit', 'input'=>"css/style.css\n<<<<<<< SUCHEN\nh2 {}\n=======\nh2 { x }\n>>>>>>> ERSETZEN"]);
pft_test(str_starts_with($failed, 'Die Datei css/style.css wurde NICHT geaendert: Block 1') && coreui_pfiles_get($pdo, 7, $a, 'css/style.css')['revision'] === 2,
  'a change that does not match changes nothing');
$new = coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_write', 'input'=>"js/main.js\n```js\nconsole.log('los');\n```"]);
pft_test($new === 'Datei js/main.js angelegt (Stand 1, 20 Bytes).' && coreui_pfiles_get($pdo, 7, $a, 'js/main.js')['content'] === "console.log('los');\n"
  && coreui_pfiles_get($pdo, 7, $a, 'js/main.js')['revisions'][0]['source'] === 'mara', 'a new file: ' . $new);
$whole = coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_write', 'input'=>"js/main.js\nconsole.log('neu');"]);
pft_test(str_starts_with($whole, 'Datei js/main.js ganz ersetzt (Stand 2,'), 'a file replaced as a whole');
pft_test(str_contains(coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'pfile_write', 'input'=>"bild.png\nxx"]), 'nur Textdateien'),
  'binary types are refused with a sentence');
pft_test(coreui_pfiles_get($pdo, 9, $c, 'secret.md')['revision'] === 1, 'the other account\'s file is untouched');
$titles = array_column($GLOBALS['pft_events'], 2);
pft_test(in_array('Projektdatei gelesen', $titles, true) && in_array('Projektdatei geaendert', $titles, true)
  && in_array('Projektdatei angelegt', $titles, true) && in_array('Projektdatei nicht geaendert', $titles, true), 'the work view names every step');
foreach ($titles as $t) pft_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $t) === 1, 'work event title fits the work store: ' . $t);

// --- Prompt hint ---------------------------------------------------------------------------------
$hint = coreui_pftools_hint($pdo, 7, 'cs_project_chat', false);
pft_test(str_contains($hint, 'Dateien des Projekts: css/style.css (1 KB), index.html (1 KB), js/main.js (1 KB).')
  && str_contains($hint, "[DATEI_AENDERN: pfad]\n<<<<<<< SUCHEN") && str_contains($hint, 'wiederherstellbar'), 'hint with the files and the format: ' . $hint);
pft_test(strlen(coreui_pftools_hint($pdo, 7, 'cs_project_chat', true)) < 600 && coreui_pftools_hint($pdo, 7, 'cs_free_chat', false) === '',
  'a short hint for small Cores, none outside a project');

// --- A whole turn: read, change, answer (scripted Core) -----------------------------------------
$script = [
  "[DATEI_AENDERN: index.html]\n<<<<<<< SUCHEN\n<p>Start</p>\n=======\n<p>Start</p>\n<button>Spielen</button>\n>>>>>>> ERSETZEN\n[/DATEI_AENDERN]",
  'Ich habe in index.html einen Knopf "Spielen" unter den Starttext gesetzt.',
];
$prompts = [];
$records = [];
$loop = coreui_agent_loop_run('[DATEI: index.html]', ['tool'=>'pfile_read', 'input'=>'index.html'], 'BASIS', $files, 0,
  static function (string $prompt) use (&$script, &$prompts): ?string { $prompts[] = $prompt; return array_shift($script); },
  static fn(array $tool): string => coreui_pftools_run($pdo, 7, 'cs_project_chat', $tool),
  static function (string $kind, string $state, string $title, array $meta) use (&$records): void { $records[] = [$kind, $state, $title, $meta]; },
  static fn(): bool => false);
pft_test($loop['out'] === 'Ich habe in index.html einen Knopf "Spielen" unter den Starttext gesetzt.' && $loop['steps'] === 2, 'the turn ends with Mara\'s answer');
pft_test(coreui_pfiles_get($pdo, 7, $a, 'index.html')['content'] === "<h1>Hallo</h1>\n<p>Start</p>\n<button>Spielen</button>\n", 'the file was changed in the turn');
pft_test(str_contains($prompts[0], '1 | <h1>Hallo</h1>') && str_contains($prompts[1], 'Datei geändert: „index.html“')
  && str_contains($prompts[1], 'geaendert (jetzt Stand 2)'), 'Mara sees the file, then the result of her change');
$notes = array_values(array_filter(array_map(static fn(array $r): string => (string)($r[3]['public_note'] ?? ''), $records)));
pft_test($notes === ['Ich lese die Datei index.html', 'Ich ändere die Datei index.html'], 'the work view says what Mara does: ' . json_encode($notes));

// --- Wiring --------------------------------------------------------------------------------------
pft_test(str_contains($chatSource, "require_once __DIR__ . '/project_file_tools.php';")
  && str_contains($chatSource, "array_push(\$tools, 'pfile_read', 'pfile_edit', 'pfile_write');")
  && str_contains($chatSource, "['web', 'archive', 'skill', 'image', 'pfile_read', 'pfile_edit', 'pfile_write']")
  && str_contains($chatSource, 'return $channel === \'console\' ? coreui_pftools_run($pdo, $uid, $sessionId, $tool)')
  && substr_count($chatSource, 'coreui_pftools_hint($pdo,$uid,(string)$consoleSessionId,') === 2, 'chat.php: tools allowed, first step, dispatch, hint in both prompts');
$migration = (string)file_get_contents($root . '/database/migrations/045_project_files_mara.sql');
pft_test(str_contains($migration, "ENUM('build','create','edit','rename','restore','mara')")
  && str_contains($migration, "VALUES ('045_project_files_mara')"), 'migration 045 adds the source mara');
pft_test(str_contains((string)file_get_contents($root . '/js/coreui-project-files.js'), "mara: 'Von Mara geändert'"), 'the history names Mara\'s changes');

// --- MariaDB (optional, never the live database) -------------------------------------------------
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('pft-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  try {
    $project = coreui_project_create($db, $uid, 'Selbsttest Datei-Werkzeuge', 'open');
    $sid = 'cs_pft_' . bin2hex(random_bytes(6));
    $db->prepare("INSERT INTO stu_console_sessions (id, user_id, project_id, title, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")
      ->execute([$sid, $uid, $project['id'], 'Datei-Werkzeuge']);
    coreui_pfiles_write($db, $uid, $project['id'], 'index.html', "<p>alt</p>\n", 'build');
    $res = coreui_pftools_run($db, $uid, $sid, ['tool'=>'pfile_edit', 'input'=>"index.html\n<<<<<<< SUCHEN\n<p>alt</p>\n=======\n<p>neu 🙂</p>\n>>>>>>> ERSETZEN"]);
    $src = $db->prepare('SELECT source FROM stu_console_project_file_revisions WHERE project_id=? AND revision=2');
    $src->execute([$project['id']]);
    pft_test(str_starts_with($res, 'Datei index.html geaendert (jetzt Stand 2)') && $src->fetchColumn() === 'mara'
      && coreui_pfiles_get($db, $uid, $project['id'], 'index.html')['content'] === "<p>neu 🙂</p>\n", 'MariaDB: a change by Mara with migration 045: ' . $res);
  } finally {
    $db->prepare('DELETE FROM stu_console_sessions WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_console_projects WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
  }
  echo "CoreAI Datei-Werkzeuge: MariaDB OK\n";
}

echo "CoreAI Datei-Werkzeuge: Marker, SUCHEN/ERSETZEN, ganz oder gar nicht, Zeilennummern, Werkzeuge im Projekt-Chat, fremde Konten, Verlauf, Hinweis, ganzer Durchlauf und Bereinigung OK\n";
