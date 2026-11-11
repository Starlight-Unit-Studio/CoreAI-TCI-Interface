<?php
declare(strict_types=1);
/**
 * Manual, preview-first cleanup of ONLY unused language-test accounts.
 *
 * php scripts/cleanup-language-test-users.php --dry-run
 * php scripts/cleanup-language-test-users.php --execute
 */
if (PHP_SAPI !== 'cli') exit(1);
$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--execute'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php scripts/cleanup-language-test-users.php --dry-run|--execute\n");
    exit(2);
}
require_once dirname(__DIR__) . '/api/db.php';
$pdo = stu_pdo();

// Signature from account-language-selftest.php: high synthetic ID,
// eight lowercase hexadecimal digits, no password and normal non-guest role.
$select = <<<'SQL'
SELECT id, username FROM stu_users
 WHERE id BETWEEN 1500000000 AND 1900000000
   AND username LIKE 'lang-%@coreui.invalid'
   AND is_guest = 0 AND permission_level = 4
   AND (password_hash IS NULL OR password_hash = '')
 ORDER BY id
SQL;
function language_test_candidates(PDO $pdo, string $select): array {
    $rows = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($rows, static function (array $row): bool {
        return preg_match('/\Alang-[a-f0-9]{8}@coreui\.invalid\z/D', (string)$row['username']) === 1
            && (int)$row['id'] >= 1500000000 && (int)$row['id'] <= 1900000000;
    }));
}
/**
 * Test source writes only stu_users and stu_user_ai_settings, whose FK
 * cascades on deletion. All other user-reference columns protect an account.
 */
function language_test_blocked(PDO $pdo, array $rows): array {
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    if ($ids === []) return [];
    $columns = $pdo->query(<<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME <> 'stu_users'
   AND TABLE_NAME <> 'stu_user_ai_settings'
   AND (
     COLUMN_NAME LIKE '%user_id'
     OR COLUMN_NAME IN ('recipient_uid', 'trigger_user_id',
       'actor_user_id', 'granted_by', 'muted_by_user_id')
   )
SQL)->fetchAll(PDO::FETCH_ASSOC);
    $blocked = [];
    foreach ($columns as $column) {
        $table = (string)$column['TABLE_NAME'];
        $name = (string)$column['COLUMN_NAME'];
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $table)
            || !preg_match('/\A[a-zA-Z0-9_]+\z/D', $name)) {
            throw new RuntimeException('unsafe_schema_identifier');
        }
        $q = chr(96);
        foreach (array_chunk($ids, 300) as $chunk) {
            $holders = implode(',', array_fill(0, count($chunk), '?'));
            $query = "SELECT DISTINCT $q$name$q FROM $q$table$q WHERE $q$name$q IN ($holders)";
            $stmt = $pdo->prepare($query);
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $linked) {
                $blocked[(int)$linked] = true;
            }
        }
    }
    return $blocked;
}
function language_test_eligible(array $rows, array $blocked): array {
    return array_values(array_filter($rows,
        static fn(array $row): bool => !isset($blocked[(int)$row['id']])));
}
$rows = language_test_candidates($pdo, $select);
$blocked = language_test_blocked($pdo, $rows);
$eligible = language_test_eligible($rows, $blocked);
printf("Matching language-test accounts: %d\n", count($rows));
printf("Protected by other account data: %d\n", count($rows) - count($eligible));
printf("Eligible unused test accounts: %d\n", count($eligible));
foreach (array_slice($eligible, 0, 15) as $row) {
    printf("  %s  %s\n", $row['id'], $row['username']);
}
if (count($eligible) > 15) printf("  ... and %d more\n", count($eligible) - 15);
if ($mode === '--dry-run') {
    echo "DRY RUN: no database rows changed.\n";
    exit(0);
}
if ($eligible === []) {
    echo "No eligible test accounts. Nothing changed.\n";
    exit(0);
}
$tty = @fopen('/dev/tty', 'r');
if ($tty === false) {
    fwrite(STDERR, "Interactive terminal required. No deletion performed.\n");
    exit(1);
}
fwrite(STDOUT, "To remove these unused test accounts type exactly: LANG TESTKONTEN LOESCHEN\n> ");
$answer = fgets($tty);
fclose($tty);
if (trim((string)$answer) !== 'LANG TESTKONTEN LOESCHEN') {
    fwrite(STDERR, "Cancelled. No deletion performed.\n");
    exit(1);
}
$pdo->beginTransaction();
try {
    // Re-evaluate within the same transaction; never trust an old preview.
    $rows = language_test_candidates($pdo, $select . ' FOR UPDATE');
    $blocked = language_test_blocked($pdo, $rows);
    $eligible = language_test_eligible($rows, $blocked);
    $delete = $pdo->prepare(<<<'SQL'
DELETE FROM stu_users WHERE id = ? AND username = ?
  AND is_guest = 0 AND permission_level = 4
  AND (password_hash IS NULL OR password_hash = '')
SQL);
    $deleted = 0;
    foreach ($eligible as $row) {
        $delete->execute([(int)$row['id'], (string)$row['username']]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('candidate_changed');
        ++$deleted;
    }
    $pdo->commit();
    printf("Removed %d unused language-test accounts. No other users changed.\n", $deleted);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Cleanup aborted and rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
