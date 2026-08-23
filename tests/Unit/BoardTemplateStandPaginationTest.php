<?php
// tests/Unit/BoardTemplateStandPaginationTest.php
//
// "Stand HH:MM" + Seitenzahlen-Pille aus Spec (Stand 2026-08-16, Pille ohne
// Pfeile seit 2026-08-23) -- sitzen am unteren Ende der Abfahrtenspalte,
// NICHT mehr in der Kopfzeile.

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
        // In der Praxis nie mehr wahr (der Schlafschirm-Slot macht totalPages
        // immer >= 2) -- als Schutz fuer Direktaufrufe dieser Funktion (z.B.
        // debug=svg&part=monitor) trotzdem getestet.
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 1);
        $this->assertStringNotContainsString('rx="28"', $svg, 'Pille nur bei mehr als einer Seite');
    }

    public function test_middle_page_shows_active_circle_and_no_arrows(): void
    {
        // totalPages=3: pillWidth=3*87+20=281, pillStartX=1083-281=802,
        // numberStartX=802+10+43=855, Slots bei 855/942/1029.
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 2, 3);

        $this->assertStringContainsString('x="802" y="1252" width="281" height="56" rx="28"', $svg);
        $this->assertStringNotContainsString('←', $svg, 'keine Pfeile mehr (Nutzerwunsch 2026-08-23)');
        $this->assertStringNotContainsString('→', $svg);
        $this->assertStringContainsString('<circle cx="942" cy="1280" r="24" fill="black"/>', $svg);
        $this->assertStringContainsString('fill="white">2<', $svg, 'aktive Seite weiss auf dem Kreis');
        $this->assertStringContainsString('x="855" y="1289" text-anchor="middle" font-size="30" fill="black">1<', $svg);
        $this->assertStringContainsString('x="1029" y="1289" text-anchor="middle" font-size="30" fill="black">3<', $svg);
    }

    public function test_first_page_marks_the_first_number_active(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3);
        $this->assertStringContainsString('<circle cx="855" cy="1280" r="24" fill="black"/>', $svg);
        $this->assertStringContainsString('x="855" y="1289" text-anchor="middle" font-weight="bold" font-size="30" fill="white">1<', $svg);
    }

    public function test_last_page_marks_the_last_number_active(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 3, 3);
        $this->assertStringContainsString('<circle cx="1029" cy="1280" r="24" fill="black"/>', $svg);
        $this->assertStringContainsString('x="1029" y="1289" text-anchor="middle" font-weight="bold" font-size="30" fill="white">3<', $svg);
    }
}
