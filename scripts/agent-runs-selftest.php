<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_agent_runs.php';

/**
 * Offline contract test. PDO has no database driver here; the fake verifies
 * SQL scoping and compare-and-swap behavior, not MariaDB integration.
 */
class CoreuiAgentFakeStatement extends PDOStatement {
  private array $rows = [];
  private int $changed = 0;
  public function __construct(private CoreuiAgentFakePDO $db, private string $sql) {}
  public function execute(?array $params = null): bool {
    $p = $params ?? [];
    $this->db->queries[] = [$this->sql, $p];
    $this->rows = [];
    $this->changed = 0;
    if (str_contains($this->sql, 'FROM stu_console_sessions')) {
      if ($p === ['session_demo', 7]) $this->rows = [['id'=>'session_demo']];
    } elseif (str_contains($this->sql, 'SELECT id FROM stu_chat_messages')) {
      if ($p === [23,7,'session_demo']) $this->rows = [['id'=>23]];
      elseif (str_contains($this->sql,"WHERE channel='console'")
          && ($p[0]??0)===7 && ($p[1]??'')==='session_demo'
          && ($p[2]??0)===23) $this->rows = [['id'=>24]];
    } elseif (str_contains($this->sql, 'INSERT INTO stu_console_agent_runs')) {
      [$id,$uid,$session,$turn,$key] = $p;
      $index = "$uid/$session/$turn/$key";
      if (!isset($this->db->runs[$index])) {
        $this->db->runs[$index] = [
          'run_id'=>$id,'turn_id'=>$turn,'run_key'=>$key,
          'response_message_id'=>null,'browse_job_id'=>null,'cancel_requested_at'=>null,
          'status'=>'queued','error_code'=>null,
          'created_at'=>'now','started_at'=>null,'updated_at'=>'now','finished_at'=>null
        ];
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql, 'FROM stu_console_agent_runs')
        && str_contains($this->sql, 'AND turn_id=? AND run_key=?')) {
      [$uid,$session,$turn,$key] = $p;
      $row = $this->db->runs["$uid/$session/$turn/$key"] ?? null;
      if ($row !== null) $this->rows = [$row];
    } elseif (str_contains($this->sql, 'FROM stu_console_agent_runs')
        && str_contains($this->sql, 'WHERE run_id=?')) {
      [$id,$uid,$session] = $p;
      foreach ($this->db->runs as $index=>$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id'] === $id) {
          $this->rows = [$row];
        }
      }
    } elseif (str_contains($this->sql, "SET status='cancelled'")) {
      [$id,$uid,$session] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id']===$id
            && $row['status']==='queued') {
          $row['status']='cancelled';
          $row['cancel_requested_at']='now';
          $this->changed=1;
        }
      }
      unset($row);
    } elseif (str_contains($this->sql, 'SET cancel_requested_at=COALESCE')) {
      [$id,$uid,$session] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id']===$id
            && in_array($row['status'],['running','waiting_tool'],true)) {
          $row['cancel_requested_at']='now';
          $this->changed=1;
        }
      }
      unset($row);
    } elseif (str_contains($this->sql, 'SET status=?')) {
      [$to,$err,$start,$end,$id,$uid,$session,$from] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id'] === $id
            && $row['status'] === $from) {
          $row['status'] = $to;
          $row['error_code'] = $err;
          $this->changed = 1;
          break;
        }
      }
      unset($row);
    } elseif (str_contains($this->sql,'SET r.browse_job_id=?')) {
      [$job,$id,$uid,$session,$verifyJob] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id']===$id
            && $row['status']==='running' && $job===$verifyJob
            && isset($this->db->browseJobs[$job])) {
          $row['browse_job_id']=$job;
          $this->changed=1;
        }
      }
      unset($row);
    } elseif (str_contains($this->sql,'SELECT status,finished_at FROM stu_ember_browse_jobs')) {
      $job=(int)$p[0];
      if (isset($this->db->browseJobs[$job]) && $p[1]===7
          && $p[2]==='session_demo' && $p[3]===23) {
        $this->rows=[$this->db->browseJobs[$job]];
      }
    } elseif (str_contains($this->sql,'SELECT id FROM stu_chat_messages WHERE channel=')) {
      if ($p[0]===7 && $p[1]==='session_demo' && $p[2]===23) {
        $this->rows=[['id'=>24]];
      }
    } elseif (str_contains($this->sql, 'SET r.response_message_id=?')) {
      [$reply,$id,$uid,$session,$verifiedReply] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id'] === $id
            && in_array($row['status'],['running','waiting_tool'],true)
            && $row['response_message_id'] === null
            && $reply === 24 && $verifiedReply === 24) {
          $row['response_message_id'] = $reply;
          $this->changed = 1;
          break;
        }
      }
      unset($row);
    } elseif (str_contains($this->sql, 'response_message_id IN (')) {
      $uid = $p[0]; $session = $p[1]; $ids = array_slice($p, 2);
      foreach ($this->db->runs as $index=>$row) {
        if (str_starts_with($index,"$uid/$session/")
            && in_array($row['response_message_id'],$ids,true)) $this->rows[] = $row;
      }
    }
    return true;
  }
  public function fetchColumn(int $column = 0): mixed {
    return $this->rows === [] ? false : reset($this->rows[0]);
  }
  public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
    return $this->rows[0] ?? false;
  }
  public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
    return $this->rows;
  }
  public function rowCount(): int { return $this->changed; }
}
class CoreuiAgentFakePDO extends PDO {
  public array $runs = [];
  public array $browseJobs = [];
  public array $queries = [];
  public function __construct() {}
  public function prepare(string $query, array $options = []): PDOStatement|false {
    return new CoreuiAgentFakeStatement($this,$query);
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
    return new CoreuiAgentFakeStatement($this,$query);
  }
}
function agent_check(bool $ok, string $reason): void {
  if (!$ok) { fwrite(STDERR, "FAIL: $reason\n"); exit(1); }
}
$db = new CoreuiAgentFakePDO();
agent_check(coreui_agent_run_schema_ready($db), 'schema probe');
agent_check(coreui_agent_run_valid_key('base'), 'base run key');
agent_check(!coreui_agent_run_valid_key('bad/key'), 'unsafe run key');
agent_check(coreui_agent_run_allowed_transition('queued','running'), 'valid claim');
agent_check(!coreui_agent_run_allowed_transition('completed','running'), 'terminal immutable');
agent_check(!coreui_agent_run_allowed_transition('running','queued'), 'no silent replay');
try {
  coreui_agent_run_ensure($db,8,'session_demo',23);
  agent_check(false,'foreign session denied');
} catch (RuntimeException $e) {
  agent_check($e->getMessage()==='session_not_found','owner isolation');
}
$a = coreui_agent_run_ensure($db,7,'session_demo',23);
$b = coreui_agent_run_ensure($db,7,'session_demo',23);
agent_check($a['run_id']===$b['run_id'] && count($db->runs)===1,'idempotent run');
$id = $a['run_id'];
agent_check(coreui_agent_run_transition($db,7,'session_demo',$id,'queued','running'),'claim');
agent_check(!coreui_agent_run_transition($db,7,'session_demo',$id,'queued','running'),'duplicate claim blocked');
agent_check(!coreui_agent_run_bind_response($db,7,'session_demo',$id,99),'foreign reply rejected');
agent_check(coreui_agent_run_bind_response($db,7,'session_demo',$id,24),'owned reply bound');
agent_check(coreui_agent_run_transition($db,7,'session_demo',$id,'running','completed'),'complete');
agent_check(!coreui_agent_run_transition($db,7,'session_demo',$id,'running','completed'),'no double finish');
agent_check(coreui_agent_run_get($db,8,'session_demo',$id)===null,'foreign run invisible');
agent_check(coreui_agent_run_get($db,7,'session_demo',$id)['status']==='completed','owner read');
$map = coreui_agent_run_map_for_responses($db,7,'session_demo',[24,24,99]);
agent_check(($map[24]['status']??'')==='completed' && !isset($map[99]),'scoped history');
$queuedCancel = coreui_agent_run_ensure($db,7,'session_demo',23,'cancel_queued');
$cancelled = coreui_agent_run_request_cancel($db,7,'session_demo',$queuedCancel['run_id']);
agent_check($cancelled['status']==='cancelled','queued run cancelled');
agent_check(coreui_agent_run_cancel_requested($db,7,'session_demo',$queuedCancel['run_id']),
  'queued cancellation visible');
$activeCancel = coreui_agent_run_ensure($db,7,'session_demo',23,'cancel_running');
agent_check(coreui_agent_run_transition($db,7,'session_demo',$activeCancel['run_id'],
  'queued','running'),'second run claimed');
$pending = coreui_agent_run_request_cancel($db,7,'session_demo',$activeCancel['run_id']);
agent_check($pending['status']==='running' && $pending['cancel_requested'],
  'active request remains running until worker acknowledges');
agent_check(coreui_agent_run_cancel_requested($db,7,'session_demo',$activeCancel['run_id']),
  'provider callback observes cancellation');
agent_check(coreui_agent_run_transition($db,7,'session_demo',$activeCancel['run_id'],
  'running','cancelled'),'worker confirms cancellation');
try {
  coreui_agent_run_request_cancel($db,8,'session_demo',$activeCancel['run_id']);
  agent_check(false,'foreign cancellation blocked');
} catch (RuntimeException $e) {
  agent_check($e->getMessage()==='agent_run_not_found','foreign cancel isolated');
}
$browser = coreui_agent_run_ensure($db,7,'session_demo',23,'browser_case');
agent_check(coreui_agent_run_transition($db,7,'session_demo',$browser['run_id'],
  'queued','running'),'browser run claimed');
$db->browseJobs[55]=['status'=>'done','finished_at'=>'now'];
agent_check(coreui_agent_run_attach_browse($db,7,'session_demo',$browser['run_id'],55),
  'browser job bound to owned run');
agent_check(coreui_agent_run_transition($db,7,'session_demo',$browser['run_id'],
  'running','waiting_tool'),'browser delegated');
$resolved = coreui_agent_run_reconcile_waiting_tool($db,7,'session_demo',$browser['run_id']);
agent_check(($resolved['status']??'')==='completed'
  && (int)($resolved['response_message_id']??0)===24,'browser result reconciled');
$schema = file_get_contents(dirname(__DIR__) . '/database/migrations/013_console_agent_runs.sql');
agent_check(is_string($schema) && str_contains($schema,'UNIQUE KEY uq_agent_turn'),'DB idempotency index');
agent_check(str_contains($schema,'FOREIGN KEY (turn_id)'), 'DB turn reference');
echo "CoreUI P05 agent runs offline selftest: OK\n";
