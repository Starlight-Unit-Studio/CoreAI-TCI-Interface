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
$window = coreui_work_safe_meta(['reason'=>'context_window_full','context_tokens'=>4096]);
check_work(($window['reason'] ?? '') === 'context_window_full'
  && ($window['context_tokens'] ?? 0) === 4096,
  'the local context window size is a safe numeric diagnostic');
$planned = coreui_work_safe_meta(['file_list'=>['index.html','style.css','../etc/passwd','.env',['x'],'main.js']]);
check_work(($planned['file_list'] ?? []) === ['index.html','style.css','main.js'],
  'the planned file list keeps safe flat basenames only');
$public = coreui_work_safe_meta([
  'done_reason'=>'length', 'content_chars'=>2512, 'thinking_chars'=>908,
  'continuation_segments'=>2, 'plan_version'=>3, 'response_bytes'=>1090,
  'marker_incomplete'=>true, 'source_code'=>'secret',
  'raw_thinking'=>'never leak thinking', 'system_prompt'=>'private prompt',
]);
check_work(($public['done_reason'] ?? '') === 'length'
  && ($public['content_chars'] ?? 0) === 2512
  && ($public['thinking_chars'] ?? 0) === 908
  && ($public['continuation_segments'] ?? 0) === 2
  && ($public['plan_version'] ?? 0) === 3
  && ($public['response_bytes'] ?? 0) === 1090
  && ($public['marker_incomplete'] ?? null) === true,
  'work narrative exposes real bounded diagnostics only');
check_work(!isset($public['raw_thinking']) && !isset($public['system_prompt'])
  && !isset($public['source_code']), 'private model data stays filtered');
check_work(($s = coreui_work_python_diagnostic(null)) && ($s['error_type'] ?? '') === 'worker_timeout', 'worker timeout classified');

$comment = coreui_work_safe_meta([
  'public_note'=>'Ich pruefe zuerst den gespeicherten Projektplan.',
  'raw_thinking'=>'private', 'system_prompt'=>'private'
]);
check_work(($comment['public_note'] ?? '') === 'Ich pruefe zuerst den gespeicherten Projektplan.',
  'Only explicit public progress from content may be shown');
check_work(!isset($comment['raw_thinking']) && !isset($comment['system_prompt']),
  'Private thinking and prompts are excluded');
$unsafePublic = coreui_work_safe_meta([
  'public_note'=>'Hier ist der API key: secret',
]);
check_work(!isset($unsafePublic['public_note']), 'Sensitive public note must fail closed');

echo "CoreUI P06 work events selftest: OK\n";
