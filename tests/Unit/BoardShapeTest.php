<?php
// tests/Unit/BoardShapeTest.php
//
// Antwortform des Board-Endpunkts. Keine DB, kein Netz.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardShapeTest extends TestCase
{
    private function dep(string $t, bool $jam = false, ?string $tow = null, ?string $name = null): array
    {
        return ['t' => $t, 'bf' => false, 'jam' => $jam,
                'name_override' => $name, 'towards_override' => $tow];
    }

    // --- board_type ----------------------------------------------------------

    public function test_type_is_normalised(): void
    {
        $this->assertSame('metro', board_type('ptMetro'));
        $this->assertSame('tram',  board_type('ptTram'));
        $this->assertSame('tram',  board_type('ptTramWLB'));
        $this->assertSame('bus',   board_type('ptBusCity'));
        $this->assertSame('bus',   board_type('ptBusNight'));
        $this->assertSame('train', board_type('ptTrainS'));
        $this->assertSame('other', board_type(''));
    }

    // --- board_departure -----------------------------------------------------

    public function test_departure_minutes_are_integers(): void
    {
        $this->assertSame(7, board_departure($this->dep('7'))['in']);
    }

    public function test_departure_star_becomes_zero(): void
    {
        // Die alte API kodiert "faehrt jetzt" als String "*".
        $this->assertSame(0, board_departure($this->dep('*'))['in']);
    }

    public function test_departure_without_deviation_has_only_in(): void
    {
        $this->assertSame(['in' => 7], board_departure($this->dep('7')));
    }

    public function test_departure_carries_deviating_destination(): void
    {
        // Kurzgefuehrte U6: die Zeile sagt Siebenhirten, dieser Zug endet in
        // Alterlaa. Ohne dieses Feld waere die Anzeige falsch, nicht nur leer.
        $d = board_departure($this->dep('7', false, 'Alterlaa'));
        $this->assertSame('Alterlaa', $d['towards']);
    }

    public function test_departure_carries_deviating_line_name(): void
    {
        $this->assertSame('6E', board_departure($this->dep('3', false, null, '6E'))['line']);
    }

    public function test_delayed_departure_is_flagged(): void
    {
        // Das Display invertiert genau diese Abfahrt (weiss auf rot).
        $this->assertTrue(board_departure($this->dep('4', true))['delayed']);
    }

    public function test_undelayed_departure_has_no_delayed_key(): void
    {
        $this->assertArrayNotHasKey('delayed', board_departure($this->dep('4', false)));
    }

    // --- board_line ----------------------------------------------------------

    public function test_line_drops_unused_fields(): void
    {
        $line = ['name' => 'U6', 'towards' => 'Siebenhirten', 'type' => 'ptMetro',
                 'direction' => 'H', 'platform' => '2', 'barrier_free' => true,
                 'realtime_supported' => true, 'trafficjam' => false, 'alert' => false,
                 'departures' => [$this->dep('2')]];
        $out = board_line($line);
        $this->assertSame(
            ['line', 'platform', 'towards', 'type', 'realtime', 'alert', 'departures'],
            array_keys($out)
        );
        $this->assertArrayNotHasKey('direction', $out);
        $this->assertArrayNotHasKey('barrier_free', $out);
    }

    public function test_line_reports_missing_realtime(): void
    {
        $line = ['name' => '8A', 'towards' => 'X', 'type' => 'ptBusCity', 'platform' => '1',
                 'realtime_supported' => false, 'alert' => false, 'departures' => []];
        $this->assertFalse(board_line($line)['realtime']);
    }

    // --- board_favorite ------------------------------------------------------

    public function test_favorite_shapes_stations_as_list(): void
    {
        $monitor = [
            '60201015' => ['id' => '60201015', 'diva' => '60201015',
                'station_name' => 'Bhf. Meidling S U', 'lines' => [
                    ['name' => 'U6', 'towards' => 'Siebenhirten', 'type' => 'ptMetro',
                     'platform' => '2', 'realtime_supported' => true, 'alert' => false,
                     'departures' => [$this->dep('2'), $this->dep('7', false, 'Alterlaa')]],
                    ['name' => '62', 'towards' => 'Lainz', 'type' => 'ptTram',
                     'platform' => '1', 'realtime_supported' => true, 'alert' => false,
                     'departures' => [$this->dep('3')]],
                ]],
            'trains' => 3, 'update_at' => '17:17:30', 'api_ping' => 0, 'alerts' => [],
        ];
        $fav = ['id' => 12, 'title' => 'Arbeit', 'diva' => '60201015',
                'filter' => ['60201015' => [['line' => 'U6', 'platform' => '2']]]];

        $out = board_favorite($fav, $monitor);

        $this->assertSame(12, $out['id']);
        $this->assertSame('Arbeit', $out['title']);
        $this->assertCount(1, $out['stations']);
        $this->assertSame('Bhf. Meidling S U', $out['stations'][0]['name']);
        $this->assertCount(1, $out['stations'][0]['lines']);       // 62 herausgefiltert
        $this->assertSame('U6', $out['stations'][0]['lines'][0]['line']);
        $this->assertSame(
            [['in' => 2], ['in' => 7, 'towards' => 'Alterlaa']],
            $out['stations'][0]['lines'][0]['departures']
        );
    }

    public function test_favorite_without_filter_keeps_every_line(): void
    {
        $monitor = ['1' => ['id' => '1', 'diva' => '1', 'station_name' => 'X', 'lines' => [
            ['name' => 'A', 'towards' => 'T', 'type' => 'ptTram', 'platform' => '1',
             'realtime_supported' => true, 'alert' => false, 'departures' => []],
        ]]];
        $fav = ['id' => 1, 'title' => 'Alles', 'diva' => '1', 'filter' => null];
        $this->assertCount(1, board_favorite($fav, $monitor)['stations'][0]['lines']);
    }

    /**
     * B1 (Review 2026-08-02): web/board.php ruft monitor_get() EINMAL mit der
     * Vereinigung der DIVAs ALLER gewaehlten Favoriten auf und reicht diese
     * gemeinsame Map an jeden board_favorite()-Aufruf weiter. Ein Favorit darf
     * daraus nur SEINE EIGENEN Stationen ziehen — fremde Stationen (hier '222',
     * die zu einem anderen Favoriten gehoert) duerfen nicht erscheinen, auch
     * wenn sie in der Map stehen.
     */
    public function test_favorite_shows_only_its_own_stations_not_every_station_in_the_shared_monitor(): void
    {
        $line = static fn (string $name) => ['name' => $name, 'towards' => 'Z', 'type' => 'ptTram',
            'platform' => '1', 'realtime_supported' => true, 'alert' => false, 'departures' => []];
        $monitor = [
            '111' => ['id' => '111', 'diva' => '111', 'station_name' => 'Halt 111', 'lines' => [$line('L111')]],
            '222' => ['id' => '222', 'diva' => '222', 'station_name' => 'Halt 222', 'lines' => [$line('L222')]],
        ];
        $fav = ['id' => 1, 'title' => 'A', 'diva' => '111',
                'filter' => ['111' => [['line' => 'L111', 'platform' => '1']]]];

        $out = board_favorite($fav, $monitor);

        $this->assertSame(['111'], array_column($out['stations'], 'diva'));
    }
}
