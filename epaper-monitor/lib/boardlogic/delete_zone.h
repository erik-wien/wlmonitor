#pragma once
#include <cstddef>

// Loesch-X der MQTT-Nachrichtenkarten (TASK-28).
//
// Anders als Favoritenleiste und Paginierungspille lassen sich diese Zonen
// NICHT aus einer Formel nachrechnen: die Kartenpositionen entstehen erst beim
// Masonry-Umbruch, haengen also vom Inhalt ab. Der Server liefert sie deshalb
// im Header X-Board-Delete-Zones mit (board_mqtt_delete_zones_header() in
// inc/board_mqtt.php):
//
//     <id>:<x>,<y>,<w>,<h>;<id>:<x>,<y>,<w>,<h>
//
// Kein JSON, weil die Firmware keinen JSON-Parser hat und fuer diese eine
// Zeile auch keinen bekommen soll.

// 20 = BOARD_MQTT_MAX_MESSAGES serverseitig; mehr Karten kann eine Seite gar
// nicht tragen. Ueberzaehlige werden verworfen, nicht ueberlaufen.
constexpr int MAX_DELETE_ZONES = 20;
// board_mqtt_message_id() ist substr(sha1(...), 0, 8) -- 8 Zeichen. Etwas
// Luft fuer den Fall, dass die Kennung dort je waechst; laengere IDs werden
// verworfen statt abgeschnitten (eine gekuerzte ID loeschte sonst entweder
// nichts oder, schlimmer, die falsche Nachricht).
constexpr int MAX_DELETE_ID_LEN = 16;

struct DeleteZone {
    int x = 0, y = 0, w = 0, h = 0;
    char id[MAX_DELETE_ID_LEN + 1] = {0};
};

// Zerlegt den Header-Wert. Gibt die Zahl der uebernommenen Zonen zurueck
// (<= MAX_DELETE_ZONES, <= capacity). Fehlerhafte Einzeleintraege werden
// uebersprungen, nicht der ganze Rest verworfen -- ein einzelner Tippfehler
// im Header soll nicht saemtliche Loesch-X unbrauchbar machen.
// header == nullptr oder leer -> 0.
int parseDeleteZones(const char* header, DeleteZone* out, int capacity);

// Index der Zone, die (x, y) enthaelt, sonst -1. Erste Uebereinstimmung
// gewinnt; die Zonen ueberlappen sich bauartbedingt nicht.
int findDeleteZone(const DeleteZone* zones, int count, int x, int y);
