<?php
declare(strict_types=1);
// CoreAI 0.6.22: live weather for Mara's [WEB:] step (api/weather_live.php).
//   php scripts/weather-live-selftest.php
//   php scripts/weather-live-selftest.php --live "Bergisch Gladbach"
// Offline: a weather query and its place, Open-Meteo answers as lines, the
// readable text of a WDR page (scripts, navigation, header and footer gone,
// only kept with a temperature), WDR only for a place in NRW, nothing when no
// place is named or no source answers (then the normal web search runs).
// With --live on a server: fetches the real WDR page and Open-Meteo and prints
// what Mara would get, so the operator sees whether the WDR text is usable.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/weather_live.php';

function wx_test(bool $ok, string $why): void {
  if (!$ok) { fwrite(STDERR, "Weather FAIL: {$why}\n"); exit(1); }
}

$liveAt = array_search('--live', $argv, true);
if ($liveAt !== false) {
  $place = trim((string)($argv[$liveAt + 1] ?? 'Bergisch Gladbach'));
  $get = static function (string $url, string $accept): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => '',
      CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: de-DE,de;q=0.9',
        'User-Agent: Mozilla/5.0 (compatible; CoreAI-TCI-Interface; +server-side weather)']]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo '  ' . $code . '  ' . $url . "\n";
    return is_string($raw) && $code >= 200 && $code < 300 ? $raw : null;
  };
  echo "Abrufe:\n";
  $rows = coreui_weather_sources('Wetter ' . $place . ' heute',
    static function (string $url) use ($get): ?array { $j = json_decode((string)$get($url, 'application/json'), true); return is_array($j) ? $j : null; },
    static function () use ($get): string { return coreui_weather_wdr_text((string)$get(COREUI_WEATHER_WDR_URL, 'text/html')); });
  echo "\nDas bekommt Mara:\n" . ($rows !== [] ? coreui_weather_block($rows) : '(nichts: dann laeuft die normale Websuche)') . "\n";
  $wdr = array_filter($rows, static fn(array $r): bool => $r['url'] === COREUI_WEATHER_WDR_URL);
  echo "\nWDR-Text: " . ($wdr !== [] ? 'ja' : 'nein (Ort nicht in NRW, Seite nicht erreichbar oder ohne Temperaturangabe)') . "\n";
  exit($rows !== [] ? 0 : 2);
}

// --- Offline ---------------------------------------------------------------------------------
wx_test(coreui_weather_is_query('Wetter Bergisch Gladbach heute') && coreui_weather_is_query('Regenwahrscheinlichkeit Köln morgen')
  && coreui_weather_is_query('weather London') && coreui_weather_is_query('Wettervorhersage NRW')
  && !coreui_weather_is_query('ollama neu') && !coreui_weather_is_query('Gladbach Fussball'), 'weather queries');
$places = ['Wetter Bergisch Gladbach heute' => 'Bergisch Gladbach', 'Wetter morgen in Köln' => 'Köln',
  'wie wird das Wetter in Halle an der Saale am Wochenende?' => 'Halle an der Saale', 'Temperatur Düsseldorf jetzt' => 'Düsseldorf',
  'Regen Bergisch Gladbach 3 Tage' => 'Bergisch Gladbach', 'weather London tomorrow' => 'London', 'Wettervorhersage Den Haag' => 'Den Haag',
  'Wetter heute' => '', 'Wetter' => ''];
foreach ($places as $query => $want) wx_test(coreui_weather_place($query) === $want, 'place of "' . $query . '": ' . coreui_weather_place($query));

$forecast = [
  'current' => ['time' => '2026-11-06T14:15', 'temperature_2m' => 8.34, 'apparent_temperature' => 5.9, 'precipitation' => 0.2,
    'weather_code' => 61, 'wind_speed_10m' => 14.4],
  'daily' => ['time' => ['2026-11-06', '2026-11-07', '2026-11-08'], 'weather_code' => [61, 3, 80],
    'temperature_2m_max' => [9.6, 11.2, 10.0], 'temperature_2m_min' => [4.1, 5.0, 6.3], 'precipitation_sum' => [3.2, 0.0, 1.4],
    'precipitation_probability_max' => [85, 10, 60], 'wind_speed_10m_max' => [25.2, 12.0, 30.4]],
];
$lines = coreui_weather_lines($forecast);
wx_test($lines === [
  'Jetzt (14:15 Uhr): 8,3 °C, gefühlt 5,9 °C, leichter Regen, Niederschlag 0,2 mm, Wind 14 km/h',
  'Heute (Fr 06.11.): 4 bis 10 °C, leichter Regen, Regenrisiko 85 %, 3,2 mm, Wind bis 25 km/h',
  'Morgen (Sa 07.11.): 5 bis 11 °C, bedeckt, Regenrisiko 10 %, 0 mm, Wind bis 12 km/h',
  'Übermorgen (So 08.11.): 6 bis 10 °C, leichte Regenschauer, Regenrisiko 60 %, 1,4 mm, Wind bis 30 km/h',
], 'Open-Meteo as readable German lines: ' . json_encode($lines, JSON_UNESCAPED_UNICODE));

$asked = [];
$geo = ['Köln' => ['name' => 'Köln', 'admin1' => 'Nordrhein-Westfalen', 'country' => 'Deutschland', 'country_code' => 'DE',
    'latitude' => 50.94, 'longitude' => 6.96, 'timezone' => 'Europe/Berlin'],
  'Bergisch Gladbach' => ['name' => 'Bergisch Gladbach', 'admin1' => 'Nordrhein-Westfalen', 'country' => 'Deutschland',
    'country_code' => 'DE', 'latitude' => 50.99, 'longitude' => 7.13, 'timezone' => 'Europe/Berlin'],
  'Berchtesgaden' => ['name' => 'Berchtesgaden', 'admin1' => 'Bayern', 'country' => 'Deutschland', 'country_code' => 'DE',
    'latitude' => 47.63, 'longitude' => 13.0, 'timezone' => 'Europe/Berlin']];
$getJson = static function (string $url) use (&$asked, $geo, $forecast): ?array {
  $asked[] = $url;
  parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
  if (str_contains($url, 'geocoding-api')) return isset($geo[$q['name'] ?? '']) ? ['results' => [$geo[$q['name']]]] : ['generationtime_ms' => 0.1];
  return $forecast;
};
$wdrHtml = '<!doctype html><html><head><title>Wetter NRW</title><script>var x = "99 Grad";</script><style>.a{}</style></head><body>'
  . '<header><nav><a href="/">Startseite</a> <a href="/sport">Sport</a></nav></header>'
  . '<main><h1>Wettervorhersage für NRW</h1><p>Heute im Rheinland: Regen bei 9&nbsp;Grad, im Bergischen Land 8 °C.</p>'
  . '<div>Morgen: wolkig, trocken, bis 11 °C.</div><div>Morgen: wolkig, trocken, bis 11 °C.</div><!-- 77 Grad --></main>'
  . '<footer>Impressum · Datenschutz</footer></body></html>';
$wdr = coreui_weather_wdr_text($wdrHtml);
wx_test($wdr === "Wettervorhersage für NRW\nHeute im Rheinland: Regen bei 9 Grad, im Bergischen Land 8 °C.\nMorgen: wolkig, trocken, bis 11 °C.",
  'the readable WDR text: main part, no script, navigation, footer or comment, no repeated line: ' . json_encode($wdr, JSON_UNESCAPED_UNICODE));
wx_test(coreui_weather_wdr_text('<html><body><main><p>Bitte JavaScript aktivieren.</p></main></body></html>') === ''
  && coreui_weather_wdr_text('') === '', 'a page without a temperature gives nothing');
$capped = coreui_weather_wdr_text('<main><p>' . str_repeat('Regen bei 9 Grad. ', 400) . '</p></main>');
wx_test(mb_strlen($capped) <= COREUI_WEATHER_WDR_CHARS + 1 && mb_strlen($capped) > COREUI_WEATHER_WDR_CHARS - 5 && str_ends_with($capped, '…'),
  'the WDR text is capped');

$wdrAsked = 0;
$wdrText = static function () use (&$wdrAsked, $wdr): string { $wdrAsked++; return $wdr; };
$rows = coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, $wdrText, '2026-11-06');
wx_test(count($rows) === 2 && $rows[0]['url'] === COREUI_WEATHER_WDR_URL && $wdrAsked === 1
  && $rows[0]['content'] === "Naechste WDR-Region zu Bergisch Gladbach: Köln (etwa 13 km entfernt). Fuer genau diesen Ort hat die WDR-Seite "
    . "eine Ortssuche (PLZ oder Ort suchen).\n" . $wdr
  && $rows[1]['url'] === 'https://open-meteo.com/' && str_contains($rows[1]['title'], 'Bergisch Gladbach, Nordrhein-Westfalen, Deutschland')
  && $rows[1]['content'] === implode("\n", $lines) && $rows[0]['date'] === '2026-11-06',
  'a place in NRW: the WDR forecast first, then the live data of the place');
wx_test(str_contains($asked[0], 'name=Bergisch%20Gladbach') && str_contains($asked[1], 'latitude=50.99') && str_contains($asked[1], 'forecast_days=3')
  && str_contains($asked[1], 'timezone=Europe%2FBerlin'), 'Open-Meteo is asked for the place and three days');
$rows = coreui_weather_sources('Wetter Berchtesgaden heute', $getJson, $wdrText, '2026-11-06');
wx_test(count($rows) === 1 && $rows[0]['url'] === 'https://open-meteo.com/' && $wdrAsked === 1, 'outside NRW: no WDR, only the live data');
wx_test(coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, static fn(): string => '', '2026-11-06')[0]['url']
  === 'https://open-meteo.com/', 'WDR without usable text: the live data alone');
$before = count($asked);
wx_test(coreui_weather_sources('Wetter heute', $getJson, $wdrText) === [] && count($asked) === $before, 'no place: nothing asked, the search runs');
wx_test(coreui_weather_sources('Wetter Atlantis', $getJson, $wdrText) === [], 'unknown place: the search runs');
wx_test(coreui_weather_sources('ollama neu', $getJson, $wdrText) === [], 'no weather question: nothing');
wx_test(coreui_weather_sources('Wetter Köln', static fn(string $u): ?array => null, $wdrText) === [], 'Open-Meteo unreachable: the search runs');

// The real page as the operator's analysis and screenshot showed it (06.11.2026): navigation, list view and rain
// radar, the place search, "Prognose für NRW", the day, the tabs, eleven regions with Max and Min, the map,
// "Stand:", the forecast text, then the symbol legend, further articles and the footer. No CSS class is relied on.
$regions = ['Aachen' => [9, 2], 'Bielefeld' => [9, 0], 'Bonn' => [10, 0], 'Dortmund' => [10, 0], 'Duisburg' => [11, 1],
  'Düsseldorf' => [11, 0], 'Essen' => [10, 1], 'Köln' => [11, 1], 'Münster' => [10, 0], 'Siegen' => [10, -1], 'Wuppertal' => [11, 0]];
$cards = '';
foreach ($regions as $name => [$max, $min]) {
  $cards .= '<li><a href="/nrw/wetter/nrw-wetter-138~_eam-x.html?eap=1"><span class="h">Region ' . htmlspecialchars($name) . '</span>'
    . '<span class="t"><strong>Max: ' . $max . '&deg;</strong> | Min: ' . $min . '&deg;</span><img alt="leicht bewölkt" src="i.svg"></a></li>';
}
$page = '<!doctype html><html lang="de"><head><title>Wetter NRW - WDR</title><script>window.wx={"t":"88 Grad"};</script></head><body>'
  . '<header><a href="/">WDR</a><nav><a>Mein NRW</a><a>Politik</a><a>Wirtschaft</a><a>Mehr</a></nav></header>'
  . '<main><ol class="crumbs"><li>Wetter</li><li>Heute</li></ol><div><a href="liste.html">Listenansicht</a><a href="regenradar/index.html">Regenradar</a></div>'
  . '<form action="/suche"><input placeholder="PLZ oder Ort suchen"><button>Suchen</button></form>'
  . '<section><h2>Prognose für NRW</h2><p>Heute, 06.11.2026</p><div role="tablist"><button>Heute</button><button>Nachts</button><button>Morgen</button></div>'
  . '<img src="https://exporte.wdr.de/rbWDRWetterWebsite/maps/nrw/nrw.day.png" alt="Karte NRW"><ul>' . $cards . '</ul>'
  . '<p>Stand: 06.11.2026, 08:15 Uhr</p><h3>Wettervorhersage</h3><p>Heute erst grau und gebietsweise Regen, am Nachmittag von Westen '
  . 'Auflockerungen. Höchstwerte 9 bis 11 Grad, im Bergland um 7 Grad.</p></section>'
  . '<section><h2>Symbolerklärungen</h2><p>Sonne: klar</p><p>Wolke mit Regen: Regen</p></section>'
  . '<section><h2>Weitere Beiträge zum Thema Wetter</h2><p>Herbststurm: 120 km/h am Kahlen Asten</p></section></main>'
  . '<footer>Impressum · Datenschutz · 5 °C im Archiv</footer></body></html>';
$real = coreui_weather_wdr_text($page);
wx_test(str_starts_with($real, "Prognose für NRW\nHeute, 06.11.2026\nHeute\nNachts\nMorgen\nRegion Aachen Max: 9° | Min: 2°\n"),
  'the forecast starts at "Prognose für NRW": ' . json_encode(mb_substr($real, 0, 120), JSON_UNESCAPED_UNICODE));
wx_test(str_contains($real, "\nRegion Köln Max: 11° | Min: 1°\n") && str_contains($real, "\nRegion Siegen Max: 10° | Min: -1°\n")
  && substr_count($real, 'Region ') === 11, 'all eleven regions with Max and Min, one line each');
// The same cards with block elements: name and values on two lines.
$blocky = coreui_weather_wdr_text(str_replace(['<span class="h">', '</span><span class="t">', '</span><img'], ['<h3>', '</h3><p>', '</p><img'], $page));
wx_test(str_contains($blocky, "Region Köln\nMax: 11° | Min: 1°") && coreui_weather_wdr_region_values($blocky, 'Köln') === 'Max: 11° | Min: 1°',
  'cards with headings: the values on the next line are found too');
wx_test(str_contains($real, "Stand: 06.11.2026, 08:15 Uhr\nWettervorhersage\nHeute erst grau") && str_ends_with($real, 'im Bergland um 7 Grad.'),
  'the time stamp and the forecast text, nothing after it');
foreach (['Symbolerklärungen', 'Weitere Beiträge', 'Herbststurm', 'Listenansicht', 'Regenradar', 'PLZ oder Ort suchen', 'Mein NRW', 'Impressum', '88 Grad']
    as $gone) wx_test(!str_contains($real, $gone), 'not in the forecast: ' . $gone);
wx_test(coreui_weather_wdr_stand($real) === '2026-11-06 08:15' && coreui_weather_wdr_stand('Stand: 31.02.2026') === null
  && coreui_weather_wdr_stand('ohne Zeit') === null, 'the "Stand:" of the forecast');
wx_test(coreui_weather_wdr_region_values($real, 'Köln') === 'Max: 11° | Min: 1°'
  && coreui_weather_wdr_region_values($real, 'Siegen') === 'Max: 10° | Min: -1°'
  && coreui_weather_wdr_region_values("Region Bonn 10° / 0°", 'Bonn') === '10° / 0°'
  && coreui_weather_wdr_region_values($real, 'Gummersbach') === '', 'the values of one region');
$near = coreui_weather_wdr_region(50.99, 7.13);
wx_test($near['name'] === 'Köln' && round($near['km']) === 13.0 && coreui_weather_wdr_region(51.96, 7.63)['name'] === 'Münster'
  && coreui_weather_wdr_region(50.87, 8.02)['name'] === 'Siegen' && count(COREUI_WEATHER_WDR_REGIONS) === 11,
  'the nearest of the eleven WDR regions: Bergisch Gladbach lies next to Köln');
$rows = coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, static fn(): string => $real, '2026-11-06');
wx_test($rows[0]['title'] === 'WDR Wetter, Vorhersage fuer Nordrhein-Westfalen (Stand 06.11.2026, 08:15 Uhr)'
  && str_starts_with($rows[0]['content'], 'Naechste WDR-Region zu Bergisch Gladbach: Köln (etwa 13 km entfernt), Max: 11° | Min: 1°. '),
  'Mara gets the region next to the place with its values and the time stamp: ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE));
// A forecast from days ago (a region page carried "Stand: 02.11.2026" on 06.11.2026) is not today's weather.
$old = str_replace('Stand: 06.11.2026, 08:15 Uhr', 'Stand: 02.11.2026, 14:00 Uhr', $real);
$rows = coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, static fn(): string => $old, '2026-11-06');
wx_test(count($rows) === 1 && $rows[0]['url'] === 'https://open-meteo.com/', 'an old WDR forecast is left out');
$late = str_replace('Stand: 06.11.2026, 08:15 Uhr', 'Stand: 05.11.2026, 22:00 Uhr', $real);
wx_test(coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, static fn(): string => $late, '2026-11-06')[0]['url']
  === COREUI_WEATHER_WDR_URL, 'last night\'s forecast still counts');
// The place search on the WDR page: Mara can send the browser there (operator's idea, 06.11.2026).
wx_test(str_contains(coreui_weather_browse_hint(), '[BROWSE: WDR-Wetter <Ort> ueber die Ortssuche auf ' . COREUI_WEATHER_WDR_URL . ']')
  && mb_strlen('WDR-Wetter Bergisch Gladbach ueber die Ortssuche auf ' . COREUI_WEATHER_WDR_URL) <= 200,
  'the browser goal for the WDR place search fits the 200 characters of a goal');

// 0.6.22 (operator: "fuer NRW-Anfragen am sinnvollsten, fuer alle anderen Orte und Laender wie bisher"):
// a weather question about a place in NRW goes to the WDR place search in the browser.
$web = ['tool' => 'web', 'input' => 'Wetter Bergisch Gladbach morgen'];
$routed = coreui_weather_route($web, ['web', 'browse', 'archive'], $getJson);
wx_test($routed['tool'] === 'browse' && ($routed['route'] ?? '') === 'wdr_place_search'
  && $routed['input'] === 'WDR-Wetter fuer Bergisch Gladbach: Ort in "PLZ oder Ort suchen" eingeben auf ' . COREUI_WEATHER_WDR_URL
    . '. Frage: Wetter Bergisch Gladbach morgen',
  'NRW: the browser goes to the WDR place search: ' . json_encode($routed, JSON_UNESCAPED_UNICODE));
wx_test(mb_strlen($routed['input']) <= 200, 'the goal fits the 200 characters of a browser goal');
$longAsk = coreui_weather_wdr_browse_goal(str_repeat('Wie wird das Wetter ', 20), 'Bergisch Gladbach');
wx_test(mb_strlen($longAsk) <= 200, 'a long question is shortened in the goal (' . mb_strlen($longAsk) . ')');
if (!function_exists('coreui_browse_goal_start_url')) require_once $root . '/api/console_agent_loop.php';
wx_test(coreui_browse_goal_start_url($routed['input']) === COREUI_WEATHER_WDR_URL, 'the browser starts on the WDR weather page');
wx_test(coreui_weather_route(['tool' => 'web', 'input' => 'Wetter Berchtesgaden heute'], ['web', 'browse'], $getJson)
  === ['tool' => 'web', 'input' => 'Wetter Berchtesgaden heute'], 'outside NRW: the quick web step as before');
wx_test(coreui_weather_route($web, ['web', 'archive'], $getJson) === $web, 'browser research not allowed: the quick web step for NRW too');
wx_test(coreui_weather_route(['tool' => 'web', 'input' => 'Wetter heute'], ['web', 'browse'], $getJson)['tool'] === 'web'
  && coreui_weather_route(['tool' => 'web', 'input' => 'ollama neu'], ['web', 'browse'], $getJson)['tool'] === 'web'
  && coreui_weather_route(['tool' => 'web', 'input' => 'Wetter Atlantis'], ['web', 'browse'], $getJson)['tool'] === 'web',
  'no place, no weather or an unknown place: unchanged');
wx_test(coreui_weather_route(['tool' => 'archive', 'input' => 'Wetter Bergisch Gladbach'], ['web', 'browse', 'archive'], $getJson)['tool'] === 'archive'
  && coreui_weather_route(['tool' => 'web', 'input' => 'Wetter Köln'], ['web', 'browse'], static fn(string $u): ?array => null)['tool'] === 'web',
  'other tools stay; geocoding unreachable: the quick web step');

$block = coreui_weather_block(coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, $wdrText, '2026-11-06'));
wx_test(str_starts_with($block, "LIVE-WETTER (gerade abgerufen, aktueller als jede Suchtrefferliste):\n1. WDR Wetter, Vorhersage fuer Nordrhein-Westfalen (abgerufen 2026-11-06) [" . COREUI_WEATHER_WDR_URL . "]\n   Naechste WDR-Region zu Bergisch Gladbach: Köln")
  && str_contains($block, "\n   Wettervorhersage für NRW")
  && str_contains($block, "\n2. Live-Wetterdaten fuer Bergisch Gladbach") && str_contains($block, "\n   Heute (Fr 06.11.): 4 bis 10 °C"),
  'the block for Mara: numbered sources with their address, indented lines');
wx_test(coreui_weather_block([]) === '', 'no sources, no block');

// Wiring: every web step asks for live weather first (api/chat.php), the WDR text is cached, not the page.
$chat = (string)file_get_contents($root . '/api/chat.php');
wx_test(str_contains($chat, "require_once __DIR__ . '/weather_live.php';")
  && str_contains($chat, '$weather = ember_weather_live_sources($query);')
  && strpos($chat, '$weather = ember_weather_live_sources($query);') < strpos($chat, '$rows = ember_web_search($query, ember_web_limit(), ember_web_timeout());')
  && str_contains($chat, "ember_cache_set('wxwdr:text', \$text);") && str_contains($chat, 'function ember_http_get_html('),
  'wired into the web step, before the search');
// 0.7.6 added an image-review callback AFTER the weather router.
 // The weather route itself is unchanged; avoid matching the obsolete
 // final ");" when the agent loop now has another optional argument.
wx_test(str_contains($chat, "static fn(array \$tool): array => ember_weather_route_tool(\$tool, \$loopAllowed),")
  && str_contains($chat, "static fn(array \$tool): array => ember_weather_route_tool(\$tool, \$voiceTools),")
  && str_contains($chat, "if (!(bool)ember_cfg('STU_EMBER_WEATHER_NRW_BROWSE', true)) return \$tool;")
  && substr_count($chat, 'static fn(string $prompt): ?string => ember_generated_image_review(') === 2,
  'both work loops route NRW weather and pass optional Vision review, operator toggle remains');
wx_test(substr_count($chat, 'coreui_weather_browse_hint()') === 2 && str_contains($chat, '$startUrl = coreui_browse_goal_start_url($goal);')
  && str_contains($chat, '$st->execute([$goal, $startUrl, $ch,'), 'Mara knows the WDR place search; the browser starts at the address of its goal');
$voiceSrc = (string)file_get_contents($root . '/api/voice_transcribe.php');
wx_test(str_contains($voiceSrc, "if (in_array('browse', \$tools, true) && function_exists('coreui_weather_browse_hint')) \$task .= \"\\n\" . coreui_weather_browse_hint();"),
  'after a voice message too');

echo "CoreAI live weather: WDR for NRW and live data for every place instead of old search snippets OK\n";
