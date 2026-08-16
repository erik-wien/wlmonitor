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
<g id="starNow" stroke="black" stroke-width="7" stroke-linecap="round">
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

    return trim($m[1]);
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
 * Kopf- und Fusszeile aus Spec §9: Logo, "Stand HH:MM", die vertikale
 * Spaltenlinie Abfahrten|Wetter, die Fusszeilen-Trennlinie und die
 * Statuszeile (Akku/Uhrzeit/WLAN-Balken).
 *
 * $dataStand ist der Zeitpunkt der WL-Datenabfrage ("Stand HH:MM" oben
 * rechts), $renderedAt die Serverzeit beim Rendern (Uhrzeit in der
 * Fusszeile) -- beide bewusst getrennte Werte, s. Spec §9. $wifiBars ist
 * bereits von RSSI in {0,1,2,3} umgerechnet (Aufgabe der aufrufenden
 * Board-Protokoll-Schicht, nicht dieser Funktion).
 */
function board_render_chrome_svg(
    DateTimeImmutable $dataStand,
    DateTimeImmutable $renderedAt,
    int $batteryPercent,
    int $wifiBars
): string {
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
<text x="1857" y="60" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="39" fill="black" text-anchor="end">Stand {$dataStand->format('H:i')}</text>

<line x1="1113" y1="90" x2="1113" y2="1310" stroke="black" stroke-width="2"/>
<line x1="0" y1="1310" x2="1872" y2="1310" stroke="black" stroke-width="2"/>

<g font-family="Atkinson Hyperlegible" font-size="28" fill="black">
  <rect x="16" y="1338" width="56" height="26" rx="3" fill="white" stroke="black" stroke-width="3"/>
  <rect x="72" y="1345" width="7" height="12" fill="black"/>
  <rect x="20" y="1342" width="{$fillWidth}" height="18" fill="black"/>
  <text x="95" y="1360" font-weight="bold">{$percent} %</text>
  <text x="936" y="1360" text-anchor="middle" font-weight="bold">{$renderedAt->format('H:i')}</text>
  <g transform="translate(1830,1352)">{$wifiBarsSvg}</g>
</g>
SVG;
}
