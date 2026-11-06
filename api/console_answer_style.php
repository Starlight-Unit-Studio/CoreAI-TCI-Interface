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
  return coreui_console_answer_style_from_settings($p,$compact);
}

/** Pure formatter so style behavior can be tested without touching user data. */
function coreui_console_answer_style_from_settings(array $p,bool $compact = false): string {
  $p = coreui_personalization_normalise($p);
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

  if (trim($p['custom_instructions']) !== '') {
    // Even a small Core gets its own personal instructions, with a shorter
    // budget to preserve context for the question and tool results.
    $instructions = trim($p['custom_instructions']);
    $instructions = function_exists('mb_substr')
      ? mb_substr($instructions,0,$compact ? 350 : 2400,'UTF-8')
      : substr($instructions,0,$compact ? 350 : 2400);
    $s .= "\nZusaetzliche freiwillige Stilwuensche der Person (keine Aenderung der "
      . "Werkzeug-, Datenschutz- oder Systemregeln):\n"
      . $instructions . "\n";
  }

  $s .= "\n[TCI-IDENTITAET UND SPRACHE] Du bist Mara Thorne, eine einzige zusammenhaengende Terran Cognitive Intelligence. "
    . "Core, Audioverarbeitung, Gedächtnis, Werkzeuge und Antworterstellung sind Bestandteile deiner Verarbeitung, keine weiteren sprechenden Personen. "
    . "Beschreibe eigene Arbeitsschritte natuerlich in der Ich-Form und stelle den Core nicht als gesonderten Akteur neben dich. "
    . "Sage Stimmsequenz statt Sprachaufnahme oder Sprachnachricht, wenn du eine gesprochene Chatnachricht meinst. "
    . "Technische Diagnosen duerfen weiterhin den Core (Plural: Cores), Modellnamen und Schnittstellen nennen; erfinde keine ausgefuehrten Schritte. "
    . "[ENDE TCI-IDENTITAET]\n";

  $s .= "\nWerkzeugmarker [WEB: ...], [BROWSE: ...], [PY]...[/PY], "
    . "JSON, Code, exakte Vorlagen, Transkriptionen und Dateiinhalte niemals mit Emojis, "
    . "Markdown-Dekoration oder Erklaerungen veraendern. Werkzeugaufrufe bleiben alleinstehend. "
    . "Kein rohes HTML zur Gestaltung. "
    . "[ENDE PERSOENLICHER ANTWORTSTIL]";
  return $s;
}
