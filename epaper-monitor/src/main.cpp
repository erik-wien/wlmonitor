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
// Der Server berechnet Patches als Diff gegen SEIN eigenes letztes Bild --
// weiss aber nichts von lokal aufs Panel gezeichnetem Inhalt (Fehler-Banner,
// Touch-Blob, Status-/Build-/Input-Marker). Bleibt so eine lokale Flaeche
// serverseitig "unveraendert", nimmt kein kuenftiger Patch sie je wieder mit
// -- die lokale Zeichnung bleibt fuer immer stehen (Nutzerbefund 2026-08-22:
// sichtbare Verschiebung/Reste genau im Logo-Bereich, wo der Fehler-Banner
// sitzt). rtcBannerShown erzwingt ein Vollbild, sobald der Banner mal
// gezeichnet wurde, bis der naechste Erfolg das ganze Panel neu synchronisiert.
RTC_DATA_ATTR bool rtcBannerShown = false;

// PATCHES SIND ABGESCHAFFT (Messung 2026-08-22, fw43 am echten Geraet).
//
// Die Teilaktualisierung war die Ursache der Darstellungsfehler (doppelte
// Zeichen, angefressene Buchstaben) -- und hat sich als das schlechtere
// Geschaeft erwiesen, weil das Panel-Schreiben ~500ms FIXKOSTEN hat,
// unabhaengig von der Flaeche:
//
//   40x448   (winziger Patch)  557 ms
//   960x1255                   642 ms
//   1640x1255                  755 ms
//   1872x1404 (Vollbild)      1024 ms
//
// Ein Patch spart also hoechstens ~470ms Panel-Zeit -- kostet aber ein
// ZWEITES Status-Overlay (490-1097ms), weil nur ein Vollbild den Text
// "Bereit" schon serverseitig mitbringt. Unterm Strich ist das Vollbild
// sogar SCHNELLER. Auch am Netz war nichts zu holen: der GET dauert
// 734-821ms, weitgehend unabhaengig von 2 KB oder 257 KB Nutzlast, weil die
// Server-Renderzeit dominiert. Und die Patches waren ohnehin riesig (der
// typische Patch deckte 150-257 KB von 328 KB ab, weil sich Abfahrtszeiten
// ueber die ganze Spalte aendern).
//
// Damit entfaellt auch die ganze Resynchronisations-Mechanik
// (rtcPatchesSinceFull / FULL_REFRESH_EVERY_N_PATCHES) -- jeder Zyklus
// synchronisiert jetzt vollstaendig. Siehe docs/hardware/reterminal-e1003.md §20.8.
static const bool ALWAYS_FULL_FRAME = true;

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
// POST_HIT_COOLDOWN_MS (900ms) entfernt (2026-08-22): seit fw31 verhindert
// der Press/Release-Zustand in runActiveSession() (touchDown/buttonDown)
// bereits zuverlaessig Mehrfachausloesung bei gehaltenem Finger/Taste -- die
// zusaetzliche Verzoegerung war nur noch spuerbare Wartezeit nach jeder
// Eingabe ohne echten Nutzen (Nutzerbefund 2026-08-22).

// Die Firmware-Marke wird seit fw46 SERVERSEITIG in die Statusleiste
// gerendert (Header X-Device-Firmware, s. board_client.cpp). Lokal gezeichnet
// war sie der teuerste Posten im ganzen Zyklus: gemessene 1104 ms fuer ein
// 256x50-Rechteck, mehr als ein komplettes Vollbild (1024 ms) -- jede lokale
// Teilaktualisierung zahlt die vollen Panel-Fixkosten.

// Zeigt der zuletzt erfolgreich geschriebene Frame GERADE den Schlafschirm
// (X-Board-Is-Sleep-Page, s. fetchAndRender())? Aktualisiert sich bei JEDEM
// erfolgreichen Abruf neu -- egal ob durch bewusstes Hinblaettern oder den
// erzwungenen Abruf vor dem Tiefschlaf. Zwei Stellen werten das aus:
// goToSleep() darf dann nichts mehr lokal darueber malen (jedes Overlay
// kostet einen vollen Panel-Schreibvorgang und wuerde den Inhalt verdecken),
// und die gruene Taste bedeutet dann "jetzt schlafen" statt "Vollupdate"
// (Nutzerwunsch 2026-08-23, Doppelbelegung statt einer eigenen vierten Taste).
// Bewusst KEIN RTC_DATA_ATTR: gilt nur fuer den laufenden Wachzyklus.
static bool showingSleepPage = false;

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
    // Ueber den Schlafschirm wird NICHTS mehr lokal gemalt: er ist bereits das
    // fertige Bild fuer die naechsten Stunden, und jedes Overlay kostet einen
    // vollen Panel-Schreibvorgang (~500ms Fixkosten, s. §20.8) und wuerde
    // seinen Inhalt ueberdecken. Nur wenn wir OHNE Schlafschirm einschlafen
    // (Verbindungsfehler beim Abruf), bleibt der bisherige Hinweis sinnvoll.
    if (!showingSleepPage) {
        showStatus(StatusIcon::Sleep, "Schlaf");
        markSleepIcon();
    }
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

// Protokollwert der erkannten Eingabe -> Klartext fuer die Statusleiste.
// touchValue == nullptr heisst "kein Tipp, kein Tastendruck" (Zeit-Refresh
// oder der erste Abruf einer Session).
static const char* inputStatusText(const char* touchValue, bool forceFull) {
    if (forceFull && touchValue == nullptr) return "Vollbild";
    if (touchValue == nullptr)              return "Lade ...";
    if (strcmp(touchValue, "fav0") == 0)      return "Favorit 1";
    if (strcmp(touchValue, "fav1") == 0)      return "Favorit 2";
    if (strcmp(touchValue, "fav2") == 0)      return "Favorit 3";
    if (strcmp(touchValue, "page_next") == 0) return "Seite vor";
    if (strcmp(touchValue, "page_prev") == 0) return "Seite zurueck";
    return "Lade ...";
}

// Ein Abruf+Render-Zyklus. touchValue darf nullptr sein (reiner Zeit-Refresh).
// forceFull leert rtcLastEtag, damit board.php ohne If-None-Match antwortet
// und automatisch ein Vollbild statt eines Patches liefert.
// Liefert true, wenn ein Bild empfangen UND aufs Panel geschrieben wurde.
static bool fetchAndRender(const String& token, const char* touchValue, bool forceFull,
                           const char* screen = nullptr) {
    const uint32_t tCycle = millis();
    // Vollbild erzwingen, wenn ein Fehler-Banner lokal steht (der Server weiss
    // nichts davon) oder das periodische Resync-Intervall erreicht ist --
    // s. rtcBannerShown/ALWAYS_FULL_FRAME weiter oben.
    if (rtcBannerShown || ALWAYS_FULL_FRAME) {
        forceFull = true;
    }
    if (forceFull) rtcLastEtag[0] = '\0';

    // Ladezustand lokal VOR dem Request zeichnen -- der Server kann ihn nicht
    // rendern (er gilt waehrend/vor dem Request, nicht als dessen Ergebnis).
    // Am Ende dieser Funktion zurueck auf "Bereit", ausser die Antwort war ein
    // Vollbild (das bringt den Server-Standardtext schon mit) -- ein Patch
    // deckt nur die serverseitig geaenderten Pixel ab und koennte die
    // Statusleiste auslassen.
    //
    // Der Text nennt auch gleich die erkannte Eingabe. Das ersetzt den
    // frueheren separaten "in:<label>"-Kasten in der Abfahrtenspalte
    // (Nutzerwunsch 2026-08-22, "kompakter"): dieselbe Information, aber in
    // der Zeile, die ohnehin schon da ist, und in Klartext statt Protokoll-ID.
    // Beim Schlafschirm KEIN Overlay: es wuerde einen vollen Panel-Schreib-
    // vorgang kosten (~500ms Fixkosten, s. §20.8) und im selben Zyklus vom
    // ankommenden Vollbild wieder ueberschrieben.
    if (screen == nullptr) {
        showStatus(forceFull ? StatusIcon::Full : StatusIcon::Loading,
                   inputStatusText(touchValue, forceFull));
    }

    int batteryMv = readBatteryMillivolts();
    int rssi = WiFi.RSSI();

    BoardFetchResult fetch;
    fetchBoard(token.c_str(), touchValue, rtcLastEtag, batteryMv, rssi, HTTP_TIMEOUT_MS, fetch, screen);

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

    bool wasFullFrame = false;
    if (outcome == FetchOutcome::Success) {
        if (fetch.parsed.isPatch) {
            // Sollte mit ALWAYS_FULL_FRAME nicht mehr vorkommen (leeres ETag ->
            // der Server hat nichts zu diffen). Bleibt als Notpfad stehen,
            // damit eine unerwartete Patch-Antwort nicht verworfen wird.
            applyPatch(fetch.body.data(), fetch.parsed.x, fetch.parsed.y, fetch.parsed.w, fetch.parsed.h);
        } else {
            applyFullFrame(fetch.body.data(), fetch.parsed.w, fetch.parsed.h);
            wasFullFrame = true;
        }
        rtcBannerShown = false;
        strncpy(rtcLastEtag, fetch.parsed.etag.c_str(), sizeof(rtcLastEtag) - 1);
        rtcLastEtag[sizeof(rtcLastEtag) - 1] = '\0';
        rtcLastFavoriteCount = fetch.parsed.favoriteCount;
        rtcLastTotalPages = fetch.parsed.totalPages;
        showingSleepPage = fetch.isSleepPage;
    } else if (st.banner != ErrorBanner::None) {
        showErrorBanner(st.banner, "??:??");
        rtcBannerShown = true;
    }

    // Bei einem Vollbild steht "Bereit" samt Piktogramm bereits serverseitig
    // im Frame (BOARD_STATUS_IDLE_TEXT, s. board_template.php) -- lokales
    // Nachzeichnen waere ein doppelter Panel-Write ohne sichtbaren Effekt.
    // Bei Patch oder Fehlerfall bleibt es noetig (Patch deckt die Statusleiste
    // i. d. R. nicht ab, Fehlerfall zeichnet gar keinen neuen Frame).
    if (!wasFullFrame) {
        showStatus(StatusIcon::Ready, "Bereit");
    }

    // Erst NACH allen lokalen Zeichnungen dieses Zyklus hochladen -- der
    // Schnappschuss soll den tatsaechlich fertig eingestellten Panel-Inhalt
    // zeigen, nicht einen Zwischenstand (Debug-Feature, s. web/board_snapshot.php).
    if (fetch.snapshotRequested) {
        uploadSnapshot(token.c_str(), getPanelBuffer(), getPanelBufferSize(), HTTP_TIMEOUT_MS);
    }

    Serial.printf("[perf] Zyklus gesamt (%s): %lu ms\n",
                  wasFullFrame ? "Vollbild" : "Patch/Fehler",
                  (unsigned long) (millis() - tCycle));

    return outcome == FetchOutcome::Success;
}

// Bleibt wach, bis ACTIVE_IDLE_TIMEOUT_MS lang keine Eingabe mehr kam.
// Eingaben (Touch/Tasten) loesen sofort einen Abruf aus; ohne Eingabe wird
// trotzdem alle REFRESH_INTERVAL_MS nachgeladen.
static void runActiveSession(const String& token) {
    // Ersten Abruf VOR dem "bereit"-Piep, nicht mehr danach (2026-08-21):
    // ein sofortiger erzwungener Refresh als allererste Schleifenaktion
    // blockierte 2-3s, WAEHREND der Piep schon "jetzt tippen" signalisierte
    // -- Touch wurde in dieser Zeit gar nicht gepollt (Nutzerbefund: "Piep,
    // dann noch 2s bis wirklich empfangsbereit"). Jetzt: erst laden, dann
    // piepen, dann WIRKLICH sofort pollen. Kein erzwungenes Vollbild fuer
    // diesen ersten Abruf mehr (Nutzervorgabe 2026-08-22): die gruene Taste
    // weckt nur noch, "Vollupdate" gilt erst innerhalb der Schleife unten.
    fetchAndRender(token, nullptr, false);

    beepConfirm();
    Serial.println("[active] bereit, Eingaben werden jetzt ausgewertet");
    uint32_t lastActivity = millis();
    // Press/Release-Zustand statt Zeit-/Distanz-Heuristik (2026-08-22): der
    // Fetch allein braucht schon 2-3s -- ein 3000ms-Zeitfenster ab
    // Touch-Erkennung ist da laengst abgelaufen,
    // WAEHREND der Finger noch aufliegt (Nutzerbefund: zweiter Piep+Blob
    // nach jedem Refresh trotz durchgehend gehaltenem Finger). Stattdessen:
    // ein Touch zaehlt erst wieder als "neu", wenn der Sensor zwischenzeitlich
    // mehrmals in Folge KEINEN Touch gemeldet hat (gegen Sensor-Flackern,
    // im Log als vereinzelte status=0x80 innerhalb einer echten Beruehrung
    // beobachtet).
    bool touchDown = false;
    int touchReleaseStreak = 0;
    const int TOUCH_RELEASE_STREAK_NEEDED = 5;
    // readPageButtons()/isFullUpdateButtonHeld() liefern anders als pollTouch()
    // keinen eigenen Press/Release-Zustand -- jeder Poll waehrend gehaltener
    // Taste liefert erneut "gedrueckt". Ohne Gegenmassnahme wuerde ein etwas
    // laenger gehaltener Tastendruck bei jedem Schleifendurchlauf (30ms) nach
    // Rueckkehr aus fetchAndRender() sofort den naechsten Fetch ausloesen.
    // Gleiches Muster wie touchDown: erst bei Loslassen (Taste nicht mehr
    // gedrueckt) zaehlt der naechste Druck wieder als neue Eingabe. Anders als
    // beim Touch-Sensor braucht das GPIO-Signal keinen Release-Streak gegen
    // Flackern -- readPageButtons()/isFullUpdateButtonHeld() entprellen die
    // Flanke bereits selbst (~50ms, s. buttons.cpp).
    bool buttonDown = false;
    uint32_t lastRefresh = millis(); // naechster automatischer Refresh erst in REFRESH_INTERVAL_MS

    while (millis() - lastActivity < ACTIVE_IDLE_TIMEOUT_MS) {
        const char* touchValue = nullptr;
        bool forceFull = false;

        int touchX = 0, touchY = 0;
        TouchZone zone = pollTouch(rtcLastFavoriteCount, rtcLastTotalPages, &touchX, &touchY);
        if (zone != TouchZone::None) {
            touchReleaseStreak = 0;
            if (!touchDown) {
                touchValue = touchZoneToHeaderValue(zone);
                beepRecognized();
                drawTouchBlob(touchX, touchY);
                touchDown = true;
            }
        } else if (const char* btn = readPageButtons()) {
            if (!buttonDown) {
                touchValue = btn;
                beepRecognized();
                buttonDown = true;
            }
        } else if (isFullUpdateButtonHeld()) {
            if (!buttonDown) {
                buttonDown = true;
                if (showingSleepPage) {
                    // Doppelbelegung (Nutzerwunsch 2026-08-23): auf dem
                    // Schlafschirm gibt es keine Abfahrten zum Aktualisieren --
                    // "Vollupdate" waere hier bedeutungslos. Stattdessen bricht
                    // ein Druck auf die gruene Taste sofort in den Tiefschlaf
                    // ab, statt den Rest von ACTIVE_IDLE_TIMEOUT_MS zu warten.
                    beepConfirm();
                    Serial.println("[active] gruene Taste auf Schlafschirm -- sofort schlafen");
                    break;
                }
                forceFull = true;
                beepRecognized();
            }
        } else {
            if (++touchReleaseStreak >= TOUCH_RELEASE_STREAK_NEEDED) touchDown = false;
            buttonDown = false;
        }

        bool dueForRefresh = (millis() - lastRefresh >= REFRESH_INTERVAL_MS);
        if (touchValue != nullptr || forceFull || dueForRefresh) {
            fetchAndRender(token, touchValue, forceFull);
            lastRefresh = millis();
            if (touchValue != nullptr || forceFull) {
                lastActivity = millis();
            }
        }

        delay(INPUT_POLL_MS);
    }

    // Letztes Bild vor dem Tiefschlaf ist der SCHLAFSCHIRM (Nutzerwunsch
    // 2026-08-23): "Bei naeherer Betrachtung macht der Abfahrtsmonitor im
    // Schlafmodus wenig Sinn." Eingefrorene Abfahrtszeiten, die stundenlang
    // stehen, sind schlimmer als nutzlos -- sie sehen aus wie gueltige Daten.
    // Stattdessen Wetter heute/morgen und der Gaeste-WLAN-QR-Code.
    //
    // Steht er schon (showingSleepPage), weil der Nutzer bewusst dorthin
    // geblaettert und ihn stehen gelassen hat, erspart sich das Geraet den
    // erneuten Abruf -- derselbe Inhalt kaeme ohnehin zurueck, nur um densel-
    // ben vollen Panel-Schreibvorgang (~1s, s. §20.8) ein zweites Mal zu
    // bezahlen.
    if (showingSleepPage) {
        Serial.println("[active] Schlafschirm steht schon -- kein erneuter Abruf noetig");
    } else {
        Serial.println("[active] 5 Minuten ohne Eingabe -- Schlafschirm holen");
        // Nur wenn der Schirm wirklich ankam: bei einem Netzfehler steht
        // weiter die Abfahrtenliste auf dem Panel, und dann ist der lokale
        // Schlafhinweis in goToSleep() genau richtig.
        fetchAndRender(token, nullptr, true, "sleep");
    }
}

void setup() {
    buzzerWarmup();
    // KEIN Boot-Zeit-Check von isFullUpdateButtonHeld() mehr (Nutzervorgabe
    // 2026-08-22): die gruene Taste ist jetzt derselbe Pin, der das Geraet
    // aus dem Tiefschlaf weckt -- ein normaler Weck-Druck wuerde sonst JEDE
    // Session mit einem erzwungenen Vollbild starten. Die Taste zaehlt erst
    // als "Vollupdate", sobald das Geraet in der Aktiv-Session-Schleife
    // laeuft (s. runActiveSession()), also wirklich schon wach ist.
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
    runActiveSession(token);

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() kehrt erst nach goToSleep() zurueck.
}
