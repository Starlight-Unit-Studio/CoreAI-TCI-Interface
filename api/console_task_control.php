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
 * 0.8.15: the reply as the decision sees it (pure, no words are interpreted): trimmed, and the
 * bounded public progress note removed together with any blank lines after it. A code fence is NOT
 * removed: a tool marker inside a fence stays refused by the tool parser; a DONE/BLOCKED block is
 * found wherever it stands anyway.
 */
function coreui_task_decision_normalise(string $reply): string {
  return trim((string)preg_replace(
    '~\A\[COREUI_PUBLIC_PROGRESS\][^\r\n]{1,480}\[/COREUI_PUBLIC_PROGRESS\][ \t]*(?:\r?\n[ \t]*)*~u', '', trim($reply), 1));
}

/** 0.8.15: what an invalid decision looked like (structure only, never model text). */
const COREUI_TASK_SHAPES = [
  'empty' => 'eine leere Antwort',
  'prose_only' => 'nur Text ohne Werkzeugmarker und ohne Steuerblock',
  'block_unclosed' => 'ein Steuerblock ohne passenden Abschluss-Marker',
  'block_multiple' => 'mehrere oder vermischte Steuerbloecke',
  'block_empty' => 'ein leerer Steuerblock',
  'block_and_tool' => 'ein Steuerblock zusammen mit einem Werkzeugmarker',
  'marker_unknown' => 'ein unbekannter COREUI_TASK-Marker',
];

/**
 * Pure decision from one model reply. The tool parser is the existing
 * allowlist-scoped parser, not a second, weaker tool protocol.
 * 0.8.15: one complete DONE or BLOCKED block counts wherever it stands (after a progress note and
 * blank lines, with text around it, on one line, inside a code fence, with Windows line ends); the
 * text outside it is not shown. Two blocks, an unclosed block, a block together with a tool marker or an
 * unknown COREUI_TASK marker stay invalid. 'shape' names what an invalid reply looked like.
 * @return array{kind:string,text:string,tool:?array,shape:string}
 */
function coreui_task_decision(string $reply, callable $parseTool): array {
  $reply = coreui_task_decision_normalise($reply);
  $invalid = static fn(string $shape): array => ['kind'=>'invalid', 'text'=>'', 'tool'=>null, 'shape'=>$shape];
  if ($reply === '') return $invalid('empty');
  $count = (int)preg_match_all('~\[(/?)COREUI_TASK_([A-Z_]+)\]~', $reply, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
  if ($count === 0) {
    $action = $parseTool($reply);
    return is_array($action) ? ['kind'=>'action', 'tool'=>$action, 'text'=>'', 'shape'=>''] : $invalid('prose_only');
  }
  $kinds = ['DONE'=>'done', 'BLOCKED'=>'blocked'];
  foreach ($hits as $hit) {
    if (!isset($kinds[$hit[2][0]])) return $invalid('marker_unknown');
  }
  // Incomplete or several terminal frames must never make tool-looking text executable.
  if ($count === 1) return $invalid('block_unclosed');
  if ($count > 2) return $invalid('block_multiple');
  [$open, $close] = $hits;
  if ($open[1][0] !== '' || $close[1][0] !== '/') return $invalid('block_unclosed');
  if ($open[2][0] !== $close[2][0]) return $invalid('block_multiple');
  $from = $open[0][1] + strlen($open[0][0]);
  $body = trim(substr($reply, $from, $close[0][1] - $from));
  if ($body === '') return $invalid('block_empty');
  $outside = trim(substr($reply, 0, $open[0][1]) . "\n" . substr($reply, $close[0][1] + strlen($close[0][0])));
  if (is_array($parseTool($body)) || ($outside !== '' && is_array($parseTool($outside)))) return $invalid('block_and_tool');
  return ['kind'=>$kinds[$open[2][0]], 'text'=>$body, 'tool'=>null, 'shape'=>''];
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
function coreui_task_decision_repair_prompt(string $verifiedContext, string $shape = ''): string {
  // 0.8.15: name the structural form that was refused (never the model text itself).
  $seen = COREUI_TASK_SHAPES[$shape] ?? '';
  return $verifiedContext . coreui_task_protocol_prompt()
    . "\nDeine letzte Ausgabe war keine gueltige Steuerentscheidung"
    . ($seen !== '' ? ' (erkannt: ' . $seen . ')' : '') . ". "
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
