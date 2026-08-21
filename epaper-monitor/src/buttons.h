#pragma once
#include <cstdint>
#include <cstddef>

// Liest die weisse "Seite weiter"-Taste (aktiv-low, ~50ms entprellt).
// Sekundaerer, redundanter Eingabeweg zu Touch (Spec §10) -- gibt
// "page_next" oder nullptr (nicht gedrueckt) zurueck.
const char* readPageButtons();

// Prueft, ob die gruene Wake-/Refresh-Taste (GPIO3/KEY0) beim Aufruf
// mindestens holdMs lang gehalten wird (blockierend) -- fuer die
// Neu-Provisionierung ohne Reflash (Spec §10).
bool isWakeButtonHeld(uint32_t holdMs);

// Prueft, ob die weisse "Vollstaendiges Update"-Taste (ganz links, GPIO5/
// KEY2) gedrueckt ist, ~50ms entprellt. Erzwingt in main.cpp ein Vollbild
// statt eines Patches (rtcLastEtag wird geleert -> board.php faellt ohne
// If-None-Match automatisch auf mode=full zurueck, kein Server-Change noetig).
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
