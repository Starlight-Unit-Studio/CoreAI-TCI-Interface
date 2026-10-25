<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';

function reply_test_fail(string $message): void {
  fwrite(STDERR, "[FEHLER] {$message}\n");
  exit(1);
}

$GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = [
  'num_predict' => 6500,
  'temperature' => 1.0,
  'memory_enabled' => true,
  'thinking_enabled' => false,
  'memory_limit' => 16,
  'model_override' => 'gemma4-selftest:latest',
];

if (ember_model() !== 'gemma4-selftest:latest') {
  reply_test_fail('Der gespeicherte lokale Modelltag wird nicht fuer Ollama verwendet.');
}

if (ember_thinking_enabled() !== false) {
  reply_test_fail('Der kontobezogene Thinking-Schalter erreicht den Modellpfad nicht.');
}

if (ember_num_predict() !== 6500 || ember_num_predict_for_model('ember-coreui:latest') !== 6500) {
  reply_test_fail('Das Benutzerbudget wird nicht unveraendert an Ollama weitergegeben.');
}

$exactCases = [
  ['Berechne bitte exakt: 123456789 * 987654321 und nenne mir NUR das Ergebnis.', '121932631112635269'],
  ['999999999999999999999999 + 1', '1000000000000000000000000'],
  ['Berechne -12345678901234567890 * -9.', '111111110111111111010'],
  ['Berechne 5 - 12.', '-7'],
  ['12 X 12', '144'],
  ['-5 + -8', '-13'],
  ['Berechne 12 * 12, und nenne nur das Ergebnis.', '144'],
  ['Calculate 12 * 12.', '144'],
  ['00012 x 00012.', '144'],
  ['Was ergibt 12*12?', '144'],
  ['Nenne das Ergebnis von 12*12.', '144'],
  ['Give only the exact result of 12*12.', '144'],
];
foreach ($exactCases as [$expression, $expected]) {
  $actual = ember_exact_integer_calculation($expression);
  if ($actual !== $expected) {
    reply_test_fail('Exakte Ganzzahlrechnung fehlgeschlagen: ' . $expression . ' -> ' . (string)$actual);
  }
}
foreach ([
  'Berechne 100 + 200 + 300.',
  'Berechne 10 / 4.',
  'Berechne 1.5 * 8.',
  'Berechne 12 * 12 und erklaere den Rechenweg.',
  'Was ist Episode 123 - 456?',
  'Welches Ergebnis hatte Episode 123-456?',
  'Was ist die Rechnung in Kapitel 12-13?',
  'Calculate 12*12 and explain your reasoning.',
  'Calculate 12*12 and show your work.',
  'Calculate 12*12 and provide an explanation.',
  'Berechne 12*12 und gib eine Erklaerung.',
  'Berechne 12*12 und gib eine Erklärung.',
  'Calculate how many chapters are in 12-13.',
  'Berechne, wie viele Episoden der Bereich 12-13 umfasst.',
  '0x10',
  '٢+٣',
  '１２+３',
] as $unsafeFastPath) {
  if (ember_exact_integer_calculation($unsafeFastPath) !== null) {
    reply_test_fail('Mehrdeutige Rechnung gelangt in den exakten Schnellpfad: ' . $unsafeFastPath);
  }
}

// Simuliert eine erhaltene 0.2.x-Konfiguration, ohne vor dem Laden der echten
// config.local.php Konstanten zu definieren. Dadurch bleibt der Test frei von
// "already defined"-Warnungen auf aktualisierten Installationen.
if (ember_max_reply_chars_for_values(7200, 6500) < 52000) {
  reply_test_fail('Eine erhaltene 7200er-Konfiguration kann Antworten weiterhin kuerzen.');
}

$head = 'Dies ist ein ausreichend langer Antwortanfang mit einem eindeutig wiederholten Uebergang.';
$tail = 'einem eindeutig wiederholten Uebergang. Danach folgt der neue Abschlussteil.';
$joined = ember_join_continuation($head, $tail);
if (substr_count($joined, 'einem eindeutig wiederholten Uebergang') !== 1) {
  reply_test_fail('Fortsetzungssegmente werden am Uebergang doppelt gespeichert.');
}

$largeOverlap = str_repeat('Ein modularer Planungsabschnitt mit Detailkriterien und Tests. ', 19);
$longHead = "# Projekt\n\n## Struktur\n" . $largeOverlap;
$longTail = $largeOverlap . "\n\n## Naechster Abschnitt\nNeue Anforderungen.";
$merged = ember_join_continuation($longHead, $longTail);
if (substr_count($merged,'## Struktur') !== 1
    || substr_count($merged,'## Naechster Abschnitt') !== 1
    || substr_count($merged,'Ein modularer Planungsabschnitt') !== 19) {
  reply_test_fail('Markdown-Ueberlappung ueber 400 Zeichen wird doppelt oder falsch verbunden.');
}
if (ember_join_continuation($merged, $longHead) !== $merged) {
  reply_test_fail('Ein Model-Neustart darf keine bereits erzeugte Planungsseite wiederholen.');
}
if (ember_join_continuation("Abschnitt abgeschlossen.", "## Weiterer Plan") !==
    "Abschnitt abgeschlossen.\n\n## Weiterer Plan") {
  reply_test_fail('Markdown-Fortsetzungen muessen Absatzgrenzen erhalten.');
}
$rollingPrompt = ember_continuation_prompt('URSPRUNG', str_repeat('Plankontext ', 4000));
if (!str_contains($rollingPrompt,'URSPRUNG') || strlen($rollingPrompt) > 3400) {
  reply_test_fail('Fortsetzung wiederholt zu viel Antworttext im Ollama-Prompt.');
}


$multiline = chat_clean_console_message("Erste Zeile\n  code_block()\n\nZweite Zeile");
if (!str_contains($multiline, "\n  code_block()\n\n")) {
  reply_test_fail('Der private Ember CoreUI-Kanal verliert Zeilenumbrueche oder Code-Einrueckungen.');
}
$transport = chat_console_transport_text($multiline);
if (!str_contains($transport, "\n  code_block()\n\n")) {
  reply_test_fail('Der History-Transport verliert Zeilenumbrueche oder Code-Einrueckungen.');
}

$streamSource = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
foreach (['console_stream_continue_truncated', 'continuation_segments', 'CURLOPT_TIMEOUT        => max(30, min(900'] as $needle) {
  if (!str_contains($streamSource, $needle)) {
    reply_test_fail('Stream-Fortsetzung oder 900-Sekunden-Laufzeit fehlt: ' . $needle);
  }
}
if (str_contains($streamSource, "|| !empty(\$acc['truncated']);")) {
  reply_test_fail('Ein Tokenlimit startet weiterhin die komplette Anfrage neu.');
}
// Die Turn-Abfrage darf weitere Spalten (z.B. trigger_revision) enthalten.
// Entscheidend ist, dass die Nachricht aus dem gespeicherten, kontogebundenen
// Turn stammt und nicht als GET-Text ueber die EventSource-URL ankommt.
$storedTurnLookup = preg_match(
  '~SELECT\s+id\s*,\s*message\s*,\s*file_uuid\s*,\s*image_url\b.*?\bFROM\s+stu_chat_messages\b\s+WHERE\s+id\s*=\s*\?\s+AND\s+channel\s*=\s*\'console\'\s+AND\s+user_id\s*=\s*\?\s+AND\s+character_id\s*=\s*\?\s+AND\s+session_id\s*=\s*\?~is',
  $streamSource
) === 1;
$turnParametersBound = str_contains(
  $streamSource,
  '$stTurn->execute([$afterId, (int)$uid, $character_id, $sessionId])'
);
if (str_contains($streamSource, "\$_GET['msg']") || !$storedTurnLookup || !$turnParametersBound) {
  reply_test_fail('Der SSE-Pfad liest die Nachricht nicht aus dem kontogebundenen gespeicherten Turn oder uebertraegt Text per URL.');
}
if (!str_contains($streamSource, "'think'    => ember_thinking_enabled()")
    || !str_contains($streamSource, "console_stream_progress(\$thinkingEnabled")) {
  reply_test_fail('Thinking wird im SSE-Pfad nicht serverseitig aktiviert oder deaktiviert.');
}
if (!str_contains($streamSource, 'ember_exact_integer_calculation($promptMessage)')
    || !str_contains($streamSource, "'truncated' => false")
    || !str_contains($streamSource, '$consoleAfkOwned = false;')
    || !str_contains($streamSource, 'if ($consoleAfkOwned && isset($pdo)')) {
  reply_test_fail('Der SSE-Pfad schliesst exakte Ganzzahlrechnungen nicht lokal ab.');
}

$chatSource = (string)file_get_contents(dirname(__DIR__) . '/api/chat.php');
if (!str_contains($chatSource, "!empty(\$lastCall['exact_calc_fastpath'])")) {
  reply_test_fail('Ein lokales Rechenergebnis kann weiterhin einen Reflect-Modellaufruf starten.');
}
$globalFastPath = strpos($chatSource, '$exactCalculation = $triggerImageUrl === null');
$globalOllamaLock = strpos($chatSource, '$globalLock = ember_global_lock_acquire();', $globalFastPath ?: 0);
if ($globalFastPath === false || $globalOllamaLock === false || $globalFastPath > $globalOllamaLock) {
  reply_test_fail('Der synchrone Rechenpfad wartet weiterhin vorab auf den Ollama-Lock.');
}

$clientSource = (string)file_get_contents(dirname(__DIR__) . '/js/console-app.js');
if (str_contains($clientSource, "'&msg='")) {
  reply_test_fail('Der Browser schreibt die Nachricht weiterhin in die EventSource-URL.');
}
$appHtml = (string)file_get_contents(dirname(__DIR__) . '/app.html');
if (!str_contains($appHtml, '<textarea')
    || !str_contains($appHtml, 'id="inputMessage"')
    || str_contains($appHtml, 'maxlength="12000"')) {
  reply_test_fail('Der private CoreUI-Composer erlaubt kein mehrzeiliges, langes Einfuegen.');
}
if (!str_contains($clientSource, 'function queueAutoTextAttachment(text, reportUnavailable)')
    || !str_contains($clientSource, 'function sendAfterCompletedUpload()')
    || !str_contains($clientSource, 'function restoreFailedAutoPaste(file)')
    || !str_contains($clientSource, "inputEl.addEventListener('paste'")
    || !str_contains($clientSource, 'var pendingSendAfterUpload = null')) {
  reply_test_fail('Lange eingefuegte Texte werden nicht verlustfrei als Anhang gesichert.');
}
if (!str_contains($appHtml, 'id="fileInput"') || !str_contains($appHtml, 'multiple')) {
  reply_test_fail('Die Dateiauswahl erlaubt keine Mehrfachauswahl.');
}

if (!str_contains($clientSource, 'MAX_MESSAGE_ATTACHMENTS = 10')
    || !str_contains($clientSource, 'attachment_uuids:')) {
  reply_test_fail('Die Nachrichtenanhaenge sind nicht auf zehn begrenzt und persistent verdrahtet.');
}
if (str_contains($clientSource, "EMBER_PREFIX + userText")
    || !str_contains($clientSource, 'var msgToSend = userText;')) {
  reply_test_fail('Der private Dateipfad sendet weiterhin ein kuenstliches @Ember-Praefix.');
}

foreach (['? 20000 : 12000', '? 1200 : 120', 'chat_clean_console_message', 'chat_console_transport_text'] as $needle) {
  if (!str_contains($chatSource, $needle)) {
    reply_test_fail('Private Ember CoreUI-Arbeitsgrenze fehlt: ' . $needle);
  }
}
if (!str_contains($chatSource, '($isConsoleChannel || ember_should_reply($message))')) {
  reply_test_fail('Der private Kanal adressiert Ember nicht serverseitig.');
}

$migration = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/015_long_chat_messages.sql');
if (!str_contains($migration, 'ALTER TABLE stu_chat_messages MODIFY COLUMN message LONGTEXT NOT NULL')) {
  reply_test_fail('Die Nachrichtenspalte erlaubt keine vollstaendigen langen Antworten.');
}
$insertAt = strpos($chatSource, 'function ember_insert(');
$limitAt = $insertAt === false ? false : strpos($chatSource, "if (\$targetChannel !== 'console') {", $insertAt);
$oldCapAt = $insertAt === false ? false : strpos($chatSource, 'if ($textLen > $max)', $insertAt);
if ($insertAt === false || $limitAt === false || $oldCapAt === false || $oldCapAt < $limitAt
    || $oldCapAt - $limitAt > 550) {
  reply_test_fail('Die alte 60k-Antwortkuerzung wird im privaten Chat noch angewandt.');
}

fwrite(STDOUT, "Reply-Pipeline-Selftest OK: exakte Ganzzahlrechnung, Budget, Thinking-Wahl, zehn Anhaenge, Fortsetzung und LONGTEXT fuer vollstaendige Antworten aktiv.\n");
