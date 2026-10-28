<?php
declare(strict_types=1);

/*
 * CoreAI 0.5.74 (P20): helpers for audio turns to Core.
 *
 * Measured by the P20.0 media test and meant for voice input (P20.1) and
 * the live conversation (P20.4). No I/O here: the callers read /api/show
 * and talk to Ollama themselves.
 */

/** Names the attached recording in the prompt with its length. */
function coreui_voice_attachment_hint(float $seconds): string {
  $s = max(1, (int)round($seconds));
  return '[Angehaengt: Sprachaufnahme, ' . $s . ($s === 1 ? ' Sekunde' : ' Sekunden') . ']';
}

/**
 * 0.5.75: transcription request after Google's Gemma audio template. It
 * offers no "no speech" way out, which Core took far too often on short
 * clips that did contain speech.
 */
function coreui_voice_transcribe_prompt(string $language = 'German'): string {
  return "Transcribe the following speech segment in {$language} into {$language} text.\n\n"
    . "Follow these specific instructions for formatting the answer:\n"
    . "* Only output the transcription, with no newlines.\n"
    . "* When transcribing numbers, write the digits, i.e. write 1.7 and not one point seven, and write 3 instead of three.";
}

/** Neutral SYSTEM text for a transcription call: no persona, no thinking switch. */
function coreui_voice_transcribe_system(): string {
  return 'Du schreibst Audioaufnahmen wortgetreu mit.';
}

/**
 * True when Core claims there is no recording although one was attached
 * ("Bitte stellen Sie die Aufnahme zur Verfuegung", "Es ist kein Audio zur
 * Analyse vorhanden", "... konnte nicht geladen werden"). A short reply that
 * names the recording together with a negation or a request counts; it
 * triggers one automatic retry, so a rare false hit only costs time.
 */
function coreui_voice_missed_audio(string $reply): bool {
  $t = mb_strtolower(trim($reply));
  if ($t === '' || mb_strlen($t) > 400) return false;
  if (preg_match('~(?:audio|aufnahme|sprachnachricht|tondatei|recording)~u', $t) !== 1) return false;
  return preg_match('~(?:kein|nicht|ben(?:ö|oe)tig|brauche|bitte|fehlt|fehlen|(?<![a-z])no(?![a-z])|'
    . '(?<![a-z])not(?![a-z])|please|cannot|can\'t|unable|(?<![a-z])need)~u', $t) === 1;
}

/** True when the reply only says that nothing was spoken ("(keine Sprache)"). */
function coreui_voice_no_speech(string $reply): bool {
  return preg_match('~^[\s\(\[\*"„“]*(?:keine\s+sprache|no\s+speech)[\s\)\]\*\."“]*$~iu', trim($reply)) === 1;
}

/** 'heard', 'missed' (claims no recording) or 'silence' (claims nothing was said). */
function coreui_voice_reply_kind(string $reply): string {
  if (coreui_voice_no_speech($reply)) return 'silence';
  return coreui_voice_missed_audio($reply) ? 'missed' : 'heard';
}

/** SYSTEM text of a Core from its /api/show answer (field or Modelfile), '' when none. */
function coreui_voice_modelfile_system(array $show): string {
  $system = (string)($show['system'] ?? '');
  if (trim($system) !== '') return $system;
  $modelfile = (string)($show['modelfile'] ?? '');
  if (preg_match('~^SYSTEM\s+"""(.*?)"""~ms', $modelfile, $m) === 1) return $m[1];
  if (preg_match('~^SYSTEM\s+(.+)$~m', $modelfile, $m) === 1) return trim($m[1], " \t\"");
  return '';
}

/**
 * The same SYSTEM text without the Gemma 4 thinking switch. Since 0.5.75
 * the Modelfile carries none; this only matters for a server whose Core was
 * created from an older Modelfile.
 */
function coreui_voice_system_without_think(string $system): string {
  return trim(str_replace('<|think|>', '', $system));
}
