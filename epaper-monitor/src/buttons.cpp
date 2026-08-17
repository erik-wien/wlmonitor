#include "buttons.h"
#include <Arduino.h>

#define PIN_KEY0 3  // Wake/Refresh
#define PIN_KEY1 4  // page_prev (Rolle unverifiziert, s. Global Constraints)
#define PIN_KEY2 5  // page_next (Rolle unverifiziert, s. Global Constraints)

static const uint32_t DEBOUNCE_MS = 50;

const char* readPageButtons() {
    pinMode(PIN_KEY1, INPUT_PULLUP);
    pinMode(PIN_KEY2, INPUT_PULLUP);

    if (digitalRead(PIN_KEY1) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY1) == LOW) return "page_prev";
    }
    if (digitalRead(PIN_KEY2) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY2) == LOW) return "page_next";
    }
    return nullptr;
}

bool isWakeButtonHeld(uint32_t holdMs) {
    pinMode(PIN_KEY0, INPUT_PULLUP);
    if (digitalRead(PIN_KEY0) != LOW) return false;

    uint32_t start = millis();
    while (digitalRead(PIN_KEY0) == LOW) {
        if (millis() - start >= holdMs) return true;
        delay(20);
    }
    return false;
}
