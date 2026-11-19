<?php
declare(strict_types=1);
require_once __DIR__ . '/console_plan_store.php';

/**
 * Extend a model-selected draft in several small, serial model calls.
 * Only private, versioned Markdown is written. No shell, SQL from the model,
 * web navigation, project build, or recursive native-tool dispatch occurs here.
 */
function coreui_plan_draft_text(string $savedMarkdown): string {
  if (coreui_plan_is_draft_markdown($savedMarkdown)) {
    return trim(substr($savedMarkdown, strlen(COREUI_PLAN_DRAFT_MARKER)));
  }
  return trim($savedMarkdown);
}

function coreui_plan_followup_prompt(string $markdown, string $goal): string {
  $body = coreui_plan_draft_text($markdown);
  $headings = [];
  if (preg_match_all('~^#{1,4}[ \t]+[^\r\n]+~mu', $body, $m)) {
    $headings = array_slice($m[0], -24);
  }
  $goal = function_exists('mb_substr')
    ? mb_substr($goal, 0, 1200, 'UTF-8') : substr($goal, 0, 1200);
  $tail = function_exists('mb_substr')
    ? mb_substr($body, -1800, null, 'UTF-8') : substr($body, -1800);
  return "Du setzt einen bereits von dir begonnenen und PRIVAT als Markdown "
    . "gespeicherten Projektplan fort. Ein Teil ist bereits in der Datei. "
    . "Dies ist nur der PLANUNGS-TEILSCHRITT des laufenden Auftrags. "
    . "Nach einem nachweislich vollstaendigen Plan prueft eine separate, "
    . "berechtigungsgeschuetzte Steuerung den ORIGINALAUFTRAG und setzt "
    . "eine ausdruecklich beauftragte Homepage eigenstaendig technisch um. "
    . "Schreibe in dieser Modellantwort nur Markdown-Planabschnitte, "
    . "keine ausfuehrbaren Befehle oder Projektdateien.\n"
    . "ZIEL/AUFTRAG:\n" . $goal . "\n\n"
    . "BEREITS VORHANDENE UEBERSCHRIFTEN (nicht duplizieren):\n"
    . implode("\n", $headings) . "\n\n"
    . "LETZTER AUSSCHNITT DER DATEI:\n" . $tail . "\n\n"
    . "Schreibe NUR den naechsten noch fehlenden, eigenstaendigen Markdown-"
    . "Abschnitt mit konkreten Anforderungen, Teilaufgaben und Abnahmekriterien. "
    . "Fuer eine Homepage plane Seiten, Dateistruktur, Navigation, Design und "
    . "Responsivitaet anhand der bereits bekannten Anforderungen, ohne Neues "
    . "als bestaetigte Nutzerentscheidung zu erfinden. "
    . "Halte den einzelnen Abschnitt geschlossen und nachvollziehbar, "
    . "auch wenn nur wenig Platz in dieser Modellantwort bleibt. "
    . "Wiederhole keinen vorhandenen Titel, Text oder Anfangsmarker. "
    . "Nutze ## oder ### fuer neue Abschnitte. Keine Einleitung, keine Codefences, "
    . "keinen [COREUI_PLAN_SAVE] oder anderen Werkzeugaufruf. "
    . "Wenn danach wirklich alle geforderten Teile vollstaendig sind, "
    . "schreibe am Ende in einer EIGENEN ZEILE: [COREUI_PLAN_COMPLETE]. "
    . "Falls weitere Abschnitte fehlen, lass diesen Abschlussmarker weg. "
    . "Ein blosses Ende deiner Antwort bedeutet NICHT, dass der Plan fertig ist.";
}

/**
 * One safe corrective request for a valid provider response that was formatted
 * as chatter or a tool marker instead of inert plan Markdown. No tool dispatch
 * and no invented completion marker on behalf of the model.
 */
function coreui_plan_followup_repair_prompt(string $original): string {
  return $original . "\n\nKORREKTUR: Deine letzte Ausgabe war KEIN gueltiger "
    . "Markdown-Planabschnitt. Schreibe nun ausschliesslich einen NEUEN "
    . "Abschnitt beginnend mit ## oder ### und vollstaendigen Stichpunkten. "
    . "Keine Vorrede, keine Codefences, keine Werkzeugmarker, keine alten "
    . "Abschnitte wiederholen. [COREUI_PLAN_COMPLETE] nur als letzte Zeile, "
    . "falls nach diesem Abschnitt wirklich alles geplant ist.";
}

function coreui_plan_followup_parse(string $raw): ?array {
  $raw = trim($raw);
  if ($raw === '' || strlen($raw) > 20000 || preg_match('//u', $raw) !== 1
      || str_contains($raw, "\0")) return null;
  // Some Gemma models wrap the chunk in an explicit PLAN_APPEND block.
  // Recover ONLY this one complete, inert Markdown wrapper, never a build.
  if (preg_match('~^\[COREUI_PLAN_APPEND\]\s*\n([\s\S]*?)\n\[/COREUI_PLAN_APPEND\]\s*$~u',
      $raw, $wrapped) === 1) {
    $raw = trim((string)$wrapped[1]);
  }
  // Follow-up turns are otherwise content only, never new tool actions.
  if (preg_match('~\[(?:/?COREUI_(?:PROJECT_BUILD|PLAN_AND_BUILD|PLAN_APPEND)|COREUI/)\b~i', $raw))
    return null;
  if (preg_match('~(?mi)^[ \t]*\[COREUI_PLAN_SAVE\][ \t]*$~', $raw)) return null;

  $end = '~(?:^|\r?\n)[ \t]*(?:\[COREUI_PLAN_COMPLETE\]|\[/COREUI_PLAN_SAVE\]|\[/COREUI/PLANSAVE\])[ \t]*$~i';
  $done = preg_match($end, $raw) === 1;
  if ($done) $raw = trim((string)preg_replace($end, '', $raw, 1));
  if (preg_match('~\[(?:/?COREUI_[A-Z_]+|/?COREUI/[A-Z_]+)\]~i', $raw)) return null;
  if ($raw !== '' && preg_match('~^#{1,4}[ \t]+\S|^[-*][ \t]+\S~u', $raw) !== 1) {
    // Ignore at most a short, harmless model preface before the first real
    // Markdown section. Do not treat an explanation as file content.
    if (preg_match('~(?m)^#{2,4}[ \t]+\S~u', $raw, $heading, PREG_OFFSET_CAPTURE) !== 1)
      return null;
    $prefixBytes = (int)$heading[0][1];
    $prefix = trim(substr($raw,0,$prefixBytes));
    if ($prefixBytes > 240 || str_contains($prefix,'[')
        || str_contains($prefix,chr(96).chr(96).chr(96))) return null;
    $raw = ltrim(substr($raw,$prefixBytes));
  }
  if (!$done && strlen($raw) < 65) return null;
  return ['text'=>$raw,'done'=>$done];
}

function coreui_plan_merge_chunk(string $base, string $new): ?string {
  $base = rtrim($base);
  $new = trim($new);
  if ($base === '') return $new !== '' ? $new : null;
  if ($new === '') return $base;

  // Reject a fresh copy of the beginning or an already stored section.
  if (strlen($new) >= 80 && str_contains($base, $new)) return null;
  if (preg_match('~^#[ \t]+[^\r\n]+~u', $new, $m)
      && str_starts_with($base, $m[0])) return null;
  $overlap = 0;
  for ($n = min(3000, strlen($base), strlen($new)); $n >= 24; $n--) {
    if (substr($base, -$n) === substr($new, 0, $n)) {
      $overlap = $n;
      break;
    }
  }
  if ($overlap > 0) $new = ltrim(substr($new, $overlap));
  if ($new === '') return null;
  // A resumed earlier paragraph is not a new section.
  $lead = substr($new, 0, min(90, strlen($new)));
  if (strlen($lead) >= 64 && strpos($base, $lead) !== false) return null;
  $merged = $base . ($overlap > 0 ? '' : "\n\n") . $new;
  return strlen($merged) <= 260000 ? $merged : null;
}

function coreui_plan_has_outline(string $markdown): bool {
  return strlen($markdown) >= 300
    && preg_match('~^#[ \t]+\S~mu', $markdown) === 1
    && preg_match_all('~^#{2,4}[ \t]+\S~mu', $markdown) >= 2;
}

/**
 * The generator returns ['text'=>string,'ok'=>bool] for one MODEL call.
 * Each successful content chunk creates one immutable, owner-scoped DB version.
 * A complete plan requires both a verified model call and an explicit terminal
 * marker. Production continues across saved checkpoints until completion,
 * cancellation, stalled progress or error. Tests can request bounded rounds.
 */
function coreui_plan_autorun(PDO $pdo, int $uid, string $sessionId, int $turnId,
                            array $initial, callable $generate,
                            string $goal = '', int $maxRounds = 0): array {
  $saved = $initial;
  $passes = 0;
  $reason = 'no_progress';
  // Positive maxRounds is an explicit test/diagnostic bound only. Production
  // must never stop after an arbitrary four calls or a 240-second timeout.
  // Each verified chunk is persisted and failure/cancellation ends the loop.
  $bounded = $maxRounds > 0;
  for ($step = 0; !$bounded || $step < $maxRounds; $step++) {
    if (!coreui_plan_is_draft_markdown((string)($saved['markdown'] ?? ''))) {
      $reason = 'completed';
      break;
    }
    if (function_exists('ember_agent_cancel_requested') && ember_agent_cancel_requested()) {
      $reason = 'cancelled';
      break;
    }
    $prompt = coreui_plan_followup_prompt((string)$saved['markdown'], $goal);
    try {
      $generated = $generate($prompt);
    } catch (Throwable $e) {
      error_log('[CoreUI] Autonomous plan continuation: '.get_class($e));
      $reason = 'generation_error';
      break;
    }
    if (is_string($generated)) $generated = ['text'=>$generated,'ok'=>true];
    if (!is_array($generated)) { $reason='generation_empty'; break; }
    $isVerified = !empty($generated['ok']);
    $parsed = coreui_plan_followup_parse((string)($generated['text'] ?? ''));
    // Some local models reply with commentary/markers despite the narrow
    // Markdown instruction. A single in-turn repair is safer than silently
    // ending the whole website task and asking the user for "Continue".
    // Never retry a transport failure or trust its partial output as complete.
    if ($parsed === null && $isVerified) {
      if (function_exists('coreui_work_record')) {
        coreui_work_record($pdo, 'model', 'info',
          'Planformat wird im selben Auftrag korrigiert',
          ['reason'=>'invalid_model_chunk','step'=>$step+1]);
      }
      try {
        $corrected = $generate(coreui_plan_followup_repair_prompt($prompt));
      } catch (Throwable $e) {
        error_log('[CoreUI] Autonomous plan correction: ' . get_class($e));
        $corrected = null;
      }
      if (is_string($corrected)) $corrected = ['text'=>$corrected,'ok'=>true];
      if (is_array($corrected) && !empty($corrected['ok'])) {
        $parsed = coreui_plan_followup_parse((string)($corrected['text'] ?? ''));
        // Provider success does not make malformed content a completed plan.
      }
    }
    if ($parsed === null) {
      $reason = $isVerified ? 'invalid_model_chunk' : 'model_incomplete';
      break;
    }
    $body = coreui_plan_draft_text((string)$saved['markdown']);
    $combined = coreui_plan_merge_chunk($body, (string)$parsed['text']);
    if ($combined === null) { $reason='repeated_or_invalid_section'; break; }
    $complete = $isVerified && $parsed['done'] && coreui_plan_has_outline($combined);
    if ($combined === $body && !$complete) { $reason='no_progress'; break; }
    $text = $complete
      ? $combined : COREUI_PLAN_DRAFT_MARKER . "\n\n" . $combined;
    if (strlen($text) > 262144) { $reason='plan_too_large'; break; }
    try {
      $saved = coreui_plan_save($pdo,$uid,$sessionId,$turnId,$text,false);
    } catch (Throwable $e) {
      error_log('[CoreUI] Plan checkpoint save: '.get_class($e));
      $reason='checkpoint_failed';
      break;
    }
    $passes++;
    if (function_exists('coreui_work_record')) {
      coreui_work_record($pdo,'artifact','success',
        $complete ? 'Markdown-Projektplan abgeschlossen'
                  : 'Markdown-Planungsabschnitt gesichert',
        ['file_name'=>'projektplan-v'.(int)$saved['version'].'.md',
         'plan_version'=>(int)$saved['version'],'step'=>$step+1]);
    }
    if ($complete) { $reason='completed'; break; }
    if (!$isVerified) { $reason='model_incomplete'; break; }
    $reason = $bounded ? 'round_limit' : 'awaiting_next_section';
  }
  if ($reason !== 'completed' && $reason !== 'round_limit'
      && function_exists('coreui_work_record')) {
    coreui_work_record($pdo,'model',
      $reason === 'cancelled' ? 'info' : 'error',
      $reason === 'cancelled'
        ? 'Planfortsetzung auf Wunsch gestoppt'
        : 'Planfortsetzung mit nachvollziehbarem Fehler beendet',
      ['reason'=>$reason,'segments'=>$passes,
        'plan_version'=>(int)($saved['version'] ?? 0)]);
  }
  return ['saved'=>$saved,'segments'=>$passes,
    'complete'=>!coreui_plan_is_draft_markdown((string)$saved['markdown']),
    'reason'=>$reason];
}
