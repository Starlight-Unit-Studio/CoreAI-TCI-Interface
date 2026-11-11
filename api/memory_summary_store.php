<?php
declare(strict_types=1);

/*
 * CoreAI 0.7.0: Settings > Personalisierung > Erinnerungszusammenfassung (after the reference).
 *   - the account's own memories (ember_memories: scope user, or character of its characters)
 *   - Mara's overview of them, written by the account's Core and kept until they change (migration 033)
 *   - "Erinnerung loeschen und ausschalten": those memories and the overview are deleted
 * Global facts maintained by the operator are never part of it and never deleted here.
 */

const COREUI_MEMORY_SUMMARY_MAX_FACTS = 150;
const COREUI_MEMORY_SUMMARY_MAX_CHARS = 8000;

function coreui_memory_summary_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, summary_md, facts_hash, fact_count, created_at FROM stu_memory_summaries LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** The account's own memories, most relevant first. */
function coreui_memory_summary_facts(PDO $pdo, int $uid, int $limit = COREUI_MEMORY_SUMMARY_MAX_FACTS): array {
  if ($uid <= 0) return [];
  $st = $pdo->prepare(
    "SELECT id, fact, relevance, updated_at FROM ember_memories
      WHERE (scope = 'user' AND user_id = ?)
         OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))
      ORDER BY relevance DESC, updated_at DESC, id DESC LIMIT " . max(1, min(500, $limit))
  );
  $st->execute([$uid, $uid]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** How many memories the account has (all of them, not only those in the overview). */
function coreui_memory_summary_count(PDO $pdo, int $uid): int {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM ember_memories
      WHERE (scope = 'user' AND user_id = ?)
         OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))"
  );
  $st->execute([$uid, $uid]);
  return (int)$st->fetchColumn();
}

/** A fingerprint of the memories: a new, changed or deleted memory makes the overview stale (pure). */
function coreui_memory_summary_hash(array $facts): string {
  $lines = [];
  foreach ($facts as $f) $lines[] = (int)($f['id'] ?? 0) . '|' . (string)($f['updated_at'] ?? '') . '|' . (string)($f['fact'] ?? '');
  sort($lines, SORT_STRING);
  return hash('sha256', implode("\n", $lines));
}

function coreui_memory_summary_cached(PDO $pdo, int $uid): ?array {
  if (!coreui_memory_summary_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT summary_md, facts_hash, fact_count, created_at FROM stu_memory_summaries WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function coreui_memory_summary_store(PDO $pdo, int $uid, string $markdown, string $hash, int $count): void {
  if (!coreui_memory_summary_ready($pdo)) throw new RuntimeException('missing_schema_033');
  $pdo->prepare('DELETE FROM stu_memory_summaries WHERE user_id = ?')->execute([$uid]);
  $pdo->prepare('INSERT INTO stu_memory_summaries (user_id, summary_md, facts_hash, fact_count, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
    ->execute([$uid, $markdown, $hash, $count]);
}

/**
 * The prompt for Mara (pure): only the given memories, sections like the reference
 * ("Ueberblick", two to four topics, "Tiefer eintauchen" with three suggestions).
 */
function coreui_memory_summary_prompt(array $facts, int $budget = 9000): string {
  $list = '';
  foreach ($facts as $f) {
    $line = '- ' . trim((string)preg_replace('~\s+~u', ' ', (string)($f['fact'] ?? ''))) . "\n";
    if (mb_strlen($list . $line, 'UTF-8') > $budget) break;
    $list .= $line;
  }
  return "Schreibe fuer die Einstellungsseite \"Erinnerungszusammenfassung\" eine kurze Uebersicht darueber, was du ueber die Person "
    . "gelernt hast. Grundlage sind NUR die gespeicherten Erinnerungen unten; erfinde nichts dazu und lass Unsicheres weg.\n\n"
    . "Form: Markdown auf Deutsch, sprich die Person mit \"du\" an.\n"
    . "## Überblick\nEin Absatz mit dem Wichtigsten.\n"
    . "Dann zwei bis vier weitere Abschnitte, jeweils \"## <Thema>\" (zum Beispiel Arbeit, Projekte, Vorlieben) mit einem kurzen Absatz.\n"
    . "## Tiefer eintauchen\nGenau drei Zeilen \"- <Vorschlag>\": kurze Fragen oder Auftraege, die die Person dir in einem neuen Chat "
    . "stellen koennte und die zu den Erinnerungen passen.\n\n"
    . "Keine Einleitung, kein Schlusssatz, keine Tabellen, keine Emojis, keine Werkzeugmarker.\n\n"
    . "Gespeicherte Erinnerungen (wichtigste zuerst):\n" . $list;
}

/** Mara's answer as the overview (pure): Markdown headings, paragraphs and the suggestions only. */
function coreui_memory_summary_clean(string $raw): string {
  $text = str_replace(["\r\n", "\r"], "\n", $raw);
  $text = (string)preg_replace('~<(think|thinking)>[\s\S]*?</\1>~iu', '', $text);
  $text = (string)preg_replace('~^```[a-z]*[ \t]*$~mu', '', $text);
  $text = strip_tags($text);
  // No tool markers: the overview is text only.
  $text = (string)preg_replace('~\[(?:WEB|BROWSE|BILD|IMAGE|ARCHIV|SKILL|PY)\b[^\]]*\]~iu', '', $text);
  $text = (string)preg_replace('~^[ \t]{0,3}#{1,6}[ \t]*~mu', '## ', $text);
  $text = (string)preg_replace('~^[ \t]*[*+•][ \t]+~mu', '- ', $text);
  $text = (string)preg_replace("~\n{3,}~u", "\n\n", trim($text));
  if ($text === '') return '';
  if (!str_starts_with($text, '## ')) $text = "## Überblick\n" . $text;
  return mb_substr($text, 0, COREUI_MEMORY_SUMMARY_MAX_CHARS, 'UTF-8');
}

/**
 * The overview for the account: the stored one while the memories are unchanged, otherwise a new
 * one from $write (the Core call: prompt in, text or null out). Returns
 * ['summary' => ?array, 'fact_count' => int, 'fresh' => bool]; throws on a failed write.
 */
function coreui_memory_summary_get(PDO $pdo, int $uid, ?callable $write, int $budget = 9000): array {
  $facts = coreui_memory_summary_facts($pdo, $uid);
  $count = coreui_memory_summary_count($pdo, $uid);
  if ($facts === []) return ['summary' => null, 'fact_count' => 0, 'fresh' => true];
  $hash = coreui_memory_summary_hash($facts);
  $cached = coreui_memory_summary_cached($pdo, $uid);
  if ($cached !== null && hash_equals((string)$cached['facts_hash'], $hash)) {
    return ['summary' => coreui_memory_summary_view($cached), 'fact_count' => $count, 'fresh' => true];
  }
  if ($write === null) {
    return ['summary' => $cached !== null ? coreui_memory_summary_view($cached) : null, 'fact_count' => $count, 'fresh' => false];
  }
  $text = $write(coreui_memory_summary_prompt($facts, $budget));
  $markdown = is_string($text) ? coreui_memory_summary_clean($text) : '';
  if ($markdown === '') throw new RuntimeException('summary_failed');
  coreui_memory_summary_store($pdo, $uid, $markdown, $hash, $count);
  return ['summary' => coreui_memory_summary_view(coreui_memory_summary_cached($pdo, $uid) ?? []), 'fact_count' => $count, 'fresh' => true];
}

function coreui_memory_summary_view(array $row): array {
  $created = (string)($row['created_at'] ?? '');
  $ts = $created !== '' ? strtotime($created) : false;
  return ['markdown' => (string)($row['summary_md'] ?? ''), 'fact_count' => (int)($row['fact_count'] ?? 0),
    'created_at' => $ts ? date('c', $ts) : null];
}

/** "Erinnerung loeschen und ausschalten": the account's memories and the overview; returns how many memories. */
function coreui_memory_summary_wipe(PDO $pdo, int $uid): int {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $st = $pdo->prepare(
    "DELETE FROM ember_memories WHERE (scope = 'user' AND user_id = ?)
        OR (scope = 'character' AND character_id IN (SELECT id FROM stu_characters WHERE user_id = ?))"
  );
  $st->execute([$uid, $uid]);
  if (coreui_memory_summary_ready($pdo)) $pdo->prepare('DELETE FROM stu_memory_summaries WHERE user_id = ?')->execute([$uid]);
  return $st->rowCount();
}

/** For the data export: the stored overview (empty when there is none or migration 033 is missing). */
function coreui_memory_summary_export(PDO $pdo, int $uid): array {
  try {
    $row = coreui_memory_summary_cached($pdo, $uid);
  } catch (Throwable $e) {
    return [];
  }
  return $row === null ? [] : ['summary_md' => (string)$row['summary_md'], 'fact_count' => (int)$row['fact_count'],
    'created_at' => (string)$row['created_at']];
}
