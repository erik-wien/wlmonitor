#include "display.h"
#include <GxEPD2_BW.h>
#include <Fonts/FreeSansBold9pt7b.h>

// Setup522_Seeed_reTerminal_E1003 (ED103TC2, 1872x1404) -- Panel-/SPI-Pins
// aus den Global Constraints. Die exakte GxEPD2-Panelklasse
// (GxEPD2_ED103TC2_1872x1404) kommt aus der Seeed_GxEPD2-Bibliothek
// (lib_deps in platformio.ini, Task 8) -- unverifiziert ohne Hardware, ob
// der Klassenname exakt so lautet; falls nicht, gegen die tatsaechlich
// installierte Bibliotheksversion korrigieren.
#define EPD_SCK_PIN     7
#define EPD_MISO_PIN    8
#define EPD_MOSI_PIN    9
#define EPD_CS_PIN      10
#define EPD_RES_PIN     12
#define EPD_BUSY_PIN    13
#define EPD_TFT_ENABLE  11
#define EPD_ITE_ENABLE  21

static const int PANEL_W = 1872;
static const int PANEL_H = 1404;

// WLAN-Balken-Rechteck der Kopfzeile (Spec §9: translate(1665,46), 3 Balken).
static const int WIFI_ICON_X = 1665;
static const int WIFI_ICON_Y = 46;
static const int WIFI_ICON_W = 54;
static const int WIFI_ICON_H = 28;

static SPIClass hspi(HSPI);
static GxEPD2_BW<GxEPD2_ED103TC2_1872x1404, GxEPD2_ED103TC2_1872x1404::HEIGHT> epd(
    GxEPD2_ED103TC2_1872x1404(EPD_CS_PIN, EPD_RES_PIN, EPD_BUSY_PIN));

void initDisplay() {
    pinMode(EPD_TFT_ENABLE, OUTPUT);
    digitalWrite(EPD_TFT_ENABLE, HIGH);
    pinMode(EPD_ITE_ENABLE, OUTPUT);
    digitalWrite(EPD_ITE_ENABLE, HIGH);

    hspi.begin(EPD_SCK_PIN, EPD_MISO_PIN, EPD_MOSI_PIN, -1);
    epd.epd2.selectSPI(hspi, SPISettings(10000000, MSBFIRST, SPI_MODE0));
    epd.init(0, true, 10, false);
    epd.setRotation(1); // Querformat: 1872x1404 statt nativ 1404x1872 (Spec §3)
}

void applyFullFrame(const uint8_t* packed, int w, int h) {
    // drawBitmap() erwartet 1bpp MSB-first mit GxEPD_BLACK als "gesetztes
    // Bit"-Farbe -- die Bit-Konvention des Protokolls ist umgekehrt
    // (1=weiss), daher invert=true.
    epd.setFullWindow();
    epd.firstPage();
    do {
        epd.fillScreen(GxEPD_WHITE);
        epd.drawInvertedBitmap(0, 0, packed, w, h, GxEPD_BLACK);
    } while (epd.nextPage());
}

void applyPatch(const uint8_t* packed, int x, int y, int w, int h) {
    epd.setPartialWindow(x, y, w, h);
    epd.firstPage();
    do {
        epd.drawInvertedBitmap(x, y, packed, w, h, GxEPD_BLACK);
    } while (epd.nextPage());
}

void showErrorBanner(ErrorBanner banner, const char* sinceTime) {
    if (banner == ErrorBanner::None) return;

    char text[64];
    if (banner == ErrorBanner::Offline) {
        snprintf(text, sizeof(text), "offline seit %s", sinceTime);
    } else {
        snprintf(text, sizeof(text), "Token ungueltig");
    }

    // Kleines Banner-Rechteck oben links in der Kopfzeile, ueberlagert den
    // Server-Renderzeit-Text nicht (der sitzt zentriert bei x=936).
    const int bannerX = 16, bannerY = 10, bannerW = 700, bannerH = 70;
    epd.setPartialWindow(bannerX, bannerY, bannerW, bannerH);
    epd.firstPage();
    do {
        epd.fillRect(bannerX, bannerY, bannerW, bannerH, GxEPD_WHITE);
        epd.setFont(&FreeSansBold9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(bannerX + 8, bannerY + bannerH - 20);
        epd.print(text);
    } while (epd.nextPage());
}

void markSleepIcon() {
    epd.setPartialWindow(WIFI_ICON_X, WIFI_ICON_Y, WIFI_ICON_W, WIFI_ICON_H);
    epd.firstPage();
    do {
        epd.fillRect(WIFI_ICON_X, WIFI_ICON_Y, WIFI_ICON_W, WIFI_ICON_H, GxEPD_WHITE);
        epd.setFont(&FreeSansBold9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(WIFI_ICON_X, WIFI_ICON_Y + WIFI_ICON_H - 6);
        epd.print("zzz");
    } while (epd.nextPage());
}

void sleepPanel() {
    epd.hibernate();
}
