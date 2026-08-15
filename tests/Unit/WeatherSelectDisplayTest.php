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
}
