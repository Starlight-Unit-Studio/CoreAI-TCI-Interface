<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_artifact_store.php';
// 0.7.82 (P11 C): versions, kept files, the retention and notes of deleted files.
require_once __DIR__ . '/artifact_lifecycle_store.php';
require_once __DIR__ . '/guest_access.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
$raw = $_GET['message_ids'] ?? '';
if (!is_string($raw) || strlen($raw) > 1700 || !preg_match('~^[1-9][0-9]{0,15}(,[1-9][0-9]{0,15}){0,99}$~D', $raw)) {
    stu_json(['ok'=>false, 'error'=>'invalid_message_ids'], 400);
}
$ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
// A missing migration is retryable, not a successful empty list.
if (!coreui_console_artifact_schema_ready($pdo)) {
    stu_json(['ok'=>false, 'error'=>'artifact_migration_required'], 503);
}
// Files whose retention has run out go first (and leave their notes).
try {
    coreui_artifact_sweep($pdo, $uid);
} catch (Throwable $e) {
    error_log('[CoreAI] artifact retention sweep failed: ' . get_class($e));
}
// Shared selection enforces owner, session, response, trigger and revision.
$map = coreui_artifact_cards($pdo, $uid, $ids);
$manage = !coreui_guest_is($pdo, $uid);
stu_json(['ok'=>true, 'artifacts'=>(object)$map, 'schema_ready'=>true, 'retention_days'=>coreui_artifact_retention_days($pdo),
    'can_manage'=>$manage, 'can_keep'=>$manage && coreui_artifact_keep_ready($pdo), 'csrf_token'=>$manage ? stu_csrf_token() : '']);
