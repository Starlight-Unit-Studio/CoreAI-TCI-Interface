<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.31 (P20.4): the live conversation.
 *
 * 0.6.33: the live Core writes the transcript again (0.6.32 tried Mara Schnell: no time saved on
 * the operator's server, short phrases misheard). Emojis are not spoken.
 *
 * Operator's decisions (06.11.2026, after the live probe of 0.6.30):
 *   - each round: the Core writes the transcript of the recording first, then Mara
 *     answers the TEXT (in the probe only the two-step way understood reliably; the
 *     WAV in Mara's own call did not help and kept Ollama from reusing the history);
 *   - Mara Normal answers live, whatever Core the chat uses (Plus took about 16 s to
 *     the first sentence, Normal about 7 s, before the cache);
 *   - hands-free: a pause ends the person's round, speaking interrupts Mara.
 *
 * The beginning of every call stays the same within one conversation, so Ollama can
 * keep it in its cache: the operator's Modelfile SYSTEM (never replaced), one fixed
 * context message (name, date, KI-Profil, memory, live rules), the history before the
 * live start (at most 10 messages), then every live round as plain user and assistant
 * messages. A Stimmsequenz is always shown by its transcript, the same way in the round
 * it was spoken and in every later round.
 */

const COREUI_LIVE_HISTORY_BEFORE = 10;
const COREUI_LIVE_HISTORY_LIVE = 80;
const COREUI_LIVE_SENTENCE_MIN = 12;
const COREUI_LIVE_REPLY_HISTORY_CHARS = 1200;
const COREUI_LIVE_ABBREVIATIONS = ['z', 'b', 'd', 'h', 'bzw', 'ca', 'dr', 'nr', 'usw', 'u', 'a', 'etc', 'evtl', 'ggf', 'inkl',
  'max', 'min', 'mio', 'mrd', 'str', 'st', 'vgl', 'zb', 'prof', 'mr', 'mrs', 'vs', 'e', 'g', 'i'];

/**
 * The Core that answers live (pure): the installed Core of the tier "normal" that hears
 * and that the account may use, Mara's own Core (family $family) first; else the chat Core.
 * $tierOf(string): ?string, $hears(string): ?bool, $allowed(string): bool.
 */
function coreui_live_pick_core(string $chatCore, array $installed, string $family, callable $tierOf, callable $hears,
                               callable $allowed): string {
  $found = coreui_live_pick_tier('normal', $installed, $family, $tierOf, $hears, $allowed);
  return $found !== '' ? $found : $chatCore;
}

/** The installed local Core of one tier that hears and is allowed, Mara's own Core first (pure); '' when none. */
function coreui_live_pick_tier(string $tier, array $installed, string $family, callable $tierOf, callable $hears,
                               callable $allowed): string {
  $candidates = [];
  foreach ($installed as $name) {
    $name = (string)$name;
    if ($name === '' || str_ends_with($name, '-cloud') || str_contains($name, ':cloud')) continue;
    if ($tierOf($name) !== $tier) continue;
    $candidates[] = $name;
  }
  usort($candidates, static function (string $a, string $b) use ($family): int {
    $ma = str_starts_with(strtolower($a), strtolower($family) . ':') ? 0 : 1;
    $mb = str_starts_with(strtolower($b), strtolower($family) . ':') ? 0 : 1;
    return $ma <=> $mb ?: strcmp($a, $b);
  });
  foreach ($candidates as $name) {
    if ($hears($name) === true && $allowed($name)) return $name;
  }
  return '';
}

/**
 * Whole sentences for Eve (pure). Takes every finished sentence from the front of $buffer
 * and leaves the rest; $final takes the rest too. A sentence ends at . ! ? or … before a
 * space, or at a line break; a full stop after a number ("am 3. November"), a single
 * letter or a common abbreviation ("z. B.", "bzw.") does not end it, and a piece shorter
 * than 12 characters waits for the next one ("Ja. Gerne.").
 */
function coreui_live_take_sentences(string &$buffer, bool $final = false): array {
  $out = [];
  $start = 0;
  $len = strlen($buffer);
  $pos = 0;
  while ($pos < $len && preg_match('~[.!?…]+["“”»)\']*(?=\s)|\n~u', $buffer, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
    $end = (int)$m[0][1] + strlen($m[0][0]);
    $pos = $end;
    $piece = trim(substr($buffer, $start, $end - $start));
    if ($piece === '') { $start = $end; continue; }
    if ($m[0][0] !== "\n" && str_starts_with($m[0][0], '.') && strlen($m[0][0]) === 1) {
      $before = substr($buffer, $start, (int)$m[0][1] - $start);
      $word = preg_match('~([\p{L}\p{N}]+)$~u', $before, $w) === 1 ? mb_strtolower($w[1]) : '';
      if ($word !== '' && (preg_match('~^\d+$~', $word) === 1 || mb_strlen($word) === 1 || in_array($word, COREUI_LIVE_ABBREVIATIONS, true))) continue;
    }
    if (mb_strlen($piece) < COREUI_LIVE_SENTENCE_MIN) continue;
    $out[] = $piece;
    $start = $end;
  }
  $rest = substr($buffer, $start);
  if ($final) {
    if (trim($rest) !== '') $out[] = trim($rest);
    $rest = '';
  }
  $buffer = ltrim($rest);
  return $out;
}

/**
 * 0.6.33: a sentence as Eve reads it and the live view shows it (pure): without emojis; '' when
 * nothing speakable is left (the operator saw a lone "😊" as my sentence). The chat keeps them.
 */
function coreui_live_spoken(string $sentence): string {
  $text = (string)preg_replace('~[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]~u', '', $sentence);
  $text = trim((string)preg_replace('~\s{2,}~u', ' ', $text));
  return preg_match('~[\p{L}\p{N}]~u', $text) === 1 ? $text : '';
}

/**
 * 0.6.33 (pure): what a Core call cost, from Ollama's final line: prompt_tokens is the whole
 * prompt (Ollama counts cached tokens too: a warm-up with 1081 tokens took 0.3 s on the operator's
 * server), prompt_s the time it really spent reading it, load_s loading the Core.
 */
function coreui_live_prompt_stats(array $final): array {
  $ns = static fn(string $key): ?float => isset($final[$key]) && is_numeric($final[$key]) ? round((float)$final[$key] / 1e9, 2) : null;
  return ['prompt_tokens' => isset($final['prompt_eval_count']) ? (int)$final['prompt_eval_count'] : null,
    'prompt_s' => $ns('prompt_eval_duration'), 'load_s' => $ns('load_duration')];
}

/** A reply as it is spoken and kept (pure): no tool markers, no Markdown marks, no stray brackets. */
function coreui_live_clean_reply(string $text): string {
  $text = (string)preg_replace('~\[(?:WEB|BROWSE|ARCHIV|SKILL):[^\]]*\]|\[/?PY\]~iu', '', $text);
  $text = (string)preg_replace('~```.*?```~su', '', $text);
  $text = (string)preg_replace(['~\*\*(.+?)\*\*~su', '~__(.+?)__~su', '~(?<![\p{L}\p{N}])\*(?!\s)(.+?)(?<!\s)\*(?![\p{L}\p{N}])~su'], '$1', $text);
  $text = (string)preg_replace('~^\s{0,3}(?:#{1,6}\s+|[-*•]\s+|>\s?)~mu', '', $text);
  $text = (string)preg_replace('~`([^`]*)`~u', '$1', $text);
  return trim((string)preg_replace(["~[ \t]+\n~u", '~[ \t]{2,}~u'], ["\n", ' '], $text));
}

/**
 * The fixed opening of a live conversation (pure). It stays word for word the same during
 * the conversation (date without the time), so Ollama keeps it in its cache.
 */
function coreui_live_context(string $name, string $date, string $profileBlock, string $memoryBlock): string {
  $name = trim($name) !== '' ? trim($name) : 'dein Gegenueber';
  return "LIVE-GESPRAECH (gesprochen, " . $date . ")\n"
    . $name . " spricht gerade live mit dir. Die Nachrichten von " . $name . " in diesem Gespraech sind automatische "
    . "Mitschriften des Gesagten und koennen Hoerfehler enthalten; ist etwas unklar, frag kurz nach.\n"
    . "Antworte so, wie man spricht: natuerlich und kurz, meist ein bis drei Saetze. Keine Listen, Tabellen, "
    . "Ueberschriften, Markdown, Emojis, Links oder Werkzeugmarker; deine Antwort wird vorgelesen. Sprich " . $name
    . " direkt an und antworte nur auf das zuletzt Gesagte."
    . ($profileBlock !== '' ? "\n" . trim($profileBlock) : '')
    . ($memoryBlock !== '' ? "\n" . trim($memoryBlock) : '');
}

/** The messages of a live call (pure): Modelfile SYSTEM untouched (no system message), context, history. */
function coreui_live_messages(string $context, array $history, string $system = ''): array {
  $messages = [];
  if (trim($system) !== '') $messages[] = ['role' => 'system', 'content' => $system];
  $messages[] = ['role' => 'user', 'content' => $context];
  foreach ($history as $turn) {
    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($turn['content'] ?? ''));
    if ($content === '') continue;
    $messages[] = ['role' => $role, 'content' => $content];
  }
  return $messages;
}

/**
 * The history of a live call: up to 10 messages up to the live start ($sinceId), then every
 * message after it, oldest first, as ['id', 'role', 'content']. A message with Stimmsequenzen
 * reads as its transcript; a long answer is shortened the same way every time.
 * $transcriptOf(int $messageId): string gives the transcript ('' when there is none).
 */
function coreui_live_history(PDO $pdo, int $uid, string $sessionId, int $sinceId, string $emberCharacterId,
                             callable $transcriptOf): array {
  $base = 'SELECT id, character_id, message FROM stu_chat_messages WHERE channel = \'console\' AND user_id = ? AND session_id = ? '
    . 'AND alliance_id IS NULL AND deleted_at IS NULL';
  $before = $pdo->prepare($base . ' AND id <= ? ORDER BY id DESC LIMIT ' . COREUI_LIVE_HISTORY_BEFORE);
  $before->execute([$uid, $sessionId, $sinceId]);
  $after = $pdo->prepare($base . ' AND id > ? ORDER BY id ASC LIMIT ' . COREUI_LIVE_HISTORY_LIVE);
  $after->execute([$uid, $sessionId, $sinceId]);
  $rows = array_merge(array_reverse($before->fetchAll(PDO::FETCH_ASSOC) ?: []), $after->fetchAll(PDO::FETCH_ASSOC) ?: []);
  $out = [];
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $assistant = strtolower((string)$row['character_id']) === strtolower($emberCharacterId);
    $text = trim((string)preg_replace('~\[file:[a-f0-9]{32}\]~', '', (string)$row['message']));
    if (!$assistant) {
      $heard = trim($transcriptOf($id));
      if ($heard !== '') $text = trim($text . ($text !== '' ? ' ' : '') . $heard);
    } elseif (mb_strlen($text) > COREUI_LIVE_REPLY_HISTORY_CHARS) {
      $text = rtrim(mb_substr($text, 0, COREUI_LIVE_REPLY_HISTORY_CHARS)) . ' …';
    }
    if ($text === '') continue;
    $out[] = ['id' => $id, 'role' => $assistant ? 'assistant' : 'user', 'content' => $text];
  }
  return $out;
}

/** One line of a server-sent event (pure). */
function coreui_live_sse(string $event, array $data): string {
  return 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
}
