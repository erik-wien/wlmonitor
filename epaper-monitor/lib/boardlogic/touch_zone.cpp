#include "touch_zone.h"

namespace {

const int FAVORITE_ROW_TOP = 1320;
const int FAVORITE_ROW_BOTTOM = 1394; // exklusiv
const int FAVORITE_MARGIN = 16;
const int FAVORITE_GAP = 16;
const int PANEL_WIDTH = 1872;

const int PAGINATION_ROW_TOP = 1256;
const int PAGINATION_ROW_BOTTOM = 1304; // exklusiv
const int PAGINATION_PILL_START_X = 793;
const int PAGINATION_ARROW_BASE_X = 822;
const int PAGINATION_NUMBER_START_X = 880;
const int PAGINATION_SLOT_WIDTH = 58;
const int PAGINATION_MIN_PILL_WIDTH = 290;

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
    if (totalPages <= 1) return TouchZone::None;

    int arrowX = PAGINATION_NUMBER_START_X + totalPages * PAGINATION_SLOT_WIDTH;
    int pillWidth = arrowX - PAGINATION_ARROW_BASE_X + PAGINATION_SLOT_WIDTH;
    if (pillWidth < PAGINATION_MIN_PILL_WIDTH) pillWidth = PAGINATION_MIN_PILL_WIDTH;

    int pillEnd = PAGINATION_PILL_START_X + pillWidth;
    if (x < PAGINATION_PILL_START_X || x >= pillEnd) return TouchZone::None;

    int mid = PAGINATION_PILL_START_X + pillWidth / 2;
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
