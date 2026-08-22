<?php
// tests/Unit/WeatherParseStationTest.php
//
// Parser fuer wetter.orf.at/wien/mariabrunn/ (aktuelle Stationsmesswerte,
// nicht die Prognose) -- ersetzt die interne SHT4x-Sensorzeile des Geraets
// durch echte Aussenwerte (Nutzerwunsch 2026-08-22).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class WeatherParseStationTest extends TestCase
{
    private function fixtureHtml(): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/orf_wetter_mariabrunn.html');
    }

    public function test_extracts_all_four_requested_measurements(): void
    {
        $result = weather_parse_station($this->fixtureHtml());

        $this->assertSame(18.4, $result['temp_c']);
        $this->assertSame(11, $result['wind_kmh']);
        $this->assertSame(28, $result['wind_gusts_kmh']);
        $this->assertSame('West', $result['wind_direction']);
        $this->assertSame(65, $result['humidity_pct']);
        $this->assertSame(0.0, $result['precipitation_mm']);
    }

    public function test_throws_when_a_label_is_missing(): void
    {
        $html = str_replace('<span>Temperatur</span>', '<span>Sonstwas</span>', $this->fixtureHtml());

        $this->expectException(RuntimeException::class);
        weather_parse_station($html);
    }

    public function test_throws_when_wind_format_is_unrecognized(): void
    {
        $html = str_replace('West, 11 <abbr', 'komisch <abbr', $this->fixtureHtml());

        $this->expectException(RuntimeException::class);
        weather_parse_station($html);
    }

    public function test_throws_when_gusts_format_is_unrecognized(): void
    {
        $html = str_replace('West, 28 <abbr', 'komisch <abbr', $this->fixtureHtml());

        $this->expectException(RuntimeException::class);
        weather_parse_station($html);
    }
}
