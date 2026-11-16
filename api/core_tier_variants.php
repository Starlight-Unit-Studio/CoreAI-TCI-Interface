<?php
declare(strict_types=1);

/**
 * Which installed local Ollama tag backs each TCI tier.
 *
 * The operator changes this in "Cores verwalten", never in the chat picker.
 * A tier choice is shared by the installation; a chat can still choose a
 * different tier or an independently named non-TCI Core as before.
 * Only exact names in the current Ollama inventory may be saved.
 */
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/helpers.php';

function coreui_tier_variant_local(string $name): bool {
  return $name !== ''
    && !coreui_core_is_generator($name)
    && preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $name) !== 1;
}

function coreui_tier_variant_key(string $tier): string {
  if (!array_key_exists($tier, COREUI_CORE_TIER_LABELS)) {
    throw new InvalidArgumentException('invalid_tier');
  }
  return 'core_tier_variant_' . $tier;
}

function coreui_tier_variant_installed(array $models, string $tier): array {
  coreui_tier_variant_key($tier);
  $found = [];
  foreach ($models as $item) {
    $name = trim((string)(is_array($item) ? ($item['name'] ?? '') : $item));
    if (!coreui_model_name_valid($name) || !coreui_tier_variant_local($name)
        || coreui_core_tier_of($name) !== $tier) continue;
    $found[coreui_ollama_model_key($name)] = $name;
  }
  return array_values($found);
}

/** If not configured, retain the original Mara / base preference. */
function coreui_tier_variant_fallback(array $models, string $tier): string {
  $names = coreui_tier_variant_installed($models, $tier);
  if ($names === []) return '';
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
  foreach ($names as $name) {
    if (coreui_core_same($name, $default)) return $name;
  }
  foreach ($names as $name) {
    if (coreui_core_is_mara($name)) return $name;
  }
  foreach ($names as $name) {
    if (stripos($name, 'uncensored') === false) return $name;
  }
  return $names[0];
}

/** Used by model lists and the administration UI; stale selections fall back. */
function coreui_tier_variant_active(PDO $pdo, string $tier, array $models): string {
  $names = coreui_tier_variant_installed($models, $tier);
  $saved = trim((string)stu_app_setting_get($pdo, coreui_tier_variant_key($tier), ''));
  foreach ($names as $name) {
    if (coreui_core_same($name, $saved)) return $name;
  }
  return coreui_tier_variant_fallback($models, $tier);
}

function coreui_tier_variant_map(PDO $pdo, array $models): array {
  $map = [];
  foreach (COREUI_CORE_TIER_LABELS as $tier => $_) {
    $map[$tier] = coreui_tier_variant_active($pdo, $tier, $models);
  }
  return $map;
}

/**
 * Turn-time routing without another Ollama HTTP call. The saved tag was
 * checked against the live inventory at write time. Cloud, generator and
 * foreign Cores do not pass through the local-tier substitution.
 */
function coreui_tier_variant_effective(PDO $pdo, string $model): string {
  $tier = coreui_core_tier_of($model);
  if ($tier === null || !coreui_tier_variant_local($model)) return $model;
  $saved = trim((string)stu_app_setting_get($pdo, coreui_tier_variant_key($tier), ''));
  return coreui_model_name_valid($saved)
    && coreui_tier_variant_local($saved)
    && coreui_core_tier_of($saved) === $tier ? $saved : $model;
}

/** Admin-only API calls this after verifying permissions and CSRF. */
function coreui_tier_variant_select(PDO $pdo, string $tier, string $model, array $models): string {
  if (!coreui_model_name_valid($model)) throw new InvalidArgumentException('invalid_model_name');
  foreach (coreui_tier_variant_installed($models, $tier) as $available) {
    if (!hash_equals($available, $model)) continue;
    stu_app_setting_set($pdo, coreui_tier_variant_key($tier), $available);
    return $available;
  }
  throw new RuntimeException('model_not_installed');
}

/** Called after a successful uninstall; a removed tag cannot stay configured. */
function coreui_tier_variant_clear_removed(PDO $pdo, array $removed): void {
  foreach (COREUI_CORE_TIER_LABELS as $tier => $_) {
    $selected = trim((string)stu_app_setting_get($pdo, coreui_tier_variant_key($tier), ''));
    foreach ($removed as $name) {
      if (coreui_core_same($selected, (string)$name)) {
        stu_app_setting_set($pdo, coreui_tier_variant_key($tier), '');
        break;
      }
    }
  }
}
