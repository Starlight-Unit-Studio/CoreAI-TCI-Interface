<?php
declare(strict_types=1);

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
 * everyone (Betrieb) or per account (Benutzer). Each exported image carries a
 * small dynamic-year Quantum CoreAI copyright at bottom left and the watermark_logo.png
 * in the bottom-right corner, following the operator's reference image.
 */

const COREUI_IMAGE_DEFAULT_PORT = 18791;
const COREUI_IMAGE_MAX_BYTES = 25 * 1024 * 1024;
const COREUI_IMAGE_LOGO_WIDTH = 0.12;  // 12% of the image width
const COREUI_IMAGE_MARK_MARGIN = 0.025; // relative to the image width
const COREUI_IMAGE_MARK_OPACITY = 0.5;  // logo opacity
const COREUI_IMAGE_CREDIT_OPACITY = 0.75;
const COREUI_IMAGE_MARK_TEXT = 'Quantum CoreAI';

/** Copyright line follows the image creation year, never a fixed release year. */
function coreui_image_mark_text(?int $year = null): string {
  return '© ' . ($year ?? (int)date('Y')) . ' ' . COREUI_IMAGE_MARK_TEXT;
}

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
function coreui_image_is_self_request(string $message): bool {
  $text = trim((string)preg_replace('~\\s+~u', ' ', $message));
  if ($text === '') return false;
  return preg_match('~\\b(?:mara(?:\\s+thorne)?|du)\\s+(?:als|in\\s+einem|auf\\s+der)\\b|\\b(?:von\\s+dir|mit\\s+dir|dein(?:\\s+eigenes)?\\s+(?:portrait|portraet|porträt|bild|foto)|zeig(?:e)?\\s+dich|zeichne\\s+dich|mal\\s+dich|male\\s+dich|dich\\s+als|yourself\\s+as|picture\\s+of\\s+you|portrait\\s+of\\s+you)\\b~iu', $text) === 1;
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
 * Reference-image layout: tiny copyright at bottom left, studio-independent
 * Quantum watermark logo at bottom right. The source watermark_logo.png already
 * has real RGBA transparency; preserve its native alpha when scaling and fading.
 * Both elements are included in the stored PNG.
 * Returns null if required imaging support or assets are missing.
 */
function coreui_image_watermark(string $png): ?string {
  $logoPath = dirname(__DIR__) . '/images/watermark_logo.png';
  $fontPath = dirname(__DIR__) . '/assets/fonts/rajdhani_700.ttf';
  if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatefrompng')
      || !function_exists('imagecopyresampled') || !function_exists('imagettftext')
      || !function_exists('imagettfbbox') || !is_file($logoPath) || !is_file($fontPath)) return null;
  $base = @imagecreatefromstring($png);
  $logo = @imagecreatefrompng($logoPath);
  if ($base === false || $logo === false) return null;
  if (!imageistruecolor($base)) imagepalettetotruecolor($base);
  $width = imagesx($base);
  $height = imagesy($base);
  $margin = max(4, (int)round($width * COREUI_IMAGE_MARK_MARGIN));
  $logoWidth = max(1, (int)round($width * COREUI_IMAGE_LOGO_WIDTH));
  $logoWidth = min($logoWidth, $width - 2 * $margin);
  $logoHeight = max(1, (int)round($logoWidth * imagesy($logo) / imagesx($logo)));
  if ($logoWidth < 1 || $logoHeight + 2 * $margin > $height) return null;

  $text = coreui_image_mark_text();
  $fontSize = max(8.0, min(14.0, round($width * 0.012)));
  $box = imagettfbbox($fontSize, 0, $fontPath, $text);
  if ($box === false) return null;
  $textWidth = $box[2] - $box[0];
  $textHeight = $box[1] - $box[7];
  if ($textWidth <= 0 || $textHeight <= 0
      || $textWidth + $logoWidth + 3 * $margin > $width
      || $textHeight + 2 * $margin > $height) return null;

  $scaled = coreui_image_canvas($logoWidth, $logoHeight);
  imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
  $mark = coreui_image_canvas($logoWidth, $logoHeight);
  for ($y = 0; $y < $logoHeight; $y++) {
    for ($x = 0; $x < $logoWidth; $x++) {
      $rgba = imagecolorat($scaled, $x, $y);
      // Respect the PNG's native alpha, including partially transparent pixels.
      // Only reduce its existing opacity; never key out colours by brightness.
      $nativeOpacity = 1 - ((($rgba >> 24) & 0x7F) / 127);
      $opacity = $nativeOpacity * COREUI_IMAGE_MARK_OPACITY;
      if ($opacity <= 0) continue;
      $alpha = 127 - (int)round(127 * $opacity);
      imagesetpixel($mark, $x, $y, ($alpha << 24) | ($rgba & 0xFFFFFF));
    }
  }
  imagealphablending($base, true);
  imagecopy($base, $mark, $width - $logoWidth - $margin, $height - $logoHeight - $margin,
    0, 0, $logoWidth, $logoHeight);

  // Align the glyphs to the same bottom margin as the watermark logo.
  $textX = $margin - $box[0];
  $textY = $height - $margin - $box[1];
  $whiteAlpha = 127 - (int)round(127 * COREUI_IMAGE_CREDIT_OPACITY);
  $shadowAlpha = 127 - (int)round(127 * COREUI_IMAGE_CREDIT_OPACITY * 0.48);
  imagettftext($base, $fontSize, 0, $textX + 1, $textY + 1,
    imagecolorallocatealpha($base, 0, 0, 0, $shadowAlpha), $fontPath, $text);
  imagettftext($base, $fontSize, 0, $textX, $textY,
    imagecolorallocatealpha($base, 255, 255, 255, $whiteAlpha), $fontPath, $text);

  imagesavealpha($base, false);
  ob_start();
  $ok = imagepng($base, null, 6);
  $out = (string)ob_get_clean();
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
    ?callable $preview = null, ?string $reference = null): array {
  $http = $http ?? 'coreui_image_http';
  $previews = 0; // 0.6.42: $preview(string $dataUrl, array $job) gets every new step preview
  $registry = coreui_image_registry();
  $limit = (int)($registry['limits']['prompt_chars'] ?? 1200);
  $prompt = trim((string)preg_replace('~\s+~u', ' ', $prompt));
  if ($prompt === '' || mb_strlen($prompt) > $limit) return ['ok' => false, 'error' => 'bad_prompt', 'detail' => ''];
  if (!isset(($registry['sizes'] ?? [])[$size])) return ['ok' => false, 'error' => 'bad_size', 'detail' => ''];
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
    [$code, $answer] = $http('POST', '/jobs', $body, 10);
    if ($code !== 202 || !is_array($answer) || !is_array($answer['job'] ?? null)) {
      return ['ok' => false, 'error' => (string)($answer['error'] ?? 'image_failed'), 'detail' => (string)($answer['detail'] ?? '')];
    }
    $job = $answer['job'];
    $jobId = (string)($job['id'] ?? '');
    if (preg_match('~^[a-f0-9]{32}$~D', $jobId) !== 1) return ['ok' => false, 'error' => 'image_failed', 'detail' => ''];
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
  if ($marked === null) error_log('[CoreUI] Image kept without credit/logo (GD, font or watermark image missing)');
  return coreui_image_store($pdo, $uid, $characterId, $marked ?? $png, [
    'watermark' => $marked !== null,
    'prompt' => $prompt, 'size' => $size, 'width' => $dims[0], 'height' => $dims[1],
    'seed' => (int)($job['seed'] ?? 0), 'seconds' => round((float)($job['seconds'] ?? 0), 1),
    'model' => (string)($registry['model']['label'] ?? ''),
  ]);
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
  $meta['created_at'] = gmdate('c');
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
      . (int)round((float)$result['seconds']) . ' Sekunden) und erscheint direkt unter deiner Antwort. '
      . 'Sag kurz, dass es da ist, und beschreibe in ein, zwei Saetzen, was du gemalt hast. '
      . 'Du kannst es nicht sehen; erfinde keine Einzelheiten, die nicht in deiner Beschreibung standen. '
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
      . "Gross (1024) nur auf ausdruecklichen Wunsch: [BILD GROSS: ...]; fuer dich selbst [BILD GROSS ICH: ...]. Andere Motive [BILD: ...]. Ohne Wunsch kein Bild.";
  }
  return "BILDER: Du kannst selbst Bilder malen (Fotos, Illustrationen, Szenen, Schrift auf Schildern). Moechte die Person "
    . "ausdruecklich ein Bild, Foto, eine Zeichnung oder Illustration von dir, antworte NUR mit einem Marker: "
    . "[BILD ICH: ausfuehrliche Bildbeschreibung auf Englisch: Motiv, Umgebung, Licht, Stil; Text im Bild in Anfuehrungszeichen]. "
    . "Nur fuer ausdrueckliche Selbstportraets verwende ICH und nutze dabei dein aktives Profilbild als Gesichtsvorlage, auch bei Kostuem und Rollenspiel. "
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
