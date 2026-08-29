<?php
// tests/Unit/BoardTemplateLayoutTest.php
//
// Cursor-Layout + Pagination aus Spec (Stand 2026-08-16). Fixture-Werte
// fuer den Einzelseiten-Fall sind die im Chat abgenommenen Pixelwerte des
// Favoriten "Westbahnhof"; der Mehrseiten-Fall nutzt eine synthetische
// Fixture mit genug Zeilen, um BOARD_DEPARTURES_MAX_Y sicher zu ueberschreiten.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateLayoutTest extends TestCase
{
    private function line(string $name, string $platform, string $towards, string $type, bool $realtime, array $departures): array
    {
        return ['line' => $name, 'platform' => $platform, 'towards' => $towards, 'type' => $type, 'realtime' => $realtime, 'alert' => false, 'departures' => $departures];
    }

    private function favorite(int $id, string $title, array $stations): array
    {
        return ['id' => $id, 'title' => $title, 'stations' => $stations];
    }

    public function test_single_page_favorite_matches_approved_mockup(): void
    {
        $favorite = $this->favorite(225, 'Westbahnhof', [
            ['diva' => '60201468', 'name' => 'Westbahnhof S U', 'lines' => [
                $this->line('18', '1', 'Schlachthausgasse U', 'tram', true, [['in' => 7], ['in' => 22]]),
                $this->line('6', '1', 'Geiereckstraße', 'tram', true, [['in' => 1], ['in' => 14]]),
                $this->line('9', '2', 'Gersthof S', 'tram', false, [['in' => 9], ['in' => 16]]),
                $this->line('U3', '1', 'Simmering', 'metro', true, [['in' => 0, 'delayed' => true], ['in' => 8]]),
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 0], ['in' => 6]]),
                $this->line('U6', '2', 'Siebenhirten', 'metro', true, [['in' => 5], ['in' => 12]]),
            ]],
        ]);

        $result = board_paginate_departures($favorite, 1);

        $this->assertSame(1, $result['totalPages']);
        $items = $result['items'];
        $this->assertSame(['type' => 'header', 'y' => 196, 'text' => 'WESTBAHNHOF S U'], $items[0]);
        $this->assertSame(259, $items[1]['r']);
        $this->assertSame(355, $items[2]['r'], '96px Zeilenraster');
        $this->assertSame('gray', $items[3]['style'], 'Linie 9 hat realtime=false');
        $this->assertSame('delayed', $items[4]['style'], 'Linie U3 hat delayed=true');
        $this->assertSame('normal', $items[5]['style']);

        // Anfrage ueber die letzte Seite hinaus klemmt auf Seite 1.
        $this->assertSame($result['items'], board_paginate_departures($favorite, 99)['items']);
    }

    public function test_overflowing_content_splits_across_pages_without_losing_rows(): void
    {
        // 3 Stationen a 4 Zeilen = 12 Zeilen -- deutlich mehr, als vor
        // BOARD_DEPARTURES_MAX_Y (1250) auf eine Seite passt (Seite 1 endet
        // rechnerisch bei Zeile ~6, s. test_single_page_favorite_...).
        $manyLines = array_map(
            fn (int $i) => $this->line((string) $i, '1', "Ziel $i", 'bus', true, [['in' => $i], ['in' => $i + 10]]),
            range(1, 4)
        );
        $favorite = $this->favorite(1, 'Viele Zeilen', [
            ['diva' => '1', 'name' => 'Station A', 'lines' => $manyLines],
            ['diva' => '2', 'name' => 'Station B', 'lines' => $manyLines],
            ['diva' => '3', 'name' => 'Station C', 'lines' => $manyLines],
        ]);

        $page1 = board_paginate_departures($favorite, 1);
        $this->assertGreaterThan(1, $page1['totalPages'], 'muss auf mehrere Seiten umbrechen');

        $totalRowsAcrossAllPages = 0;
        for ($p = 1; $p <= $page1['totalPages']; $p++) {
            $result = board_paginate_departures($favorite, $p);
            $this->assertSame($page1['totalPages'], $result['totalPages'], 'totalPages ist seitenunabhaengig konstant');

            foreach ($result['items'] as $item) {
                if ($item['type'] === 'row') {
                    $totalRowsAcrossAllPages++;
                }
                if ($item['type'] === 'row') {
                    $this->assertLessThanOrEqual(BOARD_DEPARTURES_MAX_Y, $item['r'] + 34, "Zeile auf Seite $p ueberschreitet die Seitengrenze");
                }
            }
        }
        $this->assertSame(12, $totalRowsAcrossAllPages, 'keine Zeile darf verloren gehen oder sich verdoppeln');
    }

    public function test_station_continuation_header_marks_forts(): void
    {
        // 15 Zeilen in EINER Station: rechnerisch passen 10 auf Seite 1
        // (r=259 + 96px-Raster bis r=1123, +34 Badge-Radius = 1157 <=
        // BOARD_DEPARTURES_MAX_Y=1250; Zeile 11 waere bei r=1219+34=1253,
        // also ueber dem Limit) -- 15 stellt den Umbruch MITTEN in der
        // Station sicher, statt knapp an der Grenze zu balancieren.
        $manyLines = array_map(
            fn (int $i) => $this->line((string) $i, '1', "Ziel $i", 'bus', true, [['in' => $i]]),
            range(1, 15)
        );
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Grosse Station', 'lines' => $manyLines],
        ]);

        $page1 = board_paginate_departures($favorite, 1);
        $this->assertGreaterThan(1, $page1['totalPages']);

        $page2 = board_paginate_departures($favorite, 2);
        $header = $page2['items'][0];
        $this->assertSame('header', $header['type']);
        $this->assertStringContainsString('(FORTS.)', $header['text']);
        $this->assertStringContainsString('GROSSE STATION', $header['text']);
    }

    public function test_missing_departures_become_null(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Teststation', 'lines' => [
                $this->line('1', '1', 'Nirgendwo', 'bus', true, []),
            ]],
        ]);

        $items = board_paginate_departures($favorite, 1)['items'];
        $this->assertNull($items[1]['live_in']);
        $this->assertNull($items[1]['secondary_in']);
    }

    public function test_station_without_lines_is_skipped_entirely(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Leer', 'lines' => []],
            ['diva' => '2', 'name' => 'Voll', 'lines' => [
                $this->line('1', '1', 'Ziel', 'bus', true, [['in' => 1]]),
            ]],
        ]);

        $items = board_paginate_departures($favorite, 1)['items'];
        $this->assertCount(2, $items);
        $this->assertSame('VOLL', $items[0]['text']);
    }

    public function test_rows_are_flagged_disrupted_only_when_their_line_has_an_alert(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Station', 'lines' => [
                $this->line('U1', '1', 'Leopoldau', 'metro', true, [['in' => 2]]),
                $this->line('59A', '1', 'Schönbrunn', 'bus', true, [['in' => 5]]),
            ]],
        ]);
        $filteredAlerts = [
            ['title' => 'Störung', 'description' => '…', 'priority' => 'high', 'lines' => ['U1'], 'stops' => []],
        ];

        $items = board_paginate_departures($favorite, 1, $filteredAlerts)['items'];

        $this->assertTrue($items[1]['disrupted'], 'U1 hat einen passenden Alert');
        $this->assertFalse($items[2]['disrupted'], '59A hat keinen Alert');
    }

    public function test_rows_default_to_not_disrupted_without_alerts_argument(): void
    {
        $favorite = $this->favorite(1, 'x', [
            ['diva' => '1', 'name' => 'Station', 'lines' => [
                $this->line('U1', '1', 'Leopoldau', 'metro', true, [['in' => 2]]),
            ]],
        ]);

        $items = board_paginate_departures($favorite, 1)['items'];
        $this->assertFalse($items[1]['disrupted']);
    }
}
