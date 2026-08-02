#pragma once

// Kopieren nach config.h (bleibt lokal, siehe .gitignore) und echte Werte
// eintragen. main.cpp bindet config.h per #include "config.h" ein.

#define WIFI_SSID     "dein-wlan"
#define WIFI_PASSWORD "dein-wlan-passwort"

// LAN-Listener auf akadbrain, gibt ausschliesslich /board.php frei (siehe
// wlmonitor/docs/deploy-board-endpunkt.md). Klartext-HTTP, keine TLS-Kette
// noetig -- das Geraet steht ausschliesslich zu Hause.
#define BOARD_HOST "10.10.10.18"
#define BOARD_PORT 8090

// profil.php -> Abschnitt "API-Token", auf akadbrain ausgestellt. NIE ein
// Token wiederverwenden, das schon einmal im Klartext (Chat, Ticket,
// Repository) aufgetaucht ist -- es gilt als kompromittiert.
#define BOARD_TOKEN "<token einsetzen>"

// Favoriten-IDs aus der URL von editFavorite.php?id=... Reihenfolge =
// Spaltenreihenfolge: erste ID linke Spalte, zweite ID rechte Spalte.
#define BOARD_FAV_IDS "219,218"

#define POLL_INTERVAL_SEC 120
