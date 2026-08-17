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
#define POLL_INTERVAL_SEC 120
