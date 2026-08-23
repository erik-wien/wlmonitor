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
const BOARD_SLEEP_FOOTER_Y    = 1330;

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

/** Ab hier beginnt rechts der WLAN-Block -- so weit darf der Morgen-Text
 *  reichen. Links begrenzt die Fusszeile. */
const BOARD_SLEEP_WIFI_TITLE_Y    = 830;
const BOARD_SLEEP_TOMORROW_MAX_Y  = 760;
const BOARD_SLEEP_TODAY_MAX_Y     = 1280;

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
 */
function board_sleep_render_svg(
    array $today,
    array $tomorrow,
    ?array $sun,
    ?array $wifi,
    DateTimeImmutable $renderedAt
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

    if ($tomorrow['available']) {
        $iconId = BOARD_ICON_ID_BY_CATEGORY[$tomorrow['icon_category']] ?? BOARD_ICON_ID_BY_CATEGORY['unbekannt'];
        $right .= sprintf('<g transform="translate(1290,330) scale(9)"><use href="#%s"/></g>', $iconId);
        $right .= sprintf(
            '<text x="1450" y="348" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="52" fill="black">%d–%d°C</text>',
            $tomorrow['temp_min'], $tomorrow['temp_max']
        );

        $lines = board_sleep_fit_lines(
            (string) ($tomorrow['text'] ?? $tomorrow['text_error'] ?? ''), BOARD_SLEEP_TOMORROW_CHARS,
            BOARD_SLEEP_TOMORROW_TEXT_Y, BOARD_SLEEP_TOMORROW_LEAD, BOARD_SLEEP_TOMORROW_MAX_Y
        );
        foreach ($lines as $i => $line) {
            $right .= sprintf(
                '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="44" fill="black">%s</text>',
                BOARD_SLEEP_RIGHT_X, BOARD_SLEEP_TOMORROW_TEXT_Y + $i * BOARD_SLEEP_TOMORROW_LEAD, $esc($line)
            );
        }
    }

    // --- rechts unten: Gaeste-WLAN ------------------------------------------
    if ($wifi !== null) {
        // Erst bauen, dann waagrecht in der Spalte zentrieren: die
        // Matrixgroesse haengt von der Laenge der Zugangsdaten ab (eine lange
        // SSID oder ein langes Passwort ergibt eine groessere Matrix), also
        // steht die endgueltige Kantenlaenge erst nach dem Bauen fest.
        $qr = board_sleep_qr(board_guest_wifi_payload($wifi), BOARD_SLEEP_RIGHT_X, 915, 365);
        $qrOffsetX = intdiv(BOARD_SLEEP_RIGHT_WIDTH - $qr['size'], 2);

        $right .= sprintf(
            '<text x="%d" y="' . BOARD_SLEEP_WIFI_TITLE_Y . '" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="46" fill="black">Gäste-WLAN</text>',
            BOARD_SLEEP_RIGHT_X
        );
        $right .= sprintf(
            '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="40" fill="black">%s</text>',
            BOARD_SLEEP_RIGHT_X, BOARD_SLEEP_WIFI_TITLE_Y + 52, $esc($wifi['ssid'])
        );
        $right .= sprintf('<g transform="translate(%d,0)">%s</g>', $qrOffsetX, $qr['svg']);
    }

    $footer = sprintf(
        '<line x1="0" y1="%d" x2="1872" y2="%d" stroke="black" stroke-width="2"/>'
        . '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-size="28" fill="black">Stand %s</text>',
        BOARD_SLEEP_FOOTER_Y, BOARD_SLEEP_FOOTER_Y,
        BOARD_SLEEP_LEFT_X, BOARD_SLEEP_FOOTER_Y + 46, $renderedAt->format('H:i')
    );

    $divider = sprintf(
        '<line x1="%d" y1="90" x2="%d" y2="%d" stroke="black" stroke-width="2"/>',
        BOARD_SLEEP_DIVIDER_X, BOARD_SLEEP_DIVIDER_X, BOARD_SLEEP_FOOTER_Y
    );

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
</svg>
SVG;
}
