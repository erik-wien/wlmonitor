// Isolierter Hardware-Bring-up-Test fuer das reTerminal E1003, auf Seeeds
// eigener Seeed_GFX/EPaper-Bibliothek (Setup522, s. platformio.ini) statt dem
// Community-GxEPD2-Treiber -- Wechselgrund s. Kommentar bei [env:bringup] in
// platformio.ini. Zeichnet nur "HELLO E1003" auf einen sonst leeren Screen.
// Nur im Environment [env:bringup] kompiliert (main.cpp ist dort
// ausgeschlossen, sonst zwei setup()/loop()-Definitionen).
#include <Arduino.h>
#include "TFT_eSPI.h"

#ifndef EPAPER_ENABLE
#error "BOARD_SCREEN_COMBO muss auf 522 (reTerminal E1003) stehen -- s. platformio.ini [env:bringup]"
#endif

EPaper epaper;

void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("[bringup] setup() start");

    epaper.begin();
    Serial.println("[bringup] epaper.begin() done, drawing");

    epaper.fillScreen(TFT_WHITE);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setTextSize(6);
    epaper.setCursor(100, 200);
    epaper.print("HELLO E1003");

    Serial.println("[bringup] update() -- refresh startet");
    epaper.update();

    Serial.println("[bringup] draw complete");
}

void loop() {
}
