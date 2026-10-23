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
$syntax = coreui_work_python_diagnostic([
  'id'=>69,'exit_code'=>1,'duration_ms'=>482,
  'stderr'=>"Traceback (most recent call last):\n  File \"/work/main.py\", line 17\n"
    . "    invalid python code here\nSyntaxError: invalid syntax\nSECRET=PRIVATE_HOME_PAGE",
]);
check_work(($syntax['error_type'] ?? '') === 'SyntaxError'
  && ($syntax['line_number'] ?? 0) === 17
  && ($syntax['duration_ms'] ?? 0) === 482, 'safe syntax line diagnostics');
check_work(!str_contains(json_encode($syntax), 'SECRET')
  && !str_contains(json_encode($syntax), '/work/main.py'),
  'private Python code and paths must not be leaked');
check_work(($d['error_type'] ?? '') === 'FileNotFoundError', 'meaningful error type');
check_work(($d['file_name'] ?? '') === 'mini_game.html', 'safe filename');
check_work(!str_contains(json_encode($d), 'SECRET'), 'raw stderr must not be exposed');
check_work(($d['exit_code'] ?? 0) === 1 && ($d['job_id'] ?? 0) === 42, 'real job metadata');
$safe = coreui_work_safe_meta(['model'=>'gpt-oss:120b', 'stderr'=>'sensitive', 'file_name'=>'/tmp/test.zip']);
check_work(($safe['model'] ?? '') === 'gpt-oss:120b', 'cloud model metadata');
check_work(($safe['file_name'] ?? '') === 'test.zip', 'basename only');
check_work(!isset($safe['stderr']), 'raw stderr suppressed');
$counts = coreui_work_safe_meta([
  'source_chars'=>3816, 'eval_count'=>2734, 'prompt_eval_count'=>726,
  'code'=>'secret:model:content','prompt'=>'private instructions',
]);
check_work(($counts['source_chars'] ?? 0) === 3816
  && ($counts['eval_count'] ?? 0) === 2734
  && ($counts['prompt_eval_count'] ?? 0) === 726,
  'bounded model output length diagnostics preserved');
check_work(!isset($counts['code']) && !isset($counts['prompt']),
  'model code and private prompts are never exposed in work events');
check_work(($s = coreui_work_python_diagnostic(null)) && ($s['error_type'] ?? '') === 'worker_timeout', 'worker timeout classified');
echo "CoreUI P06 work events selftest: OK\n";
