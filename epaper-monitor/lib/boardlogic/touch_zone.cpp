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
const int PAGINATION_ROW_BOTTOM = 1308; // exklusiv (Top + Height)
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

TouchZone mapPaginationTouch(int x, int totalPages) {
    // Praktisch nie <=1 (der Schlafschirm-Slot macht totalPages >= 2) -- als
    // Schutz stehen gelassen, exakt wie im PHP-Gegenstueck.
    if (totalPages <= 1) return TouchZone::None;

    int pillWidth = totalPages * PAGINATION_SLOT_WIDTH + PAGINATION_SIDE_PADDING;
    int pillStartX = PAGINATION_RIGHT_EDGE - pillWidth;

    if (x < pillStartX || x >= PAGINATION_RIGHT_EDGE) return TouchZone::None;

    // Keine Pfeile mehr sichtbar (Nutzerwunsch 2026-08-23) -- der linke/
    // rechte Pillenhalbraum bleibt trotzdem als unsichtbare Zone aktiv,
    // die physischen weissen Tasten sind ohnehin der primaere Navigationsweg.
    int mid = pillStartX + pillWidth / 2;
    return (x < mid) ? TouchZone::PagePrev : TouchZone::PageNext;
}

} // namespace

TouchZone mapTouchToZone(int x, int y, int favoriteCount, int totalPages) {
    if (y >= FAVORITE_ROW_TOP && y < FAVORITE_ROW_BOTTOM) {
        return mapFavoriteTouch(x, favoriteCount);
    }
    if (y >= PAGINATION_ROW_TOP && y < PAGINATION_ROW_BOTTOM) {
        return mapPaginationTouch(x, totalPages);
    }
    return TouchZone::None;
}

const char* touchZoneToHeaderValue(TouchZone zone) {
    switch (zone) {
        case TouchZone::Fav0: return "fav0";
        case TouchZone::Fav1: return "fav1";
        case TouchZone::Fav2: return "fav2";
        case TouchZone::PagePrev: return "page_prev";
        case TouchZone::PageNext: return "page_next";
        default: return nullptr;
    }
}
