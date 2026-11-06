<?php
declare(strict_types=1);
/*
 * CoreAI 0.6.23: how well does Core hear a real voice message? A measurement on the
 * server with the operator's own recording, instead of guessing.
 *
 * Reported on 06.11.2026: played back in the chat, the recording sounds normal, yet
 * Core wrote "Berlin", "Berchtesgaden", "der Tisch ist glatt" or "joder, alles glatt"
 * for "Bergisch Gladbach". This script takes the newest voice message of the owner
 * account (or the one given) and has it transcribed in variants, each exactly the way
 * CoreAI does it (Google's template, no thinking, WAVs of at most 60 s, one queued
 * Core call per part, one retry when Core claims there was no recording):
 *
 *   A  as before, without names and terms
 *   B  with the account's names and terms (KI-Profil) or --terms
 *   C  like B, the recording first brought to an even loudness (ffmpeg loudnorm)
 *
 * for the account's Core or for every Core given with --cores (e.g. all installed ones
 * that hear). Nothing is stored or changed; every call waits in Mara's serial queue.
 *
 *   php scripts/voice-hear-check.php
 *   php scripts/voice-hear-check.php --cores alle --repeat 2
 *   php scripts/voice-hear-check.php --uuid <32 hex> --terms "Bergisch Gladbach, EVK"
 *   php scripts/voice-hear-check.php --file uploads/... --cores ember-coreui:e4b,ember-coreui:12b
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
define('STU_CHAT_LIB', 1);
require $root . '/api/chat.php';
require_once $root . '/api/model_catalog.php';

$opt = getopt('', ['uuid:', 'file:', 'cores:', 'terms:', 'repeat:', 'user:', 'help']);
if (isset($opt['help'])) {
  echo "php scripts/voice-hear-check.php [--uuid UUID | --file PFAD] [--user ID] [--cores alle|a,b] [--terms \"A, B\"] [--repeat N]\n";
  exit(0);
}
function hear_say(string $line): void { fwrite(STDOUT, $line . "\n"); }
function hear_fail(string $why): void { fwrite(STDERR, "FEHLER: {$why}\n"); exit(1); }

$pdo = stu_pdo();

// Whose recording: --user, else the owner account (Inhaber, level 0).
$uid = isset($opt['user']) ? (int)$opt['user'] : (int)($pdo->query('SELECT id FROM stu_users WHERE permission_level = 0 AND is_guest = 0 ORDER BY id LIMIT 1')
  ->fetchColumn() ?: 0);
if ($uid <= 0) hear_fail('Kein Konto gefunden (--user ID angeben).');
coreui_ai_runtime_apply($pdo, $uid);

// Which recording: --file, --uuid, else the account's newest voice message.
$source = '';
$name = '';
if (isset($opt['file'])) {
  $source = (string)$opt['file'];
  if (!str_starts_with($source, '/')) $source = $root . '/' . $source;
  $name = basename($source);
} else {
  $sql = 'SELECT uuid, rel_path, orig_name, created_at FROM stu_console_media WHERE user_id = ? AND kind = ?';
  $args = [$uid, 'audio'];
  if (isset($opt['uuid'])) {
    $sql .= ' AND uuid = ?';
    $args[] = strtolower(trim((string)$opt['uuid']));
  } else {
    $sql .= ' AND orig_name LIKE ?';
    $args[] = COREUI_VOICE_MESSAGE_PREFIX . '%';
  }
  $st = $pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 1');
  $st->execute($args);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) hear_fail('Keine Sprachnachricht dieses Kontos gefunden.');
  $source = $root . '/' . ltrim((string)$row['rel_path'], '/');
  $name = (string)$row['orig_name'] . ' (' . (string)$row['created_at'] . ', ' . (string)$row['uuid'] . ')';
  $known = coreui_voice_transcript_load($uid, (string)$row['uuid']);
  if (is_array($known) && ($known['state'] ?? '') === 'done') $name .= "\n  Gespeicherte Mitschrift: \"" . (string)$known['text'] . '"';
}
if (!is_file($source)) hear_fail('Datei nicht gefunden: ' . $source);

// Names and terms: --terms, else the account's list.
$terms = isset($opt['terms']) ? coreui_voice_terms_normalise((string)$opt['terms']) : coreui_voice_terms_load($pdo, $uid);

// Which Cores: --cores alle (every installed Core that hears) or a list; else the account's Core.
$cores = [ember_model()];
if (isset($opt['cores'])) {
  $wanted = trim((string)$opt['cores']);
  if (in_array(strtolower($wanted), ['alle', 'all'], true)) {
    $cores = [];
    foreach (coreui_ollama_models() as $m) {
      $n = (string)$m['name'];
      if (str_ends_with($n, '-cloud') || str_contains($n, ':cloud')) continue;
      if (coreui_voice_core_hears(ember_ollama_show_json($n)) === true) $cores[] = $n;
    }
  } else {
    $cores = array_values(array_filter(array_map('trim', explode(',', $wanted)), static fn(string $c): bool => $c !== ''));
  }
}
if ($cores === []) hear_fail('Kein Core mit Audio-Eingang gefunden.');
$repeat = max(1, min(5, (int)($opt['repeat'] ?? 1)));
$runtime = coreui_ai_runtime_settings();
$language = coreui_voice_language_name((string)($runtime['main_language'] ?? 'de'));

// The WAVs: as recorded, and once more brought to an even loudness.
$work = coreui_voice_workdir();
if ($work === null) hear_fail('Kein Arbeitsordner.');
// Also after an error (exit skips finally): the WAVs of both variants, then the folder itself.
register_shutdown_function(static function () use ($work): void {
  foreach (['plain', 'loudparts'] as $sub) {
    foreach ((array)@glob($work . '/' . $sub . '/*') as $file) if (is_file((string)$file)) @unlink((string)$file);
    @rmdir($work . '/' . $sub);
  }
  coreui_voice_workdir_remove($work);
});
$variants = [];
{
  $plain = $work . '/plain';
  @mkdir($plain, 0700);
  $wavs = coreui_voice_prepare_native($source, $plain, COREUI_VOICE_INPUT_MAX_SECONDS);
  if (empty($wavs['ok'])) hear_fail('Aufnahme liess sich nicht umwandeln: ' . (string)($wavs['error'] ?? ''));
  $loud = $work . '/loud.wav';
  [$status] = coreui_voice_ffmpeg(['-loglevel', 'error', '-i', $source, '-vn', '-af', 'loudnorm=I=-18:TP=-1.5:LRA=11',
    '-ar', '48000', '-ac', '1', $loud]);
  $loudWavs = null;
  if ($status === 0 && is_file($loud)) {
    @mkdir($work . '/loudparts', 0700);
    $prepared = coreui_voice_prepare_native($loud, $work . '/loudparts', COREUI_VOICE_INPUT_MAX_SECONDS);
    if (!empty($prepared['ok'])) $loudWavs = $prepared;
  }
  $variants['A ohne Begriffe'] = [$wavs, []];
  if ($terms !== []) {
    $variants['B mit Begriffen'] = [$wavs, $terms];
    if ($loudWavs !== null) $variants['C lauter + Begriffe'] = [$loudWavs, $terms];
  } elseif ($loudWavs !== null) {
    $variants['C lauter, ohne Begriffe'] = [$loudWavs, []];
  }

  hear_say('CoreAI Hoerprobe (' . date('d.m.Y H:i') . ')');
  hear_say('Aufnahme: ' . $name);
  hear_say('Laenge: ' . coreui_voice_length((float)($wavs['source_seconds'] ?? $wavs['seconds'])) . ' (Minuten:Sekunden), '
    . count($wavs['paths']) . ' WAV-Teil(e), Sprache ' . $language);
  hear_say('Begriffe: ' . ($terms !== [] ? implode(', ', $terms) : '(keine im KI-Profil; mit --terms "..." testen)'));
  if ($loudWavs === null) hear_say('Hinweis: Lautstaerke-Variante nicht moeglich (ffmpeg loudnorm).');
  hear_say('');

  foreach ($cores as $core) {
    hear_say('== ' . coreui_core_label($core) . ' (' . $core . ')');
    if (coreui_voice_core_hears(ember_ollama_show_json($core)) === false) { hear_say('   hat keinen Audio-Eingang, uebersprungen'); continue; }
    foreach ($variants as $label => [$set, $useTerms]) {
      for ($r = 1; $r <= $repeat; $r++) {
        $start = microtime(true);
        $texts = [];
        $error = '';
        foreach ((array)$set['paths'] as $path) {
          $heard = coreui_voice_transcribe_part(ember_url(), $core, (string)@file_get_contents($path), $language, 600,
            ember_keep_alive(), ember_profile_num_ctx($core), $useTerms);
          if (empty($heard['ok'])) { $error = (string)($heard['error'] ?? 'core_failed'); continue; }
          if (($heard['kind'] ?? '') === 'heard' && trim((string)$heard['text']) !== '') $texts[] = trim((string)$heard['text']);
        }
        $line = $texts !== [] ? '"' . implode(' ', $texts) . '"' : '(nichts verstanden' . ($error !== '' ? ': ' . $error : '') . ')';
        hear_say(sprintf('   %-24s %s  [%.1f s]', $label . ($repeat > 1 ? ' #' . $r : ''), $line, microtime(true) - $start));
      }
    }
    hear_say('');
  }
  hear_say('Fertig. Bitte die Ausgabe schicken; danach waehlen wir, was CoreAI fest uebernimmt.');
}
