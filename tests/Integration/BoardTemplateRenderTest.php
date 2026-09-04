<?php
// tests/Integration/BoardTemplateRenderTest.php
//
// End-to-End: board_render_svg() -> svg_to_png() -> png_to_1bpp_packed(),
// mit Pixel-Nachmessung wie in der manuellen Mockup-Session. Fixture ist
// der Favorit "Westbahnhof" (echte Daten, im Chat abgenommen) +
// Touch-Leiste mit 3 Favoriten + 2 echten WL-Stoerungsmeldungen.

namespace WLMonitor\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateRenderTest extends TestCase
{
    private function line(string $name, string $platform, string $towards, string $type, bool $realtime, array $departures): array
    {
        return ['line' => $name, 'platform' => $platform, 'towards' => $towards, 'type' => $type, 'realtime' => $realtime, 'alert' => false, 'departures' => $departures];
    }

    private function favoriteFixture(): array
    {
        return ['id' => 225, 'title' => 'Westbahnhof', 'stations' => [
            ['diva' => '60201468', 'name' => 'Westbahnhof S U', 'lines' => [
                $this->line('18', '1', 'Schlachthausgasse U', 'tram', true, [['in' => 7], ['in' => 22]]),
                $this->line('6', '1', 'Geiereckstraße', 'tram', true, [['in' => 1], ['in' => 14]]),
                $this->line('9', '2', 'Gersthof S', 'tram', false, [['in' => 9], ['in' => 16]]),
                $this->line('U3', '1', 'Simmering', 'metro', true, [['in' => 0], ['in' => 8]]),
                $this->line('U6', '1', 'Floridsdorf', 'metro', true, [['in' => 0], ['in' => 6]]),
                $this->line('U6', '2', 'Siebenhirten', 'metro', true, [['in' => 5], ['in' => 12]]),
            ]],
        ]];
    }

    /** Favorit mit genug Zeilen, um BOARD_DEPARTURES_MAX_Y sicher auf 2 Seiten zu ueberschreiten (wie BoardTemplateLayoutTest). */
    private function multiPageFavoriteFixture(): array
    {
        $manyLines = array_map(
            fn (int $i) => $this->line((string) $i, '1', "Ziel $i", 'bus', true, [['in' => $i], ['in' => $i + 10]]),
            range(1, 4)
        );

        return ['id' => 1, 'title' => 'Viele Zeilen', 'stations' => [
            ['diva' => '1', 'name' => 'Station A', 'lines' => $manyLines],
            ['diva' => '2', 'name' => 'Station B', 'lines' => $manyLines],
            ['diva' => '3', 'name' => 'Station C', 'lines' => $manyLines],
        ]];
    }

    private function weatherFixture(): array
    {
        return [
            'available' => true, 'icon_category' => 'klar',
            'temp_min' => 18, 'temp_max' => 35,
            'text' => 'Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad.',
            'text_error' => null,
        ];
    }

    /** Erste schwarze Pixelzeile innerhalb [$x0,$x1) x [$y0,$y1), oder null. */
    private function firstInkY($im, int $x0, int $x1, int $y0, int $y1): ?int
    {
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($lum < 200) { // < 200 statt < 128: faengt auch das mittlere Grau (#808080) ein
                    return $y;
                }
            }
        }
        return null;
    }

    /** Letzte (unterste) schwarze Pixelzeile innerhalb [$x0,$x1) x [$y0,$y1), oder null. */
    private function lastInkY($im, int $x0, int $x1, int $y0, int $y1): ?int
    {
        for ($y = $y1 - 1; $y >= $y0; $y--) {
            for ($x = $x0; $x < $x1; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($lum < 200) {
                    return $y;
                }
            }
        }
        return null;
    }

    public function test_full_pipeline_produces_correctly_sized_frame(): void
    {
        $svg = board_render_svg(
            ['Arbeit', 'Westbahnhof', 'zur Stadt'], 1, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $png = svg_to_png($svg);
        $packed = png_to_1bpp_packed($png, 1872, 1404);

        $this->assertSame(234 * 1404, strlen($packed));
    }

    public function test_touch_bar_shows_active_favorite_filled(): void
    {
        $svg = board_render_svg(
            ['Arbeit', 'Westbahnhof', 'zur Stadt'], 1, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );

        $this->assertStringContainsString('fill="white">Westbahnhof<', $svg, 'aktiver Favorit (Index 1) hat weisses Label auf schwarzem Button');
    }

    public function test_header_no_longer_contains_stand(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable('2026-08-16 19:13:00'), 78, 2
        );

        // "Stand" darf NUR am Ende der Abfahrtenspalte auftauchen (einmal),
        // nicht mehr in der Kopfzeile.
        $this->assertSame(1, substr_count($svg, 'Stand'));
    }

    public function test_disruptions_appear_as_final_page_when_alerts_present(): void
    {
        $alerts = [[
            'title' => 'U3: Bauarbeiten',
            'description' => 'Die Linie U3 fährt derzeit nicht zwischen Hütteldorfer Straße und Westbahnhof.',
            'priority' => '1', 'lines' => ['U3'], 'stops' => [],
        ]];

        // Westbahnhof-Fixture passt auf 1 Abfahrten-Seite -> Stoerungsseite ist Seite 2.
        $svgPage1 = board_render_svg(['Westbahnhof'], 0, $this->favoriteFixture(), $alerts, 1, $this->weatherFixture(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);
        $svgPage2 = board_render_svg(['Westbahnhof'], 0, $this->favoriteFixture(), $alerts, 2, $this->weatherFixture(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2);

        $this->assertStringContainsString('WESTBAHNHOF S U', $svgPage1);
        $this->assertStringNotContainsString('U3: Bauarbeiten', $svgPage1);

        $this->assertStringContainsString('U3: Bauarbeiten', $svgPage2);
        $this->assertStringNotContainsString('WESTBAHNHOF S U', $svgPage2, 'Stoerungsseite zeigt keine Abfahrten mehr');
    }

    public function test_gap_between_station_header_and_first_row_matches_spec(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable('2026-08-16 19:13:00'), new DateTimeImmutable('2026-08-16 19:14:00'),
            78, 2
        );

        $im = imagecreatefromstring(svg_to_png($svg));

        // headerBottom = unterste Tinte des Stationskopfs (Baseline-Kante,
        // keine Unterlaengen bei Grossbuchstaben) -- firstInkY() wuerde hier
        // die OBERSTE Kante der Versalhoehe liefern, nicht die fuer den
        // 29px-Abstand relevante Baseline.
        $headerBottom = $this->lastInkY($im, 16, 600, 100, 200);
        $rowTop = $this->firstInkY($im, 16, 1083, 200, 280);

        $this->assertNotNull($headerBottom);
        $this->assertNotNull($rowTop);
        $this->assertSame(29, $rowTop - $headerBottom, 'Abstand Kopf-Baseline -> hoechstes Element der ersten Zeile muss 29px sein (Spec)');
    }

    public function test_disruptions_page_and_pagination_pill_render_through_real_pipeline(): void
    {
        // Abfahrten spannen 2 Seiten (multiPageFavoriteFixture) + echte
        // Stoerungsmeldung + der Schlafschirm-Slot (seit 2026-08-23 immer die
        // letzte Seite) -> 4 Seiten insgesamt, Stoerungsseite bleibt Seite 3.
        // Deckt ab, dass Stoerungsseite + Pagination-Pille auch durch
        // svg_to_png() tatsaechlich sichtbares Pixelmaterial erzeugen --
        // bisher pruefte das kein Integrationstest.
        $alerts = [[
            'title' => 'U3: Bauarbeiten',
            'description' => 'Die Linie U3 fährt derzeit nicht zwischen Hütteldorfer Straße und Westbahnhof.',
            'priority' => '1', 'lines' => ['U3'], 'stops' => [],
        ]];

        $svg = board_render_svg(
            ['Viele Zeilen'], 0, $this->multiPageFavoriteFixture(), $alerts, 3,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );

        $im = imagecreatefromstring(svg_to_png($svg));

        // Stoerungstitel "U3: Bauarbeiten" (x=16 y=160, 40px fett) muss Tinte
        // im erwarteten Bereich hinterlassen.
        $titleInkY = $this->firstInkY($im, 16, 700, 100, 200);
        $this->assertNotNull($titleInkY, 'Stoerungstitel muss auf der Stoerungsseite sichtbar sein');

        // Pagination-Pille zeigt Seite 3 von 4 als schwarz gefuellten Kreis.
        // Seit 2026-09-04 sitzt sie LINKS in der Favoritenzeile:
        // totalPages=4 -> pillWidth=4*100+20=420, pillStartX=16,
        // numberStartX=16+10+50=76, Seite 3 bei cx=76+2*100=276, cy=1357,
        // r=30. Bereich knapp innerhalb des Kreisradius, ohne den Pillenrand
        // (y=1320/1394) mit einzuschliessen.
        $pillInkY = $this->firstInkY($im, 265, 287, 1340, 1374);
        $this->assertNotNull($pillInkY, 'aktueller Seiten-Kreis der Pagination-Pille muss sichtbar sein');
    }

    public function test_wl_logo_is_monochrome_black_and_white_only(): void
    {
        $svg = board_render_svg(
            ['Westbahnhof'], 0, $this->favoriteFixture(), [], 1,
            $this->weatherFixture(),
            new DateTimeImmutable(), new DateTimeImmutable(), 78, 2
        );
        $im = imagecreatefromstring(svg_to_png($svg));

        // Logo-Bounding-Box (x=24..306, y=12..78, aus translate(24,12)
        // scale(0.5025) auf die 561,3x131,6-Quelle) darf keine echte Farbe
        // enthalten (R != G != B) -- anders als eine frühere Planversion, die
        // hier bewusst Markenfarben erlaubte (inzwischen ueberholt, s.
        // Global Constraints "Logo ist jetzt durchgehend monochrom").
        $colorPixels = 0;
        for ($y = 12; $y < 78; $y += 2) {
            for ($x = 24; $x < 306; $x += 2) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                if (max(abs($r - $g), abs($g - $b), abs($r - $b)) > 5) {
                    $colorPixels++;
                }
            }
        }
        $this->assertSame(0, $colorPixels, 'Logo muss vollstaendig schwarz/weiss sein, keine Markenfarben mehr');
    }
}
