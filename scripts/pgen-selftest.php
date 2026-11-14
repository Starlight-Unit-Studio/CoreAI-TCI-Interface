<?php
declare(strict_types=1);
/** Offline pGen selection, hardware and license checks. No live DB or worker. */
if (PHP_SAPI !== 'cli') exit(1);
$dir = sys_get_temp_dir() . '/coreai-pgen-' . getmypid();
@mkdir($dir, 0775, true);
define('STU_EMBER_CACHE_DIR', $dir);
require_once dirname(__DIR__) . '/api/pgen.php';

function pgen_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "pGen FAIL: $why\n"); exit(1); }
}
function pgen_throws(callable $fn, string $code): bool {
  try { $fn(); }
  catch (Throwable $e) { return $e->getMessage() === $code; }
  return false;
}

pgen_test(coreui_pgen_selected() === COREUI_PGEN_STANDARD, 'default remains the original 4B');
pgen_test(coreui_pgen_valid('flux2-klein-4b') && coreui_pgen_valid('flux2-klein-9b')
  && !coreui_pgen_valid('arbitrary'), 'exact two allowed values');

$g = 1073741824;
$hardware = ['mem_total'=>94*$g, 'mem_available'=>80*$g, 'disk_free'=>200*$g];
$low = ['mem_total'=>24*$g, 'mem_available'=>12*$g, 'disk_free'=>200*$g];
pgen_test(coreui_pgen_hardware($hardware)['ok'] && !coreui_pgen_hardware($low)['ok']
  && !coreui_pgen_hardware(['mem_total'=>94*$g, 'mem_available'=>80*$g, 'disk_free'=>10*$g])['ok'],
  'the actual image benchmark and existing 31GiB engine minimum protect 9B');
$health = ['ok'=>true, 'ready'=>true, 'pgen'=>[
  COREUI_PGEN_STANDARD=>['ready'=>true, 'installed'=>true, 'loading'=>false],
  COREUI_PGEN_QUALITY=>['ready'=>true, 'installed'=>true, 'loading'=>false]
]];
$notAccepted = coreui_pgen_status($health, $hardware);
pgen_test($notAccepted['active'] === COREUI_PGEN_STANDARD
  && $notAccepted['models'][0]['ready']
  && !$notAccepted['models'][1]['ready']
  && !$notAccepted['license_accepted'], '9B is license locked; 4B stays ready');
pgen_test(pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $notAccepted, 1),
  'license_accept_required'), 'cannot activate 9B before acceptance');
pgen_test(pgen_throws(fn() => coreui_pgen_save('anything', $notAccepted, 1),
  'pgen_invalid_model'), 'no unlisted model IDs');

coreui_generator_license_accept(1, coreui_generator_license_hash());
$after = coreui_pgen_status($health, $hardware);
pgen_test($after['models'][1]['ready'] && $after['license_accepted'], 'license acceptance unlocks 9B');
coreui_pgen_save(COREUI_PGEN_QUALITY, $after, 1);
pgen_test(coreui_pgen_selected() === COREUI_PGEN_QUALITY, '9B choice persists to runtime');
$changed = json_decode((string)file_get_contents(coreui_pgen_file()), true);
pgen_test($changed['model'] === COREUI_PGEN_QUALITY && $changed['changed_by'] === 1,
  'audit record is stored');

$offline = coreui_pgen_status(['ok'=>false, 'ready'=>false, 'pgen'=>[]], $hardware);
pgen_test(!$offline['models'][1]['ready'] && !$offline['engine_ready']
  && pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $offline, 1),
    'pgen_engine_unavailable'), 'no switching while image engine is down');
$small = coreui_pgen_status($health, $low);
pgen_test(!$small['can_install_9b'] && pgen_throws(
  fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $small, 1), 'pgen_hardware_insufficient'),
  'hardware threshold applies even with a stored 9B choice');
coreui_pgen_save(COREUI_PGEN_STANDARD, $after, 1);
pgen_test(coreui_pgen_selected() === COREUI_PGEN_STANDARD, '4B can be restored');
foreach (['pgen-profile.json', 'generator-license.json'] as $name) @unlink($dir . '/' . $name);
@rmdir($dir);
echo "CoreAI pGen: only 4B/9B, persistent selection, licensing, engine and hardware safeguards OK\n";
