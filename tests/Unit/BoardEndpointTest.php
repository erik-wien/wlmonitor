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
}
