<?php
declare(strict_types=1);

/*
 * 0.5.83: the self-update as the web app sees it.
 *
 * The host does the work (scripts/coreai-updater.sh through systemd): it
 * checks main every 10 minutes and writes var/update/available.json, runs an
 * update and writes var/update/status.json. Both are root's; the web app only
 * reads them. To start an update or a check it drops a file into
 * var/update-request (its only writable spot here). Whether an update is
 * available is decided here, at read time, against the installed commit, so a
 * check that ran during an installation never shows a stale window.
 */

const COREUI_UPDATE_STALE_SECONDS = 2700;   // a "running" update older than 45 min is dead
const COREUI_UPDATE_QUEUE_SECONDS = 120;    // the host service should pick a request up by then
const COREUI_UPDATE_JSON_MAX = 65536;

function coreui_update_paths(string $root): array {
  $root = rtrim($root, '/');
  return [
    'version' => $root . '/VERSION',
    'commit' => $root . '/var/dev_source_commit',
    'installed' => $root . '/var/update/updater.json',
    'available' => $root . '/var/update/available.json',
    'status' => $root . '/var/update/status.json',
    'log' => $root . '/var/update/update.log',
    'requests' => $root . '/var/update-request',
    'request' => $root . '/var/update-request/request.json',
    'check' => $root . '/var/update-request/check',
  ];
}

function coreui_update_read_json(string $file): array {
  if (!is_file($file) || is_link($file) || !is_readable($file)) return [];
  $size = @filesize($file);
  if ($size === false || $size > COREUI_UPDATE_JSON_MAX) return [];
  $data = json_decode((string)@file_get_contents($file), true);
  return is_array($data) ? $data : [];
}

function coreui_update_time(string $iso): int {
  if ($iso === '') return 0;
  $ts = strtotime($iso);
  return $ts === false ? 0 : $ts;
}

/** Installed version and commit (the commit only for installations from main). */
function coreui_update_local(string $root): array {
  $paths = coreui_update_paths($root);
  $version = trim((string)@file_get_contents($paths['version']));
  $commit = is_file($paths['commit']) ? trim((string)@file_get_contents($paths['commit'])) : '';
  return [
    'version' => preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '',
    'commit' => preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : '',
  ];
}

/** Everything about the update, as the Inhaber sees it. */
function coreui_update_state(string $root, ?int $now = null): array {
  $now = $now ?? time();
  $paths = coreui_update_paths($root);
  $local = coreui_update_local($root);
  $installed = coreui_update_read_json($paths['installed']);
  $available = coreui_update_read_json($paths['available']);
  $status = coreui_update_read_json($paths['status']);

  $latestCommit = (string)($available['latest_commit'] ?? '');
  $latestVersion = (string)($available['latest_version'] ?? '');
  if (!preg_match('/^[0-9a-f]{40}$/', $latestCommit)) $latestCommit = '';
  if (!preg_match('/^\d+\.\d+\.\d+$/', $latestVersion)) $latestVersion = '';
  // From main the commit decides; without a commit marker (release package) the version.
  if ($latestCommit === '') $isNew = false;
  elseif ($local['commit'] !== '') $isNew = $latestCommit !== $local['commit'];
  else $isNew = $latestVersion !== '' && $latestVersion !== $local['version'];

  $state = (string)($status['state'] ?? 'idle');
  if (!in_array($state, ['idle', 'running', 'done', 'failed'], true)) $state = 'idle';
  $message = (string)($status['message'] ?? '');
  $phase = (string)($status['phase'] ?? '');
  if ($state === 'running') {
    $seen = max(coreui_update_time((string)($status['updated_at'] ?? '')), coreui_update_time((string)($status['started_at'] ?? '')));
    if ($seen > 0 && $now - $seen > COREUI_UPDATE_STALE_SECONDS) {
      $state = 'failed';
      $message = 'Das Update hat sich nicht zurückgemeldet. Bitte das Update-Protokoll prüfen.';
    }
  }
  // Real progress from the host: percent 0-100 and the installer step it is on.
  $percent = max(0, min(100, (int)($status['percent'] ?? 0)));
  $step = mb_substr(trim((string)($status['step'] ?? '')), 0, 160);
  if ($state === 'done') $percent = 100;
  $queuedFor = 0;
  if ($state !== 'running' && is_file($paths['request'])) {
    $state = 'queued';
    $phase = 'queued';
    $percent = 0;
    $step = '';
    $queuedFor = max(0, $now - (int)@filemtime($paths['request']));
    $message = 'Update angefordert, der Update-Dienst startet gleich.';
  }

  return [
    'installed' => ($installed['installed'] ?? false) === true,
    'state' => $state,
    'phase' => $phase,
    'running' => $state === 'running' || $state === 'queued',
    'queued_seconds' => $queuedFor,
    'stalled' => $state === 'queued' && $queuedFor > COREUI_UPDATE_QUEUE_SECONDS,
    'message' => $message,
    'percent' => $percent,
    'step' => $step,
    'available' => $isNew && $state !== 'running' && $state !== 'queued',
    'current_version' => $local['version'],
    'current_commit' => substr($local['commit'], 0, 7),
    'latest_version' => $latestVersion,
    'latest_commit' => substr($latestCommit, 0, 7),
    'notes' => (string)($available['notes'] ?? ''),
    'checked_at' => (string)($available['checked_at'] ?? ''),
    'check_error' => (string)($available['error'] ?? ''),
    'checking' => is_file($paths['check']),
    'started_at' => (string)($status['started_at'] ?? ''),
    'finished_at' => (string)($status['finished_at'] ?? ''),
    'from_version' => (string)($status['from_version'] ?? ''),
    'to_version' => (string)($status['to_version'] ?? ''),
    'restart_ollama' => ($status['restart_ollama'] ?? false) === true,
  ];
}

/** What every account (and the login page) sees: whether CoreAI restarts, and how far it is. */
function coreui_update_public(array $state): array {
  return [
    'running' => (bool)$state['running'],
    'state' => (string)$state['state'],
    'phase' => (string)$state['phase'],
    'percent' => (int)$state['percent'],
    'step' => (string)$state['step'],
    'version' => (string)$state['current_version'],
  ];
}

/** Last lines of the update log, for the Inhaber after a failed update. */
function coreui_update_log_tail(string $root, int $lines = 40): array {
  $file = coreui_update_paths($root)['log'];
  if (!is_file($file) || is_link($file) || !is_readable($file)) return [];
  $all = @file($file, FILE_IGNORE_NEW_LINES);
  if (!is_array($all)) return [];
  return array_map(static fn($line): string => mb_substr((string)$line, 0, 400), array_slice($all, -max(1, min(200, $lines))));
}

function coreui_update_write_request(string $file, string $content): void {
  $dir = dirname($file);
  if (!is_dir($dir) || is_link($dir) || !is_writable($dir)) throw new RuntimeException('request_dir_missing');
  $tmp = @tempnam($dir, '.tmp-');
  if ($tmp === false) throw new RuntimeException('request_dir_missing');
  if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $file)) {
    @unlink($tmp);
    throw new RuntimeException('request_failed');
  }
}

/** Asks the host to install the newest main. Only the Inhaber gets here. */
function coreui_update_request(string $root, int $uid, bool $restartOllama, ?int $now = null): void {
  $state = coreui_update_state($root, $now);
  if (!$state['installed']) throw new RuntimeException('updater_missing');
  if ($state['running']) throw new RuntimeException('update_running');
  if (!$state['available']) throw new RuntimeException('no_update');
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $request = json_encode([
    'action' => 'update',
    'requested_by' => $uid,
    'restart_ollama' => $restartOllama,
    'requested_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
  ], JSON_UNESCAPED_SLASHES);
  coreui_update_write_request(coreui_update_paths($root)['request'], (string)$request);
}

/** Asks the host to look for a new merge right now. */
function coreui_update_request_check(string $root): void {
  $state = coreui_update_state($root);
  if (!$state['installed']) throw new RuntimeException('updater_missing');
  coreui_update_write_request(coreui_update_paths($root)['check'], gmdate('Y-m-d\TH:i:s\Z') . "\n");
}

/** Withdraws a request the host has not picked up yet. */
function coreui_update_cancel(string $root): bool {
  $paths = coreui_update_paths($root);
  $status = coreui_update_read_json($paths['status']);
  if (($status['state'] ?? '') === 'running' || !is_file($paths['request'])) return false;
  return @unlink($paths['request']);
}
