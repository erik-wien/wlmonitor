#pragma once
#include <cstdint>
#include <cstddef>
#include <vector>
#include "board_response.h"

enum class BoardFetchOutcome {
    Success,
    NetworkUnavailable,  // TLS/connect failure, timeout, or HTTP 503
    Unauthorized,        // HTTP 401
    UnreadableResponse,  // headers missing/malformed or Content-Length mismatch
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
