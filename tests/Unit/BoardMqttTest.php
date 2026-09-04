<?php
// tests/Unit/BoardMqttTest.php
//
// MQTT-Seite des E-Paper-Boards (TASK-26). Reine Funktionen wie
// inc/board_calendar.php -- kein $con, kein Netz, Zeit kommt als
// DateTimeImmutable herein.
//
// Payload-Format (Nutzervorgabe 2026-08-29): JSON mit "title"/"body", z.B.
// {"title":"Nicht vergessen","body":"Heute trainieren!"}. Kaputtes/kein
// JSON faellt auf title="" + body=Rohtext zurueck (board_mqtt_parse_payload()).

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoardMqttTest extends TestCase
{
    // --- board_mqtt_parse_payload() --------------------------------------------

    public function test_parse_payload_extracts_title_and_body(): void
    {
        $result = board_mqtt_parse_payload('{"title":"Nicht vergessen","body":"Heute trainieren!"}');
        $this->assertSame(['title' => 'Nicht vergessen', 'body' => 'Heute trainieren!'], $result);
    }

    public function test_parse_payload_missing_body_key_yields_empty_body(): void
    {
        $result = board_mqtt_parse_payload('{"title":"Nur Titel"}');
        $this->assertSame(['title' => 'Nur Titel', 'body' => ''], $result);
    }

    public function test_parse_payload_non_json_falls_back_to_raw_text_as_body(): void
    {
        // Kaputtes/kein JSON darf die Nachricht nicht verschlucken.
        $result = board_mqtt_parse_payload('einfach nur Text, kein JSON');
        $this->assertSame(['title' => '', 'body' => 'einfach nur Text, kein JSON'], $result);
    }

    public function test_parse_payload_json_without_title_or_body_keys_falls_back_to_raw_text(): void
    {
        $result = board_mqtt_parse_payload('{"foo":"bar"}');
        $this->assertSame(['title' => '', 'body' => '{"foo":"bar"}'], $result);
    }

    // --- board_mqtt_select_display() ------------------------------------------

    public function test_select_display_normalizes_messages_newest_first_order_preserved(): void
    {
        $cache = ['messages' => [
            ['topic' => 'wlmonitor/board/message', 'payload' => '{"title":"Erste","body":"A"}', 'received_at' => '2026-08-29T08:00:00+02:00'],
            ['topic' => 'wlmonitor/board/message', 'payload' => '{"title":"Zweite","body":"B"}', 'received_at' => '2026-08-29T07:00:00+02:00'],
        ]];
        $now = new DateTimeImmutable('2026-08-29T08:05:00+02:00');

        $result = board_mqtt_select_display($cache, $now);

        $this->assertCount(2, $result['messages']);
        $this->assertSame('Erste', $result['messages'][0]['title']);
        $this->assertSame('A', $result['messages'][0]['body']);
        $this->assertSame('Zweite', $result['messages'][1]['title']);
    }

    public function test_select_display_caps_at_max_messages(): void
    {
        $roh = [];
        for ($i = 0; $i < BOARD_MQTT_MAX_MESSAGES + 5; $i++) {
            $roh[] = ['topic' => 't', 'payload' => json_encode(['title' => 't', 'body' => (string) $i]), 'received_at' => '2026-08-29T08:00:00+02:00'];
        }
        $result = board_mqtt_select_display(['messages' => $roh], new DateTimeImmutable('2026-08-29T08:00:00+02:00'));

        $this->assertCount(BOARD_MQTT_MAX_MESSAGES, $result['messages']);
    }

    public function test_select_display_defends_against_garbage_entries(): void
    {
        $cache = ['messages' => [
            'kein array',
            ['topic' => 123, 'payload' => null],
            ['topic' => '', 'payload' => ''],
        ]];
        $result = board_mqtt_select_display($cache, new DateTimeImmutable());

        // Die kaputten/leeren Eintraege duerfen weder eine Exception werfen
        // noch stillschweigend verschwinden -- sie bekommen Platzhaltertext
        // (gleiches Prinzip wie board_calendar_normalize_events()).
        $this->assertCount(2, $result['messages']);
        $this->assertSame('', $result['messages'][0]['title']);
        $this->assertSame('(leer)', $result['messages'][0]['body']);
        $this->assertSame('(leer)', $result['messages'][1]['body']);
    }

    public function test_select_display_missing_messages_key_yields_empty_list(): void
    {
        $result = board_mqtt_select_display([], new DateTimeImmutable());
        $this->assertSame([], $result['messages']);
    }

    public function test_select_display_unparseable_timestamp_yields_empty_age_not_exception(): void
    {
        $cache = ['messages' => [['topic' => 't', 'payload' => '{"title":"t","body":"p"}', 'received_at' => 'nicht-parsebar']]];
        $result = board_mqtt_select_display($cache, new DateTimeImmutable());

        $this->assertSame('', $result['messages'][0]['age']);
    }

    // --- board_mqtt_format_age() -----------------------------------------------

    public function test_format_age_buckets(): void
    {
        $jetzt = new DateTimeImmutable('2026-08-29T12:00:00+02:00');

        $this->assertSame('gerade eben', board_mqtt_format_age($jetzt->modify('-30 seconds'), $jetzt));
        $this->assertSame('vor 5min', board_mqtt_format_age($jetzt->modify('-5 minutes'), $jetzt));
        $this->assertSame('vor 3h', board_mqtt_format_age($jetzt->modify('-3 hours'), $jetzt));
        $this->assertSame('27.8. 12:00', board_mqtt_format_age($jetzt->modify('-2 days'), $jetzt));
    }

    // --- board_mqtt_layout() ---------------------------------------------------

    public function test_layout_empty_shows_a_note(): void
    {
        $items = board_mqtt_layout(['messages' => []]);

        $this->assertCount(1, $items);
        $this->assertSame('note', $items[0]['type']);
        $this->assertSame('Keine Nachrichten', $items[0]['text']);
    }

    public function test_layout_one_message_produces_one_card(): void
    {
        $selected = ['messages' => [['title' => 'Nicht vergessen', 'body' => 'Heute trainieren!', 'age' => 'vor 1min']]];
        $items = board_mqtt_layout($selected);

        $this->assertCount(1, $items);
        $this->assertSame('card', $items[0]['type']);
        $this->assertSame('Nicht vergessen', $items[0]['title']);
        $this->assertSame(['Heute trainieren!'], $items[0]['lines']);
    }

    public function test_layout_places_cards_across_all_columns(): void
    {
        // Nutzerwunsch 2026-08-29: "dreispaltig von Displayrand zu Rand".
        // Die ersten Karten fuellen die Spalten der Reihe nach von links.
        $roh = [];
        for ($c = 0; $c < BOARD_MQTT_COLUMNS; $c++) {
            $roh[] = ['title' => "K$c", 'body' => 'kurz', 'age' => ''];
        }
        $items = board_mqtt_layout(['messages' => $roh]);

        for ($c = 0; $c < BOARD_MQTT_COLUMNS; $c++) {
            $this->assertSame(board_mqtt_column_x($c), $items[$c]['x']);
            $this->assertSame($items[0]['y'], $items[$c]['y'], 'erste Reihe steht auf gleicher Hoehe');
        }
        // Randbuendig: linke Kante am Seitenrand, rechte Kante am Gegenrand.
        $this->assertSame(16, $items[0]['x']);
        $this->assertSame(1856, $items[BOARD_MQTT_COLUMNS - 1]['x'] + BOARD_MQTT_CARD_W);
    }

    public function test_layout_masonry_puts_the_next_card_in_the_shortest_column(): void
    {
        // Die ersten BOARD_MQTT_COLUMNS Karten fuellen die Spalten der Reihe
        // nach. Ist die erste davon hoch (5 Zeilen) und die uebrigen niedrig,
        // muss die naechste Karte in die zweite Spalte wandern -- nicht zurueck
        // unter die hohe erste.
        $lang = trim(str_repeat('Wortwiederholung ', 40));
        $roh = [['title' => 'Hoch', 'body' => $lang, 'age' => '']];
        for ($i = 1; $i <= BOARD_MQTT_COLUMNS; $i++) {
            $roh[] = ['title' => "Niedrig $i", 'body' => 'kurz', 'age' => ''];
        }
        $items = board_mqtt_layout(['messages' => $roh]);

        // Karte 0..COLUMNS-1 belegen je eine Spalte.
        for ($c = 0; $c < BOARD_MQTT_COLUMNS; $c++) {
            $this->assertSame(board_mqtt_column_x($c), $items[$c]['x'], "Karte $c gehoert in Spalte $c");
        }
        // Die naechste geht in Spalte 1 (kuerzeste; Spalte 0 traegt die hohe Karte).
        $this->assertSame(board_mqtt_column_x(1), $items[BOARD_MQTT_COLUMNS]['x'], 'naechste Karte gehoert in die kuerzeste Spalte');
        $this->assertGreaterThan($items[1]['height'], $items[0]['height'], 'mehr Text = hoehere Karte');
    }

    public function test_layout_card_height_grows_with_the_message_length(): void
    {
        // Nutzerwunsch 2026-08-29: "Durchaus die Groesse anpassen, je nach
        // Laenge der Nachricht."
        $kurz = board_mqtt_layout(['messages' => [['title' => 'T', 'body' => 'kurz', 'age' => '']]]);
        $lang = board_mqtt_layout(['messages' => [['title' => 'T', 'body' => trim(str_repeat('Wortwiederholung ', 40)), 'age' => '']]]);

        $this->assertGreaterThan($kurz[0]['height'], $lang[0]['height']);
    }

    public function test_layout_short_message_keeps_the_postit_minimum_height(): void
    {
        // Ein einzeiliger Zettel soll ein Zettel bleiben, kein Streifen.
        $mitTitel = board_mqtt_layout(['messages' => [['title' => 'T', 'body' => 'kurz', 'age' => '']]]);
        $this->assertGreaterThanOrEqual(BOARD_MQTT_CARD_MIN_H, $mitTitel[0]['height']);

        // Ohne Titel (kaputtes JSON) traegt allein die Mindesthoehe -- die
        // natuerliche Hoehe waere hier nur knapp 200px, also wirklich ein
        // Streifen. Seit die Innenabstaende fuer die 2-Spalten-Fassung
        // gekuerzt wurden (2026-09-04), ist DAS der Fall, in dem die
        // Mindesthoehe ueberhaupt noch greift.
        $ohneTitel = board_mqtt_layout(['messages' => [['title' => '', 'body' => 'kurz', 'age' => '']]]);
        $this->assertSame(BOARD_MQTT_CARD_MIN_H, $ohneTitel[0]['height']);
    }

    public function test_layout_timestamp_always_sits_at_the_card_foot(): void
    {
        // Auch bei Mindesthoehe (Karte groesser als ihr Text) darf der
        // Zeitstempel nicht mitten im leeren Feld haengen.
        $items = board_mqtt_layout(['messages' => [['title' => 'T', 'body' => 'kurz', 'age' => '08:20 · vor 5min']]]);
        $card = $items[0];

        $this->assertSame($card['y'] + $card['height'] - BOARD_MQTT_CARD_PADDING, $card['age_y']);
    }

    public function test_layout_wraps_long_body_and_truncates_beyond_max_lines(): void
    {
        $langerText = str_repeat('Wortwiederholung ', 40);
        $selected = ['messages' => [['title' => 'Titel', 'body' => trim($langerText), 'age' => '']]];
        $items = board_mqtt_layout($selected);

        $this->assertCount(1, $items);
        $this->assertCount(BOARD_MQTT_MAX_BODY_LINES, $items[0]['lines']);
        $this->assertStringEndsWith('…', end($items[0]['lines']));
    }

    public function test_layout_stops_at_the_page_bottom_without_a_note(): void
    {
        // Frueher endete das Raster mit "+ N weitere". Seit den 2-Spalten-
        // Karten (Nutzervorgabe 2026-09-04) kostete der dafuer reservierte
        // Platz eine ganze Kartenreihe -- bei 8 Nachrichten passten nur 4,
        // obwohl 6 hingepasst haetten. Wieviele fehlen, sagt jetzt der
        // Seitenkopf (s. test_render_svg_..._truncated).
        $langerText = str_repeat('Wortwiederholung ', 40);
        $roh = [];
        for ($i = 0; $i < 30; $i++) {
            $roh[] = ['title' => "Titel $i", 'body' => trim($langerText), 'age' => ''];
        }
        $items = board_mqtt_layout(['messages' => $roh]);

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('card', $item['type'], 'kein Notiz-Item mehr');
            // Keine Karte (inkl. Schlagschatten) darf ueber
            // BOARD_DEPARTURES_MAX_Y hinausragen.
            $this->assertLessThanOrEqual(
                BOARD_DEPARTURES_MAX_Y,
                $item['y'] + $item['height'] + BOARD_MQTT_SHADOW
            );
        }
    }

    // --- board_mqtt_truncate_title() -------------------------------------------

    public function test_truncate_title_leaves_short_titles_untouched(): void
    {
        $this->assertSame('Nicht vergessen', board_mqtt_truncate_title('Nicht vergessen'));
    }

    public function test_truncate_title_cuts_long_titles_with_ellipsis(): void
    {
        $lang = str_repeat('a', BOARD_MQTT_TITLE_MAX_CHARS + 20);
        $kurz = board_mqtt_truncate_title($lang);

        $this->assertSame(BOARD_MQTT_TITLE_MAX_CHARS, mb_strlen($kurz, 'UTF-8'));
        $this->assertStringEndsWith('…', $kurz);
    }

    // --- board_mqtt_render_svg(): Escaping ------------------------------------

    /** @return list<array> ein vollstaendiges Karten-Item fuer Render-Tests */
    private function cardItem(array $overrides = []): array
    {
        return [array_merge([
            'type' => 'card', 'id' => '', 'x' => 16, 'y' => 180, 'height' => 220,
            'title' => 'Titel', 'title_y' => 239,
            'age' => '', 'age_y' => 368,
            'close_x' => 568, 'close_y' => 214,
            'body_y' => 291, 'lines' => ['Body'],
        ], $overrides)];
    }

    public function test_render_svg_escapes_hostile_title_and_body(): void
    {
        // Titel UND Body sind Fremdeingabe (jeder mit Broker-Zugang) -- ein
        // </text> darf die SVG-Struktur nicht umbauen.
        $svg = board_mqtt_render_svg($this->cardItem([
            'title' => '</text><script>alert(1)</script>',
            'age'   => 'vor 1min',
            'lines' => ['</text>böse&Zeile'],
        ]));

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;/text&gt;', $svg);
        $this->assertStringContainsString('&amp;Zeile', $svg);
    }

    public function test_render_svg_note_type_renders_grey_text(): void
    {
        $svg = board_mqtt_render_svg([['type' => 'note', 'y' => 160, 'text' => 'Keine Nachrichten']]);

        $this->assertStringContainsString('fill="#808080"', $svg);
        $this->assertStringContainsString('Keine Nachrichten', $svg);
    }

    public function test_render_svg_has_no_header_when_every_message_is_visible(): void
    {
        // Nutzerbefund 2026-09-04: "Die Ueberschrift 'Nachrichten (1)' kannst
        // Du Dir schenken. Die ist redundant" -- die sichtbaren Zettel sind
        // ihre eigene Anzahl.
        $items = board_mqtt_layout(['messages' => array_fill(0, 6, ['title' => 'T', 'body' => 'B', 'age' => ''])]);
        $svg = board_mqtt_render_svg($items, 6);

        $this->assertStringNotContainsString('Nachrichten', $svg);
    }

    public function test_render_svg_header_names_how_many_of_the_messages_fit(): void
    {
        // Passen nicht alle auf die Seite, muss das im Kopf stehen -- sonst
        // sieht das Board schlicht so aus, als gaebe es die anderen nicht.
        // Das ist der EINZIGE Fall, in dem der Kopf noch erscheint.
        $langerText = str_repeat('Wortwiederholung ', 40);
        $roh = array_fill(0, 30, ['title' => 'Titel', 'body' => trim($langerText), 'age' => '']);
        $items = board_mqtt_layout(['messages' => $roh]);

        $svg = board_mqtt_render_svg($items, 30);

        $this->assertStringContainsString(sprintf('%d von 30 Nachrichten', count($items)), $svg);
        $this->assertLessThan(30, count($items), 'Testvoraussetzung: es passen nicht alle');
    }

    public function test_render_svg_omits_header_when_count_not_given(): void
    {
        $svg = board_mqtt_render_svg($this->cardItem());

        $this->assertStringNotContainsString('Nachrichten (', $svg);
    }

    public function test_render_svg_title_is_bold_body_is_regular(): void
    {
        $svg = board_mqtt_render_svg($this->cardItem());

        $this->assertStringContainsString('font-weight="bold" font-size="' . BOARD_MQTT_TITLE_SIZE . '" fill="black">Titel<', $svg);
        $this->assertStringContainsString('font-size="' . BOARD_MQTT_BODY_SIZE . '" fill="black">Body<', $svg);
    }

    public function test_render_svg_draws_shadow_card_and_folded_corner(): void
    {
        // Post-it-Optik (Nutzerwunsch 2026-08-29): Schlagschatten HINTER der
        // Karte (zuerst gezeichnet, damit die weisse Karte ihn an drei Seiten
        // verdeckt), dann die Karte mit ausgesparter Ecke, dann die
        // umgeknickte Lasche.
        $svg = board_mqtt_render_svg($this->cardItem());

        // Schatten: GERASTERT (Nutzerwunsch 2026-08-29 "Grauschattierungen"),
        // nicht volltonschwarz -- ein echter Grauwert wuerde an der harten
        // 1bpp-Schwelle (Luminanz 128, ohne Dithering) komplett kippen.
        $schattenPfad = board_mqtt_card_path(16 + BOARD_MQTT_SHADOW, 180 + BOARD_MQTT_SHADOW, BOARD_MQTT_CARD_W, 220, BOARD_MQTT_FOLD);
        $this->assertStringContainsString('<path d="' . $schattenPfad . '" fill="url(#' . BOARD_MQTT_SHADOW_PATTERN_ID . ')"/>', $svg);
        $this->assertStringNotContainsString('fill="black"/><path', $svg, 'kein Volltonschatten mehr');
        // Musterkachel besteht aus REINEN Schwarz-/Weisspixeln -- damit
        // uebersteht sie die 1bpp-Wandlung unveraendert.
        $this->assertStringContainsString('<pattern id="' . BOARD_MQTT_SHADOW_PATTERN_ID . '"', $svg);
        // Der Schatten muss VOR der Karte stehen, sonst deckt er sie zu.
        $kartenPfad = board_mqtt_card_path(16, 180, BOARD_MQTT_CARD_W, 220, BOARD_MQTT_FOLD);
        $this->assertLessThan(strpos($svg, $kartenPfad), strpos($svg, $schattenPfad));
        // Umgeknickte Ecke.
        $this->assertStringContainsString(board_mqtt_fold_path(16, 180, BOARD_MQTT_CARD_W, 220, BOARD_MQTT_FOLD), $svg);
    }

    public function test_card_path_cuts_the_bottom_right_corner(): void
    {
        // Die Silhouette muss die Ecke unten rechts aussparen -- genau dort
        // sitzt die Lasche, ohne die Aussparung waere kein Knick sichtbar.
        $pfad = board_mqtt_card_path(0, 0, 100, 100, 20);
        $this->assertSame('M 0 0 H 100 V 80 L 80 100 H 0 Z', $pfad);
    }

    public function test_fold_path_lies_inside_the_card_sharing_the_cut_edge(): void
    {
        // Rechter Winkel nach INNEN (oben links), Hypotenuse deckungsgleich
        // mit der Schnittkante des Kartenpfades -- andersherum sieht es wie
        // der Zipfel einer Sprechblase aus statt wie umgeknicktes Papier.
        $pfad = board_mqtt_fold_path(0, 0, 100, 100, 20);
        $this->assertSame('M 80 80 H 100 L 80 100 Z', $pfad);
    }

    // --- Loesch-X (TASK-26, Nutzerwunsch 2026-08-29) ---------------------------

    public function test_message_id_is_stable_and_content_derived(): void
    {
        $msg = ['received_at' => '2026-08-29T08:00:00+02:00', 'payload' => '{"title":"A"}'];

        $this->assertSame(board_mqtt_message_id($msg), board_mqtt_message_id($msg), 'gleiche Nachricht -> gleiche Kennung');
        $this->assertNotSame(
            board_mqtt_message_id($msg),
            board_mqtt_message_id(['received_at' => '2026-08-29T08:00:01+02:00', 'payload' => '{"title":"A"}']),
            'anderer Empfangszeitpunkt -> andere Kennung'
        );
        $this->assertNotSame(
            board_mqtt_message_id($msg),
            board_mqtt_message_id(['received_at' => '2026-08-29T08:00:00+02:00', 'payload' => '{"title":"B"}']),
            'anderer Inhalt -> andere Kennung'
        );
    }

    public function test_message_id_survives_missing_fields(): void
    {
        // Muell im Cache darf keine Exception ausloesen -- gleicher Grundsatz
        // wie ueberall sonst auf der Leseseite.
        $this->assertNotSame('', board_mqtt_message_id([]));
    }

    public function test_select_display_carries_the_id_through(): void
    {
        $roh = ['topic' => 't', 'payload' => '{"title":"A","body":"x"}', 'received_at' => '2026-08-29T08:00:00+02:00'];
        $result = board_mqtt_select_display(['messages' => [$roh]], new DateTimeImmutable());

        $this->assertSame(board_mqtt_message_id($roh), $result['messages'][0]['id']);
    }

    public function test_layout_exposes_a_close_button_per_card(): void
    {
        $items = board_mqtt_layout(['messages' => [['id' => 'abc12345', 'title' => 'T', 'body' => 'x', 'age' => '']]]);
        $card = $items[0];

        // Oben rechts -- unten rechts sitzt die umgeknickte Ecke.
        $this->assertSame($card['x'] + BOARD_MQTT_CARD_W - BOARD_MQTT_CLOSE_INSET, $card['close_x']);
        $this->assertSame($card['y'] + BOARD_MQTT_CLOSE_INSET, $card['close_y']);
    }

    public function test_touch_zones_name_each_close_button_by_message_id(): void
    {
        $items = board_mqtt_layout(['messages' => [
            ['id' => 'aaaa1111', 'title' => 'A', 'body' => 'x', 'age' => ''],
            ['id' => 'bbbb2222', 'title' => 'B', 'body' => 'y', 'age' => ''],
        ]]);
        $zones = board_mqtt_touch_zones($items);

        $this->assertCount(2, $zones);
        $this->assertSame('mqtt_del_aaaa1111', $zones[0]['zone']);
        $this->assertSame('mqtt_del_bbbb2222', $zones[1]['zone']);
        // Trefferflaeche deutlich groesser als das gezeichnete Kreuz.
        $this->assertSame(BOARD_MQTT_CLOSE_TOUCH, $zones[0]['w']);
        $this->assertGreaterThan(2 * BOARD_MQTT_CLOSE_ARM, $zones[0]['w']);
        // Zone um den Kreuzmittelpunkt zentriert.
        $this->assertSame($items[0]['close_x'] - intdiv(BOARD_MQTT_CLOSE_TOUCH, 2), $zones[0]['x']);
    }

    public function test_touch_zones_skip_cards_without_an_id(): void
    {
        // Ein Knopf, der nichts ausloesen kann, darf gar nicht erst
        // angeboten werden.
        $items = board_mqtt_layout(['messages' => [['title' => 'T', 'body' => 'x', 'age' => '']]]);
        $this->assertSame([], board_mqtt_touch_zones($items));
    }

    public function test_render_svg_draws_the_close_cross_only_with_an_id(): void
    {
        $mit = board_mqtt_render_svg($this->cardItem(['id' => 'abc12345', 'close_x' => 500, 'close_y' => 214]));
        $this->assertStringContainsString('stroke-width="3" stroke-linecap="round"', $mit);

        $ohne = board_mqtt_render_svg($this->cardItem(['id' => '', 'close_x' => 500, 'close_y' => 214]));
        $this->assertStringNotContainsString('stroke-width="3" stroke-linecap="round"', $ohne);
    }

    // --- board_mqtt_load(): Datei-Praesenz ------------------------------------

    public function test_load_returns_null_when_no_cache_file_exists(): void
    {
        // board_mqtt_path() zeigt fix auf data/mqtt_cache.json -- in der
        // Testumgebung existiert die Datei nicht, solange kein Subscriber
        // lief. null bedeutet "keine MQTT-Seite ueberhaupt", nicht "leer".
        $pfad = board_mqtt_path();
        if (is_file($pfad)) {
            $this->markTestSkipped('mqtt_cache.json existiert bereits lokal -- Praesenz-Verhalten nicht isoliert testbar.');
        }
        $this->assertNull(board_mqtt_load());
    }

    // --- Verdrahtung in board_render_svg() (TASK-26) --------------------------

    private function minimalFavorite(): array
    {
        return ['id' => 1, 'title' => 'Test', 'stations' => [
            ['diva' => '1', 'name' => 'Station', 'lines' => [
                ['line' => '1', 'platform' => '1', 'towards' => 'Ziel', 'type' => 'bus',
                    'realtime' => true, 'alert' => false, 'departures' => [['in' => 5]]],
            ]],
        ]];
    }

    private function minimalWeather(): array
    {
        return ['available' => true, 'icon_category' => 'klar', 'temp_min' => 15, 'temp_max' => 25,
            'text' => 'Sonnig.', 'text_error' => null];
    }

    public function test_render_svg_dispatches_to_the_mqtt_page_full_width_no_weather_column(): void
    {
        $mqtt = board_mqtt_select_display(
            ['messages' => [['topic' => 'wlmonitor/board/message', 'payload' => '{"title":"Nicht vergessen","body":"Heute trainieren!"}', 'received_at' => '2026-08-29T08:00:00+02:00']]],
            new DateTimeImmutable('2026-08-29T08:05:00+02:00')
        );

        // 1 Abfahrtenseite + MQTT + Schlafschirm = 3 Seiten -- MQTT ist Seite 2.
        $svg = board_render_svg(
            ['Test'], 0, $this->minimalFavorite(), [], 2,
            $this->minimalWeather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, null, null, true, null, $mqtt
        );

        $this->assertStringContainsString('Nicht vergessen', $svg);
        $this->assertStringContainsString('Heute trainieren!', $svg);
        // Vollbreite wie beim Kalender (Kalender-Entscheidung 2026-08-26, hier
        // uebernommen): keine Wetterkarte, keine Spaltentrennlinie x=1113.
        $this->assertStringNotContainsString('Sonnig.', $svg, 'Wetterspalte darf auf der MQTT-Seite nicht erscheinen');
        $this->assertStringNotContainsString('x1="1113"', $svg, 'keine Spaltentrennlinie auf der Vollbreiten-Seite');
    }

    public function test_render_svg_total_pages_includes_the_mqtt_slot(): void
    {
        $mqtt = board_mqtt_select_display(['messages' => []], new DateTimeImmutable());

        // 1 Abfahrtenseite + MQTT + Schlafschirm = 3 -- Seite 3 (Schlafschirm)
        // ist nur erreichbar, wenn totalPages die MQTT-Seite mitzaehlt.
        $svg = board_render_svg(
            ['Test'], 0, $this->minimalFavorite(), [], 3,
            $this->minimalWeather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, ['today' => ['available' => false], 'tomorrow' => ['available' => false]], null, true, null, $mqtt
        );

        $this->assertStringContainsString('>Heute<', $svg, 'Seite 3 muss der Schlafschirm sein');
    }

    public function test_render_svg_without_mqtt_cache_skips_the_slot_entirely(): void
    {
        // $mqtt = null (Default) -- keine MQTT-Seite, Schlafschirm bleibt Seite 2.
        $svg = board_render_svg(
            ['Test'], 0, $this->minimalFavorite(), [], 2,
            $this->minimalWeather(), new DateTimeImmutable(), new DateTimeImmutable(), 78, 2,
            null, null, ['today' => ['available' => false], 'tomorrow' => ['available' => false]]
        );

        $this->assertStringContainsString('>Heute<', $svg, 'ohne Cache ist Seite 2 bereits der Schlafschirm');
    }

    // --- Loeschzonen-Header fuers Geraet (TASK-28) -----------------------------

    public function test_delete_zones_header_is_compact_and_drops_the_prefix(): void
    {
        // Kompaktformat statt JSON, weil die Firmware keinen JSON-Parser hat.
        // Das Praefix "mqtt_del_" waere in jeder Zone dieselben neun Byte --
        // die Firmware setzt es beim Senden wieder davor.
        $items = board_mqtt_layout(['messages' => [
            ['id' => 'aaaaaaaa', 'title' => 'A', 'body' => 'x', 'age' => ''],
            ['id' => 'bbbbbbbb', 'title' => 'B', 'body' => 'y', 'age' => ''],
        ]]);

        $header = board_mqtt_delete_zones_header($items);

        $this->assertMatchesRegularExpression('/^[a-z0-9]+:\d+,\d+,\d+,\d+(;[a-z0-9]+:\d+,\d+,\d+,\d+)*$/', $header);
        $this->assertStringNotContainsString('mqtt_del_', $header);
        $this->assertStringContainsString('aaaaaaaa:', $header);
        $this->assertStringContainsString('bbbbbbbb:', $header);
    }

    public function test_delete_zones_header_matches_the_json_zones(): void
    {
        // Eine Quelle, zwei Serialisierungen -- die Koordinaten muessen
        // deckungsgleich sein, sonst tippt das Geraet woanders hin als der
        // Simulator.
        $items = board_mqtt_layout(['messages' => [
            ['id' => 'aaaaaaaa', 'title' => 'A', 'body' => 'x', 'age' => ''],
        ]]);
        $zone = board_mqtt_touch_zones($items)[0];

        $this->assertSame(
            sprintf('aaaaaaaa:%d,%d,%d,%d', $zone['x'], $zone['y'], $zone['w'], $zone['h']),
            board_mqtt_delete_zones_header($items)
        );
    }

    public function test_delete_zones_header_is_empty_without_cards(): void
    {
        // Leerer Cache -> nur ein Hinweis-Item, keine Karten. board.php setzt
        // den Header dann gar nicht erst.
        $this->assertSame('', board_mqtt_delete_zones_header(board_mqtt_layout(['messages' => []])));
    }
}
