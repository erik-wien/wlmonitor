#pragma once
#include <cstdint>
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
};

// Fuehrt GET https://BOARD_HOST:BOARD_PORT/board.php aus (board_config.h),
// mit Authorization: Bearer <token>, X-Device-Battery-mV, X-Device-RSSI,
// optional X-Device-Touch (touchValue == nullptr -> Header weggelassen),
// optional X-Device-Temp-C/X-Device-Humidity-Pct (tempC != tempC, d.h. NAN
// -> beide Header weggelassen) und optional If-None-Match (lastEtag ==
// nullptr oder leer -> weggelassen, Spec §5). timeoutMs begrenzt
// Verbindungsaufbau UND Antwortwartezeit.
void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, float tempC, float humidityPct,
                 uint32_t timeoutMs, BoardFetchResult& out);
