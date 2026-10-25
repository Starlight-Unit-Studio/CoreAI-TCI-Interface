<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_plan_store.php';
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_native_tools.php';

function native_assert(bool $condition,string $why): void {
  if (!$condition) { fwrite(STDERR,"CoreUI native tools FAIL: ".$why."\n"); exit(1); }
}

$markdown = "# Mein Plan\n\nAnforderungen mit Umlauten: äöü. 🚀\n\n- [ ] Landingpage";
$valid = "[COREUI_PLAN_SAVE]\n".$markdown."\n[/COREUI_PLAN_SAVE]";
$call = coreui_native_tool_parse($valid);
native_assert(is_array($call) && $call['type']==='PLAN_SAVE' && $call['body']===$markdown,
  'Markdown tool protocol must keep UTF-8 content');
foreach ([
  'PLAN_APPEND'=>'# Neue Elemente',
  'PLAN_AND_BUILD'=>'# Umsetzungsplan',
  'PROJECT_BUILD'=>'index.html, style.css, main.js: fertige Website'
] as $kind=>$body) {
  native_assert((coreui_native_tool_parse("[COREUI_".$kind."]\n".$body."\n[/COREUI_".$kind."]")['type'] ?? null)===$kind,
    'Model cannot select tool '.$kind);
}
foreach ([
  "[COREUI_PROJECT_BUILD]\n\n[/COREUI_PROJECT_BUILD]",
  "Text\n[COREUI_PLAN_SAVE]\n# Evil\n[/COREUI_PLAN_SAVE]",
  "[COREUI_PROJECT_BUILD]\nfile\n[/COREUI_PLAN_SAVE]",
  "[COREUI_PLAN_SAVE]\n# First\n[/COREUI_PLAN_SAVE]\n[COREUI_PROJECT_BUILD]\nindex.html\n[/COREUI_PROJECT_BUILD]",
] as $invalid) native_assert(coreui_native_tool_parse($invalid) === null,
  'Multi-action/malformed protocol should not run');

native_assert(str_contains(coreui_native_tools_prompt(), '[COREUI_PLAN_SAVE]')
  && str_contains(coreui_native_tools_prompt(), '[COREUI_PROJECT_BUILD]')
  && str_contains(coreui_native_tools_prompt(), 'Du DARFST selbst entscheiden'),
  'Native tools must be model-driven from the runtime prompt');

// Ensure SQL owner isolation and server-owned downloads in all channels.
$root = dirname(__DIR__);
$store = (string)file_get_contents($root.'/api/console_plan_store.php');
foreach (['user_id=? AND session_id=?', 'source_turn_id=?', 'response_message_id=?',
  'hash(\'sha256\',$markdown)', 'function coreui_plan_artifact_map('] as $needle) {
  native_assert(str_contains($store,$needle),'Plan persistence guard missing: '.$needle);
}
$toolSource = (string)file_get_contents($root.'/api/console_native_tools.php');
native_assert(str_contains($toolSource, 'react|next[.]?js|vue|angular'),
  'Unsupported framework build must be rejected without fake artifact success');
$download = (string)file_get_contents($root.'/api/console_plan.php');
native_assert(str_contains($download,'stu_require_user_id()')
  && str_contains($download,'hash_equals(')
  && str_contains($download,'Content-Type: application/octet-stream'),
  'Plan download must enforce authentication and integrity');
foreach (['config/nginx.conf.template','config/runtime/nginx.conf.template'] as $path) {
  native_assert(str_contains((string)file_get_contents($root.'/'.$path),'|console_plan|'),
    'Nginx must expose private plan downloads: '.$path);
}
$script = (string)file_get_contents($root.'/js/coreui-artifacts.js');
native_assert(str_contains($script,"file.kind === 'plan' ? 'console_plan.php'"),
  'Plan download card not integrated');
$chat = (string)file_get_contents($root.'/api/chat.php');
$stream = (string)file_get_contents($root.'/api/console_stream.php');
native_assert(str_contains($chat,'coreui_native_tool_apply(')
  && str_contains($stream,'coreui_native_tool_apply(')
  && str_contains($chat,'coreui_plan_bind_response(')
  && !str_contains($chat, 'coreui_console_web_project_resolve(')
  && !str_contains($stream, 'coreui_console_web_project_resolve('),
  'Old keyword router must be replaced by real model-chosen tool dispatch');

if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  class NativePlanTestPDO extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
      return parent::prepare(str_replace('NOW()', 'CURRENT_TIMESTAMP', $query), $options);
    }
  }
  $pdo = new NativePlanTestPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec("CREATE TABLE stu_chat_messages (
    id INTEGER PRIMARY KEY,user_id INTEGER,session_id TEXT,channel TEXT,deleted_at TEXT,
    reply_to_id INTEGER)");
  $pdo->exec("CREATE TABLE stu_console_project_plans (
    id TEXT PRIMARY KEY,user_id INTEGER,session_id TEXT,source_turn_id INTEGER,
    response_message_id INTEGER,version INTEGER,title TEXT,markdown TEXT,byte_size INTEGER,
    sha256 TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
  $sid='cs_1234567890abcdef';
  $pdo->exec("INSERT INTO stu_chat_messages (id,user_id,session_id,channel) VALUES (10,1,'".$sid."','console'),(11,1,'".$sid."','console'),(12,2,'cs_other_12345678','console')");
  $v1=coreui_plan_save($pdo,1,$sid,10,$markdown);
  native_assert($v1['version']===1 && $v1['markdown']===$markdown,'First version saved unchanged');
  $v2=coreui_plan_save($pdo,1,$sid,11,"## Nachtrag\nNeue Funktion",true);
  native_assert($v2['version']===2 && str_contains($v2['markdown'],$markdown)
    && str_contains($v2['markdown'],'## Nachtrag'),'Append creates new complete revision');
  native_assert(coreui_plan_latest($pdo,1,$sid)['id']===$v2['id'],'Latest version lost');
  native_assert(coreui_plan_latest($pdo,2,$sid)===null,'Other account read private plan');
  $before=$pdo->query("SELECT COUNT(*) FROM stu_console_project_plans")->fetchColumn();
  try {
    coreui_plan_save($pdo,2,$sid,11,'# Evil');
    native_assert(false,'Cross-account trigger permitted');
  } catch (RuntimeException $expected) {}
  native_assert((int)$before===(int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_plans")->fetchColumn(),
    'Cross-account write modified plan data');
  $pdo->exec("INSERT INTO stu_chat_messages (id,user_id,session_id,channel,reply_to_id)
    VALUES (20,1,'".$sid."','console',10),(21,1,'".$sid."','console',11)");
  coreui_plan_bind_response($pdo,1,$sid,10,20);
  coreui_plan_bind_response($pdo,1,$sid,11,21);
  $map=coreui_plan_artifact_map($pdo,1,[20,21]);
  native_assert(count($map[20] ?? [])===1 && count($map[21] ?? [])===1
    && $map[21][0]['name']==='projektplan-v2.md',
    'Plan cards must bind to real assistant response and retain older versions');
  native_assert(coreui_plan_artifact_map($pdo,2,[20,21])===[], 'Other account received download cards');
  $hint=coreui_plan_prompt_block($pdo,1,$sid);
  native_assert(str_contains($hint,'v2') && str_contains($hint,'Nachtrag'),
    'Current persisted plan not visible to Mara');
  echo "Plan persistence (SQLite fixture): OK\n";
} else {
  echo "Plan persistence SQLite fixture: SKIP (pdo_sqlite unavailable)\n";
}
echo "Native tools: model call parsing, durable Markdown versions, guarded downloads, chat routing OK\n";
