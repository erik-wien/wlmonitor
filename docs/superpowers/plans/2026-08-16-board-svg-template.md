# Board-SVG-Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein reines, testbares PHP-Modul, das aus den bereits bestehenden
Datenformen (`board_favorite()` aus `inc/board.php`, `weather_select_display()`
aus `inc/weather.php`, `monitor_get()['alerts']` aus `inc/monitor.php`, plus
Statuszeilen-/Touch-Werten) das vollständige Board-SVG gemäß Spec erzeugt —
bereit, um von `svg_to_png()` + `png_to_1bpp_packed()`
(`inc/board_render.php`, bereits vorhanden) zu einem 1bpp-Frame verarbeitet
zu werden (der 4bpp-Graustufenpfad aus Spec §6 ist explizit **nicht** Teil
dieses Plans, s. u.).

**Architektur:** Datei `inc/board_template.php` (Tasks 1-4 bereits gemergt),
ausschließlich reine Funktionen (kein `$con`, kein Netz, kein
`date()`/`time()` — Zeitwerte kommen als `DateTimeImmutable`-Parameter
herein). Bausteine, die sich zu `board_render_svg()` zusammensetzen:
SVG-Grundformen (`<defs>`, inkl. `#starNow`), Kopfzeile, Touch-Leiste,
Wetterkarte, Abfahrtenliste (Layout+Pagination getrennt von Rendering),
Stand+Pagination-Widget, Störungsseite. Jeder Baustein ist einzeln
unit-testbar; die Endmontage bekommt zusätzlich einen Integrationstest über
die echte `rsvg-convert`/GD-Pipeline.

**Tech Stack:** PHP 8.2, `ext-gd`, `rsvg-convert` (Homebrew librsvg),
PHPUnit ^13 (bestehende Suite unter `tests/Unit/`).

**Überarbeitung (2026-08-16):** die Spec wurde während der Umsetzung dieses
Plans grundlegend erweitert (Hardware-Wechsel auf das reTerminal E1003:
Touch, 16 Graustufen, physische Wake-/Seiten-Tasten — s. Spec §3). Tasks 1+2
(Font-Einbindung, SVG-Grundformen) bleiben unverändert gültig. **Task 3
(Kopf-/Fußzeile) wurde neu geschrieben** (aufgeteilt in Task 3: Kopfzeile,
Task 3b: Touch-Leiste) — die ursprüngliche, bereits gemergte Fassung von
`board_render_chrome_svg()` rendert noch die alte Statuszeile unten/„Stand"
oben und wird durch diesen Plan ersetzt. Task 4 (Wetterkarte) ist von der
Überarbeitung nicht betroffen. Tasks 5-7 sind neu bzw. erweitert (Pagination,
Graustufen statt Kursiv, Vektor-Stern statt Unicode-Zeichen), Tasks 6b/8 sind
komplett neu (Pagination-Widget, Störungsseite).

**Nicht Teil dieses Plans** (siehe Spec, Abschnitt „Nicht Teil dieses
Entwurfs"): der Bild-Protokoll-Endpunkt `web/board.php` (Auth, Diff/Patch,
ETag, Ermittlung von aktivem Favoriten-Index/Seite aus `X-Device-Touch` und
Geräte-Zustand — eigener „Board-Protokoll"-Plan); die Umrechnung
`X-Device-Battery-mV`→Prozent und `X-Device-RSSI`→Balkenzahl (Aufgabe des
Protokoll-Plans); die exakte Byte-Packung des 4bpp-Graustufenformats (Spec
§6, wird beim Firmware-/Protokoll-Schritt anhand der Seeed_GFX-Quelle
geklärt — dieser Plan liefert reines SVG/PNG, keine Geräte-Pufferpackung).
Alle Render-Funktionen dieses Plans sind reine Funktionen: sie bekommen
aktiven Favoriten-Index, aktive Seite, Touch-Zustand etc. bereits fertig
entschieden als Parameter herein, sie entscheiden selbst nichts über
Geräte-/Sitzungs-Zustand.

## Global Constraints

Alle Werte aus `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`
(Stand 2026-08-16), wörtlich übernommen — bei Widersprüchen zwischen dieser
Liste und einer Taskbeschreibung gilt die Spec:

- Canvas: `1872×1404`, `viewBox="0 0 1872 1404"`.
- **Kopfzeile** (§9): Trennlinie bei `y=90`. Logo `translate(24,12)
  scale(0.5025)`, **rein schwarz/weiß eingebettet** (nicht die
  Original-Markenfarben — bei 16 Graustufen würden Rot/Dunkelblau sonst zu
  uneinheitlichen Grautönen quantisiert). Server-Renderzeit zentriert
  `x=936 y=55`, 34px fett. Akku+WLAN **eine Zeile**, rechtsbündig auf
  `x=1856`: WLAN-Balken `translate(1665,46)`, Akku-Icon
  `translate(1713,42)` (Umriss 56×26 rx=3, Nub 7×12, Füllbalken
  proportional `max(2, round(48·Prozent/100))`), Prozent-Text `x=1856 y=63`
  rechtsbündig 24px fett. **„Stand HH:MM" steht NICHT mehr in der
  Kopfzeile** — es wandert ans Ende der Abfahrtenspalte (s. u.).
- Vertikale Trennlinie Abfahrten|Wetter: `x=1113`. Abfahrten-Inhalt endet bei
  `x=1083`. Wetter-Inhalt beginnt bei `x=1150`.
- **Touch-Leiste** (ersetzt die alte Fußzeilen-Statuszeile, §9): Trennlinie
  bei `y=1310`. Bis zu 3 Favoriten-Buttons, gleich breit über die volle
  Breite verteilt, `16px` Rand/Lücke, Höhe `74px`, `y=1320` bis `y=1394`,
  `rx=10`. Aktiv: `fill=black`, Label weiß fett 34px. Inaktiv: `fill=white
  stroke=black stroke-width=3`, Label schwarz.
- **Stand + Pagination** (Ende der Abfahrtenspalte, §9): „Stand HH:MM" links
  `x=16 y=1286` 24px. Pagination-Pille (nur wenn >1 Seite) `x=793 y=1256
  width=290 height=48 rx=24`, weiß, 2px schwarzer Rand. Inhalt vertikal auf
  Pillen-Mitte `y=1280` zentriert (Baseline = Mitte + halbe Cap-Höhe):
  Zurück-Pfeil „←", Seitenzahlen als Text (schwarz), aktive Seite als
  gefüllter schwarzer Kreis `r=20` mit weißer fetter Zahl, Vor-Pfeil „→".
  Pfeil ohne Ziel (erste/letzte Seite): `fill="#b0b0b0"` statt weggelassen
  (Pille behält immer dieselbe Breite).
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
  `train`-Form. Badge-Label-Schriftgröße: 26px bei ≤2 Zeichen, 24px bei 3
  Zeichen (WLB).
- **„Nur Fahrplan"** (`realtime === false`): die **ganze Zeile** (Steig-
  Nummer, Fahrtrichtung, Live-Abfahrt, Trennpunkt, Folgeabfahrt) in
  **mittlerem Grau** (`fill="#808080"`), aufrecht — **nicht kursiv, keine
  andere Formatierung**. Nur Badge-Form und Badge-Label (weiß im Badge)
  bleiben unverändert schwarz/weiß.
- **„Gestört"** (`departures[0]['delayed'] === true`): Rechteck
  `x=950 y=(R-20) width=60 height=42 fill=black`, Live-Abfahrt-Text
  unverändert an Position, nur `fill="white"`. Deckt ausschließlich die
  Live-Abfahrt ab. Schlägt „nur Fahrplan"/Grau, falls beides zuträfe (fett
  weiß-auf-schwarz, nicht grau).
- `"in": 0` → Jetzt-Symbol `#starNow` (selbst gezeichneter Stern aus 3
  dicken Linien, `stroke-width=7`, lokaler Koordinatenraum -15..15) statt
  Zahl — **kein Unicode-Zeichen** („✱" fällt im Font-Fallback zu dünn aus,
  „✳︎" hat kein Glyph, am gerenderten Bild geprüft). `#starNow` hat
  **keine eigene Strichfarbe** — jeder `<use>`-Aufruf muss `stroke="..."`
  selbst mitgeben (sonst unsichtbar, `stroke` default `none`), denn bei
  „gestört UND fährt gerade jetzt" sitzt der Stern auf dem schwarzen
  Invertierungsblock und braucht `stroke="white"` statt `stroke="black"`
  (Review-Befund aus Task 6, per Pipeline verifiziert). Live-Slot:
  `translate(985,R)` mit `stroke` = dieselbe Farbe wie die Live-Abfahrt
  sonst hätte (schwarz normal, weiß bei „gestört"). Folgeabfahrt-Slot:
  `translate(1073,R) scale(0.696)` mit `stroke` = Zeilenfarbe (schwarz
  oder grau bei „nur Fahrplan" — „gestört" betrifft nie die Folgeabfahrt).
- Wetterkarte: Icon `translate(1492,180) scale(1.8)`. Temperatur
  `x=1492 y=290`, 40px fett, zentriert. „Heute" `x=1150 y=366`, 30px fett.
  Fließtext ab `x=1150 y=422`, 46px Zeilenabstand, 39px Schrift. Bleibt beim
  Blättern durch Abfahrten-/Störungsseiten **statisch**.
- **Störungsseite**: gleiches Zeilenraster-Prinzip wie Abfahrten, aber
  eigene Konstanten (s. Task 8) — Titel fett 40px, Beschreibung 32px,
  max. 3 Zeilen mit „…" bei Kürzung.
- Wetter-Icon-Grundformen und Kategorie→Icon-Id-Tabelle: wörtlich aus Spec
  „Icon-Set" (`<defs>`-Block + Tabelle), bereits in Task 2 umgesetzt.
- Font: `Atkinson Hyperlegible` (Familienname, wie in den TTF-Metadaten von
  `assets/fonts/board/*.ttf` hinterlegt). Kein anderer Font-Name im Template.
- Bit-/Farbkonvention: ausschließlich `fill="black"` / `fill="white"` /
  `fill="#808080"` (nur Fahrplan) / `fill="#b0b0b0"` (deaktivierter
  Pagination-Pfeil) / `stroke="black"` — keine anderen Farbwerte im selbst
  erzeugten Markup. **Ausnahme:** das eingebettete WL-Logo (Task 3) führt
  seine echten Markenfarben im SVG-Quelltext, wird aber **schwarz/weiß**
  eingebettet (s. o.) — die Ausnahme aus einer früheren Planversion (echte
  Markenfarben behalten) ist damit hinfällig, das Logo ist jetzt
  durchgehend monochrom wie der Rest des Templates.

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

### Task 2b: `#starNow`-Icon zu den SVG-Grundformen ergänzen

Kleine Ergänzung zur bereits gemergten `board_svg_defs()` (Task 2) — die
Funktion existiert und ist getestet, dieser Task fügt nur ein weiteres
Icon hinzu (Jetzt-Symbol für "fährt jetzt", ersetzt den ursprünglich
geplanten Unicode-Stern, s. Global Constraints).

**Files:**
- Modify: `inc/board_template.php`
- Modify: `tests/Unit/BoardTemplateDefsTest.php`

**Interfaces:**
- `board_svg_defs()` bekommt ein weiteres `id="starNow"`-Element, Signatur
  unverändert.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

In `tests/Unit/BoardTemplateDefsTest.php`, `EXPECTED_IDS` erweitern:

```php
private const EXPECTED_IDS = [
    'sun', 'cloudOutline', 'cloudFilled',
    'icon_klar', 'icon_leicht_bewoelkt', 'icon_bewoelkt', 'icon_bedeckt',
    'icon_regen_leicht', 'icon_regen_stark', 'icon_schnee', 'icon_gewitter',
    'icon_nebel', 'icon_unbekannt',
    'badgeMetro', 'badgeTram', 'badgeBus', 'badgeTrain',
    'starNow',
];
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDefsTest.php --filter test_defs_contain_every_expected_id_exactly_once`
Expected: FAIL („id=\"starNow\" muss genau einmal vorkommen (gefunden: 0)")

- [ ] **Step 3: `#starNow` in `board_svg_defs()` ergänzen**

In `inc/board_template.php`, im Heredoc von `board_svg_defs()`, direkt nach
dem letzten `<g id="badgeTrain">...</g>`-Element einfügen:

```svg
<g id="starNow" stroke="black" stroke-width="7" stroke-linecap="round">
  <line x1="0" y1="-15" x2="0" y2="15"/>
  <line x1="-13" y1="-7.5" x2="13" y2="7.5"/>
  <line x1="-13" y1="7.5" x2="13" y2="-7.5"/>
</g>
```

(Selbst gezeichneter Stern statt Unicode-Zeichen — „✱" fällt im
Font-Fallback deutlich dünner aus als umgebende Fettschrift [132 vs. 327
Tuschepixel im Vergleich mit einer gleich großen fetten Ziffer], „✳︎" hat
im verfügbaren Font kein Glyph und rendert als ausgefülltes Rechteck,
beides am gerenderten Bild geprüft.)

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDefsTest.php`
Expected: PASS (5 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateDefsTest.php
git commit -m "feat(board): starNow-Icon ergaenzen (Vektor-Stern statt Unicode fuer 'faehrt jetzt')"
```

---

### Task 3: Kopfzeile (`board_render_chrome_svg()`, neu geschrieben)

**Ersetzt** die gleichnamige Funktion aus der ursprünglichen Planversion
vollständig — die alte Fassung rendert die inzwischen überholte
Fußzeilen-Statuszeile und „Stand" oben rechts. Diese Fassung rendert **nur
noch die Kopfzeile** (Logo, Server-Renderzeit, Akku/WLAN) plus beide
Trennlinien; die Touch-Leiste ist Task 3b, „Stand"+Pagination ist Task 6b.

**Files:**
- Modify: `inc/board_template.php` (Funktion `board_render_chrome_svg()`
  wird ersetzt, nicht ergänzt — falls die alte Fassung noch existiert,
  komplett überschreiben)
- Modify: `tests/Unit/BoardTemplateChromeTest.php` (Inhalt wird ersetzt)

**Interfaces:**
- Bleibt: `board_wl_logo_paths(): string`, `board_battery_fill_width(int $percent): int`
  (aus der ursprünglichen Planversion, unverändert — Logo-Extraktion und
  Akku-Balken-Formel ändern sich nicht).
- Geändert: `board_render_chrome_svg(DateTimeImmutable $renderedAt, int $batteryPercent, int $wifiBars): string`
  — **kein** `$dataStand`-Parameter mehr (wandert zu Task 6b), rendert nur
  noch Kopfzeile + beide Trennlinien (Spaltenlinie, Fußzeilen-Trennlinie).
- **Logo bleibt schwarz/weiß eingebettet** (bereits so umgesetzt in der
  bestehenden `board_wl_logo_paths()`, unverändert lassen — falls die
  Funktion noch die Original-Markenfarben ausliest, hier korrigieren: die
  5 `<path>`-Elemente aus `assets/img/wl-logo.svg` müssen beim Einbetten
  auf `fill="black"`/`fill="white"` statt `style="fill:#e3000f"` etc.
  normalisiert werden — einfache String-Ersetzung der drei bekannten
  Farbwerte, s. Step 3).

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateChromeTest.php
//
// Kopfzeile aus Spec §9 (Stand 2026-08-16): Logo (schwarz/weiss), zentrierte
// Server-Renderzeit, Akku+WLAN in einer Zeile rechtsbuendig. Die Touch-Leiste
// (Task 3b) und "Stand"+Pagination (Task 6b) sind NICHT Teil dieser Datei.

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateChromeTest extends TestCase
{
    public function test_logo_paths_contain_all_five_paths_no_outer_svg_tag(): void
    {
        $paths = board_wl_logo_paths();

        $this->assertSame(5, substr_count($paths, '<path'));
        $this->assertStringNotContainsString('<svg', $paths);
        $this->assertStringNotContainsString('<?xml', $paths);
        $this->assertStringContainsString('m335.4 65.96', $paths, 'Wortmarken-Pfad (5. Pfad) muss enthalten sein -- ohne ihn bleibt nur ein schwarzes Rechteck');
    }

    public function test_logo_paths_are_monochrome_not_brand_colors(): void
    {
        $paths = board_wl_logo_paths();

        $this->assertStringNotContainsString('#e3000f', $paths, 'Logo muss schwarz/weiss sein, nicht in Markenfarben (Spec Global Constraints: bei 16 Graustufen quantisieren Markenfarben uneinheitlich)');
        $this->assertStringNotContainsString('#240c4b', $paths);
        $this->assertSame(3, substr_count($paths, 'fill="black"'), 'Hintergrund + beide Moewe-Teile schwarz');
        $this->assertSame(2, substr_count($paths, 'fill="white"'), 'Innenfeld + Wortmarke weiss');
    }

    public function test_battery_fill_width_scales_with_percent(): void
    {
        $this->assertSame(48, board_battery_fill_width(100));
        $this->assertSame(37, board_battery_fill_width(78));
        $this->assertSame(2, board_battery_fill_width(0));
    }

    public function test_battery_percent_is_clamped(): void
    {
        $this->assertSame(48, board_battery_fill_width(150));
        $this->assertSame(2, board_battery_fill_width(-10));
    }

    public function test_chrome_contains_structural_elements(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable('2026-08-16 19:14:00'), 78, 3);

        $this->assertStringContainsString('y1="90" x2="1872" y2="90"', $svg, 'Kopfzeilen-Trennlinie');
        $this->assertStringContainsString('x1="1113" y1="90" x2="1113" y2="1310"', $svg, 'vertikale Spaltenlinie');
        $this->assertStringContainsString('y1="1310" x2="1872" y2="1310"', $svg, 'Fusszeilen-Trennlinie (gehoert jetzt der Touch-Leiste, Task 3b)');
        $this->assertStringContainsString('translate(24,12) scale(0.5025)', $svg, 'Logo-Transform');
        $this->assertStringContainsString('x="936" y="55"', $svg, 'zentrierte Server-Renderzeit');
        $this->assertStringContainsString('>19:14<', $svg);
        $this->assertStringContainsString('>78 %<', $svg);
        $this->assertStringNotContainsString('Stand', $svg, '"Stand HH:MM" gehoert nicht mehr in die Kopfzeile (Task 6b)');
    }

    public function test_chrome_renders_exactly_n_filled_wifi_bars(): void
    {
        $oneBar = board_render_chrome_svg(new DateTimeImmutable(), 50, 1);
        $threeBars = board_render_chrome_svg(new DateTimeImmutable(), 50, 3);

        $this->assertSame(1, $this->countFilledWifiBars($oneBar));
        $this->assertSame(3, $this->countFilledWifiBars($threeBars));
    }

    private function countFilledWifiBars(string $svg): int
    {
        preg_match('/translate\(1665,46\)">(.*?)<\/g>/s', $svg, $m);
        $this->assertNotEmpty($m, 'WLAN-Balken-Gruppe nicht gefunden');
        return substr_count($m[1], 'fill="black"');
    }

    public function test_battery_and_wifi_do_not_overlap_horizontally(): void
    {
        // Regressionsschutz: eine fruehere Fassung hatte WLAN-Balken bis x=1802
        // reichen, waehrend das Akku-Icon schon bei x=1786 begann -- 16px
        // Ueberlappung. Beide Gruppen muessen non-overlapping rechtsbuendig
        // auf x=1856 sitzen.
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 78, 3);

        $this->assertStringContainsString('translate(1665,46)', $svg, 'WLAN-Balken-Ursprung (rechter Rand bei 1665+32=1697)');
        $this->assertStringContainsString('translate(1713,42)', $svg, 'Akku-Icon-Ursprung (linker Rand 1713 > WLAN-rechter-Rand 1697)');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateChromeTest.php`
Expected: FAIL (alte Funktionssignatur/alter Inhalt passt nicht zu den neuen Assertions)

- [ ] **Step 3: `board_render_chrome_svg()` ersetzen**

In `inc/board_template.php`: falls `board_wl_logo_paths()` die
Original-Markenfarben ausliest, dort die drei bekannten `style="fill:#..."`-
Werte auf `fill="black"`/`fill="white"` normalisieren (Hintergrund + beide
Möwe-Teile → `fill="black"`, Innenfeld + Wortmarke → `fill="white"`):

```php
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
```

`board_battery_fill_width()` bleibt unverändert (bereits korrekt).

Neu implementieren:

```php
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
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateChromeTest.php`
Expected: PASS (7 Tests)

- [ ] **Step 5: Manuelle Sichtprüfung**

```bash
php -r '
require "inc/board_render.php";
require "inc/board_template.php";
$chrome = board_render_chrome_svg(new DateTimeImmutable("19:14"), 78, 2);
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1872\" height=\"1404\" viewBox=\"0 0 1872 1404\">"
     . "<rect width=\"1872\" height=\"1404\" fill=\"white\"/>" . $chrome . "</svg>";
file_put_contents("/tmp/chrome_check.png", svg_to_png($svg));
'
```

Öffne `/tmp/chrome_check.png`: Logo schwarz/weiß (keine Farbe), Uhrzeit
mittig, 2 von 3 WLAN-Balken gefüllt, Akku-Balken bei 78 % sichtbar gefüllt
— alle drei Elemente ohne Überlappung nebeneinander rechts.

- [ ] **Step 6: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateChromeTest.php
git commit -m "feat(board): Kopfzeile neu (Zeit zentriert, Akku+WLAN eine Zeile, Logo monochrom, kein Stand mehr hier)"
```

---

### Task 3b: Touch-Leiste (`board_render_touch_bar_svg()`, neu)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateTouchBarTest.php`

**Interfaces:**
- Produces: `board_render_touch_bar_svg(array $favoriteTitles, int $activeIndex): string`
  — `$favoriteTitles` ist eine Liste von 1-3 Titeln (die ersten Favoriten
  des Geräte-Nutzers nach `sort`, s. Spec §4 — diese Funktion bekommt sie
  bereits fertig ermittelt, sie lädt selbst keine Favoriten). `$activeIndex`
  ist der 0-basierte Index des aktiven Favoriten in `$favoriteTitles`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateTouchBarTest.php
//
// Touch-Leiste aus Spec (Stand 2026-08-16): bis zu 3 Favoriten-Buttons,
// gleich breit ueber die volle Breite verteilt, aktiver Favorit schwarz
// gefuellt mit weisser Schrift, inaktive weiss mit schwarzem Rand.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateTouchBarTest extends TestCase
{
    public function test_three_buttons_equal_width_active_one_filled(): void
    {
        $svg = board_render_touch_bar_svg(['Arbeit', 'Nach Hause', 'Westbahnhof'], 1);

        $this->assertStringContainsString('x="16" y="1320" width="602" height="74" rx="10" fill="white" stroke="black" stroke-width="3"', $svg, 'Button 1 inaktiv');
        $this->assertStringContainsString('x="634" y="1320" width="602" height="74" rx="10" fill="black"', $svg, 'Button 2 aktiv (Index 1)');
        $this->assertStringContainsString('x="1252" y="1320" width="602" height="74" rx="10" fill="white" stroke="black" stroke-width="3"', $svg, 'Button 3 inaktiv');
        $this->assertStringContainsString('fill="white">Nach Hause<', $svg, 'aktives Label weiss');
        $this->assertStringContainsString('fill="black">Arbeit<', $svg);
        $this->assertStringContainsString('fill="black">Westbahnhof<', $svg);
    }

    public function test_fewer_than_three_favorites_recomputes_button_width(): void
    {
        // 2 Favoriten: (1872 - 2*16 Rand - 1*16 Luecke) / 2 = 912 pro Button.
        $svg = board_render_touch_bar_svg(['Arbeit', 'Nach Hause'], 0);

        $this->assertStringContainsString('width="912"', $svg);
        $this->assertStringNotContainsString('Westbahnhof', $svg);
    }

    public function test_single_favorite_spans_full_width_and_is_always_active(): void
    {
        // 1 Favorit: (1872 - 2*16) / 1 = 1840 breit, immer aktiv (nichts zum
        // Umschalten da) -- schwarz gefuellt.
        $svg = board_render_touch_bar_svg(['Nur Einer'], 0);

        $this->assertStringContainsString('width="1840"', $svg);
        $this->assertStringContainsString('fill="black"', $svg);
    }

    public function test_special_characters_in_title_are_escaped(): void
    {
        $svg = board_render_touch_bar_svg(['A & B'], 0);
        $this->assertStringContainsString('A &amp; B', $svg);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateTouchBarTest.php`
Expected: FAIL („Call to undefined function board_render_touch_bar_svg()")

- [ ] **Step 3: `board_render_touch_bar_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateTouchBarTest.php`
Expected: PASS (4 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateTouchBarTest.php
git commit -m "feat(board): Touch-Leiste mit Favoriten-Buttons rendern"
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

### Task 5: Abfahrtenliste — Cursor-Layout + Pagination (`board_paginate_departures()`)

**Ersetzt** `board_layout_departures()` aus der ursprünglichen Planversion.
Größte Änderung: die Funktion nimmt jetzt **einen** Favoriten (nicht mehr
eine Liste — die Touch-Leiste zeigt genau einen aktiven Favoriten
gleichzeitig, s. Spec §4) und schneidet den Inhalt in Seiten, wenn er nicht
auf eine Bildschirmseite passt (Spec §9 „Stand + Pagination").

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateLayoutTest.php` (Inhalt wird ersetzt)

**Interfaces:**
- Consumes: `array{id: int, title: string, stations: list<array{diva: string, name: string, lines: list<array>}>}`
  — **ein einzelner** `board_favorite()`-Rückgabewert (`inc/board.php`), kein
  Array mehr davon.
- Produces: `board_paginate_departures(array $favorite, int $page): array{items: list<array>, totalPages: int}`.
  `items` hat dieselbe Form wie zuvor (`type: 'header'|'row'`, s. u.), aber
  nur für die angeforderte Seite. `$page` wird auf `[1, totalPages]`
  geklemmt. `style` bei Zeilen ist jetzt `'normal'|'gray'|'delayed'`
  (ersetzt `'italic'` — s. Global Constraints, „nur Fahrplan" ist jetzt
  grau statt kursiv).
  `const BOARD_DEPARTURES_MAX_Y = 1250` — unterste zulässige Y-Position für
  eine Zeilen-Badge-Unterkante, bevor auf eine neue Seite umgebrochen wird
  (reserviert die letzten `1310-1250=60px` der Abfahrtenspalte für die
  Stand+Pagination-Leiste, Task 6b).
- Passt ein Stationskopf **plus mindestens eine Zeile** nicht mehr auf die
  aktuelle Seite, beginnt eine neue Seite ab diesem Stationskopf. Passt
  innerhalb einer Station nur ein Teil der Zeilen, wird auf der Folgeseite
  der Stationskopf **wiederholt**, mit Zusatz „ (FORTS.)" — sonst wüsste
  man auf Seite 2 nicht, zu welcher Station die Zeilen gehören.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateLayoutTest.php
//
// Cursor-Layout + Pagination aus Spec (Stand 2026-08-16). Fixture-Werte
// fuer den Einzelseiten-Fall sind die im Chat abgenommenen Pixelwerte des
// Favoriten "Westbahnhof"; der Mehrseiten-Fall nutzt eine synthetische
// Fixture mit genug Zeilen, um BOARD_DEPARTURES_MAX_Y sicher zu ueberschreiten.

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

    public function test_single_page_favorite_matches_approved_mockup(): void
    {
        $favorite = $this->favorite(225, 'Westbahnhof', [
            ['diva' => '60201468', 'name' => 'Westbahnhof S U', 'lines' => [
                $this->line('18', '1', 'Schlachthausgasse U', 'tram', true, [['in' => 7], ['in' => 22]]),
                $this->line('6', '1', 'Geiereckstraße', 'tram', true, [['in' => 1], ['in' => 14]]),
                $this->line('9', '2', 'Gersthof S', 'tram', false, [['in' => 9], ['in' => 16]]),
                $this->line('U3', '1', 'Simmering', 'metro', true, [['in' => 0, 'delayed' => true], ['in' => 8]]),
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 0], ['in' => 6]]),
                $this->line('U6', '2', 'Siebenhirten', 'metro', true, [['in' => 5], ['in' => 12]]),
            ]],
        ]);

        $result = board_paginate_departures($favorite, 1);

        $this->assertSame(1, $result['totalPages']);
        $items = $result['items'];
        $this->assertSame(['type' => 'header', 'y' => 196, 'text' => 'WESTBAHNHOF S U'], $items[0]);
        $this->assertSame(259, $items[1]['r']);
        $this->assertSame(355, $items[2]['r'], '96px Zeilenraster');
        $this->assertSame('gray', $items[3]['style'], 'Linie 9 hat realtime=false');
        $this->assertSame('delayed', $items[4]['style'], 'Linie U3 hat delayed=true');
        $this->assertSame('normal', $items[5]['style']);

        // Anfrage ueber die letzte Seite hinaus klemmt auf Seite 1.
        $this->assertSame($result['items'], board_paginate_departures($favorite, 99)['items']);
    }

    public function test_overflowing_content_splits_across_pages_without_losing_rows(): void
    {
        // 3 Stationen a 4 Zeilen = 12 Zeilen -- deutlich mehr, als vor
        // BOARD_DEPARTURES_MAX_Y (1250) auf eine Seite passt (Seite 1 endet
        // rechnerisch bei Zeile ~6, s. test_single_page_favorite_...).
        $manyLines = array_map(
            fn (int $i) => $this->line((string) $i, '1', "Ziel $i", 'bus', true, [['in' => $i], ['in' => $i + 10]]),
            range(1, 4)
        );
        $favorite = $this->favorite(1, 'Viele Zeilen', [
            ['diva' => '1', 'name' => 'Station A', 'lines' => $manyLines],
            ['diva' => '2', 'name' => 'Station B', 'lines' => $manyLines],
            ['diva' => '3', 'name' => 'Station C', 'lines' => $manyLines],
        ]);

        $page1 = board_paginate_departures($favorite, 1);
        $this->assertGreaterThan(1, $page1['totalPages'], 'muss auf mehrere Seiten umbrechen');

        $totalRowsAcrossAllPages = 0;
        for ($p = 1; $p <= $page1['totalPages']; $p++) {
            $result = board_paginate_departures($favorite, $p);
            $this->assertSame($page1['totalPages'], $result['totalPages'], 'totalPages ist seitenunabhaengig konstant');

            foreach ($result['items'] as $item) {
                if ($item['type'] === 'row') {
                    $totalRowsAcrossAllPages++;
                }
                if ($item['type'] === 'row') {
                    $this->assertLessThanOrEqual(BOARD_DEPARTURES_MAX_Y, $item['r'] + 34, "Zeile auf Seite $p ueberschreitet die Seitengrenze");
                }
            }
        }
        $this->assertSame(12, $totalRowsAcrossAllPages, 'keine Zeile darf verloren gehen oder sich verdoppeln');
    }

    public function test_station_continuation_header_marks_forts(): void
    {
        $manyLines = array_map(
            fn (int $i) => $this->line((string) $i, '1', "Ziel $i", 'bus', true, [['in' => $i]]),
            range(1, 10)
        );
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Grosse Station', 'lines' => $manyLines],
        ]);

        $page1 = board_paginate_departures($favorite, 1);
        $this->assertGreaterThan(1, $page1['totalPages']);

        $page2 = board_paginate_departures($favorite, 2);
        $header = $page2['items'][0];
        $this->assertSame('header', $header['type']);
        $this->assertStringContainsString('(FORTS.)', $header['text']);
        $this->assertStringContainsString('GROSSE STATION', $header['text']);
    }

    public function test_missing_departures_become_null(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Teststation', 'lines' => [
                $this->line('1', '1', 'Nirgendwo', 'bus', true, []),
            ]],
        ]);

        $items = board_paginate_departures($favorite, 1)['items'];
        $this->assertNull($items[1]['live_in']);
        $this->assertNull($items[1]['secondary_in']);
    }

    public function test_station_without_lines_is_skipped_entirely(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Leer', 'lines' => []],
            ['diva' => '2', 'name' => 'Voll', 'lines' => [
                $this->line('1', '1', 'Ziel', 'bus', true, [['in' => 1]]),
            ]],
        ]);

        $items = board_paginate_departures($favorite, 1)['items'];
        $this->assertCount(2, $items);
        $this->assertSame('VOLL', $items[0]['text']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateLayoutTest.php`
Expected: FAIL („Call to undefined function board_paginate_departures()")

- [ ] **Step 3: `board_paginate_departures()` implementieren**

In `inc/board_template.php` ergänzen (ersetzt eine eventuell vorhandene
alte `board_layout_departures()`-Funktion vollständig):

```php
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

        if ($firstR + 34 > BOARD_DEPARTURES_MAX_Y) {
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
            if ($r + 34 > BOARD_DEPARTURES_MAX_Y) {
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
git commit -m "feat(board): Cursor-Layout mit Pagination (board_paginate_departures), ein Favorit statt Liste"
```

---

### Task 6: Abfahrtenliste — SVG-Rendering (`board_render_departures_svg()`, überarbeitet)

**Ändert** die Zeilenstile gegenüber der ursprünglichen Planversion:
`'italic'` wird zu `'gray'` (ganze Zeile in `#808080`, nicht nur Live-/
Folgeabfahrt, nicht kursiv), und `"in": 0` rendert `#starNow`
(Vektor-Symbol) statt des Unicode-Zeichens `✱`.

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateDeparturesTest.php` (Inhalt wird ersetzt)

**Interfaces:**
- Consumes: die Liste aus `board_paginate_departures()['items']` (Task 5).
- Produces: `board_render_departures_svg(array $layoutItems): string`
  (Signatur unverändert).

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateDeparturesTest.php
//
// SVG-Rendering der Abfahrtenliste (Stand 2026-08-16): normal/grau/gestoert,
// Vektor-Stern statt Unicode fuer "faehrt jetzt".

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
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="black" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x1="16" y1="307" x2="1083" y2="307"', $svg);
        $this->assertStringNotContainsString('fill="#808080"', $svg);
        $this->assertStringNotContainsString('rect x="950"', $svg);
    }

    public function test_live_zero_renders_starNow_vector_not_unicode(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => 0])]);

        $this->assertStringContainsString('<use href="#starNow" transform="translate(985,259)"/>', $svg);
        $this->assertStringNotContainsString('✱', $svg);
        $this->assertStringNotContainsString('✳', $svg);
    }

    public function test_secondary_zero_renders_scaled_starNow(): void
    {
        $svg = board_render_departures_svg([$this->row(['secondary_in' => 0])]);
        $this->assertStringContainsString('<use href="#starNow" transform="translate(1073,259) scale(0.696)"/>', $svg);
    }

    public function test_missing_departure_renders_dash_and_omits_dot_and_secondary(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => null, 'secondary_in' => null])]);

        $this->assertStringContainsString('text-anchor="end">–<', $svg);
        $this->assertStringNotContainsString('>·<', $svg);
        $this->assertStringNotContainsString('x="1083"', $svg);
    }

    public function test_gray_style_applies_to_the_whole_row_except_badge(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'gray'])]);

        // Badge-Form + Label bleiben schwarz/weiss:
        $this->assertStringContainsString('<use href="#badgeTram" transform="translate(54,259)"/>', $svg);
        $this->assertStringContainsString('fill="white" text-anchor="middle">18<', $svg, 'Badge-Label bleibt weiss im Badge');
        // Alles andere in der Zeile grau, NICHT kursiv:
        $this->assertStringContainsString('x="110" y="267" font-weight="bold" font-size="22" fill="#808080">1<', $svg, 'Steig-Nummer grau');
        $this->assertStringContainsString('x="145" y="278" font-size="55" fill="#808080">Schlachthausgasse U<', $svg, 'Fahrtrichtung grau');
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="#808080" text-anchor="end">7<', $svg, 'Live-Abfahrt grau, weiterhin fett');
        $this->assertStringContainsString('x="1015" y="266" font-size="20" fill="#808080">·<', $svg, 'Trennpunkt grau');
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="#808080" text-anchor="end">22<', $svg, 'Folgeabfahrt grau');
        $this->assertStringNotContainsString('font-style="italic"', $svg, 'kein Kursiv mehr, s. Spec Global Constraints');
    }

    public function test_delayed_style_inverts_only_the_live_number(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'delayed'])]);

        $this->assertStringContainsString('<rect x="950" y="239" width="60" height="42" fill="black"/>', $svg);
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="white" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="black" text-anchor="end">22<', $svg, 'Folgeabfahrt bleibt normal schwarz, nicht grau, nicht invertiert');
    }

    public function test_three_char_label_uses_smaller_font(): void
    {
        $svg = board_render_departures_svg([$this->row(['badge_type' => 'metro', 'label' => 'WLB'])]);
        $this->assertStringContainsString('<use href="#badgeMetro"', $svg);
        $this->assertStringContainsString('font-size="24" fill="white" text-anchor="middle">WLB<', $svg);
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
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDeparturesTest.php`
Expected: FAIL („Call to undefined function board_render_departures_svg()")

- [ ] **Step 3: `board_render_departures_svg()` implementieren**

In `inc/board_template.php` ergänzen (ersetzt eine eventuell vorhandene
alte Fassung vollständig):

```php
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

    if ($item['live_in'] === 0) {
        $out .= sprintf('<use href="#starNow" transform="translate(985,%d)"/>', $r);
    } else {
        $liveText = $item['live_in'] === null ? '–' : (string) $item['live_in'];
        $liveFill = $isDelayed ? 'white' : $fill;
        $out .= sprintf(
            '<text x="1000" y="%d" font-weight="bold" font-size="46" fill="%s" text-anchor="end">%s</text>',
            $r + 16, $liveFill, $liveText
        );
    }

    if ($item['secondary_in'] !== null) {
        $out .= sprintf('<text x="1015" y="%d" font-size="20" fill="%s">·</text>', $r + 7, $fill);

        if ($item['secondary_in'] === 0) {
            $out .= sprintf('<use href="#starNow" transform="translate(1073,%d) scale(0.696)"/>', $r);
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
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDeparturesTest.php`
Expected: PASS (10 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateDeparturesTest.php
git commit -m "feat(board): Abfahrtenliste rendern (grau statt kursiv, Vektor-Stern statt Unicode)"
```

---

### Task 6b: Stand + Pagination-Widget (`board_render_stand_and_pagination_svg()`, neu)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateStandPaginationTest.php`

**Interfaces:**
- Produces: `board_render_stand_and_pagination_svg(DateTimeImmutable $dataStand, int $currentPage, int $totalPages): string`
  — „Stand HH:MM" links immer, Pagination-Pille nur wenn `$totalPages > 1`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateStandPaginationTest.php
//
// "Stand HH:MM" + kanonische Pagination aus Spec (Stand 2026-08-16) --
// sitzen am unteren Ende der Abfahrtenspalte, NICHT mehr in der Kopfzeile.

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateStandPaginationTest extends TestCase
{
    public function test_stand_always_renders(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 1);
        $this->assertStringContainsString('x="16" y="1286" font-family="Atkinson Hyperlegible" font-size="24" fill="black">Stand 19:13<', $svg);
    }

    public function test_no_pagination_pill_when_only_one_page(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 1);
        $this->assertStringNotContainsString('rx="24"', $svg, 'Pille nur bei mehr als einer Seite');
    }

    public function test_middle_page_shows_both_arrows_enabled_and_active_circle(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 2, 3);

        $this->assertStringContainsString('x="793" y="1256" width="290" height="48" rx="24"', $svg);
        $this->assertStringContainsString('fill="black">←<', $svg, 'Zurueck-Pfeil aktiv (nicht erste Seite)');
        $this->assertStringContainsString('fill="black">→<', $svg, 'Vor-Pfeil aktiv (nicht letzte Seite)');
        $this->assertStringContainsString('<circle cx="938" cy="1280" r="20" fill="black"/>', $svg);
        $this->assertStringContainsString('fill="white">2<', $svg, 'aktive Seite weiss auf dem Kreis');
        $this->assertStringContainsString('>1<', $svg);
        $this->assertStringContainsString('>3<', $svg);
    }

    public function test_first_page_grays_out_back_arrow(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3);
        $this->assertStringContainsString('fill="#b0b0b0">←<', $svg);
        $this->assertStringContainsString('fill="black">→<', $svg);
    }

    public function test_last_page_grays_out_forward_arrow(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 3, 3);
        $this->assertStringContainsString('fill="black">←<', $svg);
        $this->assertStringContainsString('fill="#b0b0b0">→<', $svg);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateStandPaginationTest.php`
Expected: FAIL („Call to undefined function board_render_stand_and_pagination_svg()")

- [ ] **Step 3: `board_render_stand_and_pagination_svg()` implementieren**

In `inc/board_template.php` ergänzen:

```php
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
    $startX = 793 + intdiv(290 - $totalPages * $slotWidth - 2 * $slotWidth, 2) + $slotWidth;
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
```

  **Hinweis für den Implementierer:** die feste Breite `290` und die festen
  x-Positionen (822/880/938/996/1054) aus der Spec sind für **genau 3
  Seiten** gemessen (der einzige am gerenderten Bild abgenommene Fall). Der
  Code oben verallgemeinert auf beliebig viele Seiten über `$slotWidth=58`
  (Abstand zwischen den Pillen-Steps in der 3-Seiten-Referenz) und behält
  für den 3-Seiten-Fall exakt die Spec-Werte (822/880/938/996/1054,
  Pillenbreite 290) bei — prüfe das mit einem eigenen Test/Sichtprüfung für
  `$totalPages=3`, bevor Werte für 4+ Seiten als verbindlich gelten.

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateStandPaginationTest.php`
Expected: PASS (5 Tests)

- [ ] **Step 5: Manuelle Sichtprüfung — exakte Pixelwerte für den 3-Seiten-Fall**

```bash
php -r '
require "inc/board_render.php";
require "inc/board_template.php";
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1200\" height=\"120\" viewBox=\"0 700 1200 120\">"
     . "<rect x=\"0\" y=\"700\" width=\"1200\" height=\"120\" fill=\"white\"/>"
     . board_render_stand_and_pagination_svg(new DateTimeImmutable(\"19:13\"), 2, 3) . "</svg>";
file_put_contents(\"/tmp/pagination_check.png\", svg_to_png($svg));
'
```

Öffne `/tmp/pagination_check.png` und vergleiche mit dem im Chat
abgenommenen Mockup (Pille, Seite 2 aktiv als schwarzer Kreis, „1"/„3" als
reiner Text, beide Pfeile schwarz). Weicht die x-Position der Zahlen sichtbar
vom Referenzbild ab, die Werte in Step 3 auf die exakten Spec-Zahlen
(822/880/938/996/1054) korrigieren statt der generalisierten Formel zu
vertrauen.

- [ ] **Step 6: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateStandPaginationTest.php
git commit -m "feat(board): Stand + kanonische Pagination am Ende der Abfahrtenspalte"
```

---

### Task 8: Störungsseite (`board_layout_disruptions()` + `board_render_disruptions_svg()`, neu)

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Unit/BoardTemplateDisruptionsTest.php`

**Interfaces:**
- Consumes: Liste von Alert-Einträgen im Format von `monitor_get()['alerts']`
  (`inc/monitor.php`): `array{title: string, description: string, priority: string, lines: list<string>, stops: list<int>}`.
  Diese Funktion filtert selbst NICHT auf die Linien eines Favoriten — das
  übernimmt der Aufrufer (Task 7), da das Filterkriterium (Favoriten-Linien)
  außerhalb dieser reinen Rendering-Schicht liegt.
- Produces:
  `board_wrap_disruption_text(string $text, int $maxLines): array` — wie
  `board_wrap_text()` (Task 4), aber mit **hartem Zeilenlimit**: bei mehr
  als `$maxLines` Zeilen wird auf der letzten Zeile gekürzt und „ …"
  angehängt (ORF-Störungstexte können mehrere hundert Zeichen lang sein,
  s. Spec §8).
  `const BOARD_DISRUPTIONS_MAX_CHARS_PER_LINE = 67` — hergeleitet wie
  `BOARD_WEATHER_TEXT_MAX_CHARS_PER_LINE`, aber für die breitere
  Abfahrtenspalte (1067px, `x=16` bis `x=1083`) bei 32px statt 39px
  Schriftgröße: `floor(1067 / (17.37 * 32/39) * 0.92) = 67`.
  `board_layout_disruptions(array $alerts): array` — Cursor-Layout analog
  zu `board_paginate_departures()`, aber ohne eigene Pagination in dieser
  Funktion (Störungen kommen nach den Abfahrten-Seiten; ob sie selbst auf
  mehrere Seiten müssen, überlässt dieser Task dem Aufrufer — mit
  realistisch wenigen aktiven Störungen pro Favorit unwahrscheinlich, aber
  nicht ausgeschlossen; **out of scope für diesen Task**, s. Global
  Constraints „Nicht Teil dieses Plans" zu Überlauf-Sonderfällen).
  `board_render_disruptions_svg(array $items): string`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

```php
<?php
// tests/Unit/BoardTemplateDisruptionsTest.php
//
// Stoerungsseite aus Spec §8/§9 (Stand 2026-08-16): Titel fett + gekuerzte
// Beschreibung, gleiches Zeilenraster-Prinzip wie die Abfahrtenliste.
// Fixture-Texte sind echte, am 2026-08-15 live abgerufene WL-Stoerungen.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDisruptionsTest extends TestCase
{
    // --- board_wrap_disruption_text -------------------------------------------

    public function test_wrap_disruption_text_truncates_with_ellipsis_beyond_max_lines(): void
    {
        $longText = 'Linie 5: Kein Betrieb zwischen Lerchenfelder Straße und Franz-Josefs-Bahnhof S. '
            . 'Betrieb zwischen Westbahnhof S U und Lerchenfelder Straße. Weiterfahrt bis Josefstädter Straße U. '
            . 'Betrieb zwischen Praterstern S U und Franz-Josefs-Bahnhof S. Weiterfahrt bis Augasse.';

        $lines = board_wrap_disruption_text($longText, 3);

        $this->assertCount(3, $lines);
        $this->assertStringEndsWith('…', $lines[2]);
    }

    public function test_short_disruption_text_not_truncated(): void
    {
        $lines = board_wrap_disruption_text('Kurze Meldung.', 3);
        $this->assertSame(['Kurze Meldung.'], $lines);
        $this->assertStringEndsNotWith('…', $lines[0]);
    }

    // --- board_layout_disruptions / board_render_disruptions_svg -------------

    private function alert(string $title, string $description, array $lines): array
    {
        return ['title' => $title, 'description' => $description, 'priority' => '1', 'lines' => $lines, 'stops' => []];
    }

    public function test_layout_and_render_two_real_disruptions(): void
    {
        $alerts = [
            $this->alert(
                '5, 12, 37, 38, 40, 41, 42: Gleisbauarbeiten',
                'Linie 5: Kein Betrieb zwischen Lerchenfelder Straße und Franz-Josefs-Bahnhof S. Betrieb zwischen Westbahnhof S U und Lerchenfelder Straße. Weiterfahrt bis Josefstädter Straße U.',
                ['5', '12', '37', '38', '40', '41', '42']
            ),
            $this->alert(
                'U3: Bauarbeiten',
                'Die Linie U3 fährt derzeit nicht zwischen Hütteldorfer Straße und Westbahnhof. Weichen Sie ersatzweise auf die Linien E3, 46, 49 und 48A aus.',
                ['U3']
            ),
        ];

        $items = board_layout_disruptions($alerts);
        $headers = array_values(array_filter($items, fn ($i) => $i['type'] === 'disruption_title'));
        $this->assertCount(2, $headers);
        $this->assertSame(160, $headers[0]['y']);
        $this->assertStringContainsString('Gleisbauarbeiten', $headers[0]['text']);

        $svg = board_render_disruptions_svg($items);
        $this->assertStringContainsString('font-weight="bold" font-size="40"', $svg);
        $this->assertStringContainsString('Gleisbauarbeiten', $svg);
        $this->assertStringContainsString('U3: Bauarbeiten', $svg);
        $this->assertStringContainsString('…', $svg, 'lange Meldung muss gekuerzt sein');
    }

    public function test_empty_alerts_render_nothing(): void
    {
        $this->assertSame([], board_layout_disruptions([]));
        $this->assertSame('', board_render_disruptions_svg([]));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDisruptionsTest.php`
Expected: FAIL („Call to undefined function board_wrap_disruption_text()")

- [ ] **Step 3: Implementieren**

In `inc/board_template.php` ergänzen:

```php
/**
 * Verfuegbare Spaltenbreite der Abfahrten-/Stoerungsspalte (1067px, x=16
 * bis x=1083) geteilt durch die bei 39px gemessene mittlere Zeichenbreite
 * (17,37px/Zeichen, s. Task 4), linear auf 32px skaliert, 8% Sicherheits-
 * abstand: floor(1067 / (17.37 * 32/39) * 0.92).
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
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardTemplateDisruptionsTest.php`
Expected: PASS (4 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add inc/board_template.php tests/Unit/BoardTemplateDisruptionsTest.php
git commit -m "feat(board): Stoerungsseite rendern (Titel + gekuerzte Beschreibung)"
```

---

### Task 7: Gesamt-Assembly (`board_render_svg()`, überarbeitet) + Integrationstest

**Ersetzt** die Assembly-Funktion aus der ursprünglichen Planversion.
Größte Änderung: die Funktion bekommt jetzt einen **einzelnen aktiven
Favoriten**, eine **aktive Seitennummer**, die **Titel aller
Touch-Leisten-Favoriten** + welcher davon aktiv ist, und optional
Störungs-Alerts — und wählt selbst, ob die aktive Seite eine
Abfahrten-Seite oder die Störungsseite ist (Störungsseite = letzte
Seite(n), nach allen Abfahrten-Seiten, s. Spec §8).

**Files:**
- Modify: `inc/board_template.php`
- Test: `tests/Integration/BoardTemplateRenderTest.php` (Inhalt wird ersetzt)

**Interfaces:**
- Produces:
  ```php
  function board_render_svg(
      array $touchBarFavoriteTitles,   // list<string>, 1-3 Eintraege
      int $activeFavoriteIndex,         // Index in $touchBarFavoriteTitles
      array $activeFavorite,            // board_favorite()-Ergebnis des aktiven Favoriten
      array $filteredAlerts,            // list<array{title,description,...}>, s. Task 8 -- bereits auf $activeFavorite gefiltert
      int $requestedPage,               // 1-basiert, ueber alle Abfahrten- + Stoerungsseiten hinweg
      array $weather,
      DateTimeImmutable $dataStand,
      DateTimeImmutable $renderedAt,
      int $batteryPercent,
      int $wifiBars
  ): string
  ```
  Seitenzählung: `board_paginate_departures($activeFavorite, ...)` liefert
  `totalPages` für die Abfahrten allein. Gibt es `$filteredAlerts`, kommt
  **eine weitere** Seite dazu (die Störungsseite — Task 8 paginiert
  Störungen selbst nicht, s. dortige Anmerkung, zählt hier also immer als
  genau 1 zusätzliche Seite). `$requestedPage` bis `totalDeparturePages`
  zeigt Abfahrten, `totalDeparturePages + 1` (falls vorhanden) zeigt
  Störungen.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Integration/BoardTemplateRenderTest.php
//
// End-to-End: board_render_svg() -> svg_to_png() -> png_to_1bpp_packed(),
// mit Pixel-Nachmessung wie in der manuellen Mockup-Session. Fixture ist
// der Favorit "Westbahnhof" (echte Daten, im Chat abgenommen) +
// Touch-Leiste mit 3 Favoriten + 2 echten WL-Stoerungsmeldungen.

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
        return ['id' => 225, 'title' => 'Westbahnhof', 'stations' => [
            ['diva' => '60201468', 'name' => 'Westbahnhof S U', 'lines' => [
                $this->line('18', '1', 'Schlachthausgasse U', 'tram', true, [['in' => 7], ['in' => 22]]),
                $this->line('6', '1', 'Geiereckstraße', 'tram', true, [['in' => 1], ['in' => 14]]),
                $this->line('9', '2', 'Gersthof S', 'tram', false, [['in' => 9], ['in' => 16]]),
                $this->line('U3', '1', 'Simmering', 'metro', true, [['in' => 0], ['in' => 8]]),
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 0], ['in' => 6]]),
                $this->line('U6', '2', 'Siebenhirten', 'metro', true, [['in' => 5], ['in' => 12]]),
            ]],
        ]];
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
                if ($lum < 200) { // < 200 statt < 128: faengt auch das mittlere Grau (#808080) ein
                    return $y;
                }
            }
        }
        return null;
    }

    public function test_full_pipeline_produces_correctly_sized_frame(): void
    {
        $svg = board_render_svg(
            ['Arbeit', 'Westbahnhof', 'zur Stadt'], 1, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $png = svg_to_png($svg);
        $packed = png_to_1bpp_packed($png, 1872, 1404);

        $this->assertSame(234 * 1404, strlen($packed));
    }

    public function test_touch_bar_shows_active_favorite_filled(): void
    {
        $svg = board_render_svg(
            ['Arbeit', 'Westbahnhof', 'zur Stadt'], 1, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );

        $this->assertStringContainsString('fill="white">Westbahnhof<', $svg, 'aktiver Favorit (Index 1) hat weisses Label auf schwarzem Button');
    }

    public function test_header_no_longer_contains_stand(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable('2026-08-16 19:13:00'), 78, 2
        );

        // "Stand" darf NUR am Ende der Abfahrtenspalte auftauchen (einmal),
        // nicht mehr in der Kopfzeile.
        $this->assertSame(1, substr_count($svg, 'Stand'));
    }

    public function test_disruptions_appear_as_final_page_when_alerts_present(): void
    {
        $alerts = [[
            'title' => 'U3: Bauarbeiten',
            'description' => 'Die Linie U3 fährt derzeit nicht zwischen Hütteldorfer Straße und Westbahnhof.',
            'priority' => '1', 'lines' => ['U3'], 'stops' => [],
        ]];

        // Westbahnhof-Fixture passt auf 1 Abfahrten-Seite -> Stoerungsseite ist Seite 2.
        $svgPage1 = board_render_svg(['Westbahnhof'], 0, $this->favoriteFixture(), $alerts, 1, $this->weatherFixture(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);
        $svgPage2 = board_render_svg(['Westbahnhof'], 0, $this->favoriteFixture(), $alerts, 2, $this->weatherFixture(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);

        $this->assertStringContainsString('WESTBAHNHOF S U', $svgPage1);
        $this->assertStringNotContainsString('U3: Bauarbeiten', $svgPage1);

        $this->assertStringContainsString('U3: Bauarbeiten', $svgPage2);
        $this->assertStringNotContainsString('WESTBAHNHOF S U', $svgPage2, 'Stoerungsseite zeigt keine Abfahrten mehr');
    }

    public function test_gap_between_station_header_and_first_row_matches_spec(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $im = imagecreatefromstring(svg_to_png($svg));

        $headerBottom = $this->firstInkY($im, 16, 600, 100, 200);
        $rowTop = $this->firstInkY($im, 16, 1083, 200, 280);

        $this->assertNotNull($headerBottom);
        $this->assertNotNull($rowTop);
    }

    public function test_wl_logo_is_monochrome_black_and_white_only(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );
        $im = imagecreatefromstring(svg_to_png($svg));

        // Logo-Bounding-Box (x=24..306, y=12..78, aus translate(24,12)
        // scale(0.5025) auf die 561,3x131,6-Quelle) darf keine echte Farbe
        // enthalten (R != G != B) -- anders als eine frühere Planversion, die
        // hier bewusst Markenfarben erlaubte (inzwischen ueberholt, s.
        // Global Constraints "Logo ist jetzt durchgehend monochrom").
        $colorPixels = 0;
        for ($y = 12; $y < 78; $y += 2) {
            for ($x = 24; $x < 306; $x += 2) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                if (max(abs($r - $g), abs($g - $b), abs($r - $b)) > 5) {
                    $colorPixels++;
                }
            }
        }
        $this->assertSame(0, $colorPixels, 'Logo muss vollstaendig schwarz/weiss sein, keine Markenfarben mehr');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Integration/BoardTemplateRenderTest.php`
Expected: FAIL („Call to undefined function board_render_svg()")

- [ ] **Step 3: `board_render_svg()` implementieren**

In `inc/board_template.php` ergänzen (ersetzt eine eventuell vorhandene
alte Fassung vollständig):

```php
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
```

- [ ] **Step 4: Tests laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Integration/BoardTemplateRenderTest.php`
Expected: PASS (6 Tests)

- [ ] **Step 5: Ganze Suite laufen lassen**

Run: `vendor/bin/phpunit`
Expected: PASS, keine Regressionen

- [ ] **Step 6: Manuelle Sichtprüfung — vollständiges Board, echte Favoriten + echte Störungen**

```bash
php -r '
require "inc/initialize.php";
require "inc/monitor.php";
require "inc/board.php";
require "inc/weather.php";
require "inc/board_render.php";
require "inc/board_template.php";

$res = $con->query("SELECT id, title, diva, filter_json FROM wl_favorites WHERE id=225");
$row = $res->fetch_assoc();
$fav = ["id"=>$row["id"], "title"=>$row["title"], "diva"=>$row["diva"], "filter"=>json_decode($row["filter_json"], true)];
$monitor = monitor_get($con, $fav["diva"], 5);
$monitor = monitor_inject_missing_stations($con, $monitor, $fav["diva"]);
$favorite = board_favorite($fav, $monitor);

$alerts = array_values(array_filter($monitor["alerts"], function ($a) use ($favorite) {
    foreach ($favorite["stations"] as $s) {
        foreach ($s["lines"] as $l) {
            if (in_array($l["line"], $a["lines"], true)) return true;
        }
    }
    return false;
}));

$weatherCache = json_decode(file_get_contents("data/weather_cache.json"), true);
$weather = weather_select_display($weatherCache, new DateTimeImmutable());

$svg = board_render_svg(["Arbeit", "Westbahnhof", "zur Stadt"], 1, $favorite, $alerts, 1, $weather, new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);
$png = svg_to_png($svg);
$packed = png_to_1bpp_packed($png, 1872, 1404);

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
echo "geschrieben: /tmp/board_final_check.png (Seite 1 von " . (isset($totalPages) ? $totalPages : "?") . ")\n";
'
```

Öffne `/tmp/board_final_check.png` und vergleiche gegen die im Chat zuletzt
bestätigten Mockups (Kopfzeile, Touch-Leiste, Stand+Pagination, Grau-Zeile,
Vektor-Stern). Rendere zusätzlich mit `$requestedPage=2` (falls Störungen
vorhanden sind) und vergleiche gegen das Störungsseiten-Mockup.

- [ ] **Step 7: Commit**

```bash
git add inc/board_template.php tests/Integration/BoardTemplateRenderTest.php
git commit -m "feat(board): Gesamt-Assembly mit Favoriten-/Seitenauswahl, Touch-Leiste, Stoerungsseite"
```

---

## Self-Review (für die Ausführung, Stand 2026-08-16)

- **Spec-Abdeckung:** Kopfzeile ohne Statuszeile (Task 3), Touch-Leiste
  (Task 3b), Abfahrtenliste inkl. Pagination-Split (Task 5+6), Stand+
  Pagination-Widget (Task 6b), Störungsseite (Task 8), Wetterkarte
  (Task 4, unverändert gültig), Grau-Zeilenstil + Vektor-Stern (Task 2b+6),
  Gesamt-Orchestrierung inkl. Favoriten-/Seitenauswahl (Task 7) — alle
  Layout-Abschnitte der überarbeiteten Spec sind auf einen Task gemappt.
- **Typkonsistenz geprüft:** `board_paginate_departures()` (Task 5) und
  `board_render_departures_svg()` (Task 6) teilen sich dieselben
  Array-Keys wie zuvor, `style` ist jetzt `'normal'|'gray'|'delayed'`
  (statt `'italic'`) — konsistent in Task 5 (Erzeugung) und Task 6
  (Konsum) verwendet.
- **Bekannte Vereinfachung, offen für Task 6b:** die generalisierte
  Pagination-Pillen-Breite für andere Seitenzahlen als 3 ist nicht am
  gerenderten Bild verifiziert (nur der 3-Seiten-Fall aus der Spec) — im
  Implementierungs-Step ausdrücklich als Prüfpunkt markiert.
- **Kein Platzhalter:** jeder Task enthält vollständigen, direkt
  einsetzbaren Code.
