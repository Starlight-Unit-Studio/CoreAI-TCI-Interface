<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_artifact_store.php';

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
// Shared selection enforces owner, session, response, trigger and revision.
$map = coreui_console_artifact_map($pdo, $uid, $ids);
stu_json(['ok'=>true, 'artifacts'=>(object)$map, 'schema_ready'=>true]);
