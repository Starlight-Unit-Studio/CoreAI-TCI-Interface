<?php
declare(strict_types=1);
// CoreAI 0.6.9: a run whose worker is gone does not stay "running" for ever.
//   php scripts/agent-run-liveness-selftest.php
// Before, a run whose PHP worker vanished (container recreated by an update, killed
// process) kept the chat "busy": "Core denkt nach" for 45 minutes, a cancel that never
// finished and a chat that could not be deleted. A live worker now refreshes its run
// (heartbeat); without that sign of life the run ends. Offline: the wiring. With a
// database (api/config.local.php, or COREUI_SELFTEST_DB=1 with COREUI_DB_* set): the
// reaper on real rows inside a transaction that is rolled back.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/console_agent_runs.php';

function liveness_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Agent run liveness FAIL: {$why}\n"); exit(1); }
}

$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$chat = $read('api/chat.php');
$heartbeatAt = strpos($chat, 'function ember_agent_heartbeat(): void {');
$cancelAt = strpos($chat, 'function ember_agent_cancel_requested(bool $force = false): bool {');
liveness_test($heartbeatAt !== false && $cancelAt !== false && $heartbeatAt < $cancelAt, 'heartbeat function before the cancel check');
$cancelBody = substr($chat, $cancelAt, 600);
liveness_test(str_contains($cancelBody, "ember_agent_heartbeat();\n  \$now = microtime(true);"),
  'every cancellation check (model stream, queue, steps) is a sign of life');
liveness_test(str_contains($chat, "usleep(400000); // 0.4s\n    ember_agent_heartbeat();"), 'waiting for a Python job is a sign of life');
liveness_test(str_contains(substr($chat, $heartbeatAt, 900), '< 15.0) return;'), 'at most every 15 seconds');
liveness_test(COREUI_AGENT_RUN_STALE_SECONDS >= 6 * 15 && COREUI_AGENT_RUN_CANCEL_STALE_SECONDS >= 2 * 15,
  'stale only after several missed heartbeats');
$actions = $read('api/console_actions.php');
liveness_test(strpos($actions, 'coreui_agent_run_reap_stale($pdo,(int)$uid,$sessionId,COREUI_AGENT_RUN_CANCEL_STALE_SECONDS,true);')
  < strpos($actions, '$result = coreui_agent_run_request_cancel($pdo,(int)$uid,$sessionId,$runId);'),
  'cancel: a run without a worker is cancelled at once');
liveness_test(str_contains($actions, "coreui_agent_run_reap_stale(\$pdo,(int)\$uid,\$sessionId);\n      \$run = coreui_agent_run_get("),
  'the run status the page polls ends a lost run');
$runs = $read('api/console_agent_runs.php');
liveness_test(strpos($runs, 'coreui_agent_run_reap_stale($pdo, $uid, $sessionId);') > strpos($runs, 'function coreui_agent_run_active_snapshot('),
  'no endless "Core denkt nach" after a reload');
$store = $read('api/console_session_store.php');
$deleteAt = strpos($store, 'function coreui_console_session_delete(PDO $pdo');
liveness_test($deleteAt !== false && strpos($store, 'coreui_agent_run_reap_stale($pdo, $uid,', $deleteAt) < strpos($store, '$pdo->beginTransaction();', $deleteAt),
  'deleting a chat ends lost runs first');
// 0.6.19: the chat list shows a spinner where Mara works (console_sessions.php ?action=list).
$sessionsApi = $read('api/console_sessions.php');
liveness_test(str_contains($sessionsApi, '$working = coreui_agent_run_working_sessions($pdo, (int)$uid);')
  && str_contains($sessionsApi, "\$row['working'] = isset(\$working[(string)\$row['id']]);"), 'the chat list says where Mara works');

$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI agent run liveness: heartbeat wiring, reaper at cancel, status, reload and delete OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
liveness_test(coreui_agent_run_schema_ready($pdo), 'migration 013 applied');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $session = 'live' . bin2hex(random_bytes(6));
  $other = 'othr' . bin2hex(random_bytes(6));
  $add = static function (string $sessionId, int $turn, string $status, int $ageSeconds, bool $cancel = false) use ($pdo, $uid): string {
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO stu_console_agent_runs (run_id,user_id,session_id,turn_id,run_key,status,cancel_requested_at,created_at,updated_at) '
      . 'VALUES (?,?,?,?,?,?,' . ($cancel ? 'NOW(3)' : 'NULL') . ',DATE_SUB(NOW(3), INTERVAL ? SECOND),DATE_SUB(NOW(3), INTERVAL ? SECOND))')
      ->execute([$id, $uid, $sessionId, $turn, 'base', $status, $ageSeconds, $ageSeconds]);
    return $id;
  };
  $get = static function (string $sessionId, string $id) use ($pdo, $uid): array {
    return coreui_agent_run_get($pdo, $uid, $sessionId, $id) ?? [];
  };
  $lost = $add($session, 1, 'running', 600);
  $lostCancelled = $add($session, 2, 'running', 600, true);
  $live = $add($session, 3, 'running', 30);
  $neverStarted = $add($session, 4, 'queued', 900);
  $justQueued = $add($session, 5, 'queued', 5);
  $otherSession = $add($other, 6, 'running', 600);
  $done = $add($session, 7, 'completed', 600);

  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session) === 3, 'three runs of this session ended');
  liveness_test($get($session, $lost)['status'] === 'interrupted' && $get($session, $lost)['error_code'] === 'worker_lost'
    && $get($session, $lost)['finished_at'] === null, 'a lost run is interrupted (worker_lost)');
  liveness_test($get($session, $lostCancelled)['status'] === 'cancelled' && $get($session, $lostCancelled)['finished_at'] !== null,
    'a lost run with a cancel request is cancelled');
  liveness_test($get($session, $live)['status'] === 'running', 'a run with a recent sign of life keeps running');
  liveness_test($get($session, $neverStarted)['status'] === 'cancelled' && $get($session, $neverStarted)['error_code'] === 'never_started',
    'a queued run that never started is cancelled');
  liveness_test($get($session, $justQueued)['status'] === 'queued', 'a fresh queued run stays');
  liveness_test($get($other, $otherSession)['status'] === 'running', 'another session is not touched');
  liveness_test($get($session, $done)['status'] === 'completed', 'a finished run is not touched');

  // Cancel: 30 s without a sign of life is still alive for the reaper, 45 s for a cancel is not.
  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session, COREUI_AGENT_RUN_CANCEL_STALE_SECONDS, true) === 0, 'live run survives a cancel check');
  $slow = $add($session, 8, 'running', 60);
  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session) === 0, 'one minute without heartbeat is not yet lost');
  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session, COREUI_AGENT_RUN_CANCEL_STALE_SECONDS, true) === 1
    && $get($session, $slow)['status'] === 'cancelled', 'on cancel, a minute without heartbeat means the worker is gone: cancelled');
  liveness_test(coreui_agent_run_request_cancel($pdo, $uid, $session, $slow)['status'] === 'cancelled', 'the cancel request then reports cancelled');
  // After "Auftrag abbrechen" the page polls the status for about two minutes: a lost worker must end within that.
  $askedToStop = $add($session, 9, 'running', 60, true);
  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session) === 1 && $get($session, $askedToStop)['status'] === 'cancelled',
    'a cancel request without a worker ends after 45 s, inside the polling time');

  // Heartbeat: refreshes a running run, never a finished one.
  $pdo->prepare('UPDATE stu_console_agent_runs SET updated_at=DATE_SUB(NOW(3), INTERVAL 600 SECOND) WHERE run_id=?')->execute([$live]);
  coreui_agent_run_heartbeat($pdo, $uid, $session, $live);
  coreui_agent_run_heartbeat($pdo, $uid, $session, $done);
  liveness_test(coreui_agent_run_reap_stale($pdo, $uid, $session) === 0 && $get($session, $live)['status'] === 'running', 'the heartbeat keeps a run alive');
  $age = static fn(string $id): int => (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW(3)) FROM stu_console_agent_runs WHERE run_id='" . $id . "'")->fetchColumn();
  liveness_test($age($live) < 5 && $age($done) >= 590, 'only running runs get a heartbeat');

  // The snapshot after a reload: the lost run is no active run.
  $pdo->prepare('UPDATE stu_console_agent_runs SET status=?, updated_at=DATE_SUB(NOW(3), INTERVAL 600 SECOND) WHERE run_id=?')->execute(['running', $live]);
  liveness_test(coreui_agent_run_active_snapshot($pdo, $uid, $session) === null || coreui_agent_run_active_snapshot($pdo, $uid, $session)['id'] === $justQueued,
    'after a reload a lost run shows no "Core denkt nach"');
  liveness_test($get($session, $live)['status'] === 'interrupted', 'and it is marked interrupted');

  // 0.6.19: chats with a run that shows a sign of life get the spinner; lost and finished runs do not.
  $fresh = 'frsh' . bin2hex(random_bytes(6));
  $queued = 'qued' . bin2hex(random_bytes(6));
  $tool = 'tool' . bin2hex(random_bytes(6));
  $gone = 'gone' . bin2hex(random_bytes(6));
  $add($fresh, 20, 'running', 20);
  $add($queued, 21, 'queued', 30);
  $add($tool, 22, 'waiting_tool', 900);
  $add($gone, 23, 'running', COREUI_AGENT_RUN_STALE_SECONDS + 60);
  $add($gone, 24, 'completed', 5);
  $working = coreui_agent_run_working_sessions($pdo, $uid);
  liveness_test(isset($working[$fresh], $working[$queued], $working[$tool]) && !isset($working[$gone]) && !isset($working[$other]),
    'spinner for running, queued and tool-waiting runs with a sign of life; none for a lost or finished one');
  liveness_test(coreui_agent_run_working_sessions($pdo, $uid + 1) === [], 'never for another account');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
echo "CoreAI agent run liveness: heartbeat wiring, reaper at cancel, status, reload and delete, and the reaper on the database (rolled back) OK\n";
