---
id: TASK-25
title: Absolutes Antippen der Pagination-Icons (Monitor/Störung/Kalender/Wetter)
status: Done
assignee: []
created_date: '2026-08-27 10:50'
updated_date: '2026-09-04 20:45'
labels: []
dependencies: []
priority: medium
ordinal: 17000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Nutzerwunsch 2026-08-27: die Pagination-Pille zeigt 4 Kategorie-Icons, ist aber nur als links/rechts-Halbraum (page_prev/page_next, RELATIV: eine Seite vor/zurueck) tippbar. Soll auf 4 einzeln antippbare Zonen erweitert werden, die ABSOLUT direkt zur ersten Seite der jeweiligen Kategorie springen -- kein schrittweises Vor-/Zurueckblaettern mehr fuer diesen Zweck. Betrifft PHP (neue X-Device-Touch-Werte + Kategorie-Info im Response) UND Firmware (touch_zone.cpp: Tipp-Erkennung pro Icon-Slot statt pro Pillenhaelfte), Reflash noetig.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Antippen des Monitor-Icons springt direkt zur ersten Abfahrtenseite, unabhaengig von der aktuellen Seite
- [x] #2 Antippen des Stoerung-Icons springt direkt zur Stoerungsseite (nur sichtbar/tippbar wenn vorhanden)
- [x] #3 Antippen des Kalender-Icons springt direkt zur Kalenderseite (nur sichtbar/tippbar wenn vorhanden)
- [x] #4 Antippen des Wetter-Icons springt direkt zum Schlafschirm
- [x] #5 web/board.php?debug=ui (Browser-Simulator) zeigt 4 einzelne Zonen statt page_prev/page_next und sendet die neuen absoluten X-Device-Touch-Werte
- [x] #6 touch_zone.cpp erkennt Tipp-Position pro Icon-Slot und sendet den passenden absoluten Touch-Wert
- [x] #7 Firmware neu geflasht und am echten Geraet verifiziert
- [x] #8 Alle bestehenden Tests bleiben gruen, neue Tests fuer die absolute Navigation ergaenzt
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Kernentscheidung: nur die Touch-Pille wird absolut, physische Tasten bleiben relativ

KEY2 (kurzer Druck) und die mittlere physische Taste senden bereits heute
hartcodiert "page_prev"/"page_next" (main.cpp, komplett getrennt vom
Touchscreen-Pfad ueber pollTouch()/mapTouchToZone()) -- eine Taste kann
keine absolute Zielseite ausdruecken, das bleibt zwingend relativ. Der
Nutzerwunsch ("Vor/zurueck ist ein Anachronismus", "vier Sprungziele,
absolut nicht relativ") betrifft nachweislich nur die kapazitive Pille.
Deshalb: PHP versteht am Ende BEIDE Formen weiter nebeneinander.

## PHP (inc/board_state.php)

board_resolve_touch() bekommt einen dritten Zweig zusaetzlich zu
fav\d+/page_prev/page_next:

    } elseif ($touch !== null && preg_match('/^page_(\d+)$/', $touch, $m)) {
        $page = max(1, (int) $m[1]);
    }

Kein neuer Parameter noetig -- der aufrufende Code in web/board.php klemmt
$requestedPage ohnehin schon auf [1, totalPages], eine zu hohe/veraltete
Zahl (Race Condition durch stale Zonen im Simulator/Geraet) landet also
automatisch sicher auf der letzten Seite statt ins Leere zu greifen.

## PHP (inc/board_template.php) -- board_touch_zones()

Pagination-Teil aendert sich von 2 Zonen (page_prev/page_next Haelften)
auf N Zonen (eine pro Seite/Slot, N = totalPages), analog zu den
Favoriten-Zonen:

    for ($p = 1; $p <= $totalPages; $p++) {
        $x = $pillStartX + ($p - 1) * BOARD_PAGINATION_SLOT_WIDTH;
        $zones[] = ['zone' => 'page_' . $p, 'x' => $x, 'y' => BOARD_PAGINATION_TOP,
                     'w' => BOARD_PAGINATION_SLOT_WIDTH, 'h' => BOARD_PAGINATION_HEIGHT];
    }

board_render_debug_ui() braucht keine Aenderung -- das JS rendert generisch,
was an Zonen zurueckkommt, und sendet den Zonennamen 1:1 als
X-Device-Touch. web/board.php's X-Board-Touch-Zones-Header (Nutzerbefund
2026-08-27) liefert automatisch die neue Form mit.

## Firmware (epaper-monitor/lib/boardlogic/touch_zone.h/.cpp)

touch_zone.h: TouchZone-Enum verliert PagePrev/PageNext, bleibt sonst
gleich (None, Fav0, Fav1, Fav2). Kombinierte Rueckgabe noetig, weil ein
Touch entweder eine Favoriten-Zone oder eine absolute Seitenzahl ergibt:

    struct TouchResult { TouchZone zone; int page; }; // page nur bei zone==None+page>0 gueltig, sonst 0
    TouchResult mapTouchToZone(int x, int y, int favoriteCount, int totalPages);

(Alternative waere ein fuenftes Enum-Mitglied TouchZone::Page -- Struct ist
lesbarer, weil page nicht in einen switch-Wert gepresst werden muss.)

touch_zone.cpp: mapPaginationTouch() liefert kuenftig direkt die
1-indizierte absolute Seitenzahl des getroffenen Slots (0 = kein Treffer)
statt links/rechts-Haelfte:

    int mapPaginationTouch(int x, int totalPages) {
        if (totalPages <= 1) return 0;
        int pillWidth = totalPages * PAGINATION_SLOT_WIDTH + PAGINATION_SIDE_PADDING;
        int pillStartX = PAGINATION_RIGHT_EDGE - pillWidth;
        if (x < pillStartX || x >= PAGINATION_RIGHT_EDGE) return 0;
        int slot = (x - pillStartX) / PAGINATION_SLOT_WIDTH;
        if (slot < 0) slot = 0;
        if (slot >= totalPages) slot = totalPages - 1;
        return slot + 1;
    }

touchZoneToHeaderValue() bleibt fuer Fav0/Fav1/Fav2/None zustaendig; die
Formatierung von "page_N" passiert an der Aufrufstelle (main.cpp) per
snprintf in einen lokalen Puffer, weil der Wert dynamisch ist (kein
switch-Case moeglich).

## main.cpp

Aufrufstelle um TouchZone::Page erweitern/anpassen (s.o. Struct-Variante):
bei result.zone == TouchZone::None && result.page > 0 -> "page_%d"
formatieren, sonst wie bisher touchZoneToHeaderValue(). Die KEY2-Sonderbe-
handlung (Zeile ~518, hartcodiertes "page_prev") und readPageButtons()
("page_next") bleiben UNVERAENDERT -- das ist der bewusst beibehaltene
relative Pfad fuer physische Tasten.

## Firmware-Tests (epaper-monitor/test/test_touch_zone)

Bestehende Tests fuer PagePrev/PageNext-Haelften ersetzen durch Tests fuer
mapPaginationTouch() mit mehreren totalPages-Werten (1, 2, 4, 6) und
Grenzfaellen (Tipp exakt auf Slotgrenze, Tipp ausserhalb der Pille).

## PHP-Tests

- tests/Unit/BoardTouchZonesTest.php: Pagination-Assertions auf N Einzelzonen
  umstellen (bisherige 2-Zonen-Tests ersetzen).
- tests/Unit/*BoardState* (board_resolve_touch): neuer Test fuer page_N,
  inkl. Grenzfall page_0/page_ungueltig (kein Treffer -> Seite bleibt).
- tests/Integration/BoardTokenEndpointTest.php: X-Board-Touch-Zones-Header-
  Assertions auf N Zonen umstellen; neuer Test dass ein X-Device-Touch:
  page_prev (physische Taste, kein Icon-Tap) weiterhin relativ funktioniert
  -- Regressionsschutz fuer den bewusst beibehaltenen Pfad.

## Reihenfolge / Verifikation

1. PHP zuerst (board_resolve_touch, board_touch_zones), Tests gruen,
   Simulator (?debug=ui) end-to-end pruefen -- funktioniert bereits ohne
   Firmware-Aenderung, weil der Simulator die Touch-Werte direkt sendet.
2. Firmware-Aenderung + firmware-eigene Tests.
3. Deploy PHP nach akadbrain.
4. Firmware bauen, reflashen, am echten Geraet verifizieren (Tipp auf jedes
   der 4 Icons springt direkt dorthin; KEY2 und mittlere Taste blaettern
   weiterhin relativ).
5. docs/hardware/reterminal-e1003.md / CLAUDE.md aktualisieren, falls dort
   das alte page_prev/page_next-Touchprotokoll fuer die Pille dokumentiert
   ist.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
PHP fertig+deployed (siehe oben). Firmware-Code fertig, kompiliert (esp32dev+native), inkl. Pillenhoehe-Fix (56->48px, 10px Abstand zur Trennlinie, Nutzerbefund 2026-08-29) -- ABER Reflash noch nicht abgeschlossen: USB-Verbindung zum Geraet (/dev/cu.wchusbserial*) fiel waehrend zweier Flash-Versuche ab, Geraet verschwindet wiederholt aus der USB-Liste. Erster Flash (nur die absolute Navigation, ohne Pillenhoehe-Fix und ohne FIRMWARE_BUILD-Bump) war erfolgreich und laeuft aktuell -- Nutzer bestaetigt 'navigation funktioniert'. Der zweite Flash (FIRMWARE_BUILD 59->60 + Pillenhoehe-Fix) ist noch offen. Naechster Schritt bei Gelegenheit: Geraet neu verbinden, pio run -e esp32dev -t upload --upload-port <port>.
<!-- SECTION:NOTES:END -->
