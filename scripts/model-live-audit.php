<?php
declare(strict_types=1);

/**
 * Verifies the model Ollama actually serves, rather than trusting a matching
 * repository template or a stale var/Modelfile.generated. Read-only; no
 * installer / ollama create / model mutation.
 */
function coreui_model_system_from_modelfile(string $text): ?string {
  if (preg_match('~^SYSTEM """(.*?)^"""[ \t]*$~ms', $text, $m) !== 1) return null;
  return trim(str_replace(["\r\n","\r"],"\n",(string)$m[1]));
}
function coreui_model_system_from_show(array $show): ?string {
  $system = $show['system'] ?? null;
  if (is_string($system) && trim($system) !== '') {
    return trim(str_replace(["\r\n","\r"],"\n",$system));
  }
  $modelfile = $show['modelfile'] ?? null;
  return is_string($modelfile) ? coreui_model_system_from_modelfile($modelfile) : null;
}
function coreui_model_system_audit(string $expected, array $show): array {
  $actual = coreui_model_system_from_show($show);
  if ($actual === null) return ['state'=>'unverifiable'];
  return ['state'=>hash_equals($expected,$actual) ? 'match' : 'mismatch',
    'expected_bytes'=>strlen($expected), 'actual_bytes'=>strlen($actual),
    'expected_sha256'=>hash('sha256',$expected),
    'actual_sha256'=>hash('sha256',$actual)];
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
if (PHP_SAPI !== 'cli') exit(1);
$templatePath = dirname(__DIR__) . '/config/Modelfile.template';
$expected = coreui_model_system_from_modelfile((string)file_get_contents($templatePath));
if ($expected === null) { fwrite(STDERR,"Modelfile-Audit: aktives Repository-SYSTEM ist ungueltig.\n"); exit(1); }

if (($argv[1] ?? '') === '--selftest') {
  $good = coreui_model_system_audit($expected,['system'=>"\r\n".$expected."\r\n"]);
  $bad = coreui_model_system_audit($expected,['system'=>'Alter, nicht freigegebener Systemprompt']);
  $fallback = coreui_model_system_audit($expected,['modelfile'=>"FROM /model/blob\nSYSTEM \"\"\"".$expected."\n\"\"\"\n"]);
  $missing = coreui_model_system_audit($expected,['parameters'=>'top_p 0.95']);
  if ($good['state'] !== 'match' || $bad['state'] !== 'mismatch'
      || $fallback['state'] !== 'match' || $missing['state'] !== 'unverifiable') {
    fwrite(STDERR,"Modelfile-Audit-Selbsttest fehlgeschlagen.\n"); exit(1);
  }
  echo "Modelfile-Audit-Selbsttest: Ollama-SYSTEM, Abweichung und Blob-Export erkannt.\n";
  exit(0);
}

$model = trim((string)($argv[1] ?? ''));
if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._:-]{0,120}$~D',$model)) {
  fwrite(STDERR,"Modelfile-Audit: gueltigen Modellnamen als Argument angeben.\n"); exit(2);
}
if (!function_exists('curl_init')) {
  fwrite(STDERR,"Modelfile-Audit: PHP-curl nicht vorhanden.\n"); exit(2);
}
$ch = curl_init('http://127.0.0.1:11434/api/show');
if ($ch === false) exit(2);
curl_setopt_array($ch,[
  CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['model'=>$model]),
  CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
  CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>2,
  CURLOPT_TIMEOUT=>12, CURLOPT_NOSIGNAL=>true,
]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if (!is_string($raw) || $raw === '' || $code !== 200) {
  fwrite(STDERR,"Modelfile-Audit: Ollama /api/show fuer $model nicht erreichbar (HTTP $code; Curl ".($curlError !== '' ? 'Fehler' : 'OK').").\n");
  exit(2);
}
$j = json_decode($raw,true);
if (!is_array($j)) {
  fwrite(STDERR,"Modelfile-Audit: Ollama /api/show lieferte kein gueltiges JSON.\n"); exit(2);
}
$actual = coreui_model_system_audit($expected,$j);
if ($actual['state'] === 'unverifiable') {
  fwrite(STDERR,"Modelfile-Audit: Ollama meldet keinen auswertbaren SYSTEM-Text fuer $model.\n"); exit(2);
}
if ($actual['state'] === 'mismatch') {
  fwrite(STDERR,"Modelfile-Audit FEHLER: Ollama-Modell $model verwendet NICHT den freigegebenen Mara-SYSTEM-Text.\n"
    . "Vorlage: ".$actual['expected_bytes']." Bytes, SHA256 ".substr($actual['expected_sha256'],0,16)
    . "; installiert: ".$actual['actual_bytes']." Bytes, SHA256 ".substr($actual['actual_sha256'],0,16).".\n"
    . "Bitte Modellname und den normalen Modell-Erstellungsschritt des bestehenden Installers pruefen. Keine automatische Ueberschreibung.\n");
  exit(1);
}
echo "Ollama-Modell $model: Mara-SYSTEM identisch mit freigegebener Vorlage ("
  . $actual['expected_bytes']." Bytes; SHA256 ".substr($actual['expected_sha256'],0,16).").\n";
