<?php
declare(strict_types=1);

/**
 * Operator acceptance for the immutable upstream FLUX Non-Commercial License v2.1.
 * The original text is bundled verbatim and displayed locally in a separate modal.
 * A receipt is scoped to this server and the SHA-256 of the bundled license.
 * Host-side auto-installation reads the same receipt through var/cache.
 */
const COREUI_GENERATOR_LICENSE_NAME = 'FLUX Non-Commercial License v2.1';
const COREUI_GENERATOR_LICENSE_URL = 'https://github.com/black-forest-labs/flux2/blob/main/model_licenses/LICENSE-FLUX-NON-COMMERICAL';

function coreui_generator_license_file(): string {
  return dirname(__DIR__) . '/licenses/FLUX-NON-COMMERCIAL-v2.1.txt';
}

function coreui_generator_license_receipt(): string {
  $cache = defined('STU_EMBER_CACHE_DIR') ? trim((string)STU_EMBER_CACHE_DIR) : '';
  return ($cache !== '' ? rtrim($cache, '/') : dirname(__DIR__) . '/var/cache')
    . '/generator-license.json';
}

function coreui_generator_license_hash(): string {
  $file = coreui_generator_license_file();
  if (is_link($file) || !is_file($file)) throw new RuntimeException('generator_license_unavailable');
  $sha = @hash_file('sha256', $file);
  if (!is_string($sha) || strlen($sha) !== 64) throw new RuntimeException('generator_license_unavailable');
  return $sha;
}

function coreui_generator_license_accepted(): bool {
  $path = coreui_generator_license_receipt();
  if (is_link($path) || !is_file($path)) return false;
  $raw = @file_get_contents($path);
  $receipt = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($receipt) || ($receipt['accepted'] ?? null) !== true
      || !is_string($receipt['license_sha256'] ?? null)) return false;
  try {
    return hash_equals(coreui_generator_license_hash(), $receipt['license_sha256']);
  } catch (RuntimeException $e) {
    return false;
  }
}

function coreui_generator_license_info(bool $includeText = false): array {
  $out = ['name'=>COREUI_GENERATOR_LICENSE_NAME, 'url'=>COREUI_GENERATOR_LICENSE_URL,
    'sha256'=>coreui_generator_license_hash(), 'accepted'=>coreui_generator_license_accepted()];
  if ($includeText) {
    $text = @file_get_contents(coreui_generator_license_file());
    if (!is_string($text) || $text === '') throw new RuntimeException('generator_license_unavailable');
    $out['text'] = $text;
  }
  return $out;
}

function coreui_generator_license_accept(int $uid, string $claimedHash): void {
  $digest = coreui_generator_license_hash();
  if (!preg_match('~^[0-9a-f]{64}$~', $claimedHash) || !hash_equals($digest, $claimedHash)) {
    throw new RuntimeException('generator_license_version_changed');
  }
  $path = coreui_generator_license_receipt();
  $dir = dirname($path);
  if (!is_dir($dir) || is_link($dir) || is_link($path)) throw new RuntimeException('generator_license_unwritable');
  $temp = @tempnam($dir, '.generator-license-');
  if (!is_string($temp)) throw new RuntimeException('generator_license_unwritable');
  try {
    $json = json_encode(['accepted'=>true, 'license_sha256'=>$digest, 'accepted_by'=>$uid,
      'accepted_at'=>gmdate('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || @file_put_contents($temp, $json . "\n", LOCK_EX) === false
        || !@chmod($temp, 0640) || !@rename($temp, $path)) {
      throw new RuntimeException('generator_license_unwritable');
    }
  } finally {
    if (is_file($temp)) @unlink($temp);
  }
}

// Individual consent is mandatory for every CoreAI account. The legacy host
// receipt remains provisioning metadata only, never a user authorization.
function coreui_generator_license_user_accepted(PDO $pdo, int $uid): bool {
  if ($uid <= 0) return false;
  try {
    $sha = coreui_generator_license_hash();
    $st = $pdo->prepare('SELECT license_sha256 FROM stu_coreui_pgen_user_licenses WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $stored = $st->fetchColumn();
    return is_string($stored) && hash_equals($sha, $stored);
  } catch (Throwable $e) {
    // Migration missing or unavailable: fail closed, never inherit other users' consent.
    return false;
  }
}

function coreui_generator_license_user_accept(PDO $pdo, int $uid, string $claimedHash): void {
  if ($uid <= 0) throw new RuntimeException('license_accept_required');
  $sha = coreui_generator_license_hash();
  if (!preg_match('~^[0-9a-f]{64}$~D', $claimedHash) || !hash_equals($sha, $claimedHash))
    throw new RuntimeException('generator_license_version_changed');
  try {
    $st = $pdo->prepare('INSERT INTO stu_coreui_pgen_user_licenses (user_id,license_sha256,accepted_at)'
      . ' VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE'
      . ' license_sha256=VALUES(license_sha256),accepted_at=UTC_TIMESTAMP()');
    $st->execute([$uid, $sha]);
  } catch (PDOException $e) {
    throw new RuntimeException('generator_license_unwritable', 0, $e);
  }
}

function coreui_generator_license_user_info(PDO $pdo, int $uid, bool $includeText = false): array {
  $info = coreui_generator_license_info($includeText);
  $info['accepted'] = coreui_generator_license_user_accepted($pdo, $uid);
  unset($info['accepted_by']);
  return $info;
}
