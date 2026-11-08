<?php
declare(strict_types=1);
// CoreAI 0.5.76: Gemma tiers carry TCI names in the chat (Schnell, Normal,
// Plus, Pro, Premium), and every Core speaks as Mara. Her own Core keeps the Modelfile SYSTEM; a raw Gemma or
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
  'gemma4:e2b' => 'TCI Core Schnell',
  'gemma4:e4b' => 'TCI Core Normal',
  'gemma4:26b' => 'TCI Core Pro',
  'gemma4:31b' => 'TCI Core Premium',
  'gemma4:31b-cloud' => 'TCI Core Premium',
  'gemma4:26b-a4b-it-q4_K_M' => 'TCI Core Pro',
  'hf.co/x/gemma-4-E4B-it-GGUF:Q8_0' => 'TCI Core Normal',
  'gemma4:12b' => 'TCI Core Plus',
  'gemma4:12b-cloud' => 'TCI Core Plus',
  // Cores the chat installer built from Mara's Modelfile (family:size).
  'ember-core:e2b' => 'TCI Core Schnell',
  'ember-core:e4b' => 'TCI Core Normal',
  'ember-core:12b' => 'TCI Core Plus',
  'Ember-Core:26b' => 'TCI Core Pro',
  'ember-core:31b' => 'TCI Core Premium',
  'ember-coreui:e2b' => 'TCI Core Schnell', // historical model alias
  'ember-coreui:e4b' => 'TCI Core Normal', // historical model alias
  'ember-coreui:12b' => 'TCI Core Plus', // historical model alias
  'ember-core:test' => 'ember-core:test',
  'gpt-oss:120b-cloud' => 'gpt-oss:120b-cloud',
  'gemma3:27b' => 'gemma3:27b',
] as $model => $label) tier_test(coreui_core_label($model) === $label, "label of {$model}");

// Mara's own Core is named after the base of her Modelfile.
$base = coreui_core_mara_base();
tier_test($base !== '' && coreui_core_tier($base) !== null, 'base of Mara\'s Core is a Gemma 4 tier');
$default = defined('STU_EMBER_MODEL') ? (string)STU_EMBER_MODEL : 'ember-coreui:latest';
tier_test(coreui_core_label($default) === COREUI_CORE_TIER_LABELS[coreui_core_tier($base)], 'Mara\'s Core carries the tier of her base');
tier_test(coreui_core_same('Ember-CoreUI', 'ember-coreui:latest') && !coreui_core_same('', ''), 'names compare like Ollama');
tier_test(array_keys(COREUI_CORE_TIER_LABELS) === ['schnell', 'normal', 'plus', 'pro', 'premium']
  && array_values(array_unique(COREUI_CORE_TIER_SIZES)) === array_keys(COREUI_CORE_TIER_LABELS)
  && array_keys(COREUI_CORE_INSTALL_CATALOG) === array_keys(COREUI_CORE_TIER_LABELS)
  && array_column(COREUI_CORE_INSTALL_CATALOG, 'tag') === ['e2b', 'e4b', '12b', '26b', '31b'], 'one fixed tier order everywhere');
tier_test(coreui_core_mara_family() === 'ember-core'
  && coreui_core_is_mara($default) && coreui_core_is_mara('ember-core:12b')
  && coreui_core_is_mara('ember-coreui:12b') && !coreui_core_is_mara('gemma4:12b'),
  'Mara\'s Cores are recognised by her name');

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
// 0.5.79: the KI-Profil has no Core list any more; the chat menu (TCI names) is the only Core choice.
tier_test(!str_contains($settingsJs, "api('./api/models.php')") && !str_contains($settingsJs, 'model_override:'),
  'the KI-Profil neither lists nor sends a Core');

// 0.6.34: which Cores hear audio (speaker and live symbol in the chat's Core menu).
$asked = [];
$shows = ['gemma4:e2b' => ['capabilities' => ['completion', 'vision', 'audio']], 'gemma4:12b' => ['capabilities' => ['completion', 'vision']],
  'ember-coreui:latest' => ['capabilities' => ['completion', 'audio']]];
$flags = coreui_core_audio_flags([['name' => 'gemma4:e2b'], ['name' => 'gemma4:31b-cloud'], ['name' => 'gemma4:12b'],
  ['name' => 'ember-coreui:latest']], static function (string $n) use (&$asked, $shows): ?array { $asked[] = $n; return $shows[$n] ?? null; });
tier_test(array_column($flags, 'audio') === [true, null, false, true] && array_column($flags, 'live') === [true, false, false, true]
  && !in_array('gemma4:31b-cloud', $asked, true), 'speaker for Cores that hear, live for local ones, cloud Cores not asked');
$asked = [];
$flags = coreui_core_audio_flags([['name' => 'a:1'], ['name' => 'b:1'], ['name' => 'c:1']],
  static function (string $n) use (&$asked): ?array { $asked[] = $n; return null; });
tier_test($asked === ['a:1'] && array_column($flags, 'audio') === [null, null, null] && array_column($flags, 'live') === [false, false, false],
  'Ollama does not describe a Core: the rest stay unknown, no symbol, no waiting');
tier_test(str_contains($models, "coreui_core_audio_flags(\$models, static fn(string \$name): ?array => coreui_ollama_read_json('show'"),
  'the Core list asks Ollama which Cores hear');

echo "CoreAI Core tiers: TCI names, Mara's base and persona for every Core, audio and live symbols OK\n";
