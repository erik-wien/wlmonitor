// Isolierter Diagnosetest (2026-08-03): treibt das Panel NUR mit GxEPD2s
// Schwarz/Weiss-Klasse an (kein Rot-Layer), um zu pruefen, ob das Problem
// spezifisch am 3-Farb-Refresh liegt oder generell an Verkabelung/Timing.
// Wird nur im Environment [env:bwtest] kompiliert (siehe platformio.ini),
// main.cpp ist dort ausgeschlossen (kein doppeltes setup()/loop()).
#include <Arduino.h>
#include <GxEPD2_BW.h>
#include <gdey/GxEPD2_750_GDEY075T7.h>
#include <Fonts/FreeSansBold24pt7b.h>

#define EPD_CS    15
#define EPD_DC    27
#define EPD_RST   26
#define EPD_BUSY  25

GxEPD2_BW<GxEPD2_750_GDEY075T7, GxEPD2_750_GDEY075T7::HEIGHT / 2> display(
    GxEPD2_750_GDEY075T7(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));

void setup() {
    Serial.begin(115200);
    delay(300);
    Serial.println("[bwtest] init");
    display.init(115200);
    display.setRotation(0);

    Serial.println("[bwtest] setFullWindow");
    display.setFullWindow();
    Serial.println("[bwtest] firstPage");
    display.firstPage();
    do {
        display.fillScreen(GxEPD_WHITE);
        display.setFont(&FreeSansBold24pt7b);
        display.setTextColor(GxEPD_BLACK);
        display.setCursor(40, 100);
        display.print("BW TEST OK");
    } while (display.nextPage());
    Serial.println("[bwtest] fertig");
}

void loop() {}
