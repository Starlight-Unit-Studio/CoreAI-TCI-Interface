<?php
declare(strict_types=1);
// CoreAI stabilize 0.8.33: the task journal no longer blocks a finished run forever.
//  F1  a step with an 'info' result (no process running, a read that found nothing new, a start the
//      tool did not confirm) has a known outcome; it is never 'uncertain', so DONE and a resume work.
//  F2  library names ("Node.js"), files to delete and old names of renamed files are no deliverables;
//      a run that only searched needs no files; file names match without regard to case.
// Offline: an in-memory journal fake and file-store stubs, no MariaDB, no Ollama, no network.
//   php scripts/stabilize-loop-journal-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_task_journal.php';
require_once $root . '/api/console_agent_loop.php';

function slj_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Stabilize journal FAIL: {$why}\n"); exit(1); }
}

// File-store stubs: a chat workspace that holds index.html and about.html.
$GLOBALS['SLJ_FILES'] = ['index.html', 'about.html'];
function coreui_pftools_project($pdo, $uid, $sid): string { return 'p1'; }
function coreui_pfiles_list($pdo, $uid, $pid): array {
  return ['files'=>array_map(static fn(string $p): array => ['path'=>$p, 'sha256'=>str_repeat('c', 64)], $GLOBALS['SLJ_FILES']),
    'count'=>count($GLOBALS['SLJ_FILES'])];
}
function coreui_pfiles_path($value): string {
  $path = trim(str_replace('\\', '/', (string)$value), '/');
  if ($path === '' || str_contains($path, '..')) throw new InvalidArgumentException('invalid_path');
  return $path;
}
function coreui_pftools_read_target(string $input): array { return [$input, 1]; }
// The pure marker parsers of project_file_tools.php (that file also loads the real file store).
$pftSource = (string)file_get_contents($root . '/api/project_file_tools.php');
$from = strpos($pftSource, '/** [DATEI: pfad] (pure): the path, or null. */');
$to = strpos($pftSource, '/** The working state of a project called $name, or null. */');
slj_test($from !== false && $to !== false && $to > $from, 'marker parsers found in project_file_tools.php');
if (!defined('COREUI_PPROCS_INPUT_CHARS')) define('COREUI_PPROCS_INPUT_CHARS', 2000);
eval(substr($pftSource, $from, $to - $from));

/** In-memory journal: the statements console_task_journal.php sends, nothing else. */
final class SljStatement extends PDOStatement {
  private array $rows = [];
  private int $changed = 0;
  public function __construct(private SljPDO $db, private string $sql) {}
  public function execute(?array $params = null): bool {
    $p = $params ?? []; $this->rows = []; $this->changed = 0; $db = $this->db;
    if (str_contains($this->sql, 'INSERT IGNORE INTO stu_console_task_journal')) {
      [, $run] = $p;
      if (!isset($db->journal[$run])) {
        $db->journal[$run] = ['run_id'=>$run, 'turn_id'=>23, 'status'=>'running', 'phase'=>'prepare', 'checkpoint_version'=>0,
          'resume_checkpoint_version'=>null, 'last_step'=>0, 'last_verified_step'=>0, 'last_error_code'=>null,
          'requirements_json'=>'[]', 'evidence_json'=>'{}', 'updated_at'=>'now'];
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql, 'INSERT IGNORE INTO stu_console_task_actions')) {
      [$step, $tool, $sha, $run] = $p;
      if (!isset($db->actions[$run][$step])) {
        $db->actions[$run][$step] = ['tool_name'=>$tool, 'input_sha256'=>$sha, 'state'=>'claimed'];
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql, 'UPDATE stu_console_task_actions SET')) {
      [$state, , , , $run, , , $step, $toolSha] = $p;
      if (($db->actions[$run][$step]['state'] ?? '') === 'claimed' && $db->actions[$run][$step]['input_sha256'] === $toolSha) {
        $db->actions[$run][$step]['state'] = $state;
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql, 'UPDATE stu_console_task_journal SET phase=')) {
      [$step, $verified, , $run] = $p;
      $db->journal[$run]['last_step'] = max($db->journal[$run]['last_step'], $step);
      $db->journal[$run]['last_verified_step'] = max($db->journal[$run]['last_verified_step'], $verified);
      $db->journal[$run]['checkpoint_version']++;
      $this->changed = 1;
    } elseif (str_contains($this->sql, 'UPDATE stu_console_task_journal SET status=')) {
      [$status, , $error, , $run] = $p;
      $db->journal[$run]['status'] = $status;
      $db->journal[$run]['last_error_code'] = $error;
      $this->changed = 1;
    } elseif (str_contains($this->sql, 'SELECT COUNT(*) AS total')) {
      $rows = array_values($db->actions[$p[0]] ?? []);
      $this->rows = [['total'=>count($rows),
        'verified'=>count(array_filter($rows, static fn(array $r): bool => $r['state'] === 'verified')),
        'pending'=>count(array_filter($rows, static fn(array $r): bool => in_array($r['state'], ['claimed', 'uncertain'], true))),
        'research'=>count(array_filter($rows, static fn(array $r): bool
          => in_array($r['tool_name'], ['web', 'archive', 'skill', 'browse', 'python', 'image'], true)))]];
    } elseif (str_contains($this->sql, "state IN ('claimed','uncertain')")) {
      foreach ($db->actions[$p[0]] ?? [] as $n => $row) {
        if (in_array($row['state'], ['claimed', 'uncertain'], true)) { $this->rows = [['step_no'=>$n, 'state'=>$row['state'], 'tool_name'=>$row['tool_name']]]; break; }
      }
    } elseif (str_contains($this->sql, 'FROM stu_console_task_journal')) {
      if (isset($db->journal[$p[0]])) $this->rows = [$db->journal[$p[0]]];
    }
    return true;
  }
  public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
    return array_shift($this->rows) ?? false;
  }
  public function fetchColumn(int $column = 0): mixed { $r = array_shift($this->rows); return is_array($r) ? reset($r) : false; }
  public function rowCount(): int { return $this->changed; }
}
final class SljPDO extends PDO {
  public array $journal = [], $actions = [];
  private bool $tx = false;
  public function __construct() {}
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new SljStatement($this, $query); }
  public function prepare(string $query, array $options = []): PDOStatement|false { return new SljStatement($this, $query); }
  public function inTransaction(): bool { return $this->tx; }
  public function beginTransaction(): bool { $this->tx = true; return true; }
  public function commit(): bool { $this->tx = false; return true; }
  public function rollBack(): bool { $this->tx = false; return true; }
  public function exec(string $statement): int|false { return 1; }
}

/** Runs the loop with the real journal functions on the fake; returns the loop, the db and the events. */
function slj_run(string $goal, array $start, array $replies, callable $result, array $allowed): array {
  static $n = 0;
  $run = str_pad(dechex(++$n), 32, 'a', STR_PAD_LEFT);
  $db = new SljPDO();
  slj_test(coreui_task_journal_begin($db, 7, 'session_demo', $run, 23, 'chat'), 'journal begins');
  $db->journal[$run]['requirements_json'] = json_encode(coreui_task_journal_requirements_from_goal($goal));
  $events = [];
  $loop = coreui_agent_loop_run('x', $start, 'BASIS', $allowed, 0,
    static function (string $p) use (&$replies): ?string { return array_shift($replies); },
    $result,
    static function (string $k, string $s, string $t, array $m) use (&$events): void { $events[] = [$k, $s, $t, $m['reason'] ?? '']; },
    static fn(): bool => false, null, null, static fn(): array => [],
    static function (string $e, array $tool, int $step, ?string $res, ?string $state) use ($db, $run): mixed {
      if ($e === 'claim') return coreui_task_journal_claim($db, 7, 'session_demo', $run, $step, $tool);
      if ($e === 'can_finish') return coreui_task_journal_can_finish($db, 7, 'session_demo', $run);
      return coreui_task_journal_finish($db, 7, 'session_demo', $run, $step, $tool, (string)$state, (string)$res);
    }, static fn(string $t): string => $t);
  return ['loop'=>$loop, 'db'=>$db, 'run'=>$run, 'events'=>$events];
}
$project = ['pfile_read', 'pfile_edit', 'pfile_write', 'prun', 'pproc_start', 'pserver_start', 'pproc_output', 'pproc_input', 'pproc_stop', 'pserver_fetch'];
$done = static fn(string $t): string => "[COREUI_TASK_DONE]\n" . $t . "\n[/COREUI_TASK_DONE]";

// --- F1: an info step does not block DONE or a resume -------------------------------------------------
$r = slj_run('Pruefe app.js', ['tool'=>'pproc_stop', 'input'=>'beenden'],
  ['[AUSFUEHREN: node --check app.js]', $done('app.js ist geprueft, Exitcode 0.')],
  static fn(array $t): string => $t['tool'] === 'pproc_stop' ? 'Gerade laeuft kein Prozess in diesem Projekt; nichts beendet.'
    : "Befehl: node --check app.js\nBeendet mit Exitcode 0 in 0,2 s.\n(Keine Ausgabe.)", $project);
slj_test(($r['loop']['outcome'] ?? '') === 'completed' && $r['loop']['out'] === 'app.js ist geprueft, Exitcode 0.',
  'F1: pproc_stop (info) then prun (success) then DONE completes: ' . json_encode($r['loop']));
slj_test(($r['db']->actions[$r['run']][1]['state'] ?? '') === 'verified' && ($r['db']->actions[$r['run']][2]['state'] ?? '') === 'verified',
  'F1: a stop with nothing running is a known, verified receipt, not uncertain');
coreui_task_journal_sync($r['db'], 7, 'session_demo', $r['run'], 'interrupted', 'worker_lost');
slj_test((coreui_task_journal_resume_check($r['db'], 7, 'session_demo', $r['run'])['safe'] ?? false) === true,
  'F1: an earlier info step no longer forbids the resume');
// A server start whose HTTP check failed is an unconfirmed start (error), a later page check proves it.
$r = slj_run('Starte den Server', ['tool'=>'pserver_start', 'input'=>'8000 python -m http.server 8000'],
  ['[SERVER_ABRUF: /]', $done('Der Server antwortet mit HTTP 200.')],
  static fn(array $t): string => $t['tool'] === 'pserver_start'
    ? "Prozess: python -m http.server 8000 (Port 8000)\nLäuft.\n(Noch keine Ausgabe.)\nProzess-ID: 3\nHTTP-Pruefung GET / fehlgeschlagen (server_unreachable). Serverprozess nicht als HTTP-bereit bestaetigt; pruefe Ausgabe und Port."
    : "GET /: HTTP 200 (text/html)\n--- Inhalt ---\n<h1>ok</h1>", $project);
slj_test(($r['loop']['outcome'] ?? '') === 'completed' && ($r['db']->actions[$r['run']][1]['state'] ?? '') === 'error',
  'F1: an unconfirmed server start does not stay pending; the later HTTP 200 completes the run: ' . json_encode($r['loop']));
foreach ([['pfile_read', 'info', 'verified'], ['pproc_output', 'info', 'verified'], ['web', 'info', 'verified'],
    ['pproc_start', 'info', 'error'], ['prun', 'success', 'verified'], ['prun', 'error', 'error']] as [$kind, $state, $want]) {
  slj_test(coreui_task_journal_proof_state(['tool'=>$kind, 'input'=>'x'], $state, 'Ergebnis') === $want,
    "F1: journal state of {$kind}/{$state} is {$want}");
}

// --- F2: no impossible deliverables ---------------------------------------------------------------
foreach (['Baue eine Seite mit Node.js' => [], 'Was ist neu in Node.js 24? Bitte kurz recherchieren.' => [],
    'Erstelle eine Seite mit Three.js, Chart.js, Vue.js und Next.js in app.js' => ['app.js'],
    'Benenne app.js in main.js um' => ['main.js'], 'Loesche alt.html' => [], 'Lösche alt.html und erstelle neu.html' => ['neu.html'],
    'Bitte index.html und about.html erstellen.' => ['index.html', 'about.html'], 'Erklaere mir app.js' => []] as $goal => $want) {
  slj_test(array_column(coreui_task_journal_requirements_from_goal($goal), 'path') === $want,
    'F2: deliverables of "' . $goal . '": ' . json_encode(coreui_task_journal_requirements_from_goal($goal)));
}
// A plain web research in a chat with a workspace; an older requirement names "node.js".
$db = new SljPDO(); $run = str_repeat('b', 32);
coreui_task_journal_begin($db, 7, 'session_demo', $run, 23, 'chat');
$db->journal[$run]['requirements_json'] = json_encode([['kind'=>'file', 'path'=>'node.js', 'origin'=>'user_turn']]);
$web = ['tool'=>'web', 'input'=>'Node.js 24 neu'];
coreui_task_journal_claim($db, 7, 'session_demo', $run, 1, $web);
coreui_task_journal_finish($db, 7, 'session_demo', $run, 1, $web, 'success', "1. Node.js 24\n   https://nodejs.org");
slj_test(coreui_task_journal_can_finish($db, 7, 'session_demo', $run),
  'F2: a run that only searched needs no project files, even if an old requirement names one');
$read = ['tool'=>'pfile_read', 'input'=>'index.html'];
coreui_task_journal_claim($db, 7, 'session_demo', $run, 2, $read);
coreui_task_journal_finish($db, 7, 'session_demo', $run, 2, $read, 'success', 'Datei index.html');
slj_test(!coreui_task_journal_can_finish($db, 7, 'session_demo', $run),
  'F2: a project run still needs its named deliverable (node.js is missing)');
$db->journal[$run]['requirements_json'] = json_encode([['kind'=>'file', 'path'=>'Index.html', 'origin'=>'user_turn']]);
slj_test(coreui_task_journal_can_finish($db, 7, 'session_demo', $run), 'F2: "Index.html" in the request matches the saved index.html');

echo "CoreAI stabilize journal: info steps and impossible deliverables no longer block a finished run OK\n";
