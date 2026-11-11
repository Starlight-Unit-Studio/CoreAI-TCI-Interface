<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_project_store.php';
require_once __DIR__ . '/knowledge_store.php';

$pdo=stu_pdo();
$uid=(int)stu_require_user_id();
stu_enforce_maintenance($pdo,$uid);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body=[];
if ($method==='POST' && !str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),'multipart/form-data')) {
  $body=stu_read_json_body();
}
$projectId=coreui_project_id($_GET['project_id'] ?? $_POST['project_id'] ?? $body['project_id'] ?? '');
try {
  $project=coreui_project_require($pdo,$uid,$projectId);
  if ($method==='GET') {
    $download=coreui_project_id($_GET['download'] ?? '');
    if ($download!=='') {
      $st=$pdo->prepare('SELECT original_name,stored_name,mime_type,file_size FROM stu_user_knowledge_sources '
        . 'WHERE uuid=? AND user_id=? AND project_id=? LIMIT 1');
      $st->execute([$download,$uid,$projectId]);$row=$st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) stu_json(['ok'=>false,'error'=>'source_not_found'],404);
      $path=coreui_knowledge_upload_dir().'/'.basename((string)$row['stored_name']);
      if (!is_file($path)) stu_json(['ok'=>false,'error'=>'source_unavailable'],404);
      $inline=strtolower((string)($_GET['view'] ?? ''))==='1' &&
        str_starts_with((string)$row['mime_type'],'image/');
      header('Content-Type: '.(string)$row['mime_type']);
      header('X-Content-Type-Options: nosniff');
      header('Content-Security-Policy: default-src \'none\'; sandbox');
      header('Content-Disposition: '.($inline?'inline':'attachment')
        ."; filename*=UTF-8''".rawurlencode((string)$row['original_name']));
      header('Content-Length: '.filesize($path));
      readfile($path);exit;
    }
    $sources=coreui_knowledge_list($pdo,$uid,$projectId);
    stu_json(['ok'=>true,'project'=>$project,'sources'=>$sources,
      'limits'=>['max_file_mb'=>(int)round(coreui_knowledge_limits($pdo)['max_file_bytes']/1048576)],
      'csrf_token'=>stu_csrf_token()]);
  }
  if ($method!=='POST') stu_json(['ok'=>false,'error'=>'method_not_allowed'],405);
  stu_require_csrf();
  if (isset($_FILES['file'])) {
    $source=coreui_knowledge_ingest($pdo,$uid,$_FILES['file'],
      (string)($_POST['title'] ?? ''),$projectId);
    stu_json(['ok'=>true,'source'=>$source,'sources'=>coreui_knowledge_list($pdo,$uid,$projectId)]);
  }
  if (($body['action'] ?? '')==='delete') {
    $uuid=coreui_project_id($body['uuid'] ?? '');
    $st=$pdo->prepare('SELECT 1 FROM stu_user_knowledge_sources WHERE uuid=? AND user_id=? AND project_id=?');
    $st->execute([$uuid,$uid,$projectId]);
    if (!$st->fetchColumn()) throw new RuntimeException('source_not_found');
    coreui_knowledge_delete($pdo,$uid,$uuid);
    stu_json(['ok'=>true,'sources'=>coreui_knowledge_list($pdo,$uid,$projectId)]);
  }
  stu_json(['ok'=>false,'error'=>'unknown_action'],400);
} catch (InvalidArgumentException $e) {
  stu_json(['ok'=>false,'error'=>$e->getMessage()],400);
} catch (RuntimeException $e) {
  $error=$e->getMessage();
  stu_json(['ok'=>false,'error'=>$error],
    in_array($error,['source_not_found','project_not_found'],true)?404:
    (str_contains($error,'migration')?503:422));
} catch (Throwable $e) {
  error_log('[CoreAI] project source failed: '.get_class($e));
  stu_json(['ok'=>false,'error'=>'project_source_failed'],500);
}
