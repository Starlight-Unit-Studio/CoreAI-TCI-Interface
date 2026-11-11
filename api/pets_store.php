<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.1: Settings > Pets (after the reference screenshots). A pet is an animated companion
 * in the chat, with animation states and a persistent movable dock.
 *   - the built-in CoreAI pets (original art, images/pets/<id>.png from scripts/build-pet-sprites.py)
 *   - pets a person uploads: one sprite sheet, a row of square frames (migration 034)
 *   - the choice per account (a built-in id, 'u<id>' or 'none'), kept with the personalization;
 *     Mara Thorne is preselected, and a deleted own pet falls back to her
 * The sprite sheet is checked from its own bytes (PNG or WebP header), so no image library decides.
 */

require_once __DIR__ . '/console_personalization_store.php';

const COREUI_PET_MAX_BYTES = 5 * 1024 * 1024;
const COREUI_PET_MAX_FRAMES = 24;
const COREUI_PET_MIN_PX = 16;
const COREUI_PET_MAX_PX = 512;
const COREUI_PET_MAX_OWN = 12;

/** Built-in pets: Mara uses the OpenPet v2 atlas; existing pets use six 32px frames. */
function coreui_pet_catalog(): array {
  return [
    'mara' => ['Mara Thorne', 'Mara begleitet dich im Chat als animierte terranische TCI.'],
    'nova' => ['Nova', 'Ein kleiner Stern, der vor Ideen funkelt.'],
    'komet' => ['Komet', 'Saust mit dir durch lange Aufgaben.'],
    'orbi' => ['Orbi', 'Ein Mini-Planet, der um deine Fragen kreist.'],
    'luna' => ['Luna', 'Ein verschlafener Halbmond für ruhige Nachtschichten.'],
    'quanti' => ['Quanti', 'Ein Quantenwürfel, der zwischen seinen Zuständen flackert.'],
    'glimm' => ['Glimm', 'Ein Glühwürmchen, das dir den Weg leuchtet.'],
    'raki' => ['Raki', 'Eine kleine Rakete, startklar für den nächsten Schritt.'],
    'sputni' => ['Sputni', 'Ein Satellit, der Signale aus dem Terra-Net empfängt.'],
  ];
}

function coreui_pet_builtin_view(): array {
  $out = [];
  foreach (coreui_pet_catalog() as $id => [$name, $description]) {
    $isMara = $id === 'mara';
    $out[] = ['id' => $id, 'name' => $name, 'description' => $description,
      'url' => './images/pets/' . $id . ($isMara ? '.webp' : '.png'),
      'frames' => $isMara ? 8 : 6, 'frame_px' => $isMara ? 192 : 32,
      'sprite_version' => $isMara ? 2 : 0, 'own' => false];
  }
  return $out;
}

/** A stored choice (pure): 'none', a built-in id or 'u<positive id>'; anything else is 'none'. */
function coreui_pet_choice_normalise($value): string {
  $value = is_string($value) ? strtolower(trim($value)) : '';
  if (isset(coreui_pet_catalog()[$value])) return $value;
  if (preg_match('~^u([1-9][0-9]{0,18})$~D', $value) === 1) return $value;
  return 'none';
}

/**
 * What a sprite sheet is, from its bytes (pure): ['mime', 'width', 'height', 'alpha'] or null.
 * PNG: IHDR size and colour type 4 or 6, or a tRNS chunk. WebP: VP8X with the alpha flag, VP8L with
 * its alpha bit; lossy VP8 without VP8X has no transparency.
 */
function coreui_pet_sheet_info(string $bytes): ?array {
  if (strlen($bytes) >= 33 && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && substr($bytes, 12, 4) === 'IHDR') {
    $size = unpack('Nw/Nh', substr($bytes, 16, 8));
    $type = ord($bytes[25]);
    $alpha = in_array($type, [4, 6], true);
    $pos = 8;
    while (!$alpha && $pos + 8 <= strlen($bytes)) {
      $len = unpack('N', substr($bytes, $pos, 4))[1];
      $chunk = substr($bytes, $pos + 4, 4);
      if ($chunk === 'tRNS') $alpha = true;
      if ($chunk === 'IDAT' || $chunk === 'IEND' || $len > strlen($bytes)) break;
      $pos += 12 + $len;
    }
    return ['mime' => 'image/png', 'width' => (int)$size['w'], 'height' => (int)$size['h'], 'alpha' => $alpha];
  }
  if (strlen($bytes) >= 30 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
    $chunk = substr($bytes, 12, 4);
    if ($chunk === 'VP8X') {
      $flags = ord($bytes[20]);
      $w = 1 + (ord($bytes[24]) | (ord($bytes[25]) << 8) | (ord($bytes[26]) << 16));
      $h = 1 + (ord($bytes[27]) | (ord($bytes[28]) << 8) | (ord($bytes[29]) << 16));
      return ['mime' => 'image/webp', 'width' => $w, 'height' => $h, 'alpha' => ($flags & 0x10) !== 0];
    }
    if ($chunk === 'VP8L' && ord($bytes[20]) === 0x2f) {
      $bits = unpack('V', substr($bytes, 21, 4))[1];
      return ['mime' => 'image/webp', 'width' => 1 + ($bits & 0x3fff), 'height' => 1 + (($bits >> 14) & 0x3fff),
        'alpha' => (($bits >> 28) & 1) === 1];
    }
    if ($chunk === 'VP8 ' && strlen($bytes) >= 30) {
      $size = unpack('vw/vh', substr($bytes, 26, 4));
      return ['mime' => 'image/webp', 'width' => $size['w'] & 0x3fff, 'height' => $size['h'] & 0x3fff, 'alpha' => false];
    }
  }
  return null;
}

/**
 * Checks a sprite sheet (pure): transparent PNG or WebP, square frames in one row (2 to 24),
 * 16 to 512 px high, at most 5 MB. Returns ['mime', 'frames', 'frame_px'] or throws the reason.
 */
function coreui_pet_sheet_check(string $bytes): array {
  if (strlen($bytes) === 0) throw new InvalidArgumentException('empty_file');
  if (strlen($bytes) > COREUI_PET_MAX_BYTES) throw new InvalidArgumentException('file_too_large');
  $info = coreui_pet_sheet_info($bytes);
  if ($info === null) throw new InvalidArgumentException('invalid_file_type');
  if (!$info['alpha']) throw new InvalidArgumentException('not_transparent');
  $h = $info['height'];
  $w = $info['width'];
  // 8 columns x 9/11 rows, 192x208 cells. Legacy strips stay backward compatible.
  if ($w === 1536 && ($h === 1872 || $h === 2288)) {
    return ['mime' => $info['mime'], 'frames' => 8, 'frame_px' => 192, 'sprite_version' => $h === 2288 ? 2 : 1];
  }
  if ($h < COREUI_PET_MIN_PX || $h > COREUI_PET_MAX_PX || $w % $h !== 0) throw new InvalidArgumentException('invalid_sheet_size');
  $frames = intdiv($w, $h);
  if ($frames < 2 || $frames > COREUI_PET_MAX_FRAMES) throw new InvalidArgumentException('invalid_frame_count');
  return ['mime' => $info['mime'], 'frames' => $frames, 'frame_px' => $h, 'sprite_version' => 0];
}

function coreui_pet_text($value, int $max): string {
  return coreui_personalization_text($value, $max, false);
}

function coreui_pet_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT id, user_id, name, description, stored_name, mime_type, frames, frame_px FROM stu_user_pets LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_pet_dir(): string {
  return dirname(__DIR__) . '/var/console_media';
}

/** The account's own pets, newest first. */
function coreui_pet_own(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_pet_schema_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT id, name, description, stored_name, mime_type, frames, frame_px, created_at FROM stu_user_pets WHERE user_id = ? ORDER BY id DESC');
  $st->execute([$uid]);
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[] = coreui_pet_own_view($row);
  return $out;
}

function coreui_pet_stored_sprite_version(string $storedName): int {
  if (preg_match('~^pet_[a-f0-9]{32}\\.(png|webp)$~D', $storedName) !== 1) return 0;
  $path = coreui_pet_dir() . '/' . $storedName;
  if (!is_file($path)) return 0;
  $header = @file_get_contents($path, false, null, 0, 64);
  if (!is_string($header)) return 0;
  $info = coreui_pet_sheet_info($header);
  if (!$info || (int)$info['width'] !== 1536) return 0;
  return (int)$info['height'] === 2288 ? 2 : ((int)$info['height'] === 1872 ? 1 : 0);
}

function coreui_pet_own_view(array $row): array {
  $id = (int)$row['id'];
  return ['id' => 'u' . $id, 'name' => (string)$row['name'], 'description' => (string)$row['description'],
    'url' => './api/pets.php?action=sprite&id=' . $id, 'frames' => (int)$row['frames'], 'frame_px' => (int)$row['frame_px'], 'sprite_version' => coreui_pet_stored_sprite_version((string)$row['stored_name']), 'own' => true];
}

function coreui_pet_own_row(PDO $pdo, int $uid, int $id): ?array {
  if ($uid <= 0 || $id <= 0 || !coreui_pet_schema_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT id, user_id, name, description, stored_name, mime_type, frames, frame_px, file_size FROM stu_user_pets WHERE id = ? AND user_id = ? LIMIT 1');
  $st->execute([$id, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/** Stores an uploaded pet (bytes already read from the upload); returns the pet for the page. */
function coreui_pet_add(PDO $pdo, int $uid, string $name, string $description, string $bytes): array {
  if (!coreui_pet_schema_ready($pdo)) throw new RuntimeException('missing_schema_034');
  $name = coreui_pet_text($name, 40);
  if ($name === '') throw new InvalidArgumentException('name_required');
  $description = coreui_pet_text($description, 500);
  $sheet = coreui_pet_sheet_check($bytes);
  $count = $pdo->prepare('SELECT COUNT(*) FROM stu_user_pets WHERE user_id = ?');
  $count->execute([$uid]);
  if ((int)$count->fetchColumn() >= COREUI_PET_MAX_OWN) throw new InvalidArgumentException('too_many_pets');
  $dir = coreui_pet_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('storage_dir_unavailable');
  $stored = 'pet_' . bin2hex(random_bytes(16)) . ($sheet['mime'] === 'image/png' ? '.png' : '.webp');
  if (@file_put_contents($dir . '/' . $stored, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('storage_failed');
  try {
    $pdo->prepare('INSERT INTO stu_user_pets (user_id, name, description, stored_name, mime_type, frames, frame_px, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$uid, $name, $description, $stored, $sheet['mime'], $sheet['frames'], $sheet['frame_px'], strlen($bytes)]);
  } catch (Throwable $e) {
    @unlink($dir . '/' . $stored);
    throw $e;
  }
  return coreui_pet_own_view(coreui_pet_own_row($pdo, $uid, (int)$pdo->lastInsertId()) ?? []);
}

/** The preselected pet (Mara Thorne). */
function coreui_pet_default(): string {
  return (string)coreui_personalization_defaults()['pet'];
}

/** Deletes an own pet with its file; if it was chosen, the preselected pet takes over. */
function coreui_pet_delete(PDO $pdo, int $uid, int $id): bool {
  $row = coreui_pet_own_row($pdo, $uid, $id);
  if ($row === null) return false;
  $pdo->prepare('DELETE FROM stu_user_pets WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
  $stored = (string)$row['stored_name'];
  if (preg_match('~^pet_[a-f0-9]{32}\.(png|webp)$~D', $stored) === 1) @unlink(coreui_pet_dir() . '/' . $stored);
  if (coreui_pet_choice($pdo, $uid) === 'u' . $id) coreui_personalization_update($pdo, $uid, ['pet' => coreui_pet_default()]);
  return true;
}

function coreui_pet_choice(PDO $pdo, int $uid): string {
  return coreui_pet_choice_normalise(coreui_personalization_load($pdo, $uid)['pet'] ?? 'none');
}

/** Chooses a pet ('none', a built-in id or an own pet); returns the stored choice. */
function coreui_pet_choose(PDO $pdo, int $uid, string $choice): string {
  $choice = coreui_pet_choice_normalise($choice);
  if (str_starts_with($choice, 'u') && coreui_pet_own_row($pdo, $uid, (int)substr($choice, 1)) === null) {
    throw new InvalidArgumentException('pet_not_found');
  }
  return coreui_pet_choice_normalise(coreui_personalization_update($pdo, $uid, ['pet' => $choice])['pet'] ?? 'none');
}

/** The pet for the chat, or null for "Pet nicht anzeigen"; an own pet that is gone gives the preselected one. */
function coreui_pet_current(PDO $pdo, int $uid): ?array {
  $choice = coreui_pet_choice($pdo, $uid);
  if ($choice === 'none') return null;
  if (str_starts_with($choice, 'u')) {
    $row = coreui_pet_own_row($pdo, $uid, (int)substr($choice, 1));
    if ($row !== null) return coreui_pet_own_view($row);
    $choice = coreui_pet_default();
  }
  foreach (coreui_pet_builtin_view() as $pet) if ($pet['id'] === $choice) return $pet;
  return null;
}

/** The files of an account's pets for the account deletion (paths below the project). */
function coreui_pet_files(PDO $pdo, int $uid): array {
  $out = [];
  foreach (coreui_pet_own($pdo, $uid) as $pet) {
    $row = coreui_pet_own_row($pdo, $uid, (int)substr($pet['id'], 1));
    if ($row !== null) $out[] = 'var/console_media/' . (string)$row['stored_name'];
  }
  return $out;
}
