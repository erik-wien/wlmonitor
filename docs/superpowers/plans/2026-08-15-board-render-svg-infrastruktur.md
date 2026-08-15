# Board-Render: SVG-Infrastruktur Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die beiden mechanischen Bausteine der Rendering-Pipeline aus Spec §7
bauen — SVG-String → PNG-Bytes (via `rsvg-convert`) und PNG-Bytes → gepackte
1bpp-Rohdaten (via `ext-gd`, harter Schwellwert) — vollständig unabhängig vom
eigentlichen Board-Layout (das ist ein eigener, späterer Plan) und ohne
HTTP/Auth/DB-Maschinerie testbar.

**Architektur:** Zwei reine(re) Funktionen in `inc/board_render.php`.
`svg_to_png()` startet `rsvg-convert` als Subprozess (Array-Form von
`proc_open`, kein Shell-String — keine Escaping-Fragen), schickt das SVG über
Stdin, liest PNG-Bytes von Stdout. `png_to_1bpp_packed()` liest die PNG-Bytes
mit `ext-gd`, wendet einen harten Helligkeits-Schwellwert an und packt direkt
in MSB-first-Bytes — kein Zwischenschritt über eine „1bpp-PNG"-Datei. Beide
Funktionen sind mit echten Werkzeugen getestet (echter `rsvg-convert`-Aufruf,
echte mit `ext-gd` selbst erzeugte Test-PNGs), nicht gemockt.

**Tech Stack:** PHP 8.2, `ext-gd`, `rsvg-convert` (Homebrew `librsvg`, siehe
`epaper-monitor/README.md`), PHPUnit (`tests/Unit`).

## Global Constraints

- **Bit-Konvention (neu festgelegt, ergänzt Spec §6):** im gepackten Ausgabeformat
  ist **1 = Weiß, 0 = Schwarz** — pro Byte MSB-first (Bit 7 = linkestes Pixel
  der 8er-Gruppe). Zeilenbreite wird auf ein Vielfaches von 8 aufgerundet;
  Füll-Pixel jenseits der tatsächlichen Bildbreite sind **weiß** (Bit = 1).
- **Kein Dithering.** Schwellwert ist ein harter Cut bei Helligkeit ≥ 128
  (ITU-R-BT.601-Luminanz: `0.299·R + 0.587·G + 0.114·B`) → weiß, sonst schwarz.
  Text-/Icon-Kanten bleiben scharf statt körnig (Spec §7).
- **Kein ImageMagick.** Auf dem Zielsystem nicht installiert; `ext-gd` ist es.
- **Kein `imagedestroy()`-Aufruf.** Seit PHP 8.0 wirkungslos (GD-Objekte
  werden vom normalen PHP-Objekt-GC eingesammelt), seit PHP 8.5 sogar
  deprecated — ein Aufruf würde auf der tatsächlichen Laufzeitumgebung dieses
  Projekts (PHP 8.5.8) den Testoutput mit Deprecation-Warnungen verschmutzen.
  Bewusstes Weglassen, kein vergessenes Aufräumen.
- `rsvg-convert` wird über die Array-Form von `proc_open` aufgerufen (kein
  Shell-String) — keine Escaping-Angriffsfläche, egal was im SVG-Inhalt steht.
- `declare(strict_types=1)`, PHPUnit-Namespace `WLMonitor\Tests\Unit`,
  deutsche Doc-Kommentare — wie im Rest von `inc/`.
- Spec: `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md` §7.
- **Nicht Teil dieses Plans:** das eigentliche Board-SVG-Template
  (Header/Abfahrtenliste/Wetterkarte/Fußzeile — eigener, späterer Plan mit
  Layout-Iteration über den `?debug=svg`-Endpunkt), die Diff-/Patch-/
  ETag-Logik und der `board.php`-Protokoll-Umbau (ebenfalls eigener Plan).

---

### Task 1: `svg_to_png()`

**Files:**
- Create: `inc/board_render.php`
- Test: `tests/Unit/BoardRenderTest.php`

**Interfaces:**
- Produces: `svg_to_png(string $svg): string` — liefert rohe PNG-Bytes, wirft
  `RuntimeException` wenn `rsvg-convert` fehlschlägt oder leer/nicht startbar
  ist.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Unit/BoardRenderTest.php
//
// SVG->PNG->1bpp-Pipeline aus Spec §7. svg_to_png() ruft den echten
// installierten rsvg-convert auf (kein Mock) -- das Zielsystem hat ihn
// (Homebrew librsvg), ImageMagick dagegen nicht (deshalb ext-gd fuer den
// zweiten Schritt, siehe Task 2).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class BoardRenderTest extends TestCase
{
    private function tinySvg(int $width, int $height, string $fill = 'black'): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
            . '<rect width="%d" height="%d" fill="%s"/></svg>',
            $width, $height, $width, $height, $width, $height, $fill
        );
    }

    public function test_svg_to_png_produces_valid_png_with_correct_dimensions(): void
    {
        $png = svg_to_png($this->tinySvg(16, 8));

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png, 'muss echte PNG-Magic-Bytes haben');

        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info, 'PNG muss von PHP lesbar sein');
        $this->assertSame(16, $info[0]);
        $this->assertSame(8, $info[1]);
    }

    public function test_svg_to_png_throws_on_malformed_svg(): void
    {
        $this->expectException(RuntimeException::class);
        svg_to_png('<svg><this is not valid xml');
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: FAIL — `Call to undefined function …svg_to_png()`

- [ ] **Step 3: `svg_to_png()` implementieren**

```php
<?php
// inc/board_render.php
//
// Rendering-Pipeline aus Spec §7: SVG-String -> PNG-Bytes (rsvg-convert) ->
// gepackte 1bpp-Rohdaten (ext-gd, harter Schwellwert). Das eigentliche
// Board-SVG-Template (Layout) ist NICHT Teil dieser Datei/dieses Plans --
// diese Funktionen sind reine Infrastruktur, unabhaengig vom Inhalt.
declare(strict_types=1);

/**
 * Rendert einen SVG-String zu PNG-Bytes via rsvg-convert (Subprozess).
 * Array-Form von proc_open -- kein Shell-String, keine Escaping-Fragen
 * unabhaengig vom SVG-Inhalt.
 *
 * @throws RuntimeException wenn rsvg-convert nicht startet oder fehlschlaegt
 */
function svg_to_png(string $svg): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['rsvg-convert', '-f', 'png'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('rsvg-convert konnte nicht gestartet werden');
    }

    fwrite($pipes[0], $svg);
    fclose($pipes[0]);

    $png = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $png === false || $png === '') {
        throw new RuntimeException(
            'rsvg-convert fehlgeschlagen (Exit ' . $exitCode . '): ' . trim((string) $stderr)
        );
    }

    return $png;
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: OK (2 tests)

- [ ] **Step 5: Commit**

```bash
git add inc/board_render.php tests/Unit/BoardRenderTest.php
git commit -m "feat(board-render): svg_to_png() -- rsvg-convert als Subprozess"
```

---

### Task 2: `png_to_1bpp_packed()`

**Files:**
- Modify: `inc/board_render.php`
- Modify: `tests/Unit/BoardRenderTest.php`

**Interfaces:**
- Consumes: nichts von Task 1 direkt (eigenständig testbar mit synthetischen
  PNGs), wird aber im echten Betrieb mit `svg_to_png()`s Ausgabe gefüttert.
- Produces: `png_to_1bpp_packed(string $pngBinary, int $width, int $height): string`
  — wirft `RuntimeException` bei unlesbarem PNG oder Größenabweichung.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

Ergänze `tests/Unit/BoardRenderTest.php` um folgenden Hilfsmethoden-Block und
die Tests (nach der bestehenden Klasse-Eröffnung, vor der schließenden `}`):

```php
    /** Erzeugt ein echtes PNG (ueber ext-gd) mit einer Pixel-Farbfunktion. */
    private function makeTestPng(int $width, int $height, callable $pixelColor): string
    {
        $im = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = $pixelColor($x, $y);
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $r, $g, $b));
            }
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        return $png;
    }

    public function test_all_black_8x8_packs_to_zero_bytes(): void
    {
        $png = $this->makeTestPng(8, 8, fn($x, $y) => [0, 0, 0]);
        $packed = png_to_1bpp_packed($png, 8, 8);

        $this->assertSame(8, strlen($packed), '8 Zeilen a 1 Byte bei Breite 8');
        $this->assertSame(str_repeat("\x00", 8), $packed, 'schwarz = Bit 0');
    }

    public function test_all_white_8x8_packs_to_0xff_bytes(): void
    {
        $png = $this->makeTestPng(8, 8, fn($x, $y) => [255, 255, 255]);
        $packed = png_to_1bpp_packed($png, 8, 8);

        $this->assertSame(str_repeat("\xFF", 8), $packed, 'weiss = Bit 1');
    }

    public function test_half_black_half_white_row_packs_msb_first(): void
    {
        // Spalten 0-3 schwarz, 4-7 weiss. MSB-first: Bit 7 = Spalte 0 (schwarz=0)
        // ... Bit 0 = Spalte 7 (weiss=1) -> 0b00001111 = 0x0F.
        $png = $this->makeTestPng(8, 1, fn($x, $y) => $x < 4 ? [0, 0, 0] : [255, 255, 255]);
        $packed = png_to_1bpp_packed($png, 8, 1);

        $this->assertSame("\x0F", $packed);
    }

    public function test_width_not_multiple_of_8_pads_with_white_bits(): void
    {
        // Breite 5, alle 5 echten Pixel schwarz. Zeile wird auf 1 Byte (8 Bit)
        // aufgerundet, die 3 Fuell-Bits jenseits von Spalte 4 sind weiss (1):
        // Bits 7..3 (Spalten 0-4) = 0, Bits 2..0 (Fuellung) = 1 -> 0b00000111 = 0x07.
        $png = $this->makeTestPng(5, 1, fn($x, $y) => [0, 0, 0]);
        $packed = png_to_1bpp_packed($png, 5, 1);

        $this->assertSame(1, strlen($packed), 'ceil(5/8) = 1 Byte pro Zeile');
        $this->assertSame("\x07", $packed);
    }

    public function test_throws_on_dimension_mismatch(): void
    {
        $png = $this->makeTestPng(8, 8, fn($x, $y) => [0, 0, 0]);

        $this->expectException(RuntimeException::class);
        png_to_1bpp_packed($png, 16, 16);
    }
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: FAIL — `Call to undefined function …png_to_1bpp_packed()`

- [ ] **Step 3: `png_to_1bpp_packed()` implementieren**

Füge in `inc/board_render.php` an:

```php
/**
 * Liest PNG-Bytes und packt sie in gepackte 1bpp-Rohdaten: MSB-first,
 * zeilenweise, Breite auf ein Vielfaches von 8 aufgerundet. Bit-Konvention:
 * 1 = Weiss, 0 = Schwarz (siehe Global Constraints). Harter Schwellwert bei
 * Helligkeit >= 128 (ITU-R BT.601) -- bewusst kein Dithering (Spec §7).
 *
 * @throws RuntimeException wenn das PNG unlesbar ist oder nicht die
 *         erwartete Groesse hat
 */
function png_to_1bpp_packed(string $pngBinary, int $width, int $height): string
{
    $image = @imagecreatefromstring($pngBinary);
    if ($image === false) {
        throw new RuntimeException('PNG konnte nicht gelesen werden');
    }

    if (imagesx($image) !== $width || imagesy($image) !== $height) {
        $actualWidth = imagesx($image);
        $actualHeight = imagesy($image);
        throw new RuntimeException(sprintf(
            'PNG-Groesse (%dx%d) passt nicht zur erwarteten Groesse (%dx%d)',
            $actualWidth, $actualHeight, $width, $height
        ));
    }

    $rowBytes = (int) ceil($width / 8);
    $out = '';

    for ($y = 0; $y < $height; $y++) {
        $row = str_repeat("\x00", $rowBytes);
        for ($byteIndex = 0; $byteIndex < $rowBytes; $byteIndex++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $byteIndex * 8 + $bit;
                $isWhite = true; // Fuell-Pixel jenseits der Bildbreite sind weiss
                if ($x < $width) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                    $isWhite = $luminance >= 128.0;
                }
                if ($isWhite) {
                    $byte |= (1 << (7 - $bit));
                }
            }
            $row[$byteIndex] = chr($byte);
        }
        $out .= $row;
    }

    return $out;
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: OK (7 tests)

- [ ] **Step 5: Voll-Suite einmal laufen lassen**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: OK (keine Regressionen)

- [ ] **Step 6: Commit**

```bash
git add inc/board_render.php tests/Unit/BoardRenderTest.php
git commit -m "feat(board-render): png_to_1bpp_packed() -- Schwellwert + MSB-first-Packung"
```

---

### Task 3: End-to-End-Rauchtest (SVG → PNG → 1bpp, ohne Board-Inhalt)

**Files:**
- Modify: `tests/Unit/BoardRenderTest.php`

**Interfaces:**
- Consumes: `svg_to_png()` (Task 1), `png_to_1bpp_packed()` (Task 2) — beide
  zusammen, nicht isoliert.

Kein neuer Code in `inc/board_render.php` — dieser Task verdrahtet die beiden
bereits getesteten Funktionen einmal end-to-end, damit ein Regressionsfehler
im Zusammenspiel (z. B. eine Formatannahme, die bei isolierten Tests nicht
auffällt) sichtbar wird, bevor das eigentliche Board-Template (späterer Plan)
darauf aufsetzt.

- [ ] **Step 1: Test schreiben**

Ergänze `tests/Unit/BoardRenderTest.php`:

```php
    public function test_full_pipeline_svg_to_packed_bits(): void
    {
        // 16x8, linke Haelfte schwarz, rechte weiss -- wie test_half_black...,
        // aber diesmal durch echtes rsvg-convert gerendert statt synthetisch
        // per ext-gd erzeugt.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="8" viewBox="0 0 16 8">'
             . '<rect width="8" height="8" fill="black"/>'
             . '<rect x="8" width="8" height="8" fill="white"/>'
             . '</svg>';

        $png = svg_to_png($svg);
        $packed = png_to_1bpp_packed($png, 16, 8);

        // 16 px Breite = 2 Byte pro Zeile, 8 Zeilen = 16 Byte gesamt.
        $this->assertSame(16, strlen($packed));
        // Jede Zeile: erstes Byte (Spalten 0-7, alle schwarz) = 0x00,
        // zweites Byte (Spalten 8-15, alle weiss) = 0xFF.
        for ($row = 0; $row < 8; $row++) {
            $this->assertSame("\x00", $packed[$row * 2], "Zeile $row, erstes Byte");
            $this->assertSame("\xFF", $packed[$row * 2 + 1], "Zeile $row, zweites Byte");
        }
    }
```

- [ ] **Step 2: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: OK (8 tests)

- [ ] **Step 3: Voll-Suite**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: OK

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/BoardRenderTest.php
git commit -m "test(board-render): End-to-End-Rauchtest svg_to_png + png_to_1bpp_packed"
```

---

## Self-Review

**Spec-Abdeckung (§7, Rendering-Schritte 2-4):** `rsvg-convert` SVG→PNG ✓
(Task 1), PHP/GD-Schwellwert statt ImageMagick ✓ (Task 2, entspricht der
Spec-Korrektur vom 2026-08-15), MSB-first/zeilenweise/auf 8 aufgerundet ✓
(Task 2, mit Padding-Test), kein Dithering ✓ (harter Schwellwert, dokumentiert
im Docblock). Schritt 1 (Template befüllen) und Schritt 4/§5 (Diff-Logik)
sind bewusst **nicht** Teil dieses Plans (siehe Global Constraints).

**Platzhalter-Scan:** keine TBD/TODO, jeder Schritt hat vollständigen Code
und von Hand nachgerechnete erwartete Testwerte (0x0F, 0x07 etc. — nicht nur
behauptet, sondern gegen eine echte Implementierung ausgeführt und bestätigt:
alle sechs Testfälle sowie der End-to-End-Pfad liefen vor dem Schreiben
dieses Plans einmal real durch, inklusive des echten `rsvg-convert`-Aufrufs).
Dabei aufgefallen und korrigiert: `imagedestroy()` erzeugt auf PHP 8.5 eine
Deprecation-Warnung — aus Implementierung und Testhelfer entfernt (siehe
Global Constraints).

**Typkonsistenz:** `svg_to_png(string $svg): string` (Task 1) liefert genau
die PNG-Bytes, die `png_to_1bpp_packed(string $pngBinary, int $width, int $height): string`
(Task 2) als ersten Parameter erwartet — Task 3 verkettet beide unverändert.

**Umfang-Grenze bewusst eng gehalten:** dieser Plan bleibt bei den beiden
mechanischen Funktionen. Das eigentliche Board-Layout (SVG-Template mit
Stationskarten/Wetterkarte/Statuszeile) ist Layout-/Design-Arbeit, die eigene
Iteration über den `?debug=svg`-Endpunkt braucht (Spec §7) und deshalb ein
eigener, nachfolgender Plan wird — kein Teil-Task hier, der das vortäuschen
würde.
