<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/image_work_diagnostic.php';

function image_diag_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

$progress = ['reason'=>'image_sampling','step'=>2,'steps'=>4,
  'prompt'=>'SECRET', 'reference'=>'/srv/private/a.png'];
$exit = coreui_image_failure_work_meta(
  ['error'=>'image_failed','detail'=>'sd-cli Exit 137'], $progress);
image_diag_check(($exit['reason'] ?? null) === 'image_worker_exit'
  && ($exit['exit_code'] ?? null) === 137, 'exit code classification');
image_diag_check(($exit['image_phase'] ?? null) === 'sampling'
  && ($exit['step'] ?? null) === 2 && ($exit['steps'] ?? null) === 4,
  'last confirmed image phase');
image_diag_check(!str_contains(json_encode($exit), 'SECRET')
  && !str_contains(json_encode($exit), 'private'), 'no private worker data');

foreach ([
  ['image_failed','Zeitlimit von 720 s erreicht','image_worker_timeout'],
  ['image_failed','kein PNG erzeugt','image_png_missing'],
  ['image_failed','kein gueltiges PNG','image_png_invalid'],
  ['image_failed','Auftrag verloren','image_job_lost'],
  ['image_failed','OSError: /var/secrets/file name','image_worker_exception'],
  ['image_failed','Unknown /var/secrets/file name','image_failed'],
  ['image_timeout','/var/secrets/anything','image_timeout'],
  ['storage_full','','storage_full'],
  ['license_accept_required','','license_accept_required'],
] as [$error,$detail,$expected]) {
  $diag = coreui_image_failure_work_meta(['error'=>$error,'detail'=>$detail],
    ['reason'=>'image_unknown','step'=>99999]);
  image_diag_check(($diag['reason'] ?? '') === $expected, 'reason: ' . $error);
  image_diag_check(!str_contains(json_encode($diag), '/var/'),
    'raw backend detail leaked');
  image_diag_check(!isset($diag['step']) && !isset($diag['image_phase']),
    'unconfirmed progress was discarded');
}
image_diag_check(coreui_image_failure_work_meta(
  ['error'=>'image_failed','detail'=>'OSError: secret'])['error_type'] === 'OSError',
  'bounded exception class');
image_diag_check(coreui_image_failure_work_meta(
  ['error'=>'image_failed','detail'=>'MyWeirdError: secret'])['reason'] === 'image_failed',
  'unrecognized classes cannot become public data');

echo "CoreAI image work diagnostic selftest: OK\n";
