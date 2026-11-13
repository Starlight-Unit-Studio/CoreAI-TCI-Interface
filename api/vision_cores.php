<?php
declare(strict_types=1);

/**
 * Authenticated admin bridge to the localhost-only image service.
 * Never accepts arbitrary URLs or filesystem paths.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image_gen.php';

$pdo = stu_pdo();
stu_require_user_id();
stu_require_permission_at_most(1, $pdo);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
if ($method === 'POST') stu_require_csrf();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if ($method === 'GET') {
  $query = trim((string)($_GET['q'] ?? ''));
  if (mb_strlen($query) > 80) stu_json(['ok'=>false, 'error'=>'bad_query'], 400);
  $path = array_key_exists('q', $_GET) ? '/vision/search?q=' . rawurlencode($query) : '/vision';
  [$code, $result] = coreui_image_http('GET', $path, null, 13);
} else {
  $body = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($body)) stu_json(['ok'=>false, 'error'=>'bad_request'], 400);
  $action = (string)($body['action'] ?? '');
  $id = (string)($body['id'] ?? '');
  if (!in_array($action, ['install', 'select', 'uninstall'], true)
      || preg_match('~^(?:default|[0-9a-f]{24})$~D', $id) !== 1) {
    stu_json(['ok'=>false, 'error'=>'bad_request'], 400);
  }
  [$code, $result] = coreui_image_http('POST', '/vision', ['action'=>$action, 'id'=>$id], 16);
}
if (!is_array($result) || $code < 200 || $code >= 300 || empty($result['ok'])) {
  $status = in_array($code, [400, 403, 404, 409, 503], true) ? $code : 503;
  stu_json(['ok'=>false, 'error'=>(string)($result['error'] ?? 'vision_service_unavailable')], $status);
}
$result['csrf_token'] = stu_csrf_token();
stu_json($result);
