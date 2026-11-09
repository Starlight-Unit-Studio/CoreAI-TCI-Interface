<?php
declare(strict_types=1);
// CoreAI 0.6.71: offline, no database or model requests required.
// php scripts/general-controls-selftest.php
$root = dirname(__DIR__);
require_once $root . '/api/ai_settings.php';
require_once $root . '/api/general_core_controls.php';
function general_test(bool $ok, string $description): void {
  if (!$ok) { fwrite(STDERR, 'General controls FAIL: ' . $description . "\n"); exit(1); }
}
$raw = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);

general_test(coreui_general_defaults() === ['auto_higher_core'=>false, 'thinking_level'=>''],
  'both General controls default to inactive/unchanged');
$yes = coreui_general_thinking_capabilities('gemma4:e4b', [
  'thinking'=>['values'=>['medium','low','high','not-a-real-level','medium'], 'default'=>'medium']
]);
general_test($yes['levels'] === ['medium','low','high'] && $yes['metadata_available'],
  'only actual Ollama-declared named levels, stable and unique');
$bool = coreui_general_thinking_capabilities('gemma4:e4b', ['thinking'=>['values'=>[true,false]]]);
general_test($bool['levels'] === [] && $bool['supports_on_off'], 'binary thinking is not mislabeled as 6 levels');
$none = coreui_general_thinking_capabilities('gemma4:e4b', []);
general_test(!$none['metadata_available'] && $none['levels'] === [], 'missing metadata invents no levels');
$GLOBALS['COREUI_GENERAL_SETTINGS'] = ['thinking_level'=>'high'];
general_test(coreui_general_think_value('gemma4:e4b', false) === false, 'thinking off always wins');
$GLOBALS['COREUI_GENERAL_SETTINGS'] = ['thinking_level'=>''];
general_test(coreui_general_think_value('gemma4:e4b', true) === true, 'legacy thinking enabled stays boolean');
unset($GLOBALS['COREUI_GENERAL_SETTINGS']);
general_test(!coreui_general_complex_text('Guten Morgen, Mara!'), 'ordinary chat remains on selected Core');
general_test(coreui_general_complex_text("Bitte implementiere und debugge diesen Code:\n```php\nfunction demo() {}\n```"),
  'explicit multi-step coding requests qualify');
general_test(!coreui_general_complex_text('Kannst du mir Hallo sagen?'),
  'short plain requests do not trigger background Core loading');
$migration = $raw('database/migrations/027_general_core_controls.sql');
general_test(str_contains($migration, 'CREATE TABLE IF NOT EXISTS stu_user_general_settings')
  && str_contains($migration, "'027_general_core_controls'"), 'isolated migration');
$api = $raw('api/user_settings.php');
general_test(str_contains($api, "array_key_exists('general', \$_GET)")
  && str_contains($api, "if ((\$body['action'] ?? '') === 'general')")
  && str_contains($api, 'coreui_general_save($pdo'), 'authenticated GET/POST integration');
$chat = $raw('api/chat.php');
$stream = $raw('api/console_stream.php');
general_test(str_contains($chat, 'coreui_general_select_auto_core($pdo, $uid, $userMsg,')
  && str_contains($stream, 'coreui_general_select_auto_core(')
  && str_contains($chat, 'COREUI_AUTO_SELECTED_CORE')
  && str_contains($stream, 'coreui_general_think_value(')
  && str_contains($chat, 'coreui_general_think_value('),
  'both synchronous and streaming routes, ephemeral model and named effort');
general_test(str_contains($raw('settings.html'), 'id="generalUiLanguage"')
  && str_contains($raw('js/settings.js'), "saveGeneralControl('auto_higher_core'")
  && str_contains($raw('js/settings.js'), "saveGeneralControl('thinking_level'"),
  'General controls are actually connected');
echo "CoreAI General: model-supported thinking, conservative auto Core, migration and UI/API routing OK\n";
