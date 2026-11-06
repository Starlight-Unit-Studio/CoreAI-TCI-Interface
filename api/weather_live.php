<?php
declare(strict_types=1);

/*
 * CoreAI 0.6.22: live weather for Mara's [WEB:] step.
 *
 * Search snippets are a poor weather source: they come from pages indexed days
 * or weeks ago and contradict each other. A weather question in [WEB: ...]
 * ("Wetter Bergisch Gladbach heute") therefore gets live data instead:
 *
 *   - WDR Wetter NRW, the source the operator trusts most (06.11.2026), for a
 *     place in Nordrhein-Westfalen: the forecast part of the page (from
 *     "Prognose fuer" to the forecast text, before "Symbolerklaerungen"), with
 *     its "Stand:" checked and the nearest of the eleven WDR regions named;
 *   - Open-Meteo for every place: now, today, tomorrow and the day after,
 *     from the weather models for that place (no key needed).
 *
 * Only when neither answers does the normal web search run. The HTTP calls are
 * passed in, so the functions run without network in the self-test
 * (scripts/weather-live-selftest.php); that script can also fetch the real
 * pages on a server: php scripts/weather-live-selftest.php --live "Bergisch Gladbach"
 */

const COREUI_WEATHER_WDR_URL = 'https://www1.wdr.de/nrw/wetter/wettervorhersage/index.html';
const COREUI_WEATHER_WDR_REGION = 'Nordrhein-Westfalen';
const COREUI_WEATHER_WDR_CHARS = 1800;
const COREUI_WEATHER_DAYS = 3;
// A WDR forecast older than this is not given as today's weather (a region page
// was seen on 06.11.2026 with "Stand: 02.11.2026").
const COREUI_WEATHER_WDR_MAX_AGE_HOURS = 36;
// The eleven regions of the WDR overview (operator's analysis, 06.11.2026), with coordinates.
const COREUI_WEATHER_WDR_REGIONS = [
  'Aachen' => [50.776, 6.084], 'Bielefeld' => [52.021, 8.535], 'Bonn' => [50.737, 7.098], 'Dortmund' => [51.514, 7.466],
  'Duisburg' => [51.435, 6.763], 'Düsseldorf' => [51.227, 6.774], 'Essen' => [51.456, 7.012], 'Köln' => [50.938, 6.960],
  'Münster' => [51.960, 7.626], 'Siegen' => [50.874, 8.024], 'Wuppertal' => [51.256, 7.151],
];

/** A web query that asks for the weather (pure). */
function coreui_weather_is_query(string $query): bool {
  return preg_match('~\b(?:wetter\w*|temperatur\w*|vorhersage|wettervorhersage|regnet|regen(?:wahrscheinlichkeit|radar)?|'
    . 'schnee|gewitter|weather|forecast)\b~iu', $query) === 1;
}

/**
 * The place in a weather query (pure): words about weather and time are cut from
 * both ends, the place in between keeps its spelling ("Wetter Bergisch Gladbach
 * heute" -> "Bergisch Gladbach", "wie wird das Wetter in Halle an der Saale am
 * Wochenende" -> "Halle an der Saale").
 */
function coreui_weather_place(string $query): string {
  $drop = ['wettervorhersage', 'wetterbericht', 'wetterlage', 'wetter', 'temperaturen', 'temperatur', 'vorhersage',
    'regenwahrscheinlichkeit', 'regenradar', 'regnet', 'regen', 'schnee', 'gewitter', 'weather', 'forecast',
    'aktuell', 'aktuelle', 'aktuelles', 'heute', 'morgen', 'übermorgen', 'uebermorgen', 'jetzt', 'gerade', 'nachher',
    'abend', 'abends', 'nacht', 'wochenende', 'woche', 'diese', 'dieses', 'nächste', 'naechste', 'kommende',
    'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag', 'today', 'tomorrow', 'now',
    'in', 'für', 'fuer', 'bei', 'im', 'am', 'um', 'von', 'das', 'die', 'der', 'wie', 'ist', 'wird', 'es', 'wo', 'was',
    'grad', 'celsius', 'tage', 'tagen', 'deutschland', 'nrw', 'and', 'the', 'for', 'und', 'mit'];
  $clean = trim((string)preg_replace('~[?!.:;,"„“()\[\]]+~u', ' ', $query));
  $words = preg_split('~\s+~u', $clean) ?: [];
  $isDrop = static fn(string $w): bool => in_array(mb_strtolower($w), $drop, true) || preg_match('~^\d+$~', $w) === 1;
  while ($words !== [] && $isDrop((string)$words[0])) array_shift($words);
  while ($words !== [] && $isDrop((string)end($words))) array_pop($words);
  $place = implode(' ', $words);
  return mb_strlen($place) >= 2 && mb_strlen($place) <= 80 ? $place : '';
}

/** German words for the WMO weather codes of Open-Meteo (pure). */
function coreui_weather_code_de($code): string {
  $map = [0 => 'klar', 1 => 'überwiegend klar', 2 => 'teils bewölkt', 3 => 'bedeckt', 45 => 'Nebel', 48 => 'gefrierender Nebel',
    51 => 'leichter Nieselregen', 53 => 'Nieselregen', 55 => 'starker Nieselregen', 56 => 'gefrierender Nieselregen',
    57 => 'gefrierender Nieselregen', 61 => 'leichter Regen', 63 => 'Regen', 65 => 'starker Regen', 66 => 'gefrierender Regen',
    67 => 'gefrierender Regen', 71 => 'leichter Schneefall', 73 => 'Schneefall', 75 => 'starker Schneefall', 77 => 'Schneegriesel',
    80 => 'leichte Regenschauer', 81 => 'Regenschauer', 82 => 'heftige Regenschauer', 85 => 'Schneeschauer',
    86 => 'starke Schneeschauer', 95 => 'Gewitter', 96 => 'Gewitter mit Hagel', 99 => 'Gewitter mit Hagel'];
  return $map[(int)$code] ?? 'Wettercode ' . (int)$code;
}

function coreui_weather_number($value, int $digits = 0): ?string {
  if (!is_numeric($value)) return null;
  return str_replace('.', ',', (string)round((float)$value, $digits));
}

/** Readable lines from an Open-Meteo forecast answer (pure). */
function coreui_weather_lines(array $forecast): array {
  $lines = [];
  $cur = is_array($forecast['current'] ?? null) ? $forecast['current'] : [];
  $now = [];
  if (($t = coreui_weather_number($cur['temperature_2m'] ?? null, 1)) !== null) $now[] = $t . ' °C';
  if (($f = coreui_weather_number($cur['apparent_temperature'] ?? null, 1)) !== null) $now[] = 'gefühlt ' . $f . ' °C';
  if (isset($cur['weather_code'])) $now[] = coreui_weather_code_de($cur['weather_code']);
  if (($p = coreui_weather_number($cur['precipitation'] ?? null, 1)) !== null && (float)$cur['precipitation'] > 0) $now[] = 'Niederschlag ' . $p . ' mm';
  if (($w = coreui_weather_number($cur['wind_speed_10m'] ?? null)) !== null) $now[] = 'Wind ' . $w . ' km/h';
  if ($now !== []) $lines[] = 'Jetzt' . (!empty($cur['time']) ? ' (' . substr((string)$cur['time'], 11, 5) . ' Uhr)' : '') . ': ' . implode(', ', $now);

  $daily = is_array($forecast['daily'] ?? null) ? $forecast['daily'] : [];
  $names = ['Heute', 'Morgen', 'Übermorgen'];
  $weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  foreach ((array)($daily['time'] ?? []) as $i => $date) {
    if ($i >= COREUI_WEATHER_DAYS) break;
    $ts = strtotime((string)$date . ' 12:00:00 UTC');
    $label = ($names[$i] ?? 'Tag ' . ($i + 1)) . ($ts !== false ? ' (' . $weekdays[(int)gmdate('w', $ts)] . ' ' . gmdate('d.m.', $ts) . ')' : '');
    $parts = [];
    $min = coreui_weather_number($daily['temperature_2m_min'][$i] ?? null);
    $max = coreui_weather_number($daily['temperature_2m_max'][$i] ?? null);
    if ($min !== null && $max !== null) $parts[] = $min . ' bis ' . $max . ' °C';
    if (isset($daily['weather_code'][$i])) $parts[] = coreui_weather_code_de($daily['weather_code'][$i]);
    if (($pp = coreui_weather_number($daily['precipitation_probability_max'][$i] ?? null)) !== null) $parts[] = 'Regenrisiko ' . $pp . ' %';
    if (($ps = coreui_weather_number($daily['precipitation_sum'][$i] ?? null, 1)) !== null) $parts[] = $ps . ' mm';
    if (($wm = coreui_weather_number($daily['wind_speed_10m_max'][$i] ?? null)) !== null) $parts[] = 'Wind bis ' . $wm . ' km/h';
    if ($parts !== []) $lines[] = $label . ': ' . implode(', ', $parts);
  }
  return $lines;
}

/** The first Open-Meteo geocoding hit for a place (name, admin1, country, latitude, longitude, timezone), or null. */
function coreui_weather_geocode(string $place, callable $getJson): ?array {
  if ($place === '') return null;
  $geo = $getJson('https://geocoding-api.open-meteo.com/v1/search?name=' . rawurlencode($place) . '&count=1&language=de&format=json');
  $hit = is_array($geo) && is_array($geo['results'][0] ?? null) ? $geo['results'][0] : null;
  if ($hit === null || !is_numeric($hit['latitude'] ?? null) || !is_numeric($hit['longitude'] ?? null)) return null;
  return $hit;
}

/**
 * Open-Meteo for a place: ['place' => 'Bergisch Gladbach, Nordrhein-Westfalen, Deutschland',
 * 'region' => 'Nordrhein-Westfalen', 'lines' => [...]] or null. $getJson(string $url): ?array.
 */
function coreui_weather_open_meteo(string $place, callable $getJson): ?array {
  $hit = coreui_weather_geocode($place, $getJson);
  if ($hit === null) return null;
  $forecast = $getJson('https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string)$hit['latitude'])
    . '&longitude=' . rawurlencode((string)$hit['longitude'])
    . '&current=temperature_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m'
    . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max'
    . '&forecast_days=' . COREUI_WEATHER_DAYS . '&timezone=' . rawurlencode((string)($hit['timezone'] ?? 'auto')));
  if (!is_array($forecast)) return null;
  $lines = coreui_weather_lines($forecast);
  if ($lines === []) return null;
  $name = implode(', ', array_values(array_unique(array_filter([(string)($hit['name'] ?? $place), (string)($hit['admin1'] ?? ''),
    (string)($hit['country'] ?? '')], static fn(string $s): bool => trim($s) !== ''))));
  return ['place' => $name, 'name' => (string)($hit['name'] ?? $place), 'region' => (string)($hit['admin1'] ?? ''),
    'country_code' => (string)($hit['country_code'] ?? ''), 'lat' => (float)$hit['latitude'], 'lon' => (float)$hit['longitude'],
    'lines' => $lines];
}

/**
 * The forecast part of a WDR weather page (pure). Structure seen on 06.11.2026
 * (operator's analysis and screenshot): navigation and search, "Prognose fuer
 * NRW" with the day ("Heute, 06.11.2026"), the tabs Heute, Nachts, Morgen, eleven
 * lines "Region Aachen" with "Max: 9° | Min: 2°", "Stand: 06.11.2026, 08:15 Uhr",
 * "Wettervorhersage" with the editorial text, then "Symbolerklaerungen" and
 * "Weitere Beitraege". No CSS classes are relied on: scripts, styles, forms,
 * navigation, header and footer go, block elements become lines, the text runs
 * from "Prognose fuer" to just before the symbol legend or further articles. Only
 * kept when it names a temperature; at most 1800 characters.
 */
function coreui_weather_wdr_text(string $html): string {
  if ($html === '') return '';
  $html = (string)preg_replace('~<(script|style|noscript|svg|template|iframe|form|nav|header|footer|aside)\b[^>]*>.*?</\1\s*>~isu', ' ', $html);
  $html = (string)preg_replace('~<!--.*?-->~su', ' ', $html);
  if (preg_match('~<main\b[^>]*>(.*)</main\s*>~isu', $html, $m) === 1) $html = $m[1];
  elseif (preg_match('~<body\b[^>]*>(.*)</body\s*>~isu', $html, $m) === 1) $html = $m[1];
  $html = (string)preg_replace('~<(?:br|/p|/div|/li|/h[1-6]|/tr|/section|/article|/dd|/dt|/a|/button)\b[^>]*>~iu', "\n", $html);
  // Inline parts of one line stay apart: "<span>Region Aachen</span><span>Max: 9°</span>" -> "Region Aachen Max: 9°".
  $html = (string)preg_replace('~</t[dh]\s*>|</(?:span|strong|em|b|i|label|time|small)\s*>|<img\b[^>]*>~iu', ' ', $html);
  $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $lines = [];
  foreach (preg_split('~\R~u', $text) ?: [] as $line) {
    $line = trim((string)preg_replace('~[ \t\x{00A0}]+~u', ' ', $line));
    if ($line === '' || mb_strlen($line) < 2) continue;
    if ($lines !== [] && end($lines) === $line) continue;
    $lines[] = $line;
  }
  $from = 0;
  foreach ($lines as $i => $line) {
    if (preg_match('~^Prognose\s+f(?:ü|ue)r\b~iu', $line) === 1) { $from = $i; break; }
  }
  $lines = array_slice($lines, $from);
  foreach ($lines as $i => $line) {
    if ($i > 0 && preg_match('~^(?:Symbolerkl(?:ä|ae)rung|Weitere\s+Beitr(?:ä|ae)ge|Mehr\s+zum\s+Thema|Mehr\s+Wetter)~iu', $line) === 1) {
      $lines = array_slice($lines, 0, $i);
      break;
    }
  }
  $text = implode("\n", $lines);
  if (preg_match('~-?\d{1,2}\s*(?:°|Grad)~u', $text) !== 1) return '';
  if (mb_strlen($text) > COREUI_WEATHER_WDR_CHARS) $text = rtrim(mb_substr($text, 0, COREUI_WEATHER_WDR_CHARS)) . '…';
  return $text;
}

/** "Stand: 06.11.2026, 08:15 Uhr" in a WDR text as "2026-11-06 08:15" (pure), or null. */
function coreui_weather_wdr_stand(string $text): ?string {
  if (preg_match('~Stand:?\s*(\d{1,2})\.(\d{1,2})\.(\d{4})(?:,?\s*(\d{1,2})[:.](\d{2}))?~u', $text, $m) !== 1) return null;
  if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return null;
  return sprintf('%04d-%02d-%02d %02d:%02d', (int)$m[3], (int)$m[2], (int)$m[1], (int)($m[4] ?? 0), (int)($m[5] ?? 0));
}

/** The nearest of the eleven WDR regions to a place (pure): ['name', 'km']. */
function coreui_weather_wdr_region(float $lat, float $lon): array {
  $best = ['name' => '', 'km' => INF];
  foreach (COREUI_WEATHER_WDR_REGIONS as $name => [$rLat, $rLon]) {
    $dLat = deg2rad($rLat - $lat);
    $dLon = deg2rad($rLon - $lon);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($rLat)) * sin($dLon / 2) ** 2;
    $km = 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    if ($km < $best['km']) $best = ['name' => (string)$name, 'km' => $km];
  }
  return $best;
}

/** The values of one region in a WDR text (pure): "Max: 11° | Min: 1°", or ''. */
function coreui_weather_wdr_region_values(string $text, string $region): string {
  $q = preg_quote($region, '~');
  if (preg_match('~Region\s+' . $q . '\b[ \t|:]*([^\n]*)(?:\n([^\n]*))?~u', $text, $m) !== 1) return '';
  foreach ([trim($m[1] ?? ''), trim($m[2] ?? '')] as $part) {
    if ($part !== '' && preg_match('~-?\d{1,2}\s*°~u', $part) === 1 && preg_match('~^Region\b~u', $part) !== 1) return $part;
  }
  return '';
}

/**
 * Sources for a weather query, best first: [['title', 'url', 'content', 'date']].
 * $getJson(string $url): ?array; $wdrText(): string, the readable text of the WDR
 * page (coreui_weather_wdr_text), asked only for a place in NRW. Empty when the
 * query names no place or no source answered (then the web search runs).
 */
function coreui_weather_sources(string $query, callable $getJson, callable $wdrText, ?string $today = null): array {
  if (!coreui_weather_is_query($query)) return [];
  $place = coreui_weather_place($query);
  $live = coreui_weather_open_meteo($place, $getJson);
  if ($live === null) return [];
  $today ??= date('Y-m-d');
  $rows = [];
  if ($live['region'] === COREUI_WEATHER_WDR_REGION) {
    $wdr = trim((string)$wdrText());
    $stand = $wdr !== '' ? coreui_weather_wdr_stand($wdr) : null;
    // An old forecast is never given as today's weather.
    $fresh = $stand === null || strtotime($stand) >= strtotime($today . ' 12:00') - COREUI_WEATHER_WDR_MAX_AGE_HOURS * 3600;
    if ($wdr !== '' && $fresh) {
      $region = coreui_weather_wdr_region((float)$live['lat'], (float)$live['lon']);
      $values = coreui_weather_wdr_region_values($wdr, $region['name']);
      $near = 'Naechste WDR-Region zu ' . $live['name'] . ': ' . $region['name']
        . ($region['km'] >= 3 ? ' (etwa ' . (int)round($region['km']) . ' km entfernt)' : '')
        . ($values !== '' ? ', ' . $values : '') . '. Fuer genau diesen Ort hat die WDR-Seite eine Ortssuche (PLZ oder Ort suchen).';
      $rows[] = ['title' => 'WDR Wetter, Vorhersage fuer Nordrhein-Westfalen'
          . ($stand !== null ? ' (Stand ' . date('d.m.Y, H:i', (int)strtotime($stand)) . ' Uhr)' : ''),
        'url' => COREUI_WEATHER_WDR_URL, 'content' => $near . "\n" . $wdr, 'date' => $today];
    }
  }
  $rows[] = ['title' => 'Live-Wetterdaten fuer ' . $live['place'] . ' (Open-Meteo, Wettermodelle)', 'url' => 'https://open-meteo.com/',
    'content' => implode("\n", $live['lines']), 'date' => $today];
  return $rows;
}

/** The block for Mara's prompt from weather sources (pure). */
function coreui_weather_block(array $rows): string {
  $out = [];
  foreach (array_values($rows) as $i => $row) {
    $head = ($i + 1) . '. ' . (string)$row['title'] . (($row['date'] ?? '') !== '' ? ' (abgerufen ' . (string)$row['date'] . ')' : '')
      . ' [' . (string)$row['url'] . ']';
    $body = implode("\n", array_map(static fn(string $l): string => '   ' . $l, preg_split('~\R~u', trim((string)$row['content'])) ?: []));
    $out[] = $head . "\n" . $body;
  }
  return $out === [] ? '' : "LIVE-WETTER (gerade abgerufen, aktueller als jede Suchtrefferliste):\n" . implode("\n", $out);
}

/**
 * One sentence for Mara's browser research: a WDR forecast for exactly one place
 * comes from the place search of the WDR weather page (operator's idea, 06.11.2026;
 * the browser worker fills in forms). The address in the goal is where it starts.
 */
function coreui_weather_browse_hint(): string {
  return 'Will jemand die WDR-Vorhersage genau fuer seinen Ort in NRW, nutze [BROWSE: WDR-Wetter <Ort> ueber die Ortssuche auf '
    . COREUI_WEATHER_WDR_URL . '].';
}

/** 0.6.22: the browser goal for a weather question about a place in NRW (pure, at most 200 characters). */
function coreui_weather_wdr_browse_goal(string $query, string $place): string {
  $goal = 'WDR-Wetter fuer ' . $place . ': Ort in "PLZ oder Ort suchen" eingeben auf ' . COREUI_WEATHER_WDR_URL;
  $ask = trim((string)preg_replace('~\s+~u', ' ', $query));
  $room = 200 - mb_strlen($goal) - mb_strlen('. Frage: ');
  if ($ask === '' || $room < 12) return mb_substr($goal, 0, 200);
  if (mb_strlen($ask) > $room) $ask = rtrim(mb_substr($ask, 0, $room - 1)) . '…';
  return $goal . '. Frage: ' . $ask;
}

/**
 * 0.6.22 (operator, 06.11.2026: "fuer NRW-Anfragen am sinnvollsten, fuer alle anderen Orte und
 * Laender wie bisher"): a weather question about a place in Nordrhein-Westfalen goes to the place
 * search of the WDR weather page, by Mara's browser research, instead of the quick web step. Every
 * other tool, place or country stays as it is, and so does NRW when browser research is not allowed.
 * $getJson(string $url): ?array for the geocoding.
 */
function coreui_weather_route(array $tool, array $allowed, callable $getJson): array {
  if (($tool['tool'] ?? '') !== 'web' || !in_array('browse', $allowed, true)) return $tool;
  $query = (string)($tool['input'] ?? '');
  if (!coreui_weather_is_query($query)) return $tool;
  $place = coreui_weather_place($query);
  $hit = coreui_weather_geocode($place, $getJson);
  if ($hit === null || (string)($hit['admin1'] ?? '') !== COREUI_WEATHER_WDR_REGION) return $tool;
  $name = trim((string)($hit['name'] ?? $place));
  return ['tool' => 'browse', 'input' => coreui_weather_wdr_browse_goal($query, $name !== '' ? $name : $place), 'route' => 'wdr_place_search'];
}
