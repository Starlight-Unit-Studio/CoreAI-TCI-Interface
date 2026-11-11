<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/api/rag_lite.php';
require_once dirname(__DIR__).'/api/console_project_store.php';
require_once dirname(__DIR__).'/api/knowledge_store.php';
function project_assert(bool $yes,string $message): void {
  if (!$yes) {fwrite(STDERR,"Project scope FAIL: ".$message."\n");exit(1);}
}
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
  echo "Project scope: SQLite not installed, skipped\n";exit(0);
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_console_projects (id TEXT, user_id INTEGER, name TEXT, context_mode TEXT,
  instructions_md TEXT, pinned_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE stu_console_sessions (id TEXT, user_id INTEGER, project_id TEXT,
  title TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_user_knowledge_sources (uuid TEXT, user_id INTEGER, project_id TEXT,
  title TEXT, status TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE stu_user_knowledge_chunks (source_uuid TEXT, user_id INTEGER,
  chunk_no INTEGER, chunk_text TEXT, title TEXT, id INTEGER)');
$pdo->exec('CREATE TABLE stu_chat_messages (id INTEGER, channel TEXT, user_id INTEGER,
  session_id TEXT, character_name TEXT, character_id TEXT, message TEXT, deleted_at TEXT, alliance_id TEXT)');
$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);
$st=$pdo->prepare('INSERT INTO stu_console_projects VALUES (?,?,?,?,?,?,?)');
$st->execute([$a,7,'Test A','isolated','Stay in A',null,'2026-01-01']);
$st->execute([$b,7,'Test B','open','',null,'2026-01-01']);
$st->execute([$c,9,'Other account','isolated','',null,'2026-01-01']);
$st=$pdo->prepare('INSERT INTO stu_console_sessions VALUES (?,?,?,?,?,?,?)');
foreach ([['cs_proj_a1',7,$a],['cs_proj_a2',7,$a],['cs_proj_b1',7,$b],['cs_proj_c1',9,$c]] as $row)
  $st->execute([$row[0],$row[1],$row[2],$row[0],null,'2026-01-01','2026-01-01']);
$st=$pdo->prepare('INSERT INTO stu_chat_messages VALUES (?,?,?,?,?,?,?,?,?)');
$st->execute([1,'console',7,'cs_proj_a2','Rick','rick','AlphaProject ChatFact',null,null]);
$st->execute([2,'console',7,'cs_proj_b1','Rick','rick','OtherProject Secret',null,null]);
$st->execute([3,'console',9,'cs_proj_c1','Someone','person','OtherAccount Secret',null,null]);
project_assert(coreui_project_session_scope($pdo,7,'cs_proj_a1')['mode']==='isolated','isolation flag');
project_assert(coreui_project_session_scope($pdo,7,'cs_proj_b1')['mode']==='open','standard mode');
project_assert(coreui_project_session_scope($pdo,7,'cs_proj_c1')['available']===false,'cross-account session denied');
$ctx=coreui_project_context_block($pdo,7,'cs_proj_a1');
project_assert(str_contains($ctx,'AlphaProject ChatFact'),'same-project chat visible');
project_assert(!str_contains($ctx,'OtherProject') && !str_contains($ctx,'OtherAccount'),'other chats excluded');
$st=$pdo->prepare('INSERT INTO stu_user_knowledge_sources VALUES (?,?,?,?,?,?)');
$st->execute([str_repeat('d',32),7,$a,'Alpha','ready','2026-01-01']);
$st->execute([str_repeat('e',32),7,$b,'Other','ready','2026-01-01']);
$st->execute([str_repeat('f',32),9,$c,'External','ready','2026-01-01']);
$st=$pdo->prepare('INSERT INTO stu_user_knowledge_chunks VALUES (?,?,?,?,?,?)');
$st->execute([str_repeat('d',32),7,0,'UniqueAnswer stable green garden for AlphaBeta reference','Alpha',1]);
$st->execute([str_repeat('e',32),7,0,'UniqueAnswer forbidden other project answer','Other',2]);
$st->execute([str_repeat('f',32),9,0,'UniqueAnswer forbidden other account answer','External',3]);
$rag=coreui_project_knowledge_block($pdo,7,$a,'UniqueAnswer');
project_assert(str_contains($rag,'stable green garden'),'own project knowledge present');
project_assert(!str_contains($rag,'forbidden'),'cross-project knowledge excluded');
project_assert(coreui_project_prompt_block($pdo,7,'cs_proj_a1')!=='' ,'project instructions included');
echo "CoreAI Projects: SQLite scope and mini-RAG isolation OK\n";
