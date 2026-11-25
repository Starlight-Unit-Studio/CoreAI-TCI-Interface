<?php
declare(strict_types=1);
// CoreAI 0.8.34: a finished image that cannot be kept names its cause.
//   php scripts/image-store-diagnostic-selftest.php
// "Das Bild konnte nicht dauerhaft gespeichert werden" came without any reason: the image service
// had finished (phase done), but saving the PNG failed at one of three points and each answered a
// bare storage_failed. The usual cause was the image folder var/console_media/images belonging to
// root: the Compose preflight ran the image and storage self-tests as root, and they created the
// real folder when it did not exist yet; PHP-FPM (www-data) could not write there afterwards.
// Offline (SQLite, a temporary image folder): every failure point answers its own cause, the log
// line carries what an operator needs, the work view shows the cause, and the self-tests and both
// preflights no longer leave the real folder to root.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/coreai-image-store-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('COREUI_IMAGE_DIR', $tmp . '/images');
ini_set('log_errors', '1');
ini_set('error_log', $tmp . '/php.log');
require_once $root . '/api/image_gen.php';
require_once $root . '/api/image_work_diagnostic.php';
require_once $root . '/api/console_work_store.php';

function isd_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Image store diagnostic FAIL: {$why}\n"); exit(1); }
}
$media = static function (): PDO {
  $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE stu_console_media (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, user_id INT, character_id TEXT, kind TEXT,
    orig_name TEXT, stored_name TEXT, rel_path TEXT, public_url TEXT, mime_type TEXT, file_size INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
  return $pdo;
};
$png = "\x89PNG\r\n\x1a\nstand-in";
$logged = static function () use ($tmp): array {
  $lines = is_file($tmp . '/php.log') ? (array)file($tmp . '/php.log', FILE_IGNORE_NEW_LINES) : [];
  $out = [];
  foreach ($lines as $line) {
    $at = strpos($line, '[CoreAI] image_store_failed ');
    if ($at !== false) $out[] = json_decode(substr($line, $at + strlen('[CoreAI] image_store_failed ')), true);
  }
  return $out;
};
$pngs = static fn(): array => is_dir($tmp . '/images') ? (glob($tmp . '/images/*.png') ?: []) : [];

// --- The folder cannot be made: image_dir_missing ----------------------------------------------
file_put_contents($tmp . '/images', 'a file where the folder belongs');
$r = coreui_image_store($media(), 0, '', $png, ['prompt' => 'fox']);
isd_test(($r['ok'] ?? null) === false && ($r['error'] ?? '') === 'storage_failed' && ($r['detail'] ?? '') === 'image_dir_missing',
  'a folder that cannot be made names image_dir_missing: ' . json_encode($r));
$log = $logged();
isd_test(count($log) === 1 && ($log[0]['cause'] ?? '') === 'image_dir_missing' && ($log[0]['dir'] ?? '') === $tmp . '/images'
  && ($log[0]['exists'] ?? null) === false && array_key_exists('owner_uid', $log[0]) && array_key_exists('php_uid', $log[0])
  && array_key_exists('free_mb', $log[0]), 'the log line names the cause, the folder, its owner, the PHP user and free space: ' . json_encode($log));
unlink($tmp . '/images');

// --- The folder is not writable for PHP: image_dir_not_writable ------------------------------
// root may write everywhere. As an ordinary user (CI, both preflights run it as www-data) the case runs
// directly; as root it runs in a forked child that becomes the nobody user, where pcntl exists.
mkdir($tmp . '/images', 0555);
$asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (!$asRoot) {
  isd_test(!is_writable($tmp . '/images'), 'the test folder is read-only for this user');
  $r = coreui_image_store($media(), 0, '', $png, ['prompt' => 'fox']);
  isd_test(($r['detail'] ?? '') === 'image_dir_not_writable' && $pngs() === [],
    'a folder PHP may not write names image_dir_not_writable: ' . json_encode($r));
} elseif (function_exists('pcntl_fork')) {
  chmod($tmp, 0711);
  $pid = pcntl_fork();
  if ($pid === 0) {
    posix_setgid(65534);
    posix_setuid(65534);
    ini_set('error_log', '/dev/null'); // the root-owned test log is not writable for nobody
    $r = coreui_image_store($media(), 0, '', $png, ['prompt' => 'fox']);
    exit(!is_writable($tmp . '/images') && ($r['detail'] ?? '') === 'image_dir_not_writable' && $pngs() === [] ? 0 : 3);
  }
  pcntl_waitpid($pid, $status);
  chmod($tmp, 0700);
  isd_test(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
    'a folder PHP may not write names image_dir_not_writable (checked as nobody)');
}
chmod($tmp . '/images', 0700);

// --- The media entry fails: media_insert_failed, nothing left behind --------------------------
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = coreui_image_store($broken, 0, '', $png, ['prompt' => 'fox']);
isd_test(($r['detail'] ?? '') === 'media_insert_failed' && $pngs() === [] && (glob($tmp . '/images/*.json') ?: []) === [],
  'a failed media entry names media_insert_failed and removes the PNG and its description: ' . json_encode($r));
$last = $logged();
$last = end($last);
isd_test(is_array($last) && $last['cause'] === 'media_insert_failed' && str_contains((string)$last['message'], 'stu_console_media')
  && $last['exists'] === true, 'the database message is in the log, not in the answer: ' . json_encode($last));

// --- A good save still works -----------------------------------------------------------------
$pdo = $media();
$r = coreui_image_store($pdo, 0, '', $png, ['prompt' => 'fox']);
isd_test(($r['ok'] ?? false) === true && is_file($tmp . '/images/' . $r['uuid'] . '.png')
  && (int)$pdo->query('SELECT COUNT(*) FROM stu_console_media')->fetchColumn() === 1, 'a writable folder keeps the image: ' . json_encode($r));

// --- The work view gets the cause, never a path ------------------------------------------------
foreach (['image_dir_missing', 'image_dir_not_writable', 'image_write_failed', 'media_insert_failed'] as $cause) {
  $meta = coreui_work_safe_meta(coreui_image_failure_work_meta(['error' => 'storage_failed', 'detail' => $cause], ['reason' => 'image_done']));
  isd_test(($meta['reason'] ?? '') === 'storage_failed' && ($meta['error_type'] ?? '') === $cause && ($meta['image_phase'] ?? '') === 'done',
    'the work event carries the cause ' . $cause . ': ' . json_encode($meta));
}
$meta = coreui_image_failure_work_meta(['error' => 'storage_failed', 'detail' => '/var/www/coreui/var/console_media/images'], []);
isd_test(!isset($meta['error_type']) && $meta['reason'] === 'storage_failed', 'an unknown detail is not passed on: ' . json_encode($meta));
$meta = coreui_image_failure_work_meta(['error' => 'image_failed', 'detail' => 'image_dir_missing'], []);
isd_test(!isset($meta['error_type']), 'a storage cause only counts for storage_failed');
$events = (string)file_get_contents($root . '/js/coreui-work-events.js');
foreach (["image_dir_missing:'Der Bildordner auf dem Server fehlt und konnte nicht angelegt werden.'",
    "image_dir_not_writable:'Der Webserver darf nicht in den Bildordner auf dem Server schreiben.'",
    "image_write_failed:'Die Bilddatei konnte nicht vollständig auf den Server geschrieben werden.'",
    "media_insert_failed:'Der Eintrag des Bildes in der Datenbank ist fehlgeschlagen.'",
    "if (code === 'storage_failed' && STORAGE_CAUSES[meta.error_type]) {"] as $needle) {
  isd_test(str_contains($events, $needle), 'the work view explains the cause: ' . $needle);
}
if (is_executable('/usr/bin/node') || trim((string)@shell_exec('command -v node 2>/dev/null')) !== '') {
  $js = <<<'JS'
const src = require('fs').readFileSync(process.argv[2], 'utf8');
const start = src.indexOf('  var IMAGE_PHASES = {');
const end = src.indexOf('  // Das ist ein oeffentliches');
const fn = new Function(src.slice(start, end) + '; return imageFailureNote;')();
process.stdout.write(fn({meta: {reason: 'storage_failed', error_type: 'image_dir_not_writable', image_phase: 'done'}}));
JS;
  file_put_contents($tmp . '/note.cjs', $js);
  $note = (string)shell_exec('node ' . escapeshellarg($tmp . '/note.cjs') . ' ' . escapeshellarg($root . '/js/coreui-work-events.js') . ' 2>&1');
  isd_test($note === 'Das Bild konnte nicht dauerhaft gespeichert werden. Der Webserver darf nicht in den Bildordner auf dem Server schreiben.'
    . ' Zuletzt bestätigt: Bild abgeschlossen. Ein fertiges Bild wurde nicht bestätigt.', 'the shown note names the cause: ' . $note);
}

// --- Self-tests and preflights no longer leave the real folder to root ---------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$gen = $read('scripts/image-gen-selftest.php');
isd_test(str_contains($gen, '$madeParent = !is_dir(dirname($realDir));')
  && str_contains($gen, 'if ($madeDir) { @rmdir($realDir); if ($madeParent) @rmdir(dirname($realDir)); }')
  && str_contains($gen, '@chown($realDir, (int)fileowner($parent)); @chgrp($realDir, (int)filegroup($parent));'),
  'the image self-test hands a folder it made to the owner of var/console_media and removes only what it made');
$storage = $read('scripts/storage-selftest.php');
isd_test(str_contains($storage, '@chown($folder, (int)fileowner(dirname($folder)));')
  && !str_contains($storage, "@mkdir(dirname(\$root . '/' . \$rel), 0770, true);"), 'the storage self-test hands a folder it made to the owner of its parent');
$compose = $read('scripts/preflight.sh');
isd_test(str_contains($compose, 'compose exec -T -u 33:33 php php scripts/image-gen-selftest.php')
  && str_contains($compose, 'compose exec -T -u 33:33 php php scripts/storage-selftest.php')
  && !str_contains($compose, 'compose exec -T php php scripts/image-gen-selftest.php')
  && !str_contains($compose, 'compose exec -T php php scripts/storage-selftest.php'),
  'the Compose preflight runs the image and storage self-tests as www-data');
foreach (['scripts/preflight.sh', 'scripts/preflight-native.sh'] as $file) {
  $src = $read($file);
  isd_test(str_contains($src, 'Image folder is writable for PHP: var/console_media/images')
    && str_contains($src, 'Image folder var/console_media/images is not writable for PHP'),
    $file . ' checks that PHP may write the image folder');
}
$gen = coreui_image_store_failed('image_write_failed', $tmp . '/nowhere');
isd_test($gen === ['ok' => false, 'error' => 'storage_failed', 'detail' => 'image_write_failed'], 'the result never carries a path');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($tmp);
echo "CoreAI image store: a failed save names its cause in the work view and the log, self-tests and preflights keep the image folder writable for PHP OK\n";
