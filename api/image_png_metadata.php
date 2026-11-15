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
const COREUI_IMAGE_AI_EDITED_SOURCE_TYPE = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';
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

/**
 * Visible disclosures only for explicit disclosure/deepfake requests and the
 * actual editor route. A reference used to generate a NEW scene is not itself
 * evidence of image editing. Publishers remain responsible for other cases.
 */
function coreui_image_disclosure_for_request(string $userText, bool $editorFlow = false): string {
  if ($editorFlow) return 'modified';
  if (preg_match('~(?:deep[ -]?fake|fakefoto|gefälschtes[ ]+(?:foto|bild)'
      . '|(?:ki|ai)[ -]?(?:kennzeichnen|kennzeichnung|label|hinweis)'
      . '|(?:label|kennzeichne)[ ]+(?:das[ ]+)?(?:bild[ ]+)?(?:als[ ]+)?(?:ki|ai))~iu',
      $userText) === 1) return 'generated';
  return 'none';
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
  $kind = ($meta['content_kind'] ?? 'generated') === 'modified' ? 'modified' : 'generated';
  $requestedDisclosure = (string)($meta['visible_disclosure'] ?? 'none');
  $disclosure = $requestedDisclosure === $kind ? $kind : 'none';
  $source = $kind === 'modified' ? COREUI_IMAGE_AI_EDITED_SOURCE_TYPE : COREUI_IMAGE_AI_SOURCE_TYPE;
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
  $encoder = (string)($meta['text_encoder'] ?? 'original');
  if ($encoder === 'ponpoke-uncensored-q8_0'
      && preg_match('~^ponpoke/flux2-klein-(?:4b|9b)-uncensored-text-encoder$~D', (string)($meta['encoder_source'] ?? '')) === 1
      && preg_match('~^[0-9a-f]{40}$~D', (string)($meta['encoder_revision'] ?? '')) === 1
      && preg_match('~^[0-9a-f]{64}$~D', (string)($meta['encoder_sha256'] ?? '')) === 1) {
    foreach ($components as &$component) {
      if ($component['role'] === 'llm') {
        $component['repository'] = (string)$meta['encoder_source'];
        $component['revision'] = (string)$meta['encoder_revision'];
        $component['sha256'] = (string)$meta['encoder_sha256'];
        $component['model_license'] = 'FLUX Non-Commercial License v2.1';
      }
    }
    unset($component);
  }
  return [
    'format'=>COREUI_IMAGE_PROVENANCE_FORMAT,
    'generated_by'=>'Quantum CoreAI: TCI Interface',
    'tci_identity'=>COREUI_IMAGE_TCI_IDENTITY,
    'software_version'=>coreui_image_provenance_version(),
    'publisher'=>'Starlight Unit Studios',
    'credit'=>'Erstellt mit Quantum CoreAI',
    'copyright_notice'=>'© ' . $year . ' Quantum CoreAI',
    'ai_generated'=>$kind === 'generated',
    'ai_modified'=>$kind === 'modified',
    'content_kind'=>$kind,
    'visible_disclosure'=>$disclosure,
    'digital_source_type'=>$source,
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
      'text_encoder'=>$encoder,
    ],
    'watermark'=>[
      'visible'=>$disclosure === 'none' ? 'su_logo_only' : 'su_logo_with_ai_disclosure',
      'logo_width_percent'=>defined('COREUI_IMAGE_LOGO_WIDTH') ? COREUI_IMAGE_LOGO_WIDTH * 100 : 13.0,
      'opacity'=>defined('COREUI_IMAGE_MARK_OPACITY') ? COREUI_IMAGE_MARK_OPACITY : 0.85,
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
  $kind = $escape((string)$data['content_kind']);
  $disclosure = $escape((string)$data['visible_disclosure']);
  return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
    . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
    . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" '
    . 'xmlns:xmp="http://ns.adobe.com/xap/1.0/" '
    . 'xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" '
    . 'xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" '
    . 'xmlns:coreai="https://starlight-unit.de/ns/coreai/1.0/">'
    . '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Quantum CoreAI Bild</rdf:li></rdf:Alt></dc:title>'
    . '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">Erstellt mit Quantum CoreAI</rdf:li></rdf:Alt></dc:description>'
    . '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . $copyright . '</rdf:li></rdf:Alt></dc:rights>'
    . '<dc:publisher><rdf:Seq><rdf:li>' . $producer . '</rdf:li></rdf:Seq></dc:publisher>'
    . '<dc:creator><rdf:Seq><rdf:li>' . $tciIdentity . '</rdf:li></rdf:Seq></dc:creator>'
    . '<photoshop:Credit>' . $credit . '</photoshop:Credit>'
    . '<xmp:CreatorTool>Quantum CoreAI ' . $version . '</xmp:CreatorTool>'
    . '<xmp:CreateDate>' . $date . '</xmp:CreateDate>'
    . '<Iptc4xmpExt:DigitalSourceType>' . $sourceType . '</Iptc4xmpExt:DigitalSourceType>'
    . '<coreai:ContentKind>' . $kind . '</coreai:ContentKind>'
    . '<coreai:VisibleDisclosure>' . $disclosure . '</coreai:VisibleDisclosure>'
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
      . coreui_image_png_itxt('AI Content Kind', $data['content_kind'])
      . coreui_image_png_itxt('AI Disclosure', $data['visible_disclosure'])
      . coreui_image_png_itxt('Quantum CoreAI Provenance', $json)
      . coreui_image_png_itxt('XML:com.adobe.xmp', coreui_image_provenance_xmp($data));
    if (strlen($png) + strlen($chunks) > 25 * 1024 * 1024) return null;
    // The first PNG chunk is the required 13-byte IHDR: 8 + 12 + 13 = 33.
    return substr($png, 0, 33) . $chunks . substr($png, 33);
  } catch (Throwable $e) {
    return null;
  }
}
