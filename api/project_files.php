<?php
declare(strict_types=1);

/*
 * 0.7.69 (P09): the files of a chat project (tab "Dateien" on the project page).
 *
 * GET  ?project_id=P                         list
 * GET  ?project_id=P&path=X                  one file with its revisions
 * GET  ?project_id=P&path=X&revision=N       one earlier state
 * GET  ?project_id=P&path=X&download=1       the file as a download (never inline)
 * GET  ?project_id=P&search=Q                paths and lines that contain Q
 * GET  ?project_id=P&zip=1                   the current state as a ZIP
 * POST {action: create|save|rename|delete|restore, project_id, ...} with CSRF
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/project_files_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function coreui_pfiles_send_download(string $name, string $type, string $path): void {
  header('Content-Type: ' . $type);
  header('X-Content-Type-Options: nosniff');
  header("Content-Security-Policy: default-src 'none'; sandbox");
  header('Cache-Control: private, no-store');
  header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
  header('Content-Length: ' . (string)filesize($path));
  readfile($path);
}

try {
  if ($method === 'GET') {
    $projectId = coreui_project_id($_GET['project_id'] ?? '');
    $path = (string)($_GET['path'] ?? '');
    if (isset($_GET['zip'])) {
      $zip = coreui_pfiles_zip($pdo, $uid, $projectId);
      try {
        coreui_pfiles_send_download($zip['name'], 'application/zip', $zip['path']);
      } finally {
        @unlink($zip['path']);
      }
      exit;
    }
    if (isset($_GET['search'])) {
      stu_json(['ok'=>true, 'results'=>coreui_pfiles_search($pdo, $uid, $projectId, (string)$_GET['search'])]);
    }
    if ($path !== '' && isset($_GET['revision'])) {
      stu_json(['ok'=>true, 'revision'=>coreui_pfiles_revision($pdo, $uid, $projectId, $path, (int)$_GET['revision'])]);
    }
    if ($path !== '' && isset($_GET['download'])) {
      $file = coreui_pfiles_get($pdo, $uid, $projectId, $path);
      // Text as a plain download: an HTML file is never rendered from this server.
      header('Content-Type: text/plain; charset=utf-8');
      header('X-Content-Type-Options: nosniff');
      header("Content-Security-Policy: default-src 'none'; sandbox");
      header('Cache-Control: private, no-store');
      header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($file['path'])));
      header('Content-Length: ' . (string)strlen($file['content']));
      echo $file['content'];
      exit;
    }
    if ($path !== '') stu_json(['ok'=>true, 'file'=>coreui_pfiles_get($pdo, $uid, $projectId, $path)]);
    stu_json(['ok'=>true] + coreui_pfiles_list($pdo, $uid, $projectId) + ['csrf_token'=>stu_csrf_token()]);
  }

  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  stu_require_csrf();
  $body = stu_read_json_body();
  $projectId = coreui_project_id($body['project_id'] ?? '');
  $action = (string)($body['action'] ?? '');
  $path = (string)($body['path'] ?? '');
  $content = $body['content'] ?? null;
  if (in_array($action, ['create', 'save'], true) && !is_string($content)) throw new InvalidArgumentException('invalid_content');
  switch ($action) {
    case 'create':
      $result = coreui_pfiles_write($pdo, $uid, $projectId, $path, $content, 'create', ['create_only'=>true]);
      stu_json(['ok'=>true, 'file'=>$result['file']], 201);
    case 'save':
      $base = (string)($body['base_sha256'] ?? '');
      if (preg_match('~^[a-f0-9]{64}$~D', $base) !== 1) throw new InvalidArgumentException('invalid_base');
      $result = coreui_pfiles_write($pdo, $uid, $projectId, $path, $content, 'edit', ['base_sha256'=>$base]);
      stu_json(['ok'=>true, 'file'=>$result['file'], 'changed'=>$result['changed']]);
    case 'rename':
      stu_json(['ok'=>true, 'file'=>coreui_pfiles_rename($pdo, $uid, $projectId, $path, (string)($body['new_path'] ?? ''))]);
    case 'delete':
      coreui_pfiles_delete($pdo, $uid, $projectId, $path);
      stu_json(['ok'=>true]);
    case 'restore':
      $result = coreui_pfiles_restore($pdo, $uid, $projectId, $path, (int)($body['revision'] ?? 0));
      stu_json(['ok'=>true, 'file'=>$result['file'], 'changed'=>$result['changed']]);
  }
  stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  $error = $e->getMessage();
  if ($e instanceof PDOException) {
    error_log('[CoreAI] project files: ' . get_class($e));
    stu_json(['ok'=>false, 'error'=>'project_files_failed'], 500);
  }
  $status = match (true) {
    in_array($error, ['project_not_found', 'file_not_found', 'revision_not_found'], true) => 404,
    in_array($error, ['file_exists', 'file_changed'], true) => 409,
    in_array($error, ['project_full', 'storage_full'], true) => 413,
    str_contains($error, 'migration') => 503,
    default => 422,
  };
  stu_json(['ok'=>false, 'error'=>$error], $status);
} catch (Throwable $e) {
  error_log('[CoreAI] project files failed: ' . get_class($e));
  stu_json(['ok'=>false, 'error'=>'project_files_failed'], 500);
}
