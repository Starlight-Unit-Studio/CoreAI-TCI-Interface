<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/model_catalog.php';
require_once __DIR__ . '/core_install.php';
require_once __DIR__ . '/core_generator_engine.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/model_access.php';

$uid = stu_require_user_id();
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
  // 0.5.83: models outside CoreAI only for the Inhaber and accounts he released them to.
  $models = coreui_model_access_filter(stu_pdo(), $uid, $models);
  // 0.6.34: speaker and live symbol for the Cores that hear audio.
  $models = coreui_core_audio_flags($models, static fn(string $name): ?array => coreui_ollama_read_json('show', ['model' => $name]));
  // Generation is a CoreAI role of the existing local FLUX prompt Core, not an Ollama vision capability.
  $models = array_map(static fn(array $m): array => $m + ['image_generation' => coreui_core_is_generator((string)$m['name'])], $models);
  // The generator stays visible in the picker even if Ollama or the image engine lacks it.
  // Only this small readiness summary is visible to regular accounts (not hardware details).
  require_once __DIR__ . '/core_generator_license.php';
  $generatorLicensed = coreui_generator_license_user_accepted(stu_pdo(), (int)$uid);
  $generatorEngine = coreui_generator_engine_public(coreui_generator_engine_status());
  $default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : '';
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  stu_json([
    'ok'=>true,
    'models'=>$models,
    'generator_engine'=>$generatorEngine,
    'generator_license_accepted'=>$generatorLicensed,
    'default_model'=>$default,
    'default_label'=>$default !== '' ? coreui_core_label($default) : '',
    'missing_tiers'=>coreui_core_missing_tiers($models),
    'install'=>coreui_core_install_status(),
  ]);
} catch (RuntimeException $e) {
  stu_json(['ok'=>false, 'error'=>$e->getMessage()], 503);
}
