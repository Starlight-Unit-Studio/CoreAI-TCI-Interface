<?php
declare(strict_types=1);
// Offline safety contract. Must not require a live DB connection.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$test = (string)file_get_contents($root . '/scripts/account-language-selftest.php');
$clean = (string)file_get_contents($root . '/scripts/cleanup-language-test-users.php');
function language_safety(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Account language safety FAIL: $message\n");
        exit(1);
    }
}
language_safety(str_contains($test, "\$dbWanted = getenv('COREUI_SELFTEST_DB') === '1';"),
    'database tests require explicit opt-in');
language_safety(!str_contains($test, "is_file(\$root . '/api/config.local.php') || getenv('COREUI_SELFTEST_DB')"),
    'local production config must never trigger DB writes');
language_safety(strpos($test, "if (!\$dbWanted) {") < strpos($test, "require_once \$root . '/api/config.php';"),
    'normal preflight returns before loading DB configuration');
language_safety(str_contains($test, 'COREUI_SELFTEST_DB_DSN')
    && str_contains($test, 'COREUI_SELFTEST_DB_USER')
    && str_contains($test, 'COREUI_SELFTEST_DB_PASS')
    && str_contains($test, '_selftest$/D')
    && str_contains($test, 'strcasecmp($dbName, (string)STU_DB_NAME) === 0')
    && !str_contains($test, '$pdo = stu_pdo();'),
    'separate MySQL _selftest database, never the configured production PDO');
language_safety(str_contains($clean, "['--dry-run', '--execute']")
    && str_contains($clean, 'LANG TESTKONTEN LOESCHEN')
    && str_contains($clean, "password_hash IS NULL OR password_hash = ''")
    && str_contains($clean, '1500000000 AND 1900000000')
    && str_contains($clean, 'coreui\\.invalid')
    && str_contains($clean, 'information_schema.COLUMNS')
    && str_contains($clean, "if (\$mode === '--dry-run')")
    && !str_contains($clean, "DROP TABLE") && !str_contains($clean, "TRUNCATE TABLE"),
    'cleanup is manually confirmed, exact-pattern, dry-run first and protects linked rows');
echo "CoreAI account language safety: production never seeded, isolated test opt-in, manual narrow cleanup OK\n";
