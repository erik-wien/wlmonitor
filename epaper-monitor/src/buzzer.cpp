#include "buzzer.h"
#include <Arduino.h>

// GPIO45 lt. Seeed reTerminal-E-Series "Buzzer Control"-Doku (Nutzer
// 2026-08-21).
#define BUZZER_PIN 45

void beepConfirm() {
    // "Single beep: Confirmation" -- 1kHz/100ms, s. Doku-Beispiel.
    tone(BUZZER_PIN, 1000, 100);
}
