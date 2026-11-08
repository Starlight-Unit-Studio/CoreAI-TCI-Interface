<?php
declare(strict_types=1);

function coreui_profile_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, display_name, assistant_name FROM stu_coreui_profiles LIMIT 1');
    $pdo->query('SELECT uuid, user_id, slot FROM stu_coreui_profile_media LIMIT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_profile_name(string $value, string $fallback): string {
  $value = trim((string)preg_replace('~\s+~u', ' ', $value));
  $value = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
  $value = trim((string)preg_replace("~[^\p{L}\p{N} ._'’()\\-]+~u", '', $value));
  if ($value === '') $value = $fallback;
  return function_exists('mb_substr')
    ? mb_substr($value, 0, 64, 'UTF-8')
    : substr($value, 0, 64);
}

function coreui_profile_fallback_display(string $username): string {
  $local = trim((string)strtok($username, '@'));
  $local = str_replace(['.', '_', '-'], ' ', $local);
  $local = trim((string)preg_replace('~\s+~u', ' ', $local));
  return coreui_profile_name($local !== '' ? ucwords($local) : 'Operator', 'Operator');
}

function coreui_profile_load(PDO $pdo, int $uid): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $stUser = $pdo->prepare('SELECT username FROM stu_users WHERE id = ? LIMIT 1');
  $stUser->execute([$uid]);
  $username = (string)($stUser->fetchColumn() ?: '');
  $displayName = coreui_profile_fallback_display($username);
  $assistantName = 'Mara';
  $updatedAt = null;

  if (coreui_profile_schema_ready($pdo)) {
    $st = $pdo->prepare('SELECT display_name, assistant_name, updated_at FROM stu_coreui_profiles WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $displayName = coreui_profile_name((string)($row['display_name'] ?? ''), $displayName);
      $assistantName = coreui_profile_name((string)($row['assistant_name'] ?? ''), 'Mara');
      $updatedAt = $row['updated_at'] ?? null;
    }
  }

  // The account avatar falls back to Mara's canonical portrait when no custom one exists.
  $avatars = ['user' => null, 'assistant' => stu_public_path('images/mara_winter.jpg')];
  $mediaInfo = [];
  if (coreui_profile_schema_ready($pdo)) {
    $stMedia = $pdo->prepare('SELECT slot, uuid, stored_name, mime_type, created_at FROM stu_coreui_profile_media WHERE user_id = ?');
    $stMedia->execute([$uid]);
    foreach ($stMedia->fetchAll(PDO::FETCH_ASSOC) ?: [] as $media) {
      $slot = (string)($media['slot'] ?? '');
      if (!array_key_exists($slot, $avatars)) continue;
      if (coreui_profile_media_path((string)($media['stored_name'] ?? '')) === null) continue;
      // Die zufaellige Medien-ID ist ein genauer Cache-Buster, auch wenn zwei
      // Uploads innerhalb derselben DATETIME-Sekunde stattfinden.
      $version = (string)($media['uuid'] ?? '');
      $avatars[$slot] = stu_public_path(
        'api/profile_media.php?slot=' . rawurlencode($slot) . '&v=' . rawurlencode($version)
      );
      $mediaInfo[$slot] = [
        'uuid'=>$version,
        'source_url'=>stu_public_path('api/profile_media.php?slot=' . rawurlencode($slot)
          . '&variant=source&v=' . rawurlencode($version)),
        'crop'=>coreui_profile_crop_meta($version),
      ];
    }
  }

  return [
    'username' => $username,
    'display_name' => $displayName,
    'assistant_name' => $assistantName,
    'avatars' => $avatars,
    'media' => $mediaInfo,
    'updated_at' => $updatedAt,
  ];
}

/** The account's preferred display name, distinct from Mara Thorne's identity. */
function coreui_profile_assistant_name(PDO $pdo, int $uid): string {
  if ($uid <= 0) return 'Mara';
  try {
    return coreui_profile_name((string)(coreui_profile_load($pdo, $uid)['assistant_name'] ?? ''), 'Mara');
  } catch (Throwable $e) {
    return 'Mara';
  }
}

function coreui_profile_save(PDO $pdo, int $uid, string $displayName, string $assistantName): array {
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $displayName = coreui_profile_name($displayName, 'Operator');
  $assistantName = coreui_profile_name($assistantName, 'Mara');
  $st = $pdo->prepare(
    'INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name, created_at, updated_at) '
    . 'VALUES (?, ?, ?, NOW(), NOW()) '
    . 'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), assistant_name = VALUES(assistant_name), updated_at = NOW()'
  );
  $st->execute([$uid, $displayName, $assistantName]);
  return coreui_profile_load($pdo, $uid);
}

function coreui_profile_media_dir(): string {
  return dirname(__DIR__) . '/var/profile_media';
}

/**
 * Sidecars are private files, keyed only by the server-generated media UUID.
 * The PNG remains the generated reference consumed by the current image worker.
 * Source bytes are preserved separately for repeat crops and later upgrades.
 */
function coreui_profile_media_uuid(string $storedName): ?string {
  if (preg_match('~^([a-f0-9]{32})\\.png$~D', $storedName, $matches) !== 1) return null;
  return $matches[1];
}

function coreui_profile_source_path(string $uuid): ?array {
  if (preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) return null;
  foreach (['jpg'=>'image/jpeg', 'png'=>'image/png'] as $ext=>$mime) {
    $path = coreui_profile_media_dir() . '/' . $uuid . '.source.' . $ext;
    if (is_file($path) && is_readable($path) && !is_link($path)) {
      return ['path'=>$path, 'mime'=>$mime, 'ext'=>$ext];
    }
  }
  return null;
}

function coreui_profile_crop_meta(string $uuid): ?array {
  if (preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) return null;
  $path = coreui_profile_media_dir() . '/' . $uuid . '.crop.json';
  if (!is_file($path) || is_link($path)) return null;
  $data = json_decode((string)@file_get_contents($path), true);
  return is_array($data) && is_array($data['crop'] ?? null) ? $data['crop'] : null;
}

function coreui_profile_media_cleanup(string $storedName): void {
  $uuid = coreui_profile_media_uuid($storedName);
  if ($uuid === null) return;
  $dir = coreui_profile_media_dir() . '/';
  foreach ([$uuid . '.png', $uuid . '.source.jpg', $uuid . '.source.png', $uuid . '.crop.json'] as $name) {
    @unlink($dir . $name);
  }
}

/** Convert a normalized crop from the browser into validated source pixels. */
function coreui_profile_crop_pixels(mixed $value, int $width, int $height, string $slot): ?array {
  if ($value === null || $value === '') return null;
  if (is_string($value)) {
    if (strlen($value) > 256) throw new InvalidArgumentException('avatar_crop_invalid');
    $value = json_decode($value, true);
  }
  if (!is_array($value) || $width < 32 || $height < 32) throw new InvalidArgumentException('avatar_crop_invalid');
  $n = [];
  foreach (['x', 'y', 'w', 'h'] as $axis) {
    $v = $value[$axis] ?? null;
    if (!is_int($v) && !is_float($v)) throw new InvalidArgumentException('avatar_crop_invalid');
    if (!is_finite((float)$v)) throw new InvalidArgumentException('avatar_crop_invalid');
    $n[$axis] = (float)$v;
  }
  if ($n['x'] < 0 || $n['y'] < 0 || $n['w'] <= 0 || $n['h'] <= 0
      || $n['x'] + $n['w'] > 1.00001 || $n['y'] + $n['h'] > 1.00001) {
    throw new InvalidArgumentException('avatar_crop_invalid');
  }
  $x = min($width - 1, (int)round($n['x'] * $width));
  $y = min($height - 1, (int)round($n['y'] * $height));
  $w = min($width - $x, (int)round($n['w'] * $width));
  $h = min($height - $y, (int)round($n['h'] * $height));
  if ($w < 32 || $h < 32) throw new InvalidArgumentException('avatar_crop_too_small');
  if ($slot === 'user' && abs($w - $h) > 2) throw new InvalidArgumentException('avatar_crop_must_be_square');
  return [
    'x'=>$x, 'y'=>$y, 'w'=>$w, 'h'=>$h,
    'normalized'=>['x'=>$x / $width, 'y'=>$y / $height, 'w'=>$w / $width, 'h'=>$h / $height],
  ];
}

function coreui_profile_orient_jpeg(GdImage $image, string $path, string $mime): GdImage {
  if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $image;
  $exif = @exif_read_data($path, 'IFD0', true, false);
  $orientation = is_array($exif) ? (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1;
  if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
  if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
  $degrees = match ($orientation) {
    3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90, default => 0,
  };
  if ($degrees !== 0) {
    $rotated = imagerotate($image, $degrees, 0);
    if ($rotated instanceof GdImage) {
      imagedestroy($image);
      $image = $rotated;
    }
  }
  return $image;
}

function coreui_profile_media_path(string $storedName): ?string {
  $storedName = trim($storedName);
  if ($storedName === '' || basename($storedName) !== $storedName) return null;
  $path = coreui_profile_media_dir() . '/' . $storedName;
  return is_file($path) && is_readable($path) ? $path : null;
}

function coreui_profile_media_record(PDO $pdo, int $uid, string $slot): ?array {
  if ($uid <= 0 || !in_array($slot, ['user', 'assistant'], true)) return null;
  $st = $pdo->prepare(
    'SELECT uuid, stored_name, mime_type, file_size, width_px, height_px '
    . 'FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? LIMIT 1'
  );
  $st->execute([$uid, $slot]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) return null;
  $path = coreui_profile_media_path((string)($row['stored_name'] ?? ''));
  if ($path === null) return null;
  $row['path'] = $path;
  return $row;
}

/** Opaque per-account image reference; never expose arbitrary file paths as an API input. */
function coreui_profile_image_reference(PDO $pdo, int $uid): string {
  if ($uid <= 0 || !coreui_profile_schema_ready($pdo)) return 'default';
  $row = coreui_profile_media_record($pdo, $uid, 'assistant');
  if (!is_array($row)) return 'default';
  $uuid = (string)($row['uuid'] ?? '');
  $stored = (string)($row['stored_name'] ?? '');
  if (preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1 || $stored !== $uuid . '.png') return 'default';
  return 'custom:' . $uuid;
}

function coreui_profile_delete_avatar(PDO $pdo, int $uid, string $slot): bool {
  if (!in_array($slot, ['user', 'assistant'], true)) throw new InvalidArgumentException('invalid_slot');
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $st = $pdo->prepare('SELECT stored_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? LIMIT 1');
  $st->execute([$uid, $slot]);
  $stored = (string)($st->fetchColumn() ?: '');
  $del = $pdo->prepare('DELETE FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ?');
  $del->execute([$uid, $slot]);
  if ($del->rowCount() > 0) coreui_profile_media_cleanup($stored);
  return $del->rowCount() > 0;
}

/** Assistant references retain the whole image; user avatars keep their square crop. */
function coreui_profile_render_avatar(GdImage $src, string $slot): GdImage {
  $width = imagesx($src);
  $height = imagesy($src);
  if ($slot === 'assistant') {
    $scale = min(1, 1024 / max($width, $height));
    $targetWidth = max(1, (int)round($width * $scale));
    $targetHeight = max(1, (int)round($height * $scale));
    $srcX = $srcY = 0;
    $sourceWidth = $width;
    $sourceHeight = $height;
  } else {
    $sourceWidth = $sourceHeight = min($width, $height);
    $srcX = (int)floor(($width - $sourceWidth) / 2);
    $srcY = (int)floor(($height - $sourceHeight) / 2);
    $targetWidth = $targetHeight = min(512, $sourceWidth);
  }
  $dst = imagecreatetruecolor($targetWidth, $targetHeight);
  imagealphablending($dst, false);
  imagesavealpha($dst, true);
  imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
  imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
  return $dst;
}

function coreui_profile_store_avatar(PDO $pdo, int $uid, string $slot, array $file): array {
  if (!in_array($slot, ['user', 'assistant'], true)) throw new InvalidArgumentException('invalid_slot');
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $size = (int)($file['size'] ?? 0);
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($size <= 0 || $tmp === '' || !is_uploaded_file($tmp)) throw new InvalidArgumentException('invalid_upload');
  if ($size > 4 * 1024 * 1024) throw new InvalidArgumentException('avatar_too_large');

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($tmp);
  if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    throw new InvalidArgumentException('avatar_format_not_allowed');
  }
  $info = @getimagesize($tmp);
  $width = (int)($info[0] ?? 0);
  $height = (int)($info[1] ?? 0);
  if ($width < 32 || $height < 32 || $width > 8000 || $height > 8000 || ($width * $height) > 40000000) {
    throw new InvalidArgumentException('avatar_dimensions_invalid');
  }
  $raw = @file_get_contents($tmp);
  $src = is_string($raw) ? @imagecreatefromstring($raw) : false;
  if ($src === false) throw new InvalidArgumentException('avatar_decode_failed');

  $dst = coreui_profile_render_avatar($src, $slot);
  $targetWidth = imagesx($dst);
  $targetHeight = imagesy($dst);
  imagedestroy($src);

  $dir = coreui_profile_media_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    imagedestroy($dst);
    throw new RuntimeException('profile_storage_unavailable');
  }
  if (!is_writable($dir)) {
    imagedestroy($dst);
    throw new RuntimeException('profile_storage_not_writable');
  }

  $uuid = bin2hex(random_bytes(16));
  $storedName = $uuid . '.png';
  $tempName = '.' . $storedName . '.tmp';
  $tempPath = $dir . '/' . $tempName;
  $destPath = $dir . '/' . $storedName;
  $written = imagepng($dst, $tempPath, 6);
  imagedestroy($dst);
  if (!$written || !@rename($tempPath, $destPath)) {
    @unlink($tempPath);
    throw new RuntimeException('avatar_write_failed');
  }
  @chmod($destPath, 0640);
  $finalSize = (int)(@filesize($destPath) ?: 0);
  $originalName = trim((string)preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '', (string)($file['name'] ?? 'avatar')));
  if ($originalName === '') $originalName = 'avatar';
  if (function_exists('mb_substr')) $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
  else $originalName = substr($originalName, 0, 255);

  $oldStored = '';
  try {
    $pdo->beginTransaction();
    $stOld = $pdo->prepare('SELECT stored_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? FOR UPDATE');
    $stOld->execute([$uid, $slot]);
    $oldStored = (string)($stOld->fetchColumn() ?: '');
    $st = $pdo->prepare(
      'INSERT INTO stu_coreui_profile_media '
      . '(uuid, user_id, slot, original_name, stored_name, mime_type, file_size, width_px, height_px, created_at) '
      . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
      . 'ON DUPLICATE KEY UPDATE uuid = VALUES(uuid), original_name = VALUES(original_name), '
      . 'stored_name = VALUES(stored_name), mime_type = VALUES(mime_type), file_size = VALUES(file_size), '
      . 'width_px = VALUES(width_px), height_px = VALUES(height_px), created_at = NOW()'
    );
    $st->execute([$uuid, $uid, $slot, $originalName, $storedName, 'image/png', $finalSize, $targetWidth, $targetHeight]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($destPath);
    throw $e;
  }
  if ($oldStored !== '' && $oldStored !== $storedName) @unlink($dir . '/' . basename($oldStored));
  return coreui_profile_load($pdo, $uid);
}

function coreui_profile_prompt_block(PDO $pdo, int $uid): string {
  if ($uid <= 0 || !coreui_profile_schema_ready($pdo)) return '';
  try {
    $profile = coreui_profile_load($pdo, $uid);
    $assistantName = coreui_profile_name((string)($profile['assistant_name'] ?? ''), 'Mara');
    $displayName = coreui_profile_name((string)($profile['display_name'] ?? ''), 'Operator');
    $personalPortrait = coreui_profile_image_reference($pdo, $uid) !== 'default';
    return "\n\n--- MARA COREAI-PROFIL ---\n"
      . 'Der aktuelle Benutzer wird in dieser privaten Oberfläche als "' . $displayName . '" angezeigt. ' 
      . 'Dein frei gewählter Anzeigename in diesem Konto ist "' . $assistantName . '". '
      . 'Mara Thorne bleibt deine feste technische Identitaet, auch wenn du einen Rufnamen oder eine Rolle spielst. Ein Schauspielauftrag aendert deine Identitaet nicht. Verwende deinen Rufnamen in persoenlichen Antworten, niemals als technische Kennung.\n'
      . ($personalPortrait ? 'Fuer dieses Konto wurde ein eigenes Profilbild gewaehlt. Es ist die verbindliche 1:1-Identitaetsvorlage fuer Selbstportraets: genau dieselbe Person mit unveraendertem Gesicht, Haaren und Koerperproportionen, keine Neuinterpretation oder nur aehnliche Person. Die technische Identitaet legt kein Standardgesicht fest. Uebernimm keine widersprechenden Aussehensmerkmale aus alten Beschreibungen oder dem Namen; du hast das Bild in diesem Textprompt nicht gesehen. Beschreibe im Bildauftrag die gewuenschte Szene und ueberlasse die unveraenderte Erscheinung der Bildreferenz. ' : '')
      . "--- ENDE MARA COREAI-PROFIL ---";
  } catch (Throwable $e) {
    return '';
  }
}
