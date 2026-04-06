<?php
// tests/Integration/StationsTest.php

namespace WLMonitor\Tests\Integration;

class StationsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // appendLog() uses $_SESSION['id'] with a FK to auth_accounts — provide a real user
        $uid = $this->createUser();
        $_SESSION['id'] = $uid;
    }

    // --- stations_alpha ------------------------------------------------------

    public function test_alpha_returns_array(): void
    {
        $rows = stations_alpha($this->con);
        $this->assertIsArray($rows);
    }

    public function test_alpha_returns_expected_keys(): void
    {
        $rows = stations_alpha($this->con);
        $this->assertNotEmpty($rows, 'ogd_stations view must have rows — run scripts/update_ogd.php first');

        $first = $rows[0];
        $this->assertArrayHasKey('station', $first);
        $this->assertArrayHasKey('diva',    $first);
        $this->assertArrayHasKey('lat',     $first);
        $this->assertArrayHasKey('lon',     $first);
    }

    public function test_alpha_returns_sorted_order(): void
    {
        $rows  = stations_alpha($this->con);
        $names = array_column($rows, 'station');

        // MySQL and PHP sort umlauts differently depending on collation and locale,
        // so we only verify ordering for adjacent pairs that are pure ASCII — where
        // both approaches must agree.
        $checked = 0;
        for ($i = 0; $i < count($names) - 1; $i++) {
            $a = $names[$i];
            $b = $names[$i + 1];
            if (mb_detect_encoding($a, 'ASCII', true) && mb_detect_encoding($b, 'ASCII', true)) {
                $this->assertLessThanOrEqual(
                    0,
                    strcasecmp($a, $b),
                    "Out-of-order: '{$a}' should come before '{$b}'"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked, 'Expected at least 10 ASCII-only station pairs to verify ordering');
    }

    public function test_alpha_diva_is_string(): void
    {
        $rows = stations_alpha($this->con);
        foreach ($rows as $row) {
            $this->assertIsString($row['diva'], "Station '{$row['station']}' diva key must be a string");
        }
    }

    public function test_alpha_lat_lon_are_plausible_coordinates(): void
    {
        // The WL network extends into Lower Austria (e.g. Neulengbach ~15.9°E,
        // Marchegg ~16.9°E), so we use a wide bounding box.
        $rows = stations_alpha($this->con);
        foreach ($rows as $row) {
            $lat = (float) $row['lat'];
            $lon = (float) $row['lon'];
            $this->assertGreaterThan(47.0, $lat, "lat out of range for {$row['station']}");
            $this->assertLessThan(49.0,    $lat, "lat out of range for {$row['station']}");
            $this->assertGreaterThan(15.0, $lon, "lon out of range for {$row['station']}");
            $this->assertLessThan(17.5,    $lon, "lon out of range for {$row['station']}");
        }
    }

    // --- stations_by_distance ------------------------------------------------

    public function test_distance_returns_array(): void
    {
        // Stephansplatz
        $rows = stations_by_distance($this->con, 48.2085, 16.3726);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
    }

    public function test_distance_rows_have_distance_key(): void
    {
        $rows = stations_by_distance($this->con, 48.2085, 16.3726);
        $this->assertArrayHasKey('distance', $rows[0]);
    }

    public function test_distance_sorted_ascending(): void
    {
        $rows      = stations_by_distance($this->con, 48.2085, 16.3726);
        $distances = array_column($rows, 'distance');
        $sorted    = $distances;
        sort($sorted, SORT_NUMERIC);

        $this->assertSame($sorted, $distances, 'Stations must be sorted by distance ascending');
    }

    public function test_distance_nearest_station_within_300m(): void
    {
        // From Stephansplatz there's always a stop within 300 m
        $rows = stations_by_distance($this->con, 48.2085, 16.3726);
        $this->assertLessThanOrEqual(300, $rows[0]['distance']);
    }

    public function test_distance_limit_is_100_rows(): void
    {
        $rows = stations_by_distance($this->con, 48.2085, 16.3726);
        $this->assertLessThanOrEqual(100, count($rows));
    }

    // --- stations_save_position ----------------------------------------------

    public function test_save_position_writes_to_session(): void
    {
        stations_save_position($this->con, 48.2085, 16.3726);

        $this->assertEqualsWithDelta(48.2085, $_SESSION['lat'], 0.0001);
        $this->assertEqualsWithDelta(16.3726, $_SESSION['lon'], 0.0001);
    }
}
