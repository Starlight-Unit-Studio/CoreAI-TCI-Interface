<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/image_gen.php';
require_once __DIR__ . '/../api/image_ab_diagnostic.php';

function ab_check(bool $ok, string $reason): void {
  if (!$ok) { fwrite(STDERR, "AB FAIL: $reason\n"); exit(1); }
}
$seen = [];
$successPng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 512, 512) . str_repeat("\0", 5);
$queue = [];
$http = static function(string $method, string $path, ?array $body = null, int $timeout = 10)
    use (&$seen, &$queue, $successPng): array {
  $seen[] = [$method,$path,$body];
  if ($path === '/health') return [200, [
    'ready'=>true, 'busy'=>false, 'ponpoke'=>[COREUI_IMAGE_AB_MODEL=>['ready'=>true]],
  ], ''];
  if ($method === 'POST' && $path === '/jobs') {
    $case = ($body['user_license_accepted'] ?? false) === true ? 'ponpoke' : 'qwen';
    $id = $case === 'qwen' ? str_repeat('a', 32) : str_repeat('b', 32);
    $queue[$case] = $id;
    return [202, ['job'=>['id'=>$id,'model'=>COREUI_IMAGE_AB_MODEL,
      'text_encoder'=>$case === 'qwen' ? 'original' : 'ponpoke-uncensored-q8_0']], ''];
  }
  if ($method === 'GET' && $path === '/jobs/' . str_repeat('a', 32)) {
    return [200, ['job'=>['state'=>'done','phase'=>'done','step'=>4,'steps'=>4,
      'seconds'=>60.3,'peak_rss_mb'=>9900,'text_encoder'=>'original']], ''];
  }
  if ($method === 'GET' && $path === '/jobs/' . str_repeat('b', 32)) {
    return [200, ['job'=>['state'=>'failed','phase'=>'sampling','step'=>2,'steps'=>4,
      'seconds'=>92.6,'peak_rss_mb'=>15001,'text_encoder'=>'ponpoke-uncensored-q8_0',
      'error'=>'sd-cli Exit 137']], ''];
  }
  if ($method === 'GET' && $path === '/jobs/' . str_repeat('a', 32) . '/image') {
    return [200, null, $successPng];
  }
  if ($method === 'DELETE') return [200, ['ok'=>true], ''];
  throw new RuntimeException('Unexpected HTTP ' . $method . ' ' . $path);
};

$state = coreui_image_ab_start(coreui_image_ab_empty(), $http);
ab_check($state['state'] === 'running' && $state['active'] === 'qwen', 'first test must use Qwen');
ab_check(($seen[1][2]['model'] ?? '') === COREUI_IMAGE_AB_MODEL
  && ($seen[1][2]['seed'] ?? 0) === COREUI_IMAGE_AB_SEED
  && ($seen[1][2]['size'] ?? '') === COREUI_IMAGE_AB_SIZE
  && ($seen[1][2]['user_license_accepted'] ?? null) === false,
  'first test fixed 4B, seed, 512px and original encoder');
$state = coreui_image_ab_poll($state, true, $http);
ab_check(($state['cases']['qwen']['state'] ?? '') === 'success'
  && ($state['cases']['qwen']['png_valid'] ?? null) === true,
  'first PNG validated');
ab_check($state['active'] === 'ponpoke'
  && ($state['cases']['ponpoke']['state'] ?? '') === 'running', 'second test starts serially');
$posts = array_values(array_filter($seen, static fn($call): bool => $call[0] === 'POST'));
ab_check(count($posts) === 2
  && $posts[0][2]['prompt'] === $posts[1][2]['prompt']
  && $posts[0][2]['seed'] === $posts[1][2]['seed']
  && $posts[0][2]['size'] === $posts[1][2]['size']
  && $posts[1][2]['user_license_accepted'] === true,
  'identical controlled prompts and independently approved Ponpoke');
$state = coreui_image_ab_poll($state, true, $http);
$public = coreui_image_ab_public($state);
ab_check($state['state'] === 'completed' && $public['comparison'] === 'ponpoke_path_suspected',
  'Qwen success, Ponpoke failure must be classified without alleging proof');
ab_check(($public['cases']['ponpoke']['error_code'] ?? '') === 'image_worker_exit'
  && ($public['cases']['ponpoke']['exit_code'] ?? 0) === 137
  && ($public['cases']['ponpoke']['peak_rss_mb'] ?? 0) === 15001.0,
  'actual exit code and peak memory included');
ab_check(!str_contains(json_encode($public), str_repeat('a',32))
  && !str_contains(json_encode($public), 'small red rowboat')
  && !str_contains(json_encode($public), 'sd-cli Exit'),
  'no worker job ids, prompts or raw logs in the browser');
ab_check(!array_filter($seen, static fn(array $row): bool =>
    str_contains($row[1], 'pgen') || str_contains($row[1], '/settings')),
  'never change global model or settings');

// A confirmed 4B baseline must not grant another account the gated encoder.
$unlicensedSeen = [];
$limited = static function(string $method,string $path,?array $body=null,int $timeout=10)
  use (&$unlicensedSeen,$successPng): array {
  $unlicensedSeen[] = [$method,$path,$body];
  if ($path === '/health') return [200, ['ready'=>true,'busy'=>false,
    'ponpoke'=>[COREUI_IMAGE_AB_MODEL=>['ready'=>true]]], ''];
  if ($method === 'POST') return [202, ['job'=>['id'=>str_repeat('a',32),
    'model'=>COREUI_IMAGE_AB_MODEL,'text_encoder'=>'original']], ''];
  if ($path === '/jobs/' . str_repeat('a',32)) return [200, ['job'=>['state'=>'done',
    'phase'=>'done','step'=>4,'steps'=>4,'seconds'=>1]], ''];
  if (str_ends_with($path,'/image')) return [200,null,$successPng];
  throw new RuntimeException('Unexpected ' . $path);
};
$noConsent = coreui_image_ab_poll(coreui_image_ab_start(coreui_image_ab_empty(), $limited),false,$limited);
ab_check($noConsent['state'] === 'completed'
  && $noConsent['cases']['ponpoke']['state'] === 'unavailable'
  && $noConsent['cases']['ponpoke']['error_code'] === 'license_accept_required',
  'no individual license never starts Ponpoke');
ab_check(count(array_filter($unlicensedSeen,static fn($row): bool=>$row[0]==='POST')) === 1,
  'only Qwen was executed without individual consent');

// A wrong encoder must be rejected and its job cancelled, never passed as success.
$wrongCalls = [];
$wrong = static function(string $method,string $path,?array $body=null,int $timeout=10)
  use (&$wrongCalls): array {
  $wrongCalls[] = [$method,$path];
  if ($method === 'POST') return [202, ['job'=>['id'=>str_repeat('b',32),
    'model'=>COREUI_IMAGE_AB_MODEL,'text_encoder'=>'original']], ''];
  if ($method === 'DELETE') return [200,['ok'=>true],''];
  throw new RuntimeException('Unexpected ' . $path);
};
$mismatch = coreui_image_ab_launch('ponpoke',$wrong);
ab_check($mismatch['state'] === 'failed'
  && $mismatch['error_code'] === 'diagnostic_encoder_mismatch',
  'silent Qwen fallback must fail the Ponpoke control');
ab_check(count($wrongCalls) === 2 && $wrongCalls[1][0] === 'DELETE',
  'cancel a wrongly routed worker job');

$base = coreui_image_ab_empty();
$base['state']='running';$base['active']='qwen';
$base['cases']['qwen']=['state'=>'running','job_id'=>str_repeat('c',32)];
$cancelled = coreui_image_ab_cancel($base, static function(string $m,string $p,?array $b=null,int $t=5): array {
  ab_check($m==='DELETE','cancel calls worker DELETE');
  return [200, ['ok'=>true], ''];
});
ab_check($cancelled['state'] === 'cancelled' && $cancelled['active'] === '',
  'aborted operator run stops the second test');

define('COREUI_IMAGE_AB_TEST_DIR',sys_get_temp_dir().'/coreai-ab-selftest-'.bin2hex(random_bytes(6)));
$persist = coreui_image_ab_locked(static fn(array $state): array =>
  ['state'=>array_replace($state,['state'=>'completed','active'=>''])]);
ab_check($persist['state']==='completed','owner state persists in a private file');
$again = coreui_image_ab_locked(static fn(array $state): array => ['state'=>$state]);
ab_check($again['state']==='completed','page reload recovers state');
$dir = COREUI_IMAGE_AB_TEST_DIR;
@unlink($dir.'/image-ab-diagnostic.json');
@unlink($dir.'/image-ab-diagnostic.lock');
@rmdir($dir);
echo "CoreAI 4B encoder A/B diagnostic selftest: OK\n";
