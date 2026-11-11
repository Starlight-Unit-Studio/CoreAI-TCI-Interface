<?php
declare(strict_types=1);
// Real SQLite-backed tests for guest image quota reservation and refund.
// php scripts/guest-image-quota-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/helpers.php';
require_once dirname(__DIR__) . '/api/guest_access.php';

function quota_test(bool $condition, string $message): void {
  if (!$condition) { fwrite(STDERR, "Guest images FAIL: {$message}\n"); exit(1); }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_users (id INTEGER PRIMARY KEY, is_guest INT NOT NULL)');
$pdo->exec('CREATE TABLE stu_abuse_counters (k TEXT PRIMARY KEY, window_start TEXT NOT NULL, hits INT NOT NULL, expires_at TEXT NOT NULL)');
$pdo->exec('INSERT INTO stu_users (id, is_guest) VALUES (1,1),(2,0),(3,1)');
coreui_abuse_secret($pdo, str_repeat('a', 64));
stu_app_setting_set($pdo, 'guest_image_daily_limit', '3');
stu_app_setting_set($pdo, 'guest_tool_image_enabled', '1');

$dayOne = strtotime('2026-11-11 12:00:00');
$dayTwo = strtotime('2026-11-12 00:01:00');
quota_test(coreui_guest_image_limit($pdo) === 3, 'default admin image limit is three');
quota_test(coreui_guest_image_quota($pdo, 1, $dayOne)['left'] === 3, 'new guest begins with 3 images');
$first = coreui_guest_image_reserve($pdo, 1, $dayOne);
$second = coreui_guest_image_reserve($pdo, 1, $dayOne);
$third = coreui_guest_image_reserve($pdo, 1, $dayOne);
quota_test(!empty($first['ok']) && !empty($second['ok']) && !empty($third['ok'])
  && $third['left'] === 0, 'three independent successful image reservations');
$fourth = coreui_guest_image_reserve($pdo, 1, $dayOne);
quota_test(empty($fourth['ok']) && $fourth['error'] === 'guest_image_limit_reached'
  && coreui_guest_image_quota($pdo, 1, $dayOne)['used'] === 3,
  'fourth image refused without counting it');
quota_test(coreui_guest_image_quota($pdo, 3, $dayOne)['left'] === 3,
  'another guest receives a separate daily budget');
quota_test(coreui_guest_image_reserve($pdo, 2, $dayOne) === ['ok'=>true, 'guest'=>false]
  && coreui_guest_image_quota($pdo, 2, $dayOne) === null,
  'registered users are unaffected by guest image limits');

coreui_guest_image_release($pdo, $third);
quota_test(coreui_guest_image_quota($pdo, 1, $dayOne)['left'] === 1,
  'failed or cancelled image refunds exactly one previously reserved credit');
$retry = coreui_guest_image_reserve($pdo, 1, $dayOne);
quota_test(!empty($retry['ok']) && $retry['left'] === 0,
  'after a failure, another image may use the returned credit');
$onNewDay = coreui_guest_image_reserve($pdo, 1, $dayTwo);
quota_test(!empty($onNewDay['ok']) && $onNewDay['left'] === 2
  && coreui_guest_image_quota($pdo, 1, $dayTwo)['used'] === 1
  && coreui_guest_image_quota($pdo, 1, $dayOne)['used'] === 3,
  'the server calendar day resets credits without altering the preceding day');

stu_app_setting_set($pdo, 'guest_image_daily_limit', '0');
$zero = coreui_guest_image_reserve($pdo, 1, $dayTwo);
quota_test(empty($zero['ok']) && $zero['error'] === 'guest_image_disabled', 'admin zero disables images');
stu_app_setting_set($pdo, 'guest_image_daily_limit', '3');
stu_app_setting_set($pdo, 'guest_tool_image_enabled', '0');
$disabled = coreui_guest_image_reserve($pdo, 1, $dayTwo);
quota_test(empty($disabled['ok']) && $disabled['error'] === 'guest_image_disabled',
  'admin feature switch independently disables guest images');
stu_app_setting_set($pdo, 'guest_tool_image_enabled', '1');
$pdo->exec('DROP TABLE stu_abuse_counters');
$closed = coreui_guest_image_reserve($pdo, 1, $dayTwo);
quota_test(empty($closed['ok']) && $closed['error'] === 'guest_image_quota_unavailable',
  'missing counter storage always rejects expensive guest image jobs');
echo "CoreAI guest images: calendar day, per-account, three images, refund, tools off and fail-closed OK\n";
