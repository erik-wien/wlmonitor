#pragma once
#include <string>
#include <cstdint>

enum class BoardFetchResult {
    Ok,                 // HTTP 200, Koerper in outBody
    Unauthorized,       // HTTP 401
    Unavailable,        // HTTP 503, Verbindungsfehler oder Zeitueberschreitung
    ServerError,        // HTTP 500 oder sonstiger unerwarteter Status
};

// Fuehrt GET http://host:port/board.php?fav=favIds aus, mit
// "Authorization: Bearer <token>". timeoutMs begrenzt Verbindungsaufbau UND
// Antwortwartezeit.
BoardFetchResult fetchBoard(const char* host, uint16_t port, const char* favIds,
                             const char* token, uint32_t timeoutMs, std::string& outBody);
