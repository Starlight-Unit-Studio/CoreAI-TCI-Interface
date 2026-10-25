<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

function markdown_preview_assert(bool $ok, string $message): void {
  if (!$ok) {
    fwrite(STDERR,"Markdown-Vorschau FAIL: ".$message."\n");
    exit(1);
  }
}
function markdown_preview_read(string $path): string {
  $file = @file_get_contents($path);
  markdown_preview_assert(is_string($file) && $file !== '',
    'Pflichtdatei nicht lesbar: ' . basename($path));
  return $file;
}

$root = dirname(__DIR__);
$app = markdown_preview_read($root.'/app.html');
$viewer = markdown_preview_read($root.'/js/coreui-markdown-preview.js');
$artifacts = markdown_preview_read($root.'/js/coreui-artifacts.js');
$markdown = markdown_preview_read($root.'/js/coreui-markdown.js');
$css = markdown_preview_read($root.'/css/artifacts.css');
$planApi = markdown_preview_read($root.'/api/console_plan.php');
$fileApi = markdown_preview_read($root.'/api/console_artifact.php');
$planStore = markdown_preview_read($root.'/api/console_plan_store.php');

$mdPos = strpos($app,'js/coreui-markdown.js');
$previewPos = strpos($app,'js/coreui-markdown-preview.js');
$artifactsPos = strpos($app,'js/coreui-artifacts.js');
markdown_preview_assert($mdPos !== false && $previewPos !== false
  && $artifactsPos !== false && $mdPos < $previewPos
  && $previewPos < $artifactsPos,
  'Die sichere Markdown-Bibliothek muss vor der Vorschau und den Dateikarten geladen werden.');
markdown_preview_assert(str_contains($app,'css/artifacts.css'),
  'Das Stylesheet fuer Markdown-Vorschau fehlt.');

foreach ([
  'window.CoreUIMarkdownPreview = Object.freeze',
  'window.CoreUIMarkdown.render(content, copyTextValue)',
  'window.CoreUIMarkdown.copyText',
  'aria-label',
  'md-preview-scroller',
  'Markdown kopieren',
  'Markdown herunterladen',
  'Vorschau vergroessern',
  'modal.showModal()',
  "credentials: 'same-origin'",
  "cache: 'no-store'",
  "redirect: 'error'",
  "response.blob()",
  "Content-Length",
  "application/octet-stream",
  "new TextDecoder('utf-8', { fatal: true })",
  "crypto.subtle.digest('SHA-256', bytes)",
  "'<!-- COREUI_PLAN_DRAFT -->'",
  'new IntersectionObserver',
] as $required) {
  markdown_preview_assert(str_contains($viewer,$required),
    'Vorschau-/Sicherheitsvertrag fehlt: '.$required);
}
markdown_preview_assert(!preg_match('~\.(?:innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(~',$viewer),
  'Vorschau darf niemals untrusted HTML in den DOM einfuegen.');
markdown_preview_assert(str_contains($artifacts,'viewer.supports(file)')
  && str_contains($artifacts,'viewer.create(file, endpoint)')
  && str_contains($artifacts,'section.appendChild(card(file))'),
  'Markdown muss als Viewer erscheinen, andere Dateitypen als Downloadkarte.');
markdown_preview_assert(str_contains($css,'.md-preview-scroller')
  && str_contains($css,'overflow: auto')
  && str_contains($css,'.md-preview-modal')
  && str_contains($css,'@media (max-width: 620px)'),
  'Responsive, scrollbare Markdown-Leseansicht fehlt.');

markdown_preview_assert(str_contains($planStore,"'mime_type'=>'text/markdown'")
  && str_contains($planStore,"'kind'=>'plan'"),
  'Geschuetzte Planversionen muessen als Markdown gekennzeichnet bleiben.');
markdown_preview_assert(str_contains($planApi,'stu_require_user_id()')
  && str_contains($planApi,'AND p.user_id=?')
  && str_contains($fileApi,'stu_require_user_id()')
  && str_contains($fileApi,'AND a.user_id=?'),
  'Authentifizierung/Kontotrennung fuer Vorschau und Download fehlen.');
foreach ([$planApi,$fileApi] as $endpoint) {
  markdown_preview_assert(str_contains($endpoint,'Content-Type: application/octet-stream')
    && str_contains($endpoint,'Cache-Control: private, no-store')
    && str_contains($endpoint,'Content-Disposition: attachment'),
    'Der alte geschuetzte Binardownload muss erhalten bleiben.');
}
markdown_preview_assert(!str_contains($markdown,'.innerHTML'),
  'Der vorhandene Markdown-Renderer fuegt aktives HTML ein.');
fwrite(STDOUT,"Markdown-Vorschau OK: privater Inline-Viewer, 3 Aktionen, mobile Scrollansicht, Legacy-Downloads\n");
