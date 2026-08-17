#pragma once

enum class TouchZone {
    None,
    Fav0,
    Fav1,
    Fav2,
    PagePrev,
    PageNext,
};

// Reine Funktion: bildet einen Touch-Punkt (Panel-Pixelkoordinaten,
// 1872x1404) auf eine Zone ab. favoriteCount (0-3) und totalPages (>=1)
// kommen aus X-Board-Favorite-Count/X-Board-Total-Pages der letzten
// erfolgreichen Antwort (Spec §5) -- ohne Hardware/GT911-Abhaengigkeit
// testbar, s. Global Constraints.
TouchZone mapTouchToZone(int x, int y, int favoriteCount, int totalPages);

// X-Device-Touch-Wert (Spec §5) fuer eine Zone, oder nullptr bei
// TouchZone::None (dann wird der Header gar nicht gesetzt).
const char* touchZoneToHeaderValue(TouchZone zone);
