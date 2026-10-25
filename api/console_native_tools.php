<?php
declare(strict_types=1);
require_once __DIR__ . '/console_plan_store.php';
require_once __DIR__ . '/console_data_link_bridge.php';

/**
 * Small, explicit native-tool vocabulary. The MODEL chooses a tool; the user
 * does not need to type predefined filenames, "ZIP" or trigger words.
 * Every call remains scoped to the authenticated, currently running turn.
 */
function coreui_native_tools_prompt(): string {
  return "\n\nCOREUI NATIVE TOOLS (nur fuer diese private Sitzung):\n"
    . "Du DARFST selbst entscheiden, welches Werkzeug zu einer Aufgabe passt. "
    . "Antworte entweder NORMAL oder mit GENAU EINEM dieser vollstaendigen Werkzeugbloecke, "
    . "ohne Einleitung und ohne weitere Markdown-Codezaeune:\n"
    . "[COREUI_PLAN_SAVE]\n# Projektplan\n...vollstaendiges Markdown...\n[/COREUI_PLAN_SAVE]\n"
    . "[COREUI_PLAN_APPEND]\n...neue Markdown-Abschnitte...\n[/COREUI_PLAN_APPEND]\n"
    . "[COREUI_PLAN_AND_BUILD]\n# Projektplan\n...vollstaendiges Markdown...\n[/COREUI_PLAN_AND_BUILD]\n"
    . "[COREUI_PROJECT_BUILD]\nBeschreibe das zu erzeugende Projekt und benoetigte Quelldateien "
    . "(z.B. index.html, style.css, main.js).\\n[/COREUI_PROJECT_BUILD]\n"
    . "PLAN_SAVE speichert eine echte Markdown-Datei dauerhaft und zeigt sie im Chat als Download. "
    . "PLAN_APPEND schreibt eine neue Version, ohne aeltere Fassungen zu verlieren. "
    . "PLAN_AND_BUILD speichert zuerst und startet danach den Projekt-Writer. "
    . "PROJECT_BUILD startet die sichere vorhandene Dateien-/ZIP-Erzeugung ohne erneute Nutzer-Keywords. "
    . "Du kannst diese Werkzeuge EIGENSTAENDIG waehlen, aber baue nur, wenn die Person "
    . "eine Umsetzung beauftragt oder ausdruecklich freigegeben hat. "
    . "Zum reinen Diskutieren, Brainstormen oder fuer Fragen KEINEN Build ausloesen. "
    . "Erfinde keine Dateilinks. Es stehen ausschliesslich statische Quelltexte "
    . "(HTML/CSS/JS/JSON/Markdown/TXT/SVG/Python) zur Verfuegung, KEIN APK-Compiler. "
    . "Eine normale Antwort braucht KEINE Werkzeugmarkierung.\n";
}

function coreui_native_tool_parse(string $reply): ?array {
  if (strlen($reply) > 300000) return null;
  $types = ['PLAN_SAVE','PLAN_APPEND','PLAN_AND_BUILD','PROJECT_BUILD'];
  foreach ($types as $type) {
    if (preg_match('~^\s*\[COREUI_' . $type . '\]\s*\n([\s\S]*?)\n\[/COREUI_'
        . $type . '\]\s*$~u', $reply, $m) !== 1) continue;
    $body = trim((string)$m[1]);
    if ($body === '' || strlen($body) > 262144 || str_contains($body,"\0")) return null;
    return ['type'=>$type,'body'=>$body];
  }
  return null;
}

function coreui_native_tool_build_request(PDO $pdo, int $uid, string $sessionId,
                                           string $description): ?string {
  $description = trim($description);
  if ($description === '' || strlen($description) > 12000) return null;
  $plan = coreui_plan_latest($pdo,$uid,$sessionId);
  $source = ($plan && trim((string)$plan['markdown']) !== '')
    ? (string)$plan['markdown'] : '';
  // Never silently omit part of an approved plan; explicit guidance to split.
  // Each local source request retains at most 9000 task characters.
  // Reject an oversized plan rather than silently losing requirements.
  if (strlen($source) > 6500) return null;
  $spec = coreui_console_project_request_spec($description);
  if (!is_array($spec)) return null;
  $files = array_values($spec['requested']);
  if (count($files) === 0) {
    // The MODEL has requested a static web project, so the server picks a
    // minimal portable source set. This is not a user-message keyword gate.
    $files = ['index.html','style.css','main.js'];
  }
  if (count($files) < 2 || count($files) > 9) return null;
  foreach ($files as $filename) {
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(?:html?|css|js|json|txt|md|svg|py)$~iD',$filename))
      return null;
  }
  $header = 'Erstelle die folgenden vollstaendigen Projektdateien und stelle '
    . 'ein ZIP zum Download bereit: ' . implode(', ',$files) . '. '
    . 'Erstelle projekt.zip. Keine Platzhalter und keine erfundenen Dateilinks.'
    . "\n[COREUI_PLANUNGSKONTEXT]\n"
    . "Ausfuehrungsauftrag vom Modell, als Projektanforderungen behandeln:\n"
    . $description . "\n";
  if ($source !== '') {
    $header .= "Der folgende persistierte Plan ist verbindlicher Projektkontext ("
      . strlen($source) . " Bytes, Version ".(int)$plan['version']."):\n" . $source . "\n";
  }
  return $header . "[COREUI_ENDE_PLANUNGSKONTEXT]\n";
}

/**
 * Execute one model-selected tool safely. No shell, SQL, URL or arbitrary
 * PHP command can be passed by the model. The build callback reuses the
 * already secured per-turn Python source/ZIP writer once and ONLY once.
 */
function coreui_native_tool_apply(
  PDO $pdo,int $uid,string $sessionId,int $turnId,string $reply,callable $build
): ?string {
  if (!empty($GLOBALS['COREUI_NATIVE_TOOL_REENTRY'])) return null;
  $tool = coreui_native_tool_parse($reply);
  if ($tool === null) return null;
  if ($uid <= 0 || $turnId <= 0 || !preg_match('~^[A-Za-z0-9_-]{8,40}$~D',$sessionId))
    return 'Der Werkzeugauftrag besitzt keinen gueltigen privaten Chatkontext.';
  $kind = $tool['type'];
  if (in_array($kind,['PLAN_SAVE','PLAN_APPEND','PLAN_AND_BUILD'],true)) {
    try {
      $saved = coreui_plan_save($pdo,$uid,$sessionId,$turnId,$tool['body'],
        $kind==='PLAN_APPEND');
    } catch (Throwable $e) {
      error_log('[CoreUI] Native plan save failed: '.get_class($e));
      return 'Ich konnte den Markdown-Projektplan nicht dauerhaft speichern. '
        . 'Die Datenbankmigration und die maximale Dateigroesse muessen geprueft werden.';
    }
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo,'artifact','success','Markdown-Projektplan gespeichert',
        ['file_name'=>'projektplan-v'.(int)$saved['version'].'.md',
          'source'=>'native_plan_tool']);
    }
    if ($kind !== 'PLAN_AND_BUILD') {
      return 'Ich habe den aktuellen Projektplan als Markdown-Version '
        . (int)$saved['version'] . ' dauerhaft gespeichert. '
        . 'Die Datei erscheint als geschuetzte Downloadkarte an meiner Antwort.';
    }
    $description = 'Setze den gespeicherten Plan als vollstaendiges statisches Webprojekt um.';
  } else {
    $description = $tool['body'];
  }
  if (function_exists('ember_py_enabled') && !ember_py_enabled()) {
    return 'Der Datei-Writer ist derzeit deaktiviert. Es wurde kein Projekt erstellt.';
  }
  $request = coreui_native_tool_build_request($pdo,$uid,$sessionId,$description);
  if ($request === null) {
    return 'Die Projektquellen konnten nicht sicher zusammengestellt werden. '
      . 'Entweder ist die Dateiliste ungueltig oder der gespeicherte Plan '
      . 'ist zu gross fuer eine einzelne Modellgenerierung. Die Markdown-Datei bleibt erhalten.';
  }
  if (function_exists('coreui_work_record')) {
    coreui_work_record($pdo,'tool','info','Mara hat selbst den Projekt-Writer aufgerufen',
      ['reason'=>'model_requested_project_build','file_count'=>3]);
  }
  $GLOBALS['COREUI_NATIVE_TOOL_REENTRY'] = true;
  try {
    $response = $build($request);
    return is_string($response) && trim($response) !== ''
      ? $response
      : 'Der Projekt-Writer hat keinen bestaetigten Download geliefert.';
  } catch (Throwable $e) {
    error_log('[CoreUI] Native project build failed: '.get_class($e));
    return 'Der Projektauftrag konnte nicht abgeschlossen werden. Es wird kein Download vorgetaeuscht.';
  } finally {
    unset($GLOBALS['COREUI_NATIVE_TOOL_REENTRY']);
  }
}
