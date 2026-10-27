<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/ai_context_estimate.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' && array_key_exists('context_model', $_GET)) {
  // #113: memory guide values for every slider stop of one installed Core.
  // Ollama may need a moment; never hold the PHP session lock meanwhile.
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  $settings = coreui_ai_settings_load($pdo, $uid);
  $model = trim((string)$_GET['context_model']);
  if ($model === '') $model = (string)($settings['model_override'] ?: (defined('STU_EMBER_MODEL') ? STU_EMBER_MODEL : 'ember-coreui:latest'));
  if (!coreui_model_name_valid($model)) stu_json(['ok'=>false, 'error'=>'invalid_model_name'], 400);
  stu_json(['ok'=>true, 'context'=>coreui_ai_context_estimate($model, coreui_ai_num_ctx_choices(), $settings['num_ctx'])]);
}

if ($method === 'GET') {
  stu_json([
    'ok' => true,
    'settings' => coreui_ai_settings_load($pdo, $uid),
    'limits' => [
      'system_prompt_chars' => coreui_ai_prompt_limit($pdo),
      'memory_limit_max' => 60,
      'num_predict_min' => 256,
      'num_predict_max' => 16384,
      'temperature_min' => 0.1,
      'temperature_max' => 1.5,
      'num_ctx_choices' => coreui_ai_num_ctx_choices(),
      'num_ctx_available' => coreui_ai_num_ctx_schema_ready($pdo),
    ],
    'default_model' => defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '',
    'external' => coreui_ai_external_capability($pdo),
    'csrf_token' => stu_csrf_token(),
    'permission_level' => stu_get_permission_level($pdo),
  ]);
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();

$body = stu_read_json_body();
// Strict whitelist: an unknown window is refused, never rounded silently.
$requestedCtx = $body['num_ctx'] ?? null;
if ($requestedCtx !== null && $requestedCtx !== '' && coreui_ai_num_ctx_normalise($requestedCtx) === null) {
  stu_json(['ok'=>false, 'error'=>'invalid_num_ctx'], 400);
}
try {
  $requestedModel = trim((string)($body['model_override'] ?? ''));
  if ($requestedModel !== '') {
    if (!coreui_model_name_valid($requestedModel)) {
      stu_json(['ok'=>false, 'error'=>'invalid_model_name'], 400);
    }
    try {
      if (!coreui_ollama_model_exists($requestedModel)) {
        stu_json(['ok'=>false, 'error'=>'model_not_available'], 400);
      }
    } catch (RuntimeException $catalogError) {
      stu_json(['ok'=>false, 'error'=>$catalogError->getMessage()], 503);
    }
  }
  $settings = coreui_ai_settings_save($pdo, $uid, $body);
  stu_json([
    'ok' => true,
    'settings' => $settings,
    'external' => coreui_ai_external_capability($pdo),
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type' => 'user_ai_settings_save_failed', 'uid' => $uid, 'message' => $e->getMessage()]);
  }
  stu_json(['ok' => false, 'error' => 'save_failed'], 500);
}
