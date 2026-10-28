<?php
declare(strict_types=1);
// CoreAI 0.5.68: read-only snapshot for the info boxes. Offline: no Ollama,
// no MariaDB; the endpoint contract is checked statically.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

function insights_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Insights FAIL: {$why}\n"); exit(1); }
}

// Runtime stubs: the store only reads what the server would really use.
function ember_model_is_cloud(string $model): bool { return str_ends_with($model, '-cloud'); }
function ember_ollama_context_window(string $model): ?int {
  $GLOBALS['COREUI_OLLAMA_CONTEXT_SOURCE'][$model] = 'modelfile';
  return 131072;
}
function ember_ollama_ps_context(string $model): ?int { return $GLOBALS['TEST_PS'] ?? 8192; }
function ember_thinking_enabled(): bool { return false; }
function ember_memory_enabled(): bool { return true; }
function ember_web_enabled(): bool { return true; }
function ember_browse_enabled(): bool { return false; }
function ember_py_enabled(): bool { return true; }
require_once $root . '/api/console_insights_store.php';

$local = coreui_insights_core('ember-coreui:latest');
insights_test($local === ['model'=>'ember-coreui:latest','runtime'=>'local','context_tokens'=>131072,
  'context_source'=>'modelfile','context_loaded'=>8192,'thinking'=>false], 'local Core snapshot');
// #113: used and loaded windows are reported separately.
$GLOBALS['TEST_PS'] = 131072;
insights_test(coreui_insights_core('ember-coreui:latest')['context_loaded'] === 131072, 'loaded window from /api/ps');
$cloud = coreui_insights_core('gpt-oss:120b-cloud');
insights_test($cloud['runtime'] === 'cloud' && $cloud['context_tokens'] === null
  && $cloud['context_source'] === null, 'a cloud window is never invented');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tools = coreui_insights_tools($pdo, 7);
$states = array_column($tools['tools'], 'on', 'id');
insights_test($states === ['rag'=>true,'memory'=>true,'web'=>true,'browse'=>false,'python'=>true],
  'tool states come from the server flags');
insights_test(in_array('werkzeuge', $tools['skills']['required'], true)
  && $tools['skills']['library'] === count(coreui_skills_catalog()) - 1, 'the mandatory skill is listed, Mara picks from the rest');
insights_test(coreui_insights_usage($pdo, 7, 'cs_session_0001') === null
  && coreui_insights_plan($pdo, 7, 'cs_session_0001') === null
  && coreui_insights_files($pdo, 7, 'cs_session_0001') === [],
  'missing migrations yield empty boxes, never an error page');

// Endpoint contract: owner, session, no lock while Ollama answers, no cache.
$endpoint = (string)file_get_contents($root . '/api/console_insights.php');
insights_test(str_contains($endpoint, 'stu_require_user_id()')
  && str_contains($endpoint, 'coreui_console_session_require($pdo, $uid, $sessionId)')
  && str_contains($endpoint, 'session_write_close()')
  && str_contains($endpoint, "header('Cache-Control: private, no-store')")
  && str_contains($endpoint, "'method_not_allowed'"), 'endpoint is owner scoped and read-only');
$store = (string)file_get_contents($root . '/api/console_insights_store.php');
insights_test(!str_contains($store, 'markdown\'=>') && !str_contains($store, "'thinking_content'")
  && str_contains($store, 'coreui_work_safe_meta(') && str_contains($store, "a.user_id=? AND a.session_id=?"),
  'only whitelisted metadata and owner-scoped files leave the server');

// 0.5.68: nginx only serves listed API files; a missing entry left the
// Werkzeuge and Projekt boxes loading forever on the server.
foreach (['config/nginx.conf.template','config/runtime/nginx.conf.template'] as $nginx) {
  insights_test(str_contains((string)file_get_contents($root . '/' . $nginx), '|console_insights|'),
    'nginx must route the info box endpoint: ' . $nginx);
}
$app = (string)file_get_contents($root . '/app.html');
$js = (string)file_get_contents($root . '/js/console-app.js');
insights_test(str_contains($app, 'id="coreInsights"') && str_contains($app, 'id="insightChips"')
  && str_contains($app, './js/coreui-insights.js?v='), 'info box containers and script are loaded');
insights_test(str_contains($js, 'insights.refresh(ses.id)') && str_contains($js, 'insights.observe(event)')
  && substr_count($js, 'insights.refresh(sessionId)') === 2, 'boxes refresh per session, live and after a reply');

echo "CoreAI info boxes snapshot, endpoint contract and wiring: OK\n";
