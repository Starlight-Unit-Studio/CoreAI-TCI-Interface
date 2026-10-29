<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/ai_context_estimate.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/model_access.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
// 0.5.79: guests use the server's default Core with its own window. Core and
// window are per account, but switching them loads another Core into the RAM
// that all accounts share (only one Core stays loaded), so guests cannot.
$isGuest = coreui_guest_is($pdo, (int)$uid);

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

// 0.5.76: chat installer for missing tiers. Every account sees what is
// missing and the progress; the hardware check and installing are for admins.
if ($method === 'GET' && (array_key_exists('core_install', $_GET) || array_key_exists('core_install_status', $_GET))) {
  require_once __DIR__ . '/core_install.php';
  $level = stu_get_permission_level($pdo);
  $csrf = stu_csrf_token();
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  if (!array_key_exists('core_install', $_GET)) stu_json(['ok'=>true, 'install'=>coreui_core_install_status()]);
  $admin = $level <= COREUI_CORE_INSTALL_PERMISSION_MAX;
  try {
    $check = coreui_core_install_check(trim((string)$_GET['core_install']), $admin);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
  }
  stu_json(['ok'=>true, 'check'=>$check, 'can_install'=>$admin, 'install'=>coreui_core_install_status(),
    'auto'=>$admin ? coreui_core_auto_update($pdo) : null, 'csrf_token'=>$csrf]);
}

// 0.5.76: Cores verwalten (admins): every tier with its Cores, what an
// update would touch, the weekly switch and the last job.
if ($method === 'GET' && array_key_exists('core_manage', $_GET)) {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  $csrf = stu_csrf_token();
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  try {
    $models = coreui_ollama_models();
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  stu_json(['ok'=>true, 'tiers'=>coreui_core_manage_rows($models), 'plan'=>coreui_core_update_plan($models),
    'auto'=>coreui_core_auto_update($pdo), 'install'=>coreui_core_install_status(), 'csrf_token'=>$csrf]);
}

// 0.5.76: Ollama Cloud account of the server (admins), like `ollama signin`.
if ($method === 'GET' && array_key_exists('core_cloud', $_GET)) {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  stu_json(['ok'=>true, 'cloud'=>coreui_ollama_cloud_status()]);
}

if ($method === 'GET') {
  $settings = coreui_ai_settings_load($pdo, $uid);
  // 0.5.83: a Core whose release was withdrawn no longer counts as chosen.
  if (!coreui_model_access_allowed($pdo, $uid, (string)$settings['model_override'])) $settings['model_override'] = '';
  $defaultModel = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '';
  $payload = [
    'ok' => true,
    'settings' => $settings,
    // 0.5.76: TCI names for the chat chip.
    'default_label' => $defaultModel !== '' ? coreui_core_label($defaultModel) : '',
    'core_label' => coreui_core_label((string)($settings['model_override'] ?: $defaultModel)),
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
    'is_guest' => $isGuest,
  ];
  // 0.5.76: weekly Core update when an admin switched it on. The first page
  // load after the due date starts it in the background, for any account.
  require_once __DIR__ . '/core_install.php';
  if (!coreui_core_auto_update_due(coreui_core_auto_update($pdo), time())) stu_json($payload);
  stu_app_setting_set($pdo, 'core_update_last', (string)time());
  try {
    $lock = coreui_core_install_lock();
    $plan = $lock === false ? [] : coreui_core_update_plan(coreui_ollama_models());
  } catch (RuntimeException $e) {
    $plan = [];
  }
  if ($plan === []) {
    if (!empty($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    stu_json($payload);
  }
  $state = coreui_core_update_begin($plan);
  coreui_core_job_detach($payload);
  coreui_core_job_run($lock, 'coreui_core_update_run', $state, $uid);
  exit;
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
  if ($isGuest) stu_json(['ok'=>false, 'error'=>'guest_core_locked'], 403);
  $name = trim((string)($body['model_override'] ?? ''));
  if ($name !== '') {
    if (!coreui_model_name_valid($name)) stu_json(['ok'=>false, 'error'=>'invalid_model_name'], 400);
    try {
      if (!coreui_ollama_model_exists($name)) stu_json(['ok'=>false, 'error'=>'model_not_available'], 400);
    } catch (RuntimeException $e) {
      stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
    }
    // 0.5.83: a model outside CoreAI needs the Inhaber's release.
    if (!coreui_model_access_allowed($pdo, $uid, $name)) stu_json(['ok'=>false, 'error'=>'model_not_allowed'], 403);
  }
  try {
    coreui_ai_settings_patch($pdo, $uid, ['model_override'=>$name]);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  $model = $name !== '' ? $name : (defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest');
  $cloud = preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $model) === 1;
  $loadedNow = coreui_ollama_loaded_names();
  $preload = $cloud ? 'cloud'
    : (in_array(coreui_ollama_model_key($model), $loadedNow, true) ? 'loaded' : 'started');
  // 0.5.77: other local Cores leave the RAM first, also when the chosen one is already loaded.
  $unloadOthers = !$cloud && count($loadedNow) > ($preload === 'loaded' ? 1 : 0);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode(['ok'=>true, 'model_override'=>$name, 'model'=>$model, 'label'=>coreui_core_label($model),
    'preload'=>$preload], JSON_UNESCAPED_UNICODE);
  if ($preload !== 'started' && !$unloadOthers) exit;
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
// 0.5.76: install a missing tier from the chat. Admins only, one job at a
// time; the answer goes out at once, the job reports into the status file.
if (($body['action'] ?? '') === 'core_install') {
  require_once __DIR__ . '/core_install.php';
  if (stu_get_permission_level($pdo) > COREUI_CORE_INSTALL_PERMISSION_MAX) {
    stu_json(['ok'=>false, 'error'=>'install_not_allowed'], 403);
  }
  try {
    $check = coreui_core_install_check(trim((string)($body['tier'] ?? '')));
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
  }
  if ($check['installed']) stu_json(['ok'=>false, 'error'=>'already_installed'], 409);
  if ($check['blocked']) stu_json(['ok'=>false, 'error'=>'install_blocked', 'check'=>$check], 409);
  // Advised against: only with a second, explicit confirmation.
  if ($check['verdict'] === 'abraten' && ($body['confirm'] ?? false) !== true) {
    stu_json(['ok'=>false, 'error'=>'install_confirm_required', 'check'=>$check], 409);
  }
  try {
    $lock = coreui_core_install_lock();
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  if ($lock === false) stu_json(['ok'=>false, 'error'=>'install_running', 'install'=>coreui_core_install_status()], 409);
  $state = coreui_core_install_begin($check);
  coreui_core_job_detach(['ok'=>true, 'install'=>['running'=>true] + array_diff_key($state, ['layers'=>true])]);
  coreui_core_job_run($lock, 'coreui_core_install_run', $state, $uid);
  exit;
}
// 0.5.76: add a cloud Core (admins), only with the server's Ollama connected.
if (($body['action'] ?? '') === 'core_cloud_add') {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  $tier = trim((string)($body['tier'] ?? ''));
  if (!isset(COREUI_CORE_CLOUD_CATALOG[$tier])) stu_json(['ok'=>false, 'error'=>'unknown_tier'], 400);
  try {
    if (coreui_ollama_model_exists(COREUI_CORE_CLOUD_CATALOG[$tier])) stu_json(['ok'=>false, 'error'=>'already_installed'], 409);
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  if ((coreui_ollama_cloud_status()['account']['state'] ?? '') !== 'connected') {
    stu_json(['ok'=>false, 'error'=>'cloud_signin_required'], 409);
  }
  try {
    $lock = coreui_core_install_lock();
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  if ($lock === false) stu_json(['ok'=>false, 'error'=>'install_running', 'install'=>coreui_core_install_status()], 409);
  $state = coreui_core_cloud_begin($tier);
  coreui_core_job_detach(['ok'=>true, 'install'=>['running'=>true] + array_diff_key($state, ['layers'=>true])]);
  coreui_core_job_run($lock, 'coreui_core_install_run', $state, $uid);
  exit;
}
// 0.5.77: "Deinstallieren" in Cores verwalten (admins): the local Cores of a
// tier (never Mara's own Core) or its cloud Cores.
if (($body['action'] ?? '') === 'core_uninstall') {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  $tier = trim((string)($body['tier'] ?? ''));
  if (!isset(COREUI_CORE_INSTALL_CATALOG[$tier])) stu_json(['ok'=>false, 'error'=>'unknown_tier'], 400);
  try {
    $rows = array_column(coreui_core_manage_rows(coreui_ollama_models()), null, 'tier');
    $lock = coreui_core_install_lock();
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  if ($lock === false) stu_json(['ok'=>false, 'error'=>'install_running', 'install'=>coreui_core_install_status()], 409);
  $targets = coreui_core_uninstall_targets($rows[$tier], ($body['cloud'] ?? false) === true);
  if ($targets === []) {
    flock($lock, LOCK_UN);
    fclose($lock);
    stu_json(['ok'=>false, 'error'=>'nothing_to_remove'], 409);
  }
  try {
    $result = coreui_core_uninstall($pdo, $targets);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
  stu_json(['ok'=>$result['failed'] === [], 'error'=>$result['failed'] === [] ? null : 'uninstall_partial'] + $result,
    $result['failed'] === [] ? 200 : 502);
}
// 0.5.76: weekly update switch (admins).
if (($body['action'] ?? '') === 'core_auto_update') {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  $enabled = filter_var($body['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($enabled === null) stu_json(['ok'=>false, 'error'=>'invalid_auto_update'], 400);
  stu_json(['ok'=>true, 'auto'=>coreui_core_auto_update_set($pdo, $enabled)]);
}
// 0.5.76: update every local tier now (admins); same lock as the installer.
// 0.5.77: with a tier, only that tier (button in its row).
if (($body['action'] ?? '') === 'core_update') {
  require_once __DIR__ . '/core_install.php';
  stu_require_permission_at_most(COREUI_CORE_INSTALL_PERMISSION_MAX, $pdo);
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  $onlyTier = trim((string)($body['tier'] ?? ''));
  if ($onlyTier !== '' && !isset(COREUI_CORE_INSTALL_CATALOG[$onlyTier])) stu_json(['ok'=>false, 'error'=>'unknown_tier'], 400);
  try {
    $plan = array_values(array_filter(coreui_core_update_plan(coreui_ollama_models()),
      static fn(array $item): bool => $onlyTier === '' || $item['tier'] === $onlyTier));
    $lock = $plan === [] ? null : coreui_core_install_lock();
  } catch (RuntimeException $e) {
    stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
  }
  if ($plan === []) stu_json(['ok'=>false, 'error'=>'nothing_to_update'], 409);
  if ($lock === false) stu_json(['ok'=>false, 'error'=>'install_running', 'install'=>coreui_core_install_status()], 409);
  stu_app_setting_set($pdo, 'core_update_last', (string)time());
  $state = coreui_core_update_begin($plan);
  coreui_core_job_detach(['ok'=>true, 'install'=>['running'=>true] + array_diff_key($state, ['layers'=>true, 'plan'=>true])]);
  coreui_core_job_run($lock, 'coreui_core_update_run', $state, $uid);
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
if ($isGuest) {
  $wantsModel = trim((string)($body['model_override'] ?? '')) !== '';
  $wantsCtx = $requestedCtx !== null && $requestedCtx !== '';
  if ($wantsModel || $wantsCtx) stu_json(['ok'=>false, 'error'=>'guest_core_locked'], 403);
  $body['model_override'] = '';
  $body['num_ctx'] = null;
}
// 0.5.79: the Core is chosen in the chat menu only; the KI-Profil keeps the
// stored choice (also one Ollama no longer lists, so saving never fails on it).
$keepModel = !array_key_exists('model_override', $body);
if ($keepModel) {
  $body['model_override'] = (string)(coreui_ai_settings_load($pdo, $uid)['model_override'] ?? '');
}
try {
  $requestedModel = trim((string)($body['model_override'] ?? ''));
  if ($requestedModel !== '' && !$keepModel) {
    if (!coreui_model_name_valid($requestedModel)) {
      stu_json(['ok'=>false, 'error'=>'invalid_model_name'], 400);
    }
    if (!coreui_model_access_allowed($pdo, $uid, $requestedModel)) {
      stu_json(['ok'=>false, 'error'=>'model_not_allowed'], 403);
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
