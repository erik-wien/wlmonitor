<?php
// inc/board_template.php
//
// Board-SVG-Template aus Spec §9: SVG-Grundformen, Kopf-/Fusszeile,
// Wetterkarte, Abfahrtenliste. Ausschliesslich reine Funktionen -- kein
// $con, kein Netz, keine date()/time()-Aufrufe (Zeitwerte kommen als
// DateTimeImmutable herein), analog zu inc/board.php und inc/weather.php.
declare(strict_types=1);

// board_render_chrome_svg() ruft board_battery_is_charging_mv() und
// board_battery_label() -- die leben in board.php. Bisher hing es daran, dass
// jeder Aufrufer beides ohnehin laedt; ein Skript, das nur den Renderer
// braucht, lief in "Call to undefined function" (beim Rendern eines
// Vorschaubildes aufgefallen, 2026-09-04). board.php hat selbst keine
// Requires, ein Zirkel ist also ausgeschlossen.
require_once __DIR__ . '/board.php';

/** board_type()-Ergebnis (inc/board.php) -> Badge-Id aus board_svg_defs(). */
const BOARD_BADGE_SHAPE_BY_TYPE = [
    'metro' => 'badgeMetro',
    'tram'  => 'badgeTram',
    'bus'   => 'badgeBus',
    'train' => 'badgeTrain',
    // 'other' ist board_type()s Fallback fuer nicht erkannte WL-Fahrzeugtypen;
    // die "train"-Form (ungefuellter Rahmen) wirkt am neutralsten dafuer.
    'other' => 'badgeTrain',
];

/** icon_category aus weather_map_icon_code() (inc/weather.php) -> Icon-Id. */
const BOARD_ICON_ID_BY_CATEGORY = [
    'klar'             => 'icon_klar',
    'leicht_bewoelkt'  => 'icon_leicht_bewoelkt',
    'bewoelkt'         => 'icon_bewoelkt',
    'bedeckt'          => 'icon_bedeckt',
    'regen_leicht'     => 'icon_regen_leicht',
    'regen_stark'      => 'icon_regen_stark',
    'schnee'           => 'icon_schnee',
    'gewitter'         => 'icon_gewitter',
    'nebel'            => 'icon_nebel',
    'unbekannt'        => 'icon_unbekannt',
];

/**
 * Schriftgroesse fuer das Liniennummern-Label im Badge: 26px bei bis zu
 * zwei Zeichen (z.B. "U6", "18"), 24px bei drei oder mehr Zeichen (z.B.
 * "WLB") -- sonst wuerde das Label ueber den 68px-Badge-Rand hinausragen.
 * Bei U-Bahn/Strassenbahn ist das Label (fast immer 1-2 Zeichen) im Badge
 * spuerbar kleiner als bei Bus/Bahn -- 30% groesser auf Nutzerwunsch.
 */
function board_badge_label_font_size(string $label, string $badgeType = ''): int
{
    $base = mb_strlen($label, 'UTF-8') >= 3 ? 24 : 26;
    return in_array($badgeType, ['metro', 'tram'], true) ? (int) round($base * 1.3) : $base;
}

/**
 * Der komplette <defs>-Innenblock (ohne die <defs>-Tags selbst) aus Spec §9:
 * Badges (4 Formen) und Wetter-Icons (9 Kategorien + Fallback), aus
 * Kreis/Wolken-Outline/Linien-Grundformen gebaut, am gerenderten Bild
 * abgenommen (docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md
 * §9 "Icon-Set" + "Badges").
 */
/**
 * Wetter-Icons kommen als Tabler-Outline-Dateien aus assets/img/wetter/
 * (Nutzerwunsch 2026-08-22, ersetzt die handgezeichneten Formen). Mapping
 * 1:1 wo eine passende Datei existiert; "bewoelkt"/"bedeckt" teilen sich
 * cloud.svg (keine zwei Bewoelkungsgrad-Varianten verfuegbar). "regen_leicht"
 * (leicht bewoelkt + Niederschlag) bekommt cloud-sun-rain.svg statt der
 * reinen Regenwolke -- "regen_stark" (stark bewoelkt + Niederschlag) bleibt
 * bei cloud-rain.svg, da dort keine Sonne mehr zu sehen waere.
 * cloud-sun.svg/cloud-sun-rain.svg kommen aus Lucide statt Tabler (Tabler hat
 * keine Sonne-Wolke-Kombi-Icons; Lucide ist derselbe offene MIT-Icon-Stil,
 * 2026-08-25 -- ersetzt eine vorherige handgebaute Version mit falschem
 * Format, die deshalb nicht rendern wollte).
 * icon_unbekannt bleibt handgezeichnet -- kein Wetterzustand, sondern ein
 * UI-Fallback ("Icon-Code nicht erkannt").
 */
const BOARD_WEATHER_ICON_FILES = [
    'icon_klar'            => 'sun.svg',
    'icon_leicht_bewoelkt' => 'cloud-sun.svg',
    'icon_bewoelkt'        => 'cloud.svg',
    'icon_bedeckt'         => 'cloud.svg',
    'icon_regen_leicht'    => 'cloud-sun-rain.svg',
    'icon_regen_stark'     => 'cloud-rain.svg',
    'icon_schnee'          => 'cloud-snow.svg',
    'icon_gewitter'        => 'cloud-bolt.svg',
    'icon_nebel'           => 'cloud-fog.svg',
];

function board_svg_defs(): string
{
    $weatherIcons = '';
    foreach (BOARD_WEATHER_ICON_FILES as $id => $file) {
        $weatherIcons .= sprintf("<g id=\"%s\">%s</g>\n", $id, board_read_weather_icon($file));
    }
    // Kleine Praefix-Icons fuer die Stationsmesswert-Zeilen (Nutzerwunsch
    // 2026-08-22): Temperatur, Luftfeuchtigkeit, Wind, Niederschlag.
    $rowIcons = sprintf('<g id="iconTemp">%s</g>' . "\n", board_read_weather_icon('temperature.svg'))
        . sprintf('<g id="iconDroplet">%s</g>' . "\n", board_read_weather_icon('droplet.svg'))
        . sprintf('<g id="iconWind">%s</g>' . "\n", board_read_weather_icon('wind.svg'))
        . sprintf('<g id="iconDroplets">%s</g>' . "\n", board_read_weather_icon('droplets.svg'))
        . sprintf('<g id="iconSunrise">%s</g>' . "\n", board_read_weather_icon('sunrise.svg'))
        . sprintf('<g id="iconSunset">%s</g>' . "\n", board_read_weather_icon('sunset.svg'));

    return $weatherIcons . $rowIcons . <<<'SVG'
<g id="icon_unbekannt">
  <circle r="10" fill="white" stroke="black" stroke-width="2"/>
  <text x="0" y="4" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="13"
        fill="black" text-anchor="middle">?</text>
</g>

<g id="iconWarn">
  <polygon points="0,-13 13,10 -13,10" fill="white" stroke="black" stroke-width="2.5" stroke-linejoin="round"/>
  <text x="0" y="8" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="13"
        fill="black" text-anchor="middle">!</text>
</g>

<!-- Dieselbe Form wie iconWarn, aber in Textgroesse: das Warndreieck steht
     seit 2026-09-04 als eigenes Zeichen VOR dem Zielnamen, nicht mehr als
     Index an der Ecke der Linien-Badge (Nutzerbefund: "zu klein"). Auf 26px
     Gesamthoehe war es aus Zimmerentfernung nicht als Dreieck erkennbar; hier
     ist es 39px hoch und damit so gross wie die Versalhoehe des 55px-Ziel-
     namens daneben. Eigenes Symbol statt <use> mit scale(): der Strich soll
     NICHT mitwachsen (bei 1,5-facher Skalierung waere er 3,75px und wirkte
     ploetzlich fett gegenueber den anderen Umrissen auf der Seite). -->
<g id="iconWarnBig">
  <polygon points="0,-20 22,17 -22,17" fill="white" stroke="black" stroke-width="3" stroke-linejoin="round"/>
  <text x="0" y="14" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="24"
        fill="black" text-anchor="middle">!</text>
</g>

<g id="badgeTram"><circle r="34" fill="black"/></g>
<g id="badgeBus"><rect x="-34" y="-34" width="68" height="68" rx="14" fill="#404040"/></g>
<g id="badgeMetro"><rect x="-34" y="-34" width="68" height="68" fill="black"/></g>
<g id="badgeTrain"><rect x="-34" y="-34" width="68" height="68" rx="14" fill="white" stroke="black" stroke-width="5"/></g>
<g id="badgeWLB"><rect x="-34" y="-34" width="68" height="68" rx="14" fill="white" stroke="black" stroke-width="3"/>
  <path transform="scale(0.14) translate(-175,-150)" fill="black" d="m185,300c30,-52 60,-103 90,-155-58,-0-115,1-173,0 13,-22 25,-45 38,-67 56,-0 112,0 168,0 14,24 28,48 42,73-28,50-57,100-86,149-26,-0.2112-53,0-79,0zM167,0c-30,53-61,107-91,160 58,0 115,-0 173,0-13,22-26,45-39,67-56,0-112,0-167,0C28,203 14,179 0,154 29,102 58,51 88,0"/>
</g>
<g id="starNow" stroke-width="7" stroke-linecap="round">
  <line x1="0" y1="-15" x2="0" y2="15"/>
  <line x1="-13" y1="-7.5" x2="13" y2="7.5"/>
  <line x1="-13" y1="7.5" x2="13" y2="-7.5"/>
</g>
SVG;
}

/**
 * Liest assets/img/wl-logo.svg und liefert nur die inneren Elemente
 * (<title> + 5 <path>), ohne die aeusseren <svg>-Tags -- zur Einbettung in
 * eine eigene <g transform="..."> im Board-Template. Alle 5 Pfade sind
 * Pflicht: fehlt die Wortmarke (letzter Pfad, style="fill:#fff"), bleibt
 * nur ein schwarzes Rechteck sichtbar (siehe Spec §9, in dieser Session
 * live beobachteter Fehler beim manuellen Kopieren).
 *
 * Monochrom einbetten statt der echten Markenfarben -- bei 16 Graustufen
 * wuerden Rot/Dunkelblau sonst zu uneinheitlichen mittleren Grautoenen
 * quantisiert statt sauber Schwarz zu bleiben (Spec Global Constraints).
 *
 * @throws RuntimeException wenn die Datei fehlt oder nicht das erwartete
 *         <svg>...</svg>-Format hat
 */
function board_wl_logo_paths(): string
{
    $file = realpath(__DIR__ . '/../assets/img/wl-logo.svg');
    if ($file === false) {
        throw new RuntimeException('assets/img/wl-logo.svg nicht gefunden');
    }

    $raw = file_get_contents($file);
    if (!preg_match('/<svg[^>]*>(.*)<\/svg>/s', $raw, $m)) {
        throw new RuntimeException('assets/img/wl-logo.svg hat nicht das erwartete <svg>...</svg>-Format');
    }

    // Monochrom einbetten statt der echten Markenfarben -- bei 16 Graustufen
    // wuerden Rot/Dunkelblau sonst zu uneinheitlichen mittleren Grautoenen
    // quantisiert statt sauber Schwarz zu bleiben (Spec Global Constraints).
    $mono = str_replace(
        ['style="fill:#e3000f"', 'style="fill:#240c4b"', 'style="fill:#fff"'],
        ['fill="black"', 'fill="black"', 'fill="white"'],
        trim($m[1])
    );

    return $mono;
}

/**
 * Liest ein Tabler-Outline-Icon aus assets/img/wetter/ (24x24 Viewbox,
 * Strichzeichnung, fill/stroke auf dem AEUSSEREN <svg>-Tag statt pro Pfad --
 * ohne das explizit auf die uebernommene Gruppe zu setzen, waeren die Pfade
 * unsichtbar, sobald der <svg>-Rahmen abgeschnitten ist). cloud-sun.svg ist
 * die einzige Ausnahme (eigenstaendig mit fill/stroke gefaerbt, kein
 * currentColor) -- die Umwicklung bleibt dort leer.
 *
 * Auf den lokalen Mittelpunkt zentriert (translate(-12,-12)), damit
 * <use href="#..." transform="translate(x,y) scale(s)"/> dieselbe Konvention
 * wie die uebrigen Board-Symbole nutzt (Icon-Mitte = lokaler Ursprung).
 *
 * @throws RuntimeException wenn die Datei fehlt oder nicht das erwartete
 *         <svg>...</svg>-Format hat
 */
function board_read_weather_icon(string $filename): string
{
    $file = realpath(__DIR__ . '/../assets/img/wetter/' . $filename);
    if ($file === false) {
        throw new RuntimeException("assets/img/wetter/$filename nicht gefunden");
    }

    $raw = file_get_contents($file);
    if (!preg_match('/<svg[^>]*>(.*)<\/svg>/s', $raw, $m)) {
        throw new RuntimeException("assets/img/wetter/$filename hat nicht das erwartete <svg>...</svg>-Format");
    }

    $needsStrokeWrapper = str_contains($raw, 'stroke="currentColor"');
    $attrs = $needsStrokeWrapper
        ? ' fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        : '';

    return sprintf('<g transform="translate(-12,-12)"%s>%s</g>', $attrs, trim($m[1]));
}

/**
 * Fuellbreite des Akku-Balkens in Pixeln (0-48). Das Akku-Icon sitzt im
 * Kopfbereich bei transform="translate(1713,42)": Umriss-Rechteck lokal x=0
 * width=56 (absolut x=1713-1769), Polklemme bei lokal x=56 (absolut x=1769);
 * der Fuellbalken beginnt bei lokal x=4 (absolut x=1717), also max. 48px breit.
 *
 * Fuellung STUFENLOS, nicht in Vierteln: der Balken wird als SVG-Rechteck
 * gezeichnet, nicht aus festen Symbolen zusammengesetzt, also kostet jeder
 * Zwischenwert nichts (Nutzerentscheidung 2026-09-04, nachdem eine
 * Quantisierung auf 0/25/50/75/100 % erwogen war). Das naheliegende
 * Gegenargument -- jede Pixelaenderung erzeugt einen Bilddiff -- traegt hier
 * nicht: im selben Kopfbereich steht die Uhrzeit, der wird ohnehin jede
 * Minute neu geschrieben.
 *
 * Minimum 2px: bei 0 % soll ein Rest sichtbar bleiben, sonst ist ein leerer
 * Akku nicht von einem Rendering-Fehler zu unterscheiden.
 */
function board_battery_fill_width(int $percent): int
{
    $percent = max(0, min(100, $percent));
    return max(2, (int) round(48 * $percent / 100));
}

/**
 * Kopfzeile aus Spec (Stand 2026-08-16): Logo (schwarz/weiss), zentrierte
 * Server-Renderzeit, Akku+WLAN in einer Zeile rechtsbuendig auf x=1856,
 * plus die vertikale Spaltenlinie. Die Fusszeilen-Trennlinie zwischen Pille
 * und Touch-Leiste ist entfallen (Nutzerentscheidung 2026-09-01: ueberfluessig).
 * "Stand HH:MM" und die Touch-Leiste sind NICHT Teil dieser Funktion
 * (Task 6b bzw. Task 3b).
 */
function board_render_chrome_svg(
    DateTimeImmutable $renderedAt,
    int $batteryPercent,
    int $wifiBars,
    // Die Kalenderseite nutzt die volle Breite (Nutzerentscheidung 2026-08-26)
    // und braucht die Spaltentrennlinie deshalb nicht. Kopf- und Fusszeile
    // bleiben identisch -- nur der senkrechte Strich entfaellt.
    bool $withColumnDivider = true,
    // Gemessene Spannung des Geraets (X-Device-Battery-mV). Seit 2026-09-04
    // die EINZIGE Grundlage der Anzeige -- $batteryPercent kommt zwar weiter
    // vorberechnet herein (Aufrufer haben ihn ohnehin), die Entscheidung
    // "laedt gerade" und die Beschriftung haengen aber an der Spannung.
    // 0 = keine Messung im Request (dann kein Blitz, Balken auf 0).
    int $batteryMv = 0,
    // Admin-konfigurierbar (wl_board_settings, Migration 007).
    int $batteryChargingMv = BOARD_BATTERY_CHARGING_MV_DEFAULT,
    string $batteryDisplayMode = 'percent',
    // Auf dem echten Schlafschirm abgeschaltet: das Geraet zeigt dieses Bild
    // stundenlang weiter, eine eingefrorene Uhrzeit saehe dort aus wie eine
    // gueltige Angabe. Akku und WLAN frieren zwar genauso ein, sagen aber
    // etwas anderes -- sie beschreiben den Zustand BEIM Einschlafen, und
    // genau das will man spaeter wissen ("wann war die Batterie leer, bei
    // welcher Spannung", Nutzerwunsch 2026-09-04). Eine Uhrzeit beschreibt
    // dagegen nur sich selbst.
    bool $showClock = true
): string {
    $wifiBars = max(0, min(3, $wifiBars));
    $percent = max(0, min(100, $batteryPercent));
    // Oberhalb der Ladeschwelle ist der Messwert kein Ladestand mehr, sondern
    // die Aussage "haengt am Kabel" -- Blitz statt Wert. Sonst je nach
    // Schalterpille "87 %" oder "3,87 V"; der Balken fuellt sich in beiden
    // Faellen nach Prozent.
    $isCharging = $batteryMv > 0 && board_battery_is_charging_mv($batteryMv, $batteryChargingMv);

    // Der Blitz sitzt IM Akkusymbol und ersetzt dort den Fuellbalken -- der
    // Wert daneben bleibt in jedem Fall stehen.
    //
    // Frueher ersetzte der Blitz den WERT. Das war genau verkehrt herum
    // (Nutzerbefund 2026-09-04: "wenn das Kabel angeschlossen ist seh ich
    // sofort den Blitz, keine Volt"): kalibriert wird am oberen Ende der
    // Kurve, also am Kabel -- und ausgerechnet dort verschwand die Spannung,
    // die man dafuer ablesen muss.
    //
    // Der Fuellbalken darf dabei weichen, ohne dass Information verloren geht:
    // waehrend des Ladens ist die Spannung ohnehin von der Ladeschaltung
    // hochgetrieben, ein Fuellstand waere dort eine Behauptung. Nebenbei
    // erspart das auf 1bpp die Frage, ob der Blitz schwarz oder weiss sein
    // muesste -- auf einem teils gefuellten Balken waere er mal so, mal so.
    $fillOrBolt = $isCharging
        ? '<polygon points="10,0 0,14 7,14 4,26 17,10 9,10 12,0" fill="black" transform="translate(22,4) scale(0.69)"/>'
        : sprintf('<rect x="4" y="4" width="%d" height="18" fill="black"/>', board_battery_fill_width($percent));

    $percentSvg = sprintf(
        '<text x="1856" y="63" text-anchor="end" font-weight="bold" font-size="24">%s</text>',
        htmlspecialchars(board_battery_label($batteryMv, $percent, $batteryDisplayMode), ENT_XML1)
    );

    $wifiBarSpecs = [
        ['x' => 0,  'y' => 10, 'h' => 8],
        ['x' => 12, 'y' => 4,  'h' => 14],
        ['x' => 24, 'y' => -4, 'h' => 22],
    ];
    $wifiBarsSvg = '';
    foreach ($wifiBarSpecs as $i => $bar) {
        $filled = $i < $wifiBars;
        $wifiBarsSvg .= sprintf(
            '<rect x="%d" y="%d" width="8" height="%d" %s/>',
            $bar['x'], $bar['y'], $bar['h'],
            $filled ? 'fill="black"' : 'fill="white" stroke="black" stroke-width="2"'
        );
    }

    $dividerSvg = $withColumnDivider
        ? '<line x1="1113" y1="90" x2="1113" y2="1310" stroke="black" stroke-width="2"/>' . "\n"
        : '';

    $logo = board_wl_logo_paths();

    $clockSvg = $showClock
        ? sprintf(
            '<text x="936" y="55" font-family="Atkinson Hyperlegible Next" font-weight="bold"'
            . ' font-size="34" fill="black" text-anchor="middle">%s</text>',
            $renderedAt->format('H:i')
        )
        : '';

    return <<<SVG
<line x1="0" y1="90" x2="1872" y2="90" stroke="black" stroke-width="2"/>
<g transform="translate(24,12) scale(0.5025)">
{$logo}
</g>
{$clockSvg}

<g font-family="Atkinson Hyperlegible Next" fill="black">
  <g transform="translate(1665,46)">{$wifiBarsSvg}</g>
  <g transform="translate(1713,42)">
    <rect x="0" y="0" width="56" height="26" rx="3" fill="white" stroke="black" stroke-width="3"/>
    <rect x="56" y="7" width="7" height="12" fill="black"/>
    {$fillOrBolt}
  </g>
  {$percentSvg}
</g>

{$dividerSvg}
SVG;
}

/**
 * Touch-Leiste aus Spec: bis zu 3 Favoriten-Buttons, gleich breit ueber die
 * volle Breite verteilt (16px Rand/Luecke), Hoehe 74px, y=1320 bis y=1394,
 * rx=10. Aktiver Favorit schwarz gefuellt/weisses Label, inaktive weiss mit
 * 3px schwarzem Rand/schwarzem Label.
 *
 * @param list<string> $favoriteTitles 1-3 Titel, bereits fertig ermittelt
 *        (diese Funktion laedt selbst keine Favoriten).
 */
function board_render_touch_bar_svg(array $favoriteTitles, int $activeIndex, int $totalPages = 1): string
{
    if ($favoriteTitles === []) {
        return '';
    }

    $count = count($favoriteTitles);
    $margin = BOARD_NAV_MARGIN;
    $gap = BOARD_NAV_MARGIN;

    // Die Pille sitzt seit 2026-09-04 links in derselben Zeile -- die
    // Favoriten fangen erst dahinter an.
    //
    // Der Platz wird auch dann freigehalten, wenn die Pille GERADE nicht
    // gezeichnet wird (echter Schlafschirm, $showPagination=false): sonst
    // wuerden die Favoriten beim Einschlafen sichtbar aufspringen, und die
    // Firmware -- die nichts von $showPagination weiss -- rechnete mit
    // anderen Zonen als das Bild zeigt.
    $left = $margin + board_pagination_pill_width($totalPages);
    if ($totalPages > 1) {
        $left += $gap;
    }

    $verfuegbar = 1872 - $margin - $left - ($count - 1) * $gap;
    // Rein defensiv: bei absurd vielen Seiten bliebe sonst eine negative
    // Breite und die Knoepfe wuerden verkehrt herum gezeichnet.
    $buttonWidth = max(0, intdiv($verfuegbar, $count));

    $y = BOARD_NAV_ROW_TOP;
    $textY = $y + 47; // wie zuvor: 1320 -> Grundlinie 1367

    $out = '<g font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="34">';
    foreach ($favoriteTitles as $i => $title) {
        $x = $left + $i * ($buttonWidth + $gap);
        $active = $i === $activeIndex;
        $out .= sprintf(
            // rx = halbe Hoehe -> echte Pillenform mit Halbkreisen an den Enden
            // (Nutzerwunsch 2026-09-04), wie die Seitenpille links daneben.
            // Die Tippzone bleibt das volle Rechteck: die abgerundeten Ecken
            // sind nur ein paar Pixel, und ein Tipp knapp daneben soll weiter
            // gelten statt ins Leere zu laufen.
            '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" %s/>',
            $x, $y, $buttonWidth, BOARD_NAV_ROW_HEIGHT, intdiv(BOARD_NAV_ROW_HEIGHT, 2),
            $active ? 'fill="black"' : 'fill="white" stroke="black" stroke-width="3"'
        );
        $out .= sprintf(
            '<text x="%d" y="%d" text-anchor="middle" fill="%s">%s</text>',
            $x + intdiv($buttonWidth, 2), $textY, $active ? 'white' : 'black',
            htmlspecialchars($title, ENT_XML1)
        );
    }
    $out .= '</g>';

    return $out;
}

/**
 * Tipp-Zonen als Pixel-Rechtecke (Panel-Koordinaten 1872x1404) fuer den
 * Browser-Simulator (web/board.php?debug=ui) -- KEINE eigene Geometrie,
 * sondern dieselben Formeln wie board_render_touch_bar_svg() und
 * board_render_stand_and_pagination_svg() nochmal ausgewertet, damit die
 * anklickbaren Bereiche niemals von dem abweichen, was tatsaechlich
 * gezeichnet wurde. MUSS inhaltlich mit mapTouchToZone() in
 * epaper-monitor/lib/boardlogic/touch_zone.cpp uebereinstimmen (die
 * Firmware kennt dieses Array nicht -- dritte, unabhaengige Ableitung
 * derselben drei Konstantensaetze).
 *
 * @return list<array{zone: string, x: int, y: int, w: int, h: int}>
 */
function board_touch_zones(int $favoriteCount, int $totalPages): array
{
    $zones = [];

    // Pille zuerst: sie sitzt seit 2026-09-04 LINKS IN derselben Zeile wie die
    // Favoriten, und touch_zone.cpp prueft sie ebenfalls zuerst. Die
    // Reihenfolge in dieser Liste muss dem entsprechen, sonst zeigte der
    // Simulator eine andere Trefferreihenfolge als das Geraet.
    if ($totalPages > 1) {
        // Absolut statt relativ (Nutzerwunsch 2026-08-27, TASK-25): jeder
        // Slot springt per "page_<N>" DIREKT zu seiner eigenen Seitenzahl.
        // Die letzte Zone reicht bewusst bis zum Pillenende (nicht nur bis
        // zum Slotende): mapPaginationTouch() in touch_zone.cpp klemmt einen
        // Tipp im rechten Padding-Streifen auf den letzten Slot, diese Zone
        // MUSS deckungsgleich sein, sonst zeigt der Simulator dort eine
        // Luecke, die es am echten Geraet gar nicht gibt.
        $pillStartX = BOARD_PAGINATION_LEFT_EDGE;
        $pillEndX = $pillStartX + board_pagination_pill_width($totalPages);
        for ($p = 1; $p <= $totalPages; $p++) {
            $slotStart = $pillStartX + ($p - 1) * BOARD_PAGINATION_SLOT_WIDTH;
            $slotEnd = $p === $totalPages ? $pillEndX : $slotStart + BOARD_PAGINATION_SLOT_WIDTH;
            $zones[] = ['zone' => 'page_' . $p, 'x' => $slotStart, 'y' => BOARD_PAGINATION_TOP, 'w' => $slotEnd - $slotStart, 'h' => BOARD_PAGINATION_HEIGHT];
        }
    }

    if ($favoriteCount > 0) {
        // Dieselbe Herleitung wie board_render_touch_bar_svg() und
        // mapFavoriteTouch(): die Favoriten fangen hinter der Pille an.
        $margin = BOARD_NAV_MARGIN;
        $gap = BOARD_NAV_MARGIN;
        $left = $margin + board_pagination_pill_width($totalPages);
        if ($totalPages > 1) {
            $left += $gap;
        }
        $buttonWidth = max(0, intdiv(1872 - $margin - $left - ($favoriteCount - 1) * $gap, $favoriteCount));
        $favZoneNames = ['fav0', 'fav1', 'fav2'];
        for ($i = 0; $i < $favoriteCount; $i++) {
            $zones[] = [
                'zone' => $favZoneNames[$i],
                'x' => $left + $i * ($buttonWidth + $gap),
                'y' => BOARD_NAV_ROW_TOP,
                'w' => $buttonWidth,
                'h' => 1404 - BOARD_NAV_ROW_TOP,
            ];
        }
    }

    return $zones;
}

/**
 * Browser-Simulator (web/board.php?debug=ui): eine HTML-Huelle um das
 * ?debug=png-Bild mit anklickbaren Zonen aus board_touch_zones(). Jeder
 * Klick schickt per fetch() denselben X-Device-Touch-Header, den die echte
 * Firmware setzen wuerde -- Zustand/Navigation laeuft komplett ueber den
 * bestehenden, bereits getesteten Codepfad in web/board.php
 * (board_resolve_touch() + board_state_*), diese Funktion fuegt nur eine
 * duenne UI davor. Kein Teil des Geraeteprotokolls, nur fuer Entwicklung.
 *
 * Gibt HTML direkt aus (kein Rueckgabewert) -- Aufrufer beendet danach.
 *
 * @param string $cspNonce $_cspNonce aus auth_bootstrap() (initialize.php) --
 *        ohne Nonce blockt die CSP (script-src 'self' 'nonce-...', kein
 *        'unsafe-inline') das eingebettete <script> stillschweigend: die
 *        Seite laedt mit 200, aber keine Zone erscheint und kein Klick
 *        funktioniert (am 2026-08-27 live auf akadbrain so gefunden).
 */
function board_render_debug_ui(string $token, int $favoriteCount, int $totalPages, string $cspNonce): void
{
    $zones = board_touch_zones($favoriteCount, $totalPages);
    // Erstanzeige: reiner Lesezugriff, persistiert nichts. Klicks haengen
    // &sim=1 an (s. web/board.php) -- nur DANN schreibt der Server die
    // aufgeloeste Navigation in die Token-Zustandsdatei.
    $imgSrc = 'board.php?' . http_build_query(['token' => $token, 'debug' => 'png']);
    $touchSrc = $imgSrc . '&sim=1';
    $zonesJson = json_encode($zones, JSON_THROW_ON_ERROR);
    $imgSrcJson = json_encode($imgSrc, JSON_THROW_ON_ERROR);
    $touchSrcJson = json_encode($touchSrc, JSON_THROW_ON_ERROR);
    $nonce = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Board-Simulator</title>
<style>
  body { background: #222; color: #eee; font-family: system-ui, sans-serif; margin: 0; padding: 24px; }
  h1 { font-size: 16px; font-weight: normal; color: #aaa; margin: 0 0 16px; }
  #stage { position: relative; width: min(100%, 936px); margin: 0 auto; }
  #stage img { display: block; width: 100%; height: auto; }
  .zone {
    position: absolute; box-sizing: border-box;
    border: 1px dashed rgba(255,120,0,0.7); background: rgba(255,120,0,0.08);
    color: rgba(255,180,80,0.9); font-size: 11px; font-family: monospace;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; user-select: none;
  }
  .zone:hover { background: rgba(255,120,0,0.25); }
  #status { margin-top: 12px; color: #888; font-family: monospace; font-size: 13px; min-height: 1.2em; }
</style>
</head>
<body>
<h1>Board-Simulator -- Klick auf eine Zone sendet X-Device-Touch, wie die echte Firmware</h1>
<div id="stage">
  <img id="board" src="{$imgSrc}" alt="Board">
</div>
<div id="status"></div>
<script nonce="{$nonce}">
(function () {
  const PANEL_W = 1872, PANEL_H = 1404;
  const imgSrc = {$imgSrcJson};
  const touchSrc = {$touchSrcJson};
  const stage = document.getElementById('stage');
  const img = document.getElementById('board');
  const status = document.getElementById('status');
  let objectUrl = null;

  // totalPages haengt vom AKTIVEN Favoriten ab (eine Stoerung fuegt eine
  // Seite hinzu, die Pille wird breiter/wandert nach links) -- die Zonen
  // deshalb NICHT einmalig fest verdrahten, sondern nach jedem Touch aus dem
  // X-Board-Touch-Zones-Header neu aufbauen (Nutzerbefund 2026-08-27, live
  // auf akadbrain: nach einem Favoritenwechsel deckten die alten Zonen die
  // tatsaechlich gerenderte Pille nicht mehr).
  function renderZones(zones) {
    stage.querySelectorAll('.zone').forEach(el => el.remove());
    for (const z of zones) {
      const el = document.createElement('div');
      el.className = 'zone';
      el.style.left = (z.x / PANEL_W * 100) + '%';
      el.style.top = (z.y / PANEL_H * 100) + '%';
      el.style.width = (z.w / PANEL_W * 100) + '%';
      el.style.height = (z.h / PANEL_H * 100) + '%';
      el.textContent = z.zone;
      el.title = 'X-Device-Touch: ' + z.zone;
      el.addEventListener('click', () => touch(z.zone));
      stage.appendChild(el);
    }
  }

  renderZones({$zonesJson});

  async function touch(zone) {
    status.textContent = 'X-Device-Touch: ' + zone + ' ...';
    try {
      const res = await fetch(touchSrc, { headers: { 'X-Device-Touch': zone } });
      if (!res.ok) { status.textContent = 'Fehler: HTTP ' + res.status; return; }
      const zonesHeader = res.headers.get('X-Board-Touch-Zones');
      const blob = await res.blob();
      const next = URL.createObjectURL(blob);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = next;
      img.src = next;
      if (zonesHeader) renderZones(JSON.parse(zonesHeader));
      status.textContent = 'X-Device-Touch: ' + zone + ' -> aktualisiert';
    } catch (e) {
      status.textContent = 'Fehler: ' + e;
    }
  }
})();
</script>
</body>
</html>
HTML;
}

/**
 * Anzahl Seiten INKLUSIVE des Schlafschirms, der seit 2026-08-23 immer die
 * LETZTE Seite ist (Nutzerwunsch: "damit ich den Schirm auch absichtlich
 * aufrufen kann"). EINE Formel fuer board_render_svg() und web/board.php,
 * die $requestedPage schon vor dem Rendern gegen totalPages clampen muss.
 */
function board_total_pages(int $totalDeparturePages, bool $hasDisruptions, bool $hasCalendar = false, bool $hasMqtt = false): int
{
    return $totalDeparturePages + ($hasDisruptions ? 1 : 0) + ($hasCalendar ? 1 : 0) + ($hasMqtt ? 1 : 0) + 1;
}

/**
 * Setzt das komplette Board-SVG zusammen: Grundformen, Kopfzeile,
 * Abfahrten- ODER Stoerungsseite (je nach $requestedPage), Stand+
 * Pagination, Wetterkarte, Touch-Leiste. Die LETZTE Seite ist immer der
 * Schlafschirm (board_sleep_render_svg(), inc/board_sleep.php) -- eigenes,
 * vollstaendiges Layout ohne Kopf-/Touch-Leiste, aber MIT "Stand HH:MM" und
 * der Seitenzahlen-Pille an derselben Stelle wie die Abfahrtenseite (solange
 * das Geraet nicht effektiv schlaeft, bleibt so ein Tippweg zurueck, nicht
 * nur die physischen Tasten -- Nutzerwunsch 2026-08-23). Identisch zu dem,
 * was das Geraet vor dem Tiefschlaf anfordert.
 *
 * Seitenzaehlung: board_paginate_departures() liefert totalPages fuer die
 * Abfahrten allein. Gibt es $filteredAlerts, kommt genau eine weitere
 * Seite dazu (die Stoerungsseite -- board_layout_disruptions() paginiert
 * selbst nicht, s. Task 8), dann IMMER der Schlafschirm-Slot
 * (board_total_pages()). $requestedPage bis totalDeparturePages zeigt
 * Abfahrten, totalDeparturePages+1 (falls vorhanden) zeigt Stoerungen,
 * der letzte Slot den Schlafschirm.
 *
 * @param list<string> $touchBarFavoriteTitles 1-3 Titel
 * @param array $activeFavorite board_favorite()-Ergebnis
 * @param list<array> $filteredAlerts bereits auf $activeFavorite gefiltert
 * @param ?array{today: array, tomorrow: array} $sleepWeather Beide
 *        Prognose-Scheiben fuer den Schlafschirm-Slot (weather_select_two_days()).
 *        null ist nur fuer Aufrufe unzulaessig, die diesen Slot nie erreichen
 *        koennen (z.B. Tests, die $requestedPage klein genug waehlen).
 * @param ?array{ssid: string, password: string, encryption: string, hidden: bool} $guestWifi
 *        null = kein QR-Block (board_guest_wifi_load() lieferte nichts)
 */
function board_render_svg(
    array $touchBarFavoriteTitles,
    int $activeFavoriteIndex,
    array $activeFavorite,
    array $filteredAlerts,
    int $requestedPage,
    array $weather,
    DateTimeImmutable $dataStand,
    DateTimeImmutable $renderedAt,
    int $batteryPercent,
    int $wifiBars,
    ?int $firmwareBuild = null,
    ?array $sun = null,
    ?array $sleepWeather = null,
    ?array $guestWifi = null,
    bool $sleepShowPagination = true,
    // Angehaengt statt einsortiert: alle Aufrufstellen uebergeben positionsbasiert.
    // null = keine Kalenderseite (kein Cache vorhanden).
    ?array $calendar = null,
    // TASK-26: null = keine MQTT-Seite (kein Cache vorhanden, s. board_mqtt_load()).
    ?array $mqtt = null,
    // Akku: gemessene Spannung + Kalibrierung, s. board_render_chrome_svg().
    // 0 = keine Messung im Request.
    int $batteryMv = 0,
    int $batteryChargingMv = BOARD_BATTERY_CHARGING_MV_DEFAULT,
    string $batteryDisplayMode = 'percent',
    // Auf dem echten Schlafschirm abgeschaltet: das Geraet zeigt dieses Bild
    // stundenlang weiter, eine eingefrorene Uhrzeit saehe dort aus wie eine
    // gueltige Angabe. Akku und WLAN frieren zwar genauso ein, sagen aber
    // etwas anderes -- sie beschreiben den Zustand BEIM Einschlafen, und
    // genau das will man spaeter wissen ("wann war die Batterie leer, bei
    // welcher Spannung", Nutzerwunsch 2026-09-04). Eine Uhrzeit beschreibt
    // dagegen nur sich selbst.
    bool $showClock = true
): string {
    $departurePages = board_paginate_departures($activeFavorite, 1);
    $totalDeparturePages = $departurePages['totalPages'];
    $hasDisruptions = $filteredAlerts !== [];
    $hasCalendar = $calendar !== null;
    $hasMqtt = $mqtt !== null;
    $totalContentPages = $totalDeparturePages + ($hasDisruptions ? 1 : 0) + ($hasCalendar ? 1 : 0) + ($hasMqtt ? 1 : 0);
    $totalPages = board_total_pages($totalDeparturePages, $hasDisruptions, $hasCalendar, $hasMqtt);
    $pageCategories = board_pagination_categories($totalDeparturePages, $hasDisruptions, $hasCalendar, $hasMqtt);

    $requestedPage = max(1, min($totalPages, $requestedPage));

    if ($requestedPage > $totalContentPages) {
        $sleepWeather ??= ['today' => ['available' => false], 'tomorrow' => ['available' => false]];

        // Kopfzeile jetzt AUCH auf dem Schlafschirm (Nutzerwunsch 2026-09-04:
        // "so seh ich nicht wann ungefaehr die Batterie leer war und bei
        // welcher Voltzahl"). Ohne Spaltentrennlinie -- der Schlafschirm
        // zieht seine eigene an anderer Stelle. Die Uhrzeit entfaellt nur
        // beim ECHTEN Einschlafen ($sleepShowPagination === false, dasselbe
        // Signal wie bei Pille und Touch-Leiste): dann steht das Bild
        // stundenlang, und eine eingefrorene Uhrzeit saehe aus wie eine
        // gueltige Angabe. Akku und WLAN frieren genauso ein, sagen aber
        // etwas ueber den Zustand BEIM Einschlafen -- genau das ist gefragt.
        $sleepChrome = board_render_chrome_svg(
            $renderedAt, $batteryPercent, $wifiBars, false,
            $batteryMv, $batteryChargingMv, $batteryDisplayMode,
            $sleepShowPagination
        );

        return board_sleep_render_svg(
            $sleepWeather['today'], $sleepWeather['tomorrow'], $sun, $guestWifi, $renderedAt,
            $totalPages, $sleepShowPagination,
            $touchBarFavoriteTitles, $activeFavoriteIndex, $pageCategories,
            $sleepChrome
        );
    }

    $defs = board_svg_defs();
    $touchBar = board_render_touch_bar_svg($touchBarFavoriteTitles, $activeFavoriteIndex, $totalPages);

    // Reihenfolge: Abfahrten -> Stoerungen -> Kalender -> MQTT -> Schlafschirm
    // (der oben schon per Frueh-Ausstieg behandelt ist).
    $disruptionsPage = $hasDisruptions ? $totalDeparturePages + 1 : null;
    $calendarPage = $hasCalendar ? $totalDeparturePages + ($hasDisruptions ? 1 : 0) + 1 : null;
    $istKalenderSeite = $requestedPage === $calendarPage;
    $istMqttSeite = $hasMqtt
        && $requestedPage > $totalDeparturePages
        && $requestedPage !== $disruptionsPage
        && $requestedPage !== $calendarPage;

    // Kalender- UND MQTT-Seite nehmen die ganze Breite (Kalender-Entscheidung
    // 2026-08-26, MQTT analog uebernommen): keine Wetterspalte, keine
    // Spaltentrennlinie. Kopfzeile, Fusszeile, Paginierung und Touch-Leiste
    // bleiben unveraendert -- die Pille MUSS an ihrer festen Stelle sitzen,
    // die Firmware tippt nach Koordinaten.
    $istVollbreiteSeite = $istKalenderSeite || $istMqttSeite;
    $chrome = board_render_chrome_svg(
        $renderedAt, $batteryPercent, $wifiBars, !$istVollbreiteSeite,
        $batteryMv, $batteryChargingMv, $batteryDisplayMode
    );
    $weatherSvg = $istVollbreiteSeite ? '' : board_render_weather_svg($weather, $firmwareBuild, $sun);

    $kalenderStand = null;
    if ($requestedPage <= $totalDeparturePages) {
        $items = board_paginate_departures($activeFavorite, $requestedPage, $filteredAlerts)['items'];
        $mainSvg = board_render_departures_svg($items);
    } elseif ($requestedPage === $disruptionsPage) {
        $mainSvg = board_render_disruptions_svg(board_layout_disruptions($filteredAlerts));
    } elseif ($istKalenderSeite) {
        $kalenderItems = board_calendar_layout($calendar);
        $mainSvg = board_calendar_render_svg($kalenderItems);
        // Statt "Stand HH:MM" (WL-Abfrage) unten links: der Kalenderstand.
        $kalenderStand = board_calendar_status_text($kalenderItems) ?: null;
    } else {
        // Nur erreichbar wenn $hasMqtt -- $requestedPage ist hier bereits
        // gegen $totalContentPages geklemmt.
        $mainSvg = board_mqtt_render_svg(board_mqtt_layout($mqtt), count($mqtt['messages']));
    }

    $standAndPagination = board_render_stand_and_pagination_svg(
        $dataStand, $requestedPage, $totalPages, true, $pageCategories,
        $hasMqtt ? count($mqtt['messages']) : null,
        $kalenderStand
    );

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1872" height="1404" viewBox="0 0 1872 1404">
<defs>
{$defs}
</defs>
<rect width="1872" height="1404" fill="white"/>
{$chrome}
{$mainSvg}
{$standAndPagination}
{$weatherSvg}
{$touchBar}
</svg>
SVG;
}

/**
 * Verfuegbare Spaltenbreite der Wetterkarte (706px, x=1150 bis x=1856)
 * geteilt durch die gemessene mittlere Zeichenbreite. Basiswert 17,37px/
 * Zeichen war fuer 39px gemessen (Task 4 Step 3); Text auf 46px vergroessert
 * (Nutzerwunsch 2026-08-22), Zeichenbreite linear mitskaliert:
 * 17,37 * 46/39 = 20,48px/Zeichen. 8% Sicherheitsabstand:
 * floor(706 / 20,48 * 0.92).
 */
const BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE = 31;

/**
 * Greedy Wortumbruch, mb-safe. SVG <text> bricht nicht von selbst um --
 * diese Funktion ersetzt den in den Mockups von Hand gesetzten Umbruch
 * durch eine fuer beliebigen (sich alle 3h aendernden) Fliesstext
 * reproduzierbare Regel. Kein Silbentrennen: ein einzelnes Wort, das laenger
 * als $maxCharsPerLine ist, bleibt unveraendert auf einer eigenen Zeile.
 *
 * @return list<string>
 */
function board_wrap_text(string $text, int $maxCharsPerLine): array
{
    $words = preg_split('/\s+/u', trim($text));
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (mb_strlen($candidate, 'UTF-8') <= $maxCharsPerLine || $current === '') {
            $current = $candidate;
        } else {
            $lines[] = $current;
            $current = $word;
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

/**
 * Wie board_wrap_text(), aber mit RESPEKT vor gesetzten Zeilenumbruechen.
 *
 * board_wrap_text() zerlegt mit /\s+/, und fuer das ist "\n" gewoehnlicher
 * Leerraum -- eine getippte Einkaufsliste kam als ein Fliesstextklumpen
 * heraus (Nutzerbefund 2026-09-04). Hier wird zuerst an den Umbruechen
 * getrennt und JEDER Absatz einzeln umgebrochen.
 *
 * Bewusst NICHT board_wrap_text() selbst geaendert: Wetter- und
 * Stoerungstexte kommen von der ORF-Seite und tragen Umbrueche aus deren
 * Satz, nicht aus einer Absicht des Verfassers -- dort ist das Einebnen
 * richtig.
 *
 * Leerzeilen bleiben erhalten: wer eine setzt, will eine Trennung sehen.
 * Mehrere aufeinander folgende werden zu einer, sonst frisst ein
 * versehentlicher Doppelumbruch die halbe Karte.
 *
 * @return list<string>
 */
function board_wrap_text_multiline(string $text, int $maxCharsPerLine): array
{
    $absaetze = preg_split('/\R/u', trim($text)) ?: [];
    $lines = [];
    $letzteWarLeer = false;

    foreach ($absaetze as $absatz) {
        if (trim($absatz) === '') {
            if (!$letzteWarLeer && $lines !== []) {
                $lines[] = '';
                $letzteWarLeer = true;
            }
            continue;
        }
        foreach (board_wrap_text($absatz, $maxCharsPerLine) as $zeile) {
            $lines[] = $zeile;
        }
        $letzteWarLeer = false;
    }

    // Eine Leerzeile am Ende waere nur Luft ueber dem Zeitstempel.
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }

    return $lines;
}

/**
 * Wetterkarte aus Spec §9: Icon, Temperatur "von-bis", Stations-Messwerte,
 * Ueberschrift "Heute", Fliesstext mit manuellem Zeilenumbruch, Statuszeile.
 * $weather ist die Rueckgabe von weather_select_display() (inc/weather.php)
 * -- 'available' => false heisst "noch nie erfolgreich abgerufen" (z.B. vor
 * dem ersten Cron-Lauf), nicht dasselbe wie der ">6h veraltet"-Fall (dort
 * bleiben Icon/Temp erhalten, nur der Text wird ersetzt, s. Spec §8).
 *
 * $weather['station'] kommt von wetter.orf.at/wien/mariabrunn (echte
 * Aussenmesswerte, 2026-08-22) -- ersetzt die vorherige interne SHT4x-
 * Sensorzeile des Geraets, die nur die Innenraumtemperatur am Display zeigte.
 */
function board_render_weather_svg(array $weather, ?int $firmwareBuild = null, ?array $sun = null): string
{
    $station = $weather['station'] ?? ['available' => false];
    // 'period' fehlt nur in Alt-Fixtures/Tests von vor 2026-08-23 -- 'today'
    // als Fallback erhaelt dort das bisherige Verhalten (Ueberschrift "Heute").
    $heading = ($weather['period'] ?? 'today') === 'tomorrow' ? 'Morgen' : 'Heute';

    if ($weather['available'] === false) {
        return board_render_weather_card('icon_unbekannt', null, null, ['Wetterdaten werden geladen …'], $station, $firmwareBuild, $sun, $heading);
    }

    $iconId = BOARD_ICON_ID_BY_CATEGORY[$weather['icon_category']] ?? BOARD_ICON_ID_BY_CATEGORY['unbekannt'];
    $bodyText = $weather['text'] ?? $weather['text_error'] ?? '';
    $lines = board_wrap_text($bodyText, BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE);

    return board_render_weather_card($iconId, $weather['temp_min'], $weather['temp_max'], $lines, $station, $firmwareBuild, $sun, $heading);
}

/**
 * Statusleiste am unteren Rand der Wetterkarte, auf einer Grundlinie mit
 * "Stand HH:MM" der linken Spalte: kleines Piktogramm + kurzes Wort, beides
 * in derselben zurueckhaltenden Groesse wie "Stand" (Nutzerwunsch
 * 2026-08-22: "kompakter und huebscher, Schrift wie Stand, diskretere
 * Icons"). Vorher: 34px fett "Warte auf Eingabe" ohne Icon.
 *
 * Server-seitig ist der Zustand IMMER "Bereit" -- ein ausgeliefertes Bild
 * wird per Definition erst angezeigt, WAEHREND das Geraet auf die naechste
 * Eingabe wartet. Die uebrigen Zustaende ("Lade …", "Vollbild", "Schlaf")
 * gelten waehrend/vor einem Request, nicht als dessen Ergebnis, und werden
 * deshalb von der Firmware lokal ueber dieselbe Flaeche gezeichnet
 * (showStatus() in display.cpp, s. docs/hardware/reterminal-e1003.md).
 *
 * Die Geometrie ist mit display.cpp abgestimmt -- wer hier etwas verschiebt,
 * muss die Konstanten dort mitziehen.
 */
const BOARD_STATUS_IDLE_TEXT   = 'Bereit';
const BOARD_STATUS_ICON_CX     = 1164;
const BOARD_STATUS_ICON_CY     = 1281;
const BOARD_STATUS_TEXT_X      = 1194;
const BOARD_STATUS_BASELINE    = 1290;
const BOARD_STATUS_FONT_SIZE   = 24;
/** Rechtes Ende der Statusleiste -- Firmware-Marke, rechtsbuendig. */
const BOARD_STATUS_MARKER_RIGHT = 1856;

/**
 * "Bereit"-Piktogramm: Ring mit Punkt in der Mitte (Zielscheibe/Standby).
 * Bewusst aus Grundformen statt aus einer Tabler-Datei gebaut -- die
 * Firmware muss dieselben Symbole mit GFX-Primitiven nachzeichnen koennen
 * (kein Bitmap-Asset im Flash), und identische Formen auf beiden Seiten sind
 * die Voraussetzung dafuer, dass ein lokal uebermaltes Statusfeld nicht vom
 * Server-Frame abweicht.
 */
function board_render_status_icon_svg(int $cx, int $cy): string
{
    return sprintf(
        '<circle cx="%d" cy="%d" r="10" fill="none" stroke="black" stroke-width="2"/>'
        . '<circle cx="%d" cy="%d" r="4" fill="black"/>',
        $cx, $cy, $cx, $cy
    );
}

/**
 * Firmware-Marke ("FW45") am rechten Ende der Statusleiste.
 *
 * Wird seit 2026-08-22 SERVERSEITIG gerendert, nicht mehr vom Geraet lokal
 * aufs Panel gezeichnet. Grund ist eine Messung am echten Geraet: jede lokale
 * Teilaktualisierung zahlt die vollen Panel-Fixkosten, und ausgerechnet dieses
 * 256x50-Rechteck kostete **1104 ms** -- mehr als das komplette Vollbild
 * (1024 ms). Der Wert kommt jetzt als Header `X-Device-Firmware` mit und
 * kostet im Bild exakt nichts. Siehe docs/hardware/reterminal-e1003.md §20.8.
 *
 * Versalien, weil in FreeSans/Atkinson das kleine w auf x-Hoehe sitzt und die
 * Marke dadurch zerfranst wirkte (Nutzerbefund 2026-08-22).
 *
 * @param int|null $build null = Geraet hat keinen Header geschickt (aelterer
 *        Firmware-Stand oder Browser-Aufruf) -> gar nichts rendern.
 */
function board_render_firmware_marker_svg(?int $build): string
{
    if ($build === null) {
        return '';
    }

    return sprintf(
        '<text x="%d" y="%d" text-anchor="end" font-family="Atkinson Hyperlegible Next"'
        . ' font-size="%d" fill="black">FW%d</text>',
        BOARD_STATUS_MARKER_RIGHT, BOARD_STATUS_BASELINE, BOARD_STATUS_FONT_SIZE, $build
    );
}

/**
 * Zeilenraster der Wetterkarte. Nur der Startwert ist fest -- die Zeilen
 * selbst zaehlen ab hier FORTLAUFEND (Nutzerbefund 2026-08-23: "fehlender
 * Regen macht auf der Monitorseite wieder eine Leerzeile"). Eine fruehere
 * Fassung hielt die Sonnenzeile absichtlich an einer festen Y-Position, um
 * einen Sprung zu vermeiden, wenn die bedingte Niederschlagszeile
 * verschwindet -- das hinterliess dafuer bei jedem trockenen Refresh (dem
 * Normalfall) eine sichtbare Luecke. "Heute"/Fliesstext bleiben trotzdem
 * fest: selbst der laengste Zeilenblock (5 Zeilen) endet bei
 * 190+4*56=414, 72px Luft bis zur Ueberschrift bei 486 -- die muss dafuer
 * nie ausweichen.
 */
const BOARD_WEATHER_ROW_TEMP_Y     = 190;
const BOARD_WEATHER_ROW_LEAD       = 56;
const BOARD_WEATHER_HEADING_Y      = 486;
const BOARD_WEATHER_BODY_Y         = 542;

/**
 * @param list<string> $bodyLines
 * @param array{available: bool, temp_c?: float, humidity_pct?: int, wind_kmh?: int, wind_gusts_kmh?: int, wind_direction?: string, precipitation_mm?: float} $station
 */
function board_render_weather_card(
    string $iconId,
    ?int $tempMin,
    ?int $tempMax,
    array $bodyLines,
    array $station = ['available' => false],
    ?int $firmwareBuild = null,
    ?array $sun = null,
    string $heading = 'Heute'
): string {
    // Haupt-Icon: scale(6) -> scale(9) (Nutzerwunsch 2026-08-22, zweimal
    // nachgeschaerft). Die Tabler-Dateien sind 24x24, das ergibt 216 statt
    // 144px -- die Haelfte mehr. Das ist die Obergrenze in dieser Spalte:
    // bei Mitte (1232,220) spannt es 1124..1340 waagrecht und 112..328
    // senkrecht, also 11px neben der Spaltenlinie (x=1113), 40px vor den
    // Zeilen-Piktogrammen (ab x=1380) und 22px unter der Kopfzeilenlinie
    // (y=90). Groesser geht nur, wenn die Messwertspalte weiter nach rechts
    // rueckt -- dort begrenzt die Temperaturzeile ("23.4° 18-29°C").
    $iconSvg = sprintf('<g transform="translate(1232,220) scale(9)"><use href="#%s"/></g>', $iconId);

    // Messwertzeilen NEBEN dem Icon: Temperatur (Mariabrunn fett +
    // Prognosebereich), Luftfeuchtigkeit, Wind (Geschwindigkeit-Boeen),
    // Niederschlag (nur wenn > 0), Sonnenauf-/-untergang. Icon-Mitte einer
    // Zeile liegt ~14px ueber ihrer Textbaseline (0,35 * 40px).
    $rowIconX = 1400;
    $rowTextX = 1450;

    $row = static function (string $iconId, int $y, string $textSvg) use ($rowIconX, $rowTextX): string {
        return sprintf('<use href="#%s" transform="translate(%d,%d) scale(1.7)"/>', $iconId, $rowIconX, $y - 14)
            . sprintf(
                '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="40" fill="black">%s</text>',
                $rowTextX, $y, $textSvg
            );
    };

    $rowsSvg = '';
    // Laufender Cursor statt fester Y-Positionen pro Zeile (Nutzerbefund
    // 2026-08-23: "fehlender Regen macht auf der Monitorseite wieder eine
    // Leerzeile"). Die fruehere feste Rasterung sollte verhindern, dass die
    // Sonnenzeile springt, wenn die bedingte Niederschlagszeile erscheint --
    // hat dabei aber bei jedem trockenen Refresh (dem Normalfall) eine
    // sichtbare Luecke zwischen Wind- und Sonnenzeile hinterlassen. Das
    // wiegt schwerer als der seltene 56px-Sprung genau in dem Moment, in dem
    // Niederschlag einsetzt oder aufhoert -- gleiche Abwaegung wie beim
    // Schlafschirm (board_sleep.php), der aus demselben Grund von Anfang an
    // fortlaufend zaehlt. "Heute"/Fliesstext bleiben trotzdem fest
    // (BOARD_WEATHER_HEADING_Y=486): selbst der laengste Zeilenblock (5
    // Zeilen, Temp+Feuchte+Wind+Regen+Sonne) endet bei 190+4*56=414, 72px
    // Luft bis 486 -- die Ueberschrift muss dafuer nie ausweichen.
    $y = BOARD_WEATHER_ROW_TEMP_Y;

    if ($tempMin !== null && $tempMax !== null) {
        $rowsSvg .= $row('iconTemp', $y, $station['available']
            ? sprintf('<tspan font-weight="bold">%s°</tspan> %d–%d°C', number_format($station['temp_c'], 1), $tempMin, $tempMax)
            : sprintf('%d–%d°C', $tempMin, $tempMax));
        $y += BOARD_WEATHER_ROW_LEAD;
    }

    if ($station['available']) {
        $rowsSvg .= $row('iconDroplet', $y, sprintf('%d%%', $station['humidity_pct']));
        $y += BOARD_WEATHER_ROW_LEAD;
        $rowsSvg .= $row('iconWind', $y,
            sprintf('%d–%d km/h', $station['wind_kmh'], $station['wind_gusts_kmh']));
        $y += BOARD_WEATHER_ROW_LEAD;

        if ($station['precipitation_mm'] > 0) {
            $rowsSvg .= $row('iconDroplets', $y,
                sprintf('%s mm/h', number_format($station['precipitation_mm'], 1)));
            $y += BOARD_WEATHER_ROW_LEAD;
        }
    }

    // Sonnenzeile (Nutzerwunsch 2026-08-22): beide Zeiten in EINER Zeile, je
    // mit eigenem Piktogramm -- das erste im regulaeren Icon-Slot, das zweite
    // eingerueckt hinter der Aufgangszeit. Die Zeiten sind gerechnet, nicht
    // gescrapt (weather_sun_times() in inc/weather.php).
    if ($sun !== null && ($sun['available'] ?? false)) {
        $sunsetIconX = 1600;
        $sunsetTextX = 1650;
        $rowsSvg .= $row('iconSunrise', $y, $sun['sunrise']->format('H:i'));
        $rowsSvg .= sprintf('<use href="#iconSunset" transform="translate(%d,%d) scale(1.7)"/>', $sunsetIconX, $y - 14);
        $rowsSvg .= sprintf(
            '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="40" fill="black">%s</text>',
            $sunsetTextX, $y, $sun['sunset']->format('H:i')
        );
    }

    // Feste Position unabhaengig davon, ob die (bedingte) Niederschlagszeile
    // gerade steht -- sonst spraenge "Heute" je nach Wetterlage auf und ab.
    $headingSvg = $tempMin !== null
        ? sprintf(
            '<text x="1150" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="46" fill="black">%s</text>',
            BOARD_WEATHER_HEADING_Y, htmlspecialchars($heading, ENT_XML1)
        )
        : '';

    $bodySvg = '';
    foreach ($bodyLines as $i => $line) {
        // 46px statt 39px, Zeilenabstand 54px statt 46px (Nutzerwunsch 2026-08-22).
        $y = BOARD_WEATHER_BODY_Y + $i * 54;
        $bodySvg .= sprintf(
            '<text x="1150" y="%d" font-family="Atkinson Hyperlegible Next" font-weight="500" font-size="46" fill="black">%s</text>',
            $y, htmlspecialchars($line, ENT_XML1)
        );
    }

    $statusSvg = board_render_status_icon_svg(BOARD_STATUS_ICON_CX, BOARD_STATUS_ICON_CY)
        . sprintf(
            '<text x="%d" y="%d" font-family="Atkinson Hyperlegible Next" font-size="%d" fill="black">%s</text>',
            BOARD_STATUS_TEXT_X, BOARD_STATUS_BASELINE, BOARD_STATUS_FONT_SIZE,
            htmlspecialchars(BOARD_STATUS_IDLE_TEXT, ENT_XML1)
        )
        . board_render_firmware_marker_svg($firmwareBuild);

    return <<<SVG
{$iconSvg}
{$rowsSvg}
{$headingSvg}
{$bodySvg}
{$statusSvg}
SVG;
}

/**
 * Unterste zulaessige Y-Position fuer eine Zeilen-Badge-Unterkante, bevor
 * auf eine neue Seite umgebrochen wird -- reserviert die letzten 60px der
 * Abfahrtenspalte (1310-1250) fuer die Stand+Pagination-Leiste (Task 6b).
 */
const BOARD_DEPARTURES_MAX_Y = 1250;

/**
 * Geometrie der Seitenzahlen-Pille (board_render_stand_and_pagination_svg()).
 * MUSS deckungsgleich mit den PAGINATION_*-Konstanten in
 * epaper-monitor/lib/boardlogic/touch_zone.cpp bleiben -- die Firmware
 * berechnet aus denselben Werten die Tipp-Zonen fuer page_prev/page_next.
 */
// Seit 2026-09-04 steht die Pille LINKS IN DER FAVORITENZEILE, nicht mehr in
// einem eigenen Band darueber (Nutzerwunsch: "die ganze Hauptnavigation in
// einer Zeile"). Sie ist damit genauso hoch wie die Favoritenknoepfe; Slots,
// Kreis und Icons sind entsprechend mitgewachsen, sonst wirkten sie in der
// 74px-Zeile verloren.
//
// Beide Elemente teilen sich jetzt DIESELBE Zeile -- die Firmware muss die
// Pille deshalb VOR den Favoriten pruefen (touch_zone.cpp), sonst schluckt
// der Favoritenbereich den Tipp.
const BOARD_NAV_ROW_TOP    = 1320;
const BOARD_NAV_ROW_HEIGHT = 74;
const BOARD_NAV_MARGIN     = 16; // Rand links/rechts, auch Luecke Pille<->Favoriten

const BOARD_PAGINATION_SLOT_WIDTH  = 100; // war 87 (48px-Zeile), gewachsen mit der Zeilenhoehe
const BOARD_PAGINATION_SIDE_PADDING = 20; // Luft links/rechts der aeussersten Zahl
const BOARD_PAGINATION_CIRCLE_RADIUS = 30; // war 20 -- passt zur 74px-Zeile
const BOARD_PAGINATION_ICON_SCALE  = 1.5;  // Icons sind auf ~26px gezeichnet (+-13)
const BOARD_PAGINATION_FONT_SIZE   = 38;   // war 30
/** Nachrichtenanzahl neben dem Sprechblasen-Icon (Nutzerwunsch 2026-09-04):
 *  Icon um diesen Betrag nach links, Zahl um denselben nach rechts. 20px hält
 *  beides in der fixen 100px-Slotbreite -- die Firmware rechnet die
 *  Pillenbreite unabhängig aus totalPages nach und kennt den Inhalt nicht. */
const BOARD_PAGINATION_BADGE_SHIFT     = 20;
/** Kleiner als die Seitenziffern: die Zahl steht neben einem Icon im selben
 *  Slot, nicht allein darin. */
const BOARD_PAGINATION_BADGE_FONT_SIZE = 30;
/** Linke Kante der Pille -- sie waechst jetzt nach RECHTS (vorher rechtsbuendig
 *  auf x=1083 verankert und nach links gewachsen). */
const BOARD_PAGINATION_LEFT_EDGE   = BOARD_NAV_MARGIN;
const BOARD_PAGINATION_TOP         = BOARD_NAV_ROW_TOP;
const BOARD_PAGINATION_HEIGHT      = BOARD_NAV_ROW_HEIGHT;

/**
 * Breite der Pille fuer $totalPages Seiten. Eine Funktion statt einer
 * Konstanten, weil sowohl die Pille selbst als auch die Favoritenleiste
 * (die sich rechts daneben einordnet) und die Firmware denselben Wert
 * brauchen -- eine zweite Herleitung waere genau die Sorte Duplikat, die
 * bei einer Aenderung auseinanderlaeuft.
 */
function board_pagination_pill_width(int $totalPages): int
{
    if ($totalPages <= 1) {
        return 0; // keine Pille -> die Favoriten bekommen die volle Breite
    }
    return $totalPages * BOARD_PAGINATION_SLOT_WIDTH + BOARD_PAGINATION_SIDE_PADDING;
}

/**
 * Cursor-Layout + Pagination der Abfahrtenliste eines einzelnen Favoriten
 * (Spec: 58px vor / 29px nach jedem Stationskopf, 96px Zeilenraster,
 * Cursor nach einem Block = Badge-Unterkante der letzten Zeile). Bricht auf
 * eine neue Seite um, sobald ein Stationskopf+erste Zeile oder eine
 * einzelne Zeile BOARD_DEPARTURES_MAX_Y ueberschreiten wuerde; bei einem
 * Umbruch MITTEN in einer Station wird deren Kopf auf der Folgeseite mit
 * " (FORTS.)" wiederholt, sonst waere die Zugehoerigkeit auf Seite 2
 * unklar. $page wird auf [1, totalPages] geklemmt.
 *
 * Die Umbruchpruefung nutzt "+48" (Zeilentrenner-Position, R+48), nicht nur
 * "+34" (Badge-Unterkante) -- sonst koennte der 1px-Trennstrich der letzten
 * Zeile einer Seite bis zu 14px in den fuer Stand+Pagination reservierten
 * Bereich (1250-1310) hineinragen (am 2026-08-16 im Review von Task 5
 * gefunden, hier korrigiert).
 *
 * @param array{id: int, title: string, stations: list<array{diva: string, name: string, lines: list<array>}>} $favorite
 * @param list<array{lines: list<string>}> $filteredAlerts bereits auf $favorite gefiltert
 *        (board_filter_alerts_for_favorite()) -- markiert betroffene Zeilen mit
 *        'disrupted' => true, damit die Zeile ein Warndreieck bekommt.
 * @return array{items: list<array>, totalPages: int}
 */
function board_paginate_departures(array $favorite, int $page, array $filteredAlerts = []): array
{
    $disruptedLines = [];
    foreach ($filteredAlerts as $alert) {
        foreach ($alert['lines'] ?? [] as $line) {
            $disruptedLines[(string) $line] = true;
        }
    }

    $pages = [[]];
    $pageIndex = 0;
    $cursor = 90;

    foreach ($favorite['stations'] as $station) {
        if ($station['lines'] === []) {
            continue;
        }

        $capTop = $cursor + 58;
        $headerBaseline = $capTop + 48;
        $firstR = $headerBaseline + 29 + 34;
        $stationName = mb_strtoupper($station['name'], 'UTF-8');

        if ($firstR + 48 > BOARD_DEPARTURES_MAX_Y) {
            $pages[] = [];
            $pageIndex++;
            $cursor = 90;
            $capTop = $cursor + 58;
            $headerBaseline = $capTop + 48;
            $firstR = $headerBaseline + 29 + 34;
        }

        $pages[$pageIndex][] = ['type' => 'header', 'y' => $headerBaseline, 'text' => $stationName];

        $r = $firstR;
        foreach ($station['lines'] as $line) {
            if ($r + 48 > BOARD_DEPARTURES_MAX_Y) {
                $pages[] = [];
                $pageIndex++;
                $cursor = 90;
                $capTop = $cursor + 58;
                $headerBaseline = $capTop + 48;
                $r = $headerBaseline + 29 + 34;
                $pages[$pageIndex][] = ['type' => 'header', 'y' => $headerBaseline, 'text' => $stationName . ' (FORTS.)'];
            }

            $departures = $line['departures'];
            $delayed = ($departures[0]['delayed'] ?? false) === true;

            $pages[$pageIndex][] = [
                'type' => 'row',
                'r' => $r,
                'badge_type' => $line['type'],
                'label' => $line['line'],
                'platform' => $line['platform'],
                'destination' => $line['towards'],
                'live_in' => $departures[0]['in'] ?? null,
                'secondary_in' => $departures[1]['in'] ?? null,
                'style' => $delayed ? 'delayed' : ($line['realtime'] ? 'normal' : 'gray'),
                'disrupted' => isset($disruptedLines[$line['line']]),
                'divider_y' => $r + 48,
            ];

            $r += 96;
        }

        $cursor = ($r - 96) + 34;
    }

    $totalPages = count($pages);
    $page = max(1, min($totalPages, $page));

    return ['items' => $pages[$page - 1], 'totalPages' => $totalPages];
}

/**
 * SVG-Rendering der Abfahrtenliste aus den Layout-Items von
 * board_paginate_departures() (Task 5).
 *
 * @param list<array> $layoutItems
 */
function board_render_departures_svg(array $layoutItems): string
{
    $out = '<g font-family="Atkinson Hyperlegible Next">';

    foreach ($layoutItems as $item) {
        $out .= $item['type'] === 'header'
            ? board_render_departure_header($item)
            : board_render_departure_row($item);
    }

    $out .= '</g>';
    return $out;
}

function board_render_departure_header(array $item): string
{
    return sprintf(
        '<text x="16" y="%d" font-weight="bold" font-size="55" fill="black">%s</text>',
        $item['y'], htmlspecialchars($item['text'], ENT_XML1)
    );
}

/**
 * Verfuegbare Breite fuer die Fahrtrichtung: x=145 bis x=900 (755px), lasst
 * Platz vor der Live-Abfahrtszeit (text-anchor="end" bei x=1000, plus dem
 * "delayed"-Rechteck ab x=950) -- ohne diese Grenze laeuft ein langer
 * Zielname wie "Nattmanngasse, Betriebsbhf. Speising" direkt in die Minuten-
 * anzeige hinein (Nutzerbefund 2026-08-22, "Overflow control funktioniert
 * nicht"). Zeichenbreite wie bei BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE
 * hergeleitet: 17,37px/Zeichen bei 39px linear auf 55px skaliert
 * (17,37 * 55/39 = 24,5px/Zeichen), 8% Sicherheitsabstand:
 * floor(755 / 24,5 * 0.92).
 */
const BOARD_DEPARTURE_DESTINATION_MAX_CHARS = 28;

/** Linke Kante des Zielnamens (und, bei Stoerung, des Warndreiecks davor). */
const BOARD_DEPARTURE_DESTINATION_X = 145;
/**
 * Platz, den das vorangestellte Warndreieck dem Zielnamen wegnimmt: 44px
 * Symbolbreite (iconWarnBig, +-22) + 22px Abstand zum Text. Der Abstand ist
 * bewusst grosszuegig: mit 14px klebte das Dreieck im gerenderten Bild am
 * ersten Buchstaben und las sich wie ein Teil des Wortes.
 */
const BOARD_DEPARTURE_WARN_WIDTH = 66;
/**
 * Zeichenbudget einer gestoerten Zeile. Das Dreieck kostet 66px der 755px
 * Textbreite; mit derselben Herleitung wie oben:
 * floor((755 - 66) / 24,5 * 0.92) = 25. Ohne diese Absenkung liefe ein langer
 * Zielname bei gestoerten Linien genau um die Symbolbreite in die
 * Minutenanzeige hinein -- also ausgerechnet der Fehler, den
 * BOARD_DEPARTURE_DESTINATION_MAX_CHARS verhindern soll.
 */
const BOARD_DEPARTURE_DESTINATION_MAX_CHARS_WARN = 25;

function board_truncate_destination(string $text, bool $disrupted = false): string
{
    $max = $disrupted
        ? BOARD_DEPARTURE_DESTINATION_MAX_CHARS_WARN
        : BOARD_DEPARTURE_DESTINATION_MAX_CHARS;

    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…'; // -1: Platz fuer "…"
}

function board_render_departure_row(array $item): string
{
    $r = $item['r'];
    // Wiener Lokalbahn: eigenes Logo (schwarz statt Website-Blau, s.
    // board_svg_defs() "badgeWLB") statt Kreis-Badge mit "WLB"-Text drin
    // (Nutzerwunsch 2026-08-22, "wie im Web").
    $isWlb = $item['label'] === 'WLB';
    $badgeShape = $isWlb
        ? 'badgeWLB'
        : (BOARD_BADGE_SHAPE_BY_TYPE[$item['badge_type']] ?? BOARD_BADGE_SHAPE_BY_TYPE['other']);
    $labelSize = board_badge_label_font_size($item['label'], $item['badge_type']);
    $isGray = $item['style'] === 'gray';
    $isDelayed = $item['style'] === 'delayed';
    $fill = $isGray ? '#808080' : 'black';

    $out = sprintf('<use href="#%s" transform="translate(54,%d)"/>', $badgeShape, $r);
    if (!$isWlb) {
        $out .= sprintf(
            '<text x="54" y="%d" font-weight="bold" font-size="%d" fill="white" text-anchor="middle">%s</text>',
            $r + 9, $labelSize, htmlspecialchars($item['label'], ENT_XML1)
        );
    }
    $isDisrupted = (bool) ($item['disrupted'] ?? false);
    if ($isDisrupted) {
        // Eigenes Zeichen VOR dem Zielnamen (Nutzervorgabe 2026-09-04: "Das
        // Warndreieck als Index der Linie ist zu klein - bitte als eigenes
        // Zeichen vor den Stationsnamen"). Vorher sass es als 26px-Index an
        // der Ecke der Linien-Badge; dort war die Flaeche zwar frei, aber zu
        // klein, um die Dreiecksform noch zu erkennen.
        //
        // Vertikal auf die Versalhoehe des Zielnamens gesetzt: dessen
        // Grundlinie ist $r+19, die Versalhoehe reicht bis etwa $r-20. Das
        // Symbol laeuft von -20 bis +17 um seinen Ursprung, also Ursprung auf
        // $r+2.
        $out .= sprintf('<use href="#iconWarnBig" transform="translate(%d,%d)"/>',
            BOARD_DEPARTURE_DESTINATION_X + BOARD_DEPARTURE_WARN_WIDTH / 2, $r + 2);
    }
    $out .= sprintf(
        '<text x="110" y="%d" font-weight="bold" font-size="22" fill="%s">%s</text>',
        $r + 8, $fill, htmlspecialchars($item['platform'], ENT_XML1)
    );
    $out .= sprintf(
        '<text x="%d" y="%d" font-size="55" fill="%s">%s</text>',
        $isDisrupted ? BOARD_DEPARTURE_DESTINATION_X + BOARD_DEPARTURE_WARN_WIDTH : BOARD_DEPARTURE_DESTINATION_X,
        $r + 19, $fill,
        htmlspecialchars(board_truncate_destination($item['destination'], $isDisrupted), ENT_XML1)
    );

    if ($isDelayed) {
        $out .= sprintf('<rect x="950" y="%d" width="60" height="42" fill="black"/>', $r - 20);
    }

    // $liveFill gilt fuer die Live-Abfahrt in JEDER Darstellung (Zahl,
    // Bindestrich, oder starNow) -- ohne das waere starNow bei "gestoert UND
    // faehrt gerade jetzt" unsichtbar (schwarzer Stern auf dem schwarzen
    // Invertierungsblock, Review-Befund).
    $liveFill = $isDelayed ? 'white' : $fill;

    if ($item['live_in'] === 0) {
        $out .= sprintf('<use href="#starNow" transform="translate(985,%d)" stroke="%s"/>', $r, $liveFill);
    } else {
        // 58px statt 46px (Nutzerwunsch 2026-08-22, "Abfahrtszeiten groesser").
        $liveText = $item['live_in'] === null ? '–' : (string) $item['live_in'];
        $out .= sprintf(
            '<text x="1000" y="%d" font-weight="bold" font-size="58" fill="%s" text-anchor="end">%s</text>',
            $r + 19, $liveFill, $liveText
        );
    }

    if ($item['secondary_in'] !== null) {
        $out .= sprintf('<text x="1015" y="%d" font-size="20" fill="%s">·</text>', $r + 7, $fill);

        if ($item['secondary_in'] === 0) {
            $out .= sprintf('<use href="#starNow" transform="translate(1073,%d) scale(0.696)" stroke="%s"/>', $r, $fill);
        } else {
            // 40px statt 32px.
            $out .= sprintf(
                '<text x="1083" y="%d" font-size="40" fill="%s" text-anchor="end">%s</text>',
                $r + 14, $fill, (string) $item['secondary_in']
            );
        }
    }

    $out .= sprintf('<line x1="16" y1="%d" x2="1083" y2="%d" stroke="black" stroke-width="1"/>', $item['divider_y'], $item['divider_y']);

    return $out;
}

/**
 * Kategorie je Pagination-Slot fuer die Icon-Pille (Nutzerwunsch 2026-08-26:
 * "Die Nummern bei der Pagination machen immer weniger Sinn.
 * Monitor/Stoerung/Kalender/Wetter macht mehr Sinn"). Dieselbe Reihenfolge
 * wie board_total_pages()/board_render_svg() (Abfahrten -> Stoerungen ->
 * Kalender -> Schlafschirm), an einer Stelle gehalten, weil sie an drei
 * unabhaengigen Dispatch-Stellen gebraucht wird (board_render_svg(),
 * web/board.php Haupt- und ?part=monitor-Pfad).
 *
 * "Wetter" statt "Schlaf" fuer den letzten Slot: der Schlafschirm IST die
 * Wetterseite (Heute/Morgen-Prognose), solange das Geraet noch wach ist --
 * so hat der Nutzer die Seite auch benannt.
 *
 * 'mqtt' (TASK-26) reiht sich zwischen Kalender und Wetter ein -- Wetter/
 * Schlafschirm bleibt strukturell IMMER die letzte Seite.
 *
 * @return array<int, string> Seitenzahl (1-indiziert) -> 'monitor'|'stoerung'|'kalender'|'mqtt'|'wetter'
 */
function board_pagination_categories(int $totalDeparturePages, bool $hasDisruptions, bool $hasCalendar, bool $hasMqtt = false): array
{
    $categories = [];
    for ($p = 1; $p <= $totalDeparturePages; $p++) {
        $categories[$p] = 'monitor';
    }
    $p = $totalDeparturePages;
    if ($hasDisruptions) {
        $categories[++$p] = 'stoerung';
    }
    if ($hasCalendar) {
        $categories[++$p] = 'kalender';
    }
    if ($hasMqtt) {
        $categories[++$p] = 'mqtt';
    }
    $categories[++$p] = 'wetter';

    return $categories;
}

/**
 * Handgezeichnetes Icon fuer einen Pagination-Slot, zentriert auf (0,0) --
 * KEIN <use> auf ein defs-Symbol wie sonst ueblich (icon_unbekannt, iconWarn):
 * die aktive Seite zeigt ihr Icon invertiert (weiss auf dem schwarzen Kreis,
 * s. board_render_stand_and_pagination_svg()), ein fest eingefaerbtes
 * defs-Symbol muesste dafuer dupliziert werden. $color wird direkt
 * eingesetzt, dieselbe Form deckt beide Zustaende ab.
 */
function board_pagination_icon_svg(string $category, string $color): string
{
    return match ($category) {
        // Bus-Piktogramm statt woertlich "Monitor" -- am 32px-Slot sofort als
        // OePNV erkennbar, wo ein Bildschirm-Symbol nur ein Rechteck waere.
        'monitor' => sprintf(
            '<g fill="none" stroke="%1$s" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="-13" y="-10" width="26" height="17" rx="4"/>'
            . '<line x1="-13" y1="-1" x2="13" y2="-1"/>'
            . '<circle cx="-7" cy="10" r="2.5" fill="%1$s" stroke="none"/>'
            . '<circle cx="7" cy="10" r="2.5" fill="%1$s" stroke="none"/>'
            . '</g>',
            $color
        ),
        // Dasselbe Dreieck+"!" wie board_render_departure_row() (Wiedererkennung),
        // hier aber ohne fixe Fuellung -- s. Funktionskommentar.
        'stoerung' => sprintf(
            '<polygon points="0,-13 13,10 -13,10" fill="none" stroke="%1$s" stroke-width="2.5" stroke-linejoin="round"/>'
            . '<text x="0" y="8" font-family="Atkinson Hyperlegible Next" font-weight="bold" font-size="14" fill="%1$s" text-anchor="middle">!</text>',
            $color
        ),
        'kalender' => sprintf(
            '<g fill="none" stroke="%1$s" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="-13" y="-10" width="26" height="22" rx="3"/>'
            . '<line x1="-13" y1="-3" x2="13" y2="-3"/>'
            . '<line x1="-7" y1="-13" x2="-7" y2="-7"/>'
            . '<line x1="7" y1="-13" x2="7" y2="-7"/>'
            . '</g>',
            $color
        ),
        // Sprechblase mit drei Punkten (TASK-26) -- Nachrichten-Metapher,
        // sofort von Kalender/Wetter unterscheidbar.
        'mqtt' => sprintf(
            '<g fill="none" stroke="%1$s" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="-13" y="-11" width="26" height="18" rx="6"/>'
            . '<circle cx="-6" cy="-2" r="1.3" fill="%1$s" stroke="none"/>'
            . '<circle cx="0" cy="-2" r="1.3" fill="%1$s" stroke="none"/>'
            . '<circle cx="6" cy="-2" r="1.3" fill="%1$s" stroke="none"/>'
            . '</g>'
            . '<polygon points="-8,7 -2,7 -6,13" fill="%1$s"/>',
            $color
        ),
        // Sonne statt Mond: der Schlafschirm zeigt die Wetterprognose, kein
        // Schlaf-Piktogramm (s. Funktionskommentar board_pagination_categories()).
        'wetter' => sprintf(
            '<g stroke="%1$s" stroke-width="2.5" stroke-linecap="round">'
            . '<circle cx="0" cy="0" r="7" fill="none"/>'
            . '<line x1="0" y1="-13" x2="0" y2="-10"/>'
            . '<line x1="0" y1="10" x2="0" y2="13"/>'
            . '<line x1="-13" y1="0" x2="-10" y2="0"/>'
            . '<line x1="10" y1="0" x2="13" y2="0"/>'
            . '<line x1="-9.2" y1="-9.2" x2="-7.1" y2="-7.1"/>'
            . '<line x1="7.1" y1="7.1" x2="9.2" y2="9.2"/>'
            . '<line x1="-9.2" y1="9.2" x2="-7.1" y2="7.1"/>'
            . '<line x1="7.1" y1="-7.1" x2="9.2" y2="-9.2"/>'
            . '</g>',
            $color
        ),
        default => '',
    };
}

/**
 * "Stand HH:MM" (Zeitpunkt der WL-Datenabfrage) + kanonische Pagination am
 * unteren Ende der Abfahrtenspalte. Die Pille erscheint nur, wenn es mehr
 * als eine Seite gibt. Ein Pfeil ohne Ziel (erste/letzte Seite) wird
 * ausgegraut statt weggelassen, damit die Pille immer gleich breit bleibt.
 */
/**
 * Seitenpille OHNE Pfeile (Nutzerwunsch 2026-08-23: "50% groesser, aber ohne
 * weitere Pfeile, nur Seitennummern" -- seit der Schlafschirm immer eine
 * zusaetzliche letzte Seite ist, wirkte die Pille mit Pfeilen an beiden Enden
 * zu voll). Jeder Slot zeigt entweder ein Kategorie-Icon ($pageCategories,
 * Nutzerwunsch 2026-08-26: "Monitor/Stoerung/Kalender/Wetter macht mehr
 * Sinn" statt reiner Zahlen -- board_pagination_categories()) oder, ohne
 * Kategorie, die alte Seitenzahl als Ziffer. Weiterhin tippbar: der linke/
 * rechte Pillenhalbraum sendet unsichtbar page_prev/page_next
 * (mapPaginationTouch() in touch_zone.cpp) -- die physischen weissen Tasten
 * bleiben ohnehin der primaere Navigationsweg. Die Slotbreite bleibt fix
 * (87px, aus totalPages -- NICHT aus dem Inhalt!): die Firmware rechnet
 * die Pillenbreite unabhaengig aus derselben Formel nach, ohne die Icons
 * zu kennen -- variable Breiten wuerden die Touch-Zonen verschieben.
 *
 * Rechtsbuendig an x=1083 (rechter Rand der Abfahrtenspalte) statt wie
 * frueher linksbuendig ab x=793: totalPages ist seit dem Schlafschirm-Slot
 * IMMER mindestens 2, oft 3-5 (Abfahrten + evtl. Stoerungen + Schlaf) -- eine
 * linksbuendige Pille waere bei 4+ Seiten und 87px breiten Slots ueber die
 * Trennlinie bei x=1113 hinausgewachsen.
 *
 * Hoehe/Radius sind NICHT die vollen +50%: die Pille steht in einem hart
 * begrenzten 60px-Band (BOARD_DEPARTURES_MAX_Y=1250 bis zur Trennlinie bei
 * y=1310) -- ein wortwoertlich 50% groesserer Kreis (r=30, Durchmesser 60)
 * wuerde das Band randlos ausfuellen. Slotbreite und Schrift bekommen die
 * vollen 50%, weil dort durch den Pfeil-Wegfall echter Platz frei wurde.
 */
function board_render_stand_and_pagination_svg(
    DateTimeImmutable $dataStand,
    int $currentPage,
    int $totalPages,
    bool $showPagination = true,
    // Seitenzahl -> 'monitor'|'stoerung'|'kalender'|'wetter' (board_pagination_categories()).
    // Leer = alte Ziffernpille (Faellt zurueck, kein Bruch fuer Aufrufer/Tests,
    // die die Seitenstruktur nicht kennen -- s. Funktionskommentar unten).
    array $pageCategories = [],
    // Anzahl wartender Nachrichten, neben dem Sprechblasen-Icon (Nutzerwunsch
    // 2026-09-04). null = nicht anzeigen. Steht IM Slot, nicht daneben: die
    // Slotbreite ist fix und wird von der Firmware unabhaengig nachgerechnet
    // (s. Funktionskommentar) -- eine breitere Pille verschoebe die Touchzonen.
    ?int $mqttCount = null,
    // Ersetzt das generische "Stand HH:MM". Gebraucht von der Kalenderseite:
    // dort bezieht sich $dataStand (Zeitpunkt der WL-Abfrage) auf Daten, die
    // auf der Seite gar nicht vorkommen -- gezeigt gehoert der Kalenderstand
    // (Nutzerbefund 2026-09-04). null = Regelfall.
    ?string $standText = null
): string {
    $standSvg = sprintf(
        '<text x="16" y="1286" font-family="Atkinson Hyperlegible Next" font-size="24" fill="black">%s</text>',
        htmlspecialchars($standText ?? ('Stand ' . $dataStand->format('H:i')), ENT_XML1)
    );

    // $showPagination=false: der Schlafschirm auf dem Weg in den ECHTEN
    // Tiefschlaf (Nutzerbefund 2026-08-23: "die paginierung ist jetzt aber
    // leider auch zu sehen, wenn das panel schlaeft") -- die Pille ist dann
    // toter Zierrat: der Touch-Controller wird bis zum naechsten Tastendruck
    // nicht mehr abgefragt, ein Tipp darauf taete nichts. "Stand HH:MM"
    // bleibt trotzdem stehen, das ist reine Information, keine Einladung
    // zum Tippen.
    //
    // In der Praxis nie mehr wahr (der Schlafschirm-Slot macht totalPages
    // >= 2) -- als Schutz fuer Direktaufrufe dieser reinen Funktion (Tests,
    // debug=svg&part=monitor) trotzdem stehen gelassen.
    if ($totalPages <= 1 || !$showPagination) {
        return $standSvg;
    }

    $pillWidth = board_pagination_pill_width($totalPages);
    // Linksbuendig in der Favoritenzeile (vorher rechtsbuendig auf x=1083 im
    // eigenen Band darueber).
    $pillStartX = BOARD_PAGINATION_LEFT_EDGE;
    $numberStartX = $pillStartX + (int) (BOARD_PAGINATION_SIDE_PADDING / 2) + (int) (BOARD_PAGINATION_SLOT_WIDTH / 2);
    $cy = BOARD_PAGINATION_TOP + (int) (BOARD_PAGINATION_HEIGHT / 2); // Mitte der Navigationszeile
    $baselineY = $cy + 13; // optische Mitte fuer die Ziffern-Ersatzdarstellung

    $pagesSvg = '';
    for ($p = 1; $p <= $totalPages; $p++) {
        $x = $numberStartX + ($p - 1) * BOARD_PAGINATION_SLOT_WIDTH;
        $isActive = $p === $currentPage;
        $color = $isActive ? 'white' : 'black';
        $iconSvg = board_pagination_icon_svg($pageCategories[$p] ?? '', $color);

        if ($isActive) {
            $pagesSvg .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="black"/>', $x, $cy, BOARD_PAGINATION_CIRCLE_RADIUS);
        }

        if ($iconSvg !== '') {
            // Nachrichten-Slot mit Anzahl: Icon nach links ruecken, Zahl
            // rechts daneben -- beides bleibt innerhalb der fixen Slotbreite
            // (100px; Icon ~39px breit nach Skalierung, Zahl <= 2 Stellen).
            //
            // NICHT auf der aktiven Seite: dort liegt ein schwarzer Kreis mit
            // r=30 unter dem Slot, Icon und Zahl nebeneinander sind zusammen
            // aber ~80px breit und ragten sichtbar ueber seinen Rand hinaus
            // (im gerenderten Bild geprueft). Verlust ist keiner -- wer auf der
            // Nachrichtenseite steht, liest die Anzahl im Seitenkopf
            // ("Nachrichten (6 von 8)"). Gebraucht wird sie genau dann, wenn
            // man WOANDERS ist.
            $zeigtAnzahl = ($pageCategories[$p] ?? '') === 'mqtt' && $mqttCount !== null && !$isActive;
            $iconX = $zeigtAnzahl ? $x - BOARD_PAGINATION_BADGE_SHIFT : $x;

            // Die Icons sind auf ~26px gezeichnet (+-13 um den Ursprung) und
            // waren fuer das alte 48px-Band gedacht; in der 74px-Zeile wirken
            // sie ohne Skalierung verloren. Skalieren statt neu zeichnen haelt
            // sie deckungsgleich mit drawStatusIcon() in der Firmware.
            $pagesSvg .= sprintf(
                '<g transform="translate(%d,%d) scale(%s)">%s</g>',
                $iconX, $cy, rtrim(rtrim(number_format(BOARD_PAGINATION_ICON_SCALE, 2, '.', ''), '0'), '.'), $iconSvg
            );

            if ($zeigtAnzahl) {
                $pagesSvg .= sprintf(
                    '<text x="%d" y="%d" text-anchor="middle" font-weight="bold" font-size="%d" fill="%s">%d</text>',
                    $x + BOARD_PAGINATION_BADGE_SHIFT, $baselineY - 3,
                    BOARD_PAGINATION_BADGE_FONT_SIZE, $color, $mqttCount
                );
            }
        } elseif ($isActive) {
            $pagesSvg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-weight="bold" font-size="%d" fill="white">%d</text>',
                $x, $baselineY, BOARD_PAGINATION_FONT_SIZE, $p
            );
        } else {
            $pagesSvg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-size="%d" fill="black">%d</text>',
                $x, $baselineY, BOARD_PAGINATION_FONT_SIZE, $p
            );
        }
    }

    return $standSvg . sprintf(
        // stroke-width 3 wie die Favoritenknoepfe: seit die Pille in derselben
        // Zeile direkt daneben steht, faellt ein duennerer Rand als
        // Ungenauigkeit auf (vorher stand sie allein in ihrem eigenen Band).
        '<g font-family="Atkinson Hyperlegible Next"><rect x="%d" y="%d" width="%d" height="%d" rx="%d" fill="white" stroke="black" stroke-width="3"/>%s</g>',
        $pillStartX, BOARD_PAGINATION_TOP, $pillWidth, BOARD_PAGINATION_HEIGHT,
        (int) (BOARD_PAGINATION_HEIGHT / 2), $pagesSvg
    );
}

/**
 * Schriftgroessen der Stoerungsseite. 46px ist EXAKT die Groesse des
 * Wetter-Fliesstextes (board_render_weather_svg(), s.
 * BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE) -- Nutzervorgabe 2026-09-04:
 * "Betriebsstoerung: Gleich Schriftgroesse wie Wettervorhersage". Vorher 32px,
 * und damit die kleinste Schrift auf dem ganzen Board, obwohl es die Seite mit
 * dem wichtigsten Text ist. Der Titel waechst mit (40 -> 54), sonst waere die
 * Ueberschrift kleiner als der Fliesstext darunter.
 */
const BOARD_DISRUPTIONS_TITLE_SIZE = 54;
const BOARD_DISRUPTIONS_TEXT_SIZE  = 46;
/** Zeilenabstand der Beschreibung, mit der Schrift mitskaliert (42 * 46/32). */
const BOARD_DISRUPTIONS_LINE_HEIGHT = 60;

/**
 * Verfuegbare Spaltenbreite der Abfahrten-/Stoerungsspalte (1067px, x=16
 * bis x=1083) geteilt durch die bei 39px gemessene mittlere Zeichenbreite
 * (17,37px/Zeichen, s. Task 4), linear auf BOARD_DISRUPTIONS_TEXT_SIZE
 * skaliert, 8% Sicherheitsabstand:
 * floor(1067 / (17.37 * 46/39) * 0.92) = 47. Mit den frueheren 32px waren es
 * 67 Zeichen -- wer die Schriftgroesse aendert, MUSS diesen Wert mitrechnen,
 * sonst laeuft die Zeile ueber x=1083 hinaus.
 */
const BOARD_DISRUPTIONS_MAX_CHARS_PER_LINE = 47;

/**
 * Abstand von der Titel-Grundlinie bis zur ersten Beschreibungszeile.
 * Mit der Schrift mitgewachsen (53 * 46/32 = 76): der alte Wert war fuer
 * 32px-Text bemessen und liesse den 46px-Fliesstext am Titel kleben.
 */
const BOARD_DISRUPTIONS_TITLE_GAP = 76;

/**
 * Wie board_wrap_text() (Task 4), aber mit hartem Zeilenlimit: ORF-
 * Stoerungstexte koennen mehrere hundert Zeichen lang sein (Spec §8) -- bei
 * mehr als $maxLines Zeilen wird die letzte Zeile so weit gekuerzt, dass
 * " …" noch dazupasst.
 *
 * @return list<string>
 */
function board_wrap_disruption_text(string $text, int $maxLines): array
{
    $lines = board_wrap_text($text, BOARD_DISRUPTIONS_MAX_CHARS_PER_LINE);

    if (count($lines) <= $maxLines) {
        return $lines;
    }

    $truncated = array_slice($lines, 0, $maxLines);
    $last = $truncated[$maxLines - 1];
    $budget = BOARD_DISRUPTIONS_MAX_CHARS_PER_LINE - 2; // Platz fuer " …"
    if (mb_strlen($last, 'UTF-8') > $budget) {
        $last = mb_substr($last, 0, $budget, 'UTF-8');
    }
    $truncated[$maxLines - 1] = rtrim($last) . ' …';

    return $truncated;
}

/**
 * Cursor-Layout der Stoerungsseite: Titel fett (BOARD_DISRUPTIONS_TITLE_SIZE)
 * + Beschreibung (BOARD_DISRUPTIONS_TEXT_SIZE), 50px Abstand vor jedem Titel,
 * BOARD_DISRUPTIONS_TITLE_GAP zwischen Titel und Beschreibung,
 * BOARD_DISRUPTIONS_LINE_HEIGHT Zeilenabstand in der Beschreibung, 40px nach
 * der letzten Beschreibungszeile bis zum Trennstrich. $alerts ist bereits auf
 * die Linien des aktiven Favoriten gefiltert (Aufgabe des Aufrufers, s.
 * Interfaces).
 *
 * Die Beschreibung wird nur so weit gekuerzt, wie der verbleibende Platz bis
 * BOARD_DEPARTURES_MAX_Y es erzwingt (frueher pauschal 3 Zeilen).
 *
 * KEIN Ueberlauf-Schutz: anders als board_paginate_departures() bricht diese
 * Funktion nicht auf eine neue Seite um, wenn der Inhalt zu lang wird --
 * bewusste Vereinfachung (Task 8: "ob Stoerungen selbst auf mehrere Seiten
 * muessen, ueberlaesst dieser Task dem Aufrufer... out of scope"). Setzt
 * voraus, dass die auf einen Favoriten gefilterten Alerts realistisch auf
 * eine Seite passen.
 *
 * @param list<array{title: string, description: string}> $alerts
 * @return list<array>
 */
function board_layout_disruptions(array $alerts): array
{
    $items = [];
    $cursor = 90;

    foreach ($alerts as $alert) {
        $titleTop = $cursor + 50;
        // Grundlinie = Oberkante + Versalhoehe (~0,8 der Schriftgroesse).
        // Frueher fix +20 fuer 40px Schrift; mit 54px saesse der Titel sonst
        // teilweise oberhalb seines eigenen Startpunkts.
        $titleBaseline = $titleTop + (int) round(BOARD_DISRUPTIONS_TITLE_SIZE * 0.8);

        // Wieviele Beschreibungszeilen passen ab hier noch auf die Seite?
        // Frueher pauschal 3 -- der Text wurde damit fast immer gekuerzt,
        // obwohl die ganze Spalte frei ist (Nutzerbefund 2026-08-22: "den
        // eigentlichen Text nicht kuerzen, es ist genug Platz"). Jetzt nur
        // noch so weit kuerzen, wie der Platz bis BOARD_DEPARTURES_MAX_Y es
        // wirklich erzwingt -- die Stoerungsseite bricht bewusst weiterhin
        // nicht auf eine Folgeseite um (s. Funktionskopf).
        $firstLineY = $titleBaseline + BOARD_DISRUPTIONS_TITLE_GAP;
        $available = BOARD_DEPARTURES_MAX_Y - $firstLineY;
        $maxLines = max(1, (int) floor($available / BOARD_DISRUPTIONS_LINE_HEIGHT) + 1);

        $items[] = ['type' => 'disruption_title', 'y' => $titleBaseline, 'text' => $alert['title']];

        $descLines = board_wrap_disruption_text($alert['description'], $maxLines);
        $y = $firstLineY;
        foreach ($descLines as $line) {
            $items[] = ['type' => 'disruption_line', 'y' => $y, 'text' => $line];
            $y += BOARD_DISRUPTIONS_LINE_HEIGHT;
        }

        $dividerY = $y - BOARD_DISRUPTIONS_LINE_HEIGHT + 40;
        $items[] = ['type' => 'disruption_divider', 'y' => $dividerY];
        $cursor = $dividerY;
    }

    return $items;
}

function board_render_disruptions_svg(array $items): string
{
    if ($items === []) {
        return '';
    }

    $out = '<g font-family="Atkinson Hyperlegible Next">';
    foreach ($items as $item) {
        $out .= match ($item['type']) {
            'disruption_title' => sprintf(
                '<text x="16" y="%d" font-weight="bold" font-size="%d" fill="black">%s</text>',
                $item['y'], BOARD_DISRUPTIONS_TITLE_SIZE, htmlspecialchars($item['text'], ENT_XML1)
            ),
            'disruption_line' => sprintf(
                '<text x="16" y="%d" font-size="%d" fill="black">%s</text>',
                $item['y'], BOARD_DISRUPTIONS_TEXT_SIZE, htmlspecialchars($item['text'], ENT_XML1)
            ),
            'disruption_divider' => sprintf(
                '<line x1="16" y1="%d" x2="1083" y2="%d" stroke="black" stroke-width="1"/>',
                $item['y'], $item['y']
            ),
        };
    }
    $out .= '</g>';

    return $out;
}
