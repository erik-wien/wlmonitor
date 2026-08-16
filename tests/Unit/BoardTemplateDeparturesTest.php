<?php
// tests/Unit/BoardTemplateDeparturesTest.php
//
// SVG-Rendering der Abfahrtenliste (Stand 2026-08-16): normal/grau/gestoert,
// Vektor-Stern statt Unicode fuer "faehrt jetzt".

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDeparturesTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'type' => 'row', 'r' => 259, 'badge_type' => 'tram', 'label' => '18',
            'platform' => '1', 'destination' => 'Schlachthausgasse U',
            'live_in' => 7, 'secondary_in' => 22, 'style' => 'normal', 'divider_y' => 307,
        ], $overrides);
    }

    public function test_header_renders_at_correct_position(): void
    {
        $svg = board_render_departures_svg([['type' => 'header', 'y' => 196, 'text' => 'WESTBAHNHOF S U']]);

        $this->assertStringContainsString('x="16" y="196" font-weight="bold" font-size="55"', $svg);
        $this->assertStringContainsString('>WESTBAHNHOF S U<', $svg);
    }

    public function test_normal_row_renders_badge_and_bold_black_numbers(): void
    {
        $svg = board_render_departures_svg([$this->row()]);

        $this->assertStringContainsString('<use href="#badgeTram" transform="translate(54,259)"/>', $svg);
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="black" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x1="16" y1="307" x2="1083" y2="307"', $svg);
        $this->assertStringNotContainsString('fill="#808080"', $svg);
        $this->assertStringNotContainsString('rect x="950"', $svg);
    }

    public function test_live_zero_renders_starNow_vector_not_unicode(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => 0])]);

        $this->assertStringContainsString('<use href="#starNow" transform="translate(985,259)"/>', $svg);
        $this->assertStringNotContainsString('✱', $svg);
        $this->assertStringNotContainsString('✳', $svg);
    }

    public function test_secondary_zero_renders_scaled_starNow(): void
    {
        $svg = board_render_departures_svg([$this->row(['secondary_in' => 0])]);
        $this->assertStringContainsString('<use href="#starNow" transform="translate(1073,259) scale(0.696)"/>', $svg);
    }

    public function test_missing_departure_renders_dash_and_omits_dot_and_secondary(): void
    {
        $svg = board_render_departures_svg([$this->row(['live_in' => null, 'secondary_in' => null])]);

        $this->assertStringContainsString('text-anchor="end">–<', $svg);
        $this->assertStringNotContainsString('>·<', $svg);
        $this->assertStringNotContainsString('x="1083"', $svg);
    }

    public function test_gray_style_applies_to_the_whole_row_except_badge(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'gray'])]);

        // Badge-Form + Label bleiben schwarz/weiss:
        $this->assertStringContainsString('<use href="#badgeTram" transform="translate(54,259)"/>', $svg);
        $this->assertStringContainsString('fill="white" text-anchor="middle">18<', $svg, 'Badge-Label bleibt weiss im Badge');
        // Alles andere in der Zeile grau, NICHT kursiv:
        $this->assertStringContainsString('x="110" y="267" font-weight="bold" font-size="22" fill="#808080">1<', $svg, 'Steig-Nummer grau');
        $this->assertStringContainsString('x="145" y="278" font-size="55" fill="#808080">Schlachthausgasse U<', $svg, 'Fahrtrichtung grau');
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="#808080" text-anchor="end">7<', $svg, 'Live-Abfahrt grau, weiterhin fett');
        $this->assertStringContainsString('x="1015" y="266" font-size="20" fill="#808080">·<', $svg, 'Trennpunkt grau');
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="#808080" text-anchor="end">22<', $svg, 'Folgeabfahrt grau');
        $this->assertStringNotContainsString('font-style="italic"', $svg, 'kein Kursiv mehr, s. Spec Global Constraints');
    }

    public function test_delayed_style_inverts_only_the_live_number(): void
    {
        $svg = board_render_departures_svg([$this->row(['style' => 'delayed'])]);

        $this->assertStringContainsString('<rect x="950" y="239" width="60" height="42" fill="black"/>', $svg);
        $this->assertStringContainsString('x="1000" y="275" font-weight="bold" font-size="46" fill="white" text-anchor="end">7<', $svg);
        $this->assertStringContainsString('x="1083" y="270" font-size="32" fill="black" text-anchor="end">22<', $svg, 'Folgeabfahrt bleibt normal schwarz, nicht grau, nicht invertiert');
    }

    public function test_three_char_label_uses_smaller_font(): void
    {
        $svg = board_render_departures_svg([$this->row(['badge_type' => 'metro', 'label' => 'WLB'])]);
        $this->assertStringContainsString('<use href="#badgeMetro"', $svg);
        $this->assertStringContainsString('font-size="24" fill="white" text-anchor="middle">WLB<', $svg);
    }

    public function test_unknown_badge_type_falls_back_to_train_shape(): void
    {
        $svg = board_render_departures_svg([$this->row(['badge_type' => 'other'])]);
        $this->assertStringContainsString('<use href="#badgeTrain"', $svg);
    }

    public function test_special_characters_in_destination_are_escaped(): void
    {
        $svg = board_render_departures_svg([$this->row(['destination' => 'A & B'])]);
        $this->assertStringContainsString('A &amp; B', $svg);
    }
}
