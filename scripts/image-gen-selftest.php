<?php
declare(strict_types=1);
// CoreAI 0.6.39 (P20.5 C): Mara makes images in the chat.
//   php scripts/image-gen-selftest.php
// Offline: the marker ([BILD: ...], [BILD GROSS: ...]), the step in Mara's work loop, the
// prompt hint, the progress titles, the result Mara reads, making an image against a
// stand-in image service (health, queue lease, progress, cancel, wrong PNG, busy) and
// keeping it as a media entry with its description (SQLite), the history line, the
// attachments of an answer, deleting it with its chat, and the wiring (chat, stream,
// switches under Betrieb and per account, migration 026, chat view). With a test database
// (COREUI_SELFTEST_DB=1, never the live one: the model queue is shared by every call): a
// call waiting behind an image waits longer instead of failing, and runs when it is done.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/coreai-image-gen-' . bin2hex(random_bytes(4));
mkdir($tmp . '/images', 0700, true);
define('COREUI_IMAGE_DIR', $tmp . '/images');
require_once $root . '/api/image_gen.php';
require_once $root . '/api/console_agent_loop.php';
require_once $root . '/api/console_model_queue.php';

function img_gen_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Image gen FAIL: {$why}\n"); exit(1); }
}

// --- Marker -----------------------------------------------------------------------------
img_gen_test(coreui_image_extract_marker('[BILD: a red fox in the snow]') === 'a red fox in the snow', 'plain marker');
img_gen_test(coreui_image_extract_marker("Gern!\n[BILD GROSS: a castle at night, moonlight]") === 'GROSS: a castle at night, moonlight', 'big marker');
img_gen_test(coreui_image_extract_marker('[bild groß:  a  cat ]') === 'GROSS: a cat', 'lower case, ß, spaces folded');
img_gen_test(coreui_image_extract_marker('[BILD: ab]') === null && coreui_image_extract_marker('Ich kann dir gern ein Bild malen.') === null
  && coreui_image_extract_marker('[BILD: a [nested] thing]') === null && coreui_image_extract_marker('[BILDER: x y z]') === null,
  'no marker, too short or brackets inside');
img_gen_test(coreui_image_parse_input('GROSS: a castle') === ['size' => 'large', 'prompt' => 'a castle', 'self_reference' => false]
  && coreui_image_parse_input('a fox') === ['size' => 'normal', 'prompt' => 'a fox', 'self_reference' => false], 'size from the input');
img_gen_test(coreui_image_extract_marker('[BILD ICH: Mara in winter]') === 'ICH: Mara in winter'
  && coreui_image_extract_marker('[BILD GROSS ICH: Mara as a captain]') === 'GROSS: ICH: Mara as a captain'
  && coreui_image_parse_input('GROSS: ICH: Mara as a captain') === ['size' => 'large', 'prompt' => 'Mara as a captain', 'self_reference' => true],
  'self portrait marker selects account reference and allows roleplay');
img_gen_test(coreui_image_is_self_request('Zeig dich als Superwoman')
  && coreui_image_is_self_request('Ein Bild von dir auf der Bruecke')
  && !coreui_image_is_self_request('Zeichne Superwoman')
  && !coreui_image_is_self_request('Mal eine Katze'), 'self request never changes unrelated images');

// Regression: a natural request to show Mara's real appearance formerly used
// [BILD:] without reference, so Flux invented a new face and neon circuitry.
$appearanceQuestion = 'wie siehst du eigentlich aus wenn ich fragen darf? kannste mir das auch auf einem bild zeigen?';
img_gen_test(coreui_image_is_self_request($appearanceQuestion)
  && coreui_image_is_self_request('Wie siehst du aus?')
  && coreui_image_is_self_request('Wie sieht Mara Thorne aus?')
  && coreui_image_is_self_request('Wie sieht Nova aus?', 'Nova')
  && coreui_image_is_self_request('Show me what you look like.')
  && !coreui_image_is_self_request('Wie siehst du das aus deiner Perspektive?')
  && !coreui_image_is_self_request('Zeichne Superwoman')
  && !coreui_image_is_self_request('Ein Bild von einer Katze'),
  'natural appearance intent is about the TCI, never generic image subjects');
$plainTool = ['tool'=>'image', 'input'=>'A representation of Mara Thorne on a spaceship'];
$portraitTool = coreui_image_tool_for_request($plainTool, $appearanceQuestion, 'Mara');
img_gen_test($portraitTool === ['tool'=>'image', 'input'=>'ICH: A representation of Mara Thorne on a spaceship']
  && coreui_image_parse_input($portraitTool['input'])['self_reference'] === true,
  'natural text question passes the personal portrait to FLUX');
$largeTool = ['tool'=>'image', 'input'=>'GROSS: Mara as Superwoman'];
img_gen_test(coreui_image_tool_for_request($largeTool, 'Zeig dich als Superwoman')['input'] === 'GROSS: ICH: Mara as Superwoman'
  && coreui_image_tool_for_request($plainTool, 'Zeichne Superwoman') === $plainTool
  && coreui_image_tool_for_request($plainTool, 'Mal eine Katze') === $plainTool
  && coreui_image_tool_for_request(['tool'=>'web', 'input'=>'Mara'], $appearanceQuestion)['tool'] === 'web'
  && coreui_image_tool_for_request(['tool'=>'image', 'input'=>'ICH: Mara in winter'], $appearanceQuestion)['input'] === 'ICH: Mara in winter',
  'keep roleplay, normal picture requests and existing explicit marker stable');

// Current-turn uploads: only the owner and the exact stored image filename can be forwarded.
$idA = str_repeat('a', 32);
$idB = str_repeat('b', 32);
$first = ['user_id'=>7, 'kind'=>'image', 'uuid'=>$idA, 'stored_name'=>$idA . '.png',
  'rel_path'=>'assets/chat_media/' . $idA . '.png', 'mime_type'=>'image/png', 'file_size'=>512];
$second = ['user_id'=>7, 'kind'=>'image', 'uuid'=>$idB, 'stored_name'=>$idB . '.jpg',
  'rel_path'=>'assets/chat_media/' . $idB . '.jpg', 'mime_type'=>'image/jpeg', 'file_size'=>512];
img_gen_test(coreui_image_media_reference_token($first, 7) === 'upload:' . $idA . '.png'
  && coreui_image_media_reference_token($second, 7) === 'upload:' . $idB . '.jpg'
  && coreui_image_media_reference_token($first, 8) === null
  && coreui_image_media_reference_token($first + ['invalid'=>1], 0) === null
  && coreui_image_media_reference_token(array_replace($first, ['rel_path'=>'assets/chat_media/../../secret.png']), 7) === null
  && coreui_image_media_reference_token(array_replace($first, ['kind'=>'audio']), 7) === null
  && coreui_image_media_reference_token(array_replace($first, ['file_size'=>9000000]), 7) === null,
  'uploaded references require exact account ownership, media kind and safe paths');
img_gen_test(coreui_image_attachment_creation_requested('Erstelle ein Bild, in dem die beiden Figuren gegeneinander kaempfen')
  && coreui_image_attachment_creation_requested('Lass die beiden Figuren gegeneinander kämpfen')
  && coreui_image_attachment_creation_requested('Create an image of the two characters fighting')
  && coreui_image_attachment_creation_requested('Kannste du die beiden gegeneinander kämpfen lassen?')
  && coreui_image_attachment_creation_requested('Lass sie im Schnee kämpfen', ['tool'=>'image'])
  && !coreui_image_attachment_creation_requested('Analysiere diese beiden Bilder')
  && !coreui_image_attachment_creation_requested('Was zeigen die Fotos?')
  && !coreui_image_attachment_creation_requested('Kannst du Bilder lesen?'),
  'generating with references is distinct from merely analyzing images');
img_gen_test(str_contains(coreui_image_attachment_fallback_prompt('Lass die beiden kämpfen', 'Zwei Figuren in Ruestung'),
  'Zwei Figuren in Ruestung'), 'if vision gives only prose, generation gets a proper fallback prompt');
$styleFallback = coreui_image_attachment_fallback_prompt(
  'Use the reference for style only, draw a new castle', 'Two people in armor');
img_gen_test(str_contains($styleFallback, 'ONLY as visual style guides')
  && !str_contains($styleFallback, 'Two people in armor'),
  'a style-only Vision fallback must not recreate unrelated pictured characters');

// Finite batches: default/invalid counts, serial success, partial failure, cancel and no model retry.
foreach ([1, 2, 4, 9, '4', null] as $count) {
  $calls = 0;
  $answer = coreui_image_batch_run($count, static function () use (&$calls): array { $calls++; return ['ok'=>true]; }, static fn(): bool => false);
  img_gen_test($calls === (in_array($count, [1, 2, 4], true) ? $count : 1), 'batch count enforced');
}
$calls = 0;
$partial = coreui_image_batch_run(4, static function () use (&$calls): array {
  return ++$calls === 1 ? ['ok'=>true] : ['ok'=>false, 'error'=>'image_memory'];
}, static fn(): bool => false);
img_gen_test($calls === 2 && str_contains($partial, '1 von 4') && !str_contains($partial, 'KEIN Bild'), 'stop on error and retain partial result');
$calls = 0;
coreui_image_batch_run(4, static function () use (&$calls): array { $calls++; return ['ok'=>true]; }, static function () use (&$calls): bool { return $calls === 1; });
img_gen_test($calls === 1, 'cancel between images');
$modelCalls = 0;
$toolCalls = 0;
$loop = coreui_agent_loop_run('[BILD: a fox]', ['tool'=>'image', 'input'=>'a fox'], 'Make an image', ['image'], 0,
  static function () use (&$modelCalls): string { $modelCalls++; return '[BILD: another fox]'; },
  static function () use (&$toolCalls): string { $toolCalls++; return 'Ich habe ein Bild erstellt.'; },
  static function (): void {}, static fn(): bool => false);
img_gen_test($modelCalls === 0 && $toolCalls === 1 && $loop['out'] === 'Ich habe ein Bild erstellt.', 'image is terminal even if model would repeat forever');

// --- Registry ---------------------------------------------------------------------------
$registry = coreui_image_registry();
img_gen_test(($registry['model']['id'] ?? '') === 'flux2-klein-4b' && coreui_image_size_label('normal') === '512 × 512'
  && coreui_image_size_label('large') === '1024 × 1024', 'the registry: FLUX.2-klein 4B, 512 and 1024');
$pinned = $registry;
foreach ($pinned['files'] as $i => $f) { $pinned['files'][$i]['revision'] = str_repeat('a', 40); $pinned['files'][$i]['sha256'] = str_repeat('b', 64); }
img_gen_test(coreui_image_registry_pinned($pinned), 'pinned registry');
$half = $pinned;
$half['files'][2]['sha256'] = '';
img_gen_test(!coreui_image_registry_pinned($half), 'one missing SHA-256: not usable');
img_gen_test(coreui_image_registry_pinned($registry), 'the registry is pinned (revision and SHA-256 of every file)');

// --- Work loop --------------------------------------------------------------------------
$tool = coreui_agent_next_tool('Klar, ich male es dir. [BILD: a red fox in the snow at sunrise]', ['web', 'image']);
img_gen_test($tool === ['tool' => 'image', 'input' => 'a red fox in the snow at sunrise'], 'the loop finds the image step');
img_gen_test(coreui_agent_next_tool('[BILD: a red fox in the snow]', ['web']) === null, 'not allowed: no step');
img_gen_test(coreui_agent_step_title($tool, 1, 6) === 'Schritt 1 von 6: Bild', 'step title');
img_gen_test(coreui_agent_step_note(['tool' => 'image', 'input' => 'GROSS: a castle at night']) === 'Ich male: a castle at night',
  'Mara says in first person what she paints, without the size word');
img_gen_test(str_contains(coreui_agent_tool_menu(['image']), '[BILD ICH: Bildbeschreibung auf Englisch]'),
  'the menu names the marker');
$hint = coreui_image_capability_hint(false);
img_gen_test(str_contains($hint, '[BILD ICH: ausfuehrliche Bildbeschreibung auf Englisch') && str_contains($hint, '[BILD GROSS: ...] bzw. [BILD GROSS ICH: ...] (1024 × 1024')
  && str_contains($hint, 'Standard sind 512 × 512 Pixel') && str_contains($hint, 'Ohne Bildwunsch malst du nichts')
  && !str_contains($hint, 'Modell'), 'the prompt hint: 512 normal, big only on request, no image without a wish');
img_gen_test(str_contains(coreui_image_capability_hint(true), '[BILD GROSS ICH: ...]'), 'compact hint');
img_gen_test(str_contains(coreui_image_capability_hint(false), 'Die technische TCI-Identitaet bedeutet NICHT'),
  'appearance prompt does not invent cybernetic facial implants');
img_gen_test(str_contains(coreui_image_capability_hint(true), 'Dein aktives Profilbild zeigt DICH SELBST als TCI: Mara Thorne')
  && str_contains(coreui_image_capability_hint(false), 'Das aktive Profilbild zeigt DICH SELBST, TCI: Mara Thorne')
  && str_contains(coreui_image_capability_hint(false), 'nicht ein Foto einer Person, die dir nur aehnlich sieht')
  && str_contains(coreui_image_capability_hint(false), 'der Bilddienst erhaelt dieses Foto als verbindliche Selbstreferenz'),
  'Both small and large Cores recognize the portrait as the TCI herself');

// --- Progress and result ----------------------------------------------------------------
img_gen_test(coreui_image_progress_title(['phase' => 'load']) === 'Bild: Gewichte werden geladen'
  && coreui_image_progress_title(['phase' => 'text']) === 'Bild: Beschreibung wird gelesen'
  && coreui_image_progress_title(['phase' => 'sampling', 'step' => 0, 'steps' => 4]) === 'Bild: Schritt 1 von 4'
  && coreui_image_progress_title(['phase' => 'sampling', 'step' => 2, 'steps' => 4]) === 'Bild: Schritt 3 von 4'
  && coreui_image_progress_title(['phase' => 'sampling', 'step' => 4, 'steps' => 4]) === 'Bild: Schritt 4 von 4'
  && coreui_image_progress_title(['phase' => 'decode']) === 'Bild: Pixel werden berechnet', 'progress titles');
// 0.6.41: what the progress card in the chat gets with every step (js/coreui-image-progress.js).
img_gen_test(coreui_image_progress_meta(['phase' => 'sampling', 'step' => 2, 'steps' => 4, 'width' => 1024])
  === ['source' => 'image', 'reason' => 'image_sampling', 'step' => 2, 'steps' => 4, 'image_px' => 1024]
  && coreui_image_progress_meta(['phase' => 'save', 'step' => 4, 'steps' => 4, 'width' => 512])['reason'] === 'image_decode'
  && coreui_image_progress_meta(['phase' => '<x>'])['reason'] === 'image_load', 'progress data for the card');
foreach (['load', 'text', 'sampling', 'decode', 'x'] as $phase) {
  img_gen_test(preg_match('/^[a-zA-Z0-9 \-.,:()\/]{3,110}$/D', coreui_image_progress_title(['phase' => $phase, 'step' => 1, 'steps' => 4])) === 1,
    'the work store accepts the title of ' . $phase);
}
$ok = coreui_image_result_text(['ok' => true, 'width' => 512, 'height' => 512, 'seconds' => 62.6]);
img_gen_test(str_contains($ok, 'Das Bild ist fertig (512 × 512 Pixel, 63 Sekunden) und erscheint direkt unter deiner Antwort')
  && str_contains($ok, 'Du kannst es nicht sehen'), 'success: Mara says it is there and invents nothing');
$failed = coreui_image_result_text(['ok' => false, 'error' => 'image_loading', 'detail' => '42']);
img_gen_test(str_starts_with($failed, 'Es gibt KEIN Bild:') && str_contains($failed, '(42 Prozent)')
  && str_contains($failed, 'behaupte nicht, dass ein Bild da ist'), 'failure: no claimed image');

// --- Making an image against a stand-in service -----------------------------------------
function img_gen_png(int $w, int $h): string {
  $chunk = static fn(string $k, string $d): string => pack('N', strlen($d)) . $k . $d . pack('N', crc32($k . $d));
  $raw = str_repeat("\0" . str_repeat("\x10\x20\x30", $w), $h);
  return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}
img_gen_test(coreui_image_png_size(img_gen_png(64, 32)) === [64, 32] && coreui_image_png_size('nope') === null, 'PNG size');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE stu_console_media (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, user_id INT, character_id TEXT, kind TEXT,
  orig_name TEXT, stored_name TEXT, rel_path TEXT, public_url TEXT, mime_type TEXT, file_size INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE stu_console_message_attachments (message_id INT, media_uuid TEXT, user_id INT, position INT, created_at TEXT)');

$fake = static function (array $script) {
  $GLOBALS['img_calls'] = [];
  return static function (string $method, string $path, ?array $body = null, int $timeout = 10) use (&$script): array {
    $GLOBALS['img_calls'][] = [$method, $path, $body];
    foreach ($script as $i => [$m, $p, $answer]) {
      if ($m === $method && $p === $path) {
        if (is_array($answer) && array_is_list($answer) && isset($answer[0]) && is_array($answer[0]) && count($answer) > 1) {
          $script[$i][2] = array_slice($answer, 1);
          return $answer[0];
        }
        return is_array($answer) && array_is_list($answer) && isset($answer[0]) && is_array($answer[0]) ? $answer[0] : $answer;
      }
    }
    return [404, ['ok' => false, 'error' => 'not_found'], ''];
  };
};
$ready = [200, ['ok' => true, 'ready' => true, 'loading' => false], ''];
$jobId = str_repeat('c', 32);
$job = static fn(string $state, string $phase, int $step = 0) => [200, ['ok' => true, 'job' => ['id' => $jobId, 'state' => $state, 'phase' => $phase,
  'step' => $step, 'steps' => 4, 'width' => 512, 'height' => 512, 'seed' => 7, 'seconds' => 61.8, 'error' => '']], ''];
$leases = 0; $unleases = 0;
$lease = static function () use (&$leases) { $leases++; return 'db_lock'; };
$unlease = static function ($slot) use (&$unleases): void { $unleases++; };
$never = static fn(): bool => false;

// Not ready: no lease, the reason with its percent.
$http = $fake([['GET', '/health', [200, ['ok' => true, 'ready' => false, 'loading' => true, 'weights' => ['have' => 1, 'total' => 2]], '']]]);
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['ok'] === false && $r['error'] === 'image_loading' && $r['detail'] === '50' && $leases === 0, 'loading weights: no job, no queue');
$http = $fake([]);
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'image_unreachable' && $leases === 0, 'no service: no queue');
$http = $fake([['GET', '/health', $ready]]);
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', static fn() => false, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'model_queue_timeout' && count($GLOBALS['img_calls']) === 1, 'queue not free: no job started');
$r = coreui_image_generate($pdo, 5, 'ember', '   ', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'bad_prompt', 'empty description');
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'huge', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'bad_size', 'unknown size');

// Busy service: the lease is given back.
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [503, ['ok' => false, 'error' => 'image_busy'], '']]]);
$leases = $unleases = 0;
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'image_busy' && $leases === 1 && $unleases === 1, 'busy: queue given back');
// The reference is sent only for explicit images of the TCI, not for ordinary foxes.
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [503, ['ok' => false, 'error' => 'image_busy'], '']]]);
$r = coreui_image_generate($pdo, 5, 'ember', 'Mara as Superwoman', 'normal',
  $lease, $unlease, static function (): void {}, $never, $http, null, 'default');
img_gen_test($r['error'] === 'image_busy' && ($GLOBALS['img_calls'][1][2]['reference'] ?? null) === 'default'
  && str_starts_with((string)($GLOBALS['img_calls'][1][2]['prompt'] ?? ''), 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains((string)($GLOBALS['img_calls'][1][2]['prompt'] ?? ''), 'Mara as Superwoman'),
  'explicit self portrait anchors identity and routes canonical reference to the service');
$refs = ['upload:' . $idA . '.png', 'upload:' . $idB . '.jpg'];
img_gen_test(coreui_image_reference_prompt('plain spaceship', null, [], 1200) === 'plain spaceship',
  'no reference does not alter text-to-image prompts');
img_gen_test(coreui_image_reference_mode('Only the style of these images, not the subject', false, true) === 'style'
  && coreui_image_reference_mode('a watercolor of the same woman', false, true) === 'identity'
  && coreui_image_reference_mode('an old aircraft', false, true) === 'subject',
  'reference mode is style only when explicit, otherwise preserve individuals/subjects');
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [503, ['ok'=>false,'error'=>'image_busy'], '']]]);
$r = coreui_image_generate($pdo, 7, 'ember', 'two warriors fighting', 'normal',
  $lease, $unlease, static function (): void {}, $never, $http, null, null, $refs);
img_gen_test($r['error'] === 'image_busy'
  && ($GLOBALS['img_calls'][1][2]['references'] ?? null) === $refs
  && !isset($GLOBALS['img_calls'][1][2]['reference'])
  && str_starts_with((string)$GLOBALS['img_calls'][1][2]['prompt'], 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains((string)$GLOBALS['img_calls'][1][2]['prompt'], 'MULTI-REFERENCE:'),
  'two user uploads are forwarded in order, without adding Maras portrait');



// A whole image.
$png = img_gen_png(512, 512);
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [202, ['ok' => true, 'job' => $job('queued', 'load')[1]['job']], '']],
  ['GET', '/jobs/' . $jobId, [$job('running', 'sampling', 1), $job('running', 'sampling', 3), $job('done', 'done', 4)]],
  ['GET', '/jobs/' . $jobId . '/image', [200, null, $png]]]);
$titles = [];
$leases = $unleases = 0;
$r = coreui_image_generate($pdo, 5, 'ember', "A red fox,\n  sitting  in snow", 'normal', $lease, $unlease,
  static function (array $j) use (&$titles): void { $titles[] = coreui_image_progress_title($j); }, $never, $http);
img_gen_test($r['ok'] === true && preg_match('~^[a-f0-9]{32}$~', $r['uuid']) === 1 && $r['width'] === 512 && $r['seed'] === 7
  && $r['prompt'] === 'A red fox, sitting in snow', 'the image is made: ' . json_encode($r));
img_gen_test($titles === ['Bild: Gewichte werden geladen', 'Bild: Schritt 2 von 4', 'Bild: Schritt 4 von 4', 'Bild wird erzeugt'],
  'every change of phase and step is shown once: ' . json_encode($titles));
img_gen_test($leases === 1 && $unleases === 1, 'the queue is held for the job and given back');
img_gen_test($GLOBALS['img_calls'][1][2] === ['prompt' => 'A red fox, sitting in snow', 'size' => 'normal'], 'the service gets description and size only');
$row = $pdo->query('SELECT * FROM stu_console_media')->fetch(PDO::FETCH_ASSOC);
img_gen_test($row['uuid'] === $r['uuid'] && (int)$row['user_id'] === 5 && $row['kind'] === 'image' && $row['public_url'] === null
  && $row['rel_path'] === 'var/console_media/images/' . $r['uuid'] . '.png' && $row['mime_type'] === 'image/png'
  && str_starts_with($row['orig_name'], 'Mara-Bild-') && $row['character_id'] === 'ember',
  'kept as a private media entry of the account: ' . json_encode($row));
$kept = (string)file_get_contents($tmp . '/images/' . $r['uuid'] . '.png');
img_gen_test(coreui_image_png_size($kept) === [512, 512] && $kept !== $png && (int)$row['file_size'] === strlen($kept)
  && substr(sprintf('%o', fileperms($tmp . '/images/' . $r['uuid'] . '.png')), -4) === '0640', 'the PNG on disk with logo only and provenance, 0640');
$meta = coreui_image_meta($r['uuid']);
img_gen_test(($meta['prompt'] ?? '') === 'A red fox, sitting in snow' && ($meta['seconds'] ?? 0) == 61.8 && ($meta['model'] ?? '') === 'FLUX.2-klein 4B (Q8_0)'
  && ($meta['watermark'] ?? null) === true && ($meta['metadata_embedded'] ?? null) === true,
  'Private description and successful public PNG/XMP metadata next to it');

// Logo-only design: 11.5% of the width, 50% logo opacity, zero visible text.
img_gen_test(abs(COREUI_IMAGE_LOGO_WIDTH - 0.115) < 0.000001
  && COREUI_IMAGE_MARK_OPACITY === 0.5,
  'The only visible watermark stays 11.5% wide and 50% opaque');
$logoPath = $root . '/images/watermark_logo.png';
$logoDims = @getimagesize($logoPath);
img_gen_test(is_array($logoDims) && $logoDims[0] > 0 && $logoDims[1] > 0,
  'watermark_logo.png must ship with the image generator');
$sourceLogo = imagecreatefrompng($logoPath);
img_gen_test($sourceLogo !== false
  && ((imagecolorat($sourceLogo, 0, 0) >> 24) & 0x7f) === 127
  && ((imagecolorat($sourceLogo, imagesx($sourceLogo) - 1, imagesy($sourceLogo) - 1) >> 24) & 0x7f) === 127,
  'Transparent logo corners remain transparent');
$centreAlpha = (imagecolorat($sourceLogo, intdiv(imagesx($sourceLogo), 2), intdiv(imagesy($sourceLogo), 2)) >> 24) & 0x7f;
img_gen_test($centreAlpha > 0 && $centreAlpha < 127,
  'Logo native partial alpha is preserved');
foreach ([512, 1024] as $n) {
  $gray = imagecreatetruecolor($n, $n);
  imagefill($gray, 0, 0, imagecolorallocate($gray, 128, 128, 128));
  ob_start(); imagepng($gray); $plain = (string)ob_get_clean();
  $marked = coreui_image_watermark($plain);
  img_gen_test(is_string($marked) && coreui_image_png_size($marked) === [$n, $n],
    "logo-only watermark {$n}: dimensions stay identical");
  $im = imagecreatefromstring($marked);
  $margin = max(4, (int)round($n * COREUI_IMAGE_MARK_MARGIN));
  $logoW = (int)round($n * COREUI_IMAGE_LOGO_WIDTH);
  $logoH = (int)round($logoW * $logoDims[1] / $logoDims[0]);
  $logoX = $n - $logoW - $margin;
  $logoY = $n - $logoH - $margin;
  $right = 0;
  $outside = 0;
  for ($y = 0; $y < $n; $y++) {
    for ($x = 0; $x < $n; $x++) {
      $c = imagecolorat($im, $x, $y);
      if ((($c >> 16) & 255) === 128 && (($c >> 8) & 255) === 128 && ($c & 255) === 128) continue;
      if ($x >= $logoX && $x < $n - $margin && $y >= $logoY && $y < $n - $margin) ++$right;
      else ++$outside;
    }
  }
  img_gen_test($right >= 30, "logo-only watermark {$n}: visible logo in lower-right");
  img_gen_test($outside === 0, "logo-only watermark {$n}: no visible text or altered pixels elsewhere");
  $corner = imagecolorat($im, $logoX + 1, $logoY + 1);
  $delta = max(abs((($corner >> 16) & 255) - 128), abs((($corner >> 8) & 255) - 128),
    abs(($corner & 255) - 128));
  img_gen_test($delta <= 8, "logo-only watermark {$n}: no black rectangle");
  imagedestroy($gray);
  imagedestroy($im);
}
img_gen_test(coreui_image_watermark('no png') === null, 'invalid PNG cannot receive logo');
$markSource = (string)file_get_contents($root . '/api/image_gen.php');
img_gen_test(str_contains($markSource, "'/images/watermark_logo.png'")
  && !str_contains($markSource, 'imagettftext(')
  && !str_contains($markSource, 'coreui_image_mark_text')
  && !str_contains($markSource, 'removeDarkBackdrop')
  && !str_contains($markSource, 'cornerBright'),
  'Only the transparent Quantum logo is drawn, with no visible credit text');

// Standard PNG iTXt + XMP encode the provenance and Quantum CoreAI brand.
$public = coreui_image_provenance([
  'width'=>512,'height'=>512,'seed'=>7,'seconds'=>61.8,
  'size'=>'normal','reference_count'=>2,'created_at'=>'2026-11-08T12:30:00+00:00',
], $registry);
img_gen_test(($public['generated_by'] ?? null) === 'Quantum CoreAI: TCI Interface'
  && ($public['tci_identity'] ?? null) === 'TCI: Mara Thorne'
  && ($public['credit'] ?? null) === 'Erstellt mit Quantum CoreAI'
  && ($public['copyright_notice'] ?? null) === '© 2026 Quantum CoreAI'
  && ($public['model']['id'] ?? null) === 'flux2-klein-4b'
  && count($public['model']['components'] ?? []) === 3
  && ($public['generation']['seed'] ?? null) === 7
  && ($public['generation']['reference_image_count'] ?? null) === 2
  && $public['digital_source_type'] === COREUI_IMAGE_AI_SOURCE_TYPE,
  'Public provenance includes exact Quantum CoreAI brand and technical generation details');
$embedded = coreui_image_png_embed_provenance(img_gen_png(48, 48),
  ['width'=>48, 'height'=>48, 'seed'=>7,'seconds'=>61.8,
   'size'=>'normal','reference_count'=>2,'created_at'=>'2026-11-08T12:30:00+00:00',
   'prompt'=>'PRIVATE_USER_TEXT_DO_NOT_EXPORT','account_id'=>999,
   'reference'=>'PRIVATE_REFERENCE_DO_NOT_EXPORT'], $registry);
img_gen_test(is_string($embedded) && coreui_image_png_size($embedded) === [48, 48]
  && !str_contains($embedded, 'PRIVATE_USER_TEXT_DO_NOT_EXPORT')
  && !str_contains($embedded, 'PRIVATE_REFERENCE_DO_NOT_EXPORT')
  && !str_contains($embedded, '"account_id"'),
  'Embedded metadata preserves PNG size and excludes prompts and private identifiers');
$chunks = [];
for ($pos = 8, $len = strlen($embedded); $pos + 12 <= $len; ) {
  $size = unpack('Nlen', substr($embedded, $pos, 4))['len'];
  $type = substr($embedded, $pos + 4, 4);
  if ($size < 0 || $pos + 12 + $size > $len) break;
  if ($type === 'iTXt') {
    $text = substr($embedded, $pos + 8, $size);
    $sep = strpos($text, "\0");
    if ($sep !== false) $chunks[substr($text, 0, $sep)] = substr($text, $sep + 5);
  }
  $pos += 12 + $size;
  if ($type === 'IEND') break;
}
$details = json_decode((string)($chunks['Quantum CoreAI Provenance'] ?? ''), true);
img_gen_test(is_array($details)
  && ($chunks['Credit'] ?? '') === 'Erstellt mit Quantum CoreAI'
  && ($chunks['Description'] ?? '') === 'Erstellt mit Quantum CoreAI'
  && ($chunks['Author'] ?? '') === 'TCI: Mara Thorne'
  && ($chunks['TCI Identity'] ?? '') === 'TCI: Mara Thorne'
  && ($chunks['Digital Source Type'] ?? '') === COREUI_IMAGE_AI_SOURCE_TYPE
  && str_contains((string)($chunks['XML:com.adobe.xmp'] ?? ''), 'Iptc4xmpExt:DigitalSourceType')
  && str_contains((string)($chunks['XML:com.adobe.xmp'] ?? ''), 'Quantum CoreAI')
  && str_contains((string)($chunks['XML:com.adobe.xmp'] ?? ''), '<dc:creator><rdf:Seq><rdf:li>TCI: Mara Thorne</rdf:li>')
  && ($details['tci_identity'] ?? null) === 'TCI: Mara Thorne'
  && ($details['generation']['seed'] ?? null) === 7,
  'PNG iTXt and IPTC XMP carry readable Quantum CoreAI source and model information');
img_gen_test(coreui_image_png_embed_provenance('not a png', [], $registry) === null,
  'Metadata writer rejects invalid PNG');

img_gen_test(coreui_image_meta('../x') === null, 'no paths as image names');

// 0.6.42: step previews go to the chat as small data URLs, each new one once, never after the job.
img_gen_test(coreui_image_preview_data_url('no png') === null && coreui_image_preview_data_url(img_gen_png(2, 2)) === null,
  'no PNG or a tiny one: no preview');
$small = coreui_image_preview_data_url(img_gen_png(48, 48));
img_gen_test(is_string($small) && str_starts_with($small, 'data:image/png;base64,')
  && coreui_image_png_size((string)base64_decode(substr($small, 22))) === [48, 48], 'a small preview stays as it is');
$scaled = coreui_image_preview_data_url(img_gen_png(128, 128));
img_gen_test(is_string($scaled) && coreui_image_png_size((string)base64_decode(substr($scaled, 22))) === [64, 64], 'a large preview is scaled to 64');
$withPreview = static fn(string $state, int $step, int $preview) => [200, ['ok' => true, 'job' => ['id' => $jobId, 'state' => $state,
  'phase' => $state === 'done' ? 'done' : 'sampling', 'step' => $step, 'steps' => 4, 'width' => 512, 'height' => 512, 'seed' => 7,
  'seconds' => 60.0, 'error' => '', 'preview' => $preview]], ''];
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [202, ['ok' => true, 'job' => $job('queued', 'load')[1]['job']], '']],
  ['GET', '/jobs/' . $jobId, [$withPreview('running', 1, 1), $withPreview('running', 1, 1), $withPreview('running', 2, 2), $withPreview('done', 4, 4)]],
  ['GET', '/jobs/' . $jobId . '/preview', [200, null, img_gen_png(64, 64)]],
  ['GET', '/jobs/' . $jobId . '/image', [200, null, img_gen_png(512, 512)]]]);
$shots = [];
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http,
  static function (string $url, array $j) use (&$shots): void { $shots[] = [(int)$j['preview'], strlen($url) > 30]; });
$asked = array_values(array_filter($GLOBALS['img_calls'], static fn(array $c): bool => str_ends_with($c[1], '/preview')));
img_gen_test($r['ok'] === true && $shots === [[1, true], [2, true]] && count($asked) === 2,
  'every new preview once, none after the job: ' . json_encode($shots));

// Cancelled during the job: the service is told, the queue given back.
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [202, ['ok' => true, 'job' => $job('queued', 'load')[1]['job']], '']],
  ['GET', '/jobs/' . $jobId, $job('running', 'sampling', 1)], ['DELETE', '/jobs/' . $jobId, [200, ['ok' => true], '']]]);
$asked = 0;
$leases = $unleases = 0;
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {},
  static function () use (&$asked): bool { return ++$asked >= 3; }, $http);
img_gen_test($r['error'] === 'cancelled' && in_array(['DELETE', '/jobs/' . $jobId, null], $GLOBALS['img_calls'], true) && $unleases === 1, 'cancel stops the job');

// A PNG of the wrong size is no result.
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [202, ['ok' => true, 'job' => $job('done', 'done', 4)[1]['job']], '']],
  ['GET', '/jobs/' . $jobId . '/image', [200, null, img_gen_png(64, 64)]]]);
$count = (int)$pdo->query('SELECT COUNT(*) FROM stu_console_media')->fetchColumn();
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'image_failed' && (int)$pdo->query('SELECT COUNT(*) FROM stu_console_media')->fetchColumn() === $count,
  'a wrong PNG is not kept');
$http = $fake([['GET', '/health', $ready], ['POST', '/jobs', [202, ['ok' => true, 'job' => $job('failed', 'sampling', 1)[1]['job']], '']]]);
$r = coreui_image_generate($pdo, 5, 'ember', 'a fox', 'normal', $lease, $unlease, static function (): void {}, $never, $http);
img_gen_test($r['error'] === 'image_failed', 'a failed job is reported');

// --- Pending, attachments, history ------------------------------------------------------
unset($GLOBALS['COREUI_PENDING_IMAGES']);
$uuid = (string)$row['uuid'];
coreui_image_pending_add($uuid);
coreui_image_pending_add($uuid);
coreui_image_pending_add('../../etc/passwd');
img_gen_test(coreui_image_pending_take() === [$uuid] && coreui_image_pending_take() === [], 'pending images: once, valid ids only');
$pdo->prepare('INSERT INTO stu_console_message_attachments (message_id, media_uuid, user_id, position) VALUES (?,?,?,0)')->execute([77, $uuid, 5]);
$attachments = coreui_image_message_attachments($pdo, 5, 77);
img_gen_test(count($attachments) === 1 && $attachments[0]['uuid'] === $uuid && $attachments[0]['kind'] === 'image'
  && $attachments[0]['generated'] === true && $attachments[0]['image_url'] === null
  && $attachments[0]['url'] === 'api/console_media.php?uuid=' . $uuid, 'the answer carries the image: ' . json_encode($attachments));
img_gen_test(coreui_image_message_attachments($pdo, 6, 77) === [], 'another account sees nothing');
$notes = coreui_image_history_notes($pdo, 5, [77, 78]);
img_gen_test(($notes[77] ?? '') === '512 × 512: "A red fox, sitting in snow"' && !isset($notes[78]), 'the history note: what Mara painted');
// 0.6.40: one block after the conversation, not inside Mara's own lines (she copied the line into a new
// answer instead of painting, operator's screenshot 07.11.2026).
$block = coreui_image_history_block($notes);
img_gen_test(str_starts_with($block, '--- BILDER, DIE DU IN DIESEM CHAT SCHON GEMALT HAST') && str_contains($block, "\n- 512 × 512: \"A red fox, sitting in snow\"\n")
  && str_contains($block, 'Ein NEUES Bild entsteht nur, wenn du mit dem Marker [BILD: ...] antwortest.')
  && coreui_image_history_block([]) === '', 'the history block');
img_gen_test(substr_count(coreui_image_history_block(['a', 'b', 'c', 'd', 'e', 'f', 'g']), "\n- ") === 5, 'at most the last five images');

// 0.6.40: what Mara wrote instead of an image is taken out of her answer (operator's screenshots, 07.11.2026).
$voice = "Hallo Rick! 👋\n\nOkay, eine Wildkatze im Wald. Gerne versuche ich, das für dich umzusetzen! 🐆🌿\n\n"
  . "*(Hier erscheint das Bild der Wildkatze im Wald) (Von dir erzeugtes Bild (512 × 512): \"A sleek wild cat sitting stealthily in a dense forest.\")";
img_gen_test(coreui_image_strip_placeholders($voice) === "Hallo Rick! 👋\n\nOkay, eine Wildkatze im Wald. Gerne versuche ich, das für dich umzusetzen! 🐆🌿",
  'a copied history line and a placeholder go');
$typed = "Hier ist deine Katze im Schnee. Ich hoffe, das Bild gefällt dir! 😊\n\n*(Hier erscheint das Bild der Katze im Schnee)";
img_gen_test(coreui_image_strip_placeholders($typed) === 'Hier ist deine Katze im Schnee. Ich hoffe, das Bild gefällt dir! 😊', 'a placeholder line goes');
foreach (["Hier ist dein Bild! Ein Fuchs im Schnee.", "(Das Bild zeigt einen Fuchs.)", "Ich mag Bilder (hier ist eins von mir).",
    "[BILD: a red fox in the snow]", "Kein Thema."] as $keep) {
  img_gen_test(coreui_image_strip_placeholders($keep) === $keep, 'stays: ' . $keep);
}

// --- Deleting a chat removes the image and its description ------------------------------
require_once $root . '/api/console_session_store.php';
$realDir = $root . '/var/console_media/images';
$madeDir = !is_dir($realDir);
if ($madeDir) mkdir($realDir, 0750, true);
$probe = bin2hex(random_bytes(16));
file_put_contents("{$realDir}/{$probe}.png", 'png');
file_put_contents("{$realDir}/{$probe}.json", '{}');
$deleted = coreui_console_session_delete_media_files(["var/console_media/images/{$probe}.png", 'var/console_media/images/../../x.png']);
img_gen_test(!is_file("{$realDir}/{$probe}.png") && !is_file("{$realDir}/{$probe}.json") && $deleted['files_deleted'] === 2
  && $deleted['files_failed'] === 1, 'a deleted chat takes the image and its description, nothing outside: ' . json_encode($deleted));
if ($madeDir) { @rmdir($realDir); @rmdir(dirname($realDir)); }

// --- Wiring -----------------------------------------------------------------------------
$read = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$chat = $read('api/chat.php');
img_gen_test(substr_count($chat, 'coreui_image_tool_for_request(') === 2
  && str_contains($chat, "if (is_array(\$clip) && !empty(\$clip['voice']))"),
  'reference safety net is wired into chat and genuine voice turns, not arbitrary files');
foreach (["require_once __DIR__ . '/image_gen.php';", "if (\$sessionId !== '' && ember_image_allowed(\$uid)) \$tools[] = 'image';",
    "if (\$tool['tool'] === 'image') {", "\$imageHint = ember_image_hint(\$channel, \$imageUrl, \$profile['compact'], \$consoleUid);",
    "\$imageHint = ember_image_hint(\$channel, \$imageUrl, \$promptProfile['compact'], \$consoleUid);",
    "array_intersect(\$loopAllowed, ['web', 'archive', 'skill', 'image'])", "foreach ([\$webHint ?? '', \$browseHint ?? '', \$pyHint ?? '', \$imageHint ?? '', \$intentHint ?? ''] as \$hint) {",
    "coreui_console_attachment_store(\$pdo, \$insertedId, \$uid, coreui_image_pending_take());",
    "\$slot = ember_global_lock_acquire(600);", "coreui_model_queue_mark_long(\$connection, ember_lock_name('global_ollama_image'));",
    "ember_lock_name('global_ollama_image')\n    );", "coreui_image_history_notes(\$pdo, \$consoleUid,",
    '|\[\s*BILD(?:\s+GRO(?:SS|ß))?(?:\s+ICH)?\s*:[^\[\]]*\]|'] as $needle) {
  img_gen_test(str_contains($chat, $needle), 'chat.php: ' . $needle);
}
img_gen_test(substr_count($chat, "   . (\$imageHint !== '' ? (\$imageHint . \"\\n\\n\") : \"\")") === 2, 'the hint in both prompts');
$guest = strpos($read('api/image_gen.php'), "coreui_ai_account_is_guest(\$pdo, \$uid)) return false;");
img_gen_test($guest !== false, 'guests never make images');
img_gen_test(str_contains($chat, "  \$content = coreui_image_strip_placeholders(\$content);\n")
  && strpos($chat, '$content = coreui_image_strip_placeholders($content);') > strpos($chat, 'function ember_sanitize_public_reply('),
  'every public answer (and Mara\'s lines in the history) loses placeholders');
img_gen_test(str_contains($chat, "\$imageBlock = coreui_image_history_block(\$imageNotes);")
  && !str_contains($chat, "\$msg = trim(\$msg . ' (' . \$imageNotes["), 'the image notes stand as one block, not in Mara\'s lines');
img_gen_test(str_contains($chat, "(string)\$consoleSessionId), ['web', 'browse', 'archive', 'image']))")
  && str_contains($chat, "coreui_agent_next_tool(\$imgReply, ['web', 'browse', 'archive', 'skill', 'image']) !== null"),
  'after a voice message Mara may paint too');
$stream = $read('api/console_stream.php');
img_gen_test(str_contains($chat, "sse_send('image_preview', ['image' => \$image, 'step' => (int)(\$job['step'] ?? 0), 'steps' => (int)(\$job['steps'] ?? 0)]);"),
  '0.6.42: previews go to the live stream of this request');
// 0.6.43: the first check also takes a tool chosen in the + menu (composer-intent-selftest.php).
img_gen_test(substr_count($stream, '|| ember_image_marker_in($reply, (int)$uid)') === 2
  && substr_count($stream, '|| ember_image_marker_in($reply, (int)$uid);') === 1
  && str_contains($stream, "|| ember_image_marker_in(\$reply, (int)\$uid)\n            || (\$composerIntentStream !== null && !\$isVisionTurn && !\$fileTaskHandled);")
  && str_contains($stream, "'attachments'   => coreui_image_message_attachments(\$pdo, (int)\$uid, \$emberMsgId),"),
  'the stream hands an image wish to the work loop and sends the image with the answer');
img_gen_test(str_contains($read('api/console_session_store.php'), "\$allowedPrefixes = ['var/console_media/images/', 'var/console_media/',"),
  'chat and account deletion know the image folder');
img_gen_test(str_contains($read('api/console_messages.php'), "'generated' => str_starts_with((string)(\$media['rel_path'] ?? ''), 'var/console_media/images/'),"),
  'the history marks Mara\'s images');
img_gen_test(str_contains($read('database/migrations/026_image_tool.sql'), 'ADD COLUMN IF NOT EXISTS tool_image TINYINT(1) NOT NULL DEFAULT 1 AFTER tool_python;'),
  'migration 026');
$policy = $read('api/user_policy.php');
img_gen_test(str_contains($policy, "const COREUI_USER_POLICY_TOOLS = ['web', 'browse', 'python', 'image'];")
  && str_contains($policy, "UPDATE stu_user_policies SET tool_image = ? WHERE user_id = ?"), 'per account switch');
img_gen_test(str_contains($read('api/ai_settings.php'), "'image' => stu_app_setting_get(\$pdo, 'tool_image_enabled',")
  && str_contains($read('api/admin.php'), "'tool_web_enabled', 'tool_browse_enabled', 'tool_python_enabled', 'tool_image_enabled',"),
  'global switch under Betrieb');
$settings = $read('settings.html');
img_gen_test(str_contains($settings, '<strong>BILDER ERZEUGEN</strong>') && str_contains($settings, 'id="toolImageEnabled"')
  && strpos($settings, 'id="toolImageEnabled"') > strpos($settings, 'id="toolPythonEnabled"'), 'the switch sits with the other tools');
$admin = $read('js/admin.js');
img_gen_test(str_contains($admin, "['image', 'Bilder']") && str_contains($admin, "tool_image_enabled: document.getElementById('toolImageEnabled').checked ? '1' : '0',"),
  'admin page saves both switches');
$work = $read('js/coreui-work-events.js');
img_gen_test(str_contains($work, "if (/^Bild: /.test(String(event.title))) return 'Ich male · ' + String(event.title).slice(6);")
  && str_contains($work, "return 'Das Bild ist fertig und hängt an meiner Antwort.';"), 'the work view speaks about the image');

// --- Queue: waiting behind an image (database) ------------------------------------------
$dbWanted = getenv('COREUI_SELFTEST_DB') === '1';
if ($dbWanted) {
  require_once $root . '/api/config.php';
  require_once $root . '/api/helpers.php';
  require_once $root . '/api/db.php';
  $a = stu_pdo();
  img_gen_test(coreui_model_queue_schema_ready($a), 'model queue table');
  $dsn = 'mysql:host=' . STU_DB_HOST . ';port=' . (int)STU_DB_PORT . ';dbname=' . STU_DB_NAME . ';charset=utf8mb4';
  $b = new PDO($dsn, STU_DB_USER, STU_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $lock = 'coreui_selftest:' . bin2hex(random_bytes(4));
  $long = $lock . '_image';
  $ticket = coreui_model_queue_acquire($a, $lock, 5, null, $long);
  img_gen_test(is_string($ticket), 'the image takes the queue');
  img_gen_test(coreui_model_queue_mark_long($a, $long) && coreui_model_queue_long_job_running($b, $long), 'and marks it as long');
  $started = microtime(true);
  // The image is done after four seconds: released from inside the waiting loop (one process, two connections).
  $releaseAt = $started + 4.0;
  $released = false;
  $cancel = static function () use (&$released, $releaseAt, $a, $lock, $long, $ticket): bool {
    if (!$released && microtime(true) >= $releaseAt) {
      coreui_model_queue_unmark_long($a, $long);
      coreui_model_queue_release($a, $lock, $ticket);
      $released = true;
    }
    return false;
  };
  $second = coreui_model_queue_acquire($b, $lock, 1, $cancel, $long);
  $waited = microtime(true) - $started;
  img_gen_test(is_string($second) && $waited >= 3.5, 'a call with a 1 s limit waited ' . round($waited, 1) . ' s behind the image and then ran');
  coreui_model_queue_release($b, $lock, $second);
  // Without an image the usual limit holds.
  $third = coreui_model_queue_acquire($a, $lock, 5, null, $long);
  $started = microtime(true);
  $fourth = coreui_model_queue_acquire($b, $lock, 1, null, $long);
  img_gen_test($fourth === false && microtime(true) - $started < 3.0, 'behind a normal call the usual limit holds');
  coreui_model_queue_release($a, $lock, $third);
  $a->exec("DELETE FROM stu_console_model_queue WHERE finished_at IS NOT NULL AND ticket_id IN ('" . implode("','", array_filter([$ticket, $second, $third], 'is_string')) . "')");
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($tmp);

echo 'CoreAI images in chat: marker, work loop step, hint, progress, result, making and keeping an image, cancel, history, '
  . 'attachments, deletion, switches and wiring OK' . ($dbWanted ? ', waiting behind an image (database) OK' : ' (no database here)') . "\n";
