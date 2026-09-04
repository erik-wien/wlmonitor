<?php
// tests/Unit/BoardCalendarTest.php
//
// Kalenderseite des E-Paper-Boards. Bis 2026-09-04 gab es hier GAR KEINE
// Tests -- aufgefallen, als der Umbau auf mehrere Tage (Nutzerwunsch:
// "einfach die naechsten, like Morgen, Sonntag, Montag") keinen einzigen
// Test zum Scheitern brachte.
//
// Reine Funktionen: kein $con, kein Netz, Zeit kommt als DateTimeImmutable
// herein -- der Server entscheidet gegen seine eigene Uhr, nicht gegen ein
// im Cache mitgeschriebenes "heute".

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardCalendarTest extends TestCase
{
    private function heute(): DateTimeImmutable
    {
        // Ein Freitag -- damit die Wochentagsnamen der Folgetage eindeutig sind.
        return new DateTimeImmutable('2026-09-04 08:30', new \DateTimeZone('Europe/Vienna'));
    }

    /** Cache-Gerüst mit Terminen je Tagesversatz. */
    private function cache(array $terminNachVersatz, ?string $received = null): array
    {
        $tag0 = $this->heute()->setTime(0, 0);
        $days = [];
        foreach ($terminNachVersatz as $versatz => $titel) {
            $d = $tag0->modify('+' . $versatz . ' day');
            $events = [];
            foreach ((array) $titel as $t) {
                $events[] = [
                    'title' => $t,
                    'all_day' => false,
                    'start' => $d->setTime(10, 0)->format(DATE_ATOM),
                    'end'   => $d->setTime(11, 0)->format(DATE_ATOM),
                ];
            }
            $days[] = ['date' => $d->format('Y-m-d'), 'events' => $events];
        }
        return [
            'received_at' => $received ?? $this->heute()->format(DATE_ATOM),
            'days' => $days,
        ];
    }

    // --- Tagesueberschriften ---------------------------------------------------

    public function test_day_label_uses_heute_morgen_then_weekday_names(): void
    {
        $heute = $this->heute()->setTime(0, 0); // Freitag

        $this->assertSame('HEUTE',      board_calendar_day_label($heute, $heute));
        $this->assertSame('MORGEN',     board_calendar_day_label($heute->modify('+1 day'), $heute));
        $this->assertSame('SONNTAG',    board_calendar_day_label($heute->modify('+2 day'), $heute));
        $this->assertSame('MONTAG',     board_calendar_day_label($heute->modify('+3 day'), $heute));
        $this->assertSame('DONNERSTAG', board_calendar_day_label($heute->modify('+6 day'), $heute));
    }

    public function test_day_label_adds_the_date_once_the_weekday_repeats(): void
    {
        // Ab sieben Tagen waere "FREITAG" mehrdeutig -- welcher Freitag?
        $heute = $this->heute()->setTime(0, 0);
        $this->assertSame('FREITAG 11.9.', board_calendar_day_label($heute->modify('+7 day'), $heute));
    }

    public function test_day_label_is_language_independent_of_server_locale(): void
    {
        // Bewusst ausformulierte Namen statt strftime(): auf einem Server ohne
        // deutsche Locale stuende sonst still "SUNDAY" auf dem Board.
        $heute = $this->heute()->setTime(0, 0);
        $vorher = setlocale(LC_TIME, '0');
        setlocale(LC_TIME, 'C');
        $this->assertSame('SONNTAG', board_calendar_day_label($heute->modify('+2 day'), $heute));
        setlocale(LC_TIME, $vorher);
    }

    // --- Tagesauswahl ----------------------------------------------------------

    public function test_shows_the_next_days_that_have_events(): void
    {
        // Termine heute, uebermorgen und in 4 Tagen -> genau diese Tage.
        $sicht = board_calendar_select_display(
            $this->cache([0 => 'Heute-Termin', 2 => 'Sonntag-Termin', 4 => 'Dienstag-Termin']),
            $this->heute()
        );

        $labels = array_column($sicht['days'], 'label');
        $this->assertSame(['HEUTE', 'MORGEN', 'SONNTAG', 'DIENSTAG'], $labels);
    }

    public function test_today_and_tomorrow_stay_even_when_empty(): void
    {
        // "Nichts los" ist dort selbst die Information -- anders als bei einem
        // leeren Tag naechste Woche.
        $sicht = board_calendar_select_display($this->cache([4 => 'Spaeter']), $this->heute());

        $labels = array_column($sicht['days'], 'label');
        $this->assertSame('HEUTE', $labels[0]);
        $this->assertSame('MORGEN', $labels[1]);
        $this->assertSame([], $sicht['days'][0]['events']);
    }

    public function test_empty_days_after_tomorrow_are_skipped(): void
    {
        // Sonst verdraengte eine Reihe von "Keine Termine" die echten
        // Eintraege spaeterer Tage von der Seite.
        $sicht = board_calendar_select_display($this->cache([5 => 'Mittwoch-Termin']), $this->heute());

        $labels = array_column($sicht['days'], 'label');
        $this->assertNotContains('SONNTAG', $labels);
        $this->assertContains('MITTWOCH', $labels);
    }

    public function test_no_data_yields_a_clear_status_not_a_false_free_day(): void
    {
        // Faelschlich "keine Termine" zu behaupten waere schlimmer als zu
        // sagen, dass Daten fehlen.
        $sicht = board_calendar_select_display([], $this->heute());

        $this->assertFalse($sicht['available']);
        $this->assertSame('Keine Kalenderdaten', $sicht['status_text']);
        $this->assertSame('missing', $sicht['days'][0]['state']);
    }

    public function test_stale_cache_keeps_the_events_and_says_so(): void
    {
        // Nach dem Wetter-Vorbild: nur das Label degradiert, die Termine
        // bleiben stehen.
        $alt = $this->heute()->modify('-5 hours')->format(DATE_ATOM);
        $sicht = board_calendar_select_display($this->cache([0 => 'Termin'], $alt), $this->heute());

        $this->assertTrue($sicht['stale']);
        $this->assertStringContainsString('veraltet', $sicht['status_text']);
        $this->assertNotEmpty($sicht['days'][0]['events']);
    }

    // --- Layout ----------------------------------------------------------------

    public function test_today_stays_left_and_further_days_stack_on_the_right(): void
    {
        // Nutzervorgabe 2026-09-04: "linke Spalte: heute, rechte spalte morgen
        // und weitere Termine bis die Spalte voll ist". Heute behaelt damit
        // seinen festen Platz, egal wie viel die Folgetage mitbringen.
        $viele = [];
        for ($t = 0; $t < 5; $t++) {
            $viele[$t] = ['Termin A', 'Termin B'];
        }
        $items = board_calendar_layout(board_calendar_select_display($this->cache($viele), $this->heute()));

        $kopf = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'day_header') {
                $kopf[] = ['text' => $it['text'], 'x' => $it['x']];
            }
        }

        $linkeX  = BOARD_CALENDAR_COLUMNS[0]['title_x'];
        $rechteX = BOARD_CALENDAR_COLUMNS[1]['title_x'];

        $this->assertSame('HEUTE', $kopf[0]['text']);
        $this->assertSame($linkeX, $kopf[0]['x'], 'heute gehoert nach links');

        // Alles Weitere steht rechts -- und zwar mehr als nur "morgen".
        $rechts = array_values(array_filter($kopf, static fn ($k) => $k['x'] === $rechteX));
        $this->assertGreaterThan(1, count($rechts), 'rechte Spalte traegt mehrere Tage');
        $this->assertSame('MORGEN', $rechts[0]['text']);
        $this->assertCount(1, array_filter($kopf, static fn ($k) => $k['x'] === $linkeX), 'links steht NUR heute');
    }

    public function test_a_busy_today_does_not_push_the_other_days_away(): void
    {
        // Laeuft die linke Spalte ueber, darf das die rechte nicht leeren --
        // die beiden Spalten sind unabhaengig.
        $viele = [0 => array_fill(0, 30, 'Vollgepackter Tag'), 1 => ['Einkaufen'], 2 => ['Brunch']];
        $items = board_calendar_layout(board_calendar_select_display($this->cache($viele), $this->heute()));

        $texte = array_column(array_filter($items, static fn ($i) => ($i['type'] ?? '') === 'day_header'), 'text');
        $this->assertContains('MORGEN', $texte);
        $this->assertContains('SONNTAG', $texte);
    }

    public function test_nothing_is_drawn_below_the_content_boundary(): void
    {
        // Alles unterhalb BOARD_DEPARTURES_MAX_Y liefe in die
        // Navigationszeile hinein.
        $viele = [];
        for ($t = 0; $t < 7; $t++) {
            $viele[$t] = ['Ein ziemlich langer Termintitel zum Umbrechen', 'Zweiter', 'Dritter', 'Vierter'];
        }
        $items = board_calendar_layout(board_calendar_select_display($this->cache($viele), $this->heute()));

        foreach ($items as $it) {
            if (isset($it['y']) && ($it['type'] ?? '') !== 'status') {
                $this->assertLessThanOrEqual(BOARD_DEPARTURES_MAX_Y, $it['y'], ($it['type'] ?? '?') . ' ragt in die Navigationszeile');
            }
        }
    }

    public function test_empty_day_shows_a_note_instead_of_a_blank_column(): void
    {
        $items = board_calendar_layout(board_calendar_select_display($this->cache([0 => []]), $this->heute()));

        $texte = array_column(array_filter($items, static fn ($i) => ($i['type'] ?? '') === 'note'), 'text');
        $this->assertContains('Keine Termine', $texte);
    }
}
