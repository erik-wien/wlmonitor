#include "buttons.h"
#include <Arduino.h>

// Tastenbelegung, bestaetigt durch Seeeds reTerminal-E-Series-"User Buttons"-
// Doku (Nutzer 2026-08-21): fuer E1001/E1002/E1003 ist KEY0 (GPIO3) die
// gruene Taste rechts, KEY1 (GPIO4) die mittlere, KEY2 (GPIO5) die linke.
//   KEY0 (GPIO3, gruen, rechts) -- schlaeft: aufwecken. wach: kurz
//                                  vollstaendiges Update, lang (beim Boot)
//                                  WLAN/Token-Reset (Nutzervorgabe 2026-08-22:
//                                  "wecken"/"Vollupdate" derselbe Knopf).
//   KEY1 (GPIO4, Mitte, weiss)  -- Seite weiter
//   KEY2 (GPIO5, links, weiss)  -- Seite zurueck (Nutzervorgabe 2026-08-22:
//                                  die weissen Tasten sind das Blaettern-Paar)
//
// pinMode(..., INPUT) OHNE _PULLUP: die Hardware hat laut derselben Doku
// bereits eigene Pull-up-Widerstaende ("Hardware already has pull-up
// resistors, so use INPUT mode") -- vorher faelschlich INPUT_PULLUP gesetzt.
#define PIN_KEY0 3
#define PIN_KEY1 4
#define PIN_KEY2 5

static const uint32_t DEBOUNCE_MS = 50;

const char* readPageButtons() {
    pinMode(PIN_KEY1, INPUT);
    pinMode(PIN_KEY2, INPUT);

    if (digitalRead(PIN_KEY1) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY1) == LOW) return "page_next";
    }
    if (digitalRead(PIN_KEY2) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY2) == LOW) return "page_prev";
    }
    return nullptr;
}

bool isWakeButtonHeld(uint32_t holdMs) {
    pinMode(PIN_KEY0, INPUT);
    if (digitalRead(PIN_KEY0) != LOW) return false;

    uint32_t start = millis();
    while (digitalRead(PIN_KEY0) == LOW) {
        if (millis() - start >= holdMs) return true;
        delay(20);
    }
    return false;
}

bool isFullUpdateButtonHeld() {
    pinMode(PIN_KEY0, INPUT);
    if (digitalRead(PIN_KEY0) != LOW) return false;

    delay(DEBOUNCE_MS);
    return digitalRead(PIN_KEY0) == LOW;
}

RawButtonStates readRawButtonStates() {
    pinMode(PIN_KEY0, INPUT);
    pinMode(PIN_KEY1, INPUT);
    pinMode(PIN_KEY2, INPUT);
    return RawButtonStates{
        digitalRead(PIN_KEY0) == LOW,
        digitalRead(PIN_KEY1) == LOW,
        digitalRead(PIN_KEY2) == LOW,
    };
}
