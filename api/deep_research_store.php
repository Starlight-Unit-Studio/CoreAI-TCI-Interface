<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.38: Deep Research (roadmap P15), after the operator's reference screenshots.
 *
 * The person picks Deep Research in the + menu ([[deep-research]] in front of the message). Instead
 * of an answer Mara writes a plan: a short title and four to six steps. Her reply in the chat is the
 * mark [[deep-research:ID]] plus one readable sentence; the page shows the plan card for it. The plan
 * starts by itself after COREUI_DR_AUTOSTART_SECONDS or when the person presses Starten; Bearbeiten
 * puts the plan into the input field ([[deep-research-edit:ID]]) and the next message changes it into
 * a new plan. The browse worker does the research (tools/coreai_deep_research.py). This file keeps
 * the runs: plan, state, sources, activity and the report. Every read and write is scoped to the
 * account; a run is deleted with its chat.
 */

const COREUI_DR_AUTOSTART_SECONDS = 45;
const COREUI_DR_MARK_RE = '~^\s*\[\[deep-research:(\d{1,18})\]\]~';
/** A worker that has not given a sign of life for this long has lost the run (update, restart). */
const COREUI_DR_STALE_SECONDS = 600;

/** Mara's reply for a plan (pure): the mark and one readable sentence for the history. */
function coreui_dr_reply_text(int $id, string $title): string {
  return '[[deep-research:' . $id . ']] Ich habe einen Rechercheplan erstellt: ' . rtrim($title, '.') . '.';
}

/** The run of a reply, 0 without the mark (pure). */
function coreui_dr_marker_id(string $text): int {
  return preg_match(COREUI_DR_MARK_RE, $text, $m) === 1 ? (int)$m[1] : 0;
}

function coreui_dr_ready(PDO $pdo): bool {
  static $ready = [];
  $key = spl_object_id($pdo);
  if (isset($ready[$key])) return $ready[$key];
  try {
    $pdo->query('SELECT 1 FROM stu_deep_research_runs LIMIT 0');
    $pdo->query('SELECT 1 FROM stu_deep_research_sources LIMIT 0');
    $pdo->query('SELECT 1 FROM stu_deep_research_events LIMIT 0');
    return $ready[$key] = true;
  } catch (Throwable $e) {
    return $ready[$key] = false;
  }
}

/** One line of a plan (pure): no list marks, no line breaks, at most $max characters. */
function coreui_dr_clean_line(string $value, int $max): string {
  $value = (string)preg_replace('~\s+~u', ' ', $value);
  $value = (string)preg_replace('~^\s*(?:[-*•#>]+|\d{1,2}[.)])\s*~u', '', $value);
  $value = trim(str_replace(['**', '__', '`'], '', $value));
  if (mb_strlen($value) > $max) $value = rtrim(mb_substr($value, 0, $max - 1)) . '…';
  return $value;
}

/** The research question from the person's message (pure). */
function coreui_dr_query(string $message): string {
  $value = trim((string)preg_replace('~[ \t]+~u', ' ', $message));
  return mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) : $value;
}

/** The first web address in a text, or '' (pure). */
function coreui_dr_first_url(string $text): string {
  return preg_match('~https?://[^\s<>"\')\]]+~iu', $text, $m) === 1 ? rtrim($m[0], '.,;:!?') : '';
}

/** A title when the Core gives none (pure). */
function coreui_dr_fallback_title(string $query): string {
  $url = coreui_dr_first_url($query);
  if ($url !== '') {
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
    return coreui_dr_clean_line('Analyse ' . preg_replace('~^www\.~i', '', $host), 80);
  }
  $words = coreui_dr_clean_line($query, 70);
  return $words !== '' ? 'Recherche: ' . $words : 'Recherche';
}

/** A plan when the Core gives none (pure): honest, general steps that the worker can follow. */
function coreui_dr_fallback_plan(string $query): array {
  $url = coreui_dr_first_url($query);
  $host = $url !== '' ? (string)preg_replace('~^www\.~i', '', (string)(parse_url($url, PHP_URL_HOST) ?: $url)) : '';
  $steps = $host !== ''
    ? ['Durchsuche die angegebene Website ' . $host . ' nach den Hauptinhalten und Angeboten.',
       'Halte die wichtigsten Fakten der einzelnen Seiten mit Quelle fest.',
       'Ergänze die Recherche mit unabhängigen externen Quellen.',
       'Fasse die Erkenntnisse in einem strukturierten Bericht mit Quellen zusammen.']
    : ['Suche nach den wichtigsten Quellen zum Thema.',
       'Lies die ergiebigsten Seiten und halte Fakten mit Quelle fest.',
       'Ergänze die Recherche mit unabhängigen und aktuellen Quellen.',
       'Fasse die Erkenntnisse in einem strukturierten Bericht mit Quellen zusammen.'];
  return ['title' => coreui_dr_fallback_title($query), 'steps' => $steps, 'fallback' => true];
}

/** The Core's plan (pure): JSON {"title", "steps"} or a list; 3 to 6 steps, else the fallback. */
function coreui_dr_parse_plan(string $raw, string $query): array {
  $title = '';
  $steps = [];
  if (preg_match('~\{.*\}~su', $raw, $m) === 1) {
    $json = json_decode($m[0], true);
    if (is_array($json)) {
      $title = (string)($json['title'] ?? $json['titel'] ?? '');
      $list = $json['steps'] ?? $json['schritte'] ?? [];
      if (is_array($list)) {
        foreach ($list as $item) {
          $steps[] = is_array($item) ? (string)($item['text'] ?? $item['step'] ?? $item['schritt'] ?? '') : (string)$item;
        }
      }
    }
  }
  if ($steps === []) {
    foreach (preg_split('~\R~u', $raw) ?: [] as $line) {
      if (preg_match('~^\s*(?:[-*•]|\d{1,2}[.)])\s+\S~u', $line) === 1) $steps[] = $line;
    }
  }
  $clean = [];
  foreach ($steps as $step) {
    $line = coreui_dr_clean_line($step, 220);
    if (mb_strlen($line) < 8) continue;
    $key = mb_strtolower($line);
    if (isset($clean[$key])) continue;
    $clean[$key] = $line;
    if (count($clean) === 6) break;
  }
  if (count($clean) < 3) return coreui_dr_fallback_plan($query);
  $title = coreui_dr_clean_line($title, 80);
  return ['title' => $title !== '' ? $title : coreui_dr_fallback_title($query), 'steps' => array_values($clean), 'fallback' => false];
}

/** The planning call (pure): [system, user]. $previous and $change for a changed plan. */
function coreui_dr_plan_prompts(string $query, ?array $previous = null, string $change = ''): array {
  $system = "Du planst eine gruendliche Webrecherche (Deep Research) fuer die Person. Antworte NUR mit JSON in genau dieser Form:\n"
    . "{\"title\": \"kurzer Titel, hoechstens 60 Zeichen\", \"steps\": [\"Schritt 1\", \"Schritt 2\"]}\n"
    . "Regeln: 4 bis 6 Schritte. Jeder Schritt ist ein kurzer deutscher Satz im Imperativ (zum Beispiel \"Durchsuche ...\", "
    . "\"Vergleiche ...\", \"Suche nach ...\"). Die Schritte muessen mit Websuche und dem Lesen oeffentlicher Webseiten machbar sein. "
    . "Der letzte Schritt fasst die Erkenntnisse in einem strukturierten Bericht mit Quellen zusammen. Kein Markdown, keine Erklaerung.";
  $user = "Auftrag der Person: " . $query;
  if ($previous !== null) {
    $user .= "\n\nBisheriger Plan: " . (string)($previous['title'] ?? '');
    foreach ((array)($previous['steps'] ?? []) as $i => $step) $user .= "\n" . ($i + 1) . '. ' . (string)$step;
    $user .= "\n\nAenderungswunsch der Person: " . ($change !== '' ? $change : '(keiner, Plan verbessern)')
      . "\nSchreibe den ganzen Plan neu mit der Aenderung.";
  }
  return [$system, $user];
}

/** The steps of a run (pure). */
function coreui_dr_steps(array $row): array {
  $steps = json_decode((string)($row['plan_json'] ?? '[]'), true);
  return is_array($steps) ? array_values(array_map('strval', $steps)) : [];
}

function coreui_dr_create(PDO $pdo, int $uid, string $sessionId, string $query, array $plan,
                          ?string $model = null, ?int $numCtx = null, int $autostart = COREUI_DR_AUTOSTART_SECONDS): int {
  $st = $pdo->prepare(
    "INSERT INTO stu_deep_research_runs (user_id, session_id, status, query, title, plan_json, model, num_ctx, autostart_at)
     VALUES (?, ?, 'planned', ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
  );
  $st->execute([$uid, $sessionId, $query, (string)$plan['title'],
    json_encode(array_values($plan['steps']), JSON_UNESCAPED_UNICODE), $model, $numCtx, max(5, $autostart)]);
  return (int)$pdo->lastInsertId();
}

function coreui_dr_row(PDO $pdo, int $uid, int $id): ?array {
  $st = $pdo->prepare(
    'SELECT r.*, TIMESTAMPDIFF(SECOND, NOW(), r.autostart_at) AS autostart_in,
            TIMESTAMPDIFF(SECOND, r.started_at, COALESCE(r.finished_at, NOW())) AS elapsed,
            TIMESTAMPDIFF(SECOND, COALESCE(r.heartbeat_at, r.started_at), NOW()) AS silent
       FROM stu_deep_research_runs r WHERE r.id = ? AND r.user_id = ?'
  );
  $st->execute([$id, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

/** A run as the page sees it. $details adds sources, activity and the report. */
function coreui_dr_public(PDO $pdo, array $row, bool $details = false): array {
  $status = (string)$row['status'];
  $steps = coreui_dr_steps($row);
  $progress = json_decode((string)($row['progress_json'] ?? ''), true);
  $progress = is_array($progress) ? $progress : [];
  $states = [];
  foreach ($steps as $i => $_) {
    $state = (string)(($progress['states'] ?? [])[$i] ?? '');
    $states[] = $status === 'done' ? 'done' : (in_array($state, ['todo', 'active', 'done'], true) ? $state : 'todo');
  }
  $error = (string)($row['error'] ?? '');
  // A run whose worker went silent is over: say so instead of turning for ever.
  if (in_array($status, ['running', 'writing'], true) && (int)($row['silent'] ?? 0) > COREUI_DR_STALE_SECONDS) {
    $status = 'error';
    $error = 'worker_lost';
  }
  $out = [
    'id' => (int)$row['id'],
    'session_id' => (string)$row['session_id'],
    'status' => $status,
    'title' => (string)$row['title'],
    'query' => (string)$row['query'],
    'steps' => $steps,
    'states' => $states,
    'phase' => (string)($progress['phase'] ?? ''),
    'percent' => max(0, min(100, (int)($progress['percent'] ?? ($status === 'done' ? 100 : 0)))),
    'autostart_in' => $status === 'planned' ? max(0, (int)($row['autostart_in'] ?? 0)) : null,
    'searches' => (int)$row['searches'],
    'citations' => (int)$row['citations'],
    'elapsed' => $row['started_at'] !== null ? max(0, (int)($row['elapsed'] ?? 0)) : 0,
    'cancelling' => (int)$row['cancel_requested'] === 1 && in_array($status, ['queued', 'running', 'writing'], true),
    'error' => $error,
    'has_report' => $status === 'done' && trim((string)($row['report_md'] ?? '')) !== '',
  ];
  $count = $pdo->prepare('SELECT COUNT(*) FROM stu_deep_research_sources WHERE run_id = ?');
  $count->execute([(int)$row['id']]);
  $out['sources_count'] = (int)$count->fetchColumn();
  if (!$details) return $out;
  $out['sources'] = coreui_dr_sources($pdo, (int)$row['id']);
  $out['events'] = coreui_dr_events($pdo, (int)$row['id']);
  $out['report'] = $out['has_report'] ? (string)$row['report_md'] : '';
  return $out;
}

function coreui_dr_sources(PDO $pdo, int $runId): array {
  $st = $pdo->prepare('SELECT n, url, title, domain, cited FROM stu_deep_research_sources WHERE run_id = ? ORDER BY n');
  $st->execute([$runId]);
  return array_map(static fn(array $r): array => ['n' => (int)$r['n'], 'url' => (string)$r['url'], 'title' => (string)$r['title'],
    'domain' => (string)$r['domain'], 'cited' => (int)$r['cited'] === 1], $st->fetchAll(PDO::FETCH_ASSOC));
}

function coreui_dr_events(PDO $pdo, int $runId, int $limit = 400): array {
  $st = $pdo->prepare('SELECT id, kind, text, domains, created_at FROM stu_deep_research_events WHERE run_id = ? ORDER BY id LIMIT ' . max(1, min(1000, $limit)));
  $st->execute([$runId]);
  return array_map(static function (array $r): array {
    $domains = json_decode((string)$r['domains'], true);
    return ['kind' => (string)$r['kind'], 'text' => (string)$r['text'], 'domains' => is_array($domains) ? array_values(array_map('strval', $domains)) : []];
  }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Starten: a planned (or edited) run goes to the worker. */
function coreui_dr_start(PDO $pdo, int $uid, int $id): bool {
  $st = $pdo->prepare("UPDATE stu_deep_research_runs SET status = 'queued', autostart_at = NULL
                        WHERE id = ? AND user_id = ? AND status IN ('planned', 'editing')");
  $st->execute([$id, $uid]);
  return $st->rowCount() === 1;
}

/** Bearbeiten: the countdown stops; the plan waits for the person's change. */
function coreui_dr_edit(PDO $pdo, int $uid, int $id): bool {
  $st = $pdo->prepare("UPDATE stu_deep_research_runs SET status = 'editing', autostart_at = NULL
                        WHERE id = ? AND user_id = ? AND status IN ('planned', 'editing')");
  $st->execute([$id, $uid]);
  return $st->rowCount() === 1 || (($row = coreui_dr_row($pdo, $uid, $id)) !== null && $row['status'] === 'editing');
}

/** Abbrechen / Stopp: a waiting run ends at once, a running one at the worker's next check. */
function coreui_dr_cancel(PDO $pdo, int $uid, int $id): string {
  $st = $pdo->prepare("UPDATE stu_deep_research_runs SET status = 'cancelled', autostart_at = NULL, finished_at = NOW()
                        WHERE id = ? AND user_id = ? AND status IN ('planned', 'editing', 'queued')");
  $st->execute([$id, $uid]);
  if ($st->rowCount() === 1) return 'cancelled';
  $st = $pdo->prepare("UPDATE stu_deep_research_runs SET cancel_requested = 1
                        WHERE id = ? AND user_id = ? AND status IN ('running', 'writing')");
  $st->execute([$id, $uid]);
  $row = coreui_dr_row($pdo, $uid, $id);
  return $row === null ? 'missing' : (string)$row['status'];
}

/** The old plan after a changed one was written. */
function coreui_dr_replace(PDO $pdo, int $uid, int $id): void {
  $pdo->prepare("UPDATE stu_deep_research_runs SET status = 'replaced', autostart_at = NULL, finished_at = NOW()
                  WHERE id = ? AND user_id = ? AND status IN ('planned', 'editing')")->execute([$id, $uid]);
}

/** Removes the runs of a chat with their sources and activity; returns how many runs. */
function coreui_dr_delete_session(PDO $pdo, int $uid, string $sessionId): int {
  if (!coreui_dr_ready($pdo)) return 0;
  $st = $pdo->prepare('SELECT id FROM stu_deep_research_runs WHERE user_id = ? AND session_id = ?');
  $st->execute([$uid, $sessionId]);
  $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
  if ($ids === []) return 0;
  $in = implode(',', array_fill(0, count($ids), '?'));
  $pdo->prepare("DELETE FROM stu_deep_research_events WHERE run_id IN ($in)")->execute($ids);
  $pdo->prepare("DELETE FROM stu_deep_research_sources WHERE run_id IN ($in)")->execute($ids);
  $pdo->prepare("DELETE FROM stu_deep_research_runs WHERE id IN ($in) AND user_id = ?")->execute([...$ids, $uid]);
  return count($ids);
}

/** The report as a Markdown file (pure): the text and, below, the numbered sources. */
function coreui_dr_download_markdown(string $title, string $report, array $sources): string {
  $text = trim($report);
  if ($text === '') $text = '# ' . $title;
  if ($sources !== []) {
    $text .= "\n\n## Quellen\n";
    foreach ($sources as $s) {
      $label = trim((string)$s['title']) !== '' ? (string)$s['title'] : (string)$s['url'];
      $text .= "\n" . (int)$s['n'] . '. [' . str_replace(['[', ']'], ['(', ')'], $label) . '](' . (string)$s['url'] . ')';
    }
  }
  return $text . "\n";
}

/** A file name for the download (pure). */
function coreui_dr_file_name(string $title): string {
  $slug = strtolower(trim((string)preg_replace('~[^a-z0-9]+~i', '-',
    strtr($title, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])), '-'));
  return 'deep-research-' . ($slug !== '' ? substr($slug, 0, 60) : 'bericht') . '.md';
}

/**
 * Mara's reply when Deep Research was picked: a plan instead of an answer. Called from
 * ember_generate_reply (chat.php) before anything else is prepared for the Core.
 */
function coreui_dr_reply(PDO $pdo, int $uid, string $sessionId, string $userMsg, array $intent, bool $guest, bool $webOn): string {
  if ($guest) return 'Deep Research steht Gästen nicht zur Verfügung. Gern suche ich dir aber schnell im Netz etwas heraus.';
  if (!$webOn) return 'Für Deep Research brauche ich die Websuche, und die ist für dein Konto gerade nicht freigegeben.';
  if (!coreui_dr_ready($pdo)) return 'Deep Research ist auf diesem Server noch nicht eingerichtet: Die Datenbank-Migration 040 fehlt noch.';
  $query = coreui_dr_query($userMsg);
  $previous = null;
  $editId = (int)($intent['edit'] ?? 0);
  if ($editId > 0) {
    $row = coreui_dr_row($pdo, $uid, $editId);
    if ($row !== null && in_array((string)$row['status'], ['planned', 'editing'], true)) {
      $previous = ['title' => (string)$row['title'], 'steps' => coreui_dr_steps($row), 'query' => (string)$row['query']];
    }
  }
  if ($previous === null && $query === '') return 'Schreib mir, was ich gründlich recherchieren soll, dann mache ich einen Plan.';
  $plan = null;
  if (function_exists('ember_call_ollama') && function_exists('ember_model')) {
    [$system, $user] = coreui_dr_plan_prompts($previous !== null ? (string)$previous['query'] : $query, $previous, $previous !== null ? $query : '');
    try {
      $raw = ember_call_ollama(ember_model(), $system, $user, 180, ['__skills' => 'none', '__suppress_fail_log' => true], null, false, false);
      if (is_string($raw) && trim($raw) !== '') $plan = coreui_dr_parse_plan($raw, $previous !== null ? (string)$previous['query'] : $query);
    } catch (RuntimeException $e) {
      if ($e->getMessage() === 'agent_cancelled') throw $e;
    } catch (Throwable $e) {
      $plan = null;
    }
  }
  if ($plan === null) $plan = $previous !== null ? ['title' => $previous['title'], 'steps' => $previous['steps']] : coreui_dr_fallback_plan($query);
  $model = function_exists('ember_model') ? ember_model() : null;
  $numCtx = ($model !== null && function_exists('ember_profile_num_ctx')) ? ember_profile_num_ctx($model) : null;
  $id = coreui_dr_create($pdo, $uid, $sessionId, $previous !== null ? (string)$previous['query'] : $query, $plan, $model, $numCtx);
  if ($previous !== null) coreui_dr_replace($pdo, $uid, $editId);
  return coreui_dr_reply_text($id, (string)$plan['title']);
}
