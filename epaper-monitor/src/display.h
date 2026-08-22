#pragma once
#include <cstdint>
#include <cstddef>
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

// Versetzt das Panel in Standby (unabhaengig vom ESP32-Tiefschlaf), via
// EPaper::sleep() (Seeed_GFX).
void sleepPanel();

// Zeichnet "fw<FIRMWARE_BUILD>" plus ein Kaestchen fuer die Art des letzten
// Panel-Schreibvorgangs (gefuellt = Vollbild, hohl = Patch) rechtsbuendig ans
// Ende der Statusleiste (Debug-Hilfe, s. FIRMWARE_BUILD in board_config.h) --
// damit am Geraet sichtbar ist, welcher Firmware-Stand laeuft und ob das
// zuletzt gezeigte Bild ein Vollbild war, unabhaengig vom Server-Frame-Inhalt.
void showBuildMarker(int build, bool lastFrameWasFull);

// Setzt die Panel-Temperatur, die Seeed_GFX bei jedem wake() an den IT8951
// schickt (Standard sonst fest 16 C). celsius = NAN laesst den bisherigen
// Wert stehen. Erst nach initTouch() aufrufen -- der Sensor haengt am
// selben I2C-Bus. Siehe sensor.h und docs/hardware/reterminal-e1003.md §5.5.
void applyPanelTemperature(float celsius);

// Zeichnet einen fetten Punkt an der erkannten Touch-Position -- sofortiges
// visuelles Feedback zur Kalibrierung (Nutzerwunsch 2026-08-21).
void drawTouchBlob(int x, int y);

// Piktogramme der Statusleiste. Aus GFX-Grundformen gezeichnet statt aus
// Bitmap-Assets -- der Server muss dieselben Formen als SVG nachbauen
// koennen (board_render_status_icon_svg() in inc/board_template.php), damit
// ein lokal uebermaltes Statusfeld nicht vom Server-Frame abweicht.
enum class StatusIcon {
    Ready,    // Ring mit Punkt -- wartet auf Eingabe
    Loading,  // Pfeil auf eine Grundlinie -- Daten werden geholt
    Full,     // Rahmen mit gefuelltem Kern -- Vollbild wird geschrieben
    Sleep,    // Mondsichel -- Geraet legt sich schlafen
};

// Ueberschreibt die Statusleiste am unteren Rand der Wetterkarte
// (x=1150..1600, y=1256..1306, deckungsgleich mit BOARD_STATUS_*-Konstanten
// in board_template.php) mit einem lokal bekannten Zustand -- fuer
// "Lade …"/"Vollbild"/"Schlaf", die der Server nicht rendern kann (sie
// gelten waehrend/vor einem Request, nicht als dessen Ergebnis). "Bereit"
// muss NICHT lokal gezeichnet werden, solange gerade ein Vollbild ankam --
// das ist bereits der Server-Standardtext in jedem regulaeren Frame.
void showStatus(StatusIcon icon, const char* text);

// Rohzugriff auf den internen 1bpp-Panel-Puffer (Geraete-Screenshot-Upload,
// s. board_snapshot.php). Gleiche Packung wie board.phps Vollbild-Antworten.
const uint8_t* getPanelBuffer();
size_t getPanelBufferSize();
