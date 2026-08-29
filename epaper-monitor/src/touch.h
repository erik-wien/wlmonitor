#pragma once
#include "touch_zone.h"

// Initialisiert den GT911-Touch-Controller (I2C, Reset ueber GPIO48).
// Gibt false zurueck, wenn kein Controller an einer der beiden bekannten
// Adressen antwortet -- das ist NICHT fatal, die Firmware laeuft ohne
// Touch weiter (physische Tasten bleiben als Fallback, s. buttons.h).
bool initTouch();

// Fragt einen aktuell anliegenden Touch-Punkt ab (nicht blockierend -- gibt
// sofort ein TouchResult mit zone==TouchZone::None zurueck, wenn gerade
// nichts beruehrt wird) und
// bildet ihn ueber favoriteCount/totalPages (aus der letzten Antwort) auf
// eine Zone ab. outRawX/outRawY (optional): liefern die zuletzt gelesenen,
// auf Panel-Ausrichtung gemappten Koordinaten -- fuer Debug-Anzeige
// (Nutzerwunsch 2026-08-21), unveraendert wenn kein Touch anliegt.
TouchResult pollTouch(int favoriteCount, int totalPages, int* outRawX = nullptr, int* outRawY = nullptr);

// Quittiert einen anstehenden GT911-Interrupt, damit GPIO2 vor dem
// Tiefschlaf nicht LOW haengt (sonst weckt ext1 sofort wieder).
void touchClearInterrupt();
