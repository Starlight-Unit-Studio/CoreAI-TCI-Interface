<?php
declare(strict_types=1);

/**
 * The Quantum Gen Engine is the existing local image service, not a second model.
 * A generator can only be selected when that service actually reports ready.
 * The automatic image installer has a 31 GiB RAM threshold (scripts/install.sh).
 * A running engine is authoritative even if installed below that default with
 * an explicit operator override. No GPU is required for this CPU-only backend.
 */
require_once __DIR__ . '/image_gen.php';

const COREUI_GENERATOR_IMAGE_AUTO_MIN_GIB = 31;
const COREUI_GENERATOR_IMAGE_NOT_INSTALLED_HARDWARE =
  'Quantum Gen Engine nicht installiert da die Mindesthardwareanforderungen nicht erfüllt werden';

/** Deterministic status calculation; health and hardware may be injected by selftests. */
function coreui_generator_engine_status(
  ?array $hardware = null,
  ?array $health = null,
  ?int $httpCode = null,
  ?array $registry = null
): array {
  if ($hardware === null) {
    $meminfo = (string)@file_get_contents('/proc/meminfo');
    $total = preg_match('~^MemTotal:\\s+(\\d+)\\s+kB~mi', $meminfo, $m) === 1
      ? (int)$m[1] * 1024 : null;
    $hardware = ['mem_total'=>$total];
  }
  $total = is_int($hardware['mem_total'] ?? null) ? $hardware['mem_total'] : null;
  // This is the image engine's existing *default auto-install* threshold.
  $minimum = COREUI_GENERATOR_IMAGE_AUTO_MIN_GIB * 1073741824;
  $eligible = $total === null ? null : $total >= $minimum;
  $registry ??= coreui_image_registry();
  $measured = is_array($registry['measured'] ?? null) ? $registry['measured'] : [];
  $normal = (int)($measured['normal']['peak_rss_mb'] ?? 0);
  $large = (int)($measured['large']['peak_rss_mb'] ?? 0);

  if ($httpCode === null) {
    if (function_exists('curl_init')) {
      [$httpCode, $health] = coreui_image_http('GET', '/health', null, 2);
    } else {
      $httpCode = 0;
    }
  }
  $responding = $httpCode === 200 && is_array($health) && ($health['ok'] ?? false) === true;
  $ready = $responding && ($health['ready'] ?? false) === true;
  $loading = $responding && !$ready && ($health['loading'] ?? false) === true;

  if ($ready) {
    $state = 'ready';
    $message = '';
  } elseif ($loading) {
    $state = 'loading';
    $message = 'Quantum Gen Engine wird vorbereitet.';
  } elseif (!$responding && $eligible === false) {
    $state = 'hardware_insufficient';
    $message = COREUI_GENERATOR_IMAGE_NOT_INSTALLED_HARDWARE;
  } elseif ($responding) {
    $state = 'not_ready';
    $message = 'Quantum Gen Engine ist momentan nicht betriebsbereit.';
  } elseif ($eligible === true) {
    $state = 'unavailable';
    $message = 'Quantum Gen Engine nicht installiert oder nicht erreichbar.';
  } else {
    $state = 'unknown';
    $message = 'Quantum Gen Engine derzeit nicht erreichbar; Hardwarestatus unbekannt.';
  }
  return [
    'ready'=>$ready,
    'installed'=>$responding,
    'state'=>$state,
    'message'=>$message,
    'hardware_eligible'=>$eligible,
    'minimum_ram_gib'=>COREUI_GENERATOR_IMAGE_AUTO_MIN_GIB,
    'normal_peak_rss_mb'=>$normal > 0 ? $normal : null,
    'large_peak_rss_mb'=>$large > 0 ? $large : null,
  ];
}

/** Only non-sensitive state goes to the chat Core menu. */
function coreui_generator_engine_public(array $status): array {
  return [
    'ready'=>($status['ready'] ?? false) === true,
    'state'=>(string)($status['state'] ?? 'unknown'),
    'message'=>(string)($status['message'] ?? ''),
  ];
}
