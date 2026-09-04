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
        // Kein eigener "WLAN"-Titel mehr (Nutzerwunsch 2026-08-25) -- die
        // SSID steht direkt neben dem QR-Code.
        $this->assertStringNotContainsString('>WLAN<', $svg);
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

        $this->assertStringNotContainsString('>Jardyx-Gaeste<', $svg);
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

        // Keine Textzeile darf in den QR-Block ragen. Gegen die TATSAECHLICHE
        // QR-Position pruefen (ihr translate()-y), nicht gegen eine feste
        // Konstante: seit 2026-08-25 wird der Block von unten aufgebaut und die
        // Textgrenze aus seiner echten Hoehe gerechnet (vorher blieb unter einem
        // kleinen QR-Code toter Raum stehen, waehrend der Text darueber schon
        // gekuerzt wurde -- Nutzerbefund "unnoetig gekuerzt"). Kein eigener
        // "WLAN"-Titel mehr (Nutzerwunsch 2026-08-25), die SSID steht neben
        // dem QR und ist selbst Teil der Hoehenberechnung nicht mehr.
        $this->assertSame(1, preg_match('/<g transform="translate\(\d+,(\d+)\)"><rect x="1160" y="0" width="\d+" height="\d+" fill="white"\/>/', $svg, $t));
        $qrTop = (int) $t[1];

        preg_match_all('/<text x="1160" y="(\d+)" [^>]*font-size="44"/', $svg, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $y) {
            $this->assertLessThan($qrTop, (int) $y, 'Morgen-Text ragt in den QR-Block');
        }
        // ... und der Abstand muss auch gross genug fuer die Schriftgroesse sein.
        $this->assertGreaterThanOrEqual(50, $qrTop - (int) max($m[1]));
    }

    public function test_without_wifi_block_the_forecast_may_use_the_whole_column(): void
    {
        // Der eigentliche Nutzerbefund 2026-08-25 ("unnoetig gekuerzt"): auf
        // akadbrain fehlt data/guest_wifi.json, der QR-Block entfaellt also --
        // dann darf der Morgen-Text bis zur Fusszeile laufen statt bei 760 zu
        // enden und darunter eine halbe leere Spalte stehen zu lassen.
        $long = $this->day(['text' => str_repeat('Sehr ausfuehrliche Wettervorhersage. ', 30)]);

        $ohneWifi = board_sleep_render_svg($this->today(), $long, null, null, $this->now(), 3);
        $mitWifi  = board_sleep_render_svg($this->today(), $long, null, $this->wifi(), $this->now(), 3);

        $zeilen = static function (string $svg): array {
            preg_match_all('/<text x="1160" y="(\d+)" [^>]*font-size="44"/', $svg, $m);
            return array_map('intval', $m[1]);
        };

        $this->assertGreaterThan(
            count($zeilen($mitWifi)),
            count($zeilen($ohneWifi)),
            'ohne WLAN-Block muss mehr Text passen'
        );
        // Aber weiterhin nicht in die Fusszeile/Paginierung hineinlaufen.
        $this->assertLessThanOrEqual(BOARD_SLEEP_TODAY_MAX_Y, max($zeilen($ohneWifi)));
    }

    public function test_forecast_and_wifi_block_never_overlap_regardless_of_qr_size(): void
    {
        // Die QR-Kantenlaenge schwankt mit den Zugangsdaten (Modulzahl x
        // ganzzahlige Modulgroesse, 294-339px bei 340px Zielgroesse). Der
        // Block wird seit 2026-08-25 von unten aufgebaut, die Textgrenze
        // daraus gerechnet -- das muss fuer JEDE dieser Groessen aufgehen.
        $long = $this->day(['text' => str_repeat('Sehr ausfuehrliche Wettervorhersage. ', 30)]);

        foreach ([['AB', 'cd'], ['Jardyx-Gaeste', 'Willkommen2026'], [str_repeat('S', 30), str_repeat('p', 30)]] as [$ssid, $pw]) {
            $svg = board_sleep_render_svg(
                $this->today(), $long, null,
                ['ssid' => $ssid, 'password' => $pw, 'encryption' => 'WPA', 'hidden' => false],
                $this->now(), 3
            );

            $this->assertSame(1, preg_match('/<g transform="translate\(\d+,(\d+)\)"><rect x="1160" y="0" width="\d+" height="\d+" fill="white"\/>/', $svg, $t));
            preg_match_all('/<text x="1160" y="(\d+)" [^>]*font-size="44"/', $svg, $m);
            $this->assertNotEmpty($m[1]);
            $this->assertGreaterThanOrEqual(50, (int) $t[1] - (int) max($m[1]), "SSID '$ssid': Text zu dicht am QR-Block");
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
            // 420 = 4*100+20 (Slotbreite seit der Umstellung auf eine
            // gemeinsame Navigationszeile, 2026-09-04).
            sprintf('y="%d" width="%d" height="%d"', BOARD_PAGINATION_TOP, 420, BOARD_PAGINATION_HEIGHT),
            $svg,
            'Pillenhoehe/-position muessen exakt mit der Abfahrtenseite uebereinstimmen'
        );
        // Seite 4 von 4 (der Schlafschirm-Slot selbst) ist die aktive --
        // schwarzer Kreis mit weisser "4".
        $this->assertStringContainsString('font-weight="bold" font-size="38" fill="white">4<', $svg);
        $this->assertStringContainsString('Stand 21:34', $svg);
    }

    public function test_pagination_pill_is_hidden_on_the_final_pre_sleep_frame(): void
    {
        // Nutzerbefund 2026-08-23: "die paginierung ist jetzt aber leider
        // auch zu sehen, wenn das panel schlaeft." $showPagination=false ist
        // der erzwungene letzte Abruf vor esp_deep_sleep_start() -- die
        // Pille darf hier nicht mit einschlafen, "Stand HH:MM" bleibt aber.
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, $this->wifi(), $this->now(), 4, false);

        $this->assertStringContainsString('Stand 21:34', $svg);
        $this->assertStringNotContainsString(sprintf('y="%d"', BOARD_PAGINATION_TOP), $svg);
        $this->assertStringNotContainsString('font-size="38" fill="white">4<', $svg);
    }

    public function test_pagination_pill_always_present_even_with_only_two_pages(): void
    {
        // Der Schlafschirm ist strukturell IMMER mindestens Seite 2 von 2
        // (1 Abfahrtenseite + Schlafschirm) -- die Pille darf hier nicht dem
        // "totalPages <= 1"-Schutz von board_render_stand_and_pagination_svg()
        // zum Opfer fallen.
        $svg = board_sleep_render_svg($this->today(), $this->day(), null, null, $this->now(), 2);

        $this->assertStringContainsString(sprintf('y="%d"', BOARD_PAGINATION_TOP), $svg);
        $this->assertStringContainsString('font-weight="bold" font-size="38" fill="white">2<', $svg);
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
