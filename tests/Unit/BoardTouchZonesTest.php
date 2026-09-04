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
        // direkt zu seiner eigenen Seitenzahl. Seit 2026-09-04 sitzt die Pille
        // LINKS in der Favoritenzeile (totalPages=3: pillWidth=3*100+20=320,
        // pillStartX=16, Pillenende 336). Die letzte Zone reicht bis zum
        // Pillenende, nicht nur bis zum Slotende -- deckungsgleich mit
        // mapPaginationTouch()'s Klemmung des rechten Padding-Streifens auf
        // den letzten Slot (touch_zone.cpp).
        $zones = board_touch_zones(0, 3);

        $this->assertSame([
            ['zone' => 'page_1', 'x' => 16,  'y' => 1320, 'w' => 100, 'h' => 74],
            ['zone' => 'page_2', 'x' => 116, 'y' => 1320, 'w' => 100, 'h' => 74],
            ['zone' => 'page_3', 'x' => 216, 'y' => 1320, 'w' => 120, 'h' => 74],
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
        // Pille ZUERST: sie liegt seit 2026-09-04 in derselben Zeile wie die
        // Favoriten, und touch_zone.cpp prueft sie ebenfalls zuerst. Waere die
        // Reihenfolge hier anders, zeigte der Simulator eine andere
        // Trefferreihenfolge als das Geraet.
        $this->assertSame(['page_1', 'page_2', 'fav0'], $names);
    }

    public function test_favorites_start_after_the_pill_in_the_same_row(): void
    {
        // Kern der Umstellung: ein Favorit darf nicht mehr bei x=16 beginnen,
        // dort sitzt jetzt die Pille. Muss zu mapFavoriteTouch() passen.
        $zones = board_touch_zones(3, 3);
        $favs = array_values(array_filter($zones, static fn ($z) => str_starts_with($z['zone'], 'fav')));

        // pillWidth=320 -> links = 16 + 320 + 16 = 352
        $this->assertSame(352, $favs[0]['x']);
        $this->assertSame(1320, $favs[0]['y']);
        // 1854 statt 1856: die Ganzzahldivision der Buttonbreite laesst bis zu
        // (Anzahl-1) Pixel liegen. War vor der Umstellung genauso (16 + 3*602
        // + 2*16 = 1854) -- bewusst nicht "korrigiert", 2px auf 1872 sind
        // unsichtbar und jede Ausgleichsrechnung waere neue Komplexitaet.
        $this->assertSame(1854, $favs[2]['x'] + $favs[2]['w']);

        // Ohne Pille beginnen sie wieder ganz links.
        $ohnePille = board_touch_zones(3, 1);
        $this->assertSame(16, $ohnePille[0]['x']);
    }
}
