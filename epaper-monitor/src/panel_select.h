#pragma once
// Waveshare 7.5" e-Paper HAT (B), 800x480, schwarz/weiss/rot.
//
// WICHTIG: Es gibt mehrere GxEPD2-Treiberklassen fuer aehnlich benannte
// Waveshare-7.5"-B-Panels (unterschiedliche Controller-Revisionen). Vor dem
// ersten Flashen gegen die Waveshare-Wiki-Seite des eigenen Moduls bzw. die
// Beschriftung auf der Ruecktseite des Panels pruefen und bei Abweichung
// hier tauschen (siehe GxEPD2-Bibliothek, Ordner src/epd3c/ und src/gdey3c/,
// Dateien GxEPD2_750c_*.h fuer die unterstuetzten Panel-Revisionen).
#include <epd3c/GxEPD2_750c_Z08.h>
#define PANEL_DRIVER GxEPD2_750c_Z08

// Treiberboard-Pins (Waveshare e-Paper ESP32 Driver Board, fest verdrahtet).
// SCLK 13 / DIN 14 laufen ueber das Standard-SPI (VSPI) des ESP32.
#define EPD_CS    15
#define EPD_DC    27
#define EPD_RST   26
#define EPD_BUSY  25
