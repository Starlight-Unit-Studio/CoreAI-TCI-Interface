<?php
declare(strict_types=1);
// CoreAI 0.7.91: Mara knows the environment of a project (P10) and runs its processes herself.
//   php scripts/project-env-selftest.php
// Offline: the markers [PROZESS_STARTEN:], [SERVER_STARTEN: port ...], [PROZESS_AUSGABE],
// [PROZESS_EINGABE:], [PROZESS_BEENDEN] and [SERVER_ABRUF: /pfad], the port in a command, local
// addresses in a path, what Mara reads, the work loop (titles, notes without input lines, labels,
// menu), the safety net that keeps the markers out of the chat, the hint in a project chat (sandbox,
// preview, processes, the process running now, nothing promised without the Python tool) and
// outside one, the wiring in chat.php, and the tools against SQLite with a stand-in worker (start,
// a server's port, a request, binary and unreachable answers, input, output, stop, a process that
// ends at once, one that waits, other accounts, a missing migration) and a whole turn of Mara.
// With COREUI_SELFTEST_DB=1 against MariaDB (migrations 044, 045, 047, 048), and with
// COREUI_SELFTEST_SANDBOX=1 as well end to end with the real worker and Docker: Mara starts a
// server, reads its JSON, changes a page and sees the change, stops it with the real exit code, and
// answers a program that asks.
// Test data uses example.test only.
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
$GLOBALS['env_events'] = [];
$GLOBALS['env_python'] = true;
function coreui_work_record(PDO $pdo, string $kind, string $state, string $title, array $meta = []): ?array {
  $GLOBALS['env_events'][] = [$kind, $state, $title];
  return null;
}
function ember_py_enabled(): bool { return $GLOBALS['env_python']; }
function env_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project environment FAIL: {$why}\n"); exit(1); }
}
function env_titles(): array { return array_column($GLOBALS['env_events'], 2); }

// --- Markers (pure) ----------------------------------------------------------------------------------
env_test(coreui_pftools_extract_proc_start("Ich starte das Spiel.\n[PROZESS_STARTEN: python spiel.py]\n") === 'python spiel.py'
  && coreui_pftools_extract_proc_start('[PROZESS_STARTEN: python -c "print([1])"]') === 'python -c "print([1])"', 'start marker, brackets inside');
env_test(coreui_pftools_extract_server_start('[SERVER_STARTEN: 8000 python -m http.server 8000]') === '8000 python -m http.server 8000', 'server marker');
env_test(coreui_pftools_extract_proc_output('Ich schaue nach. [PROZESS_AUSGABE]') === 'ausgabe'
  && coreui_pftools_extract_proc_output('[PROZESS_AUSGABE: neu]') === 'ausgabe' && coreui_pftools_extract_proc_output('Prozessausgabe') === null, 'output marker');
env_test(coreui_pftools_extract_proc_stop('[PROZESS_BEENDEN]') === 'beenden' && coreui_pftools_extract_proc_stop('[PROZESS_BEENDEN: jetzt]') === 'beenden'
  && coreui_pftools_extract_proc_stop('beenden') === null, 'stop marker');
env_test(coreui_pftools_extract_proc_input("[PROZESS_EINGABE: Mara]\n") === 'Mara' && coreui_pftools_extract_proc_input('[PROZESS_EINGABE: ]') === null
  && coreui_pftools_extract_proc_input('[PROZESS_EINGABE: print([1, 2])]') === 'print([1, 2])', 'input marker');
env_test(coreui_pftools_extract_server_fetch('[SERVER_ABRUF: /api/zahl?x=1]') === '/api/zahl?x=1' && coreui_pftools_extract_server_fetch('[SERVER_ABRUF: ]') === null,
  'request marker');

// The port in a command, a port in front, local addresses in a path.
foreach ([['python -m http.server 8000', 8000], ['python3 -m http.server --bind 0.0.0.0 8080', 8080], ['python -m http.server -d public 9000', 9000],
    ['python -m http.server', null], ['python -m http.server 80', null], ['npx vite --port 5173', 5173], ['npm run dev -- --port=3000', 3000],
    ['php -S 0.0.0.0:8000 -t . -p 8080', 8080], ['PORT=3000 node server.js', 3000], ['node server.js', null], ['python spiel.py 12345', null]] as [$command, $port]) {
  env_test(coreui_pftools_guess_port($command) === $port, 'port of ' . $command . ': ' . json_encode(coreui_pftools_guess_port($command)));
}
env_test(coreui_pftools_server_spec('8000 python -m http.server 8000') === ['port'=>8000, 'command'=>'python -m http.server 8000']
  && coreui_pftools_server_spec('port 3000 npm start') === ['port'=>3000, 'command'=>'npm start']
  && coreui_pftools_server_spec('3000: npm start') === ['port'=>3000, 'command'=>'npm start']
  && coreui_pftools_server_spec('npm start') === ['port'=>null, 'command'=>'npm start']
  && coreui_pftools_server_spec('python -m http.server 8001') === ['port'=>8001, 'command'=>'python -m http.server 8001'], 'server specs');
$refused = false;
try { coreui_pftools_server_spec('80 npm start'); } catch (InvalidArgumentException $e) { $refused = $e->getMessage() === 'invalid_port'; }
env_test($refused, 'a port below 1024 is refused');
env_test(coreui_pftools_server_path('/') === '/' && coreui_pftools_server_path(' /api/x?y=1 ') === '/api/x?y=1'
  && coreui_pftools_server_path('http://localhost:8000/api') === '/api' && coreui_pftools_server_path('localhost:3000') === '/'
  && coreui_pftools_server_path('127.0.0.1:8000/x') === '/x' && coreui_pftools_server_path('http://[::1]:8000/') === '/', 'server paths');
foreach (['api', '//evil.test/x', 'https://example.test/', 'http://localhost:8000/a b'] as $bad) {
  $refused = false;
  try { coreui_pftools_server_path($bad); } catch (InvalidArgumentException $e) { $refused = $e->getMessage() === 'invalid_server_path'; }
  env_test($refused, 'refused path: ' . $bad);
}

// What Mara reads (pure).
$run = ['id'=>1, 'command'=>'python app.py', 'port'=>8000, 'status'=>'running', 'exit_code'=>null, 'error'=>null, 'stop_requested'=>false];
$text = coreui_pftools_proc_text($run, "Server bereit\n");
env_test(str_starts_with($text, "Prozess: python app.py (Port 8000)\nLäuft.\n--- Ausgabe ---\nServer bereit\n(Er laeuft im Sandbox-Container weiter")
  && str_contains($text, '[SERVER_ABRUF: /pfad]') && str_contains($text, '5 Minuten') && str_contains($text, 'localhost:8000 erreicht sie nicht'),
  'a running server: ' . $text);
$text = coreui_pftools_proc_text(['port'=>null, 'command'=>'python spiel.py'] + $run, '');
env_test(str_contains($text, "(Noch keine Ausgabe.)") && !str_contains($text, 'SERVER_ABRUF'), 'a program without output: ' . $text);
$text = coreui_pftools_proc_text(['status'=>'queued'] + $run, '');
env_test(str_contains($text, 'Wartet auf den Sandbox-Dienst.') && str_contains($text, 'noch nicht uebernommen') && !str_contains($text, 'laeuft im Sandbox'),
  'a queued process does not claim to run: ' . $text);
$text = coreui_pftools_proc_text(['status'=>'exited', 'exit_code'=>1] + $run, "Traceback\nNameError\n");
env_test(str_contains($text, 'Beendet mit Exitcode 1.') && str_contains($text, 'NameError') && !str_contains($text, 'laeuft im Sandbox'), 'an ended process');
env_test(str_starts_with(coreui_pftools_proc_tail(['output'=>str_repeat('x', 5000) . 'ENDE']), '[... aeltere Ausgabe ausgelassen ...]')
  && str_ends_with(coreui_pftools_proc_tail(['output'=>str_repeat('x', 5000) . 'ENDE']), 'ENDE'), 'only the newest output');
$fetchText = coreui_pftools_fetch_text('/api/zahl', ['status'=>200, 'type'=>'application/json', 'path'=>'/api/zahl', 'body'=>'{"zahl": 42}', 'truncated'=>false]);
env_test($fetchText === "GET /api/zahl: HTTP 200 (application/json)\n--- Inhalt ---\n{\"zahl\": 42}", 'a JSON answer: ' . $fetchText);
env_test(str_contains(coreui_pftools_fetch_text('/', ['status'=>200, 'type'=>'image/png', 'path'=>'/', 'body'=>"\x89PNG\x00\x01", 'truncated'=>false]), '(Binaerdaten, 6 Bytes.)')
  && str_contains(coreui_pftools_fetch_text('/alt', ['status'=>200, 'type'=>'text/html', 'path'=>'/neu', 'body'=>'<p>x</p>', 'truncated'=>true]), ', nach Weiterleitung /neu')
  && str_contains(coreui_pftools_fetch_text('/alt', ['status'=>200, 'type'=>'text/html', 'path'=>'/neu', 'body'=>'<p>x</p>', 'truncated'=>true]), 'gekuerzt')
  && str_ends_with(coreui_pftools_fetch_text('/x', ['status'=>204, 'type'=>'', 'path'=>'/x', 'body'=>'', 'truncated'=>false]), '(Leere Antwort.)'),
  'binary, redirected, cut and empty answers');

// --- The work loop (pure) -----------------------------------------------------------------------------
$procKinds = COREUI_PFTOOLS_PROC_KINDS;
env_test(coreui_agent_next_tool("[SERVER_STARTEN: 8000 python app.py]\n", $procKinds) === ['tool'=>'pserver_start', 'input'=>'8000 python app.py']
  && coreui_agent_next_tool('[PROZESS_AUSGABE]', $procKinds) === ['tool'=>'pproc_output', 'input'=>'ausgabe']
  && coreui_agent_next_tool('[PROZESS_BEENDEN]', $procKinds) === ['tool'=>'pproc_stop', 'input'=>'beenden']
  && coreui_agent_next_tool('[SERVER_ABRUF: /]', $procKinds) === ['tool'=>'pserver_fetch', 'input'=>'/']
  && coreui_agent_next_tool("[PROZESS_EINGABE: 42]\n", $procKinds) === ['tool'=>'pproc_input', 'input'=>'42']
  && coreui_agent_next_tool("[PROZESS_STARTEN: python spiel.py]\n", ['prun', 'pfile_read']) === null, 'the loop takes the markers only where allowed');
env_test(coreui_agent_step_title(['tool'=>'pserver_start', 'input'=>'x'], 1, 6) === 'Schritt 1 von 6: Server starten'
  && coreui_agent_step_title(['tool'=>'pserver_fetch', 'input'=>'/'], 2, 6) === 'Schritt 2 von 6: Server abrufen'
  && coreui_agent_step_title(['tool'=>'pproc_stop', 'input'=>'beenden'], 3, 6) === 'Schritt 3 von 6: Prozess beenden', 'step titles');
env_test(coreui_agent_step_note(['tool'=>'pserver_start', 'input'=>'8000 python app.py']) === 'Ich starte den Server: 8000 python app.py'
  && coreui_agent_step_note(['tool'=>'pproc_start', 'input'=>'python spiel.py']) === 'Ich starte: python spiel.py'
  && coreui_agent_step_note(['tool'=>'pproc_input', 'input'=>'geheim123']) === 'Ich schicke eine Eingabe an den laufenden Prozess.'
  && coreui_agent_step_note(['tool'=>'pproc_output', 'input'=>'ausgabe']) === 'Ich lese die Ausgabe des laufenden Prozesses.'
  && coreui_agent_step_note(['tool'=>'pserver_fetch', 'input'=>'/api/zahl']) === 'Ich rufe beim Server ab: /api/zahl', 'work view notes, no input lines');
env_test(coreui_agent_step_label(['tool'=>'pserver_start', 'input'=>'8000 python app.py'], 1, 6) === 'Server gestartet: „8000 python app.py“'
  && coreui_agent_step_label(['tool'=>'pproc_output', 'input'=>'ausgabe'], 1, 6) === 'Prozessausgabe gelesen'
  && coreui_agent_step_label(['tool'=>'pserver_fetch', 'input'=>'/'], 1, 6) === 'Server abgerufen: „/“', 'labels in the next prompt');
$menu = coreui_agent_tool_menu($procKinds);
env_test(str_contains($menu, '[SERVER_STARTEN: port befehl]') && str_contains($menu, '[PROZESS_EINGABE: zeile]') && str_contains($menu, '[SERVER_ABRUF: /pfad]')
  && !str_contains(coreui_agent_tool_menu(['prun']), 'PROZESS'), 'tool menu');

// The safety net: markers that did not run never reach the chat.
env_test(ember_strip_stray_tool_markers("Ich starte ihn.\n[SERVER_STARTEN: 8000 python -m http.server 8000]\nGleich da.") === "Ich starte ihn.\n\nGleich da."
  && ember_strip_stray_tool_markers('Moment [PROZESS_AUSGABE] und [PROZESS_BEENDEN] fertig.') === 'Moment und fertig.'
  && ember_strip_stray_tool_markers("[PROZESS_EINGABE: print([1])]\nok") === "\nok"
  && ember_strip_stray_tool_markers('Ich frage [SERVER_ABRUF: /api] ab.') === 'Ich frage ab.', 'stray process markers are removed: '
  . json_encode(ember_strip_stray_tool_markers('Moment [PROZESS_AUSGABE] und [PROZESS_BEENDEN] fertig.')));

// --- Wiring (static) -----------------------------------------------------------------------------------
env_test(str_contains($chatSource, "|| in_array(\$tool['tool'], COREUI_PFTOOLS_PROC_KINDS, true)) {")
  && str_contains($chatSource, "if (ember_py_enabled() && coreui_pruns_ready(stu_pdo()) && coreui_pprocs_ready(stu_pdo())) array_push(\$tools, ...COREUI_PFTOOLS_PROC_KINDS);")
  && str_contains($chatSource, "'pvcs_commit', 'pvcs_branch', 'pvcs_merge'],\n        COREUI_PFTOOLS_PROC_KINDS))));")
  && substr_count($chatSource, "if ((\$projectScope['id'] ?? '') === '') \$projectFilesHint = coreui_pftools_outside_hint(\$pdo, \$channel, \$uid,") === 2,
  'chat.php: dispatch, offered with Python and migration 048, first step, the hint outside a project in both prompts');
$loopSource = (string)file_get_contents($root . '/api/console_agent_loop.php');
env_test(str_contains($loopSource, "'pproc_start','pserver_start','pproc_output','pproc_input','pproc_stop','pserver_fetch']) !== null"),
  'console_agent_loop.php: no process marker in an image closing text');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Projektumgebung: Marker, Ports, Texte, Schleife, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Against SQLite with a stand-in worker -------------------------------------------------------------
function env_schema(PDO $pdo, bool $procs = true): void {
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
  if (!$procs) return;
  $pdo->exec('CREATE TABLE stu_console_project_procs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, project_id TEXT NOT NULL,
    command TEXT NOT NULL, port INTEGER, status TEXT NOT NULL DEFAULT \'queued\', exit_code INTEGER, error TEXT, output TEXT, output_end INTEGER NOT NULL DEFAULT 0,
    files_count INTEGER, files_sha256 TEXT, synced_at TEXT, worker_pid INTEGER, beat_at TEXT, seen_at TEXT, stop_requested_at TEXT, created_at TEXT,
    started_at TEXT, finished_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_proc_input (id INTEGER PRIMARY KEY AUTOINCREMENT, proc_id INTEGER NOT NULL, data TEXT NOT NULL,
    created_at TEXT, sent_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_proc_fetch (id INTEGER PRIMARY KEY AUTOINCREMENT, proc_id INTEGER NOT NULL, method TEXT NOT NULL DEFAULT \'GET\',
    path TEXT NOT NULL, content_type TEXT, body BLOB, status TEXT NOT NULL DEFAULT \'queued\', response_status INTEGER, response_path TEXT,
    response_type TEXT, response_body BLOB, error TEXT, created_at TEXT, finished_at TEXT)');
}

/**
 * One look of a stand-in worker: it takes queued processes ($w['start']: output, or ['exit', code,
 * output]; null leaves them waiting), answers input with "Hallo <line>", stops with exit code 143 and
 * answers requests from $w['fetch'] (path => [status, type, body] or 'connect').
 */
function env_worker(PDO $db, array $w): void {
  $add = static function (int $id, string $text) use ($db): void {
    $st = $db->prepare('SELECT output, output_end FROM stu_console_project_procs WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $db->prepare('UPDATE stu_console_project_procs SET output=?, output_end=?, beat_at=NOW() WHERE id=?')
      ->execute([(string)$row['output'] . $text, (int)$row['output_end'] + mb_strlen($text, 'UTF-8'), $id]);
  };
  foreach ($db->query("SELECT id, status, stop_requested_at FROM stu_console_project_procs WHERE status IN ('queued','running')")->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $id = (int)$p['id'];
    if ($p['status'] === 'queued') {
      if ($w['start'] === null) continue;
      $db->prepare("UPDATE stu_console_project_procs SET status='running', started_at=NOW(), beat_at=NOW(), worker_pid=1, files_count=1 WHERE id=?")->execute([$id]);
      if (is_array($w['start'])) {
        $add($id, $w['start'][2]);
        $db->prepare("UPDATE stu_console_project_procs SET status='exited', exit_code=?, finished_at=NOW() WHERE id=?")->execute([$w['start'][1], $id]);
      } else {
        $add($id, $w['start']);
      }
      continue;
    }
    if ($p['stop_requested_at'] !== null) {
      $add($id, "Beendet.\n");
      $db->prepare("UPDATE stu_console_project_procs SET status='stopped', exit_code=143, finished_at=NOW() WHERE id=?")->execute([$id]);
      continue;
    }
    $st = $db->prepare('SELECT id, data FROM stu_console_project_proc_input WHERE proc_id=? AND sent_at IS NULL ORDER BY id');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $in) {
      $db->prepare('UPDATE stu_console_project_proc_input SET sent_at=NOW() WHERE id=?')->execute([$in['id']]);
      $add($id, 'Hallo ' . trim((string)$in['data']) . "\n");
    }
    $st = $db->prepare("SELECT id, path FROM stu_console_project_proc_fetch WHERE proc_id=? AND status='queued'");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
      $answer = $w['fetch'][(string)$f['path']] ?? [404, 'text/plain', 'nicht da'];
      if ($answer === 'connect') {
        $db->prepare("UPDATE stu_console_project_proc_fetch SET status='error', error='connect', finished_at=NOW() WHERE id=?")->execute([$f['id']]);
        continue;
      }
      $up = $db->prepare("UPDATE stu_console_project_proc_fetch SET status='done', response_status=?, response_type=?, response_path=?, response_body=?,
        finished_at=NOW() WHERE id=?");
      $up->bindValue(1, $answer[0], PDO::PARAM_INT);
      $up->bindValue(2, $answer[1]);
      $up->bindValue(3, (string)$f['path']);
      $up->bindValue(4, $answer[2], PDO::PARAM_LOB);
      $up->bindValue(5, (int)$f['id'], PDO::PARAM_INT);
      $up->execute();
    }
  }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
env_schema($pdo);
$pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
$a = str_repeat('a', 32); $b = str_repeat('b', 32); $c = str_repeat('c', 32);
$ins = $pdo->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)');
foreach ([[$a, 7, 'Server'], [$b, 7, 'Spiel'], [$c, 9, 'Fremd']] as $row) $ins->execute([$row[0], $row[1], $row[2], 'open', '', null, '2026-11-18']);
$st = $pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)');
foreach ([['cs_server', 7, $a], ['cs_game', 7, $b], ['cs_free', 7, null], ['cs_other', 9, $c]] as $row) {
  $st->execute([$row[0], $row[1], $row[2], 'Chat', null, '2026-11-18', '2026-11-18']);
}
coreui_pfiles_write($pdo, 7, $a, 'app.py', "print('Server bereit')\n", 'build');
coreui_pfiles_write($pdo, 7, $a, 'index.html', "<h1>Hallo</h1>\n", 'build');
coreui_pfiles_write($pdo, 7, $b, 'spiel.py', "print(input('Name? '))\n", 'build');
coreui_pfiles_write($pdo, 9, $c, 'x.py', "print(1)\n", 'build');
$w = ['start'=>"Server bereit\n", 'fetch'=>['/api/zahl'=>[200, 'application/json', '{"zahl": 42}'], '/'=>[200, 'text/html', '<h1>Hallo</h1>'],
  '/bild.png'=>[200, 'image/png', "\x89PNG\x00\x00"], '/kaputt'=>'connect']];
$looks = 0;
$sleep = static function () use ($pdo, &$w, &$looks): void { $looks++; env_worker($pdo, $w); };
$tool = static fn(string $sid, string $kind, string $input = ''): string => coreui_pftools_run($pdo, 7, $sid, ['tool'=>$kind, 'input'=>$input], $sleep);

// A model's invented shell syntax must never become a claimed server start.
$unknownTool = ember_strip_stray_tool_markers("Ich starte jetzt den Server.\n[linux-shell: npm run dev]");
env_test(str_contains($unknownTool, 'NICHT ausgefuehrt')
  && str_contains($unknownTool,'kein CoreAI-Werkzeug')
  && !str_contains($unknownTool,'Ich starte jetzt'), 'invalid shell marker is not executable success');

// The hint in a project chat: environment, preview, processes, what runs now.
$hint = coreui_pftools_hint($pdo, 7, 'cs_server', false, true);
env_test(str_contains($hint, 'UMGEBUNG: Zum Projekt gehoert ein Sandbox-Container mit Python 3.12 und Node.js 24 (npm, npx) und Internetzugang.')
  && str_contains($hint, 'Was ein Befehl an Dateien aendert, bleibt nicht erhalten') && str_contains($hint, 'im Tab Dateien unter "Vorschau"')
  && str_contains($hint, 'unter "Prozesse" mit "Vorschau" (eine Adresse wie localhost:8000 erreicht sie nicht)')
  && str_contains($hint, 'Du selbst siehst die Vorschau nicht.')
  && str_contains($hint, '[SERVER_STARTEN: 8000 python -m http.server 8000]') && str_contains($hint, '[PROZESS_EINGABE: zeile]')
  && str_contains($hint, 'Nach dem Start eines Servers pruefst du ihn mit [SERVER_ABRUF: /]') && str_contains($hint, 'Gerade laeuft kein Prozess.')
  && str_contains($hint, 'Python 3.12 und Node.js 24 mit npm'), 'the hint names the environment: ' . $hint);
$compact = coreui_pftools_hint($pdo, 7, 'cs_server', true, true);
env_test(str_contains($compact, 'Umgebung: Sandbox mit Python 3.12 und Node.js 24') && str_contains($compact, '[SERVER_STARTEN: port befehl]')
  && !str_contains($compact, 'Sandbox mit Python 3.12).') && strlen($compact) < 1100, 'a short hint for small Cores: ' . strlen($compact) . ' ' . $compact);
$off = coreui_pftools_hint($pdo, 7, 'cs_server', false, false);
env_test(str_contains($off, 'Befehle, Tests und Prozesse sind in diesem Chat nicht verfuegbar') && str_contains($off, 'statt eine Ausfuehrung zu behaupten')
  && str_contains($off, 'unter "Vorschau"') && !str_contains($off, 'PROZESS_STARTEN') && !str_contains($off, 'AUSFUEHREN')
  && str_contains(coreui_pftools_hint($pdo, 7, 'cs_server', true, false), 'Ausfuehren und Prozesse sind in diesem Chat nicht verfuegbar.'),
  'without the Python tool nothing is promised: ' . $off);
// Outside a project: one line that the environment lives in project chats.
$outside = coreui_pftools_outside_hint($pdo, 'console', 7, false);
env_test(str_starts_with($outside, 'PROJEKTE: In einem Projekt-Chat') && str_contains($outside, 'Programme und Entwicklungsserver starten')
  && str_contains($outside, 'In diesem Chat gibt es sie nicht') && coreui_pftools_outside_hint($pdo, 'console', 7, true) === ''
  && coreui_pftools_outside_hint($pdo, 'global', 7, false) === '' && coreui_pftools_outside_hint($pdo, 'console', 0, false) === '', 'the hint outside a project');
$GLOBALS['env_python'] = false;
env_test(coreui_pftools_outside_hint($pdo, 'console', 7, false) === '', 'outside a project without the Python tool: nothing');
$GLOBALS['env_python'] = true;

// Start a server: the worker takes it, the first output comes, the port is the one Mara named.
$GLOBALS['env_events'] = [];
$started = $tool('cs_server', 'pserver_start', '8000 python app.py');
env_test(str_starts_with($started, "Prozess: python app.py (Port 8000)\nLäuft.\n--- Ausgabe ---\nServer bereit") && $looks >= 1
  && str_contains($started, 'HTTP-Pruefung GET /: 200 (Server erreichbar).') && str_contains($started, 'Prozess-ID: '),
  'Mara starts the server and verifies HTTP before success: ' . $started);
env_test(env_titles() === ['Server verifiziert, Port 8000'], 'work view: ' . json_encode(env_titles()));
env_test(str_contains(coreui_pftools_hint($pdo, 7, 'cs_server', false, true), 'Gerade laeuft: python app.py (Port 8000).'), 'the hint names what runs now');
env_test($tool('cs_server', 'pproc_start', 'python app.py') === 'Das ging nicht: in diesem Projekt laeuft schon ein Prozess; lies ihn mit [PROZESS_AUSGABE] oder beende ihn mit [PROZESS_BEENDEN].',
  'one process per project');
// Requests to the server.
$json = $tool('cs_server', 'pserver_fetch', 'http://localhost:8000/api/zahl');
env_test($json === "GET /api/zahl: HTTP 200 (application/json)\n--- Inhalt ---\n{\"zahl\": 42}", 'Mara reads the JSON: ' . $json);
env_test(str_contains($tool('cs_server', 'pserver_fetch', '/bild.png'), '(Binaerdaten, 6 Bytes.)')
  && str_starts_with($tool('cs_server', 'pserver_fetch', '/fehlt'), 'GET /fehlt: HTTP 404 (text/plain)'), 'a picture and a missing page');
env_test(str_starts_with($tool('cs_server', 'pserver_fetch', '/kaputt'), 'Das ging nicht: der Server antwortet nicht: er ist noch nicht bereit')
  && str_contains($tool('cs_server', 'pserver_fetch', 'api'), 'der Pfad beginnt mit /'), 'unreachable and invalid paths are sentences');
// Output and input.
$out = $tool('cs_server', 'pproc_output');
env_test(str_contains($out, "--- Ausgabe ---\nServer bereit") && str_contains($out, 'Läuft.'), 'the output: ' . $out);
$in = $tool('cs_server', 'pproc_input', 'Mara');
env_test(str_starts_with($in, "Eingabe gesendet: \"Mara\"\nProzess: python app.py (Port 8000)\nLäuft.\n--- Neue Ausgabe ---\nHallo Mara")
  && !str_contains($in, 'Server bereit'), 'input and only the new output: ' . $in);
// Stop: the real exit code.
$GLOBALS['env_events'] = [];
$stop = $tool('cs_server', 'pproc_stop');
env_test(str_contains($stop, 'Beendet (Exitcode 143).') && str_contains($stop, 'Beendet.') && !str_contains($stop, 'laeuft im Sandbox'), 'stopped: ' . $stop);
env_test(env_titles() === ['Prozess beendet, Exitcode 143'], 'work view of the stop: ' . json_encode(env_titles()));
env_test($tool('cs_server', 'pproc_stop') === 'Gerade laeuft kein Prozess in diesem Projekt; nichts beendet.'
  && str_starts_with($tool('cs_server', 'pproc_input', 'x'), 'Gerade laeuft kein Prozess in diesem Projekt. Starte einen')
  && str_starts_with($tool('cs_server', 'pserver_fetch', '/'), 'Gerade laeuft kein Prozess'), 'nothing runs: sentences');
env_test(str_contains($tool('cs_server', 'pproc_output'), 'Beendet (Exitcode 143).'), 'the output of the last process stays readable');
// A port in the command makes it a server; a server without a port does not start.
$guess = $tool('cs_server', 'pproc_start', 'python -m http.server 8001');
env_test(str_starts_with($guess, 'Prozess: python -m http.server 8001 (Port 8001)'), 'the port from the command: ' . $guess);
$tool('cs_server', 'pproc_stop');
$count = (int)$pdo->query('SELECT COUNT(*) FROM stu_console_project_procs')->fetchColumn();
env_test(str_starts_with($tool('cs_server', 'pserver_start', 'npm start'), 'Der Server wurde NICHT gestartet: nenne den Port')
  && (int)$pdo->query('SELECT COUNT(*) FROM stu_console_project_procs')->fetchColumn() === $count, 'a server without a port does not start');
env_test(str_contains($tool('cs_server', 'pserver_start', '80 python app.py'), 'der Port liegt zwischen 1024 und 65535'), 'a low port is a sentence');
// A program, input that answers, a request without a port.
$game = $tool('cs_game', 'pproc_start', 'python spiel.py');
env_test(str_starts_with($game, "Prozess: python spiel.py\nLäuft.") && !str_contains($game, 'SERVER_ABRUF'), 'a program: ' . $game);
env_test(str_starts_with($tool('cs_game', 'pserver_fetch', '/'), 'Der laufende Prozess hat keinen Port'), 'no port, no request');
$tool('cs_game', 'pproc_stop');
// A program that ends at once, one that waits for a worker.
$GLOBALS['env_events'] = [];
$w['start'] = ['exit', 1, "Traceback (most recent call last):\nNameError: name 'x' is not defined\n"];
$crash = $tool('cs_game', 'pproc_start', 'python spiel.py');
env_test(str_contains($crash, 'Beendet mit Exitcode 1.') && str_contains($crash, 'NameError') && env_titles() === ['Prozess gleich wieder beendet, Exitcode 1'],
  'a program that ends at once: ' . $crash . json_encode(env_titles()));
$GLOBALS['env_events'] = [];
$w['start'] = null;
$wait = $tool('cs_game', 'pproc_start', 'python spiel.py');
env_test(str_contains($wait, 'Wartet auf den Sandbox-Dienst.') && str_contains($wait, 'noch nicht uebernommen')
  && env_titles() === ['Prozess wartet auf den Sandbox-Dienst'], 'a process nobody takes is not called running: ' . $wait);
$tool('cs_game', 'pproc_stop');
$w['start'] = "Server bereit\n";
// Other chats and accounts, a missing migration.
env_test($tool('cs_free', 'pproc_start', 'python app.py') === 'Der Datei-Arbeitsbereich dieses Chats ist noch nicht verfügbar. Migration 056 und Berechtigungen prüfen.'
  && str_starts_with($tool('cs_other', 'pproc_output'), 'Der Datei-Arbeitsbereich dieses Chats ist noch nicht verfügbar'), 'only in the own project chat');
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
env_schema($old, false);
$old->exec('INSERT INTO stu_users VALUES (7, 0)');
$old->prepare('INSERT INTO stu_console_projects (id,user_id,name,context_mode,instructions_md,pinned_at,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$a, 7, 'Alt', 'open', '', null, '2026-11-18']);
$old->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)')->execute(['cs_old', 7, $a, 'Alt', null, '2026-11-18', '2026-11-18']);
coreui_pfiles_write($old, 7, $a, 'app.py', "print(1)\n", 'build');
env_test(coreui_pftools_run($old, 7, 'cs_old', ['tool'=>'pproc_start', 'input'=>'python app.py'])
    === 'Das ging nicht: Prozesse sind auf diesem Server noch nicht eingerichtet (Migration 048).'
  && !str_contains(coreui_pftools_hint($old, 7, 'cs_old', false, true), 'PROZESS_STARTEN')
  && str_contains(coreui_pftools_hint($old, 7, 'cs_old', false, true), '[AUSFUEHREN: befehl]')
  && coreui_pftools_run($old, 7, 'cs_old', ['tool'=>'pproc_output', 'input'=>'ausgabe'])
    === 'Das ging nicht: Prozesse sind auf diesem Server noch nicht eingerichtet (Migration 048).', 'without migration 048: runs yes, processes no');
foreach (array_column($GLOBALS['env_events'], 2) as $t) {
  env_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', $t) === 1, 'work event title fits the work store: ' . $t);
}

// --- A whole turn: start the server, check it, answer (scripted Core) ------------------------------------
$script = ["[SERVER_ABRUF: /api/zahl]", 'Der Server laeuft auf Port 8000 und liefert unter /api/zahl die Zahl 42. Du siehst ihn im Tab Dateien unter Prozesse.'];
$prompts = [];
$records = [];
$loop = coreui_agent_loop_run("[SERVER_STARTEN: 8000 python app.py]\n", ['tool'=>'pserver_start', 'input'=>'8000 python app.py'], 'BASIS', $procKinds, 0,
  static function (string $prompt) use (&$script, &$prompts): ?string { $prompts[] = $prompt; return array_shift($script); },
  static fn(array $t): string => coreui_pftools_run($pdo, 7, 'cs_server', $t, $sleep),
  static function (string $kind, string $state, string $title, array $meta) use (&$records): void { $records[] = [$kind, $state, $title, $meta]; },
  static fn(): bool => false);
env_test($loop['steps'] === 2 && str_starts_with($loop['out'], 'Der Server laeuft auf Port 8000'), 'the turn ends with Mara\'s answer: ' . json_encode($loop['out']));
env_test(str_contains($prompts[0], 'Server bereit') && str_contains($prompts[0], 'Server gestartet: „8000 python app.py“')
  && str_contains($prompts[1], '{"zahl": 42}') && str_contains($prompts[1], 'Server abgerufen: „/api/zahl“'), 'Mara sees the start, then the answer');
$notes = array_values(array_filter(array_map(static fn(array $r): string => (string)($r[3]['public_note'] ?? ''), $records)));
env_test($notes === ['Ich starte den Server: 8000 python app.py', 'Ich rufe beim Server ab: /api/zahl'], 'the work view says what Mara does: ' . json_encode($notes));
coreui_pftools_run($pdo, 7, 'cs_server', ['tool'=>'pproc_stop', 'input'=>'beenden'], $sleep);

// --- MariaDB and the real sandbox ----------------------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql', '047_project_runs.sql', '048_project_procs.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('env-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  $worker = null;
  $tmp = sys_get_temp_dir() . '/coreai-env-' . bin2hex(random_bytes(4));
  $projects = [];
  $sessions = [];
  $chat = static function (string $projectId) use ($db, $uid, &$sessions): string {
    $sid = 'cs_env_' . bin2hex(random_bytes(6));
    $db->prepare('INSERT INTO stu_console_sessions (id, user_id, project_id, title, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
      ->execute([$sid, $uid, $projectId, 'Selbsttest']);
    $sessions[] = $sid;
    return $sid;
  };
  try {
    $web = coreui_project_create($db, $uid, 'Selbsttest Umgebung', 'open');
    $projects[] = $pid = $web['id'];
    $sid = $chat($pid);
    $app = "import http.server, json\n"
      . "class H(http.server.SimpleHTTPRequestHandler):\n"
      . "    def do_GET(self):\n"
      . "        if self.path.startswith('/api/zahl'):\n"
      . "            body = json.dumps({'zahl': 42}).encode()\n"
      . "            self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(body); return\n"
      . "        return super().do_GET()\n"
      . "print('Server bereit', flush=True)\n"
      . "http.server.ThreadingHTTPServer(('0.0.0.0', 8000), H).serve_forever()\n";
    coreui_pfiles_write($db, $uid, $pid, 'app.py', $app, 'build');
    coreui_pfiles_write($db, $uid, $pid, 'index.html', "<!doctype html><html><body><h1>Version 1</h1></body></html>", 'build');
    $mdbLook = static function () use ($db, &$w): void { env_worker($db, $w); };
    $w = ['start'=>"Server bereit\n", 'fetch'=>['/'=>[200,'text/html','<h1>OK</h1>'], '/api/zahl'=>[200, 'application/json', '{"zahl": 42}']]];
    $first = coreui_pftools_run($db, $uid, $sid, ['tool'=>'pserver_start', 'input'=>'8000 python app.py'], $mdbLook);
    env_test(str_starts_with($first, "Prozess: python app.py (Port 8000)\nLäuft.\n--- Ausgabe ---\nServer bereit"), 'MariaDB: start with a stand-in worker: ' . $first);
    env_test(str_contains(coreui_pftools_hint($db, $uid, $sid, false, true), 'Gerade laeuft: python app.py (Port 8000).')
      && str_ends_with(coreui_pftools_run($db, $uid, $sid, ['tool'=>'pserver_fetch', 'input'=>'/api/zahl'], $mdbLook), '{"zahl": 42}'), 'MariaDB: hint and request');
    env_test(str_contains(coreui_pftools_run($db, $uid, $sid, ['tool'=>'pproc_stop', 'input'=>'beenden'], $mdbLook), 'Beendet (Exitcode 143).'), 'MariaDB: stop');
    $mariadb = ', MariaDB (Migration 044 bis 048)';

    if (getenv('COREUI_SELFTEST_SANDBOX') === '1') {
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
      $log = static fn(): string => "\n" . (string)@file_get_contents($tmp . '/worker.log');
      $real = static fn(string $kind, string $input = ''): string => coreui_pftools_run($db, $uid, $sid, ['tool'=>$kind, 'input'=>$input]);
      $until = static function (callable $test, float $seconds = 30.0) {
        $deadline = microtime(true) + $seconds;
        do { $value = $test(); if ($value) return $value; usleep(300000); } while (microtime(true) < $deadline);
        return false;
      };

      // Mara starts the server herself and checks it.
      $GLOBALS['env_events'] = [];
      $start = $real('pserver_start', '8000 python app.py');
      env_test(str_starts_with($start, "Prozess: python app.py (Port 8000)\nLäuft.") && env_titles() === ['Server verifiziert, Port 8000'],
        'sandbox: Mara starts the server: ' . $start . $log());
      $answer = $until(static function () use ($real) {
        $text = $real('pserver_fetch', '/api/zahl');
        return str_ends_with($text, '{"zahl": 42}') ? $text : null;
      });
      env_test(is_string($answer) && str_starts_with($answer, 'GET /api/zahl: HTTP 200 (application/json)'), 'sandbox: Mara reads the JSON of her server' . $log());
      env_test(str_contains($real('pserver_fetch', '/'), '<h1>Version 1</h1>'), 'sandbox: the page');
      // She changes the page; the running server serves the change.
      $edit = coreui_pftools_run($db, $uid, $sid, ['tool'=>'pfile_edit', 'input'=>"index.html\n<<<<<<< SUCHEN\n<h1>Version 1</h1>\n=======\n<h1>Version 2</h1>\n>>>>>>> ERSETZEN"]);
      env_test(str_starts_with($edit, 'Datei index.html geaendert'), 'sandbox: Mara changes the page: ' . $edit);
      env_test((bool)$until(static fn() => str_contains($real('pserver_fetch', '/'), '<h1>Version 2</h1>'), 15), 'sandbox: the change is served' . $log());
      env_test(str_contains($real('pproc_output'), 'Server bereit'), 'sandbox: the output');
      $stopped = $real('pproc_stop');
      env_test(str_contains($stopped, 'Beendet (Exitcode 143).'), 'sandbox: Mara stops it, exit code 143: ' . $stopped . $log());

      // A program that asks: Mara answers it, it ends with its own exit code.
      $ask = coreui_project_create($db, $uid, 'Selbsttest Frage', 'open');
      $projects[] = $aid = $ask['id'];
      $asid = $chat($aid);
      coreui_pfiles_write($db, $uid, $aid, 'frage.py', "import sys\nprint('Wie heisst du?', flush=True)\nname = sys.stdin.readline().strip()\n"
        . "print('Hallo ' + name, flush=True)\nsys.exit(3)\n", 'build');
      $asked = coreui_pftools_run($db, $uid, $asid, ['tool'=>'pproc_start', 'input'=>'python frage.py']);
      if (!str_contains($asked, 'Wie heisst du?')) $asked = coreui_pftools_run($db, $uid, $asid, ['tool'=>'pproc_output', 'input'=>'ausgabe']);
      env_test(str_contains($asked, 'Wie heisst du?'), 'sandbox: the program asks Mara: ' . $asked . $log());
      $reply = coreui_pftools_run($db, $uid, $asid, ['tool'=>'pproc_input', 'input'=>'Mara']);
      $done = $until(static function () use ($db, $uid, $asid) {
        $text = coreui_pftools_run($db, $uid, $asid, ['tool'=>'pproc_output', 'input'=>'ausgabe']);
        return str_contains($text, 'Beendet mit Exitcode 3.') ? $text : null;
      }, 15);
      env_test(str_contains($reply, 'Hallo Mara') || (is_string($done) && str_contains($done, 'Hallo Mara')), 'sandbox: the answer arrives: ' . $reply . $log());
      env_test(is_string($done), 'sandbox: the program ends with its own exit code 3' . $log());
      $mariadb .= ', Sandbox (Server gestartet, JSON, Aenderung ausgeliefert, Beenden 143, Eingabe, Exitcode 3)';
    }
  } finally {
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    exec('docker ps -aq --filter name=ember-proc- 2>/dev/null | xargs -r docker rm -f >/dev/null 2>&1');
    foreach ($projects as $project) coreui_project_delete($db, $uid, $project);
    foreach ($sessions as $session) $db->prepare('DELETE FROM stu_console_sessions WHERE id=?')->execute([$session]);
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
    if (is_dir($tmp)) exec('rm -rf ' . escapeshellarg($tmp));
  }
}

echo "CoreAI Projektumgebung: Marker, Ports, Texte, Schleife, Sicherheitsnetz, Hinweis im und ausserhalb eines Projekts, Verdrahtung, "
  . "Starten, Server mit Port, Abruf, Eingabe, Ausgabe, Beenden, sofort beendet, wartend, fremde Konten, ohne Migration 048, ganzer Zug OK (SQLite{$mariadb})\n";
