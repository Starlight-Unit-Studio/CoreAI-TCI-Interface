<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_model_queue.php';

/** No DB driver required. Checks the FIFO/lock contract, not MariaDB itself. */
class CoreuiQueueFakeStatement extends PDOStatement {
  private mixed $value = false;
  private int $changed = 0;
  public function __construct(private CoreuiQueueFakePDO $db, private string $sql) {}
  public function execute(?array $params = null): bool {
    $p = $params ?? [];
    $this->value = false;
    $this->changed = 0;
    if (str_contains($this->sql,'INSERT INTO stu_console_model_queue')) {
      $id = (string)$p[0];
      $this->db->tickets[$id] = ['status'=>'queued','seq'=>++$this->db->seq];
      $this->changed = 1;
    } elseif (str_contains($this->sql,'SELECT GET_LOCK')) {
      $this->db->holderFinishes();
      $this->value = $this->db->lockOwner === null && !$this->db->forceBusy ? 1 : 0;
      if ($this->value === 1) $this->db->lockOwner = 'model';
    } elseif (str_contains($this->sql,'SELECT RELEASE_LOCK')) {
      $this->value = $this->db->lockOwner === null ? 0 : 1;
      $this->db->lockOwner = null;
    } elseif (str_contains($this->sql,'SELECT IS_USED_LOCK')) {
      $this->db->holderFinishes();
      $this->value = $this->db->lockOwner === null ? null : 1;
    } elseif (str_contains($this->sql,"SET status='running'")) {
      $id = (string)$p[0];
      if (($this->db->tickets[$id]['status'] ?? '') === 'queued') {
        $this->db->tickets[$id]['status'] = 'running';
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql,"SET status='completed'")) {
      $id = (string)$p[0];
      if (($this->db->tickets[$id]['status'] ?? '') === 'running') {
        $this->db->tickets[$id]['status'] = 'completed';
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql,'SET status=?,finished_at=')) {
      [$status,$id] = $p;
      if (($this->db->tickets[$id]['status'] ?? '') === 'queued') {
        $this->db->tickets[$id]['status'] = $status;
        $this->changed = 1;
      }
    } elseif (str_contains($this->sql,'SET updated_at=NOW(3)')) {
      $id = (string)$p[0];
      if (($this->db->tickets[$id]['status'] ?? '') === 'queued') $this->changed = 1;
    }
    return true;
  }
  public function fetchColumn(int $column = 0): mixed { return $this->value; }
  public function rowCount(): int { return $this->changed; }
}
class CoreuiQueueFakePDO extends PDO {
  public array $tickets = [];
  public int $seq = 0;
  public ?string $lockOwner = null;
  public bool $forceBusy = false;
  /** 0.8.34: a live worker that holds the model until this time, then releases it. */
  public ?float $releaseAt = null;
  public function __construct() {}
  public function holderFinishes(): void {
    if ($this->releaseAt !== null && microtime(true) >= $this->releaseAt) {
      $this->lockOwner = null;
      $this->releaseAt = null;
    }
  }
  public function prepare(string $query, array $options = []): PDOStatement|false {
    return new CoreuiQueueFakeStatement($this,$query);
  }
  public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
    if (str_contains($query,'ORDER BY queue_id ASC')) {
      $oldest = null;
      foreach ($this->tickets as $id=>$ticket) {
        if ($ticket['status'] !== 'queued') continue;
        if ($oldest === null || $ticket['seq'] < $oldest['seq']) {
          $oldest = ['id'=>$id,'seq'=>$ticket['seq']];
        }
      }
      return new class($oldest['id'] ?? false) extends PDOStatement {
        public function __construct(private mixed $value) {}
        public function fetchColumn(int $column = 0): mixed { return $this->value; }
      };
    }
    return new CoreuiQueueFakeStatement($this,$query);
  }
  public function exec(string $statement): int|false { return 0; }
}
function queue_check(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR,"FAIL: $message\n"); exit(1); }
}
$db = new CoreuiQueueFakePDO();
queue_check(coreui_model_queue_schema_ready($db),'schema probe');
$first = coreui_model_queue_acquire($db,'test_ollama',1);
queue_check(is_string($first) && strlen($first)===32,'first ticket claimed');
queue_check($db->tickets[$first]['status']==='running','ticket running');
queue_check($db->lockOwner !== null,'DB lock acquired');
$db->forceBusy = true;
$second = coreui_model_queue_acquire($db,'test_ollama',0);
queue_check($second===false,'busy call timed out');
queue_check(count(array_filter($db->tickets,static fn($r)=>$r['status']==='timed_out'))===1,
  'timed out ticket persisted');
$db->forceBusy = false;
coreui_model_queue_release($db,'test_ollama',$first);
queue_check($db->tickets[$first]['status']==='completed','ticket completed');
queue_check($db->lockOwner===null,'DB lock released');
try {
  coreui_model_queue_acquire($db,'test_ollama',1,static fn(): bool => true);
  queue_check(false,'cancel must interrupt');
} catch (RuntimeException $e) {
  queue_check($e->getMessage()==='agent_cancelled','typed cancellation');
}
queue_check(count(array_filter($db->tickets,static fn($r)=>$r['status']==='cancelled'))===1,
  'cancelled ticket persisted');
queue_check($db->lockOwner===null,'cancel does not leak lock');
$third = coreui_model_queue_acquire($db,'test_ollama',1);
queue_check(is_string($third) && $db->tickets[$third]['status']==='running',
  'new ticket can acquire after release');
coreui_model_queue_release($db,'test_ollama',$third);
queue_check($db->lockOwner===null,'final release');
// 0.8.34: a generation may hold the model for many minutes. A call behind a live holder keeps its
// place instead of failing with model_queue_timeout after its short wait (scaled: a 1 s wait behind
// a holder that needs 2.5 s); a holder that is gone does not extend the wait.
$holder = coreui_model_queue_acquire($db,'test_ollama',1);
queue_check(is_string($holder) && $db->lockOwner !== null,'a long generation holds the model');
$db->releaseAt = microtime(true) + 2.5;
$waitStarted = microtime(true);
$behind = coreui_model_queue_acquire($db,'test_ollama',1,null,null,30);
queue_check(is_string($behind) && $db->tickets[$behind]['status']==='running'
  && microtime(true) - $waitStarted >= 2.0,'a call behind a live holder is not timed out');
$db->tickets[$holder]['status'] = 'completed';
coreui_model_queue_release($db,'test_ollama',$behind);
$db->forceBusy = true; // the lock cannot be taken, but nobody holds it: no live holder
$waitStarted = microtime(true);
queue_check(coreui_model_queue_acquire($db,'test_ollama',1,null,null,30)===false
  && microtime(true) - $waitStarted < 5.0,'without a live holder the wait stays bounded');
$db->forceBusy = false;
$queueSource = (string)file_get_contents(dirname(__DIR__) . '/api/console_model_queue.php');
queue_check(str_contains($queueSource,'&& coreui_model_queue_holder_alive($pdo, $lockName)) {'),'holder wait wired');
$schema = file_get_contents(dirname(__DIR__) . '/database/migrations/013_console_agent_runs.sql');
queue_check(is_string($schema) && str_contains($schema,'KEY idx_model_fifo (status,queue_id)'),
  'FIFO index');
queue_check(str_contains($schema,'cancel_requested_at DATETIME(3)'), 'cancel metadata');
echo "CoreUI P05 model queue offline selftest: OK\n";
