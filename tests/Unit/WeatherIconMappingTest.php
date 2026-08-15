<?php
// tests/Unit/WeatherIconMappingTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WeatherIconMappingTest extends TestCase
{
    public function test_known_code_maps_to_category(): void
    {
        $result = weather_map_icon_code('100000');
        $this->assertSame('klar', $result['category']);
        $this->assertTrue($result['known']);
    }

    public function test_precipitation_code_maps_to_regen(): void
    {
        $this->assertSame('regen_leicht', weather_map_icon_code('112000')['category']);
        $this->assertSame('regen_stark', weather_map_icon_code('122000')['category']);
        $this->assertSame('gewitter', weather_map_icon_code('122001')['category']);
    }

    public function test_unknown_code_falls_back_to_unbekannt(): void
    {
        $result = weather_map_icon_code('999999');
        $this->assertSame('unbekannt', $result['category']);
        $this->assertFalse($result['known']);
    }
}
