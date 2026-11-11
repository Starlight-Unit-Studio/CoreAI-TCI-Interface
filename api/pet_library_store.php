<?php
declare(strict_types=1);

/**
 * Curated, system-wide OpenPet library.
 *
 * A community gallery entry is NOT a redistribution license. No uploaded
 * sprites are bundled or published automatically. Only an owner-approved
 * .openpet.zip with a documented asset license and attribution is accepted.
 *
 * Files live in CoreAI's persistent private media directory and are served
 * through the authenticated pets API. Existing built-ins and user-owned pets
 * are untouched. No extra database table is required.
 */

const COREUI_OPENPET_ZIP_MAX_BYTES = 6 * 1024 * 1024;
const COREUI_OPENPET_MANIFEST_MAX_BYTES = 512 * 1024;

function coreui_openpet_library_dir(): string {
  $override = getenv('COREUI_PET_LIBRARY_DIR');
  return is_string($override) && $override !== ''
    ? $override : dirname(__DIR__) . '/var/console_media/pet_library';
}

function coreui_openpet_library_id(string $value): string {
  $slug = strtolower(trim($value));
  if (preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) !== 1 || strlen($slug) > 50 || strlen($slug) < 2) {
    throw new InvalidArgumentException('invalid_pet_id');
  }
  return 'op-' . $slug;
}

function coreui_openpet_library_read(): array {
  $path = coreui_openpet_library_dir() . '/manifest.json';
  if (!is_file($path) || !is_readable($path) || filesize($path) > COREUI_OPENPET_MANIFEST_MAX_BYTES) return [];
  $decoded = json_decode((string)@file_get_contents($path), true);
  if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) return [];
  $valid = [];
  foreach ($decoded['entries'] as $id => $entry) {
    if (!is_string($id) || !preg_match('~^op-[a-z0-9]+(?:-[a-z0-9]+)*$~D', $id)
        || strlen($id) > 53 || !is_array($entry)) continue;
    $filename = (string)($entry['file'] ?? '');
    if (preg_match('~^[a-f0-9]{64}\.webp$~D', $filename) !== 1) continue;
    $path = coreui_openpet_library_dir() . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) continue;
    if (!in_array((int)($entry['sprite_version'] ?? 0), [1, 2], true)) continue;
    if (empty($entry['evidence_url']) || empty($entry['rights_holder']) || empty($entry['license'])) continue;
    $valid[$id] = $entry;
  }
  return $valid;
}

/** This returns only owner-reviewed entries, never unreviewed archive items. */
function coreui_openpet_library_catalog(): array {
  $out = [];
  foreach (coreui_openpet_library_read() as $id => $entry) {
    $out[$id] = [(string)$entry['name'], (string)$entry['description']];
  }
  return $out;
}

function coreui_openpet_library_view(): array {
  $out = [];
  foreach (coreui_openpet_library_read() as $id => $e) {
    $out[] = [
      'id'=>$id, 'name'=>(string)$e['name'], 'description'=>(string)$e['description'],
      'url'=>'./api/pets.php?action=library_sprite&id=' . rawurlencode($id),
      'frames'=>8, 'frame_px'=>192, 'sprite_version'=>(int)$e['sprite_version'],
      'own'=>false, 'library'=>true,
      'attribution'=>(string)$e['rights_holder'], 'license'=>(string)$e['license'],
      'source_url'=>(string)$e['source_url'], 'evidence_url'=>(string)$e['evidence_url'],
    ];
  }
  return $out;
}

function coreui_openpet_library_asset(string $id): ?string {
  $entry = coreui_openpet_library_read()[$id] ?? null;
  if (!is_array($entry)) return null;
  $dir = realpath(coreui_openpet_library_dir());
  if (!is_string($dir)) return null;
  $file = realpath($dir . '/' . $entry['file']);
  if (!is_string($file) || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) return null;
  return $file;
}

/** Validate the license record before publishing any sprite to all accounts. */
function coreui_openpet_library_license(array $metadata): array {
  $license = strtoupper(trim((string)($metadata['license'] ?? '')));
  $allowed = ['CC0-1.0', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'MIT', 'APACHE-2.0', 'BSD-3-CLAUSE'];
  if (!in_array($license, $allowed, true)) throw new InvalidArgumentException('unverified_license');
  $holder = trim((string)($metadata['rights_holder'] ?? ''));
  if (mb_strlen($holder) < 2 || mb_strlen($holder) > 120) throw new InvalidArgumentException('rights_holder_required');
  $source = trim((string)($metadata['source_url'] ?? ''));
  $proof = trim((string)($metadata['evidence_url'] ?? ''));
  foreach ([$source, $proof] as $url) {
    if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false
        || parse_url($url, PHP_URL_SCHEME) !== 'https') {
      throw new InvalidArgumentException('license_proof_required');
    }
  }
  if (($metadata['rights_confirmed'] ?? '') !== 'yes') throw new InvalidArgumentException('rights_confirmation_required');
  return ['license'=>$license, 'rights_holder'=>$holder, 'source_url'=>$source, 'evidence_url'=>$proof];
}

/**
 * Accept exactly the OpenPet data format, never extract paths from the ZIP.
 * The caller must verify the current user is the account owner and validate
 * the uploaded file before passing its private temporary path.
 */
function coreui_openpet_library_import(string $zipPath, int $size, array $metadata): array {
  if ($size <= 0 || $size > COREUI_OPENPET_ZIP_MAX_BYTES || !is_file($zipPath)) {
    throw new InvalidArgumentException('file_too_large');
  }
  if (!class_exists(ZipArchive::class)) throw new RuntimeException('zip_extension_missing');
  $rights = coreui_openpet_library_license($metadata);
  $zip = new ZipArchive();
  if ($zip->open($zipPath) !== true) throw new InvalidArgumentException('invalid_openpet_zip');
  try {
    if ($zip->numFiles !== 2) throw new InvalidArgumentException('invalid_openpet_zip');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $stat = $zip->statIndex($i);
      $entry = is_array($stat) ? (string)($stat['name'] ?? '') : '';
      if (!in_array($entry, ['pet.json', 'spritesheet.webp'], true)
          || in_array($entry, $names, true)) throw new InvalidArgumentException('invalid_openpet_zip');
      $limit = $entry === 'pet.json' ? 65536 : COREUI_PET_MAX_BYTES;
      if (!is_array($stat) || (int)$stat['size'] <= 0 || (int)$stat['size'] > $limit) {
        throw new InvalidArgumentException('file_too_large');
      }
      $names[] = $entry;
    }
    if (count($names) !== 2) throw new InvalidArgumentException('invalid_openpet_zip');
    $json = $zip->getFromName('pet.json');
    $bytes = $zip->getFromName('spritesheet.webp');
    if (!is_string($json) || !is_string($bytes)) throw new InvalidArgumentException('invalid_openpet_zip');
  } finally { $zip->close(); }

  $pet = json_decode($json, true);
  if (!is_array($pet)) throw new InvalidArgumentException('invalid_openpet_manifest');
  $id = coreui_openpet_library_id((string)($pet['id'] ?? ''));
  $name = coreui_pet_text($pet['displayName'] ?? '', 40);
  $description = coreui_pet_text($pet['description'] ?? '', 500);
  if ($name === '') throw new InvalidArgumentException('name_required');
  $sheet = coreui_pet_sheet_check($bytes);
  if ($sheet['mime'] !== 'image/webp' || !in_array($sheet['sprite_version'], [1,2], true)) {
    throw new InvalidArgumentException('invalid_openpet_atlas');
  }
  $directory = coreui_openpet_library_dir();
  if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('storage_dir_unavailable');
  }
  $lock = @fopen($directory . '/library.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('storage_lock_failed');
  try {
    $current = coreui_openpet_library_read();
    if (isset($current[$id])) throw new InvalidArgumentException('pet_already_installed');
    $file = hash('sha256', $bytes) . '.webp';
    if (!is_file($directory . '/' . $file) &&
        @file_put_contents($directory . '/' . $file, $bytes, LOCK_EX) !== strlen($bytes)) {
      throw new RuntimeException('storage_failed');
    }
    $entry = [
      'name'=>$name, 'description'=>$description, 'file'=>$file,
      'sprite_version'=>(int)$sheet['sprite_version'], 'imported_at'=>gmdate('c'),
    ] + $rights;
    $current[$id] = $entry;
    $json = json_encode(['version'=>1, 'entries'=>$current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!is_string($json) || strlen($json) > COREUI_OPENPET_MANIFEST_MAX_BYTES) {
      throw new RuntimeException('library_manifest_full');
    }
    $temp = $directory . '/manifest-' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $directory . '/manifest.json')) {
      @unlink($temp);
      throw new RuntimeException('storage_failed');
    }
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
  foreach (coreui_openpet_library_view() as $view) if ($view['id'] === $id) return $view;
  throw new RuntimeException('library_publication_failed');
}

/** Owner-only unpublish; never removes existing account-owned pet files. */
function coreui_openpet_library_remove(string $id): bool {
  if (preg_match('~^op-[a-z0-9]+(?:-[a-z0-9]+)*$~D', $id) !== 1) return false;
  $directory = coreui_openpet_library_dir();
  if (!is_dir($directory)) return false;
  $lock = @fopen($directory . '/library.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX)) return false;
  try {
    $current = coreui_openpet_library_read();
    if (!isset($current[$id])) return false;
    unset($current[$id]);
    $json = json_encode(['version'=>1, 'entries'=>$current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = $directory . '/manifest-' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $directory . '/manifest.json')) {
      @unlink($temp);
      throw new RuntimeException('storage_failed');
    }
    return true;
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
