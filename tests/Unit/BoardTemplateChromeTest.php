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

    // --- Lade-Erkennung (Nutzerkalibrierung 2026-08-22, verschaerft auf >=95%) --

    public function test_battery_is_charging_from_95_percent(): void
    {
        $this->assertTrue(board_battery_is_charging(95));
        $this->assertTrue(board_battery_is_charging(97));
        $this->assertTrue(board_battery_is_charging(100));
    }

    public function test_battery_is_not_charging_below_95_percent(): void
    {
        $this->assertFalse(board_battery_is_charging(94));
        $this->assertFalse(board_battery_is_charging(50));
        $this->assertFalse(board_battery_is_charging(0));
    }

    public function test_chrome_shows_lightning_bolt_instead_of_percent_when_charging(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 97, 3);

        $this->assertStringNotContainsString('97 %', $svg);
        $this->assertStringContainsString('<polygon', $svg, 'Blitz-Symbol');
    }

    public function test_chrome_shows_percent_when_not_charging(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 80, 3);

        $this->assertStringContainsString('>80 %<', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
    }

    // --- Anzeige-Korrektur 92-94% -> 100% (Nutzerkalibrierung 2026-08-22) -----

    public function test_battery_display_percent_rounds_92_to_94_up_to_100(): void
    {
        $this->assertSame(100, board_battery_display_percent(92));
        $this->assertSame(100, board_battery_display_percent(93));
        $this->assertSame(100, board_battery_display_percent(94));
    }

    public function test_battery_display_percent_leaves_other_values_unchanged(): void
    {
        $this->assertSame(91, board_battery_display_percent(91));
        $this->assertSame(95, board_battery_display_percent(95));
        $this->assertSame(50, board_battery_display_percent(50));
        $this->assertSame(0, board_battery_display_percent(0));
    }

    public function test_chrome_shows_100_percent_and_full_bar_for_92_to_94_raw(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 93, 3);

        $this->assertStringContainsString('>100 %<', $svg);
        $this->assertStringContainsString('width="48"', $svg, 'voller Balken bei angezeigten 100%');
        $this->assertStringNotContainsString('<polygon', $svg, '92-94% ist noch kein Laden, s. board_battery_is_charging()');
    }
}
