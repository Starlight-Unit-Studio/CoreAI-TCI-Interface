<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.86: Settings > Konto, modeled on the ChatGPT account page (api/account_page_store.php;
 * account provisioning stays in api/account_store.php).
 *
 * Benutzername: an @handle per account, 3 to 20 of a-z, 0-9, ".", "_" and "-", unique on
 * this server (migration 031, stu_usernames). A few names are reserved so nobody can pose
 * as Mara, CoreAI or the administration. It shows in the profile; a public profile with
 * its own link is planned.
 *
 * Plans: four templates an Inhaber or Admin picks per account under Admin Core > Benutzer:
 * Free, Go, Plus and Pro. Picking one writes real rules through api/user_policy.php (tools,
 * messages per day, largest context window), so a plan is never just a label. There are
 * no prices: CoreAI stays free. Changing single rules afterwards keeps the plan, marked
 * "angepasst". Without migration 031 nothing breaks: no usernames, no plans.
 */

require_once __DIR__ . '/user_policy.php';

const COREUI_USERNAME_PATTERN = '~^[a-z0-9._-]{3,20}$~';
const COREUI_USERNAME_RESERVED = [
  'admin', 'administrator', 'admins', 'root', 'system', 'support', 'hilfe', 'help', 'info',
  'coreai', 'core', 'mara', 'tci', 'ember', 'terra', 'terra-net', 'inhaber', 'owner',
  'moderator', 'mod', 'gast', 'guest', 'operator', 'starlight', 'quantum', 'security',
  'sicherheit', 'api', 'settings', 'login', 'me', 'null', 'undefined',
];
const COREUI_PLANS = ['free', 'go', 'plus', 'pro'];
const COREUI_PLAN_TOOL_NAMES = ['web' => 'Websuche', 'browse' => 'Recherche im Browser', 'python' => 'Python', 'image' => 'Bilderstellung'];

// --- Benutzername -----------------------------------------------------------------------------

function coreui_username_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, handle, changed_at FROM stu_usernames LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** What the person typed, as a handle: trimmed, without a leading "@", lower case. */
function coreui_username_clean(string $value): string {
  $value = strtolower(trim($value));
  if (str_starts_with($value, '@')) $value = substr($value, 1);
  return $value;
}

/** '' when the handle may be used, else the error code. */
function coreui_username_check(string $handle): string {
  if (preg_match(COREUI_USERNAME_PATTERN, $handle) !== 1) return 'username_invalid';
  if (in_array($handle, COREUI_USERNAME_RESERVED, true)) return 'username_reserved';
  return '';
}

function coreui_username_get(PDO $pdo, int $uid): ?string {
  if ($uid <= 0 || !coreui_username_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT handle FROM stu_usernames WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $handle = $st->fetchColumn();
  return is_string($handle) && $handle !== '' ? $handle : null;
}

/** The account that holds a handle, 0 when it is free. */
function coreui_username_owner(PDO $pdo, string $handle): int {
  $st = $pdo->prepare('SELECT user_id FROM stu_usernames WHERE handle = ? LIMIT 1');
  $st->execute([$handle]);
  return (int)($st->fetchColumn() ?: 0);
}

/** Sets the account's handle and returns it; InvalidArgumentException with the error code. */
function coreui_username_set(PDO $pdo, int $uid, string $value): string {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $handle = coreui_username_clean($value);
  $error = coreui_username_check($handle);
  if ($error !== '') throw new InvalidArgumentException($error);
  if (!coreui_username_ready($pdo)) throw new RuntimeException('missing_schema_031');
  $owner = coreui_username_owner($pdo, $handle);
  if ($owner === $uid) return $handle;
  if ($owner > 0) throw new InvalidArgumentException('username_taken');
  $now = gmdate('Y-m-d H:i:s');
  $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? 'INSERT INTO stu_usernames (user_id, handle, changed_at) VALUES (?, ?, ?) '
      . 'ON CONFLICT(user_id) DO UPDATE SET handle = excluded.handle, changed_at = excluded.changed_at'
    : 'INSERT INTO stu_usernames (user_id, handle, changed_at) VALUES (?, ?, ?) '
      . 'ON DUPLICATE KEY UPDATE handle = VALUES(handle), changed_at = VALUES(changed_at)';
  try {
    $pdo->prepare($sql)->execute([$uid, $handle, $now]);
  } catch (PDOException $e) {
    // Two people asked for the same handle at the same moment: the unique key decides.
    if ((string)$e->getCode() === '23000') throw new InvalidArgumentException('username_taken');
    throw $e;
  }
  return $handle;
}

/** A free handle made from the e-mail's local part, offered when the account has none. */
function coreui_username_suggest(PDO $pdo, int $uid, string $email): string {
  $local = strtolower((string)strtok($email, '@'));
  $base = (string)preg_replace('~[^a-z0-9._-]+~', '', $local);
  $base = trim($base, '.-_');
  if (strlen($base) < 3) $base = 'nutzer' . $base;
  $base = substr($base, 0, 20);
  if (!coreui_username_ready($pdo)) return $base;
  for ($n = 1; $n < 100; $n++) {
    $suffix = $n === 1 ? '' : (string)$n;
    $candidate = substr($base, 0, 20 - strlen($suffix)) . $suffix;
    if (coreui_username_check($candidate) !== '') continue;
    $owner = coreui_username_owner($pdo, $candidate);
    if ($owner === 0 || $owner === $uid) return $candidate;
  }
  return '';
}

// --- Plans ------------------------------------------------------------------------------------

function coreui_plan_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT plan FROM stu_user_policies LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** The largest offered window that is not above $wanted (null: the server's largest). */
function coreui_plan_ctx(?int $wanted, array $ctxChoices): ?int {
  if ($wanted === null) return null;
  $choices = array_values(array_filter(array_map('intval', $ctxChoices), static fn(int $n): bool => $n > 0));
  sort($choices);
  if ($choices === []) return null;
  $pick = $choices[0];
  foreach ($choices as $n) if ($n <= $wanted) $pick = $n;
  return $pick;
}

/** The four templates with the rules they write, already fitted to this server. */
function coreui_plan_catalog(array $ctxChoices): array {
  $all = ['web' => true, 'browse' => true, 'python' => true, 'image' => true];
  $defs = [
    'free' => ['Free', 'Für den Einstieg: unbegrenzt schreiben, mit Websuche.',
      ['web' => true, 'browse' => false, 'python' => false, 'image' => false], 8192],
    'go' => ['Go', 'Mehr Gedächtnis im Chat und Bilder erstellen.',
      ['web' => true, 'browse' => false, 'python' => false, 'image' => true], 16384],
    'plus' => ['Plus', 'Das volle Werkzeugpaket.', $all, 65536],
    'pro' => ['Pro', 'Alles, mit dem größten Kontextfenster dieses Servers.', $all, null],
  ];
  $out = [];
  foreach ($defs as $key => [$label, $tagline, $tools, $ctx]) {
    $out[$key] = ['label' => $label, 'tagline' => $tagline,
      'policy' => ['tools' => $tools, 'daily_messages' => null, 'max_num_ctx' => coreui_plan_ctx($ctx, $ctxChoices)]];
  }
  return $out;
}

function coreui_plan_ctx_label(int $n): string {
  return (string)(int)round($n / 1024) . 'K';
}

function coreui_plan_join(array $items): string {
  if (count($items) < 2) return implode('', $items);
  $last = array_pop($items);
  return implode(', ', $items) . ' und ' . $last;
}

/** What rules mean in words, for the account page and the plan cards. */
function coreui_plan_features(array $policy, array $ctxChoices): array {
  $out = [];
  $daily = $policy['daily_messages'] ?? null;
  $out[] = $daily === null ? 'Unbegrenzte Textchats' : 'Bis zu ' . number_format((int)$daily, 0, ',', '.') . ' Nachrichten pro Tag';
  $on = [];
  $off = [];
  foreach (COREUI_PLAN_TOOL_NAMES as $tool => $name) {
    if (!empty($policy['tools'][$tool])) $on[] = $name;
    else $off[] = $name;
  }
  if ($off === []) {
    $out[] = 'Alle Werkzeuge: ' . coreui_plan_join($on);
  } else {
    if ($on !== []) $out[] = coreui_plan_join($on);
    $out[] = 'Ohne ' . coreui_plan_join($off);
  }
  $ctx = $policy['max_num_ctx'] ?? null;
  $max = $ctxChoices !== [] ? max(array_map('intval', $ctxChoices)) : 0;
  $out[] = $ctx === null
    ? 'Größtes Kontextfenster dieses Servers' . ($max > 0 ? ' (' . coreui_plan_ctx_label($max) . ')' : '')
    : 'Kontextfenster bis ' . coreui_plan_ctx_label((int)$ctx);
  return $out;
}

/** True when the rules are exactly the template's. */
function coreui_plan_matches(array $policy, array $preset): bool {
  foreach (COREUI_USER_POLICY_TOOLS as $tool) {
    if (!empty($policy['tools'][$tool]) !== !empty($preset['tools'][$tool])) return false;
  }
  return ($policy['daily_messages'] ?? null) === ($preset['daily_messages'] ?? null)
    && ($policy['max_num_ctx'] ?? null) === ($preset['max_num_ctx'] ?? null);
}

function coreui_plan_get(PDO $pdo, int $uid): ?string {
  if ($uid <= 0 || !coreui_plan_ready($pdo)) return null;
  $st = $pdo->prepare('SELECT plan FROM stu_user_policies WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $plan = $st->fetchColumn();
  return is_string($plan) && in_array($plan, COREUI_PLANS, true) ? $plan : null;
}

/** Every account with a plan, by id. */
function coreui_plan_all(PDO $pdo): array {
  if (!coreui_plan_ready($pdo)) return [];
  $out = [];
  foreach ($pdo->query('SELECT user_id, plan FROM stu_user_policies WHERE plan IS NOT NULL') ?: [] as $row) {
    if (in_array($row['plan'], COREUI_PLANS, true)) $out[(string)$row['user_id']] = $row['plan'];
  }
  return $out;
}

/**
 * Gives an account a plan: the template's rules are written like Admin Core's own tool and
 * limit fields, then the plan is remembered. null removes the plan and keeps the rules.
 * Returns ['plan' => ..., 'policy' => ...].
 */
function coreui_plan_apply(PDO $pdo, int $uid, ?string $plan, int $actorId, array $ctxChoices): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if ($plan !== null && !in_array($plan, COREUI_PLANS, true)) throw new InvalidArgumentException('invalid_plan');
  if (!coreui_plan_ready($pdo)) throw new RuntimeException('missing_schema_031');
  if ($plan !== null) {
    $preset = coreui_plan_catalog($ctxChoices)[$plan]['policy'];
    coreui_user_policy_save($pdo, $uid, [
      'tools' => $preset['tools'], 'daily_messages' => $preset['daily_messages'], 'max_num_ctx' => $preset['max_num_ctx'],
    ], $actorId, $ctxChoices);
  }
  $pdo->prepare('UPDATE stu_user_policies SET plan = ? WHERE user_id = ?')->execute([$plan, $uid]);
  return ['plan' => coreui_plan_get($pdo, $uid), 'policy' => coreui_user_policy_load($pdo, $uid)];
}

/**
 * The "Plan" row of Settings > Konto: the account's plan (or why it has none), what its rules
 * mean, today's count against a daily limit, and the four templates to compare.
 */
function coreui_account_plan_summary(PDO $pdo, int $uid, int $level, bool $guest, array $global, array $ctxChoices): array {
  $catalog = coreui_plan_catalog($ctxChoices);
  $cards = [];
  foreach ($catalog as $key => $def) {
    $cards[] = ['key' => $key, 'label' => $def['label'], 'tagline' => $def['tagline'],
      'features' => coreui_plan_features($def['policy'], $ctxChoices)];
  }
  $serverOff = [];
  foreach (COREUI_PLAN_TOOL_NAMES as $tool => $name) {
    if (array_key_exists($tool, $global) && $global[$tool] === false) $serverOff[] = $name;
  }
  $base = ['plans' => $cards, 'server_off' => $serverOff, 'role' => coreui_plan_role($level, $guest)];
  if ($guest) {
    return $base + ['key' => null, 'label' => 'Gastzugang', 'customized' => false, 'daily_messages' => null, 'used_today' => null,
      'max_num_ctx' => null, 'features' => [], 'note' => 'Der Gastzugang hat eigene Grenzen und keinen Plan.'];
  }
  if ($level === 0) {
    return $base + ['key' => null, 'label' => 'Inhaber', 'customized' => false, 'daily_messages' => null, 'used_today' => null,
      'max_num_ctx' => null, 'features' => coreui_plan_features(coreui_user_policy_default(), $ctxChoices),
      'note' => 'Für dich als Inhaber gelten keine Limits. Pläne vergibst du in Admin Core unter Benutzer.'];
  }
  $policy = coreui_user_policy_load($pdo, $uid);
  $key = coreui_plan_get($pdo, $uid);
  $custom = false;
  if ($key !== null) {
    $custom = !coreui_plan_matches($policy, $catalog[$key]['policy']);
    $label = $catalog[$key]['label'] . ($custom ? ' (angepasst)' : '');
    $note = 'Deinen Plan legt der Inhaber oder ein Admin in Admin Core fest. Alle Pläne sind kostenlos.';
  } elseif (coreui_plan_matches($policy, coreui_user_policy_default())) {
    $label = 'Kein Plan';
    $note = 'Für dein Konto gelten keine eigenen Limits.';
  } else {
    $label = 'Individuell';
    $note = 'Für dein Konto gelten eigene Regeln des Inhabers oder eines Admins.';
  }
  $daily = $policy['daily_messages'];
  return $base + ['key' => $key, 'label' => $label, 'customized' => $custom, 'daily_messages' => $daily,
    'used_today' => $daily !== null ? coreui_user_policy_used_today($pdo, $uid) : null, 'max_num_ctx' => $policy['max_num_ctx'],
    'features' => coreui_plan_features($policy, $ctxChoices), 'note' => $note];
}

function coreui_plan_role(int $level, bool $guest): string {
  if ($guest) return 'Gast';
  return $level === 0 ? 'Inhaber' : ($level === 1 ? 'Admin' : 'Nutzer');
}
