<?php
declare(strict_types=1);
// 0.5.68: read-only snapshot for the Core, Kontext, Werkzeuge and Projekt
// info boxes. Owner and session scoped; never model text, prompts or paths.
require_once __DIR__ . '/console_plan_store.php';
require_once __DIR__ . '/console_artifact_store.php';
require_once __DIR__ . '/console_work_store.php';
require_once __DIR__ . '/skills.php';

/** Model, where it runs and its context window (read-only towards Ollama). */
function coreui_insights_core(string $model): array {
  $cloud = function_exists('ember_model_is_cloud') && ember_model_is_cloud($model);
  $window = (!$cloud && function_exists('ember_ollama_context_window'))
    ? ember_ollama_context_window($model) : null;
  $source = $window !== null
    ? (string)($GLOBALS['COREUI_OLLAMA_CONTEXT_SOURCE'][$model] ?? 'default') : null;
  // #113: "used for requests" and "loaded by Ollama" are different facts;
  // a profile window applies from the next request on.
  $loaded = $source === 'loaded' ? $window
    : ((!$cloud && function_exists('ember_ollama_ps_context')) ? ember_ollama_ps_context($model) : null);
  return [
    'model'=>$model,
    'runtime'=>$cloud ? 'cloud' : 'local',
    'context_tokens'=>$window,
    'context_source'=>$source,
    'context_loaded'=>$loaded,
    'thinking'=>function_exists('ember_thinking_enabled') ? ember_thinking_enabled() : true,
  ];
}

/** The last measured Core call of this session (prompt and output tokens). */
function coreui_insights_usage(PDO $pdo, int $uid, string $sessionId): ?array {
  if ($uid <= 0 || $sessionId === '' || !coreui_work_schema_ready($pdo)) return null;
  $stmt = $pdo->prepare("SELECT details_json, UNIX_TIMESTAMP(created_at) AS unix_time
      FROM stu_console_work_events WHERE user_id=? AND session_id=? AND kind='model'
      ORDER BY id DESC LIMIT 80");
  $stmt->execute([$uid, $sessionId]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $details = json_decode((string)($row['details_json'] ?? ''), true);
    $meta = coreui_work_safe_meta(is_array($details) ? $details : []);
    if ((int)($meta['prompt_eval_count'] ?? 0) <= 0) continue;
    return [
      'prompt_tokens'=>(int)$meta['prompt_eval_count'],
      'output_tokens'=>(int)($meta['eval_count'] ?? 0),
      'context_tokens'=>(int)($meta['context_tokens'] ?? 0) ?: null,
      'model'=>(string)($meta['model'] ?? ''),
      'time'=>(int)($row['unix_time'] ?? 0) > 0 ? gmdate('c', (int)$row['unix_time']) : '',
    ];
  }
  return null;
}

/** Tool bus and skills as the server will actually use them. */
function coreui_insights_tools(PDO $pdo, int $uid): array {
  $on = static fn(string $fn): bool => function_exists($fn) ? (bool)$fn() : false;
  $skills = coreui_skills_load($pdo, $uid);
  $required = coreui_skills_required_ids();
  $optional = 0;
  foreach ($skills as $id=>$enabled) {
    if ($enabled && !in_array($id, $required, true)) $optional++;
  }
  return [
    'tools'=>[
      ['id'=>'rag','label'=>'RAG-LITE','detail'=>'Eigenes Wissen','on'=>true],
      ['id'=>'memory','label'=>'CRUD-LZG','detail'=>'Langzeitgedächtnis','on'=>$on('ember_memory_enabled')],
      ['id'=>'web','label'=>'WEB','detail'=>'SearXNG-Suche','on'=>$on('ember_web_enabled')],
      ['id'=>'browse','label'=>'BROWSE','detail'=>'Browser-Recherche','on'=>$on('ember_browse_enabled')],
      ['id'=>'python','label'=>'PYTHON','detail'=>'Isolierter Worker und Projekt-Writer','on'=>$on('ember_py_enabled')],
    ],
    'skills'=>['required'=>array_values($required),'optional_enabled'=>$optional],
  ];
}

/** Latest saved Markdown plan of this session, as a download. */
function coreui_insights_plan(PDO $pdo, int $uid, string $sessionId): ?array {
  $plan = coreui_plan_latest($pdo, $uid, $sessionId);
  if (!is_array($plan)) return null;
  $url = 'api/console_plan.php?id=' . rawurlencode((string)$plan['id']);
  if (function_exists('stu_public_path')) $url = stu_public_path($url);
  return [
    'version'=>(int)$plan['version'],
    'title'=>mb_substr(trim((string)($plan['title'] ?? '')), 0, 120, 'UTF-8'),
    'draft'=>coreui_plan_is_draft_markdown((string)$plan['markdown']),
    'size_bytes'=>(int)$plan['byte_size'],
    'name'=>'projektplan-v' . (int)$plan['version'] . '.md',
    'download_url'=>$url,
  ];
}

/** Newest confirmed downloads of this session (same checks as the chat). */
function coreui_insights_files(PDO $pdo, int $uid, string $sessionId, int $limit = 8): array {
  if ($uid <= 0 || $sessionId === '' || !coreui_console_artifact_schema_ready($pdo)) return [];
  $stmt = $pdo->prepare(coreui_console_artifact_select_sql()
    . ' AND a.user_id=? AND a.session_id=? ORDER BY a.created_at DESC, a.id DESC LIMIT '
    . max(1, min(20, $limit)));
  $stmt->execute([$uid, $sessionId]);
  $files = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $public = coreui_console_artifact_public($row);
    if (empty($public['available'])) continue;
    $files[] = ['name'=>$public['name'],'size_bytes'=>$public['size_bytes'],
      'mime_type'=>$public['mime_type'],'download_url'=>$public['download_url']];
  }
  return $files;
}
