<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_project_store.php';
require_once __DIR__ . '/console_agent_runs.php';
require_once __DIR__ . '/console_chat_tools.php';
require_once __DIR__ . '/console_chat_lock_store.php';
require_once __DIR__ . '/knowledge_store.php';
require_once __DIR__ . '/data_controls_store.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = stu_pdo();
$uid = stu_require_user_id();
stu_enforce_maintenance($pdo, (int)$uid);

if (!coreui_console_session_schema_ready($pdo)) {
  stu_json(['ok'=>false, 'error'=>'session_migration_required'], 503);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$body = [];
if ($method === 'POST') {
  $body = stu_read_json_body();
  $action = strtolower(trim((string)($body['action'] ?? $action)));
  if (str_starts_with($action,'project_') || str_starts_with($action,'chat_lock') || $action === 'chat_unlock'
    || in_array($action, ['archive_all', 'delete_all'], true)) stu_require_csrf();
}

try {
  // 0.7.32: Settings > Datenkontrollen. Counts, and every chat at once (never the locked ones).
  if ($action === 'data_summary' && $method === 'GET') {
    stu_json(['ok'=>true] + coreui_data_controls_summary($pdo, (int)$uid) + ['csrf_token'=>stu_csrf_token()]);
  }
  if ($method === 'POST' && $action === 'archive_all') {
    $archived = coreui_data_controls_archive_all($pdo, (int)$uid, (string)($body['confirm'] ?? ''));
    // archived_now: how many were archived just now; active/archived/locked: the counts afterwards.
    stu_json(['ok'=>true, 'archived_now'=>$archived] + coreui_data_controls_summary($pdo, (int)$uid));
  }
  if ($method === 'POST' && $action === 'delete_all') {
    $result = coreui_data_controls_delete_all($pdo, (int)$uid, (string)($body['confirm'] ?? ''));
    stu_json(['ok'=>true] + $result + ['summary'=>coreui_data_controls_summary($pdo, (int)$uid)]);
  }

  if ($action === 'list') {
    $includeArchived = !empty($_GET['include_archived']);
    $rows = coreui_console_session_list($pdo, (int)$uid, $includeArchived);
    // 0.6.19: a spinner in the list while Mara works in a chat (also from another tab or device).
    try {
      $working = coreui_agent_run_working_sessions($pdo, (int)$uid);
    } catch (Throwable $e) {
      $working = [];
    }
    foreach ($rows as &$row) $row['working'] = isset($working[(string)$row['id']]);
    unset($row);
    stu_json(['ok'=>true, 'sessions'=>$rows, 'count'=>count($rows)]);
  }

  // Locked chat titles are visible only in the owner's special folder.
  // Chat content, files, project context and normal session list stay blocked.
  if ($action === 'chat_lock_status' && $method === 'GET') {
    $ready=coreui_chat_lock_schema_ready($pdo);
    stu_json(['ok'=>true,'ready'=>$ready,
      'has_pin'=>$ready ? coreui_chat_lock_has_pin($pdo,(int)$uid) : false,
      'csrf_token'=>stu_csrf_token()]);
  }
  if ($action === 'chat_locked_list' && $method === 'GET') {
    stu_json(['ok'=>true,'sessions'=>coreui_chat_lock_list($pdo,(int)$uid)]);
  }

  if ($action === 'projects' && $method === 'GET') {
    stu_json(['ok'=>true,'projects'=>coreui_project_list($pdo,(int)$uid),'csrf_token'=>stu_csrf_token()]);
  }

  if ($method === 'POST' && $action === 'project_create') {
    $project = coreui_project_create($pdo,(int)$uid,(string)($body['name'] ?? ''),
      (string)($body['context_mode'] ?? 'open'));
    stu_json(['ok'=>true,'project'=>$project],201);
  }

  if ($method === 'POST' && $action === 'project_assign') {
    $id = coreui_console_session_normalize_id($body['id'] ?? '');
    if ($id === '') throw new InvalidArgumentException('invalid_session');
    $project = coreui_project_assign($pdo,(int)$uid,$id,(string)($body['project_id'] ?? ''));
    stu_json(['ok'=>true,'id'=>$id,'project'=>$project]);
  }

  if ($method === 'POST' && $action === 'project_update') {
    $project = coreui_project_update($pdo,(int)$uid,(string)($body['project_id'] ?? ''),
      (string)($body['name'] ?? ''),(string)($body['instructions_md'] ?? ''));
    stu_json(['ok'=>true,'project'=>$project]);
  }

  if ($method === 'POST' && $action === 'project_pin') {
    $project = coreui_project_pin($pdo,(int)$uid,(string)($body['project_id'] ?? ''),
      !empty($body['pinned']));
    stu_json(['ok'=>true,'project'=>$project]);
  }

  if ($method === 'POST' && $action === 'project_delete') {
    $id = coreui_project_id($body['project_id'] ?? '');
    if ($id === '' || !hash_equals('DELETE:'.$id,(string)($body['confirmation'] ?? '')))
      throw new InvalidArgumentException('delete_confirmation_required');
    stu_json(['ok'=>true] + coreui_project_delete($pdo,(int)$uid,$id));
  }

  // 0.6.87: Settings > Archivierte Chats (search by title; type all or cloud; sorted updated, created or alpha).
  // Scheduled tasks are planned.
  if ($action === 'archived') {
    $type = (string)($_GET['type'] ?? 'all');
    if (!in_array($type, ['all', 'cloud'], true)) $type = 'all';
    $sort = (string)($_GET['sort'] ?? 'updated');
    if (!in_array($sort, ['updated', 'created', 'alpha'], true)) $sort = 'updated';
    $rows = coreui_chat_archived($pdo, (int)$uid, mb_substr((string)($_GET['q'] ?? ''), 0, 120, 'UTF-8'), $type, $sort);
    stu_json(['ok'=>true, 'sessions'=>$rows, 'count'=>count($rows), 'projects_ready'=>coreui_project_schema_ready($pdo), 'tasks_ready'=>false]);
  }

  // 0.6.87: the chat menu: uploaded files, search inside the chat, the chat as Markdown.
  if (in_array($action, ['files', 'search', 'export'], true) && $method === 'GET') {
    $readId = coreui_console_session_normalize_id($_GET['id'] ?? '');
    if ($readId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);
    if ($action === 'files') stu_json(['ok'=>true, 'id'=>$readId, 'files'=>coreui_chat_files($pdo, (int)$uid, $readId)]);
    if ($action === 'search') {
      $hits = coreui_chat_search($pdo, (int)$uid, $readId, mb_substr((string)($_GET['q'] ?? ''), 0, 200, 'UTF-8'));
      stu_json(['ok'=>true, 'id'=>$readId, 'results'=>$hits, 'count'=>count($hits)]);
    }
    stu_json(['ok'=>true, 'id'=>$readId] + coreui_chat_export($pdo, (int)$uid, $readId));
  }

  if ($method !== 'POST') stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);

  if (in_array($action,['chat_lock_setup','chat_lock','chat_unlock','chat_lock_pin_change'],true)) {
    $guest=$pdo->prepare('SELECT is_guest FROM stu_users WHERE id=? LIMIT 1');
    $guest->execute([(int)$uid]);
    if ((int)$guest->fetchColumn() !== 0) throw new RuntimeException('guest_not_allowed');
    if ($action==='chat_lock_setup') {
      coreui_chat_lock_setup($pdo,(int)$uid,(string)($body['pin']??''),(string)($body['confirmation']??''));
      stu_json(['ok'=>true,'has_pin'=>true]);
    }
    if ($action==='chat_lock_pin_change') {
      coreui_chat_lock_change($pdo,(int)$uid,(string)($body['old_pin']??''),
        (string)($body['pin']??''),(string)($body['confirmation']??''));
      stu_json(['ok'=>true,'has_pin'=>true]);
    }
    $lockedId=coreui_console_session_normalize_id($body['id']??'');
    if ($lockedId==='') throw new InvalidArgumentException('invalid_session');
    if ($action==='chat_lock') {
      coreui_chat_lock_set($pdo,(int)$uid,$lockedId);
      stu_json(['ok'=>true,'id'=>$lockedId,'locked'=>true]);
    }
    coreui_chat_lock_unset($pdo,(int)$uid,$lockedId,(string)($body['pin']??''));
    stu_json(['ok'=>true,'id'=>$lockedId,'locked'=>false]);
  }

  if ($action === 'create') {
    $session = coreui_console_session_create($pdo, (int)$uid, (string)($body['title'] ?? ''), (string)($body['project_id'] ?? ''));
    stu_json(['ok'=>true, 'session'=>$session] + $session, 201);
  }

  $sessionId = coreui_console_session_normalize_id($body['id'] ?? $body['session_id'] ?? '');
  if ($sessionId === '') stu_json(['ok'=>false, 'error'=>'invalid_session'], 400);

  if ($action === 'rename') {
    $title = coreui_console_session_rename($pdo, (int)$uid, $sessionId, (string)($body['title'] ?? ''));
    stu_json(['ok'=>true, 'id'=>$sessionId, 'title'=>$title]);
  }

  if ($action === 'mark_read' || $action === 'update_since') {
    coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
    $messageId = max(0, (int)($body['message_id'] ?? $body['since_id'] ?? 0));
    coreui_console_session_mark_read($pdo, (int)$uid, $sessionId, $messageId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'last_read_message_id'=>$messageId]);
  }

  if ($action === 'archive') {
    coreui_console_session_archive($pdo, (int)$uid, $sessionId);
    coreui_chat_unpin_archived($pdo, (int)$uid, $sessionId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'archived'=>true]);
  }

  // 0.6.87: "Anpinnen" in the chat menu keeps a chat at the top of the list.
  if ($action === 'pin') {
    $pinned = coreui_chat_pin($pdo, (int)$uid, $sessionId, !empty($body['pinned']));
    stu_json(['ok'=>true, 'id'=>$sessionId, 'pinned'=>$pinned]);
  }

  if ($action === 'restore') {
    coreui_console_session_restore($pdo, (int)$uid, $sessionId);
    stu_json(['ok'=>true, 'id'=>$sessionId, 'archived'=>false]);
  }

  if ($action === 'delete' || $action === 'delete_permanently') {
    $confirmation = (string)($body['confirmation'] ?? '');
    if (!hash_equals('DELETE:' . $sessionId, $confirmation)) {
      throw new InvalidArgumentException('delete_confirmation_required');
    }
    // 0.6.87: "Löschen" in the chat menu deletes an active chat in one step: it is archived
    // first (the delete itself still only takes archived chats) and restored if deleting fails.
    $archivedNow = false;
    if (!empty($body['archive_first'])) {
      $current = coreui_console_session_require($pdo, (int)$uid, $sessionId, true);
      if (empty($current['archived_at'])) {
        coreui_console_session_archive($pdo, (int)$uid, $sessionId);
        $archivedNow = true;
      }
    }
    try {
      $deleted = coreui_console_session_delete($pdo, (int)$uid, $sessionId);
    } catch (Throwable $e) {
      if ($archivedNow) coreui_console_session_restore($pdo, (int)$uid, $sessionId);
      throw $e;
    }
    stu_json(['ok'=>true, 'id'=>$sessionId, 'deleted'=>true, 'counts'=>$deleted]);
  }

  stu_json(['ok'=>false, 'error'=>'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
  $code = $e->getMessage();
  $status = (in_array($code,['session_not_found','project_not_found'],true)) ? 404
    : (in_array($code, ['session_limit_reached', 'session_must_be_archived', 'session_busy', 'session_delete_conflict'], true) ? 409
      : (in_array($code,['missing_schema_032','project_migration_required','chat_lock_migration_required'],true) ? 503
        : ($code==='chat_pin_temporarily_blocked' ? 429 : 400)));
  stu_json(['ok'=>false, 'error'=>$code], $status);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_sessions_exception', 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'session_operation_failed'], 500);
}
