<?php
declare(strict_types=1);

/*
 * 0.5.83: who may use which Core.
 *
 * Mara's Cores (the Gemma 4 tiers, local or cloud, and Cores built from her
 * Modelfile) belong to CoreAI and are open to every account; guests stay on
 * the default Core anyway. Every other model in Ollama (own names, such as a
 * private cloud model of the owner) is the Inhaber's: he uses all of them and
 * releases single ones per account under Admin Core > Benutzer. The server
 * checks this on the list, on the choice and when a turn starts, so a revoked
 * release takes effect at the next message.
 */

require_once __DIR__ . '/model_catalog.php';

/** A Core of CoreAI (pure): a TCI tier or one of Mara's Cores. */
function coreui_model_is_coreai(string $model): bool {
  return coreui_core_tier_of($model) !== null || coreui_core_is_mara($model);
}

function coreui_model_access_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, model_name FROM stu_user_model_access LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** Released models of an account (names as stored). */
function coreui_model_access_grants(PDO $pdo, int $uid): array {
  if ($uid <= 0 || !coreui_model_access_ready($pdo)) return [];
  $st = $pdo->prepare('SELECT model_name FROM stu_user_model_access WHERE user_id = ? ORDER BY model_name');
  $st->execute([$uid]);
  return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Every release, by account id. */
function coreui_model_access_all(PDO $pdo): array {
  if (!coreui_model_access_ready($pdo)) return [];
  $out = [];
  foreach ($pdo->query('SELECT user_id, model_name FROM stu_user_model_access ORDER BY user_id, model_name') ?: [] as $row) {
    $out[(string)$row['user_id']][] = (string)$row['model_name'];
  }
  return $out;
}

/** Role of an account: level and guest flag (unknown accounts count as guests). */
function coreui_model_access_account(PDO $pdo, int $uid): array {
  if ($uid <= 0) return ['level'=>4, 'guest'=>true];
  try {
    $st = $pdo->prepare('SELECT permission_level, is_guest FROM stu_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $row = false;
  }
  if (!is_array($row)) return ['level'=>4, 'guest'=>true];
  return ['level'=>(int)$row['permission_level'], 'guest'=>(int)($row['is_guest'] ?? 0) === 1];
}

/** May this account use the model? '' (the default Core) always. */
function coreui_model_access_allowed(PDO $pdo, int $uid, string $model): bool {
  if (trim($model) === '' || coreui_model_is_coreai($model)) return true;
  $account = coreui_model_access_account($pdo, $uid);
  if ($account['guest']) return false;
  if ($account['level'] === 0) return true;
  foreach (coreui_model_access_grants($pdo, $uid) as $granted) {
    if (coreui_core_same($granted, $model)) return true;
  }
  return false;
}

/** The Ollama list as this account may see it (CoreAI Cores plus its releases). */
function coreui_model_access_filter(PDO $pdo, int $uid, array $models): array {
  $account = coreui_model_access_account($pdo, $uid);
  if (!$account['guest'] && $account['level'] === 0) return array_values($models);
  $grants = $account['guest'] ? [] : coreui_model_access_grants($pdo, $uid);
  return array_values(array_filter($models, static function ($m) use ($grants): bool {
    $name = (string)(is_array($m) ? ($m['name'] ?? '') : $m);
    if ($name === '' || coreui_model_is_coreai($name)) return $name !== '';
    foreach ($grants as $granted) {
      if (coreui_core_same($granted, $name)) return true;
    }
    return false;
  }));
}

/**
 * Replaces the releases of an account. Only valid names outside CoreAI are
 * stored (CoreAI Cores need no release); returns the stored list.
 */
function coreui_model_access_save(PDO $pdo, int $uid, array $models, int $actorId): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_model_access_ready($pdo)) throw new RuntimeException('missing_schema_020');
  $clean = [];
  foreach ($models as $name) {
    $name = trim((string)$name);
    if (!coreui_model_name_valid($name)) throw new InvalidArgumentException('invalid_model_name');
    if (coreui_model_is_coreai($name)) continue;
    $clean[strtolower($name)] = $name;
  }
  if (count($clean) > 50) throw new InvalidArgumentException('too_many_models');
  $pdo->beginTransaction();
  try {
    $pdo->prepare('DELETE FROM stu_user_model_access WHERE user_id = ?')->execute([$uid]);
    $ins = $pdo->prepare('INSERT INTO stu_user_model_access (user_id, model_name, granted_by) VALUES (?, ?, ?)');
    foreach ($clean as $name) $ins->execute([$uid, $name, $actorId > 0 ? $actorId : null]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return coreui_model_access_grants($pdo, $uid);
}
