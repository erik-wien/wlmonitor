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

    // --- Kuerzel je Kalender ---------------------------------------------------

    public function test_marker_is_prefixed_to_the_title(): void
    {
        // Nutzerwunsch 2026-09-04: auf dem Board muss zu sehen sein, WEM der
        // Termin gehoert. Der Kalendername steht dort nirgends, Emoji taugen
        // auf dem 1-Bit-Panel nicht -- also "(E)"/"(A)" vor den Titel.
        $tz = new \DateTimeZone('Europe/Vienna');
        $roh = [
            ['title' => 'Zahnarzt', 'all_day' => false, 'calendar' => '🔜 Eriks Termine',
             'start' => '2026-09-04T10:00:00+02:00', 'end' => '2026-09-04T11:00:00+02:00'],
            ['title' => 'Sport', 'all_day' => false, 'calendar' => '🅰️ Armins Termine',
             'start' => '2026-09-04T12:00:00+02:00', 'end' => '2026-09-04T13:00:00+02:00'],
        ];
        $out = board_calendar_normalize_events($roh, $tz, ['🔜 Eriks Termine' => '(E)', '🅰️ Armins Termine' => '(A)']);

        $this->assertSame('(E) Zahnarzt', $out[0]['title']);
        $this->assertSame('(A) Sport', $out[1]['title']);
    }

    public function test_without_a_marker_the_title_stays_untouched(): void
    {
        // Bei einem einzigen Kalender waere eine Markierung an jeder Zeile
        // bloss Rauschen.
        $tz = new \DateTimeZone('Europe/Vienna');
        $roh = [['title' => 'Zahnarzt', 'all_day' => false, 'calendar' => 'Gemeinsam',
                 'start' => '2026-09-04T10:00:00+02:00', 'end' => '2026-09-04T11:00:00+02:00']];

        $this->assertSame('Zahnarzt', board_calendar_normalize_events($roh, $tz, ['Gemeinsam' => ''])[0]['title']);
        $this->assertSame('Zahnarzt', board_calendar_normalize_events($roh, $tz, [])[0]['title']);
    }

    public function test_unknown_calendar_gets_no_marker_instead_of_a_warning(): void
    {
        // Termin aus einem Kalender, fuer den kein Kuerzel hinterlegt ist --
        // etwa direkt nach dem Umbenennen. Darf nichts kaputtmachen.
        $tz = new \DateTimeZone('Europe/Vienna');
        $roh = [['title' => 'Termin', 'all_day' => false, 'calendar' => 'Unbekannt',
                 'start' => '2026-09-04T10:00:00+02:00', 'end' => '2026-09-04T11:00:00+02:00']];

        $this->assertSame('Termin', board_calendar_normalize_events($roh, $tz, ['Anderer' => '(X)'])[0]['title']);
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

    // --- Kalenderstand gehoert in die Fusszeile (Nutzerbefund 2026-09-04) ----

    public function test_calendar_status_is_not_drawn_on_the_page_itself(): void
    {
        // Frueher oben rechts (x=1856). Zusammen mit dem "Stand HH:MM" unten
        // links standen damit ZWEI Stand-Angaben auf einer Seite, und die
        // prominentere bezog sich auf die WL-Abfrage -- Daten, die auf der
        // Kalenderseite gar nicht vorkommen.
        $sicht = board_calendar_select_display($this->cache([0 => 'Termin heute']), $this->heute());
        $items = board_calendar_layout($sicht);

        $this->assertNotSame('', board_calendar_status_text($items), 'Text bleibt im Layout abrufbar');
        $this->assertStringNotContainsString(
            board_calendar_status_text($items),
            board_calendar_render_svg($items),
            'aber er wird nicht mehr auf der Seite selbst gezeichnet'
        );
    }

    public function test_footer_shows_the_calendar_stand_instead_of_the_monitor_stand(): void
    {
        $svg = board_render_stand_and_pagination_svg(
            new DateTimeImmutable('19:13'), 1, 2, true, [], null, 'Kalender 16:06'
        );

        $this->assertStringContainsString('Kalender 16:06', $svg);
        $this->assertStringNotContainsString('Stand 19:13', $svg);
    }

    // --- Kalenderkuerzel als Plakette (Nutzerwunsch 2026-09-04) -------------

    public function test_circled_letter_marker_becomes_a_bare_letter_in_the_badge(): void
    {
        // Den Kreis zeichnen wir selbst -- ein zweiter im Glyph waere doppelt.
        $this->assertSame('E', board_calendar_badge_glyph("\u{24BA}"));
        $this->assertSame('A', board_calendar_badge_glyph("\u{24B6}"));
        $this->assertSame('E', board_calendar_badge_glyph("\u{24D4}"), 'auch klein geschrieben');
    }

    public function test_any_single_character_marker_gets_a_badge(): void
    {
        // "\u{260D}" fuer gemeinsame Termine: stand ohne Plakette als duenner,
        // kleiner Strich neben zwei fetten Plaketten und sah auf dem Panel
        // wie ein Fehler aus (beobachtet 2026-09-04 am gerenderten Bild).
        $this->assertSame("\u{260D}", board_calendar_badge_glyph("\u{260D}"));
        $this->assertSame('*', board_calendar_badge_glyph('*'));
    }

    public function test_multi_character_markers_stay_plain_text(): void
    {
        // Wer Klammern tippt, will Klammern sehen -- und drei Zeichen passen
        // nicht in einen Kreis.
        $this->assertNull(board_calendar_badge_glyph('(A)'));
        $this->assertNull(board_calendar_badge_glyph(''));
        $this->assertNull(board_calendar_badge_glyph('  '));
    }

    public function test_badge_is_drawn_filled_with_a_white_bold_glyph(): void
    {
        $svg = board_calendar_render_marker_badge('E', 218, 300, 48);

        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringContainsString('fill="black"', $svg);
        $this->assertStringContainsString('font-weight="bold"', $svg);
        $this->assertStringContainsString('fill="white">E</text>', $svg);
    }

    public function test_badge_is_exactly_as_wide_as_the_two_characters_it_replaces(): void
    {
        // board_calendar_fit_title() bricht den Titel MIT Kuerzel um und kennt
        // die Plakette nicht. Waere sie breiter als Kuerzel + Leerzeichen,
        // liefe jede markierte Zeile genau um die Differenz ueber die
        // Spaltenkante.
        foreach ([38, 48] as $titleSize) {
            $zweiZeichen = 2 * 17.37 * $titleSize / 39;
            $this->assertEqualsWithDelta(
                $zweiZeichen,
                board_calendar_marker_badge_width($titleSize),
                1.0,
                "Plakettenbreite bei title_size=$titleSize"
            );
        }
    }

    public function test_marker_comes_from_its_own_field_not_from_the_title_text(): void
    {
        // Ein Termin, dessen TITEL zufaellig mit demselben Zeichen beginnt,
        // darf keine Plakette bekommen -- das Kuerzel ist ein eigenes Feld,
        // kein Ratespiel auf dem Titelanfang.
        $ohneKuerzel = board_calendar_render_svg([[
            'type' => 'event', 'time_end' => 190, 'title_x' => 218, 'y' => 300,
            'time' => '14:00', 'lines' => ["\u{24BA} kein Kuerzel gesetzt"], 'marker' => '',
            'title_size' => 48, 'time_size' => 30, 'wrap_lead' => 53,
        ]]);
        $this->assertStringNotContainsString('<circle', $ohneKuerzel);

        $mitKuerzel = board_calendar_render_svg([[
            'type' => 'event', 'time_end' => 190, 'title_x' => 218, 'y' => 300,
            'time' => '14:00', 'lines' => ["\u{24BA} Friseur Dante"], 'marker' => "\u{24BA}",
            'title_size' => 48, 'time_size' => 30, 'wrap_lead' => 53,
        ]]);
        $this->assertStringContainsString('<circle', $mitKuerzel);
        $this->assertStringNotContainsString("\u{24BA}", $mitKuerzel, 'Schriftzeichen selbst kommt nicht mehr vor');
        $this->assertStringContainsString(
            sprintf('<text x="%d"', 218 + board_calendar_marker_badge_width(48)),
            $mitKuerzel,
            'Titeltext beginnt hinter der Plakette'
        );
    }

    public function test_normalize_events_records_the_marker_as_a_field(): void
    {
        $roh = [['title' => 'Friseur', 'all_day' => false, 'calendar' => 'Eriks',
                 'start' => '2026-09-04T14:00:00+02:00', 'end' => '2026-09-04T15:00:00+02:00']];

        $out = board_calendar_normalize_events($roh, new \DateTimeZone('Europe/Vienna'), ['Eriks' => "\u{24BA}"]);

        $this->assertSame("\u{24BA}", $out[0]['marker']);
        $this->assertSame("\u{24BA} Friseur", $out[0]['title'], 'Praefix bleibt im Titel -- der Umbruch rechnet darauf');
    }
}
