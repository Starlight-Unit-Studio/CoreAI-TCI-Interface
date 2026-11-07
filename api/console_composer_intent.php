<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.43: a tool chosen in the + menu of the input field.
 *
 * The page puts a short mark in front of the message: [[bild]], [[bild:gross]], [[websuche]]
 * or [[recherche]]. It is stored with the message, so the chip stays in the person's bubble,
 * after a reload, an edit or a new answer. For Mara the mark becomes a clear instruction
 * (paint, search, research) and is never part of the text she reads; if she still answers
 * without the tool's marker, the step is started with the person's own words. A tool the
 * account may not use is not forced: Mara is told it is not available and says so.
 */

const COREUI_COMPOSER_INTENT_RE = '~^\s*\[\[(bild(?::gross)?(?::[124])?|websuche|recherche)\]\]\s*~u';

/** The tool chosen for a message (pure): ['tool' => image|web|browse, 'size' => normal|large] or null. */
function coreui_composer_intent_peek(string $message): ?array {
  if (preg_match(COREUI_COMPOSER_INTENT_RE, $message, $m) !== 1) return null;
  if (preg_match('~^bild(?::gross)?(?::([124]))?$~', $m[1], $count) === 1) {
    $intent = ['tool' => 'image', 'size' => str_contains($m[1], ':gross') ? 'large' : 'normal'];
    if (isset($count[1])) $intent['count'] = (int)$count[1];
    return $intent;
  }
  return match ($m[1]) {
    'bild' => ['tool' => 'image', 'size' => 'normal'],
    'bild:gross' => ['tool' => 'image', 'size' => 'large'],
    'websuche' => ['tool' => 'web', 'size' => 'normal'],
    default => ['tool' => 'browse', 'size' => 'normal'],
  };
}

/** The message without its mark (pure). */
function coreui_composer_intent_strip(string $message): string {
  return (string)preg_replace(COREUI_COMPOSER_INTENT_RE, '', $message, 1);
}

/** Reads the mark and takes it out of the message. */
function coreui_composer_intent_take(string &$message): ?array {
  $intent = coreui_composer_intent_peek($message);
  if ($intent !== null) $message = coreui_composer_intent_strip($message);
  return $intent;
}

/** The message for archives and summaries (pure): the mark as a readable note, "(Bild erstellen) ein Fuchs". */
function coreui_composer_intent_readable(string $message): string {
  $intent = coreui_composer_intent_peek($message);
  if ($intent === null) return $message;
  $rest = trim(coreui_composer_intent_strip($message));
  return '(' . coreui_composer_intent_label($intent) . ')' . ($rest !== '' ? ' ' . $rest : '');
}

/** The label of the chip (pure). */
function coreui_composer_intent_label(array $intent): string {
  return match ((string)($intent['tool'] ?? '')) {
    'image' => (($intent['size'] ?? '') === 'large' ? 'Bild erstellen (groß)' : 'Bild erstellen')
      . (isset($intent['count']) ? ' · ' . (int)$intent['count'] . ((int)$intent['count'] === 1 ? ' Bild' : ' Bilder') : ''),
    'web' => 'Websuche',
    'browse' => 'Recherche',
    default => '',
  };
}

/** The instruction in Mara's prompt (pure); $allowed says whether the account may use the tool. */
function coreui_composer_intent_hint(array $intent, bool $allowed): string {
  $tool = (string)($intent['tool'] ?? '');
  if (!$allowed) {
    $what = $tool === 'image' ? 'Bilder zu malen' : ($tool === 'web' ? 'im Netz zu suchen' : 'Webseiten zu recherchieren');
    return "AUSWAHL IM MENUE: Die Person moechte, dass du ein Werkzeug nutzt, das fuer dieses Konto gerade nicht freigegeben ist ("
      . $what . "). Sag das kurz und freundlich und hilf, so gut es ohne geht.";
  }
  return match ($tool) {
    'image' => "AUSWAHL IM MENUE: Die Person hat \"Bild erstellen\" gewaehlt. Male ein Bild zu ihrer Nachricht. Fuer dich selbst (auch im Rollenspiel) nutze den Marker mit ICH und dein Referenzportraet; bei fremden Motiven nie ICH: antworte NUR mit "
      . (($intent['size'] ?? '') === 'large' ? '[BILD GROSS: ...] oder [BILD GROSS ICH: ...]' : '[BILD: ...] oder [BILD ICH: ...]')
      . " und sonst nichts.",
    'web' => "AUSWAHL IM MENUE: Die Person hat \"Websuche\" gewaehlt. Suche zuerst im Netz: antworte NUR mit [WEB: genaue Suchbegriffe] und sonst nichts.",
    default => "AUSWAHL IM MENUE: Die Person hat \"Recherche\" gewaehlt. Lies selbst Webseiten: antworte NUR mit "
      . "[BROWSE: was du herausfinden sollst] und sonst nichts.",
  };
}

/**
 * Mara's first reply with the chosen tool's marker (pure): unchanged when it has one, else the
 * marker with the person's own words. Nothing is forced when the account may not use the tool.
 */
function coreui_composer_intent_ensure(array $intent, string $reply, string $userText, bool $allowed): string {
  if (!$allowed) return $reply;
  $tool = (string)($intent['tool'] ?? '');
  $has = match ($tool) {
    'image' => function_exists('coreui_image_extract_marker') && coreui_image_extract_marker($reply) !== null,
    'web' => preg_match('~\[WEB:\s*[^\]]{2,}\]~iu', $reply) === 1,
    'browse' => preg_match('~\[BROWSE:\s*[^\]]{2,}\]~iu', $reply) === 1,
    default => true,
  };
  if ($has) return $reply;
  $words = trim((string)preg_replace('~\s+~u', ' ', (string)preg_replace('~[\[\]]+~u', ' ', $userText)));
  if (mb_strlen($words) < 3) return $reply;
  $words = mb_substr($words, 0, $tool === 'image' ? 1000 : 300);
  $self = $tool === 'image' && function_exists('coreui_image_is_self_request') && coreui_image_is_self_request($words);
  return match ($tool) {
    'image' => '[BILD' . (($intent['size'] ?? '') === 'large' ? ' GROSS' : '') . ($self ? ' ICH' : '') . ': ' . $words . ']',
    'web' => '[WEB: ' . $words . ']',
    default => '[BROWSE: ' . $words . ']',
  };
}

/** The answer of api/composer_tools.php (pure). */
function coreui_composer_tools_answer(bool $guest, bool $image, bool $web, bool $browse): array {
  $off = $guest ? 'Für Gäste nicht verfügbar' : 'Für dein Konto gerade nicht freigegeben';
  return ['ok' => true, 'guest' => $guest, 'tools' => [
    'image' => ['on' => $image && !$guest, 'why' => $image && !$guest ? '' : $off],
    'web' => ['on' => $web, 'why' => $web ? '' : $off],
    'browse' => ['on' => $browse, 'why' => $browse ? '' : $off],
  ], 'planned' => ['deep_research', 'connectors']];
}
