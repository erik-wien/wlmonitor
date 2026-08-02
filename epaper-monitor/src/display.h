#pragma once
#include "board_model.h"
#include "error_state.h"
#include <ctime>

void initDisplay();

// Voller Refresh (kein Partial Refresh, Spec §3). generatedEpoch/estimatedNow
// steuern die "Stand HH:MM"-Invertierung (isStale(), Spec §9); banner
// ueberlagert die Kopfzeile bei Verbindungs-/Token-Problemen.
void renderBoard(const BoardResponse& board, time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner);
