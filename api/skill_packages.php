<?php
declare(strict_types=1);

/*
 * 0.7.83 (P12 A): Settings > Skills. Signed-in accounts only, never guests; only the account's own skills.
 *
 *   GET  ?action=list&q=..          own skills, the built-in library, projects, tools, limits
 *   GET  ?action=get&id=..          one skill with its instruction and files
 *   POST ?action=save {id?, slug, title, description, body, triggers, requires, project_id, enabled}
 *   POST ?action=delete {id}
 *   POST ?action=file_save {id, path, content, old_path?}
 *   POST ?action=file_delete {id, path}
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/skill_packages_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
header('Cache-Control: private, no-store');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
if (coreui_guest_is($pdo, $uid)) stu_json(['ok'=>false, 'error'=>'guests_not_allowed'], 403);

try {
  if ($method === 'GET' && $action === 'list') {
    if (!coreui_skillpkg_ready($pdo)) stu_json(['ok'=>true, 'ready'=>false, 'csrf_token'=>stu_csrf_token()]);
    $builtin = [];
    foreach (coreui_skills_catalog() as $id => $skill) {
      $builtin[] = ['id'=>(string)$id, 'title'=>(string)$skill['title'], 'category'=>(string)($skill['category'] ?? ''),
        'description'=>(string)$skill['description'], 'required'=>($skill['required'] ?? false) === true,
        'references'=>count(coreui_skills_resource_paths((string)$id))];
    }
    $projects = [];
    try {
      foreach (coreui_project_list($pdo, $uid) as $project) $projects[] = ['id'=>(string)$project['id'], 'name'=>(string)$project['name']];
    } catch (Throwable $e) {
      $projects = [];
    }
    stu_json(['ok'=>true, 'ready'=>true, 'skills'=>coreui_skillpkg_list($pdo, $uid, (string)($_GET['q'] ?? '')), 'builtin'=>$builtin,
      'projects'=>$projects, 'tools'=>COREUI_SKILLPKG_TOOLS,
      'limits'=>['skills'=>COREUI_SKILLPKG_MAX, 'files'=>COREUI_SKILLPKG_FILES, 'body_bytes'=>COREUI_SKILLPKG_BODY_BYTES,
        'file_bytes'=>COREUI_SKILLPKG_FILE_BYTES, 'triggers'=>COREUI_SKILLPKG_TRIGGERS],
      'csrf_token'=>stu_csrf_token()]);
  }
  if ($method === 'GET' && $action === 'get') {
    stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_get($pdo, $uid, (string)($_GET['id'] ?? ''))]);
  }
  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  stu_require_csrf();
  $body = stu_read_json_body();
  $id = (string)($body['id'] ?? '');
  switch ($action) {
    case 'save':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_save($pdo, $uid, $id, $body)], $id === '' ? 201 : 200);
    case 'delete':
      coreui_skillpkg_delete($pdo, $uid, $id);
      stu_json(['ok'=>true]);
    case 'file_save':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_file_save($pdo, $uid, $id, $body['path'] ?? '', $body['content'] ?? null, $body['old_path'] ?? '')]);
    case 'file_delete':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_file_delete($pdo, $uid, $id, $body['path'] ?? '')]);
  }
  stu_json(['ok'=>false, 'error'=>'unknown_action'], 404);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  if ($e instanceof PDOException) {
    error_log('[CoreAI] skills: ' . get_class($e));
    stu_json(['ok'=>false, 'error'=>'skills_failed'], 500);
  }
  $error = $e->getMessage();
  $status = match (true) {
    in_array($error, ['skill_not_found', 'skill_file_not_found', 'project_not_found'], true) => 404,
    in_array($error, ['skill_exists', 'skill_file_exists', 'skill_limit', 'skill_files_limit'], true) => 409,
    str_contains($error, 'migration') => 503,
    default => 422,
  };
  stu_json(['ok'=>false, 'error'=>$error], $status);
} catch (Throwable $e) {
  error_log('[CoreAI] skills failed: ' . get_class($e));
  stu_json(['ok'=>false, 'error'=>'skills_failed'], 500);
}
