<?php
declare(strict_types=1);

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
      'identity' => 'REFERENCE CONSISTENCY (IDENTITY): Same individuals as the visual references. Lock face, eyes, nose, lips, jaw, hairline, hair, skin, marks, body shape and proportions. Natural neck, shoulders, limbs and feet. Pose, outfit, expression, camera, lighting and setting may vary; invent a fitting scene when none is specified. Never infer appearance from name or memory.',
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
        . 'Face, hairstyle and physique come from the visual reference, never from a name, stored memory or an invented description. Pose, clothing, expression, lighting, camera angle and surroundings may freely vary. When the user does not specify a scene, invent a fitting scene; do not copy the reference background by default.',
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
      ? ' MULTI-REFERENCE: Keep subjects separate; do not mix faces, features or accessories.'
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
  $overlay = $styleOverlay
    ? ' OTHER REFERENCES (STYLE ONLY): Take palette, texture and lighting from uploads; do NOT copy their people or objects.'
    : '';
  if ($selfReference !== null && str_starts_with($selfReference, 'custom:')) {
    $overlay .= ' ACCOUNT PORTRAIT: This account portrait overrides all default appearance, stored-memory and name-based descriptions of the assistant. Lock its face, hair and body proportions. The assistant may choose a new pose, outfit and scene without changing that person.';
  }
  $block = coreui_image_reference_block($mode, $multiple) . $overlay;
  if (mb_strlen($block) + 1 + mb_strlen($scene) > $limit) {
    $block = coreui_image_reference_block($mode, $multiple, true) . $overlay;
  }
  if (mb_strlen($block) + 1 >= $limit) {
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
