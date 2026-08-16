<?php
// inc/board_template.php
//
// Board-SVG-Template aus Spec §9: SVG-Grundformen, Kopf-/Fusszeile,
// Wetterkarte, Abfahrtenliste. Ausschliesslich reine Funktionen -- kein
// $con, kein Netz, keine date()/time()-Aufrufe (Zeitwerte kommen als
// DateTimeImmutable herein), analog zu inc/board.php und inc/weather.php.
declare(strict_types=1);

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
 * zwei Zeichen (z.B. "U6", "18"), 24px bei drei Zeichen (z.B. "WLB") --
 * sonst wuerde das Label ueber den 68px-Badge-Rand hinausragen.
 */
function board_badge_label_font_size(string $label): int
{
    return mb_strlen($label, 'UTF-8') >= 3 ? 24 : 26;
}

/**
 * Der komplette <defs>-Innenblock (ohne die <defs>-Tags selbst) aus Spec §9:
 * Badges (4 Formen) und Wetter-Icons (9 Kategorien + Fallback), aus
 * Kreis/Wolken-Outline/Linien-Grundformen gebaut, am gerenderten Bild
 * abgenommen (docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md
 * §9 "Icon-Set" + "Badges").
 */
function board_svg_defs(): string
{
    return <<<'SVG'
<g id="sun">
  <circle cx="0" cy="0" r="16" fill="black"/>
  <g stroke="black" stroke-width="4">
    <line x1="0" y1="-26" x2="0" y2="-34"/><line x1="0" y1="26" x2="0" y2="34"/>
    <line x1="-26" y1="0" x2="-34" y2="0"/><line x1="26" y1="0" x2="34" y2="0"/>
    <line x1="-18" y1="-18" x2="-24" y2="-24"/><line x1="18" y1="-18" x2="24" y2="-24"/>
    <line x1="-18" y1="18" x2="-24" y2="24"/><line x1="18" y1="18" x2="24" y2="24"/>
  </g>
</g>
<path id="cloudOutline" d="M -32,14 A 14,14 0 0 1 -20,-6 A 18,18 0 0 1 14,-14 A 16,16 0 0 1 32,4
         A 11,11 0 0 1 30,26 L -26,26 A 11,11 0 0 1 -32,14 Z"
      fill="white" stroke="black" stroke-width="5" stroke-linejoin="round"/>
<path id="cloudFilled" d="M -32,14 A 14,14 0 0 1 -20,-6 A 18,18 0 0 1 14,-14 A 16,16 0 0 1 32,4
         A 11,11 0 0 1 30,26 L -26,26 A 11,11 0 0 1 -32,14 Z"
      fill="black"/>

<g id="icon_klar">
  <use href="#sun"/>
</g>
<g id="icon_leicht_bewoelkt">
  <use href="#sun" transform="translate(-7,-17) scale(0.55)"/>
  <use href="#cloudOutline" transform="translate(5,7)"/>
</g>
<g id="icon_bewoelkt">
  <use href="#cloudOutline" transform="translate(-7,-5) scale(0.8)"/>
  <use href="#cloudOutline" transform="translate(6,9)"/>
</g>
<g id="icon_bedeckt">
  <use href="#cloudOutline" transform="scale(1.12)"/>
</g>
<g id="icon_regen_leicht">
  <use href="#cloudOutline" transform="translate(0,-8)"/>
  <g stroke="black" stroke-width="4" stroke-linecap="round">
    <line x1="-14" y1="22" x2="-19" y2="34"/>
    <line x1="0"   y1="22" x2="-5"  y2="34"/>
    <line x1="14"  y1="22" x2="9"   y2="34"/>
  </g>
</g>
<g id="icon_regen_stark">
  <use href="#cloudFilled" transform="translate(0,-10) scale(1.05)"/>
  <g stroke="black" stroke-width="4" stroke-linecap="round">
    <line x1="-20" y1="20" x2="-26" y2="35"/>
    <line x1="-9"  y1="20" x2="-15" y2="35"/>
    <line x1="2"   y1="20" x2="-4"  y2="35"/>
    <line x1="13"  y1="20" x2="7"   y2="35"/>
    <line x1="24"  y1="20" x2="18"  y2="35"/>
  </g>
</g>
<g id="icon_schnee">
  <use href="#cloudOutline" transform="translate(0,-8)"/>
  <g stroke="black" stroke-width="3" stroke-linecap="round">
    <g transform="translate(-16,27)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
    <g transform="translate(0,33)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
    <g transform="translate(16,27)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
  </g>
</g>
<g id="icon_gewitter">
  <use href="#cloudFilled" transform="translate(0,-12) scale(1.05)"/>
  <polygon points="2,10 -10,26 -1,26 -6,42 10,22 0,22 6,10" fill="black"/>
</g>
<g id="icon_nebel">
  <g stroke="black" stroke-width="5" stroke-linecap="round">
    <line x1="-30" y1="-18" x2="30" y2="-18"/>
    <line x1="-22" y1="-6"  x2="30" y2="-6"/>
    <line x1="-30" y1="6"   x2="22" y2="6"/>
    <line x1="-18" y1="18"  x2="30" y2="18"/>
  </g>
</g>
<g id="icon_unbekannt">
  <circle r="26" fill="white" stroke="black" stroke-width="5"/>
  <text x="0" y="11" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="34"
        fill="black" text-anchor="middle">?</text>
</g>

<g id="badgeTram"><circle r="34" fill="black"/></g>
<g id="badgeBus"><rect x="-34" y="-34" width="68" height="68" rx="14" fill="black"/></g>
<g id="badgeMetro"><rect x="-34" y="-34" width="68" height="68" fill="black"/></g>
<g id="badgeTrain"><rect x="-34" y="-34" width="68" height="68" rx="14" fill="white" stroke="black" stroke-width="5"/></g>
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
 * Fuellbreite des Akku-Balkens in Pixeln (0-48, proportional zu Prozent).
 * Innenflaeche des Umriss-Rechtecks (x=16 width=56) reicht bis x=68 (4px
 * vor der Polklemme bei x=72); der Fuellbalken beginnt bei x=20, also
 * max. 48px breit. Minimum 2px, damit der Balken bei sehr niedrigem
 * Ladestand nicht komplett verschwindet (0% waere sonst nicht von einem
 * Rendering-Fehler zu unterscheiden).
 */
function board_battery_fill_width(int $percent): int
{
    $percent = max(0, min(100, $percent));
    return max(2, (int) round(48 * $percent / 100));
}

/**
 * Kopfzeile aus Spec (Stand 2026-08-16): Logo (schwarz/weiss), zentrierte
 * Server-Renderzeit, Akku+WLAN in einer Zeile rechtsbuendig auf x=1856,
 * plus beide Trennlinien (vertikale Spaltenlinie, Fusszeilen-Trennlinie).
 * "Stand HH:MM" und die Touch-Leiste sind NICHT Teil dieser Funktion
 * (Task 6b bzw. Task 3b).
 */
function board_render_chrome_svg(DateTimeImmutable $renderedAt, int $batteryPercent, int $wifiBars): string
{
    $wifiBars = max(0, min(3, $wifiBars));
    $fillWidth = board_battery_fill_width($batteryPercent);
    $percent = max(0, min(100, $batteryPercent));

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

    $logo = board_wl_logo_paths();

    return <<<SVG
<line x1="0" y1="90" x2="1872" y2="90" stroke="black" stroke-width="2"/>
<g transform="translate(24,12) scale(0.5025)">
{$logo}
</g>
<text x="936" y="55" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="34" fill="black" text-anchor="middle">{$renderedAt->format('H:i')}</text>

<g font-family="Atkinson Hyperlegible" fill="black">
  <g transform="translate(1665,46)">{$wifiBarsSvg}</g>
  <g transform="translate(1713,42)">
    <rect x="0" y="0" width="56" height="26" rx="3" fill="white" stroke="black" stroke-width="3"/>
    <rect x="56" y="7" width="7" height="12" fill="black"/>
    <rect x="4" y="4" width="{$fillWidth}" height="18" fill="black"/>
  </g>
  <text x="1856" y="63" text-anchor="end" font-weight="bold" font-size="24">{$percent} %</text>
</g>

<line x1="1113" y1="90" x2="1113" y2="1310" stroke="black" stroke-width="2"/>
<line x1="0" y1="1310" x2="1872" y2="1310" stroke="black" stroke-width="2"/>
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
function board_render_touch_bar_svg(array $favoriteTitles, int $activeIndex): string
{
    $count = count($favoriteTitles);
    $margin = 16;
    $gap = 16;
    $buttonWidth = intdiv(1872 - 2 * $margin - ($count - 1) * $gap, $count);

    $out = '<g font-family="Atkinson Hyperlegible" font-weight="bold" font-size="34">';
    foreach ($favoriteTitles as $i => $title) {
        $x = $margin + $i * ($buttonWidth + $gap);
        $active = $i === $activeIndex;
        $out .= sprintf(
            '<rect x="%d" y="1320" width="%d" height="74" rx="10" %s/>',
            $x, $buttonWidth,
            $active ? 'fill="black"' : 'fill="white" stroke="black" stroke-width="3"'
        );
        $out .= sprintf(
            '<text x="%d" y="1367" text-anchor="middle" fill="%s">%s</text>',
            $x + intdiv($buttonWidth, 2), $active ? 'white' : 'black',
            htmlspecialchars($title, ENT_XML1)
        );
    }
    $out .= '</g>';

    return $out;
}

/**
 * Verfuegbare Spaltenbreite der Wetterkarte (706px, x=1150 bis x=1856)
 * geteilt durch die gemessene mittlere Zeichenbreite bei 39px Atkinson
 * Hyperlegible (17,37px/Zeichen, s. Task 4 Step 3), 8% Sicherheitsabstand
 * gegen ueberdurchschnittlich breite Saetze: floor(706 / 17.37 * 0.92).
 */
const BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE = 37;

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
 * Wetterkarte aus Spec §9: Icon, Temperatur "von-bis", Ueberschrift "Heute",
 * Fliesstext mit manuellem Zeilenumbruch. $weather ist die Rueckgabe von
 * weather_select_display() (inc/weather.php) -- 'available' => false heisst
 * "noch nie erfolgreich abgerufen" (z.B. vor dem ersten Cron-Lauf), nicht
 * dasselbe wie der ">6h veraltet"-Fall (dort bleiben Icon/Temp erhalten,
 * nur der Text wird ersetzt, s. Spec §8).
 */
function board_render_weather_svg(array $weather): string
{
    if ($weather['available'] === false) {
        return board_render_weather_card('icon_unbekannt', null, null, ['Wetterdaten werden geladen …']);
    }

    $iconId = BOARD_ICON_ID_BY_CATEGORY[$weather['icon_category']] ?? BOARD_ICON_ID_BY_CATEGORY['unbekannt'];
    $bodyText = $weather['text'] ?? $weather['text_error'] ?? '';
    $lines = board_wrap_text($bodyText, BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE);

    return board_render_weather_card($iconId, $weather['temp_min'], $weather['temp_max'], $lines);
}

/** @param list<string> $bodyLines */
function board_render_weather_card(string $iconId, ?int $tempMin, ?int $tempMax, array $bodyLines): string
{
    $tempSvg = $tempMin !== null && $tempMax !== null
        ? sprintf(
            '<text x="1492" y="290" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="40" fill="black" text-anchor="middle">%d° – %d°C</text>',
            $tempMin, $tempMax
        )
        : '';

    $headingSvg = $tempMin !== null
        ? '<text x="1150" y="366" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="30" fill="black">Heute</text>'
        : '';

    $bodySvg = '';
    foreach ($bodyLines as $i => $line) {
        $y = 422 + $i * 46;
        $bodySvg .= sprintf(
            '<text x="1150" y="%d" font-family="Atkinson Hyperlegible" font-size="39" fill="black">%s</text>',
            $y, htmlspecialchars($line, ENT_XML1)
        );
    }

    return <<<SVG
<g transform="translate(1492,180) scale(1.8)">
  <use href="#{$iconId}"/>
</g>
{$tempSvg}
{$headingSvg}
{$bodySvg}
SVG;
}

/**
 * Unterste zulaessige Y-Position fuer eine Zeilen-Badge-Unterkante, bevor
 * auf eine neue Seite umgebrochen wird -- reserviert die letzten 60px der
 * Abfahrtenspalte (1310-1250) fuer die Stand+Pagination-Leiste (Task 6b).
 */
const BOARD_DEPARTURES_MAX_Y = 1250;

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
 * @return array{items: list<array>, totalPages: int}
 */
function board_paginate_departures(array $favorite, int $page): array
{
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
    $out = '<g font-family="Atkinson Hyperlegible">';

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

function board_render_departure_row(array $item): string
{
    $r = $item['r'];
    $badgeShape = BOARD_BADGE_SHAPE_BY_TYPE[$item['badge_type']] ?? BOARD_BADGE_SHAPE_BY_TYPE['other'];
    $labelSize = board_badge_label_font_size($item['label']);
    $isGray = $item['style'] === 'gray';
    $isDelayed = $item['style'] === 'delayed';
    $fill = $isGray ? '#808080' : 'black';

    $out = sprintf('<use href="#%s" transform="translate(54,%d)"/>', $badgeShape, $r);
    $out .= sprintf(
        '<text x="54" y="%d" font-weight="bold" font-size="%d" fill="white" text-anchor="middle">%s</text>',
        $r + 9, $labelSize, htmlspecialchars($item['label'], ENT_XML1)
    );
    $out .= sprintf(
        '<text x="110" y="%d" font-weight="bold" font-size="22" fill="%s">%s</text>',
        $r + 8, $fill, htmlspecialchars($item['platform'], ENT_XML1)
    );
    $out .= sprintf(
        '<text x="145" y="%d" font-size="55" fill="%s">%s</text>',
        $r + 19, $fill, htmlspecialchars($item['destination'], ENT_XML1)
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
        $liveText = $item['live_in'] === null ? '–' : (string) $item['live_in'];
        $out .= sprintf(
            '<text x="1000" y="%d" font-weight="bold" font-size="46" fill="%s" text-anchor="end">%s</text>',
            $r + 16, $liveFill, $liveText
        );
    }

    if ($item['secondary_in'] !== null) {
        $out .= sprintf('<text x="1015" y="%d" font-size="20" fill="%s">·</text>', $r + 7, $fill);

        if ($item['secondary_in'] === 0) {
            $out .= sprintf('<use href="#starNow" transform="translate(1073,%d) scale(0.696)" stroke="%s"/>', $r, $fill);
        } else {
            $out .= sprintf(
                '<text x="1083" y="%d" font-size="32" fill="%s" text-anchor="end">%s</text>',
                $r + 11, $fill, (string) $item['secondary_in']
            );
        }
    }

    $out .= sprintf('<line x1="16" y1="%d" x2="1083" y2="%d" stroke="black" stroke-width="1"/>', $item['divider_y'], $item['divider_y']);

    return $out;
}
