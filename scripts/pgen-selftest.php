<?php
declare(strict_types=1);
/** Offline pGen Standard/Magic selection, hardware and per-user license checks. */
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

$ids = [COREUI_PGEN_4B_STANDARD, COREUI_PGEN_4B_MAGIC,
  COREUI_PGEN_9B_STANDARD, COREUI_PGEN_9B_MAGIC];
pgen_test(coreui_pgen_selected() === COREUI_PGEN_4B_STANDARD, 'default is explicit Standard 4B');
pgen_test(count(array_unique($ids)) === 4 && count(array_filter($ids, 'coreui_pgen_valid')) === 4
  && !coreui_pgen_valid(COREUI_PGEN_STANDARD) && !coreui_pgen_valid(COREUI_PGEN_QUALITY)
  && !coreui_pgen_valid('arbitrary'), 'exact four profile selectors; no raw encoder or base IDs');
pgen_test(coreui_pgen_model(COREUI_PGEN_4B_MAGIC) === COREUI_PGEN_STANDARD
  && coreui_pgen_model(COREUI_PGEN_9B_STANDARD) === COREUI_PGEN_QUALITY
  && coreui_pgen_mode(COREUI_PGEN_9B_MAGIC) === 'magic'
  && coreui_pgen_mode(COREUI_PGEN_4B_STANDARD) === 'standard',
  'the base model and variant are resolved independently');

$g = 1073741824;
$hardware = ['mem_total'=>94*$g, 'mem_available'=>80*$g, 'disk_free'=>200*$g];
$low = ['mem_total'=>24*$g, 'mem_available'=>12*$g, 'disk_free'=>200*$g];
pgen_test(coreui_pgen_hardware($hardware)['ok'] && !coreui_pgen_hardware($low)['ok']
  && !coreui_pgen_hardware(['mem_total'=>94*$g, 'mem_available'=>80*$g, 'disk_free'=>10*$g])['ok'],
  'benchmark-based hardware requirements remain intact');

$health = ['ok'=>true, 'ready'=>true, 'pgen'=>[
  COREUI_PGEN_STANDARD=>['ready'=>true, 'installed'=>true, 'loading'=>false],
  COREUI_PGEN_QUALITY=>['ready'=>true, 'installed'=>true, 'loading'=>false],
], 'ponpoke'=>['loading'=>false,
  COREUI_PGEN_STANDARD=>['ready'=>true],
  COREUI_PGEN_QUALITY=>['ready'=>true]]];
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "PARTIAL: PDO SQLite unavailable; account acceptance checks not executed.\n";
  exit(0);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY)');
$pdo->exec("INSERT INTO stu_users VALUES (1), (2)");
$pdo->exec('CREATE TABLE stu_coreui_pgen_user_licenses (
  user_id INTEGER PRIMARY KEY REFERENCES stu_users(id) ON DELETE CASCADE,
  license_sha256 CHAR(64) NOT NULL, accepted_at DATETIME NOT NULL)');
$sha = coreui_generator_license_hash();
$noLicense = coreui_pgen_status($health, $hardware, $pdo, 1);
pgen_test($noLicense['active'] === COREUI_PGEN_4B_STANDARD
  && array_column($noLicense['models'], 'id') === $ids
  && $noLicense['models'][0]['ready']
  && !$noLicense['models'][1]['ready']
  && !$noLicense['models'][2]['ready']
  && !$noLicense['models'][3]['ready']
  && !$noLicense['license_accepted'],
  'four profile options visible, licensed options blocked before consent');
pgen_test($noLicense['models'][0]['license'] === 'Apache-2.0'
  && $noLicense['models'][0]['mode'] === 'standard'
  && $noLicense['models'][1]['mode'] === 'magic'
  && $noLicense['models'][1]['base_model'] === COREUI_PGEN_STANDARD
  && !array_key_exists('encoder', $noLicense['models'][1])
  && !array_key_exists('encoder_ready', $noLicense['models'][1]),
  'selection never exposes separate text encoder models');
foreach (array_slice($ids, 1) as $id) {
  pgen_test(pgen_throws(fn() => coreui_pgen_save($id, $noLicense, 1),
    'license_accept_required'), 'license required: ' . $id);
}
pgen_test(pgen_throws(fn() => coreui_pgen_save('ponpoke-4b-q8', $noLicense, 1),
  'pgen_invalid_model'), 'raw encoder selection is refused');

// Old global receipt never authorizes a different account.
coreui_generator_license_accept(1, $sha);
pgen_test(!coreui_generator_license_user_accepted($pdo, 1)
  && !coreui_generator_license_user_accepted($pdo, 2),
  'server receipt does not grant account permission');
pgen_test(pgen_throws(fn() => coreui_generator_license_user_accept($pdo, 1,
  str_repeat('0',64)), 'generator_license_version_changed'),
  'forged license version rejected');
coreui_generator_license_user_accept($pdo, 1, $sha);
$after = coreui_pgen_status($health, $hardware, $pdo, 1);
$other = coreui_pgen_status($health, $hardware, $pdo, 2);
pgen_test(count(array_filter($after['models'], fn($m) => $m['ready'])) === 4
  && count(array_filter($other['models'], fn($m) => $m['ready'])) === 1,
  'accepted user sees four ready profiles, other user only Standard 4B');

// A missing Magic asset never silently selects the original encoder.
$missing = $health;
$missing['ponpoke'][COREUI_PGEN_STANDARD]['ready'] = false;
$missingMagic = coreui_pgen_status($missing, $hardware, $pdo, 1);
pgen_test(!$missingMagic['models'][1]['ready']
  && $missingMagic['models'][1]['prepare_required']
  && $missingMagic['models'][0]['ready']
  && pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_4B_MAGIC, $missingMagic, 1),
    'pgen_magic_unavailable'), 'Magic requires verified prepared assets');

foreach ($ids as $id) {
  coreui_pgen_save($id, $after, 1);
  pgen_test(coreui_pgen_selected() === $id
    && json_decode((string)file_get_contents(coreui_pgen_file()), true)['changed_by'] === 1,
    'audited persisted profile: ' . $id);
}
pgen_test(pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_9B_MAGIC, $other, 2),
  'license_accept_required'), 'another user cannot activate Magic through an owner receipt');

// Legacy 4B and 9B installs map to Standard, not automatic Magic.
file_put_contents(coreui_pgen_file(), json_encode(['model'=>COREUI_PGEN_STANDARD]));
pgen_test(coreui_pgen_selected() === COREUI_PGEN_4B_STANDARD, 'old 4B migrates to Standard');
file_put_contents(coreui_pgen_file(), json_encode(['model'=>COREUI_PGEN_QUALITY]));
pgen_test(coreui_pgen_selected() === COREUI_PGEN_9B_STANDARD, 'old 9B migrates to Standard');
file_put_contents(coreui_pgen_file(), json_encode(['model'=>'ponpoke-q8']));
pgen_test(coreui_pgen_selected() === COREUI_PGEN_4B_STANDARD,
  'forged or unknown cache values fail closed');

// Hardware and missing engine still reject all relevant profiles.
$offline = coreui_pgen_status(['ok'=>false,'ready'=>false,'pgen'=>[]], $hardware, $pdo, 1);
pgen_test(!$offline['models'][0]['ready'] && !$offline['engine_ready']
  && pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_4B_STANDARD, $offline, 1),
    'pgen_engine_unavailable'), 'offline engine blocks all variants');
$small = coreui_pgen_status($health, $low, $pdo, 1);
pgen_test(!$small['can_install_9b'] && pgen_throws(
  fn() => coreui_pgen_save(COREUI_PGEN_9B_MAGIC, $small, 1),
  'pgen_hardware_insufficient'), 'both 9B choices obey hardware limit');

// Deleting or invalidating consent revokes Magic and 9B.
coreui_generator_license_user_accept($pdo, 2, $sha);
$pdo->exec("UPDATE stu_coreui_pgen_user_licenses SET license_sha256='"
  . str_repeat('0',64) . "' WHERE user_id=2");
pgen_test(!coreui_generator_license_user_accepted($pdo, 2),
  'changed license SHA invalidates prior consent');
$pdo->exec('DELETE FROM stu_users WHERE id=1');
pgen_test(!coreui_generator_license_user_accepted($pdo, 1),
  'deleting an account removes its license consent');

foreach (['pgen-profile.json', 'generator-license.json'] as $name) @unlink($dir . '/' . $name);
@rmdir($dir);
echo "CoreAI pGen: Standard/Magic x 4B/9B, explicit encoder routing, per-user consent OK\n";
