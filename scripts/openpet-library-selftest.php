<?php
declare(strict_types=1);
/** Offline contract for owner-approved OpenPet public presets. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/pets_store.php';

function library_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "OpenPet library FAIL: {$message}\n"); exit(1); }
}

$base = sys_get_temp_dir() . '/coreai_openpet_test_' . bin2hex(random_bytes(6));
putenv('COREUI_PET_LIBRARY_DIR=' . $base);
@mkdir($base, 0770, true);
$rootTest = static function () use ($root, $base): void {
  library_test(coreui_openpet_library_catalog() === [] && coreui_openpet_library_view() === [],
    'empty public library does not include any unchecked gallery sprite');
  library_test(coreui_openpet_library_id('Original-Pet') === 'op-original-pet'
    && coreui_pet_choice_normalise('op-original-pet') === 'none',
    'unapproved pet identifiers are not accepted in account settings');
  foreach (['../evil','bad_path','a','',str_repeat('x', 55)] as $bad) {
    try { coreui_openpet_library_id($bad); library_test(false,'invalid ID accepted'); }
    catch (InvalidArgumentException $e) { library_test($e->getMessage() === 'invalid_pet_id','invalid ID rejected'); }
  }
  $rights = [
    'license'=>'CC-BY-4.0', 'rights_holder'=>'Starlight Unit Studios',
    'source_url'=>'https://example.org/our-original-pet',
    'evidence_url'=>'https://example.org/our-original-pet-license',
    'rights_confirmed'=>'yes',
  ];
  foreach ([
    ['license'=>'none'], ['rights_holder'=>''], ['evidence_url'=>'http://example.org/permissions'],
    ['rights_confirmed'=>'no'],
  ] as $bad) {
    try { coreui_openpet_library_license(array_replace($rights,$bad)); library_test(false,'bad license accepted'); }
    catch (InvalidArgumentException $e) { library_test(true,'missing permission refused'); }
  }
  library_test(coreui_openpet_library_license($rights)['license'] === 'CC-BY-4.0',
    'verified license metadata accepted');
  $zip = $base . '/pet.openpet.zip';
  $z = new ZipArchive();
  library_test($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,'test zip created');
  $manifest = ['id'=>'original-pet', 'displayName'=>'Original Pet',
    'description'=>'Own reference artwork, signed and approved for redistribution.',
    'spritesheetPath'=>'spritesheet.webp'];
  $z->addFromString('pet.json', json_encode($manifest));
  $z->addFile($root . '/images/pets/mara.webp', 'spritesheet.webp');
  library_test($z->close(), 'test ZIP finalized');
  try { coreui_openpet_library_import($zip, filesize($zip), array_replace($rights, ['license'=>'unverified'])); library_test(false,'unverified upload imported'); }
  catch (InvalidArgumentException $e) { library_test($e->getMessage() === 'unverified_license', 'unverified gallery pet rejected'); }
  $pet = coreui_openpet_library_import($zip, filesize($zip), $rights);
  library_test($pet['id'] === 'op-original-pet' && $pet['sprite_version'] === 2
    && $pet['frames'] === 8 && $pet['library'] === true, 'approved OpenPet atlas published with v2 states');
  library_test(coreui_pet_choice_normalise('op-original-pet') === 'op-original-pet'
    && isset(coreui_openpet_library_catalog()['op-original-pet']),
    'licensed pet can be selected by any account');
  $path = coreui_openpet_library_asset($pet['id']);
  library_test(is_string($path) && hash_file('sha256', $path) === hash_file('sha256', $root . '/images/pets/mara.webp'),
    'private generated library asset is the original unmodified sprite');
  $bad = $base . '/bad.openpet.zip';
  $z = new ZipArchive(); $z->open($bad, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  $z->addFromString('pet.json', json_encode($manifest));
  $z->addFromString('spritesheet.webp', 'malformed');
  $z->close();
  try { coreui_openpet_library_import($bad, filesize($bad), $rights); library_test(false,'invalid sprite imported'); }
  catch (InvalidArgumentException $e) { library_test(true, 'corrupt atlas rejected'); }
  library_test(coreui_openpet_library_remove('op-original-pet'), 'owner can unpublish a shared pet');
  library_test(coreui_pet_choice_normalise('op-original-pet') === 'none'
    && coreui_openpet_library_view() === [],
    'unpublished pets are no longer offered to accounts');
  library_test(coreui_openpet_library_asset('op-original-pet') === null,
    'unpublished sprite can no longer be fetched from the authenticated API');
  library_test(coreui_pet_default() === 'mara'
    && coreui_pet_builtin_view()[0]['id'] === 'mara',
    'the original Mara default and built-in pets remain untouched');
  $endpoint = file_get_contents($root . '/api/pets.php');
  library_test(str_contains($endpoint, "if (stu_permission_level_for_user(\$pdo, \$uid) !== COREUI_ROLE_OWNER)")
    && str_contains($endpoint, "stu_require_csrf();")
    && str_contains($endpoint, "action=library_sprite") === false
    && str_contains($endpoint, "if (\$method === 'GET' && \$action === 'library_sprite')"),
    'publishing/removing shared pets is owner-only, with authenticated sprite serving');
};
try { $rootTest(); }
finally {
  foreach (glob($base . '/*') ?: [] as $path) if (is_file($path)) @unlink($path);
  @rmdir($base);
}
echo "CoreAI OpenPet library: safe import, license gate, private asset, selection and unpublish OK\n";
