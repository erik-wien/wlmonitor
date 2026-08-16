<?php
// tests/Unit/BoardTemplateTouchBarTest.php
//
// Touch-Leiste aus Spec (Stand 2026-08-16): bis zu 3 Favoriten-Buttons,
// gleich breit ueber die volle Breite verteilt, aktiver Favorit schwarz
// gefuellt mit weisser Schrift, inaktive weiss mit schwarzem Rand.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateTouchBarTest extends TestCase
{
    public function test_three_buttons_equal_width_active_one_filled(): void
    {
        $svg = board_render_touch_bar_svg(['Arbeit', 'Nach Hause', 'Westbahnhof'], 1);

        $this->assertStringContainsString('x="16" y="1320" width="602" height="74" rx="10" fill="white" stroke="black" stroke-width="3"', $svg, 'Button 1 inaktiv');
        $this->assertStringContainsString('x="634" y="1320" width="602" height="74" rx="10" fill="black"', $svg, 'Button 2 aktiv (Index 1)');
        $this->assertStringContainsString('x="1252" y="1320" width="602" height="74" rx="10" fill="white" stroke="black" stroke-width="3"', $svg, 'Button 3 inaktiv');
        $this->assertStringContainsString('fill="white">Nach Hause<', $svg, 'aktives Label weiss');
        $this->assertStringContainsString('fill="black">Arbeit<', $svg);
        $this->assertStringContainsString('fill="black">Westbahnhof<', $svg);
    }

    public function test_fewer_than_three_favorites_recomputes_button_width(): void
    {
        // 2 Favoriten: (1872 - 2*16 Rand - 1*16 Luecke) / 2 = 912 pro Button.
        $svg = board_render_touch_bar_svg(['Arbeit', 'Nach Hause'], 0);

        $this->assertStringContainsString('width="912"', $svg);
        $this->assertStringNotContainsString('Westbahnhof', $svg);
    }

    public function test_single_favorite_spans_full_width_and_is_always_active(): void
    {
        // 1 Favorit: (1872 - 2*16) / 1 = 1840 breit, immer aktiv (nichts zum
        // Umschalten da) -- schwarz gefuellt.
        $svg = board_render_touch_bar_svg(['Nur Einer'], 0);

        $this->assertStringContainsString('width="1840"', $svg);
        $this->assertStringContainsString('fill="black"', $svg);
    }

    public function test_special_characters_in_title_are_escaped(): void
    {
        $svg = board_render_touch_bar_svg(['A & B'], 0);
        $this->assertStringContainsString('A &amp; B', $svg);
    }
}
