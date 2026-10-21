<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_work_store.php';

function work_persist_assert(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "FAIL: $why\n"); exit(1); }
}

class WorkPersistFakeStatement extends PDOStatement {
  private array $rows = [];
  public function __construct(private WorkPersistFakePDO $db, private string $sql) {}
  public function execute(?array $params = null): bool {
    $this->rows = [];
    $p = $params ?? [];
    if (!str_contains($this->sql, 'FROM stu_console_work_events w')) return true;
    work_persist_assert(str_contains($this->sql, 'LEFT JOIN stu_console_agent_runs r')
      && str_contains($this->sql, 'BINARY r.run_key=BINARY w.run_key'), 'owner-scoped run join missing');
    $single = str_contains($this->sql, 'w.turn_id=?');
    $uid = (int)($p[0] ?? 0);
    $session = (string)($p[1] ?? '');
    $turn = $single ? (int)($p[2] ?? 0) : 0;
    $responseIds = $single
      ? [(int)($p[3] ?? 0)]
      : array_values(array_unique(array_map('intval',array_slice($p,2))));
    foreach ($this->db->events as $event) {
      if ($event['user_id'] !== $uid || $event['session_id'] !== $session) continue;
      if ($single && $event['turn_id'] !== $turn) continue;
      $key = implode('|',[$uid,$session,$event['turn_id'],$event['run_key']]);
      $agent = $this->db->runs[$key] ?? null;
      $responseId = $event['response_message_id']
        ?? ($agent['response_message_id'] ?? null);
      if (!in_array((int)$responseId,$responseIds,true)) continue;
      $row = $event;
      $row['response_message_id'] = $responseId;
      $row['unix_time'] = 1792570000;
      $this->rows[] = $row;
    }
    usort($this->rows,static fn($a,$b)=>$a['id'] <=> $b['id']);
    return true;
  }
  public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
    return $this->rows;
  }
}

class WorkPersistFakePDO extends PDO {
  public array $events = [];
  public array $runs = [];
  public function __construct() {}
  public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
    return new WorkPersistFakeStatement($this,$query);
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    return new WorkPersistFakeStatement($this,$query);
  }
}

$db = new WorkPersistFakePDO();
$db->runs = [
  '7|session_a|11|base'=>['response_message_id'=>21],
  '7|session_a|11|regenerate'=>['response_message_id'=>22],
  '8|session_a|11|base'=>['response_message_id'=>88],
  '7|session_b|11|base'=>['response_message_id'=>89],
];
$base = [
  'user_id'=>7,'session_id'=>'session_a','turn_id'=>11,'run_key'=>'base',
  'response_message_id'=>null,'kind'=>'tool','state'=>'success',
  'title'=>'Werkzeug ausgefuehrt','details_json'=>'{"job_id":3,"stderr":"SECRET"}'
];
$db->events = [
  ['id'=>1,'response_message_id'=>21] + $base,
  ['id'=>2,'title'=>'Ausgabe gesichert'] + $base,
  ['id'=>3,'run_key'=>'regenerate','title'=>'Alternative erstellt'] + $base,
  ['id'=>4,'user_id'=>8,'title'=>'Fremder Account'] + $base,
  ['id'=>5,'session_id'=>'session_b','title'=>'Fremde Sitzung'] + $base,
  ['id'=>6,'run_key'=>'unknown','title'=>'Nicht zugeordnet'] + $base,
];
work_persist_assert(coreui_work_schema_ready($db),'schema probe');
$map = coreui_work_map_for_responses($db,7,'session_a',[21,22]);
work_persist_assert(count($map[21] ?? [])===2,'bound and interrupted binding recovered');
work_persist_assert(count($map[22] ?? [])===1,'regeneration stays separate');
work_persist_assert(count($map)===2,'other users and sessions are isolated');
work_persist_assert(($map[21][1]['title'] ?? '')==='Ausgabe gesichert',
  'recovered event order');
work_persist_assert(!str_contains(json_encode($map),'SECRET'),
  'no private stderr in public events');
$first = coreui_work_list($db,7,'session_a',11,21);
$second = coreui_work_list($db,7,'session_a',11,22);
work_persist_assert(count($first)===2 && count($second)===1,
  'SSE response lookup recovers events without variant bleed');
work_persist_assert(coreui_work_list($db,8,'session_a',11,21)===[],
  'no cross-account access');
echo "CoreUI P06 persisted work timeline selftest: OK\n";
