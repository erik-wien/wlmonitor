<?php
// scripts/weather_fetch_cron.php
//
// Cron: alle 3h ab 06:00 (06/09/12/15/18/21 Uhr). Schreibt data/weather_cache.json.
// board.php ruft NIEMALS direkt ORF ab -- nur dieses Skript tut das.
// Bei Fehlern bleibt die vorhandene Cache-Datei unveraendert stehen.
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/weather.php';

const WEATHER_SOURCE_URL = 'https://wetter.orf.at/wien/prognose';
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

try {
    $html = weather_fetch_html(WEATHER_SOURCE_URL);
    $forecast = weather_parse_forecast($html);

    foreach (['today', 'tomorrow'] as $period) {
        $mapping = weather_map_icon_code($forecast[$period]['icon_code']);
        if (!$mapping['known']) {
            appendLog($con, 'weather', 'Unbekannter ORF-Icon-Code: ' . $forecast[$period]['icon_code']);
        }
    }

    $cache = [
        'fetched_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'today' => $forecast['today'],
        'tomorrow' => $forecast['tomorrow'],
    ];

    $tmpFile = WEATHER_CACHE_FILE . '.tmp';
    if (file_put_contents(
        $tmpFile,
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    ) === false) {
        throw new RuntimeException('Cache-Datei nicht schreibbar: ' . $tmpFile);
    }
    rename($tmpFile, WEATHER_CACHE_FILE);

    fwrite(STDOUT, "Wetter-Cache aktualisiert: {$cache['fetched_at']}\n");
} catch (Throwable $e) {
    appendLog($con, 'weather', 'Wetter-Abruf fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
