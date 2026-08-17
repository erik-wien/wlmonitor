#include "display.h"
#include <it8951/GxEPD2_it103_1872x1404.h>
#include <GxEPD2_BW.h>
#include <Fonts/FreeSansBold9pt7b.h>

// Setup522_Seeed_reTerminal_E1003 (IT8951-Controller, 1872x1404) -- Panel-/
// SPI-Pins aus den Global Constraints (Seeed-Wiki, unverifiziert ohne Hardware).
//
// KORREKTUR ggue. commit 137f563: die dort verwendete Klasse
// GxEPD2_ED103TC2_1872x1404 existiert NICHT in der tatsaechlich installierten
// GxEPD2@1.5.3 (lib_deps in platformio.ini). Durch Lesen des echten
// Bibliotheks-Quellcodes bestaetigt (.pio/libdeps/esp32dev/GxEPD2/src/it8951/
// GxEPD2_it103_1872x1404.h/.cpp): die reale Klasse fuer ein 1872x1404-Panel am
// IT8951-Controller heisst GxEPD2_it103_1872x1404 (Kommentar im Header:
// "ES103TC1 10.3\" e-paper panel" -- der Panelname weicht von der Spec
// ("ED103TC2") geringfuegig ab, aber Controller (IT8951) + Aufloesung
// (1872x1404) + Groesse (10.3") passen; naeher kommt man ohne Hardware nicht
// heran, siehe Task-Report).
#define EPD_SCK_PIN     7
#define EPD_MISO_PIN    8
#define EPD_MOSI_PIN    9
#define EPD_CS_PIN      10
#define EPD_RES_PIN     12
#define EPD_BUSY_PIN    13
#define EPD_TFT_ENABLE  11
#define EPD_ITE_ENABLE  21

// *** UNGEKLAERTE LUECKE: DC-Pin -- vor dem ersten Flash pruefen! ***
// GxEPD2_EPD::GxEPD2_EPD(cs, dc, rst, busy, ...) verlangt einen DC-Pin als
// 2. Konstruktorargument. Die Seeed-Wiki-Pinliste (Quelle der Konstanten oben)
// fuehrt aber KEINEN DC-Pin fuer die E1003-ePaper-Schnittstelle auf.
//
// -1 ist GxEPD2s eigene, in der Bibliothek durchgaengig verwendete Konvention
// fuer "Pin nicht vorhanden/nicht verdrahtet" (siehe GxEPD2_EPD.cpp: cs/dc/
// rst/busy werden jeweils nur mit "if (_pin >= 0) { pinMode(...); ... }"
// angefasst -- bei -1 passiert schlicht nichts). Stuetzender Befund aus dem
// Lesen von GxEPD2_it103_1872x1404.cpp: die Membervariable _dc wird dort in
// KEINER einzigen Methode referenziert -- das IT8951-Protokoll unterscheidet
// Kommando/Daten ueber 16-Bit-Praeambel-Worte im SPI-Datenstrom selbst
// (0x6000 fuer Kommando, 0x0000/0x1000 fuer Daten, siehe _writeCommand16/
// _writeData16/_readData16), nicht ueber eine eigene DC-Leitung. Das ist ein
// starkes Indiz, dass -1 hier nicht nur ein sicherer Platzhalter, sondern der
// tatsaechlich korrekte Wert ist -- passend dazu, dass die Seeed-Pinliste
// keinen DC-Pin kennt.
//
// TROTZDEM NICHT ungeprueft fuer bare Muenze nehmen: vor dem ersten Flash
// gegen ein echtes E1003-Schaltplan/Seeeds eigene Beispiel-Firmware
// gegenchecken. Sollte sich doch ein realer DC-Pin finden, hier UND im
// Konstruktoraufruf unten aendern.
#define EPD_DC_PIN      -1  // UNBEKANNT/UNBESTAETIGT -- s. Kommentar oben

// WLAN-Balken-Rechteck der Kopfzeile (Spec §9: translate(1665,46), 3 Balken).
static const int WIFI_ICON_X = 1665;
static const int WIFI_ICON_Y = 46;
static const int WIFI_ICON_W = 54;
static const int WIFI_ICON_H = 28;

// Seitenhoehe fuer den GxEPD2_BW-Pufferpfad unten (nur fuer Text). 128 Zeilen
// reichen locker fuer beide Rechtecke (Banner 70px, Sleep-Icon 28px hoch) und
// halten den RAM-Puffer klein: (1872/8)*128 Byte = 29952 Byte statt >300 KB
// bei voller Panelhoehe.
static const uint16_t TEXT_PAGE_HEIGHT = 128;

// GxEPD2_GFX (im Task-Auftrag als Wrapper-Klasse genannt) ist in der
// installierten GxEPD2@1.5.3 eine rein abstrakte Schnittstelle (nur "= 0"
// virtuelle Methoden, siehe GxEPD2_GFX.h) -- sie laesst sich nicht direkt
// instanziieren. Es gibt in dieser Bibliotheksversion auch keine fertige
// konkrete GxEPD2_GFX-Unterklasse fuer IT8951-Panels: GxEPD2_BW/_3C/_4C/_7C
// binden per __has_include nur Klassen aus den Ordnern epd/ und gdey/ ein,
// nicht it8951/. Der reale, kompilierbare Ersatz mit identischer Text-API
// (fillScreen/setFont/setCursor/print/setPartialWindow/firstPage/nextPage)
// ist das GxEPD2_BW<...>-Template selbst (Adafruit_GFX als Basisklasse) --
// GxEPD2_it103_1872x1404 implementiert alle dafuer noetigen virtuellen
// GxEPD2_EPD-Methoden (clearScreen/writeScreenBuffer/writeImage/
// writeImagePart), das Template bindet rein strukturell (kein Bezug zur
// epd/-Vorauswahl). Das ist exakt das gleiche Muster wie im alten (falschen)
// 137f563-Code, nur jetzt mit der echten Panelklasse.
//
// Es existiert genau EIN Panel-Objekt (epd.epd2) -- fuer den reinen
// Pixel-Blit (applyFullFrame/applyPatch) wird direkt darauf zugegriffen,
// ganz ohne die GFX-Zwischenschicht (die Protokoll-Bytes werden 1:1
// durchgereicht). Fuer die zwei Text-Funktionen wird die GFX-Huelle (epd
// selbst) verwendet. Ein zweites, eigenstaendiges Panel-Objekt zu erzeugen
// waere falsch, weil dann zwei getrennte GxEPD2_EPD-Instanzen (mit jeweils
// eigenem _initial_write/_initial_refresh/_hibernating-Zustand) um dieselbe
// Hardware konkurrieren wuerden.
static GxEPD2_BW<GxEPD2_it103_1872x1404, TEXT_PAGE_HEIGHT> epd(
    GxEPD2_it103_1872x1404(EPD_CS_PIN, EPD_DC_PIN, EPD_RES_PIN, EPD_BUSY_PIN));

void initDisplay() {
    pinMode(EPD_TFT_ENABLE, OUTPUT);
    digitalWrite(EPD_TFT_ENABLE, HIGH);
    pinMode(EPD_ITE_ENABLE, OUTPUT);
    digitalWrite(EPD_ITE_ENABLE, HIGH);

    // GxEPD2_it103_1872x1404 spricht das SPI-Peripheriegeraet ausschliesslich
    // ueber das globale `SPI`-Objekt an (SPI.beginTransaction/.transfer/
    // .endTransaction direkt in praktisch jeder Methode in
    // GxEPD2_it103_1872x1404.cpp) -- GxEPD2_EPD::selectSPI()/_pSPIx wird von
    // dieser Treiberklasse NIE gelesen (anders als bei den meisten anderen
    // GxEPD2-Panelklassen). Ein separates SPIClass-Objekt + selectSPI(), wie
    // es der alte 137f563-Code verwendet hat, waere hier also wirkungslos
    // gewesen. Stattdessen muessen die E1003-eigenen SPI-Pins direkt auf dem
    // globalen SPI-Objekt gebunden werden, bevor epd.init() laeuft (das ruft
    // intern nur noch SPI.begin() ohne Parameter -- auf dem ESP32 ein No-Op,
    // wenn der Bus schon mit expliziten Pins gestartet wurde). ss=-1, weil
    // GxEPD2 den CS-Pin selbst per digitalWrite() manuell steuert.
    SPI.begin(EPD_SCK_PIN, EPD_MISO_PIN, EPD_MOSI_PIN, -1);

    epd.init(0, true, 20, false);
    // Kein setRotation() noetig: GxEPD2_it103_1872x1404 ist bereits nativ
    // WIDTH=1872 x HEIGHT=1404 (siehe Klassenheader) -- die alte, falsche
    // Panelklasse hatte offenbar vertauschte native Masse und brauchte
    // deshalb eine Drehung um 90 Grad.
}

void applyFullFrame(const uint8_t* packed, int w, int h) {
    // Direkter Pass-Through ueber das rohe Panel-Objekt (epd.epd2), keine
    // GFX-Zwischenschicht -- writeImage()/drawImage() erwarten exakt das
    // Protokollformat (1bpp MSB-first, Zeilenbreite auf 8 aufgerundet).
    //
    // invert-Wert per Quellcode-Analyse hergeleitet (nicht geraten): in
    // GxEPD2_it103_1872x1404::writeImage() gilt
    //   data = bitmap_byte; if (invert) data = ~data; _send8pixel(~data);
    // und in _send8pixel() wird pro gesetztem Bit (bit==1) ein 0x00-Byte
    // (schwarz) ans Panel gesendet, pro geloeschtem Bit (bit==0) ein
    // 0xFF-Byte (weiss). Bei invert=false ist das an _send8pixel
    // uebergebene Byte ~bitmap_byte, d.h. bitmap_byte-Bit 0 -> gesendet 1 ->
    // schwarz, bitmap_byte-Bit 1 -> gesendet 0 -> weiss. Das entspricht
    // exakt der Protokoll-Konvention (Spec §6, s. display.h): 1=weiss,
    // 0=schwarz. invert=false ist also nachweislich richtig, kein Ratewert.
    epd.epd2.drawImage(packed, 0, 0, w, h, /*invert=*/false);
}

void applyPatch(const uint8_t* packed, int x, int y, int w, int h) {
    // Gleiche Bit-Konvention/Herleitung wie applyFullFrame.
    epd.epd2.drawImage(packed, x, y, w, h, /*invert=*/false);
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
    epd.epd2.hibernate();
}
