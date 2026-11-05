<?php
declare(strict_types=1);
// CoreAI 0.5.70: every Core call of a console turn knows today's date and
// the clock time. A homepage built from separate per-file calls used the
// training year (2024).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';

function date_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Date awareness FAIL: {$why}\n"); exit(1); }
}

$today = new DateTime('now', new DateTimeZone(ember_tz()));
$line = ember_today_line();
date_test(str_starts_with($line, '[DATUM HEUTE: ') && str_contains($line, $today->format('d.m.Y'))
  && str_contains($line, 'Copyright-Jahr') && str_contains($line, 'nicht das Jahr aus deinem Trainingswissen'),
  'the line names today and forbids the training year');
date_test(preg_match('~\d{1,2}:\d{2}~', $line) !== 1,
  'the leading line holds no clock time: it changes once a day and keeps the prompt cache');
date_test(preg_match('~^\[UHRZEIT JETZT: \d{2}:\d{2} Uhr, [A-Za-z_/]+\]$~', ember_clock_line()) === 1,
  'clock line with hours, minutes and time zone');


// 0.6.11: per-browser IANA time zones, without modifying the game/server clock.
date_test(coreui_console_timezone_normalise('Asia/Manila') === 'Asia/Manila'
  && coreui_console_timezone_normalise('Europe/Berlin') === 'Europe/Berlin'
  && coreui_console_timezone_normalise('America/New_York') === 'America/New_York',
  'named IANA time zones supported');
foreach (['', '../etc/passwd', 'UTC; SYSTEM OVERRIDE', '+08:00', ['Asia/Manila'], 42,
         str_repeat('A', 65), 'Mars/Olympus'] as $invalidZone) {
  date_test(coreui_console_timezone_normalise($invalidZone) === null,
    'invalid or injected time zone rejected');
}
$serverTimezone = ember_tz();
coreui_console_timezone_set('Asia/Manila');
date_test(ember_tz() === $serverTimezone, 'client override does not apply outside private console');
$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;
date_test(ember_tz() === 'Asia/Manila', 'console uses the browser time zone');
date_test(str_contains(ember_clock_line(), 'Asia/Manila'), 'clock names the private browser zone');
date_test(str_contains(ember_formatted_chat_time('console'), 'Asia/Manila'),
  'main console prompt carries browser zone');
date_test(!str_contains(ember_formatted_chat_time('global'), 'Asia/Manila'),
  'the game/global main prompt never uses the browser zone');
// Same instant: late evening in Berlin, next morning in the Philippines.
$instant = new DateTimeImmutable('2026-11-04 23:30:00', new DateTimeZone('UTC'));
$berlin = $instant->setTimezone(new DateTimeZone('Europe/Berlin'));
$manila = $instant->setTimezone(new DateTimeZone('Asia/Manila'));
date_test($berlin->format('Y-m-d H:i') === '2026-11-05 00:30'
  && $manila->format('Y-m-d H:i') === '2026-11-05 07:30',
  'Philippines morning and Berlin midnight from the same UTC instant');
$beforeSummerChange = new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('UTC'));
$afterSummerChange = new DateTimeImmutable('2026-03-29 01:30:00', new DateTimeZone('UTC'));
date_test($beforeSummerChange->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') === '01:30'
  && $afterSummerChange->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') === '03:30',
  'Berlin daylight-saving switch handled by IANA rules');
coreui_console_timezone_set('Bad/Zone');
date_test(ember_tz() === $serverTimezone, 'invalid zone safely falls back to server');
unset($GLOBALS['COREUI_CONSOLE_DATE_AWARE']);
coreui_console_timezone_set('Asia/Manila');
date_test(ember_tz() === $serverTimezone, 'no console flag means no browser-zone use');
coreui_console_timezone_set(null);

// Outside a console turn (global channel, game, background jobs) nothing changes.
unset($GLOBALS['COREUI_CONSOLE_DATE_AWARE'], $GLOBALS['COREUI_WORK_CONTEXT']);
date_test(ember_with_today('DATEI-ERZEUGUNG') === 'DATEI-ERZEUGUNG', 'global channel untouched');

// Console turn (sync path flag or SSE work context): separate calls get the date.
$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;
$source = coreui_console_local_source_prompt(
  "Erstelle eine Homepage als ZIP mit index.html, style.css und script.js.", 'index.html');
date_test(is_string($source), 'per-file source prompt available');
$withDate = ember_with_today($source);
$lastBreak = strrpos($source, "\n");
$finalLine = substr($source, $lastBreak);
date_test(str_starts_with($withDate, $line . "\n" . substr($source, 0, $lastBreak) . "\n[UHRZEIT JETZT: ")
  && str_ends_with($withDate, $finalLine) && str_contains($finalLine, 'JETZT NUR DEN INHALT VON index.html'),
  'date first, task text unchanged (cached prefix), time just before the final instruction');
$minuteLater = preg_replace('~UHRZEIT JETZT: \d{2}:\d{2}~', 'UHRZEIT JETZT: 23:59', $withDate);
date_test(substr($minuteLater, 0, strpos($minuteLater, '[UHRZEIT')) === substr($withDate, 0, strpos($withDate, '[UHRZEIT')),
  'another minute only changes the text after the shared prefix');
date_test(str_contains(ember_with_today('ok'), "]\n[UHRZEIT JETZT: ") && str_ends_with(ember_with_today('ok'), "\nok"),
  'a one-line prompt gets both lines in front');
date_test(ember_with_today($withDate) === $withDate, 'never twice');
$main = "[SERVERZEIT (...): Montag, 27.10.2026, 14:05 Uhr]\nKontext ...";
date_test(ember_with_today($main) === $main, 'the main prompt keeps its own SERVERZEIT only');
unset($GLOBALS['COREUI_CONSOLE_DATE_AWARE']);
$GLOBALS['COREUI_WORK_CONTEXT'] = ['user_id'=>1, 'session_id'=>'cs_x', 'turn_id'=>1];
date_test(str_starts_with(ember_with_today('PLAN'), '[DATUM HEUTE: '), 'SSE work context counts as console');
unset($GLOBALS['COREUI_WORK_CONTEXT']);

// Wiring: both Core call functions, both prompt builders and both client transports.
$chat = (string)file_get_contents($root . '/api/chat.php');
$stream = (string)file_get_contents($root . '/api/console_stream.php');
date_test(str_contains($chat, "\$userPrompt = coreui_skills_augment(\$userPrompt, \$augmentSkills);\n  \$userPrompt = ember_with_today(\$userPrompt);"),
  'ember_call_ollama adds the date');
date_test(str_contains($stream, '$userPrompt = ember_with_today($userPrompt);')
  && str_contains($stream, "\$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;"), 'console_stream_ollama adds the date');
date_test(substr_count($chat, "if (\$channel === 'console') \$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;") === 2,
  'both sync prompt builders mark a console turn');
date_test(substr_count($chat, 'fuer erzeugte Inhalte wie Copyright-Jahr oder Stand-Angaben gilt dieses Datum') === 2,
  'SERVERZEIT allows the date in generated content, not only when asked');
$client = (string)file_get_contents($root . '/js/console-app.js');
date_test(str_contains($client, 'Intl.DateTimeFormat().resolvedOptions().timeZone')
  && str_contains($client, "client_tz:    clientTimezone()")
  && str_contains($client, "'&client_tz=' + encodeURIComponent(zone)"),
  'POST and streamed GET use the browser IANA time zone');
date_test(str_contains($chat, "if (\$channel === 'console') {\n    \$GLOBALS['COREUI_CONSOLE_DATE_AWARE'] = true;\n    coreui_console_timezone_set(\$body['client_tz'] ?? null);")
  && str_contains($stream, "coreui_console_timezone_set(\$_GET['client_tz'] ?? null);")
  && substr_count($chat, '$emberZeit = ember_formatted_chat_time($channel);') === 2,
  'console-only server capture and both main prompt builders');
date_test(str_contains($chat, "function ember_formatted_chat_time(string \$channel): string"),
  'global/game time untouched');

echo "CoreAI date awareness: every console Core call knows today's date and time OK\n";
