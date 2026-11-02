<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/console_personalization_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  stu_json([
    'ok' => true,
    'settings' => coreui_personalization_load($pdo,(int)$uid),
    'memory_enabled' => coreui_ai_settings_load($pdo,(int)$uid)['memory_enabled'],
    'schema_available' => coreui_personalization_schema_ready($pdo),
    'csrf_token' => stu_csrf_token()
  ]);
}
if ($method !== 'POST') stu_json(['ok'=>false,'error'=>'method_not_allowed'],405);
stu_require_csrf();
$body = stu_read_json_body();
$action = (string)($body['action'] ?? '');

try {
  if ($action === 'save') {
    if (!isset($body['settings']) || !is_array($body['settings'])) {
      stu_json(['ok'=>false,'error'=>'invalid_settings'],400);
    }
    $saved = coreui_personalization_save($pdo,(int)$uid,$body['settings']);
    stu_json(['ok'=>true,'settings'=>$saved]);
  }
  if ($action === 'memory') {
    if (!array_key_exists('enabled',$body) || !is_bool($body['enabled'])) {
      stu_json(['ok'=>false,'error'=>'invalid_memory_enabled'],400);
    }
    // Do not overwrite independent AI settings while changing one switch.
    $old = coreui_ai_settings_load($pdo,(int)$uid);
    $new = coreui_ai_settings_save($pdo,(int)$uid,
      array_merge($old,['memory_enabled'=>$body['enabled']]));
    stu_json(['ok'=>true,'memory_enabled'=>$new['memory_enabled']]);
  }
  stu_json(['ok'=>false,'error'=>'invalid_action'],400);
} catch (RuntimeException $error) {
  if ($error->getMessage() === 'missing_schema_022' || $error->getMessage() === 'missing_schema_005') {
    stu_json(['ok'=>false,'error'=>$error->getMessage()],503);
  }
  error_log('[CoreAI] Personalization failed: ' . get_class($error));
  stu_json(['ok'=>false,'error'=>'personalization_failed'],500);
} catch (Throwable $error) {
  error_log('[CoreAI] Personalization failed: ' . get_class($error));
  stu_json(['ok'=>false,'error'=>'personalization_failed'],500);
}
