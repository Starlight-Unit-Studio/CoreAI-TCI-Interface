<?php
declare(strict_types=1);

/*
 * 0.7.30: Settings > Nutzung (api/usage_page_store.php). Signed-in accounts only, never guests;
 * only the account's own figures, numbers only.
 *
 *   GET ?action=overview                               plan, daily limit window, storage, today, 7 days
 *   GET ?action=analytics&days=7|30&back=N             tokens by Core and thinking, tools, skills, messages
 *   GET ?action=chats&days=7|30&back=N&offset=N        the chats with the most own messages
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/usage_page_store.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  header('Allow: GET');
  stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$st = $pdo->prepare('SELECT permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$account = $st->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int)($account['is_guest'] ?? 1) === 1) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);
$level = (int)($account['permission_level'] ?? 4);
$assistantId = defined('STU_EMBER_CHARACTER_ID') && trim((string)STU_EMBER_CHARACTER_ID) !== '' ? trim((string)STU_EMBER_CHARACTER_ID) : 'ember';

$action = strtolower(trim((string)($_GET['action'] ?? 'overview')));
$days = (int)($_GET['days'] ?? 7);
$back = (int)($_GET['back'] ?? 0);

if ($action === 'overview') {
  stu_json(['ok' => true] + coreui_usage_page_overview($pdo, $uid, $assistantId, $level, false,
    coreui_operator_runtime_load($pdo), coreui_ai_num_ctx_choices()));
}
if ($action === 'analytics') {
  stu_json(['ok' => true] + coreui_usage_page_analytics($pdo, $uid, $assistantId, $days, $back));
}
if ($action === 'chats') {
  stu_json(['ok' => true] + coreui_usage_page_chats($pdo, $uid, $assistantId, $days, $back, (int)($_GET['offset'] ?? 0)));
}
stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
