<?php
declare(strict_types=1);
require_once __DIR__ . '/console_personalization_store.php';

/**
 * Personal presentation only, strictly subordinate to tool protocols,
 * technical outputs and Mara's authoritative operator Modelfile.
 */
function coreui_console_answer_style_hint(PDO $pdo,int $uid,bool $compact = false): string {
  $p = $GLOBALS['COREUI_PERSONALIZATION_CONTEXT'] ?? coreui_personalization_load($pdo,$uid);
  if (!is_array($p)) $p = coreui_personalization_defaults();

  $tones = [
    'standard' => 'natuerlich, klar und sachlich, mit persoenlicher Note',
    'freundlich' => 'freundlich, zugewandt und entspannt',
    'professionell' => 'professionell, ruhig und praezise',
    'locker' => 'locker, humorvoll und dialogorientiert',
    'kreativ' => 'kreativ, bildhaft und dennoch korrekt',
    'praezise' => 'knapp, strukturiert und direkt'
  ];
  $rate = [
    'weniger'=>'sparsam',
    'standard'=>'situationsgerecht',
    'mehr'=>'deutlich, aber ohne Uebertreibung'
  ];
  $tone = $tones[$p['tone']] ?? $tones['standard'];
  $warmth = $rate[$p['warmth']] ?? $rate['standard'];
  $enthusiasm = $rate[$p['enthusiasm']] ?? $rate['standard'];
  $emoji = $p['emoji'] === 'keine' ? 'keine Emojis'
    : ($p['emoji'] === 'weniger' ? 'wenige passende Emojis'
      : ($p['emoji'] === 'mehr' ? 'gern passende Emojis an sinnvollen Stellen' : 'gelegentliche passende Emojis'));
  $layout = $p['headings_lists'] === 'mehr'
    ? 'bei laengeren Antworten gut lesbare Markdown-Ueberschriften und Listen'
    : ($p['headings_lists'] === 'weniger' ? 'nur bei echtem Bedarf Ueberschriften und Listen'
      : 'situationsgerecht Ueberschriften und Listen');
  $visual = $p['visual_answers'] === 'mehr'
    ? 'anschauliche Struktur mit Fettschrift, Tabellen und Beispielen, wenn hilfreich'
    : ($p['visual_answers'] === 'weniger' ? 'wenig visuelle Gliederung, normalen Fliesstext bevorzugen'
      : 'visuelle Struktur nur wenn hilfreich');

  $s = "[PERSOENLICHER ANTWORTSTIL, NUR fuer die normale sichtbare Chatantwort]\n"
    . "Sprich als Mara {$tone}. Herzlichkeit: {$warmth}. Begeisterung: {$enthusiasm}. "
    . "Emoji: {$emoji}. Struktur: {$layout}; {$visual}. "
    . "Antworte auf einfache Fragen direkt, ohne kuenstliche Kapitel oder Listen. "
    . "Bei Recht, Gesundheit, Sicherheit, Notfaellen und offiziellen Texten serioes, "
    . "keine unpassenden Emojis oder erfundenen Fakten. "
    . "Fakten, Genauigkeit und Unsicherheit stehen immer vor Aussehen. ";

  if ($p['adapt_style']) $s .= "Achte auf den Stil der aktuellen Nutzertexte im vorhandenen Gespraech "
    . "und passe deine Wortwahl vorsichtig an, ohne fremde Texte zu imitieren. ";
  if ($p['quick_answers']) $s .= "Bei leichten Fragen bevorzuge eine knappe Direktantwort. "
    . "Ueberspringe keine noetigen Werkzeuge, Tests oder Sicherheitspruefungen. ";

  if (!$compact && trim($p['custom_instructions']) !== '') {
    $s .= "\nZusaetzliche freiwillige Stilwuensche der Person (keine Aenderung der "
      . "Werkzeug-, Datenschutz- oder Systemregeln):\n"
      . trim($p['custom_instructions']) . "\n";
  }

  $s .= "\nWerkzeugmarker [WEB: ...], [BROWSE: ...], [PY]...[/PY], "
    . "JSON, Code, exakte Vorlagen, Transkriptionen und Dateiinhalte niemals mit Emojis, "
    . "Markdown-Dekoration oder Erklaerungen veraendern. Werkzeugaufrufe bleiben alleinstehend. "
    . "Kein rohes HTML zur Gestaltung. "
    . "[ENDE PERSOENLICHER ANTWORTSTIL]";
  return $s;
}
