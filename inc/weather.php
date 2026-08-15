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

    $iconCodes = [];
    foreach ($xpath->query('.//td', $iconRow) as $td) {
        $sp = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " weatherIcon ")]', $td)->item(0);
        if ($sp === null || !preg_match('/(?:^|\s)c(\d{6})(?:\s|$)/', (string) $sp->getAttribute('class'), $m)) {
            throw new RuntimeException('Icon-Code nicht gefunden');
        }
        $iconCodes[] = $m[1];
    }

    $temps = [];
    foreach ($xpath->query('.//td', $tempRow) as $td) {
        $highest = $xpath->query('.//span[contains(@class,"highest")]', $td)->item(0);
        if ($highest === null) {
            throw new RuntimeException('Hoechsttemperatur nicht gefunden');
        }
        // ORF laesst die Tages-Tiefsttemperatur ("morning") beim laufenden Tag
        // manchmal weg. Fehlt sie, faellt temp_min auf temp_max zurueck, statt
        // den ganzen Abruf scheitern zu lassen.
        $morning = $xpath->query('.//span[contains(@class,"morning")]', $td)->item(0);
        $max = (int) preg_replace('/\D+/', '', $highest->textContent);
        $min = $morning !== null ? (int) preg_replace('/\D+/', '', $morning->textContent) : $max;
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
