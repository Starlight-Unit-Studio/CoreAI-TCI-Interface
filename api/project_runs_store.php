<?php
declare(strict_types=1);

require_once __DIR__ . '/project_files_store.php';

/*
 * 0.7.77 (P10 B): commands on the saved files of a project.
 *
 * A run is queued here and taken by the Python worker (tools/ember_project_runs.py), which runs
 * the command with "sh -c" in the sandbox container on a copy of the project's files. The row
 * keeps the real stdout, stderr, exit code and duration. One run at a time per project, at most
 * COREUI_PRUNS_PER_HOUR per account and hour, the last COREUI_PRUNS_KEEP runs per project stay.
 * Runs count as the Python tool: where Python is off, there are no runs.
 */

const COREUI_PRUNS_COMMAND_CHARS = 400;
const COREUI_PRUNS_PER_HOUR = 60;
const COREUI_PRUNS_KEEP = 20;
const COREUI_PRUNS_RUN_SECONDS = 120;
const COREUI_PRUNS_QUEUE_SECONDS = 600;

function coreui_pruns_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT id, cancel_requested_at, files_sha256 FROM stu_console_project_runs LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** A command as it may run (pure): one line, 1 to 400 characters, no control characters. */
function coreui_pruns_command($value): string {
  $command = trim((string)$value);
  if ($command === '' || preg_match('//u', $command) !== 1 || mb_strlen($command, 'UTF-8') > COREUI_PRUNS_COMMAND_CHARS
      || preg_match('~[\x00-\x08\x0A-\x1F\x7F]~', $command) === 1) {
    throw new InvalidArgumentException('invalid_command');
  }
  return str_replace("\t", ' ', $command);
}

/**
 * 0.7.79: what a package.json says (pure): its scripts and whether it has packages to install.
 * Anything that is not a JSON object counts as an empty package.
 */
function coreui_pruns_package(?string $json): array {
  $data = $json === null ? null : json_decode($json, true);
  if (!is_array($data)) return ['scripts'=>[], 'deps'=>[]];
  $scripts = [];
  foreach ((array)($data['scripts'] ?? []) as $name => $script) {
    if (is_string($name) && is_string($script) && trim($script) !== '') $scripts[$name] = $script;
  }
  // "npm init" writes a test script that only fails; it is no test.
  if (isset($scripts['test']) && str_contains($scripts['test'], 'no test specified')) unset($scripts['test']);
  $deps = [];
  foreach (['dependencies', 'devDependencies'] as $key) {
    foreach ((array)($data[$key] ?? []) as $name => $version) if (is_string($name)) $deps[] = $name;
  }
  return ['scripts'=>$scripts, 'deps'=>$deps];
}

/**
 * A command that fits the project (pure): its tests, else its main program, else ''.
 * The sandbox has Python 3.12 and, since 0.7.79, Node.js 24 with npm; "python -m" puts the
 * project folder on the import path, packages of a package.json are installed first.
 */
function coreui_pruns_suggest(array $paths, ?string $packageJson = null): string {
  $tests = array_values(array_filter($paths, static fn($p): bool => preg_match('~(^|/)(test_[^/]*|[^/]*_test)\.py$~', (string)$p) === 1));
  if ($tests !== []) {
    $inTests = array_filter($tests, static fn($p): bool => str_starts_with((string)$p, 'tests/'));
    return count($inTests) === count($tests) ? 'python -m unittest discover -v -s tests' : 'python -m unittest -v';
  }
  $package = coreui_pruns_package(in_array('package.json', $paths, true) ? $packageJson : null);
  $install = $package['deps'] !== [] ? 'npm install && ' : '';
  if (isset($package['scripts']['test'])) return $install . 'npm test';
  if (array_filter($paths, static fn($p): bool => preg_match('~((^|/)[^/]+\.test|^tests?/[^/]+)\.[cm]?js$~', (string)$p) === 1) !== []) {
    return 'node --test';
  }
  if (in_array('main.py', $paths, true)) return 'python main.py';
  $python = array_values(array_filter($paths, static fn($p): bool => preg_match('~^[^/]+\.py$~', (string)$p) === 1));
  sort($python);
  if ($python !== []) return 'python ' . $python[0];
  foreach (['index.js', 'main.js'] as $name) if (in_array($name, $paths, true)) return 'node ' . $name;
  return '';
}

function coreui_pruns_public(array $row, bool $output = true): array {
  $out = [
    'id' => (int)$row['id'],
    'command' => (string)$row['command'],
    'status' => (string)$row['status'],
    'exit_code' => $row['exit_code'] === null ? null : (int)$row['exit_code'],
    'error' => $row['error'] === null ? null : (string)$row['error'],
    'duration_ms' => $row['duration_ms'] === null ? null : (int)$row['duration_ms'],
    'files_count' => $row['files_count'] === null ? null : (int)$row['files_count'],
    'from_chat' => (string)($row['session_id'] ?? '') !== '',
    'created_at' => (string)$row['created_at'],
    'finished_at' => $row['finished_at'] === null ? null : (string)$row['finished_at'],
    'cancel_requested' => !empty($row['cancel_requested_at']),
  ];
  if ($output) {
    $out['stdout'] = (string)($row['stdout'] ?? '');
    $out['stderr'] = (string)($row['stderr'] ?? '');
  }
  return $out;
}

function coreui_pruns_active(string $status): bool {
  return $status === 'queued' || $status === 'running';
}

/** The database's own clock as a UNIX time, so ages compare in one time zone. */
function coreui_pruns_now(PDO $pdo): int {
  return (int)strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());
}

/** Runs whose worker is gone end as an error instead of "running" for ever. */
function coreui_pruns_reap(PDO $pdo, int $uid, string $projectId): void {
  $st = $pdo->prepare("SELECT id, status, created_at, started_at FROM stu_console_project_runs
    WHERE user_id=? AND project_id=? AND status IN ('queued','running')");
  $st->execute([$uid, $projectId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) return;
  $now = coreui_pruns_now($pdo);
  $end = $pdo->prepare("UPDATE stu_console_project_runs SET status='error', error=?, finished_at=NOW()
    WHERE id=? AND status=?");
  foreach ($rows as $row) {
    if ($row['status'] === 'running' && $now - (int)strtotime((string)$row['started_at']) > COREUI_PRUNS_RUN_SECONDS + 120) {
      $end->execute(['worker_lost', $row['id'], 'running']);
    } elseif ($row['status'] === 'queued' && $now - (int)strtotime((string)$row['created_at']) > COREUI_PRUNS_QUEUE_SECONDS) {
      $end->execute(['no_worker', $row['id'], 'queued']);
    }
  }
}

function coreui_pruns_enqueue(PDO $pdo, int $uid, string $projectId, $command, ?string $sessionId = null): array {
  if (!coreui_pruns_ready($pdo)) throw new RuntimeException('project_runs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $command = coreui_pruns_command($command);
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_files WHERE project_id=? AND user_id=?');
  $st->execute([$project['id'], $uid]);
  if ((int)$st->fetchColumn() === 0) throw new RuntimeException('no_files');
  coreui_pruns_reap($pdo, $uid, $project['id']);
  $st = $pdo->prepare("SELECT COUNT(*) FROM stu_console_project_runs WHERE user_id=? AND project_id=? AND status IN ('queued','running')");
  $st->execute([$uid, $project['id']]);
  if ((int)$st->fetchColumn() > 0) throw new RuntimeException('run_busy');
  $st = $pdo->prepare('SELECT created_at FROM stu_console_project_runs WHERE user_id=? ORDER BY id DESC LIMIT ' . COREUI_PRUNS_PER_HOUR);
  $st->execute([$uid]);
  $times = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  if (count($times) >= COREUI_PRUNS_PER_HOUR && coreui_pruns_now($pdo) - (int)strtotime((string)end($times)) < 3600) {
    throw new RuntimeException('run_limit');
  }
  $pdo->prepare("INSERT INTO stu_console_project_runs (user_id, project_id, session_id, command, status, created_at)
    VALUES (?, ?, ?, ?, 'queued', NOW())")->execute([$uid, $project['id'], $sessionId !== '' ? $sessionId : null, $command]);
  $id = (int)$pdo->lastInsertId();
  // Only the latest runs of a project stay.
  $st = $pdo->prepare('SELECT id FROM stu_console_project_runs WHERE user_id=? AND project_id=? ORDER BY id DESC LIMIT 1000 OFFSET ' . COREUI_PRUNS_KEEP);
  $st->execute([$uid, $project['id']]);
  $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  if ($old !== []) {
    $pdo->prepare("DELETE FROM stu_console_project_runs WHERE user_id=? AND id IN (" . implode(',', array_fill(0, count($old), '?')) . ")
      AND status NOT IN ('queued','running')")->execute([$uid, ...$old]);
  }
  return coreui_pruns_get($pdo, $uid, $project['id'], $id);
}

function coreui_pruns_row(PDO $pdo, int $uid, string $projectId, int $runId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_runs WHERE id=? AND user_id=? AND project_id=?');
  $st->execute([$runId, $uid, $projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('run_not_found');
  return $row;
}

function coreui_pruns_get(PDO $pdo, int $uid, string $projectId, int $runId): array {
  if (!coreui_pruns_ready($pdo)) throw new RuntimeException('project_runs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pruns_reap($pdo, $uid, $project['id']);
  return coreui_pruns_public(coreui_pruns_row($pdo, $uid, $project['id'], $runId));
}

function coreui_pruns_list(PDO $pdo, int $uid, string $projectId, int $limit = 10): array {
  if (!coreui_pruns_ready($pdo)) throw new RuntimeException('project_runs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pruns_reap($pdo, $uid, $project['id']);
  $st = $pdo->prepare('SELECT * FROM stu_console_project_runs WHERE user_id=? AND project_id=? ORDER BY id DESC LIMIT ' . max(1, min(COREUI_PRUNS_KEEP, $limit)));
  $st->execute([$uid, $project['id']]);
  return array_map(static fn(array $row): array => coreui_pruns_public($row, false), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** A queued run ends at once; a running one is stopped by the worker within about a second. */
function coreui_pruns_cancel(PDO $pdo, int $uid, string $projectId, int $runId): array {
  if (!coreui_pruns_ready($pdo)) throw new RuntimeException('project_runs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $row = coreui_pruns_row($pdo, $uid, $project['id'], $runId);
  if ($row['status'] === 'queued') {
    $pdo->prepare("UPDATE stu_console_project_runs SET status='cancelled', error='cancelled', cancel_requested_at=NOW(), finished_at=NOW()
      WHERE id=? AND status='queued'")->execute([$runId]);
  } elseif ($row['status'] === 'running') {
    $pdo->prepare("UPDATE stu_console_project_runs SET cancel_requested_at=NOW() WHERE id=? AND status='running'")->execute([$runId]);
  }
  return coreui_pruns_public(coreui_pruns_row($pdo, $uid, $project['id'], $runId));
}

/** Waits for a run (Mara's tool). $sleep is replaceable in tests. */
function coreui_pruns_wait(PDO $pdo, int $uid, string $projectId, int $runId, int $seconds, ?callable $sleep = null): array {
  $sleep = $sleep ?? static function (): void { usleep(500000); };
  $deadline = microtime(true) + max(1, $seconds);
  do {
    $run = coreui_pruns_get($pdo, $uid, $projectId, $runId);
    if (!coreui_pruns_active($run['status'])) return $run;
    $sleep();
  } while (microtime(true) < $deadline);
  return coreui_pruns_get($pdo, $uid, $projectId, $runId);
}

/** What a finished or waiting run means, as German sentences (pure). */
function coreui_pruns_summary(array $run): string {
  $seconds = $run['duration_ms'] === null ? '' : ' in ' . str_replace('.', ',', (string)round($run['duration_ms'] / 1000, 1)) . ' s';
  switch ($run['status']) {
    case 'queued': return 'Wartet auf den Sandbox-Dienst.';
    case 'running': return 'Läuft.';
    case 'cancelled': return 'Abgebrochen' . $seconds . '.';
    case 'done': return 'Beendet mit Exitcode ' . (int)$run['exit_code'] . $seconds . '.';
  }
  return [
    'timeout' => 'Nach ' . COREUI_PRUNS_RUN_SECONDS . ' s abgebrochen (Zeitlimit).',
    'no_worker' => 'Der Sandbox-Dienst hat den Lauf nicht übernommen. Läuft der Python-Dienst?',
    'worker_lost' => 'Der Sandbox-Dienst hat sich während des Laufs nicht mehr gemeldet.',
    'docker_missing' => 'Docker ist auf dem Server nicht erreichbar.',
    'no_files' => 'Das Projekt hat keine Dateien.',
    'unsafe_path' => 'Ein Dateipfad im Projekt wurde abgelehnt.',
    'sandbox_stop_unconfirmed' => 'Der Sandbox-Container ließ sich nicht sicher beenden.',
  ][(string)$run['error']] ?? 'Fehler: ' . (string)$run['error'] . '.';
}
