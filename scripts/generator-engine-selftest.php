<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/core_generator_engine.php';

function engine_test(bool $condition, string $reason): void {
  if (!$condition) {
    fwrite(STDERR, "Generator engine FAIL: {$reason}\n");
    exit(1);
  }
}

$gib = 1073741824;
$register = ['measured'=>[
  'normal'=>['peak_rss_mb'=>9372],
  'large'=>['peak_rss_mb'=>14369],
]];
$lowRam = ['mem_total'=>24 * $gib];
$normalRam = ['mem_total'=>94 * $gib];
$unknown = ['mem_total'=>null];

$missingLow = coreui_generator_engine_status($lowRam, null, 0, $register);
engine_test(!$missingLow['ready'] && !$missingLow['hardware_eligible']
  && $missingLow['state'] === 'hardware_insufficient'
  && $missingLow['message'] ===
    'Quantum Gen Engine nicht installiert da die Mindesthardwareanforderungen nicht erfüllt werden',
  'missing service and insufficient RAM are correctly distinguished');
engine_test($missingLow['minimum_ram_gib'] === 31
  && $missingLow['normal_peak_rss_mb'] === 9372 && $missingLow['large_peak_rss_mb'] === 14369,
  'uses the existing image engine measured RAM and auto-install threshold');

$missingLarge = coreui_generator_engine_status($normalRam, null, 0, $register);
engine_test(!$missingLarge['ready'] && $missingLarge['hardware_eligible']
  && $missingLarge['state'] === 'unavailable'
  && !str_contains($missingLarge['message'], 'Mindesthardwareanforderungen'),
  'missing engine with sufficient RAM must not falsely claim hardware is inadequate');

$loading = coreui_generator_engine_status($normalRam,
  ['ok'=>true, 'ready'=>false, 'loading'=>true], 200, $register);
engine_test(!$loading['ready'] && $loading['installed']
  && $loading['state'] === 'loading'
  && $loading['message'] === 'Quantum Gen Engine wird vorbereitet.',
  'ongoing weight download is not reported as missing installation');

$operational = coreui_generator_engine_status($lowRam,
  ['ok'=>true, 'ready'=>true, 'loading'=>false], 200, $register);
engine_test($operational['ready'] && $operational['installed']
  && $operational['state'] === 'ready' && $operational['message'] === '',
  'actually running Engine remains usable after an explicit hardware override');

$unhealthy = coreui_generator_engine_status($normalRam,
  ['ok'=>true, 'ready'=>false, 'loading'=>false], 200, $register);
engine_test(!$unhealthy['ready'] && $unhealthy['state'] === 'not_ready',
  'reachable but failed Engine is not ready');
$notKnown = coreui_generator_engine_status($unknown, null, 0, $register);
engine_test(!$notKnown['ready'] && $notKnown['hardware_eligible'] === null
  && $notKnown['state'] === 'unknown', 'unknown RAM does not fabricate a minimum hardware failure');

$public = coreui_generator_engine_public($missingLow);
engine_test(array_keys($public) === ['ready','state','message']
  && !array_key_exists('minimum_ram_gib', $public),
  'Core menu gets only the availability state, not private machine specifications');

$install = (string)file_get_contents(dirname(__DIR__) . '/scripts/install.sh');
engine_test(str_contains($install, 'MEM_TOTAL_GB >= 31'),
  'image engine RAM threshold agrees with existing auto-installer');
$userSettings = (string)file_get_contents(dirname(__DIR__) . '/api/user_settings.php');
engine_test(substr_count($userSettings, "error'=>'generator_engine_unavailable'") === 2,
  'both the Core menu and direct settings write enforce availability on the server');

echo "CoreAI Quantum Gen Engine: minimum hardware, ready/loading/offline, privacy and guards OK\n";
