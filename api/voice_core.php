<?php
declare(strict_types=1);

/*
 * CoreAI 0.5.74 (P20): helpers for audio turns to Core.
 *
 * Measured by the P20.0 media test and meant for voice input (P20.1) and
 * the live conversation (P20.4). No I/O here: the callers read /api/show
 * and talk to Ollama themselves.
 */

/** Names the attached recording in the prompt; a 5 s clip was once missed without it. */
function coreui_voice_attachment_hint(float $seconds): string {
  $s = max(1, (int)round($seconds));
  return '[Angehaengt: Sprachaufnahme, ' . $s . ($s === 1 ? ' Sekunde' : ' Sekunden') . ']';
}

/**
 * True when Core claims there is no recording although one was attached
 * ("Bitte stellen Sie die Aufnahme zur Verfuegung ..."). A heuristic for one
 * automatic retry; "(keine Sprache)" is a valid answer for silence.
 */
function coreui_voice_missed_audio(string $reply): bool {
  $t = mb_strtolower(trim($reply));
  if ($t === '' || mb_strlen($t) > 600) return false;
  $media = '(?:audio\w*|aufnahme\w*|tondatei\w*|sprachnachricht\w*|sprachaufnahme\w*|datei)';
  $patterns = [
    '~(?:stellen|schicken|senden|laden|geben)\s+sie\s+(?:mir\s+)?(?:bitte\s+)?(?:die|eine|den)\s+' . $media . '~u',
    '~(?:schick|sende|lade|gib)\s+mir\s+(?:bitte\s+)?(?:die|eine|den)\s+' . $media . '~u',
    '~keine?n?\s+' . $media . '\s+(?:wurde\s+|ist\s+)?(?:angeh(?:ä|ae)ngt|gefunden|erhalten|vorhanden|beigef(?:ü|ue)gt|mitgeschickt|(?:ü|ue)bermittelt)~u',
    '~(?:wenn|sobald)\s+ich\s+(?:den|die|das)\s+(?:audioinhalt|' . $media . ')\s+(?:habe|erhalte|bekomme)~u',
    '~ich\s+(?:kann|habe)\s+(?:leider\s+)?keine?n?\s+(?:' . $media . '|zugriff)~u',
    '~ich\s+kann\s+(?:leider\s+)?(?:die\s+)?' . $media . '\s+(?:leider\s+)?nicht\s+(?:h(?:ö|oe)ren|abspielen|anh(?:ö|oe)ren|verarbeiten|(?:ö|oe)ffnen)~u',
    '~please\s+(?:provide|upload|share|attach|send)\s+(?:the\s+|an?\s+)?(?:audio|recording|file)~u',
    '~no\s+(?:audio|recording)(?:\s+file)?\s+(?:was\s+|has\s+been\s+)?(?:attached|provided|found|included)~u',
    '~i\s+(?:cannot|can\'t|am\s+unable\s+to)\s+(?:listen|hear|play|process|access)~u',
  ];
  foreach ($patterns as $p) if (preg_match($p, $t) === 1) return true;
  return false;
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
 * The same SYSTEM text without the Gemma 4 thinking switch. Mara's persona
 * stays word for word; only latency-bound turns (live talk) use it.
 */
function coreui_voice_system_without_think(string $system): string {
  return trim(str_replace('<|think|>', '', $system));
}
