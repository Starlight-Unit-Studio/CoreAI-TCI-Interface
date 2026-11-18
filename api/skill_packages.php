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
 * 0.7.84 (P12 B):
 *   GET  ?action=view&id=..         a shared skill of another account, read only
 *   POST ?action=use {id, on}       use a shared skill or switch it off for this account
 *   GET  ?action=chat&session=..    the skills a chat can fix and the ones it fixed
 *   POST ?action=chat_set {session, refs}   fixes up to three skills for a chat ([] lets Mara choose)
 * 0.7.90 (P13 A):
 *   GET  ?action=history&id=..      the revisions of a skill, newest first, with what each changed
 *   GET  ?action=revision&id=..&rev=..       one revision with its instruction and files
 *   POST ?action=restore {id, rev, base_revision}   brings a revision back as a new one on top
 *   GET  ?action=export&id=..[&rev=..]       the skill (or one revision) as a ZIP with SKILL.md
 *   POST ?action=import (multipart: package, mode new|update, slug?, base_revision?)
 *   save, file_save and file_delete take base_revision; a change after someone else's is refused.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/skill_packages_store.php';
require_once __DIR__ . '/console_session_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
header('Cache-Control: private, no-store');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
if (coreui_guest_is($pdo, $uid)) stu_json(['ok'=>false, 'error'=>'guests_not_allowed'], 403);

$body = [];
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
    $query = (string)($_GET['q'] ?? '');
    stu_json(['ok'=>true, 'ready'=>true, 'skills'=>coreui_skillpkg_list($pdo, $uid, $query), 'builtin'=>$builtin,
      'projects'=>$projects, 'tools'=>COREUI_SKILLPKG_TOOLS,
      'sharing'=>coreui_skillpkg_sharing_ready($pdo), 'can_share'=>coreui_skillpkg_can_share($pdo, $uid), 'revisions'=>coreui_skillrev_ready($pdo),
      'shared'=>coreui_skillpkg_shared_list($pdo, $uid, $query),
      'limits'=>['skills'=>COREUI_SKILLPKG_MAX, 'files'=>COREUI_SKILLPKG_FILES, 'body_bytes'=>COREUI_SKILLPKG_BODY_BYTES,
        'file_bytes'=>COREUI_SKILLPKG_FILE_BYTES, 'triggers'=>COREUI_SKILLPKG_TRIGGERS],
      'csrf_token'=>stu_csrf_token()]);
  }
  if ($method === 'GET' && $action === 'get') {
    stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_get($pdo, $uid, (string)($_GET['id'] ?? ''))]);
  }
  if ($method === 'GET' && $action === 'history') {
    stu_json(['ok'=>true] + coreui_skillrev_list($pdo, $uid, (string)($_GET['id'] ?? '')));
  }
  if ($method === 'GET' && $action === 'revision') {
    stu_json(['ok'=>true, 'revision'=>coreui_skillrev_get($pdo, $uid, (string)($_GET['id'] ?? ''), (int)($_GET['rev'] ?? 0))]);
  }
  if ($method === 'GET' && $action === 'export') {
    $zip = coreui_skillrev_export($pdo, $uid, (string)($_GET['id'] ?? ''), $_GET['rev'] ?? null);
    try {
      header('Content-Type: application/zip');
      header('X-Content-Type-Options: nosniff');
      header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($zip['name']));
      header('Content-Length: ' . (string)filesize($zip['path']));
      readfile($zip['path']);
    } finally {
      @unlink($zip['path']);
    }
    exit;
  }
  if ($method === 'GET' && $action === 'view') {
    stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_shared_get($pdo, $uid, (string)($_GET['id'] ?? ''))]);
  }
  if ($method === 'GET' && $action === 'chat') {
    // A locked or foreign chat is refused like everywhere else in the console.
    $session = (string)coreui_console_session_require($pdo, $uid, (string)($_GET['session'] ?? ''))['id'];
    stu_json(['ok'=>true, 'csrf_token'=>stu_csrf_token()] + coreui_skillpkg_chat_options($pdo, $uid, $session));
  }
  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
  stu_require_csrf();
  if ($action === 'import') {
    // A package as an upload; nothing is kept but what the skill takes from it.
    $upload = $_FILES['package'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
      stu_json(['ok'=>false, 'error'=>'skill_package_missing'], 400);
    }
    $result = coreui_skillrev_import($pdo, $uid, (string)$upload['tmp_name'], (string)($_POST['mode'] ?? 'new'), trim((string)($_POST['slug'] ?? '')),
      $_POST['base_revision'] ?? null, (string)($upload['name'] ?? ''));
    stu_json(['ok'=>true] + $result, $result['created'] ? 201 : 200);
  }
  $body = stu_read_json_body();
  $id = (string)($body['id'] ?? '');
  switch ($action) {
    case 'save':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_save($pdo, $uid, $id, $body)], $id === '' ? 201 : 200);
    case 'delete':
      coreui_skillpkg_delete($pdo, $uid, $id);
      stu_json(['ok'=>true]);
    case 'file_save':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_file_save($pdo, $uid, $id, $body['path'] ?? '', $body['content'] ?? null, $body['old_path'] ?? '',
        $body['base_revision'] ?? null)]);
    case 'file_delete':
      stu_json(['ok'=>true, 'skill'=>coreui_skillpkg_file_delete($pdo, $uid, $id, $body['path'] ?? '', $body['base_revision'] ?? null)]);
    case 'restore':
      stu_json(['ok'=>true, 'skill'=>coreui_skillrev_restore($pdo, $uid, $id, $body['rev'] ?? null, $body['base_revision'] ?? null)]);
    case 'use':
      coreui_skillpkg_use_shared($pdo, $uid, $id, !empty($body['on']));
      stu_json(['ok'=>true, 'shared'=>coreui_skillpkg_shared_list($pdo, $uid)]);
    case 'chat_set':
      $session = (string)coreui_console_session_require($pdo, $uid, (string)($body['session'] ?? ''))['id'];
      stu_json(['ok'=>true] + coreui_skillpkg_chat_set($pdo, $uid, $session, $body['refs'] ?? null));
  }
  stu_json(['ok'=>false, 'error'=>'unknown_action'], 404);
} catch (InvalidArgumentException $e) {
  // 0.7.90: a package names the entry that cannot be taken.
  stu_json(['ok'=>false, 'error'=>$e->getMessage()] + ($e instanceof CoreUISkillPackageError && $e->path !== '' ? ['path'=>$e->path] : []), 400);
} catch (RuntimeException $e) {
  if ($e instanceof PDOException) {
    error_log('[CoreAI] skills: ' . get_class($e));
    stu_json(['ok'=>false, 'error'=>'skills_failed'], 500);
  }
  $error = $e->getMessage();
  $status = match (true) {
    in_array($error, ['skill_not_found', 'skill_file_not_found', 'project_not_found', 'session_not_found', 'skill_revision_not_found'], true) => 404,
    $error === 'skill_share_forbidden' => 403,
    in_array($error, ['skill_exists', 'skill_file_exists', 'skill_limit', 'skill_files_limit', 'skill_conflict'], true) => 409,
    str_contains($error, 'migration') => 503,
    default => 422,
  };
  $extra = $e instanceof CoreUISkillImportExists ? ['slug'=>$e->slug, 'title'=>$e->title] : [];
  if ($error === 'skill_conflict') {
    // 0.7.90: the revision that is current now, so the page can show what changed.
    try {
      $extra['revision'] = (int)(coreui_skillpkg_row($pdo, $uid, (string)($body['id'] ?? ($_GET['id'] ?? '')))['revision'] ?? 0);
    } catch (Throwable $ignored) {
    }
  }
  stu_json(['ok'=>false, 'error'=>$error] + $extra, $status);
} catch (Throwable $e) {
  error_log('[CoreAI] skills failed: ' . get_class($e));
  stu_json(['ok'=>false, 'error'=>'skills_failed'], 500);
}
