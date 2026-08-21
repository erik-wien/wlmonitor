#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <Preferences.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include <time.h>
#include "board_config.h"
#include "board_client.h"
#include "display.h"
#include "touch.h"
#include "buttons.h"
#include "battery.h"
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
// WiFiManager blockiert per Default unbegrenzt im Access-Point-Portal, wenn
// autoConnect() mit gespeicherten Zugangsdaten trotzdem nicht verbindet
// (z. B. Router kurz down) -- ohne Timeout wuerde ein batteriebetriebenes
// Geraet dann bis zum manuellen Eingriff im Portal-Modus haengen bleiben,
// statt in den Offline-Eskalations-/Tiefschlaf-Zyklus zu fallen (Spec §11).
static const uint32_t WIFI_PORTAL_TIMEOUT_S = 180;

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

    wm.setConfigPortalTimeout(WIFI_PORTAL_TIMEOUT_S); // s. Kommentar bei WIFI_PORTAL_TIMEOUT_S
    bool connected = wm.autoConnect("wlmonitor-setup");
    if (!connected) return false;

    outToken = String(tokenParam.getValue());
    saveToken(outToken);
    return true;
}

static void syncTimeForTls() {
    // Best-effort SNTP-Sync (Global Constraints): TLS-Zertifikatspruefung
    // braucht eine ungefaehr richtige Uhr, "kein NTP" aus Spec §10 bezog
    // sich nur auf die Zeitstempel-ANZEIGE, nicht auf TLS. Kurzer Timeout,
    // Fehlschlag ist nicht fatal -- der HTTPS-Request laeuft trotzdem an.
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    struct tm timeinfo;
    getLocalTime(&timeinfo, 3000); // max 3s warten, Ergebnis wird nicht ausgewertet
}

static void goToSleep() {
    markSleepIcon();
    sleepPanel();
    WiFi.disconnect(true);
    // Weckquellen: GPIO2 (Touch-INT, zieht bei anliegendem Touch aktiv-low),
    // GPIO3 (KEY0/Wake), GPIO4 (KEY1), GPIO5 (KEY2) -- alle vier auf dem
    // ESP32-S3 RTC-IO-faehig. Ohne GPIO2/4/5 in dieser Maske koennten Touch
    // und die Seiten-Tasten das Geraet ueberhaupt nicht aufwecken (nur der
    // Timer oder KEY0 wuerden je einen setup()-Durchlauf ausloesen) --
    // pollTouch()/readPageButtons() liefen dann leer, obwohl Spec §4
    // Touch/Taste explizit als Weckquellen neben dem Timer nennt.
    // Review-Befund (Whole-Branch-Review, 2026-08-17): urspruenglich fehlte
    // das, s. Ticket-Historie im Progress-Ledger.
    const uint64_t wakePinMask = (1ULL << 2) | (1ULL << 3) | (1ULL << 4) | (1ULL << 5);
    esp_sleep_enable_ext1_wakeup(wakePinMask, ESP_EXT1_WAKEUP_ANY_LOW);
    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

void setup() {
    // Tasten als ALLERERSTE Aktion, noch vor Serial.begin()/initDisplay()/
    // initTouch() -- live bestaetigt (2026-08-21): ein kurzer Tastendruck
    // wurde selbst an der (vermeintlich schon frueh genug) vorigen Stelle
    // nicht erkannt, nur ein LANGER Druck. Grund: Serial.begin()+delay(300)
    // + initDisplay() (SPI/IT8951-Reset-Pulse) + initTouch() (I2C/GT911-
    // Reset) verbrauchen zusammen schon 500ms-1s+ Bootzeit, bevor ueberhaupt
    // geprueft wird -- ein normaler kurzer Tipp ist bis dahin laengst
    // losgelassen.
    bool fullUpdateRequested = isFullUpdateButtonHeld();
    RawButtonStates raw = readRawButtonStates();

    Serial.begin(115200);
    delay(300);

    initDisplay();
    initTouch(); // Rueckgabewert bewusst ignoriert -- fehlender Touch ist nicht fatal, s. touch.h

    int touchX = 0, touchY = 0;
    const char* touchValue = nullptr;
    TouchZone zone = pollTouch(rtcLastFavoriteCount, rtcLastTotalPages, &touchX, &touchY);
    if (zone != TouchZone::None) {
        touchValue = touchZoneToHeaderValue(zone);
    } else {
        // Kein Touch -- sekundaerer Tasten-Weg (Spec §10).
        touchValue = readPageButtons();
    }

    // Diagnose-Text (Nutzerwunsch 2026-08-21): explizit WAS erkannt wurde
    // (Touch-Koordinaten bzw. welche Taste mit ihrer Funktion), PLUS immer
    // die rohen, unentprellten Pin-Zustaende aller drei Tasten -- damit
    // sichtbar ist, ob ein Tastendruck ueberhaupt am GPIO ankommt, auch
    // wenn die interpretierte Aktion "none" bleibt.
    char inputLabel[48];
    if (fullUpdateRequested) {
        snprintf(inputLabel, sizeof(inputLabel), "K2 Update");
    } else if (zone != TouchZone::None) {
        snprintf(inputLabel, sizeof(inputLabel), "t %d,%d", touchX, touchY);
    } else if (touchValue != nullptr) {
        snprintf(inputLabel, sizeof(inputLabel), "K1 Weiter");
    } else if (raw.key0Low) {
        snprintf(inputLabel, sizeof(inputLabel), "K0 Wach");
    } else {
        snprintf(inputLabel, sizeof(inputLabel), "none");
    }
    // "-" = losgelassen, "X" = gedrueckt (statt H/L, Nutzerwunsch 2026-08-21:
    // "H"/"L" ist nicht selbsterklaerend). Reihenfolge immer K0,K1,K2 --
    // ohne Einzel-Labels, damit es bei doppelter Schriftgroesse noch passt.
    char inputLabelWithRaw[64];
    snprintf(inputLabelWithRaw, sizeof(inputLabelWithRaw), "%s [%c%c%c]", inputLabel,
             raw.key0Low ? 'X' : '-', raw.key1Low ? 'X' : '-', raw.key2Low ? 'X' : '-');

    // Unmittelbares Feedback (Nutzerwunsch 2026-08-21) -- noch vor WLAN-
    // Connect/Fetch, nicht erst am Ende mit dem Rest. Piepton zusaetzlich
    // zum visuellen Marker: der Marker braucht ~1-2s (e-Paper-Partial-
    // Update), der Ton ist wirklich sofort. Nur bei tatsaechlich erkannter
    // Interaktion, nicht bei reinem Timer-Wake.
    if (fullUpdateRequested || touchValue != nullptr || raw.key0Low) {
        beepConfirm();
    }
    showInputMarker(inputLabelWithRaw);

    String token;
    if (!provisionAndConnect(token)) {
        // Kein WLAN verbunden und keine Zugangsdaten hinterlegt --
        // WiFiManager-Portal wurde bereits versucht, hier bleibt nur:
        // wie ein WLAN-Ausfall behandeln.
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None) {
            showErrorBanner(st.banner, "??:??");
        }
        goToSleep();
        return;
    }

    syncTimeForTls();

    int batteryMv = readBatteryMillivolts();
    int rssi = WiFi.RSSI();

    // Debug-Taste "vollstaendiges Update" (Nutzervorgabe 2026-08-21, um
    // Patch-Darstellungsfehler von echten Full-Frame-Rendern zu unterscheiden):
    // rtcLastEtag leeren -> fetchBoard() schickt kein If-None-Match mehr ->
    // board.php::canPatch() ist false (s. board.php, prueft If-None-Match !=
    // '') -> Server liefert automatisch mode=full, keine Server-Aenderung
    // noetig. Taste selbst wurde schon ganz oben in setup() gelesen (Timing).
    if (fullUpdateRequested) {
        rtcLastEtag[0] = '\0';
    }

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
        showErrorBanner(st.banner, "??:??"); // Firmware fuehrt keine eigene Uhr (Spec §10), kein echter Zeitstempel verfuegbar
    }

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() geht in Tiefschlaf, bevor loop() je aufgerufen wird.
}
