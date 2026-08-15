<?php
// tests/Unit/WeatherParseTest.php
//
// Parser fuer wetter.orf.at/wien/prognose (DESKTOP-Markup: weatherIcon-Spans,
// nicht img/svg). Positionale Auswahl (1./2. Spalte, 1./2. Textblock), nicht
// ueber Ueberschriftentext -- siehe Spec §8.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class WeatherParseTest extends TestCase
{
    private function fixtureHtml(): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/orf_wetter_wien.html');
    }

    public function test_extracts_today_and_tomorrow_from_hohe_warte(): void
    {
        $result = weather_parse_forecast($this->fixtureHtml());

        $this->assertSame('100000', $result['today']['icon_code']);
        $this->assertSame(18, $result['today']['temp_min']);
        $this->assertSame(35, $result['today']['temp_max']);
        $this->assertStringContainsString('scheint die Sonne', $result['today']['text']);

        $this->assertSame('100000', $result['tomorrow']['icon_code']);
        $this->assertSame(22, $result['tomorrow']['temp_min']);
        $this->assertSame(37, $result['tomorrow']['temp_max']);
        $this->assertStringContainsString('Hitze steigert sich', $result['tomorrow']['text']);
    }

    public function test_reads_hohe_warte_not_innere_stadt(): void
    {
        // Innere Stadt hat am Tag 1 den Code 110000, Hohe Warte 100000.
        // Kaeme 110000 zurueck, laese der Parser die falsche Tabelle.
        $result = weather_parse_forecast($this->fixtureHtml());
        $this->assertSame('100000', $result['today']['icon_code']);
    }

    public function test_missing_morning_temp_falls_back_to_max(): void
    {
        // ORF laesst die Tages-Tiefsttemperatur beim laufenden Tag manchmal weg.
        $html = preg_replace('/<span class="morning">.*?<\/span>/s', '', $this->fixtureHtml(), 1);
        $result = weather_parse_forecast($html);
        $this->assertSame(35, $result['today']['temp_min']); // == temp_max
        $this->assertSame(35, $result['today']['temp_max']);
    }

    public function test_throws_when_hohe_warte_table_is_missing(): void
    {
        $broken = str_replace('Wien-Hohe Warte', 'Wien-Nirgendwo', $this->fixtureHtml());
        $this->expectException(RuntimeException::class);
        weather_parse_forecast($broken);
    }
}
