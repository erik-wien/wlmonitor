# Board-SVG-Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein reines, testbares PHP-Modul, das aus den bereits bestehenden
Datenformen (`board_favorite()` aus `inc/board.php`, `weather_select_display()`
aus `inc/weather.php`, plus Statuszeilen-Werten) das vollständige Board-SVG
gemäß Spec §9 erzeugt — bereit, um von `svg_to_png()` +
`png_to_1bpp_packed()` (`inc/board_render.php`, bereits vorhanden) zu einem
1bpp-Frame verarbeitet zu werden.

**Architektur:** Neue Datei `inc/board_template.php`, ausschließlich reine
Funktionen (kein `$con`, kein Netz, kein `date()`/`time()` — Zeitwerte kommen
als `DateTimeImmutable`-Parameter herein), analog zu `inc/board.php` und
`inc/weather.php`. Fünf Bausteine, die sich zu einer Funktion
`board_render_svg()` zusammensetzen: SVG-Grundformen (`<defs>`), Kopf-/
Fußzeile, Wetterkarte, Abfahrtenliste (Layout getrennt von Rendering). Jeder
Baustein ist einzeln unit-testbar; die Endmontage bekommt zusätzlich einen
Integrationstest über die echte `rsvg-convert`/GD-Pipeline.

**Tech Stack:** PHP 8.2, `ext-gd`, `rsvg-convert` (Homebrew librsvg),
PHPUnit ^13 (bestehende Suite unter `tests/Unit/`).

**Nicht Teil dieses Plans** (siehe Spec §13 / Phasenaufteilung): der
Bild-Protokoll-Endpunkt `web/board.php` (Auth, Diff/Patch, ETag — eigener
Plan „Board-Protokoll"), die Umrechnung `X-Device-Battery-mV`→Prozent und
`X-Device-RSSI`→Balkenzahl (Aufgabe des Protokoll-Plans, dieser Plan nimmt
beides bereits fertig berechnet entgegen), Überlauf-Behandlung bei mehr
Zeilen als vertikal Platz haben (mit den beiden echten Testfavoriten aus
diesem Plan — 6 bzw. 5 Zeilen — bleibt reichlich Reserve bis zur Fußzeile;
die tatsächliche Zahl gleichzeitig angezeigter Favoriten/Stationen legt der
Board-Protokoll-Plan fest).

## Global Constraints

Alle Werte aus `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`
§9, wörtlich übernommen — bei Widersprüchen zwischen dieser Liste und einer
Taskbeschreibung gilt die Spec:

- Canvas: `1872×1404`, `viewBox="0 0 1872 1404"`.
- Kopfzeile: Trennlinie bei `y=90`. Logo `translate(24,12) scale(0.5025)`.
  „Stand HH:MM" rechtsbündig `x=1857 y=60`, 39px fett.
- Vertikale Trennlinie Abfahrten|Wetter: `x=1113`. Abfahrten-Inhalt endet bei
  `x=1083`. Wetter-Inhalt beginnt bei `x=1150`.
- Fußzeile: Trennlinie bei `y=1310`, Inhalt bis `y=1404`.
- Zeilenraster Abfahrten: 96px von Badge-Mitte zu Badge-Mitte. Vor jedem
  Stationskopf 58px Abstand (Cursor → Cap-Top, Cap-Top mit 48px
  Umlaut-Worst-Case ab Baseline), danach 29px (Kopf-Baseline → höchstes
  Element der ersten Zeile, i. d. R. das Badge). Cursor nach einem Block =
  Badge-Unterkante der letzten Zeile (`R+34`).
- Zeilen-interne Ausrichtung, `R` = Badge-Mitte, `translate(54,R)`:
  Badge-Label `R+9` (26px fett), Steig-Nummer `x=110` `R+8` (22px fett),
  Fahrtrichtung `x=145` `R+19` (55px), Live-Abfahrt `x=1000`
  rechtsbündig `R+16` (46px fett), Trennpunkt „·" `x=1015` `R+7` (20px),
  Folgeabfahrt `x=1083` rechtsbündig `R+11` (32px). Divider `R+48`
  (`x=16` bis `x=1083`, 1px).
- Badge-Formen: `metro`=Quadrat 68×68 gefüllt, `tram`=Kreis r=34 gefüllt,
  `bus`=Rechteck 68×68 rx=14 gefüllt, `train`=Rechteck 68×68 rx=14
  ungefüllt/5px Rand. `other` (Fallback von `board_type()`) nutzt die
  `train`-Form (neutralste/„unbekannt wirkende" Form, da `other` nur bei
  einem WL-Fahrzeugtyp außerhalb der vier bekannten greift).
  Badge-Label-Schriftgröße: 26px bei ≤2 Zeichen, 24px bei 3 Zeichen (WLB).
- „Nur Fahrplan" (`realtime === false`): Live- UND Folgeabfahrt kursiv statt
  fett. „Gestört" (`departures[0]['delayed'] === true`): Rechteck
  `x=950 y=(R-20) width=60 height=42 fill=black`, Live-Abfahrt-Text
  `fill="white"`, sonst unverändert. Gestört schlägt „nur Fahrplan" (Tie-Break
  aus Spec: fett-weiß-auf-schwarz, nicht kursiv).
- `"in": 0` → Jetzt-Symbol `#starNow` (selbst gezeichneter Stern aus 3 dicken
  Linien, `stroke-width=7`) statt Zahl — **kein Unicode-Zeichen**, sowohl
  „✱" als auch „✳︎" wurden am gerenderten Bild geprüft und verworfen (zu
  dünn bzw. gar kein Glyph im Fallback-Font, s. Spec §9). Live-Slot:
  `translate(985,R)`. Folgeabfahrt-Slot: `translate(1073,R) scale(0.696)`.
- Wetterkarte: Icon `translate(1492,180) scale(1.8)`. Temperatur
  `x=1492 y=290`, 40px fett, zentriert. „Heute" `x=1150 y=366`, 30px fett.
  Fließtext ab `x=1150 y=422`, 46px Zeilenabstand, 39px Schrift.
- Wetter-Icon-Grundformen und Kategorie→Icon-Id-Tabelle: wörtlich aus Spec §9
  „Icon-Set" (`<defs>`-Block + Tabelle).
- Font: `Atkinson Hyperlegible` (Familienname, wie in den TTF-Metadaten von
  `assets/fonts/board/*.ttf` hinterlegt). Kein anderer Font-Name im Template.
- Bit-/Farbkonvention: ausschließlich `fill="black"` / `fill="white"` /
  `stroke="black"` — keine anderen Farbwerte im selbst erzeugten Markup
  (Badges, Text, Wetter-Icons, Trennlinien, Statuszeile). **Ausnahme:** das
  eingebettete WL-Logo (`assets/img/wl-logo.svg`, Task 3) führt seine
  echten Markenfarben unverändert mit — der harte Schwellwert in
  `png_to_1bpp_packed()` (Spec §7) reduziert jede Farbe anhand ihrer
  Luminanz auf Schwarz/Weiß, unabhängig davon, ob die Quelle schon
  monochrom war. Genau so wurde das Logo in jedem Mockup dieser
  Design-Session gerendert und abgenommen.

---
### Task 1: Font-Einbindung für `rsvg-convert` (`FONTCONFIG_FILE`)

**Files:**
- Modify: `inc/board_render.php`
- Test: `tests/Unit/BoardRenderTest.php`

**Interfaces:**
- Produces: `board_fontconfig_path(): string` — erzeugt/aktualisiert eine
  Fontconfig-XML unter `sys_get_temp_dir()` und gibt ihren Pfad zurück.
  Spätere Tasks brauchen diese Funktion nicht direkt (sie ist intern an
  `svg_to_png()` gekoppelt), aber sie muss von außen aufrufbar/testbar sein.
- `svg_to_png()` bleibt öffentlich mit unveränderter Signatur
  (`svg_to_png(string $svg): string`) — nur die interne `proc_open()`-Zeile
  ändert sich (expliziter `$env`-Parameter statt vererbter Umgebung).

- [ ] **Step 1: Fehlschlagenden Test schreiben — Fontconfig-Datei zeigt auf den echten Font-Ordner**

```php
public function test_fontconfig_path_points_at_board_fonts_dir(): void
{
    $path = board_fontconfig_path();

    $this->assertFileExists($path);
    $xml = file_get_contents($path);
    $expectedDir = realpath(__DIR__ . '/../../assets/fonts/board');
    $this->assertNotFalse($expectedDir, 'assets/fonts/board muss existieren');
    $this->assertStringContainsString('<dir>' . $expectedDir . '</dir>', $xml);
}
```

Füge diesen Test in `tests/Unit/BoardRenderTest.php` am Ende der Klasse ein.

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php --filter test_fontconfig_path_points_at_board_fonts_dir`
Expected: FAIL mit „Call to undefined function board_fontconfig_path()"

- [ ] **Step 3: `board_fontconfig_path()` implementieren**

In `inc/board_render.php`, vor `svg_to_png()` einfügen:

```php
/**
 * Erzeugt (bzw. aktualisiert) eine Fontconfig-XML, die ausschliesslich auf
 * assets/fonts/board/ zeigt, und gibt ihren Pfad zurueck. rsvg-convert
 * findet Schriften ueber Fontconfig, nicht ueber @font-face -- ohne dieses
 * Env-Var faellt "Atkinson Hyperlegible" still auf eine Systemschrift
 * zurueck (kein Fehler, nur falsche Optik). Pfad relativ zu __DIR__, damit
 * Dev und Prod ohne Konfigurationsaenderung funktionieren.
 *
 * @throws RuntimeException wenn assets/fonts/board fehlt
 */
function board_fontconfig_path(): string
{
    $fontsDir = realpath(__DIR__ . '/../assets/fonts/board');
    if ($fontsDir === false) {
        throw new RuntimeException('assets/fonts/board nicht gefunden');
    }

    $cacheDir = sys_get_temp_dir() . '/wlmonitor-board-fontcache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $xml = '<?xml version="1.0"?>' . "\n"
        . '<!DOCTYPE fontconfig SYSTEM "fonts.dtd">' . "\n"
        . '<fontconfig>' . "\n"
        . '  <dir>' . $fontsDir . '</dir>' . "\n"
        . '  <cachedir>' . $cacheDir . '</cachedir>' . "\n"
        . '</fontconfig>' . "\n";

    $path = sys_get_temp_dir() . '/wlmonitor-board-fontconfig.xml';
    file_put_contents($path, $xml);

    return $path;
}
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php --filter test_fontconfig_path_points_at_board_fonts_dir`
Expected: PASS

- [ ] **Step 5: Fehlschlagenden Test schreiben — `svg_to_png()` nutzt die Fontconfig-Datei**

```php
public function test_svg_to_png_renders_atkinson_hyperlegible_not_a_fallback_font(): void
{
    // "iiiiiiiiii" (schmale Buchstaben) vs "mmmmmmmmmm" (breite) bei
    // gleicher Zeichenzahl: bei jeder realistischen Schriftart liegt die
    // gerenderte Breite von "i"-Folgen deutlich unter der von "m"-Folgen.
    // Das ist kein Test auf die exakte Atkinson-Hyperlegible-Metrik,
    // sondern ein Rauchtest, dass ueberhaupt EINE Schrift mit
    // Buchstaben-Weitenunterschied geladen wurde (ein fehlendes
    // FONTCONFIG_FILE wuerde still auf eine Systemschrift zurueckfallen,
    // aber selbst das haette diesen Unterschied -- der eigentliche Zweck
    // ist, das Nichtabsturzen mit dem Custom-Font-Pfad zu belegen).
    $svgFor = fn (string $s) => sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="60" viewBox="0 0 800 60">'
        . '<text x="0" y="45" font-family="Atkinson Hyperlegible" font-size="39">%s</text></svg>',
        $s
    );

    $narrow = svg_to_png($svgFor('iiiiiiiiii'));
    $wide = svg_to_png($svgFor('mmmmmmmmmm'));

    $ink = function (string $png): int {
        $im = imagecreatefromstring($png);
        $maxX = 0;
        for ($x = imagesx($im) - 1; $x >= 0; $x--) {
            for ($y = 0; $y < imagesy($im); $y++) {
                $rgb = imagecolorat($im, $x, $y);
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($lum < 128) {
                    return $x;
                }
            }
        }
        return 0;
    };

    $this->assertGreaterThan($ink($narrow) + 100, $ink($wide),
        '"mmmmmmmmmm" muss deutlich breiter rendern als "iiiiiiiiii" -- ' .
        'eine echte Schrift wurde geladen und benutzt (kein leerer/fehlender Font-Fallback)');
}
```

- [ ] **Step 6: Test laufen lassen, Fehlschlag bestätigen (Env wird noch nicht gesetzt)**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php --filter test_svg_to_png_renders_atkinson_hyperlegible_not_a_fallback_font`
Expected: PASS läuft schon zufällig durch (jede Schrift hat diesen Breitenunterschied) — das ist erwartet, dieser Test allein beweist noch nicht die Font-Bindung. Der eigentliche Beleg kommt aus Step 4 (Datei zeigt auf den richtigen Ordner) + manueller Sichtprüfung unten. Trotzdem: Test jetzt einfügen und grün sehen, bevor Step 7 die Pipeline verdrahtet, damit ein Fehlschlag danach eindeutig auf die neue proc_open()-Zeile zurückführbar ist.

- [ ] **Step 7: `svg_to_png()` auf expliziten `$env`-Parameter umstellen**

In `inc/board_render.php`, `svg_to_png()` ändern:

```php
function svg_to_png(string $svg): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = getenv();
    $env['FONTCONFIG_FILE'] = board_fontconfig_path();

    $process = proc_open(['rsvg-convert', '-f', 'png', '-b', 'white'], $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('rsvg-convert konnte nicht gestartet werden');
    }

    // ... Rest unveraendert (fwrite/fclose/stream_get_contents/proc_close) ...
}
```

Nur die `$env`-Zeilen und der `proc_open()`-Aufruf ändern sich; der restliche
Funktionskörper (fwrite, Pipes schließen, Exit-Code prüfen) bleibt exakt wie
zuvor.

- [ ] **Step 8: Alle Tests der Datei laufen lassen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: PASS (alle bisherigen + die 2 neuen)

- [ ] **Step 9: Manuelle Sichtprüfung — Atkinson Hyperlegible wird tatsächlich verwendet**

```bash
php -r '
require "inc/board_render.php";
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"400\" height=\"80\" viewBox=\"0 0 400 80\">"
     . "<text x=\"10\" y=\"55\" font-family=\"Atkinson Hyperlegible\" font-weight=\"bold\" font-size=\"40\">WESTBAHNHOF</text></svg>";
file_put_contents("/tmp/font_check.png", svg_to_png($svg));
'
```

Öffne `/tmp/font_check.png` und vergleiche mit einem der in dieser Session
bereits gerenderten Mockups (z. B. Text „WESTBAHNHOF S U" — gleiche
zweistöckige "A", gleiche breite, hoch-x-Height-Anmutung). Weicht die
Schriftform sichtbar ab (schmalere Buchstaben, andere Serifen-Anmutung),
ist `FONTCONFIG_FILE` nicht wirksam — vor Fortsetzung debuggen.

- [ ] **Step 10: Commit**

```bash
git add inc/board_render.php tests/Unit/BoardRenderTest.php
git commit -m "feat(board): FONTCONFIG_FILE fuer rsvg-convert setzen (Atkinson Hyperlegible)"
```

---

### Task 2: SVG-Grundformen — Badges + Wetter-Icons (`inc/board_template.php`, neu)

**Files:**
- Create: `inc/board_template.php`
- Modify: `tests/bootstrap.php` (neue Datei in die Require-Liste aufnehmen)
- Test: `tests/Unit/BoardTemplateDefsTest.php`

**Interfaces:**
- Produces: `board_svg_defs(): string` — der komplette `<defs>`-Innenblock
  (ohne die `<defs>`-Tags selbst, die setzt Task 7 beim Zusammenbau).
  Spätere Tasks referenzieren die IDs `badgeMetro`, `badgeTram`, `badgeBus`,
  `badgeTrain` sowie `icon_klar` … `icon_unbekannt` per `<use href="#...">`.
- Produces: `const BOARD_BADGE_SHAPE_BY_TYPE` — Map `board_type()`-Ergebnis
  (`metro|tram|bus|train|other`) → Badge-Id (`badgeMetro|badgeTram|badgeBus|badgeTrain`).
- Produces: `const BOARD_ICON_ID_BY_CATEGORY` — Map `icon_category` aus
  `weather_map_icon_code()` → Icon-Id (`icon_klar` … `icon_unbekannt`).
- Produces: `function board_badge_label_font_size(string $label): int` —
  26 bei ≤2 Zeichen, 24 bei 3 Zeichen (mb-safe).

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateDefsTest.php
//
// SVG-Grundformen (Badges, Wetter-Icons) aus Spec §9. Reiner String-Vergleich
// gegen die in der Spec festgeschriebenen IDs -- kein Rendering hier (das
// prueft Task 7 ueber die echte Pipeline).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDefsTest extends TestCase
{
    private const EXPECTED_IDS = [
        'sun', 'cloudOutline', 'cloudFilled',
        'icon_klar', 'icon_leicht_bewoelkt', 'icon_bewoelkt', 'icon_bedeckt',
        'icon_regen_leicht', 'icon_regen_stark', 'icon_schnee', 'icon_gewitter',
        'icon_nebel', 'icon_unbekannt',
        'badgeMetro', 'badgeTram', 'badgeBus', 'badgeTrain',
    ];

    public function test_defs_contain_every_expected_id_exactly_once(): void
    {
        $defs = board_svg_defs();

        foreach (self::EXPECTED_IDS as $id) {
            $count = substr_count($defs, 'id="' . $id . '"');
            $this->assertSame(1, $count, "id=\"$id\" muss genau einmal vorkommen (gefunden: $count)");
        }
    }

    public function test_defs_is_well_formed_xml_fragment(): void
    {
        $wrapped = '<svg xmlns="http://www.w3.org/2000/svg"><defs>' . board_svg_defs() . '</defs></svg>';
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($wrapped);
        $errors = libxml_get_errors();
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'board_svg_defs() muss valides XML liefern');
        $this->assertSame([], $errors);
    }

    public function test_badge_shape_mapping_covers_all_board_types(): void
    {
        $this->assertSame('badgeMetro', BOARD_BADGE_SHAPE_BY_TYPE['metro']);
        $this->assertSame('badgeTram', BOARD_BADGE_SHAPE_BY_TYPE['tram']);
        $this->assertSame('badgeBus', BOARD_BADGE_SHAPE_BY_TYPE['bus']);
        $this->assertSame('badgeTrain', BOARD_BADGE_SHAPE_BY_TYPE['train']);
        $this->assertSame('badgeTrain', BOARD_BADGE_SHAPE_BY_TYPE['other'], 'other faellt auf die train-Form zurueck (Spec Global Constraints)');
    }

    public function test_icon_id_mapping_covers_all_nine_categories_plus_fallback(): void
    {
        $expected = [
            'klar' => 'icon_klar', 'leicht_bewoelkt' => 'icon_leicht_bewoelkt',
            'bewoelkt' => 'icon_bewoelkt', 'bedeckt' => 'icon_bedeckt',
            'regen_leicht' => 'icon_regen_leicht', 'regen_stark' => 'icon_regen_stark',
            'schnee' => 'icon_schnee', 'gewitter' => 'icon_gewitter',
            'nebel' => 'icon_nebel', 'unbekannt' => 'icon_unbekannt',
        ];
        foreach ($expected as $category => $iconId) {
            $this->assertSame($iconId, BOARD_ICON_ID_BY_CATEGORY[$category]);
        }
    }

    public function test_badge_label_font_size_shrinks_for_three_char_labels(): void
    {
        $this->assertSame(26, board_badge_label_font_size('U6'));
        $this->assertSame(26, board_badge_label_font_size('18'));
        $this->assertSame(24, board_badge_label_font_size('WLB'));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDefsTest.php`
Expected: FAIL mit „Class ... not found" / „Call to undefined function board_svg_defs()"

- [ ] **Step 3: `inc/board_template.php` mit den Grundformen anlegen**

```php
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
```

- [ ] **Step 4: `inc/board_template.php` in `tests/bootstrap.php` requiren**

In `tests/bootstrap.php`, nach der Zeile `require_once __DIR__ . '/../inc/board_render.php';`:

```php
require_once __DIR__ . '/../inc/board_template.php';
```

- [ ] **Step 5: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDefsTest.php`
Expected: PASS (5 Tests)

- [ ] **Step 6: Ganze Suite laufen lassen (Regressionscheck)**

Run: `vendor/bin/phpunit`
Expected: PASS, keine neuen Fehlschläge

- [ ] **Step 7: Commit**

```bash
git add inc/board_template.php tests/bootstrap.php tests/Unit/BoardTemplateDefsTest.php
git commit -m "feat(board): SVG-Grundformen fuer Badges und Wetter-Icons (board_svg_defs)"
```

---

### Task 3: Kopf- und Fußzeile (`board_render_chrome_svg()`)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateChromeTest.php`

**Interfaces:**
- Consumes: `assets/img/wl-logo.svg` (Datei, bereits im Repo).
- Produces:
  `board_wl_logo_paths(): string` — die inneren `<title>`/`<path>`-Elemente
  aus `assets/img/wl-logo.svg`, ohne die äußeren `<svg>`-Tags.
  `board_battery_fill_width(int $percent): int` — Balkenbreite 0–48px,
  proportional zu `$percent` (0–100), mindestens 2px sichtbar auch bei 0/1 %.
  `board_render_chrome_svg(DateTimeImmutable $dataStand, DateTimeImmutable $renderedAt, int $batteryPercent, int $wifiBars): string`
  — Kopf-/Fußzeile + beide Trennlinien (Kopfzeile, vertikale Spaltenlinie,
  Fußzeile) als fertiges SVG-Markup. `$wifiBars` ∈ {0,1,2,3} (Aufrufer hat
  bereits von RSSI umgerechnet, s. Global Constraints „Nicht Teil dieses
  Plans").
- `$batteryPercent` wird auf 0–100 geklemmt (`max(0, min(100, ...))`) —
  Verteidigung gegen unerwartete Eingaben von der (noch nicht existierenden)
  Board-Protokoll-Schicht, kein Spec-Fehlerfall.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateChromeTest.php
//
// Kopf-/Fusszeile aus Spec §9: Logo-Einbettung, "Stand"-Text, Trennlinien,
// Statuszeile (Akku/Uhrzeit/WLAN-Balken).

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateChromeTest extends TestCase
{
    public function test_logo_paths_contain_all_five_paths_no_outer_svg_tag(): void
    {
        $paths = board_wl_logo_paths();

        $this->assertSame(5, substr_count($paths, '<path'), 'alle 5 Pfade (Hintergrund, Innenfeld, 2x Moewe, Wortmarke) muessen vorhanden sein');
        $this->assertStringNotContainsString('<svg', $paths, 'die aeusseren svg-Tags duerfen nicht mitkommen (wird selbst in eine <g> eingebettet)');
        $this->assertStringNotContainsString('<?xml', $paths);
        $this->assertStringContainsString('WIENER LINIEN', $paths, 'die Wortmarke (letzter Pfad) muss enthalten sein -- ohne sie bleibt nur ein schwarzes Rechteck (siehe Spec §9)');
    }

    public function test_battery_fill_width_scales_with_percent(): void
    {
        $this->assertSame(48, board_battery_fill_width(100));
        $this->assertGreaterThan(0, board_battery_fill_width(1), 'auch bei 1% muss ein sichtbarer Rest bleiben');
        $this->assertSame(37, board_battery_fill_width(78));
        $this->assertSame(2, board_battery_fill_width(0), 'Minimum 2px, auch bei 0%, damit der Balken nie unsichtbar verschwindet');
    }

    public function test_battery_percent_is_clamped(): void
    {
        $this->assertSame(48, board_battery_fill_width(150));
        $this->assertSame(2, board_battery_fill_width(-10));
    }

    public function test_chrome_contains_all_structural_elements(): void
    {
        $svg = board_render_chrome_svg(
            new DateTimeImmutable('2026-08-16 19:13:00'),
            new DateTimeImmutable('2026-08-16 19:14:00'),
            78,
            3
        );

        $this->assertStringContainsString('y1="90" x2="1872" y2="90"', $svg, 'Kopfzeilen-Trennlinie');
        $this->assertStringContainsString('x1="1113" y1="90" x2="1113" y2="1310"', $svg, 'vertikale Spaltenlinie');
        $this->assertStringContainsString('y1="1310" x2="1872" y2="1310"', $svg, 'Fusszeilen-Trennlinie');
        $this->assertStringContainsString('translate(24,12) scale(0.5025)', $svg, 'Logo-Transform');
        $this->assertStringContainsString('>Stand 19:13<', $svg);
        $this->assertStringContainsString('>19:14<', $svg, 'Server-Renderzeit in der Fusszeile');
        $this->assertStringContainsString('>78 %<', $svg);
    }

    public function test_chrome_renders_exactly_n_filled_wifi_bars(): void
    {
        $oneBar = board_render_chrome_svg(new DateTimeImmutable(), new DateTimeImmutable(), 50, 1);
        $threeBars = board_render_chrome_svg(new DateTimeImmutable(), new DateTimeImmutable(), 50, 3);

        $this->assertSame(1, $this->countFilledWifiBars($oneBar));
        $this->assertSame(3, $this->countFilledWifiBars($threeBars));
    }

    private function countFilledWifiBars(string $svg): int
    {
        // Isoliert den WLAN-Balken-Block (translate(1830,1352)) und zaehlt
        // darin die gefuellten (nicht nur umrandeten) rects.
        preg_match('/translate\(1830,1352\)">(.*?)<\/g>/s', $svg, $m);
        $this->assertNotEmpty($m, 'WLAN-Balken-Gruppe nicht gefunden');
        return substr_count($m[1], 'fill="black"');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateChromeTest.php`
Expected: FAIL („Call to undefined function board_wl_logo_paths()")

- [ ] **Step 3: `board_wl_logo_paths()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
```

- [ ] **Step 4: Test laufen lassen — Logo- und Akku-Tests grün, `board_render_chrome_svg` noch fehlend**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateChromeTest.php`
Expected: `test_logo_paths_...`, `test_battery_fill_width_...`, `test_battery_percent_is_clamped` PASS; die beiden `test_chrome_...`-Tests FAIL („Call to undefined function board_render_chrome_svg()")

- [ ] **Step 5: `board_render_chrome_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
```

- [ ] **Step 6: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateChromeTest.php`
Expected: PASS (6 Tests)

- [ ] **Step 7: Manuelle Sichtprüfung über die echte Pipeline**

```bash
php -r '
require "inc/board_render.php";
require "inc/board_template.php";
$chrome = board_render_chrome_svg(new DateTimeImmutable("19:13"), new DateTimeImmutable("19:14"), 78, 2);
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1872\" height=\"1404\" viewBox=\"0 0 1872 1404\">"
     . "<rect width=\"1872\" height=\"1404\" fill=\"white\"/>" . $chrome . "</svg>";
file_put_contents("/tmp/chrome_check.png", svg_to_png($svg));
'
```

Öffne `/tmp/chrome_check.png`: Logo mit Wortmarke lesbar, „Stand 19:13" oben
rechts, 2 von 3 WLAN-Balken gefüllt (der dritte nur Umriss), Akku-Balken bei
78 % sichtbar gefüllt (nicht ganz voll).

- [ ] **Step 8: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateChromeTest.php
git commit -m "feat(board): Kopf- und Fusszeile rendern (Logo, Stand-Zeit, Statuszeile)"
```

---

### Task 4: Wetterkarte inkl. Zeilenumbruch (`board_render_weather_svg()`)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateWeatherTest.php`

**Interfaces:**
- Consumes: `weather_select_display()`-Rückgabeform aus `inc/weather.php`
  (`array{available: bool, icon_category?: string, temp_min?: int,
  temp_max?: int, text?: ?string, text_error?: ?string}`).
- Produces:
  `board_wrap_text(string $text, int $maxCharsPerLine): array` — Liste von
  Zeilen, Wortumbruch (kein Silbentrennen), mb-safe (Umlaute zählen als
  1 Zeichen).
  `const BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE = 37` — hergeleitet aus der
  verfügbaren Spaltenbreite (706px, `x=1150` bis `x=1856`) geteilt durch die
  gemessene mittlere Zeichenbreite bei 39px Atkinson Hyperlegible
  (17,37px/Zeichen, gemessen an einem realen deutschen Wettersatz durch die
  echte `rsvg-convert`-Pipeline) mit 8 % Sicherheitsabstand:
  `floor(706 / 17.37 * 0.92) = 37`. Bei einer künftigen Schriftgrößen-
  Änderung muss dieser Wert neu gemessen werden (Messscript s. Step 3).
  `board_render_weather_svg(array $weather): string`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateWeatherTest.php
//
// Wetterkarte aus Spec §9: Icon-Auswahl, Temperaturformat, manueller
// Zeilenumbruch (SVG kann <text> nicht selbst umbrechen).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateWeatherTest extends TestCase
{
    // --- board_wrap_text -----------------------------------------------------

    public function test_wrap_text_breaks_at_word_boundaries_within_limit(): void
    {
        $lines = board_wrap_text('Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.', 37);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(37, mb_strlen($line, 'UTF-8'), "Zeile \"$line\" ueberschreitet das Limit");
        }
        $this->assertSame(
            'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            implode(' ', $lines),
            'kein Wort darf verloren gehen oder sich verdoppeln'
        );
    }

    public function test_wrap_text_single_short_line_stays_one_line(): void
    {
        $this->assertSame(['Kurzer Text.'], board_wrap_text('Kurzer Text.', 37));
    }

    public function test_wrap_text_word_longer_than_limit_stays_on_its_own_line(): void
    {
        // Kein Silbentrennen -- ein zu langes Wort ragt lieber ueber das
        // Limit hinaus, als dass die Funktion es zerhackt.
        $longWord = str_repeat('a', 50);
        $lines = board_wrap_text($longWord, 37);
        $this->assertSame([$longWord], $lines);
    }

    // --- board_render_weather_svg ---------------------------------------------

    private function weatherFixture(array $overrides = []): array
    {
        return array_merge([
            'available' => true,
            'icon_category' => 'klar',
            'temp_min' => 18,
            'temp_max' => 35,
            'text' => 'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            'text_error' => null,
        ], $overrides);
    }

    public function test_weather_svg_uses_correct_icon_and_temperature(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringContainsString('#icon_klar', $svg);
        $this->assertStringContainsString('18° – 35°C', $svg);
        $this->assertStringContainsString('>Heute<', $svg);
    }

    public function test_weather_svg_wraps_long_text_into_multiple_text_elements(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture());

        // Bei 37 Zeichen/Zeile braucht der Fixture-Text mehr als eine Zeile.
        $this->assertGreaterThan(1, substr_count($svg, 'font-size="39"'));
    }

    public function test_weather_svg_shows_stale_error_instead_of_text_but_keeps_icon_and_temp(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture([
            'text' => null,
            'text_error' => 'Wetterbericht veraltet seit 14:00',
        ]));

        $this->assertStringContainsString('#icon_klar', $svg, 'Icon bleibt bei veraltetem Text unveraendert (Spec §8)');
        $this->assertStringContainsString('18° – 35°C', $svg, 'Temperatur bleibt bei veraltetem Text unveraendert (Spec §8)');
        $this->assertStringContainsString('Wetterbericht veraltet seit 14:00', $svg);
        $this->assertStringNotContainsString('Von früh bis spät', $svg);
    }

    public function test_weather_svg_shows_fallback_when_never_fetched(): void
    {
        $svg = board_render_weather_svg(['available' => false]);

        $this->assertStringContainsString('#icon_unbekannt', $svg);
        $this->assertStringNotContainsString('°C', $svg, 'ohne Daten keine erfundene Temperatur');
        $this->assertStringContainsString('Wetterdaten werden geladen', $svg);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateWeatherTest.php`
Expected: FAIL („Call to undefined function board_wrap_text()")

- [ ] **Step 3: Messscript für `BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE` (Dokumentation, kein Testcode)**

Nur zur Nachvollziehbarkeit — nicht Teil der Testsuite, bei Bedarf manuell
erneut ausführen (z. B. nach einer Schriftgrößen-Änderung):

```bash
php -r '
require "inc/board_render.php";
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1600\" height=\"60\" viewBox=\"0 0 1600 60\">"
     . "<text x=\"0\" y=\"45\" font-family=\"Atkinson Hyperlegible\" font-size=\"39\">"
     . "Von früh bis spät scheint die Sonne, damit klettert Temperatur mäßig</text></svg>";
file_put_contents("/tmp/w.png", svg_to_png($svg));
$im = imagecreatefrompng("/tmp/w.png");
$maxX = 0;
for ($x = imagesx($im) - 1; $x >= 0; $x--) {
    for ($y = 0; $y < imagesy($im); $y++) {
        $rgb = imagecolorat($im, $x, $y);
        $lum = 0.299*(($rgb>>16)&0xFF) + 0.587*(($rgb>>8)&0xFF) + 0.114*($rgb&0xFF);
        if ($lum < 128) { $maxX = $x; break 2; }
    }
}
$len = mb_strlen("Von früh bis spät scheint die Sonne, damit klettert Temperatur mäßig", "UTF-8");
echo "avg px/char: " . round($maxX / $len, 2) . "\n";
'
```

Erwartete Ausgabe: `avg px/char: 17.37` (bei geänderter Ausgabe
`BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE` in Step 4 neu berechnen:
`floor(706 / <neuer Wert> * 0.92)`).

- [ ] **Step 4: `board_wrap_text()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
```

- [ ] **Step 5: Test laufen lassen — `board_wrap_text`-Tests grün**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateWeatherTest.php --filter test_wrap_text`
Expected: PASS (3 Tests)

- [ ] **Step 6: `board_render_weather_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
```

- [ ] **Step 7: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateWeatherTest.php`
Expected: PASS (8 Tests)

- [ ] **Step 8: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateWeatherTest.php
git commit -m "feat(board): Wetterkarte rendern (Icon-Auswahl, manueller Zeilenumbruch)"
```

---

### Task 5: Abfahrtenliste — Cursor-Layout (`board_layout_departures()`)

Reine Positionsberechnung, getrennt vom eigentlichen SVG-String-Rendering
(Task 6) — damit die Zahlenwerte unabhängig von der Textausgabe exakt gegen
die Spec-Formeln testbar sind. Alle Werte in diesem Task sind gegen das in
dieser Session zuletzt bestätigte Mehrstationen-Mockup (Favorit „Nach
Hause": Bhf. Meidling S U, Siebenhirten, Vösendorf-SCS) exakt
nachgerechnet — die Fixture-Werte unten sind keine Schätzung, sondern
1:1 die im Chat abgenommenen Pixelwerte.

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateLayoutTest.php`

**Interfaces:**
- Consumes: `list<array{id: int, title: string, stations: list<array{diva: string, name: string, lines: list<array{line: string, platform: string, towards: string, type: string, realtime: bool, alert: bool, departures: list<array{in: int, delayed?: true}>}>}>}>`
  — exakt die Rückgabeform von `board_favorite()` (`inc/board.php`), als
  Liste (ein Board kann mehrere Favoriten gleichzeitig zeigen, `fav=<id>,<id>`).
- Produces: `board_layout_departures(array $favorites): array` — flache Liste
  von Layout-Items:
  - Kopf: `['type' => 'header', 'y' => int, 'text' => string]`
  - Zeile: `['type' => 'row', 'r' => int, 'badge_type' => string, 'label' => string, 'platform' => string, 'destination' => string, 'live_in' => ?int, 'secondary_in' => ?int, 'style' => 'normal'|'italic'|'delayed', 'divider_y' => int]`
  - `live_in`/`secondary_in` sind `null`, wenn die Linie keine (bzw. keine
    zweite) Abfahrt hat — Task 6 rendert das als „–".
  - Task 6 konsumiert diese Liste 1:1, ohne erneut zu rechnen.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateLayoutTest.php
//
// Cursor-Layout aus Spec §9: 58px vor / 29px nach jedem Stationskopf
// (bezogen auf das jeweils hoechste sichtbare Element), 96px Zeilenraster,
// Cursor nach einem Block = Badge-Unterkante der letzten Zeile. Fixture-
// Werte sind die im Chat am echten Rendering abgenommenen Pixelwerte des
// Favoriten "Nach Hause" (Bhf. Meidling S U / Siebenhirten / Voesendorf-SCS).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateLayoutTest extends TestCase
{
    private function line(string $name, string $platform, string $towards, string $type, bool $realtime, array $departures): array
    {
        return ['line' => $name, 'platform' => $platform, 'towards' => $towards, 'type' => $type, 'realtime' => $realtime, 'alert' => false, 'departures' => $departures];
    }

    private function favorite(int $id, string $title, array $stations): array
    {
        return ['id' => $id, 'title' => $title, 'stations' => $stations];
    }

    public function test_single_station_first_row_and_cadence(): void
    {
        $favorites = [$this->favorite(225, 'Westbahnhof', [
            ['diva' => '60201468', 'name' => 'Westbahnhof S U', 'lines' => [
                $this->line('18', '1', 'Schlachthausgasse U', 'tram', true, [['in' => 7], ['in' => 22]]),
                $this->line('6', '1', 'Geiereckstraße', 'tram', true, [['in' => 1], ['in' => 14]]),
            ]],
        ])];

        $items = board_layout_departures($favorites);

        $this->assertSame(['type' => 'header', 'y' => 196, 'text' => 'WESTBAHNHOF S U'], $items[0]);
        $this->assertSame(259, $items[1]['r'], 'erste Zeile: Cursor 90 -> Kopf-Baseline 196 -> R = 196+29+34');
        $this->assertSame(355, $items[2]['r'], '96px Zeilenraster');
        $this->assertSame(307, $items[1]['divider_y']);
    }

    public function test_multi_station_favorite_matches_approved_mockup(): void
    {
        // Exakte Fixture des Favoriten "Nach Hause" (echte Live-Daten,
        // 2026-08-15 in diesem Chat abgenommen).
        $favorites = [$this->favorite(215, 'Nach Hause', [
            ['diva' => '60201015', 'name' => 'Bhf. Meidling S U', 'lines' => [
                $this->line('59A', '2', 'Oper, Karlsplatz U', 'bus', true, [['in' => 7], ['in' => 22]]),
                $this->line('62', '2', 'Quartier Belvedere S', 'tram', true, [['in' => 9], ['in' => 24]]),
            ]],
            ['diva' => '60201235', 'name' => 'Siebenhirten', 'lines' => [
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 1], ['in' => 9]]),
            ]],
            ['diva' => '60205132', 'name' => 'Vösendorf-SCS', 'lines' => [
                $this->line('WLB', '1', 'Inzersdorf', 'tram', true, [['in' => 2], ['in' => 13]]),
                $this->line('WLB', '2', 'Baden Josefspl.', 'tram', true, [['in' => 0], ['in' => 14]]),
            ]],
        ])];

        $items = board_layout_departures($favorites);
        $headers = array_values(array_filter($items, fn ($i) => $i['type'] === 'header'));
        $rows = array_values(array_filter($items, fn ($i) => $i['type'] === 'row'));

        $this->assertSame([196, 495, 698], array_column($headers, 'y'));
        $this->assertSame([259, 355, 558, 761, 857], array_column($rows, 'r'));
        $this->assertSame([307, 403, 606, 809, 905], array_column($rows, 'divider_y'));
    }

    public function test_missing_departures_become_null_not_missing_key(): void
    {
        $favorites = [$this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Teststation', 'lines' => [
                $this->line('1', '1', 'Nirgendwo', 'bus', true, []),
            ]],
        ])];

        $items = board_layout_departures($favorites);
        $row = $items[1];

        $this->assertNull($row['live_in']);
        $this->assertNull($row['secondary_in']);
    }

    public function test_delayed_wins_over_non_realtime_style(): void
    {
        $favorites = [$this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Teststation', 'lines' => [
                $this->line('1', '1', 'Nirgendwo', 'bus', false, [['in' => 7, 'delayed' => true]]),
            ]],
        ])];

        $items = board_layout_departures($favorites);
        $this->assertSame('delayed', $items[1]['style']);
    }

    public function test_station_without_lines_is_skipped_entirely(): void
    {
        // board_favorite() liefert fuer eine gefilterte Station, die aktuell
        // keine passende Linie hat, ['lines' => []] statt die Station
        // wegzulassen (inc/board.php board_filter_station()-Doku). Ein Kopf
        // ohne jede Zeile darunter waere eine leere, verwirrende Karte.
        $favorites = [$this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Leer', 'lines' => []],
            ['diva' => '2', 'name' => 'Voll', 'lines' => [
                $this->line('1', '1', 'Ziel', 'bus', true, [['in' => 1]]),
            ]],
        ])];

        $items = board_layout_departures($favorites);
        $this->assertCount(2, $items, 'nur der Kopf + eine Zeile von "Voll", "Leer" faellt komplett weg');
        $this->assertSame('VOLL', $items[0]['text']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateLayoutTest.php`
Expected: FAIL („Call to undefined function board_layout_departures()")

- [ ] **Step 3: `board_layout_departures()` implementieren**

In `inc/board_template.php` ergänzen:

```php
/**
 * Cursor-Layout der Abfahrtenliste aus Spec §9: 58px Abstand vor jedem
 * Stationskopf (Cursor -> Cap-Top, Cap-Top = Baseline - 48px
 * Umlaut-Worst-Case), 29px danach (Kopf-Baseline -> Badge-Oberkante der
 * ersten Zeile), 96px Zeilenraster, Cursor nach einem Block =
 * Badge-Unterkante der letzten Zeile (R+34). Reine Zahlen -- kein SVG-String
 * hier, das macht board_render_departures_svg() (Task 6) aus dieser Liste.
 *
 * Stationen mit leerer $station['lines']-Liste (gefiltert, aktuell keine
 * passende Linie) werden komplett uebersprungen -- ein Kopf ohne jede Zeile
 * waere eine leere, verwirrende Karte (anders als board_filter_station()
 * selbst, das die leere Station bewusst NICHT aus der Antwort entfernt,
 * s. inc/board.php).
 *
 * @param list<array{id: int, title: string, stations: list<array{diva: string, name: string, lines: list<array>}>}> $favorites
 * @return list<array>
 */
function board_layout_departures(array $favorites): array
{
    $items = [];
    $cursor = 90; // Kopfzeilen-Trennlinie

    foreach ($favorites as $favorite) {
        foreach ($favorite['stations'] as $station) {
            if ($station['lines'] === []) {
                continue;
            }

            $capTop = $cursor + 58;
            $headerBaseline = $capTop + 48;
            $items[] = [
                'type' => 'header',
                'y' => $headerBaseline,
                'text' => mb_strtoupper($station['name'], 'UTF-8'),
            ];

            $r = $headerBaseline + 29 + 34;
            foreach ($station['lines'] as $line) {
                $departures = $line['departures'];
                $delayed = ($departures[0]['delayed'] ?? false) === true;

                $items[] = [
                    'type' => 'row',
                    'r' => $r,
                    'badge_type' => $line['type'],
                    'label' => $line['line'],
                    'platform' => $line['platform'],
                    'destination' => $line['towards'],
                    'live_in' => $departures[0]['in'] ?? null,
                    'secondary_in' => $departures[1]['in'] ?? null,
                    'style' => $delayed ? 'delayed' : ($line['realtime'] ? 'normal' : 'italic'),
                    'divider_y' => $r + 48,
                ];

                $r += 96;
            }

            $cursor = ($r - 96) + 34;
        }
    }

    return $items;
}
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateLayoutTest.php`
Expected: PASS (6 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateLayoutTest.php
git commit -m "feat(board): Cursor-Layout der Abfahrtenliste (board_layout_departures)"
```

---

### Task 6: Abfahrtenliste — SVG-Rendering (`board_render_departures_svg()`)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateDeparturesTest.php`

**Interfaces:**
- Consumes: die Liste aus `board_layout_departures()` (Task 5), 1:1, ohne
  eigene Positionsberechnung.
- Produces: `board_render_departures_svg(array $layoutItems): string`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateDeparturesTest.php
//
// SVG-Rendering der Abfahrtenliste aus den Layout-Items von
// board_layout_departures() (Task 5). Deckt die drei Zeilenstile (normal,
// kursiv/"nur Fahrplan", invertiert/"gestoert") und Randfaelle
// (fehlende Abfahrt, "faehrt jetzt") ab.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDeparturesTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'type' => 'row', 'r' => 259, 'badge_type' => 'tram', 'label' => '18',
            'platform' => '1', 'destination' => 'Schlachthausgasse U',
            'live_in' => 7, 'secondary_in' => 22, 'style' => 'normal', 'divider_y' => 307,
        ], $overrides);
    }

    public function test_header_renders_at_correct_position(): void
    {
        $svg = board_render_departures_svg([['type' => 'header', 'y' => 196, 'text' => 'WESTBAHNHOF S U']]);

        $this->assertStringContainsString('x="16" y="196" font-weight="bold" font-size="55"', $svg);
        $this->assertStringContainsString('>WESTBAHNHOF S U<', $svg);
    }

    public function test_normal_row_renders_badge_and_bold_black_numbers(): void
    {
        $svg = board_render_departures_svg([$this->row()]);

        $this->assertStringContainsString('<use href="#badgeTram" transform="translate(54,259)"/>', $svg);
        $this->assertStringContainsString('x="54" y="268" font-weight="bold" font-size="26" fill="white" text-anchor="middle">18<', $svg);
        $this->assertStringContainsString('x="110" y="267" font-weight="bold" font-size="22" fill="black">1<', $svg);
        $this->assertStringContainsString('x="145" y="278" font-size="55" fill="black">Schlachthausgasse U<', $svg);
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="black" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x="1015" y="266" font-size="20" fill="black">·<', $svg);
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="black" text-anchor="end">22<', $svg);
        $this->assertStringContainsString('x1="16" y1="307" x2="1083" y2="307"', $svg);
        $this->assertStringNotContainsString('font-style="italic"', $svg);
        $this->assertStringNotContainsString('rect x="950"', $svg);
    }

    public function test_three_char_label_uses_smaller_font_and_correct_badge(): void
    {
        $svg = board_render_departures_svg([$this->row(['badge_type' => 'metro', 'label' => 'WLB'])]);

        $this->assertStringContainsString('<use href="#badgeMetro"', $svg);
        $this->assertStringContainsString('font-size="24" fill="white" text-anchor="middle">WLB<', $svg);
    }

    public function test_live_zero_renders_as_star(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => 0])]);
        $this->assertStringContainsString('text-anchor="end">✱<', $svg);
    }

    public function test_missing_departure_renders_dash_and_omits_dot_and_secondary(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => null, 'secondary_in' => null])]);

        $this->assertStringContainsString('text-anchor="end">–<', $svg);
        $this->assertStringNotContainsString('>·<', $svg);
        $this->assertStringNotContainsString('x="1083"', $svg);
    }

    public function test_italic_style_applies_to_live_and_secondary_without_bold(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'italic'])]);

        $this->assertStringContainsString('x="1000" y="275" font-style="italic" font-size="46" fill="black" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x="1083" y="270" font-style="italic" font-size="32" fill="black" text-anchor="end">22<', $svg);
        $this->assertStringNotContainsString('font-weight="bold" font-size="46"', $svg);
    }

    public function test_delayed_style_inverts_only_the_live_number(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'delayed'])]);

        $this->assertStringContainsString('<rect x="950" y="239" width="60" height="42" fill="black"/>', $svg);
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="white" text-anchor="end">7<', $svg);
        // Folgeabfahrt bleibt normal schwarz, NICHT invertiert (Spec: "deckt
        // ausschliesslich die Live-Abfahrt ab").
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="black" text-anchor="end">22<', $svg);
    }

    public function test_unknown_badge_type_falls_back_to_train_shape(): void
    {
        $svg = board_render_departures_svg([$this->row(['badge_type' => 'other'])]);
        $this->assertStringContainsString('<use href="#badgeTrain"', $svg);
    }

    public function test_special_characters_in_destination_are_escaped(): void
    {
        $svg = board_render_departures_svg([$this->row(['destination' => 'A & B'])]);
        $this->assertStringContainsString('A &amp; B', $svg);
        $this->assertStringNotContainsString('A & B<', $svg);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDeparturesTest.php`
Expected: FAIL („Call to undefined function board_render_departures_svg()")

- [ ] **Step 3: `board_render_departures_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
/**
 * SVG-Rendering der Abfahrtenliste aus den Layout-Items von
 * board_layout_departures() (Task 5) -- reine String-Erzeugung, keine
 * Positionsberechnung mehr hier.
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
    $isItalic = $item['style'] === 'italic';
    $isDelayed = $item['style'] === 'delayed';

    $liveText = $item['live_in'] === null ? '–' : ($item['live_in'] === 0 ? '✱' : (string) $item['live_in']);

    $liveStyleAttrs = $isDelayed
        ? 'font-weight="bold" font-size="46" fill="white"'
        : ($isItalic ? 'font-style="italic" font-size="46" fill="black"' : 'font-weight="bold" font-size="46" fill="black"');

    $out = sprintf('<use href="#%s" transform="translate(54,%d)"/>', $badgeShape, $r);
    $out .= sprintf(
        '<text x="54" y="%d" font-weight="bold" font-size="%d" fill="white" text-anchor="middle">%s</text>',
        $r + 9, $labelSize, htmlspecialchars($item['label'], ENT_XML1)
    );
    $out .= sprintf(
        '<text x="110" y="%d" font-weight="bold" font-size="22" fill="black">%s</text>',
        $r + 8, htmlspecialchars($item['platform'], ENT_XML1)
    );
    $out .= sprintf(
        '<text x="145" y="%d" font-size="55" fill="black">%s</text>',
        $r + 19, htmlspecialchars($item['destination'], ENT_XML1)
    );

    if ($isDelayed) {
        $out .= sprintf('<rect x="950" y="%d" width="60" height="42" fill="black"/>', $r - 20);
    }
    $out .= sprintf('<text x="1000" y="%d" %s text-anchor="end">%s</text>', $r + 16, $liveStyleAttrs, $liveText);

    if ($item['secondary_in'] !== null) {
        $secondaryText = $item['secondary_in'] === 0 ? '✱' : (string) $item['secondary_in'];
        $secondaryStyleAttrs = $isItalic ? 'font-style="italic" font-size="32" fill="black"' : 'font-size="32" fill="black"';

        $out .= sprintf('<text x="1015" y="%d" font-size="20" fill="black">·</text>', $r + 7);
        $out .= sprintf('<text x="1083" y="%d" %s text-anchor="end">%s</text>', $r + 11, $secondaryStyleAttrs, $secondaryText);
    }

    $out .= sprintf('<line x1="16" y1="%d" x2="1083" y2="%d" stroke="black" stroke-width="1"/>', $item['divider_y'], $item['divider_y']);

    return $out;
}
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDeparturesTest.php`
Expected: PASS (9 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateDeparturesTest.php
git commit -m "feat(board): Abfahrtenliste rendern (normal/kursiv/gestoert, aus Layout-Items)"
```

---

### Task 7: Gesamt-Assembly (`board_render_svg()`) + Integrationstest über die echte Pipeline

Letzter Task: setzt `<defs>` (Task 2), Kopf-/Fußzeile (Task 3), Wetterkarte
(Task 4) und Abfahrtenliste (Task 5+6) zu einem vollständigen SVG-Dokument
zusammen. Der Integrationstest verifiziert **nicht nur**, dass gültiges SVG
entsteht, sondern rendert über die echte `rsvg-convert`/GD-Pipeline und
misst reale Pixelwerte nach — exakt die Methode, mit der jedes
Layout-Detail in dieser Session am gerenderten Bild überprüft wurde (siehe
Spec §9 Einleitung), jetzt als automatisierte Regression statt manueller
Prüfung.

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Integration/BoardTemplateRenderTest.php`

**Interfaces:**
- Produces: `board_render_svg(array $favorites, array $weather, DateTimeImmutable $dataStand, DateTimeImmutable $renderedAt, int $batteryPercent, int $wifiBars): string`
  — das vollständige, gültige SVG-Dokument (`<svg>...</svg>`), 1872×1404,
  bereit für `svg_to_png()` + `png_to_1bpp_packed()` (`inc/board_render.php`).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Integration/BoardTemplateRenderTest.php
//
// End-to-End: board_render_svg() -> svg_to_png() -> png_to_1bpp_packed(),
// mit Pixel-Nachmessung wie in der manuellen Mockup-Session (Spec §9).
// Fixture ist der Favorit "Nach Hause" (echte Live-Daten, 2026-08-15 im
// Chat abgenommen) -- dieselbe wie in BoardTemplateLayoutTest.

namespace WLMonitor\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateRenderTest extends TestCase
{
    private function line(string $name, string $platform, string $towards, string $type, bool $realtime, array $departures): array
    {
        return ['line' => $name, 'platform' => $platform, 'towards' => $towards, 'type' => $type, 'realtime' => $realtime, 'alert' => false, 'departures' => $departures];
    }

    private function favoriteFixture(): array
    {
        return [['id' => 215, 'title' => 'Nach Hause', 'stations' => [
            ['diva' => '60201015', 'name' => 'Bhf. Meidling S U', 'lines' => [
                $this->line('59A', '2', 'Oper, Karlsplatz U', 'bus', true, [['in' => 7], ['in' => 22]]),
                $this->line('62', '2', 'Quartier Belvedere S', 'tram', true, [['in' => 9], ['in' => 24]]),
            ]],
            ['diva' => '60201235', 'name' => 'Siebenhirten', 'lines' => [
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 1], ['in' => 9]]),
            ]],
            ['diva' => '60205132', 'name' => 'Vösendorf-SCS', 'lines' => [
                $this->line('WLB', '1', 'Inzersdorf', 'tram', true, [['in' => 2], ['in' => 13]]),
                $this->line('WLB', '2', 'Baden Josefspl.', 'tram', true, [['in' => 0], ['in' => 14]]),
            ]],
        ]]];
    }

    private function weatherFixture(): array
    {
        return [
            'available' => true, 'icon_category' => 'klar',
            'temp_min' => 18, 'temp_max' => 35,
            'text' => 'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            'text_error' => null,
        ];
    }

    /** Erste schwarze Pixelzeile innerhalb [$x0,$x1) x [$y0,$y1), oder null. */
    private function firstInkY($im, int $x0, int $x1, int $y0, int $y1): ?int
    {
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($lum < 128) {
                    return $y;
                }
            }
        }
        return null;
    }

    /** Letzte schwarze Pixelzeile innerhalb [$x0,$x1) x [$y0,$y1), oder null. */
    private function lastInkY($im, int $x0, int $x1, int $y0, int $y1): ?int
    {
        for ($y = $y1 - 1; $y >= $y0; $y--) {
            for ($x = $x0; $x < $x1; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($lum < 128) {
                    return $y;
                }
            }
        }
        return null;
    }

    public function test_full_pipeline_produces_correctly_sized_frame(): void
    {
        $svg = board_render_svg(
            $this->favoriteFixture(), $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $png = svg_to_png($svg);
        $packed = png_to_1bpp_packed($png, 1872, 1404);

        // 1872px Breite = 234 Byte/Zeile (ceil(1872/8)), 1404 Zeilen.
        $this->assertSame(234 * 1404, strlen($packed));
    }

    public function test_gap_between_station_header_and_first_row_matches_spec(): void
    {
        $svg = board_render_svg(
            $this->favoriteFixture(), $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $im = imagecreatefromstring(svg_to_png($svg));

        // Kopf "BHF. MEIDLING S U" (x=16..600, y-Suchbereich um Baseline 196
        // herum) vs. erste Zeile (x=16..1083, direkt danach) -- misst ueber
        // die GESAMTE Zeilenbreite (Badge inklusive), nicht nur den Text,
        // weil das Badge (68px hoch) das Element mit dem hoechsten sichtbaren
        // Punkt ist -- genau der Fehler, der in dieser Session einmal
        // uebersehen wurde (Spec §9, "hoechstes sichtbares Element").
        $headerBottom = $this->lastInkY($im, 16, 600, 100, 200);
        $rowTop = $this->firstInkY($im, 16, 1083, 200, 280);

        $this->assertNotNull($headerBottom);
        $this->assertNotNull($rowTop);
        $this->assertSame(29, $rowTop - $headerBottom, 'Abstand Kopf-Baseline -> hoechstes Element der ersten Zeile muss 29px sein (Spec §9)');
    }

    public function test_wl_logo_wordmark_is_present_not_a_black_box(): void
    {
        $svg = board_render_svg(
            $this->favoriteFixture(), $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );
        $im = imagecreatefromstring(svg_to_png($svg));

        // Regressionsschutz gegen den in dieser Session beobachteten Fehler
        // (fehlender 5. Pfad -> Logo wird zum reinen schwarzen Rechteck):
        // im weissen Innenfeld (ca. x=30..280 y=10..90 bei diesem Massstab)
        // muss es sowohl schwarze ALS AUCH weisse Pixel geben.
        $hasBlack = $this->firstInkY($im, 30, 280, 10, 90) !== null;
        $hasWhite = false;
        for ($y = 10; $y < 90 && !$hasWhite; $y++) {
            for ($x = 30; $x < 280; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                if ((($rgb >> 16) & 0xFF) > 200 && (($rgb >> 8) & 0xFF) > 200 && ($rgb & 0xFF) > 200) {
                    $hasWhite = true;
                    break;
                }
            }
        }

        $this->assertTrue($hasBlack, 'Logo muss schwarze Flaechen haben');
        $this->assertTrue($hasWhite, 'Logo muss weisse Flaechen haben (reines Schwarz = Wortmarke fehlt, s. Spec §9)');
    }

    public function test_only_black_and_white_pixels_no_gray_no_color(): void
    {
        // Spec: "Alles einfarbig Schwarz auf Weiss -- keine Graustufen, keine
        // Farbe." rsvg-convert liefert Antialiasing-Grautoene an Kanten; die
        // sind hier explizit ERLAUBT (der harte Schwellwert aus
        // png_to_1bpp_packed() raeumt sie auf) -- dieser Test prueft nur,
        // dass keine ECHTE Farbe (R != G != B) vorkommt.
        //
        // Ausnahme: das eingebettete WL-Logo (Task 3) fuehrt seine echten
        // Markenfarben (#e3000f, #240c4b) unveraendert aus assets/img/wl-logo.svg
        // mit -- das ist gewollt (das Logo wurde in jedem Mockup dieser Design-
        // Session genau so, farbig-im-Quell-SVG, gerendert und abgenommen) und
        // fuer die Displayausgabe folgenlos: der harte Schwellwert in
        // png_to_1bpp_packed() reduziert jede Farbe anhand ihrer Luminanz auf
        // Schwarz oder Weiss, ganz gleich ob die Quelle schon monochrom war
        // oder nicht. Diese Zeile scannt daher bewusst nur den Bereich
        // AUSSERHALB der Logo-Bounding-Box (x=24..306, y=12..78, aus
        // translate(24,12) scale(0.5025) auf die 561,3x131,6-Quelle,
        // grosszuegig gerundet) -- der Rest des Boards (Abfahrten, Wetter,
        // Statuszeile) muss weiterhin ausschliesslich schwarz/weiss sein.
        $svg = board_render_svg(
            $this->favoriteFixture(), $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );
        $im = imagecreatefromstring(svg_to_png($svg));

        $colorPixels = 0;
        for ($y = 0; $y < imagesy($im); $y += 7) {
            for ($x = 0; $x < imagesx($im); $x += 7) {
                if ($x >= 20 && $x <= 310 && $y >= 10 && $y <= 80) {
                    continue; // Logo-Bounding-Box, s.o.
                }
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                if (max(abs($r - $g), abs($g - $b), abs($r - $b)) > 5) {
                    $colorPixels++;
                }
            }
        }

        $this->assertSame(0, $colorPixels, 'ausserhalb der Logo-Bounding-Box darf kein Pixel sichtbar farbig sein');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Integration/BoardTemplateRenderTest.php`
Expected: FAIL („Call to undefined function board_render_svg()")

- [ ] **Step 3: `board_render_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
/**
 * Setzt das komplette Board-SVG aus Spec §9 zusammen: Grundformen,
 * Kopf-/Fusszeile, Abfahrtenliste, Wetterkarte. Ergebnis ist bereit fuer
 * svg_to_png() + png_to_1bpp_packed() (inc/board_render.php).
 *
 * $favorites ist eine Liste von board_favorite()-Ergebnissen (inc/board.php,
 * ein Board kann mehrere Favoriten gleichzeitig zeigen), $weather die
 * Rueckgabe von weather_select_display() (inc/weather.php). $dataStand und
 * $renderedAt sind bewusst getrennte Zeitwerte (s. Task 3), $batteryPercent
 * und $wifiBars kommen bereits fertig umgerechnet von der aufrufenden
 * Board-Protokoll-Schicht (nicht Teil dieses Plans, s. Global Constraints).
 */
function board_render_svg(
    array $favorites,
    array $weather,
    DateTimeImmutable $dataStand,
    DateTimeImmutable $renderedAt,
    int $batteryPercent,
    int $wifiBars
): string {
    $defs = board_svg_defs();
    $chrome = board_render_chrome_svg($dataStand, $renderedAt, $batteryPercent, $wifiBars);
    $departures = board_render_departures_svg(board_layout_departures($favorites));
    $weatherSvg = board_render_weather_svg($weather);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1872" height="1404" viewBox="0 0 1872 1404">
<defs>
{$defs}
</defs>
<rect width="1872" height="1404" fill="white"/>
{$chrome}
{$departures}
{$weatherSvg}
</svg>
SVG;
}
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Integration/BoardTemplateRenderTest.php`
Expected: PASS (4 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS, keine Regressionen in den bestehenden Weather-/Board-/Render-Tests

- [ ] **Step 6: Manuelle Sichtprüfung — vollständiges Board, echte Favoriten**

```bash
php -r '
require "inc/initialize.php";
require "inc/monitor.php";
require "inc/board.php";
require "inc/weather.php";
require "inc/board_render.php";
require "inc/board_template.php";

$res = $con->query("SELECT id, title, diva, filter_json FROM wl_favorites WHERE id=215");
$row = $res->fetch_assoc();
$fav = ["id"=>$row["id"], "title"=>$row["title"], "diva"=>$row["diva"], "filter"=>json_decode($row["filter_json"], true)];
$monitor = monitor_get($con, $fav["diva"], 5);
$monitor = monitor_inject_missing_stations($con, $monitor, $fav["diva"]);
$favorite = board_favorite($fav, $monitor);

$weatherCache = json_decode(file_get_contents("data/weather_cache.json"), true);
$weather = weather_select_display($weatherCache, new DateTimeImmutable());

$svg = board_render_svg([$favorite], $weather, new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);
$png = svg_to_png($svg);
$packed = png_to_1bpp_packed($png, 1872, 1404);

// Gepackte Bits zurueck zu einem sichtbaren PNG entpacken (weiss=1,schwarz=0).
$im = imagecreatetruecolor(1872, 1404);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
$rowBytes = intdiv(1872 + 7, 8);
for ($y = 0; $y < 1404; $y++) {
    for ($x = 0; $x < 1872; $x++) {
        $byteIdx = $y * $rowBytes + intdiv($x, 8);
        $bit = 7 - ($x % 8);
        $val = (ord($packed[$byteIdx]) >> $bit) & 1;
        imagesetpixel($im, $x, $y, $val ? $white : $black);
    }
}
imagepng($im, "/tmp/board_final_check.png");
echo "geschrieben: /tmp/board_final_check.png\n";
'
```

Öffne `/tmp/board_final_check.png` und vergleiche gegen die in diesem Chat
zuletzt bestätigten Mockups (Favorit „Nach Hause", Kopfzeile 150%, Abstände
zur Spaltenlinie). Weicht irgendetwas sichtbar ab, das die automatisierten
Tests nicht abdecken (z. B. Schriftbild, Zeilenumbruch der echten
Live-Wetterdaten), vor Abschluss klären.

- [ ] **Step 7: Commit**

```bash
git add inc/board_template.php tests/Integration/BoardTemplateRenderTest.php
git commit -m "feat(board): Board-SVG-Template Gesamt-Assembly (board_render_svg)"
```

---

## Self-Review (für die Ausführung)

- **Spec-Abdeckung:** Kopfzeile (Task 3), vertikale/horizontale
  Trennlinien (Task 3), Abfahrtenliste inkl. Cursor-Regel/Zeilenraster/
  Zeilenausrichtung (Task 5+6), Badges (Task 2+6), Typografie-Sonderfälle
  kursiv/gestört/„fährt jetzt" (Task 6), Wetterkarte inkl. Icon-Set und
  Zeilenumbruch (Task 2+4), Statuszeile (Task 3), Font-Einbindung (Task 1)
  — alle Abschnitte von Spec §9 sind auf einen Task gemappt.
- **Typkonsistenz geprüft:** `board_layout_departures()` (Task 5) und
  `board_render_departures_svg()` (Task 6) teilen sich exakt dieselben
  Array-Keys (`r`, `badge_type`, `label`, `platform`, `destination`,
  `live_in`, `secondary_in`, `style`, `divider_y`) — Task 6 erfindet keine
  neuen Feldnamen.
- **Kein Platzhalter:** jeder Task enthält vollständigen, direkt
  einsetzbaren Code (keine „TODO"/„entsprechend anpassen"-Stellen).
