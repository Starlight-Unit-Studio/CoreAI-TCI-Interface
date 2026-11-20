<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
require_once $root.'/api/project_file_tools.php';

function workspace_test(bool $okay,string $what): void {
  if (!$okay) {fwrite(STDERR,"Chat workspace FAIL: ".$what."\n");exit(1);}
}
function workspace_fails(callable $run,string $expected): void {
  try {$run();} catch (Throwable $e) {
    workspace_test($e->getMessage()===$expected,"expected ".$expected.", got ".$e->getMessage());
    return;
  }
  workspace_test(false,"expected rejection ".$expected);
}
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  echo "Chat Workspace: static contract OK; SQLite not installed, fixture skipped\n";
  exit(0);
}
// Same ownership/lock/archival contract as the real console session store.
function coreui_console_session_require(PDO $pdo,int $uid,string $sid,bool $archived=false): array {
  $st=$pdo->prepare('SELECT * FROM stu_console_sessions WHERE id=? AND user_id=? LIMIT 1');
  $st->execute([$sid,$uid]);
  $row=$st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (!$archived && $row['archived_at']!==null)
      || $row['locked_at']!==null) throw new RuntimeException('session_not_found');
  return $row;
}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE stu_users(id INTEGER PRIMARY KEY,permission_level INTEGER)');
$pdo->exec('CREATE TABLE stu_console_media(user_id INTEGER,kind TEXT,file_size INTEGER)');
$pdo->exec('CREATE TABLE stu_console_sessions(
  id TEXT PRIMARY KEY,user_id INTEGER,project_id TEXT,title TEXT,
  archived_at TEXT,locked_at TEXT,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_projects(
  id TEXT PRIMARY KEY,user_id INTEGER,name TEXT,description TEXT,context_mode TEXT,
  instructions_md TEXT,pinned_at TEXT,created_at TEXT,
  workspace_session_id TEXT UNIQUE REFERENCES stu_console_sessions(id) ON DELETE CASCADE)');
$pdo->exec('CREATE TABLE stu_console_project_files(
  id TEXT PRIMARY KEY,project_id TEXT REFERENCES stu_console_projects(id) ON DELETE CASCADE,
  user_id INTEGER,path TEXT,content TEXT,size_bytes INTEGER,sha256 TEXT,revision INTEGER,
  created_at TEXT,updated_at TEXT,UNIQUE(project_id,path))');
$pdo->exec('CREATE TABLE stu_console_project_file_revisions(
  id INTEGER PRIMARY KEY AUTOINCREMENT,file_id TEXT REFERENCES stu_console_project_files(id) ON DELETE CASCADE,
  project_id TEXT,user_id INTEGER,revision INTEGER,path TEXT,content TEXT,size_bytes INTEGER,sha256 TEXT,
  source TEXT,session_id TEXT,message_id INTEGER,created_at TEXT,UNIQUE(file_id,revision))');
$pdo->exec("INSERT INTO stu_users(id,permission_level) VALUES(7,0),(9,0)");
$projectId=str_repeat('a',32);
$pdo->prepare("INSERT INTO stu_console_projects
  (id,user_id,name,description,context_mode,created_at)
  VALUES (?,?,?,?,?,'2026-11-19')")->execute([$projectId,7,'Sichtbares Projekt','','open']);
$ins=$pdo->prepare("INSERT INTO stu_console_sessions
  (id,user_id,project_id,title,archived_at,locked_at,created_at,updated_at)
  VALUES (?,?,?, ?,NULL,NULL,'2026-11-19','2026-11-19')");
$ins->execute(['cs_free_alpha',7,null,'Normaler Chat']);
$ins->execute(['cs_project_alpha',7,$projectId,'Projekt-Chat']);
$ins->execute(['cs_free_beta',7,null,'Zweiter normaler Chat']);
$ins->execute(['cs_other_alpha',9,null,'Fremder Chat']);
$ins->execute(['cs_locked_alpha',7,null,'Gesperrter Chat']);

workspace_test(coreui_chat_workspace_ready($pdo),'migration column is present');
workspace_test(coreui_project_session_scope($pdo,7,'cs_free_alpha')['mode']==='none',
  'normal conversation keeps normal context instead of implicit isolation');
$alpha=coreui_chat_workspace_ensure($pdo,7,'cs_free_alpha');
workspace_test($alpha['kind']==='chat' && preg_match('/^[a-f0-9]{32}$/',$alpha['project_id'])===1,
  'ordinary chat creates a private backing workspace');
workspace_test(coreui_chat_workspace_ensure($pdo,7,'cs_free_alpha')['project_id']===$alpha['project_id'],
  'same chat reuses its workspace across reloads');
workspace_test(count(coreui_project_list($pdo,7))===1
  && coreui_project_list($pdo,7)[0]['name']==='Sichtbares Projekt',
  'workspace must never appear in the organizational Projects list');
workspace_test(coreui_chat_workspace_ensure($pdo,7,'cs_project_alpha')===
  ['project_id'=>$projectId,'kind'=>'project'],
  'an organizational project chat reuses the original project files');
workspace_test(coreui_pftools_project($pdo,7,'cs_free_alpha')===$alpha['project_id'],
  'ordinary chat exposes the same validated read/write tools');
workspace_test(coreui_pftools_project($pdo,9,'cs_free_alpha')==='',
  'other accounts cannot borrow chat workspace');
workspace_fails(static fn()=>coreui_chat_workspace_ensure($pdo,7,'cs_other_alpha'),'session_not_found');
workspace_fails(static fn()=>coreui_project_require_managed($pdo,7,$alpha['project_id']),
  'workspace_not_a_project');

$file=coreui_pftools_run($pdo,7,'cs_free_alpha',
  ['tool'=>'pfile_write','input'=>"index.html\n<h1>Chat-App</h1>"]);
workspace_test(str_contains($file,'Datei index.html angelegt'),
  'normal chat writes a real source file with existing tool');
$blog=coreui_pftools_run($pdo,7,'cs_free_alpha',
  ['tool'=>'pfile_write','input'=>"blog.html\n<main>Blog</main>"]);
workspace_test(str_contains($blog,'Datei blog.html angelegt')
  && coreui_pfiles_list($pdo,7,$alpha['project_id'])['count']===2
  && str_contains(coreui_pfiles_get($pdo,7,$alpha['project_id'],'index.html')['content'],'Chat-App'),
  'adding blog keeps the earlier index file and both revisions');
// A missing script is NOT a missing workspace. Tell the Core the actual
// saved paths so it can choose an authorized next action, not the same
// nonexistent read three times.
$missing=coreui_pftools_run($pdo,7,'cs_free_alpha',
  ['tool'=>'pfile_read','input'=>'assets/app.js']);
workspace_test(str_starts_with($missing,'Das ging nicht:')
  && str_contains($missing,'diese Datei gibt es im Projekt nicht')
  && str_contains($missing,'index.html')
  && str_contains($missing,'blog.html')
  && !str_contains($missing,'assets/app.js\n'),
  'missing file in ordinary chat yields real workspace inventory, not a false unavailable project');
$beta=coreui_chat_workspace_ensure($pdo,7,'cs_free_beta');
workspace_test($beta['project_id']!==$alpha['project_id']
  && coreui_pfiles_list($pdo,7,$beta['project_id'])['count']===0,
  'two normal chats never share source files');

$pdo->exec("UPDATE stu_console_sessions SET locked_at='2026-11-19' WHERE id='cs_free_alpha'");
workspace_fails(static fn()=>coreui_pfiles_list($pdo,7,$alpha['project_id']),'session_not_found');
workspace_fails(static fn()=>coreui_chat_workspace_ensure($pdo,7,'cs_free_alpha'),'session_not_found');
$pdo->exec("UPDATE stu_console_sessions SET locked_at=NULL WHERE id='cs_free_alpha'");
$pdo->exec("DELETE FROM stu_console_sessions WHERE id='cs_free_alpha'");
workspace_test(coreui_chat_workspace_id($pdo,7,'cs_free_alpha')===''
  && (int)$pdo->query("SELECT COUNT(*) FROM stu_console_project_files
       WHERE project_id='".$alpha['project_id']."'")->fetchColumn()===0
  && count(coreui_project_list($pdo,7))===1,
  'deleting a chat cascades its hidden project, files and history only');
echo "CoreAI chat workspaces: privacy, direct files, preview scope and lifecycle OK\n";
