#include "board_client.h"
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "board_config.h"
#include <Arduino.h>

namespace {

const char* HEADER_NAMES[] = {
    "X-Board-Mode", "X-Board-ETag", "X-Board-Generated",
    "X-Board-X", "X-Board-Y", "X-Board-W", "X-Board-H",
    "X-Board-Favorite-Count", "X-Board-Total-Pages", "Content-Length",
};
const size_t HEADER_COUNT = sizeof(HEADER_NAMES) / sizeof(HEADER_NAMES[0]);

} // namespace

void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, float tempC, float humidityPct,
                 uint32_t timeoutMs, BoardFetchResult& out) {
    out = BoardFetchResult{};

    WiFiClientSecure client;
    // arduino-esp32 buendelt seit Core 2.x ein Mozilla-Root-CA-Bundle
    // (CONFIG_MBEDTLS_CERTIFICATE_BUNDLE, standardmaessig aktiv) --
    // WiFiClientSecure::setCACertBundle() bindet es ein. UNVERIFIZIERT ohne
    // installierte Toolchain: das exakte Symbol/die Methode kann sich
    // zwischen Core-Versionen unterscheiden (manche brauchen diesen Aufruf
    // gar nicht, wenn das Bundle bereits global aktiv ist) -- beim ersten
    // echten Build pruefen und ggf. korrigieren. NIEMALS durch
    // client.setInsecure() ersetzen (der Bearer-Token liefe sonst
    // ungeprueft ueber eine potenziell falsche Gegenstelle -- Grund, warum
    // dieser Plan ueberhaupt HTTPS statt Klartext-HTTP gewaehlt hat).
    extern const uint8_t rootca_crt_bundle_start[] asm("_binary_x509_crt_bundle_start");
    client.setCACertBundle(rootca_crt_bundle_start);
    HTTPClient http;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);
    http.collectHeaders(HEADER_NAMES, HEADER_COUNT);

    // Getrennte Zeitmessung (Nutzerwunsch 2026-08-21): TCP-Connect+TLS-Handshake
    // manuell VOR http.begin() ausfuehren. HTTPClient::GET() erkennt einen
    // schon verbundenen Client und ueberspringt seinen eigenen Connect-Schritt
    // -- damit misst die bestehende Zeitmessung um http.GET() danach nur noch
    // Request+Response, nicht mehr Connect+TLS+Request+Response in einem Topf.
    uint32_t tConnect = millis();
    bool connected = client.connect(BOARD_HOST, BOARD_PORT);
    Serial.printf("[fetch] TCP+TLS connect: %s nach %lums\n",
                  connected ? "ok" : "FEHLGESCHLAGEN", (unsigned long) (millis() - tConnect));
    if (!connected) {
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    String url = String("https://") + BOARD_HOST + ":" + BOARD_PORT + "/board.php";
    if (!http.begin(client, url)) {
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    http.addHeader("Authorization", String("Bearer ") + token);
    http.addHeader("X-Device-Battery-mV", String(batteryMv));
    http.addHeader("X-Device-RSSI", String(rssi));
    if (touchValue != nullptr) {
        http.addHeader("X-Device-Touch", touchValue);
    }
    if (!isnan(tempC) && !isnan(humidityPct)) {
        http.addHeader("X-Device-Temp-C", String(tempC, 1));
        http.addHeader("X-Device-Humidity-Pct", String((int) roundf(humidityPct)));
    }
    if (lastEtag != nullptr && lastEtag[0] != '\0') {
        http.addHeader("If-None-Match", lastEtag);
    }

    // Diagnose (2026-08-21): nach fw11 erreichten die Abrufe den Server nicht
    // mehr. status <= 0 ist ein HTTPClient-Fehlercode (negativ), sonst der
    // HTTP-Status -- ohne Ausgabe ist beides nicht unterscheidbar.
    uint32_t t0 = millis();
    int status = http.GET();
    Serial.printf("[fetch] GET -> %d nach %lums, heap=%lu psram=%lu\n",
                  status, (unsigned long)(millis() - t0),
                  (unsigned long) ESP.getFreeHeap(), (unsigned long) ESP.getFreePsram());
    if (status <= 0) {
        Serial.printf("[fetch] Fehler: %s\n", http.errorToString(status).c_str());
        http.end();
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }
    if (status == 401) {
        http.end();
        out.outcome = BoardFetchOutcome::Unauthorized;
        return;
    }
    if (status != 200) {
        // 503 und alles sonstige Unerwartete: wie ein Verbindungsausfall
        // behandeln (Spec §11 "wie WLAN-Ausfall").
        http.end();
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    BoardHeaders headers;
    headers.mode           = http.header("X-Board-Mode").c_str();
    headers.etag            = http.header("X-Board-ETag").c_str();
    headers.generated       = http.header("X-Board-Generated").c_str();
    headers.x               = http.header("X-Board-X").c_str();
    headers.y               = http.header("X-Board-Y").c_str();
    headers.w               = http.header("X-Board-W").c_str();
    headers.h               = http.header("X-Board-H").c_str();
    headers.favoriteCount    = http.header("X-Board-Favorite-Count").c_str();
    headers.totalPages       = http.header("X-Board-Total-Pages").c_str();
    headers.contentLength    = http.header("Content-Length").c_str();

    WiFiClient* stream = http.getStreamPtr();
    int contentLength = http.getSize(); // -1, falls unbekannt -- validateBoardResponse() prueft ohnehin gegen den echten gelesenen Byte-Count
    out.body.reserve(contentLength > 0 ? (size_t) contentLength : 4096);

    // Eigene millis()-Deadline statt sich allein auf http.setTimeout() zu
    // verlassen: ob der Kern-Timeout wirklich diese Lese-Schleife abbricht
    // (statt nur einen einzelnen blockierenden read()-Aufruf) ist
    // core-versionsabhaengig und ohne Hardware nicht verifizierbar (Review-
    // Befund Task 4). Ohne diese Deadline koennte ein Peer, der die
    // Verbindung offen haelt aber nichts mehr sendet (v.a. bei fehlendem
    // Content-Length, contentLength == -1), die Schleife auf einem
    // batteriebetriebenen Geraet unbegrenzt blockieren.
    uint8_t buf[512];
    uint32_t readStart = millis();
    while (http.connected() && (contentLength > 0 || contentLength == -1)) {
        if (millis() - readStart > timeoutMs) {
            break; // unvollstaendiger Body -> validateBoardResponse() erkennt die falsche Laenge
        }
        size_t avail = stream->available();
        if (avail == 0) {
            if (!stream->connected()) break;
            delay(1);
            continue;
        }
        size_t toRead = avail > sizeof(buf) ? sizeof(buf) : avail;
        int got = stream->readBytes(buf, toRead);
        if (got <= 0) break;
        out.body.insert(out.body.end(), buf, buf + got);
        if (contentLength > 0) contentLength -= got;
    }
    http.end();

    out.parsed = validateBoardResponse(headers, out.body.size());
    if (out.parsed.status == BoardResponseStatus::Ok) {
        out.outcome = BoardFetchOutcome::Success;
    } else {
        out.outcome = BoardFetchOutcome::UnreadableResponse;
    }
}
