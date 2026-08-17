#pragma once
#include <cstdint>
#include "error_state.h"

void initDisplay();

// Vollbild: packed ist 1bpp MSB-first, Zeilenbreite auf Vielfaches von 8
// aufgerundet, 1=weiss/0=schwarz (Spec §6), w/h immer 1872x1404 bei Vollbild.
void applyFullFrame(const uint8_t* packed, int w, int h);

// Patch: packed deckt NUR das Rechteck (x,y,w,h) ab, gleiche Bit-Konvention.
void applyPatch(const uint8_t* packed, int x, int y, int w, int h);

// Lokaler Fallback-Banner (Spec §11) -- ueberlagert die Kopfzeile, kein
// eigenes Bitmap-Font-Asset, nutzt GxEPD2s eingebaute Standardschrift.
// sinceTime: "HH:MM"-String fuer den Offline-Banner, ignoriert beim
// TokenInvalid-Banner (kein Zeitstempel im Text, Spec §11 "Token ungueltig").
void showErrorBanner(ErrorBanner banner, const char* sinceTime);

// Ueberschreibt das WLAN-Balken-Rechteck der Kopfzeile mit einem "zzz"
// (Spec §9/§10/§11) -- wird unmittelbar vor esp_deep_sleep_start() gerufen.
void markSleepIcon();

// Versetzt das Panel in den stromsparenden Ruhezustand (unabhaengig vom
// ESP32-Tiefschlaf -- das Panel selbst hat einen eigenen deep-power-down).
void sleepPanel();
