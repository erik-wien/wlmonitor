<?php
// tests/Unit/BoardTemplateDisruptionsTest.php
//
// Stoerungsseite aus Spec §8/§9 (Stand 2026-08-16): Titel fett + gekuerzte
// Beschreibung, gleiches Zeilenraster-Prinzip wie die Abfahrtenliste.
// Fixture-Texte sind echte, am 2026-08-15 live abgerufene WL-Stoerungen.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDisruptionsTest extends TestCase
{
    // --- board_wrap_disruption_text -------------------------------------------

    public function test_wrap_disruption_text_truncates_with_ellipsis_beyond_max_lines(): void
    {
        $longText = 'Linie 5: Kein Betrieb zwischen Lerchenfelder Straße und Franz-Josefs-Bahnhof S. '
            . 'Betrieb zwischen Westbahnhof S U und Lerchenfelder Straße. Weiterfahrt bis Josefstädter Straße U. '
            . 'Betrieb zwischen Praterstern S U und Franz-Josefs-Bahnhof S. Weiterfahrt bis Augasse.';

        $lines = board_wrap_disruption_text($longText, 3);

        $this->assertCount(3, $lines);
        $this->assertStringEndsWith('…', $lines[2]);
    }

    public function test_short_disruption_text_not_truncated(): void
    {
        $lines = board_wrap_disruption_text('Kurze Meldung.', 3);
        $this->assertSame(['Kurze Meldung.'], $lines);
        $this->assertStringEndsNotWith('…', $lines[0]);
    }

    // --- board_layout_disruptions / board_render_disruptions_svg -------------

    private function alert(string $title, string $description, array $lines): array
    {
        return ['title' => $title, 'description' => $description, 'priority' => '1', 'lines' => $lines, 'stops' => []];
    }

    public function test_layout_and_render_two_real_disruptions(): void
    {
        $alerts = [
            $this->alert(
                '5, 12, 37, 38, 40, 41, 42: Gleisbauarbeiten',
                'Linie 5: Kein Betrieb zwischen Lerchenfelder Straße und Franz-Josefs-Bahnhof S. Betrieb zwischen Westbahnhof S U und Lerchenfelder Straße. Weiterfahrt bis Josefstädter Straße U. Betrieb zwischen Praterstern S U und Franz-Josefs-Bahnhof S. Weiterfahrt bis Augasse.',
                ['5', '12', '37', '38', '40', '41', '42']
            ),
            $this->alert(
                'U3: Bauarbeiten',
                'Die Linie U3 fährt derzeit nicht zwischen Hütteldorfer Straße und Westbahnhof. Weichen Sie ersatzweise auf die Linien E3, 46, 49 und 48A aus.',
                ['U3']
            ),
        ];

        $items = board_layout_disruptions($alerts);
        $headers = array_values(array_filter($items, fn ($i) => $i['type'] === 'disruption_title'));
        $this->assertCount(2, $headers);
        $this->assertSame(160, $headers[0]['y']);
        $this->assertStringContainsString('Gleisbauarbeiten', $headers[0]['text']);

        $svg = board_render_disruptions_svg($items);
        $this->assertStringContainsString('font-weight="bold" font-size="40"', $svg);
        $this->assertStringContainsString('Gleisbauarbeiten', $svg);
        $this->assertStringContainsString('U3: Bauarbeiten', $svg);
        $this->assertStringContainsString('…', $svg, 'lange Meldung muss gekuerzt sein');
    }

    public function test_empty_alerts_render_nothing(): void
    {
        $this->assertSame([], board_layout_disruptions([]));
        $this->assertSame('', board_render_disruptions_svg([]));
    }
}
