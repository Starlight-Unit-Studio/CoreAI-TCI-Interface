<?php
declare(strict_types=1);

require_once __DIR__ . '/project_files_store.php';
require_once __DIR__ . '/project_runs_store.php';
require_once __DIR__ . '/project_preview_store.php';

/*
 * 0.7.78 (P10 C): processes of a project that keep running (a program, a shell or a
 * development server) in the sandbox container of the Python worker.
 *
 * A process is queued here and started by the worker (tools/ember_project_procs.py). The row
 * keeps the latest output and its end position, so the browser only fetches what is new. Input
 * lines and requests of the preview to a development server inside the container wait in their
 * own tables. One process per project, COREUI_PPROCS_ACTIVE at once per account, at most
 * COREUI_PPROCS_PER_HOUR starts per account and hour, the last COREUI_PPROCS_KEEP per project
 * stay. Processes count as the Python tool, like the runs of P10 B.
 */

const COREUI_PPROCS_PER_HOUR = 30;
const COREUI_PPROCS_ACTIVE = 2;
const COREUI_PPROCS_KEEP = 10;
const COREUI_PPROCS_INPUT_CHARS = 2000;
const COREUI_PPROCS_INPUT_PENDING = 50;
const COREUI_PPROCS_QUEUE_SECONDS = 60;
const COREUI_PPROCS_BEAT_SECONDS = 30;
const COREUI_PPROCS_FETCH_WAIT = 15;
const COREUI_PPROCS_FETCH_BODY = 1048576;
const COREUI_PPROCS_FETCH_PENDING = 20;
const COREUI_PPROCS_PAGE_REFS = 16;
const COREUI_PPROCS_PAGE_BYTES = 6291456;
const COREUI_PPROCS_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

function coreui_pprocs_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT id, output_end, stop_requested_at FROM stu_console_project_procs LIMIT 0');
      $pdo->query('SELECT id, sent_at FROM stu_console_project_proc_input LIMIT 0');
      $pdo->query('SELECT id, response_path, response_body FROM stu_console_project_proc_fetch LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** The port of a development server (pure): null for none, else 1024 to 65535. */
function coreui_pprocs_port($value): ?int {
  if ($value === null || $value === '' || $value === false) return null;
  if (is_string($value) && preg_match('~^\s*\d{1,5}\s*$~', $value) === 1) $value = (int)trim($value);
  if (!is_int($value) || $value < 1024 || $value > 65535) throw new InvalidArgumentException('invalid_port');
  return $value;
}

/** A request path on the development server (pure): absolute, printable ASCII, 1000 characters. */
function coreui_pprocs_path($value): string {
  $path = (string)$value;
  if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || strlen($path) > 1000
      || preg_match('~^/[\x21-\x7e]*$~D', $path) !== 1) {
    throw new InvalidArgumentException('invalid_server_path');
  }
  return $path;
}

/**
 * What the browser has not seen yet (pure). $output holds the last characters up to $end; $since
 * is the end the browser knows. Returns the text from there, where it starts, and whether
 * something in between is no longer kept.
 */
function coreui_pprocs_slice(string $output, int $end, int $since): array {
  $start = max(0, $end - mb_strlen($output, 'UTF-8'));
  if ($since >= $end) return ['text'=>'', 'from'=>$end, 'cut'=>false];
  if ($since < $start) return ['text'=>$output, 'from'=>$start, 'cut'=>$since < $start && $start > 0];
  return ['text'=>mb_substr($output, $since - $start, null, 'UTF-8'), 'from'=>$since, 'cut'=>false];
}

/** A command and port that fit the project (pure): a web server for pages, else the program. */
function coreui_pprocs_suggest(array $paths): array {
  $python = array_values(array_filter($paths, static fn($p): bool => preg_match('~^[^/]+\.py$~', (string)$p) === 1));
  foreach (['app.py', 'server.py', 'main.py'] as $name) {
    if (in_array($name, $python, true)) return ['command'=>'python ' . $name, 'port'=>null];
  }
  $pages = array_filter($paths, static fn($p): bool => coreui_preview_is_page((string)$p));
  if ($pages !== []) return ['command'=>'python -m http.server 8000', 'port'=>8000];
  sort($python);
  return ['command'=>$python !== [] ? 'python ' . $python[0] : '', 'port'=>null];
}

function coreui_pprocs_active(string $status): bool {
  return $status === 'queued' || $status === 'running';
}

function coreui_pprocs_public(array $row, ?int $since = null): array {
  $out = [
    'id' => (int)$row['id'],
    'command' => (string)$row['command'],
    'port' => $row['port'] === null ? null : (int)$row['port'],
    'status' => (string)$row['status'],
    'exit_code' => $row['exit_code'] === null ? null : (int)$row['exit_code'],
    'error' => $row['error'] === null ? null : (string)$row['error'],
    'files_count' => $row['files_count'] === null ? null : (int)$row['files_count'],
    'created_at' => (string)$row['created_at'],
    'started_at' => $row['started_at'] === null ? null : (string)$row['started_at'],
    'finished_at' => $row['finished_at'] === null ? null : (string)$row['finished_at'],
    'stop_requested' => !empty($row['stop_requested_at']),
    'output_end' => (int)$row['output_end'],
  ];
  if ($since !== null) {
    $slice = coreui_pprocs_slice((string)($row['output'] ?? ''), (int)$row['output_end'], max(0, $since));
    $out['output'] = $slice['text'];
    $out['output_from'] = $slice['from'];
    $out['output_cut'] = $slice['cut'];
  }
  return $out;
}

/** Processes whose worker is gone end as an error instead of "running" for ever. */
function coreui_pprocs_reap(PDO $pdo, int $uid, ?string $projectId = null): void {
  $sql = "SELECT id, status, created_at, beat_at FROM stu_console_project_procs WHERE user_id=? AND status IN ('queued','running')";
  $args = [$uid];
  if ($projectId !== null) { $sql .= ' AND project_id=?'; $args[] = $projectId; }
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) return;
  $now = coreui_pruns_now($pdo);
  $end = $pdo->prepare("UPDATE stu_console_project_procs SET status='error', error=?, finished_at=NOW() WHERE id=? AND status=?");
  foreach ($rows as $row) {
    if ($row['status'] === 'running' && $now - (int)strtotime((string)($row['beat_at'] ?? $row['created_at'])) > COREUI_PPROCS_BEAT_SECONDS) {
      $end->execute(['worker_lost', $row['id'], 'running']);
    } elseif ($row['status'] === 'queued' && $now - (int)strtotime((string)$row['created_at']) > COREUI_PPROCS_QUEUE_SECONDS) {
      $end->execute(['no_worker', $row['id'], 'queued']);
    }
  }
}

function coreui_pprocs_start(PDO $pdo, int $uid, string $projectId, $command, $port = null): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $command = coreui_pruns_command($command);
  $port = coreui_pprocs_port($port);
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_files WHERE project_id=? AND user_id=?');
  $st->execute([$project['id'], $uid]);
  if ((int)$st->fetchColumn() === 0) throw new RuntimeException('no_files');
  coreui_pprocs_reap($pdo, $uid);
  $st = $pdo->prepare("SELECT project_id FROM stu_console_project_procs WHERE user_id=? AND status IN ('queued','running')");
  $st->execute([$uid]);
  $active = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  if (in_array($project['id'], $active, true)) throw new RuntimeException('proc_busy');
  if (count($active) >= COREUI_PPROCS_ACTIVE) throw new RuntimeException('proc_active_limit');
  $st = $pdo->prepare('SELECT created_at FROM stu_console_project_procs WHERE user_id=? ORDER BY id DESC LIMIT ' . COREUI_PPROCS_PER_HOUR);
  $st->execute([$uid]);
  $times = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  if (count($times) >= COREUI_PPROCS_PER_HOUR && coreui_pruns_now($pdo) - (int)strtotime((string)end($times)) < 3600) {
    throw new RuntimeException('proc_limit');
  }
  $pdo->prepare("INSERT INTO stu_console_project_procs (user_id, project_id, command, port, status, output, output_end, seen_at, created_at)
    VALUES (?, ?, ?, ?, 'queued', '', 0, NOW(), NOW())")->execute([$uid, $project['id'], $command, $port]);
  $id = (int)$pdo->lastInsertId();
  $st = $pdo->prepare('SELECT id FROM stu_console_project_procs WHERE user_id=? AND project_id=? ORDER BY id DESC LIMIT 1000 OFFSET ' . COREUI_PPROCS_KEEP);
  $st->execute([$uid, $project['id']]);
  $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  if ($old !== []) {
    $marks = implode(',', array_fill(0, count($old), '?'));
    $pdo->prepare("DELETE FROM stu_console_project_proc_input WHERE proc_id IN ($marks)")->execute($old);
    $pdo->prepare("DELETE FROM stu_console_project_proc_fetch WHERE proc_id IN ($marks)")->execute($old);
    $pdo->prepare("DELETE FROM stu_console_project_procs WHERE user_id=? AND id IN ($marks) AND status NOT IN ('queued','running')")
      ->execute([$uid, ...$old]);
  }
  return coreui_pprocs_public(coreui_pprocs_row($pdo, $uid, $project['id'], $id), 0);
}

function coreui_pprocs_row(PDO $pdo, int $uid, string $projectId, int $procId): array {
  $st = $pdo->prepare('SELECT * FROM stu_console_project_procs WHERE id=? AND user_id=? AND project_id=?');
  $st->execute([$procId, $uid, $projectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('proc_not_found');
  return $row;
}

/** One process with its output since $since. Looking at a running process keeps it alive. */
function coreui_pprocs_get(PDO $pdo, int $uid, string $projectId, int $procId, int $since = 0): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pprocs_reap($pdo, $uid, $project['id']);
  $row = coreui_pprocs_row($pdo, $uid, $project['id'], $procId);
  if (coreui_pprocs_active((string)$row['status'])) {
    $pdo->prepare('UPDATE stu_console_project_procs SET seen_at=NOW() WHERE id=?')->execute([$procId]);
  }
  return coreui_pprocs_public($row, $since);
}

function coreui_pprocs_list(PDO $pdo, int $uid, string $projectId): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  coreui_pprocs_reap($pdo, $uid, $project['id']);
  $st = $pdo->prepare('SELECT * FROM stu_console_project_procs WHERE user_id=? AND project_id=? ORDER BY id DESC LIMIT ' . COREUI_PPROCS_KEEP);
  $st->execute([$uid, $project['id']]);
  return array_map(static fn(array $row): array => coreui_pprocs_public($row), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** A line (or text) for the program's stdin; $newline adds the Enter key. */
function coreui_pprocs_input(PDO $pdo, int $uid, string $projectId, int $procId, $text, bool $newline = true): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  if (!is_string($text) || preg_match('//u', $text) !== 1 || str_contains($text, "\0")
      || mb_strlen($text, 'UTF-8') > COREUI_PPROCS_INPUT_CHARS || ($text === '' && !$newline)) {
    throw new InvalidArgumentException('invalid_input');
  }
  coreui_pprocs_reap($pdo, $uid, $project['id']);
  $row = coreui_pprocs_row($pdo, $uid, $project['id'], $procId);
  if ($row['status'] !== 'running') throw new RuntimeException('proc_not_running');
  $st = $pdo->prepare('SELECT COUNT(*) FROM stu_console_project_proc_input WHERE proc_id=? AND sent_at IS NULL');
  $st->execute([$procId]);
  if ((int)$st->fetchColumn() >= COREUI_PPROCS_INPUT_PENDING) throw new RuntimeException('input_busy');
  $pdo->prepare('INSERT INTO stu_console_project_proc_input (proc_id, data, created_at) VALUES (?, ?, NOW())')
    ->execute([$procId, $text . ($newline ? "\n" : '')]);
  $pdo->prepare('UPDATE stu_console_project_procs SET seen_at=NOW() WHERE id=?')->execute([$procId]);
  return coreui_pprocs_public(coreui_pprocs_row($pdo, $uid, $project['id'], $procId));
}

/** A queued process ends at once; a running one gets SIGTERM from the worker, SIGKILL later. */
function coreui_pprocs_stop(PDO $pdo, int $uid, string $projectId, int $procId): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $row = coreui_pprocs_row($pdo, $uid, $project['id'], $procId);
  if ($row['status'] === 'queued') {
    $pdo->prepare("UPDATE stu_console_project_procs SET status='stopped', stop_requested_at=NOW(), finished_at=NOW()
      WHERE id=? AND status='queued'")->execute([$procId]);
  } elseif ($row['status'] === 'running') {
    $pdo->prepare("UPDATE stu_console_project_procs SET stop_requested_at=NOW() WHERE id=? AND status='running'")->execute([$procId]);
  }
  return coreui_pprocs_public(coreui_pprocs_row($pdo, $uid, $project['id'], $procId));
}

/**
 * Requests to the development server of a running process, answered by the worker from inside
 * the container. $requests: list of [method, path, body or null, content type]. All are queued at
 * once and answered within COREUI_PPROCS_FETCH_WAIT seconds; the result has one entry per request
 * in the same order: an answer (status, type, path after redirects, body bytes, truncated) or an
 * error code string. $sleep is replaceable in tests.
 */
function coreui_pprocs_fetch_many(PDO $pdo, int $uid, string $projectId, int $procId, array $requests, ?callable $sleep = null): array {
  if (!coreui_pprocs_ready($pdo)) throw new RuntimeException('project_procs_migration_required');
  $project = coreui_pfiles_require($pdo, $uid, $projectId);
  $clean = [];
  foreach ($requests as $request) {
    [$method, $path, $body, $type] = array_pad(array_values((array)$request), 4, null);
    $method = strtoupper((string)$method);
    if (!in_array($method, COREUI_PPROCS_METHODS, true)) throw new InvalidArgumentException('invalid_method');
    $path = coreui_pprocs_path($path);
    if ($body !== null && (!is_string($body) || strlen($body) > COREUI_PPROCS_FETCH_BODY)) throw new InvalidArgumentException('body_too_large');
    $type = substr(trim((string)$type), 0, 160);
    if (preg_match('~[\x00-\x1f\x7f]~', $type) === 1) throw new InvalidArgumentException('invalid_type');
    $clean[] = [$method, $path, $body, $type];
  }
  if ($clean === []) return [];
  coreui_pprocs_reap($pdo, $uid, $project['id']);
  $row = coreui_pprocs_row($pdo, $uid, $project['id'], $procId);
  if ($row['status'] !== 'running') throw new RuntimeException('proc_not_running');
  if ($row['port'] === null) throw new RuntimeException('proc_no_port');
  // Answers older than two minutes are no longer needed (the database's clock, as everywhere here).
  $pdo->prepare("DELETE FROM stu_console_project_proc_fetch WHERE proc_id=? AND status<>'queued' AND finished_at < ?")
    ->execute([$procId, date('Y-m-d H:i:s', coreui_pruns_now($pdo) - 120)]);
  $st = $pdo->prepare("SELECT COUNT(*) FROM stu_console_project_proc_fetch WHERE proc_id=? AND status='queued'");
  $st->execute([$procId]);
  if ((int)$st->fetchColumn() + count($clean) > COREUI_PPROCS_FETCH_PENDING) throw new RuntimeException('fetch_busy');
  $insert = $pdo->prepare("INSERT INTO stu_console_project_proc_fetch (proc_id, method, path, content_type, body, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'queued', NOW())");
  $ids = [];
  foreach ($clean as [$method, $path, $body, $type]) {
    $insert->bindValue(1, $procId, PDO::PARAM_INT);
    $insert->bindValue(2, $method);
    $insert->bindValue(3, $path);
    $insert->bindValue(4, $type !== '' ? $type : null, $type !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $insert->bindValue(5, $body, $body === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $insert->execute();
    $ids[] = (int)$pdo->lastInsertId();
  }
  $pdo->prepare('UPDATE stu_console_project_procs SET seen_at=NOW() WHERE id=?')->execute([$procId]);
  $sleep = $sleep ?? static function (): void { usleep(100000); };
  $deadline = microtime(true) + COREUI_PPROCS_FETCH_WAIT;
  $read = $pdo->prepare('SELECT id, status, response_status, response_type, response_path, response_body, error
    FROM stu_console_project_proc_fetch WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
  while (true) {
    $read->execute($ids);
    $answers = [];
    foreach ($read->fetchAll(PDO::FETCH_ASSOC) ?: [] as $answer) $answers[(int)$answer['id']] = $answer;
    $read->closeCursor();
    $waiting = array_filter($answers, static fn(array $a): bool => $a['status'] === 'queued');
    if ($waiting === [] || microtime(true) >= $deadline) break;
    $sleep();
  }
  $out = [];
  foreach ($ids as $i => $id) {
    $answer = $answers[$id] ?? null;
    if ($answer === null) { $out[] = 'proc_not_running'; continue; }
    if ($answer['status'] === 'queued') {
      $pdo->prepare("UPDATE stu_console_project_proc_fetch SET status='error', error='timeout', finished_at=NOW() WHERE id=? AND status='queued'")
        ->execute([$id]);
      $out[] = 'fetch_timeout';
      continue;
    }
    if ($answer['status'] === 'error') {
      $error = (string)$answer['error'];
      $out[] = $error === 'connect' ? 'server_unreachable' : ($error === 'not_running' ? 'proc_not_running' : 'fetch_failed');
      continue;
    }
    $content = $answer['response_body'];
    if (is_resource($content)) $content = stream_get_contents($content);
    $out[] = ['status'=>(int)$answer['response_status'], 'type'=>(string)($answer['response_type'] ?? ''),
      'path'=>(string)($answer['response_path'] ?? $clean[$i][1]), 'body'=>(string)($content ?? ''),
      'truncated'=>$answer['error'] === 'truncated'];
  }
  return $out;
}

/** One request to the development server (coreui_pprocs_fetch_many); an error is thrown. */
function coreui_pprocs_fetch(PDO $pdo, int $uid, string $projectId, int $procId, string $method, $path, ?string $body = null,
    string $type = '', ?callable $sleep = null): array {
  $answer = coreui_pprocs_fetch_many($pdo, $uid, $projectId, $procId, [[$method, $path, $body, $type]], $sleep)[0];
  if (is_string($answer)) throw new RuntimeException($answer);
  return $answer;
}

/** The key of a server preview (pure): random letters from the browser, or '' when it is not one. */
function coreui_pprocs_key($value): string {
  $key = (string)$value;
  return preg_match('~^[A-Za-z0-9_-]{16,64}$~D', $key) === 1 ? $key : '';
}

/** The key a server path has in the page builder's file map (pure): "/" and "/a/" end in index.html. */
function coreui_pprocs_page_key(string $serverPath): string {
  $path = rawurldecode((string)preg_replace('~[?#].*$~s', '', $serverPath));
  $path = ltrim($path, '/');
  return ($path === '' || str_ends_with($path, '/')) ? $path . 'index.html' : $path;
}

/** Whether an answer is text that can be shown as such (pure). */
function coreui_pprocs_is_text(string $type): bool {
  return preg_match('~^(text/|application/(json|javascript|xml|[a-z.+-]*\+(json|xml))|image/svg\+xml)~i', trim($type)) === 1;
}

/**
 * The preview page of a development server (server mode of coreui_preview_build): the page and
 * the stylesheets, scripts and pictures it refers to on the same server. $fetch is
 * fn(string $path): array (coreui_pprocs_fetch with GET), replaceable in tests.
 */
function coreui_pprocs_page(callable $fetch, string $path, string $pageUrl, ?callable $fetchMany = null, string $key = ''): string {
  $answer = $fetch($path);
  $final = (string)($answer['path'] ?: $path);
  $type = strtolower((string)$answer['type']);
  if (!str_contains($type, 'html')) {
    $label = 'Antwort ' . (int)$answer['status'] . ($type !== '' ? ' · ' . $type : '') . ' · ' . $final;
    $text = coreui_pprocs_is_text($type) ? (string)$answer['body'] : 'Keine Textantwort (' . strlen((string)$answer['body']) . ' Bytes).';
    if (preg_match('//u', $text) !== 1) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vorschau</title></head>'
      . '<body style="font:14px/1.5 system-ui,sans-serif;margin:16px;color:#222">'
      . '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong></p>'
      . '<pre style="white-space:pre-wrap;word-break:break-word">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></body></html>';
  }
  $pageKey = coreui_pprocs_page_key($final);
  $html = (string)$answer['body'];
  $files = [$pageKey => $html];
  $wanted = [];
  if (preg_match_all('~<link\b[^>]*>|<script\b[^>]*>|<(?:img|image|source|use)\b[^>]*>~i', $html, $m)) {
    foreach ($m[0] as $tag) {
      $a = coreui_preview_attrs($tag);
      $lower = strtolower($tag);
      if (str_starts_with($lower, '<link')) {
        if (preg_match('~(^|\s)stylesheet(\s|$)~i', $a['rel'] ?? '') !== 1) continue;
        $refs = [$a['href'] ?? ''];
      } elseif (str_starts_with($lower, '<script')) {
        $refs = [$a['src'] ?? ''];
      } else {
        $refs = [$a['src'] ?? '', $a['href'] ?? '', $a['xlink:href'] ?? ''];
      }
      foreach ($refs as $ref) {
        $ref = (string)$ref;
        if ($ref === '') continue;
        $target = coreui_preview_ref($pageKey, $ref);
        if ($target !== null && $target !== $pageKey) $wanted[$target] = true;
      }
    }
  }
  $targets = array_slice(array_keys($wanted), 0, COREUI_PPROCS_PAGE_REFS);
  $paths = array_map(static fn(string $target): string => '/' . implode('/', array_map('rawurlencode', explode('/', $target))), $targets);
  // All references at once when the caller can (the worker answers several per round).
  if ($fetchMany !== null && $paths !== []) {
    try {
      $answers = $fetchMany($paths);
    } catch (Throwable $e) {
      $answers = [];
    }
  } else {
    $answers = [];
    foreach ($paths as $one) {
      try {
        $answers[] = $fetch($one);
      } catch (Throwable $e) {
        $answers[] = 'fetch_failed';
      }
    }
  }
  $bytes = strlen($html);
  foreach ($targets as $i => $target) {
    $ref = $answers[$i] ?? null;
    if (!is_array($ref) || (int)$ref['status'] !== 200 || $bytes + strlen((string)$ref['body']) > COREUI_PPROCS_PAGE_BYTES) continue;
    $bytes += strlen((string)$ref['body']);
    $files[$target] = (string)$ref['body'];
  }
  return coreui_preview_build($files, $pageKey, $pageUrl, $final, $key);
}

/** The answer of a request for the page's fetch() as JSON fields (pure): text or base64. */
function coreui_pprocs_fetch_json(array $answer): array {
  $type = (string)$answer['type'];
  $body = (string)$answer['body'];
  $text = coreui_pprocs_is_text($type) && preg_match('//u', $body) === 1;
  return ['status'=>(int)$answer['status'], 'type'=>$type, 'path'=>(string)$answer['path'],
    'body'=>$text ? $body : base64_encode($body), 'base64'=>!$text, 'truncated'=>!empty($answer['truncated'])];
}

/** What the state of a process means, as a German sentence (pure). */
function coreui_pprocs_summary(array $proc): string {
  switch ($proc['status']) {
    case 'queued': return 'Wartet auf den Sandbox-Dienst.';
    case 'running': return $proc['stop_requested'] ? 'Wird beendet.' : 'Läuft.';
    case 'exited': return 'Beendet mit Exitcode ' . (int)$proc['exit_code'] . '.';
    case 'stopped':
      $code = $proc['exit_code'] === null ? '' : ' (Exitcode ' . (int)$proc['exit_code'] . ')';
      return [
        'time_limit' => 'Nach der längsten erlaubten Laufzeit beendet' . $code . '.',
        'idle' => 'Beendet, weil niemand mehr hingesehen hat' . $code . '.',
        'worker_stopped' => 'Beendet, weil der Sandbox-Dienst neu gestartet wurde' . $code . '.',
      ][(string)$proc['error']] ?? 'Beendet' . $code . '.';
  }
  return [
    'no_worker' => 'Der Sandbox-Dienst hat den Prozess nicht übernommen. Läuft der Python-Dienst?',
    'worker_lost' => 'Der Sandbox-Dienst hat sich nicht mehr gemeldet.',
    'worker_restart' => 'Der Sandbox-Dienst wurde neu gestartet; der Prozess lief dabei nicht weiter.',
    'docker_missing' => 'Docker ist auf dem Server nicht erreichbar.',
    'docker_runtime_error' => 'Der Sandbox-Container ließ sich nicht starten.',
    'no_files' => 'Das Projekt hat keine Dateien.',
    'unsafe_path' => 'Ein Dateipfad im Projekt wurde abgelehnt.',
    'sandbox_stop_unconfirmed' => 'Der Sandbox-Container ließ sich nicht sicher beenden.',
  ][(string)$proc['error']] ?? 'Fehler: ' . (string)$proc['error'] . '.';
}
