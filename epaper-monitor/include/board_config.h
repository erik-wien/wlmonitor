#pragma once

// Nicht geheime Infrastruktur-Konstanten, fuer jedes Geraet gleich --
// bewusst COMMITTED (anders als die alte gitignorete config.h). WLAN und
// API-Token sind KEINE Compile-Zeit-Konstanten mehr, sondern werden per
// WiFiManager-Captive-Portal eingegeben und in NVS-Flash persistiert
// (Preferences-Bibliothek, s. main.cpp) -- damit gibt es ueberhaupt keine
// Geheimnisse mehr, die versehentlich in dieses Repo committed werden
// koennten (Spec §10, Vorfall vom 2026-08-03 mit der alten config.h).

#define BOARD_HOST "wlmonitor.eriks.cloud"
#define BOARD_PORT 443

// Optionaler Compile-Zeit-Token aus include/board_secrets.h (gitignored, s.
// board_secrets.example.h) -- Nutzerwunsch 2026-08-25: Token soll beim
// Flashen automatisch gesetzt werden statt jedes Mal per WiFiManager-Portal
// eingetippt zu werden. Fehlt die Datei (frisches Checkout), bleibt
// BOARD_API_TOKEN leer und provisionAndConnect() ueberschreibt den
// gespeicherten Token dann NICHT -- das Portal bleibt die einzige Quelle,
// wie bisher.
#if __has_include("board_secrets.h")
#include "board_secrets.h"
#else
#define BOARD_API_TOKEN ""
#endif
// Nur noch der FALLBACK, wenn goToSleep() die lokale Uhrzeit nicht kennt
// (WLAN/NTP fehlgeschlagen) -- der normale Zeitplan (1x/Stunde ab 06:00,
// Nacht still) steckt seit 2026-08-23 in wake_schedule.h/.cpp
// (secondsUntilNextAutomaticWake(), Nutzervorgabe: "Automatisches kann auf
// 1x pro Stunde ab 06:00 Uhr zurueckgefahren werden"). Ohne bekannte Uhrzeit
// waere jede Zeitplan-Berechnung Zufall -- lieber alle 2 Minuten einen neuen
// Verbindungsversuch, damit ein voruebergehender WLAN-Ausfall das Geraet
// nicht fuer den Rest der Nacht verstummen laesst.
#define NETWORK_RETRY_INTERVAL_SEC 120

// Manuell erhoeht, einmal pro Session mit sichtbaren Firmware-Aenderungen
// (analog APP_BUILD in inc/initialize.php) -- wird neben "Stand HH:MM" aufs
// Panel gezeichnet, damit am echten Geraet sichtbar ist, ob wirklich der
// aktuell geflashte Code laeuft (2026-08-21: Verwirrung durch e-Paper-
// Ghosting/unsauberes Partial-Update machte das sonst unmoeglich zu sagen).
#define FIRMWARE_BUILD 61
