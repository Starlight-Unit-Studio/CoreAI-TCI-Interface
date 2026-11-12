<?php
declare(strict_types=1);
// CoreAI 0.7.28: Settings > Speicher on the PHP side. Checked: the limit (Inhaber none, own value,
// own 0 = none, else the limit for all accounts), its normalising, room left, usage of images and
// files (uploads, Mara's images, Mara's files), the list views (Dateien: all, suggested, favorites,
// type, search, project folders; Bilder: Deine Kreationen, Uploads), favourites, deleting (file,
// entry, favourite; never another account's) and the wiring (endpoint, guests, CSRF, nginx,
// migration 039, upload and image limit, Admin Core). The database part runs in a transaction
// and is rolled back; the files it writes are removed.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/files_store.php';

function st_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Storage FAIL: {$why}\n"); exit(1); }
}
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);

// --- Pure parts ---------------------------------------------------------------------------------
st_test(coreui_files_limit_normalise('') === '' && coreui_files_limit_normalise('0') === '' && coreui_files_limit_normalise('-5') === ''
  && coreui_files_limit_normalise('abc') === '' && coreui_files_limit_normalise('20480') === '20480'
  && coreui_files_limit_normalise((string)(COREUI_USER_POLICY_STORAGE_MAX_MB + 1)) === '', 'the limit for all accounts: empty, 0 and nonsense mean none');
st_test(coreui_files_size_label(1610612736) === '1,5 GB' && coreui_files_size_label(1572864) === '1,5 MB' && coreui_files_size_label(1700) === '2 KB',
  'sizes for messages');
st_test(preg_match(COREUI_FILES_KEY_RE, 'm:' . str_repeat('a', 32)) === 1 && preg_match(COREUI_FILES_KEY_RE, 'a:' . str_repeat('0', 32)) === 1
  && preg_match(COREUI_FILES_KEY_RE, 'x:' . str_repeat('a', 32)) === 0 && preg_match(COREUI_FILES_KEY_RE, 'm:../etc') === 0, 'item ids');

// --- Wiring -------------------------------------------------------------------------------------
$api = $read('api/files.php');
st_test(strpos($api, "if (coreui_guest_is(\$pdo, \$uid)) stu_json(['ok' => false, 'error' => 'guests_not_allowed'], 403);") < strpos($api, "\$action === 'summary'")
  && strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'favorite')") && strpos($api, 'stu_require_csrf();') < strpos($api, "if (\$action === 'delete')"),
  'endpoint: no guests, changes only with CSRF');
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $template) {
  // The allowlist names api/files.php, whatever stands next to it (0.7.30 added usage after files).
  st_test(preg_match('~location \~ \^/api/\(\?:[a-z_|]*\|files\|[a-z_|]*\)\\\\\.php\$~', $read($template)) === 1, "nginx allows the storage endpoint: $template");
}
$migration = $read('database/migrations/039_storage.sql');
st_test(str_contains($migration, 'ADD COLUMN IF NOT EXISTS storage_mb INT UNSIGNED NULL') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_user_file_favorites')
  && str_contains($migration, "VALUES ('039_storage')"), 'migration 039');
$upload = $read('api/console_upload.php');
st_test(strpos($upload, '$room = coreui_files_room($pdo, (int)$uid, $size);') > strpos($upload, "\$limit = (\$kind === 'image') ? CONSOLE_MAX_IMAGE_BYTES : CONSOLE_MAX_FILE_BYTES;")
  && strpos($upload, '$room = coreui_files_room($pdo, (int)$uid, $size);') < strpos($upload, 'move_uploaded_file(') && str_contains($upload, "'error'=>'storage_full'"),
  'an upload is refused before it is stored when the storage is full');
$image = $read('api/image_gen.php');
st_test(str_contains($image, "if (coreui_files_room(\$pdo, \$uid, 1) !== null) return ['ok' => false, 'error' => 'storage_full', 'detail' => ''];")
  && str_contains($image, "if (coreui_files_room(\$pdo, \$uid, strlen(\$png)) !== null) return ['ok' => false, 'error' => 'storage_full', 'detail' => ''];")
  && str_contains($image, "'storage_full' => 'Dein Speicher ist voll."), 'Mara starts no image and keeps none when the storage is full, and says why');
st_test(str_contains($read('js/console-app.js'), "if (code === 'storage_full') msg = 'Dein Speicher ist voll'"), 'the chat explains a refused upload');
$admin = $read('api/admin.php');
st_test(str_contains($admin, "'storage_limit_mb' => '',") && str_contains($admin, "if (\$key === 'storage_limit_mb') \$value = coreui_files_limit_normalise(\$value);")
  && str_contains($admin, "foreach (['tools', 'daily_messages', 'max_num_ctx', 'storage_mb'] as \$key)"), 'Admin Core: the limit for all accounts and per account');
$adminJs = $read('js/admin.js');
st_test(str_contains($adminJs, "storage_limit_mb: gbToMb(document.getElementById('storageLimitGb').value),") && str_contains($adminJs, "editField(limits, 'SPEICHER (GB)', storage);")
  && str_contains($read('admin/index.html'), 'id="storageLimitGb"'), 'Admin Core page: Betrieb and Benutzer > Limits');
$settings = $read('settings.html');
st_test(str_contains($settings, 'id="tab-speicher" data-view="speicher" aria-controls="view-speicher" aria-selected="false" tabindex="-1"><svg')
  && !str_contains($settings, 'class="cfg-view cfg-view-planned" id="view-speicher"') && str_contains($settings, 'js/coreui-storage-settings.js'),
  'Speicher is a real page now');

// --- With a database ------------------------------------------------------------------------------
$dbWanted = is_file($root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB') === '1';
if (!$dbWanted) {
  echo "CoreAI storage: limits, sizes, ids and wiring OK (no database here)\n";
  exit(0);
}
require_once $root . '/api/config.php';
require_once $root . '/api/helpers.php';
require_once $root . '/api/db.php';
$pdo = stu_pdo();
st_test(coreui_user_policy_storage_ready($pdo) && coreui_files_favorites_ready($pdo), 'migration 039 applied');
$written = [];
$global = stu_app_setting_get($pdo, 'storage_limit_mb', '');
$pdo->beginTransaction();
try {
  $uid = random_int(1500000000, 1900000000);
  $other = $uid + 1;
  $owner = $uid + 2;
  $ins = $pdo->prepare('INSERT INTO stu_users (id, username, is_guest, permission_level, created_at) VALUES (?, ?, 0, ?, NOW())');
  $ins->execute([$uid, 'storage-' . bin2hex(random_bytes(4)) . '@coreui.invalid', 3]);
  $ins->execute([$other, 'storage-' . bin2hex(random_bytes(4)) . '@coreui.invalid', 3]);
  $ins->execute([$owner, 'storage-' . bin2hex(random_bytes(4)) . '@coreui.invalid', 0]);
  $media = static function (int $user, string $kind, string $name, int $size, string $dir) use ($pdo, $root, &$written): string {
    $uuid = bin2hex(random_bytes(16));
    $rel = $dir . $uuid . ($kind === 'image' ? '.png' : '.bin');
    @mkdir(dirname($root . '/' . $rel), 0770, true);
    file_put_contents($root . '/' . $rel, str_repeat('x', $size));
    $written[] = $root . '/' . $rel;
    $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, kind, orig_name, stored_name, rel_path, mime_type, file_size) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$uuid, $user, $kind, $name, basename($rel), $rel, $kind === 'image' ? 'image/png' : 'application/octet-stream', $size]);
    return $uuid;
  };
  $doc = $media($uid, 'document', 'Bericht.pdf', 3000, 'var/console_media/');
  $audio = $media($uid, 'audio', 'Ton.webm', 2000, 'var/console_media/');
  $upload = $media($uid, 'image', 'Foto.png', 500, 'assets/chat_media/');
  $made = $media($uid, 'image', 'Mara-Bild.png', 700, 'var/console_media/images/');
  file_put_contents($root . '/var/console_media/images/' . $made . '.json', '{}');
  $written[] = $root . '/var/console_media/images/' . $made . '.json';
  $foreign = $media($other, 'document', 'Fremd.pdf', 900, 'var/console_media/');
  // A project chat with the document and one of Mara's files.
  $project = bin2hex(random_bytes(16));
  $session = 's' . bin2hex(random_bytes(10));
  $pdo->prepare('INSERT INTO stu_console_projects (id, user_id, name) VALUES (?, ?, ?)')->execute([$project, $uid, 'Projekt Testlauf']);
  $pdo->prepare('INSERT INTO stu_console_sessions (id, user_id, project_id, title) VALUES (?, ?, ?, ?)')->execute([$session, $uid, $project, 'Chat Testlauf']);
  $pdo->prepare("INSERT INTO stu_chat_messages (user_id, session_id, channel, message, character_id, character_name) VALUES (?, ?, 'console', 'x', 'c', 'T')")->execute([$uid, $session]);
  $message = (int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position) VALUES (?, ?, ?, 0)')->execute([$message, $doc, $uid]);
  $artifact = bin2hex(random_bytes(16));
  $pdo->prepare("INSERT INTO stu_console_artifacts (id, user_id, session_id, message_id, job_id, producer, storage_key, orig_name, mime_type, file_size, sha256) VALUES (?, ?, ?, ?, 1, 'python', ?, 'Ergebnis.zip', 'application/zip', 4000, ?)")
    ->execute([$artifact, $uid, $session, $message, $artifact, str_repeat('a', 64)]);

  // Usage.
  $usage = coreui_files_usage($pdo, $uid);
  st_test($usage['images'] === ['count' => 2, 'bytes' => 1200] && $usage['files'] === ['count' => 3, 'bytes' => 9000] && $usage['used'] === 10200,
    'usage: images (uploads and Mara\'s), files (uploads and Mara\'s files): ' . json_encode($usage));
  st_test((coreui_files_usage_all($pdo)[(string)$uid] ?? 0) === 10200 && (coreui_files_usage_all($pdo)[(string)$other] ?? 0) === 900, 'usage per account for Admin Core');

  // The limit.
  stu_app_setting_set($pdo, 'storage_limit_mb', '');
  st_test(coreui_files_limit_bytes($pdo, $uid) === null && coreui_files_room($pdo, $uid, 10 ** 9) === null, 'no limit for all accounts: anything fits');
  stu_app_setting_set($pdo, 'storage_limit_mb', '1');
  st_test(coreui_files_limit_bytes($pdo, $uid) === 1048576 && coreui_files_room($pdo, $uid, 1000) === null
    && coreui_files_room($pdo, $uid, 1048576) === ['used' => 10200, 'limit' => 1048576], 'the limit for all accounts counts at once');
  st_test(coreui_files_limit_bytes($pdo, $owner) === null, 'the Inhaber has no limit');
  coreui_user_policy_save($pdo, $uid, ['storage_mb' => 0], 0, []);
  st_test(coreui_files_limit_bytes($pdo, $uid) === null, 'own 0: no limit for this account');
  coreui_user_policy_save($pdo, $uid, ['storage_mb' => 2048], 0, []);
  st_test(coreui_files_limit_bytes($pdo, $uid) === 2048 * 1048576 && coreui_files_limit_bytes($pdo, $other) === 1048576, 'an own value beats the one for all, only for this account');
  coreui_user_policy_save($pdo, $uid, ['storage_mb' => null], 0, []);
  st_test(coreui_files_limit_bytes($pdo, $uid) === 1048576, 'empty: back to the limit for all');
  foreach ([-1, 'zwei', 1.5, COREUI_USER_POLICY_STORAGE_MAX_MB + 1] as $bad) {
    try { coreui_user_policy_save($pdo, $uid, ['storage_mb' => $bad], 0, []); $ok = false; } catch (InvalidArgumentException $e) { $ok = $e->getMessage() === 'invalid_storage_mb'; }
    st_test($ok, 'refused storage value ' . var_export($bad, true));
  }
  coreui_user_policy_save($pdo, $uid, ['daily_messages' => 20], 0, []);
  st_test(coreui_user_policy_load($pdo, $uid)['storage_mb'] === null, 'saving other limits leaves storage alone');

  // Lists.
  $names = static fn(array $list): array => array_column($list['items'], 'name');
  $all = coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all']);
  st_test(count($all['items']) === 3 && !in_array('Fremd.pdf', $names($all), true) && !in_array('Foto.png', $names($all), true), 'Dateien: own files only, no images');
  st_test($all['folders'] === [['id' => $project, 'name' => 'Projekt Testlauf', 'count' => 2, 'size' => 7000]], 'a project with files is a folder');
  $inProject = coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all', 'project' => $project]);
  st_test($names($inProject) === ['Ergebnis.zip', 'Bericht.pdf'] || $names($inProject) === ['Bericht.pdf', 'Ergebnis.zip'], 'the folder holds the project\'s files');
  $byKey = array_column($all['items'], null, 'id');
  st_test($byKey['m:' . $doc]['chat']['title'] === 'Chat Testlauf' && $byKey['m:' . $doc]['chat']['project'] === 'Projekt Testlauf'
    && $byKey['m:' . $audio]['chat'] === null && $byKey['a:' . $artifact]['kind'] === 'mara' && $byKey['a:' . $artifact]['url'] === 'api/console_artifact.php?id=' . $artifact,
    'where a file came from');
  st_test($names(coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all', 'type' => 'mara'])) === ['Ergebnis.zip']
    && $names(coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all', 'type' => 'audio'])) === ['Ton.webm'], 'type filter');
  st_test($names(coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all', 'q' => 'berich'])) === ['Bericht.pdf']
    && coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'all', 'q' => '%'])['items'] === [], 'search (wildcards are literal)');
  st_test($names(coreui_files_list($pdo, $uid, ['view' => 'images', 'tab' => 'creations'])) === ['Mara-Bild.png']
    && $names(coreui_files_list($pdo, $uid, ['view' => 'images', 'tab' => 'uploads'])) === ['Foto.png'], 'Bilder: Deine Kreationen and Uploads');
  st_test(coreui_files_list($pdo, $uid, ['view' => 'images', 'tab' => 'creations'])['items'][0]['thumb'] === 'api/console_media.php?uuid=' . $made, 'images carry a picture');

  // Favourites.
  st_test(coreui_files_favorite($pdo, $uid, 'm:' . $doc, true) === true && $names(coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'favorites'])) === ['Bericht.pdf'],
    'a favourite');
  try { coreui_files_favorite($pdo, $uid, 'm:' . $foreign, true); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
  st_test($ok, 'never another account\'s file as favourite');

  // Deleting.
  try { coreui_files_delete($pdo, $uid, 'm:' . $foreign); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
  st_test($ok && is_file($root . '/var/console_media/' . $foreign . '.bin'), 'never another account\'s file');
  st_test(coreui_files_delete($pdo, $uid, 'm:' . $doc) === 3000 && !is_file($root . '/var/console_media/' . $doc . '.bin')
    && coreui_files_list($pdo, $uid, ['view' => 'files', 'tab' => 'favorites'])['items'] === [], 'deleted: file, entry and favourite');
  st_test(coreui_files_delete($pdo, $uid, 'm:' . $made) === 700 && !is_file($root . '/var/console_media/images/' . $made . '.png')
    && !is_file($root . '/var/console_media/images/' . $made . '.json'), 'Mara\'s image with its description');
  st_test(coreui_files_delete($pdo, $uid, 'a:' . $artifact) === 4000, 'Mara\'s file');
  st_test(coreui_files_usage($pdo, $uid)['used'] === 2500, 'room again after deleting');
  try { coreui_files_delete($pdo, $uid, 'm:' . $doc); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
  st_test($ok, 'a deleted file is gone');
  st_test($pdo->inTransaction(), 'still inside the transaction');
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
  stu_app_setting_set($pdo, 'storage_limit_mb', (string)$global);
  foreach ($written as $file) @unlink($file);
}
echo "CoreAI storage: limits (for all, per account, Inhaber), usage, lists, folders, favourites, deleting and wiring on the database (rolled back) OK\n";
