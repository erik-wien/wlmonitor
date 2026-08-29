<?php
// tests/Unit/BoardTouchZonesTest.php
//
// board_touch_zones() liefert die anklickbaren Bereiche fuer den Browser-
// Simulator (web/board.php?debug=ui) -- dieselben Formeln wie
// board_render_touch_bar_svg() (Werte hier uebernommen aus
// BoardTemplateTouchBarTest.php) und board_render_stand_and_pagination_svg()
// (Werte aus BoardTemplateStandPaginationTest.php), nur als Rechtecke statt
// als SVG. MUSS mit mapTouchToZone() in
// epaper-monitor/lib/boardlogic/touch_zone.cpp uebereinstimmen.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTouchZonesTest extends TestCase
{
    public function test_three_favorites_match_the_touch_bar_geometry(): void
    {
        // Bekannte Werte aus BoardTemplateTouchBarTest::test_three_buttons_equal_width_active_one_filled().
        $zones = board_touch_zones(3, 1); // totalPages=1 -> keine Pagination-Zonen

        $this->assertSame([
            ['zone' => 'fav0', 'x' => 16, 'y' => 1320, 'w' => 602, 'h' => 84],
            ['zone' => 'fav1', 'x' => 634, 'y' => 1320, 'w' => 602, 'h' => 84],
            ['zone' => 'fav2', 'x' => 1252, 'y' => 1320, 'w' => 602, 'h' => 84],
        ], $zones);
    }

    public function test_two_favorites_recompute_button_width(): void
    {
        // Bekannter Wert aus BoardTemplateTouchBarTest::test_fewer_than_three_favorites_recomputes_button_width().
        $zones = board_touch_zones(2, 1);

        $this->assertCount(2, $zones);
        $this->assertSame(912, $zones[0]['w']);
        $this->assertSame(912, $zones[1]['w']);
    }

    public function test_no_favorites_and_single_page_yields_no_zones(): void
    {
        $this->assertSame([], board_touch_zones(0, 1));
    }

    public function test_pagination_zones_are_absolute_one_per_page_not_prev_next(): void
    {
        // TASK-25 (Nutzerwunsch 2026-08-27: "Vor/zurueck ist ein Anachronismus",
        // "vier Sprungziele, absolut nicht relativ") -- jeder Slot springt
        // direkt zu seiner eigenen Seitenzahl. Bekannte Geometrie aus
        // BoardTemplateStandPaginationTest (totalPages=3: pillWidth=281,
        // pillStartX=802). Die letzte Zone reicht bis zum rechten Pillenrand
        // (1083), nicht nur bis zum Slotende -- deckungsgleich mit
        // mapPaginationTouch()'s Klemmung des rechten Padding-Streifens auf
        // den letzten Slot (touch_zone.cpp).
        $zones = board_touch_zones(0, 3);

        $this->assertSame([
            ['zone' => 'page_1', 'x' => 802, 'y' => 1252, 'w' => 87, 'h' => 48],
            ['zone' => 'page_2', 'x' => 889, 'y' => 1252, 'w' => 87, 'h' => 48],
            ['zone' => 'page_3', 'x' => 976, 'y' => 1252, 'w' => 107, 'h' => 48],
        ], $zones);
    }

    public function test_single_page_yields_no_pagination_zones(): void
    {
        $this->assertSame([], board_touch_zones(0, 1));
    }

    public function test_favorites_and_pagination_zones_combine(): void
    {
        $zones = board_touch_zones(1, 2);
        $names = array_column($zones, 'zone');
        $this->assertSame(['fav0', 'page_1', 'page_2'], $names);
    }
}
