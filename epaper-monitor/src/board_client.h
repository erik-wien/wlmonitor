#pragma once
#include <cstdint>
#include <cstddef>
#include <vector>
#include <string>
#include "board_response.h"

enum class BoardFetchOutcome {
    Success,
    NetworkUnavailable,  // TLS/connect failure, timeout, or HTTP 503
    Unauthorized,        // HTTP 401
    UnreadableResponse,  // headers missing/malformed or Content-Length mismatch
};

// Zeitverhalten, wie es der Server mitschickt (X-Board-*-Sec / -Hour, s.
// web/board.php). present == false heisst "diese Antwort trug keine Angabe"
// -- dann behaelt main.cpp seine bisherigen Werte, statt auf 0 zu springen.
// Getrennt gemeldet, weil quietEndHour == 0 (Mitternacht) ein gueltiger Wert
// ist und sich sonst nicht von "fehlt" unterscheiden liesse.
struct BoardTiming {
    bool present = false;
    uint32_t idleTimeoutSec = 0;
    uint32_t refreshIntervalSec = 0;
    uint32_t wakeIntervalSec = 0;
    int quietStartHour = 0;
    int quietEndHour = 0;
};

struct BoardFetchResult {
    BoardFetchOutcome outcome = BoardFetchOutcome::NetworkUnavailable;
    ParsedBoardResponse parsed;   // only meaningful when outcome == Success
    std::vector<uint8_t> body;    // raw packed pixel bytes, only when outcome == Success
    // X-Board-Snapshot-Requested response header, s. web/board_snapshot.php --
    // ausserhalb von ParsedBoardResponse, weil es kein Teil des strikt
    // getesteten Kern-Protokolls (board_response.cpp) ist, nur ein optionaler
    // Seitenkanal fuer den Debug-Screenshot-Upload.
    bool snapshotRequested = false;
    // X-Board-Is-Sleep-Page: der gerade ausgelieferte Frame IST der
    // Schlafschirm (board_sleep_render_svg()) -- egal ob per screen="sleep"
    // erzwungen oder weil der Nutzer bewusst dorthin geblaettert hat (er ist
    // seit 2026-08-23 strukturell immer die letzte Seite). Steuert in
    // main.cpp, ob die gruene Taste "Vollupdate" oder "jetzt schlafen"
    // bedeutet -- ebenfalls ausserhalb von ParsedBoardResponse, aus demselben
    // Grund wie snapshotRequested.
    bool isSleepPage = false;
    // Zeitverhalten vom Server (s. BoardTiming). Wie snapshotRequested und
    // isSleepPage ausserhalb von ParsedBoardResponse: kein Teil des strikt
    // getesteten Kern-Bildprotokolls, sondern ein Steuer-Seitenkanal.
    BoardTiming timing;
    // X-Board-Delete-Zones (TASK-28), roh: "<id>:<x>,<y>,<w>,<h>;..." fuer die
    // Loesch-X der Nachrichtenkarten. Nur auf der MQTT-Seite gesetzt, sonst
    // leer. Zerlegt wird in main.cpp (parseDeleteZones(), lib/boardlogic) --
    // hier bewusst nur durchgereicht, damit der HTTP-Client nichts ueber den
    // Inhalt wissen muss. Wie snapshotRequested/isSleepPage ausserhalb von
    // ParsedBoardResponse: kein Teil des Kern-Bildprotokolls.
    std::string deleteZones;
};

// Fuehrt GET https://BOARD_HOST:BOARD_PORT/board.php aus (board_config.h),
// mit Authorization: Bearer <token>, X-Device-Battery-mV, X-Device-RSSI,
// optional X-Device-Touch (touchValue == nullptr -> Header weggelassen)
// und optional If-None-Match (lastEtag == nullptr oder leer -> weggelassen,
// Spec §5). timeoutMs begrenzt Verbindungsaufbau UND Antwortwartezeit.
// screen: nullptr = regulaerer Abfahrtsmonitor, "sleep" = Schlafschirm
// (Wetter heute/morgen + Gaeste-WLAN-QR statt eingefrorener Abfahrtszeiten,
// s. inc/board_sleep.php). Das Geraet fordert ihn als letztes Bild vor dem
// Tiefschlaf an.
void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, uint32_t timeoutMs, BoardFetchResult& out,
                 const char* screen = nullptr);

// Laedt den aktuellen Panel-Puffer (s. getPanelBuffer()/getPanelBufferSize()
// in display.h) per POST zu web/board_snapshot.php hoch -- Antwort auf
// X-Board-Snapshot-Requested. Gibt true bei HTTP 200 zurueck, sonst false
// (Fehler wird nur geloggt, kein eigener Retry -- der Server haelt das
// Anfrage-Flag bis zum naechsten erfolgreichen Upload).
bool uploadSnapshot(const char* token, const uint8_t* buffer, size_t bufferSize, uint32_t timeoutMs);
