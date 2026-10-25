<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/api/console_native_tools.php';
function intent_assert(bool $good,string $why): void {
  if (!$good) { fwrite(STDERR,"Model project-intent FAIL: ".$why."\n"); exit(1); }
}
intent_assert(coreui_build_intent_decode('START_BUILD')==='build'
  && coreui_build_intent_decode('CHAT')==='chat', 'Model decision parser');
foreach (['', 'START_BUILD maybe', 'BUILD and CHAT', 'Starting now!',
  '[COREUI_PROJECT_BUILD]'] as $wrong) {
  intent_assert(coreui_build_intent_decode($wrong)===null,
    'Free prose must not be treated as permission');
}
$pages="# Anime-Welt Tagebuch\n\n## Dateien\n- index.html\n"
  . "- about.html\n- gallery.html\n- characters.html\n"
  . "- journal.html\n- contact.html\n- style.css\n- main.js\n";
$expected=['index.html','about.html','gallery.html','characters.html',
  'journal.html','contact.html','style.css','main.js'];
intent_assert(coreui_native_plan_source_manifest($pages)===$expected,
  'Six pages and both shared assets must be in manifest');
intent_assert(coreui_native_plan_source_manifest(
  "# Plan\n- about.html\n- index.html\n- about.html\n- style.css"
  )===['index.html','about.html','style.css','main.js'],
  'Duplicate page names and missing shared asset');
intent_assert(coreui_native_plan_source_manifest(
  "# Plan\n- index.html\n- ".implode("\n- ",[
    'a.html','b.html','c.html','d.html','e.html','f.html','g.html','h.html','i.html'])
  )===null,'More than nine files must fail closed');
intent_assert(coreui_native_plan_source_manifest(
  "# Plan\n- /etc/passwd\n- ../../../secret.php"
  )===[],'Unsafe path and PHP entries must be rejected');

$prompt=coreui_build_intent_prompt(['version'=>3,'title'=>'Anime-Welt',
  'bytes'=>7100,'message'=>'Lass mal den Plan umsetzen']);
intent_assert(strlen($prompt)<3500 && str_contains($prompt,'START_BUILD')
  && str_contains($prompt,'CHAT')
  && str_contains($prompt,'Lass mal den Plan umsetzen'),
  'Small model decision lacks expected format/message');
$stream=(string)file_get_contents(dirname(__DIR__).'/api/console_stream.php');
$chat=(string)file_get_contents(dirname(__DIR__).'/api/chat.php');
intent_assert(str_contains($stream,'coreui_build_intent_select(')
  && str_contains($chat,'coreui_build_intent_select(')
  && str_contains($stream,'false, 90, false)')
  && str_contains($chat,'[],null,false,false)'),
  'Sync/SSE no-thinking model decision not wired');
intent_assert(!str_contains($stream,'coreui_console_web_project_resolve(')
  && !str_contains($chat,'coreui_console_web_project_resolve('),
  'Old keyword router returned');

if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE stu_chat_messages(id INTEGER PRIMARY KEY,
    user_id INTEGER,session_id TEXT,channel TEXT,deleted_at TEXT)');
  $pdo->exec('CREATE TABLE stu_console_project_plans(id TEXT PRIMARY KEY,
    user_id INTEGER,session_id TEXT,source_turn_id INTEGER,response_message_id INTEGER,
    version INTEGER,title TEXT,markdown TEXT,byte_size INTEGER,sha256 TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
  $sid='cs_intent_project_0001';
  $pdo->prepare("INSERT INTO stu_chat_messages(id,user_id,session_id,channel)
    VALUES(11,7,?,'console')")->execute([$sid]);
  $insert=$pdo->prepare('INSERT INTO stu_console_project_plans
    (id,user_id,session_id,source_turn_id,response_message_id,version,title,
    markdown,byte_size,sha256) VALUES(?,?,?,?,?,?,?,?,?,?)');
  $saved=$pages."\n## Umsetzung\n".str_repeat("Konsistente Gestaltung. ",280);
  intent_assert(strlen($saved)>6500 && strlen($saved)<12000,
    'Saved plan must exceed old 6500-byte cutoff, but remain bounded');
  $insert->execute([str_repeat('a',32),7,$sid,11,null,3,'Anime-Welt Tagebuch',
    $saved,strlen($saved),hash('sha256',$saved)]);
  $calls=0;
  $yes=coreui_build_intent_select($pdo,7,$sid,11,'Lass mal den Plan umsetzen',
    static function(string $q) use (&$calls): array {
      $calls++;
      intent_assert(str_contains($q,'Lass mal den Plan umsetzen'),
        'Model did not see current user intent');
      return ['ok'=>true,'text'=>'START_BUILD'];
    });
  intent_assert($calls===1 && $yes['status']==='build'
    && (coreui_native_tool_parse((string)$yes['tool'])['type'] ?? '')==='PROJECT_BUILD',
    'Authorized model action was not parsed as a native build');
  $buildCalls=0;
  $request='';
  $answer=coreui_native_tool_apply($pdo,7,$sid,11,(string)$yes['tool'],
    static function(string $task) use (&$buildCalls,&$request): string {
      $buildCalls++;
      $request=$task;
      return 'Projektdateien wurden gespeichert.';
    },false);
  intent_assert($buildCalls===1 && $answer==='Projektdateien wurden gespeichert.',
    'Existing guarded builder must run exactly once');
  $spec=coreui_console_project_request_spec($request);
  intent_assert(is_array($spec) && $spec['zip_requested']
    && array_values($spec['requested'])===$expected
    && str_contains($request,str_repeat("Konsistente Gestaltung. ",280)),
    'ZIP generator must receive all named pages and complete plan bytes');
  $chatOnly=coreui_build_intent_select($pdo,7,$sid,11,'Was soll ich anpassen?',
    static fn(string $q): array => ['ok'=>true,'text'=>'CHAT']);
  intent_assert($chatOnly['status']==='chat' && $chatOnly['tool']===null,
    'Ordinary questions must not build');
  $bad=coreui_build_intent_select($pdo,7,$sid,11,'Plan bauen?',
    static fn(string $q): array => ['ok'=>true,'text'=>'START_BUILD, weil ...']);
  intent_assert($bad['status']==='decision_invalid' && $bad['tool']===null,
    'Malformed model decisions must not build');
  $truncated=coreui_build_intent_select($pdo,7,$sid,11,'Plan umsetzen',
    static fn(string $q): array => ['ok'=>false,'text'=>'START_BUILD']);
  intent_assert($truncated['status']==='model_incomplete'
    && $truncated['tool']===null,'Truncated model output must not authorize build');
  $other=coreui_build_intent_select($pdo,8,$sid,11,'Plan umsetzen',
    static function(string $q): array { throw new RuntimeException('Wrong account'); });
  intent_assert($other['status']==='ineligible',
    'Cross-account plan execution must not reach the model');
  $draft=COREUI_PLAN_DRAFT_MARKER."\n\n".$pages;
  $insert->execute([str_repeat('b',32),7,$sid,11,null,4,'Anime-Welt Tagebuch',
    $draft,strlen($draft),hash('sha256',$draft)]);
  $notReady=coreui_build_intent_select($pdo,7,$sid,11,'Plan umsetzen',
    static function(string $q): array { throw new RuntimeException('Draft not allowed'); });
  intent_assert($notReady['status']==='ineligible',
    'A newer draft must invalidate a previous completed plan');
  $large=str_repeat("L",12500);
  $insert->execute([str_repeat('c',32),7,$sid,11,null,5,'Anime-Welt Tagebuch',
    $large,strlen($large),hash('sha256',$large)]);
  intent_assert(coreui_native_tool_build_request($pdo,7,$sid,
    'Setze eine statische Website um.')===null,
    'Oversized plans must not be silently truncated');
  echo "Model-selected six-page plan build OK: real SQL fixture, safe decision, unchanged full plan, no duplicate builds\n";
} else echo "Model project intent fixture SKIP (pdo_sqlite missing)\n";
