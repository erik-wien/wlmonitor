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
        $this->assertStringNotContainsString('x1="0" y1="1310" x2="1872" y2="1310"', $svg, 'Fusszeilen-Trennlinie zwischen Pille und Touch-Leiste entfaellt (Nutzerentscheidung 2026-09-01)');
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

    // --- Lade-Erkennung ueber SPANNUNG (Nutzerbefund 2026-09-04) ------------
    //
    // Frueher verglich board_battery_is_charging() PROZENT. Das Prozent haengt
    // seinerseits an der Kalibrierung -- eine Schwelle darauf haette sich bei
    // jeder Aenderung der Spanne stillschweigend mitverschoben.

    public function test_charging_is_decided_on_millivolts(): void
    {
        $this->assertTrue(board_battery_is_charging_mv(4155));
        $this->assertTrue(board_battery_is_charging_mv(4200));
        $this->assertFalse(board_battery_is_charging_mv(4154));
        $this->assertFalse(board_battery_is_charging_mv(3700));
    }

    public function test_charging_threshold_is_configurable(): void
    {
        $this->assertTrue(board_battery_is_charging_mv(4000, 4000));
        $this->assertFalse(board_battery_is_charging_mv(3999, 4000));
        $this->assertFalse(board_battery_is_charging_mv(4000), 'Vorgabe bleibt 4155');
    }

    public function test_chrome_shows_lightning_bolt_instead_of_a_value_when_charging(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 97, 3, true, 4180);

        $this->assertStringNotContainsString('97 %', $svg);
        $this->assertStringContainsString('<polygon', $svg, 'Blitz-Symbol');
    }

    public function test_chrome_shows_the_value_when_not_charging(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 80, 3, true, 3900);

        $this->assertStringContainsString('>80 %<', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
    }

    public function test_chrome_without_a_measurement_never_shows_the_bolt(): void
    {
        // $batteryMv = 0 heisst "keine Messung im Request" (der Header ist
        // optional) -- ein Blitz waere dort eine Behauptung ueber Hardware,
        // von der nichts bekannt ist.
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 0, 3);

        $this->assertStringNotContainsString('<polygon', $svg);
    }

    // --- Schalterpille % / Volt (Nutzerwunsch 2026-09-04) -------------------

    public function test_label_shows_percent_or_volt_depending_on_the_mode(): void
    {
        $this->assertSame('87 %', board_battery_label(3987, 87, 'percent'));
        $this->assertSame('3,99 V', board_battery_label(3987, 87, 'volt'), 'deutsches Dezimalkomma');
        $this->assertSame('4,20 V', board_battery_label(4200, 100, 'volt'));
    }

    public function test_chrome_renders_volts_when_that_mode_is_set(): void
    {
        $svg = board_render_chrome_svg(new DateTimeImmutable(), 62, 3, true, 3850, 4155, 'volt');

        $this->assertStringContainsString('3,85 V', $svg);
        $this->assertStringNotContainsString('62 %', $svg);
    }

    public function test_the_bar_follows_percent_in_both_modes(): void
    {
        // Ein Balken ist ein Balken -- nur die Beschriftung wechselt.
        $prozent = board_render_chrome_svg(new DateTimeImmutable(), 50, 3, true, 3714, 4155, 'percent');
        $volt    = board_render_chrome_svg(new DateTimeImmutable(), 50, 3, true, 3714, 4155, 'volt');

        $breite = sprintf('width="%d"', board_battery_fill_width(50));
        $this->assertStringContainsString($breite, $prozent);
        $this->assertStringContainsString($breite, $volt);
    }
}
