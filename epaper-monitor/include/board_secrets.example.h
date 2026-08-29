#pragma once

// Vorlage fuer include/board_secrets.h (gitignored, s. .gitignore) --
// kopieren, echten Token aus profil.php eintragen. Ohne diese Datei baut die
// Firmware trotzdem (BOARD_API_TOKEN faellt auf "" zurueck, s. board_config.h),
// dann bleibt das WiFiManager-Portal die einzige Quelle wie bisher.
//
// Zweck: Nutzerwunsch 2026-08-25 ("Mir wird das jetzt zu dumm") -- der Token
// soll beim Flashen automatisch gesetzt werden statt bei jedem Setup erneut
// ins Captive-Portal getippt zu werden. Bleibt trotzdem aus Git heraus (Spec
// §10, Vorfall vom 2026-08-03 mit der alten config.h) -- nur lokal auf dem
// Rechner, der flasht.
#define BOARD_API_TOKEN "eintragen-aus-profil.php-tokens-verwalten"
