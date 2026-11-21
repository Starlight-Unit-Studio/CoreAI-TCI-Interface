<?php
declare(strict_types=1);
// Browser tool permission and live-view selection are separate.
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_composer_intent.php';
require_once dirname(__DIR__) . '/api/console_agent_loop.php';

function ember_extract_browse_marker(string $text): ?string {
  return preg_match('~\[BROWSE:\s*([^\]]+)\]~i', $text, $m) ? trim($m[1]) : null;
}
function ember_extract_web_marker(string $text): ?string {
  return preg_match('~\[WEB:\s*([^\]]+)\]~i', $text, $m) ? trim($m[1]) : null;
}
function browser_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Browser routing FAIL: {$message}\n"); exit(1); }
}
$browse = coreui_composer_intent_peek('[[recherche]] Bitte lies die Teamseite');
$web = coreui_composer_intent_peek('[[websuche]] Bitte lies die Teamseite');
$deep = coreui_composer_intent_peek('[[deep-research]] Bitte lies die Teamseite');
browser_test(coreui_agent_browse_available(null, true), 'Mara may start the browser in a normal turn');
browser_test(coreui_agent_browse_available($browse, true), 'explicit Research starts Playwright');
foreach ([$web, $deep] as $intent) {
  browser_test(!coreui_agent_browse_available($intent, true), 'a different explicit tool keeps precedence');
}
browser_test(!coreui_agent_browse_available(null, false)
  && !coreui_agent_browse_available($browse, false), 'disabled browser stays disabled');
browser_test(coreui_composer_live_browser_selected('[[recherche]] Bitte lies die Teamseite'),
  'the saved user selection permits live view');
foreach (['Bitte lies die Teamseite', '[[websuche]] Teamseite', '[[deep-research]] Teamseite',
    'Bitte lies die Teamseite [[recherche]]', '[BROWSE: https://example.org/team]'] as $message) {
  browser_test(!coreui_composer_live_browser_selected($message), 'only the composer Research marker permits live view');
}
$old = '[BROWSE: https://example.org/team]';
browser_test(coreui_agent_guard_browser_reply($old, false, true) === '[WEB: https://example.org/team]',
  'disabled browser marker uses web');
browser_test(coreui_agent_guard_browser_reply($old, true, true) === $old, 'enabled browser request is preserved');
browser_test(!str_contains(coreui_agent_guard_browser_reply($old, false, false), '[BROWSE:'),
  'no browse on disabled web');
browser_test(coreui_agent_next_tool($old, ['web']) === ['tool'=>'web', 'input'=>'https://example.org/team'],
  'second agent step uses web');
browser_test(coreui_agent_next_tool($old, ['browse', 'web']) === ['tool'=>'browse', 'input'=>'https://example.org/team'],
  'enabled browser permitted in agent loop');
browser_test(coreui_agent_next_tool('[WEB: Test]', ['web']) === ['tool'=>'web', 'input'=>'Test'],
  'regular web query preserved');
echo "Browser routing: automatic and explicit Playwright, explicit live view, permissions and web fallback verified\n";
