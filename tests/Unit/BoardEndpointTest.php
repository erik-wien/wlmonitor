<?php
// tests/Unit/BoardEndpointTest.php
//
// Auswahl- und Sammel-Logik des Board-Endpunkts. Rein, ohne HTTP.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardEndpointTest extends TestCase
{
    private function favs(): array
    {
        return [
            ['id' => 3,  'title' => 'Arbeit',    'diva' => '60200103,60200470', 'filter' => null],
            ['id' => 7,  'title' => '➙ Stadt',   'diva' => '60200368,60200470', 'filter' => null],
            ['id' => 11, 'title' => 'Oberlaa',   'diva' => '60200999',          'filter' => null],
        ];
    }

    public function test_selection_returns_requested_ids_in_requested_order(): void
    {
        $out = board_selected_favorites($this->favs(), '7,3');
        $this->assertSame([7, 3], array_column($out, 'id'));
    }

    public function test_selection_ignores_unknown_ids_silently(): void
    {
        // Fremde IDs duerfen keine Auskunft ueber fremde Datensaetze geben —
        // also weder Fehler noch Unterschied in der Antwort.
        $out = board_selected_favorites($this->favs(), '3,9999');
        $this->assertSame([3], array_column($out, 'id'));
    }

    public function test_empty_parameter_returns_all_favorites_in_order(): void
    {
        $this->assertSame([3, 7, 11], array_column(board_selected_favorites($this->favs(), ''), 'id'));
    }

    public function test_selection_ignores_non_numeric_input(): void
    {
        $out = board_selected_favorites($this->favs(), "3,';DROP TABLE wl_favorites;--");
        $this->assertSame([3], array_column($out, 'id'));
    }

    public function test_divas_are_collected_and_deduplicated(): void
    {
        // 60200470 kommt in beiden Favoriten vor — die WL-API soll sie einmal
        // bekommen, nicht zweimal.
        $sel = board_selected_favorites($this->favs(), '3,7');
        $this->assertSame('60200103,60200470,60200368', board_all_divas($sel));
    }

    // --- board_battery_percent_from_mv() / board_wifi_bars_from_rssi() -------

    public function test_battery_percent_clamps_at_both_ends_of_the_calibrated_span(): void
    {
        $this->assertSame(100, board_battery_percent_from_mv(BOARD_BATTERY_FULL_MV_DEFAULT));
        $this->assertSame(100, board_battery_percent_from_mv(4500));
        $this->assertSame(0, board_battery_percent_from_mv(BOARD_BATTERY_EMPTY_MV_DEFAULT));
        $this->assertSame(0, board_battery_percent_from_mv(2900));
    }

    public function test_battery_percent_is_linear_at_midpoint(): void
    {
        $mitte = (int) ((BOARD_BATTERY_EMPTY_MV_DEFAULT + BOARD_BATTERY_FULL_MV_DEFAULT) / 2);
        $this->assertSame(50, board_battery_percent_from_mv($mitte));
    }

    public function test_battery_span_is_calibratable(): void
    {
        // Der eigentliche Punkt der Umstellung (Nutzerbefund 2026-09-04):
        // die SPANNE ist die Kalibrierung, nicht ein Schwellwert dahinter.
        $this->assertSame(0,   board_battery_percent_from_mv(3000, 3000, 4000));
        $this->assertSame(50,  board_battery_percent_from_mv(3500, 3000, 4000));
        $this->assertSame(100, board_battery_percent_from_mv(4000, 3000, 4000));
    }

    public function test_an_inverted_span_falls_back_instead_of_dividing_by_zero(): void
    {
        // Die Speicherfunktion laesst das nicht zu -- dieser Wert fliesst
        // aber in ein BILD, und ein Fehler waere am Geraet nicht zu
        // diagnostizieren.
        $this->assertSame(50, board_battery_percent_from_mv(3714, 4000, 4000));
        $this->assertSame(50, board_battery_percent_from_mv(3714, 4200, 3300));
    }

    public function test_wifi_bars_thresholds(): void
    {
        $this->assertSame(3, board_wifi_bars_from_rssi(-50));
        $this->assertSame(2, board_wifi_bars_from_rssi(-65));
        $this->assertSame(1, board_wifi_bars_from_rssi(-75));
        $this->assertSame(0, board_wifi_bars_from_rssi(-90));
    }
}
