<?php
declare(strict_types=1);

/*
 * 0.5.77: Chat komprimieren.
 *
 * The private chat sends Mara only its newest messages (12, fewer for lore
 * questions); everything older used to fall out of her context without a
 * trace. Now the older part is folded into a running summary that rides
 * along in every prompt ("BISHERIGER VERLAUF"), and the folded messages are
 * written word for word into a Markdown archive of the session
 * (var/console_media/archives/<uid>/<session>.md). The archive stays until
 * the chat is deleted; it can be downloaded and Mara searches it with
 * [ARCHIV: Stichworte] when a detail is missing from the summary.
 *
 * Automatic after a reply once messages fall out of the window, or on the
 * button "Chat komprimieren". The visible chat is never shortened. State
 * per session in stu_kv (cmp_<session>), so no migration is needed.
 */

const COREUI_COMPACT_KEEP = 12;
const COREUI_COMPACT_MIN_FOLD = 4;
const COREUI_COMPACT_MAX_FOLD = 40;
const COREUI_COMPACT_SUMMARY_CHARS = 3500;

function coreui_compact_key(string $sessionId): string {
  return 'cmp_' . $sessionId;
}

function coreui_compact_load(PDO $pdo, int $uid, string $sessionId): ?array {
  if ($uid <= 0 || $sessionId === '') return null;
  try {
    $st = $pdo->prepare('SELECT value FROM stu_kv WHERE user_id = ? AND k = ? LIMIT 1');
    $st->execute([$uid, coreui_compact_key($sessionId)]);
    $state = json_decode((string)$st->fetchColumn(), true);
  } catch (Throwable $e) {
    return null;
  }
  return is_array($state) && isset($state['upto_id'], $state['summary']) ? $state : null;
}

function coreui_compact_save(PDO $pdo, int $uid, string $sessionId, array $state): void {
  $pdo->prepare(
    'INSERT INTO stu_kv (user_id, k, value, updated_at) VALUES (?, ?, ?, NOW()) '
    . 'ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
  )->execute([$uid, coreui_compact_key($sessionId), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function coreui_compact_archive_path(int $uid, string $sessionId): string {
  return dirname(__DIR__) . '/var/console_media/archives/' . $uid . '/' . preg_replace('~[^A-Za-z0-9_-]~', '', $sessionId) . '.md';
}

/** Deleting a chat removes its summary and its archive. */
function coreui_compact_forget(PDO $pdo, int $uid, string $sessionId): void {
  try {
    $pdo->prepare('DELETE FROM stu_kv WHERE user_id = ? AND k = ?')->execute([$uid, coreui_compact_key($sessionId)]);
  } catch (Throwable $e) {}
  $path = coreui_compact_archive_path($uid, $sessionId);
  if (is_file($path)) @unlink($path);
}

/** Messages of a session after $afterId, oldest first. */
function coreui_compact_messages(PDO $pdo, int $uid, string $sessionId, int $afterId): array {
  $st = $pdo->prepare(
    "SELECT id, character_id, character_name, message, created_at FROM stu_chat_messages
      WHERE channel='console' AND user_id=? AND session_id=? AND alliance_id IS NULL
        AND deleted_at IS NULL AND id > ?
      ORDER BY id ASC"
  );
  $st->execute([$uid, $sessionId, $afterId]);
  $ember = strtolower(function_exists('ember_character_id') ? ember_character_id() : 'ember');
  return array_map(static fn(array $r): array => [
    'id'=>(int)$r['id'],
    'mara'=>strtolower((string)$r['character_id']) === $ember,
    'name'=>(string)$r['character_name'],
    'message'=>(string)$r['message'],
    'created_at'=>(string)$r['created_at'],
  ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Which messages a compaction folds (pure): the oldest ones outside the
 * window of the newest $keep, at most $max per run. Automatically only once
 * at least $min messages fell out; the button folds whatever is outside.
 */
function coreui_compact_slice(array $messages, int $keep, int $min, int $max, bool $force): array {
  $outside = max(0, count($messages) - $keep);
  if ($outside === 0 || (!$force && $outside < $min)) return [];
  return array_slice($messages, 0, min($outside, $max));
}

/** The folded messages word for word as Markdown (pure). */
function coreui_compact_markdown(array $rows, string $assistant = 'Mara'): string {
  $out = '';
  foreach ($rows as $row) {
    $who = !empty($row['mara']) ? $assistant : (trim((string)$row['name']) !== '' ? trim((string)$row['name']) : 'Du');
    $when = strtotime((string)$row['created_at']);
    $out .= '### ' . $who . ($when ? ' · ' . date('d.m.Y H:i', $when) : '') . ' (#' . (int)$row['id'] . ")\n\n"
      . rtrim((string)$row['message']) . "\n\n";
  }
  return $out;
}

/** Instruction for the summary (pure). Long messages are shortened in the middle. */
function coreui_compact_prompt(string $previous, array $rows, int $budget): string {
  $lines = [];
  $per = max(300, intdiv($budget, max(1, count($rows))));
  foreach ($rows as $row) {
    $text = trim((string)$row['message']);
    if (mb_strlen($text) > $per) $text = mb_substr($text, 0, intdiv($per, 2)) . ' […] ' . mb_substr($text, -intdiv($per, 2));
    $lines[] = (!empty($row['mara']) ? 'Mara' : 'Nutzer') . ': ' . $text;
  }
  return "Du bist Mara. Fasse den bisherigen Verlauf dieses Chats fuer dein eigenes Gedaechtnis zusammen.\n"
    . "Behalte: Ziele und Wuensche der Person, Entscheidungen, wichtige Fakten, Namen, Zahlen, Datei- und "
    . "Codenamen, offene Aufgaben und Zusagen. Lass Smalltalk und Wiederholungen weg. Erfinde nichts.\n"
    . "Schreibe knappe Stichpunkte auf Deutsch, hoechstens " . COREUI_COMPACT_SUMMARY_CHARS . " Zeichen. "
    . "Gib nur die Zusammenfassung aus, ohne Einleitung und ohne Werkzeugmarker.\n\n"
    . ($previous !== '' ? "BISHERIGE ZUSAMMENFASSUNG:\n" . $previous . "\n\n" : '')
    . "NEUE NACHRICHTEN ZUM EINARBEITEN:\n" . implode("\n", $lines) . "\n";
}

/** The summary in front of the chat history (pure). */
function coreui_compact_context_block(?array $state): string {
  if (!is_array($state) || trim((string)($state['summary'] ?? '')) === '') return '';
  return "--- BISHERIGER VERLAUF (zusammengefasst, " . (int)($state['folded'] ?? 0) . " aeltere Nachrichten; "
    . "die Mitschrift liegt im Archiv, Details mit [ARCHIV: Stichworte]) ---\n"
    . trim((string)$state['summary']) . "\n--- ENDE BISHERIGER VERLAUF ---\n";
}

/** A model answer to a summary, or null if it is not usable (pure). */
function coreui_compact_clean_summary(?string $text): ?string {
  $text = trim((string)$text);
  $text = trim((string)preg_replace('~\[(?:WEB|BROWSE|ARCHIV|SKILL|PY)\b[^\]]*\]~iu', '', $text));
  if (mb_strlen($text) < 20) return null;
  return mb_substr($text, 0, COREUI_COMPACT_SUMMARY_CHARS + 500);
}

/**
 * One compaction: archive the folded messages, then fold them into the
 * summary with one model call ($summarize returns the text or null). The
 * archive is written first, so nothing is lost even if the model fails.
 */
function coreui_compact_run(PDO $pdo, int $uid, string $sessionId, callable $summarize, bool $force, int $budget = 16000): array {
  $state = coreui_compact_load($pdo, $uid, $sessionId) ?? ['upto_id'=>0, 'summary'=>'', 'folded'=>0, 'archived_upto'=>0];
  $rows = coreui_compact_slice(coreui_compact_messages($pdo, $uid, $sessionId, (int)$state['upto_id']),
    COREUI_COMPACT_KEEP, COREUI_COMPACT_MIN_FOLD, COREUI_COMPACT_MAX_FOLD, $force);
  if ($rows === []) return ['status'=>'not_needed', 'folded'=>0, 'total'=>(int)$state['folded']];

  $path = coreui_compact_archive_path($uid, $sessionId);
  $fresh = array_values(array_filter($rows, static fn(array $r): bool => $r['id'] > (int)($state['archived_upto'] ?? 0)));
  if ($fresh !== []) {
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0770, true);
    $head = is_file($path) ? '' : "# Chat-Archiv\n\nSitzung " . $sessionId . ", angelegt am " . date('d.m.Y H:i')
      . ". Woertliche Mitschrift der zusammengefassten Nachrichten; bleibt, bis der Chat geloescht wird.\n\n";
    if (@file_put_contents($path, $head . coreui_compact_markdown($fresh), FILE_APPEND | LOCK_EX) === false) {
      return ['status'=>'failed', 'reason'=>'archive_unwritable', 'folded'=>0, 'total'=>(int)$state['folded']];
    }
    $state['archived_upto'] = end($fresh)['id'];
    coreui_compact_save($pdo, $uid, $sessionId, $state);
  }

  $summary = coreui_compact_clean_summary($summarize(coreui_compact_prompt((string)$state['summary'], $rows, $budget)));
  if ($summary === null) return ['status'=>'failed', 'reason'=>'summary_empty', 'folded'=>0, 'total'=>(int)$state['folded']];
  $state['summary'] = $summary;
  $state['upto_id'] = end($rows)['id'];
  $state['folded'] = (int)$state['folded'] + count($rows);
  $state['updated_at'] = time();
  coreui_compact_save($pdo, $uid, $sessionId, $state);
  return ['status'=>'done', 'folded'=>count($rows), 'total'=>$state['folded']];
}

/**
 * Archive search for [ARCHIV: ...] (pure): message blocks that contain the
 * most query words, best first, each shortened around the first hit.
 */
function coreui_compact_archive_search(string $markdown, string $query, int $limit = 4, int $chars = 900): array {
  $words = array_values(array_filter(preg_split('~[^\p{L}\p{N}_.-]+~u', mb_strtolower($query)) ?: [],
    static fn(string $w): bool => mb_strlen($w) >= 3));
  if ($words === []) return [];
  $blocks = preg_split('~(?=^### )~m', $markdown) ?: [];
  $hits = [];
  foreach ($blocks as $i => $block) {
    if (!str_starts_with($block, '### ')) continue;
    $low = mb_strtolower($block);
    $score = 0;
    $first = null;
    foreach ($words as $w) {
      $pos = mb_strpos($low, $w);
      if ($pos === false) continue;
      $score += 1 + min(3, substr_count($low, $w) - 1);
      $first = $first === null ? $pos : min($first, $pos);
    }
    if ($score === 0) continue;
    $start = max(0, (int)$first - intdiv($chars, 3));
    $text = trim(mb_substr($block, $start, $chars));
    $hits[] = ['score'=>$score, 'order'=>$i, 'text'=>($start > 0 ? '… ' : '') . $text];
  }
  usort($hits, static fn(array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
  return array_slice(array_column($hits, 'text'), 0, $limit);
}

/** Result block of [ARCHIV: ...] for Mara's next step. */
function coreui_compact_archive_block(int $uid, string $sessionId, string $query): string {
  $path = coreui_compact_archive_path($uid, $sessionId);
  $text = is_readable($path) ? (string)@file_get_contents($path) : '';
  if ($text === '') return 'Das Archiv dieses Chats ist leer: es wurde noch nichts zusammengefasst.';
  $hits = coreui_compact_archive_search($text, $query);
  return $hits === [] ? 'Im Archiv dieses Chats steht nichts zu "' . $query . '".'
    : "Fundstellen im Archiv zu \"" . $query . "\":\n\n" . implode("\n\n---\n\n", $hits);
}

/** Room for the messages in one summary call, from the Core's window (pure). */
function coreui_compact_budget(?int $contextTokens): int {
  return ($contextTokens !== null && $contextTokens <= 8192) ? 6000 : 16000;
}

/**
 * One compaction with Mara's Core, synchronously (nothing streams to the
 * browser) and without thinking; inside a turn it shares the turn's queue
 * slot, from the button it waits in the queue like a message.
 */
function coreui_compact_with_core(PDO $pdo, int $uid, string $sessionId, bool $force): array {
  $model = ember_model();
  return coreui_compact_run($pdo, $uid, $sessionId, static function (string $prompt) use ($model): ?string {
    $text = ember_call_ollama($model, '', $prompt, max(120, ember_timeout_for_model($model)), [], null, false, false);
    return is_string($text) && !ember_last_call_was_truncated() ? $text : null;
  }, $force, coreui_compact_budget(ember_ollama_context_window($model)));
}

function coreui_extract_archive_marker(string $s): ?string {
  // A mention in inline code or a code block is not a call (as for WEB and BROWSE).
  $s = (string)preg_replace('~```[\s\S]*?```~u', '', $s);
  if (preg_match('~\A\s*`(\[ARCHIV:[^`\]]+\])`\s*\z~iu', $s, $sole) === 1) $s = $sole[1];
  else $s = (string)preg_replace('~(?<!`)`(?!`)[^`\r\n]+`(?!`)~u', '', $s);
  if (preg_match('~\[ARCHIV:\s*([^\]\n]{2,200})\]~iu', $s, $m) !== 1) return null;
  $q = trim($m[1]);
  return $q !== '' ? $q : null;
}
