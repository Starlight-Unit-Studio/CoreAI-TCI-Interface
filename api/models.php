<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/model_catalog.php';

stu_require_user_id();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  stu_json(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}

try {
  // 0.5.76: TCI names for the chat (lore); the KI-Profil keeps the technical names.
  $models = array_map(static fn(array $m): array => $m + [
    'label'=>coreui_core_label((string)$m['name']),
    'tier'=>coreui_core_tier((string)$m['name']),
    'cloud'=>preg_match('~(?:^|[:._-])cloud(?:$|[:._-])~i', (string)$m['name']) === 1,
  ], coreui_ollama_models());
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '';
  stu_json([
    'ok'=>true,
    'models'=>$models,
    'default_model'=>$default,
    'default_label'=>$default !== '' ? coreui_core_label($default) : '',
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
}
