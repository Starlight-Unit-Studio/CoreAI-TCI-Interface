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
$geo = ['Bergisch Gladbach' => ['name' => 'Bergisch Gladbach', 'admin1' => 'Nordrhein-Westfalen', 'country' => 'Deutschland',
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
wx_test(count($rows) === 2 && $rows[0]['url'] === COREUI_WEATHER_WDR_URL && $rows[0]['content'] === $wdr && $wdrAsked === 1
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

$block = coreui_weather_block(coreui_weather_sources('Wetter Bergisch Gladbach heute', $getJson, $wdrText, '2026-11-06'));
wx_test(str_starts_with($block, "LIVE-WETTER (gerade abgerufen, aktueller als jede Suchtrefferliste):\n1. WDR Wetter, Vorhersage fuer Nordrhein-Westfalen (abgerufen 2026-11-06) [" . COREUI_WEATHER_WDR_URL . "]\n   Wettervorhersage für NRW")
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

echo "CoreAI live weather: WDR for NRW and live data for every place instead of old search snippets OK\n";
