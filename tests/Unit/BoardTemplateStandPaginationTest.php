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
        // Seit 2026-09-04 linksbuendig in der Favoritenzeile:
        // totalPages=3: pillWidth=3*100+20=320, pillStartX=16,
        // numberStartX=16+10+50=76, Slots bei 76/176/276.
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 2, 3);

        $this->assertStringContainsString('x="16" y="1320" width="320" height="74" rx="37"', $svg);
        $this->assertStringNotContainsString('←', $svg, 'keine Pfeile mehr (Nutzerwunsch 2026-08-23)');
        $this->assertStringNotContainsString('→', $svg);
        $this->assertStringContainsString('<circle cx="176" cy="1357" r="30" fill="black"/>', $svg);
        $this->assertStringContainsString('fill="white">2<', $svg, 'aktive Seite weiss auf dem Kreis');
        $this->assertStringContainsString('x="76" y="1370" text-anchor="middle" font-size="38" fill="black">1<', $svg);
        $this->assertStringContainsString('x="276" y="1370" text-anchor="middle" font-size="38" fill="black">3<', $svg);
    }

    public function test_first_page_marks_the_first_number_active(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3);
        $this->assertStringContainsString('<circle cx="76" cy="1357" r="30" fill="black"/>', $svg);
        $this->assertStringContainsString('x="76" y="1370" text-anchor="middle" font-weight="bold" font-size="38" fill="white">1<', $svg);
    }

    public function test_last_page_marks_the_last_number_active(): void
    {
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 3, 3);
        $this->assertStringContainsString('<circle cx="276" cy="1357" r="30" fill="black"/>', $svg);
        $this->assertStringContainsString('x="276" y="1370" text-anchor="middle" font-weight="bold" font-size="38" fill="white">3<', $svg);
    }

    // --- Icon-Pille (Nutzerwunsch 2026-08-26: "Monitor/Stoerung/Kalender/
    // Wetter macht mehr Sinn" statt Seitenzahlen) -----------------------------

    public function test_pagination_categories_follow_the_fixed_page_order(): void
    {
        // Reihenfolge wie board_total_pages(): Abfahrten -> Stoerungen ->
        // Kalender -> Schlafschirm ("Wetter", s. Funktionskommentar).
        $this->assertSame(
            [1 => 'monitor', 2 => 'wetter'],
            board_pagination_categories(1, false, false)
        );
        $this->assertSame(
            [1 => 'monitor', 2 => 'monitor', 3 => 'stoerung', 4 => 'kalender', 5 => 'wetter'],
            board_pagination_categories(2, true, true)
        );
    }

    public function test_mqtt_category_sits_between_calendar_and_weather(): void
    {
        // TASK-26: Monitor -> Stoerung -> Kalender -> MQTT -> Wetter.
        $this->assertSame(
            [1 => 'monitor', 2 => 'stoerung', 3 => 'kalender', 4 => 'mqtt', 5 => 'wetter'],
            board_pagination_categories(1, true, true, true)
        );
        // Ohne Kalender ruckt MQTT direkt nach der Stoerung.
        $this->assertSame(
            [1 => 'monitor', 2 => 'stoerung', 3 => 'mqtt', 4 => 'wetter'],
            board_pagination_categories(1, true, false, true)
        );
        // Default false -- bestehende Aufrufer ohne den neuen Parameter
        // duerfen keine MQTT-Seite bekommen.
        $this->assertSame(
            [1 => 'monitor', 2 => 'wetter'],
            board_pagination_categories(1, false, false)
        );
    }

    public function test_categories_replace_digits_with_icons(): void
    {
        // totalPages=4: pillWidth=4*87+20=368, pillStartX=1083-368=715,
        // numberStartX=16+10+50=76, Slots bei 76/176/276/376.
        $categories = board_pagination_categories(1, true, true); // 1:monitor 2:stoerung 3:kalender 4:wetter
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 4, true, $categories);

        // Keine Ziffern mehr im Slot-Text -- stattdessen ein <g>, das die
        // handgezeichnete Form aus board_pagination_icon_svg() traegt.
        $this->assertStringNotContainsString('>2<', $svg);
        $this->assertStringNotContainsString('>3<', $svg);
        $this->assertStringNotContainsString('>4<', $svg);
        $this->assertStringContainsString('<g transform="translate(76,1357) scale(1.5)">', $svg, 'Slot 1 (monitor)');
        $this->assertStringContainsString('<g transform="translate(176,1357) scale(1.5)">', $svg, 'Slot 2 (stoerung)');
        $this->assertStringContainsString('<g transform="translate(276,1357) scale(1.5)">', $svg, 'Slot 3 (kalender)');
        $this->assertStringContainsString('<g transform="translate(376,1357) scale(1.5)">', $svg, 'Slot 4 (wetter)');
    }

    public function test_active_slot_icon_is_white_inactive_is_black(): void
    {
        $categories = board_pagination_categories(1, true, true);
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 2, 4, true, $categories);

        // Slot 2 (Stoerung) ist aktiv -> Dreieck+"!" in weiss auf dem
        // schwarzen Kreis; alle anderen Slots bleiben schwarz auf weiss.
        $this->assertStringContainsString('stroke="white" stroke-width="2.5" stroke-linejoin="round"', $svg);
        $this->assertStringContainsString('fill="white" text-anchor="middle">!</text>', $svg);
        $this->assertStringContainsString('fill="none" stroke="black" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"', $svg, 'Bus-Icon (Slot 1) bleibt schwarz');
    }

    public function test_missing_category_falls_back_to_the_digit(): void
    {
        // Kein Eintrag fuer Seite 2 in der Map -- muss wie ohne Kategorien
        // ueberhaupt rendern (Ziffer), nicht leer bleiben oder crashen.
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 2, true, [1 => 'monitor']);
        $this->assertStringContainsString('x="176" y="1370" text-anchor="middle" font-size="38" fill="black">2<', $svg);
    }

    // --- Nachrichtenanzahl in der Pille (Nutzerwunsch 2026-09-04) ------------

    public function test_message_count_appears_next_to_the_mqtt_icon(): void
    {
        $categories = board_pagination_categories(1, false, false, true);
        // Seite 1 (Monitor) aktiv, der MQTT-Slot also inaktiv.
        $svg = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3, true, $categories, 8);

        $this->assertStringContainsString('>8</text>', $svg);
    }

    public function test_message_count_is_hidden_on_the_active_mqtt_slot(): void
    {
        // Dort liegt der schwarze Kreis (r=30) darunter; Icon + Zahl sind
        // zusammen breiter und ragten sichtbar darueber hinaus. Die Anzahl
        // steht auf dieser Seite ohnehin im Kopf ("Nachrichten (6 von 8)").
        $categories = board_pagination_categories(1, false, false, true);
        $mqttSeite = array_search('mqtt', $categories, true);
        $svg = board_render_stand_and_pagination_svg(
            new DateTimeImmutable('19:13'), (int) $mqttSeite, 3, true, $categories, 8
        );

        $this->assertStringNotContainsString('>8</text>', $svg);
    }

    public function test_pill_geometry_is_unchanged_by_the_message_count(): void
    {
        // Die Firmware rechnet die Pillenbreite unabhaengig aus totalPages
        // nach (touch_zone.cpp) und kennt den Slotinhalt nicht -- waechst die
        // Pille durch die Zahl, wandern die Touchzonen unter den Fingern weg.
        $categories = board_pagination_categories(1, false, false, true);
        $ohne = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3, true, $categories);
        $mit  = board_render_stand_and_pagination_svg(new DateTimeImmutable('19:13'), 1, 3, true, $categories, 12);

        $rect = static fn (string $svg): string => (string) preg_replace('/^.*?(<rect [^>]*>).*$/s', '$1', $svg);
        $this->assertSame($rect($ohne), $rect($mit));
    }
}
