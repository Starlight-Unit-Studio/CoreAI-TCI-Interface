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
 * GET  ?project_id=P&preview=X               0.7.75 (P10 A): an HTML file as a sandboxed preview
 * GET  ?project_id=P&runs=1                  0.7.77 (P10 B): the latest runs and a suggested command
 * GET  ?project_id=P&run=N                   0.7.77 (P10 B): one run with its output
 * POST {action: run|run_cancel, ...}         0.7.77 (P10 B): a command in the sandbox, or its end
 * GET  ?project_id=P&procs=1                 0.7.78 (P10 C): processes, a suggested command and port
 * GET  ?project_id=P&proc=N&since=E          0.7.78 (P10 C): one process with its output after E
 * GET  ?project_id=P&proc_preview=N&path=/X  0.7.78 (P10 C): a page of its development server, sandboxed
 * POST {action: proc_start|proc_input|proc_stop|proc_fetch, ...}  0.7.78 (P10 C)
 * GET  ?project_id=P&vcs=1                   0.7.80 (P11 A): working states, unsaved changes, versions
 * GET  ?project_id=P&vcs_commit=N            0.7.80: one version with what it changed
 * GET  ?project_id=P&vcs_file=N|work&path=X  0.7.80: a file in a version or now
 * GET  ?project_id=P&vcs_zip=1               0.7.80: ZIP with a Git repository of the versions
 * POST {action: vcs_commit|vcs_branch|vcs_switch|vcs_restore|vcs_branch_delete, ...}  0.7.80
 * GET  ?project_id=P&vcs_merge=B             0.7.81 (P11 B): what merging working state B would change
 * POST {action: vcs_merge, branch_id, ours_tree, theirs_head, resolutions, message}  0.7.81
 * POST {action: create|save|rename|delete|restore, project_id, ...} with CSRF
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/project_files_store.php';
require_once __DIR__ . '/console_artifact_store.php';
require_once __DIR__ . '/guest_access.php';
require_once __DIR__ . '/project_preview_store.php';
require_once __DIR__ . '/project_runs_store.php';
require_once __DIR__ . '/project_procs_store.php';
require_once __DIR__ . '/project_vcs_store.php';

$pdo = stu_pdo();
$uid = (int)stu_require_user_id();
stu_enforce_maintenance($pdo, $uid);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/** 0.7.77 (P10 B): runs count as the Python tool (owner switch, account rules, guest switch). */
function coreui_pruns_allowed(PDO $pdo, int $uid): bool {
  require_once __DIR__ . '/ai_settings.php';
  try {
    coreui_ai_runtime_apply($pdo, $uid);
  } catch (Throwable $e) {
    return false;
  }
  return coreui_runtime_tool_enabled('python', defined('STU_EMBER_PY_ENABLED') ? (bool)STU_EMBER_PY_ENABLED : true);
}

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
    // 0.7.75 (P10 A): the page runs in an origin of its own (CSP sandbox), never with the account.
    if (isset($_GET['preview'])) {
      coreui_preview_headers();
      try {
        $page = coreui_pfiles_path((string)$_GET['preview']);
        if ($page === '' || !coreui_preview_is_page($page)) throw new InvalidArgumentException('preview_not_page');
        $files = coreui_preview_files($pdo, $uid, $projectId);
        if (!isset($files[$page])) throw new RuntimeException('file_not_found');
        echo coreui_preview_build($files, $page, './project_files.php?project_id=' . rawurlencode($projectId) . '&preview=');
      } catch (Throwable $e) {
        $code = $e->getMessage();
        if ($e instanceof PDOException) error_log('[CoreAI] project preview: ' . get_class($e));
        $text = [
          'preview_not_page' => 'Eine Vorschau gibt es nur für HTML-Seiten des Projekts.',
          'file_not_found' => 'Diese Seite gibt es im Projekt nicht mehr.',
          'project_not_found' => 'Das Projekt wurde nicht gefunden.',
          'invalid_path' => 'Ungültiger Pfad.',
        ][$code] ?? 'Die Vorschau konnte nicht geladen werden.';
        http_response_code(in_array($code, ['file_not_found', 'project_not_found'], true) ? 404 : ($e instanceof PDOException ? 500 : 422));
        echo coreui_preview_error_page($text);
      }
      exit;
    }
    // 0.7.78 (P10 C): a page of a development server in the sandbox, in an origin of its own.
    if (isset($_GET['proc_preview'])) {
      coreui_preview_headers();
      try {
        $procId = (int)$_GET['proc_preview'];
        $serverPath = coreui_pprocs_path($_GET['path'] ?? '/');
        $key = coreui_pprocs_key($_GET['k'] ?? '');
        $pageUrl = './project_files.php?project_id=' . rawurlencode($projectId) . '&proc_preview=' . $procId
          . ($key !== '' ? '&k=' . $key : '') . '&path=';
        echo coreui_pprocs_page(static fn(string $p): array => coreui_pprocs_fetch($pdo, $uid, $projectId, $procId, 'GET', $p),
          $serverPath, $pageUrl,
          static fn(array $paths): array => coreui_pprocs_fetch_many($pdo, $uid, $projectId, $procId,
            array_map(static fn(string $p): array => ['GET', $p, null, ''], $paths)),
          $key);
      } catch (Throwable $e) {
        $code = $e->getMessage();
        if ($e instanceof PDOException) error_log('[CoreAI] process preview: ' . get_class($e));
        $text = [
          'proc_not_running' => 'Der Prozess läuft nicht mehr. Bitte im Bereich Prozesse neu starten.',
          'proc_no_port' => 'Für diesen Prozess ist kein Port für die Vorschau angegeben.',
          'server_unreachable' => 'Auf dem angegebenen Port antwortet noch kein Server. Startet er noch, oder hört er auf einem anderen Port?',
          'fetch_timeout' => 'Der Server hat nicht rechtzeitig geantwortet.',
          'fetch_busy' => 'Gerade warten zu viele Anfragen an den Server. Bitte gleich noch einmal.',
          'invalid_server_path' => 'Ungültiger Pfad auf dem Server.',
          'proc_not_found' => 'Diesen Prozess gibt es nicht mehr.',
          'project_not_found' => 'Das Projekt wurde nicht gefunden.',
        ][$code] ?? 'Die Vorschau des Servers konnte nicht geladen werden.';
        http_response_code(in_array($code, ['proc_not_found', 'project_not_found'], true) ? 404
          : (in_array($code, ['server_unreachable', 'fetch_failed'], true) ? 502 : ($code === 'fetch_timeout' ? 504
          : ($e instanceof PDOException ? 500 : ($e instanceof InvalidArgumentException ? 400 : 409)))));
        echo coreui_preview_error_page($text);
      }
      exit;
    }
    if (isset($_GET['vcs_zip'])) {
      $zip = coreui_pvcs_git_zip($pdo, $uid, $projectId);
      try {
        coreui_pfiles_send_download($zip['name'], 'application/zip', $zip['path']);
      } finally {
        @unlink($zip['path']);
      }
      exit;
    }
    if (isset($_GET['zip'])) {
      $zip = coreui_pfiles_zip($pdo, $uid, $projectId);
      try {
        coreui_pfiles_send_download($zip['name'], 'application/zip', $zip['path']);
      } finally {
        @unlink($zip['path']);
      }
      exit;
    }
    if (isset($_GET['runs']) || isset($_GET['procs'])) {
      $paths = array_map(static fn(array $f): string => (string)$f['path'], coreui_pfiles_list($pdo, $uid, $projectId)['files'] ?? []);
      // 0.7.79: the scripts of a package.json decide the suggestion for a JavaScript project.
      $packageJson = in_array('package.json', $paths, true) ? (string)coreui_pfiles_get($pdo, $uid, $projectId, 'package.json')['content'] : null;
    }
    if (isset($_GET['runs'])) {
      stu_json(['ok'=>true, 'ready'=>coreui_pruns_ready($pdo), 'allowed'=>coreui_pruns_allowed($pdo, $uid),
        'runs'=>coreui_pruns_ready($pdo) ? coreui_pruns_list($pdo, $uid, $projectId) : [], 'suggestion'=>coreui_pruns_suggest($paths, $packageJson),
        'csrf_token'=>stu_csrf_token()]);
    }
    if (isset($_GET['run'])) {
      stu_json(['ok'=>true, 'run'=>coreui_pruns_get($pdo, $uid, $projectId, (int)$_GET['run'])]);
    }
    if (isset($_GET['procs'])) {
      $ready = coreui_pprocs_ready($pdo);
      stu_json(['ok'=>true, 'ready'=>$ready, 'allowed'=>coreui_pruns_allowed($pdo, $uid),
        'procs'=>$ready ? coreui_pprocs_list($pdo, $uid, $projectId) : [], 'suggestion'=>coreui_pprocs_suggest($paths, $packageJson),
        'csrf_token'=>stu_csrf_token()]);
    }
    if (isset($_GET['proc'])) {
      stu_json(['ok'=>true, 'proc'=>coreui_pprocs_get($pdo, $uid, $projectId, (int)$_GET['proc'], max(0, (int)($_GET['since'] ?? 0)))]);
    }
    // 0.7.80 (P11 A): versions and working states.
    if (isset($_GET['vcs'])) {
      if (!coreui_pvcs_ready($pdo)) stu_json(['ok'=>true, 'ready'=>false, 'csrf_token'=>stu_csrf_token()]);
      stu_json(['ok'=>true, 'ready'=>true] + coreui_pvcs_state($pdo, $uid, $projectId) + ['csrf_token'=>stu_csrf_token()]);
    }
    if (isset($_GET['vcs_commit'])) {
      stu_json(['ok'=>true] + coreui_pvcs_commit_changes($pdo, $uid, $projectId, (int)$_GET['vcs_commit']));
    }
    if (isset($_GET['vcs_merge'])) {
      stu_json(['ok'=>true, 'merge'=>coreui_pvcs_merge_preview($pdo, $uid, $projectId, (int)$_GET['vcs_merge'])]);
    }
    if (isset($_GET['vcs_file'])) {
      $which = (string)$_GET['vcs_file'] === 'work' ? 'work' : (int)$_GET['vcs_file'];
      stu_json(['ok'=>true, 'file'=>coreui_pvcs_file($pdo, $uid, $projectId, $which, $path)]);
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
    case 'import_chat':
      // A deliberate user action, authenticated with the account's CSRF token.
      // Never create a project merely because Mara generated a download.
      if (coreui_guest_is($pdo, $uid)) stu_json(['ok'=>false, 'error'=>'guests_not_allowed'], 403);
      $messageId = filter_var($body['message_id'] ?? null, FILTER_VALIDATE_INT);
      if ($messageId === false || $messageId === null || (int)$messageId < 1) {
        throw new InvalidArgumentException('invalid_message');
      }
      $result = coreui_pfiles_promote_chat_artifacts($pdo, $uid, (int)$messageId,
        (string)($body['project_name'] ?? ''));
      stu_json(['ok'=>true] + $result, 201);
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
    case 'run':
      if (!coreui_pruns_allowed($pdo, $uid)) stu_json(['ok'=>false, 'error'=>'python_disabled'], 403);
      stu_json(['ok'=>true, 'run'=>coreui_pruns_enqueue($pdo, $uid, $projectId, $body['command'] ?? '')], 202);
    case 'run_cancel':
      stu_json(['ok'=>true, 'run'=>coreui_pruns_cancel($pdo, $uid, $projectId, (int)($body['run_id'] ?? 0))]);
    case 'proc_start':
      if (!coreui_pruns_allowed($pdo, $uid)) stu_json(['ok'=>false, 'error'=>'python_disabled'], 403);
      stu_json(['ok'=>true, 'proc'=>coreui_pprocs_start($pdo, $uid, $projectId, $body['command'] ?? '', $body['port'] ?? null)], 202);
    case 'proc_input':
      stu_json(['ok'=>true, 'proc'=>coreui_pprocs_input($pdo, $uid, $projectId, (int)($body['proc_id'] ?? 0), $body['text'] ?? null,
        ($body['newline'] ?? true) !== false)]);
    case 'proc_stop':
      stu_json(['ok'=>true, 'proc'=>coreui_pprocs_stop($pdo, $uid, $projectId, (int)($body['proc_id'] ?? 0))]);
    case 'vcs_commit':
      stu_json(['ok'=>true, 'commit'=>coreui_pvcs_commit($pdo, $uid, $projectId, $body['message'] ?? ''),
        'state'=>coreui_pvcs_state($pdo, $uid, $projectId)], 201);
    case 'vcs_branch':
      stu_json(['ok'=>true, 'state'=>coreui_pvcs_branch_create($pdo, $uid, $projectId, $body['name'] ?? '', !empty($body['carry']))], 201);
    case 'vcs_switch':
      stu_json(['ok'=>true, 'state'=>coreui_pvcs_switch($pdo, $uid, $projectId, (int)($body['branch_id'] ?? 0))]);
    case 'vcs_restore':
      stu_json(['ok'=>true, 'state'=>coreui_pvcs_restore($pdo, $uid, $projectId, (int)($body['commit_id'] ?? 0))]);
    case 'vcs_branch_delete':
      stu_json(['ok'=>true, 'state'=>coreui_pvcs_branch_delete($pdo, $uid, $projectId, (int)($body['branch_id'] ?? 0))]);
    case 'vcs_merge':
      $merge = ['resolutions'=>$body['resolutions'] ?? [], 'message'=>$body['message'] ?? ''];
      // What the person saw in the preview; anything that changed since then is asked again.
      if (isset($body['ours_tree'])) $merge['ours_tree'] = (string)$body['ours_tree'];
      if (isset($body['theirs_head'])) $merge['theirs_head'] = (int)$body['theirs_head'];
      stu_json(['ok'=>true] + coreui_pvcs_merge($pdo, $uid, $projectId, (int)($body['branch_id'] ?? 0), $merge), 201);
    case 'proc_fetch':
      $sent = $body['body'] ?? null;
      if ($sent !== null && !is_string($sent)) throw new InvalidArgumentException('invalid_body');
      $answer = coreui_pprocs_fetch($pdo, $uid, $projectId, (int)($body['proc_id'] ?? 0), (string)($body['method'] ?? 'GET'),
        $body['path'] ?? '', $sent, (string)($body['content_type'] ?? ''));
      stu_json(['ok'=>true, 'response'=>coreui_pprocs_fetch_json($answer)]);
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
    in_array($error, ['project_not_found', 'artifact_not_found', 'file_not_found', 'revision_not_found', 'run_not_found', 'proc_not_found',
      'branch_not_found', 'commit_not_found'], true) => 404,
    in_array($error, ['file_exists', 'file_changed', 'no_artifacts', 'no_complete_artifacts', 'no_usable_project_files', 'run_busy', 'no_files', 'proc_busy', 'proc_not_running', 'proc_no_port',
      'input_busy', 'fetch_busy', 'nothing_to_commit', 'branch_exists', 'branch_active', 'branch_limit', 'history_full',
      'already_current', 'already_merged', 'merge_same', 'merge_changed', 'merge_unresolved'], true) => 409,
    in_array($error, ['run_limit', 'proc_limit', 'proc_active_limit'], true) => 429,
    in_array($error, ['server_unreachable', 'fetch_failed'], true) => 502,
    $error === 'fetch_timeout' => 504,
    in_array($error, ['project_full', 'storage_full'], true) => 413,
    str_contains($error, 'migration') => 503,
    default => 422,
  };
  stu_json(['ok'=>false, 'error'=>$error], $status);
} catch (Throwable $e) {
  error_log('[CoreAI] project files failed: ' . get_class($e));
  stu_json(['ok'=>false, 'error'=>'project_files_failed'], 500);
}
