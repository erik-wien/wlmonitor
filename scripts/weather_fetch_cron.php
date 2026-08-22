<?php
// scripts/weather_fetch_cron.php
//
// Cron: alle 3h ab 06:00 (06/09/12/15/18/21 Uhr). Schreibt data/weather_cache.json.
// board.php ruft NIEMALS direkt ORF ab -- nur dieses Skript tut das.
//
// Zwei unabhaengige Quellen (2026-08-22): wien/prognose (Icon+Temp+Text fuer
// heute/morgen) und wien/mariabrunn (aktuelle Messwerte: Temperatur, Wind,
// Luftfeuchtigkeit, Niederschlag -- ersetzt die interne SHT4x-Sensorzeile
// des Geraets). Scheitert eine der beiden, bleibt NUR ihr eigenes Cache-Feld
// unveraendert stehen, die andere wird trotzdem aktualisiert.
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/weather.php';

const WEATHER_SOURCE_URL = 'https://wetter.orf.at/wien/prognose';
const WEATHER_STATION_URL = 'https://wetter.orf.at/wien/mariabrunn/';
const WEATHER_CACHE_FILE = __DIR__ . '/../data/weather_cache.json';

function weather_fetch_html(string $url): string
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: wlmonitor-weather-cron/1.0 (+https://wlmonitor.eriks.cloud)\r\n",
        'timeout' => 10,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        throw new RuntimeException('ORF-Wetterseite nicht erreichbar');
    }
    return $html;
}

// Prognose (wien/prognose) und Station (wien/mariabrunn, 2026-08-22) sind
// zwei unabhaengige ORF-Seiten -- ein Fehlschlag bei einer soll die andere
// nicht blockieren. Startpunkt ist der VORHANDENE Cache, damit ein
// fehlschlagender Abruf sein Feld unveraendert laesst statt es zu leeren.
$cache = file_exists(WEATHER_CACHE_FILE)
    ? json_decode((string) file_get_contents(WEATHER_CACHE_FILE), true)
    : null;
if (!is_array($cache)) {
    $cache = [];
}

$hadError = false;

try {
    $html = weather_fetch_html(WEATHER_SOURCE_URL);
    $forecast = weather_parse_forecast($html);

    foreach (['today', 'tomorrow'] as $period) {
        $mapping = weather_map_icon_code($forecast[$period]['icon_code']);
        if (!$mapping['known']) {
            appendLog($con, 'weather', 'Unbekannter ORF-Icon-Code: ' . $forecast[$period]['icon_code']);
        }
    }

    $cache['fetched_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
    $cache['today'] = $forecast['today'];
    $cache['tomorrow'] = $forecast['tomorrow'];
    fwrite(STDOUT, "Prognose aktualisiert: {$cache['fetched_at']}\n");
} catch (Throwable $e) {
    $hadError = true;
    appendLog($con, 'weather', 'ORF-Prognose-Abruf fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, 'Fehler (Prognose): ' . $e->getMessage() . "\n");
}

try {
    $html = weather_fetch_html(WEATHER_STATION_URL);
    $station = weather_parse_station($html);

    $cache['station_fetched_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
    $cache['station'] = $station;
    fwrite(STDOUT, "Station Mariabrunn aktualisiert: {$cache['station_fetched_at']}\n");
} catch (Throwable $e) {
    $hadError = true;
    appendLog($con, 'weather', 'ORF-Stationsabruf (Mariabrunn) fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, 'Fehler (Station): ' . $e->getMessage() . "\n");
}

if (!isset($cache['fetched_at'])) {
    // Weder vorhandener Cache noch dieser Lauf haben je eine Prognose --
    // ohne 'fetched_at' kann board_template.php das Alter nicht pruefen.
    fwrite(STDERR, "Kein nutzbarer Cache-Zustand -- kein Schreibvorgang.\n");
    exit(1);
}

$tmpFile = WEATHER_CACHE_FILE . '.tmp';
if (file_put_contents(
    $tmpFile,
    json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
) === false) {
    appendLog($con, 'weather', 'Cache-Datei nicht schreibbar: ' . $tmpFile);
    fwrite(STDERR, 'Cache-Datei nicht schreibbar: ' . $tmpFile . "\n");
    exit(1);
}
if (!rename($tmpFile, WEATHER_CACHE_FILE)) {
    appendLog($con, 'weather', 'Cache-Datei konnte nicht ersetzt werden');
    fwrite(STDERR, 'Cache-Datei konnte nicht ersetzt werden.' . "\n");
    exit(1);
}

exit($hadError ? 1 : 0);
