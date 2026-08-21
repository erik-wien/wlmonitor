#pragma once

// Kurzer Bestaetigungston (GPIO45), sofort wenn eine Touch-/Tasten-
// Interaktion erkannt wurde (Nutzerwunsch 2026-08-21) -- ergaenzt den
// visuellen in:-Marker (display.h) um wirklich sofortiges Feedback, da ein
// e-Paper-Partial-Update ~1-2s dauert.
void beepConfirm();

// Doppel-Piep: "Eingabe erkannt, ich lade" (Nutzervorgabe 2026-08-21).
void beepRecognized();

// Waermt den LEDC-Audiokanal einmalig auf (der allererste tone()-Aufruf nach
// dem Boot scheitert sonst stumm mit "LEDC is not initialized" und
// initialisiert dabei nur die Hardware -- der naechste, eigentliche Piep
// war bisher der erste, der wirklich zu hoeren war). Ganz am Boot-Anfang
// aufrufen, bevor irgendein User-facing Piep drankommt.
void buzzerWarmup();
