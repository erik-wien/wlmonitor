#include <Arduino.h>
#include <WiFi.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include "config.h"
#include "board_client.h"
#include "board_model.h"
#include "layout.h"
#include "error_state.h"
#include "display.h"

// Ueberlebt Tiefschlaf (RTC-Speicher). Anker fuer estimateNow() -- siehe
// layout.h und Spec §8 "Keine Uhr noetig": der Zeitstempel kommt aus der
// letzten erfolgreichen Serverantwort, nicht aus einer eigenen RTC/NTP-Uhr.
RTC_DATA_ATTR time_t rtcAnchorGeneratedEpoch = 0;
RTC_DATA_ATTR uint64_t rtcAnchorUptimeMs = 0;
RTC_DATA_ATTR int rtcConsecutiveFailures = 0;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t HTTP_TIMEOUT_MS = 8000;

static uint64_t uptimeMs() {
    return (uint64_t) (esp_timer_get_time() / 1000);
}

static bool connectWifi() {
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    uint32_t start = millis();
    while (WiFi.status() != WL_CONNECTED) {
        if (millis() - start > WIFI_TIMEOUT_MS) {
            Serial.printf("[wifi] Zeitueberschreitung, letzter Status: %d\n", WiFi.status());
            return false;
        }
        delay(200);
    }
    Serial.printf("[wifi] verbunden, IP %s, %lums\n", WiFi.localIP().toString().c_str(), (unsigned long) (millis() - start));
    return true;
}

static void goToSleep() {
    WiFi.disconnect(true);
    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

void setup() {
    Serial.begin(115200);
    delay(300);
    Serial.println("[boot] setup() start");

    uint64_t now = uptimeMs();
    time_t estimatedNow = (rtcAnchorGeneratedEpoch == 0)
        ? 0
        : estimateNow(rtcAnchorGeneratedEpoch, rtcAnchorUptimeMs, now);

    Serial.println("[display] initDisplay()");
    initDisplay();
    Serial.println("[display] initDisplay() zurueck");

    if (!connectWifi()) {
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        Serial.printf("[error] kein WLAN, consecutiveFailures=%d, banner=%d\n", st.consecutiveFailures, (int) st.banner);
        if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
            BoardResponse empty;
            Serial.println("[display] renderBoard() (Fehlerbanner)");
            renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
            Serial.println("[display] renderBoard() zurueck");
        }
        Serial.println("[sleep] goToSleep()");
        goToSleep();
        return;
    }

    std::string body;
    Serial.println("[http] fetchBoard()");
    BoardFetchResult fetch = fetchBoard(BOARD_HOST, BOARD_PORT, BOARD_FAV_IDS, BOARD_TOKEN, HTTP_TIMEOUT_MS, body);
    Serial.printf("[http] Ergebnis=%d, Antwortlaenge=%d\n", (int) fetch, (int) body.size());
    if (!body.empty()) {
        Serial.printf("[http] Antwort (erste 200 Zeichen): %s\n", body.substr(0, 200).c_str());
    }

    FetchOutcome outcome;
    BoardResponse board;

    if (fetch == BoardFetchResult::Ok) {
        ParseStatus parseStatus = parseBoardResponse(body, board);
        Serial.printf("[parse] ParseStatus=%d, Favoriten=%d\n", (int) parseStatus, (int) board.favorites.size());
        outcome = (parseStatus == ParseStatus::Ok) ? FetchOutcome::Success : FetchOutcome::UnreadableResponse;
    } else if (fetch == BoardFetchResult::Unauthorized) {
        outcome = FetchOutcome::Unauthorized;
    } else {
        // Unavailable und ServerError: wie WLAN-Ausfall, Spec §9.
        outcome = FetchOutcome::NetworkUnavailable;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;
    Serial.printf("[state] outcome=%d, consecutiveFailures=%d, banner=%d\n", (int) outcome, st.consecutiveFailures, (int) st.banner);

    if (outcome == FetchOutcome::Success) {
        time_t generatedEpoch;
        if (parseIso8601(board.generated, generatedEpoch)) {
            rtcAnchorGeneratedEpoch = generatedEpoch;
            rtcAnchorUptimeMs = now;
            estimatedNow = generatedEpoch;
        }
        Serial.println("[display] renderBoard() (Erfolg)");
        renderBoard(board, rtcAnchorGeneratedEpoch, estimatedNow, ErrorBanner::None);
        Serial.println("[display] renderBoard() zurueck");
    } else if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
        // Nur neu zeichnen, wenn es etwas zu melden gibt (Spec §9: "Bild
        // bleiben lassen" unterhalb der Fehlerschwelle).
        BoardResponse empty;
        Serial.println("[display] renderBoard() (Fehlerbanner)");
        renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
        Serial.println("[display] renderBoard() zurueck");
    }

    Serial.println("[sleep] goToSleep()");
    goToSleep();
}

void loop() {
    // Ungenutzt: setup() geht in Tiefschlaf, bevor loop() je aufgerufen wird.
}
