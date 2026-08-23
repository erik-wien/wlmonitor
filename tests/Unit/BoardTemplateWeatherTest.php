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
        // Ohne Stationsdaten nur der Prognosebereich, kein fetter Praefix.
        $this->assertStringContainsString('>18–35°C</text>', $svg);
        $this->assertStringContainsString('>Heute<', $svg);
    }

    public function test_heading_shows_morgen_when_the_selected_period_is_tomorrow(): void
    {
        // Regressionsschutz 2026-08-23: weather_select_display() zeigt ab
        // 19:00 die Morgen-Prognose -- die Ueberschrift muss das nachziehen.
        $svg = board_render_weather_svg($this->weatherFixture(['period' => 'tomorrow']));

        $this->assertStringContainsString('>Morgen<', $svg);
        $this->assertStringNotContainsString('>Heute<', $svg);
    }

    public function test_heading_defaults_to_heute_without_a_period_key(): void
    {
        // Alte Fixtures/Aufrufe ohne 'period' duerfen sich nicht aendern.
        $svg = board_render_weather_svg($this->weatherFixture());
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
        $this->assertStringContainsString('>18–35°C</text>', $svg, 'Temperatur bleibt bei veraltetem Text unveraendert (Spec §8)');
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

    // --- Stations-Messwerte (Mariabrunn) + Statuszeile (2026-08-22) -----------

    /** @return array{available: bool, temp_c?: float, humidity_pct?: int, wind_kmh?: int, wind_gusts_kmh?: int, wind_direction?: string, precipitation_mm?: float} */
    private function stationFixture(array $overrides = []): array
    {
        return array_merge([
            'available' => true,
            'temp_c' => 21.3,
            'humidity_pct' => 47,
            'wind_kmh' => 11,
            'wind_gusts_kmh' => 28,
            'wind_direction' => 'West',
            'precipitation_mm' => 0.0,
        ], $overrides);
    }

    public function test_weather_svg_shows_station_measurements_when_available(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(['station' => $this->stationFixture()]));

        $this->assertStringContainsString('<tspan font-weight="bold">21.3°</tspan> 18–35°C', $svg, 'Mariabrunn-Temp fett, Prognosebereich normal');
        $this->assertStringContainsString('>47%</text>', $svg);
        $this->assertStringContainsString('>11–28 km/h</text>', $svg);
        $this->assertStringContainsString('#iconTemp', $svg);
        $this->assertStringContainsString('#iconDroplet', $svg);
        $this->assertStringContainsString('#iconWind', $svg);
    }

    public function test_weather_svg_omits_precipitation_row_when_zero(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(['station' => $this->stationFixture(['precipitation_mm' => 0.0])]));

        $this->assertStringNotContainsString('#iconDroplets', $svg);
        $this->assertStringNotContainsString('mm/h', $svg);
    }

    public function test_weather_svg_shows_precipitation_row_when_present(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(['station' => $this->stationFixture(['precipitation_mm' => 1.5])]));

        $this->assertStringContainsString('#iconDroplets', $svg);
        $this->assertStringContainsString('>1.5 mm/h</text>', $svg);
    }

    public function test_weather_svg_omits_station_rows_when_unavailable(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(['station' => ['available' => false]]));

        $this->assertStringNotContainsString('#iconDroplet', $svg);
        $this->assertStringNotContainsString('#iconWind', $svg);
        $this->assertStringNotContainsString('#iconDroplets', $svg);
        // Prognose-Temp-Zeile mit iconTemp bleibt, nur ohne fetten Praefix.
        $this->assertStringContainsString('#iconTemp', $svg);
        $this->assertStringNotContainsString('<tspan', $svg);
    }

    public function test_weather_svg_omits_station_rows_when_key_missing_entirely(): void
    {
        // weather_select_display() liefert 'station' immer mit, aber die
        // Funktion soll auch ohne den Schluessel nicht brechen (z.B. alte
        // Cache-Fixture in einem anderen Test).
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringNotContainsString('<tspan', $svg);
    }

    public function test_weather_svg_always_shows_idle_status_text(): void
    {
        // Statusleiste ist server-seitig IMMER "Bereit" -- ein ausgeliefertes
        // Bild wird per Definition angezeigt, WAEHREND das Geraet auf die
        // naechste Eingabe wartet. "Lade …"/"Vollbild"/"Schlaf" zeichnet die
        // Firmware lokal darueber (showStatus() in
        // epaper-monitor/src/display.cpp), s. BOARD_STATUS_IDLE_TEXT.
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringContainsString('>Bereit<', $svg);
    }

    public function test_weather_svg_shows_idle_status_even_when_never_fetched(): void
    {
        $svg = board_render_weather_svg(['available' => false]);

        $this->assertStringContainsString('>Bereit<', $svg);
    }

    public function test_firmware_marker_is_rendered_server_side_when_the_device_sends_its_build(): void
    {
        // Seit 2026-08-22 rendert der SERVER die Marke -- lokal aufs Panel
        // gezeichnet kostete sie gemessene 1104 ms pro Zyklus, mehr als ein
        // komplettes Vollbild (1024 ms).
        $svg = board_render_weather_svg($this->weatherFixture(), 46);

        $this->assertStringContainsString('>FW46<', $svg);
        $this->assertStringContainsString(
            sprintf('x="%d" y="%d" text-anchor="end"', BOARD_STATUS_MARKER_RIGHT, BOARD_STATUS_BASELINE),
            $svg
        );
    }

    public function test_firmware_marker_is_omitted_without_the_device_header(): void
    {
        // Browser-Aufruf oder aeltere Firmware ohne X-Device-Firmware: lieber
        // gar keine Marke als "FW0".
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringNotContainsString('FW', $svg);
    }

    public function test_idle_status_is_drawn_small_and_with_its_pictogram(): void
    {
        // Nutzerwunsch 2026-08-22: Schriftgroesse wie "Stand HH:MM" (24 statt
        // 34 fett) und ein diskretes Piktogramm davor. Der Ring-mit-Punkt muss
        // formgleich zu StatusIcon::Ready in display.cpp bleiben -- sonst
        // springt das Symbol sichtbar, sobald die Firmware die Flaeche nach
        // einem Patch lokal uebermalt.
        $svg = board_render_weather_svg($this->weatherFixture());

        $this->assertStringContainsString('font-size="24" fill="black">Bereit<', $svg);
        $this->assertStringNotContainsString('font-size="34"', $svg);
        $this->assertStringContainsString(
            sprintf('<circle cx="%d" cy="%d" r="10"', BOARD_STATUS_ICON_CX, BOARD_STATUS_ICON_CY),
            $svg
        );
        $this->assertStringContainsString(
            sprintf('<circle cx="%d" cy="%d" r="4" fill="black"/>', BOARD_STATUS_ICON_CX, BOARD_STATUS_ICON_CY),
            $svg
        );
    }

    // --- Sonnenzeile (Nutzerwunsch 2026-08-22) --------------------------------

    private function sunFixture(): array
    {
        $tz = new \DateTimeZone('Europe/Vienna');

        return [
            'available' => true,
            'sunrise'   => new \DateTimeImmutable('2026-08-22 05:58', $tz),
            'sunset'    => new \DateTimeImmutable('2026-08-22 19:56', $tz),
        ];
    }

    public function test_sun_row_shows_both_times_with_their_own_pictograms(): void
    {
        $svg = board_render_weather_svg($this->weatherFixture(), null, $this->sunFixture());

        $this->assertStringContainsString('>05:58<', $svg);
        $this->assertStringContainsString('>19:56<', $svg);
        $this->assertStringContainsString('#iconSunrise', $svg);
        $this->assertStringContainsString('#iconSunset', $svg);
    }

    public function test_sun_row_is_omitted_when_unavailable(): void
    {
        $this->assertStringNotContainsString('#iconSunrise', board_render_weather_svg($this->weatherFixture()));
        $this->assertStringNotContainsString(
            '#iconSunrise',
            board_render_weather_svg($this->weatherFixture(), null, ['available' => false])
        );
    }

    public function test_sun_row_moves_up_when_precipitation_is_absent_no_gap(): void
    {
        // Regressionsschutz 2026-08-23 ("fehlender Regen macht auf der
        // Monitorseite wieder eine Leerzeile"): eine fruehere Fassung hielt
        // die Sonnenzeile an einer festen Y-Position, um einen Sprung zu
        // vermeiden, wenn die bedingte Niederschlagszeile verschwindet --
        // das hinterliess dafuer bei jedem trockenen Refresh (dem
        // Normalfall) eine sichtbare Luecke zwischen Wind- und Sonnenzeile.
        // Jetzt zaehlen die Zeilen fortlaufend: OHNE Niederschlag ruecken
        // Sonnenauf-/-untergang eine Zeile hoeher nach, MIT Niederschlag
        // steht die zusaetzliche Zeile lueckenlos dazwischen.
        $dry = $this->weatherFixture();
        $dry['station'] = ['available' => true, 'temp_c' => 23.4, 'humidity_pct' => 61,
            'wind_kmh' => 12, 'wind_gusts_kmh' => 28, 'wind_direction' => 'W', 'precipitation_mm' => 0.0];
        $wet = $dry;
        $wet['station']['precipitation_mm'] = 1.4;

        // Reihenfolge: Temp(190), Feuchte(246), Wind(302), [Regen(358)], Sonne.
        $sunYDry = BOARD_WEATHER_ROW_TEMP_Y + 3 * BOARD_WEATHER_ROW_LEAD;  // 358 -- ruecktup, keine Luecke
        $sunYWet = BOARD_WEATHER_ROW_TEMP_Y + 4 * BOARD_WEATHER_ROW_LEAD;  // 414 -- eine Zeile weiter unten

        $svgDry = board_render_weather_svg($dry, null, $this->sunFixture());
        $svgWet = board_render_weather_svg($wet, null, $this->sunFixture());

        $this->assertStringContainsString(sprintf('href="#iconSunrise" transform="translate(1400,%d)', $sunYDry - 14), $svgDry);
        $this->assertStringContainsString(sprintf('href="#iconSunrise" transform="translate(1400,%d)', $sunYWet - 14), $svgWet);
        $this->assertStringNotContainsString('mm/h', $svgDry);
        $this->assertStringContainsString('1.4 mm/h', $svgWet);
    }
}
