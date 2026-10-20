<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_artifact_store.php';

function coreui_artifact_fail(int $status, string $error): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: private, no-store');
  header('X-Content-Type-Options: nosniff');
  if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') echo $error;
  exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','HEAD'], true)) {
  header('Allow: GET, HEAD');
  coreui_artifact_fail(405, 'method_not_allowed');
}
$uid = stu_require_user_id();
$pdo = stu_pdo();
stu_enforce_maintenance($pdo, $uid);
$id = (string)($_GET['id'] ?? '');
if (!preg_match('~^[a-f0-9]{32}$~D', $id)) coreui_artifact_fail(400, 'invalid_artifact');
if (!coreui_console_artifact_schema_ready($pdo)) coreui_artifact_fail(503, 'artifact_migration_required');
try {
  $st = $pdo->prepare(coreui_console_artifact_select_sql() . ' AND a.user_id=? AND a.id=? LIMIT 1');
  $st->execute([$uid, $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) coreui_artifact_fail(404, 'artifact_not_found');
  $fh = coreui_console_artifact_open_verified($row);
} catch (Throwable $e) {
  coreui_artifact_fail($e->getMessage() === 'artifact_file_missing' ? 410 : 409, 'artifact_unavailable');
}
$name = (string)$row['orig_name'];
$fallback = preg_replace('~[^A-Za-z0-9._-]~', '_', $name) ?: 'download';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string)(int)$row['file_size']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Cross-Origin-Resource-Policy: same-origin');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($method === 'HEAD') { fclose($fh); exit; }
while (ob_get_level() > 0) @ob_end_clean();
while (!feof($fh) && !connection_aborted()) {
  $part = fread($fh, 262144);
  if ($part === false) break;
  echo $part;
  flush();
}
fclose($fh);
