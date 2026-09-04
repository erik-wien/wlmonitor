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
        // Spaltenoberkante 90 + 50 Vorlauf + Versalhoehe des Titels. Aus den
        // Konstanten abgeleitet statt fest verdrahtet: 2026-09-04 wuchs die
        // Schrift auf Wettervorhersage-Groesse, und die fest notierte 160
        // war danach nur noch ein Wert ohne Herleitung.
        $erwarteteTitelBasis = 90 + 50 + (int) round(BOARD_DISRUPTIONS_TITLE_SIZE * 0.8);
        $this->assertSame($erwarteteTitelBasis, $headers[0]['y']);
        $this->assertStringContainsString('Gleisbauarbeiten', $headers[0]['text']);

        // Halbe Beschreibungszeile Luft zwischen Titel und Text
        // (BOARD_DISRUPTIONS_TITLE_GAP, Nutzerwunsch 2026-08-22).
        $lines = array_values(array_filter($items, fn ($i) => $i['type'] === 'disruption_line'));
        $this->assertSame($erwarteteTitelBasis + BOARD_DISRUPTIONS_TITLE_GAP, $lines[0]['y']);

        $svg = board_render_disruptions_svg($items);
        $this->assertStringContainsString(
            sprintf('font-weight="bold" font-size="%d"', BOARD_DISRUPTIONS_TITLE_SIZE), $svg
        );
        // Nutzervorgabe 2026-09-04: der Fliesstext hat exakt die Groesse des
        // Wetter-Fliesstextes. Hier gegen die WETTER-Konstante geprueft, nicht
        // gegen die eigene -- sonst wuerde der Test die Gleichheit, um die es
        // geht, gar nicht bemerken.
        $this->assertSame(46, BOARD_DISRUPTIONS_TEXT_SIZE, 'gleich gross wie der Wetter-Fliesstext');
        $this->assertStringContainsString('font-size="46"', $svg);
        $this->assertStringContainsString('Gleisbauarbeiten', $svg);
        $this->assertStringContainsString('U3: Bauarbeiten', $svg);
        // Nicht mehr auf 3 Zeilen gekuerzt: bei einer ganzen freien Spalte
        // wird der ORF-Text vollstaendig gezeigt (Nutzerwunsch 2026-08-22).
        $this->assertStringNotContainsString('…', $svg);
        // Nur das letzte WORT pruefen: seit der Fliesstext auf 46px steht
        // (2026-09-04), passen 47 statt 67 Zeichen in die Zeile, und der Satz
        // bricht zwischen "bis" und "Augasse." um -- die alte Suche nach dem
        // ganzen Satz haette also den Umbruch gemeldet, nicht fehlenden Text.
        $this->assertStringContainsString('Augasse.', $svg, 'Ende der langen Meldung muss noch da sein');
    }

    public function test_description_is_truncated_only_when_the_column_really_runs_out(): void
    {
        // Kuenstlich ueberlanger Text: die Stoerungsseite bricht bewusst nicht
        // auf eine Folgeseite um, also muss sie am Spaltenende doch kuerzen --
        // aber eben erst dort und nicht schon nach 3 Zeilen.
        $alerts = [$this->alert('Titel', str_repeat('Sehr lange Stoerungsmeldung. ', 200), ['5'])];

        $items = board_layout_disruptions($alerts);
        $lines = array_values(array_filter($items, fn ($i) => $i['type'] === 'disruption_line'));

        $this->assertGreaterThan(3, count($lines), 'deutlich mehr als die frueheren 3 Zeilen');
        $this->assertLessThanOrEqual(BOARD_DEPARTURES_MAX_Y, end($lines)['y'], 'darf nicht in die Stand-/Pagination-Leiste ragen');
        $this->assertStringEndsWith('…', end($lines)['text']);
    }

    public function test_empty_alerts_render_nothing(): void
    {
        $this->assertSame([], board_layout_disruptions([]));
        $this->assertSame('', board_render_disruptions_svg([]));
    }
}
