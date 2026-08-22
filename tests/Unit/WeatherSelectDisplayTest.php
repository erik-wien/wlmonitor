<?php
// tests/Unit/WeatherSelectDisplayTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class WeatherSelectDisplayTest extends TestCase
{
    private function cache(string $fetchedAt): array
    {
        return [
            'fetched_at' => $fetchedAt,
            'today' => ['icon_code' => '100000', 'temp_min' => 18, 'temp_max' => 35, 'text' => 'Heute-Text'],
            'tomorrow' => ['icon_code' => '110000', 'temp_min' => 22, 'temp_max' => 37, 'text' => 'Morgen-Text'],
        ];
    }

    public function test_no_cache_is_unavailable(): void
    {
        $result = weather_select_display(null, new DateTimeImmutable('2026-08-15T12:00:00+02:00'));
        $this->assertFalse($result['available']);
    }

    public function test_before_1900_uses_today(): void
    {
        // fetched_at 15:00, now 18:59 -> 3h59m alt, NICHT stale -> Text da.
        $result = weather_select_display(
            $this->cache('2026-08-15T15:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T18:59:00+02:00')
        );
        $this->assertSame('klar', $result['icon_category']);
        $this->assertSame(18, $result['temp_min']);
        $this->assertSame('Heute-Text', $result['text']);
        $this->assertNull($result['text_error']);
    }

    public function test_at_1900_uses_tomorrow(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T18:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T19:00:00+02:00')
        );
        $this->assertSame('leicht_bewoelkt', $result['icon_category']);
        $this->assertSame(22, $result['temp_min']);
        $this->assertSame('Morgen-Text', $result['text']);
    }

    public function test_cache_older_than_6h_replaces_text_with_error(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:01+02:00') // 6h 0min 1s alt
        );
        $this->assertNull($result['text']);
        $this->assertStringContainsString('06:00', $result['text_error']);
        // Icon/Temperatur bleiben unveraendert stehen (Spec §8):
        $this->assertSame(18, $result['temp_min']);
        $this->assertSame('klar', $result['icon_category']);
    }

    public function test_cache_exactly_6h_old_is_not_yet_stale(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:00+02:00') // exakt 6h
        );
        $this->assertSame('Heute-Text', $result['text']);
        $this->assertNull($result['text_error']);
    }

    public function test_utc_input_is_converted_to_vienna_for_cutover(): void
    {
        // 17:30 UTC = 19:30 Wien -> tomorrow.
        $result = weather_select_display(
            $this->cache('2026-08-15T15:00:00+00:00'),
            new DateTimeImmutable('2026-08-15T17:30:00+00:00')
        );
        $this->assertSame('Morgen-Text', $result['text']);
    }

    // --- Stations-Messwerte (Mariabrunn, 2026-08-22) --------------------------
    // Eigene fetched_at, unabhaengig von der Prognose (s. scripts/
    // weather_fetch_cron.php -- beide Quellen koennen unabhaengig scheitern).

    private function cacheWithStation(string $fetchedAt, string $stationFetchedAt): array
    {
        return array_merge($this->cache($fetchedAt), [
            'station_fetched_at' => $stationFetchedAt,
            'station' => ['temp_c' => 21.3, 'humidity_pct' => 47, 'wind_kmh' => 11, 'wind_direction' => 'West', 'precipitation_mm' => 0.0],
        ]);
    }

    public function test_no_cache_has_unavailable_station_too(): void
    {
        $result = weather_select_display(null, new DateTimeImmutable('2026-08-15T12:00:00+02:00'));
        $this->assertFalse($result['station']['available']);
    }

    public function test_cache_without_station_keys_has_unavailable_station(): void
    {
        // Alte Cache-Datei von vor 2026-08-22 hat noch keine station-Felder.
        $result = weather_select_display($this->cache('2026-08-15T15:00:00+02:00'), new DateTimeImmutable('2026-08-15T15:01:00+02:00'));
        $this->assertFalse($result['station']['available']);
    }

    public function test_fresh_station_data_is_available(): void
    {
        $result = weather_select_display(
            $this->cacheWithStation('2026-08-15T15:00:00+02:00', '2026-08-15T15:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T15:01:00+02:00')
        );
        $this->assertTrue($result['station']['available']);
        $this->assertSame(21.3, $result['station']['temp_c']);
        $this->assertSame(47, $result['station']['humidity_pct']);
        $this->assertSame(11, $result['station']['wind_kmh']);
        $this->assertSame('West', $result['station']['wind_direction']);
        $this->assertSame(0.0, $result['station']['precipitation_mm']);
    }

    public function test_station_data_older_than_6h_is_unavailable(): void
    {
        // Eigene Alterspruefung, unabhaengig von einer noch frischen Prognose.
        $result = weather_select_display(
            $this->cacheWithStation('2026-08-15T15:00:00+02:00', '2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:01+02:00') // Station 6h 0min 1s alt
        );
        $this->assertFalse($result['station']['available']);
    }

    public function test_station_data_exactly_6h_old_is_not_yet_stale(): void
    {
        $result = weather_select_display(
            $this->cacheWithStation('2026-08-15T15:00:00+02:00', '2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:00+02:00') // exakt 6h
        );
        $this->assertTrue($result['station']['available']);
    }
}
