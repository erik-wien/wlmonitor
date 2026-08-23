<?php
// tests/Unit/BoardRenderSleepSlotTest.php
//
// Der Schlafschirm ist seit 2026-08-23 strukturell die LETZTE Seite von
// board_render_svg() (Nutzerwunsch: "damit ich den Schirm auch absichtlich
// aufrufen kann"), nicht mehr nur ueber einen separaten Geraete-Header
// erreichbar. Diese Tests decken board_render_svg()/board_total_pages()
// als reine Funktionen ab -- die serverseitige Persistenz-Falle (Header
// darf den gespeicherten Blaetter-Zustand nicht verschieben) hat einen
// eigenen Integrationstest in BoardTokenEndpointTest.php.

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardRenderSleepSlotTest extends TestCase
{
    private function favorite(): array
    {
        return ['id' => 1, 'title' => 'Test', 'stations' => [
            ['diva' => '1', 'name' => 'Station', 'lines' => [
                ['line' => '1', 'platform' => '1', 'towards' => 'Ziel', 'type' => 'bus',
                    'realtime' => true, 'alert' => false, 'departures' => [['in' => 5]]],
            ]],
        ]];
    }

    private function weather(): array
    {
        return ['available' => true, 'icon_category' => 'klar', 'temp_min' => 15, 'temp_max' => 25,
            'text' => 'Sonnig.', 'text_error' => null];
    }

    private function sleepWeather(): array
    {
        return [
            'today' => ['available' => true, 'icon_category' => 'klar', 'temp_min' => 15, 'temp_max' => 25, 'text' => 'Sonnig.', 'text_error' => null],
            'tomorrow' => ['available' => true, 'icon_category' => 'bewoelkt', 'temp_min' => 12, 'temp_max' => 20, 'text' => 'Bewoelkt.', 'text_error' => null],
        ];
    }

    public function test_board_total_pages_adds_one_slot_for_sleep(): void
    {
        $this->assertSame(2, board_total_pages(1, false), '1 Abfahrtenseite + Schlafschirm');
        $this->assertSame(3, board_total_pages(1, true), '1 Abfahrtenseite + Stoerungen + Schlafschirm');
        $this->assertSame(5, board_total_pages(3, true));
    }

    public function test_last_page_renders_the_sleep_screen_without_chrome(): void
    {
        // 1 Favorit ohne genug Zeilen fuer eine zweite Seite -> totalPages=2
        // (Abfahrten + Schlafschirm). Seite 2 muss der Schlafschirm sein.
        $svg = board_render_svg(
            ['Test'], 0, $this->favorite(), [], 2,
            $this->weather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, $this->sleepWeather(), null
        );

        $this->assertStringContainsString('>Heute<', $svg);
        $this->assertStringContainsString('>Morgen<', $svg);
        // Chrome/Touch-Leiste des regulaeren Boards duerfen NICHT auftauchen --
        // der Schlafschirm ist ein eigenes, vollstaendiges Layout. Die Touch-
        // Leiste rendert den Favoritentitel als >Test< in einer schwarzen
        // Pille (board_render_touch_bar_svg()) -- das darf hier nicht stehen.
        $this->assertStringNotContainsString('>Test<', $svg, 'Favoriten-Touch-Leiste darf nicht gerendert werden');
        $this->assertStringNotContainsString('STATION', $svg, 'Stationskopf der Abfahrtenliste darf nicht gerendert werden');
    }

    public function test_requesting_a_page_past_total_pages_clamps_to_the_sleep_slot(): void
    {
        $svgClamped = board_render_svg(
            ['Test'], 0, $this->favorite(), [], 999,
            $this->weather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, $this->sleepWeather(), null
        );
        $svgExact = board_render_svg(
            ['Test'], 0, $this->favorite(), [], 2,
            $this->weather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, $this->sleepWeather(), null
        );

        $this->assertSame($svgExact, $svgClamped, 'eine zu hohe Seitenzahl muss auf den letzten Slot klemmen, nicht ins Leere greifen');
    }

    public function test_departures_page_still_renders_normally(): void
    {
        // Seite 1 von 2 ist weiterhin die normale Abfahrtenseite mit Chrome.
        $svg = board_render_svg(
            ['Test'], 0, $this->favorite(), [], 1,
            $this->weather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, $this->sleepWeather(), null
        );

        $this->assertStringContainsString('>Test<', $svg, 'Touch-Leiste mit dem Favoritentitel gehoert auf die Abfahrtenseite');
        $this->assertStringContainsString('STATION', $svg);
        $this->assertStringNotContainsString('Bewoelkt.', $svg, 'Morgen-Text gehoert nur auf den Schlafschirm');
    }

    public function test_missing_sleep_weather_falls_back_to_unavailable_instead_of_crashing(): void
    {
        // Verteidigt gegen einen Aufrufer, der $sleepWeather vergisst -- der
        // Slot existiert strukturell immer, sobald totalPages ihn erreicht.
        $svg = board_render_svg(
            ['Test'], 0, $this->favorite(), [], 2,
            $this->weather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );

        $this->assertStringContainsString('Wetterdaten werden geladen', $svg);
    }
}
