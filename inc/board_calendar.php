<?php
// inc/board_calendar.php
//
// Kalenderseite des E-Paper-Boards (Nutzerwunsch 2026-08-26): eigene Seite mit
// den naechsten Terminen, zwischen Stoerungsseite und Schlafschirm. Seit
// 2026-09-04 nicht mehr nur heute+morgen, sondern die kommenden Tage
// ("Morgen, Sonntag, Montag") -- so viele, wie auf die Seite passen.
//
// Datenherkunft: scripts/akadbrain/calsync.swift liest EventKit lokal auf dem
// Server und schreibt data/calendar/<userId>.json -- dieselbe Rolle wie der
// Wetter-Cron. Der Outlook-Kalender ist NICHT dabei: er liegt in Outlook.apps
// eigenem Container und ist fuer EventKit unerreichbar (Pruefung 2026-08-26).
// EventKit statt CalDAV, weil es Serientermine bereits aufgeloest liefert.
//
// Aufbau wie inc/board_guest_wifi.php: ein klar abgegrenzter I/O-Teil, der Rest
// reine Funktionen (kein $con, kein Netz, kein date()/time() -- Zeit kommt als
// DateTimeImmutable herein), damit das Layout ohne Hardware testbar bleibt.
declare(strict_types=1);

// --- I/O ---------------------------------------------------------------------

/** Ablage pro Nutzer: Kalenderdaten sind persoenlich, und board.php rendert
 *  ohnehin nutzerbezogen. Global waere jeder Nutzer mit eigenem Board-Token in
 *  der Lage, per ?token= fremde Termintitel zu lesen. */
function board_calendar_path(int $userId): string
{
    return __DIR__ . '/../data/calendar/' . $userId . '.json';
}

/**
 * null  = keine Datei -> es gibt gar keine Kalenderseite
 * []    = Datei da, aber unlesbar/kein JSON -> Seite bleibt, meldet "unlesbar"
 * array = dekodierter Cache
 *
 * Die Dreiteilung ist Absicht: Die Seiten-EXISTENZ darf nur an der Dateipraesenz
 * haengen, nie an Inhalt oder Alter. Verschwaende die Seite bei veralteten Daten,
 * verschoeben sich die Seitenindizes unter dem gespeicherten activePage des
 * Geraets und die Paginierungs-Pille aenderte mitten in der Sitzung ihre Breite.
 */
function board_calendar_load(int $userId): ?array
{
    $pfad = board_calendar_path($userId);
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
// Der Schreiber (scripts/akadbrain/calsync.swift) laeuft lokal und liefert eine
// saubere Datei. Die Grenzen stehen trotzdem hier, auf der LESESEITE: Diese Daten
// landen in einem BILD, und eine PHP-Warning mitten in einer PNG-Antwort ist am
// Geraet nicht zu diagnostizieren. Derselbe Fehler wurde bei den Wetter-Messwerten
// schon einmal gemacht (s. weather_select_station_display()).
const BOARD_CALENDAR_MAX_EVENTS_PER_DAY = 100;
const BOARD_CALENDAR_MAX_TITLE_CHARS    = 200;

/** ISO-Zeitstempel -> normalisierter String, oder null wenn unlesbar. */
function board_calendar_parse_time(mixed $wert): ?string
{
    if (!is_string($wert) || $wert === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($wert))->format(DATE_ATOM);
    } catch (Throwable) {
        return null;
    }
}

// --- Anzeige-Auswahl ----------------------------------------------------------

/** Kuerzer als beim Wetter (6h): eine Prognose stimmt nach sechs Stunden noch
 *  ungefaehr, ein Terminplan nicht. */
const BOARD_CALENDAR_STALE_SECONDS = 3 * 3600;
/** Wieviele Tage der Cache hoechstens hergibt (calsync.swift: VORSCHAU_TAGE).
 *  Wieviele davon aufs Bild kommen, entscheidet das Layout. */
const BOARD_CALENDAR_PREVIEW_DAYS = 7;

/**
 * Ueberschrift eines Tages: HEUTE/MORGEN, danach der Wochentag (SONNTAG,
 * MONTAG, ...). Ab einer Woche Abstand waere der Wochentag mehrdeutig --
 * dann steht das Datum dabei.
 *
 * Bewusst hier ausformuliert statt ueber strftime()/IntlDateFormatter: die
 * Locale des Servers ist nicht garantiert deutsch, und ein englisches
 * "SUNDAY" mitten auf dem Board waere ein stiller Fehler, den niemand im
 * Test bemerkt.
 */
function board_calendar_day_label(DateTimeImmutable $tag, DateTimeImmutable $heute): string
{
    $versatz = (int) $heute->diff($tag->setTime(0, 0))->format('%r%a');
    if ($versatz === 0) return 'HEUTE';
    if ($versatz === 1) return 'MORGEN';

    $namen = ['SONNTAG', 'MONTAG', 'DIENSTAG', 'MITTWOCH', 'DONNERSTAG', 'FREITAG', 'SAMSTAG'];
    $name = $namen[(int) $tag->format('w')];

    return $versatz >= 7 ? $name . ' ' . $tag->format('j.n.') : $name;
}

/**
 * Baut aus dem Cache die Anzeige-Sicht fuer JETZT: heute, morgen und die
 * naechsten Tage mit Terminen (bis BOARD_CALENDAR_PREVIEW_DAYS).
 *
 * Die Tage werden ueber das absolute Datum GESUCHT, nicht uebernommen: ein
 * Lauf um 23:50 wird um 00:30 gelesen. Ein mitgeschriebenes "heute" waere
 * dann gelogen. Der Server entscheidet gegen seine eigene Uhr.
 */
function board_calendar_select_display(array $cache, DateTimeImmutable $now): array
{
    $wien = new DateTimeZone('Europe/Vienna');
    $jetzt = $now->setTimezone($wien);
    $heute = $jetzt->setTime(0, 0);

    $empfangen = board_calendar_parse_time($cache['received_at'] ?? null);
    $empfangenAt = null;
    if ($empfangen !== null) {
        try {
            $empfangenAt = (new DateTimeImmutable($empfangen))->setTimezone($wien);
        } catch (Throwable) {
            $empfangenAt = null;
        }
    }

    $blocks = [];
    foreach (($cache['days'] ?? []) as $tag) {
        if (is_array($tag) && is_string($tag['date'] ?? null)) {
            $blocks[$tag['date']] = is_array($tag['events'] ?? null) ? $tag['events'] : [];
        }
    }

    $verfuegbar = $empfangenAt !== null && $blocks !== [];
    $veraltet = $verfuegbar
        && ($jetzt->getTimestamp() - $empfangenAt->getTimestamp()) > BOARD_CALENDAR_STALE_SECONDS;

    if (!$verfuegbar) {
        $statusText = 'Keine Kalenderdaten';
    } elseif ($veraltet) {
        $statusText = 'Kalender veraltet – Stand ' . board_calendar_format_stand($empfangenAt, $jetzt);
    } else {
        $statusText = 'Kalender ' . $empfangenAt->format('H:i');
    }

    // Nicht mehr fest HEUTE/MORGEN, sondern die naechsten Tage mit
    // Wochentagsnamen (Nutzerwunsch 2026-09-04: "einfach die naechsten, like
    // Morgen, Sonntag, Montag"). Wieviele davon tatsaechlich aufs Bild kommen,
    // entscheidet das Layout -- hier werden nur alle angeboten, die der Cache
    // hergibt.
    $tage = [];
    for ($versatz = 0; $versatz < BOARD_CALENDAR_PREVIEW_DAYS; $versatz++) {
        $tagStart = $heute->modify('+' . $versatz . ' day');
        $schluessel = $tagStart->format('Y-m-d');
        $vorhanden = array_key_exists($schluessel, $blocks);
        $termine = $vorhanden ? board_calendar_normalize_events($blocks[$schluessel], $wien) : [];

        // Tage ohne Termine hinter dem uebermorgigen weglassen: eine Spalte
        // voller "Keine Termine" verdraengt echte Eintraege spaeterer Tage.
        // Heute und morgen bleiben IMMER stehen -- dort ist die Auskunft
        // "nichts los" selbst die Information.
        if ($versatz > 1 && $termine === []) {
            continue;
        }

        $tage[] = [
            'label'  => board_calendar_day_label($tagStart, $heute),
            'date'   => $tagStart,
            // Drei Zustaende, die sonst alle gleich aussaehen: gar keine Daten,
            // Daten aber freier Tag, oder Block fehlt (Push deckte ihn nicht ab).
            'state'  => !$verfuegbar ? 'missing' : ($vorhanden ? ($termine === [] ? 'empty' : 'ok') : 'missing'),
            'events' => $termine,
        ];
    }

    return [
        'available'   => $verfuegbar,
        'stale'       => $veraltet,
        'received_at' => $empfangenAt,
        'status_text' => $statusText,
        'days'        => $tage,
    ];
}

/** "Stand"-Text: heute nur Uhrzeit, sonst mit Wochentag/Datum. */
function board_calendar_format_stand(DateTimeImmutable $empfangen, DateTimeImmutable $jetzt): string
{
    if ($empfangen->format('Y-m-d') === $jetzt->format('Y-m-d')) {
        return $empfangen->format('H:i');
    }
    $wochentage = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    $tag = $wochentage[(int) $empfangen->format('N') - 1];
    return $tag . ' ' . $empfangen->format('j.n.') . ' ' . $empfangen->format('H:i');
}

/**
 * Rohe Cache-Termine -> Anzeigeform mit DateTimeImmutable, robust gegen Muell.
 * Hier greifen die Schutzgrenzen: Titel gekappt, Anzahl begrenzt, unlesbare
 * Zeitangaben zu null statt zu einer Ausnahme.
 */
function board_calendar_normalize_events(array $roh, DateTimeZone $tz): array
{
    $out = [];
    foreach ($roh as $ev) {
        if (count($out) >= BOARD_CALENDAR_MAX_EVENTS_PER_DAY) {
            break;
        }
        if (!is_array($ev)) {
            continue;
        }
        $start = null;
        $ende = null;
        try {
            if (is_string($ev['start'] ?? null)) {
                $start = (new DateTimeImmutable($ev['start']))->setTimezone($tz);
            }
            if (is_string($ev['end'] ?? null)) {
                $ende = (new DateTimeImmutable($ev['end']))->setTimezone($tz);
            }
        } catch (Throwable) {
            $start = null;
            $ende = null;
        }

        $ganztaegig = !empty($ev['all_day']);
        // Ein Termin mit Uhrzeit ohne lesbaren Beginn laesst sich nicht
        // einsortieren -- weglassen statt halb rendern.
        if (!$ganztaegig && $start === null) {
            continue;
        }

        $titel = is_string($ev['title'] ?? null) ? trim($ev['title']) : '';
        $titel = mb_substr($titel, 0, BOARD_CALENDAR_MAX_TITLE_CHARS);

        $out[] = [
            'title'   => $titel === '' ? '(ohne Titel)' : $titel,
            'all_day' => $ganztaegig,
            'start'   => $start,
            'end'     => $ende,
        ];
    }
    return $out;
}

/**
 * Zeitspalten-Text eines Termins, bezogen auf DIESEN Tag. Mehrtaegige Termine
 * erscheinen in beiden Tagesbloecken, tragen aber absolute Zeiten -- die
 * tagesbezogene Beschriftung entsteht deshalb erst hier.
 */
function board_calendar_time_label(array $event, DateTimeImmutable $tagStart, DateTimeImmutable $tagEnde): string
{
    if (!empty($event['all_day'])) {
        return 'ganztägig';
    }
    $start = $event['start'] ?? null;
    $ende  = $event['end'] ?? null;
    if (!$start instanceof DateTimeImmutable) {
        return '';
    }
    $beginntFrueher = $start < $tagStart;
    $endetSpaeter = $ende instanceof DateTimeImmutable && $ende > $tagEnde;

    if ($beginntFrueher && $endetSpaeter) {
        return 'ganztägig';
    }
    // Nur die Anfangszeit (Nutzerwunsch 2026-08-26: "die bis Zeit kann
    // entfallen") -- sie ist die Information, nach der man den Tag absucht, und
    // ohne den Bereich wird die Zeit-Rinne schmal genug, dass die Titel mehr
    // Platz bekommen. Ausnahme: laeuft ein Termin ueber die Tagesgrenze, ist die
    // GEGENueberliegende Zeit die eigentliche Nachricht.
    if ($beginntFrueher) {
        return 'bis ' . ($ende instanceof DateTimeImmutable ? $ende->format('H:i') : '?');
    }
    if ($endetSpaeter) {
        return 'ab ' . $start->format('H:i');
    }
    return $start->format('H:i');
}

// --- Layout -------------------------------------------------------------------
//
// ZWEISPALTIG (Nutzerentscheidung 2026-08-26): heute links, morgen rechts. Bei
// wenigen Terminen blieb einspaltig ueber die volle Breite zuviel Weissraum
// stehen; nebeneinander fuellt beides die Seite und beide Tage sind auf einen
// Blick erfassbar. Titel duerfen INNERHALB ihrer Spalte umbrechen -- dafuer ist
// senkrecht reichlich Platz, weil jede Spalte nur noch einen Tag traegt.
//
// Je Spalte eine Zeit-Rinne links und die Titel daneben:
//   links   Zeit x=16    Titel x=196 .. 900
//   rechts  Zeit x=972   Titel x=1152 .. 1856
// Trennlinie bei x=936, y=90..1240 -- endet BEWUSST oberhalb der
// Paginierungs-Pille (y ab 1252), damit sie nicht in das Fusszeilenband ragt.

const BOARD_CALENDAR_COL_DIVIDER_X = 936;
const BOARD_CALENDAR_COL_DIVIDER_BOTTOM = 1240;
const BOARD_CALENDAR_STATUS_Y = 122;

/** Je Spalte: rechte Kante der Zeit-Rinne, Titelanfang, rechte Kante.
 *  Die Zeit wird RECHTSBUENDIG an time_end gesetzt (text-anchor="end") statt
 *  linksbuendig zu beginnen: "08:00-09:00" ist bei 30px spuerbar breiter als
 *  die Mittelwert-Schaetzung (Ziffern sind breiter als der Schnitt), und eine
 *  linksbuendige Rinne stiess deshalb an den Titel. Rechtsbuendig kann das
 *  strukturell nicht passieren -- der Abstand zum Titel ist fix. */
const BOARD_CALENDAR_COLUMNS = [
    ['time_end' => 190,  'title_x' => 218,  'right_x' => 900],
    ['time_end' => 1146, 'title_x' => 1174, 'right_x' => 1856],
];

// Schrift 20% groesser als der erste Entwurf (Nutzerwunsch 2026-08-26) -- auf
// einem 10,3-Zoll-Panel aus Zimmerentfernung gelesen zaehlt Groesse mehr als
// Zeilenzahl; Platz ist bei zwei Spalten ohnehin reichlich da.
//
// Titelbreite je Spalte: 900-218 = 682px. Zeichenbudget nach der etablierten
// Herleitung (0,44538 px je Schrift-px, x0,92 Sicherheit):
//   48px: floor(682 / 21.378 * 0.92) = 29
//   38px: floor(682 / 16.924 * 0.92) = 37
const BOARD_CALENDAR_METRICS_ROOMY = [
    'day_size' => 58, 'day_gap_before' => 70, 'day_baseline_drop' => 50,
    'day_to_first_row' => 67,
    'title_size' => 48, 'title_chars' => 29, 'time_size' => 30,
    'row_pitch' => 67, 'wrap_lead' => 53, 'max_title_lines' => 3,
];
const BOARD_CALENDAR_METRICS_COMPACT = [
    'day_size' => 48, 'day_gap_before' => 55, 'day_baseline_drop' => 40,
    'day_to_first_row' => 55,
    'title_size' => 38, 'title_chars' => 37, 'time_size' => 26,
    'row_pitch' => 53, 'wrap_lead' => 43, 'max_title_lines' => 2,
];

/**
 * Baut die Anzeigeliste. Erst grosszuegig versuchen; laeuft eine der beiden
 * Spalten ueber, mit dem kompakten Satz neu rechnen. Deterministisch und ohne
 * Textmessung zur Laufzeit -- allein aus der Terminliste ableitbar, damit
 * dasselbe SVG dasselbe Bild ergibt (board_frame_diff() haengt daran).
 *
 * @return list<array>
 */
function board_calendar_layout(array $selected): array
{
    foreach ([BOARD_CALENDAR_METRICS_ROOMY, BOARD_CALENDAR_METRICS_COMPACT] as $i => $m) {
        $ergebnis = board_calendar_layout_with($selected, $m);
        if (!$ergebnis['overflow'] || $i === 1) {
            return $ergebnis['items'];
        }
    }
    return [];
}

/**
 * @return array{items: list<array>, overflow: bool}
 *
 * Spaltenaufteilung (Nutzervorgabe 2026-09-04): LINKS ausschliesslich HEUTE,
 * RECHTS morgen und die weiteren Tage, bis die Spalte voll ist. Heute behaelt
 * damit immer seinen festen Platz -- man schaut auf dieselbe Stelle, egal wie
 * viele Termine die Folgetage haben. Eine ueber beide Spalten fliessende
 * Liste waere unruhiger: heute ruckte je nach Terminlage mal hierhin, mal
 * dorthin.
 */
function board_calendar_layout_with(array $selected, array $m): array
{
    $items = [
        ['type' => 'status', 'y' => BOARD_CALENDAR_STATUS_Y, 'text' => (string) $selected['status_text']],
        ['type' => 'col_divider'],
    ];
    $overflow = false;
    $spaltenOben = 90; // unterhalb der Kopfzeilen-Trennlinie

    foreach ($selected['days'] as $index => $tag) {
        // Tag 0 (heute) allein links, alles Weitere rechts untereinander.
        $spalteIdx = $index === 0 ? 0 : 1;
        $spalte = BOARD_CALENDAR_COLUMNS[$spalteIdx];
        $ersterInSpalte = $index <= 1;

        if ($ersterInSpalte) {
            $y = $spaltenOben;
        }

        $headerBaseline = $y + ($ersterInSpalte ? $m['day_gap_before'] : intdiv($m['day_gap_before'], 2))
                        + $m['day_baseline_drop'];

        // Passt der Tageskopf samt erster Zeile nicht mehr, endet die rechte
        // Spalte hier -- ein Kopf ohne Inhalt waere die schlechteste Variante.
        if ($headerBaseline + $m['day_to_first_row'] > BOARD_DEPARTURES_MAX_Y) {
            $overflow = true;
            break;
        }

        $items[] = [
            'type' => 'day_header',
            'x'    => $spalte['title_x'],
            'y'    => $headerBaseline,
            'text' => $tag['label'],
            'size' => $m['day_size'],
        ];

        $y = $headerBaseline + $m['day_to_first_row'];

        if ($tag['events'] === []) {
            $items[] = [
                'type' => 'note',
                'x'    => $spalte['title_x'],
                'y'    => $y,
                'text' => $tag['state'] === 'empty' ? 'Keine Termine' : 'Keine Daten',
                'size' => $m['title_size'],
            ];
            continue;
        }

        $tagStart = $tag['date'];
        $tagEnde = $tagStart->modify('+1 day');
        $anzahl = count($tag['events']);

        foreach ($tag['events'] as $i => $ev) {
            $zeilen = board_calendar_fit_title((string) $ev['title'], $m['title_chars'], $m['max_title_lines']);
            $hoehe = $m['row_pitch'] + (count($zeilen) - 1) * $m['wrap_lead'];

            // Vor dem Setzen pruefen, ob danach noch eine "+ N weitere"-Zeile
            // gebraucht wird und passt -- sonst waere ausgerechnet der Hinweis
            // das, was ueberlaeuft.
            $reserve = ($anzahl - $i - 1) > 0 ? $m['row_pitch'] : 0;
            if ($y + $hoehe - $m['row_pitch'] + $reserve > BOARD_DEPARTURES_MAX_Y) {
                $items[] = [
                    'type' => 'note',
                    'x'    => $spalte['title_x'],
                    'y'    => $y,
                    'text' => '+ ' . ($anzahl - $i) . ' weitere',
                    'size' => $m['title_size'],
                ];
                $overflow = true;
                // Links abgeschnitten heisst nur: heute hat viel vor. Rechts
                // abgeschnitten heisst: kein Platz mehr fuer weitere Tage.
                if ($spalteIdx === 1) {
                    break 2;
                }
                break;
            }

            $items[] = [
                'type'       => 'event',
                'time_end'   => $spalte['time_end'],
                'title_x'    => $spalte['title_x'],
                'y'          => $y,
                'time'       => board_calendar_time_label($ev, $tagStart, $tagEnde),
                'lines'      => $zeilen,
                'title_size' => $m['title_size'],
                'time_size'  => $m['time_size'],
                'wrap_lead'  => $m['wrap_lead'],
            ];
            $y += $hoehe;
        }
    }

    return ['items' => $items, 'overflow' => $overflow];
}

/** Titel umbrechen, auf maxLines kuerzen, letzte Zeile mit Auslassungszeichen. */
function board_calendar_fit_title(string $titel, int $chars, int $maxLines): array
{
    $zeilen = board_wrap_text($titel, $chars);
    if (count($zeilen) <= $maxLines) {
        return $zeilen;
    }
    $zeilen = array_slice($zeilen, 0, $maxLines);
    $zeilen[$maxLines - 1] = rtrim($zeilen[$maxLines - 1]) . '…';
    return $zeilen;
}

// --- Rendering ----------------------------------------------------------------

/**
 * ACHTUNG Sicherheit: Termintitel sind Fremdeingabe -- wer eine Einladung
 * schicken kann, bestimmt den Titel. Alles geht durch htmlspecialchars(ENT_XML1),
 * sonst koennte ein Titel mit "</text>" die SVG-Struktur umbauen, die
 * rsvg-convert danach rastert.
 */
function board_calendar_render_svg(array $items): string
{
    if ($items === []) {
        return '';
    }
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1);
    $out = '<g font-family="Atkinson Hyperlegible Next">';

    foreach ($items as $item) {
        switch ($item['type']) {
            case 'status':
                $out .= sprintf(
                    '<text x="1856" y="%d" text-anchor="end" font-size="29" fill="black">%s</text>',
                    $item['y'], $e($item['text'])
                );
                break;

            case 'col_divider':
                $out .= sprintf(
                    '<line x1="%d" y1="90" x2="%d" y2="%d" stroke="black" stroke-width="2"/>',
                    BOARD_CALENDAR_COL_DIVIDER_X, BOARD_CALENDAR_COL_DIVIDER_X,
                    BOARD_CALENDAR_COL_DIVIDER_BOTTOM
                );
                break;

            case 'day_header':
                $out .= sprintf(
                    '<text x="%d" y="%d" font-weight="bold" font-size="%d" fill="black">%s</text>',
                    $item['x'], $item['y'], $item['size'], $e($item['text'])
                );
                break;

            case 'event':
                if ($item['time'] !== '') {
                    $out .= sprintf(
                        '<text x="%d" y="%d" text-anchor="end" font-weight="500" font-size="%d" fill="black">%s</text>',
                        $item['time_end'], $item['y'], $item['time_size'], $e($item['time'])
                    );
                }
                $y = $item['y'];
                foreach ($item['lines'] as $zeile) {
                    $out .= sprintf(
                        '<text x="%d" y="%d" font-weight="500" font-size="%d" fill="black">%s</text>',
                        $item['title_x'], $y, $item['title_size'], $e($zeile)
                    );
                    $y += $item['wrap_lead'];
                }
                break;

            case 'note':
                $out .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#808080">%s</text>',
                    $item['x'], $item['y'], $item['size'], $e($item['text'])
                );
                break;
        }
    }

    return $out . '</g>';
}
