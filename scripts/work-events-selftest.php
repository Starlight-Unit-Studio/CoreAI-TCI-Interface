<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_work_store.php';
function check_work(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "FAIL: $why\n"); exit(1); }
}
$d = coreui_work_python_diagnostic([
  'id'=>42,'exit_code'=>1,'duration_ms'=>302,
  'stderr'=>"Traceback\nFileNotFoundError: [Errno 2] No such file or directory: '/mnt/data/demo/mini_game.html'\nSECRET=DO_NOT_SHOW"
]);
check_work(($d['error_type'] ?? '') === 'FileNotFoundError', 'meaningful error type');
check_work(($d['file_name'] ?? '') === 'mini_game.html', 'safe filename');
check_work(!str_contains(json_encode($d), 'SECRET'), 'raw stderr must not be exposed');
check_work(($d['exit_code'] ?? 0) === 1 && ($d['job_id'] ?? 0) === 42, 'real job metadata');
$safe = coreui_work_safe_meta(['model'=>'gpt-oss:120b', 'stderr'=>'sensitive', 'file_name'=>'/tmp/test.zip']);
check_work(($safe['model'] ?? '') === 'gpt-oss:120b', 'cloud model metadata');
check_work(($safe['file_name'] ?? '') === 'test.zip', 'basename only');
check_work(!isset($safe['stderr']), 'raw stderr suppressed');
check_work(($s = coreui_work_python_diagnostic(null)) && ($s['error_type'] ?? '') === 'worker_timeout', 'worker timeout classified');
echo "CoreUI P06 work events selftest: OK\n";
