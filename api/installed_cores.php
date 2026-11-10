<?php
declare(strict_types=1);

/**
 * Inventory and deletion eligibility for the shared LLM/Core library.
 * Use Ollama's local /api/tags as the source of truth. Actual removal is
 * delegated to the existing coreui_core_uninstall() implementation, which
 * already unloads the Core and resets saved account selections.
 *
 * These pure inventory functions accept explicit protected names for
 * deterministic unit tests. API callers pass the server's real defaults.
 */
require_once __DIR__ . '/core_install.php';

function coreui_installed_cores_rows(array $models, string $default, string $sourceBase): array {
  $rows = [];
  foreach ($models as $model) {
    if (!is_array($model)) continue;
    $name = trim((string)($model['name'] ?? ''));
    if (!coreui_model_name_valid($name)) continue;
    $standard = coreui_core_same($name, $default);
    $source = $sourceBase !== '' && coreui_core_same($name, $sourceBase);
    $mara = coreui_core_is_mara($name);
    $cloud = preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', $name) === 1;
    $reason = $standard ? 'Der Standard-Core muss installiert bleiben.'
      : ($source ? 'Die Basis des Standard-Cores bleibt geschützt.' : '');
    $rows[] = [
      'name' => $name,
      'label' => $mara ? coreui_core_label($name) : $name,
      'kind' => $mara ? 'tci' : ($cloud ? 'cloud' : 'local'),
      'size_bytes' => max(0, (int)($model['size'] ?? 0)),
      'parameter_size' => (string)($model['parameter_size'] ?? ''),
      'family' => (string)($model['family'] ?? ''),
      'removable' => !$standard && !$source,
      'protected_reason' => $reason,
    ];
  }
  $order = ['tci'=>0, 'local'=>1, 'cloud'=>2];
  usort($rows, static fn(array $a, array $b): int =>
    ($order[$a['kind']] <=> $order[$b['kind']])
    ?: strnatcasecmp($a['name'], $b['name']));
  return $rows;
}

/**
 * Fail closed. The submitted name must match a fresh Ollama inventory entry
 * exactly; similarly named or alias-normalized tags cannot delete a
 * different model. The default Core and its template base are protected.
 */
function coreui_installed_cores_target(array $models, string $requested, string $default, string $sourceBase): string {
  if (!coreui_model_name_valid($requested)) throw new RuntimeException('invalid_model_name');
  foreach (coreui_installed_cores_rows($models, $default, $sourceBase) as $row) {
    if (!hash_equals((string)$row['name'], $requested)) continue;
    if (!$row['removable']) throw new RuntimeException('core_protected');
    return $requested;
  }
  throw new RuntimeException('model_not_installed');
}
