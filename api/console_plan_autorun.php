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
    . "Die Aufgabe ist Planung, NICHT Quellcode oder Projektbau.\n"
    . "ZIEL/AUFTRAG:\n" . $goal . "\n\n"
    . "BEREITS VORHANDENE UEBERSCHRIFTEN (nicht duplizieren):\n"
    . implode("\n", $headings) . "\n\n"
    . "LETZTER AUSSCHNITT DER DATEI:\n" . $tail . "\n\n"
    . "Schreibe NUR den naechsten noch fehlenden, eigenstaendigen Markdown-"
    . "Abschnitt mit konkreten Anforderungen, Teilaufgaben und Abnahmekriterien. "
    . "Fuer eine Homepage plane Seiten, Dateistruktur, Navigation, Design und "
    . "Responsivitaet anhand der bereits bekannten Anforderungen, ohne Neues "
    . "als bestaetigte Nutzerentscheidung zu erfinden. "
    . "Schreibe hoechstens etwa 1200 bis 2000 Zeichen je Abschnitt. "
    . "Wiederhole keinen vorhandenen Titel, Text oder Anfangsmarker. "
    . "Nutze ## oder ### fuer neue Abschnitte. Keine Einleitung, keine Codefences, "
    . "keinen [COREUI_PLAN_SAVE] oder anderen Werkzeugaufruf. "
    . "Wenn danach wirklich alle geforderten Teile vollstaendig sind, "
    . "schreibe am Ende in einer EIGENEN ZEILE: [COREUI_PLAN_COMPLETE]. "
    . "Falls weitere Abschnitte fehlen, lass diesen Abschlussmarker weg. "
    . "Ein blosses Ende deiner Antwort bedeutet NICHT, dass der Plan fertig ist.";
}

function coreui_plan_followup_parse(string $raw): ?array {
  $raw = trim($raw);
  if ($raw === '' || strlen($raw) > 20000 || preg_match('//u', $raw) !== 1
      || str_contains($raw, "\0")) return null;
  // Follow-up turns are content only, never new model-selected tools.
  if (preg_match('~\[(?:/?COREUI_(?:PROJECT_BUILD|PLAN_AND_BUILD|PLAN_APPEND)|COREUI/)\b~i', $raw))
    return null;
  if (preg_match('~(?mi)^[ \t]*\[COREUI_PLAN_SAVE\][ \t]*$~', $raw)) return null;

  $end = '~(?:^|\r?\n)[ \t]*(?:\[COREUI_PLAN_COMPLETE\]|\[/COREUI_PLAN_SAVE\]|\[/COREUI/PLANSAVE\])[ \t]*$~i';
  $done = preg_match($end, $raw) === 1;
  if ($done) $raw = trim((string)preg_replace($end, '', $raw, 1));
  if (preg_match('~\[(?:/?COREUI_[A-Z_]+|/?COREUI/[A-Z_]+)\]~i', $raw)) return null;
  if ($raw !== '' && preg_match('~^#{1,4}[ \t]+\S|^[-*][ \t]+\S~u', $raw) !== 1)
    return null;
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
 * marker. Bounded budget, no infinite loop and no spurious build.
 */
function coreui_plan_autorun(PDO $pdo, int $uid, string $sessionId, int $turnId,
                            array $initial, callable $generate,
                            string $goal = '', int $maxRounds = 4): array {
  $saved = $initial;
  $passes = 0;
  $reason = 'no_progress';
  $startAt = microtime(true);
  $maxRounds = max(0, min(4, $maxRounds));
  for ($step = 0; $step < $maxRounds; $step++) {
    if (!coreui_plan_is_draft_markdown((string)($saved['markdown'] ?? ''))) {
      $reason = 'completed';
      break;
    }
    if (microtime(true) - $startAt >= 240.0) { $reason = 'time_budget'; break; }
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
    if ($parsed === null) { $reason='invalid_model_chunk'; break; }
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
    $reason='round_limit';
  }
  return ['saved'=>$saved,'segments'=>$passes,
    'complete'=>!coreui_plan_is_draft_markdown((string)$saved['markdown']),
    'reason'=>$reason];
}
