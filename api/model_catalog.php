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
 * 26b Pro, 31b Premium. Other Cores keep their own name.
 */
const COREUI_CORE_TIER_LABELS = [
  'schnell' => 'TCI: Mara Schnell',
  'normal' => 'TCI: Mara Normal',
  'pro' => 'TCI: Mara Pro',
  'premium' => 'TCI: Mara Premium',
];

function coreui_core_tier(string $model): ?string {
  $m = strtolower(trim($model));
  if (preg_match('~gemma-?4~', $m) !== 1) return null;
  foreach (['e2b'=>'schnell', 'e4b'=>'normal', '26b'=>'pro', '31b'=>'premium'] as $size => $tier) {
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

/** Name shown in the chat: the TCI tier name, or the Core's own name. */
function coreui_core_label(string $model): string {
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
  $tier = coreui_core_tier($model);
  if ($tier === null && coreui_core_same($model, $default)) $tier = coreui_core_tier(coreui_core_mara_base());
  return $tier !== null ? COREUI_CORE_TIER_LABELS[$tier] : $model;
}
