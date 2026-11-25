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
// 0.8.16: at most this much of a refused reply is shown back to the model in its one repair.
const COREUI_TASK_REPAIR_ECHO_CHARS = 4000;

/**
 * 0.8.15: the reply as the decision sees it (pure, no words are interpreted): trimmed, and the
 * bounded public progress note removed together with any blank lines after it. A code fence is NOT
 * removed: a tool marker inside a fence stays refused by the tool parser; a DONE/BLOCKED block is
 * found wherever it stands anyway.
 */
function coreui_task_decision_normalise(string $reply): string {
  // 0.8.16: a control marker written the Markdown way (\[COREUI\_TASK\_DONE\], **[COREUI_TASK_DONE]**,
  // `[COREUI_TASK_DONE]`) is the same marker.
  $reply = (string)preg_replace('~\\\\?\[[ \t]*(/?)[ \t]*COREUI\\\\?_TASK\\\\?_([A-Z]+)[ \t]*\\\\?\]~', '[$1COREUI_TASK_$2]', $reply);
  $reply = (string)preg_replace('~(?:\*\*|`)(\[/?COREUI_TASK_[A-Z]+\])(?:\*\*|`)~', '$1', $reply);
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
  'marker_malformed' => 'COREUI_TASK ohne gueltige eckige Klammern',
  // 0.8.17: a file or code block that was opened but never closed (it counted as prose_only before).
  'tool_unclosed' => 'ein Werkzeugblock ohne Schlussmarker',
  'command_multiline' => 'ein mehrzeiliger AUSFUEHREN-Aufruf',
  // 0.8.17: the provider or Ollama ended the answer at its length limit.
  'output_truncated' => 'eine an der Ausgabegrenze abgeschnittene Antwort',
];

/**
 * 0.8.17: did the model call that produced a reply end at a length limit? Pure, from the report of
 * the last call (done_reason of Ollama, or finish_reason of an external API in Ollama's words).
 */
function coreui_task_output_cut(array $report): bool {
  $reason = strtolower(trim((string)($report['done_reason'] ?? '')));
  if (in_array($reason, ['length', 'max_tokens', 'token_limit', 'model_length'], true)) return true;
  return !empty($report['done_seen']) && empty($report['done']) && !empty($report['transport_ok']);
}

/** 0.8.17: a project block tool opened in the reply but never closed (pure, structure only). */
function coreui_task_unclosed_tool_block(string $reply): bool {
  if (preg_match_all('~\[\s*(DATEI_NEU|DATEI_AENDERN)\s*:[^\]\r\n]*\]~u', $reply, $open) > 0) {
    foreach (array_unique($open[1]) as $name) {
      $opened = preg_match_all('~\[\s*' . $name . '\s*:[^\]\r\n]*\]~u', $reply);
      $closed = preg_match_all('~\[\s*/\s*' . $name . '\s*\]~u', $reply);
      if ($closed < $opened) return true;
    }
  }
  return preg_match_all('~\[PY\]~i', $reply) > preg_match_all('~\[/PY\]~i', $reply);
}

/** A command spanning lines cannot pass the single-line project command parser. */
function coreui_task_multiline_command(string $reply): bool {
  return preg_match('~(?:\A|\R)[ \t]*\[\s*AUSF(?:UE|Ü)HREN[ \t]*:[^\]\r\n]*\R~iu', $reply) === 1;
}

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
    $fileBlock = in_array($action['tool'] ?? '', ['pfile_write', 'pfile_edit'], true);
    if (!$fileBlock && coreui_task_multiline_command($reply)) return $invalid('command_multiline');
    if (is_array($action)) return ['kind'=>'action', 'tool'=>$action, 'text'=>'', 'shape'=>''];
    if (coreui_task_unclosed_tool_block($reply)) return $invalid('tool_unclosed');
    return $invalid(stripos($reply, 'COREUI_TASK') !== false ? 'marker_malformed' : 'prose_only');
  }
  if (coreui_task_multiline_command($reply)) return $invalid('command_multiline');
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
    . "A) Brauchst du weitere Arbeit, liefere GENAU EINEN erlaubten Werkzeugmarker als Antworttext. "
    . "CoreAI fuehrt den Marker aus und liefert das Ergebnis. Die freigegebenen Marker sind auch "
    . "ohne separate Funktionsaufrufe des Modellanbieters nutzbar.\n"
    . "B) Sind alle beauftragten Ergebnisse nachweisbar erstellt UND geprueft, antworte so:\n"
    . COREUI_TASK_DONE_OPEN . "\nKurze ehrliche Antwort mit nachgewiesenen Ergebnissen.\n"
    . COREUI_TASK_DONE_CLOSE . "\n"
    . "C) Ist echte Weiterarbeit blockiert, antworte so:\n"
    . COREUI_TASK_BLOCKED_OPEN . "\nKurze sachliche Blockade und gesicherter Stand.\n"
    . COREUI_TASK_BLOCKED_CLOSE . "\n"
    // 0.8.17: a run was declared blocked only because no test had been run; checking is work (A).
    . "Geprueft heisst: ein Werkzeug hat es bestaetigt (gespeicherte oder gelesene Datei, Befehl mit "
    . "Exitcode 0). Fehlt nur noch eine Pruefung, fuehre sie mit einem erlaubten Werkzeug aus; das ist A, "
    . "keine Blockade. C nur, wenn du mit den erlaubten Werkzeugen nicht weiterkommst, etwa weil eine "
    . "Entscheidung der Person fehlt.\n"
    // 0.8.17: a whole large page in one block ran into the output limit and lost its end marker.
    . "Grosse Dateien schreibst du in Teilen: zuerst ein kurzes, vollstaendiges Grundgeruest, danach je "
    . "Abschnitt ein eigener Aenderungsblock. Jeder Werkzeugblock endet mit seinem Schlussmarker.\n"
    . "Eine Zwischenmeldung, ein Plan, ein blosses Versprechen oder das Ende einer "
    . "Modellausgabe ist KEIN Abschluss. Erfinde weder Werkzeuge noch Dateien, "
    . "Testresultate oder einen erfolgreichen Server. Beende NICHT bei offener Arbeit.\n";
}

/**
 * 0.8.17: the repair after a cut-off reply. Showing the model its own cut reply and asking for the
 * same tool again only reproduced the cut (the same whole page ran into the same limit). Nothing of a
 * cut block was saved; the model is asked for a SMALLER next step instead.
 */
function coreui_task_truncation_repair_text(string $shape): string {
  return "\nDeine letzte Ausgabe brach ab, bevor der Werkzeugblock seinen Schlussmarker hatte"
    . ($shape === 'output_truncated' ? ' (die Ausgabegrenze war erreicht)' : '') . ". "
    . "Davon wurde NICHTS gespeichert. Schreibe die Datei nicht noch einmal am Stueck. "
    . "Liefere einen kleineren naechsten Schritt: fuer eine neue Datei ein kurzes, vollstaendiges "
    . "Grundgeruest mit [DATEI_NEU: pfad] ... [/DATEI_NEU], fuer eine vorhandene Datei nur die "
    . "betroffenen Zeilen mit [DATEI_AENDERN: pfad] ... [/DATEI_AENDERN]. Weitere Abschnitte "
    . "folgen in eigenen Schritten. Ein Abschluss- oder Blockadetext bleibt kurz.";
}

/**
 * A format repair requests a CONTROL decision, never an inferred file path.
 * The caller must send through its serial model queue and must not replay
 * the already confirmed tool. Retry at most once for a malformed decision
 * to avoid unlimited calls and runaway user costs.
 */
function coreui_task_decision_repair_prompt(string $verifiedContext, string $shape = '', string $previous = ''): string {
  // 0.8.15: name the structural form that was refused (never shown to anyone but the model).
  $seen = COREUI_TASK_SHAPES[$shape] ?? '';
  // 0.8.16: the model sees its own refused reply, so it can put that text into the right block
  // instead of writing the same prose again (it had no idea what to change). Only to the model.
  $previous = trim($previous);
  if (function_exists('mb_strlen') && mb_strlen($previous, 'UTF-8') > COREUI_TASK_REPAIR_ECHO_CHARS) {
    $previous = mb_substr($previous, 0, COREUI_TASK_REPAIR_ECHO_CHARS, 'UTF-8') . ' [...]';
  }
  // Stabilize 0.8.33: the echo said "nicht erneut ausfuehren", so a Core whose reply held an unexecuted
  // tool block believed it was done and repeated DONE. Nothing of a refused reply ran or was saved.
  $echo = $previous === '' ? '' : "\nDeine letzte Ausgabe (nur zur Korrektur; nichts daraus wurde gespeichert oder ausgefuehrt):\n<<<\n"
    . str_replace(['<<<', '>>>'], ['< < <', '> > >'], $previous) . "\n>>>\n"
    . "Beschreibt sie den fertigen Auftrag, gib genau diesen Text innerhalb von " . COREUI_TASK_DONE_OPEN
    . " und " . COREUI_TASK_DONE_CLOSE . " zurueck. Beschreibt sie eine Blockade, nimm " . COREUI_TASK_BLOCKED_OPEN
    . " und " . COREUI_TASK_BLOCKED_CLOSE . ". Ist noch Arbeit offen, antworte nur mit dem naechsten Werkzeugmarker."
    . " Enthielt sie einen Werkzeugblock, wurde er NICHT ausgefuehrt: sende ihn allein noch einmal.";
  // 0.8.17: a cut-off block is not echoed (it would be written again and cut again).
  if ($shape === 'command_multiline' || ($shape === 'output_truncated' && coreui_task_multiline_command($previous))) {
    $echo = "\nDer vorige AUSFUEHREN-Aufruf war mehrzeilig und wurde nicht ausgefuehrt. "
        . "Nutze einen einzigen vollstaendigen [AUSFUEHREN: befehl]-Marker auf einer Zeile. "
        . "Wenn du ein laengeres Pruefskript brauchst, speichere es zuerst mit DATEI_NEU.";
  } elseif (in_array($shape, ['tool_unclosed', 'output_truncated'], true)) {
    $echo = coreui_task_truncation_repair_text($shape);
  } elseif ($shape === 'block_and_tool') {
    $echo = coreui_task_block_and_tool_repair_text();
  }
  // Stabilize 0.8.33: a repair built on the full step prompt (same prefix, cached by the model server)
  // already ends with the protocol; it is not repeated.
  $protocol = str_contains($verifiedContext, coreui_task_protocol_prompt()) ? '' : coreui_task_protocol_prompt();
  return $verifiedContext . $protocol . $echo
    . "\nDeine letzte Ausgabe war keine gueltige Steuerentscheidung"
    . ($seen !== '' ? ' (erkannt: ' . $seen . ')' : '') . ". "
    . "Beantworte mit genau einem erlaubten Werkzeugmarker oder einem "
    . "vollstaendigen COREUI_TASK_DONE/COREUI_TASK_BLOCKED-Block. "
    . "Keine Erklaerung vor oder nach dem Steuerblock. Bereits bestaetigte "
    . "Dateioperationen aus frueheren Schritten NICHT erneut ausfuehren.";
}

/**
 * Stabilize 0.8.33: the repair for a tool marker sent together with a DONE or BLOCKED block. The run
 * was reported completed although the tool (a new page) was never saved: the old repair did not say
 * that nothing had been executed.
 */
function coreui_task_block_and_tool_repair_text(): string {
  return "\nIn deiner letzten Ausgabe standen ein Werkzeugmarker und ein Steuerblock zusammen. "
    . "Davon wurde NICHTS gespeichert oder ausgefuehrt. Ist noch Arbeit offen, sende NUR den Werkzeugmarker "
    . "mit seinem Schlussmarker, ohne " . COREUI_TASK_DONE_OPEN . " in derselben Antwort. Den Abschluss meldest du "
    . "erst in einer eigenen Antwort, nachdem das Werkzeug das Ergebnis bestaetigt hat.";
}

/**
 * Stabilize 0.8.33 (pure): a reply that holds exactly one complete DONE block and, OUTSIDE it, a complete
 * authorized tool marker ("[DATEI_NEU: about.html] ... [/DATEI_NEU]" followed by DONE). The DONE is
 * premature: the tool has not run. Returns that tool, so the caller runs it and drops the DONE; the
 * Core decides again with the real result. A tool inside the block, a BLOCKED block, several blocks or
 * a multiline command return null and stay a refused reply.
 */
function coreui_task_premature_done_tool(string $reply, callable $parseTool): ?array {
  $reply = coreui_task_decision_normalise($reply);
  if (coreui_task_multiline_command($reply)) return null;
  if ((int)preg_match_all('~\[(/?)COREUI_TASK_([A-Z_]+)\]~', $reply, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== 2) return null;
  [$open, $close] = $hits;
  if ($open[1][0] !== '' || $close[1][0] !== '/' || $open[2][0] !== 'DONE' || $close[2][0] !== 'DONE') return null;
  $from = $open[0][1] + strlen($open[0][0]);
  if (is_array($parseTool(substr($reply, $from, $close[0][1] - $from)))) return null;
  $outside = trim(substr($reply, 0, $open[0][1]) . "\n" . substr($reply, $close[0][1] + strlen($close[0][0])));
  if ($outside === '' || coreui_task_unclosed_tool_block($outside)) return null;
  $tool = $parseTool($outside);
  return is_array($tool) ? $tool : null;
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
