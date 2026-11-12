<?php
declare(strict_types=1);

/*
 * 0.7.28: Settings > Speicher. Signed-in accounts only, never guests; only the account's own items.
 *
 *   GET  ?action=summary                       used, limit (bytes or null), Dateien and Bilder, free disk
 *   GET  ?action=list&view=files|images&tab=..&q=..&type=..&project=..&offset=N
 *   POST ?action=favorite {id, on}             Dateien: Favoriten
 *   POST ?action=delete {id}                   deletes for good; the chat that used it no longer shows it
 *
 * Uploading goes through api/console_upload.php like in the chat (it checks the limit).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/files_store.php';
require_once __DIR__ . '/console_personalization_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'summary')));
if (coreui_guest_is($pdo, $uid)) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);

if ($method === 'GET' && $action === 'summary') {
  $usage = coreui_files_usage($pdo, $uid);
  $limit = coreui_files_limit_bytes($pdo, $uid);
  $prefs = coreui_personalization_load($pdo, $uid);
  stu_json(['ok' => true, 'used' => $usage['used'], 'limit' => $limit, 'files' => $usage['files'], 'images' => $usage['images'],
    'disk_free' => $limit === null ? coreui_files_disk_free() : null, 'favorites_ready' => coreui_files_favorites_ready($pdo),
    'references' => coreui_personalization_references_view($pdo, $uid, $prefs), 'csrf_token' => stu_csrf_token()]);
}

if ($method === 'GET' && $action === 'list') {
  stu_json(['ok' => true] + coreui_files_list($pdo, $uid, $_GET));
}

if ($method !== 'POST') stu_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
stu_require_csrf();
$body = stu_read_json_body();
$key = (string)($body['id'] ?? '');

if ($action === 'favorite') {
  $on = filter_var($body['on'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
  if ($on === null) stu_json(['ok' => false, 'error' => 'invalid_favorite'], 400);
  try {
    stu_json(['ok' => true, 'id' => $key, 'favorite' => coreui_files_favorite($pdo, $uid, $key, $on)]);
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => 'not_found'], 404);
  } catch (RuntimeException $e) {
    stu_json(['ok' => false, 'error' => $e->getMessage()], 503);
  }
}

if ($action === 'delete') {
  try {
    $freed = coreui_files_delete($pdo, $uid, $key);
  } catch (InvalidArgumentException $e) {
    stu_json(['ok' => false, 'error' => 'not_found'], 404);
  }
  $usage = coreui_files_usage($pdo, $uid);
  stu_json(['ok' => true, 'id' => $key, 'freed' => $freed, 'used' => $usage['used'], 'files' => $usage['files'], 'images' => $usage['images']]);
}

stu_json(['ok' => false, 'error' => 'unknown_action'], 404);
