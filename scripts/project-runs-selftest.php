<?php
declare(strict_types=1);
// CoreAI 0.7.77 (P10 B): commands on project files in the sandbox.
//   php scripts/project-runs-selftest.php
// Offline: the command check, the suggested command, the sentences, Mara's marker
// [AUSFUEHREN: befehl] and what she reads after a run, the store against SQLite (queue, one
// run per project, hourly limit, the last 20 stay, cancel, runs whose worker is gone, other
// accounts, a missing migration) and the wiring (endpoint, worker, work loop, chat).
// With COREUI_SELFTEST_DB=1 against MariaDB (migration 047, runs go with their project).
// With COREUI_SELFTEST_DB=1 and COREUI_SELFTEST_SANDBOX=1 also end to end with the real worker
// (tools/ember_py_worker.py) and Docker: a failing test, Mara changes the file, the test runs
// again and passes; a cancelled run stops. Test data uses example.test only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/console_agent_loop.php';
require_once $root . '/api/project_file_tools.php';
function prt_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project runs FAIL: {$why}\n"); exit(1); }
}
function prt_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ----------------------------------------------------------------------------------------
prt_test(coreui_pruns_command('  python -m unittest -v  ') === 'python -m unittest -v', 'command trimmed');
prt_test(coreui_pruns_command("echo a\tb") === 'echo a b', 'a tab becomes a space');
prt_test(coreui_pruns_command('python -c "print([1, 2])" | grep 1 && echo ok') === 'python -c "print([1, 2])" | grep 1 && echo ok', 'shell syntax stays');
foreach (['', '   ', "echo a\necho b", "echo \x07", str_repeat('x', 401), "\xC3\x28"] as $bad) {
  prt_test(prt_throws(static fn() => coreui_pruns_command($bad), 'invalid_command'), 'refused command: ' . json_encode($bad, JSON_INVALID_UTF8_SUBSTITUTE));
}
prt_test(coreui_pruns_suggest(['game.py', 'tests/test_game.py']) === 'python -m unittest discover -v -s tests', 'suggest tests in tests/');
prt_test(coreui_pruns_suggest(['game.py', 'test_game.py']) === 'python -m unittest -v', 'suggest tests at the top');
prt_test(coreui_pruns_suggest(['main.py', 'util.py']) === 'python main.py' && coreui_pruns_suggest(['b.py', 'a.py']) === 'python a.py'
  && coreui_pruns_suggest(['index.html', 'game.js']) === '' && coreui_pruns_suggest(['src/x.py']) === '', 'suggest a program, else nothing');
// 0.7.79: JavaScript projects (Node.js 24 in the sandbox).
$pkg = '{"scripts": {"test": "node --test", "start": "node server.js"}, "devDependencies": {"jest": "^29"}}';
prt_test(coreui_pruns_suggest(['package.json', 'add.js'], $pkg) === 'npm install && npm test'
  && coreui_pruns_suggest(['package.json'], '{"scripts": {"test": "node --test"}}') === 'npm test'
  && coreui_pruns_suggest(['package.json', 'test/add.test.js'], '{"scripts": {"test": "echo \\"Error: no test specified\\" && exit 1"}}') === 'node --test'
  && coreui_pruns_suggest(['add.js', 'add.test.mjs']) === 'node --test' && coreui_pruns_suggest(['index.js']) === 'node index.js'
  && coreui_pruns_suggest(['package.json', 'index.js'], 'kaputt') === 'node index.js'
  && coreui_pruns_suggest(['tests/test_a.py', 'package.json'], $pkg) === 'python -m unittest discover -v -s tests'
  && coreui_pruns_suggest(['add.js'], $pkg) === '', 'suggest npm test, node --test or the Node.js program');
prt_test(coreui_pruns_package('{"scripts": {"dev": "vite", "x": 3}, "dependencies": {"vite": "^5"}}') === ['scripts'=>['dev'=>'vite'], 'deps'=>['vite']]
  && coreui_pruns_package(null) === ['scripts'=>[], 'deps'=>[]] && coreui_pruns_package('[1]') === ['scripts'=>[], 'deps'=>[]], 'package.json read');
$done = ['id'=>1, 'command'=>'python t.py', 'status'=>'done', 'exit_code'=>1, 'error'=>null, 'duration_ms'=>1234, 'stdout'=>"a\n", 'stderr'=>"Traceback\n"];
prt_test(coreui_pruns_summary($done) === 'Beendet mit Exitcode 1 in 1,2 s.', 'summary of a run: ' . coreui_pruns_summary($done));
prt_test(coreui_pruns_summary(['status'=>'error', 'error'=>'timeout', 'duration_ms'=>null]) === 'Nach 120 s abgebrochen (Zeitlimit).'
  && str_contains(coreui_pruns_summary(['status'=>'error', 'error'=>'no_worker', 'duration_ms'=>null]), 'Python-Dienst')
  && coreui_pruns_summary(['status'=>'queued', 'duration_ms'=>null]) === 'Wartet auf den Sandbox-Dienst.', 'summaries of errors and waiting');

prt_test(coreui_pftools_extract_run("Ich teste das.\n[AUSFUEHREN: python -m unittest -v]\n") === 'python -m unittest -v', 'marker');
prt_test(coreui_pftools_extract_run('[AUSFÜHREN: python -c "print([1])"]') === 'python -c "print([1])"', 'umlaut and brackets inside');
prt_test(coreui_pftools_extract_run('[AUSFUEHREN: python a.py] und dann [DATEI: a.py]') === 'python a.py'
  && coreui_pftools_extract_run('Ich starte [AUSFUEHREN: python a.py] und warte.') === 'python a.py', 'in the middle of a line: up to the first ]');
prt_test(coreui_pftools_extract_run('[AUSFUEHREN: ]') === null && coreui_pftools_extract_run('Ausführen ist gut.') === null, 'no marker');
$text = coreui_pftools_run_result($done);
prt_test($text === "Befehl: python t.py\nBeendet mit Exitcode 1 in 1,2 s.\n--- Ausgabe (stdout) ---\na\n--- Fehlerausgabe (stderr) ---\nTraceback\n"
  . "(Lauf im Sandbox-Container mit den gespeicherten Projektdateien; was der Befehl an Dateien aendert, bleibt nicht erhalten.)", 'what Mara reads: ' . $text);
$long = coreui_pftools_run_result(['command'=>'x', 'status'=>'done', 'exit_code'=>0, 'error'=>null, 'duration_ms'=>5,
  'stdout'=>'A' . str_repeat('m', 20000) . 'Z', 'stderr'=>'']);
prt_test(mb_strlen($long) < 7000 && str_contains($long, '[... gekuerzt ...]') && str_contains($long, 'Z') && str_contains($long, 'A'), 'long output: start and end');
prt_test(str_contains(coreui_pftools_run_result(['command'=>'x', 'status'=>'running', 'exit_code'=>null, 'error'=>null, 'duration_ms'=>null]), 'noch nicht fertig'),
  'a run that is not ready yet');
prt_test(coreui_agent_next_tool("[AUSFUEHREN: python -m unittest -v]\n", ['prun']) === ['tool'=>'prun', 'input'=>'python -m unittest -v']
  && coreui_agent_next_tool("[AUSFUEHREN: python -m unittest -v]\n", ['pfile_read']) === null, 'the work loop takes the marker only where allowed');
prt_test(coreui_agent_step_title(['tool'=>'prun', 'input'=>'x'], 2, 6) === 'Schritt 2 von 6: Projekt ausfuehren'
  && coreui_agent_step_note(['tool'=>'prun', 'input'=>'python -m unittest -v']) === 'Ich führe aus: python -m unittest -v'
  && coreui_agent_step_note(['tool'=>'prun', 'input'=>'echo $HOME']) === null
  && coreui_agent_step_label(['tool'=>'prun', 'input'=>'python a.py'], 1, 6) === 'Ausgeführt: „python a.py“'
  && str_contains(coreui_agent_tool_menu(['prun']), '[AUSFUEHREN: befehl]') && !str_contains(coreui_agent_tool_menu(['pfile_read']), 'AUSFUEHREN'),
  'work view and tool menu');

// --- Wiring (static) -------------------------------------------------------------------------------
$chat = (string)file_get_contents($root . '/api/chat.php');
prt_test(str_contains($chat, "if (ember_py_enabled() && coreui_pruns_ready(stu_pdo())) \$tools[] = 'prun';")
  && str_contains($chat, "in_array(\$tool['tool'], ['pfile_read', 'pfile_edit', 'pfile_write', 'prun', 'pvcs_commit', 'pvcs_branch', 'pvcs_merge'], true)")
  && substr_count($chat, 'ember_py_enabled() && coreui_pruns_ready($pdo)) : \'\';') === 2
  && str_contains($chat, "preg_replace('~\\[\\s*AUSF(?:UE|Ü)HREN\\s*:[^\\r\\n]*\\][ \\t]*~u', '', \$out)"), 'chat.php: only with Python, dispatch, hint, stray marker removed');
$endpoint = (string)file_get_contents($root . '/api/project_files.php');
prt_test(str_contains($endpoint, "case 'run':\n      if (!coreui_pruns_allowed(\$pdo, \$uid)) stu_json(['ok'=>false, 'error'=>'python_disabled'], 403);")
  && str_contains($endpoint, "case 'run_cancel':") && str_contains($endpoint, "if (isset(\$_GET['runs'])) {")
  && strpos($endpoint, "case 'run':") > strpos($endpoint, 'stu_require_csrf();') && str_contains($endpoint, "in_array(\$error, ['run_limit', 'proc_limit', 'proc_active_limit'], true) => 429,"),
  'endpoint: runs behind CSRF and the Python switch');
$worker = (string)file_get_contents($root . '/tools/ember_py_worker.py');
prt_test(str_contains($worker, 'ember_project_runs.process_run(conn, cfg, run, work_base(cfg), stop_container)'), 'the worker takes runs');
$migration = (string)file_get_contents($root . '/database/migrations/047_project_runs.sql');
prt_test(str_contains($migration, 'REFERENCES stu_console_projects(id) ON DELETE CASCADE') && str_contains($migration, "VALUES ('047_project_runs')"),
  'migration 047: runs go with their project');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Projektlaeufe: Befehle, Vorschlag, Saetze, Marker, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite ----------------------------------------------------------------------------
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
$pdo->exec('CREATE TABLE stu_console_project_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, project_id TEXT NOT NULL, session_id TEXT,
  command TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'queued\', exit_code INTEGER, stdout TEXT, stderr TEXT, error TEXT, duration_ms INTEGER,
  files_count INTEGER, files_sha256 TEXT, worker_pid INTEGER, cancel_requested_at TEXT, created_at TEXT, started_at TEXT, finished_at TEXT)');
$pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
$a = str_repeat('a', 32); $e = str_repeat('e', 32); $c = str_repeat('c', 32);
$ins = $pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)');
$ins->execute([$a, 7, 'Spiel', 'open', '', null, '2026-11-17']);
$ins->execute([$e, 7, 'Leer', 'open', '', null, '2026-11-17']);
$ins->execute([$c, 9, 'Fremd', 'open', '', null, '2026-11-17']);
coreui_pfiles_write($pdo, 7, $a, 'game.py', "def add(a, b):\n    return a + b\n", 'build');
coreui_pfiles_write($pdo, 9, $c, 'x.py', "print(1)\n", 'build');

prt_test(prt_throws(static fn() => coreui_pruns_enqueue($pdo, 7, $e, 'python a.py'), 'no_files'), 'a project without files');
prt_test(prt_throws(static fn() => coreui_pruns_enqueue($pdo, 7, $c, 'python x.py'), 'project_not_found'), 'another account\'s project');
prt_test(prt_throws(static fn() => coreui_pruns_enqueue($pdo, 7, $a, "a\nb"), 'invalid_command'), 'a bad command is not queued');
$run = coreui_pruns_enqueue($pdo, 7, $a, 'python -m unittest -v', 'cs_chat_1');
prt_test($run['status'] === 'queued' && $run['command'] === 'python -m unittest -v' && $run['from_chat'] && $run['exit_code'] === null
  && $run['stdout'] === '' && !$run['cancel_requested'], 'queued: ' . json_encode($run));
prt_test(prt_throws(static fn() => coreui_pruns_enqueue($pdo, 7, $a, 'python game.py'), 'run_busy'), 'one run at a time per project');
prt_test(prt_throws(static fn() => coreui_pruns_get($pdo, 9, $a, $run['id']), 'project_not_found')
  && prt_throws(static fn() => coreui_pruns_get($pdo, 7, $e, $run['id']), 'run_not_found'), 'nobody else reads the run');

// The worker finishes it.
$pdo->prepare("UPDATE stu_console_project_runs SET status='done', exit_code=0, stdout='ok', stderr='Ran 1 test', duration_ms=800, files_count=1,
  finished_at=NOW() WHERE id=?")->execute([$run['id']]);
$got = coreui_pruns_get($pdo, 7, $a, $run['id']);
prt_test($got['status'] === 'done' && $got['exit_code'] === 0 && $got['stderr'] === 'Ran 1 test' && $got['duration_ms'] === 800, 'a finished run');
$list = coreui_pruns_list($pdo, 7, $a);
prt_test(count($list) === 1 && !array_key_exists('stdout', $list[0]) && $list[0]['id'] === $run['id'], 'the list without outputs');

// Cancel: queued at once; running by the worker.
$q = coreui_pruns_enqueue($pdo, 7, $a, 'sleep 30');
$q = coreui_pruns_cancel($pdo, 7, $a, $q['id']);
prt_test($q['status'] === 'cancelled' && $q['error'] === 'cancelled' && $q['cancel_requested'], 'a queued run ends at once');
$r = coreui_pruns_enqueue($pdo, 7, $a, 'sleep 30');
$pdo->prepare("UPDATE stu_console_project_runs SET status='running', started_at=NOW(), worker_pid=1 WHERE id=?")->execute([$r['id']]);
$r = coreui_pruns_cancel($pdo, 7, $a, $r['id']);
prt_test($r['status'] === 'running' && $r['cancel_requested'], 'a running run gets the request for the worker');

// A worker that is gone: the run ends as an error, a new one may start.
$pdo->prepare("UPDATE stu_console_project_runs SET started_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s', time() - 400), $r['id']]);
prt_test(coreui_pruns_get($pdo, 7, $a, $r['id'])['error'] === 'worker_lost', 'a running run without its worker ends');
$w = coreui_pruns_enqueue($pdo, 7, $a, 'python game.py');
$pdo->prepare("UPDATE stu_console_project_runs SET created_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s', time() - 700), $w['id']]);
$w = coreui_pruns_get($pdo, 7, $a, $w['id']);
prt_test($w['status'] === 'error' && $w['error'] === 'no_worker', 'a run no worker takes ends');

// The last 20 runs stay; the hourly limit counts the account.
for ($i = 0; $i < 25; $i++) {
  $x = coreui_pruns_enqueue($pdo, 7, $a, 'echo ' . $i);
  $pdo->prepare("UPDATE stu_console_project_runs SET status='done', exit_code=0 WHERE id=?")->execute([$x['id']]);
}
prt_test((int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_runs WHERE project_id='{$a}'")->fetchColumn() === COREUI_PRUNS_KEEP
  && count(coreui_pruns_list($pdo, 7, $a, 50)) === COREUI_PRUNS_KEEP && coreui_pruns_list($pdo, 7, $a, 1)[0]['command'] === 'echo 24',
  'the last 20 runs stay, the newest first');
$pdo->exec('DELETE FROM stu_console_project_runs');
$fill = $pdo->prepare("INSERT INTO stu_console_project_runs (user_id, project_id, command, status, exit_code, created_at) VALUES (7, ?, 'echo', 'done', 0, ?)");
for ($i = 0; $i < COREUI_PRUNS_PER_HOUR; $i++) $fill->execute([$a, gmdate('Y-m-d H:i:s', time() - 60)]);
prt_test(prt_throws(static fn() => coreui_pruns_enqueue($pdo, 7, $a, 'echo x'), 'run_limit'), 'the hourly limit');
$pdo->exec("UPDATE stu_console_project_runs SET created_at='" . gmdate('Y-m-d H:i:s', time() - 4000) . "'");
prt_test(coreui_pruns_enqueue($pdo, 7, $a, 'echo x')['status'] === 'queued', 'after an hour runs start again');

// Mara's tool outside a project chat, and the hint.
$pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)')->execute(['cs_free_chat', 7, null, 'Frei', null, '2026-11-17', '2026-11-17']);
prt_test(coreui_pftools_run($pdo, 7, 'cs_free_chat', ['tool'=>'prun', 'input'=>'echo hi']) === 'Projektdateien gibt es nur in einem Chat, der zu einem Projekt gehoert.',
  'no runs outside a project chat');
$pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)')->execute(['cs_project_chat', 7, $a, 'Bau', null, '2026-11-17', '2026-11-17']);
prt_test(str_contains(coreui_pftools_hint($pdo, 7, 'cs_project_chat', false, true), '[AUSFUEHREN: python -m unittest -v]')
  && !str_contains(coreui_pftools_hint($pdo, 7, 'cs_project_chat', false, false), 'AUSFUEHREN')
  && str_contains(coreui_pftools_hint($pdo, 7, 'cs_project_chat', true, true), '[AUSFUEHREN: befehl]'), 'the hint names running only when allowed');
prt_test(coreui_pftools_run($pdo, 7, 'cs_project_chat', ['tool'=>'prun', 'input'=>'echo hi']) === 'Das ging nicht: in diesem Projekt laeuft schon ein Befehl.',
  'Mara gets a sentence when a run is busy');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
prt_test(!coreui_pruns_ready($old) && prt_throws(static fn() => coreui_pruns_enqueue($old, 7, $a, 'echo'), 'project_runs_migration_required'),
  'without migration 047 nothing runs');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql', '047_project_runs.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('prt-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  $worker = null;
  $tmp = sys_get_temp_dir() . '/coreai-runs-' . bin2hex(random_bytes(4));
  try {
    $project = coreui_project_create($db, $uid, 'Selbsttest Laeufe', 'open');
    $pid = $project['id'];
    $sid = 'cs_prt_' . bin2hex(random_bytes(6));
    $db->prepare("INSERT INTO stu_console_sessions (id, user_id, project_id, title, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")
      ->execute([$sid, $uid, $pid, 'Laeufe']);
    coreui_pfiles_write($db, $uid, $pid, 'game.py', "def add(a, b):\n    return a - b\n", 'build');
    coreui_pfiles_write($db, $uid, $pid, 'tests/test_game.py', "import unittest\nfrom game import add\n\n\nclass T(unittest.TestCase):\n"
      . "    def test_add(self):\n        self.assertEqual(add(2, 3), 5)\n", 'build');
    $q = coreui_pruns_enqueue($db, $uid, $pid, 'python -m unittest discover -v -s tests', $sid);
    prt_test($q['status'] === 'queued' && $q['from_chat'], 'MariaDB: queued');
    $db->prepare("UPDATE stu_console_project_runs SET status='cancelled' WHERE id=?")->execute([$q['id']]);
    $mariadb = ', MariaDB (Migration 047)';

    if (getenv('COREUI_SELFTEST_SANDBOX') === '1') {
      // The real worker with its own config, against this database and Docker.
      mkdir($tmp . '/work', 0777, true);
      chmod($tmp . '/work', 0777);
      $config = "<?php\n";
      foreach (['STU_DB_HOST'=>getenv('COREUI_DB_HOST') ?: '127.0.0.1', 'STU_DB_PORT'=>getenv('COREUI_DB_PORT') ?: '3306',
        'STU_DB_NAME'=>(string)getenv('COREUI_DB_NAME'), 'STU_DB_USER'=>(string)getenv('COREUI_DB_USER'), 'STU_DB_PASS'=>(string)getenv('COREUI_DB_PASS'),
        'STU_EMBER_PY_WORKDIR'=>$tmp . '/work', 'STU_EMBER_PY_IMAGE'=>'ember-py:2'] as $k => $v) {
        $config .= "define('{$k}', " . var_export((string)$v, true) . ");\n";
      }
      file_put_contents($tmp . '/config.local.php', $config);
      $worker = proc_open(['python3', $root . '/tools/ember_py_worker.py', '--config', $tmp . '/config.local.php', '--poll', '0.3'],
        [0=>['file', '/dev/null', 'r'], 1=>['file', $tmp . '/worker.log', 'w'], 2=>['file', $tmp . '/worker.log', 'a']], $pipes);

      // The acceptance of P10: a failing test, a fix, the test again.
      $first = coreui_pftools_run($db, $uid, $sid, ['tool'=>'prun', 'input'=>'python -m unittest discover -v -s tests']);
      prt_test(str_contains($first, 'Beendet mit Exitcode 1') && str_contains($first, 'FAILED (failures=1)') && str_contains($first, 'AssertionError: -1 != 5'),
        'sandbox: the failing test with its real output: ' . $first . "\n" . @file_get_contents($tmp . '/worker.log'));
      $fix = coreui_pftools_run($db, $uid, $sid, ['tool'=>'pfile_edit',
        'input'=>"game.py\n<<<<<<< SUCHEN\n    return a - b\n=======\n    return a + b\n>>>>>>> ERSETZEN"]);
      prt_test(str_starts_with($fix, 'Datei game.py geaendert'), 'Mara fixes the file: ' . $fix);
      $second = coreui_pftools_run($db, $uid, $sid, ['tool'=>'prun', 'input'=>'python -m unittest discover -v -s tests']);
      prt_test(str_contains($second, 'Beendet mit Exitcode 0') && str_contains($second, "\nOK") && str_contains($second, 'test_add'),
        'sandbox: the test passes after the fix: ' . $second);
      $rows = $db->prepare("SELECT status, exit_code, files_sha256, session_id FROM stu_console_project_runs WHERE project_id=? AND command LIKE 'python -m unittest%' AND status='done' ORDER BY id");
      $rows->execute([$pid]);
      $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
      prt_test(count($rows) === 2 && (int)$rows[0]['exit_code'] === 1 && (int)$rows[1]['exit_code'] === 0
        && $rows[0]['files_sha256'] !== $rows[1]['files_sha256'] && $rows[1]['session_id'] === $sid, 'sandbox: two runs on two states of the files');

      // A run that is cancelled while it runs.
      $long = coreui_pruns_enqueue($db, $uid, $pid, 'echo los; sleep 60');
      $deadline = microtime(true) + 30;
      do { usleep(300000); $state = coreui_pruns_get($db, $uid, $pid, $long['id']); } while ($state['status'] === 'queued' && microtime(true) < $deadline);
      prt_test($state['status'] === 'running', 'sandbox: the worker took the long run');
      coreui_pruns_cancel($db, $uid, $pid, $long['id']);
      $end = coreui_pruns_wait($db, $uid, $pid, $long['id'], 30);
      prt_test($end['status'] === 'cancelled' && (int)$end['duration_ms'] < 20000, 'sandbox: a cancelled run stops: ' . json_encode($end));
      $mariadb .= ', Sandbox (Test rot, Korrektur durch Mara, Test gruen, Abbruch)';
    }

    coreui_project_delete($db, $uid, $pid);
    $left = $db->prepare('SELECT COUNT(*) FROM stu_console_project_runs WHERE project_id=?');
    $left->execute([$pid]);
    prt_test((int)$left->fetchColumn() === 0, 'MariaDB: the runs go with their project');
  } finally {
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    $db->prepare('DELETE FROM stu_console_sessions WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
    if (is_dir($tmp)) exec('rm -rf ' . escapeshellarg($tmp));
  }
}

echo "CoreAI Projektlaeufe: Befehle, Vorschlag, Saetze, Marker, Warteschlange, ein Lauf je Projekt, Stundengrenze, Abbruch, verlorene Laeufe, fremde Konten, Verdrahtung OK (SQLite{$mariadb})\n";
