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
// Host download consent must never unlock a second user.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "PARTIAL: PDO SQLite unavailable; per-account acceptance checks not executed.\n";
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
$notAccepted = coreui_pgen_status($health, $hardware, $pdo, 1);
pgen_test($notAccepted['active'] === COREUI_PGEN_STANDARD
  && $notAccepted['models'][0]['ready'] && !$notAccepted['models'][1]['ready']
  && !$notAccepted['license_accepted'], '9B locked before this user accepts');
pgen_test(pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $notAccepted, 1),
  'license_accept_required'), 'cannot activate 9B without own consent');
pgen_test(pgen_throws(fn() => coreui_pgen_save('anything', $notAccepted, 1),
  'pgen_invalid_model'), 'unlisted model IDs rejected');

// Historical operator-wide receipt can never be inherited by an account.
coreui_generator_license_accept(1, $sha);
pgen_test(!coreui_generator_license_user_accepted($pdo, 1)
  && !coreui_generator_license_user_accepted($pdo, 2),
  'server-wide receipt does not grant any user access');
pgen_test(pgen_throws(fn() => coreui_generator_license_user_accept($pdo, 1, str_repeat('0',64)),
  'generator_license_version_changed'), 'wrong license version is rejected');
coreui_generator_license_user_accept($pdo, 1, $sha);
pgen_test(coreui_generator_license_user_accepted($pdo, 1)
  && !coreui_generator_license_user_accepted($pdo, 2),
  'one users consent never unlocks a second account');
$after = coreui_pgen_status($health, $hardware, $pdo, 1);
$other = coreui_pgen_status($health, $hardware, $pdo, 2);
pgen_test($after['models'][1]['ready'] && $after['license_accepted']
  && !$other['models'][1]['ready'] && !$other['license_accepted'],
  'pGen status and controls are individually locked');
coreui_pgen_save(COREUI_PGEN_QUALITY, $after, 1);
pgen_test(coreui_pgen_selected() === COREUI_PGEN_QUALITY,
  'operator may select installed 9B for server, without granting others consent');
pgen_test(pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $other, 2),
  'license_accept_required'), 'second account cannot activate 9B without consent');
$changed = json_decode((string)file_get_contents(coreui_pgen_file()), true);
pgen_test($changed['model'] === COREUI_PGEN_QUALITY && $changed['changed_by'] === 1,
  'pGen active profile is audited');

// Second user explicitly accepts the same license independently.
coreui_generator_license_user_accept($pdo, 2, $sha);
pgen_test(coreui_generator_license_user_accepted($pdo, 2), 'second user acceptance persisted');
$pdo->exec("UPDATE stu_coreui_pgen_user_licenses SET license_sha256='"
  . str_repeat('0',64) . "' WHERE user_id=2");
pgen_test(!coreui_generator_license_user_accepted($pdo, 2),
  'stale/changed license SHA invalidates consent');
$pdo->exec('DELETE FROM stu_users WHERE id=1');
pgen_test(!coreui_generator_license_user_accepted($pdo, 1),
  'user deletion deletes own consent');

$offline = coreui_pgen_status(['ok'=>false,'ready'=>false,'pgen'=>[]], $hardware, $pdo, 2);
pgen_test(!$offline['models'][1]['ready'] && !$offline['engine_ready']
  && pgen_throws(fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $offline, 2),
    'pgen_engine_unavailable'), 'offline engine blocks pGen selection');
$small = coreui_pgen_status($health, $low, $pdo, 2);
pgen_test(!$small['can_install_9b'] && pgen_throws(
  fn() => coreui_pgen_save(COREUI_PGEN_QUALITY, $small, 2),
  'pgen_hardware_insufficient'), 'hardware threshold applies independently');
coreui_pgen_save(COREUI_PGEN_STANDARD, $after, 1);
pgen_test(coreui_pgen_selected() === COREUI_PGEN_STANDARD, '4B can be restored');
foreach (['pgen-profile.json', 'generator-license.json'] as $name) @unlink($dir . '/' . $name);
@rmdir($dir);
echo "CoreAI pGen: only 4B/9B, per-user licenses, no cross-account consent, pGen, engine and hardware OK\n";
