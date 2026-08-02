<?php
// tests/Unit/BoardFilterTest.php
//
// Filtersemantik aus inc/board.php. Bildet web/js/wl-monitor.js:325-330 nach.
// Keine DB, kein Netz.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardFilterTest extends TestCase
{
    /** Eine Linienzeile im Format von monitor_get(). */
    private function line(string $name, string $platform, string $towards): array
    {
        return [
            'name' => $name, 'towards' => $towards, 'type' => 'ptTram',
            'direction' => 'H', 'platform' => $platform, 'barrier_free' => false,
            'realtime_supported' => true, 'trafficjam' => false, 'alert' => false,
            'departures' => [],
        ];
    }

    private function station(string $diva, array $lines): array
    {
        return ['id' => $diva, 'diva' => $diva, 'station_name' => 'Test', 'lines' => $lines];
    }

    // --- board_stations_only -------------------------------------------------

    public function test_stations_only_removes_metadata_keys(): void
    {
        $monitor = [
            '60200103' => $this->station('60200103', []),
            'alerts'    => [], 'trains' => 10, 'update_at' => '17:17:30', 'api_ping' => 0,
        ];
        $out = board_stations_only($monitor);
        $this->assertCount(1, $out);
        $this->assertSame('60200103', $out[0]['diva']);
    }

    public function test_stations_only_returns_a_list_not_a_map(): void
    {
        // Der Client soll nicht raten muessen, welcher Schluessel eine Station ist.
        $monitor = [
            '60200103' => $this->station('60200103', []),
            '60200937' => $this->station('60200937', []),
            'trains'   => 2,
        ];
        $this->assertSame([0, 1], array_keys(board_stations_only($monitor)));
    }

    // --- board_filter_station ------------------------------------------------

    public function test_filter_keeps_only_matching_line_and_platform(): void
    {
        $st = $this->station('60201015', [
            $this->line('62', '1', 'Lainz, Wolkersbergenstraße'),
            $this->line('62', '2', 'Quartier Belvedere S'),
            $this->line('U6', '2', 'Siebenhirten'),
        ]);
        $out = board_filter_station($st, [
            ['line' => '62', 'platform' => '2'],
            ['line' => 'U6', 'platform' => '2'],
        ]);
        $this->assertCount(2, $out['lines']);
        $this->assertSame('Quartier Belvedere S', $out['lines'][0]['towards']);
        $this->assertSame('Siebenhirten', $out['lines'][1]['towards']);
    }

    public function test_filter_matches_platform_as_string_even_if_given_as_int(): void
    {
        // filter_json kann die Plattform als Zahl enthalten; das JS vergleicht
        // ueber String(...) — hier genauso.
        $st = $this->station('1', [$this->line('62', '2', 'Stadt')]);
        $out = board_filter_station($st, [['line' => '62', 'platform' => 2]]);
        $this->assertCount(1, $out['lines']);
    }

    public function test_station_without_filter_entry_keeps_all_lines(): void
    {
        $st = $this->station('1', [
            $this->line('62', '1', 'A'), $this->line('U6', '2', 'B'),
        ]);
        $out = board_filter_station($st, null);
        $this->assertCount(2, $out['lines']);
    }

    public function test_filtered_station_stays_even_when_empty(): void
    {
        // Die einzige gefilterte Linie faehrt gerade nicht. Die Karte MUSS
        // bleiben: verschwaende sie, saehe es aus wie "alles in Ordnung".
        $st  = $this->station('1', [$this->line('62', '1', 'A')]);
        $out = board_filter_station($st, [['line' => 'U6', 'platform' => '2']]);
        $this->assertNotNull($out);
        $this->assertSame([], $out['lines']);
    }

    public function test_unfiltered_station_without_lines_is_dropped(): void
    {
        $this->assertNull(board_filter_station($this->station('1', []), null));
    }

    // --- board_dedupe_lines --------------------------------------------------

    public function test_dedupe_removes_identical_line_platform_towards(): void
    {
        // Real beobachtet am Westbahnhof: U3 Steig 1 Simmering kam doppelt.
        $lines = [
            $this->line('U3', '1', 'Simmering'),
            $this->line('U3', '1', 'Simmering'),
            $this->line('U6', '1', 'Floridsdorf'),
        ];
        $this->assertCount(2, board_dedupe_lines($lines));
    }

    public function test_dedupe_keeps_same_line_on_different_platforms(): void
    {
        $lines = [
            $this->line('U6', '1', 'Floridsdorf'),
            $this->line('U6', '2', 'Siebenhirten'),
        ];
        $this->assertCount(2, board_dedupe_lines($lines));
    }
}
