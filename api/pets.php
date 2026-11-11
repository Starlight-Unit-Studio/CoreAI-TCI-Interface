<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.1: Settings > Pets.
 *   GET                       the built-in pets, the account's own pets, the choice, csrf_token
 *   GET  ?action=current      the chosen pet for the chat (null for "Pet nicht anzeigen")
 *   GET  ?action=sprite&id=N  the sprite sheet of an own pet (its owner only)
 *   POST multipart: name, description, file      upload an own pet (X-CoreUI-CSRF)
 *   POST {action:'select', pet}  |  {action:'delete', id}
 * See api/pets_store.php.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pets_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? '');

function coreui_pets_payload(PDO $pdo, int $uid): array {
  return ['ok' => true, 'builtin' => coreui_pet_builtin_view(),
    'library' => coreui_openpet_library_view(), 'own' => coreui_pet_own($pdo, $uid),
    'selected' => (string)(coreui_pet_current($pdo, $uid)['id'] ?? 'none'),
    'can_manage_library' => stu_permission_level_for_user($pdo, $uid) === COREUI_ROLE_OWNER,
    'upload_ready' => coreui_pet_schema_ready($pdo), 'csrf_token' => stu_csrf_token()];
}

try {
  if ($method === 'GET' && $action === 'sprite') {
    $row = coreui_pet_own_row($pdo, $uid, (int)($_GET['id'] ?? 0));
    $path = $row !== null ? coreui_pet_dir() . '/' . (string)$row['stored_name'] : '';
    if ($row === null || preg_match('~^pet_[a-f0-9]{32}\.(png|webp)$~D', (string)$row['stored_name']) !== 1 || !is_file($path)) {
      http_response_code(404);
      exit;
    }
    header('Content-Type: ' . ((string)$row['mime_type'] === 'image/webp' ? 'image/webp' : 'image/png'));
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }
  if ($method === 'GET' && $action === 'library_sprite') {
    $id = (string)($_GET['id'] ?? '');
    $path = coreui_openpet_library_asset($id);
    if ($path === null) { http_response_code(404); exit; }
    header('Content-Type: image/webp');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }
  if ($method === 'GET' && $action === 'current') {
    stu_json(['ok' => true, 'pet' => coreui_pet_current($pdo, $uid)]);
  }
  if ($method === 'GET') stu_json(coreui_pets_payload($pdo, $uid));
  if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
  stu_require_csrf();

  // Owner-only publication of licensed OpenPet packages; regular personal
  // uploads keep their existing path and account isolation.
  if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
      && ($_POST['action'] ?? '') === 'library_import') {
    if (stu_permission_level_for_user($pdo, $uid) !== COREUI_ROLE_OWNER) {
      stu_json(['ok'=>false,'error'=>'insufficient_permissions'], 403);
    }
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
      stu_json(['ok'=>false,'error'=>'no_file_uploaded'], 400);
    }
    $meta = [
      'license'=>(string)($_POST['license'] ?? ''),
      'rights_holder'=>(string)($_POST['rights_holder'] ?? ''),
      'source_url'=>(string)($_POST['source_url'] ?? ''),
      'evidence_url'=>(string)($_POST['evidence_url'] ?? ''),
      'rights_confirmed'=>(string)($_POST['rights_confirmed'] ?? ''),
    ];
    $pet = coreui_openpet_library_import((string)$upload['tmp_name'], (int)$upload['size'], $meta);
    stu_json(['ok'=>true, 'pet'=>$pet] + coreui_pets_payload($pdo, $uid));
  }

  // Upload of an own pet (multipart).
  if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
      $code = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
      stu_json(['ok' => false, 'error' => in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'file_too_large' : 'no_file_uploaded'], 400);
    }
    $filename = strtolower((string)($file['name'] ?? ''));
    $isZip = str_ends_with($filename, '.zip');
    $maxBytes = $isZip ? COREUI_OPENPET_ZIP_MAX_BYTES : COREUI_PET_MAX_BYTES;
    if ((int)$file['size'] > $maxBytes) stu_json(['ok' => false, 'error' => 'file_too_large'], 400);
    if ($isZip) {
      $import = coreui_pet_personal_openpet_read((string)$file['tmp_name'], (int)$file['size']);
      $pet = coreui_pet_add($pdo, $uid, $import['name'], $import['description'], $import['bytes']);
    } else {
      $bytes = (string)file_get_contents((string)$file['tmp_name']);
      $pet = coreui_pet_add($pdo, $uid, (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), $bytes);
    }
    stu_json(['ok' => true, 'pet' => $pet] + coreui_pets_payload($pdo, $uid));
  }

  $body = stu_read_json_body();
  $act = (string)($body['action'] ?? '');
  if ($act === 'library_remove') {
    if (stu_permission_level_for_user($pdo, $uid) !== COREUI_ROLE_OWNER) {
      stu_json(['ok'=>false,'error'=>'insufficient_permissions'], 403);
    }
    if (!coreui_openpet_library_remove((string)($body['id'] ?? ''))) {
      stu_json(['ok'=>false,'error'=>'pet_not_found'], 404);
    }
    stu_json(coreui_pets_payload($pdo, $uid));
  }
  if ($act === 'select') {
    coreui_pet_choose($pdo, $uid, (string)($body['pet'] ?? 'none'));
    stu_json(coreui_pets_payload($pdo, $uid));
  }
  if ($act === 'delete') {
    $id = (string)($body['id'] ?? '');
    if (preg_match('~^u([1-9][0-9]{0,18})$~D', $id, $m) !== 1 || !coreui_pet_delete($pdo, $uid, (int)$m[1])) {
      stu_json(['ok' => false, 'error' => 'pet_not_found'], 404);
    }
    stu_json(coreui_pets_payload($pdo, $uid));
  }
  stu_json(['ok' => false, 'error' => 'invalid_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = $e->getMessage();
  if ($code === 'missing_schema_034' || $code === 'missing_schema_022' || $code === 'zip_extension_missing') stu_json(['ok' => false, 'error' => $code], 503);
  error_log('[CoreAI] Pets failed: ' . get_class($e));
  stu_json(['ok' => false, 'error' => 'pets_failed'], 500);
} catch (Throwable $e) {
  error_log('[CoreAI] Pets failed: ' . get_class($e));
  stu_json(['ok' => false, 'error' => 'pets_failed'], 500);
}
