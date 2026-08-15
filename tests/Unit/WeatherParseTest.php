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
        // Scope auf den ersten "morning"-Span NACH "Wien-Hohe Warte", damit der Test
        // unabhaengig von der Reihenfolge der Regionsbloecke im Fixture bleibt.
        $html = preg_replace('/(Wien-Hohe Warte.*?)<span class="morning">.*?<\/span>/s', '$1', $this->fixtureHtml(), 1);
        $result = weather_parse_forecast($html);
        $this->assertSame(35, $result['today']['temp_min']); // == temp_max
        $this->assertSame(35, $result['today']['temp_max']);
    }

    public function test_negative_temperature_keeps_minus_sign(): void
    {
        // "-2" darf beim Ziffern-Extrahieren nicht zu "2" werden (\D matcht auch "-").
        $html = preg_replace(
            '/(<span class="morning">)18(&thinsp;)/',
            '${1}-2${2}',
            $this->fixtureHtml(),
            1
        );
        $result = weather_parse_forecast($html);
        $this->assertSame(-2, $result['today']['temp_min']);
    }

    public function test_throws_when_hohe_warte_table_is_missing(): void
    {
        $broken = str_replace('Wien-Hohe Warte', 'Wien-Nirgendwo', $this->fixtureHtml());
        $this->expectException(RuntimeException::class);
        weather_parse_forecast($broken);
    }

    public function test_ignores_malformed_third_column(): void
    {
        // Die echte Seite hat 5 Tages-Spalten, genutzt werden aber nur die
        // ersten zwei (heute/morgen). Kaputtes Markup in Spalte 3 darf den
        // Abruf nicht mehr scheitern lassen.
        $html = $this->fixtureHtml();

        $html = preg_replace(
            '/(<span class="offscreen">Prognose für Wien-Hohe Warte<\/span><\/th>.*?<\/td>)\s*<\/tr>/s',
            '$1<td><div class="iconRow temperatureRow">kaputt</div></td></tr>',
            $html,
            1
        );
        $html = preg_replace(
            '/(<span class="offscreen">Temperatur für <\/span>Wien-Hohe Warte<\/th>.*?<\/td>)\s*<\/tr>/s',
            '$1<td>kaputt</td></tr>',
            $html,
            1
        );

        $result = weather_parse_forecast($html);

        $this->assertSame('100000', $result['today']['icon_code']);
        $this->assertSame(18, $result['today']['temp_min']);
        $this->assertSame(35, $result['today']['temp_max']);
        $this->assertSame('100000', $result['tomorrow']['icon_code']);
        $this->assertSame(22, $result['tomorrow']['temp_min']);
        $this->assertSame(37, $result['tomorrow']['temp_max']);
    }
}
