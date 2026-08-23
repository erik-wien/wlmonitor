<?php
// tests/Integration/BoardSleepTest.php
//
// Schlafschirm (Nutzerwunsch 2026-08-23): Wetter heute/morgen + QR-Code fuers
// Gaeste-WLAN. Der QR-Test laeuft durch die ECHTE Pipeline (rsvg-convert ->
// 1bpp), weil genau dort die Lesbarkeit entschieden wird.

namespace WLMonitor\Tests\Integration;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class BoardSleepTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-23 21:34', new DateTimeZone('Europe/Vienna'));
    }

    private function day(array $overrides = []): array
    {
        return array_merge([
            'available' => true, 'icon_category' => 'klar',
            'temp_min' => 15, 'temp_max' => 25,
            'text' => 'Die meiste Zeit über scheint die Sonne, nur hin und wieder Wolken.',
            'text_error' => null,
        ], $overrides);
    }

    private function today(): array
    {
        return $this->day(['station' => [
            'available' => true, 'temp_c' => 11.2, 'humidity_pct' => 91,
            'wind_kmh' => 4, 'wind_gusts_kmh' => 5, 'wind_direction' => 'NW', 'precipitation_mm' => 0.0,
        ]]);
    }

    private function wifi(): array
    {
        return ['ssid' => 'Jardyx-Gaeste', 'password' => 'Willkommen2026', 'encryption' => 'WPA', 'hidden' => false];
    }

    public function test_renders_both_days_and_the_wifi_block(): void
    {
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, $this->wifi(), $this->now(), 3);

        $this->assertStringContainsString('>Heute<', $svg);
        $this->assertStringContainsString('>Morgen<', $svg);
        $this->assertStringContainsString('>Gäste-WLAN<', $svg);
        $this->assertStringContainsString('>Jardyx-Gaeste<', $svg);
        $this->assertStringContainsString('Stand 21:34', $svg);
        // Keine Abfahrten, keine Touch-Leiste -- das ist der ganze Punkt.
        // Auf die VERWENDUNG pruefen, nicht auf die Definition: board_svg_defs()
        // liefert alle Symbole gemeinsam, auch die hier ungenutzten Badges.
        $this->assertStringNotContainsString('href="#badgeMetro"', $svg);
        $this->assertStringNotContainsString('href="#badgeTram"', $svg);
    }

    public function test_without_credentials_the_qr_block_is_simply_absent(): void
    {
        // Fehlt data/guest_wifi.json, soll der Schirm weiter funktionieren --
        // nur eben ohne WLAN-Block, statt mit einem kaputten Code.
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, null, $this->now(), 3);

        $this->assertStringNotContainsString('Gäste-WLAN', $svg);
        $this->assertStringContainsString('>Heute<', $svg);
    }

    public function test_password_never_appears_as_readable_text(): void
    {
        // Der QR-Code traegt das Passwort, die Beschriftung darf es nicht --
        // sonst stuende es fuer jeden lesbar auf einem Bildschirm im Flur.
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, $this->wifi(), $this->now(), 3);

        $this->assertStringNotContainsString('>Willkommen2026<', $svg);
    }

    public function test_overlong_forecast_is_truncated_instead_of_running_into_the_wifi_block(): void
    {
        $long = $this->day(['text' => str_repeat('Sehr ausfuehrliche Wettervorhersage. ', 30)]);
        $svg = board_sleep_render_svg($this->today(), $long, null, $this->wifi(), $this->now(), 3);

        $this->assertStringContainsString('…', $svg);
        // Keine Textzeile darf in den WLAN-Block ragen.
        preg_match_all('/<text x="1160" y="(\d+)" [^>]*font-size="44"/', $svg, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $y) {
            $this->assertLessThanOrEqual(BOARD_SLEEP_TOMORROW_MAX_Y, (int) $y);
        }
    }

    public function test_shows_the_pagination_pill_at_the_identical_position_as_the_departures_page(): void
    {
        // Nutzerwunsch 2026-08-23: "der Schlafschirm sollte die Paginierung
        // zeigen, so lange das Geraet nicht effektiv schlaeft, sonst gibts
        // nur den Tastenweg zurueck." Kein Stilentscheid: die Firmware
        // erkennt einen Tipp rein anhand fester Koordinaten (touch_zone.cpp),
        // die Pille MUSS also exakt an derselben Stelle sitzen wie auf der
        // Abfahrtenseite (BOARD_PAGINATION_TOP/HEIGHT/RIGHT_EDGE).
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, $this->wifi(), $this->now(), 4);

        $this->assertStringContainsString(
            sprintf('y="%d" width="%d" height="%d"', BOARD_PAGINATION_TOP, 368, BOARD_PAGINATION_HEIGHT),
            $svg,
            'Pillenhoehe/-position muessen exakt mit der Abfahrtenseite uebereinstimmen'
        );
        // Seite 4 von 4 (der Schlafschirm-Slot selbst) ist die aktive --
        // schwarzer Kreis mit weisser "4".
        $this->assertStringContainsString('font-weight="bold" font-size="30" fill="white">4<', $svg);
        $this->assertStringContainsString('Stand 21:34', $svg);
    }

    public function test_pagination_pill_always_present_even_with_only_two_pages(): void
    {
        // Der Schlafschirm ist strukturell IMMER mindestens Seite 2 von 2
        // (1 Abfahrtenseite + Schlafschirm) -- die Pille darf hier nicht dem
        // "totalPages <= 1"-Schutz von board_render_stand_and_pagination_svg()
        // zum Opfer fallen.
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, null, $this->now(), 2);

        $this->assertStringContainsString(sprintf('y="%d"', BOARD_PAGINATION_TOP), $svg);
        $this->assertStringContainsString('font-weight="bold" font-size="30" fill="white">2<', $svg);
    }

    public function test_qr_survives_the_1bpp_pipeline_pixel_exact(): void
    {
        // Die eigentliche Bedingung fuer Scannbarkeit: jedes Modul muss nach
        // rsvg-convert + 1bpp-Schwelle EINFARBIG und in der richtigen Farbe
        // herauskommen. Krumme Modulgroessen erzeugen sonst Graustufen an den
        // Kanten, die die Schwelle zu ausgefransten Modulen macht.
        $payload = board_guest_wifi_payload($this->wifi());
        $x = 1160;
        $y = 915;
        $qr = board_sleep_qr($payload, $x, $y, 365);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1872" height="1404" viewBox="0 0 1872 1404">'
            . '<rect width="1872" height="1404" fill="white"/>' . $qr['svg'] . '</svg>';
        $packed = png_to_1bpp_packed(svg_to_png($svg), 1872, 1404);

        $rowBytes = intdiv(1872 + 7, 8);
        $pixelIsWhite = static function (int $px, int $py) use ($packed, $rowBytes): bool {
            $byte = ord($packed[$py * $rowBytes + intdiv($px, 8)]);
            return (($byte >> (7 - ($px % 8))) & 1) === 1;
        };

        $moduleSize = intdiv($qr['size'], $qr['modules'] + 8);
        $this->assertGreaterThanOrEqual(4, $moduleSize, 'unter 4px pro Modul wird das Scannen unzuverlaessig');

        $options = new QROptions(['eccLevel' => EccLevel::M, 'version' => QRCode::VERSION_AUTO, 'quietzone' => false]);
        $matrix = (new QRCode($options))->addByteSegment($payload)->getQRMatrix();

        $originX = $x + 4 * $moduleSize;
        $originY = $y + 4 * $moduleSize;
        $wrongColour = 0;
        $notUniform = 0;

        for ($row = 0; $row < $qr['modules']; $row++) {
            for ($col = 0; $col < $qr['modules']; $col++) {
                $expectWhite = !$matrix->isDark($matrix->get($col, $row));
                $seen = [];
                for ($dy = 0; $dy < $moduleSize; $dy++) {
                    for ($dx = 0; $dx < $moduleSize; $dx++) {
                        $seen[(int) $pixelIsWhite($originX + $col * $moduleSize + $dx, $originY + $row * $moduleSize + $dy)] = true;
                    }
                }
                if (count($seen) > 1) {
                    $notUniform++;
                } elseif (!isset($seen[(int) $expectWhite])) {
                    $wrongColour++;
                }
            }
        }

        $this->assertSame(0, $notUniform, 'jedes Modul muss einfarbig sein');
        $this->assertSame(0, $wrongColour, 'jedes Modul muss die richtige Farbe haben');
    }
}
