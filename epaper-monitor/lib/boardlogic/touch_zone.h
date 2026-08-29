#pragma once

enum class TouchZone {
    None,
    Fav0,
    Fav1,
    Fav2,
    Page, // absolute Seite angetippt -- Zielseite steht in TouchResult::page
};

// page ist nur gueltig, wenn zone == TouchZone::Page (1-indizierte
// Zielseite). Fuer alle anderen Zonen bleibt es 0.
//
// Expliziter Konstruktor statt reiner Default-Member-Initializer: der
// esp32dev-Toolchain (aeltere GCC-Version ohne C++14-Aggregatregeln fuer
// Structs mit Default-Werten) lehnt sonst Brace-Init wie
// "return {TouchZone::Page, slot + 1};" ab (am Geraet-Build gefunden,
// waehrend die native Testumgebung mit neuerem GCC das klaglos akzeptierte).
struct TouchResult {
    TouchZone zone;
    int page;
    TouchResult(TouchZone z = TouchZone::None, int p = 0) : zone(z), page(p) {}
};

// Reine Funktion: bildet einen Touch-Punkt (Panel-Pixelkoordinaten,
// 1872x1404) auf eine Zone ab. favoriteCount (0-3) und totalPages (>=1)
// kommen aus X-Board-Favorite-Count/X-Board-Total-Pages der letzten
// erfolgreichen Antwort (Spec §5) -- ohne Hardware/GT911-Abhaengigkeit
// testbar, s. Global Constraints.
//
// Die Pagination-Pille ist seit TASK-25 (Nutzerwunsch 2026-08-27: "Vor/
// zurueck ist ein Anachronismus", "vier Sprungziele, absolut nicht relativ")
// ABSOLUT: jeder Icon-Slot springt direkt zu seiner eigenen Seitenzahl,
// nicht mehr links/rechts-Haelfte = vor/zurueck. Betrifft NUR den
// Touchscreen -- die physischen Tasten (KEY2, mittlere Taste in main.cpp)
// senden weiterhin die hartcodierten Strings "page_prev"/"page_next", davon
// unabhaengig: eine Taste kann keine Zielseite ausdruecken.
TouchResult mapTouchToZone(int x, int y, int favoriteCount, int totalPages);

// X-Device-Touch-Wert fuer Fav0/Fav1/Fav2, oder nullptr fuer None/Page
// (Page hat keinen festen Wert -- der Aufrufer formatiert "page_<N>" selbst
// aus TouchResult::page, s. main.cpp).
const char* touchZoneToHeaderValue(TouchZone zone);
