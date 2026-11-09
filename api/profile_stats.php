<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.84: Settings > Profil, the overview modeled on the ChatGPT profile page.
 *   GET  the signed-in account's own figures (api/usage_store.php): tokens counted since
 *        migration 029, streaks, longest task, raster, tools, insights, what Mara made.
 * Signed-in accounts only (guests have no settings). Never another account's data.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_store.php';
require_once __DIR__ . '/usage_store.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  header('Allow: GET');
  stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$st = $pdo->prepare('SELECT permission_level, is_guest, created_at FROM stu_users WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$account = $st->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int)($account['is_guest'] ?? 1) === 1) stu_json(['ok' => false, 'error' => 'guest_not_allowed'], 403);

$profile = coreui_profile_load($pdo, $uid);
$assistantId = defined('STU_EMBER_CHARACTER_ID') && trim((string)STU_EMBER_CHARACTER_ID) !== '' ? trim((string)STU_EMBER_CHARACTER_ID) : 'ember';

stu_json([
  'ok' => true,
  'profile' => [
    'display_name' => (string)($profile['display_name'] ?? ''),
    'assistant_name' => (string)($profile['assistant_name'] ?? 'Mara'),
    'avatar' => $profile['avatars']['user'] ?? null,
    'role' => coreui_role_name((int)($account['permission_level'] ?? 4), false),
    'member_since' => substr((string)($account['created_at'] ?? ''), 0, 10),
  ],
  'stats' => coreui_profile_stats($pdo, $uid, $assistantId),
]);
