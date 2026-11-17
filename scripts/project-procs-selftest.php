<?php
declare(strict_types=1);
// CoreAI 0.7.78 (P10 C): processes of a project and the preview of a development server.
//   php scripts/project-procs-selftest.php
// Offline: port and path checks, the output slice, the suggestion, the sentences, server paths
// in a page, the page builder in server mode (stylesheets, scripts and pictures from the server,
// links and GET forms with server paths, fetch through the parent, redirects), the store against
// SQLite (one process per project, two per account, hourly limit, the last 10 stay, input, stop,
// requests to the server, processes whose worker is gone, other accounts, a missing migration)
// and the wiring of the endpoint.
// With COREUI_SELFTEST_DB=1 against MariaDB (migration 048, everything goes with its project).
// With COREUI_SELFTEST_DB=1 and COREUI_SELFTEST_SANDBOX=1 also end to end with the real worker
// (tools/ember_py_worker.py) and Docker: a development server starts, its page and JSON come
// through the preview, a saved change shows up, input reaches a program, exit codes are the
// real ones, an unwatched process ends, and stopping the worker ends its processes.
// Test data uses example.test only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_compaction.php';
require_once $root . '/api/project_procs_store.php';
function ppr_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Project procs FAIL: {$why}\n"); exit(1); }
}
function ppr_throws(callable $fn, string $error): bool {
  try { $fn(); } catch (Throwable $e) { return $e->getMessage() === $error; }
  return false;
}

// --- Pure ----------------------------------------------------------------------------------------
ppr_test(coreui_pprocs_port(null) === null && coreui_pprocs_port('') === null && coreui_pprocs_port(8000) === 8000
  && coreui_pprocs_port(' 5173 ') === 5173, 'ports');
foreach ([80, 1023, 65536, 'acht', '80a', 8000.5, true] as $bad) {
  ppr_test(ppr_throws(static fn() => coreui_pprocs_port($bad), 'invalid_port'), 'refused port: ' . json_encode($bad));
}
ppr_test(coreui_pprocs_path('/') === '/' && coreui_pprocs_path('/api/x?y=1&z=%20') === '/api/x?y=1&z=%20', 'server paths');
foreach (['', 'a', '//evil.test/x', '/a b', "/a\r\nHost: x", '/ä', '/' . str_repeat('a', 1000)] as $bad) {
  ppr_test(ppr_throws(static fn() => coreui_pprocs_path($bad), 'invalid_server_path'), 'refused server path: ' . json_encode($bad));
}
ppr_test(coreui_pprocs_slice("Hallo\nWelt\n", 11, 0) === ['text'=>"Hallo\nWelt\n", 'from'=>0, 'cut'=>false]
  && coreui_pprocs_slice("Hallo\nWelt\n", 11, 6) === ['text'=>"Welt\n", 'from'=>6, 'cut'=>false]
  && coreui_pprocs_slice("Hallo\nWelt\n", 11, 11) === ['text'=>'', 'from'=>11, 'cut'=>false]
  && coreui_pprocs_slice("Hallo\nWelt\n", 11, 50) === ['text'=>'', 'from'=>11, 'cut'=>false], 'output slices');
ppr_test(coreui_pprocs_slice('äöü€end', 100007, 5) === ['text'=>'äöü€end', 'from'=>100000, 'cut'=>true]
  && coreui_pprocs_slice('äöü€end', 100007, 100002) === ['text'=>'ü€end', 'from'=>100002, 'cut'=>false], 'a cut buffer, counted in characters');
ppr_test(coreui_pprocs_suggest(['index.html', 'style.css']) === ['command'=>'python -m http.server 8000', 'port'=>8000]
  && coreui_pprocs_suggest(['app.py', 'index.html']) === ['command'=>'python app.py', 'port'=>null]
  && coreui_pprocs_suggest(['b.py', 'a.py']) === ['command'=>'python a.py', 'port'=>null]
  && coreui_pprocs_suggest(['notes.md']) === ['command'=>'', 'port'=>null], 'suggestions');
$p = ['status'=>'stopped', 'exit_code'=>143, 'error'=>null, 'stop_requested'=>true];
ppr_test(coreui_pprocs_summary($p) === 'Beendet (Exitcode 143).'
  && coreui_pprocs_summary(['status'=>'exited', 'exit_code'=>3, 'error'=>null, 'stop_requested'=>false]) === 'Beendet mit Exitcode 3.'
  && coreui_pprocs_summary(['status'=>'stopped', 'exit_code'=>143, 'error'=>'idle', 'stop_requested'=>false]) === 'Beendet, weil niemand mehr hingesehen hat (Exitcode 143).'
  && coreui_pprocs_summary(['status'=>'running', 'exit_code'=>null, 'error'=>null, 'stop_requested'=>true]) === 'Wird beendet.'
  && str_contains(coreui_pprocs_summary(['status'=>'error', 'exit_code'=>null, 'error'=>'no_worker', 'stop_requested'=>false]), 'Python-Dienst'),
  'sentences');
ppr_test(coreui_pprocs_page_key('/') === 'index.html' && coreui_pprocs_page_key('/docs/') === 'docs/index.html'
  && coreui_pprocs_page_key('/about?x=1') === 'about' && coreui_pprocs_page_key('/a%20b/c.html') === 'a b/c.html', 'page keys');
ppr_test(coreui_pprocs_is_text('text/html; charset=utf-8') && coreui_pprocs_is_text('application/json') && coreui_pprocs_is_text('application/ld+json')
  && coreui_pprocs_is_text('image/svg+xml') && !coreui_pprocs_is_text('image/png') && !coreui_pprocs_is_text(''), 'text types');
ppr_test(coreui_pprocs_fetch_json(['status'=>200, 'type'=>'application/json', 'path'=>'/a', 'body'=>'{"a":1}'])
    === ['status'=>200, 'type'=>'application/json', 'path'=>'/a', 'body'=>'{"a":1}', 'base64'=>false, 'truncated'=>false]
  && coreui_pprocs_fetch_json(['status'=>200, 'type'=>'image/png', 'path'=>'/p.png', 'body'=>"\x89PNG"])['body'] === base64_encode("\x89PNG")
  && coreui_pprocs_fetch_json(['status'=>200, 'type'=>'text/plain', 'path'=>'/x', 'body'=>"\xff\xfe"])['base64'] === true,
  'fetch answers: text as text, bytes as base64');

ppr_test(coreui_preview_server_ref('/docs/a.html', 'b.html') === '/docs/b.html'
  && coreui_preview_server_ref('/docs/a.html', '../x?y=1#z') === '/x?y=1'
  && coreui_preview_server_ref('/docs/', '?q=2') === '/docs/?q=2'
  && coreui_preview_server_ref('/a', '/') === '/'
  && coreui_preview_server_ref('/a/b', './') === '/a/'
  && coreui_preview_server_ref('/a', '../../../etc/passwd') === '/etc/passwd'
  && coreui_preview_server_ref('/a', 'grüße.html') === '/gr%C3%BC%C3%9Fe.html'
  && coreui_preview_server_ref('/a', 'https://example.test/') === null && coreui_preview_server_ref('/a', '//example.test/x') === null
  && coreui_preview_server_ref('/a', '#top') === null && coreui_preview_server_ref('/a', 'javascript:alert(1)') === null
  && coreui_preview_server_ref('/a', 'mailto:x@example.test') === null, 'server references');

// The page of a development server, with a fake server.
$server = [
  '/docs' => ['status'=>200, 'type'=>'text/html; charset=utf-8', 'path'=>'/docs/', 'body'=>'<!doctype html><html><head>'
    . '<link rel="stylesheet" href="style.css"><script src="/js/app.js" defer></script></head><body>'
    . '<img src="logo.png" alt=""><img src="icon.svg" alt=""><a href="../about?x=1">Über</a> <a href="https://example.test/">außen</a>'
    . '<form action="suche"><input name="q"></form></body></html>'],
  '/docs/style.css' => ['status'=>200, 'type'=>'text/css', 'path'=>'/docs/style.css', 'body'=>'h1{color:rgb(1,2,3)}'],
  '/js/app.js' => ['status'=>200, 'type'=>'text/javascript', 'path'=>'/js/app.js', 'body'=>"fetch('/api/zahl').then(r => r.json());"],
  '/docs/logo.png' => ['status'=>200, 'type'=>'image/png', 'path'=>'/docs/logo.png', 'body'=>"\x89PNG\r\n"],
  '/docs/icon.svg' => ['status'=>404, 'type'=>'text/plain', 'path'=>'/docs/icon.svg', 'body'=>'fehlt'],
  '/api/zahl' => ['status'=>200, 'type'=>'application/json', 'path'=>'/api/zahl', 'body'=>'{"zahl":42}'],
];
$asked = [];
$fake = static function (string $path) use ($server, &$asked): array {
  $asked[] = $path;
  if (!isset($server[$path])) throw new RuntimeException('server_unreachable');
  return $server[$path];
};
$batches = [];
$many = static function (array $paths) use ($fake, &$batches): array {
  $batches[] = $paths;
  return array_map(static function (string $p) use ($fake) { try { return $fake($p); } catch (Throwable $e) { return $e->getMessage(); } }, $paths);
};
$html = coreui_pprocs_page($fake, '/docs', './project_files.php?project_id=x&proc_preview=4&path=', $many, 'Schluessel_0123456789');
ppr_test($asked === ['/docs', '/docs/style.css', '/js/app.js', '/docs/logo.png', '/docs/icon.svg']
  && $batches === [['/docs/style.css', '/js/app.js', '/docs/logo.png', '/docs/icon.svg']],
  'the page asks for its references after the redirect, all at once: ' . json_encode([$asked, $batches]));
$one = coreui_pprocs_page($fake, '/docs', 'u');
ppr_test(str_contains($one, '<style data-coreai-src="docs/style.css">'), 'one by one without a batch function');
ppr_test(coreui_pprocs_key('Schluessel_0123456789') === 'Schluessel_0123456789' && coreui_pprocs_key(str_repeat('a', 15)) === ''
  && coreui_pprocs_key('a"b' . str_repeat('c', 20)) === '' && coreui_pprocs_key(null) === '' && coreui_pprocs_key(str_repeat('f', 65)) === '',
  'preview keys');
ppr_test(str_contains($html, '<style data-coreai-src="docs/style.css">h1{color:rgb(1,2,3)}</style>')
  && str_contains($html, 'data-coreai-src="js/app.js">fetch(\'/api/zahl\')')
  && str_contains($html, 'src="data:image/png;base64,' . base64_encode("\x89PNG\r\n") . '"')
  && str_contains($html, '<img src="icon.svg" alt="">'), 'stylesheet, script and picture from the server; a missing one stays as it was');
ppr_test(str_contains($html, '<a href="../about?x=1" data-coreai-page="/about?x=1">') && str_contains($html, '<a href="https://example.test/">außen</a>'),
  'links keep server paths, web addresses stay');
ppr_test(str_contains($html, 'SERVER = "/docs/", KEY = "Schluessel_0123456789"') && str_contains($html, 'var FILES = []')
  && str_contains($html, "coreaiPreview: 'fetch', key: KEY") && substr_count($html, "coreaiPreview: 'open', key: KEY, path: next") === 2,
  'the shim knows the server path and its key, and sends fetch and pages to the parent with the key');
$shimLines = coreui_preview_shim('{}', '"i"', '"x"', '[]', '"/"');
ppr_test(preg_match('~(^|\s)//~m', $shimLines) !== 1, 'the shim has no line comments (it is put on one line)');
$plain = coreui_pprocs_page(static fn(string $p): array => ['status'=>200, 'type'=>'application/json', 'path'=>$p, 'body'=>'{"<b>":1}'], '/api', 'u');
ppr_test(str_contains($plain, 'Antwort 200 · application/json · /api') && str_contains($plain, '{&quot;&lt;b&gt;&quot;:1}')
  && !str_contains($plain, '<b>'), 'an answer that is not a page is shown as text, escaped');
$bin = coreui_pprocs_page(static fn(string $p): array => ['status'=>200, 'type'=>'image/png', 'path'=>$p, 'body'=>"\x89PNG"], '/p.png', 'u');
ppr_test(str_contains($bin, 'Keine Textantwort (4 Bytes).'), 'bytes are named, not shown');
ppr_test(str_contains(coreui_preview_build(['a.html'=>'<a href="b">x</a>'], 'a.html', 'u'), '<a href="b">x</a>')
  && str_contains(coreui_preview_build(['a.html'=>'x'], 'a.html', 'u'), 'SERVER = null'), 'the saved-file preview of P10 A is unchanged');

// --- Wiring (static) -------------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/project_files.php');
ppr_test(str_contains($endpoint, "case 'proc_start':\n      if (!coreui_pruns_allowed(\$pdo, \$uid)) stu_json(['ok'=>false, 'error'=>'python_disabled'], 403);")
  && strpos($endpoint, "case 'proc_start':") > strpos($endpoint, 'stu_require_csrf();')
  && strpos($endpoint, "case 'proc_fetch':") > strpos($endpoint, 'stu_require_csrf();')
  && str_contains($endpoint, "case 'proc_input':") && str_contains($endpoint, "case 'proc_stop':")
  && str_contains($endpoint, "if (isset(\$_GET['proc_preview'])) {\n      coreui_preview_headers();"), 'endpoint: CSRF, Python switch, sandboxed preview');
$migration = (string)file_get_contents($root . '/database/migrations/048_project_procs.sql');
ppr_test(substr_count($migration, 'ON DELETE CASCADE') === 4 && str_contains($migration, "VALUES ('048_project_procs')"), 'migration 048: everything goes with its project');
$worker = (string)file_get_contents($root . '/tools/ember_project_procs.py');
ppr_test(str_contains($worker, '"docker", "run", "--rm", "-i", "--init"') && !str_contains($worker, '"--publish"') && !str_contains($worker, '"-p",'),
  'the worker publishes no port');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "CoreAI Projektprozesse: Ports, Pfade, Ausgabe, Vorschlag, Saetze, Serverseiten, Verdrahtung OK; SQLite not installed, store tests skipped\n";
  exit(0);
}

// --- Store against SQLite ----------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, permission_level INTEGER)');
$pdo->exec('CREATE TABLE stu_console_media (user_id INTEGER, kind TEXT, file_size INTEGER)');
$pdo->exec('CREATE TABLE stu_console_projects (id TEXT PRIMARY KEY, user_id INTEGER, name TEXT, context_mode TEXT, instructions_md TEXT, pinned_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_sessions (id TEXT PRIMARY KEY, user_id INTEGER, project_id TEXT, title TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_project_files (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, user_id INTEGER NOT NULL, path TEXT NOT NULL,
  content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, UNIQUE (project_id, path))');
$pdo->exec('CREATE TABLE stu_console_project_file_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id TEXT NOT NULL, project_id TEXT NOT NULL,
  user_id INTEGER NOT NULL, revision INTEGER NOT NULL, path TEXT NOT NULL, content TEXT NOT NULL, size_bytes INTEGER NOT NULL, sha256 TEXT NOT NULL,
  source TEXT NOT NULL, session_id TEXT, message_id INTEGER, created_at TEXT, UNIQUE (file_id, revision))');
$pdo->exec('CREATE TABLE stu_console_project_procs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, project_id TEXT NOT NULL,
  command TEXT NOT NULL, port INTEGER, status TEXT NOT NULL DEFAULT \'queued\', exit_code INTEGER, error TEXT, output TEXT, output_end INTEGER NOT NULL DEFAULT 0,
  files_count INTEGER, files_sha256 TEXT, synced_at TEXT, worker_pid INTEGER, beat_at TEXT, seen_at TEXT, stop_requested_at TEXT, created_at TEXT,
  started_at TEXT, finished_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_project_proc_input (id INTEGER PRIMARY KEY AUTOINCREMENT, proc_id INTEGER NOT NULL, data TEXT NOT NULL,
  created_at TEXT, sent_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_project_proc_fetch (id INTEGER PRIMARY KEY AUTOINCREMENT, proc_id INTEGER NOT NULL, method TEXT NOT NULL DEFAULT \'GET\',
  path TEXT NOT NULL, content_type TEXT, body BLOB, status TEXT NOT NULL DEFAULT \'queued\', response_status INTEGER, response_path TEXT,
  response_type TEXT, response_body BLOB, error TEXT, created_at TEXT, finished_at TEXT)');
$pdo->exec('INSERT INTO stu_users VALUES (7, 0), (9, 0)');
$a = str_repeat('a', 32); $b = str_repeat('b', 32); $d = str_repeat('d', 32); $e = str_repeat('e', 32); $c = str_repeat('c', 32);
$ins = $pdo->prepare('INSERT INTO stu_console_projects VALUES (?,?,?,?,?,?,?)');
foreach ([[$a, 7, 'Web'], [$b, 7, 'Zwei'], [$d, 7, 'Drei'], [$e, 7, 'Leer'], [$c, 9, 'Fremd']] as $row) $ins->execute([$row[0], $row[1], $row[2], 'open', '', null, '2026-11-17']);
foreach ([$a, $b, $d] as $project) coreui_pfiles_write($pdo, 7, $project, 'index.html', "<h1>Hallo</h1>\n", 'build');
coreui_pfiles_write($pdo, 9, $c, 'x.py', "print(1)\n", 'build');
$running = static function (PDO $pdo, int $id): void {
  $pdo->prepare("UPDATE stu_console_project_procs SET status='running', started_at=NOW(), beat_at=NOW(), worker_pid=1 WHERE id=?")->execute([$id]);
};

ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $e, 'python -m http.server 8000', 8000), 'no_files'), 'a project without files');
ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $c, 'python x.py'), 'project_not_found'), 'another account\'s project');
ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $a, "a\nb"), 'invalid_command')
  && ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $a, 'python -m http.server 80', 80), 'invalid_port'), 'bad command or port');
$web = coreui_pprocs_start($pdo, 7, $a, 'python -m http.server 8000', '8000');
ppr_test($web['status'] === 'queued' && $web['port'] === 8000 && $web['output'] === '' && $web['output_end'] === 0 && !$web['stop_requested'],
  'queued: ' . json_encode($web));
ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $a, 'sh'), 'proc_busy'), 'one process per project');
$two = coreui_pprocs_start($pdo, 7, $b, 'python -i');
ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $d, 'sh'), 'proc_active_limit'), 'two processes per account at once');
ppr_test(ppr_throws(static fn() => coreui_pprocs_get($pdo, 9, $a, $web['id']), 'project_not_found')
  && ppr_throws(static fn() => coreui_pprocs_get($pdo, 7, $b, $web['id']), 'proc_not_found')
  && ppr_throws(static fn() => coreui_pprocs_input($pdo, 9, $a, $web['id'], 'x'), 'project_not_found'), 'nobody else reads or writes it');

// Input only while it runs; the worker's output with its end position.
ppr_test(ppr_throws(static fn() => coreui_pprocs_input($pdo, 7, $b, $two['id'], 'print(1)'), 'proc_not_running'), 'no input before it runs');
$running($pdo, (int)$two['id']);
$pdo->prepare("UPDATE stu_console_project_procs SET output=?, output_end=? WHERE id=?")->execute([">>> ", 4, $two['id']]);
coreui_pprocs_input($pdo, 7, $b, $two['id'], 'print(6 * 7)');
coreui_pprocs_input($pdo, 7, $b, $two['id'], '', true);
coreui_pprocs_input($pdo, 7, $b, $two['id'], 'ohne', false);
$sent = $pdo->query('SELECT data FROM stu_console_project_proc_input ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
ppr_test($sent === ["print(6 * 7)\n", "\n", 'ohne'], 'input lines in order, Enter added unless asked not to: ' . json_encode($sent));
foreach ([str_repeat('x', 2001), "a\0b", "\xC3\x28", null] as $bad) {
  ppr_test(ppr_throws(static fn() => coreui_pprocs_input($pdo, 7, $b, $two['id'], $bad), 'invalid_input'), 'refused input: ' . json_encode($bad, JSON_INVALID_UTF8_SUBSTITUTE));
}
ppr_test(ppr_throws(static fn() => coreui_pprocs_input($pdo, 7, $b, $two['id'], '', false), 'invalid_input'), 'nothing to send');
for ($i = 0; $i < 47; $i++) coreui_pprocs_input($pdo, 7, $b, $two['id'], 'x');
ppr_test(ppr_throws(static fn() => coreui_pprocs_input($pdo, 7, $b, $two['id'], 'zu viel'), 'input_busy'), 'at most 50 lines wait for the worker');
$pdo->prepare("UPDATE stu_console_project_procs SET output=?, output_end=?, seen_at=? WHERE id=?")
  ->execute([">>> 42\n>>> ", 11, '2026-01-01 00:00:00', $two['id']]);
$got = coreui_pprocs_get($pdo, 7, $b, $two['id'], 4);
ppr_test($got['status'] === 'running' && $got['output'] === "42\n>>> " && $got['output_from'] === 4 && !$got['output_cut'], 'only the new output');
ppr_test((string)$pdo->query("SELECT seen_at FROM stu_console_project_procs WHERE id={$two['id']}")->fetchColumn() !== '2026-01-01 00:00:00',
  'looking at a running process keeps it alive');
$list = coreui_pprocs_list($pdo, 7, $b);
ppr_test(count($list) === 1 && !array_key_exists('output', $list[0]), 'the list without output');

// Stop: queued at once, running through the worker.
$q = coreui_pprocs_stop($pdo, 7, $a, $web['id']);
ppr_test($q['status'] === 'stopped' && $q['stop_requested'], 'a queued process ends at once');
$s = coreui_pprocs_stop($pdo, 7, $b, $two['id']);
ppr_test($s['status'] === 'running' && $s['stop_requested'] && coreui_pprocs_summary($s) === 'Wird beendet.', 'a running one gets the request');
$pdo->prepare("UPDATE stu_console_project_procs SET status='stopped', exit_code=143, finished_at=NOW() WHERE id=?")->execute([$two['id']]);

// Requests to the development server.
$srv = coreui_pprocs_start($pdo, 7, $a, 'python -m http.server 8000', 8000);
ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'GET', '/'), 'proc_not_running'), 'no request before it runs');
$running($pdo, (int)$srv['id']);
ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'TRACE', '/'), 'invalid_method')
  && ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'GET', 'http://example.test/'), 'invalid_server_path')
  && ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'POST', '/', str_repeat('x', 1048577)), 'body_too_large'),
  'bad requests are refused before they are queued');
$worker = static function (string $status, array $fields) use ($pdo): callable {
  return static function () use ($pdo, $status, $fields): void {
    $id = (int)$pdo->query("SELECT id FROM stu_console_project_proc_fetch WHERE status='queued' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $sets = ["status='{$status}'", 'finished_at=NOW()'];
    $args = [];
    foreach ($fields as $k => $v) { $sets[] = "{$k}=?"; $args[] = $v; }
    $args[] = $id;
    $pdo->prepare('UPDATE stu_console_project_proc_fetch SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($args);
  };
};
$answer = coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'post', '/api/senden', 'name=Mara', 'application/x-www-form-urlencoded',
  $worker('done', ['response_status'=>201, 'response_type'=>'application/json', 'response_path'=>'/api/senden', 'response_body'=>'{"ok":true}']));
$row = $pdo->query('SELECT method, path, content_type, body FROM stu_console_project_proc_fetch ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ppr_test($answer === ['status'=>201, 'type'=>'application/json', 'path'=>'/api/senden', 'body'=>'{"ok":true}', 'truncated'=>false]
  && $row['method'] === 'POST' && $row['path'] === '/api/senden' && $row['content_type'] === 'application/x-www-form-urlencoded' && $row['body'] === 'name=Mara',
  'a request goes to the worker and its answer comes back: ' . json_encode($answer));
ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'GET', '/', null, '', $worker('error', ['error'=>'connect'])), 'server_unreachable')
  && ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'GET', '/', null, '', $worker('error', ['error'=>'exec_failed'])), 'fetch_failed'),
  'no server on the port, a broken request');
$fill = $pdo->prepare("INSERT INTO stu_console_project_proc_fetch (proc_id, path, status, created_at) VALUES (?, '/', 'queued', NOW())");
for ($i = 0; $i < COREUI_PPROCS_FETCH_PENDING; $i++) $fill->execute([$srv['id']]);
ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $a, $srv['id'], 'GET', '/'), 'fetch_busy'), 'at most 20 requests wait');
$pdo->exec("DELETE FROM stu_console_project_proc_fetch WHERE status='queued'");
$noPort = coreui_pprocs_start($pdo, 7, $b, 'sh');
$running($pdo, (int)$noPort['id']);
ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($pdo, 7, $b, $noPort['id'], 'GET', '/'), 'proc_no_port'), 'no preview without a port');

// A worker that is gone; a process no worker takes.
$pdo->prepare('UPDATE stu_console_project_procs SET beat_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 120), $srv['id']]);
$lost = coreui_pprocs_get($pdo, 7, $a, $srv['id']);
ppr_test($lost['status'] === 'error' && $lost['error'] === 'worker_lost', 'a process without its worker ends: ' . json_encode($lost));
$pdo->prepare("UPDATE stu_console_project_procs SET status='exited', exit_code=0 WHERE id=?")->execute([$noPort['id']]);
$late = coreui_pprocs_start($pdo, 7, $d, 'sh');
$pdo->prepare('UPDATE stu_console_project_procs SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 120), $late['id']]);
ppr_test(coreui_pprocs_get($pdo, 7, $d, $late['id'])['error'] === 'no_worker', 'a process no worker takes ends');

// The last 10 stay (with their input and requests gone too); the hourly limit counts the account.
$first = (int)$pdo->query("SELECT MIN(id) FROM stu_console_project_procs WHERE project_id='{$a}'")->fetchColumn();
$pdo->prepare("INSERT INTO stu_console_project_proc_input (proc_id, data, created_at) VALUES (?, 'alt', NOW())")->execute([$first]);
$pdo->prepare("INSERT INTO stu_console_project_proc_fetch (proc_id, path, status, created_at) VALUES (?, '/', 'done', NOW())")->execute([$first]);
for ($i = 0; $i < 12; $i++) {
  $x = coreui_pprocs_start($pdo, 7, $a, 'echo ' . $i);
  $pdo->prepare("UPDATE stu_console_project_procs SET status='exited', exit_code=0 WHERE id=?")->execute([$x['id']]);
}
ppr_test((int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_procs WHERE project_id='{$a}'")->fetchColumn() === COREUI_PPROCS_KEEP
  && coreui_pprocs_list($pdo, 7, $a)[0]['command'] === 'echo 11'
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_proc_input WHERE proc_id={$first}")->fetchColumn() === 0
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_proc_fetch WHERE proc_id={$first}")->fetchColumn() === 0,
  'the last 10 processes stay, with nothing left of the older ones');
$pdo->exec('DELETE FROM stu_console_project_procs');
$many = $pdo->prepare("INSERT INTO stu_console_project_procs (user_id, project_id, command, status, created_at) VALUES (7, ?, 'echo', 'exited', ?)");
for ($i = 0; $i < COREUI_PPROCS_PER_HOUR; $i++) $many->execute([$a, gmdate('Y-m-d H:i:s', time() - 60)]);
ppr_test(ppr_throws(static fn() => coreui_pprocs_start($pdo, 7, $a, 'sh'), 'proc_limit'), 'the hourly limit');
$pdo->exec("UPDATE stu_console_project_procs SET created_at='" . gmdate('Y-m-d H:i:s', time() - 4000) . "'");
ppr_test(coreui_pprocs_start($pdo, 7, $a, 'sh')['status'] === 'queued', 'after an hour processes start again');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ppr_test(!coreui_pprocs_ready($old) && ppr_throws(static fn() => coreui_pprocs_start($old, 7, $a, 'sh'), 'project_procs_migration_required'),
  'without migration 048 nothing starts');

// --- MariaDB (optional, never the live database) -------------------------------------------------------
$mariadb = '';
if (getenv('COREUI_SELFTEST_DB') === '1') {
  $dsn = 'mysql:host=' . (getenv('COREUI_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('COREUI_DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('COREUI_DB_NAME') ?: '') . ';charset=utf8mb4';
  $db = new PDO($dsn, (string)getenv('COREUI_DB_USER'), (string)getenv('COREUI_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  foreach (['044_project_files.sql', '045_project_files_mara.sql', '047_project_runs.sql', '048_project_procs.sql'] as $file) {
    foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($root . '/database/migrations/' . $file)))) as $sql) $db->exec($sql);
  }
  $db->exec("INSERT INTO stu_users (username, is_guest, permission_level, created_at) VALUES ('ppr-" . bin2hex(random_bytes(3)) . "@example.test', 0, 0, NOW())");
  $uid = (int)$db->lastInsertId();
  $worker = null;
  $tmp = sys_get_temp_dir() . '/coreai-procs-' . bin2hex(random_bytes(4));
  $projects = [];
  try {
    $web = coreui_project_create($db, $uid, 'Selbsttest Server', 'open');
    $projects[] = $pid = $web['id'];
    $app = "import http.server, json\n"
      . "class H(http.server.SimpleHTTPRequestHandler):\n"
      . "    def do_GET(self):\n"
      . "        if self.path.startswith('/api/zahl'):\n"
      . "            body = json.dumps({'zahl': 42}).encode()\n"
      . "            self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(body); return\n"
      . "        return super().do_GET()\n"
      . "    def log_message(self, fmt, *args):\n"
      . "        print('Anfrage: ' + (fmt % args), flush=True)\n"
      . "print('Server bereit', flush=True)\n"
      . "http.server.ThreadingHTTPServer(('0.0.0.0', 8000), H).serve_forever()\n";
    coreui_pfiles_write($db, $uid, $pid, 'app.py', $app, 'build');
    coreui_pfiles_write($db, $uid, $pid, 'index.html', "<!doctype html><html><head><link rel=\"stylesheet\" href=\"style.css\"></head>"
      . "<body><h1>Version 1</h1><a href=\"/api/zahl\">Zahl</a></body></html>", 'build');
    coreui_pfiles_write($db, $uid, $pid, 'style.css', 'h1 { color: rgb(10, 20, 30); }', 'build');
    $q = coreui_pprocs_start($db, $uid, $pid, 'python app.py', 8000);
    ppr_test($q['status'] === 'queued' && $q['port'] === 8000, 'MariaDB: queued');
    $mariadb = ', MariaDB (Migration 048)';

    if (getenv('COREUI_SELFTEST_SANDBOX') === '1') {
      mkdir($tmp . '/work', 0777, true);
      chmod($tmp . '/work', 0777);
      $config = "<?php\n";
      foreach (['STU_DB_HOST'=>getenv('COREUI_DB_HOST') ?: '127.0.0.1', 'STU_DB_PORT'=>getenv('COREUI_DB_PORT') ?: '3306',
        'STU_DB_NAME'=>(string)getenv('COREUI_DB_NAME'), 'STU_DB_USER'=>(string)getenv('COREUI_DB_USER'), 'STU_DB_PASS'=>(string)getenv('COREUI_DB_PASS'),
        'STU_EMBER_PY_WORKDIR'=>$tmp . '/work', 'STU_EMBER_PY_IMAGE'=>'ember-py:1', 'STU_EMBER_PROJECT_PROC_IDLE'=>'30'] as $k => $v) {
        $config .= "define('{$k}', " . var_export((string)$v, true) . ");\n";
      }
      file_put_contents($tmp . '/config.local.php', $config);
      $worker = proc_open(['python3', $root . '/tools/ember_py_worker.py', '--config', $tmp . '/config.local.php', '--poll', '0.3'],
        [0=>['file', '/dev/null', 'r'], 1=>['file', $tmp . '/worker.log', 'w'], 2=>['file', $tmp . '/worker.log', 'a']], $pipes);
      $log = static fn(): string => "\n" . (string)@file_get_contents($tmp . '/worker.log');
      $until = static function (callable $test, float $seconds = 30.0) {
        $deadline = microtime(true) + $seconds;
        do { $value = $test(); if ($value) return $value; usleep(200000); } while (microtime(true) < $deadline);
        return false;
      };

      // A development server: running, its own first line, the page through the preview.
      $state = $until(static function () use ($db, $uid, $pid, $q) {
        $p = coreui_pprocs_get($db, $uid, $pid, $q['id']);
        return str_contains($p['output'], 'Server bereit') ? $p : null;
      });
      ppr_test(is_array($state) && $state['status'] === 'running' && $state['files_count'] === 3, 'sandbox: the server runs and says so' . $log());
      $fetch = static fn(string $path): array => coreui_pprocs_fetch($db, $uid, $pid, $q['id'], 'GET', $path);
      $many = static fn(array $paths): array => coreui_pprocs_fetch_many($db, $uid, $pid, $q['id'],
        array_map(static fn(string $p): array => ['GET', $p, null, ''], $paths));
      $page = coreui_pprocs_page($fetch, '/', './project_files.php?project_id=' . $pid . '&proc_preview=' . $q['id'] . '&path=', $many, 'Schluessel_0123456789');
      ppr_test(str_contains($page, '<h1>Version 1</h1>') && str_contains($page, '<style data-coreai-src="style.css">h1 { color: rgb(10, 20, 30); }</style>')
        && str_contains($page, 'data-coreai-page="/api/zahl"'), 'sandbox: the page with its stylesheet from the server' . $log());
      $json = coreui_pprocs_fetch_json(coreui_pprocs_fetch($db, $uid, $pid, $q['id'], 'GET', '/api/zahl?x=1'));
      ppr_test($json['status'] === 200 && $json['body'] === '{"zahl": 42}' && !$json['base64'], 'sandbox: JSON for fetch()');
      $missing = coreui_pprocs_fetch($db, $uid, $pid, $q['id'], 'GET', '/fehlt.html');
      ppr_test($missing['status'] === 404, 'sandbox: a missing page is the server\'s 404');
      $output = $until(static function () use ($db, $uid, $pid, $q) {
        $p = coreui_pprocs_get($db, $uid, $pid, $q['id'], 0);
        return str_contains($p['output'], 'Anfrage: "GET /api/zahl?x=1') ? $p : null;
      }, 10);
      ppr_test(is_array($output), 'sandbox: the server\'s log lines arrive as output');

      // A saved change shows up without a restart.
      coreui_pfiles_write($db, $uid, $pid, 'index.html', "<!doctype html><html><body><h1>Version 2</h1></body></html>", 'edit');
      $fresh = $until(static fn() => str_contains(coreui_pprocs_fetch($db, $uid, $pid, $q['id'], 'GET', '/')['body'], 'Version 2'), 15);
      ppr_test($fresh === true, 'sandbox: the saved change is served' . $log());

      // A second project: input reaches a program, which ends with its own exit code.
      $ask = coreui_project_create($db, $uid, 'Selbsttest Eingabe', 'open');
      $projects[] = $aid = $ask['id'];
      coreui_pfiles_write($db, $uid, $aid, 'frage.py', "import sys\nprint('Wie heisst du?', flush=True)\nname = sys.stdin.readline().strip()\n"
        . "print('Hallo ' + name, flush=True)\nsys.exit(3)\n", 'build');
      $p2 = coreui_pprocs_start($db, $uid, $aid, 'python frage.py');
      ppr_test((bool)$until(static fn() => str_contains(coreui_pprocs_get($db, $uid, $aid, $p2['id'])['output'], 'Wie heisst du?')),
        'sandbox: the program asks' . $log());
      coreui_pprocs_input($db, $uid, $aid, $p2['id'], 'Mara');
      $ended = $until(static function () use ($db, $uid, $aid, $p2) {
        $p = coreui_pprocs_get($db, $uid, $aid, $p2['id']);
        return $p['status'] === 'exited' ? $p : null;
      });
      ppr_test(is_array($ended) && $ended['exit_code'] === 3 && str_contains($ended['output'], "Wie heisst du?\nHallo Mara"),
        'sandbox: the answer arrives, the exit code is the program\'s own: ' . json_encode($ended) . $log());

      // Beenden: SIGTERM, the server's real exit code.
      coreui_pprocs_stop($db, $uid, $pid, $q['id']);
      $stopped = $until(static function () use ($db, $uid, $pid, $q) {
        $p = coreui_pprocs_get($db, $uid, $pid, $q['id']);
        return $p['status'] !== 'running' ? $p : null;
      });
      ppr_test(is_array($stopped) && $stopped['status'] === 'stopped' && $stopped['exit_code'] === 143 && $stopped['error'] === null,
        'sandbox: Beenden ends the server with exit code 143: ' . json_encode($stopped) . $log());
      ppr_test(ppr_throws(static fn() => coreui_pprocs_fetch($db, $uid, $pid, $q['id'], 'GET', '/'), 'proc_not_running'), 'sandbox: no preview after the end');

      // Nobody looks: the process ends after the idle time (30 s here).
      $idle = coreui_pprocs_start($db, $uid, $aid, 'sleep 600');
      ppr_test((bool)$until(static fn() => coreui_pprocs_get($db, $uid, $aid, $idle['id'])['status'] === 'running'), 'sandbox: the idle test runs');
      $db->prepare('UPDATE stu_console_project_procs SET seen_at=NOW() - INTERVAL 40 SECOND WHERE id=?')->execute([$idle['id']]);
      $quiet = $until(static function () use ($db, $idle) {
        $st = $db->prepare('SELECT status, error, exit_code FROM stu_console_project_procs WHERE id=?');
        $st->execute([$idle['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row['status'] !== 'running' ? $row : null;
      }, 20);
      ppr_test(is_array($quiet) && $quiet['status'] === 'stopped' && $quiet['error'] === 'idle', 'sandbox: an unwatched process ends: ' . json_encode($quiet));

      // The worker stops: its processes end with it, no container stays.
      $last = coreui_pprocs_start($db, $uid, $aid, 'sleep 600');
      ppr_test((bool)$until(static fn() => coreui_pprocs_get($db, $uid, $aid, $last['id'])['status'] === 'running'), 'sandbox: the last process runs');
      proc_terminate($worker);
      $code = proc_close($worker);
      $worker = null;
      $st = $db->prepare('SELECT status, error FROM stu_console_project_procs WHERE id=?');
      $st->execute([$last['id']]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      exec('docker ps -aq --filter name=ember-proc-', $left);
      ppr_test($row['status'] === 'stopped' && $row['error'] === 'worker_stopped' && $left === [],
        'sandbox: stopping the worker ends its processes: ' . json_encode($row) . ' containers: ' . count($left) . $log());
      $mariadb .= ', Sandbox (Server mit Seite, JSON, Abgleich, Eingabe, Exitcodes, Leerlauf, Worker-Ende)';
    }

    foreach ($projects as $project) coreui_project_delete($db, $uid, $project);
    $left = $db->prepare('SELECT COUNT(*) FROM stu_console_project_procs WHERE user_id=?');
    $left->execute([$uid]);
    ppr_test((int)$left->fetchColumn() === 0, 'MariaDB: the processes go with their project');
  } finally {
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    exec('docker ps -aq --filter name=ember-proc- 2>/dev/null | xargs -r docker rm -f >/dev/null 2>&1');
    $db->prepare('DELETE FROM stu_users WHERE id=?')->execute([$uid]);
    if (is_dir($tmp)) exec('rm -rf ' . escapeshellarg($tmp));
  }
}

echo "CoreAI Projektprozesse: Ports, Pfade, Ausgabe, Vorschlag, Saetze, Serverseiten, Eingabe, Beenden, Anfragen an den Server, verlorene Prozesse, Grenzen, fremde Konten, Verdrahtung OK (SQLite{$mariadb})\n";
