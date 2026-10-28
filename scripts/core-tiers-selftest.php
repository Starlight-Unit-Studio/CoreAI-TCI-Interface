<?php
declare(strict_types=1);
// CoreAI 0.5.76: Gemma tiers carry TCI names in the chat, and every Core
// speaks as Mara. Her own Core keeps the Modelfile SYSTEM; a raw Gemma or
// cloud Core without one gets the same text as system message.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/model_catalog.php';

function tier_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Core tiers FAIL: {$why}\n"); exit(1); }
}

// Same table as the client mirror in thinking-switch-ui-selftest.cjs.
foreach ([
  'gemma4:e2b' => 'TCI: Mara Schnell',
  'gemma4:e4b' => 'TCI: Mara Normal',
  'gemma4:26b' => 'TCI: Mara Pro',
  'gemma4:31b' => 'TCI: Mara Premium',
  'gemma4:31b-cloud' => 'TCI: Mara Premium',
  'gemma4:26b-a4b-it-q4_K_M' => 'TCI: Mara Pro',
  'hf.co/x/gemma-4-E4B-it-GGUF:Q8_0' => 'TCI: Mara Normal',
  'gemma4:12b' => 'gemma4:12b',
  'gpt-oss:120b-cloud' => 'gpt-oss:120b-cloud',
  'gemma3:27b' => 'gemma3:27b',
] as $model => $label) tier_test(coreui_core_label($model) === $label, "label of {$model}");

// Mara's own Core is named after the base of her Modelfile.
$base = coreui_core_mara_base();
tier_test($base !== '' && coreui_core_tier($base) !== null, 'base of Mara\'s Core is a Gemma 4 tier');
$default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
tier_test(coreui_core_label($default) === COREUI_CORE_TIER_LABELS[coreui_core_tier($base)], 'Mara\'s Core carries the tier of her base');
tier_test(coreui_core_same('Ember-CoreUI', 'ember-coreui:latest') && !coreui_core_same('', ''), 'names compare like Ollama');

// Persona: Mara's SYSTEM text without a thinking switch, never for her own Core.
$template = (string)file_get_contents($root . '/config/Modelfile.template');
preg_match('~^SYSTEM[ \t]+"""(.*?)^"""~ms', $template, $m);
$persona = ember_mara_system_text();
tier_test($persona !== '' && $persona === trim($m[1] ?? '') && str_starts_with($persona, 'Du bist Mara Thorne')
  && !str_contains($persona, '<|think|>'), 'persona is the Modelfile SYSTEM text');
tier_test(ember_persona_system_for($default) === '' && ember_persona_system_for('ember-coreui') === '',
  'Mara\'s own Core keeps her Modelfile, nothing is sent');
$chat = (string)file_get_contents($root . '/api/chat.php');
tier_test(substr_count($chat, 'ember_persona_system_for(ember_model())') === 2, 'both prompt builders give other Cores the persona');
tier_test(str_contains($chat, "if (is_array(\$show) && trim((string)(\$show['system'] ?? '')) !== '') return \$memo[\$model] = '';"),
  'a Core with a SYSTEM text of its own keeps it');

// API: TCI names for the chat, technical names stay for the KI-Profil.
$models = (string)file_get_contents($root . '/api/models.php');
tier_test(str_contains($models, "'label'=>coreui_core_label((string)\$m['name'])") && str_contains($models, "'default_label'=>"),
  'Core list sends TCI names');
$settings = (string)file_get_contents($root . '/api/user_settings.php');
tier_test(str_contains($settings, "'default_label' =>") && str_contains($settings, "'core_label' =>")
  && str_contains($settings, "'label'=>coreui_core_label(\$model)"), 'settings and Core choice send TCI names');
$settingsJs = (string)file_get_contents($root . '/js/settings.js');
tier_test(str_contains($settingsJs, "option.textContent = name + (model.parameter_size"), 'the KI-Profil keeps technical names');

echo "CoreAI Core tiers: TCI names, Mara's base and persona for every Core OK\n";
