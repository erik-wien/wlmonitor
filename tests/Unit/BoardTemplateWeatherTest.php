<?php
// tests/Unit/BoardTemplateWeatherTest.php
//
// Wetterkarte aus Spec §9: Icon-Auswahl, Temperaturformat, manueller
// Zeilenumbruch (SVG kann <text> nicht selbst umbrechen).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateWeatherTest extends TestCase
{
    // --- board_wrap_text -----------------------------------------------------

    public function test_wrap_text_breaks_at_word_boundaries_within_limit(): void
    {
        $lines = board_wrap_text('Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.', 37);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(37, mb_strlen($line, 'UTF-8'), "Zeile \"$line\" ueberschreitet das Limit");
        }
        $this->assertSame(
            'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            implode(' ', $lines),
            'kein Wort darf verloren gehen oder sich verdoppeln'
        );
    }

    public function test_wrap_text_single_short_line_stays_one_line(): void
    {
        $this->assertSame(['Kurzer Text.'], board_wrap_text('Kurzer Text.', 37));
    }

    public function test_wrap_text_word_longer_than_limit_stays_on_its_own_line(): void
    {
        // Kein Silbentrennen -- ein zu langes Wort ragt lieber ueber das
        // Limit hinaus, als dass die Funktion es zerhackt.
        $longWord = str_repeat('a', 50);
        $lines = board_wrap_text($longWord, 37);
        $this->assertSame([$longWord], $lines);
    }

    // --- board_render_weather_svg ---------------------------------------------

    private function weatherFixture(array $overrides = []): array
    {
        return array_merge([
            'available' => true,
            'icon_category' => 'klar',
            'temp_min' => 18,
            'temp_max' => 35,
            'text' => 'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            'text_error' => null,
        ], $overrides);
    }

    public function test_weather_svg_uses_correct_icon_and_temperature(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringContainsString('#icon_klar', $svg);
        $this->assertStringContainsString('18° – 35°C', $svg);
        $this->assertStringContainsString('>Heute<', $svg);
    }

    public function test_weather_svg_wraps_long_text_into_multiple_text_elements(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture());

        // Bei 37 Zeichen/Zeile braucht der Fixture-Text mehr als eine Zeile.
        $this->assertGreaterThan(1, substr_count($svg, 'font-size="46"'));
    }

    public function test_weather_svg_shows_stale_error_instead_of_text_but_keeps_icon_and_temp(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture([
            'text' => null,
            'text_error' => 'Wetterbericht veraltet seit 14:00',
        ]));

        $this->assertStringContainsString('#icon_klar', $svg, 'Icon bleibt bei veraltetem Text unveraendert (Spec §8)');
        $this->assertStringContainsString('18° – 35°C', $svg, 'Temperatur bleibt bei veraltetem Text unveraendert (Spec §8)');
        // Bricht bei 31 Zeichen/Zeile (2026-08-22, groesserer Font) auf zwei
        // Zeilen um statt in einer zu stehen.
        $this->assertStringContainsString('Wetterbericht veraltet seit', $svg);
        $this->assertStringContainsString('14:00', $svg);
        $this->assertStringNotContainsString('Von früh bis spät', $svg);
    }

    public function test_weather_svg_shows_fallback_when_never_fetched(): void
    {
        $svg = board_render_weather_svg(['available' => false]);

        $this->assertStringContainsString('#icon_unbekannt', $svg);
        $this->assertStringNotContainsString('°C', $svg, 'ohne Daten keine erfundene Temperatur');
        $this->assertStringContainsString('Wetterdaten werden geladen', $svg);
    }

    // --- Geraete-Sensorzeile + Statuszeile (2026-08-22) -----------------------

    public function test_weather_svg_shows_device_sensor_line_when_provided(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(), 21.3, 47);

        $this->assertStringContainsString('21.3°C • 47% Rel.LF', $svg);
    }

    public function test_weather_svg_omits_sensor_line_when_not_provided(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringNotContainsString('Rel.LF', $svg);
    }

    public function test_weather_svg_omits_sensor_line_when_only_one_value_present(): void
    {
        // Kaputter Sensor liefert nie nur einen der beiden Werte (SHT4x
        // beantwortet eine Messung immer mit Temperatur+Feuchte zusammen,
        // s. readAmbientConditions() in epaper-monitor/src/sensor.cpp) --
        // trotzdem defensiv: nur mit BEIDEN Werten rendern.
        $svg = board_render_weather_svg($this->weatherFixture(), 21.3, null);

        $this->assertStringNotContainsString('Rel.LF', $svg);
    }

    public function test_weather_svg_always_shows_idle_status_text(): void
    {
        // Statuszeile ist server-seitig IMMER "Warte auf Eingabe" -- ein
        // ausgeliefertes Bild wird per Definition angezeigt, WAEHREND das
        // Geraet auf die naechste Eingabe wartet. "Hole Daten…"/"Schlafe"
        // zeichnet die Firmware lokal darueber (showStatusOverlay() in
        // epaper-monitor/src/display.cpp), s. BOARD_STATUS_IDLE_TEXT.
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringContainsString('>Warte auf Eingabe<', $svg);
    }

    public function test_weather_svg_shows_idle_status_even_when_never_fetched(): void
    {
        $svg = board_render_weather_svg(['available' => false]);

        $this->assertStringContainsString('>Warte auf Eingabe<', $svg);
    }
}
