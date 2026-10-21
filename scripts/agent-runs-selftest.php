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
    } elseif (str_contains($this->sql, 'INSERT INTO stu_console_agent_runs')) {
      [$id,$uid,$session,$turn,$key] = $p;
      $index = "$uid/$session/$turn/$key";
      if (!isset($this->db->runs[$index])) {
        $this->db->runs[$index] = [
          'run_id'=>$id,'turn_id'=>$turn,'run_key'=>$key,
          'response_message_id'=>null,'status'=>'queued','error_code'=>null,
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
    } elseif (str_contains($this->sql, 'SET r.response_message_id=?')) {
      [$reply,$id,$uid,$session,$verifiedReply] = $p;
      foreach ($this->db->runs as $index=>&$row) {
        if (str_starts_with($index,"$uid/$session/") && $row['run_id'] === $id
            && $row['status'] === 'running' && $row['response_message_id'] === null
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
$schema = file_get_contents(dirname(__DIR__) . '/database/migrations/013_console_agent_runs.sql');
agent_check(is_string($schema) && str_contains($schema,'UNIQUE KEY uq_agent_turn'),'DB idempotency index');
agent_check(str_contains($schema,'FOREIGN KEY (turn_id)'), 'DB turn reference');
echo "CoreUI P05 agent runs offline selftest: OK\n";
