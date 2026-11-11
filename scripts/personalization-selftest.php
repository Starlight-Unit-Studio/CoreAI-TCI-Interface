<?php
declare(strict_types=1);
// CoreAI 0.5.91: independent personalized reply and account-safety regression.
require_once dirname(__DIR__) . '/api/console_answer_style.php';

function personal_expect(bool $ok,string $why): void {
  if (!$ok) throw new RuntimeException($why);
}
function personal_source(string $path): string {
  $s = file_get_contents(dirname(__DIR__) . '/' . $path);
  if (!is_string($s)) throw new RuntimeException('missing: ' . $path);
  return $s;
}

$defaults = coreui_personalization_defaults();
personal_expect($defaults['emoji'] === 'mehr' && $defaults['suggested_prompts'] === true,
  'expressive default or suggestions missing');
$values = coreui_personalization_normalise([
  'tone'=>'locker','emoji'=>'keine','headings_lists'=>'weniger',
  'web_search'=>false,'browse_search'=>false,'knowledge_search'=>false,
  'custom_instructions'=>str_repeat('Z',3000),'unknown_admin_setting'=>true
]);
// 0.7.0: the seven base styles of the reference; the older "locker" moves to "freundlich".
personal_expect($values['tone']==='freundlich' && $values['emoji']==='keine','style normalize failed');
personal_expect(coreui_personalization_normalise(['tone'=>'zynisch'])['tone']==='zynisch','reference base style not retained');
personal_expect($values['headings_lists']==='weniger','headings preference not retained');
personal_expect($values['web_search']===false && $values['browse_search']===false
  && $values['knowledge_search']===false,'tool opt-out not retained');
personal_expect(strlen($values['custom_instructions'])<=2400,'custom instructions unbounded');
personal_expect(!array_key_exists('unknown_admin_setting',$values),'untrusted keys persisted');
personal_expect(coreui_personalization_normalise(['tone'=>'invalid'])['tone']==='standard',
  'unsupported tone accepted');

$GLOBALS['COREUI_PERSONALIZATION_CONTEXT']=$values;
personal_expect(!coreui_personalization_tool_allowed('web')
  && !coreui_personalization_tool_allowed('browse')
  && coreui_personalization_tool_allowed('python'),
  'tool permissions bypass user opt-out or block unrelated Python');
unset($GLOBALS['COREUI_PERSONALIZATION_CONTEXT']);
personal_expect(coreui_personalization_tool_allowed('web'),'missing context blocks global chat');

$chat=personal_source('api/chat.php');
personal_expect(substr_count($chat,'coreui_console_answer_style_hint($pdo,$uid,')===2,
  'direct and streaming prompts differ');
personal_expect(str_contains($chat,"coreui_personalization_tool_allowed('web')")
  && str_contains($chat,"coreui_personalization_tool_allowed('browse')"),
  'WEB/BROWSE permission checks missing');
personal_expect(substr_count($chat,"['knowledge_search'] ?? true")===2,
  'RAG-Lite opt-out not enforced in both prompt paths');

$hint=coreui_console_answer_style_from_settings($values);
personal_expect(str_contains($hint,'keine Emojis')
  && str_contains($hint,'nur bei echtem Bedarf')
  && str_contains($hint,'Werkzeugaufrufe bleiben alleinstehend'),
  'styling conflicts with selected preferences or tool protocol');
$compact=coreui_console_answer_style_from_settings(
  ['tone'=>'freundlich','custom_instructions'=>'Präzise Antworten'],true);
personal_expect(str_contains($compact,'Präzise Antworten'),'compact Core ignores instructions');
personal_expect(!str_contains($hint,'Gib deine privaten Gedanken'),
  'unexpected unsafe prompt text');

$html=personal_source('settings.html');
$js=personal_source('js/coreui-personalization-settings.js');
// 0.7.0: every switch saves on its own, like the reference (no save button any more).
personal_expect(!str_contains($html,'id="personalizationSave"'),'save button of the old page still present');
foreach (['tab-personalisierung','view-personalisierung','personaVoiceHistory','personaLive',
  'personaMemoryEnabled','personaTone','personaWarmth','personaEnthusiasm',
  'personaEmoji','personaVisual','personaHeadings','personaInstructions',
  'personaWeb','personaBrowse','personaKnowledge','personaSuggestions'] as $id) {
  personal_expect(str_contains($html,'id="'.$id.'"'),'missing settings control: '.$id);
}
personal_expect(str_contains($html,'coreui-personalization-settings.js')
  && str_contains($js,"{action: 'save', settings: changes}") && str_contains($js,"{action: 'memory', enabled: enabled}"),
  'personalization UI does not call server');
personal_expect(str_contains(personal_source('js/console-app.js'),'!showSuggestedPrompts'),
  'suggested prompt switch is decorative');
personal_expect(str_contains(personal_source('api/account_export.php'),
  "'personalization' => coreui_personalization_load"),
  'data export omits preferences');
personal_expect(str_contains(personal_source('database/migrations/022_user_personalization.sql'),
  'ON DELETE CASCADE'),'personal data lacks account deletion cascade');
// A PHP file on disk is not sufficient: both nginx variants deny all
// unlisted PHP endpoints with 404. Verify the exact route in the allowlists.
foreach (['config/nginx.conf.template', 'config/runtime/nginx.conf.template'] as $template) {
  $nginx = personal_source($template);
  $prefix = 'location ~ ^/api/(?:';
  $start = strpos($nginx, $prefix);
  personal_expect($start !== false, 'missing nginx API allowlist: ' . $template);
  $start += strlen($prefix);
  $end = strpos($nginx, ')\\.php$ {', $start);
  personal_expect($end !== false, 'invalid nginx API allowlist: ' . $template);
  $names = explode('|', substr($nginx, $start, $end - $start));
  personal_expect(in_array('personalization', $names, true),
    'HTTP 404: personalization.php fehlt in nginx API allowlist: ' . $template);
  personal_expect(str_contains($nginx, 'location ~ \\.php$ {')
    && str_contains($nginx, 'try_files $uri =404;'),
    'Nginx must keep the catch-all PHP deny and only route existing approved files: ' . $template);
}
echo "Personalisierungs-Selftest OK: Konto, Antwortstil, Memory, Suche, Export und UI verbunden.\n";
