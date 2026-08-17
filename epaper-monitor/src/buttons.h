#pragma once
#include <cstdint>

// Liest die beiden Seiten-Navigationstasten (aktiv-low, ~50ms entprellt).
// Sekundaerer, redundanter Eingabeweg zu Touch (Spec §10) -- gibt
// "page_prev"/"page_next" oder nullptr (keine Taste gedrueckt) zurueck.
const char* readPageButtons();

// Prueft, ob die gruene Wake-/Refresh-Taste (GPIO3/KEY0) beim Aufruf
// mindestens holdMs lang gehalten wird (blockierend) -- fuer die
// Neu-Provisionierung ohne Reflash (Spec §10).
bool isWakeButtonHeld(uint32_t holdMs);
