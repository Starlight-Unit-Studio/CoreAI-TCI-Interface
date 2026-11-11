<?php
declare(strict_types=1);
// CoreAI 0.7.1: Settings > Pets, offline.
//   php scripts/pets-selftest.php
// Pure parts: the nine built-in CoreAI pets and their sheets, the stored choice, sprite sheets read
// from their own bytes (PNG with alpha or tRNS, WebP lossless, lossy with and without alpha) and the
// upload rules; Mara is preselected. With COREUI_SELFTEST_DB=1 on MariaDB (migration 034): upload, the
// own list, other accounts never see a pet, choosing, the pet for the chat, deleting with the file
// (Mara takes over), the limit of 12.
// Then the wiring of the API, the page, the chat work line, the account deletion and the preflights.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/pets_store.php';

function pet_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Pets FAIL: {$why}\n"); exit(1); }
}
function pet_code(callable $fn): string {
  try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

// Two frames of 16 px each (32 x 16), made with Pillow.
$fixtures = [
  'png' => 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAYAAAB3AH1ZAAAALElEQVR4nGNgGAUjHTBSbMJ/hv9UcMcoGDjA+J+BsjhkpEY6GgWjYBQMJAAApC8EAeaW4ZsAAAAASUVORK5CYII=',
  'pngrgb' => 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAIAAAD4YuoOAAAAI0lEQVR4nGNgGAUDDRhJ1vGfBq4Y2YCR1CAlPdJGwSgY9AAAaoMCAlyflXoAAAAASUVORK5CYII=',
  'pngtrns' => 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAQAQMAAABNzu8aAAAABlBMVEUAAAD/AAAb/40iAAAAAXRSTlMAQObYZgAAAAxJREFUeJxjYKAuAAAAUAAB8zD3KgAAAABJRU5ErkJggg==',
  'lossless' => 'UklGRjIAAABXRUJQVlA4TCYAAAAvH8ADEBcgEEjaH3qN+Y/5D2zBUAAzV1JJMb3YCkT0PzlRqB0+AQ==',
  'lossyalpha' => 'UklGRp4AAABXRUJQVlA4WAoAAAAQAAAAHwAADwAAQUxQSBMAAAABDzD/ERFi/iP6HwAADAADAAAAAFZQOCBkAAAAUAQAnQEqIAAQAD61VqFLJyUjIbAIAOAWiWwAuzlAs4GCAev/doH6ABQx4AD++YFn//sSTHyZAZY4RYf8E49U9DK9/k98O6GV7+1z//9m8Vgv7F1af79i05YvsJCDlj8I/YAAAA==',
  'lossy' => 'UklGRmwAAABXRUJQVlA4IGAAAAAQBACdASogABAAPrUwqVCnIiSjGADgFolAF2KgBz/92gBKes2zOTuEAAD+/sy+cjfLQyZyAikm4xvo1f8NOh/4Q+th3Gf4Q4Fh/8fJKcrGZTPyy2mUgPp8ltQfkYAAAAA=',
];
$bytes = array_map('base64_decode', $fixtures);

// --- Pure ---------------------------------------------------------------------------------------------------
$catalog = coreui_pet_catalog();
pet_test(array_keys($catalog) === ['mara', 'nova', 'komet', 'orbi', 'luna', 'quanti', 'glimm', 'raki', 'sputni'], 'nine built-in CoreAI pets');
foreach ($catalog as $id => [$name, $description]) {
  $file = $root . '/images/pets/' . $id . ($id === 'mara' ? '.webp' : '.png');
  pet_test(is_file($file), "sprite sheet of $id");
  $expected = $id === 'mara'
    ? ['mime' => 'image/webp', 'frames' => 8, 'frame_px' => 192, 'sprite_version' => 2]
    : ['mime' => 'image/png', 'frames' => 6, 'frame_px' => 32, 'sprite_version' => 0];
  pet_test(coreui_pet_sheet_check((string)file_get_contents($file)) === $expected, "$id: validated sheet");
  pet_test($name !== '' && mb_strlen($description) > 10 && !preg_match('~codex|chatgpt|openai~i', $name . $description), "$id: own name and description");
}
pet_test(hash_file('sha256', $root . '/images/pets/mara.webp') === '0b86744a172f2515c030b28a3f989ff824040b3ceb8e2221014c36aed0c5efd0',
  'Mara sprite atlas is the unchanged original upload');
pet_test(coreui_pet_builtin_view()[0]['id'] === 'mara' && coreui_pet_builtin_view()[0]['url'] === './images/pets/mara.webp'
  && coreui_pet_builtin_view()[0]['sprite_version'] === 2, 'Mara is the first builtin and uses the v2 renderer');
foreach (['codex', 'dewey', 'fireball', 'hoots', 'rocky', 'seedy', 'stacky', 'bsod', 'null'] as $foreign) {
  pet_test(!isset($catalog[$foreign]), 'no pet of the reference product: ' . $foreign);
}
foreach (['mara' => 'mara', 'nova' => 'nova', ' Komet ' => 'komet', 'u12' => 'u12', 'none' => 'none', 'u0' => 'none', 'gibtsnicht' => 'none', '' => 'none'] as $in => $out) {
  pet_test(coreui_pet_choice_normalise($in) === $out, "choice '$in'");
}
pet_test(coreui_personalization_defaults()['pet'] === 'mara' && coreui_pet_default() === 'mara', 'Mara is preselected');
pet_test(coreui_personalization_normalise(['pet' => 'u7'])['pet'] === 'u7' && coreui_personalization_normalise(['pet' => '../x'])['pet'] === 'none'
  && in_array('pet', coreui_personalization_client_keys(), true), 'the choice is kept with the personalization');
$expect = ['png' => ['image/png', true], 'pngrgb' => ['image/png', false], 'pngtrns' => ['image/png', true],
  'lossless' => ['image/webp', true], 'lossyalpha' => ['image/webp', true], 'lossy' => ['image/webp', false]];
foreach ($expect as $name => [$mime, $alpha]) {
  $info = coreui_pet_sheet_info($bytes[$name]);
  pet_test($info === ['mime' => $mime, 'width' => 32, 'height' => 16, 'alpha' => $alpha], "sheet info $name: " . json_encode($info));
  pet_test(getimagesizefromstring($bytes[$name])[0] === 32, "getimagesize agrees for $name");
}
pet_test(coreui_pet_sheet_check($bytes['lossless']) === ['mime' => 'image/webp', 'frames' => 2, 'frame_px' => 16, 'sprite_version' => 0], 'two frames of 16 px');
pet_test(pet_code(fn() => coreui_pet_sheet_check($bytes['lossy'])) === 'not_transparent'
  && pet_code(fn() => coreui_pet_sheet_check($bytes['pngrgb'])) === 'not_transparent', 'a sheet without transparency is refused');
pet_test(pet_code(fn() => coreui_pet_sheet_check('GIF89a' . str_repeat("\0", 40))) === 'invalid_file_type'
  && pet_code(fn() => coreui_pet_sheet_check('')) === 'empty_file'
  && pet_code(fn() => coreui_pet_sheet_check(str_repeat('a', COREUI_PET_MAX_BYTES + 1))) === 'file_too_large', 'type, empty and size');
$png = static function (int $w, int $h): string {
  $ihdr = pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";
  return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
};
pet_test(pet_code(fn() => coreui_pet_sheet_check($png(100, 32))) === 'invalid_sheet_size'
  && pet_code(fn() => coreui_pet_sheet_check($png(16, 8))) === 'invalid_sheet_size'
  && pet_code(fn() => coreui_pet_sheet_check($png(1024, 1024))) === 'invalid_sheet_size'
  && pet_code(fn() => coreui_pet_sheet_check($png(32, 32))) === 'invalid_frame_count'
  && pet_code(fn() => coreui_pet_sheet_check($png(25 * 16, 16))) === 'invalid_frame_count'
  && coreui_pet_sheet_check($png(24 * 16, 16))['frames'] === 24, 'square frames in one row, 2 to 24, 16 to 512 px');

pet_test(coreui_pet_sheet_check($png(1536, 1872)) === ['mime' => 'image/png', 'frames' => 8, 'frame_px' => 192, 'sprite_version' => 1], '9-row atlas');
pet_test(coreui_pet_sheet_check($png(1536, 2288)) === ['mime' => 'image/png', 'frames' => 8, 'frame_px' => 192, 'sprite_version' => 2], '11-row atlas');
pet_test(pet_code(fn() => coreui_pet_sheet_check($png(1536, 1871))) === 'invalid_sheet_size', 'invalid atlas rejected');
// --- MariaDB (COREUI_SELFTEST_DB=1, never the live database) --------------------------------------------------
$dbChecked = false;
if (getenv('COREUI_SELFTEST_DB') === '1') {
  require_once $root . '/api/config.php';
  require_once $root . '/api/db.php';
  $my = stu_pdo();
  pet_test(coreui_pet_schema_ready($my), 'MariaDB: migration 034 applied');
  $tag = bin2hex(random_bytes(4));
  $ins = $my->prepare("INSERT INTO stu_users (username, password_hash, permission_level, is_guest) VALUES (?, '', 4, 0)");
  $ins->execute(["pet-a-$tag@example.test"]);
  $ua = (int)$my->lastInsertId();
  $ins->execute(["pet-b-$tag@example.test"]);
  $ub = (int)$my->lastInsertId();
  try {
    pet_test(coreui_pet_current($my, $ua)['id'] === 'mara' && coreui_pet_choice($my, $ua) === 'mara', 'MariaDB: Mara is preselected');
    pet_test(coreui_pet_choose($my, $ua, 'none') === 'none' && coreui_pet_current($my, $ua) === null, 'MariaDB: "Pet nicht anzeigen"');
    pet_test(coreui_pet_choose($my, $ua, 'luna') === 'luna' && coreui_pet_current($my, $ua)['url'] === './images/pets/luna.png', 'MariaDB: a built-in pet');
    pet_test(pet_code(fn() => coreui_pet_add($my, $ua, '  ', '', $bytes['png'])) === 'name_required'
      && pet_code(fn() => coreui_pet_add($my, $ua, 'Opak', '', $bytes['pngrgb'])) === 'not_transparent', 'MariaDB: name and transparency');
    $pet = coreui_pet_add($my, $ua, "  Mein\nTestpet  ", str_repeat('b', 600), $bytes['lossless']);
    $id = (int)substr($pet['id'], 1);
    $row = coreui_pet_own_row($my, $ua, $id);
    $file = coreui_pet_dir() . '/' . $row['stored_name'];
    pet_test($pet['name'] === 'Mein Testpet' && mb_strlen($pet['description']) === 500 && $pet['frames'] === 2 && $pet['frame_px'] === 16
      && $pet['url'] === './api/pets.php?action=sprite&id=' . $id && is_file($file) && file_get_contents($file) === $bytes['lossless']
      && preg_match('~^pet_[a-f0-9]{32}\.webp$~', $row['stored_name']) === 1, 'MariaDB: an own pet with its file');
    pet_test(coreui_pet_own_row($my, $ub, $id) === null && coreui_pet_own($my, $ub) === []
      && pet_code(fn() => coreui_pet_choose($my, $ub, 'u' . $id)) === 'pet_not_found', 'MariaDB: other accounts never see it');
    pet_test(coreui_pet_choose($my, $ua, 'u' . $id) === 'u' . $id && coreui_pet_current($my, $ua)['name'] === 'Mein Testpet', 'MariaDB: the own pet in the chat');
    pet_test(coreui_pet_files($my, $ua) === ['var/console_media/' . $row['stored_name']], 'MariaDB: files for the account deletion');
    pet_test(coreui_pet_delete($my, $ua, $id) && !is_file($file) && coreui_pet_choice($my, $ua) === 'mara' && coreui_pet_current($my, $ua)['id'] === 'mara'
      && !coreui_pet_delete($my, $ua, $id), 'MariaDB: deleted with its file, Mara takes over');
    $my->prepare('UPDATE stu_user_personalization SET preferences_json = JSON_SET(preferences_json, \'$.pet\', \'u999999\') WHERE user_id = ?')->execute([$ua]);
    pet_test(coreui_pet_current($my, $ua)['id'] === 'mara', 'MariaDB: a missing own pet shows Mara');
    for ($i = 0; $i < COREUI_PET_MAX_OWN; $i++) coreui_pet_add($my, $ub, 'Pet ' . $i, '', $bytes['png']);
    pet_test(pet_code(fn() => coreui_pet_add($my, $ub, 'Eins zu viel', '', $bytes['png'])) === 'too_many_pets' && count(coreui_pet_own($my, $ub)) === 12, 'MariaDB: at most 12 own pets');
    $dbChecked = true;
  } finally {
    foreach ([$ua, $ub] as $u) {
      foreach (coreui_pet_files($my, $u) as $path) @unlink($root . '/' . $path);
      $my->prepare('DELETE FROM stu_user_pets WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_user_personalization WHERE user_id = ?')->execute([$u]);
      $my->prepare('DELETE FROM stu_users WHERE id = ?')->execute([$u]);
    }
  }
}

// --- Wiring ------------------------------------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$mig = $read('database/migrations/034_user_pets.sql');
pet_test(str_contains($mig, 'CREATE TABLE IF NOT EXISTS stu_user_pets') && str_contains($mig, 'ON DELETE CASCADE') && str_contains($mig, "('034_user_pets')"), 'migration 034');
$api = $read('api/pets.php');
pet_test(str_contains($api, "if (\$method === 'GET' && \$action === 'sprite') {") && str_contains($api, 'stu_require_csrf();')
  && str_contains($api, "if (\$act === 'select') {") && str_contains($api, "if (\$act === 'delete') {"), 'pets API');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $template) {
  pet_test(str_contains($read($template), '|memory_summary|pets|'), "nginx allows pets: $template");
}
pet_test(str_contains($read('api/account_delete.php'), "SELECT CONCAT('var/console_media/', stored_name) FROM stu_user_pets WHERE user_id = ?")
  && str_contains($read('api/account_export.php'), "'pets' => coreui_pet_own(\$pdo, (int)\$uid),"), 'account deletion and export');
$html = $read('settings.html');
$at = strpos($html, 'id="view-pets"');
$view = substr($html, $at, strpos($html, '</section>', strpos($html, 'id="petDeleteConfirm"')) - $at);
pet_test(!str_contains($view, 'cfg-view-planned') && !str_contains($html, 'id="tab-pets" data-view="pets" aria-controls="view-pets" aria-selected="false" tabindex="-1" title="In Vorbereitung"'), 'Pets is a real page');
foreach (['petUploadOpen', 'petStatus', 'petCreate', 'petRows', 'petNone', 'petUploadDialog', 'petUploadForm', 'petName', 'petDescription', 'petFile',
    'petFileName', 'petPreview', 'petUploadError', 'petUploadCancel', 'petUploadSubmit', 'petDeleteConfirm', 'petDeleteOk', 'petDeleteCancel'] as $id) {
  pet_test(str_contains($view, 'id="' . $id . '"'), "settings.html: $id");
}
pet_test(str_contains($view, '<h2>Pet auswählen</h2>') && str_contains($view, '<strong>Pet mit Mara erstellen</strong>') && str_contains($view, '<strong>Pet nicht anzeigen</strong>')
  && str_contains($view, 'placeholder="Benenne dein Pet"') && str_contains($view, 'Bis zu 500 Zeichen'), 'page after the reference');
pet_test((bool)preg_match('~<script src="\./js/coreui-pets\.js\?v=\d{25}"></script>\s*<script src="\./js/coreui-pets-settings\.js\?v=\d{25}"></script>~', $html), 'settings scripts');
pet_test((bool)preg_match('~<script src="\./js/coreui-pets\.js\?v=\d{25}"></script>\s*<script src="\./js/coreui-work-events\.js~', $read('app.html')), 'chat loads the pets');
$work = $read('js/coreui-work-events.js');
pet_test(!str_contains($work, 'petLead()') && str_contains($read('js/coreui-pet-dock.js'), 'coreai-pet-dock'), 'pet lives in persistent dock');
pet_test(str_contains($read('app.html'), './js/coreui-pet-dock.js?v=1') && str_contains($read('app.html'), './css/coreui-pet-dock.css?v=1'), 'chat loads dock');
pet_test(str_contains($read('js/console-app.js'), 'window.CoreAIPets.loadCurrent();'), 'the chat loads the chosen pet');
pet_test(str_contains($read('scripts/build-pet-sprites.py'), "PETS = {'nova': nova, 'komet': komet,"), 'sprites come from the generator');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $pf) {
  $text = $read($pf);
  pet_test(str_contains($text, 'database/migrations/034_user_pets.sql') && str_contains($text, 'api/pets_store.php') && str_contains($text, 'api/pets.php')
    && str_contains($text, 'js/coreui-pets.js') && str_contains($text, 'scripts/pets-selftest.php') && str_contains($text, 'scripts/pets-ui-selftest.cjs'), "$pf lists and runs the pets");
}

echo "CoreAI Pets: eigene Pets, Sprite-Sheets (PNG, WebP), Auswahl, Verdrahtung OK"
  . ($dbChecked ? ', MariaDB (Hochladen, Auswahl, Chat, Loeschen, Grenze) OK' : ', MariaDB-Teil uebersprungen (COREUI_SELFTEST_DB nicht gesetzt)') . "\n";
