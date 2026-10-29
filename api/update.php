<?php
declare(strict_types=1);

/*
 * 0.5.83: the update window.
 *
 * GET  ?action=status  every page: whether CoreAI is restarting for an update
 *                      (no login needed, so the waiting window survives a
 *                      restart). The Inhaber also gets version, notes and state.
 * POST ?action=start   Inhaber only: install the newest main {restart_ollama}
 * POST ?action=check   Inhaber only: look for a new merge right now
 * POST ?action=cancel  Inhaber only: withdraw a request not yet picked up
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/update_store.php';

header('Cache-Control: no-store');

$root = dirname(__DIR__);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'status')));

/** The signed-in account when it is the Inhaber, else 0. */
function coreui_update_owner(): int {
  $uid = stu_get_user_id();
  if (!$uid) return 0;
  try {
    $pdo = stu_pdo();
    $st = $pdo->prepare('SELECT permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return 0;
  }
  if (!is_array($row) || (int)$row['permission_level'] !== 0 || (int)($row['is_guest'] ?? 0) === 1) return 0;
  return (int)$uid;
}

if ($method === 'GET' && $action === 'status') {
  $state = coreui_update_state($root);
  $out = ['ok' => true] + coreui_update_public($state);
  $owner = coreui_update_owner();
  if ($owner > 0) {
    $out['owner'] = true;
    $out['update'] = $state;
    $out['csrf_token'] = stu_csrf_token();
    if ($state['state'] === 'failed') $out['log'] = coreui_update_log_tail($root, 40);
  }
  stu_json($out);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
$owner = coreui_update_owner();
if ($owner <= 0) stu_json(['ok' => false, 'error' => 'owner_only'], 403);
stu_require_csrf();
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

try {
  if ($action === 'start') {
    $restartOllama = ($body['restart_ollama'] ?? true) === true;
    coreui_update_request($root, $owner, $restartOllama);
    $state = coreui_update_state($root);
    try {
      stu_pdo()->prepare('INSERT INTO stu_admin_audit (actor_user_id, action_name, target_type, target_id, detail_json) VALUES (?, ?, ?, ?, ?)')
        ->execute([$owner, 'update_start', 'coreai', (string)$state['latest_version'], json_encode([
          'from' => $state['current_version'], 'to' => $state['latest_version'], 'commit' => $state['latest_commit'],
          'restart_ollama' => $restartOllama,
        ], JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
      // The audit must never block an update.
    }
    stu_json(['ok' => true, 'update' => $state] + coreui_update_public($state));
  }
  if ($action === 'check') {
    coreui_update_request_check($root);
    stu_json(['ok' => true]);
  }
  if ($action === 'cancel') {
    stu_json(['ok' => coreui_update_cancel($root)]);
  }
} catch (RuntimeException | InvalidArgumentException $e) {
  $code = $e->getMessage();
  $known = ['updater_missing', 'update_running', 'no_update', 'request_dir_missing', 'request_failed', 'invalid_user'];
  stu_json(['ok' => false, 'error' => in_array($code, $known, true) ? $code : 'update_failed'], $code === 'update_running' ? 409 : 400);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
