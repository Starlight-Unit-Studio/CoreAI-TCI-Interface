<?php
declare(strict_types=1);

require_once __DIR__ . '/console_session_store.php';

/*
 * 0.7.74 (P09 C): conversation branches.
 *
 * At one of Mara's replies a chat branches off into a new chat. The branch gets every
 * visible message up to that reply (its attachments too, so Mara still sees pictures,
 * hears Stimmsequenzen and knows the images she made), the chat's project and, when it
 * ends before the branch point, the compaction summary with its archive. The original
 * chat is only read, never changed. Downloads, the work path and Deep Research runs stay
 * with the original chat. Migration 046 records where a branch came from.
 */

const COREUI_BRANCH_MAX_MESSAGES = 5000;
const COREUI_BRANCH_PREFIX = 'Zweig: ';

function coreui_branch_ready(PDO $pdo): bool {
  static $memo = [];
  $key = spl_object_id($pdo);
  if (!array_key_exists($key, $memo)) {
    try {
      $pdo->query('SELECT branch_source_id, branch_source_message_id, branch_point_id FROM stu_console_sessions LIMIT 0');
      $memo[$key] = true;
    } catch (Throwable $e) {
      $memo[$key] = false;
    }
  }
  return $memo[$key];
}

/** Title of a branch (pure): "Zweig: " once, at most 64 characters. */
function coreui_branch_title(string $title): string {
  $title = trim((string)preg_replace('~\s+~u', ' ', $title));
  while (mb_stripos($title, COREUI_BRANCH_PREFIX, 0, 'UTF-8') === 0) {
    $title = trim(mb_substr($title, mb_strlen(COREUI_BRANCH_PREFIX, 'UTF-8'), null, 'UTF-8'));
  }
  if ($title === '') $title = 'Chat';
  return mb_substr(COREUI_BRANCH_PREFIX . $title, 0, 64, 'UTF-8');
}

/**
 * The messages a branch takes (pure). $rows: the chat's visible messages, oldest first, each
 * with id, reply_to_id, mara (bool) and message. $continues: continuation id => the reply it
 * continues ("Weiter"). The branch ends with the chosen reply as it is shown: a continuation
 * belongs to its reply and is merged into it, so the branch shows one answer, not two
 * variants. Other answer variants to the same question stay behind; earlier questions keep
 * everything Mara saw in the original chat. Each row gets parts: the original ids in it.
 */
function coreui_branch_pick(array $rows, int $messageId, array $continues = []): array {
  $byId = [];
  foreach ($rows as $row) $byId[(int)$row['id']] = $row;
  if (!isset($byId[$messageId]) || empty($byId[$messageId]['mara'])) return [];
  $root = static function (int $id) use ($byId, $continues): int {
    $seen = [];
    while (isset($continues[$id]) && isset($byId[(int)$continues[$id]]) && !isset($seen[$id])) {
      $seen[$id] = true;
      $id = (int)$continues[$id];
    }
    return $id;
  };
  $base = $root($messageId);
  $turn = (int)($byId[$base]['reply_to_id'] ?? 0);
  $out = [];
  $index = [];
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    if ($id > $base || $root($id) !== $id) continue;
    if ($id !== $base && $turn > 0 && !empty($row['mara']) && (int)($row['reply_to_id'] ?? 0) === $turn) continue;
    $row['parts'] = [$id];
    $index[$id] = count($out);
    $out[] = $row;
  }
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $r = $root($id);
    if ($r === $id || !isset($index[$r])) continue;
    $k = $index[$r];
    $text = trim((string)($row['message'] ?? ''));
    if ($text !== '') $out[$k]['message'] = rtrim((string)$out[$k]['message']) . "\n\n" . $text;
    $out[$k]['parts'][] = $id;
  }
  return $out;
}

/**
 * The compaction state a branch may take over (pure), or null. Only a summary that folds
 * nothing after the branch point belongs to the branch; otherwise it would know what was
 * said later in the original chat. Its ids are moved to the branch's own messages.
 * $idMap: original message id => branch message id, oldest first.
 */
function coreui_branch_compact_state(?array $state, int $pointId, array $idMap): ?array {
  if (!is_array($state) || trim((string)($state['summary'] ?? '')) === '') return null;
  $upto = (int)($state['upto_id'] ?? 0);
  $archived = (int)($state['archived_upto'] ?? 0);
  if ($upto <= 0 || $upto > $pointId || $archived > $pointId) return null;
  $map = static function (int $old) use ($idMap): int {
    $best = 0;
    foreach ($idMap as $from => $to) {
      if ((int)$from <= $old) $best = (int)$to;
    }
    return $best;
  };
  $state['upto_id'] = $map($upto);
  $state['archived_upto'] = $archived > 0 ? $map($archived) : 0;
  if ($state['upto_id'] <= 0) return null;
  $state['updated_at'] = time();
  return $state;
}

/** Continuations ("Weiter") of this chat: continuation id => the reply it continues. */
function coreui_branch_continuations(PDO $pdo, int $uid, string $sessionId): array {
  try {
    $st = $pdo->prepare(
      "SELECT response_message_id, source_response_id FROM stu_console_generation_requests
        WHERE user_id=? AND session_id=? AND mode='continue' AND status='done'
          AND response_message_id IS NOT NULL AND source_response_id IS NOT NULL"
    );
    $st->execute([$uid, $sessionId]);
  } catch (PDOException $e) {
    return [];
  }
  $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $out[(int)$row['response_message_id']] = (int)$row['source_response_id'];
  }
  return $out;
}

/** Whether something still writes into the chat (Mara answering, browsing, a run). */
function coreui_branch_busy(PDO $pdo, int $uid, string $sessionId): bool {
  $checks = [
    "SELECT id FROM stu_console_generation_requests WHERE user_id=? AND session_id=? AND status IN ('issued','running') AND expires_at>=NOW() LIMIT 1",
    "SELECT id FROM stu_ember_browse_jobs WHERE channel='console' AND recipient_uid=? AND session_id=? AND status IN ('queued','running') LIMIT 1",
    "SELECT run_id FROM stu_console_agent_runs WHERE user_id=? AND session_id=? AND status IN ('queued','running') LIMIT 1",
  ];
  foreach ($checks as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$uid, $sessionId]);
      if ($st->fetchColumn()) return true;
    } catch (PDOException $e) {
      // A table an older install does not have yet cannot hold a running job.
    }
  }
  return false;
}

/**
 * Branches $sessionId off at Mara's reply $messageId into a new chat of the same account.
 * Returns the new chat (like coreui_console_session_create) plus copied and point_id.
 */
function coreui_branch_create(PDO $pdo, int $uid, string $sessionId, int $messageId): array {
  if (!coreui_branch_ready($pdo)) throw new RuntimeException('branch_migration_required');
  $sessionId = coreui_console_session_normalize_id($sessionId);
  if ($uid <= 0 || $sessionId === '' || $messageId <= 0) throw new InvalidArgumentException('invalid_branch');
  $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $ember = strtolower(function_exists('ember_character_id') ? ember_character_id() : 'ember');

  $copiedArchive = '';
  $own = !$pdo->inTransaction();
  if ($own) $pdo->beginTransaction();
  try {
    // Not archived, not locked, this account only (the same guard as every chat read).
    $source = coreui_console_session_require($pdo, $uid, $sessionId);
    if ($mysql) {
      $pdo->prepare('SELECT id FROM stu_console_sessions WHERE id=? AND user_id=? FOR UPDATE')->execute([$sessionId, $uid]);
    }
    if (coreui_branch_busy($pdo, $uid, $sessionId)) throw new RuntimeException('session_busy');

    $st = $pdo->prepare(
      "SELECT id, reply_to_id, character_id FROM stu_chat_messages
        WHERE id=? AND channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=? AND deleted_at IS NULL"
    );
    $st->execute([$messageId, $uid, $sessionId]);
    $chosen = $st->fetch(PDO::FETCH_ASSOC);
    if (!$chosen) throw new RuntimeException('message_not_found');
    if (strtolower((string)$chosen['character_id']) !== $ember) throw new InvalidArgumentException('branch_needs_reply');

    $st = $pdo->prepare(
      "SELECT id, reply_to_id, character_id, character_name, message, thinking_content, image_url, file_uuid, created_at
         FROM stu_chat_messages
        WHERE channel='console' AND alliance_id IS NULL AND user_id=? AND session_id=? AND deleted_at IS NULL
        ORDER BY id ASC"
    );
    $st->execute([$uid, $sessionId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) $row['mara'] = strtolower((string)$row['character_id']) === $ember;
    unset($row);
    $rows = coreui_branch_pick($rows, $messageId, coreui_branch_continuations($pdo, $uid, $sessionId));
    if ($rows === []) throw new RuntimeException('message_not_found');
    if (count($rows) > COREUI_BRANCH_MAX_MESSAGES) throw new RuntimeException('branch_too_long');

    $stProject = $pdo->prepare('SELECT project_id FROM stu_console_sessions WHERE id=? AND user_id=?');
    $stProject->execute([$sessionId, $uid]);
    $projectId = (string)($stProject->fetchColumn() ?: '');
    if ($projectId !== '') {
      try {
        coreui_project_require($pdo, $uid, $projectId);
      } catch (Throwable $e) {
        $projectId = '';
      }
    }
    $branch = coreui_console_session_create($pdo, $uid, coreui_branch_title((string)$source['title']), $projectId);
    $branchId = (string)$branch['id'];

    $insert = $pdo->prepare(
      "INSERT INTO stu_chat_messages
         (channel, alliance_id, session_id, user_id, character_id, character_name, message, thinking_content,
          image_url, file_uuid, reply_to_id, created_at)
       VALUES ('console', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $idMap = [];
    $partMap = [];
    foreach ($rows as $row) {
      $oldReply = (int)($row['reply_to_id'] ?? 0);
      $insert->execute([
        $branchId, $uid, (string)$row['character_id'], (string)$row['character_name'], (string)$row['message'],
        $row['thinking_content'], $row['image_url'], $row['file_uuid'],
        $oldReply > 0 && isset($idMap[$oldReply]) ? $idMap[$oldReply] : null,
        (string)$row['created_at'],
      ]);
      $newId = (int)$pdo->lastInsertId();
      $idMap[(int)$row['id']] = $newId;
      foreach ($row['parts'] as $part) $partMap[(int)$part] = $newId;
    }

    // Attachments point at the same files; deleting the original keeps them while the branch uses them.
    // A merged continuation brings its own attachments to the reply (positions 0 to 9 per message).
    $oldIds = array_keys($partMap);
    $stAtt = $pdo->prepare(
      'SELECT message_id, media_uuid, position FROM stu_console_message_attachments WHERE user_id=? AND message_id IN ('
      . implode(',', array_fill(0, count($oldIds), '?')) . ') ORDER BY message_id, position'
    );
    $stAtt->execute([$uid, ...$oldIds]);
    $insAtt = $pdo->prepare(
      'INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $attachments = 0;
    $used = [];
    foreach ($stAtt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $att) {
      $target = $partMap[(int)$att['message_id']];
      $uuid = (string)$att['media_uuid'];
      if (isset($used[$target][$uuid])) continue;
      $position = count($used[$target] ?? []);
      if ($position > 9) continue;
      $insAtt->execute([$target, $uuid, $uid, $position]);
      $used[$target][$uuid] = true;
      $attachments++;
    }

    $last = (int)end($idMap);
    $base = (int)end($rows)['id'];
    $point = (int)$idMap[$base];
    $pdo->prepare(
      'UPDATE stu_console_sessions SET since_id=?, last_message_id=?, last_read_message_id=?,
              branch_source_id=?, branch_source_message_id=?, branch_point_id=?, updated_at=NOW()
        WHERE id=? AND user_id=?'
    )->execute([$last, $last, $last, $sessionId, $messageId, $point, $branchId, $uid]);

    // The summary of older messages, only when it ends before the branch point.
    $summaryKept = false;
    $state = coreui_branch_compact_state(coreui_compact_load($pdo, $uid, $sessionId), $base, $idMap);
    if ($state !== null) {
      $from = coreui_compact_archive_path($uid, $sessionId);
      $to = coreui_compact_archive_path($uid, $branchId);
      if ((int)$state['archived_upto'] > 0 && is_file($from)) {
        if (!is_dir(dirname($to))) @mkdir(dirname($to), 0770, true);
        if (@copy($from, $to)) $copiedArchive = $to;
        else $state = null;
      }
      if ($state !== null) {
        coreui_compact_save($pdo, $uid, $branchId, $state);
        $summaryKept = true;
      }
    }

    if ($own) $pdo->commit();
  } catch (Throwable $e) {
    if ($own && $pdo->inTransaction()) $pdo->rollBack();
    if ($copiedArchive !== '' && is_file($copiedArchive)) @unlink($copiedArchive);
    throw $e;
  }

  $branch['message_count'] = count($idMap);
  $branch['last_message_id'] = $last;
  $branch['last_read_message_id'] = $last;
  $branch['since_id'] = $last;
  return [
    'session' => $branch,
    'copied' => count($idMap),
    'attachments' => $attachments,
    'point_id' => $point,
    'summary_kept' => $summaryKept,
  ];
}

/**
 * Where a chat branched off, for the line in the chat. The title of the original is only
 * named while that chat is visible: a locked or deleted chat stays anonymous.
 */
function coreui_branch_info(PDO $pdo, int $uid, string $sessionId): ?array {
  if (!coreui_branch_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT branch_source_id, branch_point_id FROM stu_console_sessions WHERE id=? AND user_id=?');
  $st->execute([$sessionId, $uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (string)($row['branch_source_id'] ?? '') === '') return null;
  $sourceId = (string)$row['branch_source_id'];
  $sql = 'SELECT title, archived_at' . (coreui_console_chat_lock_ready($pdo) ? ', locked_at' : ', NULL AS locked_at')
    . ' FROM stu_console_sessions WHERE id=? AND user_id=?';
  $stSource = $pdo->prepare($sql);
  $stSource->execute([$sourceId, $uid]);
  $source = $stSource->fetch(PDO::FETCH_ASSOC);
  $state = !$source || !empty($source['locked_at']) ? 'gone' : (!empty($source['archived_at']) ? 'archived' : 'active');
  return [
    'point_id' => (int)($row['branch_point_id'] ?? 0),
    'source_state' => $state,
    'source_id' => $state === 'active' ? $sourceId : null,
    'source_title' => $state === 'gone' ? null : (string)$source['title'],
  ];
}
