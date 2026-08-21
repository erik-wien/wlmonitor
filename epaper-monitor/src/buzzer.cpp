#include "buzzer.h"
#include <Arduino.h>

// GPIO45 lt. Seeed reTerminal-E-Series "Buzzer Control"-Doku.
//
// ACHTUNG: GPIO45 ist beim ESP32-S3 zugleich ein STRAPPING-PIN
// (VDD_SPI-Spannungswahl: LOW = 3,3V, HIGH = 1,8V, ausgewertet im Moment
// des Resets). Bleibt der Pin nach einem Ton HIGH und es kommt ein Reset,
// waehlt der Chip 1,8V fuer den externen Flash, der mit 3,3V laeuft --
// er startet dann nicht und bleibt auf UART0 stumm.
// Deshalb nach jedem Ton explizit noTone() UND den Pin nach LOW ziehen,
// statt ihn in einem undefinierten Zustand zu hinterlassen.
// Siehe docs/hardware/reterminal-e1003.md §17 "Strapping-Pins".
#define BUZZER_PIN 45

void beepConfirm() {
    // "Single beep: Confirmation" -- 1kHz/100ms, s. Doku-Beispiel.
    tone(BUZZER_PIN, 1000, 100);
    delay(110);          // Ton auslaufen lassen (tone() ist nicht blockierend)
    noTone(BUZZER_PIN);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);   // Strapping-Pegel definiert LOW halten
}
