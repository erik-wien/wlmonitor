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
SVG;
}
