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
