<?php
// inc/board_mqtt.php
//
// MQTT-Seite des E-Paper-Boards (TASK-26, Nutzerwunsch 2026-08-29): zeigt die
// zuletzt empfangenen MQTT-Nachrichten, neueste oben.
//
// Datenherkunft: scripts/akadbrain/mqtt_subscriber.py, ein DAUERHAFT laufender
// Prozess (launchd KeepAlive, kein periodischer Task wie der Wetter-Cron oder
// calsync.swift) -- MQTT ist push/pub-sub, ein Poll wuerde Nachrichten
// zwischen zwei Laeufen verpassen. Schreibt data/mqtt_cache.json bei jeder
// empfangenen Nachricht atomar neu, Ringpuffer nach Anzahl (kein Zeit-TTL).
//
// Aufbau wie inc/board_calendar.php: I/O-Teil klar abgegrenzt, Rest reine
// Funktionen (kein $con, kein Netz, kein date()/time() -- Zeit kommt als
// DateTimeImmutable herein), damit das Layout ohne Hardware testbar bleibt.
declare(strict_types=1);

// --- I/O ---------------------------------------------------------------------

function board_mqtt_path(): string
{
    return __DIR__ . '/../data/mqtt_cache.json';
}

/**
 * null  = keine Datei -> es gibt gar keine MQTT-Seite
 * []    = Datei da, aber unlesbar/kein JSON -> Seite bleibt, meldet "unlesbar"
 * array = dekodierter Cache
 *
 * Gleiche Dreiteilung wie board_calendar_load() -- die Seiten-EXISTENZ darf
 * nur an der Dateipraesenz haengen, nie an Inhalt/Alter, sonst verschieben
 * sich die Seitenindizes unter dem gespeicherten activePage des Geraets.
 */
function board_mqtt_load(): ?array
{
    $pfad = board_mqtt_path();
    if (!is_file($pfad)) {
        return null;
    }
    $roh = @file_get_contents($pfad);
    if ($roh === false) {
        return [];
    }
    $daten = json_decode($roh, true);
    return is_array($daten) ? $daten : [];
}

// --- Schutzgrenzen fuer den Renderer ------------------------------------------
//
// Der Subscriber laeuft lokal und schreibt sauber. Die Grenzen stehen trotzdem
// auf der LESESEITE: diese Daten landen in einem BILD, und Topic/Payload sind
// FREMDEINGABE -- jeder mit Broker-Zugang bestimmt den Text (anders als beim
// Kalender, wo nur Erik/Armin/geteilte Kalender Titel liefern). Gleicher
// Grundsatz wie board_calendar.php: eine PHP-Warning mitten in einer PNG-
// Antwort ist am Geraet nicht zu diagnostizieren.
const BOARD_MQTT_MAX_MESSAGES = 20;
const BOARD_MQTT_MAX_PAYLOAD_CHARS = 500;
const BOARD_MQTT_MAX_TOPIC_CHARS = 200;

/**
 * Stabile Kennung einer Cache-Nachricht, abgeleitet aus ihrem Inhalt.
 *
 * Bewusst KEIN Listenindex: zwischen dem Rendern einer Seite und dem Antippen
 * des Loesch-X kann eine neue Nachricht eintreffen und alle Indizes um eins
 * verschieben -- getippt wuerde dann die falsche Karte geloescht. Die Kennung
 * wird auf beiden Seiten aus denselben Rohfeldern gerechnet (Renderer und
 * board_mqtt_delete()), ohne dass das Cache-Format sie mitspeichern muss.
 */
function board_mqtt_message_id(array $rawMessage): string
{
    $received = is_string($rawMessage['received_at'] ?? null) ? $rawMessage['received_at'] : '';
    $payload  = is_string($rawMessage['payload'] ?? null) ? $rawMessage['payload'] : '';

    return substr(sha1($received . '|' . $payload), 0, 8);
}

/**
 * Entfernt die Nachricht mit dieser Kennung aus dem Cache. Schreibt atomar
 * (tmp + rename) wie der Subscriber, mit exklusiver Sperre gegen ein
 * gleichzeitiges Schreiben von dort.
 *
 * Gegenstueck auf der Schreibseite: scripts/akadbrain/mqtt_subscriber.py liest
 * die Datei vor JEDEM Schreiben neu (read_messages()) -- ohne das wuerde eine
 * hier geloeschte Nachricht beim naechsten Telegramm aus dem Speicherstand
 * des Subscribers wieder auftauchen.
 *
 * @return bool true, wenn tatsaechlich etwas entfernt wurde
 */
function board_mqtt_delete(string $id): bool
{
    $pfad = board_mqtt_path();
    if (!is_file($pfad)) {
        return false;
    }

    $fp = @fopen($pfad, 'c+');
    if ($fp === false) {
        return false;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }
        $roh = stream_get_contents($fp);
        $daten = json_decode((string) $roh, true);
        if (!is_array($daten) || !is_array($daten['messages'] ?? null)) {
            return false;
        }

        $vorher = count($daten['messages']);
        $daten['messages'] = array_values(array_filter(
            $daten['messages'],
            static fn ($msg): bool => !is_array($msg) || board_mqtt_message_id($msg) !== $id
        ));
        if (count($daten['messages']) === $vorher) {
            return false;
        }

        $tmp = $pfad . '.tmp';
        if (@file_put_contents($tmp, json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            return false;
        }
        return @rename($tmp, $pfad);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// --- Anzeige-Auswahl -----------------------------------------------------------

/**
 * Nutzervorgabe 2026-08-29: der Payload ist JSON mit "title"/"body", z.B.
 * {"title":"Nicht vergessen","body":"Heute trainieren!"}. Kaputtes/kein JSON
 * wird NICHT verschluckt -- der Rohtext landet als body mit leerem title,
 * sonst waere eine falsch formatierte Nachricht spurlos weg (gleiches
 * Prinzip wie board_calendar_normalize_events() mit unlesbaren Zeiten).
 *
 * @return array{title: string, body: string}
 */
function board_mqtt_parse_payload(string $payload): array
{
    $parsed = json_decode($payload, true);
    if (is_array($parsed) && (is_string($parsed['title'] ?? null) || is_string($parsed['body'] ?? null))) {
        return [
            'title' => is_string($parsed['title'] ?? null) ? mb_substr($parsed['title'], 0, BOARD_MQTT_MAX_TOPIC_CHARS) : '',
            'body'  => is_string($parsed['body'] ?? null) ? mb_substr($parsed['body'], 0, BOARD_MQTT_MAX_PAYLOAD_CHARS) : '',
        ];
    }
    return ['title' => '', 'body' => mb_substr($payload, 0, BOARD_MQTT_MAX_PAYLOAD_CHARS)];
}

/**
 * Baut aus dem Cache die Anzeigeliste. Kein "veraltet"-Konzept wie beim
 * Wetter/Kalender: eine leere Liste heisst schlicht "keine Nachrichten", ein
 * Push-Protokoll ohne festen Takt hat keine erwartete Aktualisierungs-
 * frequenz, gegen die man "alt" definieren koennte.
 */
function board_mqtt_select_display(array $cache, DateTimeImmutable $now): array
{
    $wien = new DateTimeZone('Europe/Vienna');
    $jetzt = $now->setTimezone($wien);

    $roh = is_array($cache['messages'] ?? null) ? $cache['messages'] : [];
    $nachrichten = [];
    foreach ($roh as $msg) {
        if (count($nachrichten) >= BOARD_MQTT_MAX_MESSAGES) {
            break;
        }
        if (!is_array($msg)) {
            continue;
        }

        $payloadRaw = is_string($msg['payload'] ?? null) ? $msg['payload'] : '';
        ['title' => $title, 'body' => $body] = board_mqtt_parse_payload($payloadRaw);
        if ($title === '' && $body === '') {
            $body = '(leer)';
        }

        $empfangenAt = null;
        if (is_string($msg['received_at'] ?? null)) {
            try {
                $empfangenAt = (new DateTimeImmutable($msg['received_at']))->setTimezone($wien);
            } catch (Throwable) {
                $empfangenAt = null;
            }
        }

        $nachrichten[] = [
            'id'    => board_mqtt_message_id($msg),
            'title' => $title,
            'body'  => $body,
            // Uhrzeit + relatives Alter (Nutzerwunsch 2026-08-29: "Zeig auch
            // die Uhrzeit") -- die Uhrzeit allein aendert sich zwischen zwei
            // Renderings NICHT (sie haengt an received_at, nicht an "jetzt"),
            // anders als "vor Xmin"; zusammen zeigen sie sowohl den festen
            // Zeitpunkt als auch die Frische auf einen Blick.
            'age'   => $empfangenAt !== null
                ? $empfangenAt->format('H:i') . ' · ' . board_mqtt_format_age($empfangenAt, $jetzt)
                : '',
        ];
    }

    return ['messages' => $nachrichten];
}

/** Grob gestuft ("vor Xmin"/"vor Xh") statt sekundengenau -- Sekunden waeren
 *  bei jedem Rendering ein anderer Text und wuerden board_frame_diff() bei
 *  sonst unveraenderten Nachrichten unnoetig ein Vollbild erzwingen. */
function board_mqtt_format_age(DateTimeImmutable $empfangen, DateTimeImmutable $jetzt): string
{
    $diffSeconds = $jetzt->getTimestamp() - $empfangen->getTimestamp();
    if ($diffSeconds < 60) {
        return 'gerade eben';
    }
    if ($diffSeconds < 3600) {
        return 'vor ' . intdiv($diffSeconds, 60) . 'min';
    }
    if ($diffSeconds < 86400) {
        return 'vor ' . intdiv($diffSeconds, 3600) . 'h';
    }
    return $empfangen->format('j.n. H:i');
}


// --- Layout ---------------------------------------------------------------
//
// Post-it-Wand (Nutzerwunsch 2026-08-29: "Sollen wirklich wie Post-it's
// aussehen. In zwei Spalten." + "Durchaus die Groesse anpassen, je nach
// Laenge der Nachricht."). Vorher lagen die Nachrichten als breite Baender
// untereinander -- das las sich wie eine Tabelle, nicht wie ein Zettel.
//
// ZWEI SPALTEN, HOEHE NACH INHALT: klassisches Masonry -- jede neue Karte
// kommt in die Spalte, die gerade kuerzer ist. Bei fester Reihenhoehe haetten
// kurze Nachrichten entweder riesige Leerflaechen oder lange Nachrichten
// keinen Platz; so waechst jeder Zettel mit seinem Text, und die beiden
// Spalten bleiben trotzdem etwa gleich hoch.
//
// Eine Mindesthoehe bleibt: ein einzeiliger Zettel soll ein Zettel sein, kein
// Streifen. Der Rest der Karte bleibt dann bewusst leer -- wie bei einem
// echten Klebezettel, auf dem nur ein Wort steht.
//
// Zeichenbudget nach der etablierten Herleitung (0,44538 px/Zeichen bei 1px
// Schriftgroesse, 8% Sicherheitsabstand, s. BOARD_DEPARTURE_DESTINATION_MAX_CHARS),
// Innenbreite 897 - 2*32 = 833:
//   Body  38px:  floor(833 / (38*0.44538) * 0.92) = 45
//   Titel 34px:  floor(833 / (34*0.44538) * 0.92) = 50

// DREI Spalten randbuendig (Nutzerwunsch 2026-08-29). Bei zwei Spalten ueber
// die volle Breite waere jede Karte ~900px breit und bei realistischen
// Textmengen nur ~300px hoch -- ein 3:1-Band, das wie eine Tabellenzeile
// aussieht, nicht wie ein Klebezettel. Drei Spalten loesen beides zugleich:
// randbuendig UND nah an der quadratischen Post-it-Form.
const BOARD_MQTT_CARD_W = 586;
const BOARD_MQTT_COL_GAP = 41;
const BOARD_MQTT_COLUMNS = 3;
/** Randbuendig wie die uebrigen Seiten (Abfahrtenliste, Touch-Leiste):
 *  16 .. 1856, also 3*586 + 2*41 = 1840 (Nutzerwunsch 2026-08-29:
 *  "dreispaltig von Displayrand zu Rand"). */
const BOARD_MQTT_GRID_X = 16;
const BOARD_MQTT_GRID_TOP = 150;
const BOARD_MQTT_ROW_GAP = 40;
const BOARD_MQTT_CARD_PADDING = 32;
/** Ein einzeiliger Zettel soll ein Zettel bleiben, kein Streifen. */
const BOARD_MQTT_CARD_MIN_H = 280;
/** Groesse der umgeknickten Ecke unten rechts ("dog ear") -- das eine
 *  Merkmal, an dem ein Post-it auch in reinem Schwarzweiss sofort erkennbar
 *  ist (Farbe steht auf 1bpp nicht zur Verfuegung). */
const BOARD_MQTT_FOLD = 44;
/** Schatten als GERASTERTE Flaeche statt Volltonschwarz (Nutzerwunsch
 *  2026-08-29: "Schatten soll die Grauschattierungen nuetzen").
 *
 *  Ein echter Grauwert (fill="#a0a0a0") ginge NICHT: png_to_1bpp_packed()
 *  schwellt hart bei Luminanz 128, ohne Dithering -- jedes Grau kippt am
 *  Geraet entweder komplett auf Weiss (unsichtbar) oder auf Schwarz (wie
 *  vorher). Ein Schachbrettmuster aus reinen Schwarz-/Weisspixeln ueber-
 *  steht die Schwelle dagegen unveraendert und liest sich aus Zimmer-
 *  entfernung als Grau (~230 dpi Panel). Breiter als der alte Volltonschatten,
 *  weil eine gerasterte Flaeche optisch leichter wirkt. */
const BOARD_MQTT_SHADOW = 12;
/** Kantenlaenge der Musterkachel in Pixeln. 2 = feinstes Raster, das der
 *  1bpp-Wandler noch exakt wiedergibt. */
const BOARD_MQTT_SHADOW_TILE = 2;
const BOARD_MQTT_SHADOW_PATTERN_ID = 'mqttShadowHatch';

// Zeichenbudget nach der etablierten Herleitung (0,44538 px/Zeichen bei 1px
// Schriftgroesse, 8% Sicherheitsabstand), Innenbreite 700 - 2*40 = 620:
//   Body  36px:  floor(522 / (36*0.44538) * 0.92) = 29
//   Titel 32px:  floor(522 / (32*0.44538) * 0.92) = 33; davon gehen rechts
//                nochmal ~60px fuers Loesch-X ab -> 29
/** Loesch-X oben rechts auf jeder Karte (Nutzerwunsch 2026-08-29: "auf den
 *  einzelnen Karten ein lösch-x"). Oben rechts, weil unten rechts die
 *  umgeknickte Ecke sitzt. Die Trefferflaeche ist deutlich groesser als das
 *  gezeichnete Kreuz -- getippt wird mit dem Finger, nicht mit dem Mauszeiger
 *  (gleiche Ueberlegung wie bei der auf den Bildschirmrand ausgedehnten
 *  Favoritenleiste, s. touch_zone.cpp FAVORITE_ROW_BOTTOM). */
const BOARD_MQTT_CLOSE_TOUCH = 72;
/** Halbe Kantenlaenge des gezeichneten Kreuzes. */
const BOARD_MQTT_CLOSE_ARM = 11;
/** Mittelpunkt des Kreuzes, gemessen von der rechten/oberen Kartenkante. */
const BOARD_MQTT_CLOSE_INSET = 34;

const BOARD_MQTT_TITLE_SIZE = 32;
const BOARD_MQTT_TITLE_MAX_CHARS = 29;
const BOARD_MQTT_AGE_SIZE = 24;
const BOARD_MQTT_BODY_SIZE = 36;
const BOARD_MQTT_BODY_MAX_CHARS = 29;
const BOARD_MQTT_MAX_BODY_LINES = 5;
const BOARD_MQTT_BODY_LEAD = 46;

// Baselines als feste Versaetze statt Boxmodell (wie im Rest der Codebasis,
// s. Datei-Kommentar board_calendar.php); 0,8 * Schriftgroesse naehert die
// Versalhoehe an.
const BOARD_MQTT_TITLE_ASCENT = 26; // round(32 * 0.8)
const BOARD_MQTT_BODY_ASCENT  = 29; // round(36 * 0.8)
const BOARD_MQTT_AGE_DESCENT  = 10;
/** Abstand Titel-Baseline -> erste Body-Baseline. */
const BOARD_MQTT_TITLE_TO_BODY = 52;
/** Mindestabstand letzte Body-Baseline -> Zeitstempel-Baseline. */
const BOARD_MQTT_BODY_TO_AGE = 48;

function board_mqtt_truncate_title(string $text): string
{
    if (mb_strlen($text, 'UTF-8') <= BOARD_MQTT_TITLE_MAX_CHARS) {
        return $text;
    }
    $budget = BOARD_MQTT_TITLE_MAX_CHARS - 1;
    return rtrim(mb_substr($text, 0, $budget, 'UTF-8')) . '…';
}

/** x-Position einer Spalte (0-basiert). */
function board_mqtt_column_x(int $col): int
{
    return BOARD_MQTT_GRID_X + $col * (BOARD_MQTT_CARD_W + BOARD_MQTT_COL_GAP);
}

/**
 * Baut die Post-it-Wand. Zwei Spalten, Hoehe je Karte nach Textmenge, neue
 * Karte immer in die kuerzere Spalte (Masonry). Passt eine Karte nicht mehr,
 * endet das Raster mit "+ N weitere" -- dieselbe Ueberlauf-Idee wie beim
 * Kalender: VOR dem Setzen pruefen, ob danach der Hinweis selbst noch passt,
 * sonst waere ausgerechnet der Hinweis das, was ueberlaeuft.
 *
 * @return list<array>
 */
function board_mqtt_layout(array $selected): array
{
    $nachrichten = $selected['messages'];
    if ($nachrichten === []) {
        return [[
            'type' => 'note',
            'y'    => BOARD_MQTT_GRID_TOP + BOARD_MQTT_CARD_PADDING + BOARD_MQTT_BODY_ASCENT,
            'text' => 'Keine Nachrichten',
        ]];
    }

    $items = [];
    // Laufender Fuss je Spalte (naechste freie y-Position).
    $columnY = array_fill(0, BOARD_MQTT_COLUMNS, BOARD_MQTT_GRID_TOP);
    $anzahl = count($nachrichten);
    $noteHeight = BOARD_MQTT_BODY_SIZE + BOARD_MQTT_ROW_GAP;
    $gesetzt = 0;

    foreach ($nachrichten as $i => $msg) {
        $zeilen = board_wrap_text($msg['body'], BOARD_MQTT_BODY_MAX_CHARS);
        if (count($zeilen) > BOARD_MQTT_MAX_BODY_LINES) {
            $zeilen = array_slice($zeilen, 0, BOARD_MQTT_MAX_BODY_LINES);
            $letzte = BOARD_MQTT_MAX_BODY_LINES - 1;
            $zeilen[$letzte] = rtrim($zeilen[$letzte]) . '…';
        }

        // Kuerzeste Spalte suchen; bei Gleichstand die linke (stabile,
        // deterministische Reihenfolge -- board_frame_diff() haengt daran,
        // dass dasselbe Datenmaterial dasselbe Bild ergibt).
        $col = 0;
        for ($c = 1; $c < BOARD_MQTT_COLUMNS; $c++) {
            if ($columnY[$c] < $columnY[$col]) {
                $col = $c;
            }
        }

        $y = $columnY[$col];
        $innerTop = $y + BOARD_MQTT_CARD_PADDING;
        // Ohne Titel (kaputtes/kein JSON, s. board_mqtt_parse_payload())
        // ruecken die Textzeilen nach oben, statt eine leere Zeile zu lassen.
        $hatTitel = $msg['title'] !== '';
        $titleBaseline = $innerTop + BOARD_MQTT_TITLE_ASCENT;
        $bodyFirst = $hatTitel
            ? $titleBaseline + BOARD_MQTT_TITLE_TO_BODY
            : $innerTop + BOARD_MQTT_BODY_ASCENT;
        $bodyLast = $bodyFirst + (count($zeilen) - 1) * BOARD_MQTT_BODY_LEAD;

        $natuerlicheHoehe = ($bodyLast + BOARD_MQTT_BODY_TO_AGE + BOARD_MQTT_AGE_DESCENT
            + BOARD_MQTT_CARD_PADDING) - $y;
        $hoehe = max(BOARD_MQTT_CARD_MIN_H, $natuerlicheHoehe);

        // Der Zeitstempel sitzt IMMER am Fuss des Zettels, auch wenn die Karte
        // wegen der Mindesthoehe groesser ist als ihr Text -- sonst haette ein
        // kurzer Zettel den Stempel mitten im leeren Feld stehen.
        $ageBaseline = $y + $hoehe - BOARD_MQTT_CARD_PADDING;

        $restNachDieser = $anzahl - $i - 1;
        $reserve = $restNachDieser > 0 ? $noteHeight : 0;
        if ($y + $hoehe + BOARD_MQTT_SHADOW + $reserve > BOARD_DEPARTURES_MAX_Y) {
            break;
        }

        $items[] = [
            'type'    => 'card',
            'id'      => (string) ($msg['id'] ?? ''),
            'x'       => board_mqtt_column_x($col),
            'y'       => $y,
            'height'  => $hoehe,
            'close_x' => board_mqtt_column_x($col) + BOARD_MQTT_CARD_W - BOARD_MQTT_CLOSE_INSET,
            'close_y' => $y + BOARD_MQTT_CLOSE_INSET,
            'title'   => $hatTitel ? board_mqtt_truncate_title($msg['title']) : '',
            'title_y' => $titleBaseline,
            'age'     => $msg['age'],
            'age_y'   => $ageBaseline,
            'body_y'  => $bodyFirst,
            'lines'   => $zeilen,
        ];
        $columnY[$col] = $y + $hoehe + BOARD_MQTT_ROW_GAP;
        $gesetzt++;
    }

    if ($anzahl > $gesetzt) {
        $items[] = [
            'type' => 'note',
            'y'    => max($columnY) + BOARD_MQTT_BODY_ASCENT,
            'text' => '+ ' . ($anzahl - $gesetzt) . ' weitere',
        ];
    }

    return $items;
}

// --- Rendering ------------------------------------------------------------

/**
 * Post-it-Silhouette: Rechteck mit abgeschnittener Ecke unten rechts. Die
 * ausgesparte Ecke fuellt board_mqtt_fold_path() als umgeknickte Lasche --
 * zusammen ergibt das den klassischen Klebezettel.
 */
function board_mqtt_card_path(int $x, int $y, int $w, int $h, int $fold): string
{
    return sprintf(
        'M %d %d H %d V %d L %d %d H %d Z',
        $x, $y,
        $x + $w,
        $y + $h - $fold,
        $x + $w - $fold, $y + $h,
        $x
    );
}

/** Die umgeknickte Ecke selbst (fuellt exakt den vom Kartenpfad ausgesparten
 *  Bereich). */
function board_mqtt_fold_path(int $x, int $y, int $w, int $h, int $fold): string
{
    // Die Lasche liegt INNERHALB der Karte und teilt sich ihre Hypotenuse mit
    // der Schnittkante -- der rechte Winkel zeigt nach innen (oben links).
    // Falsch herum gezeichnet (rechter Winkel nach aussen) sieht das Ergebnis
    // wie der Zipfel einer Sprechblase aus, nicht wie umgeknicktes Papier
    // (2026-08-29 am gerenderten Bild aufgefallen).
    return sprintf(
        'M %d %d H %d L %d %d Z',
        $x + $w - $fold, $y + $h - $fold,
        $x + $w,
        $x + $w - $fold, $y + $h
    );
}

/**
 * ACHTUNG Sicherheit: Titel UND Body sind Fremdeingabe -- wer Zugriff auf
 * den Broker hat, bestimmt beide (anders als beim Kalender, wo nur geteilte
 * Kalender Titel liefern). Alles geht durch htmlspecialchars(ENT_XML1), sonst
 * koennte ein Payload mit "</text>" die SVG-Struktur umbauen, die
 * rsvg-convert danach rastert.
 */
/**
 * Musterdefinition fuer den gerasterten Schatten. Schachbrett aus reinen
 * Schwarz-/Weisspixeln (50% Deckung) -- aus Zimmerentfernung ein Grau, fuer
 * png_to_1bpp_packed() aber bereits fertig geschwellte Pixel, also ohne
 * jeden Informationsverlust an der 1bpp-Grenze.
 */
function board_mqtt_shadow_pattern_svg(): string
{
    $t = BOARD_MQTT_SHADOW_TILE;
    $h = intdiv($t, 2);

    return sprintf(
        '<defs><pattern id="%s" width="%d" height="%d" patternUnits="userSpaceOnUse">'
        . '<rect width="%d" height="%d" fill="white"/>'
        . '<rect width="%d" height="%d" fill="black"/>'
        . '<rect x="%d" y="%d" width="%d" height="%d" fill="black"/>'
        . '</pattern></defs>',
        BOARD_MQTT_SHADOW_PATTERN_ID, $t, $t,
        $t, $t,
        $h, $h,
        $h, $h, $h, $h
    );
}

/**
 * @param list<array> $items von board_mqtt_layout()
 * @param int|null $count Gesamtzahl der Nachrichten fuer den Seitenkopf
 *        (Nutzerwunsch 2026-09-01); optional statt in $items gemischt, damit
 *        board_mqtt_layout()s Rueckgabe (Karten/Notiz-Items, von vielen Tests
 *        indexbasiert geprueft) unveraendert bleibt. null = kein Kopf (z.B.
 *        isolierte Render-Tests einzelner Karten).
 */
function board_mqtt_render_svg(array $items, ?int $count = null): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1);
    $out = board_mqtt_shadow_pattern_svg() . '<g font-family="Atkinson Hyperlegible Next">';

    if ($count !== null) {
        $out .= sprintf(
            '<text x="%d" y="130" font-weight="bold" font-size="28">%s</text>',
            BOARD_MQTT_GRID_X, $e(sprintf('Nachrichten (%d)', $count))
        );
    }

    foreach ($items as $item) {
        if ($item['type'] === 'note') {
            $out .= sprintf(
                '<text x="%d" y="%d" font-size="%d" fill="#808080">%s</text>',
                BOARD_MQTT_GRID_X, $item['y'], BOARD_MQTT_BODY_SIZE, $e($item['text'])
            );
            continue;
        }

        $x = $item['x'];
        $y = $item['y'];
        $h = $item['height'];
        $innerX = $x + BOARD_MQTT_CARD_PADDING;

        // Schatten zuerst (liegt hinter der Karte), dann die weisse Karte
        // darueber -- so verdeckt sie den Schatten an drei Seiten und er
        // bleibt nur rechts/unten sichtbar.
        $out .= sprintf(
            '<path d="%s" fill="url(#%s)"/>',
            board_mqtt_card_path($x + BOARD_MQTT_SHADOW, $y + BOARD_MQTT_SHADOW, BOARD_MQTT_CARD_W, $h, BOARD_MQTT_FOLD),
            BOARD_MQTT_SHADOW_PATTERN_ID
        );
        $out .= sprintf(
            '<path d="%s" fill="white" stroke="black" stroke-width="2" stroke-linejoin="round"/>',
            board_mqtt_card_path($x, $y, BOARD_MQTT_CARD_W, $h, BOARD_MQTT_FOLD)
        );
        $out .= sprintf(
            '<path d="%s" fill="white" stroke="black" stroke-width="2" stroke-linejoin="round"/>',
            board_mqtt_fold_path($x, $y, BOARD_MQTT_CARD_W, $h, BOARD_MQTT_FOLD)
        );

        // Loesch-X oben rechts. Nur zeichnen, wenn die Karte eine Kennung
        // hat -- ohne die koennte ein Tipp nichts ausloesen, und ein Knopf,
        // der nichts tut, ist schlimmer als keiner.
        if (($item['id'] ?? '') !== '') {
            $cx = $item['close_x'];
            $cy = $item['close_y'];
            $a = BOARD_MQTT_CLOSE_ARM;
            $out .= sprintf(
                '<g stroke="black" stroke-width="3" stroke-linecap="round">'
                . '<line x1="%d" y1="%d" x2="%d" y2="%d"/>'
                . '<line x1="%d" y1="%d" x2="%d" y2="%d"/>'
                . '</g>',
                $cx - $a, $cy - $a, $cx + $a, $cy + $a,
                $cx - $a, $cy + $a, $cx + $a, $cy - $a
            );
        }

        if ($item['title'] !== '') {
            $out .= sprintf(
                '<text x="%d" y="%d" font-weight="bold" font-size="%d" fill="black">%s</text>',
                $innerX, $item['title_y'], BOARD_MQTT_TITLE_SIZE, $e($item['title'])
            );
        }

        $lineY = $item['body_y'];
        foreach ($item['lines'] as $zeile) {
            $out .= sprintf(
                '<text x="%d" y="%d" font-size="%d" fill="black">%s</text>',
                $innerX, $lineY, BOARD_MQTT_BODY_SIZE, $e($zeile)
            );
            $lineY += BOARD_MQTT_BODY_LEAD;
        }

        if ($item['age'] !== '') {
            // Am Fuss des Zettels, linksbuendig -- die rechte untere Ecke
            // gehoert der umgeknickten Lasche.
            $out .= sprintf(
                '<text x="%d" y="%d" font-size="%d" fill="#808080">%s</text>',
                $innerX, $item['age_y'], BOARD_MQTT_AGE_SIZE, $e($item['age'])
            );
        }
    }

    return $out . '</g>';
}

/**
 * Tipp-Zonen der Loesch-X aus einem fertigen Layout -- Gegenstueck zu
 * board_touch_zones() in inc/board_template.php, aber INHALTSABHAENGIG:
 * die Kartenpositionen entstehen erst beim Umbruch (Masonry), es gibt also
 * keine feste Formel, die die Firmware selbst nachrechnen koennte.
 *
 * Zonenname: "mqtt_del_<id>" mit der stabilen Kennung aus
 * board_mqtt_message_id() -- NICHT der Listenindex, s. dort.
 *
 * @param list<array> $items Ergebnis von board_mqtt_layout()
 * @return list<array{zone: string, x: int, y: int, w: int, h: int}>
 */
function board_mqtt_touch_zones(array $items): array
{
    $zones = [];
    $half = intdiv(BOARD_MQTT_CLOSE_TOUCH, 2);

    foreach ($items as $item) {
        if (($item['type'] ?? '') !== 'card' || ($item['id'] ?? '') === '') {
            continue;
        }
        $zones[] = [
            'zone' => 'mqtt_del_' . $item['id'],
            'x'    => $item['close_x'] - $half,
            'y'    => $item['close_y'] - $half,
            'w'    => BOARD_MQTT_CLOSE_TOUCH,
            'h'    => BOARD_MQTT_CLOSE_TOUCH,
        ];
    }

    return $zones;
}

/**
 * Dieselben Zonen wie board_mqtt_touch_zones(), aber als kompakte Zeile fuer
 * das GERAET (Header X-Board-Delete-Zones, TASK-28):
 *
 *     <id>:<x>,<y>,<w>,<h>;<id>:<x>,<y>,<w>,<h>
 *
 * Bewusst kein JSON: die Firmware hat keinen JSON-Parser (und soll fuer diese
 * eine Zeile keinen bekommen). Das Format ist fest, ohne Verschachtelung und
 * mit einem Blick begrenzbar -- auf einem Mikrocontroller genau das, was man
 * will. Der Simulator bekommt weiterhin JSON ueber X-Board-Touch-Zones; beide
 * stammen aus DERSELBEN Zonenberechnung, nur die Serialisierung unterscheidet
 * sich.
 *
 * Das Praefix "mqtt_del_" faellt weg -- es waere in jeder Zone dieselben neun
 * Byte. Die Firmware setzt es beim Senden wieder davor, board.php erwartet es
 * unveraendert (s. Zeile mit board_mqtt_delete()).
 *
 * @param list<array> $items Ergebnis von board_mqtt_layout()
 */
function board_mqtt_delete_zones_header(array $items): string
{
    $teile = [];
    foreach (board_mqtt_touch_zones($items) as $zone) {
        $teile[] = sprintf(
            '%s:%d,%d,%d,%d',
            substr($zone['zone'], strlen('mqtt_del_')),
            $zone['x'],
            $zone['y'],
            $zone['w'],
            $zone['h']
        );
    }

    return implode(';', $teile);
}
