<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/skills.php';
$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
  stu_json(['ok'=>true, 'catalog'=>array_values(coreui_skills_catalog()),
    'enabled'=>coreui_skills_load($pdo, $uid), 'ready'=>coreui_skills_schema_ready($pdo),
    'csrf_token'=>stu_csrf_token()]);
}
if ($method !== 'POST') stu_json(['ok'=>false,'error'=>'method_not_allowed'],405);
stu_require_csrf();
$body = stu_read_json_body();
if (!isset($body['enabled']) || !is_array($body['enabled'])) stu_json(['ok'=>false,'error'=>'invalid_skill_preferences'],400);
try {
  stu_json(['ok'=>true,'enabled'=>coreui_skills_save($pdo,$uid,$body['enabled'])]);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false,'error'=>$e->getMessage()],400);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false,'error'=>'skills_save_failed_or_migration_missing'],503);
}
