<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}
require_once dirname(__DIR__) . '/api/profile_store.php';

function coreui_identity_assert(bool $ok, string $reason): void {
  if (!$ok) {
    fwrite(STDERR, 'CoreAI identity: ' . $reason . "\n");
    exit(1);
  }
}
$root = dirname(__DIR__);
coreui_identity_assert(coreui_profile_coreai_name('') === 'Mara',
  'empty CoreAI profile must default to Mara');
coreui_identity_assert(coreui_profile_coreai_name('Ember') === 'Mara'
  && coreui_profile_coreai_name('ember') === 'Mara',
  'old CoreAI default must not be exposed after upgrade');
coreui_identity_assert(coreui_profile_coreai_name('Nova Core') === 'Nova Core',
  'deliberately customized CoreAI names must be preserved');

$model = (string)file_get_contents($root . '/config/Modelfile.template');
$chat = (string)file_get_contents($root . '/api/chat.php');
$ui = (string)file_get_contents($root . '/js/console-app.js');
$work = (string)file_get_contents($root . '/js/coreui-work-events.js');
$settings = (string)file_get_contents($root . '/settings.html');
$migration = (string)file_get_contents($root . '/database/migrations/014_coreai_mara_identity.sql');
$coreuiIdentity = (string)file_get_contents($root . '/api/config.php');
$bootstrap = (string)file_get_contents($root . '/scripts/bootstrap.php');

coreui_identity_assert(str_contains($model, 'Du bist Mara Thorne')
  && str_contains($model, 'CoreAI der Ravager Thorn')
  && str_contains($model, 'Gamer-Schreibweise'),
  'Maras identity and vision tone must be central in the owned Modelfile');
coreui_identity_assert(!str_contains($model, 'Sandra Jansen')
  && !str_contains($model, 'Ember Caldwell')
  && !preg_match('/^PARAMETER[ \\t]+num_ctx\\b/m', $model)
  && !preg_match('/^PARAMETER[ \\t]+num_predict\\b/m', $model)
  && preg_match('/^PARAMETER[ \\t]+draft_num_predict[ \\t]+0\\s*$/m', $model) === 1,
  'Modelfile must preserve approved draft setting and exclude banned parameters');
coreui_identity_assert(!str_contains($chat, 'Sandra Jansen')
  && !str_contains($chat, 'Ember Caldwell')
  && str_contains($chat, "preg_match('~^ember-coreui(?::|$)~i', \$primaryModel)")
  && str_contains($chat, "preg_match('~(?:@\\s*)?(?:mara|ember)\\b~u', \$m)"),
  'vision and direct addressing must recognize Mara without obsolete persona');
coreui_identity_assert(str_contains($work, 'E.M.B.E.R CoreAI arbeitet')
  && str_contains($ui, "assistantName: 'Mara'")
  && str_contains($settings, 'value="Mara"'),
  'chat and work panel must show the right names');
coreui_identity_assert(str_contains($coreuiIdentity, "COREUI_EMBER_CHARACTER_NAME', 'Mara'")
  && str_contains($bootstrap, "'name' => 'Mara'"),
  'bootstrapping and fallback CoreAI names must default to Mara');
coreui_identity_assert(str_contains($migration, "WHERE BINARY assistant_name = BINARY 'Ember'")
  && str_contains($migration, "WHERE character_id='ember'")
  && !str_contains($migration, "SET id='mara'"),
  'legacy names must migrate without changing stable identifiers');
echo "CoreUI Mara TCI/CoreAI identity selftest: OK\n";
