<?php
// inc/weather.php
//
// Wetter-Integration: ORF-Scraping, Icon-Code-Mapping, Anzeige-Auswahl.
// Ausschliesslich reine Funktionen -- kein Netz, keine DB. Der Netzabruf
// lebt in scripts/weather_fetch_cron.php.
declare(strict_types=1);

/**
 * Parst wetter.orf.at/wien/prognose (DESKTOP-Version) und liefert Icon-Code,
 * Min/Max-Temperatur und Fliesstext fuer heute und morgen, Station
 * Wien-Hohe Warte. Auswahl ist POSITIONAL (1./2. Spalte bzw. Textblock),
 * nicht ueber den Ueberschriftentext -- der wechselt je nach Tageszeit/
 * Feiertag ("Heute Nachmittag" vs. "Heute, Mariä Himmelfahrt").
 *
 * Das Icon steht als <span class="weatherIcon c123456"> im Markup (die
 * mobile /m/-Seite nutzt dagegen <img .../123456.svg> -- hier NICHT relevant).
 *
 * @return array{today: array{icon_code: string, temp_min: int, temp_max: int, text: string}, tomorrow: array{icon_code: string, temp_min: int, temp_max: int, text: string}}
 * @throws RuntimeException wenn die erwartete Struktur nicht gefunden wird
 */
function weather_parse_forecast(string $html): array
{
    $dom = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_use_internal_errors($prevErrors);
    $xpath = new DOMXPath($dom);

    $iconRow = $xpath->query(
        '//tr[contains(concat(" ", normalize-space(@class), " "), " forecastIconRow ")]'
        . '[.//th[contains(@class, "legendCol")][contains(., "Wien-Hohe Warte")]]'
    )->item(0);
    $tempRow = $xpath->query(
        '//tr[contains(concat(" ", normalize-space(@class), " "), " temperatureRow ")]'
        . '[.//th[contains(@class, "legendCol")][contains(., "Wien-Hohe Warte")]]'
    )->item(0);

    if ($iconRow === null || $tempRow === null) {
        throw new RuntimeException('Wetter-Tabelle fuer Wien-Hohe Warte nicht gefunden');
    }

    // Nur die ersten zwei Spalten (heute/morgen) werden ausgewertet -- ein
    // fehlerhaftes Markup in Spalte 3-5 soll den Abruf nicht scheitern lassen.
    $iconCodes = [];
    foreach ($xpath->query('.//td', $iconRow) as $td) {
        if (count($iconCodes) >= 2) {
            break;
        }
        $sp = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " weatherIcon ")]', $td)->item(0);
        if ($sp === null || !preg_match('/(?:^|\s)c(\d{6})(?:\s|$)/', (string) $sp->getAttribute('class'), $m)) {
            throw new RuntimeException('Icon-Code nicht gefunden');
        }
        $iconCodes[] = $m[1];
    }

    $temps = [];
    foreach ($xpath->query('.//td', $tempRow) as $td) {
        if (count($temps) >= 2) {
            break;
        }
        $highest = $xpath->query('.//span[contains(@class,"highest")]', $td)->item(0);
        if ($highest === null) {
            throw new RuntimeException('Hoechsttemperatur nicht gefunden');
        }
        // ORF laesst die Tages-Tiefsttemperatur ("morning") beim laufenden Tag
        // manchmal weg. Fehlt sie, faellt temp_min auf temp_max zurueck, statt
        // den ganzen Abruf scheitern zu lassen.
        $morning = $xpath->query('.//span[contains(@class,"morning")]', $td)->item(0);
        preg_match('/-?\d+/', $highest->textContent, $maxMatch);
        $max = (int) $maxMatch[0];
        if ($morning !== null) {
            preg_match('/-?\d+/', $morning->textContent, $minMatch);
            $min = (int) $minMatch[0];
        } else {
            $min = $max;
        }
        $temps[] = ['min' => $min, 'max' => $max];
    }

    $textBlocks = weather_extract_text_blocks($xpath);

    if (count($iconCodes) < 2 || count($temps) < 2 || count($textBlocks) < 2) {
        throw new RuntimeException('Wetterseite unvollstaendig geparst');
    }

    return [
        'today' => [
            'icon_code' => $iconCodes[0],
            'temp_min' => $temps[0]['min'],
            'temp_max' => $temps[0]['max'],
            'text' => $textBlocks[0],
        ],
        'tomorrow' => [
            'icon_code' => $iconCodes[1],
            'temp_min' => $temps[1]['min'],
            'temp_max' => $temps[1]['max'],
            'text' => $textBlocks[1],
        ],
    ];
}

/**
 * Parst wetter.orf.at/wien/mariabrunn/ (aktuelle Messwerte der Station
 * Mariabrunn, nicht die Prognose) -- Temperatur, Wind, Luftfeuchtigkeit,
 * Niederschlag (Nutzerwunsch 2026-08-22, ersetzt die interne SHT4x-
 * Sensorzeile des Geraets durch echte Aussenwerte).
 *
 * Markup ist ein einfaches <p><span>Label</span>...Wert <abbr>Einheit</abbr></p>
 * -- Werte werden ueber den Label-Text gefunden (sprachunabhaengig von
 * Spalten-Reihenfolge/CSS-Klassen, die Seite hat keine data-Attribute).
 *
 * @return array{temp_c: float, wind_kmh: int, wind_gusts_kmh: int, wind_direction: string, humidity_pct: int, precipitation_mm: float}
 * @throws RuntimeException wenn ein erwarteter Messwert fehlt
 */
function weather_parse_station(string $html): array
{
    $dom = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_use_internal_errors($prevErrors);
    $xpath = new DOMXPath($dom);

    $findValue = function (string $label) use ($xpath): string {
        $p = $xpath->query("//p[span[normalize-space(text())='$label']]")->item(0);
        if ($p === null) {
            throw new RuntimeException("Messwert '$label' nicht gefunden");
        }
        return trim($p->textContent);
    };

    // "Temperatur: 18,4 °C" -> 18.4
    $tempText = $findValue('Temperatur');
    if (!preg_match('/(-?\d+),(\d+)/', $tempText, $m)) {
        throw new RuntimeException('Temperatur nicht auswertbar: ' . $tempText);
    }
    $tempC = (float) ($m[1] . '.' . $m[2]);

    // "Wind: West, 11 km/h" -> Richtung "West", Geschwindigkeit 11
    $windText = $findValue('Wind');
    if (!preg_match('/:\s*(\S+),\s*(\d+)\s*km\/h/u', $windText, $m)) {
        throw new RuntimeException('Wind nicht auswertbar: ' . $windText);
    }
    $windDirection = $m[1];
    $windKmh = (int) $m[2];

    // "Windspitzen: West, 28 km/h" -> 28
    $gustsText = $findValue('Windspitzen');
    if (!preg_match('/:\s*\S+,\s*(\d+)\s*km\/h/u', $gustsText, $m)) {
        throw new RuntimeException('Windspitzen nicht auswertbar: ' . $gustsText);
    }
    $windGustsKmh = (int) $m[1];

    // "Luftfeuchtigkeit: 65 %" -> 65
    $humidityText = $findValue('Luftfeuchtigkeit');
    if (!preg_match('/(\d+)\s*%/', $humidityText, $m)) {
        throw new RuntimeException('Luftfeuchtigkeit nicht auswertbar: ' . $humidityText);
    }
    $humidityPct = (int) $m[1];

    // "Niederschlag: 0,0 mm/h" -> 0.0
    $precipText = $findValue('Niederschlag');
    if (!preg_match('/(\d+),(\d+)\s*mm/', $precipText, $m)) {
        throw new RuntimeException('Niederschlag nicht auswertbar: ' . $precipText);
    }
    $precipitationMm = (float) ($m[1] . '.' . $m[2]);

    return [
        'temp_c' => $tempC,
        'wind_kmh' => $windKmh,
        'wind_gusts_kmh' => $windGustsKmh,
        'wind_direction' => $windDirection,
        'humidity_pct' => $humidityPct,
        'precipitation_mm' => $precipitationMm,
    ];
}

/**
 * Sammelt aus .fulltextWrapper je das erste <p> nach jedem direkten <h2> in
 * Dokumentreihenfolge. Index 0 = heute, Index 1 = morgen (positional).
 *
 * @return list<string>
 */
function weather_extract_text_blocks(DOMXPath $xpath): array
{
    $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " fulltextWrapper ")]')->item(0);
    if ($wrapper === null) {
        throw new RuntimeException('fulltextWrapper nicht gefunden');
    }

    $blocks = [];
    foreach ($wrapper->childNodes as $node) {
        if (!($node instanceof DOMElement) || $node->tagName !== 'h2') {
            continue;
        }
        $next = $node->nextSibling;
        while ($next !== null && !($next instanceof DOMElement)) {
            $next = $next->nextSibling;
        }
        if ($next instanceof DOMElement && $next->tagName === 'p') {
            $blocks[] = trim($next->textContent);
        }
    }
    return $blocks;
}

/**
 * ORF liefert einen 6-stelligen numerischen Icon-Code (Klasse "c123456").
 * Diese Tabelle bildet ihn auf eine von neun Anzeige-Kategorien ab (Spec §8)
 * und waechst anhand geloggter, bislang unbekannter Codes -- kein
 * vollstaendiges Reverse-Engineering des ORF-Codesystems. Startwerte sind die
 * am 15.8.2026 auf der echten Seite beobachteten Codes.
 *
 * Kategorien: klar, leicht_bewoelkt, bewoelkt, bedeckt, regen_leicht,
 * regen_stark, schnee, gewitter, nebel, unbekannt (Fallback).
 */
const WEATHER_ICON_CATEGORIES = [
    '100000' => 'klar',
    '110000' => 'leicht_bewoelkt',
    '112000' => 'regen_leicht',   // "leicht bewölkt mit (starkem) Niederschlag"
    '120000' => 'leicht_bewoelkt', // Sonne hinter Wolken, kein Niederschlag -- live am 2026-08-25 gegen ORF geprueft (vorherige Ziffern-Vermutung "bewoelkt" war falsch)
    '122000' => 'regen_stark',    // "stark bewölkt mit starkem Niederschlag"
    '122001' => 'gewitter',       // "stark bewölkt mit starkem Niederschlag und Gewitter"
];

/**
 * @return array{category: string, known: bool}
 */
function weather_map_icon_code(string $code): array
{
    if (isset(WEATHER_ICON_CATEGORIES[$code])) {
        return ['category' => WEATHER_ICON_CATEGORIES[$code], 'known' => true];
    }
    return ['category' => 'unbekannt', 'known' => false];
}

/**
 * Waehlt aus dem Wetter-Cache die fuer JETZT richtige Anzeige-Scheibe:
 * vor 19:00 Europe/Vienna "today", ab 19:00 "tomorrow" (Spec §8). Ist der
 * Cache aelter als 6h, wird NUR der Fliesstext durch eine Fehlermeldung
 * ersetzt -- Icon und Temperatur bleiben unveraendert stehen.
 *
 * 'station' (Mariabrunn-Messwerte, 2026-08-22) hat eine EIGENE fetched_at --
 * Prognose- und Stationsabruf koennen unabhaengig voneinander fehlschlagen
 * (s. scripts/weather_fetch_cron.php), deshalb eigene Alters-/
 * Verfuegbarkeitspruefung statt der Prognose-Frische mitzubenutzen.
 *
 * @param ?array{fetched_at: string, today: array, tomorrow: array, station?: array, station_fetched_at?: string} $cache
 * @return array{available: bool, icon_category?: string, temp_min?: int, temp_max?: int, text?: ?string, text_error?: ?string, station: array{available: bool, temp_c?: float, humidity_pct?: int, wind_kmh?: int, wind_gusts_kmh?: int, wind_direction?: string, precipitation_mm?: float}}
 */
/**
 * "today" vor 19:00 Europe/Vienna, sonst "tomorrow" (Spec §8) -- EINZIGE
 * Stelle, die diese Schwelle kennt. weather_select_display() nutzt sie fuer
 * die Datenauswahl, board_render_weather_svg() fuer die dazu passende
 * Ueberschrift ("Heute"/"Morgen"). Vor 2026-08-23 wich das auseinander: die
 * Ueberschrift stand hartcodiert auf "Heute", auch wenn ab 19 Uhr laengst die
 * Morgen-Prognose angezeigt wurde.
 */
function weather_display_period(DateTimeImmutable $now): string
{
    $vienna = new DateTimeZone('Europe/Vienna');
    return ((int) $now->setTimezone($vienna)->format('H') < 19) ? 'today' : 'tomorrow';
}

function weather_select_display(?array $cache, DateTimeImmutable $now): array
{
    $station = weather_select_station_display($cache, $now);
    $period = weather_display_period($now);

    if ($cache === null) {
        return ['available' => false, 'station' => $station, 'period' => $period];
    }

    $vienna = new DateTimeZone('Europe/Vienna');
    $slice = $cache[$period];

    $mapping = weather_map_icon_code($slice['icon_code']);

    $fetchedAt = new DateTimeImmutable($cache['fetched_at']);
    $ageSeconds = $now->getTimestamp() - $fetchedAt->getTimestamp();
    $stale = $ageSeconds > 6 * 3600;

    return [
        'available' => true,
        'icon_category' => $mapping['category'],
        'temp_min' => $slice['temp_min'],
        'temp_max' => $slice['temp_max'],
        'text' => $stale ? null : $slice['text'],
        'text_error' => $stale
            ? 'Wetterbericht veraltet seit ' . $fetchedAt->setTimezone($vienna)->format('H:i')
            : null,
        'station' => $station,
        'period' => $period,
    ];
}

/**
 * @param ?array{station?: array, station_fetched_at?: string} $cache
 * @return array{available: bool, temp_c?: float, humidity_pct?: int, wind_kmh?: int, wind_gusts_kmh?: int, wind_direction?: string, precipitation_mm?: float}
 */
function weather_select_station_display(?array $cache, DateTimeImmutable $now): array
{
    $requiredFields = ['temp_c', 'humidity_pct', 'wind_kmh', 'wind_gusts_kmh', 'wind_direction', 'precipitation_mm'];
    if ($cache === null || !isset($cache['station'], $cache['station_fetched_at'])
        || array_diff($requiredFields, array_keys($cache['station'])) !== []) {
        // Fehlt ein Feld (z.B. Cache-Datei von vor der wind_gusts_kmh-
        // Erweiterung 2026-08-22, direkt nach einem Deploy und vor dem
        // naechsten Cron-Lauf), lieber "nicht verfuegbar" als eine kaputte
        // Kartenzeile/PHP-Warning in der Bild-Antwort.
        return ['available' => false];
    }

    $fetchedAt = new DateTimeImmutable($cache['station_fetched_at']);
    $ageSeconds = $now->getTimestamp() - $fetchedAt->getTimestamp();
    if ($ageSeconds > 6 * 3600) {
        return ['available' => false];
    }

    return array_merge(['available' => true], $cache['station']);
}

/**
 * Wien, fuer die Sonnenstandsberechnung. Stadtmitte statt Messstation --
 * ueber das Stadtgebiet unterscheiden sich die Zeiten um weniger als eine
 * Minute, und die Anzeige rundet ohnehin auf Minuten.
 */
const WEATHER_VIENNA_LAT = 48.2082;
const WEATHER_VIENNA_LON = 16.3738;

/**
 * Sonnenauf- und -untergang fuer einen Tag.
 *
 * BEWUSST GERECHNET statt gescrapt: date_sun_info() ist exakt, braucht kein
 * Netz, keinen Cache und keinen Cron-Lauf -- also auch keinen weiteren
 * Ausfallpfad im Bildrender. Die ORF-Seiten fuehren die Zeiten zwar auch,
 * aber dafuer eine dritte Scraping-Quelle samt Veralterungslogik zu bauen,
 * waere fuer eine Groesse, die sich aus Datum und Koordinaten ergibt,
 * unverhaeltnismaessig.
 *
 * Rein: haengt nur an $day, ruft selbst kein date()/time() (vgl. Kopf von
 * inc/board_template.php).
 *
 * @return array{available: bool, sunrise?: DateTimeImmutable, sunset?: DateTimeImmutable}
 */
function weather_sun_times(DateTimeImmutable $day): array
{
    $info = date_sun_info($day->getTimestamp(), WEATHER_VIENNA_LAT, WEATHER_VIENNA_LON);

    // In Polarnaehe liefert date_sun_info() bool statt Zeitstempel (Sonne geht
    // gar nicht auf bzw. gar nicht unter). Fuer Wien kann das nicht eintreten,
    // aber ein bool wuerde hier sonst still zu "01:00" werden.
    if (!is_int($info['sunrise'] ?? null) || !is_int($info['sunset'] ?? null)) {
        return ['available' => false];
    }

    $tz = $day->getTimezone();

    return [
        'available' => true,
        'sunrise'   => (new DateTimeImmutable('@' . $info['sunrise']))->setTimezone($tz),
        'sunset'    => (new DateTimeImmutable('@' . $info['sunset']))->setTimezone($tz),
    ];
}

/**
 * Beide Prognose-Scheiben fuer den Schlafschirm (inc/board_sleep.php).
 *
 * weather_select_display() liefert bewusst nur EINE Scheibe -- ab 19:00 die
 * von morgen, weil der Abfahrtsmonitor am Abend die relevante Prognose zeigen
 * soll. Der Schlafschirm hat Platz fuer beide und beschriftet sie auch als
 * das, was sie sind: die Cache-Felder "today" und "tomorrow".
 *
 * Die Veralterungsregel ist dieselbe wie bei weather_select_display(): aelter
 * als 6h ersetzt NUR den Fliesstext, Icon und Temperatur bleiben stehen.
 *
 * @param ?array{fetched_at: string, today: array, tomorrow: array, station?: array, station_fetched_at?: string} $cache
 * @return array{today: array, tomorrow: array}
 */
function weather_select_two_days(?array $cache, DateTimeImmutable $now): array
{
    $unavailable = ['available' => false];

    if ($cache === null || !isset($cache['today'], $cache['tomorrow'], $cache['fetched_at'])) {
        return ['today' => $unavailable + ['station' => weather_select_station_display($cache, $now)], 'tomorrow' => $unavailable];
    }

    $vienna = new DateTimeZone('Europe/Vienna');
    $fetchedAt = new DateTimeImmutable($cache['fetched_at']);
    $stale = ($now->getTimestamp() - $fetchedAt->getTimestamp()) > 6 * 3600;
    $staleText = 'Wetterbericht veraltet seit ' . $fetchedAt->setTimezone($vienna)->format('H:i');

    $slice = static function (array $raw) use ($stale, $staleText): array {
        return [
            'available'     => true,
            'icon_category' => weather_map_icon_code($raw['icon_code'])['category'],
            'temp_min'      => $raw['temp_min'],
            'temp_max'      => $raw['temp_max'],
            'text'          => $stale ? null : $raw['text'],
            'text_error'    => $stale ? $staleText : null,
        ];
    };

    return [
        // Nur "heute" bekommt die Stationsmesswerte -- ein aktueller Messwert
        // fuer morgen waere ein Widerspruch in sich.
        'today'    => $slice($cache['today']) + ['station' => weather_select_station_display($cache, $now)],
        'tomorrow' => $slice($cache['tomorrow']),
    ];
}
