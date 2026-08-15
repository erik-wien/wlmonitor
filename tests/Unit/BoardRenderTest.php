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
}
