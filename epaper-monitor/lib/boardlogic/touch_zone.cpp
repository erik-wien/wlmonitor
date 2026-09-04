#include "touch_zone.h"

namespace {

const int FAVORITE_ROW_TOP = 1320;
// Sichtbarer Button endet bei y=1394, aber bis zum physischen Bildschirmrand
// (Panelhoehe 1404) ist darunter nichts anderes -- Trefferzone bis dorthin
// ausgedehnt, echte Finger-Taps landen sonst knapp unterhalb der Schaltflaeche
// ins Leere (Nutzerbefund 2026-08-22: Tap bei y=1397-1399 loeste nichts aus).
const int FAVORITE_ROW_BOTTOM = 1404; // exklusiv
const int FAVORITE_MARGIN = 16;
const int FAVORITE_GAP = 16;
const int PANEL_WIDTH = 1872;

// Deckungsgleich mit den BOARD_PAGINATION_*-Konstanten in
// inc/board_template.php.
//
// Seit 2026-09-04 steht die Pille LINKS IN DER FAVORITENZEILE (Nutzerwunsch:
// "die ganze Hauptnavigation in einer Zeile"). Damit:
//   - gleiche Zeile wie die Favoriten (kein eigenes Band mehr darueber),
//   - LINKSbuendig ab PAGINATION_LEFT_EDGE, waechst nach RECHTS
//     (vorher rechtsbuendig auf x=1083 verankert, nach links wachsend),
//   - Slots/Kreis groesser, weil die Zeile 74 statt 48 Pixel hoch ist.
// Die Favoriten fangen erst hinter der Pille an -- s. mapFavoriteTouch().
const int PAGINATION_LEFT_EDGE = FAVORITE_MARGIN;
const int PAGINATION_SLOT_WIDTH = 100;   // war 87
const int PAGINATION_SIDE_PADDING = 20;

// Breite der Pille. Muss mit board_pagination_pill_width() in
// inc/board_template.php uebereinstimmen -- die Favoritenzonen haengen daran.
int paginationPillWidth(int totalPages) {
    if (totalPages <= 1) return 0;  // keine Pille -> Favoriten volle Breite
    return totalPages * PAGINATION_SLOT_WIDTH + PAGINATION_SIDE_PADDING;
}

TouchZone mapFavoriteTouch(int x, int favoriteCount, int totalPages) {
    if (favoriteCount <= 0) return TouchZone::None;

    // Die Pille belegt den linken Teil derselben Zeile. Ihr Platz wird auch
    // dann freigehalten, wenn sie gerade nicht gezeichnet wird (echter
    // Schlafschirm) -- sonst waeren Bild und Zonen verschoben. Deckungsgleich
    // mit board_render_touch_bar_svg().
    int left = FAVORITE_MARGIN + paginationPillWidth(totalPages);
    if (totalPages > 1) left += FAVORITE_GAP;

    int verfuegbar = PANEL_WIDTH - FAVORITE_MARGIN - left - (favoriteCount - 1) * FAVORITE_GAP;
    int buttonWidth = verfuegbar / favoriteCount;
    if (buttonWidth <= 0) return TouchZone::None;

    for (int i = 0; i < favoriteCount; i++) {
        int xStart = left + i * (buttonWidth + FAVORITE_GAP);
        int xEnd = xStart + buttonWidth;
        if (x >= xStart && x < xEnd) {
            switch (i) {
                case 0: return TouchZone::Fav0;
                case 1: return TouchZone::Fav1;
                case 2: return TouchZone::Fav2;
            }
        }
    }
    return TouchZone::None;
}

TouchResult mapPaginationTouch(int x, int totalPages) {
    // Praktisch nie <=1 (der Schlafschirm-Slot macht totalPages >= 2) -- als
    // Schutz stehen gelassen, exakt wie im PHP-Gegenstueck.
    if (totalPages <= 1) return {};

    int pillStartX = PAGINATION_LEFT_EDGE;
    int pillEndX = pillStartX + paginationPillWidth(totalPages);

    if (x < pillStartX || x >= pillEndX) return {};

    // Absolut statt links/rechts (TASK-25, Nutzerwunsch 2026-08-27: "Vor/
    // zurueck ist ein Anachronismus"): jeder Slot IST die Zielseite. Der
    // letzte Slot schluckt den rechten Padding-Streifen (Klemmung auf
    // totalPages-1) -- deckungsgleich mit board_touch_zones() in
    // inc/board_template.php, die aus demselben Grund ihre letzte Zone bis
    // zum Pillenende statt nur bis zum rechnerischen Slotende zeichnet.
    int slot = (x - pillStartX) / PAGINATION_SLOT_WIDTH;
    if (slot < 0) slot = 0;
    if (slot >= totalPages) slot = totalPages - 1;
    return { TouchZone::Page, slot + 1 };
}

} // namespace

TouchResult mapTouchToZone(int x, int y, int favoriteCount, int totalPages) {
    // Pille und Favoriten liegen seit 2026-09-04 in DERSELBEN Zeile -- die
    // Pille muss deshalb ZUERST geprueft werden. Andernfalls schluckte
    // mapFavoriteTouch() den Tipp, sobald die Favoritenberechnung den
    // Pillenbereich faelschlich mit abdeckte.
    if (y < FAVORITE_ROW_TOP || y >= FAVORITE_ROW_BOTTOM) return {};

    TouchResult pill = mapPaginationTouch(x, totalPages);
    if (pill.zone != TouchZone::None) return pill;

    return { mapFavoriteTouch(x, favoriteCount, totalPages), 0 };
}

const char* touchZoneToHeaderValue(TouchZone zone) {
    switch (zone) {
        case TouchZone::Fav0: return "fav0";
        case TouchZone::Fav1: return "fav1";
        case TouchZone::Fav2: return "fav2";
        // TouchZone::Page hat keinen festen Wert -- der Aufrufer formatiert
        // "page_<N>" selbst aus TouchResult::page (main.cpp).
        default: return nullptr;
    }
}
