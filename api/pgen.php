<?php
declare(strict_types=1);

/** pGen is the image model profile, independent of Ollama text Cores. */
require_once __DIR__ . '/image_gen.php';
require_once __DIR__ . '/core_generator_license.php';

const COREUI_PGEN_STANDARD = 'flux2-klein-4b';
const COREUI_PGEN_QUALITY = 'flux2-klein-9b';
const COREUI_PGEN_4B_STANDARD = 'flux2-klein-4b-standard';
const COREUI_PGEN_4B_MAGIC = 'flux2-klein-4b-magic';
const COREUI_PGEN_9B_STANDARD = 'flux2-klein-9b-standard';
const COREUI_PGEN_9B_MAGIC = 'flux2-klein-9b-magic';

function coreui_pgen_file(): string {
  $cache = defined('STU_EMBER_CACHE_DIR') ? (string)STU_EMBER_CACHE_DIR
    : dirname(__DIR__) . '/var/cache';
  return rtrim($cache, '/') . '/pgen-profile.json';
}

/** Public choices are complete profiles. Encoders stay internal to the image worker. */
function coreui_pgen_valid(string $profile): bool {
  return in_array($profile, [
    COREUI_PGEN_4B_STANDARD, COREUI_PGEN_4B_MAGIC,
    COREUI_PGEN_9B_STANDARD, COREUI_PGEN_9B_MAGIC,
  ], true);
}

function coreui_pgen_model(string $profile): string {
  return in_array($profile, [COREUI_PGEN_9B_STANDARD, COREUI_PGEN_9B_MAGIC], true)
    ? COREUI_PGEN_QUALITY : COREUI_PGEN_STANDARD;
}

function coreui_pgen_mode(string $profile): string {
  return in_array($profile, [COREUI_PGEN_4B_MAGIC, COREUI_PGEN_9B_MAGIC], true)
    ? 'magic' : 'standard';
}

function coreui_pgen_selected(): string {
  $file = coreui_pgen_file();
  if (is_link($file) || !is_file($file)) return COREUI_PGEN_4B_STANDARD;
  $data = json_decode((string)@file_get_contents($file), true);
  $profile = is_array($data) ? ($data['model'] ?? '') : '';
  if (!is_string($profile)) return COREUI_PGEN_4B_STANDARD;
  // Existing installs only stored the diffusion size: migrate without
  // silently turning a previously licensed auto-selected encoder into Magic.
  if ($profile === COREUI_PGEN_STANDARD) return COREUI_PGEN_4B_STANDARD;
  if ($profile === COREUI_PGEN_QUALITY) return COREUI_PGEN_9B_STANDARD;
  return coreui_pgen_valid($profile) ? $profile : COREUI_PGEN_4B_STANDARD;
}

function coreui_pgen_hardware(?array $hw = null): array {
  if ($hw === null && function_exists('coreui_core_install_hardware')) {
    $hw = coreui_core_install_hardware();
  }
  $hw ??= [];
  $total = is_int($hw['mem_total'] ?? null) ? $hw['mem_total'] : null;
  $available = is_int($hw['mem_available'] ?? null) ? $hw['mem_available'] : null;
  $disk = is_int($hw['disk_free'] ?? null) ? $hw['disk_free'] : null;
  $minTotal = 31 * 1073741824; // same default as the Quantum Gen Engine installer
  $minAvailable = (22605 + 1024) * 1048576; // observed 9B 1024 peak + reserve
  $group = json_decode((string)@file_get_contents(dirname(__DIR__) . '/tools/image_spike/models.json'), true);
  $specs = $group['groups'][COREUI_PGEN_QUALITY]['files'] ?? [];
  $weights = is_array($specs) ? array_sum(array_map(
    static fn($f): int => is_array($f) ? (int)($f['size'] ?? 0) : 0, $specs)) : 0;
  $minDisk = $weights + 2 * 1073741824;
  return ['ok'=>($total === null || $total >= $minTotal)
      && ($available === null || $available >= $minAvailable)
      && ($disk === null || $disk >= $minDisk),
    'required_ram_gib'=>31, 'observed_peak_1024_mb'=>22605];
}

function coreui_pgen_status(?array $health = null, ?array $hardware = null, ?PDO $pdo = null, int $uid = 0): array {
  if ($health === null) {
    [$code, $body] = coreui_image_http('GET', '/health', null, 3);
    $health = $code === 200 && is_array($body) ? $body : [];
  }
  $ready = ($health['ok'] ?? false) === true && ($health['ready'] ?? false) === true;
  $available = is_array($health['pgen'] ?? null) ? $health['pgen'] : [];
  $ponpoke = is_array($health['ponpoke'] ?? null) ? $health['ponpoke'] : [];
  $approved = $pdo instanceof PDO && $uid > 0
    && coreui_generator_license_user_accepted($pdo, $uid);
  $hw = coreui_pgen_hardware($hardware);
  $models = [];
  foreach ([
    [COREUI_PGEN_4B_STANDARD, 'pGen Engine Standard (4B)', COREUI_PGEN_STANDARD, 'standard', false, 67, 14.4],
    [COREUI_PGEN_4B_MAGIC, 'pGen Engine Magic (4B)', COREUI_PGEN_STANDARD, 'magic', true, 67, 14.4],
    [COREUI_PGEN_9B_STANDARD, 'pGen Engine Standard (9B)', COREUI_PGEN_QUALITY, 'standard', true, 137, 22.6],
    [COREUI_PGEN_9B_MAGIC, 'pGen Engine Magic (9B)', COREUI_PGEN_QUALITY, 'magic', true, 137, 22.6],
  ] as [$id, $label, $base, $mode, $licenseRequired, $seconds, $ram]) {
    $baseState = is_array($available[$base] ?? null) ? $available[$base] : [];
    $magicState = is_array($ponpoke[$base] ?? null) ? $ponpoke[$base] : [];
    $magicReady = ($magicState['ready'] ?? false) === true;
    $physicalReady = $ready && ($baseState['ready'] ?? false) === true;
    $modelReady = $physicalReady && ($mode === 'standard' || $magicReady);
    $models[] = [
      'id'=>$id, 'label'=>$label, 'base_model'=>$base, 'mode'=>$mode,
      'license_required'=>$licenseRequired,
      'license'=>$base === COREUI_PGEN_QUALITY || $mode === 'magic'
        ? 'FLUX Non-Commercial License v2.1' : 'Apache-2.0',
      'ready'=>$modelReady && (!$licenseRequired || $approved),
      'installed'=>($baseState['installed'] ?? false) === true
        && ($mode === 'standard' || $magicReady),
      'loading'=>($baseState['loading'] ?? false) === true
        || ($mode === 'magic' && !$magicReady && ($ponpoke['loading'] ?? false) === true),
      'prepare_required'=>$mode === 'magic' && !$magicReady,
      'error'=>mb_substr((string)($baseState['error'] ?? ''), 0, 180),
      'seconds_512'=>$seconds, 'ram_1024_gb'=>$ram,
    ];
  }
  return [
    'active'=>coreui_pgen_selected(),
    'engine_ready'=>$ready,
    'license_accepted'=>$approved,
    'can_install_9b'=>$hw['ok'],
    'required_ram_gib'=>$hw['required_ram_gib'],
    'models'=>$models,
  ];
}

function coreui_pgen_save(string $profile, array $status, int $uid): void {
  if (!coreui_pgen_valid($profile)) throw new InvalidArgumentException('pgen_invalid_model');
  if (!$status['engine_ready']) throw new RuntimeException('pgen_engine_unavailable');
  foreach ($status['models'] as $entry) {
    if ($entry['id'] !== $profile) continue;
    if (coreui_pgen_model($profile) === COREUI_PGEN_QUALITY && !$status['can_install_9b'])
      throw new RuntimeException('pgen_hardware_insufficient');
    if (($entry['license_required'] ?? false) && !$status['license_accepted'])
      throw new RuntimeException('license_accept_required');
    if (!$entry['ready']) throw new RuntimeException(
      coreui_pgen_mode($profile) === 'magic' && ($entry['prepare_required'] ?? false)
        ? 'pgen_magic_unavailable' : 'pgen_model_unavailable');
    $file = coreui_pgen_file();
    $dir = dirname($file);
    if (!is_dir($dir) || is_link($dir) || is_link($file)) throw new RuntimeException('pgen_save_unavailable');
    $tmp = @tempnam($dir, '.pgen-profile-');
    if (!is_string($tmp)) throw new RuntimeException('pgen_save_unavailable');
    try {
      $json = json_encode(['model'=>$profile, 'changed_by'=>$uid,
          'changed_at'=>gmdate('c')], JSON_UNESCAPED_SLASHES);
      if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false
          || !@chmod($tmp, 0640) || !@rename($tmp, $file))
        throw new RuntimeException('pgen_save_unavailable');
    } finally {
      if (is_file($tmp)) @unlink($tmp);
    }
    return;
  }
  throw new InvalidArgumentException('pgen_invalid_model');
}
