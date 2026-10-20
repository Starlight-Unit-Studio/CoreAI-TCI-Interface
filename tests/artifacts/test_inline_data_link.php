<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/console_data_link_bridge.php';
function check_case(bool $ok, string $label): void { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } echo "OK: $label\n"; }
$html = '<!doctype html><html lang="de"><body><h1>Ein Spiel</h1><script>const x=1;</script></body></html>';
$anchor = '<a href="data:text/html;charset=utf-8,' . rawurlencode($html) . '" download="minigame.html">Herunterladen</a>';
$r = coreui_console_inline_file_python('Fertig: ' . $anchor, 'Kannst du ein Minigame als HTML-Datei erstellen?', 20000);
check_case(is_array($r) && $r['name']==='minigame.html' && $r['size_bytes']===strlen($html), 'percent-html and filename');
check_case(str_contains((string)$r['code'], '/work/output'), 'uses trusted sandbox output directory');
check_case(!str_contains((string)$r['code'], '<script>'), 'no HTML source executed in Python');
$r2 = coreui_console_inline_file_python($anchor, 'Erkläre mir bitte das Internetprotokoll', 20000);
check_case($r2 === null, 'no auto-convert for unrelated query');
$base = '<a download="mygame.html" href="data:text/html;base64,'.base64_encode($html).'">HTML</a>';
check_case(is_array(coreui_console_inline_file_python($base, 'Download html minigame', 20000)), 'base64-html');
$invalid = '<a href="data:text/html;charset=utf-8,%3Chtml%3Eonly half" download="test.html">x</a>';
check_case(coreui_console_inline_file_python($invalid, 'HTML-Datei',20000)===null, 'truncated HTML refused');
check_case(coreui_console_inline_link_present($invalid), 'bad encoded link detected');
$zip = "PK\x03\x04" . 'test';
check_case(is_array(coreui_console_inline_file_python('<a href="data:application/zip;base64,'.base64_encode($zip).'" download="archive.zip">zip</a>', 'ZIP-Datei erstellen', 20000)), 'zip-byte payload accepted');
check_case(coreui_console_inline_file_python($anchor, 'HTML-Datei erstellen', 60)===null, 'max code chars enforced');
check_case(coreui_console_inline_file_python('<a href="data:text/html,%3Chtml%3Ehi%3C%2Fhtml%3E" download="../bad.html">x</a>', 'HTML-Datei erstellen',20000)['name']==='ergebnis.html', 'malicious filename falls back to safe default');
check_case(coreui_console_inline_file_python('<a href="data:text/html,%3Chtml%3Ehi%3C%2Fhtml%3E" download="bad.php">x</a>', 'HTML-Datei erstellen',20000)['name']==='ergebnis.html', 'extension mismatch does not escape output extension');
$fenced = "Hier das Beispiel:\n```html\n".$html."\n```";
check_case(is_array(coreui_console_inline_file_python($fenced, 'HTML-Minispiel erstellen', 20000)), 'fenced complete HTML fallback');
check_case(coreui_console_inline_file_python('ein normaler Satz', 'HTML erstellen', 20000)===null, 'no phantom file');
$cloudReply = "Der erste Versuch ergab FileNotFoundError. Hier die Datei:\\n"
  . $html . "\\nKopieren ist nicht noetig.";
$raw = coreui_console_inline_file_python($cloudReply, 'Erstelle mini_game.html zum Download', 20000);
check_case(is_array($raw) && $raw['name'] === 'mini_game.html', 'recover complete raw cloud HTML with filename');
check_case($raw['size_bytes'] === strlen($html), 'recovered byte count matches full document');
check_case(coreui_console_inline_file_python(
  "FileNotFoundError\\n<!doctype html><html><body>unvollstaendig",
  'HTML-Minispiel als Datei', 20000
) === null, 'partial cloud HTML must never become a file');
check_case(coreui_console_inline_file_python(
  "Nur Text: <html><body>abc</body></html>", 'Was ist HTML?', 20000
) === null, 'not a download when asking a general HTML question');

check_case(coreui_console_download_requested('Kannst du eine HTML-Datei erstellen und mir hier zum Download bereitstellen?'),
  'explicit file creation and download recognized');
check_case(!coreui_console_download_requested('Warum klappt mein Download nicht?'),
  'ordinary download debugging must not be mistaken for file creation');
check_case(!coreui_console_download_requested('Zeige mir bitte HTML-Code im Chat.'),
  'inline code explanation is not forced into a download');
