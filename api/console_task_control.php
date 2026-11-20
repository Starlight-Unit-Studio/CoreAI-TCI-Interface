<?php
declare(strict_types=1);

/**
 * E.M.B.E.R. agent task-control protocol.
 *
 * Model prose is NOT a terminal run state. After a verified agent tool
 * result the model must choose an authorized next tool, an explicit terminal
 * outcome, or an explicit block. Unknown output is an INVALID decision, not
 * success. This is intentionally independent of language, model/provider,
 * file types and "I will now..." phrasing.
 *
 * The caller owns permissions, actual tool execution, cancellation, saved
 * checkpoints, and completion evidence. No model text grants authority.
 */
const COREUI_TASK_DONE_OPEN = '[COREUI_TASK_DONE]';
const COREUI_TASK_DONE_CLOSE = '[/COREUI_TASK_DONE]';
const COREUI_TASK_BLOCKED_OPEN = '[COREUI_TASK_BLOCKED]';
const COREUI_TASK_BLOCKED_CLOSE = '[/COREUI_TASK_BLOCKED]';

/**
 * Pure decision from one model reply. The tool parser is the existing
 * allowlist-scoped parser, not a second, weaker tool protocol.
 * @return array{kind:string,text:string,tool:?array}
 */
function coreui_task_decision(string $reply, callable $parseTool): array {
  $reply = trim($reply);
  foreach ([
    'done'=>[COREUI_TASK_DONE_OPEN,COREUI_TASK_DONE_CLOSE],
    'blocked'=>[COREUI_TASK_BLOCKED_OPEN,COREUI_TASK_BLOCKED_CLOSE]
  ] as $kind => $markers) {
    if (!str_starts_with($reply, $markers[0] . "\n")
        || !str_ends_with($reply, "\n" . $markers[1])) continue;
    $body = trim(substr($reply, strlen($markers[0]) + 1,
      -strlen($markers[1]) - 1));
    if ($body === '' || str_contains($body, '[COREUI_TASK_')) continue;
    return ['kind'=>$kind,'text'=>$body,'tool'=>null];
  }
  $action = $parseTool($reply);
  if (is_array($action)) return ['kind'=>'action','tool'=>$action,'text'=>''];
  return ['kind'=>'invalid','text'=>'','tool'=>null];
}

/** Same contract for every model and every kind of authorized project tool. */
function coreui_task_protocol_prompt(): string {
  return "\n\nAUFTRAGSSTEUERUNG (verbindlich, nicht Teil des sichtbaren Texts):\n"
    . "Der Auftrag ist aktiv. Nach jedem Werkzeugergebnis entscheide mit GENAU EINER Form:\n"
    . "A) Brauchst du weitere Arbeit, liefere GENAU EINEN erlaubten Werkzeugmarker.\n"
    . "B) Sind alle beauftragten Ergebnisse nachweisbar erstellt UND geprueft, antworte so:\n"
    . COREUI_TASK_DONE_OPEN . "\nKurze ehrliche Antwort mit nachgewiesenen Ergebnissen.\n"
    . COREUI_TASK_DONE_CLOSE . "\n"
    . "C) Ist echte Weiterarbeit blockiert, antworte so:\n"
    . COREUI_TASK_BLOCKED_OPEN . "\nKurze sachliche Blockade und gesicherter Stand.\n"
    . COREUI_TASK_BLOCKED_CLOSE . "\n"
    . "Eine Zwischenmeldung, ein Plan, ein blosses Versprechen oder das Ende einer "
    . "Modellausgabe ist KEIN Abschluss. Erfinde weder Werkzeuge noch Dateien, "
    . "Testresultate oder einen erfolgreichen Server. Beende NICHT bei offener Arbeit.\n";
}

/**
 * A format repair requests a CONTROL decision, never an inferred file path.
 * The caller must send through its serial model queue and must not replay
 * the already confirmed tool. Retry at most once for a malformed decision
 * to avoid unlimited calls and runaway user costs.
 */
function coreui_task_decision_repair_prompt(string $verifiedContext): string {
  return $verifiedContext . coreui_task_protocol_prompt()
    . "\nDeine letzte Ausgabe war keine gueltige Steuerentscheidung. "
    . "Beantworte mit genau einem erlaubten Werkzeugmarker oder einem "
    . "vollstaendigen COREUI_TASK_DONE/COREUI_TASK_BLOCKED-Block. "
    . "Keine Erklaerung vor oder nach dem Steuerblock. Bereits bestaetigte "
    . "Dateioperationen NICHT erneut ausfuehren.";
}

/** A model completion assertion is accepted only after actual tool evidence. */
function coreui_task_completion_evidence(array $steps, array $outstandingEdits): bool {
  if ($steps === [] || $outstandingEdits !== []) return false;
  $last = $steps[count($steps)-1] ?? null;
  if (!is_array($last) || ($last['state'] ?? '') !== 'success') return false;
  foreach ($steps as $step) {
    if (!is_array($step) || !isset($step['state'])) return false;
  }
  return true;
}
