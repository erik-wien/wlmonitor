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
    "X-Board-Snapshot-Requested",
};
const size_t HEADER_COUNT = sizeof(HEADER_NAMES) / sizeof(HEADER_NAMES[0]);

// Dateistatischer TLS-Client, ueberlebt mehrere fetchBoard()-Aufrufe (Nutzerbefund
// 2026-08-22): am echten Geraet gemessen kostet ein kompletter TCP+TLS-Handshake
// 1583-1590ms von insgesamt ~3900ms Fetchzeit -- bei 25s-Refresh in einer
// 5-Minuten-Aktiv-Session sind das ~12 unnoetige volle Handshakes. Deep Sleep
// startet den Chip ohnehin komplett neu (RAM inkl. dieser Variable weg), daher
// muss hier NICHT im RTC-Speicher gehalten werden -- reiner Prozesslaufzeit-Cache.
WiFiClientSecure persistentClient;

// MUSS ebenfalls die Funktion ueberleben: ~HTTPClient() ruft BEDINGUNGSLOS
// _client->stop() -- ohne Ruecksicht auf _reuse/_canReuse (gegen den
// Core-Quellcode geprueft, HTTPClient.cpp ~Z.107). Als lokale Variable haette
// der Destruktor also bei jeder Rueckkehr aus fetchBoard() den Socket von
// persistentClient abgerissen und setReuse(true) damit komplett wirkungslos
// gemacht -- am Geraet als konstante 1590ms Handshake-Zeit pro Fetch gemessen,
// obwohl der Server sauber "Connection: keep-alive" liefert (Review-Befund
// 2026-08-22). Als Dateistatische laeuft der Destruktor nie.
// Mehrfaches begin()/collectHeaders() auf demselben Objekt ist unkritisch:
// begin() setzt nur _client+URL neu, _canReuse wird pro Antwort neu gesetzt,
// collectHeaders() gibt das alte Array vorher frei (alles ebenda geprueft).
HTTPClient persistentHttp;

// Wird gesetzt, wenn ein Antwortkoerper NICHT vollstaendig gelesen wurde
// (Timeout, Abbruch mitten im Body). Kritisch erst seit der Wiederverwendung
// oben: HTTPClient::disconnect() ruft zwar flush(), das leert aber NUR den
// lokalen RX-Puffer (WiFiClient::flush -> _rxBuffer->flush(), gegen den
// Core-Quellcode geprueft) -- Bytes, die noch unterwegs sind, treffen danach
// weiter ein. Der naechste Abruf wuerde sie als HTTP-Header seiner eigenen
// Antwort lesen und Muell bekommen. Frueher war das harmlos (Socket wurde
// ohnehin verworfen), jetzt wuerde es die ganze Session verwursten. Also:
// nach unvollstaendigem Body IMMER frisch verbinden (Review-Befund 2026-08-22).
bool connectionDirty = false;

// Stellt sicher, dass persistentClient verbunden ist. Baut bei Bedarf frisch auf
// (mit Zeitmessung) oder erkennt eine noch offene Verbindung und meldet das
// ehrlich als "wiederverwendet" statt eine erfundene 0ms-Verbindungszeit zu
// loggen. force=true erzwingt einen Neuaufbau, unabhaengig vom aktuellen
// connected()-Status (Retry-Pfad, falls die Gegenstelle die wiederverwendete
// Verbindung inzwischen zugemacht hat -- Robustheit vor Geschwindigkeitsgewinn,
// Nutzervorgabe 2026-08-22).
bool ensureConnected(bool force) {
    if (connectionDirty) {
        force = true;
        connectionDirty = false;
    }
    if (!force && persistentClient.connected()) {
        Serial.println("[fetch] TCP+TLS connect: wiederverwendet (bestehende Verbindung)");
        return true;
    }
    if (force) {
        persistentClient.stop();
    }
    uint32_t tConnect = millis();
    bool connected = persistentClient.connect(BOARD_HOST, BOARD_PORT);
    Serial.printf("[fetch] TCP+TLS connect: %s nach %lums\n",
                  connected ? "ok" : "FEHLGESCHLAGEN", (unsigned long) (millis() - tConnect));
    return connected;
}

} // namespace

void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, uint32_t timeoutMs, BoardFetchResult& out) {
    out = BoardFetchResult{};

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
    persistentClient.setCACertBundle(rootca_crt_bundle_start);

    String url = String("https://") + BOARD_HOST + ":" + BOARD_PORT + "/board.php";

    // Referenz auf das dateistatische Objekt -- der restliche Funktionskoerper
    // bleibt unveraendert, aber es wird beim Verlassen NICHT zerstoert.
    HTTPClient& http = persistentHttp;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);
    // Keep-Alive (Nutzervorgabe 2026-08-22): end() schliesst den TCP-Socket bei
    // aktiviertem Reuse nicht mehr, damit der naechste fetchBoard()-Aufruf ihn
    // ueber persistentClient wiederverwenden kann.
    http.setReuse(true);
    http.collectHeaders(HEADER_NAMES, HEADER_COUNT);

    // Bis zu 2 Versuche: der erste nutzt eine ggf. wiederverwendete Verbindung,
    // der zweite erzwingt einen frischen Connect, falls status <= 0 zeigt, dass
    // die wiederverwendete Verbindung von der Gegenstelle bereits zugemacht
    // wurde (Robustheit vor Geschwindigkeit -- ein Fetch darf dadurch nie
    // dauerhaft scheitern, Nutzervorgabe 2026-08-22).
    int status = -1;
    for (int attempt = 0; attempt < 2; ++attempt) {
        if (!ensureConnected(attempt == 1)) {
            out.outcome = BoardFetchOutcome::NetworkUnavailable;
            return;
        }

        if (!http.begin(persistentClient, url)) {
            out.outcome = BoardFetchOutcome::NetworkUnavailable;
            return;
        }

        http.addHeader("Authorization", String("Bearer ") + token);
        http.addHeader("X-Device-Battery-mV", String(batteryMv));
        http.addHeader("X-Device-RSSI", String(rssi));
        if (touchValue != nullptr) {
            http.addHeader("X-Device-Touch", touchValue);
        }
        if (lastEtag != nullptr && lastEtag[0] != '\0') {
            http.addHeader("If-None-Match", lastEtag);
        }

        // Diagnose (2026-08-21): nach fw11 erreichten die Abrufe den Server nicht
        // mehr. status <= 0 ist ein HTTPClient-Fehlercode (negativ), sonst der
        // HTTP-Status -- ohne Ausgabe ist beides nicht unterscheidbar.
        uint32_t t0 = millis();
        status = http.GET();
        Serial.printf("[fetch] GET -> %d nach %lums, heap=%lu psram=%lu\n",
                      status, (unsigned long)(millis() - t0),
                      (unsigned long) ESP.getFreeHeap(), (unsigned long) ESP.getFreePsram());

        if (status > 0) {
            break; // HTTP-Antwort erhalten (egal welcher Code) -> Verbindung war ok, kein Retry noetig
        }

        Serial.printf("[fetch] Fehler: %s\n", http.errorToString(status).c_str());
        http.end();
        if (attempt == 1) {
            out.outcome = BoardFetchOutcome::NetworkUnavailable;
            return;
        }
        // attempt 0 mit status <= 0: koennte eine zugemachte wiederverwendete
        // Verbindung sein -- naechste Schleifenrunde erzwingt frischen Connect.
    }
    // Fehlerantworten haben einen (JSON-)Body, den wir hier bewusst nicht
    // lesen -- damit bleibt der Socket unsauber, s. connectionDirty oben.
    if (status == 401) {
        connectionDirty = true;
        http.end();
        out.outcome = BoardFetchOutcome::Unauthorized;
        return;
    }
    if (status != 200) {
        // 503 und alles sonstige Unerwartete: wie ein Verbindungsausfall
        // behandeln (Spec §11 "wie WLAN-Ausfall").
        connectionDirty = true;
        http.end();
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    out.snapshotRequested = http.header("X-Board-Snapshot-Requested") == "1";

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
    // Sauber ist die Schleife nur zu Ende gelaufen, wenn der angekuendigte
    // Body restlos gelesen wurde (contentLength genau auf 0 heruntergezaehlt).
    // Bei -1 (kein Content-Length) laesst sich Vollstaendigkeit nicht pruefen
    // -> ebenfalls als unsauber behandeln, s. connectionDirty oben.
    connectionDirty = (contentLength != 0);
    http.end();

    out.parsed = validateBoardResponse(headers, out.body.size());
    if (out.parsed.status == BoardResponseStatus::Ok) {
        out.outcome = BoardFetchOutcome::Success;
    } else {
        out.outcome = BoardFetchOutcome::UnreadableResponse;
    }
}

bool uploadSnapshot(const char* token, const uint8_t* buffer, size_t bufferSize, uint32_t timeoutMs) {
    WiFiClientSecure client;
    extern const uint8_t rootca_crt_bundle_start[] asm("_binary_x509_crt_bundle_start");
    client.setCACertBundle(rootca_crt_bundle_start);
    HTTPClient http;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);

    String url = String("https://") + BOARD_HOST + ":" + BOARD_PORT + "/board_snapshot.php";
    if (!http.begin(client, url)) {
        Serial.println("[snapshot] http.begin() fehlgeschlagen");
        return false;
    }

    http.addHeader("Authorization", String("Bearer ") + token);
    http.addHeader("Content-Type", "application/octet-stream");

    uint32_t t0 = millis();
    int status = http.POST((uint8_t*) buffer, bufferSize);
    Serial.printf("[snapshot] POST -> %d nach %lums, %u Bytes\n",
                  status, (unsigned long) (millis() - t0), (unsigned) bufferSize);
    http.end();

    return status == 200;
}
