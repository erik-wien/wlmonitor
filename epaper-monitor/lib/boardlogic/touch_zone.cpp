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
// inc/board_template.php (Nutzerwunsch 2026-08-23: "50% groesser, ohne
// Pfeile, nur Seitennummern"). Die Pille ist RECHTSBUENDIG an
// PAGINATION_RIGHT_EDGE verankert und waechst bei mehr Seiten nach LINKS --
// seit der Schlafschirm immer eine zusaetzliche letzte Seite ist, ist
// totalPages nie mehr 1 und oft 3-5, eine linksbuendige Pille waere ueber die
// Spaltentrennlinie hinausgewachsen.
const int PAGINATION_ROW_TOP = 1252;
// 1300 (Nutzerbefund 2026-08-29: Pille beruehrte optisch die Trennlinie bei
// y=1310, nur 2px Abstand -- war 1308/HEIGHT=56, jetzt HEIGHT=48 fuer 10px
// Luft, s. BOARD_PAGINATION_HEIGHT in inc/board_template.php).
const int PAGINATION_ROW_BOTTOM = 1300; // exklusiv (Top + Height)
const int PAGINATION_RIGHT_EDGE = 1083;
const int PAGINATION_SLOT_WIDTH = 87;
const int PAGINATION_SIDE_PADDING = 20;

TouchZone mapFavoriteTouch(int x, int favoriteCount) {
    if (favoriteCount <= 0) return TouchZone::None;

    int buttonWidth = (PANEL_WIDTH - 2 * FAVORITE_MARGIN - (favoriteCount - 1) * FAVORITE_GAP) / favoriteCount;
    for (int i = 0; i < favoriteCount; i++) {
        int xStart = FAVORITE_MARGIN + i * (buttonWidth + FAVORITE_GAP);
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

    int pillWidth = totalPages * PAGINATION_SLOT_WIDTH + PAGINATION_SIDE_PADDING;
    int pillStartX = PAGINATION_RIGHT_EDGE - pillWidth;

    if (x < pillStartX || x >= PAGINATION_RIGHT_EDGE) return {};

    // Absolut statt links/rechts (TASK-25, Nutzerwunsch 2026-08-27: "Vor/
    // zurueck ist ein Anachronismus"): jeder Slot IST die Zielseite. Der
    // letzte Slot schluckt den rechten Padding-Streifen (Klemmung auf
    // totalPages-1) -- deckungsgleich mit board_touch_zones() in
    // inc/board_template.php, die aus demselben Grund ihre letzte Zone bis
    // PAGINATION_RIGHT_EDGE statt nur bis zum rechnerischen Slotende zeichnet.
    int slot = (x - pillStartX) / PAGINATION_SLOT_WIDTH;
    if (slot < 0) slot = 0;
    if (slot >= totalPages) slot = totalPages - 1;
    return { TouchZone::Page, slot + 1 };
}

} // namespace

TouchResult mapTouchToZone(int x, int y, int favoriteCount, int totalPages) {
    if (y >= FAVORITE_ROW_TOP && y < FAVORITE_ROW_BOTTOM) {
        return { mapFavoriteTouch(x, favoriteCount), 0 };
    }
    if (y >= PAGINATION_ROW_TOP && y < PAGINATION_ROW_BOTTOM) {
        return mapPaginationTouch(x, totalPages);
    }
    return {};
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
