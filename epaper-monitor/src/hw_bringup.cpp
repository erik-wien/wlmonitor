// Isolierter Hardware-Bring-up-Test fuer das reTerminal E1003 (ersetzt das
// alte bw_test.cpp, das gegen den frueheren Waveshare-7,5"-Panel gebaut
// war). Zeichnet nur "HELLO E1003" auf einen sonst leeren Screen -- prueft
// Verkabelung/SPI-Timing/Panel-Init, unabhaengig von WLAN, Touch, HTTPS
// oder allem anderen aus main.cpp. Nur im Environment [env:bringup]
// kompiliert (siehe platformio.ini), main.cpp ist dort ausgeschlossen
// (sonst zwei setup()/loop()-Definitionen).
#include <Arduino.h>
#include <it8951/GxEPD2_it103_1872x1404.h>
#include <GxEPD2_BW.h>
#include <Fonts/FreeSansBold24pt7b.h>

#define EPD_CS_PIN      10
#define EPD_RES_PIN     12
#define EPD_BUSY_PIN    13
#define EPD_TFT_ENABLE  11
#define EPD_ITE_ENABLE  21
#define EPD_SCK_PIN     7
#define EPD_MISO_PIN    8
#define EPD_MOSI_PIN    9
// UNGEKLAERT (s. Task 5): kein DC-Pin in der Seeed-Wiki-Pinliste, -1 =
// GxEPD2s "nicht verdrahtet"-Konvention -- vor dem ersten Flash pruefen.
#define EPD_DC_PIN      -1

static GxEPD2_BW<GxEPD2_it103_1872x1404, 128> epd(
    GxEPD2_it103_1872x1404(EPD_CS_PIN, EPD_DC_PIN, EPD_RES_PIN, EPD_BUSY_PIN));

void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("[bringup] setup() start");

    pinMode(EPD_TFT_ENABLE, OUTPUT);
    digitalWrite(EPD_TFT_ENABLE, HIGH);
    pinMode(EPD_ITE_ENABLE, OUTPUT);
    digitalWrite(EPD_ITE_ENABLE, HIGH);

    // GxEPD2_it103_1872x1404 ignoriert selectSPI() (s. Task 5) -- Pins
    // direkt auf dem globalen SPI-Objekt binden, vor epd.init().
    SPI.begin(EPD_SCK_PIN, EPD_MISO_PIN, EPD_MOSI_PIN, -1);
    epd.init(115200, true, 20, false);
    // Kein setRotation() noetig: die Klasse ist bereits nativ 1872x1404.

    Serial.println("[bringup] init() done, drawing");

    epd.setFullWindow();
    epd.firstPage();
    do {
        epd.fillScreen(GxEPD_WHITE);
        epd.setFont(&FreeSansBold24pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(100, 200);
        epd.print("HELLO E1003");
    } while (epd.nextPage());

    Serial.println("[bringup] draw complete");
}

void loop() {
}
