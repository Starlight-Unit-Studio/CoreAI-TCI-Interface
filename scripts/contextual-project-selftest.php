<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_native_tools.php';

function dispatch_assert(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Model project dispatch FAIL: " . $why . "\n"); exit(1); }
}

// Test the ACTIVE model-selected contract, never the retired keyword-based
// conversation handoff from CoreUI 0.5.38. No user-side magic words are needed.
$modelReply = "[COREUI_PROJECT_BUILD]\nSetze das vereinbarte Layout als statische Seite um.\n[/COREUI_PROJECT_BUILD]";
$parsed = coreui_native_tool_parse($modelReply);
dispatch_assert(is_array($parsed) && $parsed['type'] === 'PROJECT_BUILD',
  'Model tool marker is not parsed');
dispatch_assert(coreui_native_tool_parse('Wir besprechen die Idee noch weiter.') === null,
  'Ordinary discussion must not execute a tool');
dispatch_assert(coreui_native_tool_parse("[COREUI_PROJECT_BUILD]\nUnvollstaendig") === null,
  'Incomplete tool marker must never execute');
dispatch_assert(str_contains(coreui_native_tools_prompt(), "main.js).\n[/COREUI_PROJECT_BUILD]"),
  'Build tool example contains escaped instead of actual newline');

$legacy = 'Erstelle index.html, style.css und main.js, liefere das ZIP zum Download.';
dispatch_assert(coreui_console_download_requested($legacy)
  && coreui_console_multifile_source_directive($legacy) !== null,
  'Direct legacy file requests must remain compatible');

// Both live routes MUST consume the model output through the controlled
// native-tool dispatcher, rather than invoking old user-phrase classification.
$root = dirname(__DIR__);
$chat = (string)file_get_contents($root . '/api/chat.php');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
foreach (['chat.php'=>$chat,'console_stream.php'=>$stream] as $file=>$source) {
  dispatch_assert(str_contains($source, 'coreui_native_tool_apply('),
    'Model-selected tool dispatcher missing from ' . $file);
  dispatch_assert(!str_contains($source, 'coreui_console_web_project_resolve('),
    'Retired user phrase trigger still active in ' . $file);
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  $pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  $pdo->exec('CREATE TABLE stu_console_project_plans (
    id TEXT PRIMARY KEY, user_id INTEGER, session_id TEXT,
    source_turn_id INTEGER, response_message_id INTEGER, version INTEGER,
    title TEXT, markdown TEXT, byte_size INTEGER, sha256 TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )');
  $uid = 7;
  $session = 'cs_model_project_001';
  $planText = "# Website\n\n- Nachtblaues Design\n- Responsive Navigation\n- Drei Karten";
  $stmt = $pdo->prepare('INSERT INTO stu_console_project_plans
    (id,user_id,session_id,source_turn_id,response_message_id,version,title,
      markdown,byte_size,sha256,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
  $stmt->execute([
    str_repeat('a',32),$uid,$session,101,null,1,'Website',
    $planText,strlen($planText),hash('sha256',$planText)
  ]);
  $description = (string)$parsed['body'];
  $request = coreui_native_tool_build_request($pdo,$uid,$session,$description);
  dispatch_assert(is_string($request), 'Model-selected project request was rejected');
  dispatch_assert(str_contains($request,'Nachtblaues Design')
    && str_contains($request,'Responsive Navigation'),
    'Saved project requirements were omitted from the build');
  dispatch_assert(coreui_console_download_requested($request),
    'Generated tool task must activate the existing download worker');
  $spec = coreui_console_project_request_spec($request);
  dispatch_assert(is_array($spec) && $spec['zip_requested']
    && array_values($spec['requested']) === ['index.html','style.css','main.js'],
    'Worker did not get exactly the validated static source manifest');
  dispatch_assert(is_string(coreui_console_multifile_source_directive($request)),
    'Validated source ZIP writer could not recognize the generated task');

  $calls=0;
  $lastRequest='';
  $result=coreui_native_tool_apply($pdo,$uid,$session,101,$modelReply,
    static function(string $task) use (&$calls,&$lastRequest,$pdo,$uid,$session,$modelReply): string {
      $calls++;
      $lastRequest=$task;
      $nested=coreui_native_tool_apply($pdo,$uid,$session,101,$modelReply,
        static fn(string $untrusted): string => 'unexpected nested call');
      dispatch_assert($nested === null, 'Recursive tool action was permitted');
      return 'Projektdateien wurden bereitgestellt.';
    }
  );
  dispatch_assert($calls===1 && $lastRequest===$request
    && $result==='Projektdateien wurden bereitgestellt.',
    'Build callback was not dispatched exactly once');
  dispatch_assert(empty($GLOBALS['COREUI_NATIVE_TOOL_REENTRY']),
    'Model tool reentry lock was not released');

  $otherRequest=coreui_native_tool_build_request($pdo,$uid+1,$session,$description);
  dispatch_assert(is_string($otherRequest) && !str_contains($otherRequest,'Nachtblaues Design'),
    'Cross-account project plan leaked into model task');
  dispatch_assert(coreui_native_tool_build_request($pdo,$uid,$session,'React Projekt')===null,
    'Unsupported React framework was silently treated as static HTML');
  // Never invent a complete build from a truncated 7k-byte plan.
  $oversized=str_repeat('X',7000);
  $stmt->execute([
    str_repeat('b',32),$uid,$session,102,null,2,'Long Plan',
    $oversized,strlen($oversized),hash('sha256',$oversized)
  ]);
  dispatch_assert(coreui_native_tool_build_request($pdo,$uid,$session,$description)===null,
    'Oversized saved plan was silently truncated for a project build');
  echo "Model project dispatch with real SQL fixture and one safe callback: OK\n";
} else {
  echo "Model project dispatch SQL fixture: SKIP (pdo_sqlite unavailable)\n";
}
echo "CoreUI model-chosen project handoff and legacy explicit downloads: OK\n";
