<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_plan_store.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method,['GET','HEAD'],true)) {
  header('Allow: GET, HEAD');
  stu_json(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$uid = (int)stu_require_user_id();
$pdo = stu_pdo();
stu_enforce_maintenance($pdo,$uid);
$id = (string)($_GET['id'] ?? '');
if (!preg_match('~^[a-f0-9]{32}$~D',$id)) stu_json(['ok'=>false,'error'=>'invalid_plan'],400);
if (!coreui_plan_schema_ready($pdo)) stu_json(['ok'=>false,'error'=>'plan_migration_required'],503);
$st = $pdo->prepare("SELECT p.id,p.version,p.markdown,p.byte_size,p.sha256 FROM stu_console_project_plans p
  JOIN stu_console_sessions s ON s.id=p.session_id AND s.user_id=p.user_id
  WHERE p.id=? AND p.user_id=? AND s.user_id=? LIMIT 1");
$st->execute([$id,$uid,$uid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) stu_json(['ok'=>false,'error'=>'plan_not_found'],404);
$body = (string)$row['markdown'];
if (strlen($body) !== (int)$row['byte_size']
    || !hash_equals((string)$row['sha256'],hash('sha256',$body))) {
  stu_json(['ok'=>false,'error'=>'plan_integrity_failed'],409);
}
$name = 'projektplan-v' . (int)$row['version'] . '.md';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Cross-Origin-Resource-Policy: same-origin');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($method !== 'HEAD') echo $body;
