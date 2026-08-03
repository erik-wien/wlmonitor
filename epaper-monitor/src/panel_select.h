#pragma once
// Waveshare 7.5" e-Paper HAT (B), 800x480, schwarz/weiss/rot.
//
// Aufkleber auf der Ruecktseite: SKU 13380, "7.5inch e-Paper (B)", Panel
// selbst zusaetzlich mit "V3" markiert. Laut Waveshare ist V3 hardware-/
// schnittstellenkompatibel zu V2 (800x480). GxEPD2_750c_Z08 (GD7965-
// Controller, aeltere GDEW075Z08-Panelgeneration) fuehrte auf diesem Panel
// zu einem Busy-Timeout beim Power-On (2026-08-03, real getestet) -- V2/V3-
// Stock verwendet den neueren UC8179-Controller (GDEY075Z08-Panelgeneration).
#include <gdey3c/GxEPD2_750c_GDEY075Z08.h>
#define PANEL_DRIVER GxEPD2_750c_GDEY075Z08

// Treiberboard-Pins (Waveshare e-Paper ESP32 Driver Board, fest verdrahtet).
// SCLK 13 / DIN 14 laufen ueber das Standard-SPI (VSPI) des ESP32.
#define EPD_CS    15
#define EPD_DC    27
#define EPD_RST   26
#define EPD_BUSY  25
