#pragma once

// Kurzer Bestaetigungston (GPIO45), sofort wenn eine Touch-/Tasten-
// Interaktion erkannt wurde (Nutzerwunsch 2026-08-21) -- ergaenzt den
// visuellen in:-Marker (display.h) um wirklich sofortiges Feedback, da ein
// e-Paper-Partial-Update ~1-2s dauert.
void beepConfirm();
