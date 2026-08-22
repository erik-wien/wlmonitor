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
 * Fuellbreite des Akku-Balkens in Pixeln (0-48, proportional zu Prozent).
 * Das Akku-Icon sitzt im Kopfbereich bei transform="translate(1713,42)":
 * Umriss-Rechteck lokal x=0 width=56 (absolut x=1713-1769), Polklemme bei
 * lokal x=56 (absolut x=1769); der Fuellbalken beginnt bei lokal x=4
 * (absolut x=1717), also max. 48px breit. Minimum 2px, damit der Balken bei
 * sehr niedrigem Ladestand nicht komplett verschwindet (0% waere sonst nicht
 * von einem Rendering-Fehler zu unterscheiden).
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
    if ($favoriteTitles === []) {
        return '';
    }

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
 * Setzt das komplette Board-SVG zusammen: Grundformen, Kopfzeile,
 * Abfahrten- ODER Stoerungsseite (je nach $requestedPage), Stand+
 * Pagination, Wetterkarte, Touch-Leiste.
 *
 * Seitenzaehlung: board_paginate_departures() liefert totalPages fuer die
 * Abfahrten allein. Gibt es $filteredAlerts, kommt genau eine weitere
 * Seite dazu (die Stoerungsseite -- board_layout_disruptions() paginiert
 * selbst nicht, s. Task 8). $requestedPage bis totalDeparturePages zeigt
 * Abfahrten, totalDeparturePages+1 (falls vorhanden) zeigt Stoerungen.
 *
 * @param list<string> $touchBarFavoriteTitles 1-3 Titel
 * @param array $activeFavorite board_favorite()-Ergebnis
 * @param list<array> $filteredAlerts bereits auf $activeFavorite gefiltert
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
    int $wifiBars
): string {
    $defs = board_svg_defs();
    $chrome = board_render_chrome_svg($renderedAt, $batteryPercent, $wifiBars);
    $touchBar = board_render_touch_bar_svg($touchBarFavoriteTitles, $activeFavoriteIndex);
    $weatherSvg = board_render_weather_svg($weather);

    $departurePages = board_paginate_departures($activeFavorite, 1);
    $totalDeparturePages = $departurePages['totalPages'];
    $hasDisruptions = $filteredAlerts !== [];
    $totalPages = $totalDeparturePages + ($hasDisruptions ? 1 : 0);

    $requestedPage = max(1, min($totalPages, $requestedPage));

    if ($requestedPage <= $totalDeparturePages) {
        $items = board_paginate_departures($activeFavorite, $requestedPage)['items'];
        $mainSvg = board_render_departures_svg($items);
    } else {
        $mainSvg = board_render_disruptions_svg(board_layout_disruptions($filteredAlerts));
    }

    $standAndPagination = board_render_stand_and_pagination_svg($dataStand, $requestedPage, $totalPages);

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
        ? '<text x="1150" y="366" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="46" fill="black">Heute</text>'
        : '';

    $bodySvg = '';
    foreach ($bodyLines as $i => $line) {
        // 46px statt 39px, Zeilenabstand 54px statt 46px (Nutzerwunsch 2026-08-22).
        $y = 422 + $i * 54;
        $bodySvg .= sprintf(
            '<text x="1150" y="%d" font-family="Atkinson Hyperlegible" font-size="46" fill="black">%s</text>',
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
 * "Stand HH:MM" (Zeitpunkt der WL-Datenabfrage) + kanonische Pagination am
 * unteren Ende der Abfahrtenspalte. Die Pille erscheint nur, wenn es mehr
 * als eine Seite gibt. Ein Pfeil ohne Ziel (erste/letzte Seite) wird
 * ausgegraut statt weggelassen, damit die Pille immer gleich breit bleibt.
 */
function board_render_stand_and_pagination_svg(DateTimeImmutable $dataStand, int $currentPage, int $totalPages): string
{
    $standSvg = sprintf(
        '<text x="16" y="1286" font-family="Atkinson Hyperlegible" font-size="24" fill="black">Stand %s</text>',
        $dataStand->format('H:i')
    );

    if ($totalPages <= 1) {
        return $standSvg;
    }

    $backFill = $currentPage > 1 ? 'black' : '#b0b0b0';
    $forwardFill = $currentPage < $totalPages ? 'black' : '#b0b0b0';

    $pagesSvg = '';
    $slotWidth = 58;
    // Vereinfachtes, robustes Layout: Pfeile an den Raendern der Pille,
    // Seitenzahlen mittig verteilt.
    $pagesSvg .= sprintf('<text x="822" y="1289" text-anchor="middle" font-size="26" fill="%s">←</text>', $backFill);

    $numberStartX = 880;
    for ($p = 1; $p <= $totalPages; $p++) {
        $x = $numberStartX + ($p - 1) * 58;
        if ($p === $currentPage) {
            $pagesSvg .= sprintf('<circle cx="%d" cy="1280" r="20" fill="black"/>', $x);
            $pagesSvg .= sprintf('<text x="%d" y="1289" text-anchor="middle" font-weight="bold" font-size="24" fill="white">%d</text>', $x, $p);
        } else {
            $pagesSvg .= sprintf('<text x="%d" y="1289" text-anchor="middle" font-size="24" fill="black">%d</text>', $x, $p);
        }
    }
    $arrowX = $numberStartX + $totalPages * 58;
    $pagesSvg .= sprintf('<text x="%d" y="1289" text-anchor="middle" font-size="26" fill="%s">→</text>', $arrowX, $forwardFill);

    $pillWidth = max(290, $arrowX - 822 + 58);

    return $standSvg . sprintf(
        '<g font-family="Atkinson Hyperlegible"><rect x="793" y="1256" width="%d" height="48" rx="24" fill="white" stroke="black" stroke-width="2"/>%s</g>',
        $pillWidth, $pagesSvg
    );
}

/**
 * Verfuegbare Spaltenbreite der Abfahrten-/Stoerungsspalte (1067px, x=16
 * bis x=1083) geteilt durch die bei 39px gemessene mittlere Zeichenbreite
 * (17,37px/Zeichen, s. Task 4), linear auf 32px skaliert, 8% Sicherheits-
 * abstand: floor(1067 / (17.37 * 32/39) * 0.92) = 68, hier auf 67 abgerundet
 * (Review-Befund: die urspruengliche Formel im Plan ergab bereits 68, nicht
 * 67 -- 67 ist die konservativere, schmalere Wahl und kann daher nie zum
 * Ueberlauf ueber x=1083 fuehren, nutzt die verfuegbare Breite nur minimal
 * weniger aus).
 */
const BOARD_DISRUPTIONS_MAX_CHARS_PER_LINE = 67;

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
 * Cursor-Layout der Stoerungsseite: Titel fett (40px) + gekuerzte
 * Beschreibung (32px, max. 3 Zeilen), 50px Abstand vor jedem Titel, 16px
 * zwischen Titel und Beschreibung, 42px Zeilenabstand innerhalb der
 * Beschreibung, 40px nach dem letzten Beschreibungszeile bis zum
 * Trennstrich. $alerts ist bereits auf die Linien des aktiven Favoriten
 * gefiltert (Aufgabe des Aufrufers, s. Interfaces).
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
        $titleBaseline = $titleTop + 20;
        $items[] = ['type' => 'disruption_title', 'y' => $titleBaseline, 'text' => $alert['title']];

        $descLines = board_wrap_disruption_text($alert['description'], 3);
        $y = $titleBaseline + 16 + 16;
        foreach ($descLines as $line) {
            $items[] = ['type' => 'disruption_line', 'y' => $y, 'text' => $line];
            $y += 42;
        }

        $dividerY = $y - 42 + 40;
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

    $out = '<g font-family="Atkinson Hyperlegible">';
    foreach ($items as $item) {
        $out .= match ($item['type']) {
            'disruption_title' => sprintf(
                '<text x="16" y="%d" font-weight="bold" font-size="40" fill="black">%s</text>',
                $item['y'], htmlspecialchars($item['text'], ENT_XML1)
            ),
            'disruption_line' => sprintf(
                '<text x="16" y="%d" font-size="32" fill="black">%s</text>',
                $item['y'], htmlspecialchars($item['text'], ENT_XML1)
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
