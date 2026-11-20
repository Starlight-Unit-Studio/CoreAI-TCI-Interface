<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_task_journal.php';

function journal_test(bool $ok,string $msg):void {
  if (!$ok) { fwrite(STDERR,"Task journal FAIL: {$msg}\n");exit(1); }
}

/** Offline fake, no MariaDB or production account data. */
final class JournalFakeStatement extends PDOStatement {
  private array $rows=[];
  private int $changed=0;
  public function __construct(private JournalFakePDO $db,private string $sql) {}
  public function execute(?array $params=null):bool {
    $p=$params??[];$this->rows=[];$this->changed=0;
    $this->db->queries[]=[$this->sql,$p];
    if (str_contains($this->sql,'INSERT IGNORE INTO stu_console_task_journal')) {
      [$kind,$run,$uid,$sid,$turn]=$p;
      if ($this->db->runId===$run && $uid===7 && $sid==='session_demo' && $turn===23
          && !isset($this->db->journal[$run])) {
        $this->db->journal[$run]=[
          'run_id'=>$run,'turn_id'=>23,'status'=>'running','phase'=>'prepare',
          'checkpoint_version'=>0,'resume_checkpoint_version'=>null,
          'last_step'=>0,'last_verified_step'=>0,
          'last_error_code'=>null,'requirements_json'=>'[]','evidence_json'=>'{}',
          'updated_at'=>'now'];
        $this->changed=1;
      }
    } elseif (str_contains($this->sql,'INSERT IGNORE INTO stu_console_task_actions')) {
      [$step,$tool,$sha,$run,$uid,$sid]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && ($this->db->journal[$run]['status']??'')==='running'
          && !isset($this->db->actions[$run][$step])) {
        $this->db->actions[$run][$step]=[
          'tool_name'=>$tool,'input_sha256'=>$sha,'state'=>'claimed',
          'proof_kind'=>null,'proof_sha256'=>null,'proof_json'=>null];
        $this->changed=1;
      }
    } elseif (str_contains($this->sql,'UPDATE stu_console_task_actions SET')) {
      [$state,$kind,$sha,$proof,$run,$uid,$sid,$step,$toolSha]=$p;
      $row=&$this->db->actions[$run][$step];
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && ($row['state']??'')==='claimed'
          && ($row['input_sha256']??'')===$toolSha) {
        $row['state']=$state;$row['proof_kind']=$kind;$row['proof_sha256']=$sha;
        $row['proof_json']=$proof;$this->changed=1;
      }
      unset($row);
    } elseif (str_contains($this->sql,'UPDATE stu_console_task_journal SET phase=')) {
      [$step,$verified,$json,$run,$uid,$sid]=$p;
      $row=&$this->db->journal[$run];
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && ($row['status']??'')==='running') {
        $row['phase']='execute';$row['last_step']=max($row['last_step'],$step);
        $row['last_verified_step']=max($row['last_verified_step'],$verified);
        $row['checkpoint_version']++;$row['evidence_json']=$json;
        $this->changed=1;
      }
      unset($row);
    } elseif (str_contains($this->sql,"UPDATE stu_console_agent_runs SET status='running'")) {
      [$run,$uid,$sid]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && $this->db->runStatus==='interrupted') {
        $this->db->runStatus='running';$this->changed=1;
      }
    } elseif (str_contains($this->sql,'resume_checkpoint_version=checkpoint_version')) {
      [$run,$uid,$sid,$version]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && $this->db->journal[$run]['status']==='interrupted'
          && $this->db->journal[$run]['checkpoint_version']===$version) {
        $this->db->journal[$run]['status']='running';
        $this->db->journal[$run]['resume_checkpoint_version']=$version;
        $this->changed=1;
      }
    } elseif (str_contains($this->sql,'UPDATE stu_console_task_journal SET status=')) {
      [$status,$phase,$error,$finished,$run,$uid,$sid]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo') {
        $this->db->journal[$run]['status']=$status;
        $this->db->journal[$run]['last_error_code']=$error;
        $this->changed=1;
      }
    } elseif (str_contains($this->sql,'SELECT COUNT(*) AS total')) {
      [$run,$uid,$sid]=$p;
      $rows=($run===$this->db->runId && $uid===7 && $sid==='session_demo')
        ? array_values($this->db->actions[$run]??[]):[];
      $this->rows=[['total'=>count($rows),
        'verified'=>count(array_filter($rows,fn($x)=>$x['state']==='verified')),
        'pending'=>count(array_filter($rows,fn($x)=>in_array($x['state'],['claimed','uncertain'],true)))]];
    } elseif (str_contains($this->sql,'FROM stu_console_task_actions ')
        && str_contains($this->sql,"state IN ('claimed','uncertain')")) {
      [$run,$uid,$sid]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo') {
        foreach($this->db->actions[$run]??[] as $n=>$row) {
          if (in_array($row['state'],['claimed','uncertain'],true)) {
            $this->rows=[['step_no'=>$n,'state'=>$row['state'],'tool_name'=>$row['tool_name']]];
            break;
          }
        }
      }
    } elseif (str_contains($this->sql,'FROM stu_console_task_actions WHERE run_id=')) {
      [$run,$uid,$sid,$step]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && isset($this->db->actions[$run][$step]))
        $this->rows=[$this->db->actions[$run][$step]];
    } elseif (str_contains($this->sql,'FROM stu_console_agent_runs WHERE run_id=')) {
      [$run,$uid,$sid]=$p;
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo')
        $this->rows=[[
          'run_id'=>$run,'turn_id'=>23,'run_key'=>'base',
          'status'=>$this->db->runStatus,'response_message_id'=>null,
          'cancel_requested_at'=>null
        ]];
    } elseif (str_contains($this->sql,'FROM stu_console_task_journal')) {
      if (count($p)===4) [$run,$uid,$sid,$turn]=$p;
      else {[$run,$uid,$sid]=$p;$turn=23;}
      if ($run===$this->db->runId && $uid===7 && $sid==='session_demo'
          && $turn===23 && isset($this->db->journal[$run]))
        $this->rows=[$this->db->journal[$run]];
    }
    return true;
  }
  public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
    return array_shift($this->rows)??false;
  }
  public function fetchColumn(int $column=0):mixed {
    $r=array_shift($this->rows);return is_array($r)?reset($r):false;
  }
  public function rowCount():int {return $this->changed;}
}
final class JournalFakePDO extends PDO {
  public array $journal=[],$actions=[],$queries=[];
  public string $runId;
  public string $runStatus='running';
  private bool $tx=false;
  public function __construct(string $runId){$this->runId=$runId;}
  public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
    return new JournalFakeStatement($this,$query);
  }
  public function prepare(string $query,array $options=[]):PDOStatement|false {
    return new JournalFakeStatement($this,$query);
  }
  public function inTransaction():bool{return $this->tx;}
  public function beginTransaction():bool{$this->tx=true;return true;}
  public function commit():bool{$this->tx=false;return true;}
  public function rollBack():bool{$this->tx=false;return true;}
  public function exec(string $statement):int|false{return 1;}
}
$run=str_repeat('a',32);
$db=new JournalFakePDO($run);
journal_test(coreui_task_journal_schema_ready($db),'schema probe');
journal_test(coreui_task_journal_begin($db,7,'session_demo',$run,23,'chat'),'owned goal turn can start task');
journal_test(!coreui_task_journal_begin($db,8,'session_demo',$run,23,'chat'),'foreign account cannot start task');
$write=['tool'=>'pfile_write','input'=>"index.html\nhello"];
$c1=coreui_task_journal_claim($db,7,'session_demo',$run,1,$write);
journal_test($c1['status']==='claimed','new step claimed exactly once');
journal_test(coreui_task_journal_claim($db,7,'session_demo',$run,1,$write)['status']==='existing',
  'a duplicate step is not executed a second time');
journal_test(coreui_task_journal_claim($db,7,'session_demo',$run,1,
  ['tool'=>'pfile_write','input'=>"index.html\ndifferent"])['status']==='conflict',
  'changed payload cannot steal an existing step');
journal_test(coreui_task_journal_finish($db,7,'session_demo',$run,1,$write,
  'success','Datei gespeichert',['file_revision'=>4,'file_sha256'=>str_repeat('b',64)]),
  'trusted outcome committed with revision and checksum');
journal_test(!coreui_task_journal_finish($db,7,'session_demo',$run,1,$write,
  'success','Datei gespeichert'), 'finalized step cannot be double-finished');
$p=json_decode($db->actions[$run][1]['proof_json'],true);
journal_test(($p['file_revision']??0)===4 && ($p['file_sha256']??'')===str_repeat('b',64)
  && !str_contains($db->actions[$run][1]['proof_json'],'index.html')
  && ($db->journal[$run]['checkpoint_version']??0)===1,
  'the durable journal retains only evidence, no raw tool payload');
journal_test(coreui_task_journal_can_finish($db,7,'session_demo',$run),'verified project work can finish');
journal_test(coreui_task_journal_resume_check($db,7,'session_demo',$run)['reason']==='run_still_active',
  'an active worker may not be resumed concurrently');
coreui_task_journal_sync($db,7,'session_demo',$run,'interrupted','worker_lost');
journal_test(coreui_task_journal_resume_check($db,7,'session_demo',$run)['safe']===true,
  'interrupted verified checkpoint is safe for model-side continuation');
// The same automatic attempt can be claimed once, atomically. A second
// reconnect cannot re-bill tokens or replay an already completed file.
$db->runStatus='interrupted';
$resume=coreui_task_journal_resume_claim($db,7,'session_demo',$run);
journal_test(($resume['ok']??false)===true && $db->runStatus==='running'
  && ($db->journal[$run]['resume_checkpoint_version']??-1)===1,
  'authenticated SSE takeover claims one verified checkpoint exactly once');
$db->runStatus='interrupted';
coreui_task_journal_sync($db,7,'session_demo',$run,'interrupted','worker_lost');
journal_test(coreui_task_journal_resume_check($db,7,'session_demo',$run)['reason']==='checkpoint_already_resumed'
  && (coreui_task_journal_resume_claim($db,7,'session_demo',$run)['ok']??false)===false,
  'same checkpoint cannot trigger another paid recovery without new progress');
$db->runStatus='running';
coreui_task_journal_sync($db,7,'session_demo',$run,'running');
$command=['tool'=>'prun','input'=>'npm test'];
journal_test(coreui_task_journal_claim($db,7,'session_demo',$run,2,$command)['status']==='claimed',
  'a new test step can be claimed');
journal_test(coreui_task_journal_resume_check($db,7,'session_demo',$run)['reason']==='unconfirmed_side_effect'
  && !coreui_task_journal_can_finish($db,7,'session_demo',$run),
  'crashed or ambiguous command is never replayed or declared completed');
coreui_task_journal_sync($db,7,'session_demo',$run,'interrupted','worker_lost');
journal_test(($db->journal[$run]['status']??'')==='interrupted',
  'worker loss projects to the durable task status');
journal_test(!coreui_task_journal_valid_ref(7,'../secret',$run),'scope rejects path injection');
journal_test(!coreui_task_journal_valid_ref(7,'session_demo','bad'),'scope rejects foreign IDs');
$source=file_get_contents(dirname(__DIR__).'/database/migrations/057_task_journal.sql');
journal_test(str_contains($source,'UNIQUE KEY uq_task_action (run_id,step_no)')
  && str_contains($source,'ON DELETE CASCADE')
  && str_contains($source,"INSERT INTO stu_schema_migrations (version) VALUES ('057_task_journal')"),
  'migration is durable, cascade-owned and versioned');
echo "Task journal OK\n";
