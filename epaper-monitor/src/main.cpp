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
        if (millis() - start > WIFI_TIMEOUT_MS) return false;
        delay(200);
    }
    return true;
}

static void goToSleep() {
    WiFi.disconnect(true);
    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

void setup() {
    uint64_t now = uptimeMs();
    time_t estimatedNow = (rtcAnchorGeneratedEpoch == 0)
        ? 0
        : estimateNow(rtcAnchorGeneratedEpoch, rtcAnchorUptimeMs, now);

    initDisplay();

    if (!connectWifi()) {
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
            BoardResponse empty;
            renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
        }
        goToSleep();
        return;
    }

    std::string body;
    BoardFetchResult fetch = fetchBoard(BOARD_HOST, BOARD_PORT, BOARD_FAV_IDS, BOARD_TOKEN, HTTP_TIMEOUT_MS, body);

    FetchOutcome outcome;
    BoardResponse board;

    if (fetch == BoardFetchResult::Ok) {
        ParseStatus parseStatus = parseBoardResponse(body, board);
        outcome = (parseStatus == ParseStatus::Ok) ? FetchOutcome::Success : FetchOutcome::UnreadableResponse;
    } else if (fetch == BoardFetchResult::Unauthorized) {
        outcome = FetchOutcome::Unauthorized;
    } else {
        // Unavailable und ServerError: wie WLAN-Ausfall, Spec §9.
        outcome = FetchOutcome::NetworkUnavailable;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;

    if (outcome == FetchOutcome::Success) {
        time_t generatedEpoch;
        if (parseIso8601(board.generated, generatedEpoch)) {
            rtcAnchorGeneratedEpoch = generatedEpoch;
            rtcAnchorUptimeMs = now;
            estimatedNow = generatedEpoch;
        }
        renderBoard(board, rtcAnchorGeneratedEpoch, estimatedNow, ErrorBanner::None);
    } else if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
        // Nur neu zeichnen, wenn es etwas zu melden gibt (Spec §9: "Bild
        // bleiben lassen" unterhalb der Fehlerschwelle).
        BoardResponse empty;
        renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
    }

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() geht in Tiefschlaf, bevor loop() je aufgerufen wird.
}
