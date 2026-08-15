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
