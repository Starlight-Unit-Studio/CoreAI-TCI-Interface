<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_session_store.php';
require_once __DIR__ . '/console_agent_runs.php';
require_once __DIR__ . '/console_chat_tools.php';

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
}

try {
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

  // 0.6.87: Settings > Archivierte Chats (search by title; type all or cloud; sorted updated, created or alpha).
  // Projects and scheduled tasks are planned.
  if ($action === 'archived') {
    $type = (string)($_GET['type'] ?? 'all');
    if (!in_array($type, ['all', 'cloud'], true)) $type = 'all';
    $sort = (string)($_GET['sort'] ?? 'updated');
    if (!in_array($sort, ['updated', 'created', 'alpha'], true)) $sort = 'updated';
    $rows = coreui_chat_archived($pdo, (int)$uid, mb_substr((string)($_GET['q'] ?? ''), 0, 120, 'UTF-8'), $type, $sort);
    stu_json(['ok'=>true, 'sessions'=>$rows, 'count'=>count($rows), 'projects_ready'=>false, 'tasks_ready'=>false]);
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

  if ($action === 'create') {
    $session = coreui_console_session_create($pdo, (int)$uid, (string)($body['title'] ?? ''));
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
  $status = ($code === 'session_not_found') ? 404
    : (in_array($code, ['session_limit_reached', 'session_must_be_archived', 'session_busy', 'session_delete_conflict'], true) ? 409
      : ($code === 'missing_schema_032' ? 503 : 400));
  stu_json(['ok'=>false, 'error'=>$code], $status);
} catch (Throwable $e) {
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_sessions_exception', 'message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false, 'error'=>'session_operation_failed'], 500);
}
