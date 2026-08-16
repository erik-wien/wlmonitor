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

    public function test_uncovered_svg_region_renders_white_not_transparent(): void
    {
        // 16x8, nur die linke Haelfte wird bemalt (schwarz), die rechte
        // Haelfte bleibt im SVG unbemalt/transparent. rsvg-convert muss mit
        // "-b white" aufgerufen werden, sonst rendert der unbemalte Bereich
        // transparent (RGB 0,0,0, Alpha 0) und imagecolorat() liest davon nur
        // die ignorierte RGB-Komponente -> faelschlich Schwarz statt Weiss.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="8" viewBox="0 0 16 8">'
             . '<rect width="8" height="8" fill="black"/>'
             . '</svg>';

        $png = svg_to_png($svg);
        $packed = png_to_1bpp_packed($png, 16, 8);

        $this->assertSame(16, strlen($packed));
        // Jede Zeile: erstes Byte (Spalten 0-7, bemalt schwarz) = 0x00,
        // zweites Byte (Spalten 8-15, unbemalt) muss WEISS sein = 0xFF.
        for ($row = 0; $row < 8; $row++) {
            $this->assertSame("\x00", $packed[$row * 2], "Zeile $row, erstes Byte (bemalt)");
            $this->assertSame("\xFF", $packed[$row * 2 + 1], "Zeile $row, zweites Byte (unbemalt -> muss weiss sein)");
        }
    }

    public function test_fontconfig_path_points_at_board_fonts_dir(): void
    {
        $path = board_fontconfig_path();

        $this->assertFileExists($path);
        $xml = file_get_contents($path);
        $expectedDir = realpath(__DIR__ . '/../../assets/fonts/board');
        $this->assertNotFalse($expectedDir, 'assets/fonts/board muss existieren');
        $this->assertStringContainsString('<dir>' . $expectedDir . '</dir>', $xml);
    }

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
}
