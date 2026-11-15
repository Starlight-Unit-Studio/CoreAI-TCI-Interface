<?php
declare(strict_types=1);
// Regression: only the explicit + Recherche menu selection opens Playwright.
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/console_composer_intent.php';
require_once dirname(__DIR__) . '/api/console_agent_loop.php';

function ember_extract_browse_marker(string $text): ?string {
  return preg_match('~\[BROWSE:\s*([^\]]+)\]~i', $text, $m) ? trim($m[1]) : null;
}
function browser_test(bool $ok, string $message): void {
  if (!$ok) { fwrite(STDERR, "Browser routing FAIL: {$message}\n"); exit(1); }
}
$browse = coreui_composer_intent_peek('[[recherche]] Bitte lies die Teamseite');
$web = coreui_composer_intent_peek('[[websuche]] Bitte lies die Teamseite');
$deep = coreui_composer_intent_peek('[[deep-research]] Bitte lies die Teamseite');
browser_test(coreui_agent_explicit_browse($browse, true), 'explicit browser permission');
foreach ([null, $web, $deep] as $intent) {
  browser_test(!coreui_agent_explicit_browse($intent, true), 'no automatic browser permission');
}
browser_test(!coreui_agent_explicit_browse($browse, false), 'disabled browser stays disabled');
$old = '[BROWSE: https://example.org/team]';
browser_test(coreui_agent_guard_browser_reply($old, false, true) === '[WEB: https://example.org/team]',
  'legacy browser marker uses web');
browser_test(coreui_agent_guard_browser_reply($old, true, true) === $old, 'explicit browser preserved');
browser_test(!str_contains(coreui_agent_guard_browser_reply($old, false, false), '[BROWSE:'),
  'no browse on disabled web');
browser_test(coreui_agent_next_tool($old, ['web']) === ['tool'=>'web', 'input'=>'https://example.org/team'],
  'second agent step uses web');
browser_test(coreui_agent_next_tool($old, ['browse', 'web']) === ['tool'=>'browse', 'input'=>'https://example.org/team'],
  'explicit browser permitted in agent loop');
browser_test(coreui_agent_next_tool('[WEB: Test]', ['web']) === ['tool'=>'web', 'input'=>'Test'],
  'regular web query preserved');
echo "Browser routing: only explicit Recherche uses Playwright; web fallback OK\n";
