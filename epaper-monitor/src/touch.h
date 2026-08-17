#pragma once
#include "touch_zone.h"

// Initialisiert den GT911-Touch-Controller (I2C, Reset ueber GPIO48).
// Gibt false zurueck, wenn kein Controller an einer der beiden bekannten
// Adressen antwortet -- das ist NICHT fatal, die Firmware laeuft ohne
// Touch weiter (physische Tasten bleiben als Fallback, s. buttons.h).
bool initTouch();

// Fragt einen aktuell anliegenden Touch-Punkt ab (nicht blockierend -- gibt
// sofort TouchZone::None zurueck, wenn gerade nichts beruehrt wird) und
// bildet ihn ueber favoriteCount/totalPages (aus der letzten Antwort) auf
// eine Zone ab.
TouchZone pollTouch(int favoriteCount, int totalPages);
