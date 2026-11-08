<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/profile_store.php';
require_once dirname(__DIR__) . '/api/image_reference_consistency.php';
function stu_public_path(string $path): string { return '/' . $path; }
function check(bool $yes, string $why): void { if (!$yes) throw new RuntimeException($why); }
set_exception_handler(static function (Throwable $e): void {
  fwrite(STDERR, 'Profile reference FAIL: ' . $e->getMessage() . "\n");
  exit(1);
});
$src = imagecreatetruecolor(100,300);
imagefilledrectangle($src,0,0,99,39,0xff0000);
imagefilledrectangle($src,0,260,99,299,0x00ff00);
$portrait = coreui_profile_render_avatar($src,'assistant');
check(imagesx($portrait)===100 && imagesy($portrait)===300, 'Whole portrait aspect ratio');
check((imagecolorat($portrait,50,10)&0xffffff)===0xff0000 && (imagecolorat($portrait,50,290)&0xffffff)===0x00ff00, 'Head and feet preserved');
$user = coreui_profile_render_avatar($src,'user');
check(imagesx($user)===100 && imagesy($user)===100, 'User avatar remains square');
$wide = imagecreatetruecolor(2048,1024);
$scaled = coreui_profile_render_avatar($wide,'assistant');
check(imagesx($scaled)===1024 && imagesy($scaled)===512, 'Landscape size bounded without crop');

// 0.6.55: original-relative coordinates are validated server-side, not trusted
// from the browser. The personal reference may retain any portrait aspect.
$half = coreui_profile_crop_pixels(['x'=>0.0,'y'=>0.0,'w'=>1.0,'h'=>0.5],100,300,'assistant');
check($half['w']===100 && $half['h']===150, 'Portrait crop converts to original pixels');
$cropped = coreui_profile_render_avatar($src, 'assistant', $half);
check(imagesx($cropped)===100 && imagesy($cropped)===150
  && (imagecolorat($cropped,50,10)&0xffffff)===0xff0000
  && (imagecolorat($cropped,50,140)&0xffffff)!==0x00ff00,
  'Personal reference crop retains selected region and avoids the unselected bottom');
$square = coreui_profile_crop_pixels(['x'=>0.0,'y'=>0.4,'w'=>1.0,'h'=>1/3],100,300,'user');
check($square['w']===$square['h'] && $square['w']===100, 'Square crop is enforced on server');
check(coreui_profile_crop_pixels(null,100,300,'assistant')===null, 'Old uploads keep uncropped assistant behavior');
foreach ([
  [['x'=>-0.1,'y'=>0,'w'=>1,'h'=>1], 'assistant'],
  [['x'=>0,'y'=>0,'w'=>1,'h'=>1.2], 'assistant'],
  [['x'=>0,'y'=>0,'w'=>0.1,'h'=>0.1], 'user'],
  [['x'=>0,'y'=>0,'w'=>1,'h'=>0.6], 'user'],
  [['x'=>'0','y'=>0,'w'=>1,'h'=>1], 'assistant']
] as [$bad, $slot]) {
  $caught = false;
  try { coreui_profile_crop_pixels($bad,100,300,$slot); }
  catch (InvalidArgumentException $e) { $caught = true; }
  check($caught, 'Invalid crop geometry rejected for ' . $slot);
}
imagedestroy($cropped);
imagedestroy($scaled);
imagedestroy($wide);
imagedestroy($user);
// The avatar is the TCI's own on-account visual identity, not merely an
// example of someone who resembles her. This must work without SQLite.
$customRule = coreui_tci_self_portrait_rule(true);
$defaultRule = coreui_tci_self_portrait_rule(false);
check(str_contains($customRule, 'zeigt DICH SELBST als TCI: Mara Thorne')
  && str_contains($defaultRule, 'zeigt DICH SELBST als TCI: Mara Thorne')
  && str_contains($customRule, 'nicht eine Schauspielerin')
  && str_contains($customRule, 'Fremde hochgeladene Personenbilder bleiben getrennte Identitaeten')
  && str_contains($customRule, 'du siehst die Bildpixel nicht allein durch diesen Text'),
  'Account and default portraits are the TCI herself without inventing visible details');

// These checks must run even on production hosts which intentionally do not
// install PDO SQLite: model prompts and avatar geometry do not need a database.
$directPrompt = coreui_image_reference_prompt(
  'A scene at sunset', 'custom:' . str_repeat('a', 32), [], 1200
);
check(str_contains($directPrompt, 'ACCOUNT PORTRAIT')
  && str_contains($directPrompt, 'EXACT SAME')
  && str_contains($directPrompt, 'A scene at sunset'),
  'Exact identity and chosen scene must coexist');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
  echo "PARTIAL: Profile reference avatar and prompt checks OK. PDO SQLite is not installed; "
    . "account isolation, profile database and memory side-effect checks NOT executed.\n";
  exit(0);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER, username TEXT);
CREATE TABLE stu_coreui_profiles (user_id INTEGER, display_name TEXT, assistant_name TEXT, updated_at TEXT);
CREATE TABLE stu_coreui_profile_media (uuid TEXT,user_id INTEGER,slot TEXT,stored_name TEXT,mime_type TEXT,file_size INTEGER,width_px INTEGER,height_px INTEGER,created_at TEXT);
CREATE TABLE ember_memories (id INTEGER PRIMARY KEY,fact TEXT,relevance INTEGER,scope TEXT,user_id INTEGER,character_id TEXT,fact_hash TEXT,last_used_at TEXT,updated_at TEXT);
INSERT INTO stu_users VALUES (1,"one@example.invalid"),(2,"two@example.invalid");
INSERT INTO stu_coreui_profiles VALUES (1,"One","Ember",CURRENT_TIMESTAMP),(2,"Two","Mara",CURRENT_TIMESTAMP);');
check(coreui_profile_load($pdo,1)['assistant_name'] === 'Ember', 'Ember is a valid display name');
check(str_contains(coreui_profile_prompt_block($pdo,1), '"Ember"'), 'Prompt retains alias');
$mediaDir = coreui_profile_media_dir();
check(is_dir($mediaDir) && is_writable($mediaDir), 'Profile media directory not writable for the test account');
$key = bin2hex(random_bytes(16));
$path = coreui_profile_media_dir() . '/' . $key . '.png';
imagepng($portrait,$path);
try {
 $pdo->prepare('INSERT INTO stu_coreui_profile_media VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([$key,1,'assistant',$key.'.png','image/png',filesize($path),100,300]);
 check(coreui_profile_image_reference($pdo,1)==='custom:'.$key, 'Custom reference selected');
 check(coreui_profile_image_reference($pdo,2)==='default', 'Other account remains canonical');
 check(str_contains(coreui_profile_prompt_block($pdo,1), 'Das vom Benutzer fuer dieses Konto gewaehlte Bild zeigt DICH SELBST als TCI: Mara Thorne')
   && str_contains(coreui_profile_prompt_block($pdo,1), 'Dein Rufname (z. B. Ember)'),
   'Custom assistant photo is Mara Thorne herself even with Ember display name');
 check(str_contains(coreui_profile_prompt_block($pdo,2), 'Das kanonische Standardbild zeigt DICH SELBST als TCI: Mara Thorne'),
   'Default assistant photo is also Mara herself');
 $prompt=coreui_image_reference_prompt('A scene at sunset','custom:'.$key,[],1200);
 check(str_contains($prompt,'ACCOUNT PORTRAIT') && str_contains($prompt,'A scene at sunset'), 'Custom source priority retains scene');
 $sourceFile = coreui_profile_media_dir() . '/' . $key . '.source.png';
 $metaFile = coreui_profile_media_dir() . '/' . $key . '.crop.json';
 imagepng($portrait, $sourceFile);
 file_put_contents($metaFile, json_encode(['crop'=>['x'=>0.0,'y'=>0.0,'w'=>1.0,'h'=>0.5]]));
 check(coreui_profile_source_path($key)['path'] === $sourceFile, 'Own original source persisted privately');
 check(coreui_profile_crop_meta($key)['h'] === 0.5, 'Stored crop coordinates can be re-edited');
 $loaded = coreui_profile_load($pdo, 1);
 check(($loaded['media']['assistant']['uuid'] ?? null) === $key
   && str_contains((string)($loaded['media']['assistant']['source_url'] ?? ''), 'variant=source')
   && ($loaded['media']['assistant']['crop']['h'] ?? null) === 0.5,
   'Account profile exposes original URL and normalized crop only for its own media');
 check(!isset(coreui_profile_load($pdo,2)['media']['assistant']),
   'A second account never sees another account original or crop');
 check((int)$pdo->query('SELECT COUNT(*) FROM ember_memories')->fetchColumn()===0, 'Profile reads and references create no memories');
} finally {
 coreui_profile_media_cleanup($key . '.png');
 check(!file_exists($path) && !file_exists(coreui_profile_media_dir() . '/' . $key . '.source.png')
   && !file_exists(coreui_profile_media_dir() . '/' . $key . '.crop.json'),
   'Deleting avatar also deletes original bytes and crop coordinates');
}
echo "Profile reference: alias, uncropped portrait, account isolation, prompt priority and no memory side effects OK\n";
