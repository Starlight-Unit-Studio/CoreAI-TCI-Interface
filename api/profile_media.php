<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_store.php';

$pdo = stu_pdo();
$uid = stu_require_user_id();
$slot = strtolower(trim((string)($_GET['slot'] ?? '')));
if (!in_array($slot, ['user', 'assistant'], true)) {
  http_response_code(404);
  exit;
}

$row = coreui_profile_media_record($pdo, $uid, $slot);
if (!$row) {
  http_response_code(404);
  exit;
}
$path = (string)$row['path'];
$mime = (string)$row['mime_type'] ?: 'image/png';
$variant = strtolower(trim((string)($_GET['variant'] ?? '')));
if ($variant === 'source') {
  $source = coreui_profile_source_path((string)$row['uuid']);
  if ($source !== null) {
    $path = $source['path'];
    $mime = $source['mime'];
  }
} elseif ($variant !== '') {
  http_response_code(404);
  exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-cache, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="coreai-' . $slot . ($mime === 'image/jpeg' ? '.jpg' : '.png') . '"');
readfile($path);
exit;
