<?php
declare(strict_types=1);

/*
 * Settings > Personalisierung (0.5.91; 0.7.0 rebuilt after the reference screenshots).
 *   GET                                  settings, memory_enabled, references, available, lockdown
 *   POST {action:'save', settings:{...}} changes only the keys sent (every switch saves on its own)
 *   POST {action:'memory', enabled}      the same server switch as Memory > Langzeitgedaechtnis
 *   POST {action:'reference', slot:'portrait'|'body', uuid|null}
 *        a reference photo for images of the person: an image uploaded with api/console_upload.php
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/security_store.php';
require_once __DIR__ . '/image_gen.php';
require_once __DIR__ . '/console_personalization_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * What this account can use at all: the operator's switches, the account's tool rules and the
 * Lockdown-Modus (Settings > Sicherheit) decide; a personal switch can only opt out.
 */
function coreui_personalization_available(PDO $pdo, int $uid): array {
  try {
    coreui_ai_runtime_apply($pdo, $uid);
  } catch (Throwable $error) {
    return ['web' => true, 'browse' => true, 'image' => false, 'lockdown' => false];
  }
  $tools = $GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] ?? [];
  $lockdown = false;
  try {
    $lockdown = coreui_security_lockdown($pdo, $uid);
  } catch (Throwable $error) { /* security schema missing: no lockdown */ }
  $image = false;
  try {
    $image = coreui_image_tool_allowed($pdo, $uid);
  } catch (Throwable $error) { /* image service not set up */ }
  return ['web' => ($tools['web'] ?? true) === true, 'browse' => ($tools['browse'] ?? true) === true,
    'image' => $image, 'lockdown' => $lockdown];
}

function coreui_personalization_payload(PDO $pdo, int $uid, array $settings): array {
  return ['settings' => $settings, 'references' => coreui_personalization_references_view($pdo, $uid, $settings)];
}

if ($method === 'GET') {
  $settings = coreui_personalization_load($pdo,(int)$uid);
  stu_json([
    'ok' => true,
    'memory_enabled' => coreui_ai_settings_load($pdo,(int)$uid)['memory_enabled'],
    'available' => coreui_personalization_available($pdo,(int)$uid),
    'schema_available' => coreui_personalization_schema_ready($pdo),
    'csrf_token' => stu_csrf_token()
  ] + coreui_personalization_payload($pdo,(int)$uid,$settings));
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
    $saved = coreui_personalization_update($pdo,(int)$uid,$body['settings']);
    stu_json(['ok'=>true] + coreui_personalization_payload($pdo,(int)$uid,$saved));
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
  if ($action === 'reference') {
    $slot = (string)($body['slot'] ?? '');
    $uuid = $body['uuid'] ?? null;
    if (!isset(COREUI_PERSONALIZATION_REFERENCE_SLOTS[$slot]) || ($uuid !== null && !is_string($uuid))) {
      stu_json(['ok'=>false,'error'=>'invalid_reference'],400);
    }
    // A new photo only where Mara can make images; removing one always works.
    if ($uuid !== null && !coreui_image_tool_allowed($pdo,(int)$uid)) {
      stu_json(['ok'=>false,'error'=>'image_unavailable'],409);
    }
    $saved = coreui_personalization_reference_set($pdo,(int)$uid,$slot,$uuid);
    stu_json(['ok'=>true] + coreui_personalization_payload($pdo,(int)$uid,$saved));
  }
  stu_json(['ok'=>false,'error'=>'invalid_action'],400);
} catch (InvalidArgumentException $error) {
  stu_json(['ok'=>false,'error'=>$error->getMessage() === 'invalid_slot' ? 'invalid_reference' : $error->getMessage()],400);
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
