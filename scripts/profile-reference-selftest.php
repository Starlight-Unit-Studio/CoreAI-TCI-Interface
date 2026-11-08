<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/profile_store.php';
require_once dirname(__DIR__) . '/api/image_reference_consistency.php';
function stu_public_path(string $path): string { return '/' . $path; }
function check(bool $yes, string $why): void { if (!$yes) throw new RuntimeException($why); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER, username TEXT);
CREATE TABLE stu_coreui_profiles (user_id INTEGER, display_name TEXT, assistant_name TEXT, updated_at TEXT);
CREATE TABLE stu_coreui_profile_media (uuid TEXT,user_id INTEGER,slot TEXT,stored_name TEXT,mime_type TEXT,file_size INTEGER,width_px INTEGER,height_px INTEGER,created_at TEXT);
CREATE TABLE ember_memories (id INTEGER PRIMARY KEY,fact TEXT,relevance INTEGER,scope TEXT,user_id INTEGER,character_id TEXT,fact_hash TEXT,last_used_at TEXT,updated_at TEXT);
INSERT INTO stu_users VALUES (1,"one@example.invalid"),(2,"two@example.invalid");
INSERT INTO stu_coreui_profiles VALUES (1,"One","Ember",CURRENT_TIMESTAMP),(2,"Two","Mara",CURRENT_TIMESTAMP);');
check(coreui_profile_load($pdo,1)['assistant_name'] === 'Ember', 'Ember is a valid display name');
check(str_contains(coreui_profile_prompt_block($pdo,1), '"Ember"'), 'Prompt retains alias');
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
$key = bin2hex(random_bytes(16));
$path = coreui_profile_media_dir() . '/' . $key . '.png';
imagepng($portrait,$path);
try {
 $pdo->prepare('INSERT INTO stu_coreui_profile_media VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([$key,1,'assistant',$key.'.png','image/png',filesize($path),100,300]);
 check(coreui_profile_image_reference($pdo,1)==='custom:'.$key, 'Custom reference selected');
 check(coreui_profile_image_reference($pdo,2)==='default', 'Other account remains canonical');
 check(str_contains(coreui_profile_prompt_block($pdo,1),'verbindliche visuelle Vorlage'), 'Current portrait takes priority in model context');
 $prompt=coreui_image_reference_prompt('A scene at sunset','custom:'.$key,[],1200);
 check(str_contains($prompt,'ACCOUNT PORTRAIT') && str_contains($prompt,'A scene at sunset'), 'Custom source priority retains scene');
 check((int)$pdo->query('SELECT COUNT(*) FROM ember_memories')->fetchColumn()===0, 'Profile reads and references create no memories');
} finally { @unlink($path); }
echo "Profile reference: alias, uncropped portrait, account isolation, prompt priority and no memory side effects OK\n";
