#include "board_client.h"
#include <WiFiClient.h>
#include <HTTPClient.h>

BoardFetchResult fetchBoard(const char* host, uint16_t port, const char* favIds,
                             const char* token, uint32_t timeoutMs, std::string& outBody) {
    WiFiClient client;
    HTTPClient http;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);

    String url = String("http://") + host + ":" + port + "/board.php?fav=" + favIds;
    if (!http.begin(client, url)) {
        return BoardFetchResult::Unavailable;
    }
    http.addHeader("Authorization", String("Bearer ") + token);

    int status = http.GET();
    if (status <= 0) {
        // Negative HTTPClient-Codes: Verbindungsfehler oder Zeitueberschreitung.
        http.end();
        return BoardFetchResult::Unavailable;
    }

    outBody = http.getString().c_str();
    http.end();

    if (status == 200) return BoardFetchResult::Ok;
    if (status == 401) return BoardFetchResult::Unauthorized;
    if (status == 503) return BoardFetchResult::Unavailable;
    return BoardFetchResult::ServerError;
}
