<?php
// tests/Unit/BoardTemplateStandPaginationTest.php
//
// "Stand HH:MM" + kanonische Pagination aus Spec (Stand 2026-08-16) --
// sitzen am unteren Ende der Abfahrtenspalte, NICHT mehr in der Kopfzeile.

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardTemplateStandPaginationTest extends TestCase
{
    public function test_stand_always_renders(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 1);
        $this->assertStringContainsString('x="16" y="1286" font-family="Atkinson Hyperlegible Next" font-size="24" fill="black">Stand 19:13<', $svg);
    }

    public function test_no_pagination_pill_when_only_one_page(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 1);
        $this->assertStringNotContainsString('rx="24"', $svg, 'Pille nur bei mehr als einer Seite');
    }

    public function test_middle_page_shows_both_arrows_enabled_and_active_circle(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 2, 3);

        $this->assertStringContainsString('x="793" y="1256" width="290" height="48" rx="24"', $svg);
        $this->assertStringContainsString('fill="black">←<', $svg, 'Zurueck-Pfeil aktiv (nicht erste Seite)');
        $this->assertStringContainsString('fill="black">→<', $svg, 'Vor-Pfeil aktiv (nicht letzte Seite)');
        $this->assertStringContainsString('<circle cx="938" cy="1280" r="20" fill="black"/>', $svg);
        $this->assertStringContainsString('fill="white">2<', $svg, 'aktive Seite weiss auf dem Kreis');
        $this->assertStringContainsString('>1<', $svg);
        $this->assertStringContainsString('>3<', $svg);
    }

    public function test_first_page_grays_out_back_arrow(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3);
        $this->assertStringContainsString('fill="#b0b0b0">←<', $svg);
        $this->assertStringContainsString('fill="black">→<', $svg);
    }

    public function test_last_page_grays_out_forward_arrow(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 3, 3);
        $this->assertStringContainsString('fill="black">←<', $svg);
        $this->assertStringContainsString('fill="#b0b0b0">→<', $svg);
    }
}
