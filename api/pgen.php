<?php
declare(strict_types=1);

/** pGen is the image model profile, independent of Ollama text Cores. */
require_once __DIR__ . '/image_gen.php';
require_once __DIR__ . '/core_generator_license.php';

const COREUI_PGEN_STANDARD = 'flux2-klein-4b';
const COREUI_PGEN_QUALITY = 'flux2-klein-9b';

function coreui_pgen_file(): string {
  $cache = defined('STU_EMBER_CACHE_DIR') ? (string)STU_EMBER_CACHE_DIR
    : dirname(__DIR__) . '/var/cache';
  return rtrim($cache, '/') . '/pgen-profile.json';
}

function coreui_pgen_valid(string $model): bool {
  return in_array($model, [COREUI_PGEN_STANDARD, COREUI_PGEN_QUALITY], true);
}

function coreui_pgen_selected(): string {
  $file = coreui_pgen_file();
  if (is_link($file) || !is_file($file)) return COREUI_PGEN_STANDARD;
  $data = json_decode((string)@file_get_contents($file), true);
  $model = is_array($data) ? ($data['model'] ?? '') : '';
  if (!is_string($model) || !coreui_pgen_valid($model)) return COREUI_PGEN_STANDARD;
  // The server shares model weights; per-account consent is checked at each image job.
  return $model;
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
  $four = is_array($available[COREUI_PGEN_STANDARD] ?? null) ? $available[COREUI_PGEN_STANDARD] : [];
  $nine = is_array($available[COREUI_PGEN_QUALITY] ?? null) ? $available[COREUI_PGEN_QUALITY] : [];
  $approved = $pdo instanceof PDO && $uid > 0
    && coreui_generator_license_user_accepted($pdo, $uid);
  $hw = coreui_pgen_hardware($hardware);
  return [
    'active'=>coreui_pgen_selected(),
    'engine_ready'=>$ready,
    'license_accepted'=>$approved,
    'can_install_9b'=>$hw['ok'],
    'required_ram_gib'=>$hw['required_ram_gib'],
    'models'=>[
      ['id'=>COREUI_PGEN_STANDARD, 'label'=>'FLUX.2 Standard (4B)',
       'license'=>'Apache-2.0', 'ready'=>$ready && ($four['ready'] ?? false) === true,
       'installed'=>$ready && ($four['installed'] ?? false) === true,
       'loading'=>($four['loading'] ?? false) === true,
       'seconds_512'=>67, 'ram_1024_gb'=>14.4],
      ['id'=>COREUI_PGEN_QUALITY, 'label'=>'FLUX.2 9B',
       'license'=>'FLUX Non-Commercial License v2.1',
       'ready'=>$ready && $approved && ($nine['ready'] ?? false) === true,
       'installed'=>($nine['installed'] ?? false) === true,
       'loading'=>($nine['loading'] ?? false) === true,
       'error'=>mb_substr((string)($nine['error'] ?? ''), 0, 180),
       'seconds_512'=>137, 'ram_1024_gb'=>22.6]
    ],
  ];
}

function coreui_pgen_save(string $model, array $status, int $uid): void {
  if (!coreui_pgen_valid($model)) throw new InvalidArgumentException('pgen_invalid_model');
  if (!$status['engine_ready']) throw new RuntimeException('pgen_engine_unavailable');
  foreach ($status['models'] as $entry) {
    if ($entry['id'] !== $model) continue;
    if ($model === COREUI_PGEN_QUALITY && !$status['can_install_9b'])
      throw new RuntimeException('pgen_hardware_insufficient');
    if ($model === COREUI_PGEN_QUALITY && !$status['license_accepted'])
      throw new RuntimeException('license_accept_required');
    if (!$entry['ready']) throw new RuntimeException('pgen_model_unavailable');
    $file = coreui_pgen_file();
    $dir = dirname($file);
    if (!is_dir($dir) || is_link($dir) || is_link($file)) throw new RuntimeException('pgen_save_unavailable');
    $tmp = @tempnam($dir, '.pgen-profile-');
    if (!is_string($tmp)) throw new RuntimeException('pgen_save_unavailable');
    try {
      $json = json_encode(['model'=>$model, 'changed_by'=>$uid,
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
