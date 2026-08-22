#pragma once
#include <cstdint>
#include <cstddef>

// Liest die beiden weissen "Blaettern"-Tasten (aktiv-low, ~50ms entprellt).
// Sekundaerer, redundanter Eingabeweg zu Touch (Spec §10) -- gibt
// "page_next"/"page_prev" oder nullptr (keine gedrueckt) zurueck.
// Nutzervorgabe 2026-08-22: die weissen Tasten sind das Blaettern-Paar,
// die gruene Taste uebernimmt "Vollstaendiges Update" (s. isFullUpdateButtonHeld()).
const char* readPageButtons();

// Prueft, ob die gruene Taste (GPIO3/KEY0) beim Aufruf mindestens holdMs
// lang gehalten wird (blockierend) -- fuer die Neu-Provisionierung ohne
// Reflash (Spec §10). Nur beim Boot geprueft, nicht waehrend der Aktiv-
// Session (dort uebernimmt isFullUpdateButtonHeld() denselben Pin).
bool isWakeButtonHeld(uint32_t holdMs);

// Prueft, ob die gruene Taste (GPIO3/KEY0) gedrueckt ist, ~50ms entprellt.
// Waehrend das Geraet schlaeft, weckt jeder Druck auf diese Taste es auf
// (Wake-Pinmaske in goToSleep()); ist es schon wach, erzwingt ein kurzer
// Druck stattdessen ein Vollbild statt eines Patches (rtcLastEtag wird
// geleert -> board.php faellt ohne If-None-Match automatisch auf mode=full
// zurueck, kein Server-Change noetig) -- Nutzervorgabe 2026-08-22: "wecken"
// und "Vollupdate" sind derselbe physische Knopf, je nach Geraetezustand.
bool isFullUpdateButtonHeld();

// Rohe, unentprellte Pin-Zustaende aller drei Tasten -- Debug-Hilfe
// (Nutzerwunsch 2026-08-21), um zu sehen ob ueberhaupt ein Tastendruck am
// GPIO ankommt, unabhaengig von Entprell-/Interpretationslogik.
struct RawButtonStates {
    bool key0Low; // gruen/rechts, GPIO3
    bool key1Low; // Mitte, GPIO4
    bool key2Low; // links, GPIO5
};
RawButtonStates readRawButtonStates();
