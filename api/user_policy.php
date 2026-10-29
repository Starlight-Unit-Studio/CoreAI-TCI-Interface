<?php
declare(strict_types=1);

/*
 * 0.5.85: rules per account (Admin Core > Benutzer > BEARBEITEN).
 *
 * Tools: web search, browser and Python can be blocked for one account; the
 * global switch under Betrieb stays the upper bound (a tool that is off there
 * is off for everyone). Limits: messages per day and the largest context
 * window; null means the account has no own limit. Without migration 021
 * every account has the defaults, and nothing breaks.
 */

const COREUI_USER_POLICY_TOOLS = ['web', 'browse', 'python'];
const COREUI_USER_POLICY_DAILY_MAX = 100000;

function coreui_user_policy_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, tool_web, daily_messages, max_num_ctx FROM stu_user_policies LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_user_policy_default(): array {
  return ['tools' => ['web' => true, 'browse' => true, 'python' => true], 'daily_messages' => null, 'max_num_ctx' => null];
}

function coreui_user_policy_from_row(array $row): array {
  $policy = coreui_user_policy_default();
  foreach (COREUI_USER_POLICY_TOOLS as $tool) $policy['tools'][$tool] = (int)($row['tool_' . $tool] ?? 1) === 1;
  $daily = $row['daily_messages'] ?? null;
  $policy['daily_messages'] = $daily === null ? null : max(1, (int)$daily);
  $ctx = $row['max_num_ctx'] ?? null;
  $policy['max_num_ctx'] = $ctx === null ? null : (int)$ctx;
  return $policy;
}

function coreui_user_policy_load(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_user_policy_ready($pdo)) return coreui_user_policy_default();
  $st = $pdo->prepare('SELECT * FROM stu_user_policies WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? coreui_user_policy_from_row($row) : coreui_user_policy_default();
}

/** Every account with own rules, by id (others have the defaults). */
function coreui_user_policy_all(PDO $pdo): array {
  if (!coreui_user_policy_ready($pdo)) return [];
  $out = [];
  foreach ($pdo->query('SELECT * FROM stu_user_policies') ?: [] as $row) {
    $out[(string)$row['user_id']] = coreui_user_policy_from_row($row);
  }
  return $out;
}

/** The global tool switches, narrowed by the account's own blocks (pure). */
function coreui_user_policy_tools(array $operator, array $policy): array {
  foreach (COREUI_USER_POLICY_TOOLS as $tool) {
    if (array_key_exists($tool, $operator) && empty($policy['tools'][$tool])) $operator[$tool] = false;
  }
  return $operator;
}

/**
 * Saves the rules of an account. $patch may hold 'tools' (tool => bool),
 * 'daily_messages' (null or 1..100000) and 'max_num_ctx' (null or one of
 * $ctxChoices); keys not given keep their value. Returns the stored rules.
 */
function coreui_user_policy_save(PDO $pdo, int $uid, array $patch, int $actorId, array $ctxChoices): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_user_policy_ready($pdo)) throw new RuntimeException('missing_schema_021');
  $policy = coreui_user_policy_load($pdo, $uid);
  if (isset($patch['tools'])) {
    if (!is_array($patch['tools'])) throw new InvalidArgumentException('invalid_tools');
    foreach ($patch['tools'] as $tool => $on) {
      if (!in_array($tool, COREUI_USER_POLICY_TOOLS, true) || !is_bool($on)) throw new InvalidArgumentException('invalid_tools');
      $policy['tools'][$tool] = $on;
    }
  }
  if (array_key_exists('daily_messages', $patch)) {
    $daily = $patch['daily_messages'];
    if ($daily === null || $daily === '' || $daily === 0) $policy['daily_messages'] = null;
    elseif (is_int($daily) && $daily >= 1 && $daily <= COREUI_USER_POLICY_DAILY_MAX) $policy['daily_messages'] = $daily;
    else throw new InvalidArgumentException('invalid_daily_messages');
  }
  if (array_key_exists('max_num_ctx', $patch)) {
    $ctx = $patch['max_num_ctx'];
    if ($ctx === null || $ctx === '' || $ctx === 0) $policy['max_num_ctx'] = null;
    elseif (is_int($ctx) && in_array($ctx, $ctxChoices, true)) $policy['max_num_ctx'] = $ctx;
    else throw new InvalidArgumentException('invalid_max_num_ctx');
  }
  $pdo->prepare(
    'INSERT INTO stu_user_policies (user_id, tool_web, tool_browse, tool_python, daily_messages, max_num_ctx, updated_by) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE tool_web = VALUES(tool_web), tool_browse = VALUES(tool_browse), '
    . 'tool_python = VALUES(tool_python), daily_messages = VALUES(daily_messages), max_num_ctx = VALUES(max_num_ctx), updated_by = VALUES(updated_by)'
  )->execute([
    $uid, $policy['tools']['web'] ? 1 : 0, $policy['tools']['browse'] ? 1 : 0, $policy['tools']['python'] ? 1 : 0,
    $policy['daily_messages'], $policy['max_num_ctx'], $actorId > 0 ? $actorId : null,
  ]);
  return coreui_user_policy_load($pdo, $uid);
}
