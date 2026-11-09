<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.81: Settings > Benachrichtigungen.
 *   GET                       prefs, devices, push key, whether E-Mail is available
 *   POST {action: prefs}      {prefs: {answers: 'push,email', ...}}
 *   POST {action: subscribe}  {endpoint, keys: {p256dh, auth}} from PushManager.subscribe()
 *   POST {action: unsubscribe}{id} or {endpoint}
 *   POST {action: test}       a test push to this account's browsers (and nothing else)
 * Signed-in accounts only (guests have no settings), CSRF on every POST.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notifications_store.php';

header('Cache-Control: private, no-store');

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$st = $pdo->prepare('SELECT username, permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$account = $st->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int)($account['is_guest'] ?? 1) === 1) stu_json(['ok' => false, 'error' => 'guest_not_allowed'], 403);
$owner = (int)($account['permission_level'] ?? 9) === 0;
$address = (string)($account['username'] ?? '');

function coreui_notify_state(PDO $pdo, int $uid, bool $owner, string $address): array {
  $ready = coreui_notify_schema_ready($pdo);
  $pushReady = $ready && coreui_push_supported();
  $public = '';
  if ($pushReady) {
    try { $public = coreui_push_vapid($pdo)['public']; } catch (Throwable $e) { $pushReady = false; }
  }
  return [
    'ok' => true,
    'ready' => $ready,
    'owner' => $owner,
    'prefs' => coreui_notify_prefs($pdo, $uid),
    'categories' => coreui_notify_categories(),
    'push' => ['available' => $pushReady, 'public_key' => $public, 'devices' => coreui_push_devices($pdo, $uid)],
    'email' => ['available' => coreui_notify_mail_available($pdo), 'address' => filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : ''],
    'answer_seconds' => COREUI_NOTIFY_ANSWER_SECONDS,
    'csrf_token' => stu_csrf_token(),
  ];
}

if ($method === 'GET') {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  stu_json(coreui_notify_state($pdo, $uid, $owner, $address));
}
if ($method !== 'POST') {
  header('Allow: GET, POST');
  stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
stu_require_csrf();
$body = stu_read_json_body();
$action = (string)($body['action'] ?? '');

try {
  if ($action === 'prefs') {
    $prefs = is_array($body['prefs'] ?? null) ? $body['prefs'] : [];
    coreui_notify_save($pdo, $uid, $prefs, $owner);
    stu_json(coreui_notify_state($pdo, $uid, $owner, $address));
  }
  if ($action === 'subscribe') {
    $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
    $label = function_exists('coreui_auth_device_label') ? coreui_auth_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) : 'Browser';
    $saved = coreui_push_subscribe($pdo, $uid, (string)($body['endpoint'] ?? ''), (string)($keys['p256dh'] ?? ''),
      (string)($keys['auth'] ?? ''), $label);
    stu_json(['subscribed' => $saved] + coreui_notify_state($pdo, $uid, $owner, $address));
  }
  if ($action === 'unsubscribe') {
    coreui_push_unsubscribe($pdo, $uid, (int)($body['id'] ?? 0), (string)($body['endpoint'] ?? ''));
    stu_json(coreui_notify_state($pdo, $uid, $owner, $address));
  }
  if ($action === 'test') {
    $sent = ['push' => 0, 'push_failed' => 0, 'push_removed' => 0];
    $q = $pdo->prepare('SELECT id, endpoint, p256dh, auth, failures FROM stu_push_subscriptions WHERE user_id = ?');
    $q->execute([$uid]);
    $subs = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $vapid = $subs !== [] ? coreui_push_vapid($pdo) : null;
    foreach ($subs as $sub) {
      $r = coreui_push_send($sub, ['title' => 'CoreAI: Test', 'body' => 'Push funktioniert auf diesem Gerät.',
        'url' => './settings.html#benachrichtigungen', 'tag' => 'coreai-test', 'category' => 'test'], coreui_push_subject(), $vapid);
      if ($r['ok']) {
        $sent['push']++;
        $pdo->prepare('UPDATE stu_push_subscriptions SET failures = 0, last_success_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$sub['id']]);
      } elseif ($r['gone']) {
        $sent['push_removed']++;
        $pdo->prepare('DELETE FROM stu_push_subscriptions WHERE id = ?')->execute([(int)$sub['id']]);
      } else {
        $sent['push_failed']++;
        if (function_exists('stu__log_error')) stu__log_error(['type' => 'push_test_failed', 'uid' => $uid, 'status' => $r['status'], 'message' => $r['error']]);
      }
    }
    stu_json(['test' => $sent] + coreui_notify_state($pdo, $uid, $owner, $address));
  }
} catch (InvalidArgumentException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = $e->getMessage();
  stu_json(['ok' => false, 'error' => $code], $code === 'notifications_migration_required' ? 503 : 400);
}
stu_json(['ok' => false, 'error' => 'unknown_action'], 400);
