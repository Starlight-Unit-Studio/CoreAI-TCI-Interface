<?php
declare(strict_types=1);
// CoreAI 0.5.83: the self-update as the web app sees it. Offline: a temporary
// project folder stands in for /opt/ember-coreui. Checks what the window may
// show (new commit, at read time against the installed one), the states a
// restart goes through, the request file (Inhaber only, once at a time), and
// the wiring (owner check, CSRF, nginx, Compose mounts, sessions, host units).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/update_store.php';

function update_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Update FAIL: {$why}\n"); exit(1); }
}

$work = sys_get_temp_dir() . '/coreai-update-test-' . bin2hex(random_bytes(4));
mkdir($work . '/var/update', 0777, true);
mkdir($work . '/var/update-request', 0777, true);
register_shutdown_function(static function () use ($work): void {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
  @rmdir($work);
});
$A = str_repeat('a', 40);
$B = str_repeat('b', 40);
$put = static function (string $rel, $data) use ($work): void {
  file_put_contents($work . '/' . $rel, is_array($data) ? json_encode($data) : $data);
};
$put('VERSION', "0.5.83\n");
$put('var/dev_source_commit', $A . "\n");
$now = time();

// Nothing from the host yet: no window, nothing running.
$s = coreui_update_state($work, $now);
update_test(!$s['installed'] && $s['state'] === 'idle' && !$s['running'] && !$s['available'], 'empty state');
update_test($s['current_version'] === '0.5.83' && $s['current_commit'] === 'aaaaaaa', 'installed version and commit');

// The host found a new merge.
$put('var/update/updater.json', ['installed' => true, 'units' => 'systemd']);
$put('var/update/available.json', ['latest_commit' => $B, 'latest_version' => '0.5.84', 'notes' => "## 0.5.84\n- neu", 'checked_at' => '2026-10-29T12:00:00Z', 'error' => '']);
$s = coreui_update_state($work, $now);
update_test($s['installed'] && $s['available'] && $s['latest_version'] === '0.5.84' && $s['latest_commit'] === 'bbbbbbb', 'new commit on main');
update_test($s['notes'] === "## 0.5.84\n- neu", 'notes for the window');
update_test(!$s['auto_enabled'] && $s['snooze_until'] === 0, 'auto-updates are opt-in and disabled by default');
$auto = coreui_update_set_auto($work, 7, true);
update_test($auto['enabled'] && $auto['requested_by'] === 7 && !$auto['restart_ollama'],
  'only the owner may opt into server auto-updates; no automatic Ollama reboot');
$held = coreui_update_snooze($work, 7, $now);
$s = coreui_update_state($work, $now);
update_test($held['snooze_until'] === $now + 3600 && $s['snooze_seconds'] === 3600
  && $s['auto_enabled'], 'Später pauses automatic updates and the popup for 60 minutes');
update_test(coreui_update_state($work, $now + 3599)['snooze_seconds'] === 1
  && coreui_update_state($work, $now + 3600)['snooze_seconds'] === 0,
  'snooze expires after exactly one hour independent of merges');
$put('var/update-request/request.json', [
  'action' => 'update', 'requested_by' => 7, 'restart_ollama' => false, 'automatic' => true
]);
coreui_update_snooze($work, 7, $now);
update_test(!is_file($work . '/var/update-request/request.json'),
  'Später withdraws only an unstarted automatic request');
$put('var/update-request/request.json', [
  'action' => 'update', 'requested_by' => 7, 'restart_ollama' => false
]);
coreui_update_snooze($work, 7, $now);
update_test(is_file($work . '/var/update-request/request.json'),
  'Später never cancels a manually requested update');
unlink($work . '/var/update-request/request.json');
coreui_update_set_auto($work, 7, false);
update_test(!coreui_update_state($work, $now)['auto_enabled'], 'automatic update can be disabled');
// Decided at read time: once installed (marker = B), the same check result shows nothing.
$put('var/dev_source_commit', $B . "\n");
update_test(!coreui_update_state($work, $now)['available'], 'a check during the installation never shows a stale window');
// Without a commit marker (release package) the version decides.
unlink($work . '/var/dev_source_commit');
update_test(coreui_update_state($work, $now)['available'], 'release package: other version');
$put('VERSION', "0.5.84\n");
update_test(!coreui_update_state($work, $now)['available'], 'release package: same version');
$put('VERSION', "0.5.83\n");
$put('var/dev_source_commit', $A . "\n");
// Broken or foreign files never break the page.
$put('var/update/available.json', '{broken');
update_test(!coreui_update_state($work, $now)['available'], 'broken JSON');
$put('var/update/available.json', ['latest_commit' => 'not-a-commit', 'latest_version' => '<b>1</b>']);
$s = coreui_update_state($work, $now);
update_test(!$s['available'] && $s['latest_version'] === '', 'invalid commit or version ignored');
$put('var/update/available.json', ['latest_commit' => $B, 'latest_version' => '0.5.84', 'notes' => '', 'checked_at' => '', 'error' => '']);

// Requests: only when the host service exists, nothing runs and there is something new.
unlink($work . '/var/update/updater.json');
try { coreui_update_request($work, 1, true, $now); update_test(false, 'request without host service'); }
catch (RuntimeException $e) { update_test($e->getMessage() === 'updater_missing', 'no host service: ' . $e->getMessage()); }
$put('var/update/updater.json', ['installed' => true]);
coreui_update_request($work, 7, false, $now);
$req = json_decode((string)file_get_contents($work . '/var/update-request/request.json'), true);
update_test($req['action'] === 'update' && $req['requested_by'] === 7 && $req['restart_ollama'] === false, 'request file');
update_test(count(glob($work . '/var/update-request/.tmp-*') ?: []) === 0, 'written atomically');
$s = coreui_update_state($work, $now + 5);
update_test($s['state'] === 'queued' && $s['running'] && !$s['available'] && !$s['stalled'], 'queued');
update_test(coreui_update_state($work, $now + COREUI_UPDATE_QUEUE_SECONDS + 30)['stalled'], 'a request nobody picks up is reported');
try { coreui_update_request($work, 7, true, $now); update_test(false, 'second request'); }
catch (RuntimeException $e) { update_test($e->getMessage() === 'update_running', 'one update at a time'); }
update_test(coreui_update_cancel($work) && !is_file($work . '/var/update-request/request.json'), 'a queued request can be withdrawn');

// A running update: everyone waits; a dead one does not hold the page forever.
$put('var/update/status.json', ['state' => 'running', 'phase' => 'install', 'percent' => 47, 'step' => 'Die Images werden gebaut.',
  'started_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 60), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 30)]);
$s = coreui_update_state($work, $now);
update_test($s['running'] && $s['state'] === 'running' && $s['phase'] === 'install' && !$s['available'], 'running');
update_test($s['percent'] === 47 && $s['step'] === 'Die Images werden gebaut.', 'real progress from the host');
$put('var/update/status.json', ['state' => 'running', 'phase' => 'install', 'percent' => 900, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now)]);
update_test(coreui_update_state($work, $now)['percent'] === 100, 'percent capped');
$put('var/update/status.json', ['state' => 'running', 'phase' => 'install', 'percent' => 47, 'step' => 'Die Images werden gebaut.',
  'started_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 60), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 30)]);
coreui_update_write_request($work . '/var/update-request/request.json', '{}');
update_test(coreui_update_state($work, $now)['state'] === 'running' && !coreui_update_cancel($work), 'running beats a leftover request and cannot be cancelled');
unlink($work . '/var/update-request/request.json');
$s = coreui_update_state($work, $now + COREUI_UPDATE_STALE_SECONDS + 60);
update_test($s['state'] === 'failed' && !$s['running'] && $s['available'] && str_contains($s['message'], 'nicht zurückgemeldet'), 'a dead update ends as failed');
$public = coreui_update_public(coreui_update_state($work, $now));
update_test(array_keys($public) === ['running', 'state', 'phase', 'percent', 'step', 'version'] && $public['running'],
  'every account sees only whether CoreAI restarts and how far');
$put('var/update/status.json', ['state' => 'done', 'phase' => 'done', 'to_version' => '0.5.84', 'percent' => 97]);
update_test(coreui_update_state($work, $now)['state'] === 'done' && coreui_update_state($work, $now)['percent'] === 100, 'done is 100 percent');
// A symlinked status file (never written by the host) is ignored.
unlink($work . '/var/update/status.json');
symlink($work . '/var/update/available.json', $work . '/var/update/status.json');
update_test(coreui_update_state($work, $now)['state'] === 'idle', 'symlinks are not read');
unlink($work . '/var/update/status.json');

// A preflight error can leave the newest commit already deployed. The
// Inhaber must still be able to retry a full setup.
$put('var/dev_source_commit', $B . "\n");
$put('var/update/status.json', ['state' => 'failed', 'phase' => 'install',
  'failure_kind' => 'preflight', 'message' => 'Preflight fehlgeschlagen: 2 Problem(e).']);
$s = coreui_update_state($work, $now);
update_test(!$s['available'] && $s['failure_kind'] === 'preflight', 'preflight error distinguished from general failure');
coreui_update_request($work, 7, false, $now);
update_test(coreui_update_state($work, $now)['state'] === 'queued',
  'retry is allowed even when the failed setup already wrote main commit');
update_test(coreui_update_cancel($work), 'queued retry can be cancelled');
$put('var/dev_source_commit', $A . "\n");
unlink($work . '/var/update/status.json');

// "Jetzt pruefen" and the log.
coreui_update_request_check($work);
update_test(is_file($work . '/var/update-request/check') && coreui_update_state($work, $now)['checking'], 'check request');
$put('var/update/update.log', implode("\n", array_map(static fn($i) => 'line ' . $i, range(1, 100))) . "\n");
$tail = coreui_update_log_tail($work, 3);
update_test($tail === ['line 98', 'line 99', 'line 100'], 'log tail');
unlink($work . '/var/update-request/check');
@unlink($work . '/var/update-request/auto.json');
rmdir($work . '/var/update-request');
try { coreui_update_write_request($work . '/var/update-request/request.json', '{}'); update_test(false, 'missing folder'); }
catch (RuntimeException $e) { update_test($e->getMessage() === 'request_dir_missing', 'missing request folder named'); }

// Wiring.
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$api = $read('api/update.php');
update_test(str_contains($api, "(int)\$row['permission_level'] !== 0 || (int)(\$row['is_guest'] ?? 0) === 1") && str_contains($api, "'error' => 'owner_only'], 403")
  && str_contains($api, 'stu_require_csrf();') && str_contains($api, "'update_start', 'coreai'"), 'API: Inhaber only, CSRF, audited');
update_test(strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'start')"), 'CSRF before every action');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $nginx) {
  // The allowlist grows (0.6.1 added voice); update must stay in it.
  $allowed = preg_match('#location ~ \^/api/\(\?:([a-z_|]+)\)\\\.php\$ \{#', $read($nginx), $m) === 1 ? explode('|', $m[1]) : [];
  update_test(in_array('update', $allowed, true), 'nginx allows api/update.php: ' . $nginx);
}
$compose = $read('compose.yaml');
update_test(str_contains($compose, '- ./var/update-request:/var/www/coreui/var/update-request') && str_contains($compose, '- ./var/php_sessions:/var/www/coreui/var/php_sessions')
  && !str_contains($compose, './var/update:/'), 'Compose: request folder and sessions writable, var/update stays read-only');
update_test(str_contains($read('docker/php/coreui.ini'), 'session.save_path = /var/www/coreui/var/php_sessions'), 'sessions survive a recreated container');
update_test(str_contains($read('api/helpers.php'), 'if ($savePath !== \'\' && !is_writable($savePath)) session_save_path(sys_get_temp_dir());'), 'session fallback');
$install = $read('scripts/install.sh');
update_test(str_contains($install, 'ensure_update_service') && str_contains($install, 'systemctl enable --now coreai-update.path coreai-update-check.path coreai-update-check.timer')
  && str_contains($install, '"$PROJECT_ROOT/var/update-request" \\') && str_contains($install, '"$PROJECT_ROOT/var/php_sessions" \\'), 'installer sets up the host units and folders');
foreach (['coreai-update.service', 'coreai-update.path', 'coreai-update-check.service', 'coreai-update-check.timer', 'coreai-update-check.path'] as $unit) {
  update_test(is_file($root . '/config/systemd/' . $unit . '.template'), 'unit template ' . $unit);
}
update_test(str_contains($read('config/systemd/coreai-update.path.template'), 'PathExists=@@PROJECT_ROOT@@/var/update-request/request.json')
  && str_contains($read('config/systemd/coreai-update.service.template'), 'ExecStart=@@PROJECT_ROOT@@/scripts/coreai-updater.sh run'), 'request file starts the updater');
update_test(str_contains($read('config/systemd/coreai-update-check.timer.template'), 'OnUnitActiveSec=10min'), 'check every 10 minutes');
$updater = $read('scripts/coreai-updater.sh');
update_test(str_contains($updater, "REPOSITORY='Starlight-Unit-Studio/CoreAI-TCI-Interface'") && str_contains($updater, 'grep -qxF "TARGET_DIR=\'$PROJECT_ROOT\'"'),
  'only the official repository, only into this installation');
foreach (['app.html', 'settings.html'] as $page) {
  $html = $read($page);
  update_test(preg_match('~<script src="\./js/coreui-update\.js\?v=\d+"></script>~', $html) === 1
    && preg_match('~<link rel="stylesheet" href="\./css/coreui-update\.css\?v=\d+" />~', $html) === 1, 'window on ' . $page);
}
update_test(str_contains($read('settings.html'), 'id="updateCard"'), 'settings card');
update_test(str_contains($read('js/coreui-update.js'), "'Bitte auf Neustart warten'"), 'waiting text');

echo "CoreAI update: window only for a new commit, states, request file for the Inhaber, waiting for everyone, host units OK\n";
