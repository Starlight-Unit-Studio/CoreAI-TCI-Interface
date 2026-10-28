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

// 0.5.75: which Cores Ollama holds in memory, for the loading state in the chat.
if ($method === 'GET' && array_key_exists('core_status', $_GET)) {
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  stu_json(['ok'=>true, 'loaded'=>coreui_ollama_loaded_names()]);
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
// 0.5.75: the thinking switch lives in the chat and saves only its own value.
if (($body['action'] ?? '') === 'thinking') {
  $enabled = filter_var($body['thinking_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($enabled === null) stu_json(['ok'=>false, 'error'=>'invalid_thinking'], 400);
  try {
    $saved = coreui_ai_settings_patch($pdo, $uid, ['thinking_enabled'=>$enabled]);
    stu_json(['ok'=>true, 'thinking_enabled'=>$saved['thinking_enabled']]);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
}
// 0.5.75: Core chosen in the chat menu. Only installed Cores are accepted;
// the answer goes out at once, then a local Core is loaded in the background.
if (($body['action'] ?? '') === 'core') {
  $name = trim((string)($body['model_override'] ?? ''));
  if ($name !== '') {
    if (!coreui_model_name_valid($name)) stu_json(['ok'=>false, 'error'=>'invalid_model_name'], 400);
    try {
      if (!coreui_ollama_model_exists($name)) stu_json(['ok'=>false, 'error'=>'model_not_available'], 400);
    } catch (RuntimeException $e) {
      stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
    }
  }
  try {
    coreui_ai_settings_patch($pdo, $uid, ['model_override'=>$name]);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  $model = $name !== '' ? $name : (defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest');
  $cloud = preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $model) === 1;
  $preload = $cloud ? 'cloud'
    : (in_array(coreui_ollama_model_key($model), coreui_ollama_loaded_names(), true) ? 'loaded' : 'started');
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode(['ok'=>true, 'model_override'=>$name, 'model'=>$model, 'preload'=>$preload], JSON_UNESCAPED_UNICODE);
  if ($preload !== 'started') exit;
  if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
  ignore_user_abort(true);
  @set_time_limit(420);
  define('STU_CHAT_LIB', 1);
  require_once __DIR__ . '/chat.php';
  require_once __DIR__ . '/core_preload.php';
  try {
    coreui_core_preload($pdo, $uid, $model);
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) stu__log_error(['type'=>'core_preload_failed', 'uid'=>$uid, 'message'=>$e->getMessage()]);
  }
  exit;
}
// The KI-Profil no longer carries the switch: keep the stored value.
if (!array_key_exists('thinking_enabled', $body)) {
  $body['thinking_enabled'] = coreui_ai_settings_load($pdo, $uid)['thinking_enabled'];
}
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
