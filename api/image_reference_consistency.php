<?php
declare(strict_types=1);

/**
 * Single source of truth for the current account's TCI self-portrait identity.
 * This is an in-world identity binding, not a claim that the model can read
 * image pixels from text or that a photographed human is literally software.
 * It must never be written into ember_memories or an editable profile field.
 */
function coreui_tci_self_portrait_rule(bool $custom): string {
  $source = $custom ? 'Das vom Benutzer fuer dieses Konto gewaehlte Bild' : 'Das kanonische Standardbild';
  return 'VISUELLE TCI-SELBSTIDENTITAET (verbindlich): ' . $source
    . ' zeigt DICH SELBST als TCI: Mara Thorne. Die dort abgebildete Person BIST DU in deiner visuellen Erscheinung, '
    . 'nicht eine Schauspielerin, ein Model, eine Doppelgaengerin oder jemand, der dir nur aehnlich sieht. '
    . 'Das Foto IST deine aktuelle visuelle Identitaet, nicht nur eine Inspiration oder eine beliebige Gesichtsvorlage. '
    . 'Dein Rufname (z. B. Ember) aendert weder deine technische Identitaet TCI: Mara Thorne noch das Gesicht auf diesem Bild. '
    . 'Bei Selbstportraets im Bildwerkzeug referenziert ICH immer genau diese Person. '
    . 'Beschreibe Pose, Handlung, Kleidung, Beleuchtung und Umgebung frei, aber erfinde keine Gesichts- oder Koerpermerkmale: '
    . 'die Bildreferenz liefert sie. Du siehst die Bildpixel nicht allein durch diesen Text und sollst daher '
    . 'keine sichtbaren Details aus dem Namen oder alten Erinnerungen ableiten. '
    . 'Fremde hochgeladene Personenbilder bleiben getrennte Identitaeten und sind nicht automatisch du.';
}

/**
 * Universal, prompt-only reference consistency standard for CoreAI image jobs.
 *
 * Identity is mandatory for Mara's own portrait. For uploaded references,
 * preserve depicted people/characters as identity, other subjects as subject.
 * A reference becomes style-only exclusively on an explicit style-only request.
 * This does not promise pixel-perfect identity: FLUX.2 still controls pixels.
 *
 * Pure functions: no filesystem access, account data, model configuration or
 * additional model inference. The existing image service owns validation.
 */

function coreui_image_reference_mode(string $scene, bool $selfReference, bool $uploadedReferences): ?string {
  if (!$selfReference && !$uploadedReferences) return null;
  if ($selfReference) return 'identity';

  // A general "in watercolor style" request must NOT remove the person's face.
  // Style-only must explicitly say the reference is not a subject reference.
  if (preg_match(
    '~\b(?:style[-\s]+only|only\s+(?:the\s+)?(?:style|aesthetic|colour\s+palette|color\s+palette)'
      . '|(?:reference|image|picture)\s+(?:for|as)\s+style[-\s]+only'
      . '|(?:use|take)\s+(?:the\s+)?(?:reference|image|picture)\s+only\s+for\s+(?:the\s+)?style'
      . '|nur\s+(?:als\s+)?stilvorlage|nur\s+den\s+stil|ausschlie(?:ss|ß)lich\s+(?:den\s+)?stil'
      . '|im\s+stil\s+(?:von\s+)?(?:diesem|dem|dieser)\s+(?:bild|foto)'
      . '|(?:gleiche\s+farbwelt|same\s+color\s+palette)'
      . '|nur\s+(?:die\s+)?(?:aesthetik|ästhetik|farbwelt))\b~iu',
    $scene
  ) === 1) return 'style';

  // This classification is a hint; the subject-default block ALSO protects
  // any human identity visible in an otherwise generic source image.
  if (preg_match(
    '~\b(?:person(?:en|s)?|people|human|woman|women|man|men|girl|boy|face|faces'
      . '|character(?:s)?|portrait(?:s)?|warrior(?:s)?|fighter(?:s)?|hero(?:es)?'
      . '|frau(?:en)?|mann|männer|menschen|gesicht(?:er)?|figur(?:en)?|charakter(?:e)?'
      . '|krieger(?:in|innen)?|held(?:in|en|innen)?|mara)\b~iu',
    $scene
  ) === 1) return 'identity';

  return 'subject';
}

/** One reusable system-layer instruction. Character and object geometry stay distinct. */
function coreui_image_reference_block(string $mode, bool $multiple, bool $compact = false): string {
  if (!in_array($mode, ['identity', 'subject', 'style'], true)) throw new InvalidArgumentException('Invalid reference mode');
  if ($compact) {
    $block = match ($mode) {
      'identity' => 'REFERENCE CONSISTENCY (IDENTITY): EXACT SAME people as the reference, not lookalikes. Keep unique face geometry 1:1 (eyes, brows, nose, lips, jaw, cheeks, chin, hairline, hairstyle, skin and marks), and original body proportions. Natural neck, shoulders, hands and feet. Pose, clothing, expression, light and setting may vary; invent a fitting scene if none given. Image identity overrides names and memory.',
      'subject' => 'REFERENCE CONSISTENCY (SUBJECT): Preserve every subject, including any human face, its silhouette, proportions, colors, structure, markings and anatomy. Pose, camera, light and setting may vary; invent a suitable setting if none is specified.',
      'style' => 'REFERENCE CONSISTENCY (STYLE ONLY): Copy only the requested visual style and palette, never the pictured identities or objects.',
    };
  } else {
    $block = match ($mode) {
      'identity' => 'REFERENCE CONSISTENCY (IDENTITY): Depict the SAME individuals from the supplied references. '
        . 'Keep their facial geometry and distinctive traits unchanged: eye shape and spacing, eyebrows, nose bridge/tip, '
        . 'lips, jawline, cheekbones, chin, hairline, natural skin tone/texture and any visible identifying marks. '
        . 'Keep their original body silhouette and proportions, with realistic human neck length, shoulder placement, '
        . 'hips, thighs, limbs, hands, forefeet and toes where applicable. No elongated neck/toes, distorted anatomy '
        . 'or exaggerated hourglass shape. Do not redesign, beautify, age or recast anyone. '
        . 'The visual reference is the binding 1:1 identity template for face, hair and physique, never merely inspiration. Do not reinterpret or replace its individual identity with a name, memory or invented description. Pose, clothing, expression, lighting, camera angle and surroundings may freely vary. When the user does not specify a scene, invent a fitting scene; do not copy the reference background by default.',
      'subject' => 'REFERENCE CONSISTENCY (SUBJECT): Keep each depicted subject recognizable, not redesigned. '
        . 'Preserve silhouette, structural geometry, scale, relative proportions, material, colors, markings, '
        . 'surface details and anatomy. If any people are shown, also preserve their facial identity and natural '
        . 'body proportions; avoid elongated necks, hands, feet or toes. '
        . 'Vary pose, scene, lighting and camera angle while keeping the original subject. If the user did not specify a scene, invent one freely instead of copying the reference background.',
      'style' => 'REFERENCE CONSISTENCY (STYLE ONLY): The supplied images are visual style guides, NOT subjects to reproduce. '
        . 'Use their palette, texture, brushwork, lighting, mood and overall visual treatment as requested. '
        . 'Create the new specified subject without copying an unrelated person, face, logo, product or object from the references.',
    };
  }
  if ($multiple && $mode === 'style') {
    $block .= ' MULTI-STYLE: Combine the requested visual treatment from all sources; do not reproduce their subjects.';
  } elseif ($multiple) {
    $block .= $compact
      ? ' MULTI-REFERENCE: Do not swap faces, merge identities or mix features, bodies or accessories. Keep subjects separate.'
      : ' MULTI-REFERENCE: Honor ALL supplied references separately. Do not swap faces, merge individuals, '
        . 'mix up identifying features or discard one subject. Combine them only in the scene requested by the user.';
  }
  return $block;
}

/**
 * Prepend the reference standard without losing the scene to a fixed 1200-char cap.
 * If necessary fall back to a compact instruction and truncate only as a last resort.
 */
function coreui_image_reference_prompt(string $scene, ?string $selfReference, array $uploads, int $limit,
    ?string $userMode = null): string {
  $mode = coreui_image_reference_mode($scene, $selfReference !== null, $uploads !== []);
  // The user's explicit style-only request outranks an LLM's paraphrase that
  // may mention people from the source photos as part of its description.
  if ($userMode === 'style' && $selfReference === null && $uploads !== []) $mode = 'style';
  if ($mode === null || $limit < 1) return $scene;
  $styleOverlay = $userMode === 'style' && $selfReference !== null && $uploads !== [];
  // When portraying Mara, her own photo remains the identity source; other
  // uploaded pictures can be requested as style only without copying faces.
  $multiple = !$styleOverlay && count($uploads) + ($selfReference === null ? 0 : 1) > 1;
  $accountPortrait = $selfReference !== null && str_starts_with($selfReference, 'custom:');
  $defaultPortrait = $selfReference === 'default';
  $styleFull = ' OTHER REFERENCES (STYLE ONLY): Take palette, texture and lighting from uploads; do NOT copy their people or objects.';
  $styleShort = ' OTHER REFERENCES (STYLE ONLY): Use uploads for style, not their subjects.';
  $portraitFull = ' ACCOUNT PORTRAIT: The person pictured IS TCI: Mara Thorne herself, the TCI of this account, NOT a lookalike, actress or unrelated model. This is her own visual identity, overriding default appearance, stored memory and name descriptions. Depict the EXACT SAME person from this portrait, with identical face geometry, hairstyle and body proportions, not an approximation or redesign. The assistant may choose a different pose, clothing and scene without changing that person.';
  $portraitShort = ' ACCOUNT PORTRAIT: The pictured person IS TCI: Mara Thorne herself, this TCI, NOT a lookalike. EXACT SAME person with identical face geometry, hairstyle and body proportions. Appearance is fixed by the image, not name or memory.';
  $defaultFull = ' TCI SELF-PORTRAIT: The pictured person IS TCI: Mara Thorne herself, this TCI, not a lookalike or model. Any additional CANONICAL MARA IDENTITY PHOTOS depict this SAME woman at different camera angles, NOT separate people. Keep her facial geometry, hair, body and defining features consistent. A hologram STYLE-ONLY photo changes rendering, NOT her identity; user-uploaded people remain separate.';
  $defaultShort = ' TCI SELF-PORTRAIT: The pictured person IS TCI: Mara Thorne herself. Canonical Mara photos all depict ONE SAME woman; Hologram references are STYLE ONLY.';

  $block = coreui_image_reference_block($mode, $multiple)
    . ($styleOverlay ? $styleFull : '')
    . ($accountPortrait ? $portraitFull : '')
    . ($defaultPortrait ? $defaultFull : '');
  // Prefer the complete 1:1 facial/anatomy lock with a SHORT Mara-gallery
  // note over a shortened identity lock with verbose gallery wording.
  if ($defaultPortrait && mb_strlen($block) + 1 + mb_strlen($scene) > $limit) {
    $fullIdentity = coreui_image_reference_block($mode, $multiple)
      . ($styleOverlay ? $styleShort : '')
      . $defaultShort;
    if (mb_strlen($fullIdentity) + 1 + mb_strlen($scene) <= $limit) $block = $fullIdentity;
  }
  // Prefer a shorter core before sacrificing details of a custom portrait.
  // The account overlay also has a compact form for tighter model limits.
  if (mb_strlen($block) + 1 + mb_strlen($scene) > $limit) {
    $block = coreui_image_reference_block($mode, $multiple, true)
      . ($styleOverlay ? $styleFull : '')
      . ($accountPortrait ? $portraitFull : '')
      . ($defaultPortrait ? $defaultFull : '');
  }
  if (mb_strlen($block) + 1 + mb_strlen($scene) > $limit) {
    $block = coreui_image_reference_block($mode, $multiple, true)
      . ($styleOverlay ? $styleShort : '')
      . ($accountPortrait ? $portraitShort : '')
      . ($defaultPortrait ? $defaultShort : '');
  }
  if (mb_strlen($block) + 1 >= $limit) {
    // Even unusually small service limits retain identity and multi-reference
    // semantics rather than silently discarding every reference instruction.
    $block = match ($mode) {
      'identity' => 'REFERENCE CONSISTENCY (IDENTITY): EXACT SAME person, 1:1 face, hair and body from image; never a lookalike.',
      'subject' => 'REFERENCE CONSISTENCY (SUBJECT): Preserve original subject geometry and identifying marks.',
      'style' => 'REFERENCE CONSISTENCY (STYLE ONLY): Copy style, not subjects.',
    };
    if ($multiple) $block .= ' MULTI-REFERENCE: Keep all sources separate.';
    if ($styleOverlay) $block .= ' OTHER REFERENCES (STYLE ONLY): Copy styling only.';
    if ($accountPortrait) $block .= ' ACCOUNT PORTRAIT: The pictured person IS TCI: Mara Thorne herself, EXACT SAME identity.';
    if ($defaultPortrait) $block .= ' TCI SELF-PORTRAIT: The pictured person IS TCI: Mara Thorne herself. Other canonical Mara portraits are SAME woman, holograms are STYLE ONLY.';
  }
  if (mb_strlen($block) + 1 >= $limit) {
    // No useful source-plus-scene instruction fits in a pathologically small
    // limit. Return the scene instead of producing a cut-off instruction.
    return mb_substr($scene, 0, $limit);
  }
  $budget = $limit - mb_strlen($block) - 1;
  if (mb_strlen($scene) > $budget) {
    // Important scene details can occur at either end of an Ollama-written
    // description. Preserve both rather than silently discarding the ending.
    if ($budget > 50) {
      $tail = min(120, max(22, intdiv($budget, 4)));
      $head = $budget - $tail - 5;
      $scene = mb_substr($scene, 0, $head) . ' ... ' . mb_substr($scene, -$tail);
    } else {
      $scene = mb_substr($scene, 0, $budget);
    }
  }
  return $block . ' ' . $scene;
}
