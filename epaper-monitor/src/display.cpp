#include "display.h"
#include <Arduino.h>
#include <cstring>
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
//
// Ueber drawBufferPixel() macht EPaper::drawBufferPixel(x,y,c,1) exakt
// _img8[y*(_width/8) + (x/8)] = c -- Ziel- und Quelladressierung sind bei
// x%8==0 bytweise identisch (gegen Extensions/EPaper.cpp verifiziert, s.
// display.h). Bei einem Vollbild (1872x1404, 234 B/Zeile) macht das aus
// 328536 drawBufferPixel()-Einzelaufrufen einen einzigen memcpy; bei einem
// Patch (Ziel gestrided, Quelle fortlaufend) einen memcpy pro Zeile.
// Fallback auf den alten Einzelaufruf-Pfad, falls x nicht byte-ausgerichtet
// ist -- der Server liefert laut inc/board_render.php zwar immer
// byte-ausgerichtete x/w, aber der Client soll sich darauf nicht blind
// verlassen (Ratschlag 2026-08-22).
static void pushPackedBytes(const uint8_t* packed, int x, int y, int w, int h) {
    const int rowBytes = (w + 7) / 8;

    if ((x % 8) != 0) {
        for (int row = 0; row < h; row++) {
            for (int col = 0; col < rowBytes; col++) {
                epaper.drawBufferPixel(x + col * 8, y + row, packed[row * rowBytes + col], 1);
            }
        }
        return;
    }

    uint8_t* buf = (uint8_t*) epaper.getPointer();
    const int destStride = (epaper.width() + 7) / 8; // = 234 fuer dieses Panel
    const int destColStart = x / 8;

    // x/y/w/h stammen aus SERVER-Headern und werden von validateBoardResponse()
    // bewusst nur auf "plausible Zahl <= 100000" geprueft, NICHT gegen die
    // Panelgroesse (s. lib/boardlogic/board_response.cpp). Ein verstuemmelter
    // Header wuerde hier also ueber das Pufferende hinaus schreiben -- als
    // Einzelbyte-Schleife frueher schon schlimm, als memcpy ein zusammen-
    // haengender Ueberschreiber. Lieber gar nicht zeichnen als den Heap
    // zerlegen: das Geraet steht sonst mit korruptem RAM da und braucht einen
    // Power-Cycle (Review-Befund 2026-08-22).
    if (buf == nullptr || x < 0 || y < 0 || w <= 0 || h <= 0
        || destColStart + rowBytes > destStride
        || y + h > epaper.height()) {
        Serial.printf("[display] Rechteck ausserhalb des Panels verworfen: %dx%d @%d,%d\n", w, h, x, y);
        return;
    }

    if (x == 0 && w == epaper.width() && rowBytes == destStride) {
        // Vollbild: Quelle und Ziel sind komplett deckungsgleich -> EIN memcpy.
        memcpy(buf, packed, (size_t) rowBytes * h);
        return;
    }
    for (int row = 0; row < h; row++) {
        memcpy(buf + (size_t) (y + row) * destStride + destColStart,
               packed + (size_t) row * rowBytes, rowBytes);
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
    // Mondsichel statt des frueheren "zzz"-Textes (Nutzerwunsch 2026-08-22,
    // "diskretere Icons") -- dasselbe Symbol wie StatusIcon::Sleep in der
    // Statusleiste, nur groesser, damit es die WLAN-Balken sauber ersetzt.
    // Der Text war in Groesse 1 unlesbar und in Groesse 2 unverhaeltnismaessig
    // wuchtig; das Piktogramm braucht die Diskussion gar nicht erst.
    const int x = WIFI_ICON_X, y = WIFI_ICON_Y - 8, w = WIFI_ICON_W, h = 44;
    clearAlignedForPartial(x, y, w, h);
    const int cx = x + 18, cy = y + h / 2;
    epaper.fillCircle(cx, cy, 16, TFT_BLACK);
    epaper.fillCircle(cx + 9, cy - 7, 14, TFT_WHITE);
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

// --- Statusleiste am unteren Rand der Wetterspalte ---------------------------
//
// Gemeinsames Raster mit den BOARD_STATUS_*-Konstanten in
// inc/board_template.php (Nutzerwunsch 2026-08-22: "kompakter und huebscher,
// Schrift so klein wie 'Stand HH:MM', diskretere Icons"). Zwei getrennte
// Rechtecke im selben 50px-Band, damit eine Statusaenderung nicht jedes Mal
// den Firmware-Marker mit neu schreiben muss:
//
//   STATUS_*  x=1150..1600  Piktogramm + kurzes Wort  (wechselt oft)
//   MARKER_*  x=1608..1864  "fw40" + Modus-Kaestchen  (wechselt fast nie)
//
// Schrift: FreeSans12pt7b bei setTextSize(1). Die frueheren Marker liefen mit
// FreeSansBold9pt7b bei setTextSize(2) -- also ~24px Versalhoehe gegen die
// 17px des 24px-Atkinson im Server-Frame; genau das wirkte am Geraet grob.
// 12pt/Groesse 1 trifft die Server-Zeile.
static const int STATUS_X = 1150, STATUS_Y = 1256, STATUS_W = 450, STATUS_H = 50;
static const int STATUS_ICON_CX = 1164, STATUS_ICON_CY = 1281;
static const int STATUS_TEXT_X = 1194, STATUS_BASELINE = 1290;
static const int MARKER_X = 1608, MARKER_W = 256;

static void drawStatusIcon(StatusIcon icon, int cx, int cy) {
    switch (icon) {
        case StatusIcon::Ready:
            // Ring mit Punkt -- identisch zu board_render_status_icon_svg().
            epaper.drawCircle(cx, cy, 10, TFT_BLACK);
            epaper.drawCircle(cx, cy, 9, TFT_BLACK); // 2px Strichstaerke wie im SVG
            epaper.fillCircle(cx, cy, 4, TFT_BLACK);
            break;
        case StatusIcon::Loading:
            // Pfeil nach unten auf eine Grundlinie (Download).
            epaper.fillRect(cx - 1, cy - 11, 3, 12, TFT_BLACK);
            epaper.fillTriangle(cx - 7, cy - 2, cx + 7, cy - 2, cx, cy + 6, TFT_BLACK);
            epaper.fillRect(cx - 9, cy + 9, 19, 3, TFT_BLACK);
            break;
        case StatusIcon::Full:
            // Rahmen mit gefuelltem Kern -- "das ganze Bild wird neu gesetzt".
            epaper.drawRect(cx - 11, cy - 11, 22, 22, TFT_BLACK);
            epaper.drawRect(cx - 10, cy - 10, 20, 20, TFT_BLACK);
            epaper.fillRect(cx - 5, cy - 5, 10, 10, TFT_BLACK);
            break;
        case StatusIcon::Sleep:
            // Mondsichel: Vollkreis, dann versetzt weiss ausgestanzt.
            epaper.fillCircle(cx, cy, 11, TFT_BLACK);
            epaper.fillCircle(cx + 6, cy - 5, 10, TFT_WHITE);
            break;
    }
}

void showStatus(StatusIcon icon, const char* text) {
    clearAlignedForPartial(STATUS_X, STATUS_Y, STATUS_W, STATUS_H);
    drawStatusIcon(icon, STATUS_ICON_CX, STATUS_ICON_CY);
    epaper.setFreeFont(&FreeSans12pt7b);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);
    epaper.setCursor(STATUS_TEXT_X, STATUS_BASELINE);
    epaper.print(text);
    epaper.updataPartial(STATUS_X, STATUS_Y, STATUS_W, STATUS_H);
}

void showBuildMarker(int build, bool lastFrameWasFull) {
    // VERSALIEN, nicht "fw41" (Nutzerbefund 2026-08-22, "alle Buchstaben gleich
    // gross"): in FreeSans sitzt das kleine w auf x-Hoehe, waehrend f und die
    // Ziffern Versalhoehe haben -- die Marke wirkte dadurch zerfranst und
    // kleiner als sie ist. In Versalien sind alle vier Zeichen gleich hoch.
    char text[16];
    snprintf(text, sizeof(text), "FW%d", build);

    clearAlignedForPartial(MARKER_X, STATUS_Y, MARKER_W, STATUS_H);
    // 12pt statt 9pt (Nutzerwunsch "insgesamt um 20% groesser"). GFX-Schriften
    // gibt es nur in festen Stufen -- die naechste ueber 9pt ist 12pt, also
    // +33% statt der gewuenschten +20%. Damit liegt der Marker auf derselben
    // Versalhoehe wie der Statustext links, was die Zeile eher ruhiger macht.
    epaper.setFreeFont(&FreeSans12pt7b);
    epaper.setTextColor(TFT_BLACK, TFT_WHITE);

    // Rechtsbuendig ans Spaltenende (x=1856 wie die Kopfzeilen-Prozentzahl),
    // Kaestchen dahinter. textWidth() beruecksichtigt die gesetzte GFX-Schrift.
    const int boxSize = 15; // mit der Schrift mitgewachsen (12 * 12/9,5)
    const int boxRight = 1856;
    const int textRight = boxRight - boxSize - 8;
    const int textX = textRight - epaper.textWidth(text);

    epaper.setCursor(textX, STATUS_BASELINE);
    epaper.print(text);

    const int boxX = boxRight - boxSize, boxY = STATUS_BASELINE - boxSize;
    if (lastFrameWasFull) {
        epaper.fillRect(boxX, boxY, boxSize, boxSize, TFT_BLACK);
    } else {
        epaper.drawRect(boxX, boxY, boxSize, boxSize, TFT_BLACK);
    }

    epaper.updataPartial(MARKER_X, STATUS_Y, MARKER_W, STATUS_H);
}
