<?php
declare(strict_types=1);

require_once __DIR__ . '/image_reference_consistency.php';

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
  if ($slot === 'user') {
    // Canvas dimensions are rounded for display; tolerate <1% rounding drift,
    // then always produce an exactly square server-side crop.
    if (abs($w - $h) > max(2, (int)round(max($w, $h) * 0.01))) {
      throw new InvalidArgumentException('avatar_crop_must_be_square');
    }
    $w = $h = min($w, $h);
  }
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
    3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90, default => 0,
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

/** Assistant references retain their selected aspect ratio. User avatars stay square. */
function coreui_profile_render_avatar(GdImage $src, string $slot, ?array $crop = null): GdImage {
  $width = imagesx($src);
  $height = imagesy($src);
  if ($crop !== null) {
    $srcX = (int)$crop['x'];
    $srcY = (int)$crop['y'];
    $sourceWidth = (int)$crop['w'];
    $sourceHeight = (int)$crop['h'];
  } elseif ($slot === 'assistant') {
    $srcX = $srcY = 0;
    $sourceWidth = $width;
    $sourceHeight = $height;
  } else {
    $sourceWidth = $sourceHeight = min($width, $height);
    $srcX = (int)floor(($width - $sourceWidth) / 2);
    $srcY = (int)floor(($height - $sourceHeight) / 2);
  }
  if ($slot === 'assistant') {
    // The existing image worker accepts a private PNG reference up to 4 MB.
    // Keep the current safe 1024px limit while retaining original source bytes.
    $scale = min(1, 1024 / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
  } else {
    $targetWidth = $targetHeight = min(512, $sourceWidth, $sourceHeight);
  }
  $dst = imagecreatetruecolor($targetWidth, $targetHeight);
  imagealphablending($dst, false);
  imagesavealpha($dst, true);
  imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
  imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY,
    $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
  return $dst;
}

/**
 * Upload a new image, or re-crop the original already owned by the same account.
 * The uploaded bytes are never edited or lost. The image-worker-facing UUID.png
 * and the crop metadata are separate, replaceable derivatives.
 */
function coreui_profile_store_avatar(PDO $pdo, int $uid, string $slot,
    ?array $file, mixed $cropRaw = null, ?string $expectedUuid = null): array {
  if ($uid <= 0 || !in_array($slot, ['user', 'assistant'], true)) {
    throw new InvalidArgumentException('invalid_slot');
  }
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $recrop = $file === null;
  if ($recrop) {
    if ($expectedUuid === null || preg_match('~^[a-f0-9]{32}$~D', $expectedUuid) !== 1) {
      throw new InvalidArgumentException('avatar_changed');
    }
    $existing = coreui_profile_media_record($pdo, $uid, $slot);
    if ($existing === null || $existing['uuid'] !== $expectedUuid) {
      throw new InvalidArgumentException('avatar_changed');
    }
    $savedSource = coreui_profile_source_path($expectedUuid);
    $sourcePath = $savedSource['path'] ?? (string)$existing['path'];
    $oldNameSt = $pdo->prepare('SELECT original_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? LIMIT 1');
    $oldNameSt->execute([$uid, $slot]);
    $originalName = (string)($oldNameSt->fetchColumn() ?: 'profile-original');
  } else {
    $size = (int)($file['size'] ?? 0);
    $sourcePath = (string)($file['tmp_name'] ?? '');
    if ($size <= 0 || $sourcePath === '' || !is_uploaded_file($sourcePath)) {
      throw new InvalidArgumentException('invalid_upload');
    }
    if ($size > 4 * 1024 * 1024) throw new InvalidArgumentException('avatar_too_large');
    $originalName = trim((string)preg_replace('~[\\x00-\\x1F\\x7F/\\\\\\\\]+~u', '', (string)($file['name'] ?? 'avatar')));
    if ($originalName === '') $originalName = 'avatar';
  }
  $raw = @file_get_contents($sourcePath);
  if (!is_string($raw) || $raw === '' || strlen($raw) > 4 * 1024 * 1024) {
    throw new InvalidArgumentException('avatar_too_large');
  }
  $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
  if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    throw new InvalidArgumentException('avatar_format_not_allowed');
  }
  // Reject oversized dimensions BEFORE GD expands a compressed image in RAM.
  $info = @getimagesizefromstring($raw);
  $rawWidth = (int)($info[0] ?? 0);
  $rawHeight = (int)($info[1] ?? 0);
  if ($rawWidth < 32 || $rawHeight < 32 || $rawWidth > 8000 || $rawHeight > 8000
      || ($rawWidth * $rawHeight) > 40000000) {
    throw new InvalidArgumentException('avatar_dimensions_invalid');
  }
  $src = @imagecreatefromstring($raw);
  if (!($src instanceof GdImage)) throw new InvalidArgumentException('avatar_decode_failed');
  $src = coreui_profile_orient_jpeg($src, $sourcePath, $mime);
  $width = imagesx($src);
  $height = imagesy($src);
  try {
    $crop = coreui_profile_crop_pixels($cropRaw, $width, $height, $slot);
    if ($recrop && $crop === null) throw new InvalidArgumentException('avatar_crop_invalid');
    $dst = coreui_profile_render_avatar($src, $slot, $crop);
  } finally {
    imagedestroy($src);
  }
  $targetWidth = imagesx($dst);
  $targetHeight = imagesy($dst);
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
  $sourceName = $uuid . '.source.' . ($mime === 'image/jpeg' ? 'jpg' : 'png');
  $metaName = $uuid . '.crop.json';
  $destPath = $dir . '/' . $storedName;
  $sourceDest = $dir . '/' . $sourceName;
  $metaDest = $dir . '/' . $metaName;
  $outputTmp = $dir . '/.' . $storedName . '.tmp';
  $sourceTmp = $dir . '/.' . $sourceName . '.tmp';
  $metaTmp = $dir . '/.' . $metaName . '.tmp';
  $meta = json_encode([
    'crop'=>$crop['normalized'] ?? null,
    'source_width'=>$width,
    'source_height'=>$height,
    'mime'=>$mime,
  ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $oldStored = '';
  try {
    if (!imagepng($dst, $outputTmp, 6) || !@rename($outputTmp, $destPath)) {
      throw new RuntimeException('avatar_write_failed');
    }
    @chmod($destPath, 0640);
    $finalSize = (int)(@filesize($destPath) ?: 0);
    if ($finalSize < 1 || $finalSize > 4 * 1024 * 1024) {
      throw new RuntimeException('avatar_processed_too_large');
    }
    if (@file_put_contents($sourceTmp, $raw, LOCK_EX) !== strlen($raw)
        || !@rename($sourceTmp, $sourceDest)
        || @file_put_contents($metaTmp, $meta, LOCK_EX) !== strlen($meta)
        || !@rename($metaTmp, $metaDest)) {
      throw new RuntimeException('avatar_source_write_failed');
    }
    @chmod($sourceDest, 0640);
    @chmod($metaDest, 0640);
    if (function_exists('mb_substr')) $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
    else $originalName = substr($originalName, 0, 255);

    $pdo->beginTransaction();
    $old = $pdo->prepare('SELECT uuid, stored_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? FOR UPDATE');
    $old->execute([$uid, $slot]);
    $previous = $old->fetch(PDO::FETCH_ASSOC);
    if ($recrop && (!is_array($previous) || ($previous['uuid'] ?? '') !== $expectedUuid)) {
      throw new InvalidArgumentException('avatar_changed');
    }
    $oldStored = (string)($previous['stored_name'] ?? '');
    $st = $pdo->prepare(
      'INSERT INTO stu_coreui_profile_media '
      . '(uuid, user_id, slot, original_name, stored_name, mime_type, file_size, width_px, height_px, created_at) '
      . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
      . 'ON DUPLICATE KEY UPDATE uuid = VALUES(uuid), original_name = VALUES(original_name), '
      . 'stored_name = VALUES(stored_name), mime_type = VALUES(mime_type), file_size = VALUES(file_size), '
      . 'width_px = VALUES(width_px), height_px = VALUES(height_px), created_at = NOW()'
    );
    $st->execute([$uuid, $uid, $slot, $originalName, $storedName, 'image/png',
      $finalSize, $targetWidth, $targetHeight]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ([$destPath, $sourceDest, $metaDest, $outputTmp, $sourceTmp, $metaTmp] as $path) @unlink($path);
    throw $e;
  } finally {
    imagedestroy($dst);
  }
  if ($oldStored !== '' && $oldStored !== $storedName) coreui_profile_media_cleanup($oldStored);
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
      . 'Mara Thorne bleibt deine feste technische Identitaet, auch wenn du einen Rufnamen oder eine Rolle spielst. Ein Schauspielauftrag aendert deine Identitaet nicht. Verwende deinen Rufnamen in persoenlichen Antworten, niemals als technische Kennung.\\n'
      . coreui_tci_self_portrait_rule($personalPortrait) . ' '
      . "--- ENDE MARA COREAI-PROFIL ---";
  } catch (Throwable $e) {
    return '';
  }
}
