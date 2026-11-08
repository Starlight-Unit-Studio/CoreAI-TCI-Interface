<?php
declare(strict_types=1);

/**
 * Read-only Ollama model catalog for CoreAI: TCI Interface.
 *
 * The endpoint is derived exclusively from the operator-controlled Ollama
 * chat URL. User input can never select a host or protocol.
 */

function coreui_model_name_valid(string $name): bool {
  return $name !== ''
    && strlen($name) <= 160
    && preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]*$~D', $name) === 1;
}

function coreui_ollama_tags_url(): string {
  $chatUrl = defined('STU_EMBER_OLLAMA_URL')
    ? trim((string)STU_EMBER_OLLAMA_URL)
    : 'http://127.0.0.1:11434/api/chat';
  if (!preg_match('~^https?://~i', $chatUrl)) throw new RuntimeException('model_catalog_invalid_url');
  $tagsUrl = preg_replace('~/api/chat/?(?:\?.*)?$~i', '/api/tags', $chatUrl, 1, $count);
  if ($count !== 1 || !is_string($tagsUrl)) throw new RuntimeException('model_catalog_invalid_url');
  return $tagsUrl;
}

function coreui_ollama_models(): array {
  if (!function_exists('curl_init')) throw new RuntimeException('model_catalog_unavailable');
  $ch = curl_init(coreui_ollama_tags_url());
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if (!is_string($raw) || $error !== '' || $status < 200 || $status >= 300) {
    throw new RuntimeException('model_catalog_unavailable');
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded) || !is_array($decoded['models'] ?? null)) {
    throw new RuntimeException('model_catalog_invalid_response');
  }
  $models = [];
  foreach ($decoded['models'] as $row) {
    if (!is_array($row)) continue;
    $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
    if (!coreui_model_name_valid($name)) continue;
    $details = is_array($row['details'] ?? null) ? $row['details'] : [];
    $models[$name] = [
      'name' => $name,
      'size' => max(0, (int)($row['size'] ?? 0)),
      'modified_at' => isset($row['modified_at']) ? (string)$row['modified_at'] : null,
      'family' => isset($details['family']) ? (string)$details['family'] : null,
      'parameter_size' => isset($details['parameter_size']) ? (string)$details['parameter_size'] : null,
      'quantization_level' => isset($details['quantization_level']) ? (string)$details['quantization_level'] : null,
      'digest' => isset($row['digest']) ? strtolower((string)$row['digest']) : null,
    ];
  }
  ksort($models, SORT_NATURAL | SORT_FLAG_CASE);
  return array_values($models);
}

function coreui_ollama_model_exists(string $name): bool {
  if (!coreui_model_name_valid($name)) return false;
  foreach (coreui_ollama_models() as $model) {
    if (hash_equals((string)$model['name'], $name)) return true;
  }
  return false;
}

/*
 * 0.5.76: TCI names for the Gemma 4 tiers (lore in the chat). The size in
 * the name decides, also for cloud variants: e2b Schnell, e4b Normal,
 * 12b Plus, 26b Pro, 31b Premium. Cores the chat installer made from Mara's
 * Modelfile carry the stable CoreAI name with its size tag (ember-core:12b). Other
 * Cores keep their own name.
 */
const COREUI_CORE_TIER_LABELS = [
  'schnell' => 'TCI Core Schnell',
  'normal' => 'TCI Core Normal',
  'plus' => 'TCI Core Plus',
  'pro' => 'TCI Core Pro',
  'premium' => 'TCI Core Premium',
];
// Preserve upstream Gemma tier suffixes verbatim in our CoreAI tags.
const COREUI_CORE_TIER_SIZES = [
  'e2b'=>'schnell', 'e4b'=>'normal', '12b'=>'plus', '26b'=>'pro', '31b'=>'premium',
];

/*
 * Local tiers the chat installer may pull (api/core_install.php). Sizes as
 * ollama.com/library/gemma4 lists them on 28.10.2026 (upper value of "Size /
 * Usage"); the pull reports the real one. active_b: parameters read per
 * token (the 26b is a mixture of experts with about 4B active), decides the
 * speed hint.
 */
const COREUI_CORE_INSTALL_CATALOG = [
  'schnell' => ['base'=>'gemma4:e2b', 'tag'=>'e2b', 'download_gb'=>7.5, 'active_b'=>2.3],
  'normal' => ['base'=>'gemma4:e4b', 'tag'=>'e4b', 'download_gb'=>9.5, 'active_b'=>4.5],
  'plus' => ['base'=>'gemma4:12b', 'tag'=>'12b', 'download_gb'=>8.0, 'active_b'=>12.0],
  'pro' => ['base'=>'gemma4:26b', 'tag'=>'26b', 'download_gb'=>19.0, 'active_b'=>4.0],
  'premium' => ['base'=>'gemma4:31b', 'tag'=>'31b', 'download_gb'=>20.0, 'active_b'=>31.0],
];

/** The current CoreAI model family. The old ember-coreui family remains readable. */
function coreui_core_mara_family(): string {
  return 'ember-core';
}

function coreui_core_tier(string $model): ?string {
  $m = strtolower(trim($model));
  foreach (['ember-core', 'ember-coreui'] as $family) {
    if (str_starts_with($m, $family . ':')) return COREUI_CORE_TIER_SIZES[substr($m, strlen($family) + 1)] ?? null;
  }
  if (preg_match('~gemma-?4~', $m) !== 1) return null;
  foreach (COREUI_CORE_TIER_SIZES as $size => $tier) {
    if (preg_match('~(?<![a-z0-9])' . $size . '(?![a-z0-9])~', $m) === 1) return $tier;
  }
  return null;
}

/** Same Core, compared like Ollama does (case, missing ":latest"). */
function coreui_core_same(string $a, string $b): bool {
  $key = static function (string $n): string {
    $n = strtolower(trim($n));
    return ($n !== '' && !str_contains($n, ':')) ? $n . ':latest' : $n;
  };
  return $key($a) !== '' && $key($a) === $key($b);
}

/** Base Core of Mara's own Core: the installer's generated Modelfile, else the template. */
function coreui_core_mara_base(): string {
  $root = dirname(__DIR__);
  foreach ([$root . '/var/Modelfile.generated', $root . '/config/Modelfile.template'] as $file) {
    $text = is_readable($file) ? (string)@file_get_contents($file) : '';
    if (preg_match('~^FROM[ \t]+(\S+)~m', $text, $m) === 1) return $m[1];
  }
  return '';
}

/** Tier of a Core; Mara's own Core counts as the tier of her base. */
function coreui_core_tier_of(string $model): ?string {
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
  $tier = coreui_core_tier($model);
  if ($tier === null && coreui_core_same($model, $default)) $tier = coreui_core_tier(coreui_core_mara_base());
  return $tier;
}

/**
 * 0.6.34: which Cores hear audio, for the speaker and the live symbol in the chat's Core menu
 * (Ollama /api/show "capabilities"; $show(string $name): ?array). Adds audio (true, false or null
 * when unknown) and live (a local Core that hears: the live conversation can run on it). Cloud
 * Cores are not asked; after the first local Core Ollama does not describe, the rest stay unknown,
 * so a slow Ollama never holds the list up.
 */
function coreui_core_audio_flags(array $models, callable $show): array {
  $out = [];
  $stop = false;
  foreach ($models as $m) {
    $name = (string)($m['name'] ?? '');
    $audio = null;
    if (!$stop && $name !== '' && preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $name) !== 1) {
      $info = $show($name);
      if (!is_array($info)) $stop = true;
      elseif (is_array($info['capabilities'] ?? null)) $audio = in_array('audio', array_map('strval', $info['capabilities']), true);
    }
    $out[] = $m + ['audio' => $audio, 'live' => $audio === true];
  }
  return $out;
}

/** Name shown in the chat: the TCI tier name, or the Core's own name. */
function coreui_core_label(string $model): string {
  $tier = coreui_core_tier_of($model);
  return $tier !== null ? COREUI_CORE_TIER_LABELS[$tier] : $model;
}

/** Core made from Mara's Modelfile: her own Core or one the chat installer created. */
function coreui_core_is_mara(string $model): bool {
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
  $normalized = strtolower(trim($model));
  return coreui_core_same($model, $default)
    || str_starts_with($normalized, 'ember-core:')
    || str_starts_with($normalized, 'ember-coreui:');
}

/**
 * Local tiers without any local Core in the Ollama list, in tier order. Cloud
 * tiers are never offered: they need an Ollama account on the server.
 */
function coreui_core_missing_tiers(array $models): array {
  $have = [];
  foreach ($models as $m) {
    $name = (string)(is_array($m) ? ($m['name'] ?? '') : $m);
    if ($name === '' || preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $name) === 1) continue;
    $tier = coreui_core_tier_of($name);
    if ($tier !== null) $have[$tier] = true;
  }
  $missing = [];
  foreach (COREUI_CORE_INSTALL_CATALOG as $tier => $entry) {
    if (!isset($have[$tier])) $missing[] = ['tier'=>$tier, 'label'=>COREUI_CORE_TIER_LABELS[$tier], 'base'=>$entry['base']];
  }
  return $missing;
}
