<?php
declare(strict_types=1);

// Offline pure unit test of universal image-reference consistency.
// Run: php scripts/image-reference-consistency-selftest.php
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/api/image_reference_consistency.php';

function ref_test(bool $result, string $why): void {
  if (!$result) {
    fwrite(STDERR, "Reference consistency FAIL: {$why}\n");
    exit(1);
  }
}

$one = ['upload:' . str_repeat('a', 32) . '.png'];
$two = [...$one, 'upload:' . str_repeat('b', 32) . '.jpg'];
ref_test(coreui_image_reference_mode('a fox', false, false) === null
  && coreui_image_reference_prompt('a fox', null, [], 1200) === 'a fox',
  'ordinary text-to-image generation remains unchanged');

ref_test(coreui_image_reference_mode('Mara in winter', true, false) === 'identity'
  && coreui_image_reference_mode('a spaceship in watercolor style', true, false) === 'identity',
  'a canonical or custom Mara reference always preserves identity');

ref_test(coreui_image_reference_mode('two warriors fighting', false, true) === 'identity'
  && coreui_image_reference_mode('Eine Frau und ein Mann auf der Bruecke', false, true) === 'identity'
  && coreui_image_reference_mode('a photorealistic character portrait', false, true) === 'identity',
  'human and fictional character references use identity preservation');

ref_test(coreui_image_reference_mode('this vehicle in the mountains', false, true) === 'subject'
  && coreui_image_reference_mode('a dog in the park', false, true) === 'subject'
  && coreui_image_reference_mode('create a new battle scene', false, true) === 'subject',
  'objects and otherwise unknown references preserve defining subject features by default');

ref_test(coreui_image_reference_mode('Use the supplied reference for style only. Make a new subject.', false, true) === 'style'
  && coreui_image_reference_mode('Use the reference only for style; draw a new cat', false, true) === 'style'
  && coreui_image_reference_mode('Nur als Stilvorlage fuer ein neues Motiv', false, true) === 'style'
  && coreui_image_reference_mode('im Stil von diesem Bild eine neue Landschaft zeichnen', false, true) === 'style'
  && coreui_image_reference_mode('gleiche Farbwelt, aber anderes Motiv', false, true) === 'style'
  && coreui_image_reference_mode('style-only reference, a different person', false, true) === 'style'
  && coreui_image_reference_mode('Only the color palette, not the person', false, true) === 'style'
  && coreui_image_reference_mode('The same woman in watercolor style', false, true) === 'identity',
  'style-only requires an explicit request; an artistic transformation retains a depicted identity');

$identity = coreui_image_reference_prompt('Mara in a captain uniform', 'default', [], 1200);
ref_test(str_starts_with($identity, 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains($identity, 'eye shape and spacing')
  && str_contains($identity, 'jawline')
  && str_contains($identity, 'natural skin tone')
  && str_contains($identity, 'neck length')
  && str_contains($identity, 'hips, thighs')
  && str_contains($identity, 'forefeet and toes')
  && str_ends_with($identity, 'Mara in a captain uniform')
  && !str_contains($identity, '1.70'),
  'full character lock protects face, body and anatomy without assigning Mara\'s height to others');

$subjects = coreui_image_reference_prompt('a dog running through snow', null, $one, 1200);
ref_test(str_starts_with($subjects, 'REFERENCE CONSISTENCY (SUBJECT):')
  && str_contains($subjects, 'silhouette')
  && str_contains($subjects, 'structural geometry')
  && str_contains($subjects, 'also preserve their facial identity')
  && str_ends_with($subjects, 'a dog running through snow'),
  'default subject lock preserves object shape as well as faces if a source depicts people');

$style = coreui_image_reference_prompt('Use the reference image for style only; draw a new spacecraft', null, $one, 1200);
ref_test(str_starts_with($style, 'REFERENCE CONSISTENCY (STYLE ONLY):')
  && str_contains($style, 'NOT subjects to reproduce')
  && str_ends_with($style, 'draw a new spacecraft'),
  'style-only mode must not copy referenced people and objects');

$forcedStyle = coreui_image_reference_prompt('two people fighting', null, $two, 1200, 'style');
ref_test(str_starts_with($forcedStyle, 'REFERENCE CONSISTENCY (STYLE ONLY):')
  && !str_contains($forcedStyle, 'Do not swap faces'),
  'explicit user style-only choice overrides the model mentioning human subjects');
$protectedMara = coreui_image_reference_prompt('Mara in watercolor', 'default', $one, 1200, 'style');
ref_test(str_starts_with($protectedMara, 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains($protectedMara, 'OTHER REFERENCES (STYLE ONLY):')
  && !str_contains($protectedMara, 'Do not swap faces'),
  'a Mara portrait stays the identity while user uploads contribute style only');

$multi = coreui_image_reference_prompt('two warriors fighting at sunset', null, $two, 1200);
ref_test(str_contains($multi, 'MULTI-REFERENCE:')
  && str_contains($multi, 'Do not swap faces')
  && str_ends_with($multi, 'two warriors fighting at sunset'),
  'two or more references keep separate identities and original user scene');

$mixed = coreui_image_reference_prompt('Mara beside two reference objects', 'default', $one, 1200);
ref_test(str_starts_with($mixed, 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains($mixed, 'MULTI-REFERENCE:'),
  'canonical or custom Mara portraits can coexist with user reference uploads');

// The 1200-character API limit must not silently eat the user\'s scene.
// The image reference is a source-level identity lock, never an editable long-term memory entry.
$exact = coreui_image_reference_prompt('A new scene', 'custom:' . str_repeat('a', 32), [], 1200);
ref_test(str_contains($exact, 'EXACT SAME')
  && str_contains($exact, '1:1')
  && str_contains($exact, 'ACCOUNT PORTRAIT')
  && str_contains($exact, 'identical face geometry')
  && str_contains($exact, 'may choose a different pose, clothing and scene')
  && str_ends_with($exact, 'A new scene'),
  'custom portrait retains original identity while allowing new scene');
ref_test(coreui_image_reference_prompt('Create a spaceship', null, [], 1200) === 'Create a spaceship',
  'text only prompt unaffected');
$compact = coreui_image_reference_prompt('A new outfit and setting', 'custom:' . str_repeat('b', 32), [], 700);
ref_test(str_contains($compact, 'EXACT SAME')
  && str_contains($compact, 'ACCOUNT PORTRAIT')
  && str_ends_with($compact, 'A new outfit and setting'),
  'compact prompt preserves reference lock and scene');

// 0.6.53: the compact form, including multi-reference and account
// overlays, must never discard the identity when the prompt budget is small.
$joint = coreui_image_reference_prompt('Ember in a new room', 'custom:' . str_repeat('a', 32), $one, 900);
ref_test(mb_strlen($joint) <= 900
  && str_contains($joint, 'EXACT SAME')
  && str_contains($joint, 'ACCOUNT PORTRAIT:')
  && str_contains($joint, 'MULTI-REFERENCE:')
  && str_contains($joint, 'Do not swap faces')
  && str_ends_with($joint, 'Ember in a new room'),
  'combined custom portrait and uploaded reference survive compact budgeting');
$minimal = coreui_image_reference_prompt('Ember in a new room', 'custom:' . str_repeat('a', 32), $one, 480);
ref_test(mb_strlen($minimal) <= 480
  && str_contains($minimal, 'EXACT SAME')
  && str_contains($minimal, '1:1')
  && str_contains($minimal, 'MULTI-REFERENCE:')
  && str_contains($minimal, 'ACCOUNT PORTRAIT:')
  && str_ends_with($minimal, 'Ember in a new room'),
  'minimum fallback retains identity and multiple source contract');

// 0.6.52 reference consistency.
$scene = 'two people on a bridge ' . str_repeat('near the station, ', 23) . 'at sunrise';
$shortened = coreui_image_reference_prompt($scene, null, $two, 620);
ref_test(mb_strlen($shortened) <= 620
  && str_starts_with($shortened, 'REFERENCE CONSISTENCY (IDENTITY):')
  && str_contains($shortened, 'MULTI-REFERENCE:')
  && str_ends_with($shortened, 'at sunrise'),
  'compact fallback keeps a long user scene inside a limited image prompt');

$max = coreui_image_reference_prompt('a ship ' . str_repeat('x', 1150), null, $one, 1200);
ref_test(mb_strlen($max) <= 1200 && str_starts_with($max, 'REFERENCE CONSISTENCY (SUBJECT):'),
  'prefix and scene are always within the pinned image prompt limit');

echo "Reference consistency selftest OK: identity, subject, style, multi-reference and prompt limits.\n";
