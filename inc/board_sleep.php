<?php
// inc/board_sleep.php
//
// Schlafschirm (Nutzerwunsch 2026-08-23): "Bei naeherer Betrachtung macht der
// Abfahrtsmonitor im Schlafmodus wenig Sinn." Statt der eingefrorenen
// Abfahrtenliste steht waehrend des Tiefschlafs -- also die meiste Zeit --
// ein eigenes Bild: Wetter heute und morgen plus ein QR-Code fuers
// Gaeste-WLAN.
//
// Reine Funktionen wie inc/board_template.php: kein $con, kein Netz, keine
// date()/time()-Aufrufe. Der QR-Code wird aus chillerlan/php-qrcode gebaut
// (liegt bereits ueber erikr/auth im vendor, keine neue Abhaengigkeit).
declare(strict_types=1);

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// --- Raster -----------------------------------------------------------------

const BOARD_SLEEP_DIVIDER_X   = 1120;
const BOARD_SLEEP_LEFT_X      = 56;
const BOARD_SLEEP_RIGHT_X     = 1160;
const BOARD_SLEEP_RIGHT_WIDTH = 656; // 1160 bis 1816

/** Zeichen pro Zeile, aus der Spaltenbreite und der gemessenen mittleren
 *  Zeichenbreite von Atkinson Hyperlegible Next (0,445 * Schriftgroesse),
 *  mit 8% Sicherheitsabstand -- gleiche Herleitung wie
 *  BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE in inc/board_template.php. */
const BOARD_SLEEP_TODAY_CHARS    = 42; // 1034px bei 50px Schrift
const BOARD_SLEEP_TOMORROW_CHARS = 30; // 656px bei 44px Schrift

/** Erste Textzeile und Zeilenabstand je Spalte. */
const BOARD_SLEEP_TODAY_TEXT_Y    = 730;
const BOARD_SLEEP_TODAY_LEAD      = 62;
const BOARD_SLEEP_TOMORROW_TEXT_Y = 470;
const BOARD_SLEEP_TOMORROW_LEAD   = 54;

/** Abstand zwischen QR-Code und Morgen-Text darueber -- die Untergrenze des
 *  Textes (wieviel Zeilen passen) wird aus der TATSAECHLICHEN QR-Position
 *  gerechnet, nicht mehr aus einer festen Konstante (s. board_sleep_render_svg()):
 *  liegt keine data/guest_wifi.json vor, darf der Text bis zur Fusszeile
 *  laufen; liegt eine vor, richtet sich die Grenze nach der QR-Groesse (die
 *  mit den Zugangsdaten schwankt). */
const BOARD_SLEEP_WIFI_BOTTOM_GAP = 16;
const BOARD_SLEEP_WIFI_TEXT_GAP   = 58;
// Untergrenze OHNE Paginierungs-Pille: "Stand HH:MM" steht bei x=16, also
// LINKS, die rechte Spalte ist auf dem Schlaf-Frame komplett frei bis zur
// Fusszeilenlinie (y=1310) -- BOARD_SLEEP_TODAY_MAX_Y waere hier falsch,
// die ist mit Ruecksicht auf die Pille kalibriert, die es auf diesem Frame
// gar nicht gibt (Nutzerbefund 2026-08-25, zweiter Anlauf: "immer noch
// mindestens eine Zeile frei" -- der erste Fix sparte nur 6px statt ~80px).
const BOARD_SLEEP_WIFI_NO_PILL_MAX_Y = 1294;
// 1230 statt frueher 1280 (Nutzerwunsch 2026-08-23: "der Schlafschirm sollte
// die Paginierung zeigen, solange das Geraet nicht effektiv schlaeft") --
// die Pille sitzt jetzt bei y=1252..1308 (BOARD_PAGINATION_TOP/HEIGHT in
// board_template.php), 22px Sicherheitsabstand zur Textgrundlinie.
const BOARD_SLEEP_TODAY_MAX_Y     = 1230;

/**
 * QR-Code als <rect>-Gitter.
 *
 * BEWUSST die Matrix selbst zeichnen statt QRMarkupSVG einzubetten: so laesst
 * sich die Modulgroesse auf GANZE Pixel zwingen. Bei krummer Modulgroesse
 * landen die Modulkanten zwischen den Pixeln, rsvg-convert erzeugt Graustufen
 * und die 1bpp-Schwelle macht daraus ausgefranste Module -- auf e-Paper genau
 * das, was ein Scanner nicht mehr sicher liest.
 *
 * Die Ruhezone (4 Module, Vorgabe der QR-Spezifikation) ist eingerechnet: das
 * zurueckgegebene Bild ist $moduleSize * (Matrixgroesse + 8) Pixel gross,
 * board_sleep_qr_size() nennt den Wert vorab.
 *
 * @return array{svg: string, size: int, modules: int}
 */
function board_sleep_qr(string $payload, int $x, int $y, int $targetSize): array
{
    $options = new QROptions([
        'eccLevel'   => EccLevel::M, // vertraegt ~15% Verlust -- Reserve gegen Reflexionen auf dem Glas
        'version'    => QRCode::VERSION_AUTO,
        'quietzone'  => false,       // Ruhezone zeichnen wir selbst ueber die Positionierung
    ]);

    $matrix = (new QRCode($options))->addByteSegment($payload)->getQRMatrix();
    $modules = $matrix->getSize();
    $quiet = 4;

    $moduleSize = max(1, intdiv($targetSize, $modules + 2 * $quiet));
    $originX = $x + $quiet * $moduleSize;
    $originY = $y + $quiet * $moduleSize;

    // Weisser Grund ueber die volle Flaeche inkl. Ruhezone -- ohne ihn liest
    // ein Scanner den Code vor beliebigem Hintergrund nicht zuverlaessig.
    $svg = sprintf(
        '<rect x="%d" y="%d" width="%d" height="%d" fill="white"/>',
        $x, $y, $moduleSize * ($modules + 2 * $quiet), $moduleSize * ($modules + 2 * $quiet)
    );

    // Zeilenweise zusammenfassen: aufeinanderfolgende dunkle Module werden ein
    // einziges <rect>. Das druckt dasselbe Bild, aber mit einem Bruchteil der
    // Elemente -- ein 37er-Code hat sonst ueber 400 Rechtecke, die rsvg-convert
    // alle einzeln rastern muss.
    for ($row = 0; $row < $modules; $row++) {
        $runStart = null;
        for ($col = 0; $col <= $modules; $col++) {
            $dark = $col < $modules && $matrix->isDark($matrix->get($col, $row));
            if ($dark && $runStart === null) {
                $runStart = $col;
            } elseif (!$dark && $runStart !== null) {
                $svg .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d" fill="black"/>',
                    $originX + $runStart * $moduleSize,
                    $originY + $row * $moduleSize,
                    ($col - $runStart) * $moduleSize,
                    $moduleSize
                );
                $runStart = null;
            }
        }
    }

    return [
        'svg'     => $svg,
        'size'    => $moduleSize * ($modules + 2 * $quiet),
        'modules' => $modules,
    ];
}

/**
 * Eine Wetter-Messwertzeile (Piktogramm + Text), gleiche Konvention wie in
 * board_render_weather_card(): Piktogrammmitte ~0,35 Schriftgroessen ueber
 * der Textgrundlinie.
 */
function board_sleep_row(string $iconId, int $iconX, int $textX, int $y, int $fontSize, string $textSvg): string
{
    return sprintf(
        '<use href="#%s" transform="translate(%d,%d) scale(%s)"/>',
        $iconId, $iconX, $y - (int) round($fontSize * 0.35), number_format($fontSize / 24 * 1.15, 2, '.', '')
    ) . sprintf(
        '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="%d" fill="black">%s</text>',
        $textX, $y, $fontSize, $textSvg
    );
}

/**
 * Bricht $text um und kuerzt ihn auf so viele Zeilen, wie zwischen $firstY und
 * $maxY passen -- die letzte bekommt dann ein Auslassungszeichen. Ohne diese
 * Begrenzung waere der Schlafschirm von der Laenge des ORF-Fliesstextes
 * abhaengig: der Morgen-Text lief bei sechs Zeilen schon bis dicht an den
 * WLAN-Block, ein laengerer waere hineingelaufen.
 *
 * @return list<string>
 */
function board_sleep_fit_lines(string $text, int $charsPerLine, int $firstY, int $lead, int $maxY): array
{
    $lines = board_wrap_text($text, $charsPerLine);
    $maxLines = max(1, (int) floor(($maxY - $firstY) / $lead) + 1);

    if (count($lines) <= $maxLines) {
        return $lines;
    }

    $lines = array_slice($lines, 0, $maxLines);
    $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1]) . '…';

    return $lines;
}

/**
 * Baut den kompletten Schlafschirm.
 *
 * @param array{available: bool, icon_category?: string, temp_min?: int, temp_max?: int, text?: ?string, text_error?: ?string, station: array} $today
 * @param array{available: bool, icon_category?: string, temp_min?: int, temp_max?: int, text?: ?string} $tomorrow
 * @param array{available: bool, sunrise?: DateTimeImmutable, sunset?: DateTimeImmutable}|null $sun
 * @param array{ssid: string, password: string, encryption: string, hidden: bool}|null $wifi
 *        null = keine data/guest_wifi.json -> der QR-Block entfaellt ersatzlos
 * @param int $totalPages Gesamtseitenzahl INKLUSIVE dieses Schlafschirm-Slots
 *        (board_total_pages()) -- der Schlafschirm ist strukturell immer die
 *        letzte Seite, seine eigene Seitenzahl ist also $totalPages.
 * @param bool $showPagination false = der ECHTE Vorschlaf-Abruf (Nutzerbefund
 *        2026-08-23: die Pille darf nicht sichtbar bleiben, waehrend das
 *        Panel tatsaechlich schlaeft -- ein Tipp taete dann nichts, der
 *        Touch-Controller wird bis zum naechsten Tastendruck nicht mehr
 *        abgefragt). "Stand HH:MM" bleibt in jedem Fall stehen.
 */
function board_sleep_render_svg(
    array $today,
    array $tomorrow,
    ?array $sun,
    ?array $wifi,
    DateTimeImmutable $renderedAt,
    int $totalPages,
    bool $showPagination = true,
    // Favoritenleiste, wenn der Schirm BEWUSST aufgeblaettert wurde
    // (Nutzerwunsch 2026-08-26): dann ist er eine normale Seite und ein Tipp
    // auf einen Favoriten soll funktionieren. Beim echten Vorschlaf-Abruf
    // entfaellt sie -- dasselbe Signal wie bei der Paginierung, denn danach
    // wird der Touch-Controller bis zum naechsten Tastendruck nicht mehr
    // abgefragt. Leere Liste = keine Favoriten, keine Leiste.
    array $touchBarFavoriteTitles = [],
    int $activeFavoriteIndex = 0,
    // Icon-Pille statt Ziffern (board_pagination_categories()) -- leer =
    // alte Ziffernpille, s. board_render_stand_and_pagination_svg().
    array $pageCategories = []
): string {
    $defs = board_svg_defs();
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1);

    // --- links: heute --------------------------------------------------------
    $left = '<text x="' . BOARD_SLEEP_LEFT_X . '" y="160" font-family="Atkinson Hyperlegible Next"'
        . ' font-weight="bold" font-size="64" fill="black">Heute</text>';

    if ($today['available']) {
        $iconId = BOARD_ICON_ID_BY_CATEGORY[$today['icon_category']] ?? BOARD_ICON_ID_BY_CATEGORY['unbekannt'];
        $left .= sprintf('<g transform="translate(250,350) scale(13)"><use href="#%s"/></g>', $iconId);

        $station = $today['station'] ?? ['available' => false];
        $rowIconX = 520;
        $rowTextX = 590;
        $y = 265;

        $left .= board_sleep_row('iconTemp', $rowIconX, $rowTextX, $y, 48, $station['available']
            ? sprintf('<tspan font-weight="bold">%s°</tspan> %d–%d°C', number_format($station['temp_c'], 1), $today['temp_min'], $today['temp_max'])
            : sprintf('%d–%d°C', $today['temp_min'], $today['temp_max']));

        // Anders als auf dem Hauptbild duerfen die Zeilen hier FORTLAUFEND
        // sitzen: das Hauptbild braucht feste Positionen, damit nichts
        // springt, wenn die bedingte Niederschlagszeile erscheint. Der
        // Schlafschirm wird pro Schlafzyklus genau einmal gezeichnet -- ein
        // "Springen" zwischen zwei Bildern sieht niemand, und eine feste
        // Position hinterliesse bei trockenem Wetter ein sichtbares Loch.
        if ($station['available']) {
            $y += 80;
            $left .= board_sleep_row('iconDroplet', $rowIconX, $rowTextX, $y, 48, sprintf('%d%%', $station['humidity_pct']));
            $y += 80;
            $left .= board_sleep_row('iconWind', $rowIconX, $rowTextX, $y, 48,
                sprintf('%d–%d km/h', $station['wind_kmh'], $station['wind_gusts_kmh']));
            if ($station['precipitation_mm'] > 0) {
                $y += 80;
                $left .= board_sleep_row('iconDroplets', $rowIconX, $rowTextX, $y, 48,
                    sprintf('%s mm/h', number_format($station['precipitation_mm'], 1)));
            }
        }

        if ($sun !== null && ($sun['available'] ?? false)) {
            $y += 80;
            $left .= board_sleep_row('iconSunrise', $rowIconX, $rowTextX, $y, 48, $sun['sunrise']->format('H:i'));
            $left .= board_sleep_row('iconSunset', $rowIconX + 270, $rowTextX + 270, $y, 48, $sun['sunset']->format('H:i'));
        }

        $bodyText = $today['text'] ?? $today['text_error'] ?? '';
        $lines = board_sleep_fit_lines(
            $bodyText, BOARD_SLEEP_TODAY_CHARS,
            BOARD_SLEEP_TODAY_TEXT_Y, BOARD_SLEEP_TODAY_LEAD, BOARD_SLEEP_TODAY_MAX_Y
        );
        foreach ($lines as $i => $line) {
            $left .= sprintf(
                '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="50" fill="black">%s</text>',
                BOARD_SLEEP_LEFT_X, BOARD_SLEEP_TODAY_TEXT_Y + $i * BOARD_SLEEP_TODAY_LEAD, $esc($line)
            );
        }
    } else {
        $left .= sprintf(
            '<text x="%d" y="360" font-family="Atkinson Hyperlegible Next" font-size="50" fill="black">Wetterdaten werden geladen …</text>',
            BOARD_SLEEP_LEFT_X
        );
    }

    // --- rechts oben: morgen -------------------------------------------------
    $right = '<text x="' . BOARD_SLEEP_RIGHT_X . '" y="160" font-family="Atkinson Hyperlegible Next"'
        . ' font-weight="bold" font-size="64" fill="black">Morgen</text>';

    // WLAN-Block ZUERST vermessen, aber noch nicht anhaengen: seine Hoehe
    // haengt von der QR-Matrixgroesse ab (laengere Zugangsdaten = groesserer
    // Code), und erst daraus ergibt sich, wieviel Platz der Morgen-Text
    // darueber wirklich hat. Vorher standen Blockanfang (830) und Textgrenze
    // (760) fest verdrahtet da, kalibriert auf den groessten denkbaren QR --
    // bei einem kleineren blieb unter dem Code toter Raum stehen, WAEHREND
    // der Morgen-Text darueber abgeschnitten wurde (Nutzerbefund 2026-08-25:
    // "unnoetig gekuerzt").
    $qr = null;
    $qrTranslateY = 0;
    // Ohne WLAN-Block (keine data/guest_wifi.json) ist die ganze Spalte frei --
    // dann gilt dieselbe Untergrenze wie links, statt den Text bei 760 zu
    // kappen und darunter eine halbe leere Spalte stehen zu lassen
    // (Nutzerbefund 2026-08-25: "unnoetig gekuerzt" -- auf akadbrain fehlt
    // die Datei, dort war genau das der sichtbare Fall).
    $tomorrowMaxY = BOARD_SLEEP_TODAY_MAX_Y;

    if ($wifi !== null) {
        // y=0 bauen und beim Anhaengen per translate an die endgueltige
        // Position schieben -- so ist die Groesse vor der Platzierung bekannt.
        $qr = board_sleep_qr(board_guest_wifi_payload($wifi), BOARD_SLEEP_RIGHT_X, 0, 340);

        // QR ueber der Fusszeile, was dann noch bleibt, gehoert dem Text.
        // Kein "WLAN"-Titel und keine eigene SSID-Zeile mehr darueber
        // (Nutzerwunsch 2026-08-25: "WLAN steht immer noch da, weg damit" --
        // die SSID steht jetzt NEBEN dem QR, s. unten). Die Paginierungs-Pille
        // (y ab BOARD_PAGINATION_TOP) braucht nur Platz, wenn sie ueberhaupt
        // gezeichnet wird -- auf dem erzwungenen letzten Frame vor dem
        // Tiefschlaf ($showPagination=false) waere das reservierte ~74px
        // sonst leerer Raum unter dem QR gewesen.
        $qrBottomLimit = $showPagination ? (BOARD_PAGINATION_TOP - BOARD_SLEEP_WIFI_BOTTOM_GAP) : BOARD_SLEEP_WIFI_NO_PILL_MAX_Y;
        $qrTranslateY = $qrBottomLimit - $qr['size'];
        $tomorrowMaxY = $qrTranslateY - BOARD_SLEEP_WIFI_TEXT_GAP;
    }

    if ($tomorrow['available']) {
        $iconId = BOARD_ICON_ID_BY_CATEGORY[$tomorrow['icon_category']] ?? BOARD_ICON_ID_BY_CATEGORY['unbekannt'];
        $right .= sprintf('<g transform="translate(1290,330) scale(9)"><use href="#%s"/></g>', $iconId);
        $right .= sprintf(
            '<text x="1450" y="348" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="52" fill="black">%d–%d°C</text>',
            $tomorrow['temp_min'], $tomorrow['temp_max']
        );

        $lines = board_sleep_fit_lines(
            (string) ($tomorrow['text'] ?? $tomorrow['text_error'] ?? ''), BOARD_SLEEP_TOMORROW_CHARS,
            BOARD_SLEEP_TOMORROW_TEXT_Y, BOARD_SLEEP_TOMORROW_LEAD, $tomorrowMaxY
        );
        foreach ($lines as $i => $line) {
            $right .= sprintf(
                '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="44" fill="black">%s</text>',
                BOARD_SLEEP_RIGHT_X, BOARD_SLEEP_TOMORROW_TEXT_Y + $i * BOARD_SLEEP_TOMORROW_LEAD, $esc($line)
            );
        }
    }

    // --- rechts unten: Gaeste-WLAN -------------------------------------------
    if ($qr !== null) {
        // QR rechtsbuendig, SSID links daneben statt darueber -- kein
        // eigener "WLAN"-Titel mehr (Nutzerwunsch 2026-08-25: "weg damit").
        $qrOffsetX = BOARD_SLEEP_RIGHT_WIDTH - $qr['size'];
        $ssidY = $qrTranslateY + intdiv($qr['size'], 2) + 14; // vertikal zur QR-Mitte zentriert

        $right .= sprintf(
            '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="40" fill="black">%s</text>',
            BOARD_SLEEP_RIGHT_X, $ssidY, $esc($wifi['ssid'])
        );
        $right .= sprintf('<g transform="translate(%d,%d)">%s</g>', $qrOffsetX, $qrTranslateY, $qr['svg']);
    }

    // "Stand HH:MM" + Seitenzahlen-Pille -- DIESELBE Funktion, DIESELBE
    // Position wie auf der Abfahrten-/Stoerungsseite (Nutzerwunsch
    // 2026-08-23: "der Schlafschirm sollte die Paginierung zeigen, so lange
    // das Geraet nicht effektiv schlaeft, sonst gibts nur den Tastenweg
    // zurueck"). Das ist kein Stilentscheid, sondern Pflicht: die Firmware
    // erkennt einen Tipp auf die Pille rein anhand fester Bildschirmkoordi-
    // naten (y=1252..1308, rechtsbuendig an x=1083, s. touch_zone.cpp),
    // unabhaengig davon, was dort tatsaechlich gezeichnet ist. Eine andere
    // Position waere unsichtbar tippbar oder sichtbar untippbar. Der
    // Schlafschirm ist strukturell immer die letzte Seite, seine eigene
    // Seitenzahl ist also $totalPages.
    $footer = board_render_stand_and_pagination_svg($renderedAt, $totalPages, $totalPages, $showPagination, $pageCategories);

    $divider = sprintf(
        '<line x1="%d" y1="90" x2="%d" y2="1310" stroke="black" stroke-width="2"/>',
        BOARD_SLEEP_DIVIDER_X, BOARD_SLEEP_DIVIDER_X
    );

    $touchBar = ($showPagination && $touchBarFavoriteTitles !== [])
        ? board_render_touch_bar_svg($touchBarFavoriteTitles, $activeFavoriteIndex)
        : '';

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1872" height="1404" viewBox="0 0 1872 1404">
<defs>
{$defs}
</defs>
<rect width="1872" height="1404" fill="white"/>
{$divider}
{$left}
{$right}
{$footer}
{$touchBar}
</svg>
SVG;
}
