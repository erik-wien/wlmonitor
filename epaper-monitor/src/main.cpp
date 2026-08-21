#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <Preferences.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include <driver/rtc_io.h>
#include <time.h>
#include "board_config.h"
#include "board_client.h"
#include "display.h"
#include "touch.h"
#include "buttons.h"
#include "battery.h"
#include "sensor.h"
#include "buzzer.h"
#include "error_state.h"

// Ueberlebt Tiefschlaf (RTC-Speicher, ESP32-intern -- keine externe RTC
// noetig, s. Spec §10 und Global Constraints).
RTC_DATA_ATTR int rtcConsecutiveFailures = 0;
RTC_DATA_ATTR char rtcLastEtag[80] = "";
RTC_DATA_ATTR int rtcLastFavoriteCount = 0;
RTC_DATA_ATTR int rtcLastTotalPages = 1;

static const uint32_t HTTP_TIMEOUT_MS = 8000;
static const uint32_t WAKE_BUTTON_LONG_PRESS_MS = 3000;
static const uint32_t WIFI_PORTAL_TIMEOUT_S = 180;

// Aktiv-Session (Nutzervorgabe 2026-08-21): "nach dem Aufwachen nur EINE
// Aktion ist Bloedsinn". Nach dem Aufwachen bleibt das Geraet in einer
// ECHTEN Schleife wach -- kein Deep Sleep zwischen einzelnen Aktionen.
// Eingaben werden sofort quittiert, Inhalt laedt periodisch nach. Erst nach
// ACTIVE_IDLE_TIMEOUT_MS ohne Eingabe geht es in den Tiefschlaf zurueck.
// Werte bewusst grosszuegig ("einmal die Woche laden ist ok" -- Nutzer).
static const uint32_t ACTIVE_IDLE_TIMEOUT_MS = 5UL * 60 * 1000;   // 5 Minuten
static const uint32_t REFRESH_INTERVAL_MS    = 25UL * 1000;      // 25 Sekunden
static const uint32_t INPUT_POLL_MS          = 30;               // wie Seeeds Touch-Beispiel
static const uint32_t POST_HIT_COOLDOWN_MS   = 900;              // ein gehaltener Finger loest nicht mehrfach aus

static Preferences prefs;

static String loadToken() {
    prefs.begin("board", true);
    String token = prefs.getString("token", "");
    prefs.end();
    return token;
}

static void saveToken(const String& token) {
    if (token.length() == 0) return;
    prefs.begin("board", false);
    prefs.putString("token", token);
    prefs.end();
}

static bool provisionAndConnect(String& outToken) {
    WiFiManager wm;
    String previousToken = loadToken();
    WiFiManagerParameter tokenParam("token", "API-Token (profil.php)", previousToken.c_str(), 128);
    wm.addParameter(&tokenParam);

    if (isWakeButtonHeld(WAKE_BUTTON_LONG_PRESS_MS)) {
        wm.resetSettings(); // langer Tastendruck -> zurueck in den Access-Point-Modus (Spec §10)
    }

    wm.setConfigPortalTimeout(WIFI_PORTAL_TIMEOUT_S);
    bool connected = wm.autoConnect("wlmonitor-setup");
    if (!connected) return false;

    outToken = String(tokenParam.getValue());
    saveToken(outToken);
    return true;
}

static void syncTimeForTls() {
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    struct tm timeinfo;
    getLocalTime(&timeinfo, 3000);
}

static void goToSleep() {
    markSleepIcon();
    sleepPanel();
    WiFi.disconnect(true);
    // GPIO2 (Touch-INT) bewusst NICHT in der Weckmaske: der GT911 haelt
    // seinen Interrupt im normalen Scanbetrieb dauerhaft aktiv, quittieren
    // hilft nicht (am Geraet gemessen: Dauerwecken alle ~9s). Aufwecken
    // laeuft ueber die Tasten und den Timer; Touch wird waehrend der
    // Aktiv-Session ausgewertet, s. runActiveSession().
    const uint64_t wakePinMask = (1ULL << 3) | (1ULL << 4) | (1ULL << 5);
    esp_sleep_enable_ext1_wakeup(wakePinMask, ESP_EXT1_WAKEUP_ANY_LOW);

    for (int pin : {3, 4, 5}) {
        rtc_gpio_pullup_en(static_cast<gpio_num_t>(pin));
        rtc_gpio_pulldown_dis(static_cast<gpio_num_t>(pin));
    }
    touchClearInterrupt();

    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

// Ein Abruf+Render-Zyklus. touchValue darf nullptr sein (reiner Zeit-Refresh).
// forceFull leert rtcLastEtag, damit board.php ohne If-None-Match antwortet
// und automatisch ein Vollbild statt eines Patches liefert.
static void fetchAndRender(const String& token, const char* touchValue, bool forceFull) {
    if (forceFull) rtcLastEtag[0] = '\0';

    int batteryMv = readBatteryMillivolts();
    int rssi = WiFi.RSSI();

    BoardFetchResult fetch;
    fetchBoard(token.c_str(), touchValue, rtcLastEtag, batteryMv, rssi, HTTP_TIMEOUT_MS, fetch);

    FetchOutcome outcome;
    switch (fetch.outcome) {
        case BoardFetchOutcome::Success:           outcome = FetchOutcome::Success; break;
        case BoardFetchOutcome::Unauthorized:       outcome = FetchOutcome::Unauthorized; break;
        case BoardFetchOutcome::UnreadableResponse: outcome = FetchOutcome::UnreadableResponse; break;
        default:                                    outcome = FetchOutcome::NetworkUnavailable; break;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;

    Serial.printf("[board] outcome=%d mode=%s %dx%d @%d,%d body=%u\n",
                  (int) fetch.outcome, fetch.parsed.isPatch ? "patch" : "full",
                  fetch.parsed.w, fetch.parsed.h, fetch.parsed.x, fetch.parsed.y,
                  (unsigned) fetch.body.size());

    if (outcome == FetchOutcome::Success) {
        if (fetch.parsed.isPatch) {
            applyPatch(fetch.body.data(), fetch.parsed.x, fetch.parsed.y, fetch.parsed.w, fetch.parsed.h);
        } else {
            applyFullFrame(fetch.body.data(), fetch.parsed.w, fetch.parsed.h);
        }
        strncpy(rtcLastEtag, fetch.parsed.etag.c_str(), sizeof(rtcLastEtag) - 1);
        rtcLastEtag[sizeof(rtcLastEtag) - 1] = '\0';
        rtcLastFavoriteCount = fetch.parsed.favoriteCount;
        rtcLastTotalPages = fetch.parsed.totalPages;
        showBuildMarker(FIRMWARE_BUILD);
    } else if (st.banner != ErrorBanner::None) {
        showErrorBanner(st.banner, "??:??");
    }
}

// Bleibt wach, bis ACTIVE_IDLE_TIMEOUT_MS lang keine Eingabe mehr kam.
// Eingaben (Touch/Tasten) loesen sofort einen Abruf aus; ohne Eingabe wird
// trotzdem alle REFRESH_INTERVAL_MS nachgeladen.
static void runActiveSession(const String& token, bool forceFullFirst) {
    // "Bereit"-Piep GENAU HIER, nicht schon beim Booten: erst ab jetzt wird
    // tatsaechlich auf Touch/Tasten gehoert. Ein frueherer Piep (z.B. beim
    // Aufwachen) waere ein falsches "jetzt tippen"-Signal, waehrend Display/
    // Touch/WLAN noch initialisieren (Nutzerbefund 2026-08-21: "muss kurz
    // warten, sonst wird der touch nicht erkannt").
    beepConfirm();
    Serial.println("[active] bereit, Eingaben werden jetzt ausgewertet");
    uint32_t lastActivity = millis();
    int lastHitX = -1000, lastHitY = -1000;
    uint32_t lastHitMs = 0;
    // Sofortiger erster Refresh: lastRefresh liegt schon "in der Vergangenheit".
    uint32_t lastRefresh = millis() - REFRESH_INTERVAL_MS;
    bool firstRefreshDone = false;

    while (millis() - lastActivity < ACTIVE_IDLE_TIMEOUT_MS) {
        const char* touchValue = nullptr;
        bool forceFull = (!firstRefreshDone && forceFullFirst);

        int touchX = 0, touchY = 0;
        TouchZone zone = pollTouch(rtcLastFavoriteCount, rtcLastTotalPages, &touchX, &touchY);
        if (zone != TouchZone::None) {
            bool sameAsLastHit = (millis() - lastHitMs < 3000)
                && abs(touchX - lastHitX) < 24 && abs(touchY - lastHitY) < 24;
            if (!sameAsLastHit) {
                touchValue = touchZoneToHeaderValue(zone);
                beepRecognized();
                drawTouchBlob(touchX, touchY);
                lastHitX = touchX; lastHitY = touchY; lastHitMs = millis();
            }
        } else if (const char* btn = readPageButtons()) {
            touchValue = btn;
            beepRecognized();
        } else if (isFullUpdateButtonHeld()) {
            forceFull = true;
            beepRecognized();
        }

        bool dueForRefresh = (millis() - lastRefresh >= REFRESH_INTERVAL_MS);
        if (touchValue != nullptr || forceFull || dueForRefresh) {
            fetchAndRender(token, touchValue, forceFull);
            lastRefresh = millis();
            firstRefreshDone = true;
            if (touchValue != nullptr || forceFull) {
                lastActivity = millis();
                delay(POST_HIT_COOLDOWN_MS); // gehaltener Finger loest nicht mehrfach aus
            }
        }

        delay(INPUT_POLL_MS);
    }
    Serial.println("[active] 5 Minuten ohne Eingabe -- gehe schlafen");
}

void setup() {
    buzzerWarmup();
    bool fullUpdateRequested = isFullUpdateButtonHeld();

    Serial.begin(115200);
    delay(300);

    initDisplay();
    initTouch(); // Rueckgabewert bewusst ignoriert -- fehlender Touch ist nicht fatal, s. touch.h
    applyPanelTemperature(readAmbientTemperature());

    String token;
    if (!provisionAndConnect(token)) {
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None) {
            showErrorBanner(st.banner, "??:??");
        }
        goToSleep();
        return;
    }

    syncTimeForTls();

    // Touch-/Tastenpruefung startet HIER, nicht erst nach einem ersten Abruf
    // (der bisher WLAN-Connect+Zeit-Sync+Fetch bloehte -- spuerbare Verzoegerung
    // zwischen Piep und tatsaechlicher Touch-Reaktion, Nutzerbefund 2026-08-21).
    // runActiveSession() macht den ersten Abruf selbst, als Teil der Schleife.
    runActiveSession(token, fullUpdateRequested);

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() kehrt erst nach goToSleep() zurueck.
}
