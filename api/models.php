<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/core_install.php';

stu_require_user_id();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}

try {
  // 0.5.76: TCI names for the chat (lore); the KI-Profil keeps the technical names.
  // Local tiers that are missing come along for the chat installer.
  $models = array_map(static fn(array $m): array => $m + [
    'label'=>coreui_core_label((string)$m['name']),
    'tier'=>coreui_core_tier_of((string)$m['name']),
    'cloud'=>preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', (string)$m['name']) === 1,
    'mara'=>coreui_core_is_mara((string)$m['name']),
  ], coreui_ollama_models());
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '';
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  stu_json([
    'ok'=>true,
    'models'=>$models,
    'default_model'=>$default,
    'default_label'=>$default !== '' ? coreui_core_label($default) : '',
    'missing_tiers'=>coreui_core_missing_tiers($models),
    'install'=>coreui_core_install_status(),
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
}
