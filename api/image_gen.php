<?php
declare(strict_types=1);

require_once __DIR__ . '/image_reference_consistency.php';
require_once __DIR__ . '/image_png_metadata.php';

/*
 * CoreAI 0.6.39 (P20.5 C): Mara makes images.
 *
 * Mara asks for an image in her work loop with [BILD: description in English] (512 x 512,
 * about a minute) or, only when the person explicitly wants a big one, [BILD GROSS: ...]
 * (1024 x 1024, three to four minutes). The image service (tools/image/image_service.py,
 * FLUX.2-klein 4B) makes it while this request holds Mara's serial model queue, so no Core
 * runs on the CPU at the same time. The PNG lands in var/console_media/images as a media
 * entry of the account and is attached to Mara's answer: it shows in the chat, survives a
 * reload, is served only to its owner (console_media.php) and is deleted with its chat or
 * account. Next to it a small JSON keeps the description, size, seed and time, so Mara knows
 * later what she made. Guests never make images; the Inhaber can switch them off for
 * everyone (Betrieb) or per account (Benutzer). The exported image carries
 * only the subtle Quantum CoreAI logo at bottom-right; copyright, generator,
 * model and creation details are embedded in PNG/XMP metadata, not drawn as text.
 */

const COREUI_IMAGE_DEFAULT_PORT = 18791;
const COREUI_IMAGE_MAX_BYTES = 25 * 1024 * 1024;
const COREUI_IMAGE_MAX_REFERENCES = 10; // Same limit as attached files per turn.
const COREUI_IMAGE_LOGO_WIDTH = 0.115; // 11.5%, down slightly from 12%
const COREUI_IMAGE_MARK_MARGIN = 0.025; // relative to image width
const COREUI_IMAGE_MARK_OPACITY = 0.5;  // 50% opacity, unchanged

function coreui_image_service_url(): string {
  $port = (int)(getenv('COREUI_IMAGE_PORT') ?: COREUI_IMAGE_DEFAULT_PORT);
  return 'http://127.0.0.1:' . ($port >= 1024 && $port <= 65535 ? $port : COREUI_IMAGE_DEFAULT_PORT);
}

function coreui_image_registry(): array {
  static $registry = null;
  if (is_array($registry)) return $registry;
  $raw = @file_get_contents(dirname(__DIR__) . '/config/image/mara-image.json');
  $data = is_string($raw) ? json_decode($raw, true) : null;
  return $registry = is_array($data) ? $data : [];
}

/** The folder of Mara's images (flat, one PNG and one JSON per image). */
function coreui_image_dir(): string {
  if (defined('COREUI_IMAGE_DIR')) return (string)COREUI_IMAGE_DIR; // selftest only
  return dirname(__DIR__) . '/var/console_media/images';
}

/**
 * The image Mara asks for in a reply (pure): the text inside the marker, with "GROSS: "
 * in front for a big one, or null. The description must be at least three characters
 * and may not contain brackets; at most 1200 characters.
 */
function coreui_image_extract_marker(string $reply): ?string {
  if (preg_match('~\[\s*BILD(\s+GRO(?:SS|ß))?(\s+ICH)?\s*:\s*([^\[\]]{3,1200}?)\s*\]~iu', $reply, $m) !== 1) return null;
  $prompt = trim((string)preg_replace('~\s+~u', ' ', $m[3]));
  if (mb_strlen($prompt) < 3) return null;
  return (trim((string)$m[1]) !== '' ? 'GROSS: ' : '')
    . (trim((string)$m[2]) !== '' ? 'ICH: ' : '') . $prompt;
}

/** Deterministic safety net for the chat's image button; never infer identity from a random character. */
function coreui_image_is_self_request(string $message, string $assistantName = 'Mara'): bool {
  $text = trim((string)preg_replace('~\\s+~u', ' ', $message));
  if ($text === '') return false;
  $assistantName = trim($assistantName);
  // Everyday questions such as "Wie siehst du eigentlich aus?" are self-portraits
  // too. They must not rely on the Core choosing the optional ICH marker.
  if (preg_match('~\\b(?:wie\\s+(?:siehst|schaust)\\s+du(?:\\s+(?:eigentlich|denn|gerade|heute|jetzt|wirklich|normalerweise|wohl|so)){0,4}\\s+aus|wie\\s+du\\s+aus(?:siehst|schaust)|what\\s+do\\s+you\\s+look\\s+like|show\\s+me\\s+what\\s+you\\s+look\\s+like)\\b~iu', $text) === 1) return true;
  // The user may address the account's nickname or Mara Thorne by name.
  $names = 'mara(?:\\s+thorne)?';
  if ($assistantName !== '') $names .= '|' . preg_quote($assistantName, '~');
  // 0.6.66: natural self-portrait wording must not depend on the Core
  // remembering the optional ICH marker. This checks the USER'S request only,
  // not a model-authored scene, unrelated uploads or conversation memory.
  // A leading "Mara, ..." alone is deliberately NOT a self-portrait: the
  // operator may be addressing Mara while requesting a different subject.
  $selfWords = '~\\b(?:selbstportr(?:aet|ät|ait)|selbstbildnis|self[-\\s]?portrait'
    . '|(?:zeige?|mal(?:e)?|zeichne|generier(?:e)?|erstell(?:e)?|render|mach|create|draw|paint|show|generate)'
    . '(?:\\s+[^\\s,.;!?]+){0,7}\\s+(?:dich|yourself)'
    . '|(?:von|mit)\\s+dir|(?:of|with)\\s+yourself'
    . '|(?:dein(?:e|en|em|er|es)?|your)\\s+(?:eigen(?:e|es|en)?\\s+|own\\s+)?'
    . '(?:aussehen|gesicht|erscheinung|portrait|portraet|porträt|foto|bild|face|appearance|look|photo|picture)'
    . '|(?:bild|foto|aufnahme|portr(?:aet|ät|ait)|selfie|szene|image|picture|photo|portrait|scene)'
    . '[^.!?]{0,110}\\b(?:dich|yourself|du\\s+(?:selbst|stehst|sitzt|läufst|laeufst|bist|aussiehst))'
    . '|(?:wie|how)\\s+(?:du\\s+(?:(?:dich\\s+)?(?:darstellst|zeigen|sehen|aussiehst|aussehen|wirkst|stehst|sitzt))'
    . '|you\\s+(?:look|appear|would\\s+look))'
    . ')\\b~iu';
  if (preg_match($selfWords, $text) === 1) return true;
  if (preg_match('~\\b(?:du\\s+selbst|dich\\s+selbst|yourself)\\b~iu', $text) === 1) return true;
  if (preg_match('~\\b(?:wie|als)\\s+du\\s+[^.!?]{0,90}\\b(?:aussiehst|aussehen|wirkst|sitzt|stehst|laeufst|läufst|siehst)\\b~iu', $text) === 1) return true;
  if (preg_match('~\\bdich\\s+[^.!?]{0,65}\\b(?:sehen|abbilden|zeichnen|malen|darstellen|fotografieren)\\b~iu', $text) === 1) return true;
  // Name-based scenes: "Mara im Cockpit", "Mara am Strand", and scenes
  // with the current account-specific TCI nickname as SUBJECT use the portrait.
  // "Mara, male eine fremde Frau" must stay a generic image.
  $subjectNames = '(?:' . $names . ')';
  if (preg_match('~\\b' . $subjectNames
    . '\\s+(?:selbst|als|im|in|am|auf|bei|vor|hinter|neben|unter|mit|wearing|as|at|on|beside|inside)\\b~iu', $text) === 1) return true;
  if (preg_match('~\\b(?:bild|foto|aufnahme|portr(?:aet|ät|ait)|selfie|szene|image|picture|photo|portrait|scene)'
    . '\\b[^.!?]{0,110}\\b' . $subjectNames . '\\b~iu', $text) === 1) return true;
  if (preg_match('~\\b(?:zeichn\\w*|mal\\w*|generier\\w*|erstell\\w*|render\\w*|fotografier\\w*'
    . '|draw|paint|generate|create|show|depict)\\b[^.!?]{0,95}\\b' . $subjectNames . '\\b~iu', $text) === 1) return true;


  if (preg_match('~\\b(?:wie\\s+sieht\\s+(?:' . $names . ')\\s+aus|(?:bild|foto|portr(?:aet|ät|ait))\\s+von\\s+(?:' . $names . '))\\b~iu', $text) === 1) return true;
  if ($assistantName !== '' && preg_match('~\\b' . preg_quote($assistantName, '~') . '\\s+(?:als|in\\s+einem|auf\\s+der)\\b~iu', $text) === 1) return true;
  return preg_match('~\\b(?:mara(?:\\s+thorne)?|du)\\s+(?:als|in\\s+einem|auf\\s+der)\\b|\\b(?:von\\s+dir|mit\\s+dir|dein(?:\\s+eigenes)?\\s+(?:portrait|portraet|porträt|bild|foto)|zeig(?:e)?\\s+dich|zeichne\\s+dich|mal\\s+dich|male\\s+dich|dich\\s+als|yourself\\s+as|picture\\s+of\\s+you|portrait\\s+of\\s+you)\\b~iu', $text) === 1;
}

/**
 * 0.7.0: the PERSON asks for an image of themselves ("ein Bild von mir", "zeichne mich als
 * Astronaut", "ein Foto von uns beiden", "a picture of me"), so their reference photos from
 * Settings > Personalisierung apply (pure). "Für mich" or "zeig mir" is no image of the person.
 */
function coreui_image_is_person_self_request(string $message): bool {
  $text = trim((string)preg_replace('~\\s+~u', ' ', $message));
  if ($text === '') return false;
  $images = '(?:bild\\w*|foto\\w*|portr(?:ae|ä|a)t\\w*|selfie\\w*|zeichnung\\w*|illustration\\w*|avatar\\w*|gem(?:ä|ae)lde\\w*'
    . '|skizze\\w*|comic\\w*|image\\w*|picture\\w*|photo\\w*|drawing\\w*|painting\\w*)';
  // "ein Bild von mir", "ein Bild im Comicstil von uns beiden" (no other "von" between: "ein Bild
  // von einem Hund fuer einen Freund von mir" is no image of the person).
  if (preg_match('~\\b' . $images . '\\b(?:(?!\\bvon\\b)[^.!?]){0,40}\\b(?:von|mit)\\s+(?:mir|uns)\\b~iu', $text) === 1) return true;
  if (preg_match('~\\b' . $images . '\\b(?:(?!\\bof\\b)[^.!?]){0,40}\\b(?:of|with)\\s+(?:me|myself|us)\\b~iu', $text) === 1) return true;
  // "zeichne mich", "mal mich als Ritter", "stell mich als Pilotin dar", "verwandle mich in ..."
  if (preg_match('~\\b(?:zeichn\\w*|male?|malst|malt|stell\\w*|zeig\\w*|generier\\w*|erstell\\w*|erzeug\\w*|render\\w*'
      . '|verwandl\\w*|verwandel\\w*|portr(?:ae|ä)tier\\w*|fotografier\\w*)\\s+(?:(?:bitte|mal|doch|noch\\s*mal)\\s+)?mich\\b'
      . '(?=[^.!?]{0,80}(?:\\b(?:als|in|im|am|auf|mit|beim|vor|dar)\\b|[.!?]|$))~iu', $text) === 1) return true;
  // "kannst du mich als Astronautin zeichnen?"
  if (preg_match('~\\bmich\\b[^.!?]{0,60}\\b(?:zeichnen|malen|darstellen|fotografieren|rendern|portr(?:ae|ä)tieren|generieren|erstellen|verwandeln)\\b~iu', $text) === 1) return true;
  // "Bild: ich als Pirat"
  if (preg_match('~\\b' . $images . '\\b[^.!?]{0,40}\\bich\\s+als\\b~iu', $text) === 1) return true;
  return preg_match('~\\b(?:draw|paint|render|show|turn|imagine|picture)\\s+me\\s+(?:as|in|like|into)\\b~iu', $text) === 1;
}

/** The request is about clothing or accessories, so the full-body reference photo helps (pure). */
function coreui_image_mentions_outfit(string $message): bool {
  return preg_match('~\\b(?:kleid\\w*|outfit\\w*|anz(?:u|ü|ue)g\\w*|jacke\\w*|mantel|hose\\w*|rock|shirt\\w*|pullover|pulli|schuh\\w*'
    . '|stiefel\\w*|hut|m(?:ü|ue)tze|kappe|brille\\w*|schmuck|kette\\w*|ohrring\\w*|tasche\\w*|accessoire\\w*|kost(?:ü|ue)m\\w*'
    . '|uniform\\w*|r(?:ü|ue)stung\\w*|tracht\\w*|trag(?:e|en|end)|tr(?:ä|ae)gt|angezogen|anprobier\\w*|ganzk(?:ö|oe)rper\\w*'
    . '|ganze[nrm]?\\s+k(?:ö|oe)rper|von\\s+kopf\\s+bis\\s+fu(?:ß|ss)|full[\\s-]?body|dress\\w*|suit|clothes|clothing|wearing'
    . '|jacket|shoes|hat|glasses|accessor\\w*|costume|armou?r)\\b~iu', $message) === 1;
}

/**
 * A short continuation may refer to a self-portrait in the current chat.
 * This never turns a generic image into a self-portrait. It only keeps an
 * explicit ICH marker already chosen from the current conversational context.
 */
function coreui_image_may_continue_self_portrait(string $message): bool {
  $text = trim((string)preg_replace('~\\s+~u', ' ', $message));
  if ($text === '') return false;
  return preg_match('~^(?:(?:und|aber|okay|ok)\\s+)?(?:'
    . '(?:diesmal|jetzt|nun|now|this\\s+time)\\s+(?:als|im|in|am|auf|mit|vor|bei|as|in|on|with)\\b'
    . '|noch(?:mal)?\\s+(?:eins|eines|ein\\s+(?:bild|foto)|so\\s+eins)\\b'
    . '|(?:nochmal|wieder|again|same\\s+(?:person|face)|gleiche\\s+person|dieselbe\\s+person)\\s*$'
    . '|(?:another|one\\s+more)\\s+(?:one|image|picture|photo)\\s*$'
    . ')~iu', $text) === 1;
}

/** Upgrade a generic image tool only when the user explicitly asked for THIS TCI. */
function coreui_image_tool_for_request(array $tool, string $userText, string $assistantName = 'Mara'): array {
  if (($tool['tool'] ?? '') !== 'image' || !coreui_image_is_self_request($userText, $assistantName)) return $tool;
  $input = (string)($tool['input'] ?? '');
  $parsed = coreui_image_parse_input($input);
  if ($parsed['self_reference'] || $parsed['prompt'] === '') return $tool;
  $tool['input'] = ($parsed['size'] === 'large' ? 'GROSS: ' : '')
    . 'ICH: ' . $parsed['prompt'];
  return $tool;
}

/** Size and description from the tool input (pure). */
function coreui_image_parse_input(string $input): array {
  $input = trim($input);
  $size = 'normal';
  if (preg_match('~^GROSS:\s*(.+)$~su', $input, $m) === 1) {
    $size = 'large';
    $input = trim($m[1]);
  }
  $self = false;
  if (preg_match('~^ICH:\s*(.+)$~su', $input, $m) === 1) {
    $self = true;
    $input = trim($m[1]);
  }
  return ['size' => $size, 'prompt' => $input, 'self_reference' => $self];
}

/** An opaque, account-scoped image upload reference; no client-supplied file paths. */
function coreui_image_media_reference_token(array $row, int $uid): ?string {
  if ($uid <= 0 || (int)($row['user_id'] ?? 0) !== $uid || ($row['kind'] ?? '') !== 'image') return null;
  $uuid = strtolower((string)($row['uuid'] ?? ''));
  $name = strtolower((string)($row['stored_name'] ?? ''));
  $mime = (string)($row['mime_type'] ?? '');
  $extensions = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp', 'image/gif'=>'gif'];
  if (!isset($extensions[$mime]) || preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) return null;
  $expected = $uuid . '.' . $extensions[$mime];
  if ($name !== $expected || (string)($row['rel_path'] ?? '') !== 'assets/chat_media/' . $expected) return null;
  $bytes = (int)($row['file_size'] ?? 0);
  if ($bytes < 1 || $bytes > 8 * 1024 * 1024) return null;
  return 'upload:' . $expected;
}

/** The user's wish to create a new image from uploaded pictures, not to just analyze them. */
function coreui_image_attachment_creation_requested(string $message, ?array $intent = null): bool {
  if (($intent['tool'] ?? '') === 'image') return true;
  $message = trim((string)preg_replace('~\\s+~u', ' ', $message));
  if ($message === '') return false;
  $verbs = '(?:generier\\w*|erstell\\w*|erzeug\\w*|zeichn\\w*|mal\\w*|render\\w*|gestalt\\w*|kombinier\\w*|montier\\w*|erschaff\\w*|create|generate|draw|render|combine|make|edit)';
  $targets = '(?:bild\\w*|foto\\w*|illustration\\w*|szene\\w*|image\\w*|picture\\w*|scene\\w*)';
  if (preg_match('~\\b' . $verbs . '\\b.{0,160}\\b' . $targets . '\\b|\\b' . $targets . '\\b.{0,160}\\b' . $verbs . '\\b~iu', $message) === 1) return true;
  return preg_match('~\\b(?:lass|lasse|zeig|zeige)\\s+(?:die|diese|beide|beiden|die\\s+beiden)\\b.{0,130}\\b(?:kaempfen|kämpfen|gegeneinander|duellieren|fight|battle)\\b~iu', $message) === 1
    || preg_match('~\\b(?:kannst|kannste|koenntest|könntest)\\s+du\\b.{0,150}\\b(?:kaempfen|kämpfen|gegeneinander|duellieren)\\s+lassen\\b~iu', $message) === 1;
}

/** The attachment-vision response was prose, although the user requested a new image. */
function coreui_image_attachment_fallback_prompt(string $message, string $visionSummary): string {
  $message = mb_substr(trim((string)preg_replace('~\\s+~u', ' ', $message)), 0, 380);
  $summary = mb_substr(trim((string)preg_replace('~\\s+~u', ' ', $visionSummary)), 0, 490);
  if (coreui_image_reference_mode($message, false, true) === 'style') {
    return 'Create ONE NEW image of the requested scene. Use the supplied reference images ONLY as visual style guides, '
      . 'not as subjects to copy. Follow the requested style and scene: ' . $message;
  }
  return 'Create ONE NEW coherent image using the supplied reference pictures. Preserve the depicted subjects, '
    . 'their recognizable costumes and distinguishing visual details. Compose the scene requested by the user: '
    . $message . '. The previously observed subjects were: ' . $summary;
}

/** "512 × 512" for a size of the registry (pure). */
function coreui_image_size_label(string $size): string {
  $entry = (coreui_image_registry()['sizes'] ?? [])[$size] ?? null;
  return is_array($entry) ? ((int)$entry['width'] . ' × ' . (int)$entry['height']) : $size;
}

/** The registry names a revision and a SHA-256 for every file (pure). */
function coreui_image_registry_pinned(array $registry): bool {
  $files = $registry['files'] ?? [];
  if (!is_array($files) || count($files) !== 3) return false;
  foreach ($files as $file) {
    if (!is_array($file) || preg_match('~^[0-9a-f]{40}$~D', (string)($file['revision'] ?? '')) !== 1
        || preg_match('~^[0-9a-f]{64}$~D', (string)($file['sha256'] ?? '')) !== 1) return false;
  }
  return true;
}

/**
 * Whether Mara may make images for this account: not a guest, the switch under Betrieb
 * and the account's own rule allow it, the image service is installed and the registry
 * is pinned. The service itself is asked only when an image is made.
 */
function coreui_image_tool_allowed(PDO $pdo, int $uid): bool {
  if ($uid <= 0 || !coreui_image_registry_pinned(coreui_image_registry())) return false;
  if (function_exists('coreui_ai_account_is_guest') && coreui_ai_account_is_guest($pdo, $uid)) return false;
  $configured = defined('STU_IMAGE_ENABLED') ? (bool)STU_IMAGE_ENABLED : true;
  return function_exists('coreui_runtime_tool_enabled') ? coreui_runtime_tool_enabled('image', $configured) : $configured;
}

/** One HTTP call to the image service: [status, json or null, raw body]. */
function coreui_image_http(string $method, string $path, ?array $body = null, int $timeout = 10): array {
  $ch = curl_init(coreui_image_service_url() . $path);
  if (!$ch) return [0, null, ''];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => max(2, $timeout),
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_NOSIGNAL => true,
  ];
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
  }
  curl_setopt_array($ch, $opts);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $raw = is_string($raw) ? $raw : '';
  $json = $raw !== '' && $raw[0] === '{' ? json_decode($raw, true) : null;
  return [$code, is_array($json) ? $json : null, $raw];
}

/**
 * The visible mark is only our existing transparent RGBA logo at bottom-right.
 * All author, creation and model details live in PNG metadata instead.
 */
function coreui_image_watermark(string $png): ?string {
  $logoPath = dirname(__DIR__) . '/images/watermark_logo.png';
  if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatefrompng')
      || !function_exists('imagecopyresampled') || !is_file($logoPath)) return null;
  $base = @imagecreatefromstring($png);
  $logo = @imagecreatefrompng($logoPath);
  if ($base === false || $logo === false) {
    if ($base instanceof GdImage) imagedestroy($base);
    if ($logo instanceof GdImage) imagedestroy($logo);
    return null;
  }
  if (!imageistruecolor($base)) imagepalettetotruecolor($base);
  $width = imagesx($base);
  $height = imagesy($base);
  $margin = max(4, (int)round($width * COREUI_IMAGE_MARK_MARGIN));
  $logoWidth = max(1, (int)round($width * COREUI_IMAGE_LOGO_WIDTH));
  $logoWidth = min($logoWidth, $width - 2 * $margin);
  $logoHeight = max(1, (int)round($logoWidth * imagesy($logo) / imagesx($logo)));
  if ($logoWidth < 1 || $logoHeight + 2 * $margin > $height) {
    imagedestroy($logo);
    imagedestroy($base);
    return null;
  }

  $scaled = coreui_image_canvas($logoWidth, $logoHeight);
  imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
  $mark = coreui_image_canvas($logoWidth, $logoHeight);
  for ($y = 0; $y < $logoHeight; $y++) {
    for ($x = 0; $x < $logoWidth; $x++) {
      $rgba = imagecolorat($scaled, $x, $y);
      // Maintain the transparent logo's native alpha, fading only its opacity.
      $nativeOpacity = 1 - ((($rgba >> 24) & 0x7f) / 127);
      $opacity = $nativeOpacity * COREUI_IMAGE_MARK_OPACITY;
      if ($opacity <= 0) continue;
      $alpha = 127 - (int)round(127 * $opacity);
      imagesetpixel($mark, $x, $y, ($alpha << 24) | ($rgba & 0xffffff));
    }
  }
  imagealphablending($base, true);
  imagecopy($base, $mark, $width - $logoWidth - $margin, $height - $logoHeight - $margin,
    0, 0, $logoWidth, $logoHeight);
  imagesavealpha($base, false);
  ob_start();
  $ok = imagepng($base, null, 6);
  $out = (string)ob_get_clean();
  imagedestroy($base);
  imagedestroy($logo);
  imagedestroy($scaled);
  imagedestroy($mark);
  return $ok && coreui_image_png_size($out) === [$width, $height] ? $out : null;
}

/** A transparent truecolor canvas that keeps its alpha. */
function coreui_image_canvas(int $width, int $height): GdImage {
  $canvas = imagecreatetruecolor($width, $height);
  imagealphablending($canvas, false);
  imagesavealpha($canvas, true);
  imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
  return $canvas;
}

/**
 * 0.6.42: a step preview as a small data URL for the chat (at most 64 x 64; the card only
 * needs its colours for the dots), or null when it is no PNG of a sensible size.
 */
function coreui_image_preview_data_url(string $png, int $max = 64): ?string {
  $dims = coreui_image_png_size($png);
  if ($dims === null || $dims[0] < 4 || $dims[1] < 4 || $dims[0] > 2048 || $dims[1] > 2048 || strlen($png) > 4 * 1024 * 1024) return null;
  if (($dims[0] > $max || $dims[1] > $max) && function_exists('imagecreatefromstring')) {
    $image = @imagecreatefromstring($png);
    if ($image === false) return null;
    $scale = $max / max($dims[0], $dims[1]);
    $small = imagescale($image, max(1, (int)round($dims[0] * $scale)), max(1, (int)round($dims[1] * $scale)), IMG_BICUBIC);
    if ($small === false) return null;
    ob_start();
    imagepng($small, null, 6);
    $png = (string)ob_get_clean();
  } elseif ($dims[0] > $max || $dims[1] > $max) {
    return null;
  }
  return 'data:image/png;base64,' . base64_encode($png);
}

/** Width and height from a PNG's header, or null (pure). */
function coreui_image_png_size(string $png): ?array {
  if (strlen($png) < 24 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR') return null;
  $dims = unpack('Nw/Nh', substr($png, 16, 8));
  return is_array($dims) ? [(int)$dims['w'], (int)$dims['h']] : null;
}

/**
 * 0.6.41: what the progress card in the chat needs of a job (pure): phase, finished steps,
 * all steps and the image's width (512 or 1024), for the stage text and the percent.
 */
function coreui_image_progress_meta(array $job): array {
  $phase = (string)($job['phase'] ?? 'load');
  if (!in_array($phase, ['load', 'text', 'sampling', 'decode', 'save', 'done'], true)) $phase = 'load';
  return ['source' => 'image', 'reason' => 'image_' . ($phase === 'save' ? 'decode' : $phase),
    'step' => max(0, (int)($job['step'] ?? 0)), 'steps' => max(1, (int)($job['steps'] ?? 4)),
    'image_px' => max(64, (int)($job['width'] ?? 512))];
}

/** Work view title of a job's progress (pure, ASCII as the work store requires). */
function coreui_image_progress_title(array $job): string {
  $phase = (string)($job['phase'] ?? '');
  $steps = max(1, (int)($job['steps'] ?? 0));
  return match ($phase) {
    'load' => 'Bild: Gewichte werden geladen',
    'text' => 'Bild: Beschreibung wird gelesen',
    'sampling' => 'Bild: Schritt ' . min($steps, max(1, (int)($job['step'] ?? 0) + 1)) . ' von ' . $steps,
    'decode', 'save' => 'Bild: Pixel werden berechnet',
    default => 'Bild wird erzeugt',
  };
}

/**
 * Makes one image and keeps it for the account. $lease(): mixed takes Mara's model queue
 * (false when it is not free in time), $unlease($slot) gives it back, $progress(array $job)
 * hears every change of phase or step, $cancelled(): bool stops the job. Returns
 * ['ok' => true, 'uuid', 'width', 'height', 'seed', 'seconds', 'size', 'prompt'] or
 * ['ok' => false, 'error' => code, 'detail' => text].
 */
function coreui_image_generate(PDO $pdo, int $uid, string $characterId, string $prompt, string $size,
    callable $lease, callable $unlease, callable $progress, callable $cancelled, ?callable $http = null,
    ?callable $preview = null, ?string $reference = null, array $references = [], ?string $userReferenceMode = null): array {
  $http = $http ?? 'coreui_image_http';
  $previews = 0; // 0.6.42: $preview(string $dataUrl, array $job) gets every new step preview
  $registry = coreui_image_registry();
  $limit = (int)($registry['limits']['prompt_chars'] ?? 1200);
  $prompt = trim((string)preg_replace('~\s+~u', ' ', $prompt));
  if ($prompt === '' || mb_strlen($prompt) > $limit) return ['ok' => false, 'error' => 'bad_prompt', 'detail' => ''];
  if (!isset(($registry['sizes'] ?? [])[$size])) return ['ok' => false, 'error' => 'bad_size', 'detail' => ''];
  // Every image reference passes through the same identity/subject/style
  // consistency standard, regardless of which chat or voice tool selected it.
  // The original scene is used ONLY to choose canonical Mara angles/styles.
  // The image service never selects from its own policy/prompt boilerplate.
  $sceneHint = $prompt;
  $prompt = coreui_image_reference_prompt($prompt, $reference, $references, $limit, $userReferenceMode);
  if (count($references) > COREUI_IMAGE_MAX_REFERENCES) {
    return ['ok'=>false, 'error'=>'bad_reference', 'detail'=>''];
  }
  foreach ($references as $token) {
    if (!is_string($token) || preg_match('~^upload:[a-f0-9]{32}\\.(?:png|jpg|webp|gif)$~D', $token) !== 1) {
      return ['ok'=>false, 'error'=>'bad_reference', 'detail'=>''];
    }
  }
  if (count($references) !== count(array_unique($references))) {
    return ['ok'=>false, 'error'=>'bad_reference', 'detail'=>''];
  }
  [$code, $health] = $http('GET', '/health', null, 4);
  if ($code !== 200 || !is_array($health)) return ['ok' => false, 'error' => 'image_unreachable', 'detail' => ''];
  if (empty($health['ready'])) {
    if (!empty($health['loading'])) {
      $have = (float)($health['weights']['have'] ?? 0);
      $total = (float)($health['weights']['total'] ?? 0);
      return ['ok' => false, 'error' => 'image_loading',
        'detail' => $total > 0 ? (string)(int)floor(100 * $have / $total) : ''];
    }
    return ['ok' => false, 'error' => 'image_unavailable', 'detail' => mb_substr((string)($health['error'] ?? ''), 0, 200)];
  }

  $slot = $lease();
  if ($slot === false) return ['ok' => false, 'error' => 'model_queue_timeout', 'detail' => ''];
  $jobId = '';
  try {
    if ($cancelled()) return ['ok' => false, 'error' => 'cancelled', 'detail' => ''];
    $body = ['prompt' => $prompt, 'size' => $size];
    if ($reference !== null) $body['reference'] = $reference;
    if ($reference === 'default') $body['scene_hint'] = $sceneHint;
    if ($references !== []) $body['references'] = $references;
    [$code, $answer] = $http('POST', '/jobs', $body, 10);
    if ($code !== 202 || !is_array($answer) || !is_array($answer['job'] ?? null)) {
      return ['ok' => false, 'error' => (string)($answer['error'] ?? 'image_failed'), 'detail' => (string)($answer['detail'] ?? '')];
    }
    $job = $answer['job'];
    $jobId = (string)($job['id'] ?? '');
    if (preg_match('~^[a-f0-9]{32}$~D', $jobId) !== 1) return ['ok' => false, 'error' => 'image_failed', 'detail' => ''];
    // Fail closed if an outdated/partial worker silently ignores references:
    // otherwise a self portrait is text-to-image and FLUX invents a stranger.
    // The worker reports actual resolved -r files (including canonical angles).
    $requiredReferences = count($references) + ($reference !== null ? 1 : 0);
    // If this deployment ships Mara's canonical primary photo, an older
    // worker must not silently fall back to mara_winter.jpg. The selected
    // custom account portrait never inherits the canonical gallery.
    $canonicalAvailable = $reference === 'default'
      && is_file(dirname(__DIR__) . '/images/mara_references/mara_identity_primary.png');
    if (($requiredReferences > 0 && (int)($job['reference_count'] ?? 0) < $requiredReferences)
        || ($canonicalAvailable && (int)($job['curated_mara_count'] ?? 0) < 1)) {
      $http('DELETE', '/jobs/' . $jobId, null, 5);
      return ['ok' => false, 'error' => 'reference_unavailable', 'detail' => ''];
    }
    $deadline = microtime(true) + (int)($registry['sizes'][$size]['time_limit_s'] ?? 300) + 60;
    $seen = '';
    while (true) {
      $now = coreui_image_progress_title($job);
      if ($now !== $seen) { $seen = $now; $progress($job); }
      if (in_array($job['state'] ?? '', ['done', 'failed', 'cancelled'], true)) break;
      if ($cancelled()) {
        $http('DELETE', '/jobs/' . $jobId, null, 5);
        return ['ok' => false, 'error' => 'cancelled', 'detail' => ''];
      }
      if (microtime(true) > $deadline) {
        $http('DELETE', '/jobs/' . $jobId, null, 5);
        return ['ok' => false, 'error' => 'image_timeout', 'detail' => ''];
      }
      usleep(800000);
      [$code, $answer] = $http('GET', '/jobs/' . $jobId, null, 5);
      if ($code === 200 && is_array($answer['job'] ?? null)) $job = $answer['job'];
      elseif ($code === 404) return ['ok' => false, 'error' => 'image_failed', 'detail' => 'Auftrag verloren'];
      if ($preview !== null && (int)($job['preview'] ?? 0) > $previews && ($job['state'] ?? '') === 'running') {
        $previews = (int)$job['preview'];
        [$shot, , $raw] = $http('GET', '/jobs/' . $jobId . '/preview', null, 3);
        $url = $shot === 200 ? coreui_image_preview_data_url($raw) : null;
        if ($url !== null) $preview($url, $job);
      }
    }
    if (($job['state'] ?? '') !== 'done') {
      return ['ok' => false, 'error' => ($job['state'] ?? '') === 'cancelled' ? 'cancelled' : 'image_failed',
        'detail' => mb_substr((string)($job['error'] ?? ''), 0, 200)];
    }
    [$code, , $png] = $http('GET', '/jobs/' . $jobId . '/image', null, 30);
  } finally {
    $unlease($slot);
  }
  $dims = $code === 200 ? coreui_image_png_size($png) : null;
  if ($dims === null || strlen($png) > COREUI_IMAGE_MAX_BYTES
      || $dims !== [(int)$job['width'], (int)$job['height']]) {
    return ['ok' => false, 'error' => 'image_failed', 'detail' => 'kein gueltiges PNG'];
  }
  $marked = coreui_image_watermark($png);
  if ($marked === null) error_log('[CoreAI] Image saved without logo (GD or logo asset unavailable)');
  $meta = [
    'watermark' => $marked !== null,
    'prompt' => $prompt, 'size' => $size, 'width' => $dims[0], 'height' => $dims[1],
    'seed' => (int)($job['seed'] ?? 0), 'seconds' => round((float)($job['seconds'] ?? 0), 1),
    'model' => (string)($registry['model']['label'] ?? ''),
    // The worker also counts the extra built-in Mara photos it actually used.
    'reference_count' => (int)($job['reference_count'] ?? (count($references) + ($reference !== null ? 1 : 0))),
    'created_at' => gmdate('Y-m-d\\TH:i:s+00:00'),
  ];
  $image = $marked ?? $png;
  $embedded = coreui_image_png_embed_provenance($image, $meta, $registry);
  if ($embedded === null) error_log('[CoreAI] PNG metadata embedding failed; the generated image is still usable');
  else $image = $embedded;
  $meta['metadata_embedded'] = $embedded !== null;
  return coreui_image_store($pdo, $uid, $characterId, $image, $meta);
}

/** Keeps a made image as a media entry of the account (and its description next to it). */
function coreui_image_store(PDO $pdo, int $uid, string $characterId, string $png, array $meta): array {
  $dir = coreui_image_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return ['ok' => false, 'error' => 'storage_failed', 'detail' => ''];
  $uuid = bin2hex(random_bytes(16));
  $file = $dir . '/' . $uuid . '.png';
  if (@file_put_contents($file, $png, LOCK_EX) !== strlen($png)) {
    @unlink($file);
    return ['ok' => false, 'error' => 'storage_failed', 'detail' => ''];
  }
  @chmod($file, 0640);
  $meta['created_at'] = $meta['created_at'] ?? gmdate('c');
  @file_put_contents($dir . '/' . $uuid . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
  $alias = 'Mara';
  if (function_exists('coreui_profile_load') && $uid > 0) {
    try { $alias = coreui_profile_name((string)(coreui_profile_load($pdo, $uid)['assistant_name'] ?? ''), 'Mara'); }
    catch (Throwable $e) {}
  }
  $name = $alias . '-Bild-' . date('Ymd-His') . '.png';
  try {
    $pdo->prepare('INSERT INTO stu_console_media (uuid, user_id, character_id, kind, orig_name, stored_name, rel_path, public_url, mime_type, file_size) '
      . 'VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$uuid, $uid, $characterId !== '' ? $characterId : null, 'image', $name, $uuid . '.png',
        'var/console_media/images/' . $uuid . '.png', null, 'image/png', strlen($png)]);
  } catch (Throwable $e) {
    @unlink($file);
    @unlink($dir . '/' . $uuid . '.json');
    return ['ok' => false, 'error' => 'storage_failed', 'detail' => ''];
  }
  return ['ok' => true, 'uuid' => $uuid] + $meta;
}

/** What Mara knows about an image she made (the JSON next to it), or null. */
function coreui_image_meta(string $uuid): ?array {
  if (preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) return null;
  $raw = @file_get_contents(coreui_image_dir() . '/' . $uuid . '.json');
  $data = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($data) ? $data : null;
}

/** The result of the step, as Mara reads it in her next prompt (pure). */
function coreui_image_result_text(array $result): string {
  if (!empty($result['ok'])) {
    return 'Das Bild ist fertig (' . (int)$result['width'] . ' × ' . (int)$result['height'] . ' Pixel, '
      . (int)round((float)$result['seconds']) . ' Sekunden) und ist als Bildanhang deiner Antwort gespeichert. '
      . 'Wenn dir die echten Bildpixel im folgenden Vision-Schritt vorliegen, kommentiere die '
      . 'sichtbaren Details. Sonst nutze nur die bestaetigte Beschreibung und erfinde keine Pixel. '
      . 'Schreibe keinen Platzhalter wie "(Hier erscheint das Bild)"; das Bild haengt schon an deiner Antwort. '
      . 'Kein weiteres [BILD:], ausser die Person will ausdruecklich noch ein Bild.';
  }
  return 'Es gibt KEIN Bild: ' . coreui_image_error_text($result) . ' Sag das offen und kurz; behaupte nicht, dass ein Bild da ist.';
}

/** Public explanation shared by single-image tool results and finite batches. */
function coreui_image_error_text(array $result): string {
  return match ((string)($result['error'] ?? '')) {
    'image_loading' => 'Der Bilddienst laedt gerade noch seine Gewichte'
      . (($result['detail'] ?? '') !== '' ? ' (' . (int)$result['detail'] . ' Prozent)' : '') . '. Bitte in ein paar Minuten noch einmal.',
    'image_unreachable', 'image_unavailable' => 'Der Bilddienst ist gerade nicht bereit.',
    'image_busy', 'model_queue_timeout' => 'Gerade laeuft schon eine andere Arbeit; das Bild konnte nicht starten.',
    'image_memory' => 'Fuer das Bild ist gerade nicht genug Arbeitsspeicher frei.',
    'image_timeout' => 'Das Bild hat zu lange gedauert und wurde abgebrochen.',
    'cancelled' => 'Das Bild wurde abgebrochen.',
    'bad_prompt' => 'Die Bildbeschreibung war leer oder zu lang.',
    'bad_reference', 'reference_unavailable' => 'Mindestens ein Referenzbild ist ungueltig oder nicht mehr verfuegbar.',
    default => 'Das Bild konnte nicht erzeugt werden.',
  };
}

/** Only the operator-selected sizes of a batch are accepted; default is one. */
function coreui_image_count(mixed $count): int {
  return in_array($count, [1, 2, 4], true) ? $count : 1;
}

/** One serial, finite batch. Stop on the first failure or cancellation, retain prior images. */
function coreui_image_batch_run(mixed $count, callable $generate, callable $cancelled): string {
  $requested = coreui_image_count($count);
  $done = 0;
  $failure = '';
  for ($i = 0; $i < $requested; $i++) {
    if ($cancelled()) { $failure = 'Die Bildgenerierung wurde abgebrochen.'; break; }
    $result = $generate();
    if (empty($result['ok'])) {
      $failure = coreui_image_error_text($result);
      break;
    }
    $done++;
  }
  $summary = $done === 1 ? 'Ich habe ein Bild erstellt.' : 'Ich habe ' . $done . ' Bilder erstellt.';
  if ($done === 0) $summary = 'Ich konnte kein Bild erstellen.';
  if ($done > 0 && $done < $requested) $summary .= ' Fertig sind ' . $done . ' von ' . $requested . '.';
  // A failed later image must not deny the successfully attached earlier images.
  return $summary . ($failure !== '' ? ' ' . $failure : '');
}

/** Images made in this turn, attached to the answer when it is saved. */
function coreui_image_pending_add(string $uuid): void {
  if (preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) return;
  $list = $GLOBALS['COREUI_PENDING_IMAGES'] ?? [];
  $list = is_array($list) ? $list : [];
  if (!in_array($uuid, $list, true) && count($list) < 4) $list[] = $uuid;
  $GLOBALS['COREUI_PENDING_IMAGES'] = $list;
}

function coreui_image_pending_take(): array {
  $list = $GLOBALS['COREUI_PENDING_IMAGES'] ?? [];
  unset($GLOBALS['COREUI_PENDING_IMAGES']);
  return is_array($list) ? array_values(array_filter($list, static fn($u): bool => is_string($u) && preg_match('~^[a-f0-9]{32}$~D', $u) === 1)) : [];
}

/**
 * Resolve only images generated in THIS turn and owned by THIS account.
 * The returned absolute paths are never accepted from the browser or a model.
 * Does not consume COREUI_PENDING_IMAGES: saving the final reply still attaches them.
 */
function coreui_image_pending_vision_paths(PDO $pdo, int $uid): array {
  if ($uid <= 0) return [];
  $pending = $GLOBALS['COREUI_PENDING_IMAGES'] ?? [];
  if (!is_array($pending) || $pending === []) return [];
  $dir = realpath(coreui_image_dir());
  if (!is_string($dir) || !is_dir($dir)) return [];
  try {
    $find = $pdo->prepare('SELECT stored_name, rel_path, mime_type, file_size '
      . 'FROM stu_console_media WHERE uuid = ? AND user_id = ? AND kind = ? LIMIT 1');
  } catch (Throwable $e) {
    return [];
  }
  $paths = [];
  foreach (array_slice(array_values(array_unique($pending)), 0, 4) as $uuid) {
    if (!is_string($uuid) || preg_match('~^[a-f0-9]{32}$~D', $uuid) !== 1) continue;
    try {
      $find->execute([$uuid, $uid, 'image']);
      $row = $find->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      continue;
    }
    if (!is_array($row)
        || (string)($row['stored_name'] ?? '') !== $uuid . '.png'
        || (string)($row['rel_path'] ?? '') !== 'var/console_media/images/' . $uuid . '.png'
        || (string)($row['mime_type'] ?? '') !== 'image/png'
        || (int)($row['file_size'] ?? 0) < 100
        || (int)($row['file_size'] ?? 0) > COREUI_IMAGE_MAX_BYTES) continue;
    $path = realpath($dir . '/' . $uuid . '.png');
    if (!is_string($path) || $path !== $dir . '/' . $uuid . '.png'
        || !is_file($path) || !is_readable($path)) continue;
    $bytes = filesize($path);
    if ($bytes === false || $bytes < 100 || $bytes > COREUI_IMAGE_MAX_BYTES) continue;
    $paths[] = $path;
  }
  return $paths;
}

/** Lines for the history: what Mara painted in earlier answers ([message_id => line]). */
function coreui_image_history_notes(PDO $pdo, int $uid, array $messageIds): array {
  $messageIds = array_values(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0));
  if ($uid <= 0 || $messageIds === []) return [];
  try {
    $st = $pdo->prepare('SELECT a.message_id, m.uuid FROM stu_console_message_attachments a '
      . 'JOIN stu_console_media m ON m.uuid = a.media_uuid AND m.user_id = a.user_id '
      . "WHERE a.user_id = ? AND m.kind = 'image' AND m.rel_path LIKE 'var/console_media/images/%' AND a.message_id IN ("
      . implode(',', array_fill(0, count($messageIds), '?')) . ') ORDER BY a.message_id, a.position');
    $st->execute([$uid, ...$messageIds]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
  $notes = [];
  foreach ($rows as $row) {
    $meta = coreui_image_meta((string)$row['uuid']);
    if ($meta === null) continue;
    $line = (int)($meta['width'] ?? 0) . ' × ' . (int)($meta['height'] ?? 0) . ': "'
      . mb_substr((string)($meta['prompt'] ?? ''), 0, 300) . '"';
    $id = (int)$row['message_id'];
    $notes[$id] = isset($notes[$id]) ? $notes[$id] . "\n- " . $line : $line;
  }
  return $notes;
}

/**
 * 0.6.40: the images of the chat as one block after the conversation, not inside Mara's own
 * lines. As "(Von dir erzeugtes Bild ...)" behind her answer she copied the line into a new
 * answer instead of painting (operator's screenshot, 07.11.2026). The last five, oldest first.
 */
function coreui_image_history_block(array $notes): string {
  $notes = array_slice(array_values(array_filter(array_map('strval', $notes), static fn(string $n): bool => $n !== '')), -5);
  if ($notes === []) return '';
  return "--- BILDER, DIE DU IN DIESEM CHAT SCHON GEMALT HAST (nur Hintergrund; sie haengen an deinen Antworten) ---\n- "
    . implode("\n- ", $notes) . "\n--- ENDE BILDER ---\n"
    . 'Ein NEUES Bild entsteht nur, wenn du mit dem Marker [BILD: ...] antwortest. Uebernimm diese Liste nie in deine Antwort.';
}

/**
 * 0.6.40: what Mara wrote instead of an image, out of a reply (pure): a copied history line
 * ("(Von dir erzeugtes Bild (512 × 512): "...")") and a placeholder line in brackets
 * ("*(Hier erscheint das Bild der Katze im Schnee)"). The image itself is attached to the
 * answer; a sentence like "Hier ist dein Bild!" without brackets stays.
 */
function coreui_image_strip_placeholders(string $text): string {
  if (stripos($text, 'bild') === false) return $text;
  $out = (string)preg_replace('~[ \t]*\(?\s*Von dir erzeugtes Bild \(\d+\s*[×x]\s*\d+\):\s*"[^"\n]*"\s*\)?~u', '', $text);
  $out = (string)preg_replace('~^[ \t]*[*_]*\([ \t]*[*_]*[ \t]*(?:hier|an dieser stelle|unten|darunter)\s+'
    . '(?:erscheint|folgt|kommt|ist|siehst du|wuerde|würde|wird)\b[^\n]*?\bbild\b[^\n]*\)[*_]*[ \t]*$\n?~imu', '', $out);
  return $out === $text ? $text : trim((string)preg_replace("~\n{3,}~", "\n\n", $out));
}

/** The hint in Mara's prompt that she can make images (pure). */
function coreui_image_capability_hint(bool $compact): string {
  if ($compact) {
    return "BILDER: Moechte die Person ein Bild von dir, antworte NUR mit [BILD ICH: genaue Bildbeschreibung auf Englisch]. "
      . "Dein aktives Profilbild zeigt DICH SELBST als TCI: Mara Thorne, nicht ein Vorbild oder eine aehnliche Person. Gesicht und Frisur gehoeren dir. Eine TCI hat nicht automatisch Androidenmerkmale, "
      . "Neonlinien oder Implantate im Gesicht. Erfinde solche Merkmale nicht ohne ausdruecklichen Wunsch. "
      . "Szenen mit Mara Thorne, deinem Rufnamen oder DIR als Bildmotiv sind immer eigene Selbstportraets mit ICH, auch bei 2 oder 4 Bildern. Eine Anrede wie 'Mara, male eine fremde Frau' ist KEIN Selbstportraet. "
      . "Gross (1024) nur auf ausdruecklichen Wunsch: [BILD GROSS: ...]; fuer dich selbst [BILD GROSS ICH: ...]. Andere Motive [BILD: ...]. Ohne Wunsch kein Bild.";
  }
  return "BILDER: Du kannst selbst Bilder malen (Fotos, Illustrationen, Szenen, Schrift auf Schildern). Moechte die Person "
    . "ausdruecklich ein Bild, Foto, eine Zeichnung oder Illustration von dir, antworte NUR mit einem Marker: "
    . "[BILD ICH: ausfuehrliche Bildbeschreibung auf Englisch: Motiv, Umgebung, Licht, Stil; Text im Bild in Anfuehrungszeichen]. "
    . "Nur fuer ausdrueckliche Selbstportraets verwende ICH: Das aktive Profilbild zeigt DICH SELBST, TCI: Mara Thorne. Es ist deine eigene visuelle Identitaet, kein Foto einer Person, die dir nur aehnlich sieht. Das gilt auch bei Kostuem, Rollenspiel oder einem frei gewaehlten Rufnamen. "
    . "Bei einer schlichten Frage nach deinem Aussehen zeige dich als DIESELBE Person auf deinem eigenen Profilfoto. Erfinde keine Gesichtsdetails, die du nicht sehen kannst: der Bilddienst erhaelt dieses Foto als verbindliche Selbstreferenz. Beschreibe in ICH-Auftraegen vor allem Handlung, Pose, Kleidung, Licht und Szene statt ein neues Gesicht zu entwerfen. "
    . "Auch bei Szenen wie 'Mara im Cockpit' oder mit deinem aktuell eingestellten Rufnamen als Bildmotiv ist Mara selbst das Motiv, kein neues generisches Frauenmodell: IMMER [BILD ICH: ...] fuer ihr festes Referenzgesicht, auch bei 2/4 Bildern. Ein nur als Anrede verwendetes 'Mara, zeichne eine fremde Frau' meint dagegen ein fremdes Motiv mit [BILD: ...]. "
    . "Die technische TCI-Identitaet bedeutet NICHT, dass dein Gesicht mechanische Linien, Neonleuchten, Implantate oder Androidenmerkmale besitzen muss. "
    . "Erfinde solche Merkmale nur, wenn die Person ausdruecklich eine solche Verwandlung wuenscht. "
    . "Fuer fremde Personen, Tiere oder Szenen benutze [BILD: ...] ohne ICH. "
    . "Standard sind 512 × 512 Pixel (etwa eine Minute). Nur wenn die Person ausdruecklich ein grosses oder hochaufloesendes "
    . "Bild will: [BILD GROSS: ...] bzw. [BILD GROSS ICH: ...] (1024 × 1024, drei bis vier Minuten). Ohne Bildwunsch malst du nichts; fuer Fragen "
    . "ueber Bilder brauchst du keinen Marker. Personen des echten Lebens malst du nicht als Foto. "
    . "Ohne den Marker entsteht kein Bild: schreibe nie, dass ein Bild erscheint, und keine Platzhalter wie \"(Hier erscheint das Bild)\".";
}

/** The attachments of one message in the shape of the chat history (console_messages.php). */
function coreui_image_message_attachments(PDO $pdo, int $uid, int $messageId): array {
  if ($uid <= 0 || $messageId <= 0) return [];
  try {
    $st = $pdo->prepare('SELECT a.media_uuid, cm.kind, cm.orig_name, cm.mime_type, cm.file_size, cm.public_url, cm.rel_path '
      . 'FROM stu_console_message_attachments a JOIN stu_console_media cm ON cm.uuid=a.media_uuid AND cm.user_id=a.user_id '
      . 'WHERE a.user_id=? AND a.message_id=? ORDER BY a.position ASC');
    $st->execute([$uid, $messageId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
  $out = [];
  foreach ($rows as $row) {
    $uuid = (string)$row['media_uuid'];
    $url = 'api/console_media.php?uuid=' . rawurlencode($uuid);
    $out[] = ['uuid' => $uuid, 'kind' => (string)($row['kind'] ?? 'document'), 'name' => (string)($row['orig_name'] ?? 'Datei'),
      'mime' => (string)($row['mime_type'] ?? ''), 'size' => (int)($row['file_size'] ?? 0),
      'url' => function_exists('stu_public_path') ? stu_public_path($url) : $url,
      'image_url' => ($row['public_url'] ?? '') !== '' ? (string)$row['public_url'] : null,
      'generated' => str_starts_with((string)($row['rel_path'] ?? ''), 'var/console_media/images/')];
  }
  return $out;
}
