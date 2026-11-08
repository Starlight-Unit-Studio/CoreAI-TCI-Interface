<?php
declare(strict_types=1);

/**
 * Self-contained PNG metadata writer for generated CoreAI images.
 *
 * PNG iTXt and XMP are standard, editable metadata (not a cryptographic C2PA
 * signature). They are inserted immediately after IHDR without re-encoding
 * pixels, preserving the finished logo-only image byte-for-byte otherwise.
 * Only an explicit allowlist enters exported image metadata: never prompts,
 * reference identifiers, private names, account IDs, paths or credentials.
 */
const COREUI_IMAGE_AI_SOURCE_TYPE = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
const COREUI_IMAGE_PROVENANCE_FORMAT = 'quantum-coreai-image-provenance/1';
const COREUI_IMAGE_TCI_IDENTITY = 'TCI: Mara Thorne'; // Technical identity, independent of display aliases.

function coreui_image_png_chunk(string $type, string $payload): string {
  if (strlen($type) !== 4) throw new InvalidArgumentException('invalid_png_chunk');
  return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
}

/** Uncompressed UTF-8 PNG iTXt with empty language and translated-keyword fields. */
function coreui_image_png_itxt(string $keyword, string $text): string {
  if (strlen($keyword) < 1 || strlen($keyword) > 79
      || preg_match('/[^\x20-\x7e]/', $keyword) === 1) {
    throw new InvalidArgumentException('invalid_png_metadata_keyword');
  }
  return coreui_image_png_chunk('iTXt', $keyword . "\0\0\0\0\0" . $text);
}

function coreui_image_provenance_version(): string {
  $version = @file_get_contents(dirname(__DIR__) . '/VERSION');
  $version = is_string($version) ? trim($version) : '';
  return preg_match('~^[0-9]+\.[0-9]+\.[0-9]+$~D', $version) === 1 ? $version : 'unknown';
}

/**
 * Build externally safe, machine-readable generation details. The existing
 * private JSON sidecar remains the source for full prompts and account data.
 */
function coreui_image_provenance(array $meta, array $registry = []): array {
  $date = (string)($meta['created_at'] ?? '');
  if (preg_match('~^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$~D', $date) !== 1) {
    $date = gmdate('Y-m-d\TH:i:s+00:00');
  }
  $year = (int)substr($date, 0, 4);
  $year = $year >= 2000 && $year <= 2100 ? $year : (int)gmdate('Y');
  $model = is_array($registry['model'] ?? null) ? $registry['model'] : [];
  $runtime = is_array($registry['runtime'] ?? null) ? $registry['runtime'] : [];
  $components = [];
  foreach (($registry['files'] ?? []) as $file) {
    if (!is_array($file) || !in_array($file['role'] ?? '', ['diffusion_model', 'llm', 'vae'], true)) continue;
    $components[] = [
      'role'=>(string)$file['role'],
      'repository'=>(string)($file['repo'] ?? ''),
      'revision'=>(string)($file['revision'] ?? ''),
      'sha256'=>(string)($file['sha256'] ?? ''),
      'model_license'=>(string)($file['license'] ?? ''),
    ];
  }
  return [
    'format'=>COREUI_IMAGE_PROVENANCE_FORMAT,
    'generated_by'=>'Quantum CoreAI: TCI Interface',
    'tci_identity'=>COREUI_IMAGE_TCI_IDENTITY,
    'software_version'=>coreui_image_provenance_version(),
    'publisher'=>'Starlight Unit Studios',
    'credit'=>'Erstellt mit Quantum CoreAI',
    'copyright_notice'=>'© ' . $year . ' Quantum CoreAI',
    'ai_generated'=>true,
    'digital_source_type'=>COREUI_IMAGE_AI_SOURCE_TYPE,
    'created_at_utc'=>$date,
    'image'=>[
      'format'=>'PNG', 'width_px'=>max(0, (int)($meta['width'] ?? 0)),
      'height_px'=>max(0, (int)($meta['height'] ?? 0)),
    ],
    'model'=>[
      'id'=>(string)($model['id'] ?? ''),
      'label'=>(string)($meta['model'] ?? $model['label'] ?? ''),
      'model_license'=>(string)($model['license'] ?? ''),
      'components'=>$components,
    ],
    'runtime'=>[
      'name'=>(string)($runtime['name'] ?? ''),
      'version'=>(string)($runtime['tag'] ?? ''),
      'commit'=>(string)($runtime['commit'] ?? ''),
    ],
    'generation'=>[
      'seed'=>max(0, (int)($meta['seed'] ?? 0)),
      'steps'=>max(0, (int)($meta['steps'] ?? $model['steps'] ?? 0)),
      'cfg_scale'=>(float)($meta['cfg_scale'] ?? $model['cfg_scale'] ?? 0),
      'duration_seconds'=>round(max(0, (float)($meta['seconds'] ?? 0)), 1),
      'size_preset'=>(string)($meta['size'] ?? ''),
      'reference_image_count'=>max(0, (int)($meta['reference_count'] ?? 0)),
    ],
    'watermark'=>[
      'visible'=>'logo_only',
      'logo_width_percent'=>11.5,
      'opacity'=>0.5,
    ],
    'privacy'=>[
      'prompt_embedded'=>false, 'reference_ids_embedded'=>false,
      'account_data_embedded'=>false,
    ],
  ];
}

/** Interoperable XMP for credit, timestamp, AI source type and generator. */
function coreui_image_provenance_xmp(array $data): string {
  $escape = static fn(string $value): string =>
    htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $copyright = $escape((string)$data['copyright_notice']);
  $version = $escape((string)$data['software_version']);
  $date = $escape((string)$data['created_at_utc']);
  $credit = $escape((string)$data['credit']);
  $producer = $escape((string)$data['publisher']);
  $tciIdentity = $escape((string)$data['tci_identity']);
  $sourceType = $escape((string)$data['digital_source_type']);
  return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
    . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
    . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" '
    . 'xmlns:xmp="http://ns.adobe.com/xap/1.0/" '
    . 'xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" '
    . 'xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
    . '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Quantum CoreAI Bild</rdf:li></rdf:Alt></dc:title>'
    . '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">Erstellt mit Quantum CoreAI</rdf:li></rdf:Alt></dc:description>'
    . '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . $copyright . '</rdf:li></rdf:Alt></dc:rights>'
    . '<dc:publisher><rdf:Seq><rdf:li>' . $producer . '</rdf:li></rdf:Seq></dc:publisher>'
    . '<dc:creator><rdf:Seq><rdf:li>' . $tciIdentity . '</rdf:li></rdf:Seq></dc:creator>'
    . '<photoshop:Credit>' . $credit . '</photoshop:Credit>'
    . '<xmp:CreatorTool>Quantum CoreAI ' . $version . '</xmp:CreatorTool>'
    . '<xmp:CreateDate>' . $date . '</xmp:CreateDate>'
    . '<Iptc4xmpExt:DigitalSourceType>' . $sourceType . '</Iptc4xmpExt:DigitalSourceType>'
    . '</rdf:Description></rdf:RDF></x:xmpmeta>' . "\n"
    . '<?xpacket end="w"?>';
}

/**
 * Insert standards-compliant PNG iTXt chunks after IHDR. Unlike GD resaving,
 * this operation leaves IDAT and image pixels untouched.
 */
function coreui_image_png_embed_provenance(string $png, array $meta, array $registry = []): ?string {
  if (strlen($png) < 45 || strlen($png) > 25 * 1024 * 1024
      || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n"
      || substr($png, 8, 8) !== pack('N', 13) . 'IHDR') return null;
  try {
    $data = coreui_image_provenance($meta, $registry);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $chunks = coreui_image_png_itxt('Title', 'Quantum CoreAI Bild')
      . coreui_image_png_itxt('Description', 'Erstellt mit Quantum CoreAI')
      . coreui_image_png_itxt('Software', 'Quantum CoreAI ' . $data['software_version'])
      . coreui_image_png_itxt('Creation Time', $data['created_at_utc'])
      . coreui_image_png_itxt('Copyright', $data['copyright_notice'])
      . coreui_image_png_itxt('Credit', $data['credit'])
      . coreui_image_png_itxt('Author', $data['tci_identity'])
      . coreui_image_png_itxt('TCI Identity', $data['tci_identity'])
      . coreui_image_png_itxt('Digital Source Type', $data['digital_source_type'])
      . coreui_image_png_itxt('Quantum CoreAI Provenance', $json)
      . coreui_image_png_itxt('XML:com.adobe.xmp', coreui_image_provenance_xmp($data));
    if (strlen($png) + strlen($chunks) > 25 * 1024 * 1024) return null;
    // The first PNG chunk is the required 13-byte IHDR: 8 + 12 + 13 = 33.
    return substr($png, 0, 33) . $chunks . substr($png, 33);
  } catch (Throwable $e) {
    return null;
  }
}
