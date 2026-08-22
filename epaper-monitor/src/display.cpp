#include "display.h"
#include <Arduino.h>
#include "TFT_eSPI.h"
// FreeSansBold9pt7b kommt bereits ueber TFT_eSPI.h -> Fonts/GFXFF/gfxfont.h
// (LOAD_GFXFF in Setup522 bindet alle Standard-Free-Fonts automatisch ein,
// s. Doppel-Definitionsfehler beim Versuch, sie hier nochmal zu includieren).

// Setup522_Seeed_reTerminal_E1003 (BOARD_SCREEN_COMBO=522, s. platformio.ini)
// -- Seeeds eigene, offiziell fuer dieses Panel gepflegte Treiberklasse
// (Extensions/EPaper.h in Seeed_GFX), nicht der Community-GxEPD2-Treiber.
//
// KORREKTUR ggue. dem vorigen GxEPD2-basierten Code: der Community-Treiber
// (GxEPD2_it103_1872x1404) hat VCOM hart auf einen fremden Panel-Sticker-Wert
// codiert -- Refresh-Kommandos liefen sauber durch (Busy-Handshake/Timing
// passten), aber am Panel aenderte sich sichtbar NICHTS (verifiziert am
// echten Board, 2026-08-19). Seeeds eigener ED103TC2-Treiber (Setup522)
// wurde am selben Board getestet: "HELLO E1003" rendert korrekt.
//
// Pin-Zuordnung kommt komplett aus Setup522 (SCLK=7, MISO=8, MOSI=9, CS=10,
// DC=-1, BUSY=13, RST=12, ENABLE=11, ITE_ENABLE=21) -- kein eigener
// Pin-Kram mehr in dieser Datei noetig, epaper.begin() erledigt Pins/SPI/
// Reset-Sequenz intern (am Bring-up-Test verifiziert).
static EPaper epaper;

// WLAN-Balken-Rechteck der Kopfzeile (Spec §9: translate(1665,46), 3 Balken).
static const int WIFI_ICON_X = 1665;
static const int WIFI_ICON_Y = 46;
static const int WIFI_ICON_W = 54;
static const int WIFI_ICON_H = 28;

void initDisplay() {
    epaper.begin();
}

// Rohzugriff auf den internen 1bpp-Sprite-Puffer -- fuer den Geraete-
// Screenshot-Upload (board_snapshot.php). Gleiche Packung wie board.phps
// Vollbild-Antworten (MSB-first, Zeilenbreite auf 8px aufgerundet,
// verifiziert: EPD_COLOR_DEPTH=1 fuer den ED103TC2-Treiber, s.
// Extensions/EPaper.cpp::begin() -> createSprite(_width,_height,1)).
const uint8_t* getPanelBuffer() {
    return (const uint8_t*) epaper.getPointer();
}

size_t getPanelBufferSize() {
    const int rowBytes = (epaper.width() + 7) / 8;
    return (size_t) rowBytes * epaper.height();
}

// EPaper::setTemp() nimmt einen parameterlosen Funktionszeiger, ruft ihn
// genau einmal auf und merkt sich das Ergebnis in _temp -- deshalb der
// Umweg ueber eine Dateistatische statt einer Lambda-Capture.
static float s_panelTemp = NAN;
static float panelTempCallback() { return s_panelTemp; }

void applyPanelTemperature(float celsius) {
    if (isnan(celsius)) {
        Serial.println("[temp] SHT4x nicht lesbar -- Panel bleibt bei 16 C");
        return;
    }
    s_panelTemp = celsius;
    epaper.setTemp(panelTempCallback);
    Serial.printf("[temp] Panel-Temperatur gesetzt: %.1f C\n", celsius);
}

// board.php liefert 1bpp-Rohdaten: MSB-first, Zeilenbreite auf ein Vielfaches
// von 8 aufgerundet, 1=weiss/0=schwarz (Spec §6, s. display.h). EPaper::
// drawBufferPixel(x, y, byte, /*bpp=*/1) schreibt genau ein Byte direkt in
// den internen Sprite-Puffer an Byte-Position (x/8, y) -- gleiche MSB-first-
// Packung, gleiche Bit-Konvention (TFT_eSPI Sprite.cpp::drawPixel fuer bpp==1:
// bit gesetzt <=> uebergebene Farbe truthy; epaper.begin() ruft
// setTextColor(TFT_BLACK, TFT_WHITE), TFT_WHITE=0xFFFF ist truthy -> bit=1,
// TFT_BLACK=0 -> bit=0 -- exakt board.phps Konvention, kein Ratewert,
// gegen den Bibliotheks-Quellcode verifiziert). Direkter Byte-Pass-Through,
// keine Pixel-fuer-Pixel-GFX-Zwischenschicht.
static void pushPackedBytes(const uint8_t* packed, int x, int y, int w, int h) {
    const int rowBytes = (w + 7) / 8;
    for (int row = 0; row < h; row++) {
        for (int col = 0; col < rowBytes; col++) {
            epaper.drawBufferPixel(x + col * 8, y + row, packed[row * rowBytes + col], 1);
        }
    }
}

// EPaper::updataPartial() rundet das X-Fenster INTERN auf 8px-Grenzen nach
// aussen (align_px=8 in Extensions/EPaper.cpp), unabhaengig davon, ob das
// aufgerufene Rechteck selbst ausgerichtet ist -- liest also bis zu 7px vor
// x und nach (x+w) direkt aus dem Puffer. Ist dieser Rand nicht selbst
// beschrieben, zeigt das Update dort stehengebliebenen alten Inhalt (am
// echten Geraet beobachtet, 2026-08-21: ein schwarzer Balken direkt rechts
// neben so gut wie jedem Partial-Update). Vor JEDEM updataPartial() den
// tatsaechlich gelesenen, aufgerundeten Bereich weiss vorbelegen, damit der
// Rand garantiert leer statt zufaellig ist -- Y ist von diesem Rundungs-Bug
// nicht betroffen (updataPartial() rundet nur X).
static void clearAlignedForPartial(int x, int y, int w, int h) {
    const int x0 = x & ~7;
    const int x1 = (x + w + 7) & ~7;
    epaper.fillRect(x0, y, x1 - x0, h, TFT_WHITE);
}

void applyFullFrame(const uint8_t* packed, int w, int h) {
    pushPackedBytes(packed, 0, 0, w, h);
    epaper.update();
}

void applyPatch(const uint8_t* packed, int x, int y, int w, int h) {
    clearAlignedForPartial(x, y, w, h);
    pushPackedBytes(packed, x, y, w, h);
    epaper.updataPartial(x, y, w, h);
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
    clearAlignedForPartial(bannerX, bannerY, bannerW, bannerH);
    epaper.setFreeFont(&FreeSansBold9pt7b);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(bannerX + 8, bannerY + bannerH - 20);
    epaper.print(text);
    epaper.updataPartial(bannerX, bannerY, bannerW, bannerH);
}

void markSleepIcon() {
    // setTextSize(1) war bei dieser Panel-DPI unlesbar (Nutzerbefund
    // 2026-08-22) -- Groesse 2 wie showBuildMarker()/showInputMarker(),
    // Flaeche entsprechend vergroessert (Breite reicht bis x=1755, Panel
    // endet bei 1872).
    const int x = WIFI_ICON_X, y = WIFI_ICON_Y, w = 90, h = 50;
    clearAlignedForPartial(x, y, w, h);
    epaper.setFreeFont(&FreeSansBold9pt7b);
    epaper.setTextSize(2);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(x, y + h - 14);
    epaper.print("zzz");
    epaper.setTextSize(1);
    epaper.updataPartial(x, y, w, h);
}

void sleepPanel() {
    epaper.sleep();
}

void drawTouchBlob(int x, int y) {
    const int r = 24;
    // Nutzerbefund 2026-08-22: die Oberkante des Blobs erschien dort, wo
    // tatsaechlich getippt wurde, statt der Mitte -- um die halbe Blob-
    // Hoehe (r+6, der aussere Ring) nach oben verschoben.
    const int cy = y - (r + 6);
    const int bx = x - r - 4, by = cy - r - 4, bw = 2 * (r + 4), bh = 2 * (r + 4);
    clearAlignedForPartial(bx, by, bw, bh);
    epaper.fillCircle(x, cy, r, TFT_BLACK);
    epaper.drawCircle(x, cy, r + 6, TFT_BLACK);
    epaper.updataPartial(bx, by, bw, bh);
}

void showBuildMarker(int build) {
    char text[16];
    snprintf(text, sizeof(text), "fw%d", build);

    // Direkt rechts neben "Stand HH:MM" (server-gerendert bei x=16, y=1286,
    // 24px -- endet bei ca. x=161), vor der Pagination-Pille (beginnt bei
    // x=793), s. board_render_stand_and_pagination_svg(). setTextSize(2) --
    // Nutzerwunsch 2026-08-21 ("Text doppelt so gross").
    const int x = 190, y = 1256, w = 150, h = 50;
    clearAlignedForPartial(x, y, w, h);
    epaper.setFreeFont(&FreeSansBold9pt7b);
    epaper.setTextSize(2);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(x, y + h - 14);
    epaper.print(text);
    epaper.setTextSize(1);
    epaper.updataPartial(x, y, w, h);
}

void showInputMarker(const char* label) {
    char text[80];
    snprintf(text, sizeof(text), "in:%s", label);

    // Direkt rechts neben dem Build-Marker (x=190..340), gleiche Zeile.
    // Breit genug fuer "K2 Update [-X-]" bei doppelter Schriftgroesse
    // (Nutzerwunsch 2026-08-21), bleibt vor der Pagination-Pille (x=793,
    // s. board_render_stand_and_pagination_svg()).
    const int x = 350, y = 1256, w = 440, h = 50;
    clearAlignedForPartial(x, y, w, h);
    epaper.setFreeFont(&FreeSansBold9pt7b);
    epaper.setTextSize(2);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(x, y + h - 14);
    epaper.print(text);
    epaper.setTextSize(1);
    epaper.updataPartial(x, y, w, h);
}

void showStatusOverlay(const char* text) {
    // Deckungsgleich mit BOARD_STATUS_IDLE_TEXT in board_template.php
    // (x=1150, y=1292 Textgrundlinie, font-size 34 bold Atkinson Hyperlegible
    // Next). Breite reicht fuer "Warte auf Eingabe" bei setTextSize(2).
    const int x2 = 1150, y2 = 1256, w2 = 500, h2 = 50;
    clearAlignedForPartial(x2, y2, w2, h2);
    epaper.setFreeFont(&FreeSansBold9pt7b);
    epaper.setTextSize(2);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(x2, y2 + h2 - 14);
    epaper.print(text);
    epaper.setTextSize(1);
    epaper.updataPartial(x2, y2, w2, h2);
}
